/**
 * GatewayKit Setup Wizard Script
 *
 * @package GatewayKit
 */
( function( $ ) {
	'use strict';

	$( document ).ready( function() {
		// --- Step 2: Gateway Radio Card Toggle ---
		$( 'input[name="gateway_id"]' ).on( 'change', function() {
			var selected = $( this ).val();
			$( '.wizard-gateway-card' ).removeClass( 'selected' );
			$( this ).closest( '.wizard-gateway-card' ).addClass( 'selected' );

			$( '.gw-panel' ).hide();
			$( '#panel-' + selected ).fadeIn( 150 );
		} );

		// Initial state for gateway selection
		var initialGw = $( 'input[name="gateway_id"]:checked' ).val();
		if ( initialGw ) {
			$( '.gw-panel' ).hide();
			$( '#panel-' + initialGw ).show();
		}

		// --- Step 3: Template Radio Card Toggle ---
		var templateTitles = {
			donation: 'Support Our Cause',
			product: 'Product Checkout',
			invoice: 'Invoice Payment',
			event: 'Event Registration & Tickets'
		};

		$( 'input[name="template"]' ).on( 'change', function() {
			var selectedTpl = $( this ).val();
			$( '.wizard-template-card' ).removeClass( 'selected' );
			$( this ).closest( '.wizard-template-card' ).addClass( 'selected' );

			if ( 'none' === selectedTpl ) {
				$( '#form-title-group' ).slideUp( 150 );
			} else {
				$( '#form-title-group' ).slideDown( 150 );
				if ( templateTitles[ selectedTpl ] ) {
					$( '#form_title' ).val( templateTitles[ selectedTpl ] );
				}
			}
		} );
	} );
} )( jQuery );
