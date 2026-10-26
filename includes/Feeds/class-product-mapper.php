<?php
/**
 * Product mapper: translates WC_Product objects into OpenAI Commerce Feed rows.
 *
 * Implements the field-by-field mapping of the OpenAI Commerce Feed Reference
 * (file-upload spec), including variable-product flattening: one row per
 * purchasable variation, parent products never exported as purchasable rows.
 *
 * item_id stability rule: rows default to "wc_{id}" instead of SKUs — merchants
 * edit SKUs freely and OpenAI keeps historical index by item_id, so a stable
 * synthetic identifier prevents catalog churn.
 *
 * @package ACPFW
 */

defined( 'ABSPATH' ) || exit;

/**
 * Maps WooCommerce products and variations to OpenAI feed rows.
 */
final class ACPFW_Product_Mapper {

        /**
         * Ordered list of CSV column names (superset of JSONL fields).
         *
         * @return array
         */
        public static function csv_columns() {
                return array_keys( self::skeleton_row() );
        }

        /**
         * Map a simple/external product to a feed row.
         *
         * @param WC_Product $product Product object.
         * @return array Ordered field => value.
         */
        public static function map_simple( $product ) {
                return self::build_row( $product, null );
        }

        /**
         * Map a variation to a feed row (parent context required).
         *
         * @param WC_Product      $variation Variation object.
         * @param WC_Product|null $parent    Parent product object.
         * @return array Ordered field => value.
         */
        public static function map_variation( $variation, $parent ) {
                return self::build_row( $variation, $parent );
        }

        /**
         * GS1 Modulo-10 check-digit validation for GTIN-8/12/13/14.
         *
         * @param string $gtin Candidate GTIN (digits only expected).
         * @return bool
         */
        public static function is_valid_gtin( $gtin ) {
                $gtin = preg_replace( '/[\s\-]/', '', (string) $gtin );

                if ( ! ctype_digit( $gtin ) || ! in_array( strlen( $gtin ), array( 8, 12, 13, 14 ), true ) ) {
                        return false;
                }

                $digits = str_split( $gtin );
                $check  = (int) array_pop( $digits );
                $sum    = 0;
                $weight = 3;

                for ( $i = count( $digits ) - 1; $i >= 0; $i-- ) {
                        $sum   += (int) $digits[ $i ] * $weight;
                        $weight = ( 3 === $weight ) ? 1 : 3;
                }

                return $check === ( ( 10 - ( $sum % 10 ) ) % 10 );
        }

        /**
         * Public wrapper: resolved brand for a product (full priority chain).
         * Used by the readiness scorer and admin screens.
         *
         * @param int $product_id Product ID.
         * @return string
         */
        public static function get_resolved_brand( $product_id ) {
                $settings = function_exists( 'acpfw' ) ? acpfw()->get_settings() : array();

                return self::resolve_brand( absint( $product_id ), $settings );
        }

        /**
         * Public wrapper: identifier state for the readiness scorer.
         *
         * @param int $product_id Product ID.
         * @return array has_gtin (bool), has_mpn (bool).
         */
        public static function get_scoring_identifiers( $product_id ) {
                $product  = wc_get_product( absint( $product_id ) );
                $settings = function_exists( 'acpfw' ) ? acpfw()->get_settings() : array();

                if ( ! $product instanceof WC_Product ) {
                        return array(
                                'has_gtin' => false,
                                'has_mpn'  => false,
                        );
                }

                $gtin = self::resolve_gtin( $product, null, $settings );
                $mpn  = self::resolve_mpn( $product, null, $settings );

                return array(
                        'has_gtin' => ( '' !== $gtin ),
                        'has_mpn'  => ( '' !== $mpn ),
                );
        }

        /**
         * Public wrapper: structured attribute values for the readiness scorer.
         *
         * For variable products the attributes live on the variations, so the
         * values are aggregated across all children — exactly what the feed
         * rows will carry.
         *
         * @param int $product_id Product ID.
         * @return array slug => value ('' when missing).
         */
        public static function get_scoring_attributes( $product_id ) {
                $product = wc_get_product( absint( $product_id ) );

                if ( ! $product instanceof WC_Product ) {
                        return array();
                }

                $slugs  = array(
                        'material'  => '_acpfw_material',
                        'color'     => '_acpfw_color',
                        'size'      => '_acpfw_size',
                        'gender'    => '_acpfw_gender',
                        'age_group' => '_acpfw_age_group',
                );
                $values = array();

                if ( $product->is_type( 'variable' ) ) {
                        foreach ( $slugs as $slug => $meta_key ) {
                                $values[ $slug ] = '';
                        }

                        foreach ( $product->get_children() as $child_id ) {
                                $variation = wc_get_product( $child_id );

                                if ( ! $variation instanceof WC_Product ) {
                                        continue;
                                }

                                foreach ( $slugs as $slug => $meta_key ) {
                                        if ( '' === $values[ $slug ] ) {
                                                $values[ $slug ] = self::get_own_attribute_value( $variation, $slug, $meta_key );
                                        }
                                }
                        }

                        return $values;
                }

                foreach ( $slugs as $slug => $meta_key ) {
                        $values[ $slug ] = self::get_own_attribute_value( $product, $slug, $meta_key );
                }

                return $values;
        }

        /**
         * Skeleton row: every spec field in canonical order with empty values.
         *
         * @return array
         */
        private static function skeleton_row() {
                return array(
                        'item_id'                => '',
                        'title'                  => '',
                        'description'            => '',
                        'url'                    => '',
                        'brand'                  => '',
                        'seller_name'            => '',
                        'image_url'              => '',
                        'availability'           => '',
                        'price'                  => '',
                        'sale_price'             => '',
                        'group_id'               => '',
                        'listing_has_variations' => '',
                        'variant_dict'           => '',
                        'gtin'                   => '',
                        'mpn'                    => '',
                        'condition'              => '',
                        'product_category'       => '',
                        'material'               => '',
                        'color'                  => '',
                        'size'                   => '',
                        'gender'                 => '',
                        'age_group'              => '',
                        'dimensions'             => '',
                        'weight'                 => '',
                        'item_weight_unit'       => '',
                        'is_eligible_search'     => true,
                        'is_eligible_checkout'   => false,
                        'return_policy'          => '',
                );
        }

        /**
         * Build one complete feed row.
         *
         * @param WC_Product      $product Product or variation object.
         * @param WC_Product|null $parent  Parent product for variations.
         * @return array
         */
        private static function build_row( $product, $parent = null ) {
                $is_variation = ( null !== $parent );
                $product_id   = $product->get_id();
                $settings     = function_exists( 'acpfw' ) ? acpfw()->get_settings() : array();

                $row = self::skeleton_row();

                /*
                 * Identity.
                 */
                $row['item_id'] = 'wc_' . $product_id;

                $variant_dict = array();
                if ( $is_variation ) {
                        $variant_dict = self::get_variant_dict( $product );
                        $row['group_id']               = 'wc_' . $parent->get_id();
                        $row['listing_has_variations'] = true;
                        $row['variant_dict']           = $variant_dict;
                }

                /*
                 * Title (plain text, max 150 chars).
                 */
                if ( $is_variation ) {
                        $title = trim( (string) $parent->get_name() );
                        if ( ! empty( $variant_dict ) ) {
                                $title .= ' — ' . implode( ', ', array_values( $variant_dict ) );
                        }
                } else {
                        $title = (string) $product->get_name();
                }
                $row['title'] = self::truncate( wp_strip_all_tags( $title ), 150 );

                /*
                 * Description (plain text, max 5000 chars). Variations concatenate
                 * parent description + variation description when present.
                 */
                if ( $is_variation ) {
                        $description = trim( (string) $parent->get_description() . "\n\n" . (string) $product->get_description() );
                } else {
                        $description = (string) $product->get_description();
                }
                $description = wp_strip_all_tags( strip_shortcodes( $description ) );
                $row['description'] = self::truncate( $description, 5000 );

                /*
                 * URL. Variations deep-link with ?attribute_pa_x=slug args.
                 */
                if ( $is_variation ) {
                        $url   = (string) get_permalink( $parent->get_id() );
                        $args  = array();
                        foreach ( $product->get_attributes() as $taxonomy => $slug ) {
                                if ( '' === (string) $slug ) {
                                        continue;
                                }
                                $args[ 'attribute_' . $taxonomy ] = $slug;
                        }
                        if ( ! empty( $args ) && '' !== $url ) {
                                $url = add_query_arg( $args, $url );
                        }
                } else {
                        $url = (string) get_permalink( $product_id );
                }
                $row['url'] = esc_url_raw( $url );

                /*
                 * Brand — required by the spec; resolved via a priority chain.
                 */
                $brand_owner_id = $is_variation ? $parent->get_id() : $product_id;
                $row['brand'] = self::resolve_brand( $brand_owner_id, $settings );

                /*
                 * Seller name (store-level setting).
                 */
                $row['seller_name'] = (string) ( isset( $settings['seller_name'] ) ? $settings['seller_name'] : get_bloginfo( 'name' ) );

                /*
                 * Image URL — always routed through the JPEG/PNG resolver.
                 */
                $image_id = (int) $product->get_image_id();
                if ( ! $image_id && $is_variation ) {
                        $image_id = (int) $parent->get_image_id();
                }
                $row['image_url'] = ACPFW_Image_Resolver::get_feed_url( $image_id );

                /*
                 * Availability.
                 */
                $row['availability'] = self::resolve_availability( $product, $product_id );

                /*
                 * Price + sale price ("79.99 USD").
                 */
                $currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD';
                $regular  = (string) $product->get_regular_price();
                if ( '' === $regular && $is_variation ) {
                        $regular = (string) $parent->get_regular_price();
                }
                if ( '' !== $regular && null !== $regular ) {
                        $row['price'] = number_format( (float) $regular, 2, '.', '' ) . ' ' . $currency;
                }

                if ( $product->is_on_sale() ) {
                        $sale = (string) $product->get_sale_price();
                        if ( '' === $sale && $is_variation ) {
                                $sale = (string) $parent->get_sale_price();
                        }
                        if ( '' !== $sale && null !== $sale ) {
                                $row['sale_price'] = number_format( (float) $sale, 2, '.', '' ) . ' ' . $currency;
                        }
                }

                /*
                 * GTIN (validated) + MPN.
                 */
                $row['gtin'] = self::resolve_gtin( $product, $parent, $settings );
                $row['mpn']  = self::resolve_mpn( $product, $parent, $settings );

                /*
                 * Condition (enum).
                 */
                $condition = (string) get_post_meta( $product_id, '_acpfw_condition', true );
                if ( '' === $condition && $is_variation ) {
                        $condition = (string) get_post_meta( $parent->get_id(), '_acpfw_condition', true );
                }
                if ( ! in_array( $condition, array( 'new', 'refurbished', 'used' ), true ) ) {
                        $condition = 'new';
                }
                $row['condition'] = $condition;

                /*
                 * Structured discovery attributes.
                 */
                $row['product_category'] = self::get_category_path( $brand_owner_id );
                $row['material']         = self::get_attribute_value( $product, $parent, 'material', '_acpfw_material' );
                $row['color']            = self::get_attribute_value( $product, $parent, 'color', '_acpfw_color' );
                $row['size']             = self::get_attribute_value( $product, $parent, 'size', '_acpfw_size' );

                $gender = self::get_attribute_value( $product, $parent, 'gender', '_acpfw_gender' );
                if ( in_array( $gender, array( 'male', 'female', 'unisex' ), true ) ) {
                        $row['gender'] = $gender;
                }

                $age_group = self::get_attribute_value( $product, $parent, 'age_group', '_acpfw_age_group' );
                if ( in_array( $age_group, array( 'newborn', 'infant', 'toddler', 'kids', 'adult' ), true ) ) {
                        $row['age_group'] = $age_group;
                }

                /*
                 * Dimensions (JSON object when >= 2 axes exist) + weight.
                 */
                $dimensions = self::resolve_dimensions( $product, $parent );
                if ( ! empty( $dimensions ) ) {
                        $row['dimensions'] = $dimensions;
                }

                $weight      = (string) $product->get_weight();
                $weight_unit = (string) get_option( 'woocommerce_weight_unit', 'kg' );
                if ( 'lbs' === $weight_unit ) {
                        $weight_unit = 'lb';
                }
                if ( '' === $weight && $is_variation ) {
                        $weight = (string) $parent->get_weight();
                }
                if ( '' !== $weight && in_array( $weight_unit, array( 'g', 'kg', 'oz', 'lb' ), true ) ) {
                        $row['weight']           = $weight;
                        $row['item_weight_unit'] = $weight_unit;
                }

                /*
                 * Eligibility flags + return policy.
                 */
                $eligible_search = get_post_meta( $product_id, '_acpfw_eligible_search', true );
                if ( '' === $eligible_search && $is_variation ) {
                        $eligible_search = get_post_meta( $parent->get_id(), '_acpfw_eligible_search', true );
                }
                $row['is_eligible_search'] = ( '' === $eligible_search ) ? true : (bool) $eligible_search;

                // Free plugin: no ACP checkout sessions; Pro flips this flag.
                $row['is_eligible_checkout'] = false;

                $return_policy = '';
                if ( isset( $settings['return_policy_url'] ) ) {
                        $return_policy = (string) $settings['return_policy_url'];
                }
                $row['return_policy'] = esc_url_raw( $return_policy );

                /**
                 * Filter a completed feed row before it is written to the feed files.
                 *
                 * @param array      $row     Feed row (field => value).
                 * @param WC_Product $product Product or variation object.
                 * @param mixed      $parent  Parent product or null.
                 */
                return apply_filters( 'acpfw_feed_row', $row, $product, $parent );
        }

        /**
         * Build the variant_dict (clean labels, prefixes stripped).
         *
         * @param WC_Product $variation Variation object.
         * @return array
         */
        private static function get_variant_dict( $variation ) {
                $dict = array();

                foreach ( $variation->get_attributes() as $taxonomy => $value ) {
                        if ( '' === (string) $value || null === $value ) {
                                continue;
                        }

                        $clean = strtolower( (string) $taxonomy );
                        $clean = preg_replace( '/^attribute_/', '', $clean );
                        $clean = preg_replace( '/^pa_/', '', $clean );

                        $label = (string) $value;
                        if ( 0 === strpos( (string) $taxonomy, 'pa_' ) ) {
                                $term = get_term_by( 'slug', (string) $value, (string) $taxonomy );
                                if ( $term && ! is_wp_error( $term ) ) {
                                        $label = $term->name;
                                }
                        }

                        if ( '' !== $clean ) {
                                $dict[ $clean ] = $label;
                        }
                }

                return $dict;
        }

        /**
         * Brand resolution chain (per spec): per-product override -> mapped meta
         * source -> WC core brands taxonomy -> pa_brand attribute -> Yoast/RankMath
         * meta -> store default brand.
         *
         * @param int   $product_id Product ID to resolve for.
         * @param array $settings   Plugin settings.
         * @return string
         */
        private static function resolve_brand( $product_id, $settings ) {
                $override = get_post_meta( $product_id, '_acpfw_brand', true );
                if ( '' !== $override && null !== $override ) {
                        return (string) $override;
                }

                $source = isset( $settings['brand_source'] ) ? (string) $settings['brand_source'] : 'auto';

                if ( 'auto' !== $source ) {
                        $mapped = get_post_meta( $product_id, $source, true );
                        if ( '' !== $mapped && null !== $mapped ) {
                                return (string) $mapped;
                        }
                }

                // 1) WooCommerce core Brands taxonomy.
                $brands = wp_get_post_terms( $product_id, 'product_brand', array( 'fields' => 'names' ) );
                if ( ! is_wp_error( $brands ) && ! empty( $brands ) ) {
                        return (string) $brands[0];
                }

                // 2) pa_brand attribute.
                if ( taxonomy_exists( 'pa_brand' ) ) {
                        $pa_brand = wc_get_product_terms( $product_id, 'pa_brand', array( 'fields' => 'names' ) );
                        if ( ! is_wp_error( $pa_brand ) && ! empty( $pa_brand ) ) {
                                return (string) $pa_brand[0];
                        }
                }

                // 3) Yoast primary product brand (stores a term ID).
                $yoast_primary = get_post_meta( $product_id, '_yoast_wpseo_primary_product_brand', true );
                if ( '' !== $yoast_primary && null !== $yoast_primary ) {
                        $term = get_term( (int) $yoast_primary, 'product_brand' );
                        if ( $term && ! is_wp_error( $term ) ) {
                                return $term->name;
                        }
                }

                // 4) Rank Math brand meta.
                $rank_math = get_post_meta( $product_id, '_rank_math_brand', true );
                if ( '' !== $rank_math && null !== $rank_math ) {
                        return (string) $rank_math;
                }

                // 5) Store default.
                $default = isset( $settings['default_brand'] ) ? (string) $settings['default_brand'] : '';

                /**
                 * Filter the resolved brand for a product.
                 *
                 * @param string $brand      Resolved brand (may be '' if unresolved).
                 * @param int    $product_id Product ID.
                 */
                return (string) apply_filters( 'acpfw_feed_brand', $default, $product_id );
        }

        /**
         * Availability mapping incl. per-product override.
         *
         * @param WC_Product $product    Product object.
         * @param int        $product_id Product ID.
         * @return string
         */
        private static function resolve_availability( $product, $product_id ) {
                $override = (string) get_post_meta( $product_id, '_acpfw_availability', true );
                if ( in_array( $override, array( 'in_stock', 'out_of_stock', 'pre_order', 'backorder', 'unknown' ), true ) ) {
                        return $override;
                }

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

        /**
         * GTIN resolution: mapped source -> WC 9.2+ global unique ID. Invalid
         * check digits cause the field to be omitted entirely (per spec).
         *
         * @param WC_Product      $product  Product object.
         * @param WC_Product|null $parent   Parent product or null.
         * @param array           $settings Plugin settings.
         * @return string
         */
        private static function resolve_gtin( $product, $parent, $settings ) {
                $source = isset( $settings['gtin_source'] ) ? (string) $settings['gtin_source'] : '_global_unique_id';
                $gtin   = '';

                if ( '_global_unique_id' === $source ) {
                        if ( method_exists( $product, 'get_global_unique_id' ) ) {
                                $gtin = (string) $product->get_global_unique_id();
                        }
                        if ( '' === $gtin && null !== $parent && method_exists( $parent, 'get_global_unique_id' ) ) {
                                $gtin = (string) $parent->get_global_unique_id();
                        }
                } else {
                        $gtin = (string) get_post_meta( $product->get_id(), $source, true );
                        if ( '' === $gtin && null !== $parent ) {
                                $gtin = (string) get_post_meta( $parent->get_id(), $source, true );
                        }
                }

                $gtin = preg_replace( '/[\s\-]/', '', $gtin );

                if ( '' === $gtin || ! self::is_valid_gtin( $gtin ) ) {
                        return '';
                }

                return $gtin;
        }

        /**
         * MPN resolution: mapped meta source -> optional SKU fallback.
         *
         * @param WC_Product      $product  Product object.
         * @param WC_Product|null $parent   Parent product or null.
         * @param array           $settings Plugin settings.
         * @return string
         */
        private static function resolve_mpn( $product, $parent, $settings ) {
                $mpn    = '';
                $source = isset( $settings['mpn_source'] ) ? (string) $settings['mpn_source'] : '';

                if ( '' !== $source ) {
                        $mpn = (string) get_post_meta( $product->get_id(), $source, true );
                        if ( '' === $mpn && null !== $parent ) {
                                $mpn = (string) get_post_meta( $parent->get_id(), $source, true );
                        }
                }

                if ( '' === $mpn && isset( $settings['mpn_sku_fallback'] ) && 'yes' === $settings['mpn_sku_fallback'] ) {
                        $mpn = (string) $product->get_sku();
                        if ( '' === $mpn && null !== $parent ) {
                                $mpn = (string) $parent->get_sku();
                        }
                }

                return $mpn;
        }

        /**
         * Dimensions JSON object (requires >= 2 axes and a spec-valid unit).
         *
         * @param WC_Product      $product Product object.
         * @param WC_Product|null $parent  Parent product or null.
         * @return array|string
         */
        private static function resolve_dimensions( $product, $parent ) {
                $length = (string) $product->get_length();
                $width  = (string) $product->get_width();
                $height = (string) $product->get_height();

                if ( ( '' === $length && '' === $width && '' === $height ) && null !== $parent ) {
                        $length = (string) $parent->get_length();
                        $width  = (string) $parent->get_width();
                        $height = (string) $parent->get_height();
                }

                $filled = count( array_filter( array( $length, $width, $height ) ) );
                $unit   = (string) get_option( 'woocommerce_dimension_unit', 'cm' );

                if ( $filled < 2 || ! in_array( $unit, array( 'in', 'cm', 'ft', 'm', 'mm' ), true ) ) {
                        return '';
                }

                return array(
                        'length' => $length,
                        'width'  => $width,
                        'height' => $height,
                        'unit'   => $unit,
                );
        }

        /**
         * Deepest product category breadcrumb path ("A > B > C").
         *
         * @param int $product_id Product ID.
         * @return string
         */
        private static function get_category_path( $product_id ) {
                $terms = wp_get_post_terms( $product_id, 'product_cat' );

                if ( is_wp_error( $terms ) || empty( $terms ) ) {
                        return '';
                }

                $deepest   = $terms[0];
                $max_depth = -1;

                foreach ( $terms as $term ) {
                        $depth = count( get_ancestors( $term->term_id, 'product_cat' ) );
                        if ( $depth > $max_depth ) {
                                $max_depth = $depth;
                                $deepest   = $term;
                        }
                }

                $chain = array_reverse( get_ancestors( $deepest->term_id, 'product_cat' ) );
                $names = array();

                foreach ( $chain as $ancestor_id ) {
                        $ancestor = get_term( $ancestor_id, 'product_cat' );
                        if ( $ancestor && ! is_wp_error( $ancestor ) ) {
                                $names[] = $ancestor->name;
                        }
                }

                $names[] = $deepest->name;

                return implode( ' > ', $names );
        }

        /**
         * Structured attribute lookup with correct variation semantics.
         *
         * Resolution order:
         *  1. The object's OWN value (simple product term, or the variation's
         *     attribute_* meta via WC_Product_Variation::get_attribute()).
         *  2. The object's own plugin meta key.
         *  3. The parent's shared attribute — accepted only when it resolves
         *     to exactly ONE term. A variable parent's used-for-variations
         *     attribute holds the whole option pool ("Blue, Green, Red"),
         *     which is never a valid per-row value.
         *  4. The parent's plugin meta key.
         *
         * @param WC_Product      $product  Product or variation object.
         * @param WC_Product|null $parent   Parent product or null.
         * @param string          $slug     Attribute slug (without pa_ prefix).
         * @param string          $meta_key Fallback meta key.
         * @return string
         */
        private static function get_attribute_value( $product, $parent, $slug, $meta_key ) {
                $own = self::get_own_attribute_value( $product, $slug, $meta_key );

                if ( '' !== $own ) {
                        return $own;
                }

                if ( null === $parent ) {
                        return '';
                }

                $taxonomy = 'pa_' . $slug;

                if ( taxonomy_exists( $taxonomy ) ) {
                        $terms = wc_get_product_terms( $parent->get_id(), $taxonomy, array( 'fields' => 'names' ) );

                        if ( ! is_wp_error( $terms ) && 1 === count( $terms ) ) {
                                return (string) $terms[0];
                        }
                }

                $meta = get_post_meta( $parent->get_id(), $meta_key, true );

                return ( '' !== $meta && null !== $meta ) ? (string) $meta : '';
        }

        /**
         * Attribute value living on the object itself (no parent fallback).
         *
         * @param WC_Product $product  Product or variation object.
         * @param string     $slug     Attribute slug (without pa_ prefix).
         * @param string     $meta_key Fallback meta key.
         * @return string
         */
        private static function get_own_attribute_value( $product, $slug, $meta_key ) {
                $taxonomy = 'pa_' . $slug;

                /*
                 * Variations: read the raw attribute meta directly. Keys arrive
                 * as "pa_color" / "logo" (current WC) or historically as
                 * "attribute_pa_color" — match any form, then map the stored
                 * slug to the term label.
                 */
                if ( $product->is_type( 'variation' ) ) {
                        foreach ( (array) $product->get_attributes() as $attr_key => $attr_value ) {
                                $clean = preg_replace( '/^attribute_/', '', strtolower( (string) $attr_key ) );

                                if ( $clean !== $taxonomy && $clean !== $slug ) {
                                        continue;
                                }

                                $raw = trim( (string) $attr_value );

                                if ( '' !== $raw && taxonomy_exists( $taxonomy ) ) {
                                        return self::normalize_attribute_label( $raw, $taxonomy );
                                }

                                return $raw;
                        }

                        // This variation carries no such attribute at all.
                        $meta = get_post_meta( $product->get_id(), $meta_key, true );

                        return ( '' !== $meta && null !== $meta ) ? (string) $meta : '';
                }

                /*
                 * Simple/external products: single taxonomy term or local
                 * (custom) attribute with a matching name.
                 */
                if ( taxonomy_exists( $taxonomy ) ) {
                        $value = trim( (string) $product->get_attribute( $taxonomy ) );

                        if ( '' !== $value && false === strpos( $value, ',' ) ) {
                                return self::normalize_attribute_label( $value, $taxonomy );
                        }
                }

                $value = trim( (string) $product->get_attribute( $slug ) );

                if ( '' !== $value && false === strpos( $value, ',' ) ) {
                        return $value;
                }

                // 3) Plugin meta on the object itself.
                $meta = get_post_meta( $product->get_id(), $meta_key, true );

                return ( '' !== $meta && null !== $meta ) ? (string) $meta : '';
        }

        /**
         * Map a raw attribute value (slug or label) to the term label.
         *
         * @param string $value    Raw value from WC_Product::get_attribute().
         * @param string $taxonomy Attribute taxonomy.
         * @return string
         */
        private static function normalize_attribute_label( $value, $taxonomy ) {
                $term = get_term_by( 'slug', $value, $taxonomy );

                if ( $term && ! is_wp_error( $term ) ) {
                        return $term->name;
                }

                return $value;
        }

        /**
         * Multi-byte safe truncation.
         *
         * @param string $text      Text.
         * @param int    $max_chars Max characters.
         * @return string
         */
        private static function truncate( $text, $max_chars ) {
                $text = trim( (string) $text );

                if ( function_exists( 'mb_substr' ) && mb_strlen( $text ) > $max_chars ) {
                        return trim( mb_substr( $text, 0, $max_chars ) );
                }

                if ( strlen( $text ) > $max_chars ) {
                        return trim( substr( $text, 0, $max_chars ) );
                }

                return $text;
        }
}
