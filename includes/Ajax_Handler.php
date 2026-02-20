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
	 * Expects: $_FILES['file']. If a file with the same name already exists it
	 * is automatically renamed with a numeric suffix (-1, -2, …).
	 *
	 * @return void
	 */
	public function handle_upload_file(): void {
		$this->verify_request();

		if ( empty( $_FILES['file'] ) ) {
			wp_send_json_error( array( 'message' => __( 'No file provided.', 'wc-sku-ean-comparator' ) ) );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- handled inside handle_upload()
		$result = $this->file_handler->handle_upload( $_FILES['file'] );

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
	 * Get sheet names from an existing XLS/XLSX file (wp_ajax_wc_sec_get_sheet_names).
	 *
	 * Expects: $_POST['filename'].
	 *
	 * @return void
	 */
	public function handle_get_sheet_names(): void {
		$this->verify_request();

		$filename = isset( $_POST['filename'] ) ? sanitize_file_name( wp_unslash( $_POST['filename'] ) ) : '';

		if ( '' === $filename ) {
			wp_send_json_error( array( 'message' => __( 'Filename is required.', 'wc-sku-ean-comparator' ) ) );
		}

		$filepath = $this->file_handler->get_file_path( $filename );

		if ( is_wp_error( $filepath ) ) {
			wp_send_json_error( array( 'message' => $filepath->get_error_message() ) );
		}

		$sheet_names = $this->file_handler->get_sheet_names( $filepath );

		if ( is_wp_error( $sheet_names ) ) {
			wp_send_json_error( array( 'message' => $sheet_names->get_error_message() ) );
		}

		wp_send_json_success( array( 'sheet_names' => $sheet_names ) );
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

		// Also fetch the raw rows that appear before the header row so the
		// preview table can show all rows (with the header row highlighted).
		$pre_header_rows = array();
		if ( $effective_header_row > 1 ) {
			$raw = $this->file_handler->parse_sheet_or_csv_raw( $filepath, $sheet_index, $effective_header_row - 1 );
			if ( ! is_wp_error( $raw ) ) {
				$pre_header_rows = $raw;
			}
		}

		wp_send_json_success(
			array(
				'headers'             => $headers,
				'preview_rows'        => $preview_rows,
				'pre_header_rows'     => $pre_header_rows,
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
	// Meta key endpoint
	// =========================================================================

	/**
	 * Return unique postmeta keys for WooCommerce products (wp_ajax_wc_sec_get_meta_keys).
	 *
	 * Accepts optional $_POST['search'] to filter results for Select2 AJAX.
	 * Returns up to 200 meta keys sorted alphabetically.
	 *
	 * @return void
	 */
	public function handle_get_meta_keys(): void {
		$this->verify_request();

		global $wpdb;

		$search = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $search !== '' ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			$rows = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT pm.meta_key
					FROM {$wpdb->postmeta} pm
					INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
					WHERE p.post_type IN ('product','product_variation')
					  AND p.post_status != 'trash'
					  AND pm.meta_key LIKE %s
					ORDER BY pm.meta_key
					LIMIT 200",
					$like
				)
			);
		} else {
			$rows = $wpdb->get_col(
				"SELECT DISTINCT pm.meta_key
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE p.post_type IN ('product','product_variation')
				  AND p.post_status != 'trash'
				ORDER BY pm.meta_key
				LIMIT 200"
			);
		}
		// phpcs:enable

		$results = array();
		foreach ( (array) $rows as $key ) {
			$results[] = array(
				'id'   => $key,
				'text' => $key,
			);
		}

		// Send results array directly so JS processResults receives response.data as the array.
		wp_send_json_success( $results );
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
	 *   - column_mapping: JSON object {
	 *       rules: [{ shop_field, custom_key, label, pricelist_columns: int[] }],
	 *       header_row, sheet_index, sheet_name
	 *     }
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
		// Use wp_kses_no_null instead of sanitize_text_field to preserve special chars in meta key values.
		$column_mapping = json_decode( $column_mapping_raw, true );

		if ( ! is_array( $column_mapping ) ||
			! isset( $column_mapping['rules'] ) ||
			! is_array( $column_mapping['rules'] ) ||
			empty( $column_mapping['rules'] ) ) {
			wp_send_json_error( array( 'message' => __( 'At least one mapping rule is required.', 'wc-sku-ean-comparator' ) ) );
		}

		// Sanitize and validate each rule.
		$allowed_fields = array( 'id', 'sku', 'ean', 'name', 'custom_field' );
		$rules          = array();
		foreach ( $column_mapping['rules'] as $raw_rule ) {
			if ( ! is_array( $raw_rule ) ) {
				continue;
			}
			$field = isset( $raw_rule['shop_field'] ) ? sanitize_key( $raw_rule['shop_field'] ) : '';
			if ( ! in_array( $field, $allowed_fields, true ) ) {
				continue;
			}
			$custom_key = null;
			if ( 'custom_field' === $field ) {
				$custom_key = isset( $raw_rule['custom_key'] ) ? sanitize_text_field( wp_unslash( (string) $raw_rule['custom_key'] ) ) : '';
				if ( '' === $custom_key ) {
					continue; // custom_field rule with no key is invalid.
				}
			}
			$pricelist_columns = isset( $raw_rule['pricelist_columns'] ) && is_array( $raw_rule['pricelist_columns'] )
				? array_map( 'absint', $raw_rule['pricelist_columns'] )
				: array();
			if ( empty( $pricelist_columns ) ) {
				continue; // Rule with no columns mapped is invalid.
			}
			$label   = isset( $raw_rule['label'] ) ? sanitize_text_field( wp_unslash( (string) $raw_rule['label'] ) ) : $field;
			$rules[] = array(
				'shop_field'        => $field,
				'custom_key'        => $custom_key,
				'label'             => $label,
				'pricelist_columns' => $pricelist_columns,
			);
		}

		if ( empty( $rules ) ) {
			wp_send_json_error( array( 'message' => __( 'No valid mapping rules provided.', 'wc-sku-ean-comparator' ) ) );
		}

		if ( '' === $filename ) {
			wp_send_json_error( array( 'message' => __( 'Filename is required.', 'wc-sku-ean-comparator' ) ) );
		}

		// Get file path.
		$filepath = $this->file_handler->get_file_path( $filename );
		if ( is_wp_error( $filepath ) ) {
			wp_send_json_error( array( 'message' => $filepath->get_error_message() ) );
		}

		// Resolve sheet name for XLS/XLSX files.
		$extension  = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		$sheet_name = '';
		if ( in_array( $extension, array( 'xls', 'xlsx' ), true ) ) {
			$sheet_names_list = $this->file_handler->get_sheet_names( $filepath );
			if ( ! is_wp_error( $sheet_names_list ) && isset( $sheet_names_list[ $sheet_index ] ) ) {
				$sheet_name = $sheet_names_list[ $sheet_index ];
			}
		}

		// Parse file.
		$rows = $this->file_handler->parse_file( $filepath, $sheet_index, 0, $header_row );
		if ( is_wp_error( $rows ) ) {
			wp_send_json_error( array( 'message' => $rows->get_error_message() ) );
		}

		if ( count( $rows ) < 2 ) {
			wp_send_json_error( array( 'message' => __( 'The file contains no data rows.', 'wc-sku-ean-comparator' ) ) );
		}

		// Enrich each rule with human-readable pricelist column names for history display.
		$file_headers  = $rows[0] ?? array();
		$resolve_names = static function ( array $indices ) use ( $file_headers ): array {
			return array_values(
				array_map(
					static fn( int $idx ) => isset( $file_headers[ $idx ] ) && $file_headers[ $idx ] !== ''
						? $file_headers[ $idx ]
						: (string) $idx,
					$indices
				)
			);
		};
		foreach ( $rules as &$rule ) {
			$rule['pricelist_column_names'] = $resolve_names( $rule['pricelist_columns'] );
		}
		unset( $rule );

		// Build the column_mapping to persist.
		$column_mapping = array(
			'rules'       => $rules,
			'header_row'  => $header_row,
			'sheet_index' => $sheet_index,
			'sheet_name'  => $sheet_name,
		);

		$product_maps = $this->comparator->load_product_maps( $rules, $brand_slugs );

		// Run comparisons.
		$pricelist_result = $this->comparator->compare_pricelist_to_shop( $rows, $rules, $product_maps );
		$shop_result      = $this->comparator->compare_shop_to_pricelist( $rows, $rules, $product_maps );

		// Build brand label for filenames.
		$brand_label = ! empty( $brand_slugs )
			? implode( '-', array_slice( $brand_slugs, 0, 3 ) )
			: 'all';

		// Write CSVs.
		$csv1_filename = $this->file_handler->generate_csv_filename( $brand_label, 'pricelist-to-shop' );
		$csv2_filename = $this->file_handler->generate_csv_filename( $brand_label, 'shop-to-pricelist' );

		$csv1_rows = $this->comparator->build_pricelist_to_shop_csv( $pricelist_result, $rules );
		$csv2_rows = $this->comparator->build_shop_to_pricelist_csv( $shop_result, $rules );

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
			'csv_pricelist_url'  => ( ! is_wp_error( $csv1_path ) ) ? ( File_Handler::get_exports_url() . $csv1_filename ) : null,
			'csv_shop_url'       => ( ! is_wp_error( $csv2_path ) ) ? ( File_Handler::get_exports_url() . $csv2_filename ) : null,
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
	 * Re-run a comparison from history (wp_ajax_wc_sec_rerun_comparison).
	 *
	 * Expects: $_POST['comparison_id'].
	 *
	 * Re-parses the original file with the stored parameters, runs the comparison
	 * again, overwrites the old CSV output files, and updates the history record.
	 *
	 * @return void
	 */
	public function handle_rerun_comparison(): void {
		$this->verify_request();

		$comparison_id = isset( $_POST['comparison_id'] ) ? absint( $_POST['comparison_id'] ) : 0;

		if ( $comparison_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid comparison ID.', 'wc-sku-ean-comparator' ) ) );
		}

		$comparison = $this->history->get( $comparison_id );

		if ( ! $comparison ) {
			wp_send_json_error( array( 'message' => __( 'Comparison not found.', 'wc-sku-ean-comparator' ) ) );
		}

		// Restore stored parameters.
		$filename       = sanitize_file_name( $comparison['file_name'] );
		$column_mapping = $comparison['column_mapping'];
		$brand_slugs    = is_array( $comparison['brand_slugs'] ) ? $comparison['brand_slugs'] : array();
		$brand_slugs    = array_map( 'sanitize_key', $brand_slugs );
		$header_row     = isset( $column_mapping['header_row'] ) ? absint( $column_mapping['header_row'] ) : 0;
		$sheet_index    = isset( $column_mapping['sheet_index'] ) ? absint( $column_mapping['sheet_index'] ) : 0;

		// Validate column_mapping structure (rules-based format).
		if ( ! is_array( $column_mapping ) || ! isset( $column_mapping['rules'] ) || ! is_array( $column_mapping['rules'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Stored column mapping is invalid or uses a legacy format. Please run a new comparison.', 'wc-sku-ean-comparator' ) ) );
		}

		$rules = $column_mapping['rules'];

		if ( empty( $rules ) ) {
			wp_send_json_error( array( 'message' => __( 'No valid mapping rules found in stored comparison.', 'wc-sku-ean-comparator' ) ) );
		}

		// Get file path.
		$filepath = $this->file_handler->get_file_path( $filename );
		if ( is_wp_error( $filepath ) ) {
			wp_send_json_error( array( 'message' => $filepath->get_error_message() ) );
		}

		// Refresh sheet name for XLS/XLSX files.
		$extension  = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		$sheet_name = '';
		if ( in_array( $extension, array( 'xls', 'xlsx' ), true ) ) {
			$sheet_names_list = $this->file_handler->get_sheet_names( $filepath );
			if ( ! is_wp_error( $sheet_names_list ) && isset( $sheet_names_list[ $sheet_index ] ) ) {
				$sheet_name = $sheet_names_list[ $sheet_index ];
			}
		}

		// Parse file.
		$rows = $this->file_handler->parse_file( $filepath, $sheet_index, 0, $header_row );
		if ( is_wp_error( $rows ) ) {
			wp_send_json_error( array( 'message' => $rows->get_error_message() ) );
		}

		if ( count( $rows ) < 2 ) {
			wp_send_json_error( array( 'message' => __( 'The file contains no data rows.', 'wc-sku-ean-comparator' ) ) );
		}

		// Refresh human-readable pricelist column names in each rule from the re-parsed file.
		$file_headers  = $rows[0] ?? array();
		$resolve_names = static function ( array $indices ) use ( $file_headers ): array {
			return array_values(
				array_map(
					static fn( int $idx ) => isset( $file_headers[ $idx ] ) && $file_headers[ $idx ] !== ''
						? $file_headers[ $idx ]
						: (string) $idx,
					$indices
				)
			);
		};
		foreach ( $rules as &$rule ) {
			$rule['pricelist_column_names'] = $resolve_names( (array) ( $rule['pricelist_columns'] ?? array() ) );
		}
		unset( $rule );

		$column_mapping['rules']       = $rules;
		$column_mapping['sheet_name']  = $sheet_name;
		$column_mapping['sheet_index'] = $sheet_index;

		// Load product maps.
		$product_maps = $this->comparator->load_product_maps( $rules, $brand_slugs );

		// Run comparisons.
		$pricelist_result = $this->comparator->compare_pricelist_to_shop( $rows, $rules, $product_maps );
		$shop_result      = $this->comparator->compare_shop_to_pricelist( $rows, $rules, $product_maps );

		// Delete old CSV files before writing new ones.
		foreach ( array( 'csv_pricelist_to_shop', 'csv_shop_to_pricelist' ) as $csv_field ) {
			if ( ! empty( $comparison[ $csv_field ] ) ) {
				$this->file_handler->delete_export( $comparison[ $csv_field ] );
			}
		}

		// Write new CSVs.
		$brand_label   = ! empty( $brand_slugs )
			? implode( '-', array_slice( $brand_slugs, 0, 3 ) )
			: 'all';

		$csv1_filename = $this->file_handler->generate_csv_filename( $brand_label, 'pricelist-to-shop' );
		$csv2_filename = $this->file_handler->generate_csv_filename( $brand_label, 'shop-to-pricelist' );

		$csv1_rows = $this->comparator->build_pricelist_to_shop_csv( $pricelist_result, $rules );
		$csv2_rows = $this->comparator->build_shop_to_pricelist_csv( $shop_result, $rules );

		$csv1_path = $this->file_handler->write_csv( $csv1_filename, $csv1_rows );
		$csv2_path = $this->file_handler->write_csv( $csv2_filename, $csv2_rows );

		// Build updated stats.
		$stats = array(
			'pricelist_total'     => $pricelist_result['stats']['total'],
			'pricelist_matched'   => $pricelist_result['stats']['matched'],
			'pricelist_unmatched' => $pricelist_result['stats']['unmatched'],
			'shop_total'          => $shop_result['stats']['total'],
			'shop_matched'        => $shop_result['stats']['matched'],
			'shop_unmatched'      => $shop_result['stats']['unmatched'],
		);

		$results_summary = array(
			'pricelist_to_shop' => array_slice( $pricelist_result['rows'], 0, 5000 ),
			'shop_to_pricelist' => array_slice( $shop_result['rows'], 0, 5000 ),
		);

		// Update history record.
		$this->history->update(
			$comparison_id,
			array(
				'column_mapping'        => $column_mapping,
				'stats'                 => $stats,
				'csv_pricelist_to_shop' => is_wp_error( $csv1_path ) ? null : $csv1_filename,
				'csv_shop_to_pricelist' => is_wp_error( $csv2_path ) ? null : $csv2_filename,
				'results_summary'       => wp_json_encode( $results_summary ),
			)
		);

		$response = array(
			'comparison_id'      => $comparison_id,
			'stats'              => $stats,
			'csv_pricelist_url'  => ( ! is_wp_error( $csv1_path ) ) ? ( File_Handler::get_exports_url() . $csv1_filename ) : null,
			'csv_shop_url'       => ( ! is_wp_error( $csv2_path ) ) ? ( File_Handler::get_exports_url() . $csv2_filename ) : null,
			'detail_url'         => Admin_Page::get_history_detail_url( $comparison_id ),
			'pricelist_rows'     => $pricelist_result['rows'],
			'shop_rows'          => $shop_result['rows'],
		);

		wp_send_json_success( $response );
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

		// Delete associated CSV output files.
		foreach ( array( 'csv_pricelist_to_shop', 'csv_shop_to_pricelist' ) as $csv_field ) {
			if ( ! empty( $comparison[ $csv_field ] ) ) {
				$this->file_handler->delete_export( $comparison[ $csv_field ] );
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
