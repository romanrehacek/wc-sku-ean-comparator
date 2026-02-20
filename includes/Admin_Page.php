<?php
/**
 * Admin menu registration and page rendering.
 *
 * @package WC_SKU_EAN_Comparator
 */

namespace WC_SKU_EAN_Comparator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Admin_Page
 *
 * Registers a single plugin admin menu item under Tools, enqueues assets,
 * and dispatches rendering to template files based on the active tab.
 */
class Admin_Page {

	/**
	 * The admin menu slug (single entry under Tools).
	 *
	 * @var string
	 */
	const MENU_SLUG = 'wc-sku-ean-comparator';

	/**
	 * The hook suffix returned by add_management_page (set after menu registration).
	 *
	 * @var string
	 */
	private string $hook_suffix = '';

	/**
	 * History handler instance.
	 *
	 * @var History
	 */
	private History $history;

	/**
	 * Constructor.
	 *
	 * @param History $history History handler instance.
	 */
	public function __construct( History $history ) {
		$this->history = $history;
	}

	/**
	 * Register a single admin menu item under Tools.
	 *
	 * All tabs (New Comparison, History, Settings) are served from this one
	 * page via the ?tab= query parameter.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		$this->hook_suffix = (string) add_management_page(
			__( 'WC SKU/EAN Comparator', 'wc-sku-ean-comparator' ),
			__( 'WC SKU/EAN Comparator', 'wc-sku-ean-comparator' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue CSS and JS assets only on the plugin admin page.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( $hook_suffix !== $this->hook_suffix ) {
			return;
		}

		// Main stylesheet.
		wp_enqueue_style(
			'wc-sec-admin',
			WC_SEC_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			WC_SEC_VERSION
		);

		// Enqueue WooCommerce's select2 CSS so the enhanced select renders correctly.
		// WC only loads this on its own pages; we need it for our custom-field meta key picker.
		$wc_plugin_dir = WP_PLUGIN_DIR . '/woocommerce';
		$wc_plugin_url = WP_PLUGIN_URL . '/woocommerce';
		$select2_css   = $wc_plugin_dir . '/assets/css/select2.css';
		if ( file_exists( $select2_css ) ) {
			wp_enqueue_style(
				'wc-sec-select2',
				$wc_plugin_url . '/assets/css/select2.css',
				array(),
				defined( 'WC_VERSION' ) ? WC_VERSION : '1.0'
			);
		}

		// Ensure selectWoo (WooCommerce's Select2 fork) is available on this page.
		// WooCommerce only registers it on WC-specific pages, so we force-register
		// it here if it isn't already registered.
		if ( ! wp_script_is( 'selectWoo', 'registered' ) ) {
			$select_woo_path = $wc_plugin_dir . '/assets/js/selectWoo/selectWoo.full.min.js';
			if ( file_exists( $select_woo_path ) ) {
				wp_register_script(
					'selectWoo',
					$wc_plugin_url . '/assets/js/selectWoo/selectWoo.full.min.js',
					array( 'jquery' ),
					'1.0.10',
					true
				);
			}
		}
		$select_woo_dep = wp_script_is( 'selectWoo', 'registered' ) ? 'selectWoo' : null;

		// Main script -- depends on jQuery (available in WP admin).
		// selectWoo is WooCommerce's Select2 fork; used for the custom meta key picker.
		wp_enqueue_script(
			'wc-sec-admin',
			WC_SEC_PLUGIN_URL . 'assets/js/admin.js',
			array_filter( array( 'jquery', $select_woo_dep ) ),
			WC_SEC_VERSION,
			true
		);

		// Pass data to JS.
		wp_localize_script(
			'wc-sec-admin',
			'wcSecData',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'wc_sec_ajax' ),
				'pluginUrl' => WC_SEC_PLUGIN_URL,
				'editProductUrl' => admin_url( 'post.php?action=edit&post=' ),
				'i18n'      => array(
					'confirmDelete'      => __( 'Are you sure you want to delete this item? This action cannot be undone.', 'wc-sku-ean-comparator' ),
					'confirmDeleteFile'  => __( 'Are you sure you want to delete this file?', 'wc-sku-ean-comparator' ),
					'processing'         => __( 'Processing...', 'wc-sku-ean-comparator' ),
					'loadingProducts'    => __( 'Loading products from shop...', 'wc-sku-ean-comparator' ),
					'comparing'         => __( 'Comparing...', 'wc-sku-ean-comparator' ),
					'done'              => __( 'Done!', 'wc-sku-ean-comparator' ),
					'error'             => __( 'An error occurred. Please try again.', 'wc-sku-ean-comparator' ),
					'selectFile'        => __( 'Please select a file.', 'wc-sku-ean-comparator' ),
					'selectBrand'       => __( 'Please select at least one brand.', 'wc-sku-ean-comparator' ),
					'selectSkuColumn'   => __( 'Please select at least one SKU column.', 'wc-sku-ean-comparator' ),
					'rerun'             => __( 'Re-run Comparison', 'wc-sku-ean-comparator' ),
					// Stats card labels (Pricelist → Shop).
					'pricelistToShop'   => __( 'Pricelist → Shop', 'wc-sku-ean-comparator' ),
					'totalRows'         => __( 'Total rows', 'wc-sku-ean-comparator' ),
					'foundInShop'       => __( 'Found in shop', 'wc-sku-ean-comparator' ),
					'notFound'          => __( 'Not found', 'wc-sku-ean-comparator' ),
					// Stats card labels (Shop → Pricelist).
					'shopToPricelist'   => __( 'Shop → Pricelist', 'wc-sku-ean-comparator' ),
					'shopProducts'      => __( 'Shop products', 'wc-sku-ean-comparator' ),
					'inPricelist'       => __( 'In pricelist', 'wc-sku-ean-comparator' ),
					'notInPricelist'    => __( 'Not in pricelist', 'wc-sku-ean-comparator' ),
					// CSV download link labels.
					'downloadPricelist' => __( 'Download Pricelist→Shop CSV', 'wc-sku-ean-comparator' ),
					'downloadShop'      => __( 'Download Shop→Pricelist CSV', 'wc-sku-ean-comparator' ),
					// Comparison meta info labels.
					'metaFile'          => __( 'File', 'wc-sku-ean-comparator' ),
					'metaSheet'         => __( 'Sheet', 'wc-sku-ean-comparator' ),
					'metaBrands'        => __( 'Brands', 'wc-sku-ean-comparator' ),
					'metaAllBrands'     => __( 'All brands', 'wc-sku-ean-comparator' ),
				),
			)
		);
	}

	/**
	 * Main page callback. Dispatches to the correct tab renderer.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wc-sku-ean-comparator' ) );
		}

		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'new-comparison';

		// Whitelist of valid tabs; unknown tabs fall back to default.
		$valid_tabs = array( 'new-comparison', 'history', 'settings' );
		if ( ! in_array( $tab, $valid_tabs, true ) ) {
			$tab = 'new-comparison';
		}

		echo '<div class="wrap wc-sec-wrap">';
		echo '<h1>' . esc_html__( 'WC SKU/EAN Comparator', 'wc-sku-ean-comparator' ) . '</h1>';
		$this->render_tab_nav( $tab );

		switch ( $tab ) {
			case 'history':
				$this->render_history();
				break;
			case 'settings':
				$this->render_settings();
				break;
			default:
				$this->render_new_comparison();
		}

		echo '</div>';
	}

	/**
	 * Render the tab navigation bar.
	 *
	 * @param string $active_tab Currently active tab slug.
	 * @return void
	 */
	private function render_tab_nav( string $active_tab ): void {
		$tabs = array(
			'new-comparison' => __( 'New Comparison', 'wc-sku-ean-comparator' ),
			'history'        => __( 'History', 'wc-sku-ean-comparator' ),
			'settings'       => __( 'Settings', 'wc-sku-ean-comparator' ),
		);

		echo '<nav class="nav-tab-wrapper woo-nav-tab-wrapper">';
		foreach ( $tabs as $slug => $label ) {
			$url   = add_query_arg( 'tab', $slug, admin_url( 'tools.php?page=' . self::MENU_SLUG ) );
			$class = ( $slug === $active_tab ) ? 'nav-tab nav-tab-active' : 'nav-tab';
			printf(
				'<a href="%s" class="%s">%s</a>',
				esc_url( $url ),
				esc_attr( $class ),
				esc_html( $label )
			);
		}
		echo '</nav>';
	}

	/**
	 * Render the New Comparison tab content.
	 *
	 * @return void
	 */
	private function render_new_comparison(): void {
		$template = WC_SEC_PLUGIN_DIR . 'templates/new-comparison.php';

		if ( file_exists( $template ) ) {
			include $template;
		} else {
			echo '<p>' . esc_html__( 'Template file not found.', 'wc-sku-ean-comparator' ) . '</p>';
		}
	}

	/**
	 * Render the History tab content (list or detail depending on query params).
	 *
	 * @return void
	 */
	private function render_history(): void {
		// Detail view if comparison_id is present.
		$comparison_id = isset( $_GET['comparison_id'] ) ? absint( $_GET['comparison_id'] ) : 0;

		if ( $comparison_id > 0 ) {
			$comparison = $this->history->get( $comparison_id );

			if ( ! $comparison ) {
				wp_die( esc_html__( 'Comparison not found.', 'wc-sku-ean-comparator' ) );
			}

			$template = WC_SEC_PLUGIN_DIR . 'templates/history-detail.php';
		} else {
			$paged    = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
			$per_page = 20;

			// Sanitize sort parameters; defaults match History::get_list() defaults.
			$allowed_orderby = array( 'id', 'file_name', 'created_at' );
			$orderby         = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'created_at';
			$orderby         = in_array( $orderby, $allowed_orderby, true ) ? $orderby : 'created_at';
			$order           = isset( $_GET['order'] ) && 'asc' === strtolower( sanitize_key( wp_unslash( $_GET['order'] ) ) ) ? 'ASC' : 'DESC';

			$comparisons = $this->history->get_list( $paged, $per_page, $orderby, $order );
			$total       = $this->history->get_total_count();
			$template    = WC_SEC_PLUGIN_DIR . 'templates/history-list.php';
		}

		if ( file_exists( $template ) ) {
			include $template;
		} else {
			echo '<p>' . esc_html__( 'Template file not found.', 'wc-sku-ean-comparator' ) . '</p>';
		}
	}

	/**
	 * Render the Settings tab content. Handles form submission and displays the form.
	 *
	 * @return void
	 */
	private function render_settings(): void {
		$option_key = 'wc_sec_settings';
		$saved      = false;

		// Process form submission.
		if (
			isset( $_POST['wc_sec_settings_nonce'] ) &&
			wp_verify_nonce( sanitize_key( wp_unslash( $_POST['wc_sec_settings_nonce'] ) ), 'wc_sec_save_settings' )
		) {
			$settings = array(
				'delete_tables_on_uninstall' => isset( $_POST['delete_tables_on_uninstall'] ) ? 1 : 0,
				'delete_files_on_uninstall'  => isset( $_POST['delete_files_on_uninstall'] ) ? 1 : 0,
			);
			update_option( $option_key, $settings );
			$saved = true;
		}

		$settings = wp_parse_args(
			(array) get_option( $option_key, array() ),
			array(
				'delete_tables_on_uninstall' => 0,
				'delete_files_on_uninstall'  => 0,
			)
		);

		$template = WC_SEC_PLUGIN_DIR . 'templates/settings.php';

		if ( file_exists( $template ) ) {
			include $template;
		} else {
			echo '<p>' . esc_html__( 'Template file not found.', 'wc-sku-ean-comparator' ) . '</p>';
		}
	}

	/**
	 * Get the URL for the Settings tab.
	 *
	 * @return string Admin URL.
	 */
	public static function get_settings_url(): string {
		return add_query_arg( 'tab', 'settings', admin_url( 'tools.php?page=' . self::MENU_SLUG ) );
	}

	/**
	 * Get the URL for the New Comparison tab.
	 *
	 * @return string Admin URL.
	 */
	public static function get_new_comparison_url(): string {
		return admin_url( 'tools.php?page=' . self::MENU_SLUG );
	}

	/**
	 * Get the URL for the History tab.
	 *
	 * @return string Admin URL.
	 */
	public static function get_history_url(): string {
		return add_query_arg( 'tab', 'history', admin_url( 'tools.php?page=' . self::MENU_SLUG ) );
	}

	/**
	 * Get the URL for a specific comparison detail page.
	 *
	 * @param int $comparison_id Comparison record ID.
	 * @return string Admin URL.
	 */
	public static function get_history_detail_url( int $comparison_id ): string {
		return add_query_arg(
			array(
				'tab'           => 'history',
				'comparison_id' => $comparison_id,
			),
			admin_url( 'tools.php?page=' . self::MENU_SLUG )
		);
	}
}
