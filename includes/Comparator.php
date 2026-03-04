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
 *
 * Column mapping now uses a rules-based approach instead of hardcoded
 * SKU / EAN / Name fields. Each rule defines:
 *   - shop_field: 'id' | 'sku' | 'ean' | 'name' | 'custom_field'
 *   - custom_key: meta key string (only when shop_field = 'custom_field')
 *   - label:      human-readable label used as CSV column header
 *   - pricelist_columns: int[] column indices in the price list file
 */
class Comparator {

	/**
	 * Batch size for loading products from the database.
	 *
	 * @var int
	 */
	const BATCH_SIZE = 500;

	/**
	 * Built-in shop fields that are always available without custom meta.
	 *
	 * @var string[]
	 */
	const BUILTIN_FIELDS = array( 'id', 'sku', 'ean', 'name' );

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

	// =========================================================================
	// Product map loading
	// =========================================================================

	/**
	 * Load product data maps needed for the given comparison rules.
	 *
	 * Products are fetched in batches to avoid PHP memory limits.
	 * For each active rule a lookup map is built:
	 *   - 'id'           → $maps['id'][ $product_id ]  = $product_id
	 *   - 'sku'          → $maps['sku'][ $sku ]         = $product_id
	 *   - 'ean'          → $maps['ean'][ $ean ]         = $product_id
	 *   - 'name'         → $maps['name'][ lc($name) ]  = $product_id
	 *   - 'custom_field' → $maps['cf__{key}'][ $val ]  = $product_id
	 *
	 * @param array<int, array{shop_field: string, custom_key: string|null, label: string, pricelist_columns: int[]}> $rules
	 *   Comparison rules.
	 * @param string[] $brand_slugs Optional WooCommerce product_brand taxonomy slugs to filter by.
	 * @return array{
	 *   maps: array<string, array<string, int>>,
	 *   product_data: array<int, array<string, mixed>>
	 * }
	 */
	public function load_product_maps( array $rules, array $brand_slugs = array() ): array {
		global $wpdb;

		$maps         = array();
		$product_data = array();

		// Determine which meta keys we need to load.
		$need_sku    = false;
		$need_ean    = false;
		$need_name   = false;
		$custom_keys = array(); // unique list of custom meta keys.

		foreach ( $rules as $rule ) {
			$field = $rule['shop_field'] ?? '';
			switch ( $field ) {
				case 'id':
					// ID is available from wp_posts directly — no extra meta needed.
					break;
				case 'sku':
					$need_sku = true;
					break;
				case 'ean':
					$need_ean = true;
					break;
				case 'name':
					$need_name = true;
					break;
				case 'custom_field':
					$key = trim( (string) ( $rule['custom_key'] ?? '' ) );
					if ( $key !== '' && ! in_array( $key, $custom_keys, true ) ) {
						$custom_keys[] = $key;
					}
					break;
			}
		}

		// Build the base product ID list.
		if ( ! empty( $brand_slugs ) ) {
			$product_ids = $this->get_product_ids_by_brands( $brand_slugs );
		} else {
			$product_ids = $this->get_all_product_ids();
		}

		if ( empty( $product_ids ) ) {
			return array(
				'maps'         => $maps,
				'product_data' => $product_data,
			);
		}

		// Build list of meta keys to fetch.
		$meta_keys_to_fetch = array();
		if ( $need_sku ) {
			$meta_keys_to_fetch[] = '_sku';
		}
		if ( $need_ean ) {
			$meta_keys_to_fetch[] = '_global_unique_id';
		}
		foreach ( $custom_keys as $ck ) {
			$meta_keys_to_fetch[] = $ck;
		}

		// When name matching is needed, we also need the brand name to support
		// "Brand Name + Product Name" concatenated matching. The brand term is
		// fetched via a LEFT JOIN on term_relationships/term_taxonomy/terms with
		// taxonomy = 'product_brand' (WooCommerce Brands plugin taxonomy).
		$brand_join     = '';
		$brand_select   = '';
		$brand_taxonomy = 'product_brand';
		if ( $need_name ) {
			$brand_join   = "LEFT JOIN {$wpdb->term_relationships} tr_brand ON tr_brand.object_id = p.ID
						LEFT JOIN {$wpdb->term_taxonomy} tt_brand ON tt_brand.term_taxonomy_id = tr_brand.term_taxonomy_id AND tt_brand.taxonomy = '{$brand_taxonomy}'
						LEFT JOIN {$wpdb->terms} t_brand ON t_brand.term_id = tt_brand.term_id";
			$brand_select = ', MAX(t_brand.name) AS brand_name';
		}

		// Process products in batches.
		$total     = count( $product_ids );
		$processed = 0;

		while ( $processed < $total ) {
			$batch_ids = array_slice( $product_ids, $processed, self::BATCH_SIZE );

			if ( empty( $batch_ids ) ) {
				break;
			}

			$placeholders = implode( ',', array_fill( 0, count( $batch_ids ), '%d' ) );

			if ( ! empty( $meta_keys_to_fetch ) ) {
				// Build dynamic CASE expressions for each meta key.
				$case_parts         = array();
				$meta_key_binds     = array();
				$meta_placeholders  = implode( ',', array_fill( 0, count( $meta_keys_to_fetch ), '%s' ) );

				foreach ( $meta_keys_to_fetch as $mk ) {
					$case_parts[]      = "MAX(CASE WHEN pm.meta_key = %s THEN pm.meta_value ELSE NULL END) AS meta__{$this->safe_alias( $mk )}";
					$meta_key_binds[]  = $mk;
				}

				$select_cases = implode( ", \n\t\t\t\t\t", $case_parts );

			// Build the full query parameter array: meta keys for CASE, then meta keys for IN(), then batch IDs.
			// SQL placeholder order: CASE %s × N, IN(meta) %s × N, IN(ids) %d × K.
			$query_params = array_merge( $meta_key_binds, $meta_key_binds, $batch_ids );

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$rows = $wpdb->get_results(
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					$wpdb->prepare(
						"SELECT p.ID, p.post_title, p.post_type, p.post_parent{$brand_select},
						{$select_cases}
						FROM {$wpdb->posts} p
						{$brand_join}
						LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key IN ({$meta_placeholders})
						WHERE p.ID IN ({$placeholders})
						AND p.post_status IN ('publish', 'private', 'draft', 'pending')
						GROUP BY p.ID, p.post_title, p.post_type, p.post_parent",
						...$query_params
					),
					ARRAY_A
				);
			} else {
				// No meta keys needed — just fetch post titles/IDs.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$rows = $wpdb->get_results(
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					$wpdb->prepare(
						"SELECT p.ID, p.post_title, p.post_type, p.post_parent{$brand_select}
						FROM {$wpdb->posts} p
						{$brand_join}
						WHERE p.ID IN ({$placeholders})
						AND p.post_status IN ('publish', 'private', 'draft', 'pending')
						GROUP BY p.ID, p.post_title, p.post_type, p.post_parent",
						...$batch_ids
					),
					ARRAY_A
				);
			}

			if ( $rows ) {
				foreach ( $rows as $row ) {
					$product_id = (int) $row['ID'];
					$name       = (string) $row['post_title'];
					$brand_name = $need_name ? trim( (string) ( $row['brand_name'] ?? '' ) ) : '';

				// Build a flat product_data entry with all available fields.
				$product_entry = array(
					'id'          => $product_id,
					'name'        => $name,
					'brand_name'  => $brand_name,
					'post_type'   => (string) ( $row['post_type'] ?? 'product' ),
					'post_parent' => (int) ( $row['post_parent'] ?? 0 ),
				);

					if ( $need_sku ) {
						$product_entry['sku'] = trim( (string) ( $row[ 'meta___sku' ] ?? '' ) );
					}
					if ( $need_ean ) {
						$product_entry['ean'] = trim( (string) ( $row[ 'meta___global_unique_id' ] ?? '' ) );
					}
					foreach ( $custom_keys as $ck ) {
						$alias                        = 'meta__' . $this->safe_alias( $ck );
						$product_entry[ 'cf__' . $ck ] = trim( (string) ( $row[ $alias ] ?? '' ) );
					}

					$product_data[ $product_id ] = $product_entry;

					// Populate lookup maps.
					// ID map.
					$maps['id'][ (string) $product_id ] = $product_id;

					if ( $need_sku ) {
						$sku = $product_entry['sku'];
						if ( $sku !== '' ) {
							$maps['sku'][ $sku ] = $product_id;
						}
					}
					if ( $need_ean ) {
						$ean = $product_entry['ean'];
						if ( $ean !== '' ) {
							$maps['ean'][ $ean ] = $product_id;
						}
					}
					if ( $need_name && $name !== '' ) {
						$maps['name'][ mb_strtolower( $name ) ] = $product_id;
						// Also index by "Brand Name + Product Name" for pricelist rows
						// that have brand name prepended to the product title.
						if ( $brand_name !== '' ) {
							$maps['name'][ mb_strtolower( $brand_name . ' ' . $name ) ] = $product_id;
						}
					}
					foreach ( $custom_keys as $ck ) {
						$val = $product_entry[ 'cf__' . $ck ];
						if ( $val !== '' ) {
							$maps[ 'cf__' . $ck ][ $val ] = $product_id;
						}
					}
				}
			}

		$processed += count( $batch_ids );
		}

		return array(
			'maps'         => $maps,
			'product_data' => $product_data,
		);
	}

	// =========================================================================
	// Pricelist → Shop comparison
	// =========================================================================

	/**
	 * Compare price list rows against the WooCommerce shop (Pricelist → Shop).
	 *
	 * For each row in the price list, tries to find the matching WooCommerce
	 * product by iterating rules in order (first match wins).
	 *
	 * @param array<int, array<int, string>> $rows           Parsed rows (row 0 = headers).
	 * @param array<int, array{shop_field: string, custom_key: string|null, label: string, pricelist_columns: int[]}> $rules
	 *   Comparison rules.
	 * @param array{
	 *   maps: array<string, array<string, int>>,
	 *   product_data: array<int, array<string, mixed>>
	 * } $product_maps Pre-loaded product maps.
	 * @return array{
	 *   headers: string[],
	 *   rows: array<int, array<string, mixed>>,
	 *   stats: array{total: int, matched: int, unmatched: int}
	 * }
	 */
	public function compare_pricelist_to_shop(
		array $rows,
		array $rules,
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
			// For each rule, extract the pricelist value.
			$rule_values = array();
			foreach ( $rules as $rule ) {
				$rule_values[] = $this->extract_column_value( $row, $rule['pricelist_columns'] );
			}

			// Find matching product (first rule match wins).
			[ $product_id, $matched_rule_index ] = $this->find_product_id_by_rules(
				$rule_values,
				$rules,
				$product_maps['maps']
			);
			$found = $product_id > 0;

			if ( $found ) {
				++$matched;
				$shop_product = $product_maps['product_data'][ $product_id ];
			} else {
				$shop_product = array( 'id' => 0, 'name' => '', 'post_type' => '', 'post_parent' => 0 );
			}

			$is_variation = 'product_variation' === ( $shop_product['post_type'] ?? '' );

			$result_row = array(
				'found'              => $found,
				'shop_id'            => $shop_product['id'],
				'shop_name'          => $shop_product['name'],
				'is_variation'       => $is_variation,
				'parent_id'          => $is_variation ? (int) ( $shop_product['post_parent'] ?? 0 ) : 0,
				'matched_rule_index' => $matched_rule_index,
			);

			// Add per-rule pricelist values.
			foreach ( $rules as $idx => $rule ) {
				$result_row[ 'pricelist_rule_' . $idx ] = $rule_values[ $idx ] ?? '';
			}

			// Add per-rule shop values.
			foreach ( $rules as $idx => $rule ) {
				$result_row[ 'shop_rule_' . $idx ] = $found
					? $this->get_product_field_value( $shop_product, $rule )
					: '';
			}

			$result_row['original_row']     = $row;
			$result_row['original_headers'] = $headers;

			$result[] = $result_row;
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

	// =========================================================================
	// Shop → Pricelist comparison
	// =========================================================================

	/**
	 * Compare shop products against the price list (Shop → Pricelist).
	 *
	 * For each WooCommerce product, checks whether it appears in the price list
	 * using any of the configured rules (first match wins).
	 *
	 * @param array<int, array<int, string>> $rows Parsed rows (row 0 = headers).
	 * @param array<int, array{shop_field: string, custom_key: string|null, label: string, pricelist_columns: int[]}> $rules
	 *   Comparison rules.
	 * @param array{
	 *   maps: array<string, array<string, int>>,
	 *   product_data: array<int, array<string, mixed>>
	 * } $product_maps Pre-loaded product maps.
	 * @return array{
	 *   rows: array<int, array<string, mixed>>,
	 *   stats: array{total: int, matched: int, unmatched: int}
	 * }
	 */
	public function compare_shop_to_pricelist(
		array $rows,
		array $rules,
		array $product_maps
	): array {
		// Build per-rule pricelist value sets for fast lookup.
		$pricelist_sets = array(); // $pricelist_sets[$rule_idx][$value] = true
		foreach ( $rules as $idx => $rule ) {
			$pricelist_sets[ $idx ] = array();
		}

		foreach ( array_slice( $rows, 1 ) as $row ) {
			foreach ( $rules as $idx => $rule ) {
				$val = $this->extract_column_value( $row, $rule['pricelist_columns'] );
				if ( $val !== '' ) {
					// Normalise name values to lowercase for case-insensitive matching,
					// consistent with the Pricelist→Shop direction.
					if ( 'name' === $rule['shop_field'] ) {
						$val = mb_strtolower( $val );
					}
					$pricelist_sets[ $idx ][ $val ] = true;
				}
			}
		}

		$result    = array();
		$matched   = 0;
		$total     = 0;

		foreach ( $product_maps['product_data'] as $product ) {
			++$total;

			// Check if product appears in pricelist via any rule.
			$in_pricelist        = false;
			$matched_rule_index  = -1;

			foreach ( $rules as $idx => $rule ) {
				$shop_val = $this->get_product_field_value( $product, $rule );
				// Normalise name to lowercase for case-insensitive matching,
				// consistent with both the pricelist-set build above and the
				// Pricelist→Shop direction.
				if ( 'name' === $rule['shop_field'] && $shop_val !== '' ) {
					$shop_val = mb_strtolower( $shop_val );
				}
				if ( $shop_val !== '' && isset( $pricelist_sets[ $idx ][ $shop_val ] ) ) {
					$in_pricelist       = true;
					$matched_rule_index = $idx;
					break;
				}
				// Also try "Brand Name + Product Name" for name rules, because
				// pricelist rows may have the brand name prepended to the title.
				if ( 'name' === $rule['shop_field'] && $shop_val !== '' ) {
					$brand_name = mb_strtolower( trim( (string) ( $product['brand_name'] ?? '' ) ) );
					if ( $brand_name !== '' ) {
						$brand_shop_val = $brand_name . ' ' . $shop_val;
						if ( isset( $pricelist_sets[ $idx ][ $brand_shop_val ] ) ) {
							$in_pricelist       = true;
							$matched_rule_index = $idx;
							break;
						}
					}
				}
			}

			if ( $in_pricelist ) {
				++$matched;
			}

			$is_variation = 'product_variation' === ( $product['post_type'] ?? '' );

			$result_row = array(
				'shop_id'            => $product['id'],
				'shop_name'          => $product['name'],
				'is_variation'       => $is_variation,
				'parent_id'          => $is_variation ? (int) ( $product['post_parent'] ?? 0 ) : 0,
				'in_pricelist'       => $in_pricelist,
				'matched_rule_index' => $matched_rule_index,
			);

			// Add per-rule shop values.
			foreach ( $rules as $idx => $rule ) {
				$result_row[ 'shop_rule_' . $idx ] = $this->get_product_field_value( $product, $rule );
			}

			$result[] = $result_row;
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

	// =========================================================================
	// CSV builders
	// =========================================================================

	/**
	 * Generate CSV rows for the pricelist-to-shop comparison result.
	 *
	 * Columns:
	 *   Shop ID | Product Name (Shop) | Variant | Parent ID | {label (Shop)} per rule | {label (Pricelist)} per rule | Matched by | Found in Shop
	 *
	 * @param array{
	 *   headers: string[],
	 *   rows: array<int, array<string, mixed>>,
	 *   stats: array{total: int, matched: int, unmatched: int}
	 * } $result Comparison result.
	 * @param array<int, array{shop_field: string, custom_key: string|null, label: string, pricelist_columns: int[]}> $rules
	 *   Comparison rules (for column headers).
	 * @return array<int, array<int, string>> Rows suitable for File_Handler::write_csv().
	 */
	public function build_pricelist_to_shop_csv( array $result, array $rules ): array {
		$csv_rows = array();

		// Build header row.
		$header = array(
			__( 'Shop ID', 'wc-sku-ean-comparator' ),
			__( 'Product Name (Shop)', 'wc-sku-ean-comparator' ),
			__( 'Variant', 'wc-sku-ean-comparator' ),
			__( 'Parent ID', 'wc-sku-ean-comparator' ),
		);
		foreach ( $rules as $rule ) {
			/* translators: %s: rule label */
			$header[] = sprintf( __( '%s (Shop)', 'wc-sku-ean-comparator' ), $rule['label'] );
		}
		foreach ( $rules as $rule ) {
			/* translators: %s: rule label */
			$header[] = sprintf( __( '%s (Pricelist)', 'wc-sku-ean-comparator' ), $rule['label'] );
		}
		$header[] = __( 'Matched by', 'wc-sku-ean-comparator' );
		$header[] = __( 'Found in Shop', 'wc-sku-ean-comparator' );

		$csv_rows[] = $header;

		foreach ( $result['rows'] as $row ) {
			$is_variation = ! empty( $row['is_variation'] );
			$parent_id    = (int) ( $row['parent_id'] ?? 0 );

			$csv_row = array(
				$row['found'] ? (string) $row['shop_id'] : '',
				(string) $row['shop_name'],
				$is_variation ? __( 'Yes', 'wc-sku-ean-comparator' ) : '',
				( $is_variation && $parent_id > 0 ) ? (string) $parent_id : '',
			);

			foreach ( $rules as $idx => $rule ) {
				$csv_row[] = (string) ( $row[ 'shop_rule_' . $idx ] ?? '' );
			}

			foreach ( $rules as $idx => $rule ) {
				$csv_row[] = (string) ( $row[ 'pricelist_rule_' . $idx ] ?? '' );
			}

			// Matched by: rule label or empty.
			$matched_idx = $row['matched_rule_index'] ?? -1;
			$csv_row[]   = ( $row['found'] && isset( $rules[ $matched_idx ] ) )
				? $rules[ $matched_idx ]['label']
				: '';

			$csv_row[] = $row['found']
				? __( 'Yes', 'wc-sku-ean-comparator' )
				: __( 'No', 'wc-sku-ean-comparator' );

			$csv_rows[] = $csv_row;
		}

		return $csv_rows;
	}

	/**
	 * Generate CSV rows for the shop-to-pricelist comparison result.
	 *
	 * Columns:
	 *   Shop ID | Product Name (Shop) | Variant | Parent ID | {label (Shop)} per rule | Matched by | In Pricelist
	 *
	 * @param array{
	 *   rows: array<int, array<string, mixed>>,
	 *   stats: array{total: int, matched: int, unmatched: int}
	 * } $result Comparison result.
	 * @param array<int, array{shop_field: string, custom_key: string|null, label: string, pricelist_columns: int[]}> $rules
	 *   Comparison rules (for column headers).
	 * @return array<int, array<int, string>> Rows suitable for File_Handler::write_csv().
	 */
	public function build_shop_to_pricelist_csv( array $result, array $rules ): array {
		$csv_rows = array();

		// Build header row.
		$header = array(
			__( 'Shop ID', 'wc-sku-ean-comparator' ),
			__( 'Product Name (Shop)', 'wc-sku-ean-comparator' ),
			__( 'Variant', 'wc-sku-ean-comparator' ),
			__( 'Parent ID', 'wc-sku-ean-comparator' ),
		);
		foreach ( $rules as $rule ) {
			/* translators: %s: rule label */
			$header[] = sprintf( __( '%s (Shop)', 'wc-sku-ean-comparator' ), $rule['label'] );
		}
		$header[] = __( 'Matched by', 'wc-sku-ean-comparator' );
		$header[] = __( 'In Pricelist', 'wc-sku-ean-comparator' );

		$csv_rows[] = $header;

		foreach ( $result['rows'] as $row ) {
			$is_variation = ! empty( $row['is_variation'] );
			$parent_id    = (int) ( $row['parent_id'] ?? 0 );

			$csv_row = array(
				(string) $row['shop_id'],
				(string) $row['shop_name'],
				$is_variation ? __( 'Yes', 'wc-sku-ean-comparator' ) : '',
				( $is_variation && $parent_id > 0 ) ? (string) $parent_id : '',
			);

			foreach ( $rules as $idx => $rule ) {
				$csv_row[] = (string) ( $row[ 'shop_rule_' . $idx ] ?? '' );
			}

			// Matched by: rule label or empty.
			$matched_idx = $row['matched_rule_index'] ?? -1;
			$csv_row[]   = ( $row['in_pricelist'] && isset( $rules[ $matched_idx ] ) )
				? $rules[ $matched_idx ]['label']
				: '';

			$csv_row[] = $row['in_pricelist']
				? __( 'Yes', 'wc-sku-ean-comparator' )
				: __( 'No', 'wc-sku-ean-comparator' );

			$csv_rows[] = $csv_row;
		}

		return $csv_rows;
	}

	// =========================================================================
	// Private helpers
	// =========================================================================

	/**
	 * Find a WooCommerce product ID by iterating rules in order.
	 *
	 * Returns the product ID and the index of the matching rule, or [0, -1].
	 *
	 * @param string[] $rule_values           Extracted pricelist values indexed by rule index.
	 * @param array<int, array<string, mixed>> $rules Comparison rules.
	 * @param array<string, array<string, int>> $maps  Lookup maps keyed by field identifier.
	 * @return array{0: int, 1: int} [product_id, rule_index]
	 */
	private function find_product_id_by_rules( array $rule_values, array $rules, array $maps ): array {
		foreach ( $rules as $idx => $rule ) {
			$value    = $rule_values[ $idx ] ?? '';
			$map_key  = $this->get_map_key_for_rule( $rule );

			if ( $value === '' || ! isset( $maps[ $map_key ] ) ) {
				continue;
			}

			if ( 'name' === $rule['shop_field'] ) {
				// Case-insensitive name match.
				$key = mb_strtolower( $value );
				if ( isset( $maps[ $map_key ][ $key ] ) ) {
					return array( $maps[ $map_key ][ $key ], $idx );
				}
			} else {
				if ( isset( $maps[ $map_key ][ $value ] ) ) {
					return array( $maps[ $map_key ][ $value ], $idx );
				}
			}
		}

		return array( 0, -1 );
	}

	/**
	 * Return the map array key for a given rule.
	 *
	 * @param array{shop_field: string, custom_key: string|null} $rule
	 * @return string
	 */
	private function get_map_key_for_rule( array $rule ): string {
		if ( 'custom_field' === $rule['shop_field'] ) {
			return 'cf__' . ( $rule['custom_key'] ?? '' );
		}

		return $rule['shop_field'];
	}

	/**
	 * Get a product's value for a specific rule (shop side).
	 *
	 * @param array<string, mixed>                               $product Product data entry.
	 * @param array{shop_field: string, custom_key: string|null} $rule    Comparison rule.
	 * @return string
	 */
	private function get_product_field_value( array $product, array $rule ): string {
		$field = $rule['shop_field'];

		switch ( $field ) {
			case 'id':
				return (string) ( $product['id'] ?? '' );
			case 'sku':
				return (string) ( $product['sku'] ?? '' );
			case 'ean':
				return (string) ( $product['ean'] ?? '' );
			case 'name':
				return (string) ( $product['name'] ?? '' );
			case 'custom_field':
				$key = 'cf__' . ( $rule['custom_key'] ?? '' );
				return (string) ( $product[ $key ] ?? '' );
			default:
				return '';
		}
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
	 * Convert a meta key to a safe SQL alias (only word chars and underscores).
	 *
	 * @param string $key Meta key.
	 * @return string Safe alias string.
	 */
	private function safe_alias( string $key ): string {
		return preg_replace( '/[^a-zA-Z0-9_]/', '_', $key );
	}

	// =========================================================================
	// Product ID queries
	// =========================================================================

	/**
	 * Get all published product IDs from WooCommerce.
	 *
	 * Returns IDs for:
	 *   - Simple products (post_type = 'product' with no variation children)
	 *   - Product variations (post_type = 'product_variation')
	 *
	 * Variable product parents are excluded because they carry no SKU/EAN of
	 * their own — all matchable data lives on the individual variations.
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
				WHERE post_type = 'product_variation'
				AND post_status IN ('publish', 'private', 'draft', 'pending')
				UNION ALL
				SELECT ID FROM {$wpdb->posts}
				WHERE post_type = 'product'
				AND post_status IN ('publish', 'private', 'draft', 'pending')
				AND ID NOT IN (
					SELECT DISTINCT post_parent FROM {$wpdb->posts}
					WHERE post_type = 'product_variation'
					AND post_status IN ('publish', 'private', 'draft', 'pending')
					AND post_parent > 0
				)"
			)
		);
	}

	/**
	 * Get product IDs belonging to specific WooCommerce brands.
	 *
	 * Brands (product_brand taxonomy) are typically assigned only to the parent
	 * variable product. This method:
	 *   1. Finds all parent products matching the given brand slugs.
	 *   2. Adds all published variations of those parents.
	 *   3. Also includes simple products directly tagged with the brand.
	 *   4. Excludes variable product parents (they carry no matchable SKU/EAN).
	 *
	 * @param string[] $brand_slugs Array of product_brand taxonomy slugs.
	 * @return int[] Array of product IDs.
	 */
	private function get_product_ids_by_brands( array $brand_slugs ): array {
		global $wpdb;

		// Build a safe IN() placeholder for brand slugs.
		$slug_placeholders = implode( ',', array_fill( 0, count( $brand_slugs ), '%s' ) );

		// Step 1: Find all published products (parents + simples) with the brand.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$parent_ids = array_map(
			'intval',
			$wpdb->get_col(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$wpdb->prepare(
					"SELECT DISTINCT p.ID
					FROM {$wpdb->posts} p
					INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
					INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
					INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
					WHERE tt.taxonomy = 'product_brand'
					AND t.slug IN ({$slug_placeholders})
					AND p.post_type = 'product'
					AND p.post_status IN ('publish', 'private', 'draft', 'pending')",
					...$brand_slugs
				)
			)
		);

		if ( empty( $parent_ids ) ) {
			return array();
		}

		$parent_placeholders = implode( ',', array_fill( 0, count( $parent_ids ), '%d' ) );

		// Step 2: Find all published variations whose parent has the brand.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$variation_ids = array_map(
			'intval',
			$wpdb->get_col(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts}
					WHERE post_type = 'product_variation'
					AND post_status IN ('publish', 'private', 'draft', 'pending')
					AND post_parent IN ({$parent_placeholders})",
					...$parent_ids
				)
			)
		);

		// Step 3: From parent_ids, keep only those that are NOT variable (i.e. simple).
		// Variable parents are those that have at least one variation child.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$variable_parent_ids = array_map(
			'intval',
			$wpdb->get_col(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$wpdb->prepare(
					"SELECT DISTINCT post_parent FROM {$wpdb->posts}
					WHERE post_type = 'product_variation'
					AND post_status IN ('publish', 'private', 'draft', 'pending')
					AND post_parent IN ({$parent_placeholders})",
					...$parent_ids
				)
			)
		);

		$simple_ids = array_values( array_diff( $parent_ids, $variable_parent_ids ) );

		return array_values( array_unique( array_merge( $simple_ids, $variation_ids ) ) );
	}
}
