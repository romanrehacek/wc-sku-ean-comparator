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
			rules: [],
			header_row: 0,
			sheet_index: 0,
			sheet_name: ''
		},
		comparisonId: 0,
		currentTab: 'pricelist',
		currentPage: { pricelist: 1, shop: 1 },
		allRows: { pricelist: [], shop: [] },
		filteredRows: { pricelist: [], shop: [] },
		metaKeys: [],          // All product meta keys, loaded once on Step 3 entry.
		metaKeysLoaded: false  // True once the AJAX call has completed.
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
		if ( $notice[ 0 ] ) {
			$notice[ 0 ].scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
		}
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
		return $( '<span>' ).text( String( str === null || str === undefined ? '' : str ) ).html();
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

	/**
	 * Generate a default label from a shop field name.
	 *
	 * @param {string} shopField
	 * @param {string} customKey
	 * @return {string}
	 */
	function defaultLabelForField( shopField, customKey ) {
		if ( 'custom_field' === shopField ) {
			return customKey || 'custom_field';
		}
		var map = { id: 'ID', sku: 'SKU', ean: 'EAN', name: 'Name' };
		return map[ shopField ] || shopField;
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

			// Reset file/sheet selection state when switching tabs.
			state.filename   = '';
			state.sheetIndex = 0;
			state.sheetNames = [];
			$( '#wc-sec-sheet-selection' ).addClass( 'hidden' );
			$( '#wc-sec-sheet-select' ).empty();
			$( '#wc-sec-step1-next' ).prop( 'disabled', true );

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

	// =========================================================================
	// Drop zone (Step 1 upload tab)
	// =========================================================================

	/**
	 * Set drop zone to its idle / ready state.
	 */
	function dropzoneReset() {
		var $dz = $( '#wc-sec-dropzone' );
		$dz.removeClass( 'wc-sec-dropzone--uploading wc-sec-dropzone--over wc-sec-dropzone--error wc-sec-dropzone--success' );
		$dz.find( '.wc-sec-dropzone__uploading' ).addClass( 'hidden' );
		$dz.find( '.wc-sec-dropzone__success' ).addClass( 'hidden' );
		$dz.find( '.wc-sec-dropzone__body' ).removeClass( 'hidden' );
		setDropzoneProgress( 0, '' );
	}

	/**
	 * Show the success state with the uploaded filename.
	 *
	 * @param {string} filename The server-side filename.
	 */
	function dropzoneShowSuccess( filename ) {
		var $dz = $( '#wc-sec-dropzone' );
		$dz.removeClass( 'wc-sec-dropzone--uploading wc-sec-dropzone--over wc-sec-dropzone--error' );
		$dz.addClass( 'wc-sec-dropzone--success' );
		$dz.find( '.wc-sec-dropzone__uploading' ).addClass( 'hidden' );
		$dz.find( '.wc-sec-dropzone__body' ).addClass( 'hidden' );
		$( '#wc-sec-upload-success-filename' ).text( filename );
		$dz.find( '.wc-sec-dropzone__success' ).removeClass( 'hidden' );
	}

	/**
	 * Update the upload progress bar inside the drop zone.
	 *
	 * @param {number} pct   0-100
	 * @param {string} label Status text.
	 */
	function setDropzoneProgress( pct, label ) {
		$( '#wc-sec-upload-progress-fill' ).css( 'width', pct + '%' );
		$( '#wc-sec-upload-progress-label' ).text( label );
	}

	/**
	 * Start uploading a File object. Server auto-renames on conflict.
	 *
	 * @param {File} file The File to upload.
	 */
	function startUpload( file ) {
		var $dz = $( '#wc-sec-dropzone' );

		hideNotice();
		$dz.find( '.wc-sec-dropzone__body' ).addClass( 'hidden' );
		$dz.find( '.wc-sec-dropzone__uploading' ).removeClass( 'hidden' );
		$dz.addClass( 'wc-sec-dropzone--uploading' );
		setDropzoneProgress( 5, data.i18n.uploading || 'Uploading\u2026' );

		var formData = new FormData();
		formData.append( 'action', 'wc_sec_upload_file' );
		formData.append( 'nonce', data.nonce );
		formData.append( 'file', file );

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
						setDropzoneProgress( pct, data.i18n.uploading || 'Uploading\u2026' );
					}
				}, false );
				return xhr;
			}
		} ).done( function ( response ) {
			if ( ! response.success ) {
				dropzoneReset();
				showNotice( escHtml( response.data.message ), 'error' );
				return;
			}

			setDropzoneProgress( 100, data.i18n.done || 'Done!' );
			var filename   = response.data.filename;
			var ext        = filename.split( '.' ).pop().toLowerCase();
			var sheetNames = response.data.sheet_names || [];

			// Brief pause so the user sees 100% before we advance.
			setTimeout( function () {
				dropzoneShowSuccess( filename );
				selectFile( filename, ext, sheetNames );
			}, 300 );
		} ).fail( function () {
			dropzoneReset();
			showNotice( escHtml( data.i18n.error ), 'error' );
		} );
	}

	// Click anywhere in the drop zone → open native file picker.
	// We call the native DOM .click() directly to avoid the event
	// bubbling back up to the dropzone handler and causing recursion.
	$( document ).on( 'click', '#wc-sec-dropzone', function ( e ) {
		// Don't open picker if click originated from the file input itself
		// (its click event bubbles up here too).
		if ( $( e.target ).is( '#wc-sec-file-input' ) ) {
			return;
		}
		// Don't open while uploading.
		if ( $( this ).hasClass( 'wc-sec-dropzone--uploading' ) ) {
			return;
		}
		var input = document.getElementById( 'wc-sec-file-input' );
		if ( input ) {
			input.click();
		}
	} );

	// Keyboard: Enter / Space on the drop zone.
	$( document ).on( 'keydown', '#wc-sec-dropzone', function ( e ) {
		if ( e.which === 13 || e.which === 32 ) {
			e.preventDefault();
			var input = document.getElementById( 'wc-sec-file-input' );
			if ( input ) {
				input.click();
			}
		}
	} );

	// File chosen via browser picker → auto-upload.
	$( document ).on( 'change', '#wc-sec-file-input', function () {
		var files = this.files;
		if ( ! files || ! files.length ) {
			return;
		}
		var file = files[ 0 ];
		// Reset so the same file can be chosen again after an error.
		$( this ).val( '' );
		startUpload( file );
	} );

	// Drag-over: highlight drop zone.
	$( document ).on( 'dragover dragenter', '#wc-sec-dropzone', function ( e ) {
		e.preventDefault();
		e.stopPropagation();
		$( this ).addClass( 'wc-sec-dropzone--over' );
	} );

	$( document ).on( 'dragleave drop', '#wc-sec-dropzone', function ( e ) {
		e.preventDefault();
		e.stopPropagation();
		$( this ).removeClass( 'wc-sec-dropzone--over' );
	} );

	// Drop: extract file and auto-upload.
	$( document ).on( 'drop', '#wc-sec-dropzone', function ( e ) {
		var files = e.originalEvent.dataTransfer && e.originalEvent.dataTransfer.files;
		if ( ! files || ! files.length ) {
			return;
		}
		startUpload( files[ 0 ] );
	} );

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

		if ( 1 === sheetNames.length ) {
			// Only one sheet — pre-select it automatically, no user action needed.
			$select.append( '<option value="0">' + escHtml( sheetNames[ 0 ] ) + '</option>' );
			$select.val( '0' );
			state.sheetIndex = 0;
			$( '#wc-sec-sheet-selection' ).addClass( 'hidden' );
			$( '#wc-sec-step1-next' ).prop( 'disabled', false );
		} else {
			// Multiple sheets — require explicit selection.
			$select.append( '<option value="">-- Select a sheet --</option>' );
			$.each( sheetNames, function ( idx, name ) {
				$select.append( '<option value="' + idx + '">' + escHtml( name ) + '</option>' );
			} );
			$( '#wc-sec-sheet-selection' ).removeClass( 'hidden' );
			$( '#wc-sec-step1-next' ).prop( 'disabled', true );
		}
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
		loadMetaKeys(); // Pre-load meta keys for the custom-field picker.
	} );

	// Step back buttons.
	$( document ).on( 'click', '.wc-sec-prev-btn', function () {
		var target = parseInt( $( this ).data( 'target' ), 10 );
		goToStep( target );
	} );

	// =========================================================================
	// Step 3: Column mapping (rules-based)
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
			renderRulesUI( state.headers );

			$( '#wc-sec-preview-wrap' ).removeClass( 'hidden' );
			$( '#wc-sec-mapping-wrap' ).removeClass( 'hidden' );
		} ).fail( function () {
			showNotice( escHtml( data.i18n.error ), 'error' );
		} );
	}

	/**
	 * Load all product meta keys from the server once and store in state.metaKeys.
	 * Called when Step 3 is entered. If already loaded, does nothing.
	 * After loading, repopulates any visible custom-key <select> elements.
	 */
	function loadMetaKeys() {
		if ( state.metaKeysLoaded ) {
			return;
		}
		$.post( data.ajaxUrl, {
			action: 'wc_sec_get_meta_keys',
			nonce:  data.nonce,
			search: ''
		} ).done( function ( response ) {
			if ( response.success && Array.isArray( response.data ) ) {
				state.metaKeys       = response.data; // [{id, text}, ...]
				state.metaKeysLoaded = true;
				// Repopulate any custom-key selects that are already rendered.
				repopulateCustomKeySelects();
			}
		} );
	}

	/**
	 * Fill every .wc-sec-rule-custom-key <select> with options from state.metaKeys.
	 * Preserves the currently selected value. Called once after meta keys load.
	 */
	function repopulateCustomKeySelects() {
		$( '.wc-sec-rule-custom-key' ).each( function () {
			var $select     = $( this );
			var fn          = $.fn.selectWoo || $.fn.select2;
			var currentVal  = $select.val() || '';

			// Destroy existing instance before touching the DOM.
			if ( fn ) {
				try { fn.call( $select, 'destroy' ); } catch ( e ) {}
			}

			// Rebuild options.
			$select.empty();
			$select.append( '<option value=""></option>' );
			$.each( state.metaKeys, function ( i, item ) {
				var selected = ( item.id === currentVal ) ? ' selected' : '';
				$select.append(
					'<option value="' + escHtml( item.id ) + '"' + selected + '>' +
					escHtml( item.text ) + '</option>'
				);
			} );

			// Re-init selectWoo only if the wrapper is visible.
			if ( fn && $select.closest( '.wc-sec-rule-custom-key-wrap' ).not( '.hidden' ).length ) {
				fn.call( $select, { width: '100%', allowClear: true, placeholder: '' } );
			}
		} );
	}

	// Reload columns when user manually changes header row number.
	$( document ).on( 'click', '#wc-sec-reload-columns-btn', function () {
		// Reset rules when reloading columns.
		state.columnMapping.rules = [];
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

	// =========================================================================
	// Rules UI
	// =========================================================================

	/**
	 * Re-render all rule cards from state.columnMapping.rules.
	 *
	 * @param {string[]} [headers] Pricelist headers; uses state.headers if omitted.
	 */
	function renderRulesUI( headers ) {
		var hdrs = headers || state.headers;
		var $container = $( '#wc-sec-rules-container' );
		$container.empty();

		$.each( state.columnMapping.rules, function ( idx, rule ) {
			$container.append( buildRuleCard( rule, idx, hdrs ) );
		} );

		// Init Select2 only on custom-key fields that are currently visible
		// (i.e. rules where shop_field === 'custom_field').
		$container.find( '.wc-sec-rule-card' ).each( function () {
			var $card = $( this );
			var idx   = parseInt( $card.data( 'rule-idx' ), 10 );
			var rule  = state.columnMapping.rules[ idx ];
			if ( rule && 'custom_field' === rule.shop_field ) {
				initSelect2ForRule( $card.find( '.wc-sec-rule-custom-key' ) );
			}
		} );

		validateColumnMapping();
	}

	/**
	 * Build HTML for a single rule card.
	 *
	 * @param {Object}   rule
	 * @param {number}   idx
	 * @param {string[]} headers
	 * @return {string}
	 */
	function buildRuleCard( rule, idx, headers ) {
		var shopField  = rule.shop_field || 'sku';
		var label      = rule.label || defaultLabelForField( shopField, rule.custom_key || '' );
		var customKey  = rule.custom_key || '';
		var selCols    = rule.pricelist_columns || [];
		var isCustom   = ( 'custom_field' === shopField );
		var total      = state.columnMapping.rules.length;

		// Field select options.
		var fieldOptions = [
			{ value: 'sku',          text: 'SKU' },
			{ value: 'ean',          text: 'EAN' },
			{ value: 'name',         text: 'Name' },
			{ value: 'id',           text: 'ID' },
			{ value: 'custom_field', text: 'Custom field (meta key)' }
		];
		var fieldHtml = '';
		$.each( fieldOptions, function ( i, opt ) {
			fieldHtml += '<option value="' + escHtml( opt.value ) + '"' +
				( shopField === opt.value ? ' selected' : '' ) + '>' +
				escHtml( opt.text ) + '</option>';
		} );

		// Column checkboxes.
		var colHtml = '';
		if ( headers.length ) {
			$.each( headers, function ( colIdx, colName ) {
				var checked = ( selCols.indexOf( colIdx ) !== -1 ) ? ' checked' : '';
				colHtml += '<label class="wc-sec-column-toggle' + ( checked ? ' selected' : '' ) + '">' +
					'<input type="checkbox" class="wc-sec-rule-column-check"' +
					' value="' + colIdx + '"' + checked + '>' +
					escHtml( colName ) +
					'</label>';
			} );
		} else {
			colHtml = '<em>No columns loaded.</em>';
		}

		var html =
			'<div class="wc-sec-rule-card" data-rule-idx="' + idx + '">' +
				'<div class="wc-sec-rule-card__header">' +
					'<span class="wc-sec-rule-card__num">' + escHtml( String( idx + 1 ) ) + '</span>' +
					'<span class="wc-sec-rule-card__title">' + escHtml( label ) + '</span>' +
					'<span class="wc-sec-rule-order-btns">' +
						'<button type="button" class="button button-small wc-sec-rule-up-btn"' +
							( idx === 0 ? ' disabled' : '' ) + ' title="Move up">&#9650;</button>' +
						'<button type="button" class="button button-small wc-sec-rule-down-btn"' +
							( idx === total - 1 ? ' disabled' : '' ) + ' title="Move down">&#9660;</button>' +
					'</span>' +
					'<button type="button" class="button button-small wc-sec-rule-remove-btn" title="Remove rule">&#10005;</button>' +
				'</div>' +
				'<div class="wc-sec-rule-card__body">' +
					'<div class="wc-sec-rule-row">' +
						'<label class="wc-sec-rule-field-label">Shop field</label>' +
						'<select class="wc-sec-rule-shopfield">' + fieldHtml + '</select>' +
					'</div>' +
					'<div class="wc-sec-rule-custom-key-wrap' + ( isCustom ? '' : ' hidden' ) + '">' +
						'<label class="wc-sec-rule-field-label">Meta key</label>' +
						'<select class="wc-sec-rule-custom-key" style="min-width:220px;">' +
							( function () {
								var opts = '<option value=""></option>';
								if ( state.metaKeysLoaded ) {
									$.each( state.metaKeys, function ( i, item ) {
										var sel = ( item.id === customKey ) ? ' selected' : '';
										opts += '<option value="' + escHtml( item.id ) + '"' + sel + '>' +
											escHtml( item.text ) + '</option>';
									} );
								} else if ( customKey ) {
									opts += '<option value="' + escHtml( customKey ) + '" selected>' +
										escHtml( customKey ) + '</option>';
								}
								return opts;
							}() ) +
						'</select>' +
					'</div>' +
					'<div class="wc-sec-rule-row">' +
						'<label class="wc-sec-rule-field-label">Label</label>' +
						'<input type="text" class="regular-text wc-sec-rule-label" value="' + escHtml( label ) + '">' +
					'</div>' +
					'<div class="wc-sec-rule-row">' +
						'<label class="wc-sec-rule-field-label">Pricelist columns</label>' +
						'<div class="wc-sec-column-selector wc-sec-rule-columns-wrap">' + colHtml + '</div>' +
					'</div>' +
				'</div>' +
			'</div>';

		return html;
	}

	/**
	 * Initialise selectWoo / Select2 on the custom-key <select> inside a rule card.
	 * Options are pre-populated from state.metaKeys — no AJAX needed here.
	 * Must be called after the element is visible in the DOM.
	 *
	 * @param {jQuery} $select The .wc-sec-rule-custom-key element.
	 */
	function initSelect2ForRule( $select ) {
		// Prefer WooCommerce's selectWoo; fall back to plain select2.
		var fn = $.fn.selectWoo || $.fn.select2;
		if ( typeof fn !== 'function' ) {
			return;
		}

		// Destroy any previous instance to avoid double-init.
		try { fn.call( $select, 'destroy' ); } catch ( e ) {}

		// Options are already in the <select> DOM (from state.metaKeys).
		// selectWoo provides local search/filter automatically.
		fn.call( $select, {
			width: '100%',
			allowClear: true,
			placeholder: 'Select or type a meta key...'
		} );
	}

	/**
	 * Read all inputs from a rule card DOM element back into state.
	 *
	 * @param {jQuery} $card     The .wc-sec-rule-card element.
	 * @param {number} idx       Rule index in state.columnMapping.rules.
	 */
	function syncRuleFromCard( $card, idx ) {
		if ( ! state.columnMapping.rules[ idx ] ) {
			return;
		}
		var rule      = state.columnMapping.rules[ idx ];
		var shopField = $card.find( '.wc-sec-rule-shopfield' ).val();
		var label     = $card.find( '.wc-sec-rule-label' ).val();
		var customKey = $card.find( '.wc-sec-rule-custom-key' ).val() || '';
		var cols      = [];
		$card.find( '.wc-sec-rule-column-check:checked' ).each( function () {
			cols.push( parseInt( $( this ).val(), 10 ) );
		} );

		rule.shop_field = shopField;
		rule.label      = label;
		rule.custom_key = ( 'custom_field' === shopField ) ? customKey : null;
		rule.pricelist_columns = cols;
	}

	/**
	 * Add a new default rule and re-render the rules UI.
	 */
	function addRule() {
		syncAllRulesFromDOM();
		state.columnMapping.rules.push( {
			shop_field: 'sku',
			custom_key: null,
			label: 'SKU',
			pricelist_columns: [],
			pricelist_column_names: []
		} );
		renderRulesUI();
	}

	/**
	 * Remove a rule at the given index.
	 *
	 * @param {number} idx
	 */
	function removeRule( idx ) {
		syncAllRulesFromDOM();
		state.columnMapping.rules.splice( idx, 1 );
		renderRulesUI();
	}

	/**
	 * Move a rule up (swap with the one above).
	 *
	 * @param {number} idx
	 */
	function moveRuleUp( idx ) {
		if ( idx <= 0 ) { return; }
		var tmp = state.columnMapping.rules[ idx - 1 ];
		state.columnMapping.rules[ idx - 1 ] = state.columnMapping.rules[ idx ];
		state.columnMapping.rules[ idx ]      = tmp;
		renderRulesUI();
	}

	/**
	 * Move a rule down (swap with the one below).
	 *
	 * @param {number} idx
	 */
	function moveRuleDown( idx ) {
		var last = state.columnMapping.rules.length - 1;
		if ( idx >= last ) { return; }
		var tmp = state.columnMapping.rules[ idx + 1 ];
		state.columnMapping.rules[ idx + 1 ] = state.columnMapping.rules[ idx ];
		state.columnMapping.rules[ idx ]      = tmp;
		renderRulesUI();
	}

	// -------------------------------------------------------------------------
	// Rule UI event delegation
	// -------------------------------------------------------------------------

	// Add rule button.
	$( document ).on( 'click', '#wc-sec-add-rule-btn', function () {
		addRule();
	} );

	// Remove rule button.
	$( document ).on( 'click', '.wc-sec-rule-remove-btn', function () {
		var $card = $( this ).closest( '.wc-sec-rule-card' );
		var idx   = parseInt( $card.data( 'rule-idx' ), 10 );
		removeRule( idx );
	} );

	// Move up button.
	$( document ).on( 'click', '.wc-sec-rule-up-btn', function () {
		var $card = $( this ).closest( '.wc-sec-rule-card' );
		var idx   = parseInt( $card.data( 'rule-idx' ), 10 );
		syncAllRulesFromDOM();
		moveRuleUp( idx );
	} );

	// Move down button.
	$( document ).on( 'click', '.wc-sec-rule-down-btn', function () {
		var $card = $( this ).closest( '.wc-sec-rule-card' );
		var idx   = parseInt( $card.data( 'rule-idx' ), 10 );
		syncAllRulesFromDOM();
		moveRuleDown( idx );
	} );

	// Shop field change: show/hide custom key input, auto-update label if pristine.
	$( document ).on( 'change', '.wc-sec-rule-shopfield', function () {
		var $card     = $( this ).closest( '.wc-sec-rule-card' );
		var idx       = parseInt( $card.data( 'rule-idx' ), 10 );
		var shopField = $( this ).val();
		var isCustom  = ( 'custom_field' === shopField );
		var $wrap     = $card.find( '.wc-sec-rule-custom-key-wrap' );

		$wrap.toggleClass( 'hidden', ! isCustom );

		// Init Select2 on the custom-key field the first time it becomes visible.
		if ( isCustom ) {
			var $customKey = $card.find( '.wc-sec-rule-custom-key' );
			// Only init if not already initialised (no select2 data attached).
			if ( ! $customKey.data( 'select2' ) && ! $customKey.data( 'selectWoo' ) ) {
				initSelect2ForRule( $customKey );
			}
		}

		// Auto-update label only if it still matches the previous auto-label
		// (i.e. the user hasn't typed a custom label yet).
		var rule = state.columnMapping.rules[ idx ];
		if ( rule ) {
			var prevAutoLabel = defaultLabelForField( rule.shop_field, rule.custom_key || '' );
			var currentLabel  = $card.find( '.wc-sec-rule-label' ).val();
			if ( currentLabel === prevAutoLabel ) {
				var newLabel = defaultLabelForField( shopField, $card.find( '.wc-sec-rule-custom-key' ).val() || '' );
				$card.find( '.wc-sec-rule-label' ).val( newLabel );
				$card.find( '.wc-sec-rule-card__title' ).text( newLabel );
			}
			rule.shop_field = shopField;
			if ( ! isCustom ) {
				rule.custom_key = null;
			}
		}
		syncRuleFromCard( $card, idx );
		validateColumnMapping();
	} );

	// Label input.
	$( document ).on( 'input', '.wc-sec-rule-label', function () {
		var $card = $( this ).closest( '.wc-sec-rule-card' );
		var idx   = parseInt( $card.data( 'rule-idx' ), 10 );
		var label = $( this ).val();
		$card.find( '.wc-sec-rule-card__title' ).text( label );
		if ( state.columnMapping.rules[ idx ] ) {
			state.columnMapping.rules[ idx ].label = label;
		}
	} );

	// Column checkbox change.
	$( document ).on( 'change', '.wc-sec-rule-column-check', function () {
		var $card   = $( this ).closest( '.wc-sec-rule-card' );
		var idx     = parseInt( $card.data( 'rule-idx' ), 10 );
		$( this ).closest( '.wc-sec-column-toggle' ).toggleClass( 'selected', $( this ).is( ':checked' ) );
		syncRuleFromCard( $card, idx );
		validateColumnMapping();
	} );

	// Select2 custom key change.
	$( document ).on( 'change', '.wc-sec-rule-custom-key', function () {
		var $card = $( this ).closest( '.wc-sec-rule-card' );
		var idx   = parseInt( $card.data( 'rule-idx' ), 10 );
		var key   = $( this ).val() || '';

		if ( state.columnMapping.rules[ idx ] ) {
			state.columnMapping.rules[ idx ].custom_key = key || null;

			// Auto-update label if it still looks like an auto-generated one.
			var rule          = state.columnMapping.rules[ idx ];
			var prevAutoLabel = defaultLabelForField( 'custom_field', '' );
			var currentLabel  = $card.find( '.wc-sec-rule-label' ).val();
			if ( ! currentLabel || currentLabel === prevAutoLabel || currentLabel === rule.label ) {
				var newLabel = defaultLabelForField( 'custom_field', key );
				$card.find( '.wc-sec-rule-label' ).val( newLabel );
				$card.find( '.wc-sec-rule-card__title' ).text( newLabel );
				rule.label = newLabel;
			}
		}
		validateColumnMapping();
	} );

	/**
	 * Sync all rule cards back to state before an operation that re-renders
	 * (e.g., reorder), so user input isn't lost.
	 */
	function syncAllRulesFromDOM() {
		$( '.wc-sec-rule-card' ).each( function () {
			var $card = $( this );
			var idx   = parseInt( $card.data( 'rule-idx' ), 10 );
			syncRuleFromCard( $card, idx );
		} );
	}

	function validateColumnMapping() {
		var rules = state.columnMapping.rules;
		var valid = rules.length > 0;
		if ( valid ) {
			valid = rules.every( function ( rule ) {
				if ( ! rule.pricelist_columns || rule.pricelist_columns.length === 0 ) {
					return false;
				}
				if ( 'custom_field' === rule.shop_field && ! rule.custom_key ) {
					return false;
				}
				return true;
			} );
		}
		$( '#wc-sec-step3-next' ).prop( 'disabled', ! valid );
	}

	// Step 3 → 4 (start comparison).
	$( '#wc-sec-step3-next' ).on( 'click', function () {
		syncAllRulesFromDOM();

		var rules = state.columnMapping.rules;
		if ( ! rules.length ) {
			showNotice( 'Please add at least one mapping rule.', 'error' );
			return;
		}
		var allHaveCols = rules.every( function ( r ) {
			return r.pricelist_columns && r.pricelist_columns.length > 0;
		} );
		if ( ! allHaveCols ) {
			showNotice( 'Each rule must have at least one pricelist column selected.', 'error' );
			return;
		}

		// Persist sheet info into the mapping object.
		state.columnMapping.header_row  = state.headerRow;
		state.columnMapping.sheet_index = state.sheetIndex;
		state.columnMapping.sheet_name  = ( state.sheetNames && state.sheetIndex < state.sheetNames.length )
			? state.sheetNames[ state.sheetIndex ] : '';

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

			var comparisonId = response.data.comparison_id;

			// Redirect to the history detail page instead of rendering results inline.
			if ( data.historyDetailUrl && comparisonId ) {
				window.location.href = data.historyDetailUrl.replace( '__ID__', comparisonId );
				return;
			}

			// Fallback (should not normally be reached): render inline.
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

				// Render dynamic table headers.
				renderTableHeaders( 'pricelist', state.columnMapping.rules, false );
				renderTableHeaders( 'shop', state.columnMapping.rules, false );

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
	// Render: dynamic table headers
	// =========================================================================

	/**
	 * Render <th> elements into the empty thead row for a results table.
	 *
	 * @param {string}   type     'pricelist' | 'shop'
	 * @param {Array}    rules    Array of rule objects.
	 * @param {boolean}  isDetail True when rendering on the history-detail page.
	 */
	function renderTableHeaders( type, rules, isDetail ) {
		var prefix   = isDetail ? '#wc-sec-detail-thead-' : '#wc-sec-thead-';
		var $tr      = $( prefix + type );

		if ( 'pricelist' === type ) {
			// Pricelist value columns (one per rule, labelled from pricelist side).
			$.each( rules, function ( i, rule ) {
				$tr.append( '<th>' + escHtml( 'Pricelist: ' + ( rule.label || ( 'Rule ' + ( i + 1 ) ) ) ) + '</th>' );
			} );
			$tr.append( '<th>Status</th>' );
			$tr.append( '<th>Shop ID</th>' );
			$tr.append( '<th>Shop Name</th>' );
			// Shop value columns (one per rule, labelled from shop side).
			$.each( rules, function ( i, rule ) {
				$tr.append( '<th>' + escHtml( 'Shop: ' + ( rule.label || ( 'Rule ' + ( i + 1 ) ) ) ) + '</th>' );
			} );
			$tr.append( '<th>Matched by</th>' );
		} else {
			$tr.append( '<th>Shop ID</th>' );
			$tr.append( '<th>Shop Name</th>' );
			$.each( rules, function ( i, rule ) {
				$tr.append( '<th>' + escHtml( rule.label || ( 'Rule ' + ( i + 1 ) ) ) + '</th>' );
			} );
			$tr.append( '<th>In Pricelist</th>' );
			$tr.append( '<th>Matched by</th>' );
		}
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

	/**
	 * Build a search haystack string from a result row using dynamic rules.
	 *
	 * @param {Object}  row
	 * @param {string}  type   'pricelist' | 'shop'
	 * @param {Array}   rules
	 * @return {string}
	 */
	function buildHaystack( row, type, rules ) {
		var parts = [];
		if ( 'pricelist' === type ) {
			parts.push( row.shop_name || '' );
			$.each( rules, function ( i ) {
				parts.push( row[ 'pricelist_rule_' + i ] || '' );
				parts.push( row[ 'shop_rule_' + i ] || '' );
			} );
		} else {
			parts.push( row.shop_name || '' );
			$.each( rules, function ( i ) {
				parts.push( row[ 'shop_rule_' + i ] || '' );
			} );
		}
		return parts.join( ' ' ).toLowerCase();
	}

	function applyFiltersAndRender( type ) {
		var search = ( $( '#wc-sec-filter-search' ).val() || '' ).toLowerCase();
		var status = $( '#wc-sec-filter-status' ).val() || '';
		var rows   = state.allRows[ type ] || [];
		var rules  = state.columnMapping.rules || [];

		var filtered = rows.filter( function ( row ) {
			// Status filter.
			if ( status ) {
				var isFound = 'pricelist' === type ? row.found : row.in_pricelist;
				if ( 'found' === status && ! isFound ) { return false; }
				if ( 'not_found' === status && isFound ) { return false; }
			}
			// Search filter.
			if ( search ) {
				var haystack = buildHaystack( row, type, rules );
				if ( haystack.indexOf( search ) === -1 ) { return false; }
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
		var rules   = state.columnMapping.rules || [];

		if ( ! paged.length ) {
			var cols = 4 + rules.length * 2 + ( 'pricelist' === type ? 1 : 0 );
			$tbody.html( '<tr><td colspan="' + cols + '">No results found.</td></tr>' );
			return;
		}

		var html = '';
		$.each( paged, function ( i, row ) {
			html += buildResultRow( row, type, rules );
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

	/**
	 * Build a single <tr> HTML string for either result type.
	 *
	 * @param {Object}  row
	 * @param {string}  type   'pricelist' | 'shop'
	 * @param {Array}   rules
	 * @return {string}
	 */
	function buildResultRow( row, type, rules ) {
		var rulesArr = rules || [];

		if ( 'pricelist' === type ) {
			var statusBadge = row.found
				? '<span class="wc-sec-badge wc-sec-badge--found">Found</span>'
				: '<span class="wc-sec-badge wc-sec-badge--not-found">Not found</span>';

			var matchedRule = ( typeof row.matched_rule_index === 'number' && row.matched_rule_index >= 0 )
				? escHtml( ( rulesArr[ row.matched_rule_index ] || {} ).label || ( 'Rule ' + ( row.matched_rule_index + 1 ) ) )
				: '&mdash;';

			var html = '<tr class="' + ( row.found ? '' : 'wc-sec-row--unmatched' ) + '">';
			// Pricelist value per rule.
			$.each( rulesArr, function ( i ) {
				html += '<td>' + escHtml( row[ 'pricelist_rule_' + i ] || '' ) + '</td>';
			} );
			html += '<td>' + statusBadge + '</td>';
			html += '<td>' + ( row.shop_id ? escHtml( String( row.shop_id ) ) : '' ) + '</td>';
			html += '<td>' + shopNameCell( row.shop_id, row.shop_name ) + '</td>';
			// Shop value per rule.
			$.each( rulesArr, function ( i ) {
				html += '<td>' + escHtml( row[ 'shop_rule_' + i ] || '' ) + '</td>';
			} );
			html += '<td>' + matchedRule + '</td>';
			html += '</tr>';
			return html;

		} else {
			var inPricelist = row.in_pricelist
				? '<span class="wc-sec-badge wc-sec-badge--in-pricelist">Yes</span>'
				: '<span class="wc-sec-badge wc-sec-badge--not-in-pricelist">No</span>';

			var matchedRuleShop = ( typeof row.matched_rule_index === 'number' && row.matched_rule_index >= 0 )
				? escHtml( ( rulesArr[ row.matched_rule_index ] || {} ).label || ( 'Rule ' + ( row.matched_rule_index + 1 ) ) )
				: '&mdash;';

			var shopHtml = '<tr class="' + ( row.in_pricelist ? '' : 'wc-sec-row--unmatched' ) + '">';
			shopHtml += '<td>' + escHtml( String( row.shop_id ) ) + '</td>';
			shopHtml += '<td>' + shopNameCell( row.shop_id, row.shop_name ) + '</td>';
			$.each( rulesArr, function ( i ) {
				shopHtml += '<td>' + escHtml( row[ 'shop_rule_' + i ] || '' ) + '</td>';
			} );
			shopHtml += '<td>' + inPricelist + '</td>';
			shopHtml += '<td>' + matchedRuleShop + '</td>';
			shopHtml += '</tr>';
			return shopHtml;
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
		state.columnMapping = { rules: [], header_row: 0, sheet_index: 0, sheet_name: '' };
		state.comparisonId  = 0;
		state.allRows       = { pricelist: [], shop: [] };
		state.filteredRows  = { pricelist: [], shop: [] };

		// Reset UI.
		$( '#wc-sec-file-input' ).val( '' );
		dropzoneReset();
		$( '#wc-sec-sheet-selection' ).addClass( 'hidden' );
		$( '#wc-sec-header-row' ).val( '0' );
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
		var detailRulesRaw     = $( '#wc-sec-detail-rules' ).val() || '[]';
		var detailRules        = [];
		try {
			detailRules = JSON.parse( detailRulesRaw );
		} catch ( e ) {
			detailRules = [];
		}

		var detailState = {
			pricelist: { page: 1, rows: [], filtered: [] },
			shop:      { page: 1, rows: [], filtered: [] }
		};

		// Render table headers from stored rules.
		renderTableHeaders( 'pricelist', detailRules, true );
		renderTableHeaders( 'shop', detailRules, true );

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
				var allRows    = response.data.rows;
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
				var cols = 4 + detailRules.length * 2 + ( 'pricelist' === type ? 1 : 0 );
				$tbody.html( '<tr><td colspan="' + cols + '">No results.</td></tr>' );
				return;
			}

			var html = '';
			$.each( paged, function ( i, row ) {
				html += buildResultRow( row, type, detailRules );
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
						var h = buildHaystack( row, type, detailRules );
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
