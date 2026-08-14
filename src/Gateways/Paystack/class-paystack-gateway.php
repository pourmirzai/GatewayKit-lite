<?php
/**
 * Paystack Payment Gateway
 *
 * Initializes a Paystack transaction -> redirects to Paystack hosted checkout ->
 * the buyer returns to the callback URL -> the payment is verified by re-fetching it.
 * Paystack's async webhook is handled via the shared webhook endpoint.
 *
 * Secret key is encrypted at rest with GatewayKit_Crypto.
 *
 * @package GatewayKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Paystack Gateway Class.
 */
class GatewayKit_Paystack_Gateway extends GatewayKit_Abstract_Payment_Gateway {

	/**
	 * API base URL.
	 */
	const API_BASE = 'https://api.paystack.co';

	/**
	 * Get gateway ID.
	 *
	 * @return string
	 */
	protected function get_gateway_id() {
		return 'paystack';
	}

	/**
	 * Gateway display name.
	 *
	 * @return string
	 */
	public function get_gateway_name() {
		return __( 'Paystack', 'gatewaykit' );
	}

	/**
	 * Currencies supported by Paystack.
	 *
	 * @return string[]
	 */
	public function get_supported_currencies() {
		return array( 'NGN', 'GHS', 'KES', 'ZAR', 'USD' );
	}

	/**
	 * Required settings.
	 *
	 * @return array
	 */
	protected function get_required_settings() {
		return array( 'secret_key' );
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
				'description' => __( 'Use your Paystack test keys (starts with sk_test_).', 'gatewaykit' ),
			),
			'secret_key'   => array(
				'type'        => 'password',
				'label'       => __( 'Secret Key', 'gatewaykit' ),
				'description' => __( 'Your Paystack secret key (sk_live_... or sk_test_...). Stored encrypted.', 'gatewaykit' ),
			),
			'public_key'   => array(
				'type'        => 'text',
				'label'       => __( 'Public Key', 'gatewaykit' ),
				'description' => __( 'Your Paystack public key (pk_live_... or pk_test_...).', 'gatewaykit' ),
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
			'description' => __( 'Accept cards, bank transfers, mobile money and USSD through Paystack across Africa.', 'gatewaykit' ),
		);
	}

	/**
	 * Decrypt the secret key after loading settings.
	 */
	protected function load_settings() {
		parent::load_settings();
		if ( ! empty( $this->settings['secret_key'] ) ) {
			$this->settings['secret_key'] = GatewayKit_Crypto::decrypt( $this->settings['secret_key'] );
		}
	}

	/**
	 * Encrypt the secret key before persisting settings.
	 *
	 * @param array $settings Raw settings input.
	 * @return array|WP_Error
	 */
	public function validate_settings_input( $settings ) {
		$validated = parent::validate_settings_input( $settings );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}
		if ( isset( $validated['secret_key'] ) && '' !== $validated['secret_key'] ) {
			$validated['secret_key'] = GatewayKit_Crypto::encrypt( $validated['secret_key'] );
		}
		return $validated;
	}

	/**
	 * Paystack expects amounts in the smallest currency unit
	 * (kobo for NGN, pesewas for GHS, cents for others).
	 *
	 * @param float $amount Amount.
	 * @return int
	 */
	protected function format_amount( $amount ) {
		return (int) round( (float) $amount * 100 );
	}

	/**
	 * Get the decrypted Paystack secret key.
	 *
	 * @return string
	 */
	private function get_secret_key() {
		$key = $this->get_setting( 'secret_key', '' );
		if ( $this->is_sandbox() && '' !== $key && 0 !== strpos( $key, 'sk_test_' ) ) {
			// Advisory only: test mode expects a test key.
			$this->log( 'warning', 'Paystack test mode enabled but the secret key does not start with sk_test_.', array() );
		}
		return $key;
	}

	/**
	 * Build the webhook URL for this gateway.
	 *
	 * @return string
	 */
	private function get_webhook_url() {
		return add_query_arg( array( 'gatewaykit_webhook' => 'paystack' ), home_url( '/' ) );
	}

	/**
	 * Perform an authenticated Paystack API call (JSON).
	 *
	 * @param string $path   API path.
	 * @param array  $body   Request body.
	 * @param string $method HTTP method.
	 * @return array|WP_Error
	 */
	private function api_request( $path, $body = array(), $method = 'POST' ) {
		$key = $this->get_secret_key();
		if ( '' === $key ) {
			return new WP_Error( 'paystack_missing_key', __( 'Paystack secret key is not configured.', 'gatewaykit' ) );
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
			$this->log( 'error', 'Paystack request failed: ' . $response->get_error_message(), array( 'path' => $path ) );
			return new WP_Error( 'paystack_request_failed', __( 'Could not connect to Paystack. Please try again later.', 'gatewaykit' ) );
		}

		$code    = wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$message = isset( $decoded['message'] ) ? $decoded['message'] : __( 'Paystack request failed.', 'gatewaykit' );
			$this->log(
				'error',
				'Paystack API error',
				array(
					'path'   => $path,
					'status' => $code,
					'body'   => $decoded,
				)
			);
			return new WP_Error( 'paystack_api_error', $message );
		}

		return $decoded;
	}

	/**
	 * Create a Paystack transaction.
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

		// Email is required by Paystack; resolve from user data or site admin.
		$email = '';
		if ( isset( $user_data['email'] ) && '' !== $user_data['email'] ) {
			$email = sanitize_email( $user_data['email'] );
		}
		if ( '' === $email ) {
			$email = get_option( 'admin_email' );
		}

		// Use the receipt token as the unique transaction reference when present,
		// otherwise generate one. Paystack echoes the reference back on redirect
		// via the "trxref" / "reference" query params, so we use it as the authority.
		$reference = '';
		if ( isset( $user_data['receipt_token'] ) && '' !== $user_data['receipt_token'] ) {
			$reference = 'gk_' . sanitize_key( $user_data['receipt_token'] );
		}
		if ( '' === $reference ) {
			$reference = 'gk_' . wp_generate_password( 16, false );
		}

		$body = array(
			'email'        => $email,
			'amount'       => $formatted,
			'currency'     => $currency,
			'reference'    => $reference,
			'callback_url' => $callback_url,
		);

		if ( $description ) {
			// Paystack does not have a description field per se, but accepts metadata.
			$body['metadata'] = array(
				'custom_fields'  => array(
					array(
						'display_name'  => __( 'Description', 'gatewaykit' ),
						'variable_name' => 'description',
						'value'         => mb_substr( (string) $description, 0, 255 ),
					),
				),
				'gatewaykit_ref' => $reference,
			);
		} else {
			$body['metadata'] = array(
				'gatewaykit_ref' => $reference,
			);
		}

		$response = $this->api_request( '/transaction/initialize', $body, 'POST' );

		if ( is_wp_error( $response ) ) {
			return array(
				'status'        => 'error',
				'error_type'    => 'gateway',
				'error_message' => $response->get_error_message(),
			);
		}

		$data = isset( $response['data'] ) ? $response['data'] : array();

		$authorization_url = isset( $data['authorization_url'] ) ? $data['authorization_url'] : '';
		$access_code       = isset( $data['access_code'] ) ? $data['access_code'] : '';

		if ( '' === $authorization_url ) {
			return array(
				'status'        => 'error',
				'error_type'    => 'gateway',
				'error_message' => __( 'Paystack did not return an authorization URL.', 'gatewaykit' ),
			);
		}

		// Cache the reference -> authorization_url mapping for redirect resolution.
		set_transient( 'gatewaykit_paystack_auth_' . $reference, $authorization_url, DAY_IN_SECONDS );

		$this->log(
			'info',
			'Paystack transaction initialized',
			array(
				'reference'   => $reference,
				'access_code' => $access_code,
				'amount'      => $formatted,
				'currency'    => $currency,
			)
		);

		return array(
			'status'       => 'success',
			'authority'    => $reference,
			'redirect_url' => $authorization_url,
		);
	}

	/**
	 * Verify a Paystack payment.
	 *
	 * @param string $authority Transaction reference.
	 * @param float  $amount    Expected payment amount.
	 * @return array Verification result.
	 */
	public function verify_payment( $authority, $amount = 0 ) {
		$reference = sanitize_text_field( (string) $authority );

		if ( '' === $reference ) {
			return array(
				'status'        => 'failed',
				'error_message' => __( 'Could not resolve the Paystack transaction reference.', 'gatewaykit' ),
			);
		}

		$response = $this->api_request( '/transaction/verify/' . rawurlencode( $reference ), array(), 'GET' );

		if ( is_wp_error( $response ) ) {
			return array(
				'status'        => 'failed',
				'error_message' => $response->get_error_message(),
			);
		}

		$data = isset( $response['data'] ) ? $response['data'] : array();

		$status = isset( $data['status'] ) ? $data['status'] : '';

		if ( 'success' !== $status ) {
			return array(
				'status'        => 'failed',
				'error_message' => sprintf(
					/* translators: %s: transaction status */
					__( 'Paystack payment not successful (status: %s).', 'gatewaykit' ),
					$status
				),
			);
		}

		// Validate amount (Paystack returns amount in smallest currency unit).
		$expected = $this->format_amount( $amount );
		$paid     = isset( $data['amount'] ) ? (int) $data['amount'] : $expected;

		if ( abs( $paid - $expected ) > 0 ) {
			$this->log(
				'error',
				'Paystack amount mismatch',
				array(
					'expected' => $expected,
					'paid'     => $paid,
				)
			);
			return array(
				'status'        => 'failed',
				'error_message' => __( 'Paystack payment amount does not match the order amount.', 'gatewaykit' ),
			);
		}

		$this->log( 'info', 'Paystack payment verified', array( 'reference' => $reference ) );

		return array(
			'status' => 'success',
			'ref_id' => $reference,
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
		return get_transient( 'gatewaykit_paystack_auth_' . $authority ) ?: '';
	}

	/**
	 * Handle an inbound Paystack webhook.
	 *
	 * Paystack POSTs a JSON event payload with an x-paystack-signature header
	 * that is the HMAC-SHA512 of the raw body keyed by the secret key. After
	 * verifying the signature we process charge.success events.
	 */
	public function handle_webhook() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- external webhook callback from Paystack; no WP user session exists, security is provided by the HMAC-SHA512 signature verification below
		$signature = isset( $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$raw_body = file_get_contents( 'php://input' );

		if ( '' === $raw_body ) {
			status_header( 400 );
			exit;
		}

		$key = $this->get_secret_key();

		// Verify the request signature using HMAC-SHA512.
		if ( '' === $key || ! hash_equals( hash_hmac( 'sha512', $raw_body, $key ), $signature ) ) {
			$this->log( 'warning', 'Paystack webhook: invalid signature', array() );
			status_header( 401 );
			exit;
		}

		$event = json_decode( $raw_body, true );

		if ( ! is_array( $event ) ) {
			status_header( 400 );
			exit;
		}

		$event_type = isset( $event['event'] ) ? $event['event'] : '';
		$data       = isset( $event['data'] ) ? $event['data'] : array();
		$reference  = isset( $data['reference'] ) ? sanitize_text_field( $data['reference'] ) : '';

		if ( '' === $reference ) {
			GatewayKit_Logger::get_instance()->warning( 'Paystack webhook: no transaction reference', array() );
			status_header( 200 );
			exit;
		}

		if ( 'charge.success' !== $event_type ) {
			// Acknowledge non-success events without acting on them.
			status_header( 200 );
			exit;
		}

		$transaction = GatewayKit_Transaction_Model::find_by_authority( $reference );

		if ( ! $transaction ) {
			GatewayKit_Logger::get_instance()->warning( 'Paystack webhook: transaction not found', array( 'reference' => $reference ) );
			status_header( 200 );
			exit;
		}

		if ( in_array( $transaction->status, array( 'pending', 'processing' ), true ) ) {
			// Paystack reports the amount in kobo (smallest currency unit).
			$paid_amount     = isset( $data['amount'] ) ? (float) $data['amount'] / 100 : 0;
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
					'ref_id'       => $reference,
					'completed_at' => current_time( 'mysql' ),
				)
			);
			do_action( 'gatewaykit_payment_completed', $transaction );
		}

		status_header( 200 );
		exit;
	}
}
