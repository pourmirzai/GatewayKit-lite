( function( $, window, undefined ) {
	'use strict';

	var GatewayKitLogs = {
		init: function() {
			var $body = $( document.body );

			// Guard: bail if localized vars are missing (script loaded before localized data).
			if ( typeof gatewaykit_ajax === 'undefined' || typeof gatewaykit_logs_vars === 'undefined' ) {
				return;
			}

			$body.on( 'click', '.gatewaykit-logs-copy', this.copySelected );
			$body.on( 'click', '.gatewaykit-logs-export', this.exportSelected );
			$body.on( 'click', '.gatewaykit-logs-clear', this.clearAllLogs );

			// Intercept the WP "Apply" bulk-action button when action is copy/export.
			$( '#gatewaykit-logs-table-form' ).on( 'submit', this.handleBulkSubmit );
		},

		/**
		 * Collect selected log IDs from the checked checkboxes.
		 */
		getSelectedIds: function() {
			var ids = [];
			$( '.gatewaykit-log-checkbox:checked' ).each( function() {
				ids.push( $( this ).val() );
			} );
			return ids;
		},

		/**
		 * Show a transient notice in the action bar.
		 */
		notice: function( message, type ) {
			var $notice = $( '.gatewaykit-logs-notice' );
			if ( ! $notice.length ) {
				return;
			}
			$notice.removeClass( 'gatewaykit-notice-success gatewaykit-notice-error' )
				.addClass( 'gatewaykit-notice-' + ( type || 'success' ) )
				.text( message );

			window.clearTimeout( GatewayKitLogs._noticeTimer );
			GatewayKitLogs._noticeTimer = window.setTimeout( function() {
				$notice.text( '' );
			}, 6000 );
		},

		/**
		 * Clear all payment logs via AJAX.
		 */
		clearAllLogs: function( e ) {
			if ( e ) {
				e.preventDefault();
			}

			var confirmMsg = ( typeof gatewaykit_logs_vars !== 'undefined' && gatewaykit_logs_vars.confirm_clear )
				? gatewaykit_logs_vars.confirm_clear
				: 'Are you sure you want to delete ALL logs? This cannot be undone.';

			if ( ! window.confirm( confirmMsg ) ) {
				return;
			}

			var $btn = $( '.gatewaykit-logs-clear' ).first();
			$btn.prop( 'disabled', true );

			$.ajax( {
				url: gatewaykit_ajax.ajax_url,
				method: 'POST',
				dataType: 'json',
				data: {
					action: 'gatewaykit_clear_logs',
					nonce: gatewaykit_ajax.nonce
				}
			} ).done( function( response ) {
				if ( response && response.success ) {
					var successMsg = ( response.data && response.data.message )
						? response.data.message
						: ( ( typeof gatewaykit_logs_vars !== 'undefined' && gatewaykit_logs_vars.cleared ) ? gatewaykit_logs_vars.cleared : 'All logs cleared.' );
					GatewayKitLogs.notice( successMsg, 'success' );
					window.setTimeout( function() {
						window.location.reload();
					}, 1000 );
				} else {
					var failMsg = ( response && response.data )
						? response.data
						: ( ( typeof gatewaykit_logs_vars !== 'undefined' && gatewaykit_logs_vars.clear_failed ) ? gatewaykit_logs_vars.clear_failed : 'Failed to clear logs.' );
					GatewayKitLogs.notice( failMsg, 'error' );
				}
			} ).fail( function() {
				GatewayKitLogs.notice( ( typeof gatewaykit_logs_vars !== 'undefined' && gatewaykit_logs_vars.ajax_error ) ? gatewaykit_logs_vars.ajax_error : 'Error.', 'error' );
			} ).always( function() {
				$btn.prop( 'disabled', false );
			} );
		},

		/**
		 * Copy selected logs to the clipboard via the Phase 2 AJAX handler.
		 */
		copySelected: function( e ) {
			if ( e ) {
				e.preventDefault();
			}

			var ids = GatewayKitLogs.getSelectedIds();
			if ( ! ids.length ) {
				GatewayKitLogs.notice( gatewaykit_logs_vars.none_selected, 'error' );
				return;
			}

			var $btn = $( '.gatewaykit-logs-copy' ).first();
			$btn.prop( 'disabled', true );

			$.ajax( {
				url: gatewaykit_ajax.ajax_url,
				method: 'POST',
				dataType: 'json',
				data: {
					action: 'gatewaykit_copy_logs',
					nonce: gatewaykit_ajax.nonce,
					log_ids: ids
				}
			} ).done( function( response ) {
				if ( response && response.success && response.data && response.data.text ) {
					GatewayKitLogs.copyToClipboard( response.data.text ).then( function() {
						GatewayKitLogs.notice(
							gatewaykit_logs_vars.copied.replace( '%s', response.data.count ),
							'success'
						);
					}, function() {
						// Clipboard write blocked — fall back to a prompt with the text.
						window.prompt( gatewaykit_logs_vars.confirm_copy, response.data.text );
						GatewayKitLogs.notice(
							gatewaykit_logs_vars.copied.replace( '%s', response.data.count ),
							'success'
						);
					} );
				} else {
					var msg = ( response && response.data ) ? response.data : gatewaykit_logs_vars.copy_failed;
					GatewayKitLogs.notice( msg, 'error' );
				}
			} ).fail( function() {
				GatewayKitLogs.notice( gatewaykit_logs_vars.ajax_error, 'error' );
			} ).always( function() {
				$btn.prop( 'disabled', false );
			} );
		},

		/**
		 * Export selected logs as a downloadable .txt (Phase 2 GET endpoint).
		 */
		exportSelected: function( e ) {
			if ( e ) {
				e.preventDefault();
			}

			var ids = GatewayKitLogs.getSelectedIds();
			if ( ! ids.length ) {
				GatewayKitLogs.notice( gatewaykit_logs_vars.none_selected, 'error' );
				return;
			}

			var url = gatewaykit_ajax.ajax_url +
				'?action=gatewaykit_export_logs' +
				'&nonce=' + encodeURIComponent( gatewaykit_ajax.nonce ) +
				'&log_ids=' + encodeURIComponent( ids.join( ',' ) );

			window.location.href = url;
		},

		/**
		 * Intercept WP bulk action "Apply" for copy/export.
		 *
		 * Uses e.stopImmediatePropagation() to prevent the global admin.js
		 * handleBulkDelete handler from also firing on this submit event.
		 */
		handleBulkSubmit: function( e ) {
			var $form = $( e.currentTarget );
			var action = $( 'select[name="action"]', $form ).val();
			var action2 = $( 'select[name="action2"]', $form ).val();
			var effective = action && action !== '-1' ? action : ( action2 && action2 !== '-1' ? action2 : '' );

			if ( effective === 'copy' ) {
				e.preventDefault();
				e.stopImmediatePropagation();
				$( '.gatewaykit-logs-copy' ).trigger( 'click' );
			} else if ( effective === 'export' ) {
				e.preventDefault();
				e.stopImmediatePropagation();
				$( '.gatewaykit-logs-export' ).trigger( 'click' );
			}
			// Other actions fall through to default form behavior (none configured).
		},

		/**
		 * Promise-based clipboard copy with fallbacks.
		 */
		copyToClipboard: function( text ) {
			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				return navigator.clipboard.writeText( text );
			}

			return new Promise( function( resolve, reject ) {
				try {
					var $ta = $( '<textarea></textarea>' )
						.val( text )
						.css( { position: 'fixed', top: '-1000px', left: '-1000px' } )
						.appendTo( 'body' );
					$ta[0].select();
					var ok = document.execCommand( 'copy' );
					$ta.remove();
					ok ? resolve() : reject();
				} catch ( err ) {
					reject( err );
				}
			} );
		}
	};

	$( function() { GatewayKitLogs.init(); } );
} )( jQuery, window );
