<?php
/**
 * llms.txt / llms-full.txt / llms-commerce.txt generator.
 *
 * Builds the three AI-discovery text files as static assets inside the feed
 * storage directory (uploads/acpfw-feeds/), using the same atomic tmp-rename
 * write pattern as the product feeds so crawlers never read partial files.
 *
 * - llms.txt           Short, human/AI readable store summary + links.
 * - llms-full.txt      Full browsable catalog for AI assistants (paged scan).
 * - llms-commerce.txt  Purchasing, shipping, returns and payment guide.
 *
 * The clean root URLs (/llms.txt etc.) are served by ACPFW_Well_Known_Router.
 *
 * @package ACPFW
 */

defined( 'ABSPATH' ) || exit;

/**
 * Generates the llms*.txt discovery files.
 */
final class ACPFW_LLMS_Txt_Generator {

        const PAGE_SIZE = 100;

        /**
         * File names inside the feed storage directory.
         *
         * @return string
         */
        public static function get_llms_filename() {
                return 'llms.txt';
        }

        /**
         * File name for the full catalog file.
         *
         * @return string
         */
        public static function get_llms_full_filename() {
                return 'llms-full.txt';
        }

        /**
         * File name for the commerce guide.
         *
         * @return string
         */
        public static function get_llms_commerce_filename() {
                return 'llms-commerce.txt';
        }

        /**
         * Public clean URLs (served through the router).
         *
         * @return string
         */
        public static function get_llms_url() {
                return home_url( '/llms.txt' );
        }

        /**
         * Full catalog URL.
         *
         * @return string
         */
        public static function get_llms_full_url() {
                return home_url( '/llms-full.txt' );
        }

        /**
         * Commerce guide URL.
         *
         * @return string
         */
        public static function get_llms_commerce_url() {
                return home_url( '/llms-commerce.txt' );
        }

        /**
         * Absolute path helper.
         *
         * @param string $filename File name.
         * @return string
         */
        private static function get_path( $filename ) {
                return trailingslashit( ACPFW_Plugin_Core::get_feed_directory() ) . $filename;
        }

        /**
         * Rebuild all three discovery files.
         *
         * @return void
         */
        public static function generate_all() {
                if ( 'yes' !== acpfw()->get_setting( 'enable_llms_txt', 'yes' ) ) {
                        return;
                }

                self::generate_llms_txt();
                self::generate_llms_commerce_txt();
                self::generate_llms_full_txt();
        }

        /**
         * Build /llms.txt (short discovery file, llmstxt.org format).
         *
         * @return bool True when the file was written.
         */
        public static function generate_llms_txt() {
                $settings  = acpfw()->get_settings();
                $store     = (string) ( isset( $settings['seller_name'] ) && '' !== $settings['seller_name'] ? $settings['seller_name'] : get_bloginfo( 'name' ) );
                $tagline   = (string) get_bloginfo( 'description' );
                $lines     = array();

                $lines[] = '# ' . $store;
                $lines[] = '';

                if ( '' !== $tagline ) {
                        $lines[] = '> ' . $tagline;
                        $lines[] = '';
                }

                $lines[] = sprintf(
                        /* translators: %s: store name */
                        __( '%s is a WooCommerce-powered online store. The links below let AI assistants and agents browse the full product catalog, understand purchasing policies, and access the machine-readable product feed.', 'agentic-commerce-ai-product-feed' ),
                        $store
                );
                $lines[] = '';

                // Commerce section: machine files first (most useful to agents).
                $lines[] = '## ' . __( 'Commerce data', 'agentic-commerce-ai-product-feed' );
                $lines[] = '';
                $lines[] = '- [' . __( 'Full product catalog', 'agentic-commerce-ai-product-feed' ) . '](' . self::get_llms_full_url() . '): ' . __( 'every purchasable product with prices, availability and links', 'agentic-commerce-ai-product-feed' );
                $lines[] = '- [' . __( 'Commerce guide', 'agentic-commerce-ai-product-feed' ) . '](' . self::get_llms_commerce_url() . '): ' . __( 'how to buy, shipping, returns, payments and support', 'agentic-commerce-ai-product-feed' );

                if ( 'yes' === acpfw()->get_setting( 'enable_acp_json', 'yes' ) ) {
                        $lines[] = '- [' . __( 'ACP discovery manifest', 'agentic-commerce-ai-product-feed' ) . '](' . home_url( '/.well-known/acp.json' ) . '): ' . __( 'machine-readable store + feed metadata', 'agentic-commerce-ai-product-feed' );
                }

                if ( 'yes' === acpfw()->get_setting( 'enable_ucp', 'yes' ) ) {
                        $lines[] = '- [' . __( 'UCP discovery manifest', 'agentic-commerce-ai-product-feed' ) . '](' . home_url( '/.well-known/ucp' ) . '): ' . __( 'universal commerce protocol discovery', 'agentic-commerce-ai-product-feed' );
                }

                $jsonl_url = ACPFW_OpenAI_Feed_Generator::get_feed_url( 'jsonl' );
                $csv_url   = ACPFW_OpenAI_Feed_Generator::get_feed_url( 'csv' );

                if ( '' !== $jsonl_url ) {
                        $lines[] = '- [' . __( 'OpenAI product feed (JSONL)', 'agentic-commerce-ai-product-feed' ) . '](' . $jsonl_url . '): ' . __( 'ChatGPT Shopping product feed', 'agentic-commerce-ai-product-feed' );
                }

                if ( '' !== $csv_url ) {
                        $lines[] = '- [' . __( 'Product feed (CSV)', 'agentic-commerce-ai-product-feed' ) . '](' . $csv_url . '): ' . __( 'same feed in CSV format', 'agentic-commerce-ai-product-feed' );
                }

                $lines[] = '';

                // Shopping section: standard WC pages.
                $lines[] = '## ' . __( 'Shopping', 'agentic-commerce-ai-product-feed' );
                $lines[] = '';
                $lines[] = '- [' . __( 'All products', 'agentic-commerce-ai-product-feed' ) . '](' . self::get_wc_page_url( 'shop' ) . ')';
                $lines[] = '- [' . __( 'Cart', 'agentic-commerce-ai-product-feed' ) . '](' . self::get_wc_page_url( 'cart' ) . ')';
                $lines[] = '- [' . __( 'Checkout', 'agentic-commerce-ai-product-feed' ) . '](' . self::get_wc_page_url( 'checkout' ) . ')';
                $lines[] = '- [' . __( 'My account', 'agentic-commerce-ai-product-feed' ) . '](' . self::get_wc_page_url( 'myaccount' ) . ')';
                $lines[] = '';

                // Policies section.
                $policies = array();

                if ( ! empty( $settings['return_policy_url'] ) ) {
                        $policies[] = '- [' . __( 'Return policy', 'agentic-commerce-ai-product-feed' ) . '](' . esc_url_raw( $settings['return_policy_url'] ) . ')';
                }

                $privacy_id = (int) get_option( 'wp_page_for_privacy_policy' );

                if ( $privacy_id ) {
                        $policies[] = '- [' . __( 'Privacy policy', 'agentic-commerce-ai-product-feed' ) . '](' . get_permalink( $privacy_id ) . ')';
                }

                if ( ! empty( $policies ) ) {
                        $lines[] = '## ' . __( 'Policies', 'agentic-commerce-ai-product-feed' );
                        $lines[] = '';
                        $lines   = array_merge( $lines, $policies );
                        $lines[] = '';
                }

                return self::write_file( self::get_path( self::get_llms_filename() ), implode( "\n", $lines ) );
        }

        /**
         * Build /llms-commerce.txt (purchasing + policy guide).
         *
         * @return bool
         */
        public static function generate_llms_commerce_txt() {
                $settings = acpfw()->get_settings();
                $store    = (string) ( isset( $settings['seller_name'] ) && '' !== $settings['seller_name'] ? $settings['seller_name'] : get_bloginfo( 'name' ) );
                $currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '';
                $lines    = array();

                $lines[] = '# ' . sprintf( /* translators: %s: store name */ __( 'Commerce guide: %s', 'agentic-commerce-ai-product-feed' ), $store );
                $lines[] = '';

                // How to buy.
                $lines[] = '## ' . __( 'How to buy', 'agentic-commerce-ai-product-feed' );
                $lines[] = '';
                $lines[] = sprintf(
                        /* translators: %s: checkout URL */
                        __( 'Customers browse products at %1$s and check out at %2$s. Each product page supports standard WooCommerce add-to-cart; an AI agent session (agentic checkout) is planned for the Pro version.', 'agentic-commerce-ai-product-feed' ),
                        self::get_wc_page_url( 'shop' ),
                        self::get_wc_page_url( 'checkout' )
                );
                $lines[] = '';

                // Currency + prices.
                if ( '' !== $currency ) {
                        $lines[] = '## ' . __( 'Currency and prices', 'agentic-commerce-ai-product-feed' );
                        $lines[] = '';
                        $lines[] = sprintf(
                                /* translators: %s: currency code, e.g. USD */
                                __( 'All prices are shown in %s and include applicable taxes as configured by the store.', 'agentic-commerce-ai-product-feed' ),
                                $currency
                        );
                        $lines[] = '';
                }

                // Shipping.
                $lines[] = '## ' . __( 'Shipping', 'agentic-commerce-ai-product-feed' );
                $lines[] = '';
                $shipping = (string) ( isset( $settings['shipping_summary'] ) ? $settings['shipping_summary'] : '' );

                if ( '' !== $shipping ) {
                        $lines[] = $shipping;
                } else {
                        $lines[] = __( 'Shipping options and rates are calculated at checkout based on the delivery address. Contact the store for details before ordering.', 'agentic-commerce-ai-product-feed' );
                }

                $lines[] = '';

                // Returns.
                $lines[] = '## ' . __( 'Returns and refunds', 'agentic-commerce-ai-product-feed' );
                $lines[] = '';

                if ( ! empty( $settings['return_policy_url'] ) ) {
                        $lines[] = sprintf(
                                /* translators: %s: return policy URL */
                                __( 'The full return policy is published at %s.', 'agentic-commerce-ai-product-feed' ),
                                esc_url_raw( $settings['return_policy_url'] )
                        );
                } else {
                        $lines[] = __( 'The store has not published a dedicated return policy URL yet. Contact the store for return instructions.', 'agentic-commerce-ai-product-feed' );
                }

                $lines[] = '';

                // Payment methods.
                $lines[] = '## ' . __( 'Accepted payments', 'agentic-commerce-ai-product-feed' );
                $lines[] = '';
                $gateways = self::get_enabled_gateway_titles();

                if ( ! empty( $gateways ) ) {
                        foreach ( $gateways as $gateway_title ) {
                                $lines[] = '- ' . $gateway_title;
                        }
                } else {
                        $lines[] = '- ' . __( 'Payment methods are configured at checkout.', 'agentic-commerce-ai-product-feed' );
                }

                $lines[] = '';

                // Contact.
                $lines[] = '## ' . __( 'Contact', 'agentic-commerce-ai-product-feed' );
                $lines[] = '';
                $admin_email = (string) get_option( 'admin_email' );

                if ( '' !== $admin_email ) {
                        $lines[] = '- ' . sprintf( /* translators: %s: email address */ __( 'Email: %s', 'agentic-commerce-ai-product-feed' ), $admin_email );
                }

                $lines[] = '- ' . sprintf( /* translators: %s: home URL */ __( 'Website: %s', 'agentic-commerce-ai-product-feed' ), home_url( '/' ) );
                $lines[] = '';

                return self::write_file( self::get_path( self::get_llms_commerce_filename() ), implode( "\n", $lines ) );
        }

        /**
         * Build /llms-full.txt (full catalog, paged + streamed).
         *
         * @return bool
         */
        public static function generate_llms_full_txt() {
                $settings = acpfw()->get_settings();
                $store    = (string) ( isset( $settings['seller_name'] ) && '' !== $settings['seller_name'] ? $settings['seller_name'] : get_bloginfo( 'name' ) );
                $currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '';
                $path     = self::get_path( self::get_llms_full_filename() );
                $tmp      = $path . '.tmp-' . uniqid( '', true );

                ACPFW_Plugin_Core::prepare_feed_directory();

                $handle = fopen( $tmp, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- atomic temp-file streaming.

                if ( ! $handle ) {
                        wp_delete_file( $tmp );

                        return false;
                }

                $header = '# ' . sprintf( /* translators: %s: store name */ __( '%s — full product catalog', 'agentic-commerce-ai-product-feed' ), $store ) . "\n\n"
                        . sprintf(
                                /* translators: %s: date */
                                __( 'Generated on %s for AI assistants. Prices are in %s.', 'agentic-commerce-ai-product-feed' ),
                                wp_date( get_option( 'date_format' ) ),
                                '' !== $currency ? $currency : __( 'store currency', 'agentic-commerce-ai-product-feed' )
                        ) . "\n\n"
                        . __( 'Format: each product lists its URL, price, availability, brand and a short description. Visit the product URL for the live page.', 'agentic-commerce-ai-product-feed' ) . "\n\n";

                fwrite( $handle, $header ); // phpcs:ignore WordPress.WP.AlternativeFunctions.

                $page     = 1;
                $count    = 0;
                $products = 0;

                do {
                        $product_ids = wc_get_products(
                                array(
                                        'status'  => 'publish',
                                        'limit'   => self::PAGE_SIZE,
                                        'page'    => $page,
                                        'orderby' => 'title',
                                        'order'   => 'ASC',
                                        'return'  => 'ids',
                                )
                        );

                        if ( ! is_array( $product_ids ) ) {
                                break;
                        }

                        foreach ( $product_ids as $product_id ) {
                                $product = wc_get_product( $product_id );

                                if ( ! $product instanceof WC_Product || 'grouped' === $product->get_type() ) {
                                        continue;
                                }

                                if ( 'yes' === get_post_meta( $product_id, '_acpfw_exclude_from_feed', true ) ) {
                                        continue;
                                }

                                fwrite( $handle, self::render_product_block( $product, $currency ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.
                                $count++;
                                $products++;
                        }

                        $page++;
                } while ( count( $product_ids ) === self::PAGE_SIZE );

                fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.

                rename( $tmp, $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.

                return true;
        }

        /**
         * Render one product block for llms-full.txt.
         *
         * @param WC_Product $product  Product object (any type).
         * @param string     $currency Currency code.
         * @return string
         */
        private static function render_product_block( $product, $currency ) {
                $product_id = $product->get_id();
                $brand      = ACPFW_Product_Mapper::get_resolved_brand( $product_id );
                $price      = self::resolve_price_text( $product, $currency );

                $lines = array();
                $lines[] = '## ' . self::clean( $product->get_name(), 150 );
                $lines[] = '';
                $lines[] = '- URL: ' . esc_url_raw( (string) $product->get_permalink() );
                $lines[] = '- ' . __( 'Price', 'agentic-commerce-ai-product-feed' ) . ': ' . $price;
                $lines[] = '- ' . __( 'Availability', 'agentic-commerce-ai-product-feed' ) . ': ' . self::availability_label( $product );

                if ( '' !== $brand ) {
                        $lines[] = '- ' . __( 'Brand', 'agentic-commerce-ai-product-feed' ) . ': ' . self::clean( $brand, 100 );
                }

                $sku = (string) $product->get_sku();

                if ( '' !== $sku ) {
                        $lines[] = '- SKU: ' . $sku;
                }

                $identifiers = ACPFW_Product_Mapper::get_scoring_identifiers( $product_id );

                if ( ! empty( $identifiers['has_gtin'] ) ) {
                        $settings_local = function_exists( 'acpfw' ) ? acpfw()->get_settings() : array();
                        $gtin_source    = isset( $settings_local['gtin_source'] ) ? (string) $settings_local['gtin_source'] : '_global_unique_id';

                        if ( '_global_unique_id' === $gtin_source ) {
                                $gtin = (string) get_post_meta( $product_id, '_global_unique_id', true );
                        } else {
                                $gtin = (string) get_post_meta( $product_id, $gtin_source, true );
                        }

                        if ( '' !== $gtin ) {
                                $lines[] = '- GTIN: ' . $gtin;
                        }
                }

                $breadcrumb = self::get_category_line( $product_id );

                if ( '' !== $breadcrumb ) {
                        $lines[] = '- ' . __( 'Categories', 'agentic-commerce-ai-product-feed' ) . ': ' . $breadcrumb;
                }

                if ( $product->is_type( 'variable' ) ) {
                        $variation_count = count( $product->get_children() );

                        if ( $variation_count > 0 ) {
                                $lines[] = '- ' . sprintf( /* translators: %d: number of variations */ __( 'Available in %d variants (sizes/colors etc.)', 'agentic-commerce-ai-product-feed' ), $variation_count );
                        }
                }

                $description = wp_strip_all_tags( strip_shortcodes( (string) $product->get_short_description() ) );

                if ( '' === trim( $description ) ) {
                        $description = wp_strip_all_tags( strip_shortcodes( (string) $product->get_description() ) );
                }

                $description = trim( $description );

                if ( '' !== $description ) {
                        $lines[] = '';
                        $lines[] = self::clean( $description, 400 );
                }

                $lines[] = '';
                $lines[] = '';

                return implode( "\n", $lines );
        }

        /**
         * Price text for a product (handles variable ranges and sales).
         * Always includes the currency for real prices; informational
         * fallbacks ("See product page") stay plain so the currency is never
         * glued onto a sentence.
         *
         * @param WC_Product $product  Product object.
         * @param string     $currency Currency code.
         * @return string
         */
        private static function resolve_price_text( $product, $currency ) {
                if ( $product->is_type( 'variable' ) ) {
                        $min = $product->get_variation_price( 'min', true );
                        $max = $product->get_variation_price( 'max', true );

                        if ( null === $min ) {
                                return __( 'See product page', 'agentic-commerce-ai-product-feed' );
                        }

                        $min = number_format( (float) $min, 2, '.', '' );
                        $max = number_format( (float) $max, 2, '.', '' );

                        return ( $min === $max ) ? $min . ' ' . $currency : $min . ' - ' . $max . ' ' . $currency;
                }

                $price = (string) $product->get_price();

                if ( '' === $price || '0' === $price || '0.00' === $price ) {
                        return __( 'See product page', 'agentic-commerce-ai-product-feed' );
                }

                if ( (float) $product->get_sale_price() > 0 && (float) $product->get_sale_price() < (float) $product->get_regular_price() ) {
                        return number_format( (float) $product->get_regular_price(), 2, '.', '' ) . ' ' . $currency . ' ' . __( '(on sale: ', 'agentic-commerce-ai-product-feed' ) . number_format( (float) $product->get_sale_price(), 2, '.', '' ) . ' ' . $currency . ')';
                }

                return number_format( (float) $price, 2, '.', '' ) . ' ' . $currency;
        }

        /**
         * Human-friendly availability label.
         *
         * @param WC_Product $product Product object.
         * @return string
         */
        private static function availability_label( $product ) {
                switch ( $product->get_stock_status() ) {
                        case 'instock':
                                return __( 'In stock', 'agentic-commerce-ai-product-feed' );
                        case 'outofstock':
                                return __( 'Out of stock', 'agentic-commerce-ai-product-feed' );
                        case 'onbackorder':
                                return __( 'Available on backorder', 'agentic-commerce-ai-product-feed' );
                        default:
                                return __( 'Unknown', 'agentic-commerce-ai-product-feed' );
                }
        }

        /**
         * Comma-separated category breadcrumb (deepest path per category).
         *
         * @param int $product_id Product ID.
         * @return string
         */
        private static function get_category_line( $product_id ) {
                $terms = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'all' ) );

                if ( is_wp_error( $terms ) || empty( $terms ) ) {
                        return '';
                }

                $names = array();

                foreach ( $terms as $term ) {
                        $names[] = $term->name;
                }

                return self::clean( implode( ', ', array_slice( $names, 0, 5 ) ), 200 );
        }

        /**
         * Titles of enabled payment gateways.
         *
         * @return array
         */
        private static function get_enabled_gateway_titles() {
                if ( ! function_exists( 'WC' ) || ! isset( WC()->payment_gateways ) ) {
                        return array();
                }

                $gateways = WC()->payment_gateways->get_available_payment_gateways();
                $titles   = array();

                foreach ( (array) $gateways as $gateway ) {
                        if ( is_object( $gateway ) && method_exists( $gateway, 'get_title' ) ) {
                                $titles[] = (string) $gateway->get_title();
                        }
                }

                return $titles;
        }

        /**
         * URL of a WooCommerce page (shop/cart/checkout/myaccount).
         *
         * @param string $page WC page option key.
         * @return string
         */
        private static function get_wc_page_url( $page ) {
                if ( function_exists( 'wc_get_page_permalink' ) ) {
                        return (string) wc_get_page_permalink( $page );
                }

                return home_url( '/' );
        }

        /**
         * Clean + truncate a text value for plain-text output.
         *
         * @param string $text      Raw text.
         * @param int    $max_chars Max characters.
         * @return string
         */
        private static function clean( $text, $max_chars ) {
                $text = trim( preg_replace( '/\s+/u', ' ', (string) wp_strip_all_tags( strip_shortcodes( $text ) ) ) );

                if ( function_exists( 'mb_strlen' ) && mb_strlen( $text ) > $max_chars ) {
                        $text = mb_substr( $text, 0, $max_chars - 1 ) . '…';
                } elseif ( ! function_exists( 'mb_strlen' ) && strlen( $text ) > $max_chars ) {
                        $text = substr( $text, 0, $max_chars - 1 ) . '…';
                }

                return $text;
        }

        /**
         * Atomic write: stream text into .tmp then rename into place.
         *
         * @param string $path     Final file path.
         * @param string $contents File contents.
         * @return bool
         */
        private static function write_file( $path, $contents ) {
                ACPFW_Plugin_Core::prepare_feed_directory();

                $tmp    = $path . '.tmp-' . uniqid( '', true );
                $handle = fopen( $tmp, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- atomic temp-file write.

                if ( ! $handle ) {
                        wp_delete_file( $tmp );

                        return false;
                }

                fwrite( $handle, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.
                fclose( $handle );            // phpcs:ignore WordPress.WP.AlternativeFunctions.

                rename( $tmp, $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.

                return true;
        }
}
