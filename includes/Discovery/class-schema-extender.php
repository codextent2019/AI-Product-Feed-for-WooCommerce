<?php
/**
 * Product JSON-LD enrichment (schema extender).
 *
 * Injects the commerce-critical schema.org properties AI shopping agents
 * look for — hasMerchantReturnPolicy, shippingDetails and a conversational
 * FAQPage graph node built from the per-product Q&A pairs — into whatever
 * structured data the site already emits:
 *
 *  - Yoast SEO active  → wpseo_schema_graph filter (extends their graph).
 *  - Rank Math active  → rank_math/schema/json filter (extends their graph).
 *  - Neither active    → WooCommerce core still prints Product JSON-LD, so the
 *                        offers are enriched via woocommerce_structured_data_product
 *                        and the FAQPage node is printed as a standalone block.
 *
 * No data is ever invented: the return policy is only added when a real,
 * non-placeholder return-policy URL is configured, and shippingDetails only
 * when the merchant provides a rate via the acpfw_schema_shipping_rate filter.
 * Products excluded from AI feeds via the metabox toggle are skipped here too.
 *
 * @package ACPFW
 */

defined( 'ABSPATH' ) || exit;

/**
 * Enriches Product JSON-LD across SEO-plugin stacks.
 */
final class ACPFW_Schema_Extender {

        /**
         * Wire up the filters.
         *
         * @return void
         */
        public static function init() {
                add_filter( 'woocommerce_structured_data_product', array( __CLASS__, 'enrich_wc_product_schema' ), 10, 2 );
                add_filter( 'wpseo_schema_graph', array( __CLASS__, 'enrich_yoast_graph' ), 10, 2 );
                add_filter( 'rank_math/schema/json', array( __CLASS__, 'enrich_rankmath_schema' ), 10, 2 );
                add_action( 'wp_footer', array( __CLASS__, 'maybe_print_faq_schema' ), 20 );
        }

        /* -----------------------------------------------------------------
         * WooCommerce core Product schema (all stacks; add-if-missing).
         * ----------------------------------------------------------------- */

        /**
         * Add return-policy + shipping details to WooCommerce's Product offers.
         *
         * @param array      $markup  Product schema piece.
         * @param WC_Product $product Product object.
         * @return array
         */
        public static function enrich_wc_product_schema( $markup, $product ) {
                if ( ! is_array( $markup ) || ! $product instanceof WC_Product || self::is_excluded( $product->get_id() ) ) {
                        return $markup;
                }

                if ( isset( $markup['offers'] ) ) {
                        if ( isset( $markup['offers']['@type'] ) ) {
                                self::enrich_offer( $markup['offers'], $product->get_id() );
                        } elseif ( is_array( $markup['offers'] ) ) {
                                foreach ( $markup['offers'] as $key => $offer ) {
                                        if ( is_array( $offer ) ) {
                                                self::enrich_offer( $markup['offers'][ $key ], $product->get_id() );
                                        }
                                }
                        }
                }

                return $markup;
        }

        /* -----------------------------------------------------------------
         * Yoast SEO graph.
         * ----------------------------------------------------------------- */

        /**
         * Extend the Yoast graph: enrich Product offers, append FAQPage.
         *
         * @param array $graph   Full schema graph pieces.
         * @param mixed $context Yoast meta context.
         * @return array
         */
        public static function enrich_yoast_graph( $graph, $context = null ) {
                if ( ! is_array( $graph ) || ! is_singular( 'product' ) ) {
                        return $graph;
                }

                $product_id = (int) get_queried_object_id();

                if ( $product_id <= 0 || self::is_excluded( $product_id ) ) {
                        return $graph;
                }

                $product = wc_get_product( $product_id );

                if ( ! $product instanceof WC_Product ) {
                        return $graph;
                }

                $has_faq = false;

                foreach ( $graph as $key => $piece ) {
                        if ( ! is_array( $piece ) || ! self::is_product_piece( $piece ) ) {
                                continue;
                        }

                        if ( isset( $piece['offers'] ) ) {
                                if ( isset( $piece['offers']['@type'] ) ) {
                                        self::enrich_offer( $graph[ $key ]['offers'], $product_id );
                                } elseif ( is_array( $piece['offers'] ) ) {
                                        foreach ( $piece['offers'] as $okey => $offer ) {
                                                if ( is_array( $offer ) ) {
                                                        self::enrich_offer( $graph[ $key ]['offers'][ $okey ], $product_id );
                                                }
                                        }
                                }
                        }
                }

                if ( ! self::graph_has_faq( $graph ) ) {
                        $faq = self::build_faq_piece( $product );

                        if ( is_array( $faq ) ) {
                                $graph[] = $faq;
                                $has_faq = true;
                        }
                }

                unset( $context ); // Yoast passes a context object we do not need.

                return $graph;
        }

        /* -----------------------------------------------------------------
         * Rank Math graph.
         * ----------------------------------------------------------------- */

        /**
         * Extend the Rank Math schema: enrich Product offers, append FAQPage.
         *
         * @param array  $schema Full schema array (graph or flat list).
         * @param object $object Current object (unused).
         * @return array
         */
        public static function enrich_rankmath_schema( $schema, $object = null ) {
                if ( ! is_array( $schema ) || ! is_singular( 'product' ) ) {
                        return $schema;
                }

                $product_id = (int) get_queried_object_id();

                if ( $product_id <= 0 || self::is_excluded( $product_id ) ) {
                        return $schema;
                }

                $product = wc_get_product( $product_id );

                if ( ! $product instanceof WC_Product ) {
                        return $schema;
                }

                // Rank Math nests pieces under '@graph' or ships a flat list.
                $is_graph = isset( $schema['@graph'] ) && is_array( $schema['@graph'] );
                $pieces   = $is_graph ? $schema['@graph'] : $schema;

                foreach ( $pieces as $key => $piece ) {
                        if ( ! is_array( $piece ) || ! self::is_product_piece( $piece ) ) {
                                continue;
                        }

                        if ( isset( $pieces[ $key ]['offers'] ) ) {
                                if ( isset( $pieces[ $key ]['offers']['@type'] ) ) {
                                        self::enrich_offer( $pieces[ $key ]['offers'], $product_id );
                                } elseif ( is_array( $pieces[ $key ]['offers'] ) ) {
                                        foreach ( $pieces[ $key ]['offers'] as $okey => $offer ) {
                                                if ( is_array( $offer ) ) {
                                                        self::enrich_offer( $pieces[ $key ]['offers'][ $okey ], $product_id );
                                                }
                                        }
                                }
                        }
                }

                if ( $is_graph ) {
                        $schema['@graph'] = $pieces;
                } else {
                        $schema = $pieces;
                }

                if ( ! self::graph_has_faq( $pieces ) ) {
                        $faq = self::build_faq_piece( $product );

                        if ( is_array( $faq ) ) {
                                if ( $is_graph ) {
                                        $schema['@graph'][] = $faq;
                                } else {
                                        $schema[] = $faq;
                                }
                        }
                }

                unset( $object );

                return $schema;
        }

        /* -----------------------------------------------------------------
         * Standalone FAQPage (no SEO plugin graph to join).
         * ----------------------------------------------------------------- */

        /**
         * Print a standalone FAQPage JSON-LD block when no SEO plugin is
         * managing the schema graph and the product has Q&A pairs.
         *
         * @return void
         */
        public static function maybe_print_faq_schema() {
                if ( self::seo_plugin_active() || ! is_singular( 'product' ) ) {
                        return;
                }

                $product_id = (int) get_queried_object_id();

                if ( $product_id <= 0 || self::is_excluded( $product_id ) ) {
                        return;
                }

                $product = wc_get_product( $product_id );

                if ( ! $product instanceof WC_Product ) {
                        return;
                }

                $faq = self::build_faq_piece( $product );

                if ( ! is_array( $faq ) ) {
                        return;
                }

                echo '<script type="application/ld+json">'
                        . wp_json_encode(
                                $faq,
                                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
                        )
                        . '</script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON_HEX_TAG makes the payload script-safe.
        }

        /* -----------------------------------------------------------------
         * Piece builders.
         * ----------------------------------------------------------------- */

        /**
         * Add hasMerchantReturnPolicy / shippingDetails to one offer, if missing.
         *
         * @param array $offer      Offer schema piece (modified by reference).
         * @param int   $product_id Product ID.
         * @return void
         */
        private static function enrich_offer( array &$offer, $product_id ) {
                $return_policy = self::build_return_policy();

                if ( is_array( $return_policy ) && ! isset( $offer['hasMerchantReturnPolicy'] ) ) {
                        $offer['hasMerchantReturnPolicy'] = $return_policy;
                }

                $shipping = self::build_shipping_details( $product_id );

                if ( is_array( $shipping ) && ! isset( $offer['shippingDetails'] ) ) {
                        $offer['shippingDetails'] = $shipping;
                }
        }

        /**
         * MerchantReturnPolicy from the configured return-policy URL.
         *
         * @return array|null Null when no truthful policy can be advertised.
         */
        private static function build_return_policy() {
                $settings = acpfw()->get_settings();
                $url      = isset( $settings['return_policy_url'] ) ? trim( (string) $settings['return_policy_url'] ) : '';

                if ( '' === $url || ACPFW_Product_Mapper::is_placeholder_policy_url( $url ) ) {
                        return null;
                }

                $country = self::store_country();
                $days    = (int) apply_filters( 'acpfw_return_window_days', 30 );

                if ( '' === $country || $days <= 0 ) {
                        return null;
                }

                return array(
                        '@type'                => 'MerchantReturnPolicy',
                        'applicableCountry'    => $country,
                        'returnPolicyCategory' => 'https://schema.org/MerchantReturnFiniteReturnWindow',
                        'merchantReturnDays'   => $days,
                        'returnMethod'         => 'https://schema.org/ReturnByMail',
                );
        }

        /**
         * OfferShippingDetails. Only emitted when the merchant supplies a flat
         * shipping rate via the acpfw_schema_shipping_rate filter — inventing a
         * rate would make the schema lie to AI agents and search engines alike.
         *
         * @param int $product_id Product ID.
         * @return array|null
         */
        private static function build_shipping_details( $product_id ) {
                $rate = apply_filters( 'acpfw_schema_shipping_rate', null, $product_id );

                if ( null === $rate || '' === (string) $rate || ! is_numeric( $rate ) ) {
                        return null;
                }

                $country = self::store_country();

                if ( '' === $country ) {
                        return null;
                }

                return array(
                        '@type'               => 'OfferShippingDetails',
                        'shippingRate'        => array(
                                '@type'    => 'MonetaryAmount',
                                'value'    => (string) $rate,
                                'currency' => get_woocommerce_currency(),
                        ),
                        'shippingDestination' => array(
                                array(
                                        '@type'          => 'DefinedRegion',
                                        'addressCountry' => $country,
                                ),
                        ),
                );
        }

        /**
         * FAQPage piece from the metabox Q&A pairs, or null when none exist.
         *
         * @param WC_Product $product Product object.
         * @return array|null
         */
        private static function build_faq_piece( $product ) {
                $faqs = get_post_meta( $product->get_id(), '_acpfw_faqs', true );

                if ( ! is_array( $faqs ) || empty( $faqs ) ) {
                        return null;
                }

                $questions = array();

                foreach ( $faqs as $faq ) {
                        if ( ! is_array( $faq ) ) {
                                continue;
                        }

                        $q = isset( $faq['q'] ) ? trim( wp_strip_all_tags( (string) $faq['q'] ) ) : '';
                        $a = isset( $faq['a'] ) ? trim( wp_strip_all_tags( (string) $faq['a'] ) ) : '';

                        if ( '' === $q || '' === $a ) {
                                continue;
                        }

                        $questions[] = array(
                                '@type'          => 'Question',
                                'name'           => $q,
                                'acceptedAnswer' => array(
                                        '@type' => 'Answer',
                                        'text'  => $a,
                                ),
                        );
                }

                if ( empty( $questions ) ) {
                        return null;
                }

                return array(
                        '@type'      => 'FAQPage',
                        '@id'        => (string) $product->get_permalink() . '#faq',
                        'url'        => (string) $product->get_permalink(),
                        'mainEntity' => $questions,
                );
        }

        /* -----------------------------------------------------------------
         * Small helpers.
         * ----------------------------------------------------------------- */

        /**
         * True when the piece is a Product (or ProductGroup) node.
         *
         * @param array $piece Schema piece.
         * @return bool
         */
        private static function is_product_piece( array $piece ) {
                $types = isset( $piece['@type'] ) ? $piece['@type'] : array();

                if ( is_string( $types ) ) {
                        $types = array( $types );
                }

                return is_array( $types ) && ( in_array( 'Product', $types, true ) || in_array( 'ProductGroup', $types, true ) );
        }

        /**
         * True when a FAQPage node already exists in the graph.
         *
         * @param array $pieces Graph pieces.
         * @return bool
         */
        private static function graph_has_faq( array $pieces ) {
                foreach ( $pieces as $piece ) {
                        if ( is_array( $piece ) && isset( $piece['@type'] ) ) {
                                $types = is_string( $piece['@type'] ) ? array( $piece['@type'] ) : (array) $piece['@type'];

                                if ( in_array( 'FAQPage', $types, true ) ) {
                                        return true;
                                }
                        }
                }

                return false;
        }

        /**
         * True when a Yoast or Rank Math schema graph is active.
         *
         * @return bool
         */
        private static function seo_plugin_active() {
                return defined( 'WPSEO_VERSION' ) || class_exists( 'RankMath' ) || defined( 'RANK_MATH_VERSION' );
        }

        /**
         * True when the product is excluded from AI output via the metabox.
         *
         * @param int $product_id Product ID.
         * @return bool
         */
        private static function is_excluded( $product_id ) {
                return 'yes' === get_post_meta( (int) $product_id, '_acpfw_exclude_from_feed', true );
        }

        /**
         * Two-letter store country from WooCommerce's base location.
         *
         * @return string
         */
        private static function store_country() {
                if ( ! function_exists( 'wc_get_base_location' ) ) {
                        return '';
                }

                $location = wc_get_base_location();

                return isset( $location['country'] ) ? strtoupper( trim( (string) $location['country'] ) ) : '';
        }
}
