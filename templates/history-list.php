<?php
/**
 * Template: Comparison history list.
 *
 * Available variables (set by Admin_Page::render_history()):
 *   $comparisons  array<int, array<string, mixed>>  Paginated list of comparisons.
 *   $total        int                               Total number of records.
 *   $paged        int                               Current page number.
 *   $per_page     int                               Items per page.
 *
 * @package WC_SKU_EAN_Comparator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$total_pages = $total > 0 ? (int) ceil( $total / $per_page ) : 1;
?>
<div class="wrap wc-sec-wrap">
	<h1 class="wp-heading-inline">
		<?php esc_html_e( 'Comparison History', 'wc-sku-ean-comparator' ); ?>
	</h1>
	<a href="<?php echo esc_url( WC_SKU_EAN_Comparator\Admin_Page::get_new_comparison_url() ); ?>" class="page-title-action">
		<?php esc_html_e( 'New Comparison', 'wc-sku-ean-comparator' ); ?>
	</a>
	<hr class="wp-header-end">

	<div id="wc-sec-history-notice" class="wc-sec-notice hidden"></div>

	<?php if ( empty( $comparisons ) ) : ?>
		<div class="wc-sec-empty-state">
			<p><?php esc_html_e( 'No comparisons found. Run your first comparison to see results here.', 'wc-sku-ean-comparator' ); ?></p>
			<a href="<?php echo esc_url( WC_SKU_EAN_Comparator\Admin_Page::get_new_comparison_url() ); ?>" class="button button-primary">
				<?php esc_html_e( 'Start New Comparison', 'wc-sku-ean-comparator' ); ?>
			</a>
		</div>
	<?php else : ?>

		<table class="wp-list-table widefat fixed striped wc-sec-history-table">
			<thead>
				<tr>
					<th scope="col" class="column-id"><?php esc_html_e( 'ID', 'wc-sku-ean-comparator' ); ?></th>
					<th scope="col" class="column-filename"><?php esc_html_e( 'Price List File', 'wc-sku-ean-comparator' ); ?></th>
					<th scope="col" class="column-date"><?php esc_html_e( 'Date', 'wc-sku-ean-comparator' ); ?></th>
					<th scope="col" class="column-brands"><?php esc_html_e( 'Brands', 'wc-sku-ean-comparator' ); ?></th>
					<th scope="col" class="column-stats"><?php esc_html_e( 'Pricelist Rows', 'wc-sku-ean-comparator' ); ?></th>
					<th scope="col" class="column-stats"><?php esc_html_e( 'Shop Products', 'wc-sku-ean-comparator' ); ?></th>
					<th scope="col" class="column-csv"><?php esc_html_e( 'CSV Downloads', 'wc-sku-ean-comparator' ); ?></th>
					<th scope="col" class="column-actions"><?php esc_html_e( 'Actions', 'wc-sku-ean-comparator' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $comparisons as $comparison ) : ?>
					<?php
					$stats         = is_array( $comparison['stats'] ) ? $comparison['stats'] : array();
					$brand_slugs   = is_array( $comparison['brand_slugs'] ) ? $comparison['brand_slugs'] : array();
					$detail_url    = WC_SKU_EAN_Comparator\Admin_Page::get_history_detail_url( $comparison['id'] );
					$csv1_url      = ! empty( $comparison['csv_pricelist_to_shop'] )
						? WC_SKU_EAN_Comparator\File_Handler::get_exports_url() . $comparison['csv_pricelist_to_shop']
						: '';
					$csv2_url      = ! empty( $comparison['csv_shop_to_pricelist'] )
						? WC_SKU_EAN_Comparator\File_Handler::get_exports_url() . $comparison['csv_shop_to_pricelist']
						: '';
					?>
					<tr>
						<td class="column-id">
							<strong>
								<a href="<?php echo esc_url( $detail_url ); ?>">#<?php echo esc_html( $comparison['id'] ); ?></a>
							</strong>
						</td>
						<td class="column-filename">
							<?php echo esc_html( $comparison['file_name'] ); ?>
						</td>
						<td class="column-date">
							<?php
							echo esc_html(
								wp_date(
									get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
									strtotime( $comparison['created_at'] )
								)
							);
							?>
						</td>
						<td class="column-brands">
							<?php if ( empty( $brand_slugs ) ) : ?>
								<em><?php esc_html_e( 'All brands', 'wc-sku-ean-comparator' ); ?></em>
							<?php else : ?>
								<span title="<?php echo esc_attr( implode( ', ', $brand_slugs ) ); ?>">
									<?php
									$display_brands = array_slice( $brand_slugs, 0, 3 );
									echo esc_html( implode( ', ', $display_brands ) );
									if ( count( $brand_slugs ) > 3 ) {
										echo esc_html(
											sprintf(
												/* translators: %d: number of additional brands */
												_n( ' +%d more', ' +%d more', count( $brand_slugs ) - 3, 'wc-sku-ean-comparator' ),
												count( $brand_slugs ) - 3
											)
										);
									}
									?>
								</span>
							<?php endif; ?>
						</td>
						<td class="column-stats">
							<?php
							$pl_total     = isset( $stats['pricelist_total'] ) ? (int) $stats['pricelist_total'] : 0;
							$pl_matched   = isset( $stats['pricelist_matched'] ) ? (int) $stats['pricelist_matched'] : 0;
							$pl_unmatched = isset( $stats['pricelist_unmatched'] ) ? (int) $stats['pricelist_unmatched'] : 0;
							?>
							<span class="wc-sec-stat-total"><?php echo esc_html( number_format_i18n( $pl_total ) ); ?></span>
							&nbsp;
							<span class="wc-sec-stat-matched" title="<?php esc_attr_e( 'Found in shop', 'wc-sku-ean-comparator' ); ?>">
								&#10003; <?php echo esc_html( number_format_i18n( $pl_matched ) ); ?>
							</span>
							&nbsp;
							<span class="wc-sec-stat-unmatched" title="<?php esc_attr_e( 'Not found in shop', 'wc-sku-ean-comparator' ); ?>">
								&#10007; <?php echo esc_html( number_format_i18n( $pl_unmatched ) ); ?>
							</span>
						</td>
						<td class="column-stats">
							<?php
							$shop_total     = isset( $stats['shop_total'] ) ? (int) $stats['shop_total'] : 0;
							$shop_matched   = isset( $stats['shop_matched'] ) ? (int) $stats['shop_matched'] : 0;
							$shop_unmatched = isset( $stats['shop_unmatched'] ) ? (int) $stats['shop_unmatched'] : 0;
							?>
							<span class="wc-sec-stat-total"><?php echo esc_html( number_format_i18n( $shop_total ) ); ?></span>
							&nbsp;
							<span class="wc-sec-stat-matched" title="<?php esc_attr_e( 'In pricelist', 'wc-sku-ean-comparator' ); ?>">
								&#10003; <?php echo esc_html( number_format_i18n( $shop_matched ) ); ?>
							</span>
							&nbsp;
							<span class="wc-sec-stat-unmatched" title="<?php esc_attr_e( 'Not in pricelist', 'wc-sku-ean-comparator' ); ?>">
								&#10007; <?php echo esc_html( number_format_i18n( $shop_unmatched ) ); ?>
							</span>
						</td>
						<td class="column-csv">
							<?php if ( $csv1_url ) : ?>
								<a href="<?php echo esc_url( $csv1_url ); ?>" class="wc-sec-csv-link" download>
									<?php esc_html_e( 'Pricelist→Shop', 'wc-sku-ean-comparator' ); ?>
								</a>
							<?php endif; ?>
							<?php if ( $csv1_url && $csv2_url ) : ?>
								<br>
							<?php endif; ?>
							<?php if ( $csv2_url ) : ?>
								<a href="<?php echo esc_url( $csv2_url ); ?>" class="wc-sec-csv-link" download>
									<?php esc_html_e( 'Shop→Pricelist', 'wc-sku-ean-comparator' ); ?>
								</a>
							<?php endif; ?>
							<?php if ( ! $csv1_url && ! $csv2_url ) : ?>
								<em><?php esc_html_e( 'N/A', 'wc-sku-ean-comparator' ); ?></em>
							<?php endif; ?>
						</td>
						<td class="column-actions">
							<a href="<?php echo esc_url( $detail_url ); ?>" class="button button-small">
								<?php esc_html_e( 'View', 'wc-sku-ean-comparator' ); ?>
							</a>
							<button type="button"
								class="button button-small wc-sec-delete-comparison-btn"
								data-id="<?php echo esc_attr( $comparison['id'] ); ?>"
								data-nonce="<?php echo esc_attr( wp_create_nonce( 'wc_sec_ajax' ) ); ?>">
								<?php esc_html_e( 'Delete', 'wc-sku-ean-comparator' ); ?>
							</button>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( $total_pages > 1 ) : ?>
			<div class="tablenav bottom">
				<div class="tablenav-pages">
					<?php
					$page_links = paginate_links(
						array(
							'base'      => add_query_arg( 'paged', '%#%' ),
							'format'    => '',
							'prev_text' => '&laquo;',
							'next_text' => '&raquo;',
							'total'     => $total_pages,
							'current'   => $paged,
						)
					);

					if ( $page_links ) {
						echo wp_kses_post( $page_links );
					}
					?>
				</div>
			</div>
		<?php endif; ?>

	<?php endif; ?>
</div><!-- /.wrap -->
