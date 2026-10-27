<?php
/**
 * Uninstall cleanup for Agentic Commerce & AI Product Feed.
 *
 * Stored data is only removed when the merchant explicitly opted in via
 * Settings > Agentic AI Feed > "Remove all plugin data on uninstall".
 * On multisite each site's own opt-in governs that site's data.
 *
 * @package ACPFW
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Filesystem gateway: uninstall runs outside the normal plugin boot,
// so the WP_Filesystem wrapper is loaded explicitly here.
require_once __DIR__ . '/includes/class-files.php';

/**
 * Full cleanup for a single site (call with switch_to_blog() context active).
 *
 * @return void
 */
function acpfw_uninstall_site() {
	global $wpdb;

	$settings = get_option( 'acpfw_settings', array() );

	if ( ! is_array( $settings ) || ! isset( $settings['uninstall_cleanup'] ) || 'yes' !== $settings['uninstall_cleanup'] ) {
		return; // Default: keep data so a reinstall does not lose mappings/scores.
	}

	/*
	 * Generated JPEG copies created by the image resolver live in the
	 * uploads directory next to the originals. Delete the FILES first —
	 * the bulk meta delete below would otherwise orphan them forever.
	 */
	$jpeg_copy_meta = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_acpfw_jpeg_copy_url'"
	);

	if ( is_array( $jpeg_copy_meta ) ) {
		foreach ( $jpeg_copy_meta as $row ) {
			$file = (string) $row->meta_value;

			if ( '' === $file ) {
				continue;
			}

			$upload = wp_get_upload_dir();
			$path   = str_replace( trailingslashit( $upload['baseurl'] ), trailingslashit( $upload['basedir'] ), $file );

			if ( '' !== $path && false === strpos( $path, '://' ) && file_exists( $path ) ) {
				wp_delete_file( $path );
			}
		}
	}

	/*
	 * Options & transients. Transients live in the options table under
	 * "_transient_acpfw_*" (plus their "_transient_timeout_acpfw_*"
	 * siblings), which the plain "acpfw_%" LIKE does not match.
	 */
	delete_option( 'acpfw_settings' );
	delete_option( 'acpfw_db_version' );
	delete_option( 'acpfw_dirty_product_ids' );
	delete_option( 'acpfw_rebuild_pending' );
	delete_option( 'acpfw_feed_token' );
	delete_option( 'acpfw_feed_stats' );

	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'acpfw\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_acpfw\\_%' OR option_name LIKE '\\_transient\\_timeout\\_acpfw\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	/*
	 * Product meta (scores, issues, AI fields, overrides, JPEG cache).
	 */
	$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_acpfw\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	/*
	 * Scheduled background jobs (WP-Cron + Action Scheduler tables).
	 */
	wp_clear_scheduled_hook( 'acpfw_rebuild_after_product_change' );
	wp_clear_scheduled_hook( 'acpfw_generate_feeds' );

	$acpfw_as_actions = $wpdb->prefix . 'actionscheduler_actions';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $acpfw_as_actions ) ) === $acpfw_as_actions ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		// %i (identifier placeholder, WP 6.2+) keeps the table name out of
		// raw interpolation; the hook pattern travels as a bound parameter.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE hook LIKE %s', $acpfw_as_actions, 'acpfw\\_%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-shot uninstall cleanup.
	}

	/*
	 * Generated feed files (openai-feed-*.jsonl/.csv, llms*.txt, temp files).
	 */
	$acpfw_upload_dir = wp_upload_dir();
	$acpfw_feed_dir   = trailingslashit( $acpfw_upload_dir['basedir'] ) . 'acpfw-feeds';

	if ( ACPFW_Files::fs()->is_dir( $acpfw_feed_dir ) ) {
		// Recursive delete: feed files, stale .tmp-* leftovers and the
		// directory itself, all through WP_Filesystem.
		ACPFW_Files::fs()->delete( $acpfw_feed_dir, true );
	}

	// Clear the cached rewrite rules for our endpoints.
	flush_rewrite_rules();
}

if ( is_multisite() ) {
	$acpfw_site_ids = get_sites( array( 'fields' => 'ids', 'number' => 0, 'network_id' => get_current_network_id() ) );

	foreach ( (array) $acpfw_site_ids as $acpfw_site_id ) {
		switch_to_blog( (int) $acpfw_site_id );
		acpfw_uninstall_site();
		restore_current_blog();
	}
} else {
	acpfw_uninstall_site();
}
