/**
 * GatewayKit Pro Forms — shared frontend for optional-payment animation,
 * Payment Info live-update, and Discount Code field.
 * Ships in Lite assets; inert without Pro.
 *
 * @package GatewayKit
 */
( function( $ ) {
	'use strict';

	var cfg = ( typeof GatewayKitProForms !== 'undefined' ) ? GatewayKitProForms : {};

	/**
	 * Optional Payment: enhance the Elementor success message with a slide
	 * animation when the no-payment branch fires. We only ADD a class;
	 * Elementor owns the message rendering.
	 */
	$( document ).on( 'submit_success', function( event, response ) {
		if (
			typeof response !== 'undefined' &&
			typeof response.data !== 'undefined' &&
			response.data.gatewaykit_no_payment
		) {
			// Small delay to let Elementor render the message element.
			setTimeout( function() {
				var $messages = $( '.elementor-message-success, .elementor-message' );
				if ( $messages.length ) {
					$messages.last().addClass( 'gatewaykit-no-payment-animation' );
				}
			}, 100 );
		}
	} );

	/**
	 * Payment Info (Feature D): live-update the summary container when the
	 * amount field changes. Supports both old single-line and new cart layout.
	 */
	$( document ).on( 'input change', '[data-gatewaykit-summary]', function() {
		var $summary = $( this );
		var fieldId  = $summary.data( 'gatewaykit-amount-field' );
		if ( ! fieldId ) {
			return;
		}

		// Find the amount input by its field ID, scoped to this summary's own
		// form so multiple forms on the same page don't cross-read each other.
		var $widget = $summary.closest( '.elementor-widget-form' );
		var $field  = $widget.find( '[data-id="' + fieldId + '"]' ).find( 'input, select, textarea' ).first();
		if ( ! $field.length ) {
			// Fallback: search the whole form element (some themes alter the widget wrapper).
			var $formEl = $summary.closest( 'form' );
			$field = $formEl.find( '[data-id="' + fieldId + '"]' ).find( 'input, select, textarea' ).first();
		}
		if ( ! $field.length ) {
			return;
		}

		var val = parseFloat( $field.val() ) || 0;
		if ( val < 0 ) {
			val = 0;
		}

		var formatted = val.toLocaleString( undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 } );

		// Update subtotal amount.
		$summary.find( '.gatewaykit-summary-amount' ).text( formatted );

		// Update total (if no discount is active, total = subtotal).
		if ( ! $summary.hasClass( 'gatewaykit-has-discount' ) ) {
			$summary.find( '.gatewaykit-summary-final' ).text( formatted );
		}
	} );

	/**
	 * Payment Info: listen for discount validation results and update
	 * the cart summary.
	 */
	$( document ).on( 'gatewaykit_discount_applied', function( event, data ) {
		var $form = $( event.target );
		var $summary = $form.closest( '.elementor-widget-form' ).find( '[data-gatewaykit-summary]' );
		if ( ! $summary.length ) {
			return;
		}

		// Update subtotal.
		var originalFormatted = ( parseFloat( data.original_amount ) || 0 ).toLocaleString( undefined, {
			minimumFractionDigits: 2,
			maximumFractionDigits: 2
		} );
		$summary.find( '.gatewaykit-summary-amount' ).text( originalFormatted );

		// Show discount row.
		var $discountRow = $summary.find( '.gatewaykit-summary-discount' );
		if ( data.discount_amount > 0 ) {
			$summary.addClass( 'gatewaykit-has-discount' );

			$summary.find( '.gatewaykit-summary-discount-code' ).text( data.code || '' );
			var discountFormatted = ( parseFloat( data.discount_amount ) || 0 ).toLocaleString( undefined, {
				minimumFractionDigits: 2,
				maximumFractionDigits: 2
			} );
			$summary.find( '.gatewaykit-summary-discount-value' ).text( discountFormatted );
			$discountRow.show();
		} else {
			$summary.removeClass( 'gatewaykit-has-discount' );
			$discountRow.hide();
		}

		// Update total.
		var finalFormatted = ( parseFloat( data.final_amount ) || 0 ).toLocaleString( undefined, {
			minimumFractionDigits: 2,
			maximumFractionDigits: 2
		} );
		$summary.find( '.gatewaykit-summary-final' ).text( finalFormatted );
	} );

	/**
	 * Payment Info: listen for discount removal.
	 * Reset the summary to show only the original amount.
	 */
	$( document ).on( 'gatewaykit_discount_removed', function( event ) {
		var $form = $( event.target );
		var $summary = $form.closest( '.elementor-widget-form' ).find( '[data-gatewaykit-summary]' );
		if ( ! $summary.length ) {
			return;
		}

		// Remove the hidden input so the code is not sent on submission.
		$form.find( 'input[name="form_fields[_gatewaykit_discount_code]"]' ).remove();

		$summary.removeClass( 'gatewaykit-has-discount' );
		$summary.find( '.gatewaykit-summary-discount' ).hide();
		// Re-trigger the amount field change to reset the total.
		$summary.trigger( 'change' );
	} );

	// ─────────────────────────────────────────────────────────────
	// Discount Code field (dedicated Elementor form field type)
	// ─────────────────────────────────────────────────────────────

	/**
	 * Get the current amount from the form's payment configuration.
	 *
	 * @param {jQuery} $dc The discount code field wrapper.
	 * @return {number}
	 */
	function getFormAmount( $dc ) {
		var amountType = $dc.data( 'gatewaykit-dc-amount-type' ) || 'fixed';

		if ( 'fixed' === amountType ) {
			return parseFloat( $dc.data( 'gatewaykit-dc-amount' ) ) || 0;
		}

		// Field-based: find the amount input inside the same form.
		var fieldId = $dc.data( 'gatewaykit-dc-amount-field' );
		if ( ! fieldId ) {
			return 0;
		}

		var $form = $dc.closest( 'form' );
		var $field = $form.find( '#form-field-' + fieldId );
		if ( ! $field.length ) {
			$field = $form.find( 'input[name="form_fields[' + fieldId + ']"]' );
		}
		return parseFloat( ( $field.val() || '0' ).toString().replace( /[^\d.-]/g, '' ) ) || 0;
	}

	/**
	 * Format a number for display.
	 *
	 * @param {number} n
	 * @return {string}
	 */
	function formatDcAmount( n ) {
		n = parseFloat( n ) || 0;
		if ( n < 0 ) { n = 0; }
		return n.toLocaleString( undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 } );
	}

	// Initialize all discount code fields on the page.
	$( function() {
		$( '[data-gatewaykit-dc]' ).each( function() {
			var $dc     = $( this );
			var $input  = $dc.find( '.gatewaykit-dc-input' );
			var $btn    = $dc.find( '.gatewaykit-dc-apply' );
			var $msg    = $dc.find( '.gatewaykit-dc-msg' );
			var $form   = $dc.closest( 'form' );

			if ( ! $input.length || ! $btn.length || ! $form.length ) {
				return;
			}

			// Apply button click.
			$btn.on( 'click', function( e ) {
				e.preventDefault();

				var code = ( $input.val() || '' ).toString().trim();
				if ( ! code ) {
					showDcError( $msg, cfg.i18n ? cfg.i18n.enter_code : 'Please enter a discount code.' );
					return;
				}

				var amount = getFormAmount( $dc );
				if ( amount <= 0 ) {
					showDcError( $msg, cfg.i18n ? cfg.i18n.amount_required : 'Please enter an amount first.' );
					return;
				}

				$btn.prop( 'disabled', true ).text( cfg.i18n ? cfg.i18n.applying : 'Applying...' );

				$.post( cfg.ajaxurl, {
					action: 'gatewaykit_validate_discount_code',
					nonce:  cfg.nonce,
					code:   code,
					amount: amount
				} ).done( function( resp ) {
					if ( resp && resp.success && resp.data && resp.data.valid ) {
						showDcSuccess( $msg, $form, code, resp.data );
					} else {
						var msg = ( resp && resp.data && typeof resp.data === 'string' )
							? resp.data
							: ( cfg.i18n ? cfg.i18n.invalid : 'Invalid or expired discount code.' );
						showDcError( $msg, msg );
					}
				} ).fail( function() {
					showDcError( $msg, cfg.i18n ? cfg.i18n.error : 'An error occurred. Please try again.' );
				} ).always( function() {
					$btn.prop( 'disabled', false ).text( cfg.i18n ? cfg.i18n.apply : 'Apply' );
				} );
			} );

			// Allow Enter key to trigger apply.
			$input.on( 'keydown', function( e ) {
				if ( 13 === e.keyCode ) {
					$btn.trigger( 'click' );
				}
			} );

			// When input is cleared, remove the discount.
			$input.on( 'input', function() {
				if ( ! ( $input.val() || '' ).toString().trim() ) {
					$msg.hide().removeClass( 'gatewaykit-dc-success gatewaykit-dc-error' );
					$form.trigger( 'gatewaykit_discount_removed' );
				}
			} );
		} );
	} );

	/**
	 * Show a success message on the discount code field.
	 *
	 * @param {jQuery} $msg  Message element.
	 * @param {jQuery} $form The parent form.
	 * @param {string} code  The discount code.
	 * @param {object} data  AJAX response data.
	 */
	function showDcSuccess( $msg, $form, code, data ) {
		var prefix = cfg.i18n ? cfg.i18n.discount + ': ' : 'Discount: ';
		var saved  = formatDcAmount( data.discount_amount );

		$msg
			.removeClass( 'gatewaykit-dc-error' )
			.addClass( 'gatewaykit-dc-success' )
			.html( prefix + '<strong>' + saved + '</strong> ' + ( cfg.i18n ? cfg.i18n.removed : 'saved' ) )
			.show();

		// Inject a hidden input so the discount code is guaranteed to
		// reach the server on form submission, even when Elementor's
		// custom-field collector skips the visible input.
		$form.find( 'input[name="form_fields[_gatewaykit_discount_code]"]' ).remove();
		$( '<input type="hidden" name="form_fields[_gatewaykit_discount_code]" />' )
			.val( code )
			.appendTo( $form );

		// Fire event for Payment Summary to update.
		$form.trigger( 'gatewaykit_discount_applied', {
			code:            code,
			original_amount: data.original_amount,
			discount_amount: data.discount_amount,
			final_amount:    data.final_amount
		} );
	}

	/**
	 * Show an error message on the discount code field.
	 *
	 * @param {jQuery} $msg Message element.
	 * @param {string} msg  Error text.
	 */
	function showDcError( $msg, msg ) {
		$msg
			.removeClass( 'gatewaykit-dc-success' )
			.addClass( 'gatewaykit-dc-error' )
			.text( msg )
			.show();
	}

	// Trigger initial update for Payment Info on page load.
	$( function() {
		$( '[data-gatewaykit-summary]' ).trigger( 'change' );
	} );

} )( jQuery );
