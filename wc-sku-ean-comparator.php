<?php
/**
 * Plugin Name:       WC SKU/EAN Comparator
 * Plugin URI:        https://github.com/sjbdigital/wc-sku-ean-comparator
 * Description:       Import price lists (CSV/XLS/XLSX) and compare products against WooCommerce catalog by SKU, EAN and name. Generates output CSV files with match results.
 * Version:           1.0.19
 * Author:            Roman Rehacek
 * Author URI:        https://github.com/romanrehacek
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wc-sku-ean-comparator
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * WC requires at least: 7.0
 * WC tested up to:   9.x
 *
 * @package WC_SKU_EAN_Comparator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants.
define( 'WC_SEC_VERSION', '1.0.19' );
define( 'WC_SEC_PLUGIN_FILE', __FILE__ );
define( 'WC_SEC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WC_SEC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WC_SEC_TEXT_DOMAIN', 'wc-sku-ean-comparator' );

// Composer autoloader.
if ( file_exists( WC_SEC_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require_once WC_SEC_PLUGIN_DIR . 'vendor/autoload.php';
} else {
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'WC SKU/EAN Comparator: Composer dependencies are missing. Please run "composer install" in the plugin directory.', 'wc-sku-ean-comparator' );
			echo '</p></div>';
		}
	);
	return;
}

// Declare WooCommerce HPOS compatibility.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

/**
 * Check if WooCommerce is active and show notice if not.
 *
 * @return bool
 */
function wc_sec_check_woocommerce(): bool {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action(
			'admin_notices',
			function () {
				echo '<div class="notice notice-error"><p>';
				printf(
					/* translators: %s: WooCommerce plugin name */
					esc_html__( 'WC SKU/EAN Comparator requires %s to be installed and active.', 'wc-sku-ean-comparator' ),
					'<strong>WooCommerce</strong>'
				);
				echo '</p></div>';
			}
		);
		return false;
	}
	return true;
}

/**
 * Bootstrap the plugin.
 *
 * @return void
 */
function wc_sec_init(): void {
	if ( ! wc_sec_check_woocommerce() ) {
		return;
	}

	// Load translations.
	load_plugin_textdomain(
		'wc-sku-ean-comparator',
		false,
		dirname( plugin_basename( __FILE__ ) ) . '/languages'
	);

	// Boot the main plugin class.
	$plugin = new WC_SKU_EAN_Comparator\Plugin();
	$plugin->init();
}
add_action( 'plugins_loaded', 'wc_sec_init' );

// Activation hook -- must be registered at top level, not inside another hook.
register_activation_hook(
	__FILE__,
	function () {
		if ( ! wc_sec_check_woocommerce() ) {
			wp_die(
				esc_html__( 'WC SKU/EAN Comparator requires WooCommerce to be installed and active.', 'wc-sku-ean-comparator' ),
				esc_html__( 'Plugin Activation Error', 'wc-sku-ean-comparator' ),
				array( 'back_link' => true )
			);
		}

		// Ensure autoloader is available.
		if ( ! file_exists( WC_SEC_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
			wp_die(
				esc_html__( 'WC SKU/EAN Comparator: Composer dependencies are missing. Please run "composer install".', 'wc-sku-ean-comparator' ),
				esc_html__( 'Plugin Activation Error', 'wc-sku-ean-comparator' ),
				array( 'back_link' => true )
			);
		}

		require_once WC_SEC_PLUGIN_DIR . 'vendor/autoload.php';

		// Create DB table.
		WC_SKU_EAN_Comparator\History::create_table();

		// Create upload directory.
		WC_SKU_EAN_Comparator\File_Handler::ensure_upload_dir();

		// Store plugin version.
		update_option( 'wc_sec_db_version', WC_SEC_VERSION );

		// Store default settings (only if not already set, preserving existing values on re-activation).
		add_option(
			'wc_sec_settings',
			array(
				'delete_tables_on_uninstall' => 0,
				'delete_files_on_uninstall'  => 0,
			)
		);
	}
);

// Deactivation hook -- no destructive actions, data is preserved.
register_deactivation_hook(
	__FILE__,
	function () {
		// Nothing to do on deactivation; data is intentionally preserved.
	}
);
