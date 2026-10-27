<?php
/**
 * Well-known + clean URL router.
 *
 * Serves the public AI-discovery endpoints from a single place:
 *
 *  /.well-known/acp.json   → ACP discovery manifest (JSON, built live)
 *  /.well-known/ucp        → UCP discovery manifest (JSON, built live)
 *  /llms.txt               → short store summary (static file)
 *  /llms-full.txt          → full catalog (static file)
 *  /llms-commerce.txt      → commerce guide (static file)
 *  /acpfw-feed/openai.jsonl→ token-named JSONL feed (proxied)
 *  /acpfw-feed/openai.csv  → token-named CSV feed (proxied)
 *
 * Static files regenerate on demand the first time they are requested after
 * a wipe, so a brand-new install answers the very first crawler hit.
 *
 * @package ACPFW
 */

defined( 'ABSPATH' ) || exit;

/**
 * Serves the public AI discovery endpoints.
 */
final class ACPFW_Well_Known_Router {

        /**
         * Serve one endpoint. Called from template_redirect; always exits.
         *
         * @param string $endpoint Endpoint key.
         * @return void
         */
        public static function serve( $endpoint ) {
                switch ( $endpoint ) {
                        case 'acp_json':
                                self::serve_acp_manifest();
                                break;
                        case 'ucp':
                                self::serve_ucp_manifest();
                                break;
                        case 'llms_txt':
                                self::serve_static_llms( self::llms_file_path( 'llms.txt' ), 'text/plain', array( 'ACPFW_LLMS_Txt_Generator', 'generate_llms_txt' ) );
                                break;
                        case 'llms_full_txt':
                                self::serve_static_llms( self::llms_file_path( 'llms-full.txt' ), 'text/plain', array( 'ACPFW_LLMS_Txt_Generator', 'generate_llms_full_txt' ) );
                                break;
                        case 'llms_commerce_txt':
                                self::serve_static_llms( self::llms_file_path( 'llms-commerce.txt' ), 'text/plain', array( 'ACPFW_LLMS_Txt_Generator', 'generate_llms_commerce_txt' ) );
                                break;
                        case 'feed_jsonl':
                                self::serve_feed_file( 'jsonl', 'application/x-ndjson' );
                                break;
                        case 'feed_csv':
                                self::serve_feed_file( 'csv', 'text/csv' );
                                break;
                        default:
                                self::send_404();
                }

                exit;
        }

        /* ---------------------------------------------------------------------
         * Manifests (built live; always current settings).
         * ------------------------------------------------------------------- */

        /**
         * Serve /.well-known/acp.json.
         *
         * @return void
         */
        private static function serve_acp_manifest() {
                if ( 'yes' !== acpfw()->get_setting( 'enable_acp_json', 'yes' ) ) {
                        self::send_404();

                        return;
                }

                $settings = acpfw()->get_settings();
                $stats    = ACPFW_OpenAI_Feed_Generator::get_stats();

                $manifest = array(
                        'protocol'     => 'acp',
                        'version'      => '2026-04-17',
                        'merchant'     => array(
                                'name'              => (string) ( isset( $settings['seller_name'] ) && '' !== $settings['seller_name'] ? $settings['seller_name'] : get_bloginfo( 'name' ) ),
                                'url'               => home_url( '/' ),
                                'currency'          => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD',
                                'return_policy_url' => (string) ( isset( $settings['return_policy_url'] ) ? $settings['return_policy_url'] : '' ),
                                'description'       => (string) get_bloginfo( 'description' ),
                                'logo'              => self::get_merchant_logo_url(),
                        ),
                        'feeds'        => array(
                                'products_jsonl' => ACPFW_OpenAI_Feed_Generator::get_feed_url( 'jsonl' ),
                                'products_csv'   => ACPFW_OpenAI_Feed_Generator::get_feed_url( 'csv' ),
                                'llms_full_txt'  => home_url( '/llms-full.txt' ),
                                'last_built'     => isset( $stats['generated_at'] ) ? gmdate( 'c', (int) $stats['generated_at'] ) : '',
                        ),
                        'capabilities' => array(
                                'catalog_search'    => true,
                                'cart_deeplink'     => true,
                                'checkout_sessions' => false,
                        ),
                        'discovery'    => array(
                                'llms_txt'          => home_url( '/llms.txt' ),
                                'llms_full_txt'     => home_url( '/llms-full.txt' ),
                                'llms_commerce_txt' => home_url( '/llms-commerce.txt' ),
                        ),
                        'policies'     => array(
                                'return_policy'    => (string) ( isset( $settings['return_policy_url'] ) ? $settings['return_policy_url'] : '' ),
                                'shipping_summary' => (string) ( isset( $settings['shipping_summary'] ) ? $settings['shipping_summary'] : '' ),
                        ),
                        'updated_at'   => gmdate( 'c' ),
                );

                /**
                 * Filter the ACP discovery manifest.
                 *
                 * @param array $manifest Manifest data.
                 */
                $manifest = apply_filters( 'acpfw_acp_manifest', $manifest );

                self::send_json( $manifest );
        }

        /**
         * Serve /.well-known/ucp.
         *
         * @return void
         */
        private static function serve_ucp_manifest() {
                if ( 'yes' !== acpfw()->get_setting( 'enable_ucp', 'yes' ) ) {
                        self::send_404();

                        return;
                }

                $settings = acpfw()->get_settings();
                $stats    = ACPFW_OpenAI_Feed_Generator::get_stats();

                $search_url = function_exists( 'rest_url' ) ? rest_url( 'acpfw/v1/ucp/catalog/search' ) : home_url( '/wp-json/acpfw/v1/ucp/catalog/search' );

                $manifest = array(
                        'protocol'     => 'ucp',
                        'version'      => '1.0',
                        'merchant'     => array(
                                'name'        => (string) ( isset( $settings['seller_name'] ) && '' !== $settings['seller_name'] ? $settings['seller_name'] : get_bloginfo( 'name' ) ),
                                'description' => (string) get_bloginfo( 'description' ),
                                'url'         => home_url( '/' ),
                        ),
                        'capabilities' => array(
                                'browse_catalog'                   => true,
                                'dev.ucp.shopping.catalog.search' => $search_url,
                                'checkout'                         => false,
                                'order_status'                     => false,
                        ),
                        'endpoints'    => array(
                                'catalog_feed'   => ACPFW_OpenAI_Feed_Generator::get_feed_url( 'jsonl' ),
                                'catalog_search' => $search_url,
                                'llms_txt'       => home_url( '/llms.txt' ),
                                'llms_full'      => home_url( '/llms-full.txt' ),
                                'llms_commerce'  => home_url( '/llms-commerce.txt' ),
                                'last_built'     => isset( $stats['generated_at'] ) ? gmdate( 'c', (int) $stats['generated_at'] ) : '',
                        ),
                        'updated_at'   => gmdate( 'c' ),
                );

                /**
                 * Filter the UCP discovery manifest.
                 *
                 * @param array $manifest Manifest data.
                 */
                $manifest = apply_filters( 'acpfw_ucp_manifest', $manifest );

                self::send_json( $manifest );
        }

        /**
         * Resolve the merchant logo URL.
         *
         * Resolution order:
         *  1. Manual override (Settings > Merchant logo URL).
         *  2. Customizer Site Logo (Appearance > Customize > Site Identity > Logo).
         *  3. Site Icon (favicon) as the last automatic fallback.
         *
         * The result is filterable via 'acpfw_merchant_logo'.
         *
         * @return string Absolute URL, or '' when the site has no logo or icon.
         */
        public static function get_merchant_logo_url() {
                $url = '';

                $override = trim( (string) acpfw()->get_setting( 'logo_url', '' ) );
                if ( '' !== $override ) {
                        $url = esc_url_raw( $override );
                }

                if ( '' === $url ) {
                        $logo_id = (int) get_theme_mod( 'custom_logo', 0 );
                        if ( $logo_id > 0 ) {
                                $attachment_url = wp_get_attachment_image_url( $logo_id, 'full' );
                                if ( is_string( $attachment_url ) && '' !== $attachment_url ) {
                                        $url = esc_url_raw( $attachment_url );
                                }
                        }
                }

                if ( '' === $url ) {
                        $icon = get_site_icon_url( 512 );
                        if ( is_string( $icon ) && '' !== $icon ) {
                                $url = esc_url_raw( $icon );
                        }
                }

                /**
                 * Filter the merchant logo exposed in the discovery manifests.
                 *
                 * @param string $url Resolved logo URL ('' when none found).
                 */
                return (string) apply_filters( 'acpfw_merchant_logo', $url );
        }

        /* ---------------------------------------------------------------------
         * Static file serving.
         * ------------------------------------------------------------------- */

        /**
         * Serve an llms*.txt static file, regenerating it on demand when absent.
         *
         * @param string   $path         Absolute file path.
         * @param string   $content_type MIME type.
         * @param callable $generator    Zero-arg generator callable for on-demand rebuild.
         * @return void
         */
        private static function serve_static_llms( $path, $content_type, $generator ) {
                if ( 'yes' !== acpfw()->get_setting( 'enable_llms_txt', 'yes' ) ) {
                        self::send_404();

                        return;
                }

                if ( ! file_exists( $path ) && is_callable( $generator ) ) {
                        call_user_func( $generator );
                }

                self::send_file( $path, $content_type );
        }

        /**
         * Serve a product feed file from the storage directory.
         *
         * @param string $type         'jsonl' or 'csv'.
         * @param string $content_type MIME type.
         * @return void
         */
        private static function serve_feed_file( $type, $content_type ) {
                $path = trailingslashit( ACPFW_Plugin_Core::get_feed_directory() ) . ACPFW_OpenAI_Feed_Generator::get_feed_filename( $type );

                self::send_file( $path, $content_type );
        }

        /* ---------------------------------------------------------------------
         * HTTP helpers.
         * ------------------------------------------------------------------- */

        /**
         * Stream a file with caching headers and If-Modified-Since 304 support.
         *
         * @param string $path         Absolute file path.
         * @param string $content_type MIME type.
         * @return void
         */
        private static function send_file( $path, $content_type ) {
                if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
                        self::send_404();

                        return;
                }

                $mtime    = (int) filemtime( $path );
                $modified = gmdate( 'D, d M Y H:i:s', $mtime ) . ' GMT';

                header( 'Content-Type: ' . $content_type . '; charset=utf-8' );
                header( 'X-Content-Type-Options: nosniff' );
                header( 'Cache-Control: public, max-age=300' );
                header( 'Last-Modified: ' . $modified );

                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- only compared, never stored.
                $if_modified = isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ? $_SERVER['HTTP_IF_MODIFIED_SINCE'] : '';

                if ( '' !== $if_modified && strtotime( $if_modified ) >= $mtime ) {
                        status_header( 304 );

                        return;
                }

                header( 'Content-Length: ' . (string) filesize( $path ) );
                readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- efficient file streaming.
        }

        /**
         * Send a JSON document.
         *
         * @param array $data Data to encode.
         * @return void
         */
        private static function send_json( $data ) {
                header( 'Content-Type: application/json; charset=utf-8' );
                header( 'X-Content-Type-Options: nosniff' );
                header( 'Cache-Control: public, max-age=300' );

                echo wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        }

        /**
         * Send a 404 with a short plain-text body.
         *
         * @return void
         */
        private static function send_404() {
                status_header( 404 );
                header( 'Content-Type: text/plain; charset=utf-8' );
                header( 'Cache-Control: no-store' );

                echo esc_html__( 'Not found.', 'agentic-commerce-ai-product-feed' );
        }

        /**
         * Absolute path for an llms file inside the feed directory.
         *
         * @param string $filename File name.
         * @return string
         */
        private static function llms_file_path( $filename ) {
                return trailingslashit( ACPFW_Plugin_Core::get_feed_directory() ) . $filename;
        }
}
