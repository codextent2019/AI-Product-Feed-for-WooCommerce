<?php
/**
 * Admin menu: WooCommerce > Agentic AI Feed dashboard (foundation version).
 *
 * Provides the feed URL panel and the manual generate/refresh action. This
 * page grows into the full dashboard (setup wizard entry, readiness overview,
 * AI channel stats) in later milestones.
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
		add_action( 'admin_post_acpfw_generate_feeds', array( $this, 'handle_generate' ) );
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

		ACPFW_OpenAI_Feed_Generator::generate();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => 'acpfw-feed',
					'acpfw_done' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Render the dashboard page.
	 *
	 * @return void
	 */
	public function render_page() {
		$stats      = ACPFW_OpenAI_Feed_Generator::get_stats();
		$generated  = ! empty( $stats['generated_at'] );
		$jsonl_url  = ACPFW_OpenAI_Feed_Generator::get_feed_url( 'jsonl' );
		$csv_url    = ACPFW_OpenAI_Feed_Generator::get_feed_url( 'csv' );
		?>
		<div class="wrap acpfw-dashboard">
			<h1><?php esc_html_e( 'Agentic AI Feed', 'agentic-commerce-ai-product-feed' ); ?></h1>

			<?php if ( isset( $_GET['acpfw_done'] ) && '1' === $_GET['acpfw_done'] ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p>
					<?php esc_html_e( 'Feeds generated successfully.', 'agentic-commerce-ai-product-feed' ); ?>
				</p></div>
			<?php endif; ?>

			<p>
				<?php esc_html_e( 'Submit the JSONL feed URL to OpenAI Merchant Center / ChatGPT Shopping. Feeds also rebuild automatically once per day.', 'agentic-commerce-ai-product-feed' ); ?>
			</p>

			<table class="widefat striped" style="max-width:820px;">
				<tbody>
					<tr>
						<td style="width:220px;"><strong><?php esc_html_e( 'OpenAI JSONL feed', 'agentic-commerce-ai-product-feed' ); ?></strong></td>
						<td><input type="text" class="widefat" readonly value="<?php echo esc_attr( $jsonl_url ); ?>" /></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'CSV feed', 'agentic-commerce-ai-product-feed' ); ?></strong></td>
						<td><input type="text" class="widefat" readonly value="<?php echo esc_attr( $csv_url ); ?>" /></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Status', 'agentic-commerce-ai-product-feed' ); ?></strong></td>
						<td>
							<?php if ( $generated ) : ?>
								<?php
								printf(
									/* translators: 1: number of feed rows, 2: number of products, 3: date/time */
									esc_html__( '%1$s feed rows from %2$s products. Last generated: %3$s.', 'agentic-commerce-ai-product-feed' ),
									esc_html( number_format_i18n( (int) $stats['rows'] ) ),
									esc_html( number_format_i18n( (int) $stats['products'] ) ),
									esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $stats['generated_at'] ) )
								);
								?>
							<?php else : ?>
								<?php esc_html_e( 'Not generated yet — click the button below.', 'agentic-commerce-ai-product-feed' ); ?>
							<?php endif; ?>
						</td>
					</tr>
				</tbody>
			</table>

			<p style="margin-top:14px;">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="acpfw_generate_feeds" />
					<?php wp_nonce_field( 'acpfw_generate_feeds' ); ?>
					<button type="submit" class="button button-primary button-hero">
						<?php esc_html_e( 'Generate / Refresh feeds now', 'agentic-commerce-ai-product-feed' ); ?>
					</button>
				</form>
			</p>
		</div>
		<?php
	}
}
