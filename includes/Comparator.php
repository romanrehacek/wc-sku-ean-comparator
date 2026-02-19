<?php
/**
 * Core product comparison logic.
 *
 * @package WC_SKU_EAN_Comparator
 */

namespace WC_SKU_EAN_Comparator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Comparator
 *
 * Handles batch loading of WooCommerce product data and performs
 * bidirectional comparison between an imported price list and the
 * WooCommerce product catalog.
 */
class Comparator {

	/**
	 * Batch size for loading products from the database.
	 *
	 * @var int
	 */
	const BATCH_SIZE = 500;

	/**
	 * File handler instance.
	 *
	 * @var File_Handler
	 */
	private File_Handler $file_handler;

	/**
	 * Constructor.
	 *
	 * @param File_Handler $file_handler File handler instance.
	 */
	public function __construct( File_Handler $file_handler ) {
		$this->file_handler = $file_handler;
	}

	/**
	 * Load SKU→ID and EAN→ID maps for WooCommerce products,
	 * optionally filtered by brand slugs.
	 *
	 * Products are fetched in batches to avoid PHP memory limits.
	 *
	 * @param string[] $brand_slugs Optional WooCommerce product_brand taxonomy slugs to filter by.
	 *                              Empty array = all products.
	 * @return array{
	 *   sku_map: array<string, int>,
	 *   ean_map: array<string, int>,
	 *   product_data: array<int, array{id: int, name: string, sku: string, ean: string}>
	 * }
	 */
	public function load_product_maps( array $brand_slugs = array() ): array {
		global $wpdb;

		$sku_map      = array();
		$ean_map      = array();
		$product_data = array();

		// Build the base product ID query.
		if ( ! empty( $brand_slugs ) ) {
			$product_ids = $this->get_product_ids_by_brands( $brand_slugs );
		} else {
			$product_ids = $this->get_all_product_ids();
		}

		if ( empty( $product_ids ) ) {
			return array(
				'sku_map'      => $sku_map,
				'ean_map'      => $ean_map,
				'product_data' => $product_data,
			);
		}

		// Process in batches.
		$total     = count( $product_ids );
		$processed = 0;

		while ( $processed < $total ) {
			$batch_ids = array_slice( $product_ids, $processed, self::BATCH_SIZE );

			if ( empty( $batch_ids ) ) {
				break;
			}

			$placeholders = implode( ',', array_fill( 0, count( $batch_ids ), '%d' ) );

			// Fetch post title + SKU + EAN in one query with two meta JOINs.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT p.ID, p.post_title,
						MAX(CASE WHEN pm.meta_key = '_sku' THEN pm.meta_value ELSE NULL END) AS sku,
						MAX(CASE WHEN pm.meta_key = '_global_unique_id' THEN pm.meta_value ELSE NULL END) AS ean
					FROM {$wpdb->posts} p
					LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key IN ('_sku', '_global_unique_id')
					WHERE p.ID IN (" . $placeholders . ")
					AND p.post_status = 'publish'
					GROUP BY p.ID, p.post_title", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					...$batch_ids
				),
				ARRAY_A
			);

			if ( $rows ) {
				foreach ( $rows as $row ) {
					$product_id = (int) $row['ID'];
					$sku        = trim( (string) $row['sku'] );
					$ean        = trim( (string) $row['ean'] );
					$name       = (string) $row['post_title'];

					if ( $sku !== '' ) {
						$sku_map[ $sku ] = $product_id;
					}
					if ( $ean !== '' ) {
						$ean_map[ $ean ] = $product_id;
					}

					$product_data[ $product_id ] = array(
						'id'   => $product_id,
						'name' => $name,
						'sku'  => $sku,
						'ean'  => $ean,
					);
				}
			}

			$processed += count( $batch_ids );

			// Free WP object cache after each batch to manage memory.
			wp_cache_flush();
		}

		return array(
			'sku_map'      => $sku_map,
			'ean_map'      => $ean_map,
			'product_data' => $product_data,
		);
	}

	/**
	 * Compare price list rows against the WooCommerce shop (Pricelist → Shop).
	 *
	 * For each row in the price list, finds the matching WooCommerce product
	 * by SKU first, then EAN.
	 *
	 * @param array<int, array<int, string>>     $rows           Parsed rows from the price list (row 0 = headers).
	 * @param array{
	 *   sku_columns: int[],
	 *   ean_columns: int[],
	 *   name_columns: int[]
	 * }                                         $column_mapping Mapping of column indices.
	 * @param array{
	 *   sku_map: array<string, int>,
	 *   ean_map: array<string, int>,
	 *   product_data: array<int, array{id: int, name: string, sku: string, ean: string}>
	 * }                                         $product_maps   Pre-loaded product maps.
	 * @return array{
	 *   headers: string[],
	 *   rows: array<int, array<string, mixed>>,
	 *   stats: array{total: int, matched: int, unmatched: int}
	 * }
	 */
	public function compare_pricelist_to_shop(
		array $rows,
		array $column_mapping,
		array $product_maps
	): array {
		if ( empty( $rows ) ) {
			return array(
				'headers' => array(),
				'rows'    => array(),
				'stats'   => array( 'total' => 0, 'matched' => 0, 'unmatched' => 0 ),
			);
		}

		$headers = $rows[0];
		$data    = array_slice( $rows, 1 );
		$result  = array();
		$matched = 0;

		foreach ( $data as $row ) {
			// Extract values from configured columns.
			$sku_value  = $this->extract_column_value( $row, $column_mapping['sku_columns'] );
			$ean_value  = $this->extract_column_value( $row, $column_mapping['ean_columns'] );
			$name_value = $this->extract_combined_value( $row, $column_mapping['name_columns'] );

			// Try to match by SKU, then by EAN.
			$product_id = $this->find_product_id( $sku_value, $ean_value, $product_maps );
			$found      = $product_id > 0;

			if ( $found ) {
				++$matched;
				$shop_product = $product_maps['product_data'][ $product_id ];
			} else {
				$shop_product = array( 'id' => 0, 'name' => '', 'sku' => '', 'ean' => '' );
			}

			$result[] = array(
				'pricelist_name' => $name_value,
				'pricelist_sku'  => $sku_value,
				'pricelist_ean'  => $ean_value,
				'found'          => $found,
				'shop_id'        => $shop_product['id'],
				'shop_name'      => $shop_product['name'],
				'shop_sku'       => $shop_product['sku'],
				'shop_ean'       => $shop_product['ean'],
				'original_row'   => $row,
				'original_headers' => $headers,
			);
		}

		$total = count( $data );

		return array(
			'headers' => $headers,
			'rows'    => $result,
			'stats'   => array(
				'total'     => $total,
				'matched'   => $matched,
				'unmatched' => $total - $matched,
			),
		);
	}

	/**
	 * Compare shop products against the price list (Shop → Pricelist).
	 *
	 * For each WooCommerce product in the selected brands, checks whether
	 * it appears in the price list.
	 *
	 * @param array<int, array<int, string>>     $rows           Parsed rows from the price list (row 0 = headers).
	 * @param array{
	 *   sku_columns: int[],
	 *   ean_columns: int[],
	 *   name_columns: int[]
	 * }                                         $column_mapping Mapping of column indices.
	 * @param array{
	 *   sku_map: array<string, int>,
	 *   ean_map: array<string, int>,
	 *   product_data: array<int, array{id: int, name: string, sku: string, ean: string}>
	 * }                                         $product_maps   Pre-loaded product maps.
	 * @return array{
	 *   rows: array<int, array<string, mixed>>,
	 *   stats: array{total: int, matched: int, unmatched: int}
	 * }
	 */
	public function compare_shop_to_pricelist(
		array $rows,
		array $column_mapping,
		array $product_maps
	): array {
		// Build lookup maps from price list.
		$pricelist_sku_set = array();
		$pricelist_ean_set = array();

		foreach ( array_slice( $rows, 1 ) as $row ) {
			$sku = $this->extract_column_value( $row, $column_mapping['sku_columns'] );
			$ean = $this->extract_column_value( $row, $column_mapping['ean_columns'] );

			if ( $sku !== '' ) {
				$pricelist_sku_set[ $sku ] = true;
			}
			if ( $ean !== '' ) {
				$pricelist_ean_set[ $ean ] = true;
			}
		}

		$result    = array();
		$matched   = 0;
		$total     = 0;

		foreach ( $product_maps['product_data'] as $product ) {
			++$total;
			$in_pricelist = isset( $pricelist_sku_set[ $product['sku'] ] )
				|| ( $product['ean'] !== '' && isset( $pricelist_ean_set[ $product['ean'] ] ) );

			if ( $in_pricelist ) {
				++$matched;
			}

			$result[] = array(
				'shop_id'       => $product['id'],
				'shop_name'     => $product['name'],
				'shop_sku'      => $product['sku'],
				'shop_ean'      => $product['ean'],
				'in_pricelist'  => $in_pricelist,
			);
		}

		return array(
			'rows'  => $result,
			'stats' => array(
				'total'     => $total,
				'matched'   => $matched,
				'unmatched' => $total - $matched,
			),
		);
	}

	/**
	 * Generate CSV rows for the pricelist-to-shop comparison result.
	 *
	 * @param array{
	 *   headers: string[],
	 *   rows: array<int, array<string, mixed>>,
	 *   stats: array{total: int, matched: int, unmatched: int}
	 * } $result Comparison result.
	 * @return array<int, array<int, string>> Rows suitable for File_Handler::write_csv().
	 */
	public function build_pricelist_to_shop_csv( array $result ): array {
		$csv_rows = array();

		// Header row.
		$csv_rows[] = array(
			__( 'Product Name (Pricelist)', 'wc-sku-ean-comparator' ),
			__( 'SKU (Pricelist)', 'wc-sku-ean-comparator' ),
			__( 'EAN (Pricelist)', 'wc-sku-ean-comparator' ),
			__( 'Found in Shop', 'wc-sku-ean-comparator' ),
			__( 'Shop ID', 'wc-sku-ean-comparator' ),
			__( 'Product Name (Shop)', 'wc-sku-ean-comparator' ),
			__( 'SKU (Shop)', 'wc-sku-ean-comparator' ),
			__( 'EAN (Shop)', 'wc-sku-ean-comparator' ),
		);

		foreach ( $result['rows'] as $row ) {
			$csv_rows[] = array(
				(string) $row['pricelist_name'],
				(string) $row['pricelist_sku'],
				(string) $row['pricelist_ean'],
				$row['found'] ? __( 'Yes', 'wc-sku-ean-comparator' ) : __( 'No', 'wc-sku-ean-comparator' ),
				$row['found'] ? (string) $row['shop_id'] : '',
				(string) $row['shop_name'],
				(string) $row['shop_sku'],
				(string) $row['shop_ean'],
			);
		}

		return $csv_rows;
	}

	/**
	 * Generate CSV rows for the shop-to-pricelist comparison result.
	 *
	 * @param array{
	 *   rows: array<int, array<string, mixed>>,
	 *   stats: array{total: int, matched: int, unmatched: int}
	 * } $result Comparison result.
	 * @return array<int, array<int, string>> Rows suitable for File_Handler::write_csv().
	 */
	public function build_shop_to_pricelist_csv( array $result ): array {
		$csv_rows = array();

		// Header row.
		$csv_rows[] = array(
			__( 'Shop ID', 'wc-sku-ean-comparator' ),
			__( 'Product Name (Shop)', 'wc-sku-ean-comparator' ),
			__( 'SKU (Shop)', 'wc-sku-ean-comparator' ),
			__( 'EAN (Shop)', 'wc-sku-ean-comparator' ),
			__( 'In Pricelist', 'wc-sku-ean-comparator' ),
		);

		foreach ( $result['rows'] as $row ) {
			$csv_rows[] = array(
				(string) $row['shop_id'],
				(string) $row['shop_name'],
				(string) $row['shop_sku'],
				(string) $row['shop_ean'],
				$row['in_pricelist'] ? __( 'Yes', 'wc-sku-ean-comparator' ) : __( 'No', 'wc-sku-ean-comparator' ),
			);
		}

		return $csv_rows;
	}

	/**
	 * Get all published product + variation IDs from WooCommerce.
	 *
	 * @return int[] Array of product IDs.
	 */
	private function get_all_product_ids(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return array_map(
			'intval',
			$wpdb->get_col(
				"SELECT ID FROM {$wpdb->posts}
				WHERE post_type IN ('product', 'product_variation')
				AND post_status = 'publish'"
			)
		);
	}

	/**
	 * Get product IDs belonging to specific WooCommerce brands.
	 *
	 * @param string[] $brand_slugs Array of product_brand taxonomy slugs.
	 * @return int[] Array of product IDs.
	 */
	private function get_product_ids_by_brands( array $brand_slugs ): array {
		$args = array(
			'post_type'      => array( 'product', 'product_variation' ),
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => 'product_brand',
					'field'    => 'slug',
					'terms'    => $brand_slugs,
					'operator' => 'IN',
				),
			),
		);

		$query = new \WP_Query( $args );

		return array_map( 'intval', $query->posts );
	}

	/**
	 * Find a WooCommerce product ID by SKU then EAN.
	 *
	 * @param string  $sku          SKU value from price list.
	 * @param string  $ean          EAN value from price list.
	 * @param array{
	 *   sku_map: array<string, int>,
	 *   ean_map: array<string, int>,
	 *   product_data: array<int, array{id: int, name: string, sku: string, ean: string}>
	 * } $product_maps Pre-loaded product maps.
	 * @return int Product ID, or 0 if not found.
	 */
	private function find_product_id( string $sku, string $ean, array $product_maps ): int {
		if ( $sku !== '' && isset( $product_maps['sku_map'][ $sku ] ) ) {
			return $product_maps['sku_map'][ $sku ];
		}

		if ( $ean !== '' && isset( $product_maps['ean_map'][ $ean ] ) ) {
			return $product_maps['ean_map'][ $ean ];
		}

		return 0;
	}

	/**
	 * Extract a single string value from a row by trying multiple column indices
	 * (first non-empty value wins).
	 *
	 * @param array<int, string> $row     A data row.
	 * @param int[]              $columns Column indices to try.
	 * @return string First non-empty value, or empty string.
	 */
	private function extract_column_value( array $row, array $columns ): string {
		foreach ( $columns as $col_index ) {
			$value = isset( $row[ $col_index ] ) ? trim( $row[ $col_index ] ) : '';
			if ( $value !== '' ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * Extract and combine values from multiple columns into a single string.
	 *
	 * @param array<int, string> $row     A data row.
	 * @param int[]              $columns Column indices to combine.
	 * @return string Combined value (non-empty parts joined with space).
	 */
	private function extract_combined_value( array $row, array $columns ): string {
		$parts = array();

		foreach ( $columns as $col_index ) {
			$value = isset( $row[ $col_index ] ) ? trim( $row[ $col_index ] ) : '';
			if ( $value !== '' ) {
				$parts[] = $value;
			}
		}

		return implode( ' ', $parts );
	}
}
