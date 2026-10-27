<?php
/**
 * WP_Filesystem gateway for persistent file operations.
 *
 * The feed engine must write potentially multi-megabyte catalog files from
 * cron and Action Scheduler runs where no user is present to answer a
 * filesystem credential prompt. This gateway initialises WP_Filesystem and
 * falls back to the direct driver - the same driver WordPress itself selects
 * when FS_METHOD is "direct" - so behaviour stays identical on every host
 * where the uploads directory is writable (which the plugin requires anyway).
 *
 * @package ACPFW
 */

defined( 'ABSPATH' ) || exit;

/*
 * FS_CHMOD_FILE / FS_CHMOD_DIR are normally defined by wp-admin/includes/
 * file.php, which is not loaded on uninstall or early cron requests.
 */
if ( ! defined( 'FS_CHMOD_FILE' ) ) {
	define( 'FS_CHMOD_FILE', ( 0644 & ~ umask() ) );
}

if ( ! defined( 'FS_CHMOD_DIR' ) ) {
	define( 'FS_CHMOD_DIR', ( 0755 & ~ umask() ) );
}

/**
 * Thin gateway around the WP_Filesystem API.
 */
final class ACPFW_Files {

	/**
	 * Return the WP_Filesystem instance, initialising it when needed.
	 *
	 * @return WP_Filesystem_Base
	 */
	public static function fs() {
		global $wp_filesystem;

		if ( ! $wp_filesystem instanceof WP_Filesystem_Base && function_exists( 'WP_Filesystem' ) ) {
			WP_Filesystem();
		}

		if ( $wp_filesystem instanceof WP_Filesystem_Base ) {
			return $wp_filesystem;
		}

		/*
		 * Cron, REST and uninstall contexts can run before filesystem
		 * credentials are available. WP_Filesystem_Direct performs the
		 * same operations PHP would perform directly and is exactly what
		 * WP_Filesystem() selects on FS_METHOD=direct hosts, so this
		 * fallback changes nothing for writable-uploads installs.
		 */
		if ( ! class_exists( 'WP_Filesystem_Direct' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
		}

		return new WP_Filesystem_Direct( false );
	}
}
