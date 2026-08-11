<?php
/**
 * Razorpay Payment Gateway
 *
 * Creates a Razorpay order -> buyer completes payment via Razorpay JS/Hosted
 * Checkout -> returns to the callback URL -> the order is verified by
 * re-fetching it. Razorpay's async webhook is handled via the shared webhook
 * endpoint.
 *
 * API key id and key secret are encrypted at rest with GatewayKit_Crypto.
 *
 * @package GatewayKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Razorpay Gateway Class.
 */
class GatewayKit_Razorpay_Gateway extends GatewayKit_Abstract_Payment_Gateway {

	/**
	 * API base URL.
	 */
	const API_BASE = 'https://api.razorpay.com/v1';

	/**
	 * Get gateway ID.
	 *
	 * @return string
	 */
	protected function get_gateway_id() {
		return 'razorpay';
	}

	/**
	 * Gateway display name.
	 *
	 * @return string
	 */
	public function get_gateway_name() {
		return __( 'Razorpay', 'gatewaykit' );
	}

	/**
	 * Currencies supported by Razorpay.
	 *
	 * @return string[]
	 */
	public function get_supported_currencies() {
		return array(
			'INR', 'USD', 'EUR', 'GBP', 'AED', 'SGD', 'MYR', 'AUD', 'CAD', 'JPY',
		);
	}

	/**
	 * Required settings.
	 *
	 * @return array
	 */
	protected function get_required_settings() {
		return array( 'key_id' );
	}

	/**
	 * Admin UI settings fields.
	 *
	 * @return array
	 */
	public function get_settings_fields() {
		return array(
			'sandbox_mode' => array(
				'type'        => 'checkbox',
				'label'       => __( 'Test Mode', 'gatewaykit' ),
				'description' => __( 'Use your Razorpay test (sandbox) API keys.', 'gatewaykit' ),
			),
			'key_id' => array(
				'type'        => 'text',
				'label'       => __( 'Key ID', 'gatewaykit' ),
				'description' => __( 'Your Razorpay Key ID (starts with rzp_).', 'gatewaykit' ),
			),
			'key_secret' => array(
				'type'        => 'password',
				'label'       => __( 'Key Secret', 'gatewaykit' ),
				'description' => __( 'Your Razorpay Key Secret. Stored encrypted.', 'gatewaykit' ),
			),
			'webhook_secret' => array(
				'type'        => 'password',
				'label'       => __( 'Webhook Secret', 'gatewaykit' ),
				'description' => __( 'Enter the Webhook Secret from your Razorpay Dashboard → Settings → Webhooks. This is separate from your Key Secret.', 'gatewaykit' ),
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
			'description' => __( 'Accept UPI, cards, netbanking, wallets and more through Razorpay.', 'gatewaykit' ),
		);
	}

	/**
	 * Decrypt the key secret after loading settings.
	 */
	protected function load_settings() {
		parent::load_settings();
		if ( ! empty( $this->settings['key_secret'] ) ) {
			$this->settings['key_secret'] = GatewayKit_Crypto::decrypt( $this->settings['key_secret'] );
		}
		if ( ! empty( $this->settings['webhook_secret'] ) ) {
			$this->settings['webhook_secret'] = GatewayKit_Crypto::decrypt( $this->settings['webhook_secret'] );
		}
	}

	/**
	 * Encrypt the key secret before persisting settings.
	 *
	 * @param array $settings Raw settings input.
	 * @return array|WP_Error
	 */
	public function validate_settings_input( $settings ) {
		$validated = parent::validate_settings_input( $settings );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}
		if ( isset( $validated['key_secret'] ) && '' !== $validated['key_secret'] ) {
			$validated['key_secret'] = GatewayKit_Crypto::encrypt( $validated['key_secret'] );
		}
		if ( isset( $validated['webhook_secret'] ) && '' !== $validated['webhook_secret'] ) {
			$validated['webhook_secret'] = GatewayKit_Crypto::encrypt( $validated['webhook_secret'] );
		}
		return $validated;
	}

	/**
	 * Razorpay expects amounts in the smallest currency unit (paise for INR,
	 * cents for others). Multiply the decimal amount by 100.
	 *
	 * @param float $amount Amount.
	 * @return int
	 */
	protected function format_amount( $amount ) {
		return (int) round( (float) $amount * 100 );
	}

	/**
	 * Get the configured Razorpay key id.
	 *
	 * @return string
	 */
	private function get_key_id() {
		return $this->get_setting( 'key_id', '' );
	}

	/**
	 * Get the decrypted Razorpay key secret.
	 *
	 * @return string
	 */
	private function get_key_secret() {
		return $this->get_setting( 'key_secret', '' );
	}

	/**
	 * Build the Basic auth header value from the configured key id / secret.
	 *
	 * @return string
	 */
	private function get_auth_header() {
		$credentials = $this->get_key_id() . ':' . $this->get_key_secret();
		return 'Basic ' . base64_encode( $credentials );
	}

	/**
	 * Build the webhook URL for this gateway.
	 *
	 * @return string
	 */
	private function get_webhook_url() {
		return add_query_arg( array( 'gatewaykit_webhook' => 'razorpay' ), home_url( '/' ) );
	}

	/**
	 * Perform an authenticated Razorpay API call (JSON over HTTP Basic).
	 *
	 * @param string $path   API path (e.g. /orders).
	 * @param array  $body   Request body.
	 * @param string $method HTTP method.
	 * @return array|WP_Error
	 */
	private function api_request( $path, $body = array(), $method = 'POST' ) {
		$key_id = $this->get_key_id();
		$secret = $this->get_key_secret();
		if ( '' === $key_id || '' === $secret ) {
			return new WP_Error( 'razorpay_missing_keys', __( 'Razorpay API credentials are not configured.', 'gatewaykit' ) );
		}

		$args = array(
			'method'  => $method,
			'headers' => array(
				'Authorization' => $this->get_auth_header(),
				'Content-Type'  => 'application/json',
			),
			'timeout' => 30,
		);

		if ( ! empty( $body ) && in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( self::API_BASE . $path, $args );

		if ( is_wp_error( $response ) ) {
			$this->log( 'error', 'Razorpay request failed: ' . $response->get_error_message(), array( 'path' => $path ) );
			return new WP_Error( 'razorpay_request_failed', __( 'Could not connect to Razorpay. Please try again later.', 'gatewaykit' ) );
		}

		$code    = wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$message = isset( $decoded['error']['description'] ) ? $decoded['error']['description'] : __( 'Razorpay request failed.', 'gatewaykit' );
			$this->log( 'error', 'Razorpay API error', array( 'path' => $path, 'status' => $code, 'body' => $decoded ) );
			return new WP_Error( 'razorpay_api_error', $message );
		}

		return $decoded;
	}

	/**
	 * Create a Razorpay order.
	 *
	 * @param float  $amount       Payment amount (decimal currency units).
	 * @param string $description  Payment description.
	 * @param string $callback_url Callback/return URL.
	 * @param array  $user_data    User data array (may include receipt_token).
	 * @return array Payment result.
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

		$currency = $this->get_currency();
		$receipt  = '';
		if ( ! empty( $user_data['receipt_token'] ) ) {
			$receipt = sanitize_text_field( (string) $user_data['receipt_token'] );
		}
		if ( '' === $receipt ) {
			$receipt = 'gk_' . wp_generate_password( 16, false );
		}

		// Razorpay does not surface the order id on the buyer redirect, so
		// generate a local reference token, embed it in the return URL, and map
		// it to the order id once created.
		$ref          = 'razorpay_' . wp_generate_password( 16, false );
		$redirect_url = add_query_arg( array( 'authority' => $ref ), $callback_url );

		$body = array(
			'amount'          => $formatted,
			'currency'        => $currency,
			'receipt'         => mb_substr( $receipt, 0, 40 ),
			'payment_capture' => 1,
		);

		if ( $description ) {
			$body['notes'] = array(
				'description' => mb_substr( (string) $description, 0, 255 ),
			);
		}

		$response = $this->api_request( '/orders', $body, 'POST' );

		if ( is_wp_error( $response ) ) {
			return array(
				'status'        => 'error',
				'error_type'    => 'gateway',
				'error_message' => $response->get_error_message(),
			);
		}

		$order_id = isset( $response['id'] ) ? $response['id'] : '';
		$status   = isset( $response['status'] ) ? $response['status'] : '';

		if ( '' === $order_id || ! in_array( $status, array( 'created', 'attempted', 'paid' ), true ) ) {
			return array(
				'status'        => 'error',
				'error_type'    => 'gateway',
				'error_message' => __( 'Razorpay did not return a valid order.', 'gatewaykit' ),
			);
		}

		// Persist the ref <-> order id mapping and cache the redirect URL.
		$ttl = WEEK_IN_SECONDS;
		set_transient( 'gatewaykit_razorpay_ref_' . $ref, $order_id, $ttl );
		set_transient( 'gatewaykit_razorpay_oid_' . $order_id, $ref, $ttl );
		set_transient( 'gatewaykit_razorpay_redirect_' . $ref, $redirect_url, DAY_IN_SECONDS );

		$this->log( 'info', 'Razorpay order created', array( 'order_id' => $order_id, 'ref' => $ref, 'amount' => $formatted, 'currency' => $currency ) );

		return array(
			'status'       => 'success',
			'authority'    => $ref,
			'redirect_url' => $redirect_url,
		);
	}

	/**
	 * Verify a Razorpay payment.
	 *
	 * @param string $authority Local reference token (or a raw Razorpay order id).
	 * @param float  $amount    Expected payment amount (decimal currency units).
	 * @return array Verification result.
	 */
	public function verify_payment( $authority, $amount = 0 ) {
		$authority = sanitize_text_field( (string) $authority );
		$order_id  = $this->resolve_order_id( $authority );

		if ( '' === $order_id ) {
			return array(
				'status'        => 'failed',
				'error_message' => __( 'Could not resolve the Razorpay order.', 'gatewaykit' ),
			);
		}

		$order = $this->api_request( '/orders/' . rawurlencode( $order_id ), array(), 'GET' );

		if ( is_wp_error( $order ) ) {
			return array(
				'status'        => 'failed',
				'error_message' => $order->get_error_message(),
			);
		}

		$status = isset( $order['status'] ) ? $order['status'] : '';
		if ( 'paid' !== $status ) {
			return array(
				'status'        => 'failed',
				'error_message' => sprintf(
					/* translators: %s: order status */
					__( 'Razorpay order not paid (status: %s).', 'gatewaykit' ),
					$status
				),
			);
		}

		// Validate amount (Razorpay stores amount in the smallest unit).
		$expected = $this->format_amount( $amount );
		$paid     = isset( $order['amount_paid'] ) ? (int) $order['amount_paid'] : $expected;
		if ( $paid !== $expected ) {
			$this->log( 'error', 'Razorpay amount mismatch', array( 'expected' => $expected, 'paid' => $paid ) );
			return array(
				'status'        => 'failed',
				'error_message' => __( 'Razorpay payment amount does not match the order amount.', 'gatewaykit' ),
			);
		}

		// Fetch the payment id from the order's payments collection.
		$payment_id = '';
		$payments   = $this->api_request( '/orders/' . rawurlencode( $order_id ) . '/payments', array(), 'GET' );
		if ( ! is_wp_error( $payments ) && isset( $payments['items'] ) && is_array( $payments['items'] ) ) {
			foreach ( $payments['items'] as $item ) {
				if ( isset( $item['status'] ) && 'captured' === $item['status'] && ! empty( $item['id'] ) ) {
					$payment_id = $item['id'];
					break;
				}
			}
		}

		if ( '' === $payment_id ) {
			$payment_id = $order_id;
		}

		$this->log( 'info', 'Razorpay payment verified', array( 'order_id' => $order_id, 'payment_id' => $payment_id ) );

		return array(
			'status' => 'success',
			'ref_id' => $payment_id,
		);
	}

	/**
	 * Resolve the redirect URL for a reference token.
	 *
	 * @param string $authority Reference token.
	 * @return string
	 */
	public function get_redirect_url( $authority ) {
		$authority = sanitize_text_field( (string) $authority );
		return get_transient( 'gatewaykit_razorpay_redirect_' . $authority ) ?: '';
	}

	/**
	 * Handle an inbound Razorpay webhook.
	 *
	 * Razorpay POSTs a JSON payload and signs it with an HMAC-SHA256
	 * signature in the X-Razorpay-Signature header computed over the raw body
	 * using the webhook secret. If no separate webhook secret is configured,
	 * the key secret is used as a backward-compatible fallback. We verify the
	 * signature, then re-fetch the order to confirm before updating the
	 * matching transaction.
	 */
	public function handle_webhook() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- external webhook callback from Razorpay; no WP user session exists, security via signature verification below
		$signature = isset( $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// Razorpay sends a JSON body; read it raw so the signature matches.
		$raw_body = file_get_contents( 'php://input' );

		$webhook_secret = $this->get_setting( 'webhook_secret', '' );
		if ( empty( $webhook_secret ) ) {
			// Backward compat: fall back to key_secret if webhook_secret is not set.
			// Log a deprecation warning.
			$this->log( 'warning', 'Razorpay webhook_secret not configured — falling back to key_secret. Please configure a separate webhook secret.' );
			$webhook_secret = $this->get_key_secret();
		}
		$secret = $webhook_secret;
		if ( '' === $secret ) {
			$this->log( 'error', 'Razorpay webhook: webhook secret not configured', array() );
			status_header( 500 );
			exit;
		}

		if ( '' === $signature ) {
			$this->log( 'warning', 'Razorpay webhook: missing signature header', array() );
			status_header( 400 );
			exit;
		}

		$expected = hash_hmac( 'sha256', $raw_body, $secret );
		if ( ! hash_equals( $expected, $signature ) ) {
			$this->log( 'error', 'Razorpay webhook: signature verification failed', array() );
			status_header( 400 );
			exit;
		}

		$payload = json_decode( $raw_body, true );
		if ( ! is_array( $payload ) ) {
			status_header( 400 );
			exit;
		}

		$event = isset( $payload['event'] ) ? $payload['event'] : '';
		if ( 'payment.captured' !== $event ) {
			// Acknowledge unhandled events so Razorpay does not retry.
			status_header( 200 );
			exit;
		}

		$payment = isset( $payload['payload']['payment']['entity'] ) ? $payload['payload']['payment']['entity'] : array();
		if ( empty( $payment['order_id'] ) ) {
			status_header( 200 );
			exit;
		}

		$order_id = sanitize_text_field( (string) $payment['order_id'] );

		// Re-fetch the order to confirm authoritative state before updating.
		$order = $this->api_request( '/orders/' . rawurlencode( $order_id ), array(), 'GET' );
		if ( is_wp_error( $order ) ) {
			$this->log( 'warning', 'Razorpay webhook: could not fetch order', array( 'order_id' => $order_id ) );
			status_header( 200 );
			exit;
		}

		if ( 'paid' !== isset( $order['status'] ) ? $order['status'] : '' ) {
			status_header( 200 );
			exit;
		}

		$ref = get_transient( 'gatewaykit_razorpay_oid_' . $order_id );
		// Fallback: Razorpay orders start with order_.
		if ( ! $ref && isset( $payment['notes']['gatewaykit_ref'] ) ) {
			$ref = sanitize_text_field( $payment['notes']['gatewaykit_ref'] );
		}

		if ( ! $ref ) {
			$this->log( 'warning', 'Razorpay webhook: no transaction reference for order', array( 'order_id' => $order_id ) );
			status_header( 200 );
			exit;
		}

		$transaction = GatewayKit_Transaction_Model::find_by_authority( $ref );

		if ( ! $transaction ) {
			$this->log( 'warning', 'Razorpay webhook: transaction not found', array( 'ref' => $ref ) );
			status_header( 200 );
			exit;
		}

		if ( in_array( $transaction->status, array( 'pending', 'processing' ), true ) ) {
			// Razorpay reports amounts in paise (smallest currency unit).
			$paid_amount     = isset( $order['amount_paid'] ) ? (float) $order['amount_paid'] / 100 : ( isset( $payment['amount'] ) ? (float) $payment['amount'] / 100 : 0 );
			$expected_amount = (float) $transaction->amount;

			if ( abs( $paid_amount - $expected_amount ) > 0.01 ) {
				$this->log( 'error', sprintf(
					'Webhook amount mismatch: expected %.2f, received %.2f — marking as failed',
					$expected_amount, $paid_amount
				), array(
					'transaction_id' => $transaction->id,
					'gateway'        => $this->get_gateway_id(),
				) );
				$transaction->update( array( 'status' => 'failed' ) );
				return;
			}

			$payment_id = isset( $payment['id'] ) ? sanitize_text_field( (string) $payment['id'] ) : $order_id;
			$transaction->update( array(
				'status'       => 'completed',
				'ref_id'       => $payment_id,
				'completed_at' => current_time( 'mysql' ),
			) );
			do_action( 'gatewaykit_payment_completed', $transaction );
		}

		status_header( 200 );
		exit;
	}

	/**
	 * Resolve a Razorpay order id from a reference token (or pass through an id).
	 *
	 * @param string $authority Reference token or order id.
	 * @return string
	 */
	private function resolve_order_id( $authority ) {
		$mapped = get_transient( 'gatewaykit_razorpay_ref_' . $authority );
		if ( $mapped ) {
			return $mapped;
		}
		// Looks like a Razorpay order id (order_...).
		return ( 0 === strpos( $authority, 'order_' ) ) ? $authority : '';
	}
}
