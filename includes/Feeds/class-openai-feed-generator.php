<?php
/**
 * OpenAI feed generator: builds the .jsonl and .csv feed files locally.
 *
 * - Storage: wp-content/uploads/acpfw-feeds/openai-feed-{16-char-token}.{ext}
 *   Unguessable token URLs; directory listing blocked by an index.php stub.
 * - Atomic writes: rows stream into .tmp files which are rename()d into place,
 *   so an AI crawler never reads a half-written feed.
 * - Memory safe: products are pulled in pages of 100 and rows stream straight
 *   to disk; nothing accumulates in memory.
 *
 * @package ACPFW
 */

defined( 'ABSPATH' ) || exit;

/**
 * Generates and manages the hosted OpenAI feed files.
 */
final class ACPFW_OpenAI_Feed_Generator {

	const PAGE_SIZE = 100;

	/**
	 * Stable secret token used in feed file names.
	 *
	 * @return string
	 */
	public static function get_feed_token() {
		$token = (string) get_option( 'acpfw_feed_token', '' );

		if ( '' === $token ) {
			$token = wp_generate_password( 16, false, false );
			update_option( 'acpfw_feed_token', $token, false );
		}

		return $token;
	}

	/**
	 * File name for a feed type.
	 *
	 * @param string $type 'jsonl' or 'csv'.
	 * @return string
	 */
	public static function get_feed_filename( $type ) {
		return 'openai-feed-' . self::get_feed_token() . '.' . sanitize_key( $type );
	}

	/**
	 * Public URL for a feed file.
	 *
	 * @param string $type 'jsonl' or 'csv'.
	 * @return string
	 */
	public static function get_feed_url( $type ) {
		return ACPFW_Plugin_Core::get_feed_directory_url() . self::get_feed_filename( $type );
	}

	/**
	 * Timestamp + counters of the last successful generation.
	 *
	 * @return array
	 */
	public static function get_stats() {
		$stats = get_option( 'acpfw_feed_stats', array() );

		return is_array( $stats ) ? $stats : array();
	}

	/**
	 * Generate both feed files from the full published catalog.
	 *
	 * @return array Stats: rows, products, generated_at.
	 */
	public static function generate() {
		$feed_dir   = ACPFW_Plugin_Core::prepare_feed_directory();
		$jsonl_path = trailingslashit( $feed_dir ) . self::get_feed_filename( 'jsonl' );
		$csv_path   = trailingslashit( $feed_dir ) . self::get_feed_filename( 'csv' );

		$tmp_jsonl = $jsonl_path . '.tmp-' . uniqid( '', true );
		$tmp_csv   = $csv_path . '.tmp-' . uniqid( '', true );

		$jsonl_handle = fopen( $tmp_jsonl, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- atomic temp-file streaming.
		$csv_handle   = fopen( $tmp_csv, 'wb' );   // phpcs:ignore WordPress.WP.AlternativeFunctions.

		if ( ! $jsonl_handle || ! $csv_handle ) {
			if ( $jsonl_handle ) {
				fclose( $jsonl_handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.
			}
			if ( $csv_handle ) {
				fclose( $csv_handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.
			}
			wp_delete_file( $tmp_jsonl );
			wp_delete_file( $tmp_csv );

			return array(
				'rows'         => 0,
				'products'     => 0,
				'generated_at' => 0,
				'error'        => 'cannot_write_feed_files',
			);
		}

		// CSV header.
		$columns = ACPFW_Product_Mapper::csv_columns();
		fputcsv( $csv_handle, $columns ); // phpcs:ignore WordPress.WP.AlternativeFunctions.

		$row_count     = 0;
		$product_count = 0;
		$page          = 1;

		do {
			$product_ids = wc_get_products(
				array(
					'status'  => 'publish',
					'limit'   => self::PAGE_SIZE,
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
				$product = wc_get_product( $product_id );

				if ( ! $product instanceof WC_Product ) {
					continue;
				}

				$type = $product->get_type();

				if ( 'grouped' === $type ) {
					continue; // Grouped products are containers, not purchasable rows.
				}

				if ( 'variable' === $type ) {
					$children = $product->get_children();

					foreach ( $children as $child_id ) {
						$variation = wc_get_product( $child_id );

						if ( ! $variation instanceof WC_Product || ! $variation->is_purchasable() ) {
							continue;
						}

						$row = ACPFW_Product_Mapper::map_variation( $variation, $product );
						self::write_row( $jsonl_handle, $csv_handle, $row );
						$row_count++;
					}

					$product_count++;
					continue;
				}

				// Simple + external products.
				if ( ! $product->is_purchasable() ) {
					continue;
				}

				$row = ACPFW_Product_Mapper::map_simple( $product );
				self::write_row( $jsonl_handle, $csv_handle, $row );
				$row_count++;
				$product_count++;
			}

			$page++;
		} while ( count( $product_ids ) === self::PAGE_SIZE );

		fclose( $jsonl_handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.
		fclose( $csv_handle );   // phpcs:ignore WordPress.WP.AlternativeFunctions.

		// Atomic swap: crawlers only ever see complete files.
		rename( $tmp_jsonl, $jsonl_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.
		rename( $tmp_csv, $csv_path );     // phpcs:ignore WordPress.PHP.NoSilencedErrors.

		$stats = array(
			'rows'         => $row_count,
			'products'     => $product_count,
			'generated_at' => time(),
		);

		update_option( 'acpfw_feed_stats', $stats, false );

		/**
		 * Fires after a full feed rebuild completes.
		 *
		 * @param array $stats Generation stats.
		 */
		do_action( 'acpfw_feeds_generated', $stats );

		return $stats;
	}

	/**
	 * Stream one mapped row into both open file handles.
	 *
	 * @param resource $jsonl_handle JSONL file handle.
	 * @param resource $csv_handle   CSV file handle.
	 * @param array    $row          Mapped row.
	 * @return void
	 */
	private static function write_row( $jsonl_handle, $csv_handle, $row ) {
		// JSONL: omit empty optional fields, keep explicit booleans.
		$jsonl_row = array();

		foreach ( $row as $key => $value ) {
			if ( '' === $value || null === $value ) {
				continue;
			}
			$jsonl_row[ $key ] = $value;
		}

		fwrite( $jsonl_handle, wp_json_encode( $jsonl_row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.

		// CSV: serialize nested values as JSON strings, in column order.
		$csv_row = array();
		foreach ( array_keys( self::csv_column_keys() ) as $column ) {
			$value = isset( $row[ $column ] ) ? $row[ $column ] : '';

			if ( is_array( $value ) ) {
				$value = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			} elseif ( is_bool( $value ) ) {
				$value = $value ? 'true' : 'false';
			}

			$csv_row[] = $value;
		}

		fputcsv( $csv_handle, $csv_row ); // phpcs:ignore WordPress.WP.AlternativeFunctions.
	}

	/**
	 * Column key list (avoids rebuilding the full skeleton each row).
	 *
	 * @return array
	 */
	private static function csv_column_keys() {
		static $keys = null;

		if ( null === $keys ) {
			$keys = array_fill_keys( ACPFW_Product_Mapper::csv_columns(), true );
		}

		return $keys;
	}
}
