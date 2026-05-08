/**
 * Object Sync for Salesforce — Bulk Sync Admin JavaScript (Carkeek fork)
 *
 * Handles all AJAX interactions for the Bulk Sync tab:
 * - Bulk Pull by Salesforce ID (with CSV upload)
 * - Quick Resync Pull (SF IDs) and Push (WP Post IDs)
 * - Browse mapped records table (paginated, filterable)
 * - Row selection and batch resync from browse table
 */
(function ( $ ) {
	'use strict';

	/** Maximum Salesforce IDs to send per AJAX request (avoids PHP timeouts). */
	var SF_BATCH_SIZE = 10;

	var osfBulkSync = {

		// Current browse table state.
		browsePage: 1,
		browsePerPage: 25,
		browseTotal: 0,
		browseTotalPages: 0,

		/**
		 * Initialise all event bindings.
		 */
		init: function () {
			// Bulk Pull.
			$( '#csfbs-bulk-pull-btn' ).on( 'click', osfBulkSync.doBulkPull );
			$( '#csfbs-bulk-pull-file' ).on( 'change', osfBulkSync.handleFileUpload );

			// Quick Resync.
			$( '#csfbs-resync-pull-btn' ).on( 'click', osfBulkSync.doResyncPull );
			$( '#csfbs-resync-push-btn' ).on( 'click', osfBulkSync.doResyncPush );

			// Browse table.
			$( '#csfbs-browse-load-btn' ).on( 'click', function () {
				osfBulkSync.browsePage = 1;
				osfBulkSync.loadBrowseTable();
			} );
			$( document ).on( 'keypress', '#csfbs-browse-search', function ( e ) {
				if ( 13 === e.which ) {
					osfBulkSync.browsePage = 1;
					osfBulkSync.loadBrowseTable();
				}
			} );
			$( '#csfbs-browse-prev' ).on( 'click', function () {
				if ( osfBulkSync.browsePage > 1 ) {
					osfBulkSync.browsePage--;
					osfBulkSync.loadBrowseTable();
				}
			} );
			$( '#csfbs-browse-next' ).on( 'click', function () {
				if ( osfBulkSync.browsePage < osfBulkSync.browseTotalPages ) {
					osfBulkSync.browsePage++;
					osfBulkSync.loadBrowseTable();
				}
			} );

			// Select all / deselect all.
			$( '#csfbs-select-all' ).on( 'change', function () {
				$( '.csfbs-row-check' ).prop( 'checked', $( this ).prop( 'checked' ) );
				osfBulkSync.updateBrowseActionButtons();
			} );
			$( document ).on( 'change', '.csfbs-row-check', osfBulkSync.updateBrowseActionButtons );

			// Browse action buttons.
			$( '#csfbs-browse-repull-btn' ).on( 'click', osfBulkSync.doBrowseRepull );
			$( '#csfbs-browse-repush-btn' ).on( 'click', osfBulkSync.doBrowseRepush );
		},

		// ─── Utilities ──────────────────────────────────────────────────────────

		/**
		 * Parse IDs from a textarea value (newline or comma-separated).
		 *
		 * @param {string} raw Raw textarea content.
		 * @returns {string[]}
		 */
		parseIds: function ( raw ) {
			return raw
				.split( /[\r\n,]+/ )
				.map( function ( id ) { return id.trim(); } )
				.filter( function ( id ) { return id.length > 0; } );
		},

		/**
		 * Build a results table row using DOM methods (no innerHTML injection).
		 *
		 * @param {string} id      Record ID (SF or WP).
		 * @param {string} type    Object type label.
		 * @param {string} status  'success' | 'error' | any pending label.
		 * @param {string} message Human-readable message.
		 * @returns {jQuery}
		 */
		buildResultRow: function ( id, type, status, message ) {
			var $span = $( '<span>' );
			if ( 'success' === status ) {
				$span.addClass( 'csfbs-status csfbs-status-success' )
					.html( '&#10003; ' + osfBulkSyncData.strings.success );
			} else if ( 'error' === status ) {
				$span.addClass( 'csfbs-status csfbs-status-error' )
					.html( '&#10007; ' + osfBulkSyncData.strings.error );
			} else {
				$span.addClass( 'csfbs-status csfbs-status-pending' )
					.text( '• ' + status );
			}

			var rowClass = ( 'success' === status || 'error' === status ) ? status : 'pending';

			return $( '<tr>' )
				.addClass( 'csfbs-result-row csfbs-result-' + rowClass )
				.append( $( '<td>' ).text( id ) )
				.append( $( '<td>' ).text( type || osfBulkSyncData.strings.noObjectType ) )
				.append( $( '<td>' ).append( $span ) )
				.append( $( '<td>' ).text( message ) );
		},

		/**
		 * Show or hide a spinner.
		 *
		 * @param {jQuery}  $spinner Spinner element.
		 * @param {boolean} show     Whether to show.
		 */
		toggleSpinner: function ( $spinner, show ) {
			$spinner.toggleClass( 'is-active', show );
		},

		// ─── Bulk Pull ───────────────────────────────────────────────────────────

		/**
		 * Handle bulk pull button click.
		 */
		doBulkPull: function () {
			var ids = osfBulkSync.parseIds( $( '#csfbs-bulk-pull-ids' ).val() );
			if ( ! ids.length ) {
				alert( osfBulkSyncData.strings.noIds );
				return;
			}
			osfBulkSync.doSfPull(
				ids,
				'osf_bulk_pull',
				$( '#csfbs-bulk-pull-tbody' ),
				$( '#csfbs-bulk-pull-results' ),
				$( '#csfbs-bulk-pull-btn' ).siblings( '.csfbs-spinner' ),
				$( '#csfbs-bulk-pull-btn' )
			);
		},

		/**
		 * Handle CSV / text file upload for bulk pull.
		 *
		 * @param {Event} e File input change event.
		 */
		handleFileUpload: function ( e ) {
			var file = e.target.files && e.target.files[0];
			if ( ! file ) {
				return;
			}

			var reader = new FileReader();
			reader.onload = function ( evt ) {
				var contents = evt.target.result;
				var lines = contents.split( /[\r\n]+/ );
				var ids   = [];
				$.each( lines, function ( i, line ) {
					var col = line.split( ',' )[0].trim().replace( /^["']|["']$/g, '' );
					if ( col && /^[a-zA-Z0-9]{15,18}$/.test( col ) ) {
						ids.push( col );
					}
				} );
				$( '#csfbs-bulk-pull-ids' ).val( ids.join( '\n' ) );
			};
			reader.readAsText( file );
			$( this ).val( '' );
		},

		// ─── Quick Resync Pull ────────────────────────────────────────────────────

		/**
		 * Handle re-pull button click.
		 */
		doResyncPull: function () {
			var ids = osfBulkSync.parseIds( $( '#csfbs-resync-pull-ids' ).val() );
			if ( ! ids.length ) {
				alert( osfBulkSyncData.strings.noIds );
				return;
			}
			osfBulkSync.doSfPull(
				ids,
				'osf_resync_pull',
				$( '#csfbs-resync-pull-tbody' ),
				$( '#csfbs-resync-pull-results' ),
				$( '.csfbs-spinner-repull' ),
				$( '#csfbs-resync-pull-btn' )
			);
		},

		// ─── Shared SF Pull (batched) ─────────────────────────────────────────────

		/**
		 * Pull Salesforce records in batches to avoid PHP timeouts.
		 *
		 * @param {string[]} ids     Salesforce IDs to process.
		 * @param {string}   action  AJAX action.
		 * @param {jQuery}   $tbody  Results tbody element.
		 * @param {jQuery}   $wrap   Results wrapper element (shown on first batch).
		 * @param {jQuery}   $spinner Spinner element.
		 * @param {jQuery}   $btn    Trigger button (disabled during processing).
		 */
		doSfPull: function ( ids, action, $tbody, $wrap, $spinner, $btn ) {
			var batches = [];
			for ( var i = 0; i < ids.length; i += SF_BATCH_SIZE ) {
				batches.push( ids.slice( i, i + SF_BATCH_SIZE ) );
			}

			$tbody.empty();
			$wrap.show();
			$btn.prop( 'disabled', true );
			osfBulkSync.toggleSpinner( $spinner, true );

			function processBatch( index ) {
				if ( index >= batches.length ) {
					$btn.prop( 'disabled', false );
					osfBulkSync.toggleSpinner( $spinner, false );
					return;
				}

				var batch = batches[ index ];

				$.ajax( {
					type: 'POST',
					url:  osfBulkSyncData.ajaxUrl,
					data: {
						action: action,
						nonce:  osfBulkSyncData.nonce,
						sf_ids: batch.join( '\n' ),
					},
				} ).done( function ( response ) {
					if ( response.success && response.data.results ) {
						$.each( response.data.results, function ( i, r ) {
							$tbody.append( osfBulkSync.buildResultRow( r.sf_id, r.sf_type, r.status, r.message ) );
						} );
					} else {
						var msg = response.data && response.data.message ? response.data.message : osfBulkSyncData.strings.error;
						$.each( batch, function ( i, id ) {
							$tbody.append( osfBulkSync.buildResultRow( id, '', 'error', msg ) );
						} );
					}
				} ).fail( function () {
					$.each( batch, function ( i, id ) {
						$tbody.append( osfBulkSync.buildResultRow( id, '', 'error', osfBulkSyncData.strings.error ) );
					} );
				} ).always( function () {
					processBatch( index + 1 );
				} );
			}

			processBatch( 0 );
		},

		// ─── Quick Resync Push ────────────────────────────────────────────────────

		/**
		 * Handle re-push button click.
		 */
		doResyncPush: function () {
			var raw  = $( '#csfbs-resync-push-ids' ).val();
			var ids  = osfBulkSync.parseIds( raw );

			if ( 0 === ids.length ) {
				alert( osfBulkSyncData.strings.noIds );
				return;
			}

			var $tbody   = $( '#csfbs-resync-push-tbody' );
			var $wrap    = $( '#csfbs-resync-push-results' );
			var $spinner = $( '.csfbs-spinner-repush' );
			var $btn     = $( '#csfbs-resync-push-btn' );

			$tbody.empty();
			$wrap.show();
			$btn.prop( 'disabled', true );
			osfBulkSync.toggleSpinner( $spinner, true );

			$.ajax( {
				type: 'POST',
				url: osfBulkSyncData.ajaxUrl,
				data: {
					action:  'osf_resync_push',
					nonce:   osfBulkSyncData.nonce,
					wp_ids:  ids.join( '\n' ),
				},
			} ).done( function ( response ) {
				$tbody.empty();
				if ( response.success && response.data.results ) {
					$.each( response.data.results, function ( i, r ) {
						$tbody.append( osfBulkSync.buildResultRow( r.wp_id, r.wp_type, r.status, r.message ) );
					} );
				} else {
					var msg = response.data && response.data.message ? response.data.message : osfBulkSyncData.strings.error;
					$tbody.append( osfBulkSync.buildResultRow( '', '', 'error', msg ) );
				}
			} ).fail( function () {
				$tbody.empty();
				$tbody.append( osfBulkSync.buildResultRow( '', '', 'error', osfBulkSyncData.strings.error ) );
			} ).always( function () {
				$btn.prop( 'disabled', false );
				osfBulkSync.toggleSpinner( $spinner, false );
			} );
		},

		// ─── Browse Table ─────────────────────────────────────────────────────────

		/**
		 * Load the paginated browse table via AJAX.
		 */
		loadBrowseTable: function () {
			var $tbody   = $( '#csfbs-browse-tbody' );
			var $spinner = $( '.csfbs-spinner-browse' );
			var $btn     = $( '#csfbs-browse-load-btn' );
			var wpType   = $( '#csfbs-browse-type-filter' ).val();
			var search   = $( '#csfbs-browse-search' ).val();

			$tbody.empty().append(
				$( '<tr>' ).append( $( '<td>' ).attr( 'colspan', 6 ).text( osfBulkSyncData.strings.loading ) )
			);
			$btn.prop( 'disabled', true );
			osfBulkSync.toggleSpinner( $spinner, true );

			$.ajax( {
				type: 'POST',
				url: osfBulkSyncData.ajaxUrl,
				data: {
					action:    'osf_get_mapped_records',
					nonce:     osfBulkSyncData.nonce,
					page:      osfBulkSync.browsePage,
					per_page:  osfBulkSync.browsePerPage,
					wp_type:   wpType,
					search:    search,
				},
			} ).done( function ( response ) {
				if ( ! response.success ) {
					$tbody.empty().append(
						$( '<tr>' ).append( $( '<td>' ).attr( 'colspan', 6 ).text( osfBulkSyncData.strings.error ) )
					);
					return;
				}

				var data = response.data;
				osfBulkSync.browseTotal      = data.total;
				osfBulkSync.browseTotalPages = data.totalPages;

				// Populate type filter dropdown (first load).
				if ( data.types && data.types.length ) {
					var $filter      = $( '#csfbs-browse-type-filter' );
					var currentVal   = $filter.val();
					var existingOpts = $filter.find( 'option' ).map( function () { return $( this ).val(); } ).get();

					$.each( data.types, function ( i, t ) {
						if ( t && existingOpts.indexOf( t ) === -1 ) {
							$filter.append( $( '<option>' ).val( t ).text( t ) );
						}
					} );
					$filter.val( currentVal );
				}

				// Render rows.
				$tbody.empty();
				if ( ! data.rows || 0 === data.rows.length ) {
					$tbody.append(
						$( '<tr>' ).append( $( '<td>' ).attr( 'colspan', 6 ).addClass( 'csfbs-empty-row' ).text( 'No records found.' ) )
					);
				} else {
					$.each( data.rows, function ( i, row ) {
						var $syncStatus = '1' === String( row.last_sync_status )
							? $( '<span>' ).addClass( 'csfbs-status csfbs-status-success' ).text( 'OK' )
							: $( '<span>' ).addClass( 'csfbs-status csfbs-status-error' ).text( 'Error' );

						var $tr = $( '<tr>' )
							.attr( 'data-sf-id', row.salesforce_id )
							.attr( 'data-wp-id', row.wordpress_id )
							.attr( 'data-wp-type', row.wordpress_object || '' )
							.append(
								$( '<td>' ).append(
									$( '<input>' )
										.attr( 'type', 'checkbox' )
										.addClass( 'csfbs-row-check' )
										.val( row.id )
								)
							)
							.append( $( '<td>' ).text( row.salesforce_id ) )
							.append( $( '<td>' ).text( row.wordpress_id ) )
							.append( $( '<td>' ).text( row.wordpress_object || '' ) )
							.append( $( '<td>' ).text( row.object_updated || '' ) )
							.append( $( '<td>' ).append( $syncStatus ) );

						$tbody.append( $tr );
					} );
				}

				osfBulkSync.updatePagination();
				osfBulkSync.updateBrowseActionButtons();
				$( '#csfbs-select-all' ).prop( 'checked', false );
			} ).fail( function () {
				$tbody.empty().append(
					$( '<tr>' ).append( $( '<td>' ).attr( 'colspan', 6 ).text( osfBulkSyncData.strings.error ) )
				);
			} ).always( function () {
				$btn.prop( 'disabled', false );
				osfBulkSync.toggleSpinner( $spinner, false );
			} );
		},

		/**
		 * Update the browse table pagination info and button states.
		 */
		updatePagination: function () {
			$( '#csfbs-browse-page-info' ).text(
				'Page ' + osfBulkSync.browsePage + ' of ' + ( osfBulkSync.browseTotalPages || 1 ) +
				' (' + osfBulkSync.browseTotal + ' total)'
			);
			$( '#csfbs-browse-prev' ).prop( 'disabled', osfBulkSync.browsePage <= 1 );
			$( '#csfbs-browse-next' ).prop( 'disabled', osfBulkSync.browsePage >= osfBulkSync.browseTotalPages );
		},

		/**
		 * Enable or disable the Re-Pull / Re-Push buttons based on row selection.
		 */
		updateBrowseActionButtons: function () {
			var hasChecked = $( '.csfbs-row-check:checked' ).length > 0;
			$( '#csfbs-browse-repull-btn' ).prop( 'disabled', ! hasChecked );
			$( '#csfbs-browse-repush-btn' ).prop( 'disabled', ! hasChecked );
		},

		/**
		 * Collect selected rows' SF IDs and trigger re-pull.
		 */
		doBrowseRepull: function () {
			var sfIds = [];
			$( '.csfbs-row-check:checked' ).each( function () {
				var sfId = $( this ).closest( 'tr' ).attr( 'data-sf-id' );
				if ( sfId ) {
					sfIds.push( sfId );
				}
			} );

			if ( 0 === sfIds.length ) {
				alert( osfBulkSyncData.strings.selectRows );
				return;
			}

			osfBulkSync.doBrowseAction( 'osf_resync_pull', { sf_ids: sfIds.join( '\n' ) }, sfIds, 'sf' );
		},

		/**
		 * Collect selected rows' WP IDs and trigger re-push.
		 */
		doBrowseRepush: function () {
			var wpIds = [];
			$( '.csfbs-row-check:checked' ).each( function () {
				var wpId = $( this ).closest( 'tr' ).attr( 'data-wp-id' );
				if ( wpId ) {
					wpIds.push( wpId );
				}
			} );

			if ( 0 === wpIds.length ) {
				alert( osfBulkSyncData.strings.selectRows );
				return;
			}

			osfBulkSync.doBrowseAction( 'osf_resync_push', { wp_ids: wpIds.join( '\n' ) }, wpIds, 'wp' );
		},

		/**
		 * Run a browse-table resync action and display results inline.
		 *
		 * @param {string}   action    AJAX action name.
		 * @param {object}   extraData Additional POST data (sf_ids or wp_ids).
		 * @param {string[]} ids       IDs for labelling error rows on failure.
		 * @param {string}   idType    'sf' or 'wp'.
		 */
		doBrowseAction: function ( action, extraData, ids, idType ) {
			var $tbody   = $( '#csfbs-browse-action-tbody' );
			var $wrap    = $( '#csfbs-browse-action-results' );
			var $spinner = $( '.csfbs-spinner-browse-action' );
			var $pullBtn = $( '#csfbs-browse-repull-btn' );
			var $pushBtn = $( '#csfbs-browse-repush-btn' );

			$tbody.empty();
			$wrap.show();
			$pullBtn.prop( 'disabled', true );
			$pushBtn.prop( 'disabled', true );
			osfBulkSync.toggleSpinner( $spinner, true );

			var postData = $.extend( {
				action: action,
				nonce:  osfBulkSyncData.nonce,
			}, extraData );

			$.ajax( {
				type: 'POST',
				url:  osfBulkSyncData.ajaxUrl,
				data: postData,
			} ).done( function ( response ) {
				$tbody.empty();
				if ( response.success && response.data.results ) {
					$.each( response.data.results, function ( i, r ) {
						var id   = 'sf' === idType ? r.sf_id : r.wp_id;
						var type = 'sf' === idType ? r.sf_type : r.wp_type;
						$tbody.append( osfBulkSync.buildResultRow( id, type, r.status, r.message ) );
					} );
				} else {
					var msg = response.data && response.data.message ? response.data.message : osfBulkSyncData.strings.error;
					$tbody.append( osfBulkSync.buildResultRow( '', '', 'error', msg ) );
				}
			} ).fail( function () {
				$tbody.empty();
				$tbody.append( osfBulkSync.buildResultRow( '', '', 'error', osfBulkSyncData.strings.error ) );
			} ).always( function () {
				osfBulkSync.toggleSpinner( $spinner, false );
				osfBulkSync.updateBrowseActionButtons();
			} );
		},
	};

	$( document ).ready( function () {
		osfBulkSync.init();
	} );

})( jQuery );
