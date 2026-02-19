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

// Delete the uploads directory and all its contents.
$upload_dir = trailingslashit( wp_upload_dir()['basedir'] ) . 'wc-sku-ean-comparator/';

if ( is_dir( $upload_dir ) ) {
	// Recursively remove all files inside the directory.
	$files = glob( $upload_dir . '*', GLOB_MARK );
	if ( $files ) {
		foreach ( $files as $file ) {
			if ( is_file( $file ) ) {
				wp_delete_file( $file );
			}
		}
	}
	rmdir( $upload_dir );
}
