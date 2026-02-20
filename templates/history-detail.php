<?php
/**
 * Template: Comparison history detail.
 *
 * Available variables (set by Admin_Page::render_history()):
 *   $comparison  array<string, mixed>  The comparison record.
 *
 * @package WC_SKU_EAN_Comparator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$stats         = is_array( $comparison['stats'] ) ? $comparison['stats'] : array();
$brand_slugs   = is_array( $comparison['brand_slugs'] ) ? $comparison['brand_slugs'] : array();
$column_mapping = is_array( $comparison['column_mapping'] ) ? $comparison['column_mapping'] : array();
$sheet_name    = isset( $column_mapping['sheet_name'] ) ? (string) $column_mapping['sheet_name'] : '';

$csv1_url = ! empty( $comparison['csv_pricelist_to_shop'] )
	? WC_SKU_EAN_Comparator\File_Handler::get_exports_url() . $comparison['csv_pricelist_to_shop']
	: '';
$csv2_url = ! empty( $comparison['csv_shop_to_pricelist'] )
	? WC_SKU_EAN_Comparator\File_Handler::get_exports_url() . $comparison['csv_shop_to_pricelist']
	: '';

$pl_total     = isset( $stats['pricelist_total'] ) ? (int) $stats['pricelist_total'] : 0;
$pl_matched   = isset( $stats['pricelist_matched'] ) ? (int) $stats['pricelist_matched'] : 0;
$pl_unmatched = isset( $stats['pricelist_unmatched'] ) ? (int) $stats['pricelist_unmatched'] : 0;
$shop_total     = isset( $stats['shop_total'] ) ? (int) $stats['shop_total'] : 0;
$shop_matched   = isset( $stats['shop_matched'] ) ? (int) $stats['shop_matched'] : 0;
$shop_unmatched = isset( $stats['shop_unmatched'] ) ? (int) $stats['shop_unmatched'] : 0;
?>
<div id="wc-sec-detail-notice" class="wc-sec-notice hidden"></div>

	<!-- Meta info -->
	<div class="wc-sec-detail-meta">
		<table class="form-table wc-sec-meta-table">
			<tr>
				<th><?php esc_html_e( 'File', 'wc-sku-ean-comparator' ); ?></th>
				<td><?php echo esc_html( $comparison['file_name'] ); ?></td>
			</tr>
			<?php if ( ! empty( $sheet_name ) ) : ?>
			<tr>
				<th><?php esc_html_e( 'Sheet', 'wc-sku-ean-comparator' ); ?></th>
				<td><?php echo esc_html( $sheet_name ); ?></td>
			</tr>
			<?php endif; ?>
			<tr>
				<th><?php esc_html_e( 'Date', 'wc-sku-ean-comparator' ); ?></th>
				<td>
					<?php
					echo esc_html(
						wp_date(
							get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
							strtotime( $comparison['created_at'] )
						)
					);
					?>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Brands', 'wc-sku-ean-comparator' ); ?></th>
				<td>
					<?php if ( empty( $brand_slugs ) ) : ?>
						<em><?php esc_html_e( 'All brands', 'wc-sku-ean-comparator' ); ?></em>
					<?php else : ?>
						<?php echo esc_html( implode( ', ', $brand_slugs ) ); ?>
					<?php endif; ?>
				</td>
			</tr>
		<?php if ( ! empty( $column_mapping ) ) : ?>
		<tr>
			<th><?php esc_html_e( 'Mapping Rules', 'wc-sku-ean-comparator' ); ?></th>
			<td>
				<?php
				$rules = isset( $column_mapping['rules'] ) && is_array( $column_mapping['rules'] )
					? $column_mapping['rules']
					: array();
				if ( ! empty( $rules ) ) :
				?>
				<table class="wc-sec-rules-summary-table">
					<thead>
						<tr>
							<th>#</th>
							<th><?php esc_html_e( 'Label', 'wc-sku-ean-comparator' ); ?></th>
							<th><?php esc_html_e( 'Shop Field', 'wc-sku-ean-comparator' ); ?></th>
							<th><?php esc_html_e( 'Pricelist Columns', 'wc-sku-ean-comparator' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $rules as $i => $rule ) :
						$field      = isset( $rule['shop_field'] ) ? esc_html( $rule['shop_field'] ) : '';
						$label      = isset( $rule['label'] ) ? esc_html( $rule['label'] ) : $field;
						$custom_key = ( 'custom_field' === $rule['shop_field'] && ! empty( $rule['custom_key'] ) )
							? ' <code>' . esc_html( $rule['custom_key'] ) . '</code>'
							: '';
						$col_names  = ! empty( $rule['pricelist_column_names'] )
							? array_map( 'esc_html', (array) $rule['pricelist_column_names'] )
							: array_map( 'intval', (array) ( $rule['pricelist_columns'] ?? array() ) );
					?>
					<tr>
						<td><?php echo esc_html( (string) ( $i + 1 ) ); ?></td>
						<td><?php echo esc_html( $label ); ?></td>
						<td><?php echo esc_html( $field ); ?><?php echo wp_kses( $custom_key, array( 'code' => array() ) ); ?></td>
						<td><?php echo esc_html( implode( ', ', array_map( 'strval', $col_names ) ) ); ?></td>
					</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php else : ?>
					<em><?php esc_html_e( 'No rules stored.', 'wc-sku-ean-comparator' ); ?></em>
				<?php endif; ?>
			</td>
		</tr>
		<?php endif; ?>
		</table>
	</div>

	<!-- Stats cards -->
	<div class="wc-sec-stats">
		<div class="wc-sec-stat-card">
			<h3><?php esc_html_e( 'Pricelist → Shop', 'wc-sku-ean-comparator' ); ?></h3>
			<div class="wc-sec-stat-numbers">
				<div class="wc-sec-stat-item">
					<span class="wc-sec-stat-value"><?php echo esc_html( number_format_i18n( $pl_total ) ); ?></span>
					<span class="wc-sec-stat-label"><?php esc_html_e( 'Total rows', 'wc-sku-ean-comparator' ); ?></span>
				</div>
				<div class="wc-sec-stat-item wc-sec-stat-item--success">
					<span class="wc-sec-stat-value"><?php echo esc_html( number_format_i18n( $pl_matched ) ); ?></span>
					<span class="wc-sec-stat-label"><?php esc_html_e( 'Found in shop', 'wc-sku-ean-comparator' ); ?></span>
				</div>
				<div class="wc-sec-stat-item wc-sec-stat-item--warning">
					<span class="wc-sec-stat-value"><?php echo esc_html( number_format_i18n( $pl_unmatched ) ); ?></span>
					<span class="wc-sec-stat-label"><?php esc_html_e( 'Not found', 'wc-sku-ean-comparator' ); ?></span>
				</div>
			</div>
			<?php if ( $csv1_url ) : ?>
				<a href="<?php echo esc_url( $csv1_url ); ?>" class="button wc-sec-csv-dl-btn" download>
					&#8595; <?php esc_html_e( 'Download CSV', 'wc-sku-ean-comparator' ); ?>
				</a>
			<?php endif; ?>
		</div>

		<div class="wc-sec-stat-card">
			<h3><?php esc_html_e( 'Shop → Pricelist', 'wc-sku-ean-comparator' ); ?></h3>
			<div class="wc-sec-stat-numbers">
				<div class="wc-sec-stat-item">
					<span class="wc-sec-stat-value"><?php echo esc_html( number_format_i18n( $shop_total ) ); ?></span>
					<span class="wc-sec-stat-label"><?php esc_html_e( 'Shop products', 'wc-sku-ean-comparator' ); ?></span>
				</div>
				<div class="wc-sec-stat-item wc-sec-stat-item--success">
					<span class="wc-sec-stat-value"><?php echo esc_html( number_format_i18n( $shop_matched ) ); ?></span>
					<span class="wc-sec-stat-label"><?php esc_html_e( 'In pricelist', 'wc-sku-ean-comparator' ); ?></span>
				</div>
				<div class="wc-sec-stat-item wc-sec-stat-item--warning">
					<span class="wc-sec-stat-value"><?php echo esc_html( number_format_i18n( $shop_unmatched ) ); ?></span>
					<span class="wc-sec-stat-label"><?php esc_html_e( 'Not in pricelist', 'wc-sku-ean-comparator' ); ?></span>
				</div>
			</div>
			<?php if ( $csv2_url ) : ?>
				<a href="<?php echo esc_url( $csv2_url ); ?>" class="button wc-sec-csv-dl-btn" download>
					&#8595; <?php esc_html_e( 'Download CSV', 'wc-sku-ean-comparator' ); ?>
				</a>
			<?php endif; ?>
		</div>
	</div>

	<!-- Results tabs -->
	<?php if ( is_array( $comparison['results_summary'] ) ) : ?>

		<div class="wc-sec-result-tabs">
			<button type="button" class="button wc-sec-result-tab active" data-tab="pricelist">
				<?php esc_html_e( 'Pricelist → Shop', 'wc-sku-ean-comparator' ); ?>
			</button>
			<button type="button" class="button wc-sec-result-tab" data-tab="shop">
				<?php esc_html_e( 'Shop → Pricelist', 'wc-sku-ean-comparator' ); ?>
			</button>
		</div>

		<!-- Filter bar -->
		<div class="wc-sec-filter-bar">
			<input type="search" id="wc-sec-detail-search" placeholder="<?php esc_attr_e( 'Search...', 'wc-sku-ean-comparator' ); ?>" />
			<select id="wc-sec-detail-status">
				<option value=""><?php esc_html_e( 'All', 'wc-sku-ean-comparator' ); ?></option>
				<option value="found"><?php esc_html_e( 'Found', 'wc-sku-ean-comparator' ); ?></option>
				<option value="not_found"><?php esc_html_e( 'Not found', 'wc-sku-ean-comparator' ); ?></option>
			</select>
		</div>

		<!-- Pricelist → Shop table -->
		<div class="wc-sec-result-content active" id="wc-sec-result-pricelist">
			<div class="wc-sec-table-scroll">
				<table class="widefat striped wc-sec-table" id="wc-sec-detail-table-pricelist">
					<thead>
						<tr id="wc-sec-detail-thead-pricelist"></tr>
					</thead>
					<tbody id="wc-sec-detail-tbody-pricelist">
						<tr><td colspan="6"><?php esc_html_e( 'Loading...', 'wc-sku-ean-comparator' ); ?></td></tr>
					</tbody>
				</table>
			</div>
			<div class="wc-sec-pagination" id="wc-sec-detail-pagination-pricelist"></div>
		</div>

		<!-- Shop → Pricelist table -->
		<div class="wc-sec-result-content hidden" id="wc-sec-result-shop">
			<div class="wc-sec-table-scroll">
				<table class="widefat striped wc-sec-table" id="wc-sec-detail-table-shop">
					<thead>
						<tr id="wc-sec-detail-thead-shop"></tr>
					</thead>
					<tbody id="wc-sec-detail-tbody-shop">
						<tr><td colspan="5"><?php esc_html_e( 'Loading...', 'wc-sku-ean-comparator' ); ?></td></tr>
					</tbody>
				</table>
			</div>
			<div class="wc-sec-pagination" id="wc-sec-detail-pagination-shop"></div>
		</div>

		<!-- Hidden data for JS -->
		<input type="hidden" id="wc-sec-detail-comparison-id" value="<?php echo esc_attr( $comparison['id'] ); ?>">
		<input type="hidden" id="wc-sec-detail-nonce" value="<?php echo esc_attr( wp_create_nonce( 'wc_sec_ajax' ) ); ?>">
		<input type="hidden" id="wc-sec-detail-rules" value="<?php echo esc_attr( wp_json_encode( $column_mapping['rules'] ?? array() ) ); ?>">

	<?php else : ?>
		<p class="description">
			<?php esc_html_e( 'Detailed results are not available for this comparison.', 'wc-sku-ean-comparator' ); ?>
		</p>
	<?php endif; ?>

	<!-- Footer actions -->
	<div class="wc-sec-detail-footer">
		<button type="button"
			class="button button-primary wc-sec-rerun-comparison-btn"
			data-id="<?php echo esc_attr( $comparison['id'] ); ?>"
			data-nonce="<?php echo esc_attr( wp_create_nonce( 'wc_sec_ajax' ) ); ?>">
			<?php esc_html_e( 'Re-run Comparison', 'wc-sku-ean-comparator' ); ?>
		</button>
		<button type="button"
			class="button wc-sec-delete-comparison-btn"
			data-id="<?php echo esc_attr( $comparison['id'] ); ?>"
			data-nonce="<?php echo esc_attr( wp_create_nonce( 'wc_sec_ajax' ) ); ?>"
			data-redirect="<?php echo esc_attr( WC_SKU_EAN_Comparator\Admin_Page::get_history_url() ); ?>">
			<?php esc_html_e( 'Delete This Comparison', 'wc-sku-ean-comparator' ); ?>
		</button>
	</div>

