<?php
/**
 * Plugin uninstall handler.
 *
 * This file is called by WordPress when the plugin is deleted via the admin.
 * It conditionally removes the custom database table, the upload directory,
 * and all stored options, based on the user's settings.
 *
 * @package WC_SKU_EAN_Comparator
 */

// Guard: only run during an actual uninstall.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Require Composer autoloader so we can use our classes.
$autoload = __DIR__ . '/vendor/autoload.php';
if ( file_exists( $autoload ) ) {
	require_once $autoload;
}

// Read saved settings to determine what to clean up.
$settings = wp_parse_args(
	(array) get_option( 'wc_sec_settings', array() ),
	array(
		'delete_tables_on_uninstall' => 0,
		'delete_files_on_uninstall'  => 0,
	)
);

// Drop the custom DB table if the user opted in.
if ( ! empty( $settings['delete_tables_on_uninstall'] ) ) {
	WC_SKU_EAN_Comparator\History::drop_table();
}

// Delete the uploads directory and all its contents if the user opted in.
if ( ! empty( $settings['delete_files_on_uninstall'] ) ) {
	$upload_dir = trailingslashit( wp_upload_dir()['basedir'] ) . 'wc-sku-ean-comparator/';

	/**
	 * Recursively delete a directory and all files/subdirectories inside it.
	 *
	 * @param string $dir Absolute path to the directory.
	 * @return void
	 */
	function wc_sec_delete_directory( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$entries = glob( trailingslashit( $dir ) . '*', GLOB_MARK );
		if ( $entries ) {
			foreach ( $entries as $entry ) {
				if ( is_dir( $entry ) ) {
					wc_sec_delete_directory( rtrim( $entry, '/' ) );
				} else {
					wp_delete_file( $entry );
				}
			}
		}

		rmdir( $dir );
	}

	wc_sec_delete_directory( $upload_dir );
}

// Always remove all plugin options.
delete_option( 'wc_sec_db_version' );
delete_option( 'wc_sec_settings' );
