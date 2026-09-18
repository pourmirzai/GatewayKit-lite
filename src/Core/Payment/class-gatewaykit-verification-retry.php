<?php
/**
 * GatewayKit Verification Retry
 *
 * Handles automated background retries for failed/pending payment verifications.
 *
 * @package GatewayKit
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GatewayKit_Verification_Retry
 */
class GatewayKit_Verification_Retry {

	/**
	 * Retry payment verification for failed or pending transactions.
	 *
	 * @param int $transaction_id Transaction ID.
	 */
	public static function retry( $transaction_id ) {
		$logger = GatewayKit_Logger::get_instance();

		$logger->info( 'Starting payment verification retry', array( 'transaction_id' => $transaction_id ) );

		try {
			$transaction = GatewayKit_Transaction_Model::find( $transaction_id );

			if ( ! $transaction ) {
				$logger->warning( 'Transaction not found for retry', array( 'transaction_id' => $transaction_id ) );
				return;
			}

			if ( 'pending' !== $transaction->get( 'status' ) ) {
				$logger->info(
					'Transaction no longer pending, skipping retry',
					array(
						'transaction_id' => $transaction_id,
						'current_status' => $transaction->get( 'status' ),
					)
				);
				return;
			}

			// Get gateway and attempt verification.
			$gateway_id      = $transaction->get( 'gateway' );
			$gateway_manager = GatewayKit_Gateway_Manager::get_instance();
			$gateway         = $gateway_manager->get_gateway( $gateway_id );

			if ( ! $gateway ) {
				$logger->error(
					'Gateway not found for retry',
					array(
						'transaction_id' => $transaction_id,
						'gateway_id'     => $gateway_id,
					)
				);
				return;
			}

			// Attempt verification.
			$authority           = $transaction->get( 'authority' );
			$amount              = $transaction->get( 'amount' );
			$verification_result = $gateway->verify_payment( $authority, $amount );

			if ( $verification_result && isset( $verification_result['success'] ) && $verification_result['success'] ) {
				// Payment verified successfully.
				$transaction->update(
					array(
						'status'           => 'completed',
						'ref_id'           => $verification_result['ref_id'] ?? '',
						'gateway_response' => wp_json_encode( $verification_result ),
						'completed_at'     => current_time( 'mysql' ),
					)
				);

				$logger->info(
					'Payment verification retry successful',
					array(
						'transaction_id' => $transaction_id,
						'ref_id'         => $verification_result['ref_id'] ?? '',
					)
				);

			} else {
				// Verification failed again.
				$transaction->update(
					array(
						'status'           => 'failed',
						'gateway_response' => wp_json_encode( $verification_result ),
					)
				);

				$logger->error(
					'Payment verification retry failed',
					array(
						'transaction_id'      => $transaction_id,
						'verification_result' => $verification_result,
					)
				);
			}
		} catch ( Exception $e ) {
			$logger->error(
				'Exception during payment verification retry',
				array(
					'transaction_id' => $transaction_id,
					'error'          => $e->getMessage(),
					'trace'          => $e->getTraceAsString(),
				)
			);

			// Mark as failed after retry exception.
			try {
				$transaction = GatewayKit_Transaction_Model::find( $transaction_id );
				if ( $transaction ) {
					$transaction->update(
						array(
							'status'           => 'failed',
							'gateway_response' => wp_json_encode(
								array(
									'error'   => 'retry_exception',
									'message' => $e->getMessage(),
								)
							),
						)
					);
				}
			} catch ( Exception $update_exception ) {
				$logger->error(
					'Failed to update transaction after retry exception',
					array(
						'transaction_id' => $transaction_id,
						'update_error'   => $update_exception->getMessage(),
					)
				);
			}
		}
	}
}
