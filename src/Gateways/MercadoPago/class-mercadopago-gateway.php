<?php
/**
 * Mercado Pago Payment Gateway
 *
 * Creates a Mercado Pago checkout preference -> redirects buyer to the
 * Mercado Pago hosted checkout (init_point / sandbox_init_point) -> the buyer
 * returns to the callback URL -> the payment is verified by re-fetching it.
 * Mercado Pago's async webhook (IPN) is handled via the shared webhook endpoint.
 *
 * Access token is encrypted at rest with GatewayKit_Crypto.
 *
 * @package GatewayKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Mercado Pago Gateway Class.
 */
class GatewayKit_MercadoPago_Gateway extends GatewayKit_Abstract_Payment_Gateway {

	/**
	 * API base URL.
	 */
	const API_BASE = 'https://api.mercadopago.com';

	/**
	 * Get gateway ID.
	 *
	 * @return string
	 */
	protected function get_gateway_id() {
		return 'mercadopago';
	}

	/**
	 * Gateway display name.
	 *
	 * @return string
	 */
	public function get_gateway_name() {
		return __( 'Mercado Pago', 'gatewaykit' );
	}

	/**
	 * Currencies supported by Mercado Pago (LATAM-focused subset).
	 *
	 * @return string[]
	 */
	public function get_supported_currencies() {
		return array(
			'BRL',
			'MXN',
			'ARS',
			'COP',
			'CLP',
			'PEN',
			'UYU',
			'USD',
		);
	}

	/**
	 * Required settings.
	 *
	 * @return array
	 */
	protected function get_required_settings() {
		return array( 'access_token' );
	}

	/**
	 * Admin UI settings fields.
	 *
	 * @return array
	 */
	public function get_settings_fields() {
		return array(
			'sandbox_mode'   => array(
				'type'        => 'checkbox',
				'label'       => __( 'Sandbox Mode', 'gatewaykit' ),
				'description' => __( 'Use the sandbox init_point for test payments.', 'gatewaykit' ),
			),
			'access_token'   => array(
				'type'        => 'password',
				'label'       => __( 'Access Token', 'gatewaykit' ),
				'description' => __( 'Your Mercado Pago access token (live or test). Stored encrypted.', 'gatewaykit' ),
			),
			'public_key'     => array(
				'type'        => 'text',
				'label'       => __( 'Public Key', 'gatewaykit' ),
				'description' => __( 'Your Mercado Pago public key (optional, used for front-end integrations).', 'gatewaykit' ),
			),
			'webhook_secret' => array(
				'type'        => 'password',
				'label'       => __( 'Webhook Secret', 'gatewaykit' ),
				'description' => __( 'Per-application webhook secret used to validate the x-signature header on inbound notifications. Reveal it under Your integrations &rarr; Webhooks. Stored encrypted. If empty, webhooks fall back to API re-fetch verification only (less secure).', 'gatewaykit' ),
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
			'description' => __( 'Accept credit cards, Pix, boleto, OXXO and all Mercado Pago methods through one hosted checkout.', 'gatewaykit' ),
		);
	}

	/**
	 * Decrypt the access token after loading settings.
	 */
	protected function load_settings() {
		parent::load_settings();
		if ( ! empty( $this->settings['access_token'] ) ) {
			$this->settings['access_token'] = GatewayKit_Crypto::decrypt( $this->settings['access_token'] );
		}
		if ( ! empty( $this->settings['webhook_secret'] ) ) {
			$this->settings['webhook_secret'] = GatewayKit_Crypto::decrypt( $this->settings['webhook_secret'] );
		}
	}

	/**
	 * Encrypt the access token before persisting settings.
	 *
	 * @param array $settings Raw settings input.
	 * @return array|WP_Error
	 */
	public function validate_settings_input( $settings ) {
		$validated = parent::validate_settings_input( $settings );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}
		if ( isset( $validated['access_token'] ) && '' !== $validated['access_token'] ) {
			$validated['access_token'] = GatewayKit_Crypto::encrypt( $validated['access_token'] );
		}
		if ( isset( $validated['webhook_secret'] ) && '' !== $validated['webhook_secret'] ) {
			$validated['webhook_secret'] = GatewayKit_Crypto::encrypt( $validated['webhook_secret'] );
		}
		return $validated;
	}

	/**
	 * Mercado Pago uses decimal amounts (float), so keep 2 decimals.
	 *
	 * @param float $amount Amount.
	 * @return float
	 */
	protected function format_amount( $amount ) {
		return round( (float) $amount, 2 );
	}

	/**
	 * Get the decrypted Mercado Pago access token.
	 *
	 * @return string
	 */
	private function get_access_token() {
		return $this->get_setting( 'access_token', '' );
	}

	/**
	 * Get the decrypted per-application webhook secret.
	 *
	 * @return string
	 */
	private function get_webhook_secret() {
		return $this->get_setting( 'webhook_secret', '' );
	}

	/**
	 * Build the webhook (IPN) URL for this gateway.
	 *
	 * @return string
	 */
	private function get_webhook_url() {
		return add_query_arg( array( 'gatewaykit_webhook' => 'mercadopago' ), home_url( '/' ) );
	}

	/**
	 * Perform an authenticated Mercado Pago API call (JSON).
	 *
	 * @param string $path   API path (including leading slash).
	 * @param array  $body   Request body.
	 * @param string $method HTTP method.
	 * @return array|WP_Error
	 */
	private function api_request( $path, $body = array(), $method = 'POST' ) {
		$token = $this->get_access_token();
		if ( '' === $token ) {
			return new WP_Error( 'mercadopago_missing_token', __( 'Mercado Pago access token is not configured.', 'gatewaykit' ) );
		}

		$args = array(
			'method'  => $method,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
			'timeout' => 30,
		);

		if ( ! empty( $body ) && in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( self::API_BASE . $path, $args );

		if ( is_wp_error( $response ) ) {
			$this->log( 'error', 'Mercado Pago request failed: ' . $response->get_error_message(), array( 'path' => $path ) );
			return new WP_Error( 'mercadopago_request_failed', __( 'Could not connect to Mercado Pago. Please try again later.', 'gatewaykit' ) );
		}

		$code    = wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$message = __( 'Mercado Pago request failed.', 'gatewaykit' );
			if ( isset( $decoded['message'] ) ) {
				$message = $decoded['message'];
			} elseif ( isset( $decoded['error'] ) ) {
				$message = $decoded['error'];
			}
			$this->log(
				'error',
				'Mercado Pago API error',
				array(
					'path'   => $path,
					'status' => $code,
					'body'   => $decoded,
				)
			);
			return new WP_Error( 'mercadopago_api_error', $message );
		}

		return $decoded;
	}

	/**
	 * Create a Mercado Pago checkout preference.
	 *
	 * @param float  $amount       Payment amount.
	 * @param string $description  Payment description.
	 * @param string $callback_url Callback/return URL.
	 * @param array  $user_data    User data array.
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

		$body = array(
			'items'            => array(
				array(
					'title'       => $description ? mb_substr( (string) $description, 0, 255 ) : __( 'Payment', 'gatewaykit' ),
					'quantity'    => 1,
					'unit_price'  => $formatted,
					'currency_id' => $currency,
				),
			),
			'back_urls'        => array(
				'success' => $callback_url,
				'failure' => $callback_url,
				'pending' => $callback_url,
			),
			'auto_return'      => 'approved',
			'notification_url' => $this->get_webhook_url(),
		);

		$response = $this->api_request( '/checkout/preferences', $body, 'POST' );

		if ( is_wp_error( $response ) ) {
			return array(
				'status'        => 'error',
				'error_type'    => 'gateway',
				'error_message' => $response->get_error_message(),
			);
		}

		$preference_id = isset( $response['id'] ) ? $response['id'] : '';
		$init_point    = isset( $response['init_point'] ) ? $response['init_point'] : '';
		$sandbox_point = isset( $response['sandbox_init_point'] ) ? $response['sandbox_init_point'] : '';

		// Use sandbox init_point when sandbox mode is enabled.
		$redirect_url = $this->is_sandbox() ? $sandbox_point : $init_point;
		if ( '' === $redirect_url ) {
			// Fallback: if the chosen endpoint is empty, use whichever exists.
			$redirect_url = '' !== $init_point ? $init_point : $sandbox_point;
		}

		if ( '' === $preference_id || '' === $redirect_url ) {
			return array(
				'status'        => 'error',
				'error_type'    => 'gateway',
				'error_message' => __( 'Mercado Pago did not return a checkout preference.', 'gatewaykit' ),
			);
		}

		// Cache preference_id -> redirect_url mapping.
		$ttl = WEEK_IN_SECONDS;
		set_transient( 'gatewaykit_mercadopago_pref_' . $preference_id, $redirect_url, DAY_IN_SECONDS );
		set_transient( 'gatewaykit_mercadopago_pid_' . $preference_id, $preference_id, $ttl );

		$this->log(
			'info',
			'Mercado Pago preference created',
			array(
				'preference_id' => $preference_id,
				'amount'        => $formatted,
				'currency'      => $currency,
				'sandbox'       => $this->is_sandbox(),
			)
		);

		return array(
			'status'       => 'success',
			'authority'    => $preference_id,
			'redirect_url' => $redirect_url,
		);
	}

	/**
	 * Verify a Mercado Pago payment.
	 *
	 * The authority may be a preference_id (from the redirect flow) or a
	 * numeric payment_id (from the webhook/callback params).
	 *
	 * @param string $authority Preference ID or payment ID.
	 * @param float  $amount    Expected payment amount.
	 * @return array Verification result.
	 */
	public function verify_payment( $authority, $amount = 0 ) {
		$authority = sanitize_text_field( (string) $authority );

		$payment = $this->fetch_payment_by_authority( $authority );

		if ( is_wp_error( $payment ) ) {
			return array(
				'status'        => 'failed',
				'error_message' => $payment->get_error_message(),
			);
		}

		$status_raw = isset( $payment['status'] ) ? $payment['status'] : '';
		$status     = $this->map_payment_status( $status_raw );

		if ( 'success' !== $status ) {
			return array(
				'status'        => $status,
				'error_message' => sprintf(
					/* translators: %s: Mercado Pago payment status */
					__( 'Mercado Pago payment not approved (status: %s).', 'gatewaykit' ),
					$status_raw
				),
			);
		}

		// Validate amount.
		$expected = $this->format_amount( $amount );
		$paid     = isset( $payment['transaction_amount'] ) ? (float) $payment['transaction_amount'] : $expected;
		if ( abs( $paid - $expected ) > 0.01 ) {
			$this->log(
				'error',
				'Mercado Pago amount mismatch',
				array(
					'expected' => $expected,
					'paid'     => $paid,
				)
			);
			return array(
				'status'        => 'failed',
				'error_message' => __( 'Mercado Pago payment amount does not match the order amount.', 'gatewaykit' ),
			);
		}

		$payment_id = isset( $payment['id'] ) ? (string) $payment['id'] : $authority;

		$this->log( 'info', 'Mercado Pago payment verified', array( 'payment_id' => $payment_id ) );

		return array(
			'status' => 'success',
			'ref_id' => $payment_id,
		);
	}

	/**
	 * Resolve the redirect URL for a preference ID.
	 *
	 * @param string $authority Preference ID.
	 * @return string
	 */
	public function get_redirect_url( $authority ) {
		$authority = sanitize_text_field( (string) $authority );
		return get_transient( 'gatewaykit_mercadopago_pref_' . $authority ) ?: '';
	}

	/**
	 * Handle an inbound Mercado Pago webhook (IPN notification).
	 *
	 * Mercado Pago sends JSON with `type` (e.g. "payment") and `data.id`.
	 * For payment notifications we fetch the payment and update the matching
	 * transaction.
	 */
	public function handle_webhook() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- external webhook callback from Mercado Pago; no WP user session exists, security via HMAC signature + re-fetching payment from Mercado Pago API below
		$raw_body = file_get_contents( 'php://input' );
		// Also allow URL params (Mercado Pago sometimes sends topic/id as query params for IPN).
		$topic         = isset( $_GET['topic'] ) ? sanitize_text_field( wp_unslash( $_GET['topic'] ) ) : '';
		$param_id      = isset( $_GET['id'] ) ? sanitize_text_field( wp_unslash( $_GET['id'] ) ) : '';
		$query_data_id = isset( $_GET['data.id'] ) ? sanitize_text_field( wp_unslash( $_GET['data.id'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended

		$payload = json_decode( $raw_body, true );
		if ( ! is_array( $payload ) ) {
			$payload = array();
		}

		$type    = isset( $payload['type'] ) ? $payload['type'] : $topic;
		$data_id = isset( $payload['data']['id'] ) ? (string) $payload['data']['id'] : ( '' !== $query_data_id ? $query_data_id : $param_id );

		// Verify the x-signature header when a webhook secret is configured
		// (per-application secret from Your integrations -> Webhooks). This is
		// the primary authenticity check and prevents forged notifications.
		// Backward compatibility: if the secret is unset, fall back to the
		// existing behaviour (authenticity via re-fetching the payment from the
		// Mercado Pago API). An admin notice reminds the merchant to set it.
		// Mercado Pago signs using the `data.id` URL query param value, and
		// omits `id:` from the manifest when that param is absent — so we pass
		// the query param only (not the body fallback) to the verifier.
		$webhook_secret = $this->get_webhook_secret();
		if ( '' !== $webhook_secret ) {
			$valid = $this->verify_webhook_signature( $webhook_secret, $query_data_id );
			if ( true !== $valid ) {
				$this->log( 'warning', 'Mercado Pago webhook: signature verification failed' );
				status_header( 403 );
				exit;
			}
		} else {
			$this->log( 'info', 'Mercado Pago webhook: webhook_secret not configured — falling back to API re-fetch verification' );
		}

		// Only process payment notifications.
		if ( 'payment' !== $type ) {
			// Other notification types (merchant_order, etc.) — acknowledge but don't process.
			status_header( 200 );
			exit;
		}

		if ( '' === $data_id ) {
			status_header( 400 );
			exit;
		}

		$payment = $this->api_request( '/v1/payments/' . rawurlencode( $data_id ), array(), 'GET' );

		if ( is_wp_error( $payment ) ) {
			$this->log( 'warning', 'Mercado Pago webhook: could not fetch payment', array( 'payment_id' => $data_id ) );
			status_header( 200 );
			exit;
		}

		$status_raw = isset( $payment['status'] ) ? $payment['status'] : '';
		$status     = $this->map_payment_status( $status_raw );

		// Find the preference_id to locate the transaction.
		$preference_id = '';
		if ( isset( $payment['order']['id'] ) ) {
			$preference_id = (string) $payment['order']['id'];
		}
		if ( '' === $preference_id ) {
			// Try transient reverse lookup by payment_id.
			$preference_id = get_transient( 'gatewaykit_mercadopago_payid_' . $data_id ) ?: '';
		}

		$authority   = '' !== $preference_id ? $preference_id : $data_id;
		$transaction = GatewayKit_Transaction_Model::find_by_authority( $authority );

		if ( ! $transaction ) {
			$this->log( 'warning', 'Mercado Pago webhook: transaction not found', array( 'authority' => $authority ) );
			status_header( 200 );
			exit;
		}

		// Map to payment_id for storage.
		$payment_id = isset( $payment['id'] ) ? (string) $payment['id'] : $data_id;
		set_transient( 'gatewaykit_mercadopago_payid_' . $payment_id, $authority, WEEK_IN_SECONDS );

		if ( in_array( $transaction->status, array( 'pending', 'processing' ), true ) ) {
			if ( 'success' === $status ) {
				$paid_amount     = isset( $payment['transaction_amount'] ) ? (float) $payment['transaction_amount'] : 0;
				$expected_amount = (float) $transaction->amount;

				if ( abs( $paid_amount - $expected_amount ) > 0.01 ) {
					$this->log(
						'error',
						sprintf(
							'Webhook amount mismatch: expected %.2f, received %.2f — marking as failed',
							$expected_amount,
							$paid_amount
						),
						array(
							'transaction_id' => $transaction->id,
							'gateway'        => $this->get_gateway_id(),
						)
					);
					$transaction->update( array( 'status' => 'failed' ) );
					return;
				}

				$transaction->update(
					array(
						'status'       => 'completed',
						'ref_id'       => $payment_id,
						'completed_at' => current_time( 'mysql' ),
					)
				);
				do_action( 'gatewaykit_payment_completed', $transaction );
			} elseif ( 'failed' === $status ) {
				$transaction->update( array( 'status' => 'failed' ) );
				do_action( 'gatewaykit_payment_failed', $transaction, array( 'mercadopago_status' => $status_raw ) );
			}
			// 'pending' status: leave transaction as-is (still pending).
		}

		status_header( 200 );
		exit;
	}

	/**
	 * Verify a Mercado Pago webhook signature (x-signature + x-request-id).
	 *
	 * Implements the HMAC-SHA256 validation documented at
	 * https://www.mercadopago.com/developers/en/docs/your-integrations/notifications/webhooks.
	 *
	 * The manifest is built as `id:<data.id>;request-id:<x-request-id>;ts:<ts>;`
	 * where `ts` and `v1` are parsed from the `x-signature` header
	 * (`ts=...,v1=...`). Per the docs, if `data.id` or `x-request-id` are
	 * absent they are omitted from the manifest, and uppercase `data.id`
	 * values are lowercased before signing. The computed HMAC is compared to
	 * `v1` using hash_equals (timing-safe).
	 *
	 * @param string $secret  Per-application webhook secret.
	 * @param string $data_id The `data.id` value (from the URL query param, or
	 *                        the decoded payload body as a fallback).
	 * @return bool True on valid signature, false otherwise.
	 */
	private function verify_webhook_signature( $secret, $data_id ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- public webhook endpoint; authenticity verified via HMAC, not nonce.
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- opaque header tokens consumed as-is by the HMAC check; sanitizing would corrupt the signature.
		$x_signature  = isset( $_SERVER['HTTP_X_SIGNATURE'] ) ? wp_unslash( $_SERVER['HTTP_X_SIGNATURE'] ) : '';
		$x_request_id = isset( $_SERVER['HTTP_X_REQUEST_ID'] ) ? wp_unslash( $_SERVER['HTTP_X_REQUEST_ID'] ) : '';
		// phpcs:enable

		if ( '' === $x_signature ) {
			return false;
		}

		// Parse the x-signature header: "ts=<unix_ts>,v1=<hex_digest>".
		$ts = '';
		$v1 = '';
		foreach ( explode( ',', $x_signature ) as $part ) {
			$pair = explode( '=', $part, 2 );
			if ( 2 !== count( $pair ) ) {
				continue;
			}
			$key   = trim( $pair[0] );
			$value = trim( $pair[1] );
			if ( 'ts' === $key ) {
				$ts = $value;
			} elseif ( 'v1' === $key ) {
				$v1 = $value;
			}
		}

		if ( '' === $ts || '' === $v1 ) {
			return false;
		}

		// Build the manifest. Per the docs, omit any missing component and
		// lowercase an uppercase data.id before signing.
		$manifest = '';
		if ( '' !== $data_id ) {
			$manifest .= 'id:' . strtolower( $data_id ) . ';';
		}
		if ( '' !== $x_request_id ) {
			$manifest .= 'request-id:' . $x_request_id . ';';
		}
		$manifest .= 'ts:' . $ts . ';';

		$expected = hash_hmac( 'sha256', $manifest, $secret );

		return hash_equals( $expected, $v1 );
	}

	/**
	 * Fetch a payment from Mercado Pago by authority (preference_id or payment_id).
	 *
	 * If the authority looks like a numeric payment ID, fetch directly.
	 * Otherwise, resolve via merchant_orders search by preference_id.
	 *
	 * @param string $authority Preference ID or payment ID.
	 * @return array|WP_Error
	 */
	private function fetch_payment_by_authority( $authority ) {
		// If it's a numeric payment ID, fetch directly.
		if ( ctype_digit( (string) $authority ) ) {
			return $this->api_request( '/v1/payments/' . rawurlencode( $authority ), array(), 'GET' );
		}

		// Otherwise, search merchant orders by preference_id to find the payment.
		$search = $this->api_request(
			'/merchant_orders/search?preference_id=' . rawurlencode( $authority ),
			array(),
			'GET'
		);

		if ( is_wp_error( $search ) ) {
			return $search;
		}

		$elements = isset( $search['elements'] ) ? $search['elements'] : array();
		if ( empty( $elements ) ) {
			return new WP_Error(
				'mercadopago_no_order',
				__( 'No Mercado Pago order found for this preference.', 'gatewaykit' )
			);
		}

		// Take the first order; extract its first payment.
		$order    = $elements[0];
		$payments = isset( $order['payments'] ) ? $order['payments'] : array();
		if ( empty( $payments ) ) {
			return new WP_Error(
				'mercadopago_no_payment',
				__( 'No payment associated with this Mercado Pago order.', 'gatewaykit' )
			);
		}

		$payment_id = isset( $payments[0]['id'] ) ? (string) $payments[0]['id'] : '';
		if ( '' === $payment_id ) {
			return new WP_Error(
				'mercadopago_no_payment_id',
				__( 'Could not resolve the Mercado Pago payment ID.', 'gatewaykit' )
			);
		}

		return $this->api_request( '/v1/payments/' . rawurlencode( $payment_id ), array(), 'GET' );
	}

	/**
	 * Map Mercado Pago payment statuses to internal statuses.
	 *
	 * - approved → success
	 * - rejected, cancelled → failed
	 * - pending, in_process → pending
	 *
	 * @param string $status Mercado Pago raw status.
	 * @return string Internal status: success|failed|pending.
	 */
	private function map_payment_status( $status ) {
		switch ( $status ) {
			case 'approved':
				return 'success';
			case 'rejected':
			case 'cancelled':
			case 'charged_back':
				return 'failed';
			case 'pending':
			case 'in_process':
			case 'in_mediation':
			default:
				return 'pending';
		}
	}
}
