<?php
/**
 * Payment Gateway Callback Handler
 *
 * Handles payment gateway redirect callbacks
 *
 * @package GatewayKit
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handle payment callback
 */
function gatewaykit_handle_payment_callback() {
	// Public payment-callback endpoint (gateway redirect return). There is no
	// nonce: callbacks are matched by transaction receipt tokens and protected
	// by rate limiting.
	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	// Check if this is a payment callback
	if ( ! isset( $_GET['gatewaykit_callback'] ) || sanitize_text_field( wp_unslash( $_GET['gatewaykit_callback'] ) ) !== '1' ) {
		return;
	}

	// Rate limiting check
	$rate_limiter = GatewayKit_Rate_Limiter::get_instance();
	$rate_check   = $rate_limiter->check_rate_limit( 'callback' );
	if ( is_wp_error( $rate_check ) ) {
		wp_die( esc_html( $rate_check->get_error_message() ) );
	}

	// Validate gateway ID using centralized validator
	$validator  = GatewayKit_Input_Validator::get_instance();
	$gateway_id = isset( $_GET['gateway'] ) ? $validator->validate_gateway( wp_unslash( $_GET['gateway'] ) ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized by GatewayKit_Input_Validator::validate_gateway()
	if ( is_wp_error( $gateway_id ) ) {
		wp_die( esc_html__( 'Invalid gateway parameter.', 'gatewaykit' ) );
	}

	// Handle explicit buyer cancellation. Redirect-based gateways (Stripe
	// Checkout, PayPal, CoinGate) send the user back here with
	// gatewaykit_cancel=1 and a `token` (the transaction receipt token) so we
	// can resolve the order and route to the configured failure URL instead
	// of dying on the missing authority below.
	if ( isset( $_GET['gatewaykit_cancel'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['gatewaykit_cancel'] ) ) ) {
		$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';

		$transaction = $token ? GatewayKit_Transaction_Model::find_by_receipt_token( $token ) : null;

		if ( $transaction ) {
			// Only transition orders still awaiting payment; never overwrite an
			// already-settled transaction (e.g. webhook already completed it).
			if ( in_array( $transaction->status, array( 'pending', 'processing' ), true ) ) {
				$transaction->update( array( 'status' => 'cancelled' ) );
			}

			do_action( 'gatewaykit_payment_failed', $transaction, array( 'reason' => 'cancelled_by_user' ) );

			// Route to the failure URL; fall back to success URL, then a notice.
			$redirect_url = $transaction->failure_url ? $transaction->failure_url : $transaction->success_url;

			if ( $redirect_url ) {
				$redirect_url = add_query_arg( 'gatewaykit_receipt', $transaction->receipt_token, $redirect_url );
				wp_safe_redirect( $redirect_url );
				exit;
			}

			wp_die( esc_html__( 'Payment cancelled. No redirect URL is configured for this form.', 'gatewaykit' ) );
		}

		// No matching transaction; show a clean cancellation notice instead of
		// the misleading "Invalid callback parameters." error.
		wp_die( esc_html__( 'Payment cancelled.', 'gatewaykit' ) );
	}

	// Validate the callback token parameter. Redirect-based gateways pass the
	// transaction token back here so it can be matched to the stored
	// transaction; hosted-checkout / webhook flows are handled per gateway.
	$authority = isset( $_GET['authority'] ) ? sanitize_text_field( wp_unslash( $_GET['authority'] ) ) : '';
	if ( empty( $authority ) ) {
		$authority = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
	}

	if ( empty( $authority ) ) {
		wp_die( esc_html__( 'Invalid callback parameters.', 'gatewaykit' ) );
	}

	// Get transaction
	$transaction = GatewayKit_Transaction_Model::find_by_authority( $authority );

	if ( ! $transaction ) {
		wp_die( esc_html__( 'Transaction not found.', 'gatewaykit' ) );
	}

	// Get gateway
	$gateway_manager = GatewayKit_Gateway_Manager::get_instance();
	$gateway         = $gateway_manager->get_gateway( $gateway_id );

	if ( ! $gateway ) {
		wp_die( esc_html__( 'Payment gateway not found.', 'gatewaykit' ) );
	}

	// Verify payment
	$result = $gateway->verify_payment( $authority, $transaction->amount );

	if ( $result['status'] === 'success' ) {
		// Update transaction
		$transaction->update(
			array(
				'status'       => 'completed',
				'ref_id'       => isset( $result['ref_id'] ) ? $result['ref_id'] : '',
				'completed_at' => current_time( 'mysql' ),
			)
		);

		// Trigger success action
		do_action( 'gatewaykit_payment_completed', $transaction );

		// Redirect to redirect URL specified in form settings
		$redirect_url = $transaction->success_url;
		if ( $redirect_url ) {
			// Add receipt token to URL for shortcode
			$redirect_url = add_query_arg( 'gatewaykit_receipt', $transaction->receipt_token, $redirect_url );
			wp_safe_redirect( $redirect_url );
			exit;
		} else {
			// Fallback to default if no custom URL is set
			wp_die( esc_html__( 'Redirect URL not configured for success.', 'gatewaykit' ) );
		}
	} else {
		// Update transaction
		$transaction->update( array( 'status' => 'failed' ) );

		// Trigger failure action
		do_action( 'gatewaykit_payment_failed', $transaction, $result );

		// Redirect to redirect URL specified in form settings
		$redirect_url = $transaction->failure_url;
		if ( $redirect_url ) {
			// Add receipt token to URL for shortcode
			$redirect_url = add_query_arg( 'gatewaykit_receipt', $transaction->receipt_token, $redirect_url );
			wp_safe_redirect( $redirect_url );
			exit;
		} else {
			// Use success URL as fallback for failure
			$redirect_url = $transaction->success_url;
			if ( $redirect_url ) {
				$redirect_url = add_query_arg( 'gatewaykit_receipt', $transaction->receipt_token, $redirect_url );
				wp_safe_redirect( $redirect_url );
				exit;
			} else {
				// Fallback to default if no custom URL is set
				wp_die( esc_html__( 'Redirect URL not configured.', 'gatewaykit' ) );
			}
		}
	}
	// phpcs:enable
}

// Initialize callback handling
add_action( 'template_redirect', 'gatewaykit_handle_payment_callback', 1 );

/**
 * Handle incoming payment webhook events (async confirmations).
 *
 * Triggered by the query var `?gatewaykit_webhook=<gateway>`. Currently handles
 * PayPal's PAYMENT.CAPTURE.COMPLETED event. The event is verified server-side
 * by re-fetching the order from PayPal (see GatewayKit_PayPal_Gateway) so the
 * raw inbound payload is never trusted blindly.
 */
function gatewaykit_handle_webhook() {
	// Public webhook endpoint. No nonce: payloads are verified server-side
	// by each gateway (e.g. re-fetching the PayPal order) + rate limited.
	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	if ( ! isset( $_GET['gatewaykit_webhook'] ) ) {
		return;
	}

	$gateway_id = sanitize_key( wp_unslash( $_GET['gatewaykit_webhook'] ) );
	// phpcs:enable

	// Webhooks are open endpoints by nature; apply rate limiting to limit abuse.
	$rate_limiter = GatewayKit_Rate_Limiter::get_instance();
	$rate_check   = $rate_limiter->check_rate_limit( 'callback' );
	if ( is_wp_error( $rate_check ) ) {
		status_header( 429 );
		exit;
	}

	$gateway_manager = GatewayKit_Gateway_Manager::get_instance();
	$gateway         = $gateway_manager->get_gateway( $gateway_id );

	if ( ! $gateway ) {
		status_header( 404 );
		exit;
	}

	// Raw webhook payload: do NOT sanitize $raw_body before json_decode.
	// Gateways (e.g. PayPal) verify webhook signatures/HMACs computed over the
	// exact raw bytes, and sanitizing the body would corrupt the signature.
	// Instead we validate the decoded structure (is_array() below) and sanitize
	// individual fields when they are read out for use.
	$raw_body = file_get_contents( 'php://input' );
	$event    = json_decode( $raw_body, true );

	// Some gateways (e.g. Mollie, CoinGate) send form-encoded webhooks rather
	// than JSON. Normalize to an empty array so the handlers below still run;
	// those gateways read their fields directly from $_POST in their own
	// webhook handlers (hooked via gatewaykit_handle_gateway_webhook).
	if ( ! is_array( $event ) ) {
		$event = array();
	} else {
		// Sanitize top-level scalar fields after JSON decode. The raw body
		// ($raw_body) is intentionally never sanitized — gateways verify
		// webhook signatures/HMACs over the exact bytes. Nested structures
		// (e.g. $event['resource']) are left for individual gateway handlers
		// which validate those against their own schemas.
		$event = array_map(
			function ( $value ) {
				return is_string( $value ) ? sanitize_text_field( $value ) : $value;
			},
			$event
		);
	}

	/**
	 * Fires before a webhook event is processed. Allows custom logging or
	 * signature verification per gateway.
	 *
	 * @param array  $event      Decoded webhook payload.
	 * @param string $gateway_id Gateway identifier.
	 */
	do_action( 'gatewaykit_webhook_received', $event, $gateway_id );

	if ( 'paypal' === $gateway_id && $gateway instanceof GatewayKit_PayPal_Gateway ) {
		$result = $gateway->verify_webhook_event( $event, $raw_body );

		if ( 'success' === $result['status'] && ! empty( $result['order_id'] ) ) {
			$transaction = GatewayKit_Transaction_Model::find_by_authority( $result['order_id'] );

			if ( $transaction ) {
				// Only complete transactions that haven't already been settled.
				if ( in_array( $transaction->status, array( 'pending', 'processing' ), true ) ) {
					$transaction->update(
						array(
							'status'       => 'completed',
							'ref_id'       => isset( $result['ref_id'] ) ? $result['ref_id'] : '',
							'completed_at' => current_time( 'mysql' ),
						)
					);

					do_action( 'gatewaykit_payment_completed', $transaction );
				}

				status_header( 200 );
				exit;
			}

			GatewayKit_Logger::get_instance()->warning(
				'PayPal webhook: transaction not found for order',
				array( 'order_id' => $result['order_id'] )
			);
			// Acknowledge so PayPal stops retrying even if we can't match it.
			status_header( 200 );
			exit;
		}

	// Unsupported event types / verification failures still get a 200 so
	// PayPal does not keep hammering us, but we log them.
	GatewayKit_Logger::get_instance()->info(
		'PayPal webhook not actioned',
		array( 'result' => $result, 'event_type' => isset( $event['event_type'] ) ? sanitize_text_field( $event['event_type'] ) : '' )
	);
	status_header( 200 );
	exit;
}

	// NOWPayments (Lite) — handle directly to keep it out of the Pro action.
	if ( 'nowpayments' === $gateway_id && $gateway instanceof GatewayKit_NOWPayments_Gateway ) {
		$gateway->handle_webhook(); // Exits internally.
		return;
	}

	/**
	 * Allow other gateways (Pro) to handle their own webhook events.
	 *
	 * @param array                        $event    Decoded webhook payload.
	 * @param GatewayKit_Payment_Gateway_Interface $gateway Gateway instance.
	 */
	do_action( 'gatewaykit_handle_gateway_webhook', $event, $gateway, $gateway_id );

	status_header( 200 );
	exit;
}
add_action( 'init', 'gatewaykit_handle_webhook', 1 );

