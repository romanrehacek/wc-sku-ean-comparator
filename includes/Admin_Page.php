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
 * Registers the plugin admin menu under Tools, enqueues assets,
 * and dispatches rendering to template files.
 */
class Admin_Page {

	/**
	 * The admin menu slug for the main page.
	 *
	 * @var string
	 */
	const MENU_SLUG = 'wc-sku-ean-comparator';

	/**
	 * The admin menu slug for the history subpage.
	 *
	 * @var string
	 */
	const HISTORY_SLUG = 'wc-sku-ean-comparator-history';

	/**
	 * The hook suffix returned by add_management_page (set after menu registration).
	 *
	 * @var string
	 */
	private string $hook_suffix_new = '';

	/**
	 * The hook suffix for the history page.
	 *
	 * @var string
	 */
	private string $hook_suffix_history = '';

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
	 * Register admin menu items under Tools.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		// Main page: Tools > WC SKU/EAN Comparator (acts as "New Comparison").
		$this->hook_suffix_new = (string) add_management_page(
			__( 'WC SKU/EAN Comparator', 'wc-sku-ean-comparator' ),
			__( 'WC SKU/EAN Comparator', 'wc-sku-ean-comparator' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_new_comparison' )
		);

		// Submenu: History.
		$this->hook_suffix_history = (string) add_submenu_page(
			'tools.php',
			__( 'Comparison History', 'wc-sku-ean-comparator' ),
			__( 'Comparison History', 'wc-sku-ean-comparator' ),
			'manage_options',
			self::HISTORY_SLUG,
			array( $this, 'render_history' )
		);
	}

	/**
	 * Enqueue CSS and JS assets only on plugin admin pages.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		$plugin_pages = array( $this->hook_suffix_new, $this->hook_suffix_history );

		if ( ! in_array( $hook_suffix, $plugin_pages, true ) ) {
			return;
		}

		// Main stylesheet.
		wp_enqueue_style(
			'wc-sec-admin',
			WC_SEC_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			WC_SEC_VERSION
		);

		// Main script -- depends on jQuery (available in WP admin).
		wp_enqueue_script(
			'wc-sec-admin',
			WC_SEC_PLUGIN_URL . 'assets/js/admin.js',
			array( 'jquery', 'jquery-ui-sortable', 'wp-util' ),
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
					'comparing'          => __( 'Comparing...', 'wc-sku-ean-comparator' ),
					'done'               => __( 'Done!', 'wc-sku-ean-comparator' ),
					'error'              => __( 'An error occurred. Please try again.', 'wc-sku-ean-comparator' ),
					'selectFile'         => __( 'Please select a file.', 'wc-sku-ean-comparator' ),
					'selectBrand'        => __( 'Please select at least one brand.', 'wc-sku-ean-comparator' ),
					'selectSkuColumn'    => __( 'Please select at least one SKU column.', 'wc-sku-ean-comparator' ),
					'rerun'              => __( 'Re-run Comparison', 'wc-sku-ean-comparator' ),
					// Stats card labels (Pricelist → Shop).
					'pricelistToShop'     => __( 'Pricelist → Shop', 'wc-sku-ean-comparator' ),
					'totalRows'           => __( 'Total rows', 'wc-sku-ean-comparator' ),
					'foundInShop'         => __( 'Found in shop', 'wc-sku-ean-comparator' ),
					'notFound'            => __( 'Not found', 'wc-sku-ean-comparator' ),
					// Stats card labels (Shop → Pricelist).
					'shopToPricelist'     => __( 'Shop → Pricelist', 'wc-sku-ean-comparator' ),
					'shopProducts'        => __( 'Shop products', 'wc-sku-ean-comparator' ),
					'inPricelist'         => __( 'In pricelist', 'wc-sku-ean-comparator' ),
					'notInPricelist'      => __( 'Not in pricelist', 'wc-sku-ean-comparator' ),
					// CSV download link labels.
					'downloadPricelist'   => __( 'Download Pricelist→Shop CSV', 'wc-sku-ean-comparator' ),
					'downloadShop'        => __( 'Download Shop→Pricelist CSV', 'wc-sku-ean-comparator' ),
					// Comparison meta info labels.
					'metaFile'            => __( 'File', 'wc-sku-ean-comparator' ),
					'metaSheet'           => __( 'Sheet', 'wc-sku-ean-comparator' ),
					'metaBrands'          => __( 'Brands', 'wc-sku-ean-comparator' ),
					'metaAllBrands'       => __( 'All brands', 'wc-sku-ean-comparator' ),
				),
			)
		);
	}

	/**
	 * Render the New Comparison admin page.
	 *
	 * @return void
	 */
	public function render_new_comparison(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wc-sku-ean-comparator' ) );
		}

		$template = WC_SEC_PLUGIN_DIR . 'templates/new-comparison.php';

		if ( file_exists( $template ) ) {
			include $template;
		} else {
			echo '<div class="wrap"><h1>' . esc_html__( 'WC SKU/EAN Comparator', 'wc-sku-ean-comparator' ) . '</h1>';
			echo '<p>' . esc_html__( 'Template file not found.', 'wc-sku-ean-comparator' ) . '</p></div>';
		}
	}

	/**
	 * Render the History admin page (list or detail depending on query params).
	 *
	 * @return void
	 */
	public function render_history(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wc-sku-ean-comparator' ) );
		}

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
			echo '<div class="wrap"><h1>' . esc_html__( 'Comparison History', 'wc-sku-ean-comparator' ) . '</h1>';
			echo '<p>' . esc_html__( 'Template file not found.', 'wc-sku-ean-comparator' ) . '</p></div>';
		}
	}

	/**
	 * Get the URL for the New Comparison page.
	 *
	 * @return string Admin URL.
	 */
	public static function get_new_comparison_url(): string {
		return admin_url( 'tools.php?page=' . self::MENU_SLUG );
	}

	/**
	 * Get the URL for the History list page.
	 *
	 * @return string Admin URL.
	 */
	public static function get_history_url(): string {
		return admin_url( 'tools.php?page=' . self::HISTORY_SLUG );
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
				'page'          => self::HISTORY_SLUG,
				'comparison_id' => $comparison_id,
			),
			admin_url( 'tools.php' )
		);
	}
}
