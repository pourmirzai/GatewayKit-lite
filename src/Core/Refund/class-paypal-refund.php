<?php
/**
 * PayPal Refund Handler (Pro)
 *
 * Processes refunds through the PayPal REST API v2. Supports full and partial
 * refunds on captured payments. Requires the capture_id from the original
 * transaction.
 *
 * @package GatewayKit_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PayPal refund handler.
 */
class GatewayKit_PayPal_Refund {

	/**
	 * PayPal API base URL (live).
	 */
	const API_BASE_LIVE = 'https://api-m.paypal.com';

	/**
	 * PayPal API base URL (sandbox).
	 */
	const API_BASE_SANDBOX = 'https://api-m.sandbox.paypal.com';

	/**
	 * Process a refund via PayPal.
	 *
	 * @param GatewayKit_Transaction_Model $transaction Transaction to refund.
	 * @param float                        $amount      Refund amount.
	 * @param string                       $reason      Optional reason.
	 * @return array|WP_Error Refund details or error.
	 */
	public function refund( $transaction, $amount, $reason = '' ) {
		$settings = get_option( 'gatewaykit_paypal_settings', array() );

		$client_id     = isset( $settings['client_id'] ) ? $settings['client_id'] : '';
		$client_secret = isset( $settings['client_secret'] ) ? GatewayKit_Crypto::decrypt( $settings['client_secret'] ) : '';
		$is_sandbox    = ! empty( $settings['sandbox_mode'] );

		if ( '' === $client_id || '' === $client_secret ) {
			return new WP_Error( 'missing_credentials', __( 'PayPal credentials are not configured.', 'gatewaykit' ) );
		}

		// Resolve the capture_id from the transaction.
		$capture_id = $this->resolve_capture_id( $transaction );

		if ( ! $capture_id ) {
			return new WP_Error(
				'missing_capture_id',
				__( 'Cannot determine the PayPal capture ID for this transaction.', 'gatewaykit' )
			);
		}

		// Get an access token.
		$access_token = $this->get_access_token( $client_id, $client_secret, $is_sandbox );

		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}

		$base_url = $is_sandbox ? self::API_BASE_SANDBOX : self::API_BASE_LIVE;
		$url      = $base_url . '/v2/payments/captures/' . rawurlencode( $capture_id ) . '/refund';

		$body = array(
			'amount' => array(
				'value'         => number_format( $amount, 2, '.', '' ),
				'currency_code' => strtoupper( $transaction->currency ),
			),
		);

		if ( '' !== $reason ) {
			$body['note_to_payer'] = $reason;
		}

		$args = array(
			'method'  => 'POST',
			'headers' => array(
				'Authorization' => 'Bearer ' . $access_token,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( $body ),
			'timeout' => 30,
		);

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'api_error', $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 201 !== $code || empty( $data['id'] ) ) {
			$detail = isset( $data['message'] ) ? $data['message'] : __( 'Unknown PayPal error.', 'gatewaykit' );
			return new WP_Error( 'paypal_refund_failed', $detail );
		}

		return array(
			'refund_id'        => $data['id'],
			'amount'           => $amount,
			'gateway_response' => array(
				'status' => isset( $data['status'] ) ? $data['status'] : '',
			),
		);
	}

	/**
	 * Resolve the capture ID from the transaction.
	 *
	 * @param GatewayKit_Transaction_Model $transaction Transaction.
	 * @return string|null Capture ID or null.
	 */
	private function resolve_capture_id( $transaction ) {
		// Check ref_id first (PayPal stores capture_id there).
		$ref_id = $transaction->ref_id;
		if ( ! empty( $ref_id ) && preg_match( '/^[A-Z0-9]{10,30}$/i', $ref_id ) ) {
			return $ref_id;
		}

		// Fallback: extract from gateway_response.
		$response = $transaction->gateway_response;
		if ( is_string( $response ) ) {
			$response = json_decode( $response, true );
		}
		if ( is_array( $response ) ) {
			if ( ! empty( $response['capture_id'] ) ) {
				return $response['capture_id'];
			}
			if ( ! empty( $response['purchase_units'][0]['payments']['captures'][0]['id'] ) ) {
				return $response['purchase_units'][0]['payments']['captures'][0]['id'];
			}
		}

		return null;
	}

	/**
	 * Get a PayPal OAuth access token.
	 *
	 * @param string $client_id     Client ID.
	 * @param string $client_secret Client secret.
	 * @param bool   $is_sandbox    Whether to use sandbox.
	 * @return string|WP_Error Access token or error.
	 */
	private function get_access_token( $client_id, $client_secret, $is_sandbox ) {
		$base_url  = $is_sandbox ? self::API_BASE_SANDBOX : self::API_BASE_LIVE;
		$token_key = 'gatewaykit_paypal_access_token_' . ( $is_sandbox ? 'sandbox' : 'live' );

		// Check cached token.
		$cached = get_transient( $token_key );
		if ( $cached ) {
			return $cached;
		}

		$response = wp_remote_post(
			$base_url . '/v1/oauth2/token',
			array(
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $client_id . ':' . $client_secret ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
					'Content-Type'  => 'application/x-www-form-urlencoded',
				),
				'body'    => 'grant_type=client_credentials',
				'timeout' => 20,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'auth_failed', $response->get_error_message() );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $data['access_token'] ) ) {
			$detail = isset( $data['error_description'] ) ? $data['error_description'] : __( 'PayPal authentication failed.', 'gatewaykit' );
			return new WP_Error( 'auth_failed', $detail );
		}

		// Cache for 50 minutes (token valid for 60 minutes).
		set_transient( $token_key, $data['access_token'], 50 * MINUTE_IN_SECONDS );

		return $data['access_token'];
	}
}
