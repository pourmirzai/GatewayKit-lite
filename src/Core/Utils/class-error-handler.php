<?php
/**
 * Error Handler Utility
 *
 * Centralized error handling system for GatewayKit plugin
 * Maps error types to user-friendly messages and handles error categorization
 *
 * @package GatewayKit
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Error Handler Class
 */
class GatewayKit_Error_Handler {

	/**
	 * Singleton instance
	 */
	private static $instance = null;

	/**
	 * Error type mappings
	 */
	private $error_mappings = array();

	/**
	 * Constructor - initialize error mappings
	 */
	private function __construct() {
		$this->initialize_error_mappings();
	}

	/**
	 * Get singleton instance
	 *
	 * @return GatewayKit_Error_Handler
	 */
	public static function get_instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Initialize error type mappings
	 */
	private function initialize_error_mappings() {
		$this->error_mappings = array(
			'configuration' => array(
				'user_message'  => __( 'Payment gateway configuration error. Please contact site administrator.', 'gatewaykit' ),
				'admin_message' => __( 'Payment gateway is not properly configured.', 'gatewaykit' ),
				'error_codes'   => array(
					'api_not_initialized' => __( 'Gateway API not initialized.', 'gatewaykit' ),
					'gateway_disabled'    => __( 'Selected payment gateway is disabled.', 'gatewaykit' ),
					'gateway_unavailable' => __( 'Payment gateway is not available.', 'gatewaykit' ),
				),
			),
			'gateway'       => array(
				'user_message'  => __( 'Unable to process payment. Please check your payment details and try again.', 'gatewaykit' ),
				'admin_message' => __( 'Payment gateway error occurred.', 'gatewaykit' ),
				'error_codes'   => array(
					'api_request_failed'  => __( 'Payment gateway request failed.', 'gatewaykit' ),
					'verification_failed' => __( 'Payment verification failed.', 'gatewaykit' ),
					'request_failed'      => __( 'Payment request failed.', 'gatewaykit' ),
				),
			),
			'network'       => array(
				'user_message'  => __( 'Payment processing error. Please try again later.', 'gatewaykit' ),
				'admin_message' => __( 'Network or communication error occurred.', 'gatewaykit' ),
				'error_codes'   => array(
					'exception'              => __( 'Unexpected error occurred during payment processing.', 'gatewaykit' ),
					'verification_exception' => __( 'Error during payment verification.', 'gatewaykit' ),
				),
			),
			'validation'    => array(
				'user_message'  => __( 'Payment validation failed. Please check your form settings.', 'gatewaykit' ),
				'admin_message' => __( 'Payment data validation failed.', 'gatewaykit' ),
				'error_codes'   => array(
					'amount_field_missing' => __( 'Amount field not found.', 'gatewaykit' ),
					'gateway_not_found'    => __( 'Payment gateway not found.', 'gatewaykit' ),
				),
			),
			'rate_limit'    => array(
				'user_message'  => __( 'Too many requests. Please wait and try again.', 'gatewaykit' ),
				'admin_message' => __( 'Rate limit exceeded for payment processing.', 'gatewaykit' ),
				'error_codes'   => array(
					'rate_limit_exceeded' => __( 'Rate limit exceeded.', 'gatewaykit' ),
				),
			),
			'security'      => array(
				'user_message'  => __( 'Security check failed. Please reload and try again.', 'gatewaykit' ),
				'admin_message' => __( 'Security validation failed during payment processing.', 'gatewaykit' ),
				'error_codes'   => array(
					'nonce_failed' => __( 'Nonce verification failed.', 'gatewaykit' ),
				),
			),
			'unknown'       => array(
				'user_message'  => __( 'An unexpected error occurred. Please try again.', 'gatewaykit' ),
				'admin_message' => __( 'Unknown error occurred during payment processing.', 'gatewaykit' ),
				'error_codes'   => array(
					'payment_failed' => __( 'Payment initiation failed.', 'gatewaykit' ),
					'payment_error'  => __( 'Payment processing error.', 'gatewaykit' ),
				),
			),
		);
	}

	/**
	 * Get error message based on error type and context
	 *
	 * @param string $error_type Error type (configuration, gateway, network, etc.)
	 * @param string $error_code Specific error code
	 * @param bool   $is_admin Whether this is for admin context
	 * @param array  $error_details Additional error details
	 * @return string Formatted error message
	 */
	public function get_error_message( $error_type, $error_code = '', $is_admin = false, $error_details = array() ) {
		// Default to unknown error type if not found
		if ( ! isset( $this->error_mappings[ $error_type ] ) ) {
			$error_type = 'unknown';
		}

		$mapping = $this->error_mappings[ $error_type ];

		// Get base message based on context
		$message = $is_admin && isset( $mapping['admin_message'] )
			? $mapping['admin_message']
			: $mapping['user_message'];

		// Add specific error code message if available
		if ( ! empty( $error_code ) && isset( $mapping['error_codes'][ $error_code ] ) ) {
			$message .= ' ' . $mapping['error_codes'][ $error_code ];
		}

		// Add additional details for admin context
		if ( $is_admin && ! empty( $error_details ) ) {
			$message .= $this->format_error_details( $error_details );
		}

		return $message;
	}

	/**
	 * Format error details for admin display
	 *
	 * @param array $error_details Error details array
	 * @return string Formatted error details
	 */
	private function format_error_details( $error_details ) {
		$details = '';

		// Filter out sensitive information with comprehensive patterns
		$sensitive_patterns = array(
			'password',
			'token',
			'key',
			'secret',
			'merchant_id',
			'authorization',
			'authority',
			'receipt_token',
			'ref_id',
			'api_key',
			'access_token',
			'refresh_token',
			'client_secret',
			'private_key',
			'public_key',
			'card_number',
			'cvv',
			'expiry',
			'cardholder',
			'ssn',
		);

		$safe_details = array();
		foreach ( $error_details as $key => $value ) {
			$key_lower    = strtolower( $key );
			$is_sensitive = false;

			// Check for sensitive patterns
			foreach ( $sensitive_patterns as $pattern ) {
				if ( strpos( $key_lower, $pattern ) !== false ) {
					$is_sensitive = true;
					break;
				}
			}

			if ( ! $is_sensitive ) {
				$safe_details[ $key ] = is_scalar( $value ) ? $value : json_encode( $value );
			} else {
				// Partial redaction for debugging
				if ( is_string( $value ) && strlen( $value ) > 8 ) {
					$safe_details[ $key ] = substr( $value, 0, 4 ) . '[REDACTED]' . substr( $value, -4 );
				} else {
					$safe_details[ $key ] = '[REDACTED]';
				}
			}
		}

		if ( ! empty( $safe_details ) ) {
			$details = ' ' . __( 'Details:', 'gatewaykit' ) . ' ' . json_encode( $safe_details, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
		}

		return $details;
	}

	/**
	 * Create WP_Error from gateway error response
	 *
	 * @param array $gateway_error Gateway error response
	 * @param bool  $is_admin Whether this is for admin context
	 * @return WP_Error Formatted WP_Error object
	 */
	public function create_wp_error_from_gateway( $gateway_error, $is_admin = false ) {
		$error_type    = isset( $gateway_error['error_type'] ) ? $gateway_error['error_type'] : 'unknown';
		$error_code    = isset( $gateway_error['error_code'] ) ? $gateway_error['error_code'] : 'payment_failed';
		$error_details = isset( $gateway_error['error_details'] ) ? $gateway_error['error_details'] : array();
		$message       = isset( $gateway_error['message'] ) ? $gateway_error['message'] : '';

		// Get appropriate error message
		$error_message = $this->get_error_message( $error_type, $error_code, $is_admin, $error_details );

		// If gateway provided a specific message, use it as the primary message
		if ( ! empty( $message ) ) {
			$error_message = $message . ' ' . $error_message;
		}

		// Create WP_Error with additional data
		$wp_error = new WP_Error( $error_code, $error_message );

		// Add error details to WP_Error data
		$wp_error->add_data(
			array(
				'error_type'       => $error_type,
				'error_code'       => $error_code,
				'error_details'    => $error_details,
				'is_admin_context' => $is_admin,
			)
		);

		return $wp_error;
	}

	/**
	 * Log error with appropriate context
	 *
	 * @param string $error_type Error type
	 * @param string $error_code Error code
	 * @param array  $error_details Error details
	 * @param string $context Additional context
	 */
	public function log_error( $error_type, $error_code, $error_details = array(), $context = '' ) {
		$logger = GatewayKit_Logger::get_instance();

		$log_data = array(
			'error_type'    => $error_type,
			'error_code'    => $error_code,
			'error_details' => $error_details,
		);

		if ( ! empty( $context ) ) {
			$log_data['context'] = $context;
		}

		$logger->error( 'Payment error: ' . $error_type . '/' . $error_code, $log_data );
	}

	/**
	 * Get all error types
	 *
	 * @return array All registered error types
	 */
	public function get_error_types() {
		return array_keys( $this->error_mappings );
	}

	/**
	 * Get error codes for a specific error type
	 *
	 * @param string $error_type Error type
	 * @return array Error codes for the type
	 */
	public function get_error_codes( $error_type ) {
		if ( isset( $this->error_mappings[ $error_type ] ) ) {
			return array_keys( $this->error_mappings[ $error_type ]['error_codes'] );
		}
		return array();
	}

	/**
	 * Handle error recovery attempts
	 *
	 * @param string $error_type Error type
	 * @param string $error_code Error code
	 * @param array  $context Recovery context
	 * @return bool True if recovery was attempted
	 */
	public function attempt_error_recovery( $error_type, $error_code, $context = array() ) {
		$logger = GatewayKit_Logger::get_instance();

		// Log recovery attempt
		$logger->info(
			'Attempting error recovery',
			array(
				'error_type'       => $error_type,
				'error_code'       => $error_code,
				'recovery_context' => $context,
			)
		);

		$recovery_attempted = false;

		switch ( $error_type ) {
			case 'configuration':
				$recovery_attempted = $this->handle_configuration_recovery( $error_code, $context );
				break;

			case 'network':
				$recovery_attempted = $this->handle_network_recovery( $error_code, $context );
				break;

			case 'gateway':
				$recovery_attempted = $this->handle_gateway_recovery( $error_code, $context );
				break;
		}

		if ( $recovery_attempted ) {
			$logger->info(
				'Error recovery completed',
				array(
					'error_type'          => $error_type,
					'error_code'          => $error_code,
					'recovery_successful' => $recovery_attempted,
				)
			);
		}

		return $recovery_attempted;
	}

	/**
	 * Handle configuration error recovery
	 *
	 * @param string $error_code Error code
	 * @param array  $context Recovery context
	 * @return bool True if recovery was attempted
	 */
	private function handle_configuration_recovery( $error_code, $context ) {
		switch ( $error_code ) {
			case 'gateway_disabled':
				// Attempt to re-enable gateway if safe
				if ( isset( $context['gateway_id'] ) ) {
					$gateway_manager = GatewayKit_Gateway_Manager::get_instance();
					$gateway         = $gateway_manager->get_gateway( $context['gateway_id'] );
					if ( $gateway && $gateway->is_available() ) {
						// Gateway is actually available, update cache
						$gateway_manager->clear_all_caches();
						return true;
					}
				}
				break;
		}
		return false;
	}

	/**
	 * Handle network error recovery
	 *
	 * @param string $error_code Error code
	 * @param array  $context Recovery context
	 * @return bool True if recovery was attempted
	 */
	private function handle_network_recovery( $error_code, $context ) {
		switch ( $error_code ) {
			case 'exception':
			case 'verification_exception':
				// Implement exponential backoff for retry
				if ( isset( $context['retry_count'] ) && $context['retry_count'] < 3 ) {
					$delay = min( 30, pow( 2, $context['retry_count'] ) ); // Max 30 seconds
					sleep( $delay );
					return true;
				}
				break;
		}
		return false;
	}

	/**
	 * Handle gateway error recovery
	 *
	 * @param string $error_code Error code
	 * @param array  $context Recovery context
	 * @return bool True if recovery was attempted
	 */
	private function handle_gateway_recovery( $error_code, $context ) {
		switch ( $error_code ) {
			case 'api_request_failed':
				// Clear gateway cache and retry
				if ( isset( $context['gateway_id'] ) ) {
					$gateway_manager = GatewayKit_Gateway_Manager::get_instance();
					$gateway_manager->clear_all_caches();
					return true;
				}
				break;

			case 'verification_failed':
				// Schedule retry verification
				if ( isset( $context['transaction_id'] ) ) {
					wp_schedule_single_event(
						time() + 300,
						'gatewaykit_retry_payment_verification',
						array(
							'transaction_id' => $context['transaction_id'],
						)
					);
					return true;
				}
				break;
		}
		return false;
	}

	/**
	 * Create structured error response for API
	 *
	 * @param string $error_type Error type
	 * @param string $error_code Error code
	 * @param array  $context Error context
	 * @param bool   $is_admin Whether this is for admin context
	 * @return array Structured error response
	 */
	public function create_structured_error_response( $error_type, $error_code, $context = array(), $is_admin = false ) {
		$logger = GatewayKit_Logger::get_instance();

		// Log the error
		$logger->error(
			'Structured error created',
			array(
				'error_type' => $error_type,
				'error_code' => $error_code,
				'context'    => $context,
				'is_admin'   => $is_admin,
			)
		);

		// Attempt recovery
		$recovery_attempted = $this->attempt_error_recovery( $error_type, $error_code, $context );

		return array(
			'success' => false,
			'error'   => array(
				'type'               => $error_type,
				'code'               => $error_code,
				'message'            => $this->get_error_message( $error_type, $error_code, $is_admin, $context ),
				'recovery_attempted' => $recovery_attempted,
				'timestamp'          => current_time( 'mysql' ),
				'request_id'         => uniqid( 'gatewaykit_', true ),
			),
		);
	}
}
