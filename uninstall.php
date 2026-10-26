<?php
/**
 * Uninstall cleanup for Agentic Commerce & AI Product Feed.
 *
 * Stored data is only removed when the merchant explicitly opted in via
 * Settings > Agentic AI Feed > "Remove all plugin data on uninstall".
 *
 * @package ACPFW
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
        exit;
}

$acpfw_settings = get_option( 'acpfw_settings', array() );

if ( ! is_array( $acpfw_settings ) || ! isset( $acpfw_settings['uninstall_cleanup'] ) || 'yes' !== $acpfw_settings['uninstall_cleanup'] ) {
        return; // Default: keep data so a reinstall does not lose mappings/scores.
}

global $wpdb;

/*
 * Options & transients (acpfw_* keys).
 */
delete_option( 'acpfw_settings' );
delete_option( 'acpfw_db_version' );
delete_option( 'acpfw_dirty_product_ids' );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'acpfw\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

/*
 * Product meta (scores, issues, AI fields, overrides).
 */
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_acpfw\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

/*
 * Scheduled background jobs (Action Scheduler tables when present).
 */
wp_clear_scheduled_hook( 'acpfw_rebuild_after_product_change' );

$acpfw_as_actions = $wpdb->prefix . 'actionscheduler_actions';
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $acpfw_as_actions ) ) === $acpfw_as_actions ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query( "DELETE FROM {$acpfw_as_actions} WHERE hook LIKE 'acpfw\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

/*
 * Generated feed files.
 */
$acpfw_upload_dir = wp_upload_dir();
$acpfw_feed_dir   = trailingslashit( $acpfw_upload_dir['basedir'] ) . 'acpfw-feeds';

if ( is_dir( $acpfw_feed_dir ) ) {
        $acpfw_files = (array) glob( $acpfw_feed_dir . '/*' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
        foreach ( $acpfw_files as $acpfw_file ) {
                if ( is_file( $acpfw_file ) ) {
                        wp_delete_file( $acpfw_file );
                }
        }

        if ( is_dir( $acpfw_feed_dir ) ) {
                @rmdir( $acpfw_feed_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        }
}

// Clear the cached rewrite rules for our endpoints.
flush_rewrite_rules();
