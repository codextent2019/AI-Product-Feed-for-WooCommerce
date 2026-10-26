<?php
/**
 * Admin dashboard: WooCommerce > Agentic AI Feed.
 *
 * Single-page dashboard with anchored card sections:
 *
 *  1. Setup checklist (3 steps with live done/not-done state)
 *  2. Product feeds (JSONL/CSV URLs, status, manual generate)
 *  3. AI discovery endpoints (llms.txt family + .well-known manifests)
 *  4. Catalog readiness (score distribution + weakest products)
 *  5. Settings (seller identity, policies, brand/GTIN sources, toggles)
 *  6. Pro teaser
 *
 * Styling lives in assets/css/admin-dashboard.css (WooCommerce-inspired
 * purple design system), enqueued only on this page.
 *
 * @package ACPFW
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers and renders the WooCommerce > Agentic AI Feed admin page.
 */
final class ACPFW_Admin_Menu {

        /**
         * Constructor.
         */
        public function __construct() {
                add_action( 'admin_menu', array( $this, 'register_menu' ) );
                add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
                add_action( 'admin_post_acpfw_generate_feeds', array( $this, 'handle_generate' ) );
                add_action( 'admin_post_acpfw_save_settings', array( $this, 'handle_save_settings' ) );
                add_action( 'admin_post_acpfw_rescore_all', array( $this, 'handle_rescore_all' ) );
                add_action( 'admin_notices', array( $this, 'action_notices' ) );
        }

        /**
         * Enqueue dashboard styles on this page only.
         *
         * @param string $hook Current admin page hook.
         * @return void
         */
        public function enqueue_assets( $hook ) {
                if ( 'woocommerce_page_acpfw-feed' !== $hook ) {
                        return;
                }

                wp_enqueue_style(
                        'acpfw-dashboard',
                        ACPFW_PLUGIN_URL . 'assets/css/admin-dashboard.css',
                        array(),
                        ACPFW_VERSION
                );
        }

        /**
         * Register the submenu under the WooCommerce menu.
         *
         * @return void
         */
        public function register_menu() {
                add_submenu_page(
                        'woocommerce',
                        __( 'Agentic AI Feed', 'agentic-commerce-ai-product-feed' ),
                        __( 'Agentic AI Feed', 'agentic-commerce-ai-product-feed' ),
                        'manage_woocommerce',
                        'acpfw-feed',
                        array( $this, 'render_page' )
                );
        }

        /**
         * Handle the manual "generate now" action (admin-post).
         *
         * @return void
         */
        public function handle_generate() {
                if ( ! current_user_can( 'manage_woocommerce' ) ) {
                        wp_die( esc_html__( 'You are not allowed to manage feeds.', 'agentic-commerce-ai-product-feed' ) );
                }

                check_admin_referer( 'acpfw_generate_feeds' );

                // Feeds first; the acpfw_feeds_generated hook refreshes llms*.txt.
                ACPFW_OpenAI_Feed_Generator::generate();

                wp_safe_redirect(
                        add_query_arg(
                                array(
                                        'page'         => 'acpfw-feed',
                                        'acpfw_notice' => 'generated',
                                ),
                                admin_url( 'admin.php' )
                        )
                );
                exit;
        }

        /**
         * Handle the settings form (admin-post).
         *
         * @return void
         */
        public function handle_save_settings() {
                if ( ! current_user_can( 'manage_woocommerce' ) ) {
                        wp_die( esc_html__( 'You are not allowed to manage settings.', 'agentic-commerce-ai-product-feed' ) );
                }

                check_admin_referer( 'acpfw_save_settings' );

                // phpcs:disable WordPress.Security.ValidatedSanitizedInput -- each field sanitized on the line it is read.
                $settings = array(
                        'seller_name'       => isset( $_POST['seller_name'] ) ? sanitize_text_field( wp_unslash( $_POST['seller_name'] ) ) : '',
                        'return_policy_url' => isset( $_POST['return_policy_url'] ) ? esc_url_raw( wp_unslash( $_POST['return_policy_url'] ) ) : '',
                        'shipping_summary'  => isset( $_POST['shipping_summary'] ) ? sanitize_textarea_field( wp_unslash( $_POST['shipping_summary'] ) ) : '',
                        'default_brand'     => isset( $_POST['default_brand'] ) ? sanitize_text_field( wp_unslash( $_POST['default_brand'] ) ) : '',
                        'brand_source'      => isset( $_POST['brand_source'] ) ? sanitize_key( wp_unslash( $_POST['brand_source'] ) ) : 'auto',
                        'gtin_source'       => isset( $_POST['gtin_source'] ) ? sanitize_text_field( wp_unslash( $_POST['gtin_source'] ) ) : '_global_unique_id',
                        'mpn_source'        => isset( $_POST['mpn_source'] ) ? sanitize_text_field( wp_unslash( $_POST['mpn_source'] ) ) : '',
                        'mpn_sku_fallback'  => ( isset( $_POST['mpn_sku_fallback'] ) && 'yes' === $_POST['mpn_sku_fallback'] ) ? 'yes' : 'no',
                        'enable_llms_txt'   => ( isset( $_POST['enable_llms_txt'] ) && 'yes' === $_POST['enable_llms_txt'] ) ? 'yes' : 'no',
                        'enable_acp_json'   => ( isset( $_POST['enable_acp_json'] ) && 'yes' === $_POST['enable_acp_json'] ) ? 'yes' : 'no',
                        'enable_ucp'        => ( isset( $_POST['enable_ucp'] ) && 'yes' === $_POST['enable_ucp'] ) ? 'yes' : 'no',
                        'uninstall_cleanup' => ( isset( $_POST['uninstall_cleanup'] ) && 'yes' === $_POST['uninstall_cleanup'] ) ? 'yes' : 'no',
                );
                // phpcs:enable

                // Keep seller name in sync with the blog name unless customized.
                if ( '' === $settings['seller_name'] ) {
                        $settings['seller_name'] = get_bloginfo( 'name' );
                }

                update_option( 'acpfw_settings', $settings, false );

                // The in-memory cache still holds the pre-save values: drop it
                // BEFORE regenerating, or llms*.txt would be rebuilt from the
                // old settings.
                acpfw()->refresh_settings();

                // Policies and toggles shape llms.txt + the manifests: rebuild now.
                if ( 'yes' === acpfw()->get_setting( 'enable_llms_txt', 'yes' ) ) {
                        ACPFW_LLMS_Txt_Generator::generate_all();
                }

                wp_safe_redirect(
                        add_query_arg(
                                array(
                                        'page'         => 'acpfw-feed',
                                        'acpfw_notice' => 'settings_saved',
                                ),
                                admin_url( 'admin.php' )
                        )
                );
                exit;
        }

        /**
         * Handle "recalculate all scores" (admin-post).
         *
         * @return void
         */
        public function handle_rescore_all() {
                if ( ! current_user_can( 'manage_woocommerce' ) ) {
                        wp_die( esc_html__( 'You are not allowed to manage products.', 'agentic-commerce-ai-product-feed' ) );
                }

                check_admin_referer( 'acpfw_rescore_all' );

                $scored = ACPFW_Readiness_Scorer::bulk_score_all();

                wp_safe_redirect(
                        add_query_arg(
                                array(
                                        'page'         => 'acpfw-feed',
                                        'acpfw_notice' => 'rescored',
                                        'acpfw_count'  => $scored,
                                ),
                                admin_url( 'admin.php' )
                        )
                );
                exit;
        }

        /**
         * Success notices for the various admin-post round trips.
         *
         * @return void
         */
        public function action_notices() {
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only confirmation banners.
                if ( ! isset( $_GET['page'], $_GET['acpfw_notice'] ) || 'acpfw-feed' !== $_GET['page'] ) {
                        return;
                }

                // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                $notice = sanitize_key( wp_unslash( $_GET['acpfw_notice'] ) );

                $messages = array(
                        'generated'      => __( 'Feeds and AI discovery files generated successfully.', 'agentic-commerce-ai-product-feed' ),
                        'settings_saved' => __( 'Settings saved. AI discovery files refreshed.', 'agentic-commerce-ai-product-feed' ),
                        'env_rechecked'  => __( 'AI crawler checks re-run.', 'agentic-commerce-ai-product-feed' ),
                );

                if ( 'rescored' === $notice ) {
                        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                        $count = absint( $_GET['acpfw_count'] );

                        printf(
                                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                                sprintf(
                                        /* translators: %d: number of products */
                                        esc_html( _n( 'AI Readiness Score recalculated for %d product.', 'AI Readiness Score recalculated for %d products.', $count, 'agentic-commerce-ai-product-feed' ) ),
                                        esc_html( number_format_i18n( $count ) )
                                )
                        );

                        return;
                }

                if ( isset( $messages[ $notice ] ) ) {
                        printf(
                                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                                esc_html( $messages[ $notice ] )
                        );
                }
        }

        /* ---------------------------------------------------------------------
         * Page rendering.
         * ------------------------------------------------------------------- */

        /**
         * Render the dashboard page.
         *
         * @return void
         */
        public function render_page() {
                $stats = ACPFW_OpenAI_Feed_Generator::get_stats();
                ?>
                <div class="wrap acpfw-wrap">

                        <?php $this->render_hero( $stats ); ?>

                        <nav class="acpfw-tabs" aria-label="<?php esc_attr_e( 'Dashboard sections', 'agentic-commerce-ai-product-feed' ); ?>">
                                <a href="#acpfw-setup"><span class="dashicons dashicons-list-view"></span><?php esc_html_e( 'Setup', 'agentic-commerce-ai-product-feed' ); ?></a>
                                <a href="#acpfw-feeds"><span class="dashicons dashicons-database-export"></span><?php esc_html_e( 'Feeds', 'agentic-commerce-ai-product-feed' ); ?></a>
                                <a href="#acpfw-discovery"><span class="dashicons dashicons-search"></span><?php esc_html_e( 'AI Discovery', 'agentic-commerce-ai-product-feed' ); ?></a>
                                <a href="#acpfw-readiness"><span class="dashicons dashicons-chart-bar"></span><?php esc_html_e( 'Readiness', 'agentic-commerce-ai-product-feed' ); ?></a>
                                <a href="#acpfw-settings"><span class="dashicons dashicons-admin-generic"></span><?php esc_html_e( 'Settings', 'agentic-commerce-ai-product-feed' ); ?></a>
                                <a href="#acpfw-pro"><span class="dashicons dashicons-superhero-alt"></span><?php esc_html_e( 'Pro', 'agentic-commerce-ai-product-feed' ); ?></a>
                        </nav>

                        <?php $this->render_setup_checklist( $stats ); ?>
                        <?php $this->render_feeds_section( $stats ); ?>
                        <?php $this->render_discovery_section(); ?>
                        <?php $this->render_readiness_section(); ?>
                        <?php $this->render_settings_section(); ?>
                        <?php $this->render_pro_section(); ?>
                </div>
                <?php
        }

        /**
         * Hero banner with plugin identity + quick stats + primary CTA.
         *
         * @param array $stats Feed stats.
         * @return void
         */
        private function render_hero( $stats ) {
                $generated = ! empty( $stats['generated_at'] );
                ?>
                <div class="acpfw-hero">
                        <div>
                                <h1 class="acpfw-hero__title">
                                        <span class="acpfw-hero__icon dashicons dashicons-store"></span>
                                        <?php esc_html_e( 'Agentic AI Feed', 'agentic-commerce-ai-product-feed' ); ?>
                                        <span class="acpfw-hero__badge"><?php echo esc_html( 'v' . ACPFW_VERSION ); ?></span>
                                </h1>
                                <p class="acpfw-hero__sub">
                                        <?php esc_html_e( 'Your WooCommerce catalog, readable for ChatGPT Shopping, AI agents and assistants — product feeds, llms.txt discovery and per-product AI Readiness Scores. 100% self-hosted.', 'agentic-commerce-ai-product-feed' ); ?>
                                </p>
                        </div>

                        <div class="acpfw-hero__actions">
                                <?php if ( $generated ) : ?>
                                        <span style="color:rgba(255,255,255,.85);font-size:12.5px;">
                                                <?php
                                                printf(
                                                        /* translators: 1: number of rows, 2: date */
                                                        esc_html__( '%1$s rows · built %2$s', 'agentic-commerce-ai-product-feed' ),
                                                        esc_html( number_format_i18n( (int) $stats['rows'] ) ),
                                                        esc_html( wp_date( get_option( 'date_format' ), (int) $stats['generated_at'] ) )
                                                );
                                                ?>
                                        </span>
                                <?php endif; ?>
                                <a class="button button-hero" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=acpfw_generate_feeds' ), 'acpfw_generate_feeds' ) ); ?>">
                                        <?php esc_html_e( 'Generate feeds', 'agentic-commerce-ai-product-feed' ); ?>
                                </a>
                        </div>
                </div>
                <?php
        }

        /**
         * Section 1: setup checklist.
         *
         * @param array $stats Feed stats.
         * @return void
         */
        private function render_setup_checklist( $stats ) {
                $settings = acpfw()->get_settings();

                $policies_done  = ( ! empty( $settings['return_policy_url'] ) || ! empty( $settings['shipping_summary'] ) );
                $feeds_done     = ! empty( $stats['generated_at'] );
                $llms_path      = trailingslashit( ACPFW_Plugin_Core::get_feed_directory() ) . ACPFW_LLMS_Txt_Generator::get_llms_filename();
                $discovery_done = file_exists( $llms_path );

                $steps = array(
                        array(
                                'done'   => $policies_done,
                                'number' => 1,
                                'title'  => __( 'Describe your store policies', 'agentic-commerce-ai-product-feed' ),
                                'text'   => $policies_done
                                        ? __( 'Return policy / shipping summary saved. AI assistants use these to answer pre-sale questions.', 'agentic-commerce-ai-product-feed' )
                                        : __( 'Add your return policy URL and a shipping summary in Settings — AI agents quote them to shoppers.', 'agentic-commerce-ai-product-feed' ),
                                'anchor' => '#acpfw-settings',
                                'button' => $policies_done ? '' : __( 'Open settings', 'agentic-commerce-ai-product-feed' ),
                        ),
                        array(
                                'done'   => $feeds_done,
                                'number' => 2,
                                'title'  => __( 'Generate your product feeds', 'agentic-commerce-ai-product-feed' ),
                                'text'   => $feeds_done
                                        ? __( 'Feeds built. They rebuild automatically once per day and after every manual refresh.', 'agentic-commerce-ai-product-feed' )
                                        : __( 'Build the OpenAI JSONL/CSV feed files from your published catalog.', 'agentic-commerce-ai-product-feed' ),
                                'anchor' => '#acpfw-feeds',
                                'button' => $feeds_done ? '' : __( 'Generate feeds', 'agentic-commerce-ai-product-feed' ),
                        ),
                        array(
                                'done'   => $discovery_done,
                                'number' => 3,
                                'title'  => __( 'Verify AI discovery endpoints', 'agentic-commerce-ai-product-feed' ),
                                'text'   => $discovery_done
                                        ? __( 'llms.txt and friends are live at your site root. Share the URLs with any AI crawler.', 'agentic-commerce-ai-product-feed' )
                                        : __( 'The llms.txt files build on first visit — or hit Generate in the Feeds section right now.', 'agentic-commerce-ai-product-feed' ),
                                'anchor' => '#acpfw-discovery',
                                'button' => $discovery_done ? '' : __( 'View discovery URLs', 'agentic-commerce-ai-product-feed' ),
                        ),
                );
                ?>
                <div class="acpfw-checklist" id="acpfw-setup">
                        <?php foreach ( $steps as $step ) : ?>
                                <div class="acpfw-check acpfw-check--<?php echo $step['done'] ? 'done' : 'todo'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static class names. ?>">
                                        <span class="acpfw-check__marker"><?php echo $step['done'] ? '✓' : (string) $step['number']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static glyphs/int. ?></span>
                                        <div>
                                                <p class="acpfw-check__title"><?php echo esc_html( $step['title'] ); ?></p>
                                                <p class="acpfw-check__text"><?php echo esc_html( $step['text'] ); ?></p>
                                                <span class="acpfw-check__state">
                                                        <?php echo $step['done'] ? esc_html__( 'Done', 'agentic-commerce-ai-product-feed' ) : esc_html__( 'To do', 'agentic-commerce-ai-product-feed' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>
                                                        <?php if ( '' !== $step['button'] ) : ?>
                                                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=acpfw-feed' ) . $step['anchor'] ); ?>"><?php echo esc_html( $step['button'] ); ?> →</a>
                                                        <?php endif; ?>
                                                </span>
                                        </div>
                                </div>
                        <?php endforeach; ?>
                </div>
                <?php
        }

        /**
         * Section 2: feed URLs + generate.
         *
         * @param array $stats Feed stats.
         * @return void
         */
        private function render_feeds_section( $stats ) {
                $generated = ! empty( $stats['generated_at'] );
                $jsonl_url = ACPFW_OpenAI_Feed_Generator::get_feed_url( 'jsonl' );
                $csv_url   = ACPFW_OpenAI_Feed_Generator::get_feed_url( 'csv' );
                ?>
                <section class="acpfw-card acpfw-section-anchor" id="acpfw-feeds">
                        <div class="acpfw-card__header">
                                <h2 class="acpfw-card__title"><span class="dashicons dashicons-database-export"></span><?php esc_html_e( 'Product feeds', 'agentic-commerce-ai-product-feed' ); ?></h2>
                                <span class="acpfw-card__hint"><?php esc_html_e( 'Rebuilds automatically once per day. Submit the JSONL URL to OpenAI Merchant Center / ChatGPT Shopping.', 'agentic-commerce-ai-product-feed' ); ?></span>
                        </div>
                        <div class="acpfw-card__body">
                                <dl style="margin:0;">
                                        <div class="acpfw-url-row">
                                                <dt class="acpfw-url-label"><?php esc_html_e( 'OpenAI JSONL feed', 'agentic-commerce-ai-product-feed' ); ?><small><?php esc_html_e( 'Primary feed for ChatGPT Shopping', 'agentic-commerce-ai-product-feed' ); ?></small></dt>
                                                <dd><input type="text" class="acpfw-url-input" readonly value="<?php echo esc_attr( $jsonl_url ); ?>" onclick="this.select()" /></dd>
                                        </div>
                                        <div class="acpfw-url-row">
                                                <dt class="acpfw-url-label"><?php esc_html_e( 'CSV feed', 'agentic-commerce-ai-product-feed' ); ?><small><?php esc_html_e( 'Same data in CSV for any comparison engine', 'agentic-commerce-ai-product-feed' ); ?></small></dt>
                                                <dd><input type="text" class="acpfw-url-input" readonly value="<?php echo esc_attr( $csv_url ); ?>" onclick="this.select()" /></dd>
                                        </div>
                                </dl>

                                <p style="margin:18px 0 0;">
                                        <a class="button button-primary acpfw-btn" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=acpfw_generate_feeds' ), 'acpfw_generate_feeds' ) ); ?>">
                                                <span class="dashicons dashicons-update-alt" style="margin-top:3px;"></span>
                                                <?php $generated ? esc_html_e( 'Regenerate feeds now', 'agentic-commerce-ai-product-feed' ) : esc_html_e( 'Generate feeds now', 'agentic-commerce-ai-product-feed' ); ?>
                                        </a>

                                        <?php if ( $generated ) : ?>
                                                <span style="margin-left:12px;color:#6b6e76;font-size:12.5px;">
                                                        <?php
                                                        printf(
                                                                /* translators: 1: number of feed rows, 2: number of products, 3: date/time */
                                                                esc_html__( '%1$s feed rows from %2$s products · last built %3$s', 'agentic-commerce-ai-product-feed' ),
                                                                esc_html( number_format_i18n( (int) $stats['rows'] ) ),
                                                                esc_html( number_format_i18n( (int) $stats['products'] ) ),
                                                                esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $stats['generated_at'] ) )
                                                        );
                                                        ?>
                                                </span>
                                        <?php endif; ?>
                                </p>
                        </div>
                </section>
                <?php
        }

        /**
         * Section 3: AI discovery endpoints + crawler monitor.
         *
         * @return void
         */
        private function render_discovery_section() {
                $settings = acpfw()->get_settings();
                $llms_on  = ( 'yes' === ( isset( $settings['enable_llms_txt'] ) ? $settings['enable_llms_txt'] : 'yes' ) );
                $acp_on   = ( 'yes' === ( isset( $settings['enable_acp_json'] ) ? $settings['enable_acp_json'] : 'yes' ) );
                $ucp_on   = ( 'yes' === ( isset( $settings['enable_ucp'] ) ? $settings['enable_ucp'] : 'yes' ) );

                $rows = array(
                        array(
                                __( 'AI store summary', 'agentic-commerce-ai-product-feed' ),
                                __( 'llms.txt — short, human/AI-readable store index', 'agentic-commerce-ai-product-feed' ),
                                ACPFW_LLMS_Txt_Generator::get_llms_url(),
                                $llms_on,
                        ),
                        array(
                                __( 'Full catalog for AI', 'agentic-commerce-ai-product-feed' ),
                                __( 'llms-full.txt — every product with price and availability', 'agentic-commerce-ai-product-feed' ),
                                ACPFW_LLMS_Txt_Generator::get_llms_full_url(),
                                $llms_on,
                        ),
                        array(
                                __( 'Commerce guide', 'agentic-commerce-ai-product-feed' ),
                                __( 'llms-commerce.txt — buying, shipping, returns, payments', 'agentic-commerce-ai-product-feed' ),
                                ACPFW_LLMS_Txt_Generator::get_llms_commerce_url(),
                                $llms_on,
                        ),
                        array(
                                __( 'ACP manifest', 'agentic-commerce-ai-product-feed' ),
                                __( 'Agentic Commerce Protocol discovery (OpenAI)', 'agentic-commerce-ai-product-feed' ),
                                home_url( '/.well-known/acp.json' ),
                                $acp_on,
                        ),
                        array(
                                __( 'UCP manifest', 'agentic-commerce-ai-product-feed' ),
                                __( 'Universal Commerce Protocol discovery', 'agentic-commerce-ai-product-feed' ),
                                home_url( '/.well-known/ucp' ),
                                $ucp_on,
                        ),
                );
                ?>
                <section class="acpfw-card acpfw-section-anchor" id="acpfw-discovery">
                        <div class="acpfw-card__header">
                                <h2 class="acpfw-card__title"><span class="dashicons dashicons-search"></span><?php esc_html_e( 'AI discovery endpoints', 'agentic-commerce-ai-product-feed' ); ?></h2>
                                <span class="acpfw-card__hint"><?php esc_html_e( 'Always live at your site root (unless switched off in Settings).', 'agentic-commerce-ai-product-feed' ); ?></span>
                        </div>
                        <div class="acpfw-card__body">
                                <dl style="margin:0;">
                                        <?php foreach ( $rows as $row ) : ?>
                                                <div class="acpfw-url-row">
                                                        <dt class="acpfw-url-label"><?php echo esc_html( $row[0] ); ?><small><?php echo esc_html( $row[1] ); ?></small></dt>
                                                        <dd>
                                                                <input type="text" class="acpfw-url-input" readonly value="<?php echo esc_attr( $row[2] ); ?>" onclick="this.select()" />
                                                                <span class="acpfw-url-status acpfw-url-status--<?php echo $row[3] ? 'on' : 'off'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static class names. ?>">
                                                                        <?php echo $row[3] ? esc_html__( 'On', 'agentic-commerce-ai-product-feed' ) : esc_html__( 'Off', 'agentic-commerce-ai-product-feed' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>
                                                                </span>
                                                        </dd>
                                                </div>
                                        <?php endforeach; ?>
                                </dl>

                                <?php ACPFW_Env_Checks::render(); ?>
                        </div>
                </section>
                <?php
        }

        /**
         * Section 4: readiness overview.
         *
         * @return void
         */
        private function render_readiness_section() {
                global $wpdb;

                $distribution = array(
                        'ready'    => 0,
                        'polish'   => 0,
                        'blocked'  => 0,
                );

                $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- aggregate read on postmeta; single admin view.
                        "SELECT pm.meta_value AS score, COUNT(*) AS n
                        FROM {$wpdb->postmeta} pm
                        INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                        WHERE pm.meta_key = '_acpfw_readiness_score'
                          AND p.post_type = 'product'
                          AND p.post_status = 'publish'
                        GROUP BY pm.meta_value"
                );

                if ( is_array( $rows ) ) {
                        foreach ( $rows as $row ) {
                                $score = (int) $row->score;

                                if ( $score >= 80 ) {
                                        $distribution['ready'] += (int) $row->n;
                                } elseif ( $score >= 50 ) {
                                        $distribution['polish'] += (int) $row->n;
                                } else {
                                        $distribution['blocked'] += (int) $row->n;
                                }
                        }
                }

                $publish_counts = wp_count_posts( 'product' );
                $total          = isset( $publish_counts->publish ) ? (int) $publish_counts->publish : 0;
                $scored         = $distribution['ready'] + $distribution['polish'] + $distribution['blocked'];
                $unscored       = max( 0, $total - $scored );
                $pct            = static function ( $n ) use ( $total ) {
                        return ( $total > 0 ) ? round( ( $n / $total ) * 100 ) : 0;
                };

                // Weakest five products for quick fixes.
                $weak = get_posts(
                        array(
                                'post_type'      => 'product',
                                'post_status'    => 'publish',
                                'posts_per_page' => 5,
                                'meta_key'       => '_acpfw_readiness_score', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- small fixed admin query.
                                'orderby'        => 'meta_value_num',
                                'order'          => 'ASC',
                        )
                );
                ?>
                <section class="acpfw-card acpfw-section-anchor" id="acpfw-readiness">
                        <div class="acpfw-card__header">
                                <h2 class="acpfw-card__title"><span class="dashicons dashicons-chart-bar"></span><?php esc_html_e( 'Catalog AI readiness', 'agentic-commerce-ai-product-feed' ); ?></h2>
                                <span class="acpfw-card__hint"><?php esc_html_e( 'Every product is scored 0-100 against seven AI-channel checks; scores update live as you edit.', 'agentic-commerce-ai-product-feed' ); ?></span>
                        </div>
                        <div class="acpfw-card__body">
                                <div class="acpfw-stats">
                                        <?php
                                        $cards = array(
                                                array( __( 'AI Ready', 'agentic-commerce-ai-product-feed' ), $distribution['ready'], 'green' ),
                                                array( __( 'Needs Polish', 'agentic-commerce-ai-product-feed' ), $distribution['polish'], 'amber' ),
                                                array( __( 'AI Blocked', 'agentic-commerce-ai-product-feed' ), $distribution['blocked'], 'red' ),
                                                array( __( 'Not scored yet', 'agentic-commerce-ai-product-feed' ), $unscored, 'gray' ),
                                        );

                                        foreach ( $cards as $card ) :
                                                ?>
                                                <div class="acpfw-stat acpfw-stat--<?php echo esc_attr( $card[2] ); ?>">
                                                        <div class="acpfw-stat__num"><?php echo esc_html( number_format_i18n( (int) $card[1] ) ); ?></div>
                                                        <div class="acpfw-stat__label"><?php echo esc_html( $card[0] ); ?></div>
                                                        <div class="acpfw-stat__bar"><span style="width:<?php echo esc_attr( (string) $pct( (int) $card[1] ) ); ?>%;background:var(--acpfw-<?php echo esc_attr( $card[2] ); ?>);"></span></div>
                                                </div>
                                        <?php endforeach; ?>
                                </div>

                                <?php if ( ! empty( $weak ) ) : ?>
                                        <p style="margin:6px 0 4px;font-weight:600;font-size:13px;"><?php esc_html_e( 'Lowest-scoring products', 'agentic-commerce-ai-product-feed' ); ?></p>
                                        <ul class="acpfw-weak">
                                                <?php foreach ( $weak as $weak_post ) : ?>
                                                        <?php
                                                        $weak_score = (int) get_post_meta( $weak_post->ID, '_acpfw_readiness_score', true );
                                                        $weak_class = ( $weak_score >= 80 ) ? 'green' : ( ( $weak_score >= 50 ) ? 'amber' : 'red' );
                                                        ?>
                                                        <li>
                                                                <span class="acpfw-weak__score" style="color:var(--acpfw-<?php echo esc_attr( $weak_class ); ?>);"><?php echo esc_html( (string) $weak_score ); ?>/100</span>
                                                                <a href="<?php echo esc_url( (string) get_edit_post_link( $weak_post->ID ) ); ?>"><?php echo esc_html( get_the_title( $weak_post ) ); ?></a>
                                                        </li>
                                                <?php endforeach; ?>
                                        </ul>
                                <?php endif; ?>

                                <p style="margin:14px 0 0;">
                                        <a class="button acpfw-btn" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=acpfw_rescore_all' ), 'acpfw_rescore_all' ) ); ?>">
                                                <span class="dashicons dashicons-awards" style="margin-top:3px;"></span>
                                                <?php esc_html_e( 'Recalculate all product scores', 'agentic-commerce-ai-product-feed' ); ?>
                                        </a>
                                </p>
                        </div>
                </section>
                <?php
        }

        /**
         * Section 5: settings form.
         *
         * @return void
         */
        private function render_settings_section() {
                $settings = acpfw()->get_settings();
                ?>
                <section class="acpfw-card acpfw-section-anchor" id="acpfw-settings">
                        <div class="acpfw-card__header">
                                <h2 class="acpfw-card__title"><span class="dashicons dashicons-admin-generic"></span><?php esc_html_e( 'Settings', 'agentic-commerce-ai-product-feed' ); ?></h2>
                                <span class="acpfw-card__hint"><?php esc_html_e( 'Saving refreshes llms.txt and the manifests automatically.', 'agentic-commerce-ai-product-feed' ); ?></span>
                        </div>
                        <div class="acpfw-card__body acpfw-settings">
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                        <input type="hidden" name="action" value="acpfw_save_settings" />
                                        <?php wp_nonce_field( 'acpfw_save_settings' ); ?>

                                        <table class="form-table" role="presentation">
                                                <tbody>
                                                        <tr>
                                                                <th scope="row"><label for="acpfw-seller-name"><?php esc_html_e( 'Seller name', 'agentic-commerce-ai-product-feed' ); ?></label></th>
                                                                <td>
                                                                        <input type="text" id="acpfw-seller-name" name="seller_name" class="regular-text" value="<?php echo esc_attr( (string) $settings['seller_name'] ); ?>" />
                                                                        <p class="description"><?php esc_html_e( 'Shown to AI assistants and inside feeds. Defaults to your site title.', 'agentic-commerce-ai-product-feed' ); ?></p>
                                                                </td>
                                                        </tr>
                                                        <tr>
                                                                <th scope="row"><label for="acpfw-return-url"><?php esc_html_e( 'Return policy URL', 'agentic-commerce-ai-product-feed' ); ?></label></th>
                                                                <td>
                                                                        <input type="url" id="acpfw-return-url" name="return_policy_url" class="regular-text" value="<?php echo esc_attr( (string) $settings['return_policy_url'] ); ?>" placeholder="https://…" />
                                                                        <p class="description"><?php esc_html_e( 'Public page describing your return policy. Used in feeds and llms-commerce.txt.', 'agentic-commerce-ai-product-feed' ); ?></p>
                                                                </td>
                                                        </tr>
                                                        <tr>
                                                                <th scope="row"><label for="acpfw-shipping"><?php esc_html_e( 'Shipping summary', 'agentic-commerce-ai-product-feed' ); ?></label></th>
                                                                <td>
                                                                        <textarea id="acpfw-shipping" name="shipping_summary" rows="3" class="large-text"><?php echo esc_textarea( (string) $settings['shipping_summary'] ); ?></textarea>
                                                                        <p class="description"><?php esc_html_e( 'Plain-text summary AI agents can quote, e.g. "Free shipping over $50, delivery in 2-5 business days via DHL." Leave empty for generic text.', 'agentic-commerce-ai-product-feed' ); ?></p>
                                                                </td>
                                                        </tr>
                                                        <tr>
                                                                <th scope="row"><label for="acpfw-default-brand"><?php esc_html_e( 'Fallback brand', 'agentic-commerce-ai-product-feed' ); ?></label></th>
                                                                <td>
                                                                        <input type="text" id="acpfw-default-brand" name="default_brand" class="regular-text" value="<?php echo esc_attr( (string) $settings['default_brand'] ); ?>" />
                                                                        <p class="description"><?php esc_html_e( 'Used only when no brand is found per product (Brands taxonomy, attributes, Yoast/Rank Math).', 'agentic-commerce-ai-product-feed' ); ?></p>
                                                                </td>
                                                        </tr>
                                                        <tr>
                                                                <th scope="row"><label for="acpfw-brand-source"><?php esc_html_e( 'Brand source', 'agentic-commerce-ai-product-feed' ); ?></label></th>
                                                                <td>
                                                                        <input type="text" id="acpfw-brand-source" name="brand_source" class="regular-text" value="<?php echo esc_attr( (string) $settings['brand_source'] ); ?>" placeholder="auto" />
                                                                        <p class="description"><?php esc_html_e( 'Leave "auto" for the automatic chain, or enter a meta key to read brands from (e.g. a brand plugin field).', 'agentic-commerce-ai-product-feed' ); ?></p>
                                                                </td>
                                                        </tr>
                                                        <tr>
                                                                <th scope="row"><label for="acpfw-gtin-source"><?php esc_html_e( 'GTIN source', 'agentic-commerce-ai-product-feed' ); ?></label></th>
                                                                <td>
                                                                        <input type="text" id="acpfw-gtin-source" name="gtin_source" class="regular-text" value="<?php echo esc_attr( (string) $settings['gtin_source'] ); ?>" />
                                                                        <p class="description"><?php esc_html_e( 'Meta key holding the barcode. "_global_unique_id" uses the WooCommerce 9.2+ built-in GTIN field. Invalid check digits are dropped automatically.', 'agentic-commerce-ai-product-feed' ); ?></p>
                                                                </td>
                                                        </tr>
                                                        <tr>
                                                                <th scope="row"><label for="acpfw-mpn-source"><?php esc_html_e( 'MPN source', 'agentic-commerce-ai-product-feed' ); ?></label></th>
                                                                <td>
                                                                        <input type="text" id="acpfw-mpn-source" name="mpn_source" class="regular-text" value="<?php echo esc_attr( (string) $settings['mpn_source'] ); ?>" placeholder="_sku" />
                                                                        <p class="description"><?php esc_html_e( 'Optional meta key holding the manufacturer part number. Empty = MPN omitted unless SKU fallback is on.', 'agentic-commerce-ai-product-feed' ); ?></p>
                                                                </td>
                                                        </tr>
                                                        <tr>
                                                                <th scope="row"><?php esc_html_e( 'SKU as MPN fallback', 'agentic-commerce-ai-product-feed' ); ?></th>
                                                                <td>
                                                                        <label>
                                                                                <input type="checkbox" name="mpn_sku_fallback" value="yes" <?php checked( 'yes', (string) $settings['mpn_sku_fallback'] ); ?> />
                                                                                <?php esc_html_e( 'Use the SKU as MPN when no manufacturer part number exists', 'agentic-commerce-ai-product-feed' ); ?>
                                                                        </label>
                                                                </td>
                                                        </tr>
                                                        <tr>
                                                                <th scope="row"><?php esc_html_e( 'AI discovery files', 'agentic-commerce-ai-product-feed' ); ?></th>
                                                                <td class="acpfw-settings__toggles">
                                                                        <label><input type="checkbox" name="enable_llms_txt" value="yes" <?php checked( 'yes', (string) $settings['enable_llms_txt'] ); ?> /> <?php esc_html_e( 'Serve /llms.txt, /llms-full.txt and /llms-commerce.txt', 'agentic-commerce-ai-product-feed' ); ?></label>
                                                                        <label><input type="checkbox" name="enable_acp_json" value="yes" <?php checked( 'yes', (string) $settings['enable_acp_json'] ); ?> /> <?php esc_html_e( 'Serve /.well-known/acp.json (ACP manifest)', 'agentic-commerce-ai-product-feed' ); ?></label>
                                                                        <label><input type="checkbox" name="enable_ucp" value="yes" <?php checked( 'yes', (string) $settings['enable_ucp'] ); ?> /> <?php esc_html_e( 'Serve /.well-known/ucp (UCP manifest)', 'agentic-commerce-ai-product-feed' ); ?></label>
                                                                </td>
                                                        </tr>
                                                        <tr>
                                                                <th scope="row"><?php esc_html_e( 'Uninstall', 'agentic-commerce-ai-product-feed' ); ?></th>
                                                                <td>
                                                                        <label>
                                                                                <input type="checkbox" name="uninstall_cleanup" value="yes" <?php checked( 'yes', (string) $settings['uninstall_cleanup'] ); ?> />
                                                                                <?php esc_html_e( 'Delete all plugin data (settings, feeds, scores) when the plugin is deleted', 'agentic-commerce-ai-product-feed' ); ?>
                                                                        </label>
                                                                </td>
                                                        </tr>
                                                </tbody>
                                        </table>

                                        <?php submit_button( __( 'Save settings', 'agentic-commerce-ai-product-feed' ) ); ?>
                                </form>
                        </div>
                </section>
                <?php
        }

        /**
         * Section 6: Pro teaser.
         *
         * @return void
         */
        private function render_pro_section() {
                ?>
                <section class="acpfw-pro acpfw-section-anchor" id="acpfw-pro">
                        <p class="acpfw-pro__title">
                                <span class="dashicons dashicons-superhero-alt"></span>
                                <?php esc_html_e( 'Go further with Pro — $79/year per site', 'agentic-commerce-ai-product-feed' ); ?>
                        </p>
                        <ul>
                                <li><span class="dashicons dashicons-yes"></span><span><?php esc_html_e( 'AI Auto-Fix: bring-your-own-key (OpenAI or Anthropic) one-click rewrites of weak descriptions and missing Q&A.', 'agentic-commerce-ai-product-feed' ); ?></span></li>
                                <li><span class="dashicons dashicons-yes"></span><span><?php esc_html_e( 'Delta sync: price/stock changes reach the feed within minutes, not tomorrow.', 'agentic-commerce-ai-product-feed' ); ?></span></li>
                                <li><span class="dashicons dashicons-yes"></span><span><?php esc_html_e( 'Agentic checkout endpoints: let approved AI agents complete purchases on-site.', 'agentic-commerce-ai-product-feed' ); ?></span></li>
                                <li><span class="dashicons dashicons-yes"></span><span><?php esc_html_e( 'Priority email support.', 'agentic-commerce-ai-product-feed' ); ?></span></li>
                        </ul>
                        <p class="acpfw-pro__note"><?php esc_html_e( 'The free version stays 100% self-hosted with unlimited products — Pro never locks your catalog data.', 'agentic-commerce-ai-product-feed' ); ?></p>
                </section>
                <?php
        }
}
