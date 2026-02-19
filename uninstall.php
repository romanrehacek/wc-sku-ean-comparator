<?php
/**
 * Plugin uninstall handler.
 *
 * This file is called by WordPress when the plugin is deleted via the admin.
 * It removes the custom database table, the upload directory, and any stored options.
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

// Drop the custom DB table.
WC_SKU_EAN_Comparator\History::drop_table();

// Delete the plugin option.
delete_option( 'wc_sec_db_version' );

// Delete the uploads directory and all its contents recursively.
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
