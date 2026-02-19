<?php
/**
 * Main plugin orchestrator.
 *
 * @package WC_SKU_EAN_Comparator
 */

namespace WC_SKU_EAN_Comparator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Plugin
 *
 * Bootstraps all plugin components and registers WordPress hooks.
 */
class Plugin {

	/**
	 * Admin page handler instance.
	 *
	 * @var Admin_Page
	 */
	private Admin_Page $admin_page;

	/**
	 * File handler instance.
	 *
	 * @var File_Handler
	 */
	private File_Handler $file_handler;

	/**
	 * Comparator instance.
	 *
	 * @var Comparator
	 */
	private Comparator $comparator;

	/**
	 * History handler instance.
	 *
	 * @var History
	 */
	private History $history;

	/**
	 * AJAX handler instance.
	 *
	 * @var Ajax_Handler
	 */
	private Ajax_Handler $ajax_handler;

	/**
	 * Initialize all plugin components and register hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		$this->file_handler = new File_Handler();
		$this->history      = new History();
		$this->comparator   = new Comparator( $this->file_handler );
		$this->admin_page   = new Admin_Page( $this->history );
		$this->ajax_handler = new Ajax_Handler( $this->file_handler, $this->comparator, $this->history );

		$this->register_hooks();
	}

	/**
	 * Register all WordPress hooks.
	 *
	 * @return void
	 */
	private function register_hooks(): void {
		// Admin menu.
		add_action( 'admin_menu', array( $this->admin_page, 'register_menu' ) );

		// Admin assets.
		add_action( 'admin_enqueue_scripts', array( $this->admin_page, 'enqueue_assets' ) );

		// AJAX handlers (logged-in users only -- all require manage_options capability).
		add_action( 'wp_ajax_wc_sec_upload_file', array( $this->ajax_handler, 'handle_upload_file' ) );
		add_action( 'wp_ajax_wc_sec_list_files', array( $this->ajax_handler, 'handle_list_files' ) );
		add_action( 'wp_ajax_wc_sec_delete_file', array( $this->ajax_handler, 'handle_delete_file' ) );
		add_action( 'wp_ajax_wc_sec_get_sheet_names', array( $this->ajax_handler, 'handle_get_sheet_names' ) );
		add_action( 'wp_ajax_wc_sec_get_columns', array( $this->ajax_handler, 'handle_get_columns' ) );
		add_action( 'wp_ajax_wc_sec_get_brands', array( $this->ajax_handler, 'handle_get_brands' ) );
		add_action( 'wp_ajax_wc_sec_run_comparison', array( $this->ajax_handler, 'handle_run_comparison' ) );
		add_action( 'wp_ajax_wc_sec_rerun_comparison', array( $this->ajax_handler, 'handle_rerun_comparison' ) );
		add_action( 'wp_ajax_wc_sec_get_results', array( $this->ajax_handler, 'handle_get_results' ) );
		add_action( 'wp_ajax_wc_sec_delete_comparison', array( $this->ajax_handler, 'handle_delete_comparison' ) );
	}
}
