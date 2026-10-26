<?php
/**
 * Product metabox: "AI Product Feed" panel on the product edit screen.
 *
 * Shows the current AI Readiness Score with failing-check hints and exposes
 * the per-product overrides the feed mapper consumes:
 *
 *  - _acpfw_brand              Brand override (top of the brand chain).
 *  - _acpfw_availability       Availability override.
 *  - _acpfw_exclude_from_feed  Exclude the whole product from all AI output.
 *  - _acpfw_faqs               Conversational Q&A pairs (FAQ_SCHEMA check).
 *
 * @package ACPFW
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders and saves the product metabox.
 */
final class ACPFW_Product_Metabox {

        const MAX_FAQS = 10;

        /**
         * Constructor.
         */
        public function __construct() {
                add_action( 'add_meta_boxes', array( $this, 'register_metabox' ) );
                add_action( 'woocommerce_process_product_meta', array( $this, 'save' ), 10, 1 );
                add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
                add_action( 'admin_post_acpfw_rescore_product', array( $this, 'handle_rescore' ) );
        }

        /**
         * Register the metabox on product edit screens.
         *
         * @return void
         */
        public function register_metabox() {
                add_meta_box(
                        'acpfw_product_feed',
                        __( 'AI Product Feed (ChatGPT / Agentic)', 'agentic-commerce-ai-product-feed' ),
                        array( $this, 'render' ),
                        'product',
                        'normal',
                        'default'
                );
        }

        /**
         * Metabox CSS + JS (repeatable FAQ rows).
         *
         * @param string $hook Current admin page hook.
         * @return void
         */
        public function enqueue_assets( $hook ) {
                if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
                        return;
                }

                $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

                if ( ! $screen || 'product' !== $screen->post_type ) {
                        return;
                }

                wp_enqueue_style(
                        'acpfw-metabox',
                        ACPFW_PLUGIN_URL . 'assets/css/admin-metabox.css',
                        array(),
                        ACPFW_VERSION
                );

                wp_enqueue_script(
                        'acpfw-metabox',
                        ACPFW_PLUGIN_URL . 'assets/js/admin-metabox.js',
                        array(),
                        ACPFW_VERSION,
                        true
                );
        }

        /**
         * Render the metabox.
         *
         * @param WP_Post $post Current post.
         * @return void
         */
        public function render( $post ) {
                $product_id  = (int) $post->ID;
                $brand       = (string) get_post_meta( $product_id, '_acpfw_brand', true );
                $availability = (string) get_post_meta( $product_id, '_acpfw_availability', true );
                $excluded    = ( 'yes' === get_post_meta( $product_id, '_acpfw_exclude_from_feed', true ) );
                $faqs        = get_post_meta( $product_id, '_acpfw_faqs', true );
                $faqs        = is_array( $faqs ) ? $faqs : array();
                $score       = get_post_meta( $product_id, '_acpfw_readiness_score', true );
                $issues      = get_post_meta( $product_id, '_acpfw_readiness_issues', true );

                wp_nonce_field( 'acpfw_save_product_metabox', 'acpfw_metabox_nonce' );
                ?>
                <div class="acpfw-metabox">
                        <?php $this->render_score_box( $product_id, $score, is_array( $issues ) ? $issues : array(), $excluded ); ?>

                        <div class="acpfw-field">
                                <label class="acpfw-field__label" for="acpfw_brand"><?php esc_html_e( 'Brand override', 'agentic-commerce-ai-product-feed' ); ?></label>
                                <input type="text" id="acpfw_brand" name="acpfw_brand" value="<?php echo esc_attr( $brand ); ?>" class="regular-text" />
                                <p class="acpfw-field__hint"><?php esc_html_e( 'Optional. Leave empty to auto-detect from Brands, attributes or SEO plugins. OpenAI requires a brand on every product.', 'agentic-commerce-ai-product-feed' ); ?></p>
                        </div>

                        <div class="acpfw-field">
                                <label class="acpfw-field__label" for="acpfw_availability"><?php esc_html_e( 'Availability override', 'agentic-commerce-ai-product-feed' ); ?></label>
                                <select id="acpfw_availability" name="acpfw_availability">
                                        <option value=""><?php esc_html_e( 'Automatic (from stock status)', 'agentic-commerce-ai-product-feed' ); ?></option>
                                        <option value="in_stock" <?php selected( 'in_stock', $availability ); ?>><?php esc_html_e( 'In stock', 'agentic-commerce-ai-product-feed' ); ?></option>
                                        <option value="pre_order" <?php selected( 'pre_order', $availability ); ?>><?php esc_html_e( 'Pre-order', 'agentic-commerce-ai-product-feed' ); ?></option>
                                        <option value="backorder" <?php selected( 'backorder', $availability ); ?>><?php esc_html_e( 'Backorder', 'agentic-commerce-ai-product-feed' ); ?></option>
                                        <option value="out_of_stock" <?php selected( 'out_of_stock', $availability ); ?>><?php esc_html_e( 'Out of stock', 'agentic-commerce-ai-product-feed' ); ?></option>
                                </select>
                        </div>

                        <div class="acpfw-field">
                                <label for="acpfw_exclude">
                                        <input type="checkbox" id="acpfw_exclude" name="acpfw_exclude" value="yes" <?php checked( $excluded ); ?> />
                                        <?php esc_html_e( 'Exclude this product from all AI feeds and llms.txt output', 'agentic-commerce-ai-product-feed' ); ?>
                                </label>
                        </div>

                        <div class="acpfw-field acpfw-field--faq">
                                <span class="acpfw-field__label"><?php esc_html_e( 'Conversational Q&A (for AI shopping prompts)', 'agentic-commerce-ai-product-feed' ); ?></span>
                                <p class="acpfw-field__hint acpfw-faq-intro"><?php esc_html_e( 'Add the questions shoppers actually ask, e.g. "Is this machine washable?" — at least 2 pairs earn the full FAQ score.', 'agentic-commerce-ai-product-feed' ); ?></p>

                                <div id="acpfw-faq-rows" class="acpfw-faq-rows" data-index="<?php echo esc_attr( (string) count( $faqs ) ); ?>">
                                        <?php foreach ( $faqs as $i => $faq ) : ?>
                                                <?php self::render_faq_row( (int) $i, $faq ); ?>
                                        <?php endforeach; ?>
                                </div>

                                <p>
                                        <button type="button" class="button button-secondary" id="acpfw-add-faq"><?php esc_html_e( '+ Add question', 'agentic-commerce-ai-product-feed' ); ?></button>
                                </p>

                                <script type="text/html" id="acpfw-faq-template">
                                        <?php self::render_faq_row( '__I__', array( 'q' => '', 'a' => '' ) ); ?>
                                </script>
                        </div>
                </div>
                <?php
        }

        /**
         * Render one FAQ editor row.
         *
         * @param int|string $index Row index (or template placeholder).
         * @param array     $faq   ['q' => string, 'a' => string].
         * @return void
         */
        private static function render_faq_row( $index, $faq ) {
                $q = isset( $faq['q'] ) ? $faq['q'] : '';
                $a = isset( $faq['a'] ) ? $faq['a'] : '';
                ?>
                <div class="acpfw-faq-row">
                        <input type="text" name="acpfw_faqs[<?php echo esc_attr( (string) $index ); ?>][q]" placeholder="<?php esc_attr_e( 'Question', 'agentic-commerce-ai-product-feed' ); ?>" value="<?php echo esc_attr( $q ); ?>" />
                        <textarea name="acpfw_faqs[<?php echo esc_attr( (string) $index ); ?>][a]" placeholder="<?php esc_attr_e( 'Answer', 'agentic-commerce-ai-product-feed' ); ?>"><?php echo esc_textarea( $a ); ?></textarea>
                        <button type="button" class="button acpfw-remove-faq" aria-label="<?php esc_attr_e( 'Remove question', 'agentic-commerce-ai-product-feed' ); ?>">&times;</button>
                </div>
                <?php
        }

        /**
         * Score panel: colored score + failing checks with fix guidance.
         *
         * @param int   $product_id Product ID.
         * @param mixed $score      Stored score ('' when never scored).
         * @param array $issues     Issue codes.
         * @param bool  $excluded   Product excluded from feeds.
         * @return void
         */
        private function render_score_box( $product_id, $score, $issues, $excluded ) {
                if ( $excluded ) {
                        printf(
                                '<div class="acpfw-score-box"><span class="acpfw-score-box__num acpfw-score-box__num--excluded">%1$s</span><span class="acpfw-score-box__meta"><span class="acpfw-score-box__label acpfw-score-box__label--excluded">%2$s</span><span class="acpfw-score-box__note">%3$s</span></span></div>',
                                esc_html__( '—', 'agentic-commerce-ai-product-feed' ),
                                esc_html__( 'Excluded', 'agentic-commerce-ai-product-feed' ),
                                esc_html__( 'This product is hidden from all AI channels. Uncheck the box below to include it.', 'agentic-commerce-ai-product-feed' )
                        );

                        return;
                }

                if ( '' === $score || null === $score ) {
                        $result = ACPFW_Readiness_Scorer::score_product( $product_id );
                        $score  = $result[0];
                        $issues = $result[1];
                }

                $score = (int) $score;

                if ( $score >= 80 ) {
                        $label = __( 'AI Ready', 'agentic-commerce-ai-product-feed' );
                        $mod   = 'ready';
                } elseif ( $score >= 50 ) {
                        $label = __( 'Needs Polish', 'agentic-commerce-ai-product-feed' );
                        $mod   = 'polish';
                } else {
                        $label = __( 'AI Blocked', 'agentic-commerce-ai-product-feed' );
                        $mod   = 'blocked';
                }

                $rescore_url = wp_nonce_url(
                        add_query_arg(
                                array(
                                        'action' => 'acpfw_rescore_product',
                                        'post'   => $product_id,
                                ),
                                admin_url( 'admin-post.php' )
                        ),
                        'acpfw_rescore_product_' . $product_id
                );
                ?>
                <div class="acpfw-score-box">
                        <span class="acpfw-score-box__num acpfw-score-box__num--<?php echo esc_attr( $mod ); ?>"><?php echo esc_html( (string) $score ); ?><small>/100</small></span>
                        <span class="acpfw-score-box__meta">
                                <span class="acpfw-score-box__label acpfw-score-box__label--<?php echo esc_attr( $mod ); ?>"><?php echo esc_html( $label ); ?></span>
                                <a href="<?php echo esc_url( $rescore_url ); ?>"><?php esc_html_e( 'Recalculate score', 'agentic-commerce-ai-product-feed' ); ?></a>
                        </span>
                </div>

                <?php if ( ! empty( $issues ) ) : ?>
                        <ul class="acpfw-issues">
                                <?php foreach ( $issues as $code ) : ?>
                                        <?php $blocked = in_array( 'REQ_CORE', (array) $issues, true ) && 'REQ_CORE' === $code; ?>
                                        <li class="<?php echo $blocked ? 'acpfw-issue--blocked' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static class names. ?>">
                                                <?php echo esc_html( ACPFW_Readiness_Scorer::get_issue_message( $code, $product_id ) ); ?>
                                        </li>
                                <?php endforeach; ?>
                        </ul>
                <?php else : ?>
                        <p class="acpfw-all-good"><?php esc_html_e( 'All readiness checks passed — this product is fully AI Ready.', 'agentic-commerce-ai-product-feed' ); ?></p>
                <?php endif; ?>
                <?php
        }

        /**
         * Save metabox fields (fires inside WC's product save flow).
         *
         * @param int $post_id Product ID.
         * @return void
         */
        public function save( $post_id ) {
                $post_id = absint( $post_id );

                if ( ! $post_id || ! isset( $_POST['acpfw_metabox_nonce'] ) ) {
                        return;
                }

                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- nonce verification only.
                if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['acpfw_metabox_nonce'] ) ), 'acpfw_save_product_metabox' ) ) {
                        return;
                }

                if ( ! current_user_can( 'edit_post', $post_id ) ) {
                        return;
                }

                if ( isset( $_POST['acpfw_brand'] ) ) {
                        update_post_meta( $post_id, '_acpfw_brand', sanitize_text_field( wp_unslash( $_POST['acpfw_brand'] ) ) );
                } else {
                        delete_post_meta( $post_id, '_acpfw_brand' );
                }

                $availability = isset( $_POST['acpfw_availability'] ) ? sanitize_key( wp_unslash( $_POST['acpfw_availability'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized on this line.

                if ( in_array( $availability, array( 'in_stock', 'out_of_stock', 'pre_order', 'backorder' ), true ) ) {
                        update_post_meta( $post_id, '_acpfw_availability', $availability );
                } else {
                        delete_post_meta( $post_id, '_acpfw_availability' );
                }

                if ( isset( $_POST['acpfw_exclude'] ) && 'yes' === $_POST['acpfw_exclude'] ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared to literal.
                        update_post_meta( $post_id, '_acpfw_exclude_from_feed', 'yes' );
                } else {
                        delete_post_meta( $post_id, '_acpfw_exclude_from_feed' );
                }

                // FAQs: keep max N pairs, drop empty rows entirely.
                $faqs_in = isset( $_POST['acpfw_faqs'] ) && is_array( $_POST['acpfw_faqs'] ) ? wp_unslash( $_POST['acpfw_faqs'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized per-field below.
                $faqs    = array();

                foreach ( array_slice( (array) $faqs_in, 0, self::MAX_FAQS ) as $pair ) {
                        if ( ! is_array( $pair ) ) {
                                continue;
                        }

                        $q = isset( $pair['q'] ) ? sanitize_text_field( $pair['q'] ) : '';
                        $a = isset( $pair['a'] ) ? sanitize_textarea_field( $pair['a'] ) : '';

                        if ( '' === trim( $q ) || '' === trim( $a ) ) {
                                continue;
                        }

                        $faqs[] = array(
                                'q' => $q,
                                'a' => $a,
                        );
                }

                if ( ! empty( $faqs ) ) {
                        update_post_meta( $post_id, '_acpfw_faqs', $faqs );
                } else {
                        delete_post_meta( $post_id, '_acpfw_faqs' );
                }

                // Meta changed → refresh the score immediately (cheap, deterministic).
                ACPFW_Readiness_Scorer::score_product( $post_id );
        }

        /**
         * "Recalculate score" button on the metabox (admin-post).
         *
         * @return void
         */
        public function handle_rescore() {
                $post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- absint on next use.

                if ( ! $post_id || 'product' !== get_post_type( $post_id ) ) {
                        wp_die( esc_html__( 'Invalid product.', 'agentic-commerce-ai-product-feed' ) );
                }

                check_admin_referer( 'acpfw_rescore_product_' . $post_id );

                if ( ! current_user_can( 'edit_post', $post_id ) ) {
                        wp_die( esc_html__( 'You are not allowed to edit this product.', 'agentic-commerce-ai-product-feed' ) );
                }

                ACPFW_Readiness_Scorer::score_product( $post_id );

                wp_safe_redirect( get_edit_post_link( $post_id, 'url' ) );
                exit;
        }
}
