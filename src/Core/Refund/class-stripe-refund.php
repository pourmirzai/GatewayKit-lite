<?php
/**
 * Stripe Refund Handler (Pro)
 *
 * Processes refunds through the Stripe API. Supports full and partial refunds.
 * Requires the checkout session's payment_intent to be stored in ref_id.
 *
 * @package GatewayKit_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stripe refund handler.
 */
class GatewayKit_Stripe_Refund {

	/**
	 * Stripe API base URL.
	 */
	const API_BASE = 'https://api.stripe.com/v1';

	/**
	 * Process a refund via Stripe.
	 *
	 * @param GatewayKit_Transaction_Model $transaction Transaction to refund.
	 * @param float                        $amount      Refund amount.
	 * @param string                       $reason      Optional reason.
	 * @return array|WP_Error Refund details or error.
	 */
	public function refund( $transaction, $amount, $reason = '' ) {
		$settings = get_option( 'gatewaykit_stripe_settings', array() );

		$secret_key = '';
		if ( ! empty( $settings['secret_key'] ) ) {
			$secret_key = GatewayKit_Crypto::decrypt( $settings['secret_key'] );
		}

		if ( '' === $secret_key ) {
			return new WP_Error( 'missing_key', __( 'Stripe secret key is not configured.', 'gatewaykit' ) );
		}

		// Resolve the payment_intent from ref_id or gateway_response.
		$payment_intent = $this->resolve_payment_intent( $transaction );

		if ( ! $payment_intent ) {
			return new WP_Error(
				'missing_payment_intent',
				__( 'Cannot determine the Stripe payment intent for this transaction.', 'gatewaykit' )
			);
		}

		// Format amount for Stripe (minor units).
		$currency     = strtoupper( $transaction->currency );
		$zero_decimal = array(
			'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA',
			'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
		);
		$stripe_amount = in_array( $currency, $zero_decimal, true )
			? (int) round( $amount )
			: (int) round( $amount * 100 );

		$params = array(
			'payment_intent' => $payment_intent,
			'amount'         => $stripe_amount,
		);

		if ( '' !== $reason ) {
			$params['reason'] = 'requested_by_customer';
		}

		// Build the Stripe API request.
		$url  = self::API_BASE . '/refunds';
		$args = array(
			'method'  => 'POST',
			'headers' => array(
				'Authorization' => 'Bearer ' . $secret_key,
			),
			'body'    => http_build_query( $params ),
			'timeout' => 30,
		);

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'api_error', $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || empty( $body['id'] ) ) {
			$detail = isset( $body['error']['message'] ) ? $body['error']['message'] : __( 'Unknown Stripe error.', 'gatewaykit' );
			return new WP_Error( 'stripe_refund_failed', $detail );
		}

		return array(
			'refund_id'       => $body['id'],
			'amount'          => $amount,
			'gateway_response' => array(
				'status' => isset( $body['status'] ) ? $body['status'] : '',
				'balance_transaction' => isset( $body['balance_transaction'] ) ? $body['balance_transaction'] : '',
			),
		);
	}

	/**
	 * Resolve the payment intent ID from the transaction.
	 *
	 * @param GatewayKit_Transaction_Model $transaction Transaction.
	 * @return string|null Payment intent ID or null.
	 */
	private function resolve_payment_intent( $transaction ) {
		// Primary: ref_id stores the payment_intent for Stripe Checkout.
		$ref_id = $transaction->ref_id;
		if ( ! empty( $ref_id ) && 0 === strpos( $ref_id, 'pi_' ) ) {
			return $ref_id;
		}

		// Fallback: extract from gateway_response.
		$response = $transaction->gateway_response;
		if ( is_string( $response ) ) {
			$response = json_decode( $response, true );
		}
		if ( is_array( $response ) && ! empty( $response['payment_intent'] ) ) {
			return $response['payment_intent'];
		}

		return null;
	}
}
