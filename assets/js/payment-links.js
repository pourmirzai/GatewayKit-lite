/*!
 * GatewayKit - Payment Links
 * Creates Stripe Payment Links from the admin page and manages the
 * generated-links list + copy buttons.
 * Data is provided via the localized `GatewayKitPaymentLinks` object.
 */
( function ( $ ) {
	'use strict';

	var config = window.GatewayKitPaymentLinks || {};

	function escapeHtml( str ) {
		if ( str === null || str === undefined ) {
			return '';
		}
		return String( str )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#039;' );
	}

	function copyToClipboard( text, done ) {
		if ( navigator.clipboard && window.isSecureContext ) {
			navigator.clipboard.writeText( text ).then( done, function () {
				fallbackCopy( text, done );
			} );
			return;
		}
		fallbackCopy( text, done );
	}

	function fallbackCopy( text, done ) {
		var textarea = document.createElement( 'textarea' );
		textarea.value = text;
		textarea.style.position = 'fixed';
		textarea.style.opacity = '0';
		document.body.appendChild( textarea );
		textarea.select();
		try {
			document.execCommand( 'copy' );
		} catch ( e ) {
			// Copy failed silently — still notify the caller.
		}
		document.body.removeChild( textarea );
		if ( typeof done === 'function' ) {
			done();
		}
	}

	function formatAmount( amount, currency ) {
		return Number( amount ).toFixed( 2 ) + ' ' + String( currency || '' ).toUpperCase();
	}

	function buildRow( entry ) {
		var url = entry && entry.url ? entry.url : '';
		return '' +
			'<tr>' +
				'<td>' + escapeHtml( entry.name || '' ) + '</td>' +
				'<td>' + escapeHtml( formatAmount( entry.amount, entry.currency ) ) + '</td>' +
				'<td>' + escapeHtml( entry.created_at || '' ) + '</td>' +
				'<td>' +
					'<a href="' + escapeHtml( url ) + '" target="_blank" rel="noopener noreferrer" class="button button-small">' + escapeHtml( config.i18n.open ) + '</a> ' +
					'<button type="button" class="button button-small gatewaykit-pl-copy" data-copy="' + escapeHtml( url ) + '">' + escapeHtml( config.i18n.copy ) + '</button>' +
				'</td>' +
			'</tr>';
	}

	function showResult( entry ) {
		var url = entry && entry.url ? entry.url : '';
		var html = '' +
			'<div class="notice notice-success is-dismissible inline">' +
				'<p>' +
					'<strong>' + escapeHtml( config.i18n.generate ) + ':</strong> ' +
					'<a href="' + escapeHtml( url ) + '" target="_blank" rel="noopener noreferrer">' + escapeHtml( url ) + '</a> ' +
					'<button type="button" class="button button-small gatewaykit-pl-copy" data-copy="' + escapeHtml( url ) + '">' + escapeHtml( config.i18n.copy ) + '</button>' +
				'</p>' +
			'</div>';
		$( '#gatewaykit-pl-result' ).html( html );
	}

	$( function () {
		var $form = $( '#gatewaykit-payment-link-form' );

		$form.on( 'submit', function ( ev ) {
			ev.preventDefault();

			var amount = $( '#gk-pl-amount' ).val();
			var currency = $( '#gk-pl-currency' ).val();
			var name = $( '#gk-pl-name' ).val();
			var description = $( '#gk-pl-description' ).val();

			if ( ! amount || parseFloat( amount ) <= 0 ) {
				$( '#gk-pl-amount' ).focus();
				return;
			}
			if ( ! name ) {
				$( '#gk-pl-name' ).focus();
				return;
			}

			var $button = $( '#gk-pl-generate' );
			$button.prop( 'disabled', true ).text( config.i18n.generating );
			$( '#gatewaykit-pl-result' ).empty();

			$.post( config.ajax_url, {
				action: 'gatewaykit_create_payment_link',
				nonce: config.nonce,
				amount: amount,
				currency: currency,
				name: name,
				description: description
			} )
			.done( function ( response ) {
				if ( response && response.success && response.data && response.data.url ) {
					showResult( response.data.entry || response.data );
					var $list = $( '#gatewaykit-pl-list' );
					if ( $list.length ) {
						$list.prepend( buildRow( response.data.entry || response.data ) );
					}
					$form.trigger( 'reset' );
				} else {
					var msg = ( response && response.data ) ? response.data : config.i18n.error;
					$( '#gatewaykit-pl-result' ).html(
						'<div class="notice notice-error is-dismissible inline"><p>' + escapeHtml( msg ) + '</p></div>'
					);
				}
			} )
			.fail( function () {
				$( '#gatewaykit-pl-result' ).html(
					'<div class="notice notice-error is-dismissible inline"><p>' + escapeHtml( config.i18n.error ) + '</p></div>'
				);
			} )
			.always( function () {
				$button.prop( 'disabled', false ).text( config.i18n.generate );
			} );
		} );

		$( document ).on( 'click', '.gatewaykit-pl-copy', function ( ev ) {
			ev.preventDefault();
			var $button = $( this );
			var url = $button.data( 'copy' ) || '';
			if ( ! url ) {
				return;
			}
			copyToClipboard( url, function () {
				var original = $button.text();
				$button.text( config.i18n.copied );
				window.setTimeout( function () {
					$button.text( original );
				}, 1500 );
			} );
		} );
	} );
} )( jQuery );
