<?php
/**
 * Refund Service (Pro)
 *
 * Orchestrates refund processing across gateways. Each gateway provides
 * its own refund handler via the GatewayKit_Refund_Handler interface.
 *
 * @package GatewayKit_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Refund service orchestrator.
 */
class GatewayKit_Refund_Service {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Registered refund handlers keyed by gateway ID.
	 *
	 * @var array<string, GatewayKit_Refund_Handler>
	 */
	private $handlers = array();

	/**
	 * Get singleton instance.
	 *
	 * @return self
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor — registers gateway refund handlers.
	 */
	private function __construct() {
		$this->register_handlers();
	}

	/**
	 * Register refund handlers for each supported gateway.
	 */
	private function register_handlers() {
		// Stripe refund handler.
		if ( class_exists( 'GatewayKit_Stripe_Refund' ) ) {
			$this->handlers['stripe'] = new GatewayKit_Stripe_Refund();
		}

		// PayPal refund handler.
		if ( class_exists( 'GatewayKit_PayPal_Refund' ) ) {
			$this->handlers['paypal'] = new GatewayKit_PayPal_Refund();
		}

		/**
		 * Allow third-party gateways to register refund handlers.
		 *
		 * @param array<string, GatewayKit_Refund_Handler> $handlers Registered handlers.
		 */
		$this->handlers = apply_filters( 'gatewaykit_refund_handlers', $this->handlers );
	}

	/**
	 * Check if a gateway supports refunds.
	 *
	 * @param string $gateway_id Gateway identifier.
	 * @return bool
	 */
	public function supports_refund( $gateway_id ) {
		return isset( $this->handlers[ $gateway_id ] );
	}

	/**
	 * Process a refund for a transaction.
	 *
	 * @param int    $transaction_id Transaction ID.
	 * @param float  $amount         Refund amount (null = full refund).
	 * @param string $reason         Optional reason.
	 * @return array|WP_Error Array with refund details or error.
	 */
	public function process_refund( $transaction_id, $amount = null, $reason = '' ) {
		$transaction = GatewayKit_Transaction_Model::find( $transaction_id );

		if ( ! $transaction ) {
			return new WP_Error( 'not_found', __( 'Transaction not found.', 'gatewaykit' ) );
		}

		if ( 'completed' !== $transaction->status ) {
			return new WP_Error( 'invalid_status', __( 'Only completed transactions can be refunded.', 'gatewaykit' ) );
		}

		$gateway_id = $transaction->gateway;

		if ( ! $this->supports_refund( $gateway_id ) ) {
			return new WP_Error(
				'unsupported',
				sprintf(
					/* translators: %s: gateway name */
					__( 'Refunds are not supported for %s.', 'gatewaykit' ),
					ucfirst( $gateway_id )
				)
			);
		}

		$original_amount = (float) $transaction->amount;

		// Default to full refund.
		if ( null === $amount || $amount <= 0 ) {
			$amount = $original_amount;
		}

		if ( $amount > $original_amount ) {
			return new WP_Error( 'exceeds_amount', __( 'Refund amount cannot exceed the original payment amount.', 'gatewaykit' ) );
		}

		$handler = $this->handlers[ $gateway_id ];

		$result = $handler->refund( $transaction, $amount, $reason );

		if ( is_wp_error( $result ) ) {
			GatewayKit_Logger::get_instance()->error( 'Refund failed', array(
				'transaction_id' => $transaction_id,
				'gateway'        => $gateway_id,
				'amount'         => $amount,
				'error'          => $result->get_error_message(),
			) );
			return $result;
		}

		// Update transaction status.
		$is_partial = $amount < $original_amount;
		$new_status = $is_partial ? 'partially_refunded' : 'refunded';

		$refund_id    = isset( $result['refund_id'] ) ? $result['refund_id'] : '';
		$gateway_resp = isset( $result['gateway_response'] ) ? $result['gateway_response'] : array();

		// Store refund metadata in gateway_response for audit trail.
		$existing_response = $transaction->gateway_response;
		if ( is_string( $existing_response ) ) {
			$existing_response = json_decode( $existing_response, true );
		}
		if ( ! is_array( $existing_response ) ) {
			$existing_response = array();
		}

		$refund_history                    = isset( $existing_response['refunds'] ) ? $existing_response['refunds'] : array();
		$refund_history[]                  = array(
			'refund_id'     => $refund_id,
			'amount'        => $amount,
			'reason'        => $reason,
			'refunded_at'   => current_time( 'mysql' ),
			'is_partial'    => $is_partial,
			'gateway_data'  => $gateway_resp,
		);
		$existing_response['refunds']      = $refund_history;
		$existing_response['total_refunded'] = ( isset( $existing_response['total_refunded'] ) ? (float) $existing_response['total_refunded'] : 0 ) + $amount;

		$transaction->update( array(
			'status'            => $new_status,
			'gateway_response'  => $existing_response,
		) );

		GatewayKit_Logger::get_instance()->info( 'Refund processed', array(
			'transaction_id' => $transaction_id,
			'gateway'        => $gateway_id,
			'amount'         => $amount,
			'refund_id'      => $refund_id,
			'is_partial'     => $is_partial,
		) );

		return array(
			'transaction_id' => $transaction_id,
			'refund_id'      => $refund_id,
			'amount'         => $amount,
			'is_partial'     => $is_partial,
			'new_status'     => $new_status,
			'gateway_data'   => $gateway_resp,
		);
	}
}
