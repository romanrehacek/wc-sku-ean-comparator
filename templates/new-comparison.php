<?php
/**
 * Template: New Comparison wizard.
 *
 * Available variables: none (all data fetched via AJAX).
 *
 * @package WC_SKU_EAN_Comparator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap wc-sec-wrap">
	<h1><?php esc_html_e( 'WC SKU/EAN Comparator', 'wc-sku-ean-comparator' ); ?></h1>
	<p class="description">
		<?php esc_html_e( 'Import a price list and compare products against your WooCommerce catalog by SKU and EAN.', 'wc-sku-ean-comparator' ); ?>
	</p>

	<!-- Step indicators -->
	<div class="wc-sec-steps" id="wc-sec-steps">
		<div class="wc-sec-step active" data-step="1">
			<span class="wc-sec-step__number">1</span>
			<span class="wc-sec-step__label"><?php esc_html_e( 'File', 'wc-sku-ean-comparator' ); ?></span>
		</div>
		<div class="wc-sec-step" data-step="2">
			<span class="wc-sec-step__number">2</span>
			<span class="wc-sec-step__label"><?php esc_html_e( 'Brands', 'wc-sku-ean-comparator' ); ?></span>
		</div>
		<div class="wc-sec-step" data-step="3">
			<span class="wc-sec-step__number">3</span>
			<span class="wc-sec-step__label"><?php esc_html_e( 'Columns', 'wc-sku-ean-comparator' ); ?></span>
		</div>
		<div class="wc-sec-step" data-step="4">
			<span class="wc-sec-step__number">4</span>
			<span class="wc-sec-step__label"><?php esc_html_e( 'Compare', 'wc-sku-ean-comparator' ); ?></span>
		</div>
	</div>

	<!-- Global notice area -->
	<div id="wc-sec-notice" class="wc-sec-notice hidden"></div>

	<!-- ================================================================
	     STEP 1: File selection
	     ================================================================ -->
	<div class="wc-sec-panel" id="wc-sec-step-1">
		<h2><?php esc_html_e( 'Step 1: Select Price List File', 'wc-sku-ean-comparator' ); ?></h2>

		<div class="wc-sec-file-tabs">
			<button type="button" class="button wc-sec-tab-btn active" data-tab="upload">
				<?php esc_html_e( 'Upload New File', 'wc-sku-ean-comparator' ); ?>
			</button>
			<button type="button" class="button wc-sec-tab-btn" data-tab="existing">
				<?php esc_html_e( 'Use Existing File', 'wc-sku-ean-comparator' ); ?>
			</button>
		</div>

		<!-- Upload tab -->
		<div class="wc-sec-tab-content active" id="wc-sec-tab-upload">
			<table class="form-table">
				<tr>
					<th scope="row"><label for="wc-sec-file-input"><?php esc_html_e( 'Price list file', 'wc-sku-ean-comparator' ); ?></label></th>
					<td>
						<input type="file" id="wc-sec-file-input" name="file" accept=".csv,.xls,.xlsx" />
						<p class="description">
							<?php esc_html_e( 'Supported formats: CSV, XLS, XLSX. Maximum size: 20 MB.', 'wc-sku-ean-comparator' ); ?>
						</p>
					</td>
				</tr>
				<tr id="wc-sec-overwrite-row" class="hidden">
					<th scope="row"><?php esc_html_e( 'File exists', 'wc-sku-ean-comparator' ); ?></th>
					<td>
						<label>
							<input type="checkbox" id="wc-sec-overwrite" value="1" />
							<?php esc_html_e( 'Overwrite existing file with the same name', 'wc-sku-ean-comparator' ); ?>
						</label>
					</td>
				</tr>
			</table>
			<div id="wc-sec-upload-progress" class="wc-sec-progress hidden">
				<div class="wc-sec-progress__bar"><div class="wc-sec-progress__fill" style="width:0%"></div></div>
				<span class="wc-sec-progress__label"><?php esc_html_e( 'Uploading...', 'wc-sku-ean-comparator' ); ?></span>
			</div>
			<button type="button" class="button button-primary" id="wc-sec-upload-btn">
				<?php esc_html_e( 'Upload File', 'wc-sku-ean-comparator' ); ?>
			</button>
		</div>

		<!-- Existing files tab -->
		<div class="wc-sec-tab-content" id="wc-sec-tab-existing">
			<div id="wc-sec-file-list-wrap">
				<p><?php esc_html_e( 'Loading files...', 'wc-sku-ean-comparator' ); ?></p>
			</div>
		</div>

		<!-- Sheet selection (shown for XLS/XLSX) -->
		<div id="wc-sec-sheet-selection" class="hidden">
			<h3><?php esc_html_e( 'Select Sheet', 'wc-sku-ean-comparator' ); ?></h3>
			<select id="wc-sec-sheet-select" name="sheet_index">
				<option value="0"><?php esc_html_e( '-- Select a sheet --', 'wc-sku-ean-comparator' ); ?></option>
			</select>
		</div>

		<div class="wc-sec-panel__footer">
			<button type="button" class="button button-primary wc-sec-next-btn" id="wc-sec-step1-next" disabled>
				<?php esc_html_e( 'Next: Select Brands', 'wc-sku-ean-comparator' ); ?>
			</button>
		</div>
	</div><!-- /step 1 -->

	<!-- ================================================================
	     STEP 2: Brand selection
	     ================================================================ -->
	<div class="wc-sec-panel hidden" id="wc-sec-step-2">
		<h2><?php esc_html_e( 'Step 2: Select Brands', 'wc-sku-ean-comparator' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Select the brands whose products will be checked against this price list. The comparison will look for products from these brands that are not in the price list.', 'wc-sku-ean-comparator' ); ?>
		</p>

		<div id="wc-sec-brand-list-wrap">
			<p><?php esc_html_e( 'Loading brands...', 'wc-sku-ean-comparator' ); ?></p>
		</div>

		<div class="wc-sec-panel__footer">
			<button type="button" class="button wc-sec-prev-btn" data-target="1">
				<?php esc_html_e( 'Back', 'wc-sku-ean-comparator' ); ?>
			</button>
			<button type="button" class="button button-primary wc-sec-next-btn" id="wc-sec-step2-next" disabled>
				<?php esc_html_e( 'Next: Map Columns', 'wc-sku-ean-comparator' ); ?>
			</button>
		</div>
	</div><!-- /step 2 -->

	<!-- ================================================================
	     STEP 3: Column mapping
	     ================================================================ -->
	<div class="wc-sec-panel hidden" id="wc-sec-step-3">
		<h2><?php esc_html_e( 'Step 3: Map Columns', 'wc-sku-ean-comparator' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Tell the plugin which columns in the price list contain SKU, EAN, and product name. You can select multiple columns for each field.', 'wc-sku-ean-comparator' ); ?>
		</p>

		<!-- Header row selector -->
		<div class="wc-sec-header-row-wrap">
			<label for="wc-sec-header-row">
				<?php esc_html_e( 'Header row:', 'wc-sku-ean-comparator' ); ?>
			</label>
			<input
				type="number"
				id="wc-sec-header-row"
				name="header_row"
				min="1"
				max="20"
				value="1"
				class="small-text"
			/>
			<span class="wc-sec-header-row-hint description">
				<?php esc_html_e( 'Auto-detected. Change if columns look wrong.', 'wc-sku-ean-comparator' ); ?>
			</span>
			<button type="button" class="button" id="wc-sec-reload-columns-btn">
				<?php esc_html_e( 'Reload columns', 'wc-sku-ean-comparator' ); ?>
			</button>
		</div>

		<!-- Preview table -->
		<div id="wc-sec-preview-wrap" class="wc-sec-preview-wrap hidden">
			<h3><?php esc_html_e( 'File Preview (first 5 rows)', 'wc-sku-ean-comparator' ); ?></h3>
			<div class="wc-sec-table-scroll">
				<table id="wc-sec-preview-table" class="widefat striped wc-sec-table">
					<thead><tr id="wc-sec-preview-header"></tr></thead>
					<tbody id="wc-sec-preview-body"></tbody>
				</table>
			</div>
		</div>

		<!-- Column assignment -->
		<div id="wc-sec-mapping-wrap" class="hidden">
			<h3><?php esc_html_e( 'Column Mapping', 'wc-sku-ean-comparator' ); ?></h3>
			<table class="form-table">
				<tr>
					<th scope="row">
						<label><?php esc_html_e( 'SKU column(s)', 'wc-sku-ean-comparator' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<div id="wc-sec-sku-columns" class="wc-sec-column-selector"></div>
						<p class="description"><?php esc_html_e( 'Select one or more columns that contain SKU values.', 'wc-sku-ean-comparator' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label><?php esc_html_e( 'EAN column(s)', 'wc-sku-ean-comparator' ); ?></label>
					</th>
					<td>
						<div id="wc-sec-ean-columns" class="wc-sec-column-selector"></div>
						<p class="description"><?php esc_html_e( 'Select one or more columns that contain EAN/barcode values.', 'wc-sku-ean-comparator' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label><?php esc_html_e( 'Name column(s)', 'wc-sku-ean-comparator' ); ?></label>
					</th>
					<td>
						<div id="wc-sec-name-columns" class="wc-sec-column-selector"></div>
						<p class="description"><?php esc_html_e( 'Select columns containing product name. Multiple columns will be joined with a space.', 'wc-sku-ean-comparator' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

		<div class="wc-sec-panel__footer">
			<button type="button" class="button wc-sec-prev-btn" data-target="2">
				<?php esc_html_e( 'Back', 'wc-sku-ean-comparator' ); ?>
			</button>
			<button type="button" class="button button-primary" id="wc-sec-step3-next" disabled>
				<?php esc_html_e( 'Start Comparison', 'wc-sku-ean-comparator' ); ?>
			</button>
		</div>
	</div><!-- /step 3 -->

	<!-- ================================================================
	     STEP 4: Running comparison + Results
	     ================================================================ -->
	<div class="wc-sec-panel hidden" id="wc-sec-step-4">
		<h2><?php esc_html_e( 'Step 4: Comparison', 'wc-sku-ean-comparator' ); ?></h2>

		<!-- Progress state -->
		<div id="wc-sec-running" class="wc-sec-running">
			<div class="wc-sec-progress">
				<div class="wc-sec-progress__bar"><div class="wc-sec-progress__fill" id="wc-sec-run-progress-fill" style="width:0%"></div></div>
				<span class="wc-sec-progress__label" id="wc-sec-run-progress-label">
					<?php esc_html_e( 'Preparing comparison...', 'wc-sku-ean-comparator' ); ?>
				</span>
			</div>
		</div>

		<!-- Results (shown after completion) -->
		<div id="wc-sec-results" class="hidden">

			<!-- Comparison meta info (file, sheet, brands) -->
			<div class="wc-sec-comparison-meta" id="wc-sec-comparison-meta"></div>

			<!-- Summary stats -->
			<div class="wc-sec-stats" id="wc-sec-stats-wrap"></div>

			<!-- CSV download links -->
			<div class="wc-sec-csv-links" id="wc-sec-csv-links"></div>

			<!-- Results tabs -->
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
				<input type="search" id="wc-sec-filter-search" placeholder="<?php esc_attr_e( 'Search...', 'wc-sku-ean-comparator' ); ?>" />
				<select id="wc-sec-filter-status">
					<option value=""><?php esc_html_e( 'All', 'wc-sku-ean-comparator' ); ?></option>
					<option value="found"><?php esc_html_e( 'Found', 'wc-sku-ean-comparator' ); ?></option>
					<option value="not_found"><?php esc_html_e( 'Not found', 'wc-sku-ean-comparator' ); ?></option>
				</select>
			</div>

			<!-- Pricelist → Shop table -->
			<div class="wc-sec-result-content active" id="wc-sec-result-pricelist">
				<div class="wc-sec-table-scroll">
					<table class="widefat striped wc-sec-table" id="wc-sec-table-pricelist">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Name (Pricelist)', 'wc-sku-ean-comparator' ); ?></th>
								<th><?php esc_html_e( 'SKU (Pricelist)', 'wc-sku-ean-comparator' ); ?></th>
								<th><?php esc_html_e( 'EAN (Pricelist)', 'wc-sku-ean-comparator' ); ?></th>
								<th><?php esc_html_e( 'Status', 'wc-sku-ean-comparator' ); ?></th>
								<th><?php esc_html_e( 'Shop ID', 'wc-sku-ean-comparator' ); ?></th>
								<th><?php esc_html_e( 'Name (Shop)', 'wc-sku-ean-comparator' ); ?></th>
								<th><?php esc_html_e( 'SKU (Shop)', 'wc-sku-ean-comparator' ); ?></th>
								<th><?php esc_html_e( 'EAN (Shop)', 'wc-sku-ean-comparator' ); ?></th>
							</tr>
						</thead>
						<tbody id="wc-sec-tbody-pricelist"></tbody>
					</table>
				</div>
				<div class="wc-sec-pagination" id="wc-sec-pagination-pricelist"></div>
			</div>

			<!-- Shop → Pricelist table -->
			<div class="wc-sec-result-content hidden" id="wc-sec-result-shop">
				<div class="wc-sec-table-scroll">
					<table class="widefat striped wc-sec-table" id="wc-sec-table-shop">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Shop ID', 'wc-sku-ean-comparator' ); ?></th>
								<th><?php esc_html_e( 'Name (Shop)', 'wc-sku-ean-comparator' ); ?></th>
								<th><?php esc_html_e( 'SKU (Shop)', 'wc-sku-ean-comparator' ); ?></th>
								<th><?php esc_html_e( 'EAN (Shop)', 'wc-sku-ean-comparator' ); ?></th>
								<th><?php esc_html_e( 'In Pricelist', 'wc-sku-ean-comparator' ); ?></th>
							</tr>
						</thead>
						<tbody id="wc-sec-tbody-shop"></tbody>
					</table>
				</div>
				<div class="wc-sec-pagination" id="wc-sec-pagination-shop"></div>
			</div>

		</div><!-- /results -->

		<div class="wc-sec-panel__footer" id="wc-sec-results-footer" style="display:none;">
			<a href="<?php echo esc_url( WC_SKU_EAN_Comparator\Admin_Page::get_history_url() ); ?>" class="button">
				<?php esc_html_e( 'View History', 'wc-sku-ean-comparator' ); ?>
			</a>
			<button type="button" class="button button-primary" id="wc-sec-new-comparison-btn">
				<?php esc_html_e( 'New Comparison', 'wc-sku-ean-comparator' ); ?>
			</button>
		</div>
	</div><!-- /step 4 -->

</div><!-- /.wrap -->
