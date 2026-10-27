<?php
/**
 * Plugin Name: Agentic Commerce & AI Product Feed for WooCommerce
 * Plugin URI:  https://github.com/codextent2019/AI-Product-Feed-for-WooCommerce
 * Description: Generate OpenAI ACP & ChatGPT Shopping product feeds (JSONL/CSV), UCP/ACP discovery manifests, /llms.txt + /llms-full.txt, and per-product AI Readiness Scores. 100% self-hosted — unlimited products, no external SaaS.
 * Version:     1.0.2
 * Author:      codextent2019
 * Author URI:  https://github.com/codextent2019
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: agentic-commerce-ai-product-feed
 * Domain Path: /languages
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 *
 * WC requires at least: 7.6
 * WC tested up to:      11.0
 *
 * @package ACPFW
 */

defined( 'ABSPATH' ) || exit;

// Core constants.
define( 'ACPFW_VERSION', '1.0.2' );
define( 'ACPFW_PLUGIN_FILE', __FILE__ );
define( 'ACPFW_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ACPFW_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ACPFW_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Load the core engine class on every request. Feature modules are loaded
// through ACPFW_Plugin_Core::includes() on plugins_loaded.
require_once ACPFW_PLUGIN_DIR . 'includes/class-plugin-core.php';

/**
 * Declare compatibility with WooCommerce feature sets.
 *
 * HPOS (custom order tables) and Cart/Checkout Blocks are both safe here:
 * the free plugin never touches order storage directly, and the Pro checkout
 * endpoints use the WooCommerce order CRUD API exclusively.
 */
add_action(
        'before_woocommerce_init',
        static function () {
                if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
                        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
                        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
                }
        }
);

/**
 * Boot the plugin on plugins_loaded (priority 11 = after WooCommerce boots at 10).
 *
 * @return void
 */
function acpfw_boot() {
        if ( ! class_exists( 'WooCommerce' ) ) {
                add_action( 'admin_notices', 'acpfw_woocommerce_missing_notice' );
                return;
        }

        ACPFW_Plugin_Core::instance();
}
add_action( 'plugins_loaded', 'acpfw_boot', 11 );

/**
 * Admin notice shown when WooCommerce is not active.
 *
 * @return void
 */
function acpfw_woocommerce_missing_notice() {
        if ( ! current_user_can( 'activate_plugins' ) ) {
                return;
        }

        printf(
                '<div class="notice notice-error"><p>%s</p></div>',
                esc_html__( 'Agentic Commerce & AI Product Feed requires WooCommerce to be installed and active.', 'agentic-commerce-ai-product-feed' )
        );
}

/**
 * Plugin activation: prepare feed storage, seed default settings, register endpoints.
 *
 * @return void
 */
function acpfw_activate_plugin() {
        require_once ACPFW_PLUGIN_DIR . 'includes/class-plugin-core.php';

        ACPFW_Plugin_Core::prepare_feed_directory();

        $existing = get_option( 'acpfw_settings', false );
        if ( false === $existing ) {
                add_option(
                        'acpfw_settings',
                        array(
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
                        )
                );
        }

        ACPFW_Plugin_Core::add_rewrite_rules();
        flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'acpfw_activate_plugin' );

/**
 * Plugin deactivation: clear scheduled background jobs and rewrite cache.
 *
 * Clears BOTH schedulers: Action Scheduler recurring builds and the WP-Cron
 * single event queued by the optional "rebuild on product changes" feature
 * (a stale event would otherwise fire into a deactivated plugin).
 *
 * @return void
 */
function acpfw_deactivate_plugin() {
        if ( function_exists( 'as_unschedule_all_actions' ) ) {
                as_unschedule_all_actions( 'acpfw_generate_feeds' );
                as_unschedule_all_actions( 'acpfw_generate_feed_batch' );
                as_unschedule_all_actions( 'acpfw_delta_feed_sync' );
        }

        wp_clear_scheduled_hook( 'acpfw_rebuild_after_product_change' );
        delete_option( 'acpfw_rebuild_pending' );

        flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'acpfw_deactivate_plugin' );
