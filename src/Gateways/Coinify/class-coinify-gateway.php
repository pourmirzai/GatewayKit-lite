<?php
/**
 * Coinify Payment Gateway (Pro)
 *
 * Uses the Coinify Trade/Payment Intent REST API. Flow: create payment intent
 * -> redirect to the Coinify hosted page -> the buyer returns to the callback
 * URL -> the intent is verified by re-fetching it. The async webhook is
 * handled via the shared webhook endpoint and verified against the stored
 * intent token.
 *
 * The API key and secret are encrypted at rest with GatewayKit_Crypto.
 *
 * @package GatewayKit_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Coinify Gateway Class.
 */
class GatewayKit_Coinify_Gateway extends GatewayKit_Abstract_Payment_Gateway {

	/**
	 * Live API base URL.
	 */
	const LIVE_BASE = 'https://api.coinify.com/v1';

	/**
	 * Sandbox API base URL.
	 */
	const SANDBOX_BASE = 'https://api-sandbox.coinify.com/v1';

	/**
	 * Get gateway ID.
	 *
	 * @return string
	 */
	protected function get_gateway_id() {
		return 'coinify';
	}

	/**
	 * Gateway display name.
	 *
	 * @return string
	 */
	public function get_gateway_name() {
		return __( 'Coinify', 'gatewaykit' );
	}

	/**
	 * Currencies supported by Coinify (subset of PayPal list that Coinify
	 * also accepts as price_currency).
	 *
	 * @return string[]
	 */
	public function get_supported_currencies() {
		return array(
			'USD',
			'EUR',
			'GBP',
			'AUD',
			'CAD',
			'DKK',
			'HKD',
			'INR',
			'JPY',
			'MYR',
			'MXN',
			'NZD',
			'NOK',
			'SGD',
			'SEK',
			'CHF',
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
				'description' => __( 'Use the Coinify sandbox environment for testing.', 'gatewaykit' ),
			),
			'api_key'      => array(
				'type'        => 'password',
				'label'       => __( 'API Key', 'gatewaykit' ),
				'description' => __( 'Your Coinify API key. Stored encrypted.', 'gatewaykit' ),
			),
			'api_secret'   => array(
				'type'        => 'password',
				'label'       => __( 'API Secret', 'gatewaykit' ),
				'description' => __( 'Your Coinify API secret for webhook signature verification. Stored encrypted.', 'gatewaykit' ),
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
			'description' => __( 'Accept Bitcoin, Ethereum and other cryptocurrencies via Coinify hosted checkout.', 'gatewaykit' ),
		);
	}

	/**
	 * Decrypt API credentials after loading settings.
	 */
	protected function load_settings() {
		parent::load_settings();
		if ( ! empty( $this->settings['api_key'] ) ) {
			$this->settings['api_key'] = GatewayKit_Crypto::decrypt( $this->settings['api_key'] );
		}
		if ( ! empty( $this->settings['api_secret'] ) ) {
			$this->settings['api_secret'] = GatewayKit_Crypto::decrypt( $this->settings['api_secret'] );
		}
	}

	/**
	 * Encrypt API credentials before persisting settings.
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
		if ( isset( $validated['api_secret'] ) && '' !== $validated['api_secret'] ) {
			$validated['api_secret'] = GatewayKit_Crypto::encrypt( $validated['api_secret'] );
		}
		return $validated;
	}

	/**
	 * Coinify uses 2-decimal float amounts.
	 *
	 * @param float $amount Amount.
	 * @return float
	 */
	protected function format_amount( $amount ) {
		return round( (float) $amount, 2 );
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
	 * Get the decrypted API secret.
	 *
	 * @return string
	 */
	private function get_api_secret() {
		return $this->get_setting( 'api_secret', '' );
	}

	/**
	 * Build the webhook URL for this gateway.
	 *
	 * @return string
	 */
	private function get_webhook_url() {
		return add_query_arg( array( 'gatewaykit_webhook' => 'coinify' ), home_url( '/' ) );
	}

	/**
	 * Perform an authenticated Coinify API call.
	 *
	 * @param string $path   API path.
	 * @param array  $params Request parameters.
	 * @param string $method HTTP method.
	 * @return array|WP_Error
	 */
	private function api_request( $path, $params = array(), $method = 'POST' ) {
		$key    = $this->get_api_key();
		$secret = $this->get_api_secret();
		if ( '' === $key || '' === $secret ) {
			return new WP_Error( 'coinify_missing_credentials', __( 'Coinify API key or secret is not configured.', 'gatewaykit' ) );
		}

		$args = array(
			'method'  => $method,
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( $key . ':' . $secret ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
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
			$this->log( 'error', 'Coinify request failed: ' . $response->get_error_message(), array( 'path' => $path ) );
			return new WP_Error( 'coinify_request_failed', __( 'Could not connect to Coinify. Please try again later.', 'gatewaykit' ) );
		}

		$code    = wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$message = isset( $decoded['message'] ) ? $decoded['message'] : __( 'Coinify request failed.', 'gatewaykit' );
			if ( is_array( $decoded ) && isset( $decoded['errors'] ) ) {
				$message = wp_json_encode( $decoded['errors'] );
			}
			$this->log(
				'error',
				'Coinify API error',
				array(
					'path'   => $path,
					'status' => $code,
					'body'   => $decoded,
				)
			);
			return new WP_Error( 'coinify_api_error', $message );
		}

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Create a Coinify payment intent.
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

		$ref         = 'cf_' . wp_generate_password( 16, false );
		$success_url = add_query_arg( array( 'authority' => $ref ), $callback_url );
		$cancel_args = array( 'gatewaykit_cancel' => '1' );
		if ( ! empty( $user_data['receipt_token'] ) ) {
			$cancel_args['token'] = sanitize_text_field( $user_data['receipt_token'] );
		}
		$cancel_url = add_query_arg( $cancel_args, $callback_url );

		$params = array(
			'amount'       => $formatted,
			'currency'     => $currency,
			'external_id'  => $ref,
			'description'  => $description ? mb_substr( (string) $description, 0, 200 ) : __( 'Payment', 'gatewaykit' ),
			'callback_url' => $this->get_webhook_url(),
			'success_url'  => $success_url,
			'cancel_url'   => $cancel_url,
		);

		$response = $this->api_request( '/payment-intents', $params, 'POST' );

		if ( is_wp_error( $response ) ) {
			return array(
				'status'        => 'error',
				'error_type'    => 'gateway',
				'error_message' => $response->get_error_message(),
			);
		}

		$intent_id   = isset( $response['id'] ) ? (string) $response['id'] : '';
		$payment_url = '';
		if ( isset( $response['payment_url'] ) ) {
			$payment_url = $response['payment_url'];
		} elseif ( isset( $response['checkout_url'] ) ) {
			$payment_url = $response['checkout_url'];
		}
		$intent_token = isset( $response['token'] ) ? (string) $response['token'] : '';

		if ( '' === $intent_id || '' === $payment_url ) {
			return array(
				'status'        => 'error',
				'error_type'    => 'gateway',
				'error_message' => __( 'Coinify did not return a payment intent.', 'gatewaykit' ),
			);
		}

		$ttl = WEEK_IN_SECONDS;
		set_transient( 'gatewaykit_cf_ref_' . $ref, $intent_id, $ttl );
		set_transient( 'gatewaykit_cf_pid_' . $intent_id, $ref, $ttl );
		set_transient( 'gatewaykit_cf_token_' . $intent_id, $intent_token, $ttl );
		set_transient( 'gatewaykit_cf_redirect_' . $ref, $payment_url, DAY_IN_SECONDS );

		$this->log(
			'info',
			'Coinify intent created',
			array(
				'intent_id' => $intent_id,
				'ref'       => $ref,
				'amount'    => $formatted,
				'currency'  => $currency,
			)
		);

		return array(
			'status'       => 'success',
			'authority'    => $ref,
			'redirect_url' => $payment_url,
		);
	}

	/**
	 * Verify a Coinify payment intent.
	 *
	 * @param string $authority Local reference token (or a Coinify intent id).
	 * @param float  $amount    Expected payment amount.
	 * @return array Verification result.
	 */
	public function verify_payment( $authority, $amount = 0 ) {
		$authority = sanitize_text_field( (string) $authority );
		$intent_id = $this->resolve_intent_id( $authority );

		if ( '' === $intent_id ) {
			return array(
				'status'        => 'failed',
				'error_message' => __( 'Could not resolve the Coinify payment intent.', 'gatewaykit' ),
			);
		}

		$intent = $this->api_request( '/payment-intents/' . rawurlencode( $intent_id ), array(), 'GET' );

		if ( is_wp_error( $intent ) ) {
			return array(
				'status'        => 'failed',
				'error_message' => $intent->get_error_message(),
			);
		}

		$status = isset( $intent['status'] ) ? $intent['status'] : '';

		if ( ! in_array( $status, array( 'completed', 'settled' ), true ) ) {
			return array(
				'status'        => 'failed',
				'error_message' => sprintf(
					/* translators: %s: intent status */
					__( 'Coinify intent not paid (status: %s).', 'gatewaykit' ),
					$status
				),
			);
		}

		$expected = $this->format_amount( $amount );
		$paid     = isset( $intent['amount'] ) ? (float) $intent['amount'] : $expected;
		if ( abs( $paid - $expected ) > 0.01 ) {
			$this->log(
				'error',
				'Coinify amount mismatch',
				array(
					'expected' => $expected,
					'paid'     => $paid,
				)
			);
			return array(
				'status'        => 'failed',
				'error_message' => __( 'Coinify payment amount does not match the intent amount.', 'gatewaykit' ),
			);
		}

		$this->log( 'info', 'Coinify intent verified', array( 'intent_id' => $intent_id ) );

		return array(
			'status' => 'success',
			'ref_id' => $intent_id,
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
		return get_transient( 'gatewaykit_cf_redirect_' . $authority ) ?: '';
	}

	/**
	 * Handle an inbound Coinify webhook.
	 *
	 * Coinify POSTs the intent payload including the `token` and `status`. We
	 * verify the token against the one stored at intent creation, then update
	 * the matching transaction.
	 */
	public function handle_webhook() {
		$body    = file_get_contents( 'php://input' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$payload = json_decode( $body, true );

		if ( ! is_array( $payload ) ) {
			status_header( 400 );
			exit;
		}

		$intent_id = isset( $payload['id'] ) ? sanitize_text_field( (string) $payload['id'] ) : '';
		$token     = isset( $payload['token'] ) ? sanitize_text_field( (string) $payload['token'] ) : '';
		$status    = isset( $payload['status'] ) ? sanitize_text_field( (string) $payload['status'] ) : '';

		if ( '' === $intent_id ) {
			status_header( 400 );
			exit;
		}

		// Verify the HMAC-SHA256 signature. The signing secret is mandatory
		// (F5): a missing secret is a configuration error — refuse to act on
		// the payload rather than silently accepting forged webhooks.
		$secret = $this->get_api_secret();
		if ( '' === $secret ) {
			GatewayKit_Logger::get_instance()->error(
				'Coinify webhook: API secret is not configured — refusing to verify inbound payload',
				array( 'intent_id' => $intent_id )
			);
			status_header( 403 );
			exit;
		}

		// Read the webhook signature header. The Coinify docs
		// (https://coinify.readme.io/recipes/validate-webhook-signature)
		// document `X-Coinify-Webhook-Signature`; accept the legacy
		// `X-Coinify-Signature` spelling too for backward compatibility,
		// preferring the documented name (N5).
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- public webhook endpoint; authenticity verified via HMAC.
		$signature = '';
		if ( isset( $_SERVER['HTTP_X_COINIFY_WEBHOOK_SIGNATURE'] ) ) {
			$signature = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_COINIFY_WEBHOOK_SIGNATURE'] ) );
		} elseif ( isset( $_SERVER['HTTP_X_COINIFY_SIGNATURE'] ) ) {
			$signature = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_COINIFY_SIGNATURE'] ) );
		}
		// phpcs:enable
		$expected = hash_hmac( 'sha256', $body, $secret );
		if ( '' === $signature || ! hash_equals( $expected, $signature ) ) {
			GatewayKit_Logger::get_instance()->warning( 'Coinify webhook: signature mismatch', array( 'intent_id' => $intent_id ) );
			status_header( 403 );
			exit;
		}

		// Verify the token matches the one returned at intent creation.
		$stored_token = get_transient( 'gatewaykit_cf_token_' . $intent_id );
		if ( '' !== $stored_token && ! hash_equals( (string) $stored_token, $token ) ) {
			GatewayKit_Logger::get_instance()->warning( 'Coinify webhook: token mismatch', array( 'intent_id' => $intent_id ) );
			status_header( 403 );
			exit;
		}

		$ref = get_transient( 'gatewaykit_cf_pid_' . $intent_id );
		if ( ! $ref ) {
			$intent = $this->api_request( '/payment-intents/' . rawurlencode( $intent_id ), array(), 'GET' );
			$ref    = ( ! is_wp_error( $intent ) && isset( $intent['external_id'] ) ) ? sanitize_text_field( $intent['external_id'] ) : '';
		}

		if ( ! $ref ) {
			GatewayKit_Logger::get_instance()->warning( 'Coinify webhook: no transaction reference for intent', array( 'intent_id' => $intent_id ) );
			status_header( 200 );
			exit;
		}

		$transaction = GatewayKit_Transaction_Model::find_by_authority( $ref );

		if ( ! $transaction ) {
			GatewayKit_Logger::get_instance()->warning( 'Coinify webhook: transaction not found', array( 'ref' => $ref ) );
			status_header( 200 );
			exit;
		}

		if ( in_array( $transaction->status, array( 'pending', 'processing' ), true ) ) {
			if ( in_array( $status, array( 'completed', 'settled' ), true ) ) {
				$paid_amount     = isset( $payload['amount'] ) ? (float) $payload['amount'] : 0;
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
						'ref_id'       => $intent_id,
						'completed_at' => current_time( 'mysql' ),
					)
				);
				do_action( 'gatewaykit_payment_completed', $transaction );
			} elseif ( in_array( $status, array( 'failed', 'expired', 'refunded' ), true ) ) {
				$transaction->update( array( 'status' => 'failed' ) );
				do_action( 'gatewaykit_payment_failed', $transaction, array( 'coinify_status' => $status ) );
			}
		}

		status_header( 200 );
		exit;
	}

	/**
	 * Resolve a Coinify intent id from a reference token (or pass through).
	 *
	 * @param string $authority Reference token or intent id.
	 * @return string
	 */
	private function resolve_intent_id( $authority ) {
		$mapped = get_transient( 'gatewaykit_cf_ref_' . $authority );
		if ( $mapped ) {
			return $mapped;
		}
		return ctype_digit( $authority ) ? $authority : '';
	}
}
