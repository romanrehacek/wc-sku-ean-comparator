<?php
/**
 * AJAX request handlers.
 *
 * @package WC_SKU_EAN_Comparator
 */

namespace WC_SKU_EAN_Comparator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Ajax_Handler
 *
 * Handles all wp_ajax_* requests. Every handler verifies the nonce and
 * the manage_options capability before processing.
 */
class Ajax_Handler {

	/**
	 * Nonce action used for all AJAX requests.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'wc_sec_ajax';

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
	 * Constructor.
	 *
	 * @param File_Handler $file_handler File handler instance.
	 * @param Comparator   $comparator   Comparator instance.
	 * @param History      $history      History handler instance.
	 */
	public function __construct(
		File_Handler $file_handler,
		Comparator $comparator,
		History $history
	) {
		$this->file_handler = $file_handler;
		$this->comparator   = $comparator;
		$this->history      = $history;
	}

	// =========================================================================
	// Security helpers
	// =========================================================================

	/**
	 * Verify AJAX nonce and capability. Sends JSON error and exits on failure.
	 *
	 * @return void
	 */
	private function verify_request(): void {
		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Security check failed. Please refresh the page and try again.', 'wc-sku-ean-comparator' ) ),
				403
			);
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You do not have permission to perform this action.', 'wc-sku-ean-comparator' ) ),
				403
			);
		}
	}

	// =========================================================================
	// File management handlers
	// =========================================================================

	/**
	 * Handle file upload (wp_ajax_wc_sec_upload_file).
	 *
	 * Expects: $_FILES['file'], $_POST['overwrite'] (optional, '1' = overwrite).
	 *
	 * @return void
	 */
	public function handle_upload_file(): void {
		$this->verify_request();

		if ( empty( $_FILES['file'] ) ) {
			wp_send_json_error( array( 'message' => __( 'No file provided.', 'wc-sku-ean-comparator' ) ) );
		}

		$overwrite = ! empty( $_POST['overwrite'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['overwrite'] ) );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- handled inside handle_upload()
		$result = $this->file_handler->handle_upload( $_FILES['file'], $overwrite );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$filename  = $result;
		$extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		$response  = array( 'filename' => $filename );

		// For XLS/XLSX, also return sheet names.
		if ( in_array( $extension, array( 'xls', 'xlsx' ), true ) ) {
			$filepath    = $this->file_handler->get_file_path( $filename );
			$sheet_names = is_wp_error( $filepath ) ? array() : $this->file_handler->get_sheet_names( $filepath );

			$response['sheet_names'] = is_wp_error( $sheet_names ) ? array() : $sheet_names;
		}

		wp_send_json_success( $response );
	}

	/**
	 * List uploaded files (wp_ajax_wc_sec_list_files).
	 *
	 * @return void
	 */
	public function handle_list_files(): void {
		$this->verify_request();

		$files = $this->file_handler->list_files();

		wp_send_json_success( array( 'files' => $files ) );
	}

	/**
	 * Delete an uploaded file (wp_ajax_wc_sec_delete_file).
	 *
	 * Expects: $_POST['filename'].
	 *
	 * @return void
	 */
	public function handle_delete_file(): void {
		$this->verify_request();

		$filename = isset( $_POST['filename'] ) ? sanitize_file_name( wp_unslash( $_POST['filename'] ) ) : '';

		if ( '' === $filename ) {
			wp_send_json_error( array( 'message' => __( 'Filename is required.', 'wc-sku-ean-comparator' ) ) );
		}

		$result = $this->file_handler->delete_file( $filename );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => __( 'File deleted successfully.', 'wc-sku-ean-comparator' ) ) );
	}

	/**
	 * Get column headers from a file/sheet (wp_ajax_wc_sec_get_columns).
	 *
	 * Expects: $_POST['filename'], $_POST['sheet_index'] (optional, default 0),
	 *          $_POST['header_row'] (optional, 1-based; 0 = auto-detect).
	 *
	 * @return void
	 */
	public function handle_get_columns(): void {
		$this->verify_request();

		$filename    = isset( $_POST['filename'] ) ? sanitize_file_name( wp_unslash( $_POST['filename'] ) ) : '';
		$sheet_index = isset( $_POST['sheet_index'] ) ? absint( $_POST['sheet_index'] ) : 0;
		$header_row  = isset( $_POST['header_row'] ) ? absint( $_POST['header_row'] ) : 0;

		if ( '' === $filename ) {
			wp_send_json_error( array( 'message' => __( 'Filename is required.', 'wc-sku-ean-comparator' ) ) );
		}

		$filepath = $this->file_handler->get_file_path( $filename );

		if ( is_wp_error( $filepath ) ) {
			wp_send_json_error( array( 'message' => $filepath->get_error_message() ) );
		}

		// Determine the actual header row to use.
		// If $header_row is 0, auto-detect from the raw first few rows.
		$effective_header_row = $header_row;
		if ( 0 === $header_row ) {
			$raw_rows = $this->file_handler->parse_sheet_or_csv_raw( $filepath, $sheet_index, File_Handler::MAX_HEADER_SCAN_ROWS );
			if ( ! is_wp_error( $raw_rows ) ) {
				$detected_idx         = $this->file_handler->detect_header_row_index( $raw_rows, File_Handler::MAX_HEADER_SCAN_ROWS );
				$effective_header_row = $detected_idx + 1;
			}
		}

		// Read enough rows for the preview (header + 5 data rows).
		$rows = $this->file_handler->parse_file( $filepath, $sheet_index, 5, $effective_header_row );

		if ( is_wp_error( $rows ) ) {
			wp_send_json_error( array( 'message' => $rows->get_error_message() ) );
		}

		if ( empty( $rows ) ) {
			wp_send_json_error( array( 'message' => __( 'The file appears to be empty.', 'wc-sku-ean-comparator' ) ) );
		}

		$headers      = $rows[0];
		$preview_rows = array_slice( $rows, 1 );

		wp_send_json_success(
			array(
				'headers'             => $headers,
				'preview_rows'        => $preview_rows,
				'column_count'        => count( $headers ),
				'detected_header_row' => $effective_header_row,
			)
		);
	}

	/**
	 * Get available WooCommerce product brands (wp_ajax_wc_sec_get_brands).
	 *
	 * @return void
	 */
	public function handle_get_brands(): void {
		$this->verify_request();

		$brands = get_terms(
			array(
				'taxonomy'   => 'product_brand',
				'hide_empty' => true,
				'orderby'    => 'name',
				'order'      => 'ASC',
				'number'     => 500,
			)
		);

		if ( is_wp_error( $brands ) || empty( $brands ) ) {
			wp_send_json_success( array( 'brands' => array() ) );
		}

		$brand_list = array();
		foreach ( $brands as $brand ) {
			$brand_list[] = array(
				'slug'  => $brand->slug,
				'name'  => $brand->name,
				'count' => $brand->count,
			);
		}

		wp_send_json_success( array( 'brands' => $brand_list ) );
	}

	// =========================================================================
	// Comparison handlers
	// =========================================================================

	/**
	 * Run a full comparison (wp_ajax_wc_sec_run_comparison).
	 *
	 * Expects POST fields:
	 *   - filename: string
	 *   - sheet_index: int (default 0)
	 *   - header_row: int (default 0 = auto-detect; 1-based when specified)
	 *   - brand_slugs: string[] (JSON array)
	 *   - column_mapping: JSON object { sku_columns: int[], ean_columns: int[], name_columns: int[] }
	 *
	 * @return void
	 */
	public function handle_run_comparison(): void {
		$this->verify_request();

		// Validate and parse input.
		$filename    = isset( $_POST['filename'] ) ? sanitize_file_name( wp_unslash( $_POST['filename'] ) ) : '';
		$sheet_index = isset( $_POST['sheet_index'] ) ? absint( $_POST['sheet_index'] ) : 0;
		$header_row  = isset( $_POST['header_row'] ) ? absint( $_POST['header_row'] ) : 0;

		$brand_slugs_raw = isset( $_POST['brand_slugs'] ) ? wp_unslash( $_POST['brand_slugs'] ) : '[]';
		$brand_slugs     = json_decode( sanitize_text_field( $brand_slugs_raw ), true );
		if ( ! is_array( $brand_slugs ) ) {
			$brand_slugs = array();
		}
		$brand_slugs = array_map( 'sanitize_key', $brand_slugs );

		$column_mapping_raw = isset( $_POST['column_mapping'] ) ? wp_unslash( $_POST['column_mapping'] ) : '{}';
		$column_mapping     = json_decode( sanitize_text_field( $column_mapping_raw ), true );

		if ( ! is_array( $column_mapping ) ||
			! isset( $column_mapping['sku_columns'] ) ||
			! is_array( $column_mapping['sku_columns'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid column mapping.', 'wc-sku-ean-comparator' ) ) );
		}

		$column_mapping = array(
			'sku_columns'  => array_map( 'absint', (array) $column_mapping['sku_columns'] ),
			'ean_columns'  => array_map( 'absint', (array) ( $column_mapping['ean_columns'] ?? array() ) ),
			'name_columns' => array_map( 'absint', (array) ( $column_mapping['name_columns'] ?? array() ) ),
			'header_row'   => $header_row,
		);

		if ( '' === $filename ) {
			wp_send_json_error( array( 'message' => __( 'Filename is required.', 'wc-sku-ean-comparator' ) ) );
		}

		// Get file path.
		$filepath = $this->file_handler->get_file_path( $filename );
		if ( is_wp_error( $filepath ) ) {
			wp_send_json_error( array( 'message' => $filepath->get_error_message() ) );
		}

		// Parse file.
		$rows = $this->file_handler->parse_file( $filepath, $sheet_index, 0, $header_row );
		if ( is_wp_error( $rows ) ) {
			wp_send_json_error( array( 'message' => $rows->get_error_message() ) );
		}

		if ( count( $rows ) < 2 ) {
			wp_send_json_error( array( 'message' => __( 'The file contains no data rows.', 'wc-sku-ean-comparator' ) ) );
		}

		// Load product maps (filtered by brands if specified).
		$product_maps = $this->comparator->load_product_maps( $brand_slugs );

		// Run comparisons.
		$pricelist_result = $this->comparator->compare_pricelist_to_shop( $rows, $column_mapping, $product_maps );
		$shop_result      = $this->comparator->compare_shop_to_pricelist( $rows, $column_mapping, $product_maps );

		// Build brand label for filenames.
		$brand_label = ! empty( $brand_slugs )
			? implode( '-', array_slice( $brand_slugs, 0, 3 ) )
			: 'all';

		// Write CSVs.
		$csv1_filename = $this->file_handler->generate_csv_filename( $brand_label, 'pricelist-to-shop' );
		$csv2_filename = $this->file_handler->generate_csv_filename( $brand_label, 'shop-to-pricelist' );

		$csv1_rows = $this->comparator->build_pricelist_to_shop_csv( $pricelist_result );
		$csv2_rows = $this->comparator->build_shop_to_pricelist_csv( $shop_result );

		$csv1_path = $this->file_handler->write_csv( $csv1_filename, $csv1_rows );
		$csv2_path = $this->file_handler->write_csv( $csv2_filename, $csv2_rows );

		// Build combined stats.
		$stats = array(
			'pricelist_total'     => $pricelist_result['stats']['total'],
			'pricelist_matched'   => $pricelist_result['stats']['matched'],
			'pricelist_unmatched' => $pricelist_result['stats']['unmatched'],
			'shop_total'          => $shop_result['stats']['total'],
			'shop_matched'        => $shop_result['stats']['matched'],
			'shop_unmatched'      => $shop_result['stats']['unmatched'],
		);

		// Build results summary (store limited rows to avoid DB bloat).
		$results_summary = array(
			'pricelist_to_shop' => array_slice( $pricelist_result['rows'], 0, 5000 ),
			'shop_to_pricelist' => array_slice( $shop_result['rows'], 0, 5000 ),
		);

		// Save to history.
		$history_data = array(
			'file_name'             => $filename,
			'brand_slugs'           => $brand_slugs,
			'column_mapping'        => $column_mapping,
			'stats'                 => $stats,
			'csv_pricelist_to_shop' => is_wp_error( $csv1_path ) ? null : $csv1_filename,
			'csv_shop_to_pricelist' => is_wp_error( $csv2_path ) ? null : $csv2_filename,
			'results_summary'       => wp_json_encode( $results_summary ),
		);

		$comparison_id = $this->history->save( $history_data );

		$response = array(
			'comparison_id'      => is_wp_error( $comparison_id ) ? 0 : $comparison_id,
			'stats'              => $stats,
			'csv_pricelist_url'  => ( ! is_wp_error( $csv1_path ) ) ? ( File_Handler::get_upload_url() . $csv1_filename ) : null,
			'csv_shop_url'       => ( ! is_wp_error( $csv2_path ) ) ? ( File_Handler::get_upload_url() . $csv2_filename ) : null,
			'history_url'        => Admin_Page::get_history_detail_url( is_wp_error( $comparison_id ) ? 0 : $comparison_id ),
			'pricelist_rows'     => $pricelist_result['rows'],
			'shop_rows'          => $shop_result['rows'],
		);

		wp_send_json_success( $response );
	}

	/**
	 * Get results for a specific comparison from history (wp_ajax_wc_sec_get_results).
	 *
	 * Expects: $_POST['comparison_id'], $_POST['type'] ('pricelist'|'shop'), $_POST['page'].
	 *
	 * @return void
	 */
	public function handle_get_results(): void {
		$this->verify_request();

		$comparison_id = isset( $_POST['comparison_id'] ) ? absint( $_POST['comparison_id'] ) : 0;
		$type          = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : 'pricelist';
		$page          = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1;
		$per_page      = 100;

		if ( $comparison_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid comparison ID.', 'wc-sku-ean-comparator' ) ) );
		}

		$comparison = $this->history->get( $comparison_id );

		if ( ! $comparison ) {
			wp_send_json_error( array( 'message' => __( 'Comparison not found.', 'wc-sku-ean-comparator' ) ) );
		}

		$results_summary = $comparison['results_summary'];

		if ( ! is_array( $results_summary ) ) {
			wp_send_json_error( array( 'message' => __( 'Results not available for this comparison.', 'wc-sku-ean-comparator' ) ) );
		}

		$key  = 'shop' === $type ? 'shop_to_pricelist' : 'pricelist_to_shop';
		$rows = $results_summary[ $key ] ?? array();

		$total   = count( $rows );
		$offset  = ( $page - 1 ) * $per_page;
		$paged   = array_slice( $rows, $offset, $per_page );

		wp_send_json_success(
			array(
				'rows'       => $paged,
				'total'      => $total,
				'page'       => $page,
				'per_page'   => $per_page,
				'total_pages' => (int) ceil( $total / $per_page ),
			)
		);
	}

	/**
	 * Delete a comparison from history (wp_ajax_wc_sec_delete_comparison).
	 *
	 * Expects: $_POST['comparison_id'].
	 *
	 * @return void
	 */
	public function handle_delete_comparison(): void {
		$this->verify_request();

		$comparison_id = isset( $_POST['comparison_id'] ) ? absint( $_POST['comparison_id'] ) : 0;

		if ( $comparison_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid comparison ID.', 'wc-sku-ean-comparator' ) ) );
		}

		$comparison = $this->history->get( $comparison_id );

		if ( ! $comparison ) {
			wp_send_json_error( array( 'message' => __( 'Comparison not found.', 'wc-sku-ean-comparator' ) ) );
		}

		// Delete associated CSV files.
		foreach ( array( 'csv_pricelist_to_shop', 'csv_shop_to_pricelist' ) as $csv_field ) {
			if ( ! empty( $comparison[ $csv_field ] ) ) {
				$this->file_handler->delete_file( $comparison[ $csv_field ] );
			}
		}

		$deleted = $this->history->delete( $comparison_id );

		if ( ! $deleted ) {
			wp_send_json_error( array( 'message' => __( 'Failed to delete comparison record.', 'wc-sku-ean-comparator' ) ) );
		}

		wp_send_json_success(
			array(
				'message'     => __( 'Comparison deleted successfully.', 'wc-sku-ean-comparator' ),
				'history_url' => Admin_Page::get_history_url(),
			)
		);
	}
}
