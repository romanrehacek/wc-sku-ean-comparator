/**
 * WC SKU/EAN Comparator - Admin JavaScript
 *
 * Handles all AJAX wizard interactions for the New Comparison and History pages.
 *
 * @package WC_SKU_EAN_Comparator
 */

/* global wcSecData, jQuery */
( function ( $, data ) {
	'use strict';

	// =========================================================================
	// State
	// =========================================================================

	var state = {
		filename: '',
		sheetIndex: 0,
		sheetNames: [],
		headerRow: 0,
		headers: [],
		previewRows: [],
		brandSlugs: [],
		columnMapping: {
			sku_columns: [],
			ean_columns: [],
			name_columns: []
		},
		comparisonId: 0,
		currentTab: 'pricelist',
		currentPage: { pricelist: 1, shop: 1 },
		allRows: { pricelist: [], shop: [] },
		filteredRows: { pricelist: [], shop: [] }
	};

	var PER_PAGE = 100;

	// =========================================================================
	// Utility helpers
	// =========================================================================

	/**
	 * Show a page-level notice.
	 *
	 * @param {string} message  HTML message.
	 * @param {string} type     'success' | 'error' | '' (default/info).
	 * @param {string} selector jQuery selector for the notice container.
	 */
	function showNotice( message, type, selector ) {
		var $notice = $( selector || '#wc-sec-notice' );
		$notice
			.removeClass( 'wc-sec-notice--success wc-sec-notice--error' )
			.addClass( type ? 'wc-sec-notice--' + type : '' )
			.html( message )
			.removeClass( 'hidden' );
		$notice[ 0 ].scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
	}

	function hideNotice( selector ) {
		$( selector || '#wc-sec-notice' ).addClass( 'hidden' ).empty();
	}

	/**
	 * Escape HTML for safe insertion.
	 *
	 * @param {string} str
	 * @return {string}
	 */
	function escHtml( str ) {
		return $( '<span>' ).text( str ).html();
	}

	/**
	 * Format a number using locale-aware thousand separators (basic).
	 *
	 * @param {number} n
	 * @return {string}
	 */
	function fmtNum( n ) {
		return Number( n ).toLocaleString();
	}

	/**
	 * Format a byte count as a human-readable file size.
	 *
	 * @param {number} bytes
	 * @return {string}
	 */
	function formatFileSize( bytes ) {
		if ( bytes >= 1048576 ) {
			return ( bytes / 1048576 ).toFixed( 1 ) + ' MB';
		}
		if ( bytes >= 1024 ) {
			return ( bytes / 1024 ).toFixed( 1 ) + ' KB';
		}
		return bytes + ' B';
	}

	// =========================================================================
	// Step navigation
	// =========================================================================

	function goToStep( step ) {
		$( '.wc-sec-panel' ).addClass( 'hidden' );
		$( '#wc-sec-step-' + step ).removeClass( 'hidden' );

		$( '.wc-sec-step' ).each( function () {
			var s = parseInt( $( this ).data( 'step' ), 10 );
			$( this )
				.toggleClass( 'active', s === step )
				.toggleClass( 'completed', s < step );
		} );
	}

	// =========================================================================
	// Tab switching (file tabs)
	// =========================================================================

	function initFileTabs() {
		$( document ).on( 'click', '.wc-sec-tab-btn', function () {
			var tab = $( this ).data( 'tab' );
			$( '.wc-sec-tab-btn' ).removeClass( 'active' );
			$( '.wc-sec-tab-content' ).removeClass( 'active' );
			$( this ).addClass( 'active' );
			$( '#wc-sec-tab-' + tab ).addClass( 'active' );

			if ( 'existing' === tab ) {
				loadFileList();
			}
		} );
	}

	// =========================================================================
	// Result tab switching
	// =========================================================================

	function initResultTabs() {
		$( document ).on( 'click', '.wc-sec-result-tab', function () {
			var tab = $( this ).data( 'tab' );
			$( '.wc-sec-result-tab' ).removeClass( 'active' );
			$( this ).addClass( 'active' );
			$( '.wc-sec-result-content' ).removeClass( 'active' ).addClass( 'hidden' );
			$( '#wc-sec-result-' + tab ).addClass( 'active' ).removeClass( 'hidden' );
			state.currentTab = tab;
		} );
	}

	// =========================================================================
	// Step 1: File upload & selection
	// =========================================================================

	function loadFileList() {
		$( '#wc-sec-file-list-wrap' ).html( '<p>' + escHtml( data.i18n.processing ) + '</p>' );

		$.post( data.ajaxUrl, {
			action: 'wc_sec_list_files',
			nonce: data.nonce
		} ).done( function ( response ) {
			if ( ! response.success || ! response.data.files.length ) {
				$( '#wc-sec-file-list-wrap' ).html( '<p>No uploaded files found.</p>' );
				return;
			}

			var html = '<ul class="wc-sec-file-list">';
			$.each( response.data.files, function ( i, file ) {
			var ext   = file.name.split( '.' ).pop().toLowerCase();
			var mtime = file.modified ? new Date( file.modified * 1000 ).toLocaleString() : '';
			var size  = file.size ? formatFileSize( file.size ) : '';
			html += '<li class="wc-sec-file-item" data-name="' + escHtml( file.name ) + '" data-ext="' + ext + '">' +
				'<span class="wc-sec-file-item__name">' + escHtml( file.name ) + '</span>' +
				'<span class="wc-sec-file-item__meta">' + escHtml( size ) + ( size && mtime ? ' &mdash; ' : '' ) + escHtml( mtime ) + '</span>' +
					'<span class="wc-sec-file-item__actions">' +
						'<button type="button" class="button button-small wc-sec-delete-file-btn" data-name="' + escHtml( file.name ) + '">Delete</button>' +
					'</span>' +
					'</li>';
			} );
			html += '</ul>';
			$( '#wc-sec-file-list-wrap' ).html( html );
		} ).fail( function () {
			$( '#wc-sec-file-list-wrap' ).html( '<p>' + escHtml( data.i18n.error ) + '</p>' );
		} );
	}

	// Select existing file.
	$( document ).on( 'click', '.wc-sec-file-item', function ( e ) {
		if ( $( e.target ).closest( '.wc-sec-file-item__actions' ).length ) {
			return; // don't select when clicking delete
		}
		$( '.wc-sec-file-item' ).removeClass( 'selected' );
		$( this ).addClass( 'selected' );

		var filename = $( this ).data( 'name' );
		var ext      = $( this ).data( 'ext' );
		selectFile( filename, ext );
	} );

	// Delete an existing file.
	$( document ).on( 'click', '.wc-sec-delete-file-btn', function ( e ) {
		e.stopPropagation();
		var filename = $( this ).data( 'name' );
		if ( ! window.confirm( data.i18n.confirmDeleteFile ) ) {
			return;
		}

		var $btn = $( this ).prop( 'disabled', true );

		$.post( data.ajaxUrl, {
			action: 'wc_sec_delete_file',
			nonce: data.nonce,
			filename: filename
		} ).done( function ( response ) {
			if ( response.success ) {
				if ( state.filename === filename ) {
					state.filename   = '';
					state.sheetIndex = 0;
					$( '#wc-sec-sheet-selection' ).addClass( 'hidden' );
					$( '#wc-sec-step1-next' ).prop( 'disabled', true );
				}
				loadFileList();
			} else {
				showNotice( escHtml( response.data.message ), 'error' );
			}
		} ).fail( function () {
			showNotice( escHtml( data.i18n.error ), 'error' );
		} ).always( function () {
			$btn.prop( 'disabled', false );
		} );
	} );

	// Upload new file button.
	$( '#wc-sec-upload-btn' ).on( 'click', function () {
		var fileInput = $( '#wc-sec-file-input' )[ 0 ];
		if ( ! fileInput.files.length ) {
			showNotice( escHtml( data.i18n.selectFile ), 'error' );
			return;
		}

		var file      = fileInput.files[ 0 ];
		var overwrite = $( '#wc-sec-overwrite' ).is( ':checked' ) ? '1' : '0';
		var formData  = new FormData();
		formData.append( 'action', 'wc_sec_upload_file' );
		formData.append( 'nonce', data.nonce );
		formData.append( 'file', file );
		formData.append( 'overwrite', overwrite );

		hideNotice();
		$( '#wc-sec-upload-progress' ).removeClass( 'hidden' );
		$( '#wc-sec-upload-btn' ).prop( 'disabled', true );
		setUploadProgress( 10 );

		$.ajax( {
			url: data.ajaxUrl,
			type: 'POST',
			data: formData,
			processData: false,
			contentType: false,
			xhr: function () {
				var xhr = new window.XMLHttpRequest();
				xhr.upload.addEventListener( 'progress', function ( evt ) {
					if ( evt.lengthComputable ) {
						var pct = Math.round( ( evt.loaded / evt.total ) * 90 );
						setUploadProgress( pct );
					}
				}, false );
				return xhr;
			}
		} ).done( function ( response ) {
			setUploadProgress( 100 );
			$( '#wc-sec-upload-progress' ).addClass( 'hidden' );
			$( '#wc-sec-upload-btn' ).prop( 'disabled', false );

			if ( ! response.success ) {
				// Check for overwrite conflict.
				if ( response.data && response.data.exists ) {
					$( '#wc-sec-overwrite-row' ).removeClass( 'hidden' );
				}
				showNotice( escHtml( response.data.message ), 'error' );
				return;
			}

			var filename  = response.data.filename;
			var ext       = filename.split( '.' ).pop().toLowerCase();
			var sheetNames = response.data.sheet_names || [];
			selectFile( filename, ext, sheetNames );
		} ).fail( function () {
			$( '#wc-sec-upload-progress' ).addClass( 'hidden' );
			$( '#wc-sec-upload-btn' ).prop( 'disabled', false );
			showNotice( escHtml( data.i18n.error ), 'error' );
		} );
	} );

	function setUploadProgress( pct ) {
		$( '#wc-sec-upload-progress .wc-sec-progress__fill' ).css( 'width', pct + '%' );
	}

	/**
	 * Mark a file as selected and optionally show sheet selector.
	 *
	 * @param {string}   filename
	 * @param {string}   ext
	 * @param {string[]} [sheetNames]
	 */
	function selectFile( filename, ext, sheetNames ) {
		state.filename   = filename;
		state.sheetIndex = 0;
		state.sheetNames = sheetNames || [];

		if ( ( 'xls' === ext || 'xlsx' === ext ) ) {
			if ( ! sheetNames || ! sheetNames.length ) {
				// Fetch sheet names if not provided.
				fetchSheetNames( filename );
			} else {
				showSheetSelector( sheetNames );
			}
		} else {
			$( '#wc-sec-sheet-selection' ).addClass( 'hidden' );
			$( '#wc-sec-step1-next' ).prop( 'disabled', false );
		}
	}

	function fetchSheetNames( filename ) {
		$.post( data.ajaxUrl, {
			action: 'wc_sec_get_sheet_names',
			nonce: data.nonce,
			filename: filename
		} ).done( function ( response ) {
			if ( response.success && response.data.sheet_names && response.data.sheet_names.length ) {
				state.sheetNames = response.data.sheet_names;
				showSheetSelector( state.sheetNames );
			} else {
				// Single-sheet or unknown — proceed without sheet selector.
				$( '#wc-sec-sheet-selection' ).addClass( 'hidden' );
				$( '#wc-sec-step1-next' ).prop( 'disabled', false );
			}
		} ).fail( function () {
			// Fallback: allow proceeding without sheet selection.
			$( '#wc-sec-sheet-selection' ).addClass( 'hidden' );
			$( '#wc-sec-step1-next' ).prop( 'disabled', false );
		} );
	}

	function showSheetSelector( sheetNames ) {
		var $select = $( '#wc-sec-sheet-select' ).empty();
		$select.append( '<option value="">-- Select a sheet --</option>' );
		$.each( sheetNames, function ( idx, name ) {
			$select.append( '<option value="' + idx + '">' + escHtml( name ) + '</option>' );
		} );
		$( '#wc-sec-sheet-selection' ).removeClass( 'hidden' );
		$( '#wc-sec-step1-next' ).prop( 'disabled', true );
	}

	// Sheet selection change.
	$( '#wc-sec-sheet-select' ).on( 'change', function () {
		var val = $( this ).val();
		if ( '' !== val ) {
			state.sheetIndex = parseInt( val, 10 );
			$( '#wc-sec-step1-next' ).prop( 'disabled', false );
		} else {
			$( '#wc-sec-step1-next' ).prop( 'disabled', true );
		}
	} );

	// File input change: reset overwrite checkbox visibility.
	$( '#wc-sec-file-input' ).on( 'change', function () {
		$( '#wc-sec-overwrite-row' ).addClass( 'hidden' );
		$( '#wc-sec-overwrite' ).prop( 'checked', false );
	} );

	// Step 1 → 2.
	$( '#wc-sec-step1-next' ).on( 'click', function () {
		if ( ! state.filename ) {
			showNotice( escHtml( data.i18n.selectFile ), 'error' );
			return;
		}
		hideNotice();
		goToStep( 2 );
		loadBrands();
	} );

	// =========================================================================
	// Step 2: Brand selection
	// =========================================================================

	function loadBrands() {
		$( '#wc-sec-brand-list-wrap' ).html( '<p>' + escHtml( data.i18n.processing ) + '</p>' );

		$.post( data.ajaxUrl, {
			action: 'wc_sec_get_brands',
			nonce: data.nonce
		} ).done( function ( response ) {
			if ( ! response.success ) {
				$( '#wc-sec-brand-list-wrap' ).html( '<p>' + escHtml( data.i18n.error ) + '</p>' );
				return;
			}

			var brands = response.data.brands;

			if ( ! brands.length ) {
				// No brands — skip to column mapping.
				$( '#wc-sec-brand-list-wrap' ).html(
					'<p>No product brands found. All products will be compared.</p>'
				);
				$( '#wc-sec-step2-next' ).prop( 'disabled', false );
				return;
			}

			var html = '<div class="wc-sec-brand-actions">' +
				'<button type="button" class="button" id="wc-sec-select-all-brands">Select All</button>' +
				'<button type="button" class="button" id="wc-sec-deselect-all-brands">Deselect All</button>' +
				'</div>' +
				'<div class="wc-sec-brand-grid">';

			$.each( brands, function ( i, brand ) {
				html += '<label class="wc-sec-brand-item">' +
					'<input type="checkbox" class="wc-sec-brand-check" value="' + escHtml( brand.slug ) + '">' +
					escHtml( brand.name ) +
					'<span class="wc-sec-brand-count">' + escHtml( brand.count ) + '</span>' +
					'</label>';
			} );

			html += '</div>';
			$( '#wc-sec-brand-list-wrap' ).html( html );
		} ).fail( function () {
			$( '#wc-sec-brand-list-wrap' ).html( '<p>' + escHtml( data.i18n.error ) + '</p>' );
		} );
	}

	// Toggle brand checkbox → update Next button.
	$( document ).on( 'change', '.wc-sec-brand-check', function () {
		updateBrandSelection();
	} );

	// Select / Deselect all.
	$( document ).on( 'click', '#wc-sec-select-all-brands', function () {
		$( '.wc-sec-brand-check' ).prop( 'checked', true ).closest( '.wc-sec-brand-item' ).addClass( 'selected' );
		updateBrandSelection();
	} );

	$( document ).on( 'click', '#wc-sec-deselect-all-brands', function () {
		$( '.wc-sec-brand-check' ).prop( 'checked', false ).closest( '.wc-sec-brand-item' ).removeClass( 'selected' );
		updateBrandSelection();
	} );

	function updateBrandSelection() {
		var selected = [];
		$( '.wc-sec-brand-check:checked' ).each( function () {
			selected.push( $( this ).val() );
			$( this ).closest( '.wc-sec-brand-item' ).addClass( 'selected' );
		} );
		$( '.wc-sec-brand-check:not(:checked)' ).each( function () {
			$( this ).closest( '.wc-sec-brand-item' ).removeClass( 'selected' );
		} );
		state.brandSlugs = selected;
		$( '#wc-sec-step2-next' ).prop( 'disabled', false ); // always allow proceed (empty = all brands)
	}

	// Step 2 → 3.
	$( '#wc-sec-step2-next' ).on( 'click', function () {
		hideNotice();
		goToStep( 3 );
		loadColumns();
	} );

	// Step back buttons.
	$( document ).on( 'click', '.wc-sec-prev-btn', function () {
		var target = parseInt( $( this ).data( 'target' ), 10 );
		goToStep( target );
	} );

	// =========================================================================
	// Step 3: Column mapping
	// =========================================================================

	function loadColumns() {
		$( '#wc-sec-preview-wrap' ).addClass( 'hidden' );
		$( '#wc-sec-mapping-wrap' ).addClass( 'hidden' );
		$( '#wc-sec-step3-next' ).prop( 'disabled', true );

		var headerRow = parseInt( $( '#wc-sec-header-row' ).val(), 10 ) || 0;

		$.post( data.ajaxUrl, {
			action: 'wc_sec_get_columns',
			nonce: data.nonce,
			filename: state.filename,
			sheet_index: state.sheetIndex,
			header_row: headerRow
		} ).done( function ( response ) {
			if ( ! response.success ) {
				showNotice( escHtml( response.data.message ), 'error' );
				return;
			}

			// Update the header row input to reflect what was auto-detected.
			if ( response.data.detected_header_row ) {
				$( '#wc-sec-header-row' ).val( response.data.detected_header_row );
				state.headerRow = response.data.detected_header_row;
			} else {
				state.headerRow = headerRow;
			}

			state.headers     = response.data.headers;
			state.previewRows = response.data.preview_rows;

			renderPreviewTable( state.headers, state.previewRows, response.data.pre_header_rows || [] );
			renderColumnSelectors( state.headers );

			$( '#wc-sec-preview-wrap' ).removeClass( 'hidden' );
			$( '#wc-sec-mapping-wrap' ).removeClass( 'hidden' );
		} ).fail( function () {
			showNotice( escHtml( data.i18n.error ), 'error' );
		} );
	}

	// Reload columns when user manually changes header row number.
	$( document ).on( 'click', '#wc-sec-reload-columns-btn', function () {
		// Reset column mapping when reloading.
		state.columnMapping = { sku_columns: [], ean_columns: [], name_columns: [] };
		$( '#wc-sec-step3-next' ).prop( 'disabled', true );
		loadColumns();
	} );

	function renderPreviewTable( headers, rows, preHeaderRows ) {
		var $head     = $( '#wc-sec-preview-header' ).empty();
		var $body     = $( '#wc-sec-preview-body' ).empty();
		var headerRow = state.headerRow || 1;

		// Rows that appear before the detected header row (shown as plain rows).
		$.each( preHeaderRows, function ( i, row ) {
			var $tr = $( '<tr>' );
			$tr.append( '<td class="wc-sec-row-num">' + ( i + 1 ) + '</td>' );
			$.each( row, function ( j, cell ) {
				$tr.append( '<td>' + escHtml( cell ) + '</td>' );
			} );
			$body.append( $tr );
		} );

		// The header row itself — highlighted.
		var $headerTr = $( '<tr class="wc-sec-preview-header-row">' );
		$headerTr.append( '<td class="wc-sec-row-num">' + headerRow + '</td>' );
		$.each( headers, function ( i, h ) {
			$headerTr.append( '<td><strong>' + escHtml( h ) + '</strong></td>' );
		} );
		$body.append( $headerTr );

		// Data rows after the header.
		$.each( rows, function ( i, row ) {
			var $tr = $( '<tr>' );
			$tr.append( '<td class="wc-sec-row-num">' + ( headerRow + 1 + i ) + '</td>' );
			$.each( row, function ( j, cell ) {
				$tr.append( '<td>' + escHtml( cell ) + '</td>' );
			} );
			$body.append( $tr );
		} );
	}

	function renderColumnSelectors( headers ) {
		var targets = [
			{ id: 'wc-sec-sku-columns', key: 'sku_columns' },
			{ id: 'wc-sec-ean-columns', key: 'ean_columns' },
			{ id: 'wc-sec-name-columns', key: 'name_columns' }
		];

		$.each( targets, function ( i, target ) {
			var $wrap = $( '#' + target.id ).empty();
			$.each( headers, function ( idx, name ) {
				$wrap.append(
					'<label class="wc-sec-column-toggle" data-key="' + target.key + '" data-index="' + idx + '">' +
					'<input type="checkbox" value="' + idx + '" data-key="' + target.key + '">' +
					escHtml( name ) +
					'</label>'
				);
			} );
		} );
	}

	// Column checkbox change.
	$( document ).on( 'change', '[data-key]', function () {
		var key   = $( this ).data( 'key' );
		var index = parseInt( $( this ).val(), 10 );
		var checked = $( this ).is( ':checked' );

		$( this ).closest( '.wc-sec-column-toggle' ).toggleClass( 'selected', checked );

		if ( checked ) {
			if ( state.columnMapping[ key ].indexOf( index ) === -1 ) {
				state.columnMapping[ key ].push( index );
			}
		} else {
			state.columnMapping[ key ] = state.columnMapping[ key ].filter( function ( v ) {
				return v !== index;
			} );
		}

		validateColumnMapping();
	} );

	function validateColumnMapping() {
		var valid = state.columnMapping.sku_columns.length > 0;
		$( '#wc-sec-step3-next' ).prop( 'disabled', ! valid );
	}

	// Step 3 → 4 (start comparison).
	$( '#wc-sec-step3-next' ).on( 'click', function () {
		if ( ! state.columnMapping.sku_columns.length ) {
			showNotice( escHtml( data.i18n.selectSkuColumn ), 'error' );
			return;
		}
		hideNotice();
		goToStep( 4 );
		runComparison();
	} );

	// =========================================================================
	// Step 4: Run comparison
	// =========================================================================

	function runComparison() {
		$( '#wc-sec-running' ).removeClass( 'hidden' );
		$( '#wc-sec-results' ).addClass( 'hidden' );
		$( '#wc-sec-results-footer' ).hide();

		setRunProgress( 10, data.i18n.loadingProducts );

		$.post( data.ajaxUrl, {
			action: 'wc_sec_run_comparison',
			nonce: data.nonce,
			filename: state.filename,
			sheet_index: state.sheetIndex,
			header_row: state.headerRow,
			brand_slugs: JSON.stringify( state.brandSlugs ),
			column_mapping: JSON.stringify( state.columnMapping )
		} ).done( function ( response ) {
			setRunProgress( 100, data.i18n.done );

			if ( ! response.success ) {
				showNotice( escHtml( response.data.message ), 'error' );
				$( '#wc-sec-running' ).addClass( 'hidden' );
				return;
			}

			var res = response.data;
			state.comparisonId = res.comparison_id;

			// Store rows for client-side pagination.
			state.allRows.pricelist = res.pricelist_rows || [];
			state.allRows.shop      = res.shop_rows || [];

			setTimeout( function () {
				$( '#wc-sec-running' ).addClass( 'hidden' );
				$( '#wc-sec-results' ).removeClass( 'hidden' );
				$( '#wc-sec-results-footer' ).show();

				// Show meta info: file name, sheet name, brands.
				var selectedSheetName = ( state.sheetNames && state.sheetIndex < state.sheetNames.length )
					? state.sheetNames[ state.sheetIndex ]
					: '';
				renderComparisonMeta( state.filename, selectedSheetName, state.brandSlugs );

				renderStats( res.stats );
				renderCsvLinks( res.csv_pricelist_url, res.csv_shop_url );

				applyFiltersAndRender( 'pricelist' );
				applyFiltersAndRender( 'shop' );
			}, 400 );
		} ).fail( function () {
			setRunProgress( 0, '' );
			$( '#wc-sec-running' ).addClass( 'hidden' );
			showNotice( escHtml( data.i18n.error ), 'error' );
		} );

		// Animate progress bar to simulate work.
		animateProgress();
	}

	function animateProgress() {
		var pct = 10;
		var interval = setInterval( function () {
			pct = Math.min( pct + 5, 90 );
			setRunProgress( pct, data.i18n.comparing );
			if ( pct >= 90 ) {
				clearInterval( interval );
			}
		}, 800 );
	}

	function setRunProgress( pct, label ) {
		$( '#wc-sec-run-progress-fill' ).css( 'width', pct + '%' );
		$( '#wc-sec-run-progress-label' ).text( label );
	}

	// =========================================================================
	// Render: comparison meta info (file, sheet, brands)
	// =========================================================================

	/**
	 * Render the comparison meta info bar above the stats.
	 *
	 * @param {string}   filename   Name of the price list file.
	 * @param {string}   sheetName  Name of the selected sheet (empty for CSV).
	 * @param {string[]} brandSlugs Array of selected brand slugs.
	 */
	function renderComparisonMeta( filename, sheetName, brandSlugs ) {
		var html = '<table class="wc-sec-meta-table form-table">';

		html += '<tr><th>' + escHtml( data.i18n.metaFile ) + '</th>' +
			'<td>' + escHtml( filename ) + '</td></tr>';

		if ( sheetName ) {
			html += '<tr><th>' + escHtml( data.i18n.metaSheet ) + '</th>' +
				'<td>' + escHtml( sheetName ) + '</td></tr>';
		}

		html += '<tr><th>' + escHtml( data.i18n.metaBrands ) + '</th><td>';
		if ( ! brandSlugs || ! brandSlugs.length ) {
			html += '<em>' + escHtml( data.i18n.metaAllBrands ) + '</em>';
		} else {
			html += escHtml( brandSlugs.join( ', ' ) );
		}
		html += '</td></tr>';

		html += '</table>';

		$( '#wc-sec-comparison-meta' ).html( html );
	}

	// =========================================================================
	// Render: stats
	// =========================================================================

	function renderStats( stats ) {
		var html = '<div class="wc-sec-stat-card">' +
			'<h3>' + escHtml( data.i18n.pricelistToShop ) + '</h3>' +
			'<div class="wc-sec-stat-numbers">' +
			statItem( stats.pricelist_total, data.i18n.totalRows, '' ) +
			statItem( stats.pricelist_matched, data.i18n.foundInShop, 'success' ) +
			statItem( stats.pricelist_unmatched, data.i18n.notFound, 'warning' ) +
			'</div></div>' +
			'<div class="wc-sec-stat-card">' +
			'<h3>' + escHtml( data.i18n.shopToPricelist ) + '</h3>' +
			'<div class="wc-sec-stat-numbers">' +
			statItem( stats.shop_total, data.i18n.shopProducts, '' ) +
			statItem( stats.shop_matched, data.i18n.inPricelist, 'success' ) +
			statItem( stats.shop_unmatched, data.i18n.notInPricelist, 'warning' ) +
			'</div></div>';

		$( '#wc-sec-stats-wrap' ).html( html );
	}

	function statItem( value, label, type ) {
		var cls = type ? ' wc-sec-stat-item--' + type : '';
		return '<div class="wc-sec-stat-item' + cls + '">' +
			'<span class="wc-sec-stat-value">' + fmtNum( value ) + '</span>' +
			'<span class="wc-sec-stat-label">' + escHtml( label ) + '</span>' +
			'</div>';
	}

	// =========================================================================
	// Render: CSV links
	// =========================================================================

	function renderCsvLinks( url1, url2 ) {
		var html = '';
		if ( url1 ) {
			html += '<a href="' + escHtml( url1 ) + '" class="button" download>&#8595; ' + escHtml( data.i18n.downloadPricelist ) + '</a> ';
		}
		if ( url2 ) {
			html += '<a href="' + escHtml( url2 ) + '" class="button" download>&#8595; ' + escHtml( data.i18n.downloadShop ) + '</a>';
		}
		$( '#wc-sec-csv-links' ).html( html );
	}

	// =========================================================================
	// Client-side filtering + pagination (Step 4 results)
	// =========================================================================

	$( '#wc-sec-filter-search, #wc-sec-filter-status' ).on( 'input change', function () {
		state.currentPage.pricelist = 1;
		state.currentPage.shop      = 1;
		applyFiltersAndRender( 'pricelist' );
		applyFiltersAndRender( 'shop' );
	} );

	function applyFiltersAndRender( type ) {
		var search = ( $( '#wc-sec-filter-search' ).val() || '' ).toLowerCase();
		var status = $( '#wc-sec-filter-status' ).val() || '';
		var rows   = state.allRows[ type ] || [];

		var filtered = rows.filter( function ( row ) {
			// Status filter.
			if ( status ) {
				var isFound = 'pricelist' === type ? row.found : row.in_pricelist;
				if ( 'found' === status && ! isFound ) {
					return false;
				}
				if ( 'not_found' === status && isFound ) {
					return false;
				}
			}

			// Search filter.
			if ( search ) {
				var haystack = '';
				if ( 'pricelist' === type ) {
					haystack = [
						row.pricelist_name,
						row.pricelist_sku,
						row.pricelist_ean,
						row.shop_name,
						row.shop_sku,
						row.shop_ean
					].join( ' ' ).toLowerCase();
				} else {
					haystack = [
						row.shop_name,
						row.shop_sku,
						row.shop_ean
					].join( ' ' ).toLowerCase();
				}
				if ( haystack.indexOf( search ) === -1 ) {
					return false;
				}
			}

			return true;
		} );

		state.filteredRows[ type ] = filtered;
		renderResultTable( type );
		renderPagination( type, '#wc-sec-pagination-' + type );
	}

	function renderResultTable( type ) {
		var rows    = state.filteredRows[ type ] || [];
		var page    = state.currentPage[ type ] || 1;
		var offset  = ( page - 1 ) * PER_PAGE;
		var paged   = rows.slice( offset, offset + PER_PAGE );
		var $tbody  = $( '#wc-sec-tbody-' + type );

		if ( ! paged.length ) {
			var cols = 'pricelist' === type ? 8 : 5;
			$tbody.html( '<tr><td colspan="' + cols + '">No results found.</td></tr>' );
			return;
		}

		var html = '';
		$.each( paged, function ( i, row ) {
			html += buildResultRow( row, type );
		} );
		$tbody.html( html );
	}

	/**
	 * Build an edit link for a shop product name.
	 *
	 * @param {number|string} shopId   Product post ID.
	 * @param {string}        shopName Product name.
	 * @return {string} HTML anchor or plain escaped text.
	 */
	function shopNameCell( shopId, shopName ) {
		if ( shopId && data.editProductUrl ) {
			return '<a href="' + escHtml( data.editProductUrl ) + escHtml( shopId ) + '" target="_blank">' +
				escHtml( shopName ) + '</a>';
		}
		return escHtml( shopName );
	}

	function buildResultRow( row, type ) {
		if ( 'pricelist' === type ) {
			var statusBadge = row.found
				? '<span class="wc-sec-badge wc-sec-badge--found">Found</span>'
				: '<span class="wc-sec-badge wc-sec-badge--not-found">Not found</span>';

			return '<tr class="' + ( row.found ? '' : 'wc-sec-row--unmatched' ) + '">' +
				'<td>' + escHtml( row.pricelist_name ) + '</td>' +
				'<td>' + escHtml( row.pricelist_sku ) + '</td>' +
				'<td>' + escHtml( row.pricelist_ean ) + '</td>' +
				'<td>' + statusBadge + '</td>' +
				'<td>' + ( row.shop_id ? escHtml( String( row.shop_id ) ) : '' ) + '</td>' +
				'<td>' + shopNameCell( row.shop_id, row.shop_name ) + '</td>' +
				'<td>' + escHtml( row.shop_sku ) + '</td>' +
				'<td>' + escHtml( row.shop_ean ) + '</td>' +
				'</tr>';
		} else {
			var inPricelist = row.in_pricelist
				? '<span class="wc-sec-badge wc-sec-badge--in-pricelist">Yes</span>'
				: '<span class="wc-sec-badge wc-sec-badge--not-in-pricelist">No</span>';

			return '<tr class="' + ( row.in_pricelist ? '' : 'wc-sec-row--unmatched' ) + '">' +
				'<td>' + escHtml( String( row.shop_id ) ) + '</td>' +
				'<td>' + shopNameCell( row.shop_id, row.shop_name ) + '</td>' +
				'<td>' + escHtml( row.shop_sku ) + '</td>' +
				'<td>' + escHtml( row.shop_ean ) + '</td>' +
				'<td>' + inPricelist + '</td>' +
				'</tr>';
		}
	}

	function renderPagination( type, selector ) {
		var rows       = state.filteredRows[ type ] || [];
		var totalPages = Math.ceil( rows.length / PER_PAGE );
		var page       = state.currentPage[ type ] || 1;
		var $wrap      = $( selector );

		if ( totalPages <= 1 ) {
			$wrap.html(
				'<span class="wc-sec-pagination-info">' +
				fmtNum( rows.length ) + ' rows' +
				'</span>'
			);
			return;
		}

		var html = '<span class="wc-sec-pagination-info">' +
			fmtNum( rows.length ) + ' rows &nbsp;&mdash;&nbsp;</span>';

		if ( page > 1 ) {
			html += '<button type="button" class="button wc-sec-page-btn" data-type="' + type + '" data-page="' + ( page - 1 ) + '">&laquo;</button>';
		}

		var start = Math.max( 1, page - 3 );
		var end   = Math.min( totalPages, page + 3 );

		for ( var i = start; i <= end; i++ ) {
			var cls = i === page ? ' current' : '';
			html += '<button type="button" class="button wc-sec-page-btn' + cls + '" data-type="' + type + '" data-page="' + i + '">' + i + '</button>';
		}

		if ( page < totalPages ) {
			html += '<button type="button" class="button wc-sec-page-btn" data-type="' + type + '" data-page="' + ( page + 1 ) + '">&raquo;</button>';
		}

		$wrap.html( html );
	}

	$( document ).on( 'click', '.wc-sec-page-btn', function () {
		var type = $( this ).data( 'type' );
		var page = parseInt( $( this ).data( 'page' ), 10 );
		state.currentPage[ type ] = page;
		renderResultTable( type );
		renderPagination( type, '#wc-sec-pagination-' + type );
	} );

	// =========================================================================
	// New comparison button (Step 4 footer)
	// =========================================================================

	$( '#wc-sec-new-comparison-btn' ).on( 'click', function () {
		// Reset state.
		state.filename      = '';
		state.sheetIndex    = 0;
		state.sheetNames    = [];
		state.headerRow     = 0;
		state.headers       = [];
		state.previewRows   = [];
		state.brandSlugs    = [];
		state.columnMapping = { sku_columns: [], ean_columns: [], name_columns: [] };
		state.comparisonId  = 0;
		state.allRows       = { pricelist: [], shop: [] };
		state.filteredRows  = { pricelist: [], shop: [] };

		// Reset UI.
		$( '#wc-sec-file-input' ).val( '' );
		$( '#wc-sec-overwrite' ).prop( 'checked', false );
		$( '#wc-sec-overwrite-row' ).addClass( 'hidden' );
		$( '#wc-sec-sheet-selection' ).addClass( 'hidden' );
		$( '#wc-sec-header-row' ).val( '1' );
		$( '#wc-sec-step1-next' ).prop( 'disabled', true );
		$( '.wc-sec-tab-btn' ).first().click();
		hideNotice();
		goToStep( 1 );
	} );

	// =========================================================================
	// Delete comparison (history list & detail)
	// =========================================================================

	$( document ).on( 'click', '.wc-sec-delete-comparison-btn', function () {
		if ( ! window.confirm( data.i18n.confirmDelete ) ) {
			return;
		}

		var $btn       = $( this ).prop( 'disabled', true );
		var id         = $btn.data( 'id' );
		var nonce      = $btn.data( 'nonce' );
		var redirectTo = $btn.data( 'redirect' ) || '';

		$.post( data.ajaxUrl, {
			action: 'wc_sec_delete_comparison',
			nonce: nonce,
			comparison_id: id
		} ).done( function ( response ) {
			if ( response.success ) {
				if ( redirectTo ) {
					window.location.href = redirectTo;
				} else if ( response.data.history_url ) {
					window.location.href = response.data.history_url;
				} else {
					$btn.closest( 'tr' ).fadeOut( 300, function () {
						$( this ).remove();
					} );
				}
			} else {
				showNotice( escHtml( response.data.message ), 'error', '#wc-sec-history-notice' );
				$btn.prop( 'disabled', false );
			}
		} ).fail( function () {
			showNotice( escHtml( data.i18n.error ), 'error', '#wc-sec-history-notice' );
			$btn.prop( 'disabled', false );
		} );
	} );

	// =========================================================================
	// History detail page: load results via AJAX
	// =========================================================================

	var $detailId = $( '#wc-sec-detail-comparison-id' );
	if ( $detailId.length ) {
		var detailComparisonId = parseInt( $detailId.val(), 10 );
		var detailNonce        = $( '#wc-sec-detail-nonce' ).val();
		var detailState        = {
			pricelist: { page: 1, rows: [], filtered: [] },
			shop:      { page: 1, rows: [], filtered: [] }
		};

		// Load both tabs immediately.
		loadDetailResults( 'pricelist' );
		loadDetailResults( 'shop' );

		function loadDetailResults( type ) {
			$.post( data.ajaxUrl, {
				action: 'wc_sec_get_results',
				nonce: detailNonce,
				comparison_id: detailComparisonId,
				type: type,
				page: 1
			} ).done( function ( response ) {
				if ( ! response.success ) {
					$( '#wc-sec-detail-tbody-' + type ).html(
						'<tr><td colspan="8">' + escHtml( response.data.message ) + '</td></tr>'
					);
					return;
				}

				// Collect all pages.
				var allRows  = response.data.rows;
				var totalPages = response.data.total_pages;

				if ( totalPages > 1 ) {
					var promises = [];
					for ( var p = 2; p <= totalPages; p++ ) {
						promises.push( fetchDetailPage( type, p, detailNonce ) );
					}
					$.when.apply( $, promises ).done( function () {
						var extraRows = Array.from( arguments ).reduce( function ( acc, arg ) {
							// $.when with multiple deferred passes each as array [data, status, jqXHR]
							var res = arg[ 0 ] || arg;
							if ( res.success ) {
								return acc.concat( res.data.rows );
							}
							return acc;
						}, allRows );
						detailState[ type ].rows     = extraRows;
						detailState[ type ].filtered = extraRows;
						renderDetailTable( type );
						renderDetailPagination( type );
					} );
				} else {
					detailState[ type ].rows     = allRows;
					detailState[ type ].filtered = allRows;
					renderDetailTable( type );
					renderDetailPagination( type );
				}
			} ).fail( function () {
				$( '#wc-sec-detail-tbody-' + type ).html(
					'<tr><td colspan="8">' + escHtml( data.i18n.error ) + '</td></tr>'
				);
			} );
		}

		function fetchDetailPage( type, page, nonce ) {
			return $.post( data.ajaxUrl, {
				action: 'wc_sec_get_results',
				nonce: nonce,
				comparison_id: detailComparisonId,
				type: type,
				page: page
			} );
		}

		function renderDetailTable( type ) {
			var rows   = detailState[ type ].filtered;
			var page   = detailState[ type ].page;
			var offset = ( page - 1 ) * PER_PAGE;
			var paged  = rows.slice( offset, offset + PER_PAGE );
			var $tbody = $( '#wc-sec-detail-tbody-' + type );

			if ( ! paged.length ) {
				var cols = 'pricelist' === type ? 8 : 5;
				$tbody.html( '<tr><td colspan="' + cols + '">No results.</td></tr>' );
				return;
			}

			var html = '';
			$.each( paged, function ( i, row ) {
				html += buildResultRow( row, type );
			} );
			$tbody.html( html );
		}

		function renderDetailPagination( type ) {
			var rows       = detailState[ type ].filtered;
			var totalPages = Math.ceil( rows.length / PER_PAGE );
			var page       = detailState[ type ].page;
			var $wrap      = $( '#wc-sec-detail-pagination-' + type );

			if ( totalPages <= 1 ) {
				$wrap.html( '<span class="wc-sec-pagination-info">' + fmtNum( rows.length ) + ' rows</span>' );
				return;
			}

			var html = '<span class="wc-sec-pagination-info">' + fmtNum( rows.length ) + ' rows &nbsp;&mdash;&nbsp;</span>';

			if ( page > 1 ) {
				html += '<button type="button" class="button wc-sec-detail-page-btn" data-type="' + type + '" data-page="' + ( page - 1 ) + '">&laquo;</button>';
			}
			var start = Math.max( 1, page - 3 );
			var end   = Math.min( totalPages, page + 3 );
			for ( var i = start; i <= end; i++ ) {
				var cls = i === page ? ' current' : '';
				html += '<button type="button" class="button wc-sec-detail-page-btn' + cls + '" data-type="' + type + '" data-page="' + i + '">' + i + '</button>';
			}
			if ( page < totalPages ) {
				html += '<button type="button" class="button wc-sec-detail-page-btn" data-type="' + type + '" data-page="' + ( page + 1 ) + '">&raquo;</button>';
			}

			$wrap.html( html );
		}

		// Detail pagination click.
		$( document ).on( 'click', '.wc-sec-detail-page-btn', function () {
			var type = $( this ).data( 'type' );
			var page = parseInt( $( this ).data( 'page' ), 10 );
			detailState[ type ].page = page;
			renderDetailTable( type );
			renderDetailPagination( type );
		} );

		// Detail filter bar.
		$( '#wc-sec-detail-search, #wc-sec-detail-status' ).on( 'input change', function () {
			var search = ( $( '#wc-sec-detail-search' ).val() || '' ).toLowerCase();
			var status = $( '#wc-sec-detail-status' ).val() || '';

			[ 'pricelist', 'shop' ].forEach( function ( type ) {
				detailState[ type ].page    = 1;
				detailState[ type ].filtered = detailState[ type ].rows.filter( function ( row ) {
					if ( status ) {
						var isFound = 'pricelist' === type ? row.found : row.in_pricelist;
						if ( 'found' === status && ! isFound ) { return false; }
						if ( 'not_found' === status && isFound ) { return false; }
					}
					if ( search ) {
						var h = 'pricelist' === type
							? [ row.pricelist_name, row.pricelist_sku, row.pricelist_ean, row.shop_name, row.shop_sku, row.shop_ean ].join( ' ' ).toLowerCase()
							: [ row.shop_name, row.shop_sku, row.shop_ean ].join( ' ' ).toLowerCase();
						if ( h.indexOf( search ) === -1 ) { return false; }
					}
					return true;
				} );
				renderDetailTable( type );
				renderDetailPagination( type );
			} );
		} );
	}

	// =========================================================================
	// Re-run comparison (history list & detail)
	// =========================================================================

	$( document ).on( 'click', '.wc-sec-rerun-comparison-btn', function () {
		var $btn       = $( this );
		var id         = $btn.data( 'id' );
		var nonce      = $btn.data( 'nonce' );
		var redirectTo = $btn.data( 'redirect' ) || '';

		$btn.prop( 'disabled', true ).text( data.i18n.processing );
		showNotice( escHtml( data.i18n.processing ), '', '#wc-sec-detail-notice' );

		$.post( data.ajaxUrl, {
			action: 'wc_sec_rerun_comparison',
			nonce: nonce,
			comparison_id: id
		} ).done( function ( response ) {
			if ( response.success ) {
				if ( redirectTo ) {
					window.location.href = redirectTo;
				} else if ( response.data && response.data.detail_url ) {
					// On detail page: reload to show fresh stats.
					window.location.href = response.data.detail_url;
				} else {
					window.location.reload();
				}
			} else {
				var msg = response.data && response.data.message ? response.data.message : data.i18n.error;
				showNotice( escHtml( msg ), 'error', '#wc-sec-detail-notice' );
				$btn.prop( 'disabled', false ).text( data.i18n.rerun );
			}
		} ).fail( function () {
			showNotice( escHtml( data.i18n.error ), 'error', '#wc-sec-detail-notice' );
			$btn.prop( 'disabled', false ).text( data.i18n.rerun );
		} );
	} );

	// =========================================================================
	// Init
	// =========================================================================

	initFileTabs();
	initResultTabs();

}( jQuery, wcSecData ) );
