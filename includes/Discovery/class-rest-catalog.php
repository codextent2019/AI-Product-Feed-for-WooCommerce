<?php
/**
 * Public read-only catalog search REST endpoint for AI agents.
 *
 * GET /wp-json/acpfw/v1/ucp/catalog/search
 *
 * Query params: q (search text), page (1+), per_page (1-50, default 20).
 * Read-only, unauthenticated, and write-protected by design: it exposes the
 * same publishable catalog rows the feeds export, mapped to the same
 * wc_{id} item identifiers, so a UCP agent can query live inventory without
 * downloading the whole JSONL first.
 *
 * @package ACPFW
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers and serves the UCP catalog search route.
 */
final class ACPFW_Rest_Catalog {

        /**
         * Maximum items per page.
         *
         * @var int
         */
        const MAX_PER_PAGE = 50;

        /**
         * Wire up REST registration.
         *
         * @return void
         */
        public static function init() {
                add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
        }

        /**
         * Register the search route.
         *
         * @return void
         */
        public static function register_routes() {
                register_rest_route(
                        'acpfw/v1',
                        '/ucp/catalog/search',
                        array(
                                'methods'             => WP_REST_Server::READABLE,
                                'callback'            => array( __CLASS__, 'handle_search' ),
                                'permission_callback' => '__return_true', // Public read-only catalog data, identical to the public feed files.
                                'args'                => array(
                                        'q'        => array(
                                                'type'              => 'string',
                                                'required'          => false,
                                                'sanitize_callback' => 'sanitize_text_field',
                                        ),
                                        'page'     => array(
                                                'type'              => 'integer',
                                                'required'          => false,
                                                'sanitize_callback' => 'absint',
                                        ),
                                        'per_page' => array(
                                                'type'              => 'integer',
                                                'required'          => false,
                                                'sanitize_callback' => 'absint',
                                        ),
                                ),
                        )
                );
        }

        /**
         * Serve one page of catalog results.
         *
         * @param WP_REST_Request $request Request object.
         * @return WP_REST_Response
         */
        public static function handle_search( $request ) {
                $q        = trim( (string) ( $request->get_param( 'q' ) !== null ? $request->get_param( 'q' ) : '' ) );
                $page     = max( 1, (int) $request->get_param( 'page' ) );
                $per_page = (int) $request->get_param( 'per_page' );

                if ( $per_page <= 0 ) {
                        $per_page = 20;
                }

                $per_page = min( self::MAX_PER_PAGE, $per_page );

                // Fetch one extra row so we can report has_more without a count query.
                $args = array(
                        'status'   => 'publish',
                        'limit'    => $per_page + 1,
                        'page'     => $page,
                        'orderby'  => '' !== $q ? 'relevance' : 'title',
                        'order'    => 'ASC',
                        'return'   => 'objects',
                );

                if ( '' !== $q ) {
                        $args['search'] = $q;
                }

                $products = wc_get_products( $args );

                if ( ! is_array( $products ) ) {
                        $products = array();
                }

                $has_more = count( $products ) > $per_page;

                if ( $has_more ) {
                        $products = array_slice( $products, 0, $per_page );
                }

                $items = array();

                foreach ( $products as $product ) {
                        if ( ! $product instanceof WC_Product ) {
                                continue;
                        }

                        // Same opt-out the feeds honor.
                        if ( 'yes' === get_post_meta( $product->get_id(), '_acpfw_exclude_from_feed', true ) ) {
                                continue;
                        }

                        $item = array(
                                'item_id'     => 'wc_' . $product->get_id(),
                                'title'       => ACPFW_Product_Mapper::format_title( $product ),
                                'url'         => (string) $product->get_permalink(),
                                'price'       => self::format_price( (float) $product->get_regular_price() ),
                                'availability' => self::availability( $product ),
                                'brand'       => ACPFW_Product_Mapper::get_resolved_brand( $product->get_id() ),
                                'type'        => $product->get_type(),
                        );

                        $sale = (float) $product->get_sale_price();

                        if ( $product->is_on_sale() && $sale > 0 ) {
                                $item['sale_price'] = self::format_price( $sale );
                        }

                        $image_id = (int) $product->get_image_id();

                        if ( $image_id > 0 ) {
                                $image = ACPFW_Image_Resolver::get_feed_url( $image_id );

                                if ( '' !== $image ) {
                                        $item['image_url'] = $image;
                                }
                        }

                        if ( $product->is_type( 'variable' ) ) {
                                $counts = ACPFW_Product_Mapper::get_variation_export_counts( $product->get_id() );

                                $item['group_id']            = 'wc_' . $product->get_id();
                                $item['listing_has_variations'] = true;
                                $item['purchasable_variants'] = (int) $counts['exportable'];
                        }

                        $items[] = $item;
                }

                $response = array(
                        'query'    => $q,
                        'page'     => $page,
                        'per_page' => $per_page,
                        'count'    => count( $items ),
                        'has_more' => $has_more,
                        'items'    => $items,
                        'feeds'    => array(
                                'products_jsonl' => ACPFW_OpenAI_Feed_Generator::get_feed_url( 'jsonl' ),
                                'products_csv'   => ACPFW_OpenAI_Feed_Generator::get_feed_url( 'csv' ),
                        ),
                );

                return rest_ensure_response( $response );
        }

        /**
         * Feed-style money string, e.g. "79.99 USD".
         *
         * @param float $amount Raw amount.
         * @return string
         */
        private static function format_price( $amount ) {
                return number_format( $amount, 2, '.', '' ) . ' ' . get_woocommerce_currency();
        }

        /**
         * Map WooCommerce stock status to the OpenAI availability enum.
         *
         * @param WC_Product $product Product object.
         * @return string
         */
        private static function availability( $product ) {
                switch ( $product->get_stock_status() ) {
                        case 'instock':
                                return 'in_stock';
                        case 'outofstock':
                                return 'out_of_stock';
                        case 'onbackorder':
                                return 'backorder';
                        default:
                                return 'unknown';
                }
        }
}
