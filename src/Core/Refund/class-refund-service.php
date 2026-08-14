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
		if ( ! gatewaykit_is_pro_licensed() ) {
			return new WP_Error( 'not_licensed', __( 'Refunds require a GatewayKit Pro license.', 'gatewaykit' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'forbidden', __( 'You do not have permission to process refunds.', 'gatewaykit' ) );
		}

		$transaction = GatewayKit_Transaction_Model::find( $transaction_id );

		if ( ! $transaction ) {
			return new WP_Error( 'not_found', __( 'Transaction not found.', 'gatewaykit' ) );
		}

		if ( 'completed' !== $transaction->status ) {
			return new WP_Error( 'invalid_status', __( 'Only completed transactions can be refunded.', 'gatewaykit' ) );
		}

		$lock_key = 'gatewaykit_refund_lock_' . $transaction_id;
		if ( false === wp_cache_add( $lock_key, 1, 'gatewaykit', 120 ) ) {
			return new WP_Error( 'refund_in_progress', __( 'A refund is already being processed for this transaction. Please wait.', 'gatewaykit' ) );
		}

		$gateway_id = $transaction->gateway;

		if ( ! $this->supports_refund( $gateway_id ) ) {
			wp_cache_delete( $lock_key, 'gatewaykit' );
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

		$handler = $this->handlers[ $gateway_id ];

		$result = $handler->refund( $transaction, $amount, $reason );

		if ( is_wp_error( $result ) ) {
			GatewayKit_Logger::get_instance()->error(
				'Refund failed',
				array(
					'transaction_id' => $transaction_id,
					'gateway'        => $gateway_id,
					'amount'         => $amount,
					'error'          => $result->get_error_message(),
				)
			);
			wp_cache_delete( $lock_key, 'gatewaykit' );
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

		$already_refunded = 0;
		if ( is_array( $existing_response ) && isset( $existing_response['total_refunded'] ) ) {
			$already_refunded = (float) $existing_response['total_refunded'];
		}

		if ( ( $amount + $already_refunded ) > $original_amount ) {
			wp_cache_delete( $lock_key, 'gatewaykit' );
			return new WP_Error(
				'exceeds_amount',
				sprintf(
					/* translators: 1: requested amount, 2: already refunded, 3: original amount */
					__( 'Refund amount (%1$s) plus already refunded (%2$s) exceeds the original payment (%3$s).', 'gatewaykit' ),
					$amount,
					$already_refunded,
					$original_amount
				)
			);
		}

		$refund_history                      = isset( $existing_response['refunds'] ) ? $existing_response['refunds'] : array();
		$refund_history[]                    = array(
			'refund_id'    => $refund_id,
			'amount'       => $amount,
			'reason'       => $reason,
			'refunded_at'  => current_time( 'mysql' ),
			'is_partial'   => $is_partial,
			'gateway_data' => $gateway_resp,
		);
		$existing_response['refunds']        = $refund_history;
		$existing_response['total_refunded'] = ( isset( $existing_response['total_refunded'] ) ? (float) $existing_response['total_refunded'] : 0 ) + $amount;

		$updated = $transaction->update(
			array(
				'status'           => $new_status,
				'gateway_response' => $existing_response,
			)
		);

		if ( false === $updated ) {
			wp_cache_delete( $lock_key, 'gatewaykit' );
			GatewayKit_Logger::get_instance()->error(
				'Refund succeeded at gateway but DB update failed — status inconsistency',
				array(
					'transaction_id' => $transaction_id,
					'gateway'        => $gateway_id,
				)
			);
			return new WP_Error(
				'db_update_failed',
				__( 'The refund was processed at the gateway, but the local database update failed. Please contact support — manual status correction may be required.', 'gatewaykit' )
			);
		}

		wp_cache_delete( $lock_key, 'gatewaykit' );

		GatewayKit_Logger::get_instance()->info(
			'Refund processed',
			array(
				'transaction_id' => $transaction_id,
				'gateway'        => $gateway_id,
				'amount'         => $amount,
				'refund_id'      => $refund_id,
				'is_partial'     => $is_partial,
			)
		);

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
