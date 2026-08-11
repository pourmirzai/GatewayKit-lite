<?php
/**
 * Security Manager
 *
 * Manages security aspects of the plugin: security headers, nonce/capability
 * helpers and payment-data validation.
 *
 * The bespoke SQLi/XSS request scanner and the duplicate per-IP rate limiter
 * that used to run on `wp_loaded` were removed (F6/F7/F9): the scanner
 * produced widespread false positives on legitimate form fields ("select from
 * these options", "update my settings", CSS hex colours, dashes in names…)
 * while leaving nested payloads uninspected, and the per-endpoint
 * GatewayKit_Rate_Limiter is the canonical, sliding-window implementation.
 * SQL safety continues to be enforced by $wpdb->prepare() in every model,
 * and XSS by sanitize_*() at the storage layer.
 *
 * @package GatewayKit
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Security Manager Class
 */
class GatewayKit_Security_Manager {

	/**
	 * Single instance
	 */
	private static $instance = null;

	/**
	 * Input validator instance
	 */
	private $validator;

	/**
	 * Get single instance
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 */
	private function __construct() {
		$this->validator = GatewayKit_Input_Validator::get_instance();
		$this->init_hooks();
	}

	/**
	 * Initialize hooks
	 */
	private function init_hooks() {
		// Add security headers.
		add_action( 'send_headers', array( $this, 'add_security_headers' ) );
	}

	/**
	 * Add security headers
	 */
	public function add_security_headers() {
		if ( ! is_admin() ) {
			header( 'X-Content-Type-Options: nosniff' );
			header( 'X-Frame-Options: SAMEORIGIN' );
			header( 'X-XSS-Protection: 1; mode=block' );
		}
	}

	/**
	 * Get client IP address
	 *
	 * @return string Client IP
	 */
	private function get_client_ip() {
		return GatewayKit_IP_Helper::get_client_ip();
	}

	/**
	 * Log security event
	 *
	 * @param string $event   Event type
	 * @param array  $context Context data
	 */
	private function log_security_event( $event, $context = array() ) {
		$logger = GatewayKit_Logger::get_instance();

		if ( $logger ) {
			$logger->log( 'warning', 'Security event: ' . $event, $context );
		}
	}

	/**
	 * Validate nonce for all admin actions
	 *
	 * @param string $action Nonce action
	 * @return bool True if valid
	 */
	public function verify_admin_nonce( $action ) {
		if ( ! is_admin() ) {
			return false;
		}

		$nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- this method performs the nonce verification
		if ( empty( $nonce ) ) {
			$this->log_security_event(
				'missing_nonce',
				array(
					'action' => $action,
					'ip' => $this->get_client_ip(),
				)
			);
			return false;
		}

		if ( ! wp_verify_nonce( $nonce, $action ) ) {
			$this->log_security_event(
				'invalid_nonce',
				array(
					'action' => $action,
					'nonce' => substr( $nonce, 0, 10 ) . '...',
					'ip' => $this->get_client_ip(),
				)
			);
			return false;
		}

		return true;
	}

	/**
	 * Validate CSRF token for AJAX requests
	 *
	 * @param string $action Action name
	 * @return bool True if valid
	 */
	public function verify_ajax_nonce( $action ) {
		if ( ! wp_doing_ajax() ) {
			return false;
		}

		$nonce = isset( $_REQUEST['nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- this method performs the nonce verification
		if ( empty( $nonce ) ) {
			$this->log_security_event(
				'missing_ajax_nonce',
				array(
					'action' => $action,
					'ip' => $this->get_client_ip(),
				)
			);
			return false;
		}

		if ( ! wp_verify_nonce( $nonce, $action ) ) {
			$this->log_security_event(
				'invalid_ajax_nonce',
				array(
					'action' => $action,
					'nonce' => substr( $nonce, 0, 10 ) . '...',
					'ip' => $this->get_client_ip(),
				)
			);
			return false;
		}

		return true;
	}

	/**
	 * Sanitize and validate input data
	 *
	 * @param array $data Input data to sanitize
	 * @param array $allowed_fields Allowed fields
	 * @return array Sanitized data
	 */
	public function sanitize_input_data( $data, $allowed_fields = array() ) {
		$sanitized = array();

		foreach ( $data as $key => $value ) {
			// Only process allowed fields if specified
			if ( ! empty( $allowed_fields ) && ! in_array( $key, $allowed_fields ) ) {
				continue;
			}

			// Sanitize key
			$sanitized_key = sanitize_key( $key );

			// Sanitize value based on type
			if ( is_array( $value ) ) {
				$sanitized[ $sanitized_key ] = $this->sanitize_input_data( $value, $allowed_fields );
			} elseif ( is_string( $value ) ) {
				$sanitized[ $sanitized_key ] = sanitize_text_field( $value );
			} elseif ( is_numeric( $value ) ) {
				$sanitized[ $sanitized_key ] = intval( $value );
			} elseif ( is_bool( $value ) ) {
				$sanitized[ $sanitized_key ] = $value ? 1 : 0;
			} else {
				$sanitized[ $sanitized_key ] = sanitize_text_field( (string) $value );
			}
		}

		return $sanitized;
	}

	/**
	 * Validate payment data
	 *
	 * @param array $data Payment data
	 * @return array|WP_Error Validated data or error
	 */
	public function validate_payment_data( $data, $context = array() ) {
		$errors = array();

		// Validate amount. Discounted orders skip the minimum-amount floor
		// because a valid discount may legitimately drop the final total below
		// it; the original cart already passed validation in calculate_amount().
		if ( isset( $data['amount'] ) ) {
			$validated_amount = $this->validator->validate_amount( $data['amount'], array( 'skip_min' => ! empty( $context['discounted'] ) ) );
			if ( is_wp_error( $validated_amount ) ) {
				$errors['amount'] = $validated_amount->get_error_message();
			} else {
				$data['amount'] = $validated_amount;
			}
		}

		// Validate gateway
		if ( isset( $data['gateway'] ) ) {
			$validated_gateway = $this->validator->validate_gateway( $data['gateway'] );
			if ( is_wp_error( $validated_gateway ) ) {
				$errors['gateway'] = $validated_gateway->get_error_message();
			} else {
				$data['gateway'] = $validated_gateway;
			}
		}

		// Validate URLs
		$url_fields = array( 'callback_url', 'success_url', 'failure_url' );
		foreach ( $url_fields as $field ) {
			if ( isset( $data[ $field ] ) ) {
				// For success_url, use required validation
				if ( $field === 'success_url' ) {
					$validated_url = $this->validator->validate_required_url( $data[ $field ] );
				} else {
					$validated_url = $this->validator->validate_url( $data[ $field ] );
				}

				if ( is_wp_error( $validated_url ) ) {
					$errors[ $field ] = $validated_url->get_error_message();
				} else {
					$data[ $field ] = $validated_url;
				}
			}
		}

		if ( ! empty( $errors ) ) {
			return new WP_Error( 'validation_failed', __( 'Validation failed.', 'gatewaykit' ), $errors );
		}

		return $data;
	}
}
