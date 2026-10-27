<?php
/**
 * Products list integration: AI Readiness column, badge rendering,
 * score-band filter dropdown and a bulk recalculation action.
 *
 * Badge bands per spec:
 *  - 80-100  green  "AI Ready"
 *  - 50-79   amber  "Needs Polish"
 *  - 0-49    red    "AI Blocked"
 *
 * The Auto-Fix chip only appears when at least one open issue is actually
 * AI-fixable (thin description, missing structured attributes, missing Q&A).
 * Issues a merchant must solve with real data (price, image, identifiers)
 * do not show a chip, so the affordance never fires on products Pro cannot
 * genuinely improve.
 *
 * All styling lives in assets/css/admin-list.css, enqueued on the
 * products list screen only (column width, bands, meter, Auto-Fix chip).
 *
 * @package ACPFW
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adds the AI Readiness column and tooling to the products list table.
 */
final class ACPFW_Product_List_Column {

        /**
         * Constructor.
         */
        public function __construct() {
                add_filter( 'manage_edit-product_columns', array( $this, 'add_column' ), 20 );
                add_action( 'manage_product_posts_custom_column', array( $this, 'render_column' ), 10, 2 );
                add_action( 'restrict_manage_posts', array( $this, 'add_score_filter' ), 20, 2 );
                add_action( 'pre_get_posts', array( $this, 'apply_score_filter' ) );
                add_filter( 'bulk_actions-edit-product', array( $this, 'add_bulk_action' ), 20 );
                add_filter( 'handle_bulk_actions-edit-product', array( $this, 'handle_bulk_action' ), 10, 3 );
                add_action( 'admin_notices', array( $this, 'bulk_notice' ) );
                add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        }

        /**
         * Enqueue the list-table stylesheet on the products screen only.
         *
         * @param string $hook Current admin page hook.
         * @return void
         */
        public function enqueue_assets( $hook ) {
                if ( 'edit.php' !== $hook ) {
                        return;
                }

                $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

                if ( ! $screen || 'product' !== $screen->post_type ) {
                        return;
                }

                wp_enqueue_style(
                        'acpfw-list',
                        ACPFW_PLUGIN_URL . 'assets/css/admin-list.css',
                        array(),
                        ACPFW_VERSION
                );
        }

        /**
         * Insert the readiness column right after the product name column.
         *
         * @param array $columns Existing columns.
         * @return array
         */
        public function add_column( $columns ) {
                $new = array();

                foreach ( $columns as $key => $label ) {
                        $new[ $key ] = $label;

                        if ( 'name' === $key ) {
                                $new['acpfw_readiness'] = __( 'AI Readiness', 'agentic-commerce-ai-product-feed' );
                        }
                }

                if ( ! isset( $new['acpfw_readiness'] ) ) {
                        $new['acpfw_readiness'] = __( 'AI Readiness', 'agentic-commerce-ai-product-feed' );
                }

                return $new;
        }

        /**
         * Render the badge for one product row.
         *
         * @param string $column  Column key.
         * @param int    $post_id Product ID.
         * @return void
         */
        public function render_column( $column, $post_id ) {
                if ( 'acpfw_readiness' !== $column ) {
                        return;
                }

                // Opted-out products show a neutral state instead of a score.
                if ( 'yes' === get_post_meta( $post_id, '_acpfw_exclude_from_feed', true ) ) {
                        printf(
                                '<span class="acpfw-tag--excluded" title="%1$s">%2$s</span>',
                                esc_attr__( 'This product is excluded from all AI feeds and llms.txt.', 'agentic-commerce-ai-product-feed' ),
                                esc_html__( 'Excluded', 'agentic-commerce-ai-product-feed' )
                        );

                        return;
                }

                $score = get_post_meta( $post_id, '_acpfw_readiness_score', true );

                // Lazy first score so existing products light up without a bulk run.
                if ( '' === $score || null === $score ) {
                        $result = ACPFW_Readiness_Scorer::score_product( $post_id );
                        $score  = $result[0];
                }

                $score = (int) $score;

                if ( $score >= 80 ) {
                        $band  = 'ready';
                        $label = __( 'AI Ready', 'agentic-commerce-ai-product-feed' );
                } elseif ( $score >= 50 ) {
                        $band  = 'polish';
                        $label = __( 'Needs Polish', 'agentic-commerce-ai-product-feed' );
                } else {
                        $band  = 'blocked';
                        $label = __( 'AI Blocked', 'agentic-commerce-ai-product-feed' );
                }

                $issues  = get_post_meta( $post_id, '_acpfw_readiness_issues', true );
                $tooltip = '';

                if ( is_array( $issues ) && ! empty( $issues ) ) {
                        $messages = array();

                        foreach ( $issues as $code ) {
                                $messages[] = ACPFW_Readiness_Scorer::get_issue_message( $code, $post_id );
                        }

                        $tooltip = implode( ' • ', $messages );
                } else {
                        $tooltip = __( 'All readiness checks passed.', 'agentic-commerce-ai-product-feed' );
                }

                $pct = max( 0, min( 100, $score ) );
                $fix = '';

                /*
                 * Chip only for AI-fixable issues (Pro Auto-Fix rewrites
                 * descriptions, extracts attributes, generates Q&A). Showing
                 * it on every sub-80 row was noise: a product blocked by a
                 * missing price or image cannot be fixed by AI.
                 */
                $fixable_issues = array( 'DESC_LEN', 'ATTRS', 'FAQ_SCHEMA' );

                if ( $score < 80 && is_array( $issues ) && ! empty( array_intersect( $fixable_issues, $issues ) ) ) {
                        $fix = sprintf(
                                '<a class="acpfw-readiness__fix" href="%1$s" title="%2$s"><span class="dashicons dashicons-admin-tools" aria-hidden="true"></span>%3$s</a>',
                                esc_url( admin_url( 'admin.php?page=acpfw-feed' ) ),
                                esc_attr__( 'One-click AI Auto-Fix is included in the Pro version.', 'agentic-commerce-ai-product-feed' ),
                                esc_html__( 'Auto-Fix with AI', 'agentic-commerce-ai-product-feed' )
                        );
                }

                echo '<div class="acpfw-readiness acpfw-readiness--' . esc_attr( $band ) . '" title="' . esc_attr( $tooltip ) . '">';
                echo '<span class="acpfw-readiness__head"><span class="acpfw-readiness__dot" aria-hidden="true"></span><span class="acpfw-readiness__label">' . esc_html( $label ) . '</span></span>';
                echo '<span class="acpfw-readiness__meterline"><span class="acpfw-readiness__meter" aria-hidden="true"><i style="width:' . esc_attr( $pct ) . '%;"></i></span><span class="acpfw-readiness__score">' . esc_html( $score ) . '/100</span></span>';

                if ( '' !== $fix ) {
                        echo $fix; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fully escaped when built above.
                }

                echo '</div>';
        }

        /**
         * Score band filter dropdown above the products table.
         *
         * @param string $post_type Current list table post type.
         * @param string $which     Top or bottom.
         * @return void
         */
        public function add_score_filter( $post_type, $which ) {
                if ( 'product' !== $post_type || 'top' !== $which ) {
                        return;
                }

                $current = isset( $_GET['acpfw_score_band'] ) ? sanitize_key( wp_unslash( $_GET['acpfw_score_band'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter UI.
                ?>
                <select name="acpfw_score_band">
                        <option value=""><?php esc_html_e( 'Filter by AI readiness', 'agentic-commerce-ai-product-feed' ); ?></option>
                        <option value="ready" <?php selected( 'ready', $current ); ?>><?php esc_html_e( 'AI Ready (80-100)', 'agentic-commerce-ai-product-feed' ); ?></option>
                        <option value="polish" <?php selected( 'polish', $current ); ?>><?php esc_html_e( 'Needs Polish (50-79)', 'agentic-commerce-ai-product-feed' ); ?></option>
                        <option value="blocked" <?php selected( 'blocked', $current ); ?>><?php esc_html_e( 'AI Blocked (0-49)', 'agentic-commerce-ai-product-feed' ); ?></option>
                </select>
                <?php
        }

        /**
         * Apply the score band filter to the products query.
         *
         * @param WP_Query $query Current query.
         * @return void
         */
        public function apply_score_filter( $query ) {
                if ( ! is_admin() || ! $query->is_main_query() ) {
                        return;
                }

                if ( 'product' !== $query->get( 'post_type' ) ) {
                        return;
                }

                // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only admin list filter.
                if ( ! isset( $_GET['acpfw_score_band'] ) ) {
                        return;
                }

                $band = sanitize_key( wp_unslash( $_GET['acpfw_score_band'] ) );
                // phpcs:enable

                $ranges = array(
                        'ready'   => array( 80, 100 ),
                        'polish'  => array( 50, 79 ),
                        'blocked' => array( 0, 49 ),
                );

                if ( ! isset( $ranges[ $band ] ) ) {
                        return;
                }

                $meta_query = (array) $query->get( 'meta_query' );

                $meta_query[] = array(
                        'key'     => '_acpfw_readiness_score',
                        'value'   => $ranges[ $band ],
                        'compare' => 'BETWEEN',
                        'type'    => 'NUMERIC',
                );

                $query->set( 'meta_query', $meta_query ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- intentional admin filter on indexed-free meta.
        }

        /**
         * Bulk action registration.
         *
         * @param array $actions Bulk actions.
         * @return array
         */
        public function add_bulk_action( $actions ) {
                $actions['acpfw_recalculate'] = __( 'Recalculate AI Readiness Score', 'agentic-commerce-ai-product-feed' );

                return $actions;
        }

        /**
         * Bulk action runner: rescore the selected products.
         *
         * @param string $redirect_to Redirect URL.
         * @param string $doaction    Action slug.
         * @param array  $post_ids    Selected product IDs.
         * @return string
         */
        public function handle_bulk_action( $redirect_to, $doaction, $post_ids ) {
                if ( 'acpfw_recalculate' !== $doaction ) {
                        return $redirect_to;
                }

                if ( ! current_user_can( 'manage_woocommerce' ) ) {
                        return $redirect_to;
                }

                $scored = 0;

                foreach ( (array) $post_ids as $post_id ) {
                        if ( 'product' !== get_post_type( $post_id ) ) {
                                continue;
                        }

                        ACPFW_Readiness_Scorer::score_product( $post_id );
                        $scored++;
                }

                return add_query_arg( 'acpfw_scored', $scored, remove_query_arg( array( 'acpfw_scored' ), $redirect_to ) );
        }

        /**
         * Success notice after a bulk rescore.
         *
         * @return void
         */
        public function bulk_notice() {
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only confirmation banner.
                if ( ! isset( $_GET['acpfw_scored'] ) ) {
                        return;
                }

                // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                $scored = absint( $_GET['acpfw_scored'] );

                if ( $scored <= 0 ) {
                        return;
                }

                printf(
                        '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                        sprintf(
                                /* translators: %d: number of products */
                                esc_html( _n( 'AI Readiness Score recalculated for %d product.', 'AI Readiness Score recalculated for %d products.', $scored, 'agentic-commerce-ai-product-feed' ) ),
                                esc_html( number_format_i18n( $scored ) )
                        )
                );
        }
}
