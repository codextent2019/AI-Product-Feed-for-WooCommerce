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
                add_action( 'template_redirect', array( $this, 'maybe_serve_endpoint' ), 1 );
        }

        /**
         * Load feature module files. Extended as modules are implemented.
         */
        private function includes() {
                // Feature modules are required here as they land, so every commit
                // stays fully installable (feed engine, scoring, discovery, admin).
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
                        ACPFW_Well_Known_Router::serve( $endpoint );
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

                if ( isset( $map[ $path ] ) ) {
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
