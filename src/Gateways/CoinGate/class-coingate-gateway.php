<?php
/**
 * CoinGate Payment Gateway (Pro)
 *
 * Uses the CoinGate REST API directly (no SDK). Flow: create order ->
 * redirect to the CoinGate hosted page -> the buyer returns to the callback
 * URL -> the order is verified by re-fetching it. The async webhook is handled
 * via the shared webhook endpoint and verified against the stored order token.
 *
 * The API token is encrypted at rest with GatewayKit_Crypto.
 *
 * @package GatewayKit_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CoinGate Gateway Class.
 */
class GatewayKit_CoinGate_Gateway extends GatewayKit_Abstract_Payment_Gateway {

	/**
	 * Live API base URL.
	 */
	const LIVE_BASE = 'https://api.coingate.com/v2';

	/**
	 * Sandbox API base URL.
	 */
	const SANDBOX_BASE = 'https://api-sandbox.coingate.com/v2';

	/**
	 * Get gateway ID.
	 *
	 * @return string
	 */
	protected function get_gateway_id() {
		return 'coingate';
	}

	/**
	 * Gateway display name.
	 *
	 * @return string
	 */
	public function get_gateway_name() {
		return __( 'CoinGate', 'gatewaykit' );
	}

	/**
	 * Currencies supported by CoinGate (subset of PayPal list that CoinGate
	 * also accepts as price_currency).
	 *
	 * @return string[]
	 */
	public function get_supported_currencies() {
		return array(
			'USD', 'EUR', 'GBP', 'AUD', 'CAD', 'CNY', 'CZK', 'DKK', 'HKD',
			'HUF', 'INR', 'JPY', 'MYR', 'MXN', 'NZD', 'NOK', 'PLN', 'SGD',
			'SEK', 'CHF', 'THB',
		);
	}

	/**
	 * Required settings.
	 *
	 * @return array
	 */
	protected function get_required_settings() {
		return array( 'api_token' );
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
				'description' => __( 'Use the CoinGate sandbox environment for testing.', 'gatewaykit' ),
			),
			'api_token' => array(
				'type'        => 'password',
				'label'       => __( 'API Token', 'gatewaykit' ),
				'description' => __( 'Your CoinGate API token. Stored encrypted.', 'gatewaykit' ),
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
			'description' => __( 'Accept Bitcoin, Ethereum and other cryptocurrencies via CoinGate hosted checkout.', 'gatewaykit' ),
		);
	}

	/**
	 * Decrypt the API token after loading settings.
	 */
	protected function load_settings() {
		parent::load_settings();
		if ( ! empty( $this->settings['api_token'] ) ) {
			$this->settings['api_token'] = GatewayKit_Crypto::decrypt( $this->settings['api_token'] );
		}
	}

	/**
	 * Encrypt the API token before persisting settings.
	 *
	 * @param array $settings Raw settings input.
	 * @return array|WP_Error
	 */
	public function validate_settings_input( $settings ) {
		$validated = parent::validate_settings_input( $settings );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}
		if ( isset( $validated['api_token'] ) && '' !== $validated['api_token'] ) {
			$validated['api_token'] = GatewayKit_Crypto::encrypt( $validated['api_token'] );
		}
		return $validated;
	}

	/**
	 * CoinGate uses 2-decimal float amounts.
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
	 * Get the decrypted API token.
	 *
	 * @return string
	 */
	private function get_api_token() {
		return $this->get_setting( 'api_token', '' );
	}

	/**
	 * Build the webhook URL for this gateway.
	 *
	 * @return string
	 */
	private function get_webhook_url() {
		return add_query_arg( array( 'gatewaykit_webhook' => 'coingate' ), home_url( '/' ) );
	}

	/**
	 * Perform an authenticated CoinGate API call.
	 *
	 * @param string $path   API path.
	 * @param array  $params Request parameters.
	 * @param string $method HTTP method.
	 * @return array|WP_Error
	 */
	private function api_request( $path, $params = array(), $method = 'POST' ) {
		$token = $this->get_api_token();
		if ( '' === $token ) {
			return new WP_Error( 'coingate_missing_token', __( 'CoinGate API token is not configured.', 'gatewaykit' ) );
		}

		$args = array(
			'method'  => $method,
			'headers' => array(
				'Authorization' => 'Token ' . $token,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
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
			$this->log( 'error', 'CoinGate request failed: ' . $response->get_error_message(), array( 'path' => $path ) );
			return new WP_Error( 'coingate_request_failed', __( 'Could not connect to CoinGate. Please try again later.', 'gatewaykit' ) );
		}

		$code    = wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$message = isset( $decoded['message'] ) ? $decoded['message'] : __( 'CoinGate request failed.', 'gatewaykit' );
			if ( is_array( $decoded ) && isset( $decoded['errors'] ) ) {
				$message = wp_json_encode( $decoded['errors'] );
			}
			$this->log( 'error', 'CoinGate API error', array( 'path' => $path, 'status' => $code, 'body' => $decoded ) );
			return new WP_Error( 'coingate_api_error', $message );
		}

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Create a CoinGate order.
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

		// CoinGate does not reliably echo the order id on redirect, so we use a
		// local reference token (like Mollie) and map it to the CoinGate order.
		$ref         = 'cg_' . wp_generate_password( 16, false );
		$success_url = add_query_arg( array( 'authority' => $ref ), $callback_url );
		// Carry the transaction receipt token on cancel so the callback handler
		// can resolve the order and route to the configured failure URL.
		$cancel_args = array( 'gatewaykit_cancel' => '1' );
		if ( ! empty( $user_data['receipt_token'] ) ) {
			$cancel_args['token'] = sanitize_text_field( $user_data['receipt_token'] );
		}
		$cancel_url = add_query_arg( $cancel_args, $callback_url );

		$params = array(
			'order_id'         => $ref,
			'price_amount'     => number_format( $formatted, 2, '.', '' ),
			'price_currency'   => $currency,
			'receive_currency' => $currency,
			'title'            => $description ? mb_substr( (string) $description, 0, 200 ) : __( 'Payment', 'gatewaykit' ),
			'success_url'      => $success_url,
			'cancel_url'       => $cancel_url,
			'callback_url'     => $this->get_webhook_url(),
		);

		$response = $this->api_request( '/orders', $params, 'POST' );

		if ( is_wp_error( $response ) ) {
			return array(
				'status'        => 'error',
				'error_type'    => 'gateway',
				'error_message' => $response->get_error_message(),
			);
		}

		$order_id     = isset( $response['id'] ) ? (string) $response['id'] : '';
		$payment_url  = isset( $response['payment_url'] ) ? $response['payment_url'] : '';
		$order_token  = isset( $response['token'] ) ? (string) $response['token'] : '';

		if ( '' === $order_id || '' === $payment_url ) {
			return array(
				'status'        => 'error',
				'error_type'    => 'gateway',
				'error_message' => __( 'CoinGate did not return an order.', 'gatewaykit' ),
			);
		}

		$ttl = WEEK_IN_SECONDS;
		set_transient( 'gatewaykit_cg_ref_' . $ref, $order_id, $ttl );
		set_transient( 'gatewaykit_cg_pid_' . $order_id, $ref, $ttl );
		set_transient( 'gatewaykit_cg_token_' . $order_id, $order_token, $ttl );
		set_transient( 'gatewaykit_cg_redirect_' . $ref, $payment_url, DAY_IN_SECONDS );

		$this->log( 'info', 'CoinGate order created', array( 'order_id' => $order_id, 'ref' => $ref, 'amount' => $formatted, 'currency' => $currency ) );

		return array(
			'status'       => 'success',
			'authority'    => $ref,
			'redirect_url' => $payment_url,
		);
	}

	/**
	 * Verify a CoinGate order.
	 *
	 * @param string $authority Local reference token (or a CoinGate order id).
	 * @param float  $amount    Expected payment amount.
	 * @return array Verification result.
	 */
	public function verify_payment( $authority, $amount = 0 ) {
		$authority = sanitize_text_field( (string) $authority );
		$order_id  = $this->resolve_order_id( $authority );

		if ( '' === $order_id ) {
			return array(
				'status'        => 'failed',
				'error_message' => __( 'Could not resolve the CoinGate order.', 'gatewaykit' ),
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

		if ( ! in_array( $status, array( 'paid', 'confirmed', 'paid_over' ), true ) ) {
			return array(
				'status'        => 'failed',
				'error_message' => sprintf(
					/* translators: %s: order status */
					__( 'CoinGate order not paid (status: %s).', 'gatewaykit' ),
					$status
				),
			);
		}

		$expected = $this->format_amount( $amount );
		$paid     = isset( $order['price'] ) ? (float) $order['price'] : ( isset( $order['price_amount'] ) ? (float) $order['price_amount'] : $expected );
		if ( abs( $paid - $expected ) > 0.01 ) {
			$this->log( 'error', 'CoinGate amount mismatch', array( 'expected' => $expected, 'paid' => $paid ) );
			return array(
				'status'        => 'failed',
				'error_message' => __( 'CoinGate payment amount does not match the order amount.', 'gatewaykit' ),
			);
		}

		$this->log( 'info', 'CoinGate order verified', array( 'order_id' => $order_id ) );

		return array(
			'status' => 'success',
			'ref_id' => $order_id,
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
		return get_transient( 'gatewaykit_cg_redirect_' . $authority ) ?: '';
	}

	/**
	 * Handle an inbound CoinGate webhook.
	 *
	 * CoinGate POSTs the order payload including the `token` and `status`. We
	 * verify the token against the one stored at order creation, then update the
	 * matching transaction.
	 */
	public function handle_webhook() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- external webhook callback from CoinGate; no WP user session exists, security via stored-token hash_equals() verification below
		$order_id = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
		$token    = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		$status   = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' === $order_id ) {
			status_header( 400 );
			exit;
		}

		// Verify the token matches the one CoinGate returned at creation.
		$stored_token = get_transient( 'gatewaykit_cg_token_' . $order_id );
		if ( '' === $stored_token || ! hash_equals( (string) $stored_token, $token ) ) {
			GatewayKit_Logger::get_instance()->warning( 'CoinGate webhook: token mismatch', array( 'order_id' => $order_id ) );
			status_header( 403 );
			exit;
		}

		$ref = get_transient( 'gatewaykit_cg_pid_' . $order_id );
		if ( ! $ref ) {
			$order = $this->api_request( '/orders/' . rawurlencode( $order_id ), array(), 'GET' );
			$ref   = ( ! is_wp_error( $order ) && isset( $order['order_id'] ) ) ? sanitize_text_field( $order['order_id'] ) : '';
		}

		if ( ! $ref ) {
			GatewayKit_Logger::get_instance()->warning( 'CoinGate webhook: no transaction reference for order', array( 'order_id' => $order_id ) );
			status_header( 200 );
			exit;
		}

		$transaction = GatewayKit_Transaction_Model::find_by_authority( $ref );

		if ( ! $transaction ) {
			GatewayKit_Logger::get_instance()->warning( 'CoinGate webhook: transaction not found', array( 'ref' => $ref ) );
			status_header( 200 );
			exit;
		}

		if ( in_array( $transaction->status, array( 'pending', 'processing' ), true ) ) {
			if ( in_array( $status, array( 'paid', 'confirmed', 'paid_over' ), true ) ) {
				$transaction->update( array(
					'status'       => 'completed',
					'ref_id'       => $order_id,
					'completed_at' => current_time( 'mysql' ),
				) );
				do_action( 'gatewaykit_payment_completed', $transaction );
			} elseif ( in_array( $status, array( 'invalid', 'expired', 'canceled', 'refunded' ), true ) ) {
				$transaction->update( array( 'status' => 'failed' ) );
				do_action( 'gatewaykit_payment_failed', $transaction, array( 'coingate_status' => $status ) );
			}
		}

		status_header( 200 );
		exit;
	}

	/**
	 * Resolve a CoinGate order id from a reference token (or pass through).
	 *
	 * @param string $authority Reference token or order id.
	 * @return string
	 */
	private function resolve_order_id( $authority ) {
		$mapped = get_transient( 'gatewaykit_cg_ref_' . $authority );
		if ( $mapped ) {
			return $mapped;
		}
		return ctype_digit( $authority ) ? $authority : '';
	}
}
