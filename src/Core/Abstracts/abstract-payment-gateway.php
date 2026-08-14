<?php
/**
 * Abstract Payment Gateway
 *
 * Base class for all payment gateway implementations
 *
 * @package GatewayKit
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract Payment Gateway Class
 */
abstract class GatewayKit_Abstract_Payment_Gateway implements GatewayKit_Payment_Gateway_Interface {

	/**
	 * Gateway settings
	 */
	protected $settings = array();

	/**
	 * Logger instance
	 */
	protected $logger;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->logger = GatewayKit_Logger::get_instance();
		$this->load_settings();
	}

	/**
	 * Load gateway settings
	 */
	protected function load_settings() {
		$gateway_id     = $this->get_gateway_id();
		$this->settings = get_option( 'gatewaykit_' . $gateway_id . '_settings', array() );
	}

	/**
	 * Get gateway ID (should be overridden by child classes)
	 *
	 * @return string Gateway ID
	 */
	abstract protected function get_gateway_id();

	/**
	 * Process payment request - must be implemented by child classes
	 */
	abstract public function process_payment( $amount, $description, $callback_url, $user_data );

	/**
	 * Verify payment - must be implemented by child classes
	 */
	abstract public function verify_payment( $authority, $amount );

	/**
	 * Get payment redirect URL
	 *
	 * @param string $authority Payment authority
	 * @return string Redirect URL
	 */
	public function get_redirect_url( $authority ) {
		// Default implementation - should be overridden if needed
		return '';
	}

	/**
	 * Get gateway name
	 *
	 * @return string Gateway name
	 */
	public function get_gateway_name() {
		return ucfirst( $this->get_gateway_id() );
	}

	/**
	 * Check if gateway is available
	 *
	 * @return bool True if available
	 */
	public function is_available() {
		return $this->validate_settings();
	}

	/**
	 * Get gateway settings
	 *
	 * @return array Gateway settings
	 */
	public function get_settings() {
		return $this->settings;
	}

	/**
	 * Validate gateway settings
	 *
	 * @return bool True if settings are valid
	 */
	public function validate_settings() {
		// Basic validation - check if required settings exist
		$required_settings = $this->get_required_settings();

		foreach ( $required_settings as $setting ) {
			if ( empty( $this->settings[ $setting ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Get required settings keys
	 *
	 * @return array Required settings
	 */
	protected function get_required_settings() {
		return array( 'merchant_id' );
	}

	/**
	 * Validate settings input
	 *
	 * This base implementation provides default validation that can be overridden
	 * by specific gateway implementations for more complex validation.
	 *
	 * @param array $settings Settings to validate
	 * @return array|WP_Error Validated settings or error
	 */
	public function validate_settings_input( $settings ) {
		// Basic validation - sanitize all input fields
		$validated_settings = array();

		foreach ( $settings as $key => $value ) {
			if ( is_string( $value ) ) {
				$validated_settings[ $key ] = sanitize_text_field( $value );
			} else {
				$validated_settings[ $key ] = $value;
			}
		}

		// Check required settings
		$required_settings = $this->get_required_settings();
		$errors            = array();

		foreach ( $required_settings as $setting ) {
			if ( empty( $validated_settings[ $setting ] ) ) {
				/* translators: %s: setting field name */
				$errors[ $setting ] = sprintf( __( '%s is required.', 'gatewaykit' ), ucfirst( str_replace( '_', ' ', $setting ) ) );
			}
		}

		if ( ! empty( $errors ) ) {
			// Return error with specific error messages
			$error_messages = array();
			foreach ( $errors as $field => $message ) {
				$error_messages[] = $field . ': ' . $message;
			}
			return new WP_Error( 'settings_validation_failed', implode( ' | ', $error_messages ) );
		}

		return $validated_settings;
	}

	/**
	 * Get setting value
	 *
	 * @param string $key     Setting key
	 * @param mixed  $default Default value
	 * @return mixed Setting value
	 */
	protected function get_setting( $key, $default = '' ) {
		return isset( $this->settings[ $key ] ) ? $this->settings[ $key ] : $default;
	}

	/**
	 * Check if sandbox mode is enabled
	 *
	 * @return bool True if sandbox mode
	 */
	protected function is_sandbox() {
		return isset( $this->settings['sandbox_mode'] ) && $this->settings['sandbox_mode'] === '1';
	}

	/**
	 * Log message
	 *
	 * @param string $level   Log level
	 * @param string $message Log message
	 * @param array  $context Context data
	 */
	protected function log( $level, $message, $context = array() ) {
		if ( $this->logger ) {
			$this->logger->log( $level, $message, $context );
		}
	}

	/**
	 * Get the configured currency for this gateway.
	 *
	 * @return string Uppercase ISO 4217 currency code.
	 */
	protected function get_currency() {
		$currency = $this->get_setting( 'currency', '' );
		if ( ! empty( $currency ) ) {
			return strtoupper( $currency );
		}
		$currency = get_option( 'gatewaykit_currency', '' );
		if ( empty( $currency ) ) {
			$currency = GatewayKit_Gateway_Manager::get_instance()->get_default_currency();
		}
		return strtoupper( $currency );
	}

	/**
	 * Format amount for gateway
	 *
	 * @param float $amount Amount
	 * @return int Formatted amount
	 */
	protected function format_amount( $amount ) {
		return intval( $amount );
	}

	/**
	 * Get the configurable settings fields for the admin UI.
	 *
	 * Each entry is keyed by the setting key and may contain:
	 * - 'type':        'text' | 'password' | 'checkbox' | 'select'
	 * - 'label':       Field label.
	 * - 'description': Help text.
	 * - 'options':     Associative array (for 'select').
	 *
	 * Concrete gateways override this to expose their own fields. The base
	 * implementation returns an empty set so the settings page still renders
	 * for gateways that have nothing to configure.
	 *
	 * @return array
	 */
	public function get_settings_fields() {
		return array();
	}

	/**
	 * Get the ISO 4217 currency codes this gateway supports.
	 *
	 * Returning an empty array means "no restriction" — the gateway accepts
	 * any currency the site is configured for. Concrete gateways should
	 * override this and return their actual supported-currency list so the
	 * compatibility check (Gateway_Manager::get_available_currencies()) can
	 * compute the intersection across all active gateways.
	 *
	 * @return string[] Array of uppercase ISO 4217 codes (empty = all).
	 */
	public function get_supported_currencies() {
		return array();
	}

	/**
	 * Get descriptive metadata about the gateway for the admin UI.
	 *
	 * @return array { 'description' => string }
	 */
	public function get_gateway_info() {
		return array(
			'description' => $this->get_gateway_name(),
		);
	}
}
