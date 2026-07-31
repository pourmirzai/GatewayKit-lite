<?php
/**
 * Input Validator
 *
 * Handles validation and sanitization of user inputs
 *
 * @package GatewayKit
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Input Validator Class
 */
class GatewayKit_Input_Validator {

	/**
	 * Single instance
	 */
	private static $instance = null;

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
	 * Validate amount
	 *
	 * @param mixed $amount Amount to validate
	 * @return float|WP_Error Validated amount or error
	 */
	public function validate_amount( $amount, $options = array() ) {
		// Sanitize
		$amount = $this->sanitize_amount( $amount );

		// Check if numeric
		if ( ! is_numeric( $amount ) ) {
			return new WP_Error( 'invalid_amount', __( 'Amount must be a valid number.', 'gatewaykit' ) );
		}

		// Convert to float
		$amount = floatval( $amount );

		// Check minimum amount (skippable for discounted orders where a code
		// legitimately lowers the final total below the normal floor).
		if ( empty( $options['skip_min'] ) ) {
			$default_min = 0.50;
			$min_amount = apply_filters( 'gatewaykit_min_amount', $default_min );
			if ( $amount < $min_amount ) {
				/* translators: %s: minimum allowed amount */
				return new WP_Error( 'amount_too_low', sprintf( __( 'Amount must be at least %s.', 'gatewaykit' ), $min_amount ) );
			}
		}

		// Check maximum amount
		$default_max = 1000000;
		$max_amount = apply_filters( 'gatewaykit_max_amount', $default_max );
		if ( $amount > $max_amount ) {
			/* translators: %s: maximum allowed amount */
			return new WP_Error( 'amount_too_high', sprintf( __( 'Amount cannot exceed %s.', 'gatewaykit' ), $max_amount ) );
		}

		return $amount;
	}

	/**
	 * Validate email
	 *
	 * @param string $email Email to validate
	 * @return string|WP_Error Validated email or error
	 */
	public function validate_email( $email ) {
		$email = sanitize_email( $email );

		if ( ! is_email( $email ) ) {
			return new WP_Error( 'invalid_email', __( 'Please enter a valid email address.', 'gatewaykit' ) );
		}

		return $email;
	}

	/**
	 * Validate URL
	 *
	 * @param string $url URL to validate
	 * @return string|WP_Error Validated URL or error
	 */
	public function validate_url( $url ) {
		$url = esc_url_raw( $url );

		if ( empty( $url ) ) {
			return new WP_Error( 'invalid_url', __( 'Please enter a valid URL.', 'gatewaykit' ) );
		}

		return $url;
	}

	/**
	 * Validate required URL
	 *
	 * @param string $url URL to validate
	 * @return string|WP_Error Validated URL or error
	 */
	public function validate_required_url( $url ) {
		$url = esc_url_raw( $url );

		if ( empty( $url ) ) {
			return new WP_Error( 'required_url_empty', __( 'Redirect URL after payment completion is required.', 'gatewaykit' ) );
		}

		return $url;
	}

	/**
	 * Validate gateway
	 *
	 * @param string $gateway Gateway ID
	 * @return string|WP_Error Validated gateway or error
	 */
	public function validate_gateway( $gateway ) {
		$gateway = sanitize_key( $gateway );

		if ( empty( $gateway ) ) {
			return new WP_Error( 'empty_gateway', __( 'Payment gateway is required.', 'gatewaykit' ) );
		}

		$gateway_manager    = GatewayKit_Gateway_Manager::get_instance();
		$available_gateways = $gateway_manager->get_available_gateways();

		if ( ! isset( $available_gateways[ $gateway ] ) ) {
			return new WP_Error( 'invalid_gateway', __( 'Selected payment gateway is not available or not enabled.', 'gatewaykit' ) );
		}

		return $gateway;
	}

	/**
	 * Validate form data
	 *
	 * @param array $form_data Form data to validate
	 * @return array|WP_Error Validated data or error
	 */
	public function validate_form_data( $form_data ) {
		if ( ! is_array( $form_data ) ) {
			return new WP_Error( 'invalid_form_data', __( 'Form data must be an array.', 'gatewaykit' ) );
		}

		$validated_data = array();

		foreach ( $form_data as $key => $value ) {
			$key = sanitize_key( $key );

			if ( is_array( $value ) ) {
				$validated_data[ $key ] = $this->sanitize_array( $value );
			} else {
				$validated_data[ $key ] = sanitize_text_field( $value );
			}
		}

		return $validated_data;
	}

	/**
	 * Sanitize amount
	 *
	 * @param mixed $amount Amount to sanitize
	 * @return string Sanitized amount
	 */
	public function sanitize_amount( $amount ) {
		return preg_replace( '/[^0-9.]/', '', (string) $amount );
	}

	/**
	 * Sanitize array recursively
	 *
	 * @param array $array Array to sanitize
	 * @return array Sanitized array
	 */
	private function sanitize_array( $array ) {
		$sanitized = array();

		foreach ( $array as $key => $value ) {
			$key = sanitize_key( $key );

			if ( is_array( $value ) ) {
				$sanitized[ $key ] = $this->sanitize_array( $value );
			} else {
				$sanitized[ $key ] = sanitize_text_field( $value );
			}
		}

		return $sanitized;
	}

	/**
	 * Validate nonce
	 *
	 * @param string $nonce  Nonce value
	 * @param string $action Nonce action
	 * @return bool True if valid
	 */
	public function validate_nonce( $nonce, $action ) {
		return wp_verify_nonce( $nonce, $action );
	}

	/**
	 * Check user capability
	 *
	 * @param string $capability Capability to check
	 * @return bool True if user has capability
	 */
	public function check_capability( $capability = 'manage_options' ) {
		return current_user_can( $capability );
	}
}
