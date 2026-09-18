<?php
/**
 * PayPal Payment Gateway
 *
 * Implements the PayPal Orders REST API v2 (no legacy NVP/SOAP).
 *
 * Flow: Create Order -> redirect to PayPal approval -> return to site ->
 * capture/verify payment. The PAYMENT.CAPTURE.COMPLETED webhook is handled
 * in includes/callback-handler.php as an async confirmation path.
 *
 * @package GatewayKit
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PayPal Gateway Class
 */
class GatewayKit_PayPal_Gateway extends GatewayKit_Abstract_Payment_Gateway {

	/**
	 * Sandbox API base URL.
	 */
	const SANDBOX_BASE = 'https://api-m.sandbox.paypal.com';

	/**
	 * Live API base URL.
	 */
	const LIVE_BASE = 'https://api-m.paypal.com';

	/**
	 * Get gateway ID.
	 *
	 * @return string
	 */
	protected function get_gateway_id() {
		return 'paypal';
	}

	/**
	 * Build the cache key for the PayPal access token transient.
	 *
	 * Scoped by environment only (sandbox/live). A single WordPress install
	 * can only have ONE active PayPal configuration at a time, and transients
	 * are already install-scoped, so additional per-client_id hashing is
	 * unnecessary — and would make invalidation require a direct DB query to
	 * find the previously-written key (see invalidate_token_cache()).
	 *
	 * @return string
	 */
	private function get_token_cache_key() {
		$env = $this->is_sandbox() ? 'sb' : 'live';
		return 'gatewaykit_paypal_token_' . $env;
	}

	/**
	 * Invalidate any cached PayPal access token transients for this site.
	 *
	 * Called from Gateway_Manager::update_gateway_settings() after PayPal
	 * settings change so a stale token (issued under the previous credentials)
	 * is never reused.
	 *
	 * Because get_token_cache_key() is deterministic per environment, we can
	 * delete the exact known keys directly — no direct DB query required
	 * (AVOID-008: never enumerate transients via a LIKE query on wp_options).
	 */
	public static function invalidate_token_cache() {
		// Delete both environment keys so toggling sandbox <-> live is covered.
		delete_transient( 'gatewaykit_paypal_token_sb' );
		delete_transient( 'gatewaykit_paypal_token_live' );
	}

	/**
	 * Decrypt the client secret after loading settings from the database.
	 *
	 * The secret is stored encrypted at rest (see validate_settings_input).
	 * Here it is restored to plaintext for runtime use.
	 */
	protected function load_settings() {
		parent::load_settings();
		if ( ! empty( $this->settings['client_secret'] ) ) {
			$this->settings['client_secret'] = GatewayKit_Crypto::decrypt( $this->settings['client_secret'] );
		}
	}

	/**
	 * Encrypt the client secret before persisting settings.
	 *
	 * Only the client secret is sensitive; client_id is a public identifier
	 * and does not require encryption.
	 *
	 * @param array $settings Raw settings input.
	 * @return array|WP_Error Validated settings or error.
	 */
	public function validate_settings_input( $settings ) {
		$validated = parent::validate_settings_input( $settings );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		if ( isset( $validated['client_secret'] ) && '' !== $validated['client_secret'] ) {
			$validated['client_secret'] = GatewayKit_Crypto::encrypt( $validated['client_secret'] );
		}

		return $validated;
	}

	/**
	 * Get gateway display name.
	 *
	 * @return string
	 */
	public function get_gateway_name() {
		return __( 'PayPal', 'gatewaykit' );
	}

	/**
	 * Currencies supported by PayPal (REST API v2).
	 *
	 * Derived from the single source of truth
	 * (Gateway_Manager::get_paypal_currencies()) so the two never drift.
	 *
	 * @return string[]
	 */
	public function get_supported_currencies() {
		return array_keys( GatewayKit_Gateway_Manager::get_paypal_currencies() );
	}

	/**
	 * Required settings keys. Sandbox mode is optional, so only the
	 * credentials are mandatory.
	 *
	 * @return array
	 */
	protected function get_required_settings() {
		return array( 'client_id', 'client_secret' );
	}

	/**
	 * Admin UI settings fields.
	 *
	 * @return array
	 */
	public function get_settings_fields() {
		return array(
			'sandbox_mode'  => array(
				'type'        => 'checkbox',
				'label'       => __( 'Sandbox Mode', 'gatewaykit' ),
				'description' => __( 'Use PayPal sandbox (test) credentials.', 'gatewaykit' ),
			),
			'client_id'     => array(
				'type'        => 'text',
				'label'       => __( 'Client ID', 'gatewaykit' ),
				'description' => __( 'Your PayPal REST app client ID.', 'gatewaykit' ),
			),
			'client_secret' => array(
				'type'        => 'password',
				'label'       => __( 'Client Secret', 'gatewaykit' ),
				'description' => __( 'Your PayPal REST app client secret.', 'gatewaykit' ),
			),
			'webhook_id'    => array(
				'type'        => 'text',
				'label'       => __( 'Webhook ID', 'gatewaykit' ),
				'description' => __( 'The PayPal Webhook ID (from your REST app, under Webhooks). Required for verifying webhook signatures. Leave empty to fall back to order re-fetch verification (less secure).', 'gatewaykit' ),
			),
		);
	}

	/**
	 * Gateway metadata for the admin UI.
	 *
	 * @return array
	 */
	public function get_gateway_info() {
		return array(
			'description' => __( 'Accept payments via PayPal using the Orders REST API v2.', 'gatewaykit' ),
		);
	}

	/**
	 *
	 * @param float $amount Amount.
	 * @return float
	 */
	protected function format_amount( $amount ) {
		return round( (float) $amount, 2 );
	}

	/**
	 * Zero-decimal currencies per PayPal REST API v2 spec.
	 *
	/**
	 * Render the amount as the value string PayPal expects for the active
	 * currency: integer for zero-decimal currencies, 2-decimal string
	 * otherwise.
	 *
	 * @param float $amount Amount.
	 * @return string
	 */
	private function format_value( $amount ) {
		$currency = $this->get_currency();
		if ( $this->is_zero_decimal_currency( $currency ) ) {
			return (string) (int) round( (float) $amount );
		}
		return number_format( (float) $amount, 2, '.', '' );
	}

	/**
	 * Build the base API URL for the active mode.
	 *
	 * @return string
	 */
	private function get_api_base() {
		return $this->is_sandbox() ? self::SANDBOX_BASE : self::LIVE_BASE;
	}

	/**
	 * Build the PayPal approval (checkout) URL for an order token.
	 *
	 * @param string $order_id PayPal order ID.
	 * @return string
	 */
	private function get_approval_url_for_token( $order_id ) {
		$host = $this->is_sandbox() ? 'https://www.sandbox.paypal.com' : 'https://www.paypal.com';
		return add_query_arg( array( 'token' => $order_id ), $host . '/checkoutnow' );
	}

	/**
	 * Obtain (and persistently cache) a PayPal access token.
	 *
	 * The token is stored in a WordPress transient keyed by environment only
	 * (sandbox/live — see get_token_cache_key()) so it survives across PHP
	 * requests. PayPal tokens last ~9h by default; we re-use a cached token
	 * until 60s before its expiry. When credentials change, the deterministic
	 * key lets invalidate_token_cache() delete the exact row without a direct
	 * DB query.
	 *
	 * @return string|WP_Error Access token or error.
	 */
	private function get_access_token() {
		$client_id     = $this->get_setting( 'client_id' );
		$client_secret = $this->get_setting( 'client_secret' );

		if ( empty( $client_id ) || empty( $client_secret ) ) {
			return new WP_Error( 'paypal_missing_credentials', __( 'PayPal client ID or secret is not configured.', 'gatewaykit' ) );
		}

		// Reuse a cached token that is still valid (with a safety margin).
		$cache_key = $this->get_token_cache_key();
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached )
			&& ! empty( $cached['token'] )
			&& isset( $cached['expires_at'] )
			&& time() < ( (int) $cached['expires_at'] - 60 )
		) {
			return $cached['token'];
		}

		$url = $this->get_api_base() . '/v1/oauth2/token';

		$args = array(
			'method'  => 'POST',
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( $client_id . ':' . $client_secret ),
				'Content-Type'  => 'application/x-www-form-urlencoded',
			),
			'body'    => 'grant_type=client_credentials',
			'timeout' => 30,
		);

		$response = wp_remote_post( $url, $args );

		if ( is_wp_error( $response ) ) {
			$this->log( 'error', 'PayPal token request failed: ' . $response->get_error_message() );
			return new WP_Error( 'paypal_request_failed', __( 'Could not connect to PayPal. Please try again later.', 'gatewaykit' ) );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== (int) $code || empty( $body['access_token'] ) ) {
			$this->log(
				'error',
				'PayPal token request returned non-200',
				array(
					'status' => $code,
					'body'   => $body,
				)
			);
			return new WP_Error( 'paypal_auth_failed', __( 'PayPal authentication failed. Check your client ID and secret.', 'gatewaykit' ) );
		}

		$token   = $body['access_token'];
		$expires = isset( $body['expires_in'] ) ? (int) $body['expires_in'] : 32400;

		set_transient(
			$cache_key,
			array(
				'token'      => $token,
				'expires_at' => time() + $expires,
			),
			max( 60, $expires - 60 )
		);

		return $token;
	}

	/**
	 * Perform an authenticated PayPal API call (Orders v2).
	 *
	 * @param string $path          API path (e.g. /v2/checkout/orders).
	 * @param array  $body          Request body.
	 * @param string $method        HTTP method.
	 * @param array  $extra_headers Optional extra headers (e.g. Prefer).
	 * @return array|WP_Error Decoded response body or error.
	 */
	private function api_request( $path, $body = array(), $method = 'POST', $extra_headers = array() ) {
		$token = $this->get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$url = $this->get_api_base() . $path;

		$headers = array(
			'Authorization' => 'Bearer ' . $token,
			'Content-Type'  => 'application/json',
		);
		if ( is_array( $extra_headers ) && ! empty( $extra_headers ) ) {
			$headers = array_merge( $headers, $extra_headers );
		}

		$args = array(
			'method'  => $method,
			'headers' => $headers,
			'timeout' => 30,
		);

		if ( in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			$this->log( 'error', 'PayPal API request failed: ' . $response->get_error_message(), array( 'path' => $path ) );
			return new WP_Error( 'paypal_request_failed', __( 'Could not connect to PayPal. Please try again later.', 'gatewaykit' ) );
		}

		$code        = wp_remote_retrieve_response_code( $response );
		$raw_body    = wp_remote_retrieve_body( $response );
		$decoded     = json_decode( $raw_body, true );
		$decoded_raw = ( null === $decoded ) ? $raw_body : $decoded;

		if ( $code < 200 || $code >= 300 ) {
			$this->log(
				'error',
				'PayPal API error',
				array(
					'path'   => $path,
					'status' => $code,
					'body'   => $decoded_raw,
				)
			);
			$message = $this->extract_error_message( $decoded_raw );
			return new WP_Error( 'paypal_api_error', $message );
		}

		return array(
			'status' => $code,
			'body'   => $decoded_raw,
		);
	}

	/**
	 * Extract a human-readable message from a PayPal error payload.
	 *
	 * @param mixed $body Decoded response body.
	 * @return string
	 */
	private function extract_error_message( $body ) {
		$message = __( 'PayPal request failed. Please try again or contact support.', 'gatewaykit' );

		if ( is_array( $body ) ) {
			if ( isset( $body['message'] ) && is_string( $body['message'] ) ) {
				$message = $body['message'];
			} elseif ( isset( $body['error_description'] ) && is_string( $body['error_description'] ) ) {
				$message = $body['error_description'];
			} elseif ( isset( $body['details'][0]['description'] ) && is_string( $body['details'][0]['description'] ) ) {
				$message = $body['details'][0]['description'];
			}
		}

		return $message;
	}

	/**
	 * Process payment: create a PayPal order and return its approval URL.
	 *
	 * @param float  $amount       Payment amount.
	 * @param string $description  Payment description.
	 * @param string $callback_url Callback/return URL for verification.
	 * @param array  $user_data    User data array.
	 * @return array Payment result with status, authority, redirect_url.
	 */
	public function process_payment( $amount, $description, $callback_url, $user_data ) {
		$formatted = $this->format_amount( $amount );

		if ( $formatted <= 0 ) {
			return array(
				'status'        => 'error',
				'error_type'    => 'invalid_amount',
				'error_message' => __( 'Payment amount must be greater than zero.', 'gatewaykit' ),
			);
		}

		$currency   = $this->get_currency();
		$return_url = $callback_url;
		// Carry the transaction receipt token on cancel so the callback handler
		// can resolve the order and route to the configured failure URL.
		$cancel_args = array( 'gatewaykit_cancel' => '1' );
		if ( ! empty( $user_data['receipt_token'] ) ) {
			$cancel_args['token'] = sanitize_text_field( $user_data['receipt_token'] );
		}
		$cancel_url = add_query_arg( $cancel_args, $callback_url );

		$order_body = array(
			'intent'         => 'CAPTURE',
			'purchase_units' => array(
				array(
					'amount' => array(
						'currency_code' => $currency,
						'value'         => $this->format_value( $formatted ),
					),
				),
			),
			'payment_source' => array(
				'paypal' => array(
					'experience_context' => array(
						'return_url'  => $return_url,
						'cancel_url'  => $cancel_url,
						'user_action' => 'PAY_NOW',
					),
				),
			),
		);

		if ( ! empty( $description ) ) {
			$order_body['purchase_units'][0]['description'] = mb_substr( (string) $description, 0, 127 );
		}

		$invoice = '';
		if ( ! empty( $user_data['transaction_ref'] ) ) {
			$invoice = sanitize_text_field( $user_data['transaction_ref'] );
		}
		if ( empty( $invoice ) ) {
			$invoice = 'gk-' . wp_generate_password( 12, false );
		}
		$order_body['purchase_units'][0]['invoice_id'] = $invoice;

		$response = $this->api_request( '/v2/checkout/orders', $order_body, 'POST' );

		if ( is_wp_error( $response ) ) {
			return array(
				'status'        => 'error',
				'error_type'    => 'gateway',
				'error_message' => $response->get_error_message(),
			);
		}

		$body     = isset( $response['body'] ) ? $response['body'] : array();
		$order_id = isset( $body['id'] ) ? $body['id'] : '';

		// The approve link rel differs by create-order path: "approve" when no
		// payment_source is passed, "payer-action" when it is (our flow). Try
		// both before falling back to the synthesized checkoutnow URL.
		$approve = $this->find_link( $body, 'approve' );
		if ( '' === $approve ) {
			$approve = $this->find_link( $body, 'payer-action' );
		}

		if ( empty( $order_id ) ) {
			return array(
				'status'        => 'error',
				'error_type'    => 'gateway',
				'error_message' => __( 'PayPal did not return an order ID.', 'gatewaykit' ),
			);
		}

		$redirect_url = ! empty( $approve ) ? $approve : $this->get_approval_url_for_token( $order_id );

		// Cache the approval URL so get_redirect_url() can resolve it.
		set_transient( 'gatewaykit_paypal_redirect_' . $order_id, $redirect_url, DAY_IN_SECONDS );

		$this->log(
			'info',
			'PayPal order created',
			array(
				'order_id' => $order_id,
				'amount'   => $formatted,
				'currency' => $currency,
			)
		);

		return array(
			'status'       => 'success',
			'authority'    => $order_id,
			'redirect_url' => $redirect_url,
		);
	}

	/**
	 * Verify (and capture) a payment after the buyer returns from PayPal.
	 *
	 * @param string $authority PayPal order ID.
	 * @param float  $amount    Expected payment amount.
	 * @return array Verification result with status + ref_id.
	 */
	public function verify_payment( $authority, $amount = 0 ) {
		$authority = sanitize_text_field( (string) $authority );

		if ( empty( $authority ) ) {
			return array(
				'status'        => 'failed',
				'error_message' => __( 'Missing PayPal order ID.', 'gatewaykit' ),
			);
		}

		// Fetch the current order state first (capture is idempotent-fragile).
		$order = $this->api_request( '/v2/checkout/orders/' . rawurlencode( $authority ), array(), 'GET' );

		if ( is_wp_error( $order ) ) {
			return array(
				'status'        => 'failed',
				'error_message' => $order->get_error_message(),
			);
		}

		$order_body = isset( $order['body'] ) ? $order['body'] : array();
		$status     = isset( $order_body['status'] ) ? $order_body['status'] : '';

		// If still APPROVED (buyer approved but not yet captured), capture it.
		if ( 'APPROVED' === $status ) {
			// Prefer: return=representation makes PayPal return the FULL order
			// body (incl. purchase_units.payments.captures[]) instead of a
			// minimal one, so the amount/capture-id extraction below works.
			$capture = $this->api_request(
				'/v2/checkout/orders/' . rawurlencode( $authority ) . '/capture',
				array(),
				'POST',
				array( 'Prefer' => 'return=representation' )
			);

			if ( is_wp_error( $capture ) ) {
				return array(
					'status'        => 'failed',
					'error_message' => $capture->get_error_message(),
				);
			}

			$order_body = isset( $capture['body'] ) ? $capture['body'] : array();
			$status     = isset( $order_body['status'] ) ? $order_body['status'] : '';
		}

		if ( 'COMPLETED' !== $status ) {
			return array(
				'status'        => 'failed',
				/* translators: %s: PayPal payment status */
				'error_message' => sprintf( __( 'PayPal payment not completed (status: %s).', 'gatewaykit' ), $status ),
			);
		}

		// Validate the captured amount against the expected amount.
		// Use a numeric comparison so zero-decimal (integer) and standard
		// (2-decimal) values both validate correctly.
		$captured_value = $this->get_captured_amount( $order_body );
		$expected       = (float) $this->format_amount( $amount );

		if ( '' === $captured_value ) {
			// A "COMPLETED" PayPal order MUST expose a capture record. Missing
			// capture details means we cannot prove the amount was charged —
			// refuse rather than silently accept (closes a previous bypass
			// where empty $captured_value caused the check below to skip).
			$this->log( 'error', 'PayPal completed order has no captured amount record', array( 'order_id' => $authority ) );
			return array(
				'status'        => 'failed',
				'error_message' => __( 'PayPal payment capture details are missing.', 'gatewaykit' ),
			);
		}

		if ( abs( (float) $captured_value - $expected ) > 0.01 ) {
			$this->log(
				'error',
				'PayPal amount mismatch',
				array(
					'expected' => $expected,
					'captured' => $captured_value,
				)
			);
			return array(
				'status'        => 'failed',
				'error_message' => __( 'PayPal payment amount does not match the order amount.', 'gatewaykit' ),
			);
		}

		$ref_id = $this->get_capture_id( $order_body );

		$this->log(
			'info',
			'PayPal payment verified',
			array(
				'order_id' => $authority,
				'ref_id'   => $ref_id,
			)
		);

		return array(
			'status' => 'success',
			'ref_id' => $ref_id,
		);
	}

	/**
	 * Verify a PAYMENT.CAPTURE.COMPLETED webhook event.
	 *
	 * Two-layer verification:
	 *   1. Signature verification via PayPal's official
	 *      POST /v1/notifications/verify-webhook-signature endpoint — proves
	 *      the event actually originated from PayPal (not a forged POST).
	 *      Requires the optional 'webhook_id' setting. When it is empty, we
	 *      fall back to layer 2 only and log a warning.
	 *   2. Authoritative re-fetch of the order from PayPal — confirms the
	 *      current order state (defense-in-depth against payload tampering).
	 *
	 * @param array  $event     Decoded webhook event payload.
	 * @param string $raw_body  Raw request body bytes (kept for future use
	 *                          by signature-aware HTTP libraries; PayPal's
	 *                          signature endpoint uses the decoded event).
	 * @return array Verification result with status + ref_id + order_id.
	 */
	public function verify_webhook_event( $event, $raw_body = '' ) {
		if ( ! is_array( $event ) ) {
			return array(
				'status'        => 'failed',
				'error_message' => __( 'Invalid webhook payload.', 'gatewaykit' ),
			);
		}

		$event_type = isset( $event['event_type'] ) ? $event['event_type'] : '';

		if ( 'PAYMENT.CAPTURE.COMPLETED' !== $event_type ) {
			// translators: %s: webhook event type.
			$error_message = sprintf( __( 'Unsupported webhook event: %s', 'gatewaykit' ), $event_type );

			return array(
				'status'        => 'ignored',
				'error_message' => $error_message,
			);
		}

		// Layer 1: signature verification.
		$sig = $this->verify_webhook_signature( $event, $raw_body );
		if ( is_wp_error( $sig ) ) {
			$this->log( 'error', 'PayPal webhook signature verification error', array( 'error' => $sig->get_error_message() ) );
			// F5: a missing-webhook-id configuration error is surfaced with a
			// distinct status so the dispatcher can reject the webhook (403)
			// instead of acknowledging it.
			return array(
				'status'        => 'config_error',
				'error_code'    => $sig->get_error_code(),
				'error_message' => $sig->get_error_message(),
			);
		}
		if ( true !== $sig ) {
			$this->log( 'error', 'PayPal webhook signature invalid — rejecting forged or tampered payload' );
			return array(
				'status'        => 'failed',
				'error_message' => __( 'PayPal webhook signature verification failed.', 'gatewaykit' ),
			);
		}

		// The order id can be in resource.supplementary_data.order_id or in links.
		$resource   = isset( $event['resource'] ) ? $event['resource'] : array();
		$order_id   = '';
		$capture_id = isset( $resource['id'] ) ? $resource['id'] : '';

		if ( isset( $resource['supplementary_data']['order_id'] ) ) {
			$order_id = $resource['supplementary_data']['order_id'];
		} elseif ( ! empty( $resource['links'] ) && is_array( $resource['links'] ) ) {
			foreach ( $resource['links'] as $link ) {
				if ( isset( $link['rel'] ) && 'up' === $link['rel'] && ! empty( $link['href'] ) ) {
					$order_id = basename( wp_parse_url( $link['href'], PHP_URL_PATH ) );
					break;
				}
			}
		}

		if ( empty( $order_id ) ) {
			return array(
				'status'        => 'failed',
				'error_message' => __( 'Could not determine PayPal order from webhook.', 'gatewaykit' ),
			);
		}

		// Layer 2: Authoritative check — re-fetch the order from PayPal.
		$order = $this->api_request( '/v2/checkout/orders/' . rawurlencode( $order_id ), array(), 'GET' );

		if ( is_wp_error( $order ) ) {
			return array(
				'status'        => 'failed',
				'error_message' => $order->get_error_message(),
			);
		}

		$order_body = isset( $order['body'] ) ? $order['body'] : array();
		$status     = isset( $order_body['status'] ) ? $order_body['status'] : '';

		if ( 'COMPLETED' !== $status ) {
			return array(
				'status'        => 'failed',
				/* translators: %s: PayPal order status */
				'error_message' => sprintf( __( 'PayPal order not completed (status: %s).', 'gatewaykit' ), $status ),
			);
		}

		$ref_id = $capture_id ? $capture_id : $this->get_capture_id( $order_body );

		$this->log(
			'info',
			'PayPal webhook verified',
			array(
				'order_id' => $order_id,
				'ref_id'   => $ref_id,
			)
		);

		return array(
			'status'   => 'success',
			'ref_id'   => $ref_id,
			'order_id' => $order_id,
		);
	}

	/**
	 * Verify a PayPal webhook signature via the official endpoint.
	 *
	 * Uses POST /v1/notifications/verify-webhook-signature with the
	 * transmission headers PayPal sends (PAYPAL-AUTH-ALGO, PAYPAL-CERT-URL,
	 * PAYPAL-TRANSMISSION-ID, PAYPAL-TRANSMISSION-SIG, PAYPAL-TRANSMISSION-TIME).
	 * Returns true only when PayPal responds with verification_status=SUCCESS.
	 *
	 * Security (F5): the `webhook_id` is mandatory. When it is empty the
	 * webhook is rejected with a WP_Error so the dispatcher returns 403
	 * instead of silently accepting forged payloads.
	 *
	 * N4: PayPal computes the webhook signature over the EXACT bytes it
	 * posted, so the `webhook_event` sent to its verify endpoint must be the
	 * raw, unsanitized decoded payload. The caller's `$event` may have been
	 * run through sanitize_text_field() (callback-handler.php) which corrupts
	 * the signature; we therefore re-decode `$raw_body` here and use that.
	 *
	 * @param array  $event     Decoded webhook event payload (possibly
	 *                          sanitized — used only as a fallback).
	 * @param string $raw_body  Raw request body bytes; decoded unsanitized and
	 *                          sent to PayPal's verify endpoint.
	 * @return bool|WP_Error True on verified, false on bad signature,
	 *                       WP_Error on API failure or missing configuration.
	 */
	private function verify_webhook_signature( $event, $raw_body = '' ) {
		$webhook_id = $this->get_setting( 'webhook_id' );

		if ( empty( $webhook_id ) ) {
			// F5: a missing Webhook ID is a configuration error — refuse to
			// verify. Returning a WP_Error (code 'paypal_webhook_id_missing')
			// lets verify_webhook_event() and the dispatcher surface a 403
			// and the merchant fix the gateway settings.
			$this->log( 'error', 'PayPal webhook rejected: no webhook_id configured (cannot verify signature)' );
			return new WP_Error(
				'paypal_webhook_id_missing',
				__( 'PayPal Webhook ID is not configured — webhook signature cannot be verified.', 'gatewaykit' )
			);
		}

		// PayPal transmission headers. Read raw, then wp_unslash. These are
		// opaque tokens forwarded as-is to PayPal's verify endpoint.
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- opaque tokens forwarded to PayPal's verify endpoint as-is; sanitizing would corrupt the signature check.
		$headers = array(
			'auth_algo'         => isset( $_SERVER['HTTP_PAYPAL_AUTH_ALGO'] ) ? wp_unslash( $_SERVER['HTTP_PAYPAL_AUTH_ALGO'] ) : '',
			'cert_url'          => isset( $_SERVER['HTTP_PAYPAL_CERT_URL'] ) ? wp_unslash( $_SERVER['HTTP_PAYPAL_CERT_URL'] ) : '',
			'transmission_id'   => isset( $_SERVER['HTTP_PAYPAL_TRANSMISSION_ID'] ) ? wp_unslash( $_SERVER['HTTP_PAYPAL_TRANSMISSION_ID'] ) : '',
			'transmission_sig'  => isset( $_SERVER['HTTP_PAYPAL_TRANSMISSION_SIG'] ) ? wp_unslash( $_SERVER['HTTP_PAYPAL_TRANSMISSION_SIG'] ) : '',
			'transmission_time' => isset( $_SERVER['HTTP_PAYPAL_TRANSMISSION_TIME'] ) ? wp_unslash( $_SERVER['HTTP_PAYPAL_TRANSMISSION_TIME'] ) : '',
		);
		// phpcs:enable

		foreach ( $headers as $key => $value ) {
			if ( '' === $value ) {
				/* translators: %s: missing header key name */
				return new WP_Error( 'paypal_webhook_missing_header', sprintf( __( 'Missing PayPal webhook header: %s', 'gatewaykit' ), $key ) );
			}
		}

		// N4: re-decode the raw body so the webhook_event forwarded to PayPal
		// is byte-identical to what PayPal sent. The sanitized $event passed
		// in from the dispatcher is intentionally NOT used here — running the
		// top-level fields through sanitize_text_field() mutates the bytes
		// (strips tags, collapses whitespace, entities) and breaks the
		// signature check. Fall back to $event only when no raw body exists.
		$raw_event = '' !== $raw_body ? json_decode( $raw_body, true ) : null;
		if ( ! is_array( $raw_event ) ) {
			$raw_event = is_array( $event ) ? $event : array();
		}

		$body = array(
			'auth_algo'         => $headers['auth_algo'],
			'cert_url'          => $headers['cert_url'],
			'transmission_id'   => $headers['transmission_id'],
			'transmission_sig'  => $headers['transmission_sig'],
			'transmission_time' => $headers['transmission_time'],
			'webhook_id'        => $webhook_id,
			'webhook_event'     => $raw_event,
		);

		$response = $this->api_request( '/v1/notifications/verify-webhook-signature', $body, 'POST' );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$resp_body = isset( $response['body'] ) ? $response['body'] : array();
		$status    = isset( $resp_body['verification_status'] ) ? $resp_body['verification_status'] : '';

		return ( 'SUCCESS' === $status );
	}

	/**
	 * Get the approval redirect URL for an order.
	 *
	 * Resolves the cached approval URL, falling back to the tokenized
	 * checkout URL when the cache has expired.
	 *
	 * @param string $authority Order ID.
	 * @return string
	 */
	public function get_redirect_url( $authority ) {
		$authority = sanitize_text_field( (string) $authority );

		$cached = get_transient( 'gatewaykit_paypal_redirect_' . $authority );
		if ( ! empty( $cached ) ) {
			return $cached;
		}

		return $this->get_approval_url_for_token( $authority );
	}

	/**
	 * Find a HATEOAS link by rel from an order response.
	 *
	 * @param array  $body Order response body.
	 * @param string $rel  Link relation (e.g. approve, capture).
	 * @return string Empty string when not found.
	 */
	private function find_link( $body, $rel ) {
		if ( empty( $body['links'] ) || ! is_array( $body['links'] ) ) {
			return '';
		}

		foreach ( $body['links'] as $link ) {
			if ( isset( $link['rel'], $link['href'] ) && $link['rel'] === $rel ) {
				return $link['href'];
			}
		}

		return '';
	}

	/**
	 * Read the captured amount value from an order/capture response.
	 *
	 * Returns ONLY the captured amount — never the order amount. Falling back
	 * to the order amount would mask partial-capture mismatches. Callers
	 * (verify_payment) must treat an empty return as "amount could not be
	 * validated" and refuse the order rather than silently accepting it.
	 *
	 * @param array $body Response body.
	 * @return string Amount string (empty when no capture present).
	 */
	private function get_captured_amount( $body ) {
		if ( isset( $body['purchase_units'][0]['payments']['captures'][0]['amount']['value'] ) ) {
			return $body['purchase_units'][0]['payments']['captures'][0]['amount']['value'];
		}

		return '';
	}

	/**
	 * Read the capture id from an order/capture response.
	 *
	 * @param array $body Response body.
	 * @return string
	 */
	private function get_capture_id( $body ) {
		if ( isset( $body['purchase_units'][0]['payments']['captures'][0]['id'] ) ) {
			return $body['purchase_units'][0]['payments']['captures'][0]['id'];
		}

		return '';
	}
}
