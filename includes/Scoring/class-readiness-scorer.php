<?php
/**
 * Deterministic AI Readiness scoring engine (0-100).
 *
 * Seven weighted checks per product, exactly as specified:
 *
 *  | Check      | Weight | What it validates                                    |
 *  |------------|--------|------------------------------------------------------|
 *  | REQ_CORE   | 25     | Title, positive price, valid stock status            |
 *  | BRAND      | 15     | Resolved brand (required by OpenAI spec)             |
 *  | IMAGE_FMT  | 15     | Featured image JPEG/PNG and >= 500x500               |
 *  | DESC_LEN   | 15     | Clean description between 120 and 5000 chars         |
 *  | GTIN_MPN   | 10     | Valid GTIN (Modulo-10) or non-empty MPN              |
 *  | ATTRS      | 10     | At least 2 structured attributes                     |
 *  | FAQ_SCHEMA | 10     | At least 2 conversational Q&A pairs in _acpfw_faqs   |
 *
 * Results are stored on the parent product as:
 *  - _acpfw_readiness_score (int 0-100)
 *  - _acpfw_readiness_issues (array of issue codes)
 *
 * @package ACPFW
 */

defined( 'ABSPATH' ) || exit;

/**
 * Scores products for AI channel readiness.
 */
final class ACPFW_Readiness_Scorer {

	const MIN_DESC_CHARS = 120;
	const MAX_DESC_CHARS = 5000;
	const MIN_IMAGE_PX   = 500;

	/**
	 * Register scoring hooks.
	 *
	 * @return void
	 */
	public static function init() {
		// WC CRUD saves (admin + REST) always fire this after meta is stored.
		add_action( 'woocommerce_update_product', array( __CLASS__, 'on_product_saved' ), 20 );
		// Non-WC saves (imports, quick edit) come through save_post_product;
		// scoring is idempotent so double-firing is safe.
		add_action( 'save_post_product', array( __CLASS__, 'on_save_post' ), 20, 2 );
	}

	/**
	 * woocommerce_update_product handler.
	 *
	 * @param int $product_id Product ID.
	 * @return void
	 */
	public static function on_product_saved( $product_id ) {
		self::score_product( $product_id );
	}

	/**
	 * save_post_product handler.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @return void
	 */
	public static function on_save_post( $post_id, $post ) {
		if ( ! $post instanceof WP_Post || 'product' !== $post->post_type ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		self::score_product( $post_id );
	}

	/**
	 * Human-readable failure message for an issue code.
	 *
	 * @param string $code Issue code.
	 * @return string
	 */
	public static function get_issue_message( $code ) {
		switch ( $code ) {
			case 'REQ_CORE':
				return __( 'Missing price or title (blocked from feed)', 'agentic-commerce-ai-product-feed' );
			case 'BRAND':
				return __( 'Missing brand (required by OpenAI spec)', 'agentic-commerce-ai-product-feed' );
			case 'IMAGE_FMT':
				return __( 'Image missing, too small, or WebP only', 'agentic-commerce-ai-product-feed' );
			case 'DESC_LEN':
				return __( 'Description too thin (< 120 chars) for AI answers', 'agentic-commerce-ai-product-feed' );
			case 'GTIN_MPN':
				return __( 'Missing valid GTIN or MPN identifier', 'agentic-commerce-ai-product-feed' );
			case 'ATTRS':
				return __( 'Missing structured attributes (material/color/gender)', 'agentic-commerce-ai-product-feed' );
			case 'FAQ_SCHEMA':
				return __( 'No conversational Q&A for AI prompts', 'agentic-commerce-ai-product-feed' );
			default:
				return $code;
		}
	}

	/**
	 * Score one product and persist the result.
	 *
	 * @param int $product_id Product ID.
	 * @return array [ score (int), issues (string[] ) ].
	 */
	public static function score_product( $product_id ) {
		$product_id = absint( $product_id );
		$product    = wc_get_product( $product_id );

		if ( ! $product instanceof WC_Product ) {
			return array( 0, array( 'REQ_CORE' ) );
		}

		$issues = array();
		$score  = 0;

		// ---- REQ_CORE (25 pts): title + positive price + stock status.
		$has_title = '' !== trim( (string) $product->get_name() );

		if ( $product->is_type( 'variable' ) ) {
			$min_price = $product->get_variation_price( 'min', true );
			$has_price = ( null !== $min_price && (float) $min_price > 0 );
		} else {
			$has_price = (float) $product->get_price() > 0;
		}

		$has_stock = '' !== (string) $product->get_stock_status();

		if ( $has_title && $has_price && $has_stock ) {
			$score += 25;
		} else {
			$issues[] = 'REQ_CORE';
		}

		// ---- BRAND (15 pts).
		if ( '' !== ACPFW_Product_Mapper::get_resolved_brand( $product_id ) ) {
			$score += 15;
		} else {
			$issues[] = 'BRAND';
		}

		// ---- IMAGE_FMT (15 pts): compliant resolved image >= 500x500.
		if ( self::check_image( $product_id ) ) {
			$score += 15;
		} else {
			$issues[] = 'IMAGE_FMT';
		}

		// ---- DESC_LEN (15 pts): 120-5000 clean characters.
		$description = wp_strip_all_tags( strip_shortcodes( (string) $product->get_description() ) );
		$desc_length = function_exists( 'mb_strlen' ) ? mb_strlen( trim( $description ) ) : strlen( trim( $description ) );

		if ( $desc_length >= self::MIN_DESC_CHARS && $desc_length <= self::MAX_DESC_CHARS ) {
			$score += 15;
		} else {
			$issues[] = 'DESC_LEN';
		}

		// ---- GTIN_MPN (10 pts): valid GTIN or non-empty MPN.
		$identifiers = ACPFW_Product_Mapper::get_scoring_identifiers( $product_id );
		if ( ! empty( $identifiers['has_gtin'] ) || ! empty( $identifiers['has_mpn'] ) ) {
			$score += 10;
		} else {
			$issues[] = 'GTIN_MPN';
		}

		// ---- ATTRS (10 pts): at least 2 structured attributes.
		$attributes = ACPFW_Product_Mapper::get_scoring_attributes( $product_id );
		$filled     = 0;

		foreach ( $attributes as $value ) {
			if ( '' !== $value && null !== $value ) {
				$filled++;
			}
		}

		if ( $filled >= 2 ) {
			$score += 10;
		} else {
			$issues[] = 'ATTRS';
		}

		// ---- FAQ_SCHEMA (10 pts): at least 2 Q&A pairs.
		$faqs = get_post_meta( $product_id, '_acpfw_faqs', true );
		if ( is_array( $faqs ) && count( $faqs ) >= 2 ) {
			$score += 10;
		} else {
			$issues[] = 'FAQ_SCHEMA';
		}

		// Persist.
		update_post_meta( $product_id, '_acpfw_readiness_score', (int) $score );
		update_post_meta( $product_id, '_acpfw_readiness_issues', $issues );

		/**
		 * Fires after a product's readiness score is recalculated.
		 *
		 * @param int   $product_id Product ID.
		 * @param int   $score      New score.
		 * @param array $issues     Issue codes.
		 */
		do_action( 'acpfw_score_recalculated', $product_id, $score, $issues );

		return array( (int) $score, $issues );
	}

	/**
	 * Bulk score every published product (paged, memory-safe).
	 *
	 * @return int Number of products scored.
	 */
	public static function bulk_score_all() {
		$count = 0;
		$page  = 1;

		do {
			$product_ids = wc_get_products(
				array(
					'status'  => 'publish',
					'limit'   => 100,
					'page'    => $page,
					'orderby' => 'ID',
					'order'   => 'ASC',
					'return'  => 'ids',
				)
			);

			if ( ! is_array( $product_ids ) ) {
				break;
			}

			foreach ( $product_ids as $product_id ) {
				self::score_product( $product_id );
				$count++;
			}

			$page++;
		} while ( count( $product_ids ) === 100 );

		return $count;
	}

	/**
	 * IMAGE_FMT check: featured image resolves to JPEG/PNG and is >= 500x500.
	 *
	 * @param int $product_id Product ID.
	 * @return bool
	 */
	private static function check_image( $product_id ) {
		$image_id = (int) get_post_thumbnail_id( $product_id );

		if ( ! $image_id ) {
			return false;
		}

		$url = ACPFW_Image_Resolver::get_feed_url( $image_id );

		if ( '' === $url ) {
			return false;
		}

		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$ext  = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );

		if ( ! in_array( $ext, array( 'jpg', 'jpeg', 'png' ), true ) ) {
			return false;
		}

		$meta = wp_get_attachment_metadata( $image_id );

		$width  = isset( $meta['width'] ) ? (int) $meta['width'] : 0;
		$height = isset( $meta['height'] ) ? (int) $meta['height'] : 0;

		if ( $width >= self::MIN_IMAGE_PX && $height >= self::MIN_IMAGE_PX ) {
			return true;
		}

		// Fallback through the resized source when meta is missing/incomplete.
		$src = wp_get_attachment_image_src( $image_id, 'full' );

		if ( is_array( $src ) && (int) $src[1] >= self::MIN_IMAGE_PX && (int) $src[2] >= self::MIN_IMAGE_PX ) {
			return true;
		}

		return false;
	}
}
