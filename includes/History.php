<?php
/**
 * Comparison history -- custom DB table CRUD.
 *
 * @package WC_SKU_EAN_Comparator
 */

namespace WC_SKU_EAN_Comparator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class History
 *
 * Manages the custom database table for storing comparison history.
 *
 * Table: {prefix}wc_sec_history
 */
class History {

	/**
	 * Database table version.
	 *
	 * Bump this when the column_mapping JSON schema changes so that
	 * maybe_upgrade_db() can drop + recreate the table with fresh data.
	 *
	 * @var string
	 */
	const DB_VERSION = '1.1';

	/**
	 * Get the full table name including prefix.
	 *
	 * @return string
	 */
	public static function get_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'wc_sec_history';
	}

	/**
	 * Create the custom table using dbDelta().
	 * Called on plugin activation.
	 *
	 * @return void
	 */
	public static function create_table(): void {
		global $wpdb;

		$table_name      = self::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			file_name varchar(255) NOT NULL DEFAULT '',
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			brand_slugs text NOT NULL,
			column_mapping longtext NOT NULL,
			stats longtext NOT NULL,
			csv_pricelist_to_shop varchar(255) DEFAULT NULL,
			csv_shop_to_pricelist varchar(255) DEFAULT NULL,
			results_summary longtext DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( 'wc_sec_db_version', self::DB_VERSION );
	}

	/**
	 * Drop the custom table.
	 * Called on plugin uninstall.
	 *
	 * @return void
	 */
	public static function drop_table(): void {
		global $wpdb;
		$table_name = self::get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Check installed DB version and run upgrade if needed.
	 *
	 * When the schema version bumps (e.g. column_mapping format changes), this
	 * drops the old table and recreates it so there are no stale records with
	 * an incompatible JSON structure.
	 *
	 * Hook: plugins_loaded
	 *
	 * @return void
	 */
	public static function maybe_upgrade_db(): void {
		$installed = get_option( 'wc_sec_db_version', '0' );

		if ( version_compare( $installed, self::DB_VERSION, '<' ) ) {
			// Drop the old table — we are in dev mode, backward compat not required.
			self::drop_table();
			self::create_table();
		}
	}

	/**
	 * Save a new comparison record.
	 *
	 * @param array{
	 *   file_name: string,
	 *   brand_slugs: string[],
	 *   column_mapping: array<string, mixed>,
	 *   stats: array<string, int>,
	 *   csv_pricelist_to_shop?: string,
	 *   csv_shop_to_pricelist?: string,
	 *   results_summary?: string
	 * } $data Comparison data.
	 * @return int|\WP_Error The new record ID, or WP_Error on failure.
	 */
	public function save( array $data ): int|\WP_Error {
		global $wpdb;

		$insert = array(
			'file_name'             => sanitize_text_field( $data['file_name'] ),
			'created_at'            => current_time( 'mysql' ),
			'brand_slugs'           => wp_json_encode( $data['brand_slugs'] ),
			'column_mapping'        => wp_json_encode( $data['column_mapping'] ),
			'stats'                 => wp_json_encode( $data['stats'] ),
			'csv_pricelist_to_shop' => isset( $data['csv_pricelist_to_shop'] ) ? sanitize_text_field( $data['csv_pricelist_to_shop'] ) : null,
			'csv_shop_to_pricelist' => isset( $data['csv_shop_to_pricelist'] ) ? sanitize_text_field( $data['csv_shop_to_pricelist'] ) : null,
			'results_summary'       => isset( $data['results_summary'] ) ? $data['results_summary'] : null,
		);

		$formats = array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert( self::get_table_name(), $insert, $formats );

		if ( false === $result ) {
			return new \WP_Error(
				'wc_sec_db_error',
				__( 'Failed to save comparison record to database.', 'wc-sku-ean-comparator' ),
				array( 'db_error' => $wpdb->last_error )
			);
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Get a single comparison record by ID.
	 *
	 * @param int $id Record ID.
	 * @return array<string, mixed>|null Record data or null if not found.
	 */
	public function get( int $id ): ?array {
		global $wpdb;

		$table_name = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table_name} WHERE id = %d", $id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		return $this->unserialize_record( $row );
	}

	/**
	 * Get a paginated list of comparison records.
	 *
	 * @param int    $page     Current page (1-based).
	 * @param int    $per_page Items per page.
	 * @param string $orderby  Column to sort by ('id', 'file_name', 'created_at'). Default 'created_at'.
	 * @param string $order    Sort direction ('ASC' or 'DESC'). Default 'DESC'.
	 * @return array<int, array<string, mixed>> List of records.
	 */
	public function get_list( int $page = 1, int $per_page = 20, string $orderby = 'created_at', string $order = 'DESC' ): array {
		global $wpdb;

		$table_name = self::get_table_name();
		$offset     = ( max( 1, $page ) - 1 ) * $per_page;

		// Whitelist sortable columns to prevent SQL injection.
		$allowed_orderby = array( 'id', 'file_name', 'created_at' );
		$orderby_safe    = in_array( $orderby, $allowed_orderby, true ) ? $orderby : 'created_at';
		$order_safe      = 'ASC' === strtoupper( $order ) ? 'ASC' : 'DESC';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT id, file_name, created_at, brand_slugs, stats, csv_pricelist_to_shop, csv_shop_to_pricelist FROM {$table_name} ORDER BY {$orderby_safe} {$order_safe} LIMIT %d OFFSET %d",
				$per_page,
				$offset
			),
			ARRAY_A
		);

		if ( ! $rows ) {
			return array();
		}

		return array_map( array( $this, 'unserialize_record' ), $rows );
	}

	/**
	 * Get total count of comparison records.
	 *
	 * @return int Total count.
	 */
	public function get_total_count(): int {
		global $wpdb;

		$table_name = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( "SELECT COUNT(id) FROM {$table_name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Update an existing comparison record.
	 *
	 * @param int                  $id   Record ID.
	 * @param array<string, mixed> $data Fields to update.
	 * @return bool|\WP_Error True on success, WP_Error on failure.
	 */
	public function update( int $id, array $data ): bool|\WP_Error {
		global $wpdb;

		$update  = array();
		$formats = array();

		if ( isset( $data['file_name'] ) ) {
			$update['file_name'] = sanitize_text_field( $data['file_name'] );
			$formats[]           = '%s';
		}
		if ( isset( $data['brand_slugs'] ) ) {
			$update['brand_slugs'] = wp_json_encode( $data['brand_slugs'] );
			$formats[]             = '%s';
		}
		if ( isset( $data['column_mapping'] ) ) {
			$update['column_mapping'] = wp_json_encode( $data['column_mapping'] );
			$formats[]                = '%s';
		}
		if ( isset( $data['stats'] ) ) {
			$update['stats'] = wp_json_encode( $data['stats'] );
			$formats[]       = '%s';
		}
		// Use array_key_exists (not isset) so that explicit null values can clear
		// a stored CSV filename (e.g. when a re-run fails to write the file).
		if ( array_key_exists( 'csv_pricelist_to_shop', $data ) ) {
			$update['csv_pricelist_to_shop'] = is_null( $data['csv_pricelist_to_shop'] )
				? null
				: sanitize_text_field( $data['csv_pricelist_to_shop'] );
			$formats[]                       = '%s';
		}
		if ( array_key_exists( 'csv_shop_to_pricelist', $data ) ) {
			$update['csv_shop_to_pricelist'] = is_null( $data['csv_shop_to_pricelist'] )
				? null
				: sanitize_text_field( $data['csv_shop_to_pricelist'] );
			$formats[]                       = '%s';
		}
		if ( isset( $data['results_summary'] ) ) {
			$update['results_summary'] = $data['results_summary'];
			$formats[]                 = '%s';
		}

		if ( empty( $update ) ) {
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			self::get_table_name(),
			$update,
			array( 'id' => $id ),
			$formats,
			array( '%d' )
		);

		if ( false === $result ) {
			return new \WP_Error(
				'wc_sec_db_error',
				__( 'Failed to update comparison record.', 'wc-sku-ean-comparator' ),
				array( 'db_error' => $wpdb->last_error )
			);
		}

		return true;
	}

	/**
	 * Delete a comparison record by ID.
	 *
	 * @param int $id Record ID.
	 * @return bool True on success, false on failure.
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->delete(
			self::get_table_name(),
			array( 'id' => $id ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Unserialize JSON fields in a DB record.
	 *
	 * @param array<string, mixed> $row Raw DB row.
	 * @return array<string, mixed> Row with decoded JSON fields.
	 */
	private function unserialize_record( array $row ): array {
		foreach ( array( 'brand_slugs', 'column_mapping', 'stats', 'results_summary' ) as $field ) {
			if ( isset( $row[ $field ] ) && is_string( $row[ $field ] ) && '' !== $row[ $field ] ) {
				$decoded = json_decode( $row[ $field ], true );
				if ( null !== $decoded ) {
					$row[ $field ] = $decoded;
				}
			}
		}

		$row['id'] = (int) $row['id'];

		return $row;
	}
}
