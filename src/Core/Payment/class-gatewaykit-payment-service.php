<?php
/**
 * GatewayKit Payment Service
 *
 * Centralized service for initiating payments, creating transactions, and resolving gateways.
 *
 * @package GatewayKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GatewayKit_Payment_Service
 */
class GatewayKit_Payment_Service {

	/**
	 * Get current customer user data.
	 *
	 * @return array User data array including IP and User-Agent.
	 */
	public static function get_user_data() {
		$user_data = array();

		if ( is_user_logged_in() && get_current_user_id() ) {
			$user      = wp_get_current_user();
			$user_data = array(
				'id'    => $user->ID,
				'email' => $user->user_email,
				'name'  => $user->display_name,
			);
		}

		$user_data['ip']         = GatewayKit_IP_Helper::get_client_ip();
		$user_data['user_agent'] = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) );

		return $user_data;
	}

	/**
	 * Get callback URL for payment return.
	 *
	 * @param string $gateway Gateway slug or placeholder.
	 * @return string Full callback URL.
	 */
	public static function get_callback_url( $gateway = '{gateway}' ) {
		return add_query_arg(
			array(
				'gatewaykit_callback' => '1',
				'gateway'             => $gateway,
			),
			home_url( '/payment-callback/' )
		);
	}

	/**
	 * Process payment initiation pipeline.
	 *
	 * Pipeline: Create transaction -> Check gateway enabled/available -> Execute process_payment ->
	 * Update authority/redirect -> Set error on failure.
	 *
	 * @param array $payment_data Structured payment data.
	 * @return array|WP_Error Array with redirect_url/authority on success, or WP_Error on failure.
	 */
	public static function process_payment( array $payment_data ) {
		$logger = GatewayKit_Logger::get_instance();

		try {
			// 1. Create transaction record.
			$transaction = GatewayKit_Transaction_Model::create( $payment_data );

			if ( is_wp_error( $transaction ) ) {
				$logger->error(
					'Failed to create transaction record',
					array(
						'error' => $transaction->get_error_message(),
					)
				);
				return $transaction;
			}

			// 2. Get gateway instance.
			$gateway_manager = GatewayKit_Gateway_Manager::get_instance();
			$gateway_id      = $payment_data['gateway'] ?? '';
			$gateway         = $gateway_manager->get_gateway( $gateway_id );

			if ( ! $gateway ) {
				$logger->error(
					'Gateway not found for payment processing',
					array(
						'gateway' => $gateway_id,
					)
				);
				return new WP_Error( 'gateway_not_found', esc_html__( 'Payment gateway not found.', 'gatewaykit' ) );
			}

			// 3. Check if gateway is enabled.
			$enabled_gateways = get_option( 'gatewaykit_enabled_gateways', array() );
			if ( ! isset( $enabled_gateways[ $gateway_id ] ) || '1' !== (string) $enabled_gateways[ $gateway_id ] ) {
				$logger->error(
					'Gateway disabled for payment processing',
					array(
						'gateway' => $gateway_id,
					)
				);
				return new WP_Error( 'gateway_disabled', esc_html__( 'Selected payment gateway is not enabled.', 'gatewaykit' ) );
			}

			// 4. Check if gateway is configured/available.
			if ( ! $gateway->is_available() ) {
				$logger->error(
					'Gateway unavailable for payment processing',
					array(
						'gateway' => $gateway_id,
					)
				);
				return new WP_Error( 'gateway_unavailable', esc_html__( 'Selected payment gateway is not properly configured.', 'gatewaykit' ) );
			}

			// 5. Inject transaction receipt token into user_data for return handling.
			$user_data                  = isset( $payment_data['user_data'] ) && is_array( $payment_data['user_data'] ) ? $payment_data['user_data'] : array();
			$user_data['receipt_token'] = $transaction->get( 'receipt_token' );

			$callback_url = str_replace(
				'{gateway}',
				$gateway_id,
				$payment_data['callback_url'] ?? self::get_callback_url( $gateway_id )
			);

			// 6. Execute gateway payment processing.
			$result = $gateway->process_payment(
				$payment_data['amount'],
				$payment_data['description'] ?? '',
				$callback_url,
				$user_data
			);

			// 7. Handle success.
			if ( isset( $result['authority'] ) ) {
				$transaction->update( array( 'authority' => $result['authority'] ) );
				$redirect_url = ! empty( $result['redirect_url'] ) ? $result['redirect_url'] : $gateway->get_redirect_url( $result['authority'] );

				$logger->info(
					'Payment initiated successfully',
					array(
						'transaction_id' => $transaction->get( 'id' ),
						'gateway'        => $gateway_id,
						'authority'      => $result['authority'],
					)
				);

				return array(
					'status'         => 'success',
					'redirect_url'   => $redirect_url,
					'authority'      => $result['authority'],
					'transaction_id' => (int) $transaction->get( 'id' ),
				);
			}

			// 8. Handle gateway error.
			if ( isset( $result['status'] ) && 'error' === $result['status'] ) {
				$transaction->update(
					array(
						'status' => 'failed',
					)
				);

				if ( isset( $result['error_message'] ) || isset( $result['error_type'] ) ) {
					$transaction->set_error(
						$result['error_message'] ?? $result['message'] ?? 'Payment failed',
						$result['error_code'] ?? '',
						$result['error_type'] ?? 'unknown',
						$result['error_details'] ?? null
					);
				}

				$logger->error(
					'Payment initiation failed with gateway error',
					array(
						'transaction_id' => $transaction->get( 'id' ),
						'gateway'        => $gateway_id,
						'error'          => $result['error_message'] ?? $result['message'] ?? 'Unknown error',
					)
				);

				$error_handler = GatewayKit_Error_Handler::get_instance();
				return $error_handler->create_wp_error_from_gateway( $result, current_user_can( 'manage_options' ) );
			}

			$logger->error(
				'Payment initiation failed without authority',
				array(
					'transaction_id' => $transaction->get( 'id' ),
					'gateway'        => $gateway_id,
				)
			);

			return new WP_Error( 'payment_failed', esc_html__( 'Payment initiation failed. Please check your payment details and try again.', 'gatewaykit' ) );

		} catch ( \Throwable $e ) {
			$logger->error(
				'Exception during payment processing',
				array(
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				)
			);
			return new WP_Error( 'payment_error', esc_html__( 'An error occurred during payment processing.', 'gatewaykit' ) );
		}
	}
}
