<?php
/**
 * Core plugin singleton: loads modules, registers shared hooks and helpers.
 *
 * @package ACPFW
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main (and only) instance of the plugin core.
 */
final class ACPFW_Plugin_Core {

        /**
         * Singleton instance.
         *
         * @var ACPFW_Plugin_Core|null
         */
        private static $instance = null;

        /**
         * Cached plugin settings.
         *
         * @var array
         */
        private $settings = array();

        /**
         * Get the singleton instance.
         *
         * @return ACPFW_Plugin_Core
         */
        public static function instance() {
                if ( null === self::$instance ) {
                        self::$instance = new self();
                }

                return self::$instance;
        }

        /**
         * Constructor. Registers shared hooks; feature modules self-register on load.
         */
        private function __construct() {
                $this->includes();

                add_action( 'init', array( $this, 'load_textdomain' ), 1 );
                add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
                add_action( 'init', array( __CLASS__, 'add_rewrite_rules' ) );
                add_action( 'init', array( __CLASS__, 'maybe_schedule_generation' ) );
                add_action( 'template_redirect', array( $this, 'maybe_serve_endpoint' ), 1 );

                // Feed generation job runner (Action Scheduler / on-demand).
                add_action( 'acpfw_generate_feeds', array( 'ACPFW_OpenAI_Feed_Generator', 'generate' ) );

                // Debounced rebuild after product changes + external cron entry point.
                add_action( 'acpfw_rebuild_after_product_change', array( $this, 'run_update_rebuild' ) );
                add_action( 'admin_post_nopriv_acpfw_cron_rebuild', array( $this, 'handle_cron_rebuild' ) );
                add_action( 'admin_post_acpfw_cron_rebuild', array( $this, 'handle_cron_rebuild' ) );

                $product_change_hooks = array(
                        'woocommerce_new_product',
                        'woocommerce_update_product',
                        'woocommerce_delete_product',
                        'woocommerce_trash_product',
                        'woocommerce_untrash_product',
                        'woocommerce_new_product_variation',
                        'woocommerce_update_product_variation',
                        'woocommerce_delete_product_variation',
                        'woocommerce_product_set_stock',
                        'woocommerce_variation_set_stock',
                );

                foreach ( $product_change_hooks as $product_change_hook ) {
                        add_action( $product_change_hook, array( $this, 'queue_update_rebuild' ) );
                }

                // Prime llms*.txt on first visit after a wipe (cheap on-demand build).
                add_action( 'init', array( $this, 'maybe_prime_llms_files' ) );
        }

        /**
         * Build the llms*.txt files once after activation so the first crawler
         * hit is served from disk instead of triggering an on-demand rebuild.
         *
         * @return void
         */
        public function maybe_prime_llms_files() {
                // Transient first: keeps every init request from hydrating the
                // settings cache (which could go stale later in the request).
                if ( get_transient( 'acpfw_llms_primed' ) ) {
                        return;
                }

                if ( 'yes' !== $this->get_setting( 'enable_llms_txt', 'yes' ) ) {
                        return;
                }

                $llms_path = trailingslashit( self::get_feed_directory() ) . ACPFW_LLMS_Txt_Generator::get_llms_filename();

                if ( ! file_exists( $llms_path ) ) {
                        ACPFW_LLMS_Txt_Generator::generate_all();
                }

                set_transient( 'acpfw_llms_primed', 1, HOUR_IN_SECONDS );
        }

        /**
         * Load feature module files. Extended as modules are implemented.
         */
        private function includes() {
                // Feed engine.
                require_once ACPFW_PLUGIN_DIR . 'includes/Feeds/class-image-resolver.php';
                require_once ACPFW_PLUGIN_DIR . 'includes/Feeds/class-product-mapper.php';
                require_once ACPFW_PLUGIN_DIR . 'includes/Feeds/class-openai-feed-generator.php';

                // Scoring.
                require_once ACPFW_PLUGIN_DIR . 'includes/Scoring/class-readiness-scorer.php';
                ACPFW_Readiness_Scorer::init();

                // Discovery (llms.txt files + .well-known manifests).
                require_once ACPFW_PLUGIN_DIR . 'includes/Discovery/class-llms-txt-generator.php';
                require_once ACPFW_PLUGIN_DIR . 'includes/Discovery/class-well-known-router.php';

                // Refresh the llms*.txt files whenever the feeds rebuild.
                add_action( 'acpfw_feeds_generated', array( 'ACPFW_LLMS_Txt_Generator', 'generate_all' ) );

                // Admin UI.
                require_once ACPFW_PLUGIN_DIR . 'includes/Admin/class-admin-menu.php';
                require_once ACPFW_PLUGIN_DIR . 'includes/Admin/class-product-list-column.php';
                require_once ACPFW_PLUGIN_DIR . 'includes/Admin/class-product-metabox.php';
                require_once ACPFW_PLUGIN_DIR . 'includes/Admin/class-env-checks.php';
                new ACPFW_Admin_Menu();
                new ACPFW_Product_List_Column();
                new ACPFW_Product_Metabox();
                new ACPFW_Env_Checks();
        }

        /**
         * Load the plugin textdomain for translations.
         *
         * @return void
         */
        public function load_textdomain() {
                load_plugin_textdomain(
                        'agentic-commerce-ai-product-feed',
                        false,
                        dirname( ACPFW_PLUGIN_BASENAME ) . '/languages'
                );
        }

        /**
         * Register the custom query vars used by our rewrite endpoints.
         *
         * @param array $vars Registered query vars.
         * @return array
         */
        public function register_query_vars( $vars ) {
                $vars[] = 'acpfw_endpoint';
                $vars[] = 'acpfw_token';

                return $vars;
        }

        /**
         * Rewrite rules for the public, human-readable endpoints.
         *
         * The canonical storage lives under wp-content/uploads/acpfw-feeds/ with
         * unguessable token file names; these clean URLs proxy to it. A fallback
         * template_redirect hook covers servers (commonly Nginx) that pass
         * .well-known requests straight to PHP without hitting WP rewrites.
         *
         * @return void
         */
        public static function add_rewrite_rules() {
                add_rewrite_rule( '^\.well-known/acp\.json$', 'index.php?acpfw_endpoint=acp_json', 'top' );
                add_rewrite_rule( '^\.well-known/ucp$', 'index.php?acpfw_endpoint=ucp', 'top' );
                add_rewrite_rule( '^llms\.txt$', 'index.php?acpfw_endpoint=llms_txt', 'top' );
                add_rewrite_rule( '^llms-full\.txt$', 'index.php?acpfw_endpoint=llms_full_txt', 'top' );
                add_rewrite_rule( '^llms-commerce\.txt$', 'index.php?acpfw_endpoint=llms_commerce_txt', 'top' );
                add_rewrite_rule( '^acpfw-feed/openai\.jsonl$', 'index.php?acpfw_endpoint=feed_jsonl', 'top' );
                add_rewrite_rule( '^acpfw-feed/openai\.csv$', 'index.php?acpfw_endpoint=feed_csv', 'top' );
        }

        /**
         * Fallback endpoint matcher for requests that bypass WordPress rewrites.
         *
         * @return void
         */
        public function maybe_serve_endpoint() {
                $endpoint = get_query_var( 'acpfw_endpoint' );

                if ( ! empty( $endpoint ) ) {
                        if ( class_exists( 'ACPFW_Well_Known_Router' ) ) {
                                ACPFW_Well_Known_Router::serve( $endpoint );
                        }
                        return;
                }

                $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized on next line.
                $path        = ltrim( (string) wp_parse_url( $request_uri, PHP_URL_PATH ), '/' );

                $map = array(
                        '.well-known/acp.json'    => 'acp_json',
                        '.well-known/ucp'         => 'ucp',
                        'llms.txt'                => 'llms_txt',
                        'llms-full.txt'           => 'llms_full_txt',
                        'llms-commerce.txt'       => 'llms_commerce_txt',
                        'acpfw-feed/openai.jsonl' => 'feed_jsonl',
                        'acpfw-feed/openai.csv'   => 'feed_csv',
                );

                if ( isset( $map[ $path ] ) && class_exists( 'ACPFW_Well_Known_Router' ) ) {
                        ACPFW_Well_Known_Router::serve( $map[ $path ] );
                }
        }

        /**
         * Create (once) the feed storage directory inside uploads.
         *
         * The directory gets an index.php to block directory listing; individual
         * feed files stay intentionally public at unguessable token URLs.
         *
         * @return string Absolute path to the feed directory.
         */
        public static function prepare_feed_directory() {
                $feed_dir = self::get_feed_directory();

                if ( ! is_dir( $feed_dir ) ) {
                        wp_mkdir_p( $feed_dir );
                }

                if ( ! file_exists( $feed_dir . '/index.php' ) ) {
                        file_put_contents( $feed_dir . '/index.php', "<?php // Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
                }

                return $feed_dir;
        }

        /**
         * Absolute path to the feed storage directory.
         *
         * @return string
         */
        public static function get_feed_directory() {
                $upload_dir = wp_upload_dir();

                return trailingslashit( $upload_dir['basedir'] ) . 'acpfw-feeds';
        }

        /**
         * Public URL for the feed storage directory.
         *
         * @return string
         */
        public static function get_feed_directory_url() {
                $upload_dir = wp_upload_dir();

                return trailingslashit( $upload_dir['baseurl'] ) . 'acpfw-feeds/';
        }

        /**
         * All plugin settings with defaults applied.
         *
         * @return array
         */
        public function get_settings() {
                if ( empty( $this->settings ) ) {
                        $defaults       = array(
                                'seller_name'       => get_bloginfo( 'name' ),
                                'return_policy_url' => '',
                                'shipping_summary'  => '',
                                'default_brand'     => '',
                                'brand_source'      => 'auto',
                                'gtin_source'       => '_global_unique_id',
                                'mpn_source'        => '',
                                'mpn_sku_fallback'  => 'no',
                                'rebuild_schedule'  => 'daily',
                                'rebuild_on_update' => 'no',
                                'cron_token'        => '',
                                'enable_llms_txt'   => 'yes',
                                'enable_acp_json'   => 'yes',
                                'enable_ucp'        => 'yes',
                                'uninstall_cleanup' => 'no',
                        );
                        $this->settings = wp_parse_args( (array) get_option( 'acpfw_settings', array() ), $defaults );
                }

                return $this->settings;
        }

        /**
         * Drop the in-memory settings cache so the next read hits the DB.
         *
         * Must be called after update_option() in the same request, otherwise
         * modules keep reading the pre-save values.
         *
         * @return void
         */
        public function refresh_settings() {
                $this->settings = array();
        }

        /**
         * Single setting lookup.
         *
         * @param string $key      Setting key.
         * @param mixed  $fallback Fallback if unset or empty.
         * @return mixed
         */
        public function get_setting( $key, $fallback = '' ) {
                $settings = $this->get_settings();

                return ( isset( $settings[ $key ] ) && '' !== $settings[ $key ] ) ? $settings[ $key ] : $fallback;
        }

        /**
         * Schedule the recurring full feed rebuild via Action Scheduler (bundled with WC).
         *
         * Honors the "rebuild_schedule" setting; in "external" mode the built-in
         * scheduler is disabled so only the secret rebuild URL triggers runs.
         *
         * @return void
         */
        public static function maybe_schedule_generation() {
                if ( ! function_exists( 'as_schedule_recurring_action' ) || ! function_exists( 'as_next_scheduled_action' ) ) {
                        return;
                }

                // External cron mode: never self-schedule, drop any stale event.
                if ( 'external' === acpfw()->get_setting( 'rebuild_schedule', 'daily' ) ) {
                        if ( function_exists( 'as_unschedule_all_actions' ) ) {
                                as_unschedule_all_actions( 'acpfw_generate_feeds', array(), 'acpfw' );
                        }

                        return;
                }

                if ( false === as_next_scheduled_action( 'acpfw_generate_feeds' ) ) {
                        as_schedule_recurring_action( time() + HOUR_IN_SECONDS, self::rebuild_interval(), 'acpfw_generate_feeds', array(), 'acpfw' );
                }
        }

        /**
         * Interval in seconds for the automatic rebuild, from the schedule setting.
         *
         * @return int
         */
        public static function rebuild_interval() {
                $map = array(
                        'hourly'     => HOUR_IN_SECONDS,
                        'twicedaily' => 12 * HOUR_IN_SECONDS,
                        'daily'      => DAY_IN_SECONDS,
                        'weekly'     => WEEK_IN_SECONDS,
                );

                $mode = acpfw()->get_setting( 'rebuild_schedule', 'daily' );

                return isset( $map[ $mode ] ) ? (int) $map[ $mode ] : DAY_IN_SECONDS;
        }

        /**
         * Re-sync the recurring rebuild after the schedule setting changes.
         *
         * @return void
         */
        public static function reschedule_generation() {
                if ( ! function_exists( 'as_unschedule_all_actions' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
                        return;
                }

                as_unschedule_all_actions( 'acpfw_generate_feeds', array(), 'acpfw' );
                self::maybe_schedule_generation();
        }

        /**
         * Queue a debounced rebuild after a product create/update/delete.
         *
         * Called from woocommerce_*_product hooks. Batches burst saves (bulk
         * edits, imports) into ONE WP-Cron run about a minute later; the feed
         * write itself is atomic so crawlers never see a partial file.
         *
         * @param mixed $product Product ID / object from the hook (unused).
         * @return void
         */
        public function queue_update_rebuild( $product = 0 ) {
                unset( $product );

                if ( 'yes' !== $this->get_setting( 'rebuild_on_update', 'no' ) ) {
                        return;
                }

                // Debounce: skip while a rebuild is already queued.
                $pending = (int) get_option( 'acpfw_rebuild_pending', 0 );

                if ( $pending > time() ) {
                        return;
                }

                wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'acpfw_rebuild_after_product_change' );
                update_option( 'acpfw_rebuild_pending', time() + 2 * MINUTE_IN_SECONDS, false );
        }

        /**
         * Runner for the debounced product-change rebuild.
         *
         * @return void
         */
        public function run_update_rebuild() {
                delete_option( 'acpfw_rebuild_pending' );
                ACPFW_OpenAI_Feed_Generator::generate();
        }

        /**
         * External cron entry point: admin-post.php?action=acpfw_cron_rebuild&token=…
         *
         * Authenticated by the stored cron token (never by user sessions), so
         * real cron jobs / uptime monitors can trigger a full rebuild. Requests
         * under /wp-admin/ are never cached by page-cache plugins or CDNs;
         * explicit no-cache headers are still sent so even a misconfigured
         * proxy can never replay a stale response.
         *
         * @return void
         */
        public function handle_cron_rebuild() {
                nocache_headers();
                ignore_user_abort( true ); // Finish the rebuild even if the cron service disconnects early.

                $token  = isset( $_GET['token'] ) ? sanitize_key( wp_unslash( $_GET['token'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized on this line.
                $stored = (string) $this->get_setting( 'cron_token', '' );

                if ( '' === $stored || '' === $token || ! hash_equals( $stored, $token ) ) {
                        wp_die(
                                esc_html__( 'Invalid rebuild token.', 'agentic-commerce-ai-product-feed' ),
                                '',
                                array( 'response' => 403 )
                        );
                }

                ACPFW_OpenAI_Feed_Generator::generate();

                $stats = ACPFW_OpenAI_Feed_Generator::get_stats();

                wp_send_json(
                        array(
                                'ok'       => true,
                                'rows'     => isset( $stats['rows'] ) ? (int) $stats['rows'] : 0,
                                'built_at' => isset( $stats['generated_at'] ) ? gmdate( 'c', (int) $stats['generated_at'] ) : gmdate( 'c' ),
                        )
                );
        }

        /**
         * Lazily create + persist the external cron token (32 hex chars).
         *
         * @return string
         */
        public function get_cron_token() {
                $token = (string) $this->get_setting( 'cron_token', '' );

                if ( '' === $token ) {
                        $token = bin2hex( random_bytes( 16 ) );

                        $settings = $this->get_settings();
                        $settings['cron_token'] = $token;

                        update_option( 'acpfw_settings', $settings, false );
                        $this->settings = $settings;
                }

                return $token;
        }

        /**
         * Coexistence: is Yoast SEO active?
         *
         * @return bool
         */
        public static function is_yoast_active() {
                return defined( 'WPSEO_VERSION' );
        }

        /**
         * Coexistence: is Rank Math active?
         *
         * @return bool
         */
        public static function is_rank_math_active() {
                return class_exists( 'RankMath' );
        }
}

/**
 * Global accessor for the plugin core.
 *
 * @return ACPFW_Plugin_Core
 */
function acpfw() {
        return ACPFW_Plugin_Core::instance();
}
