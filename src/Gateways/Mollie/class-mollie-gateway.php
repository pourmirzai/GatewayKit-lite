<?php
/**
 * Mollie Payment Gateway (Pro)
 *
 * Creates a Mollie payment -> redirects to Mollie hosted checkout -> the buyer
 * returns to the callback URL -> the payment is verified by re-fetching it.
 * Mollie's async webhook is handled via the shared webhook endpoint.
 *
 * API key is encrypted at rest with GatewayKit_Crypto.
 *
 * @package GatewayKit_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Mollie Gateway Class.
 */
class GatewayKit_Mollie_Gateway extends GatewayKit_Abstract_Payment_Gateway {

	/**
	 * API base URL.
	 */
	const API_BASE = 'https://api.mollie.com/v2';

	/**
	 * Get gateway ID.
	 *
	 * @return string
	 */
	protected function get_gateway_id() {
		return 'mollie';
	}

	/**
	 * Gateway display name.
	 *
	 * @return string
	 */
	public function get_gateway_name() {
		return __( 'Mollie', 'gatewaykit' );
	}

	/**
	 * Currencies supported by Mollie (subset of PayPal list that Mollie
	 * also supports).
	 *
	 * @return string[]
	 */
	public function get_supported_currencies() {
		return array(
			'USD', 'EUR', 'GBP', 'AUD', 'CAD', 'CZK', 'DKK', 'HKD', 'HUF',
			'ILS', 'JPY', 'MYR', 'MXN', 'NZD', 'NOK', 'PLN', 'RUB', 'SGD',
			'SEK', 'CHF', 'THB',
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
				'label'       => __( 'Test Mode', 'gatewaykit' ),
				'description' => __( 'Use your Mollie test API key (starts with test_).', 'gatewaykit' ),
			),
			'api_key' => array(
				'type'        => 'password',
				'label'       => __( 'API Key', 'gatewaykit' ),
				'description' => __( 'Your Mollie API key (live_... or test_...). Stored encrypted.', 'gatewaykit' ),
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
			'description' => __( 'Accept iDEAL, credit card, Bancontact and all Mollie methods through one hosted checkout.', 'gatewaykit' ),
		);
	}

	/**
	 * Decrypt the API key after loading settings.
	 */
	protected function load_settings() {
		parent::load_settings();
		if ( ! empty( $this->settings['api_key'] ) ) {
			$this->settings['api_key'] = GatewayKit_Crypto::decrypt( $this->settings['api_key'] );
		}
	}

	/**
	 * Encrypt the API key before persisting settings.
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
		return $validated;
	}

	/**
	 * Mollie uses decimal amount strings (value), so keep 2 decimals.
	 *
	 * @param float $amount Amount.
	 * @return float
	 */
	protected function format_amount( $amount ) {
		return round( (float) $amount, 2 );
	}

	/**
	 * Zero-decimal currencies (no minor units) per ISO 4217.
	 *
	 * Mollie requires the value to match the currency's precision: integer for
	 * these (e.g. JPY "100"), 2-decimal string otherwise. Sending "100.00" for
	 * JPY is rejected. Only JPY appears in Mollie's supported list above; the
	 * others are listed for correctness/future-proofing.
	 *
	 * @var string[]
	 */
	private static $zero_decimal = array(
		'BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'LAK', 'MGA',
		'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
	);

	/**
	 * Render the amount as the value string Mollie expects for the active
	 * currency: integer for zero-decimal currencies, 2-decimal string
	 * otherwise.
	 *
	 * @param float $amount Amount.
	 * @return string
	 */
	private function format_value( $amount ) {
		$currency = $this->get_currency();
		if ( in_array( $currency, self::$zero_decimal, true ) ) {
			return (string) (int) round( (float) $amount );
		}
		return number_format( (float) $amount, 2, '.', '' );
	}

	/**
	 * Get the decrypted Mollie API key.
	 *
	 * @return string
	 */
	private function get_api_key() {
		$key = $this->get_setting( 'api_key', '' );
		if ( $this->is_sandbox() && '' !== $key && 0 !== strpos( $key, 'test_' ) ) {
			// Advisory only: test mode expects a test key.
			$this->log( 'warning', 'Mollie test mode enabled but the API key does not start with test_.', array() );
		}
		return $key;
	}

	/**
	 * Build the webhook URL for this gateway.
	 *
	 * @return string
	 */
	private function get_webhook_url() {
		return add_query_arg( array( 'gatewaykit_webhook' => 'mollie' ), home_url( '/' ) );
	}

	/**
	 * Perform an authenticated Mollie API call (JSON).
	 *
	 * @param string $path   API path.
	 * @param array  $body   Request body.
	 * @param string $method HTTP method.
	 * @return array|WP_Error
	 */
	private function api_request( $path, $body = array(), $method = 'POST' ) {
		$key = $this->get_api_key();
		if ( '' === $key ) {
			return new WP_Error( 'mollie_missing_key', __( 'Mollie API key is not configured.', 'gatewaykit' ) );
		}

		$args = array(
			'method'  => $method,
			'headers' => array(
				'Authorization' => 'Bearer ' . $key,
				'Content-Type'  => 'application/json',
			),
			'timeout' => 30,
		);

		if ( ! empty( $body ) && in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( self::API_BASE . $path, $args );

		if ( is_wp_error( $response ) ) {
			$this->log( 'error', 'Mollie request failed: ' . $response->get_error_message(), array( 'path' => $path ) );
			return new WP_Error( 'mollie_request_failed', __( 'Could not connect to Mollie. Please try again later.', 'gatewaykit' ) );
		}

		$code    = wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$message = isset( $decoded['detail'] ) ? $decoded['detail'] : __( 'Mollie request failed.', 'gatewaykit' );
			$this->log( 'error', 'Mollie API error', array( 'path' => $path, 'status' => $code, 'body' => $decoded ) );
			return new WP_Error( 'mollie_api_error', $message );
		}

		return $decoded;
	}

	/**
	 * Create a Mollie payment.
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

		// Mollie does not echo the payment id back on redirect, so we generate a
		// local reference token, embed it in the redirect URL, and map it to the
		// Mollie payment id once created.
		$ref = 'mollie_' . wp_generate_password( 16, false );
		$redirect_url = add_query_arg( array( 'authority' => $ref ), $callback_url );

		$body = array(
			'amount' => array(
				'currency' => $currency,
				'value'    => $this->format_value( $formatted ),
			),
			'description'  => $description ? mb_substr( (string) $description, 0, 255 ) : __( 'Payment', 'gatewaykit' ),
			'redirectUrl'  => $redirect_url,
			'webhookUrl'   => $this->get_webhook_url(),
			'metadata'     => array(
				'gatewaykit_ref' => $ref,
			),
		);

		$response = $this->api_request( '/payments', $body, 'POST' );

		if ( is_wp_error( $response ) ) {
			return array(
				'status'        => 'error',
				'error_type'    => 'gateway',
				'error_message' => $response->get_error_message(),
			);
		}

		$payment_id   = isset( $response['id'] ) ? $response['id'] : '';
		$checkout_url = isset( $response['_links']['checkout']['href'] ) ? $response['_links']['checkout']['href'] : '';

		if ( '' === $payment_id || '' === $checkout_url ) {
			return array(
				'status'        => 'error',
				'error_type'    => 'gateway',
				'error_message' => __( 'Mollie did not return a payment.', 'gatewaykit' ),
			);
		}

		// Persist both directions of the ref <-> payment id mapping.
		$ttl = WEEK_IN_SECONDS;
		set_transient( 'gatewaykit_mollie_ref_' . $ref, $payment_id, $ttl );
		set_transient( 'gatewaykit_mollie_pid_' . $payment_id, $ref, $ttl );
		set_transient( 'gatewaykit_mollie_redirect_' . $ref, $checkout_url, DAY_IN_SECONDS );

		$this->log( 'info', 'Mollie payment created', array( 'payment_id' => $payment_id, 'ref' => $ref, 'amount' => $formatted, 'currency' => $currency ) );

		return array(
			'status'       => 'success',
			'authority'    => $ref,
			'redirect_url' => $checkout_url,
		);
	}

	/**
	 * Verify a Mollie payment.
	 *
	 * @param string $authority Local reference token (or a raw Mollie id).
	 * @param float  $amount    Expected payment amount.
	 * @return array Verification result.
	 */
	public function verify_payment( $authority, $amount = 0 ) {
		$authority  = sanitize_text_field( (string) $authority );
		$payment_id = $this->resolve_payment_id( $authority );

		if ( '' === $payment_id ) {
			return array(
				'status'        => 'failed',
				'error_message' => __( 'Could not resolve the Mollie payment.', 'gatewaykit' ),
			);
		}

		$payment = $this->api_request( '/payments/' . rawurlencode( $payment_id ), array(), 'GET' );

		if ( is_wp_error( $payment ) ) {
			return array(
				'status'        => 'failed',
				'error_message' => $payment->get_error_message(),
			);
		}

		$status = isset( $payment['status'] ) ? $payment['status'] : '';

		if ( 'paid' !== $status ) {
			return array(
				'status'        => 'failed',
				'error_message' => sprintf(
					/* translators: %s: payment status */
					__( 'Mollie payment not paid (status: %s).', 'gatewaykit' ),
					$status
				),
			);
		}

		// Validate amount.
		$expected  = $this->format_amount( $amount );
		$paid      = isset( $payment['amount']['value'] ) ? (float) $payment['amount']['value'] : $expected;
		if ( abs( $paid - $expected ) > 0.01 ) {
			$this->log( 'error', 'Mollie amount mismatch', array( 'expected' => $expected, 'paid' => $paid ) );
			return array(
				'status'        => 'failed',
				'error_message' => __( 'Mollie payment amount does not match the order amount.', 'gatewaykit' ),
			);
		}

		$ref_id = $payment_id;

		$this->log( 'info', 'Mollie payment verified', array( 'payment_id' => $payment_id, 'ref_id' => $ref_id ) );

		return array(
			'status' => 'success',
			'ref_id' => $ref_id,
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
		return get_transient( 'gatewaykit_mollie_redirect_' . $authority ) ?: '';
	}

	/**
	 * Handle an inbound Mollie webhook.
	 *
	 * Mollie POSTs the payment `id`; we verify by re-fetching the payment and
	 * updating the matching transaction.
	 */
	public function handle_webhook() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- external webhook callback from Mollie; no WP user session exists, security via re-fetching payment from Mollie API below
		$payment_id = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' === $payment_id ) {
			status_header( 400 );
			exit;
		}

		$payment = $this->api_request( '/payments/' . rawurlencode( $payment_id ), array(), 'GET' );

		if ( is_wp_error( $payment ) ) {
			GatewayKit_Logger::get_instance()->warning( 'Mollie webhook: could not fetch payment', array( 'payment_id' => $payment_id ) );
			status_header( 200 );
			exit;
		}

		$ref = get_transient( 'gatewaykit_mollie_pid_' . $payment_id );
		// Fallback to metadata if the transient expired.
		if ( ! $ref && isset( $payment['metadata']['gatewaykit_ref'] ) ) {
			$ref = sanitize_text_field( $payment['metadata']['gatewaykit_ref'] );
		}

		if ( ! $ref ) {
			GatewayKit_Logger::get_instance()->warning( 'Mollie webhook: no transaction reference for payment', array( 'payment_id' => $payment_id ) );
			status_header( 200 );
			exit;
		}

		$transaction = GatewayKit_Transaction_Model::find_by_authority( $ref );

		if ( ! $transaction ) {
			GatewayKit_Logger::get_instance()->warning( 'Mollie webhook: transaction not found', array( 'ref' => $ref ) );
			status_header( 200 );
			exit;
		}

		if ( in_array( $transaction->status, array( 'pending', 'processing' ), true ) ) {
			$status = isset( $payment['status'] ) ? $payment['status'] : '';

			if ( 'paid' === $status ) {
				$paid_amount     = isset( $payment['amount']['value'] ) ? (float) $payment['amount']['value'] : 0;
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

				$transaction->update( array(
					'status'       => 'completed',
					'ref_id'       => $payment_id,
					'completed_at' => current_time( 'mysql' ),
				) );
				do_action( 'gatewaykit_payment_completed', $transaction );
			} elseif ( in_array( $status, array( 'failed', 'canceled', 'expired' ), true ) ) {
				$transaction->update( array( 'status' => 'failed' ) );
				do_action( 'gatewaykit_payment_failed', $transaction, array( 'mollie_status' => $status ) );
			}
		}

		status_header( 200 );
		exit;
	}

	/**
	 * Resolve a Mollie payment id from a reference token (or pass through an id).
	 *
	 * @param string $authority Reference token or payment id.
	 * @return string
	 */
	private function resolve_payment_id( $authority ) {
		$mapped = get_transient( 'gatewaykit_mollie_ref_' . $authority );
		if ( $mapped ) {
			return $mapped;
		}
		// Looks like a Mollie id (tr_...).
		return ( 0 === strpos( $authority, 'tr_' ) ) ? $authority : '';
	}
}
