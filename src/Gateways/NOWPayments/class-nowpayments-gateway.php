<?php
/**
 * NOWPayments Payment Gateway (Lite)
 *
 * Uses the NOWPayments REST API (invoice-based flow). The customer is redirected
 * to a NOWPayments hosted invoice page, then returns to the callback URL.
 * An IPN webhook confirms the payment asynchronously.
 *
 * API secrets are encrypted at rest with GatewayKit_Crypto.
 *
 * @package GatewayKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * NOWPayments Gateway Class.
 */
class GatewayKit_NOWPayments_Gateway extends GatewayKit_Abstract_Payment_Gateway {

	/**
	 * Live API base URL.
	 */
	const LIVE_BASE = 'https://api.nowpayments.io/v1';

	/**
	 * Sandbox API base URL.
	 */
	const SANDBOX_BASE = 'https://api-sandbox.nowpayments.io/v1';

	/**
	 * Get gateway ID.
	 *
	 * @return string
	 */
	protected function get_gateway_id() {
		return 'nowpayments';
	}

	/**
	 * Gateway display name.
	 *
	 * @return string
	 */
	public function get_gateway_name() {
		return __( 'NOWPayments', 'gatewaykit' );
	}

	/**
	 * Currencies supported by NOWPayments as price_currency (fiat).
	 *
	 * @return string[]
	 */
	public function get_supported_currencies() {
		return array(
			'USD', 'EUR', 'GBP', 'AUD', 'CAD', 'CHF', 'CNY', 'CZK', 'DKK',
			'HKD', 'HUF', 'INR', 'JPY', 'KRW', 'MXN', 'MYR', 'NOK', 'NZD',
			'PHP', 'PLN', 'RUB', 'SEK', 'SGD', 'THB', 'TRY', 'ZAR',
		);
	}

	/**
	 * Required settings.
	 *
	 * @return array
	 */
	protected function get_required_settings() {
		return array( 'api_key' );
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
				'label'       => __( 'Sandbox Mode', 'gatewaykit' ),
				'description' => __( 'Use the NOWPayments sandbox environment for testing.', 'gatewaykit' ),
			),
			'api_key' => array(
				'type'        => 'password',
				'label'       => __( 'API Key', 'gatewaykit' ),
				'description' => __( 'Your NOWPayments API key. Stored encrypted.', 'gatewaykit' ),
			),
			'ipn_secret' => array(
				'type'        => 'password',
				'label'       => __( 'IPN Secret', 'gatewaykit' ),
				'description' => __( 'Instant Payment Notification secret for webhook signature verification. Stored encrypted. If empty, IPN webhooks are rejected for security.', 'gatewaykit' ),
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
			'description' => __( 'Accept Bitcoin, Ethereum, and 150+ cryptocurrencies via NOWPayments hosted invoices.', 'gatewaykit' ),
		);
	}

	/**
	 * Decrypt sensitive settings after loading.
	 */
	protected function load_settings() {
		parent::load_settings();
		if ( ! empty( $this->settings['api_key'] ) ) {
			$this->settings['api_key'] = GatewayKit_Crypto::decrypt( $this->settings['api_key'] );
		}
		if ( ! empty( $this->settings['ipn_secret'] ) ) {
			$this->settings['ipn_secret'] = GatewayKit_Crypto::decrypt( $this->settings['ipn_secret'] );
		}
	}

	/**
	 * Encrypt sensitive settings before persisting.
	 *
	 * @param array $settings Raw settings input.
	 * @return array|WP_Error
	 */
	public function validate_settings_input( $settings ) {
		$validated = parent::validate_settings_input( $settings );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}
		if ( isset( $validated['api_key'] ) && '' !== $validated['api_key'] ) {
			$validated['api_key'] = GatewayKit_Crypto::encrypt( $validated['api_key'] );
		}
		if ( isset( $validated['ipn_secret'] ) && '' !== $validated['ipn_secret'] ) {
			$validated['ipn_secret'] = GatewayKit_Crypto::encrypt( $validated['ipn_secret'] );
		}
		return $validated;
	}

	/**
	 * NOWPayments uses 2-decimal float amounts.
	 *
	 * @param float $amount Amount.
	 * @return float
	 */
	protected function format_amount( $amount ) {
		return round( (float) $amount, 2 );
	}

	/**
	 * Resolve the currency for this transaction.
	 *
	 * @return string ISO 4217 currency code.
	 */
	private function get_currency() {
			$currency = $this->get_setting( 'currency', '' );
			if ( ! empty( $currency ) ) {
				return strtoupper( $currency );
			}
			$currency = get_option( 'gatewaykit_currency', '' );
			if ( empty( $currency ) ) {
				$currency = GatewayKit_Gateway_Manager::get_instance()->get_default_currency();
			}
			return strtoupper( $currency );
		}

	/**
	 * Get the active API base URL.
	 *
	 * @return string
	 */
	private function get_api_base() {
		return $this->is_sandbox() ? self::SANDBOX_BASE : self::LIVE_BASE;
	}

	/**
	 * Get the decrypted API key.
	 *
	 * @return string
	 */
	private function get_api_key() {
		return $this->get_setting( 'api_key', '' );
	}

	/**
	 * Get the decrypted IPN secret.
	 *
	 * @return string
	 */
	private function get_ipn_secret() {
		return $this->get_setting( 'ipn_secret', '' );
	}

	/**
	 * Build the webhook URL for this gateway.
	 *
	 * @return string
	 */
	private function get_webhook_url() {
		return add_query_arg( array( 'gatewaykit_webhook' => 'nowpayments' ), home_url( '/' ) );
	}

	/**
	 * Perform an authenticated NOWPayments API call.
	 *
	 * @param string $path   API path (e.g. '/invoice').
	 * @param array  $params Request parameters.
	 * @param string $method HTTP method.
	 * @return array|WP_Error
	 */
	private function api_request( $path, $params = array(), $method = 'POST' ) {
		$api_key = $this->get_api_key();
		if ( '' === $api_key ) {
			return new WP_Error( 'nowpayments_missing_key', __( 'NOWPayments API key is not configured.', 'gatewaykit' ) );
		}

		$args = array(
			'method'  => $method,
			'headers' => array(
				'x-api-key'    => $api_key,
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			),
			'timeout' => 30,
		);

		if ( ! empty( $params ) ) {
			if ( in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) ) {
				$args['body'] = wp_json_encode( $params );
			} else {
				$path = add_query_arg( $params, $path );
			}
		}

		$response = wp_remote_request( $this->get_api_base() . $path, $args );

		if ( is_wp_error( $response ) ) {
			$this->log( 'error', 'NOWPayments request failed: ' . $response->get_error_message(), array( 'path' => $path ) );
			return new WP_Error( 'nowpayments_request_failed', __( 'Could not connect to NOWPayments. Please try again later.', 'gatewaykit' ) );
		}

		$code    = wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$message = isset( $decoded['message'] ) ? $decoded['message'] : __( 'NOWPayments request failed.', 'gatewaykit' );
			$this->log( 'error', 'NOWPayments API error', array( 'path' => $path, 'status' => $code, 'body' => $decoded ) );
			return new WP_Error( 'nowpayments_api_error', $message );
		}

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Create a NOWPayments invoice and return the redirect URL.
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

		// NOWPayments does not echo its invoice id on redirect, so we use a
		// local reference token (same pattern as CoinGate).
		$ref         = 'np_' . wp_generate_password( 16, false );
		$success_url = add_query_arg( array( 'authority' => $ref ), $callback_url );
		$cancel_args = array( 'gatewaykit_cancel' => '1' );
		if ( ! empty( $user_data['receipt_token'] ) ) {
			$cancel_args['token'] = sanitize_text_field( $user_data['receipt_token'] );
		}
		$cancel_url = add_query_arg( $cancel_args, $callback_url );

		$params = array(
			'price_amount'     => $formatted,
			'price_currency'   => $currency,
			'order_id'         => $ref,
			'order_description' => $description ? mb_substr( (string) $description, 0, 200 ) : __( 'Payment', 'gatewaykit' ),
			'success_url'      => $success_url,
			'cancel_url'       => $cancel_url,
			'ipn_callback_url' => $this->get_webhook_url(),
		);

		$response = $this->api_request( '/invoice', $params, 'POST' );

		if ( is_wp_error( $response ) ) {
			return array(
				'status'        => 'error',
				'error_type'    => 'gateway',
				'error_message' => $response->get_error_message(),
			);
		}

		$invoice_id  = isset( $response['id'] ) ? (string) $response['id'] : '';
		$invoice_url = isset( $response['invoice_url'] ) ? $response['invoice_url'] : '';

		if ( '' === $invoice_id || '' === $invoice_url ) {
			return array(
				'status'        => 'error',
				'error_type'    => 'gateway',
				'error_message' => __( 'NOWPayments did not return an invoice.', 'gatewaykit' ),
			);
		}

		$ttl = WEEK_IN_SECONDS;
		set_transient( 'gatewaykit_np_ref_' . $ref, $invoice_id, $ttl );
		set_transient( 'gatewaykit_np_redirect_' . $ref, $invoice_url, DAY_IN_SECONDS );
		set_transient( 'gatewaykit_np_pid_' . $invoice_id, $ref, $ttl );

		$this->log( 'info', 'NOWPayments invoice created', array( 'invoice_id' => $invoice_id, 'ref' => $ref, 'amount' => $formatted, 'currency' => $currency ) );

		return array(
			'status'       => 'success',
			'authority'    => $ref,
			'redirect_url' => $invoice_url,
		);
	}

	/**
	 * Verify a NOWPayments invoice payment.
	 *
	 * @param string $authority Local reference token.
	 * @param float  $amount    Expected payment amount.
	 * @return array Verification result.
	 */
	public function verify_payment( $authority, $amount = 0 ) {
		$authority  = sanitize_text_field( (string) $authority );
		$invoice_id = $this->resolve_invoice_id( $authority );

		if ( '' === $invoice_id ) {
			return array(
				'status'        => 'failed',
				'error_message' => __( 'Could not resolve the NOWPayments invoice.', 'gatewaykit' ),
			);
		}

		$result = $this->api_request( '/invoice/' . rawurlencode( $invoice_id ), array(), 'GET' );

		if ( is_wp_error( $result ) ) {
			return array(
				'status'        => 'failed',
				'error_message' => $result->get_error_message(),
			);
		}

		$invoice_status = isset( $result['invoice_status'] ) ? $result['invoice_status'] : '';

		if ( ! in_array( $invoice_status, array( 'finished', 'confirmed', 'complete' ), true ) ) {
			return array(
				'status'        => 'failed',
				'error_message' => sprintf(
					/* translators: %s: invoice status */
					__( 'NOWPayments invoice not paid (status: %s).', 'gatewaykit' ),
					$invoice_status
				),
			);
		}

		$expected = $this->format_amount( $amount );
		$paid     = isset( $result['price_amount'] ) ? (float) $result['price_amount'] : $expected;
		if ( abs( $paid - $expected ) > 0.01 ) {
			$this->log( 'error', 'NOWPayments amount mismatch', array( 'expected' => $expected, 'paid' => $paid ) );
			return array(
				'status'        => 'failed',
				'error_message' => __( 'NOWPayments payment amount does not match the invoice amount.', 'gatewaykit' ),
			);
		}

		$this->log( 'info', 'NOWPayments invoice verified', array( 'invoice_id' => $invoice_id ) );

		return array(
			'status' => 'success',
			'ref_id' => $invoice_id,
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
		return get_transient( 'gatewaykit_np_redirect_' . $authority ) ?: '';
	}

	/**
	 * Handle an inbound NOWPayments IPN webhook.
	 *
	 * Reads the raw body, verifies the HMAC-SHA512 signature, then updates the
	 * matching transaction.
	 */
	public function handle_webhook() {
		$ipn_secret = $this->get_ipn_secret();

		// Stricter than PREF-004: crypto payments have finality — reject when
		// ipn_secret is not configured to prevent forged webhooks.
		if ( '' === $ipn_secret ) {
			$this->log( 'warning', 'NOWPayments IPN rejected: ipn_secret is not configured' );
			status_header( 403 );
			exit;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public webhook endpoint; verified via HMAC signature.
		$raw_body = file_get_contents( 'php://input' );

		if ( false === $raw_body || '' === trim( $raw_body ) ) {
			status_header( 400 );
			exit;
		}

		// Verify HMAC-SHA512 signature per NOWPayments docs.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$signature = isset( $_SERVER['HTTP_X_NOWPAYMENTS_SIG'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_NOWPAYMENTS_SIG'] ) ) : '';

		if ( '' === $signature ) {
			$this->log( 'warning', 'NOWPayments IPN: missing signature header' );
			status_header( 403 );
			exit;
		}

		$decoded = json_decode( $raw_body, true );
		if ( ! is_array( $decoded ) ) {
			status_header( 400 );
			exit;
		}

		$expected_sig = hash_hmac( 'sha512', $raw_body, $ipn_secret );
		if ( ! hash_equals( $expected_sig, $signature ) ) {
			$this->log( 'warning', 'NOWPayments IPN: signature mismatch' );
			status_header( 403 );
			exit;
		}

		$order_id      = isset( $decoded['order_id'] ) ? sanitize_text_field( $decoded['order_id'] ) : '';
		$payment_status = isset( $decoded['payment_status'] ) ? sanitize_text_field( $decoded['payment_status'] ) : '';

		if ( '' === $order_id ) {
			status_header( 400 );
			exit;
		}

		$ref = get_transient( 'gatewaykit_np_pid_' . $order_id );
		if ( ! $ref ) {
			$this->log( 'info', 'NOWPayments IPN: no transaction reference for order', array( 'order_id' => $order_id ) );
			status_header( 200 );
			exit;
		}

		$transaction = GatewayKit_Transaction_Model::find_by_authority( $ref );

		if ( ! $transaction ) {
			$this->log( 'warning', 'NOWPayments IPN: transaction not found', array( 'ref' => $ref ) );
			status_header( 200 );
			exit;
		}

		if ( in_array( $transaction->status, array( 'pending', 'processing' ), true ) ) {
			$completed_statuses = array( 'finished', 'confirmed', 'complete' );
			$failed_statuses    = array( 'failed', 'expired', 'refunded' );

			if ( in_array( $payment_status, $completed_statuses, true ) ) {
				$transaction->update( array(
					'status'       => 'completed',
					'ref_id'       => $order_id,
					'completed_at' => current_time( 'mysql' ),
				) );
				do_action( 'gatewaykit_payment_completed', $transaction );
			} elseif ( in_array( $payment_status, $failed_statuses, true ) ) {
				$transaction->update( array( 'status' => 'failed' ) );
				do_action( 'gatewaykit_payment_failed', $transaction, array( 'nowpayments_status' => $payment_status ) );
			}
		}

		status_header( 200 );
		exit;
	}

	/**
	 * Resolve a NOWPayments invoice id from a reference token (or pass through).
	 *
	 * @param string $authority Reference token or invoice id.
	 * @return string
	 */
	private function resolve_invoice_id( $authority ) {
		$mapped = get_transient( 'gatewaykit_np_ref_' . $authority );
		if ( $mapped ) {
			return $mapped;
		}
		return '' !== $authority && is_numeric( $authority ) ? (string) $authority : '';
	}
}
