<?php
/**
 * File upload handling and spreadsheet/CSV parsing.
 *
 * @package WC_SKU_EAN_Comparator
 */

namespace WC_SKU_EAN_Comparator;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReader;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class File_Handler
 *
 * Handles file uploads, validation, storage management, and parsing of
 * CSV, XLS and XLSX price list files.
 */
class File_Handler {

	/**
	 * Maximum allowed file size in bytes (20 MB).
	 *
	 * @var int
	 */
	const MAX_FILE_SIZE = 20 * 1024 * 1024;

	/**
	 * Number of rows to scan when auto-detecting the header row.
	 *
	 * @var int
	 */
	const MAX_HEADER_SCAN_ROWS = 10;

	/**
	 * Allowed MIME types for uploaded files.
	 *
	 * @var array<string, string>
	 */
	const ALLOWED_MIME_TYPES = array(
		'csv'  => 'text/csv',
		'xls'  => 'application/vnd.ms-excel',
		'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
	);

	/**
	 * Get the plugin base upload directory path.
	 *
	 * @return string Absolute path to base upload directory (with trailing slash).
	 */
	public static function get_upload_dir(): string {
		$upload_dir = wp_upload_dir();
		return trailingslashit( $upload_dir['basedir'] ) . 'wc-sku-ean-comparator/';
	}

	/**
	 * Get the plugin base upload directory URL.
	 *
	 * @return string URL to base upload directory (with trailing slash).
	 */
	public static function get_upload_url(): string {
		$upload_dir = wp_upload_dir();
		return trailingslashit( $upload_dir['baseurl'] ) . 'wc-sku-ean-comparator/';
	}

	/**
	 * Get the imports subdirectory path (uploaded price list files).
	 *
	 * @return string Absolute path to imports directory (with trailing slash).
	 */
	public static function get_imports_dir(): string {
		return self::get_upload_dir() . 'imports/';
	}

	/**
	 * Get the imports subdirectory URL.
	 *
	 * @return string URL to imports directory (with trailing slash).
	 */
	public static function get_imports_url(): string {
		return self::get_upload_url() . 'imports/';
	}

	/**
	 * Get the exports subdirectory path (generated output CSV files).
	 *
	 * @return string Absolute path to exports directory (with trailing slash).
	 */
	public static function get_exports_dir(): string {
		return self::get_upload_dir() . 'exports/';
	}

	/**
	 * Get the exports subdirectory URL.
	 *
	 * @return string URL to exports directory (with trailing slash).
	 */
	public static function get_exports_url(): string {
		return self::get_upload_url() . 'exports/';
	}

	/**
	 * Ensure the upload directories exist and are protected.
	 * Called on plugin activation.
	 *
	 * @return bool True on success, false on failure.
	 */
	public static function ensure_upload_dir(): bool {
		$base_dir    = self::get_upload_dir();
		$imports_dir = self::get_imports_dir();
		$exports_dir = self::get_exports_dir();

		foreach ( array( $base_dir, $imports_dir, $exports_dir ) as $dir ) {
			if ( ! wp_mkdir_p( $dir ) ) {
				return false;
			}

			// The exports directory must remain publicly accessible (CSVs are downloaded
			// directly via URL). We block only directory listing there.
			// The base and imports directories are fully blocked from direct access.
			$is_exports = ( $dir === $exports_dir );
			$htaccess   = $dir . '.htaccess';

			if ( $is_exports ) {
				// Always write exports .htaccess to correct any previously written "deny from all".
				file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
					$htaccess,
					'Options -Indexes' . PHP_EOL
				);
			} elseif ( ! file_exists( $htaccess ) ) {
				file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
					$htaccess,
					'Options -Indexes' . PHP_EOL . 'deny from all' . PHP_EOL
				);
			}

			$index = $dir . 'index.php';
			if ( ! file_exists( $index ) ) {
				file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
					$index,
					'<?php // Silence is golden.' . PHP_EOL
				);
			}
		}

		return true;
	}

	/**
	 * Handle a file upload from $_FILES.
	 *
	 * If a file with the same name already exists the new file is automatically
	 * renamed by appending -1, -2, … before the extension until a free slot is
	 * found (up to 999 attempts).
	 *
	 * @param array{
	 *   name: string,
	 *   type: string,
	 *   tmp_name: string,
	 *   error: int,
	 *   size: int
	 * } $file The $_FILES entry.
	 * @return string|\WP_Error Relative filename (possibly renamed) on success, WP_Error on failure.
	 */
	public function handle_upload( array $file ): string|\WP_Error {
		// Check for PHP upload errors.
		if ( $file['error'] !== UPLOAD_ERR_OK ) {
			return new \WP_Error(
				'wc_sec_upload_error',
				$this->get_upload_error_message( $file['error'] )
			);
		}

		// Validate file size.
		if ( $file['size'] > self::MAX_FILE_SIZE ) {
			return new \WP_Error(
				'wc_sec_file_too_large',
				sprintf(
					/* translators: 1: file size, 2: max allowed size */
					__( 'File size (%1$s) exceeds the maximum allowed size (%2$s).', 'wc-sku-ean-comparator' ),
					size_format( $file['size'] ),
					size_format( self::MAX_FILE_SIZE )
				)
			);
		}

		// Validate file extension.
		$filename  = sanitize_file_name( $file['name'] );
		$extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );

		if ( ! array_key_exists( $extension, self::ALLOWED_MIME_TYPES ) ) {
			return new \WP_Error(
				'wc_sec_invalid_type',
				sprintf(
					/* translators: %s: file extension */
					__( 'File type ".%s" is not allowed. Allowed types: CSV, XLS, XLSX.', 'wc-sku-ean-comparator' ),
					esc_html( $extension )
				)
			);
		}

		// Validate MIME type using wp_check_filetype.
		$filetype = wp_check_filetype( $filename );
		if ( ! $filetype['ext'] ) {
			return new \WP_Error(
				'wc_sec_invalid_mime',
				__( 'File type could not be verified.', 'wc-sku-ean-comparator' )
			);
		}

		// Ensure upload directories exist.
		self::ensure_upload_dir();

		$upload_dir = self::get_imports_dir();

		// Auto-rename: if the target path is occupied, append -1, -2, … before the extension.
		$basename    = pathinfo( $filename, PATHINFO_FILENAME );
		$target_name = $filename;

		if ( file_exists( $upload_dir . $target_name ) ) {
			$counter = 1;
			do {
				$target_name = $basename . '-' . $counter . '.' . $extension;
				++$counter;
			} while ( file_exists( $upload_dir . $target_name ) && $counter <= 999 );
		}

		// Move uploaded file.
		if ( ! move_uploaded_file( $file['tmp_name'], $upload_dir . $target_name ) ) {
			return new \WP_Error(
				'wc_sec_move_failed',
				__( 'Failed to save uploaded file. Check directory permissions.', 'wc-sku-ean-comparator' )
			);
		}

		return $target_name;
	}

	/**
	 * List all uploaded price list files in the imports directory.
	 *
	 * @return array<int, array{name: string, size: int, modified: int, url: string}> List of files.
	 */
	public function list_files(): array {
		$dir = self::get_imports_dir();

		if ( ! is_dir( $dir ) ) {
			return array();
		}

		$files  = array();
		$handle = opendir( $dir );

		if ( false === $handle ) {
			return array();
		}

		while ( false !== ( $entry = readdir( $handle ) ) ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$extension = strtolower( pathinfo( $entry, PATHINFO_EXTENSION ) );
			if ( ! array_key_exists( $extension, self::ALLOWED_MIME_TYPES ) ) {
				continue;
			}

			$filepath = $dir . $entry;
			$files[]  = array(
				'name'     => $entry,
				'size'     => (int) filesize( $filepath ),
				'modified' => (int) filemtime( $filepath ),
				'url'      => self::get_imports_url() . $entry,
			);
		}

		closedir( $handle );

		// Sort by modification time, newest first.
		usort(
			$files,
			fn( $a, $b ) => $b['modified'] - $a['modified']
		);

		return $files;
	}

	/**
	 * Delete a file from the imports directory.
	 *
	 * @param string $filename Filename (basename only, no path traversal allowed).
	 * @return bool|\WP_Error True on success, WP_Error on failure.
	 */
	public function delete_file( string $filename ): bool|\WP_Error {
		$filename = sanitize_file_name( $filename );
		$filepath = self::get_imports_dir() . $filename;

		if ( ! file_exists( $filepath ) ) {
			return new \WP_Error(
				'wc_sec_file_not_found',
				sprintf(
					/* translators: %s: filename */
					__( 'File "%s" not found.', 'wc-sku-ean-comparator' ),
					esc_html( $filename )
				)
			);
		}

		if ( ! unlink( $filepath ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			return new \WP_Error(
				'wc_sec_delete_failed',
				__( 'Failed to delete file.', 'wc-sku-ean-comparator' )
			);
		}

		return true;
	}

	/**
	 * Delete an exported output CSV from the exports directory.
	 *
	 * @param string $filename Filename (basename only, no path traversal allowed).
	 * @return bool|\WP_Error True on success, WP_Error on failure.
	 */
	public function delete_export( string $filename ): bool|\WP_Error {
		$filename = sanitize_file_name( $filename );
		$filepath = self::get_exports_dir() . $filename;

		if ( ! file_exists( $filepath ) ) {
			return new \WP_Error(
				'wc_sec_file_not_found',
				sprintf(
					/* translators: %s: filename */
					__( 'File "%s" not found.', 'wc-sku-ean-comparator' ),
					esc_html( $filename )
				)
			);
		}

		if ( ! unlink( $filepath ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			return new \WP_Error(
				'wc_sec_delete_failed',
				__( 'Failed to delete file.', 'wc-sku-ean-comparator' )
			);
		}

		return true;
	}

	/**
	 * Get the full path to an uploaded file in the imports directory.
	 *
	 * @param string $filename Filename (basename only).
	 * @return string|\WP_Error Full path or WP_Error if not found.
	 */
	public function get_file_path( string $filename ): string|\WP_Error {
		$filename = sanitize_file_name( $filename );
		$filepath = self::get_imports_dir() . $filename;

		if ( ! file_exists( $filepath ) ) {
			return new \WP_Error(
				'wc_sec_file_not_found',
				sprintf(
					/* translators: %s: filename */
					__( 'File "%s" not found.', 'wc-sku-ean-comparator' ),
					esc_html( $filename )
				)
			);
		}

		return $filepath;
	}

	/**
	 * Get sheet names from an XLS or XLSX file.
	 *
	 * @param string $filepath Full path to the spreadsheet file.
	 * @return string[]|\WP_Error Array of sheet names, or WP_Error on failure.
	 */
	public function get_sheet_names( string $filepath ): array|\WP_Error {
		try {
			$reader      = $this->create_spreadsheet_reader( $filepath );
			$sheet_names = $reader->listWorksheetNames( $filepath );
			return $this->deduplicate_sheet_names( $sheet_names );
		} catch ( \Exception $e ) {
			return new \WP_Error(
				'wc_sec_spreadsheet_error',
				sprintf(
					/* translators: %s: error message */
					__( 'Failed to read spreadsheet: %s', 'wc-sku-ean-comparator' ),
					$e->getMessage()
				)
			);
		}
	}

	/**
	 * Parse a CSV file and return rows as arrays.
	 *
	 * @param string $filepath  Full path to the CSV file.
	 * @param int    $max_rows  Maximum rows to read (0 = all).
	 * @return array<int, array<int, string>>|\WP_Error Rows array or WP_Error.
	 */
	public function parse_csv( string $filepath, int $max_rows = 0 ): array|\WP_Error {
		if ( ! file_exists( $filepath ) ) {
			return new \WP_Error( 'wc_sec_file_not_found', __( 'CSV file not found.', 'wc-sku-ean-comparator' ) );
		}

		$rows   = array();
		$handle = fopen( $filepath, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( false === $handle ) {
			return new \WP_Error( 'wc_sec_file_open_failed', __( 'Failed to open CSV file.', 'wc-sku-ean-comparator' ) );
		}

		// Detect BOM (UTF-8) and skip it.
		$bom = fread( $handle, 3 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		if ( $bom !== "\xEF\xBB\xBF" ) {
			rewind( $handle );
		}

		$count = 0;
		while ( false !== ( $row = fgetcsv( $handle, 0, ',' ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgetcsv
			if ( null === $row ) {
				continue;
			}
			$rows[] = array_map( 'trim', $row );
			++$count;
			if ( $max_rows > 0 && $count >= $max_rows ) {
				break;
			}
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return $rows;
	}

	/**
	 * Parse a specific sheet from an XLS or XLSX file.
	 *
	 * @param string $filepath    Full path to the spreadsheet file.
	 * @param int    $sheet_index Zero-based sheet index.
	 * @param int    $max_rows    Maximum rows to read (0 = all).
	 * @return array<int, array<int, string>>|\WP_Error Rows array or WP_Error.
	 */
	public function parse_sheet( string $filepath, int $sheet_index = 0, int $max_rows = 0 ): array|\WP_Error {
		try {
			$reader = $this->create_spreadsheet_reader( $filepath );

			// Apply a row-range ReadFilter when max_rows is set to avoid loading
			// the entire worksheet into memory (critical for large XLSX files).
			if ( $max_rows > 0 && method_exists( $reader, 'setReadFilter' ) ) {
				$filter = new class( 1, $max_rows ) implements IReadFilter {
					private int $start_row;
					private int $end_row;

					public function __construct( int $start_row, int $end_row ) {
						$this->start_row = $start_row;
						$this->end_row   = $end_row;
					}

					// Signature without type hints for compatibility with PhpSpreadsheet 1.x
					// (IReadFilter::readCell had no type declarations before v2.0).
					// phpcs:ignore Squiz.Commenting.FunctionComment.Missing
					public function readCell( $columnAddress, $row, $worksheetName = '' ) {
						return $row >= $this->start_row && $row <= $this->end_row;
					}
				};
				$reader->setReadFilter( $filter );
			}

			$spreadsheet = $reader->load( $filepath );
			$sheet       = $spreadsheet->getSheet( $sheet_index );
			$rows        = array();
			$row_count   = 0;

			foreach ( $sheet->getRowIterator() as $row ) {
				$cell_iterator = $row->getCellIterator();
				$cell_iterator->setIterateOnlyExistingCells( false );
				$row_data = array();

				foreach ( $cell_iterator as $cell ) {
					$value      = $cell->getFormattedValue();
					$row_data[] = trim( (string) $value );
				}

				// Trim trailing empty cells.
				while ( ! empty( $row_data ) && '' === end( $row_data ) ) {
					array_pop( $row_data );
				}

				$rows[] = $row_data;
				++$row_count;

				if ( $max_rows > 0 && $row_count >= $max_rows ) {
					break;
				}
			}

			// Free memory.
			$spreadsheet->disconnectWorksheets();

			return $rows;
		} catch ( \Exception $e ) {
			return new \WP_Error(
				'wc_sec_spreadsheet_error',
				sprintf(
					/* translators: %s: error message */
					__( 'Failed to parse spreadsheet: %s', 'wc-sku-ean-comparator' ),
					$e->getMessage()
				)
			);
		}
	}

	/**
	 * Read raw rows from a file without any header-detection re-indexing.
	 * Used internally to get a plain slice of rows for header detection.
	 *
	 * @param string $filepath    Full path.
	 * @param int    $sheet_index Sheet index for XLS/XLSX (0-based).
	 * @param int    $max_rows    Maximum rows to read (0 = all).
	 * @return array<int, array<int, string>>|\WP_Error
	 */
	public function parse_sheet_or_csv_raw( string $filepath, int $sheet_index = 0, int $max_rows = 0 ): array|\WP_Error {
		$extension = strtolower( pathinfo( $filepath, PATHINFO_EXTENSION ) );

		if ( 'csv' === $extension ) {
			return $this->parse_csv( $filepath, $max_rows );
		}

		return $this->parse_sheet( $filepath, $sheet_index, $max_rows );
	}

	/**
	 * Parse a file (auto-detect CSV or spreadsheet) and return all rows.
	 *
	 * When $header_row is 0 (default), the header row is auto-detected by
	 * finding the row with the most non-empty cells within the first
	 * MAX_HEADER_SCAN_ROWS rows. Pass a positive integer (1-based) to pin
	 * the header to a specific row.
	 *
	 * The returned array is re-indexed so that index 0 is always the header
	 * row and subsequent indices are data rows.
	 *
	 * @param string $filepath    Full path.
	 * @param int    $sheet_index Sheet index for XLS/XLSX (0-based).
	 * @param int    $max_rows    Maximum data rows to return after the header (0 = all).
	 * @param int    $header_row  1-based header row number, or 0 for auto-detect.
	 * @return array<int, array<int, string>>|\WP_Error
	 */
	public function parse_file( string $filepath, int $sheet_index = 0, int $max_rows = 0, int $header_row = 0 ): array|\WP_Error {
		$extension = strtolower( pathinfo( $filepath, PATHINFO_EXTENSION ) );

		// How many extra rows to pre-read so auto-detection can scan them.
		$scan_rows = self::MAX_HEADER_SCAN_ROWS;

		if ( 'csv' === $extension ) {
			// For CSV, read enough rows to detect the header, then re-slice.
			$peek = $max_rows > 0
				? $this->parse_csv( $filepath, $scan_rows + $max_rows )
				: $this->parse_csv( $filepath );
		} else {
			$peek = $max_rows > 0
				? $this->parse_sheet( $filepath, $sheet_index, $scan_rows + $max_rows )
				: $this->parse_sheet( $filepath, $sheet_index );
		}

		if ( is_wp_error( $peek ) ) {
			return $peek;
		}

		// Determine the 0-based index of the header row.
		if ( $header_row > 0 ) {
			$header_idx = $header_row - 1; // convert 1-based to 0-based
		} else {
			$header_idx = $this->detect_header_row_index( $peek, $scan_rows );
		}

		// Slice: header row first, then data rows.
		$header    = $peek[ $header_idx ] ?? array();
		$data_rows = array_values( array_slice( $peek, $header_idx + 1 ) );

		if ( $max_rows > 0 ) {
			$data_rows = array_slice( $data_rows, 0, $max_rows );
		}

		return array_merge( array( $header ), $data_rows );
	}

	/**
	 * Detect the most likely header row index (0-based) in the first few rows.
	 *
	 * Strategy (in order):
	 *  1. Score every row by how many of its non-empty cells look like text
	 *     labels (not purely numeric, not a date-like pattern).
	 *  2. Among all rows that share the maximum non-empty cell count, prefer
	 *     the one with the highest text-label score.
	 *  3. Tie-break: first occurrence wins.
	 *
	 * This handles the common case where rows above the real header are
	 * sparsely filled (e.g. a company name in cell A1) while the header row
	 * itself has a full set of column labels.
	 *
	 * @param array<int, array<int, string>> $rows       Raw rows (0-indexed).
	 * @param int                            $scan_limit Maximum number of rows to scan.
	 * @return int 0-based index of the detected header row.
	 */
	public function detect_header_row_index( array $rows, int $scan_limit = 10 ): int {
		$limit = min( $scan_limit, count( $rows ) );
		if ( 0 === $limit ) {
			return 0;
		}

		$best_idx        = 0;
		$best_non_empty  = 0;
		$best_text_score = -1;

		for ( $i = 0; $i < $limit; $i++ ) {
			$non_empty   = 0;
			$text_labels = 0;

			foreach ( $rows[ $i ] as $cell ) {
				$cell = trim( (string) $cell );
				if ( '' === $cell ) {
					continue;
				}
				++$non_empty;

				// Count as a text label if the cell is NOT purely numeric
				// and does NOT look like a date (YYYY-MM-DD or DD.MM.YYYY etc.).
				$is_numeric = is_numeric( $cell );
				$is_date    = (bool) preg_match(
					'/^\d{1,4}[.\-\/]\d{1,2}[.\-\/]\d{2,4}$/',
					$cell
				);
				if ( ! $is_numeric && ! $is_date ) {
					++$text_labels;
				}
			}

			// Pick this row if it has strictly more non-empty cells, OR the
			// same number but a higher text-label score.
			if (
				$non_empty > $best_non_empty ||
				( $non_empty === $best_non_empty && $text_labels > $best_text_score )
			) {
				$best_non_empty  = $non_empty;
				$best_text_score = $text_labels;
				$best_idx        = $i;
			}
		}

		return $best_idx;
	}

	/**
	 * Generate a unique filename for an output CSV.
	 *
	 * @param string $prefix     Filename prefix (e.g. brand names).
	 * @param string $direction  Direction: 'pricelist_to_shop' or 'shop_to_pricelist'.
	 * @return string Filename (basename only).
	 */
	public function generate_csv_filename( string $prefix, string $direction ): string {
		$date = current_time( 'Y-m-d_H-i-s' );
		$slug = sanitize_file_name( $prefix );
		return "wc_sec_{$slug}_{$direction}_{$date}.csv";
	}

	/**
	 * Write rows to an output CSV file in the exports directory.
	 *
	 * @param string                        $filename Filename (basename).
	 * @param array<int, array<int|string, string>> $rows     Rows to write (first row = headers).
	 * @return string|\WP_Error Full path on success, WP_Error on failure.
	 */
	public function write_csv( string $filename, array $rows ): string|\WP_Error {
		self::ensure_upload_dir();
		$filepath = self::get_exports_dir() . sanitize_file_name( $filename );

		$handle = fopen( $filepath, 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( false === $handle ) {
			return new \WP_Error( 'wc_sec_write_failed', __( 'Failed to create output CSV file.', 'wc-sku-ean-comparator' ) );
		}

		// Write UTF-8 BOM for Excel compatibility.
		fwrite( $handle, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

		foreach ( $rows as $row ) {
			fputcsv( $handle, $row, ',' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return $filepath;
	}

	/**
	 * Create a PhpSpreadsheet reader appropriate for the given file.
	 *
	 * ReadDataOnly is enabled to skip all style/formatting processing, which
	 * dramatically reduces memory usage and execution time for large XLSX files.
	 *
	 * @param string $filepath Full path.
	 * @return IReader
	 * @throws \PhpOffice\PhpSpreadsheet\Reader\Exception If file type is unsupported.
	 */
	private function create_spreadsheet_reader( string $filepath ): IReader {
		$reader = IOFactory::createReaderForFile( $filepath );
		if ( method_exists( $reader, 'setReadDataOnly' ) ) {
			$reader->setReadDataOnly( true );
		}
		return $reader;
	}

	/**
	 * Deduplicate sheet names by appending _2, _3, etc. for duplicates.
	 *
	 * @param string[] $names Original sheet names.
	 * @return string[] Deduplicated sheet names.
	 */
	private function deduplicate_sheet_names( array $names ): array {
		$seen   = array();
		$result = array();

		foreach ( $names as $name ) {
			if ( ! isset( $seen[ $name ] ) ) {
				$seen[ $name ] = 1;
				$result[]      = $name;
			} else {
				++$seen[ $name ];
				$result[] = $name . '_' . $seen[ $name ];
			}
		}

		return $result;
	}

	/**
	 * Get a human-readable message for a PHP upload error code.
	 *
	 * @param int $error_code PHP upload error code.
	 * @return string Error message.
	 */
	private function get_upload_error_message( int $error_code ): string {
		$messages = array(
			UPLOAD_ERR_INI_SIZE   => __( 'The uploaded file exceeds the upload_max_filesize directive in php.ini.', 'wc-sku-ean-comparator' ),
			UPLOAD_ERR_FORM_SIZE  => __( 'The uploaded file exceeds the MAX_FILE_SIZE directive in the HTML form.', 'wc-sku-ean-comparator' ),
			UPLOAD_ERR_PARTIAL    => __( 'The uploaded file was only partially uploaded.', 'wc-sku-ean-comparator' ),
			UPLOAD_ERR_NO_FILE    => __( 'No file was uploaded.', 'wc-sku-ean-comparator' ),
			UPLOAD_ERR_NO_TMP_DIR => __( 'Missing a temporary folder.', 'wc-sku-ean-comparator' ),
			UPLOAD_ERR_CANT_WRITE => __( 'Failed to write file to disk.', 'wc-sku-ean-comparator' ),
			UPLOAD_ERR_EXTENSION  => __( 'A PHP extension stopped the file upload.', 'wc-sku-ean-comparator' ),
		);

		return $messages[ $error_code ] ?? __( 'Unknown upload error.', 'wc-sku-ean-comparator' );
	}
}
