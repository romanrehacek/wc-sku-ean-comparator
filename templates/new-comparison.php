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
	<!-- Global notice area -->
	<div id="wc-sec-notice" class="wc-sec-notice hidden"></div>

	<!-- ================================================================
	     STEP 1: File selection
	     ================================================================ -->
	<div class="wc-sec-panel" id="wc-sec-step-1">
		<h2><?php esc_html_e( 'Step 1: Select Price List File', 'wc-sku-ean-comparator' ); ?></h2>

		<div class="wc-sec-file-tabs">
			<button type="button" class="wc-sec-tab-btn active" data-tab="upload">
				<?php esc_html_e( 'Upload New File', 'wc-sku-ean-comparator' ); ?>
			</button>
			<button type="button" class="wc-sec-tab-btn" data-tab="existing">
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
	     STEP 3: Column mapping (rules-based)
	     ================================================================ -->
	<div class="wc-sec-panel hidden" id="wc-sec-step-3">
		<h2><?php esc_html_e( 'Step 3: Map Columns', 'wc-sku-ean-comparator' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Add one rule per shop field you want to match on. Rules are tried in order — first match wins. Each rule maps one or more pricelist columns to a shop field.', 'wc-sku-ean-comparator' ); ?>
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

		<!-- Rules builder -->
		<div id="wc-sec-mapping-wrap" class="hidden">
			<h3><?php esc_html_e( 'Mapping Rules', 'wc-sku-ean-comparator' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'Rules are matched in order. At least one rule with at least one pricelist column is required.', 'wc-sku-ean-comparator' ); ?>
			</p>
			<div id="wc-sec-rules-container"></div>
			<button type="button" class="button wc-sec-add-rule-btn" id="wc-sec-add-rule-btn">
				+ <?php esc_html_e( 'Add Rule', 'wc-sku-ean-comparator' ); ?>
			</button>
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
				<button type="button" class="wc-sec-result-tab active" data-tab="pricelist">
					<?php esc_html_e( 'Pricelist → Shop', 'wc-sku-ean-comparator' ); ?>
				</button>
				<button type="button" class="wc-sec-result-tab" data-tab="shop">
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
							<tr id="wc-sec-thead-pricelist"></tr>
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
							<tr id="wc-sec-thead-shop"></tr>
						</thead>
						<tbody id="wc-sec-tbody-shop"></tbody>
					</table>
				</div>
				<div class="wc-sec-pagination" id="wc-sec-pagination-shop"></div>
			</div>

		</div><!-- /results -->

		<div class="wc-sec-panel__footer" id="wc-sec-results-footer" style="display:none;">
			<button type="button" class="button button-primary" id="wc-sec-new-comparison-btn">
				<?php esc_html_e( 'New Comparison', 'wc-sku-ean-comparator' ); ?>
			</button>
		</div>
	</div><!-- /step 4 -->
