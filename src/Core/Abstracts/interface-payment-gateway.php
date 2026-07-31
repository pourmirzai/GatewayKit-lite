<?php
/**
 * Payment Gateway Interface
 *
 * Defines the contract for all payment gateway implementations
 *
 * @package GatewayKit
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Payment Gateway Interface
 */
interface GatewayKit_Payment_Gateway_Interface {

	/**
	 * Process payment request
	 *
	 * @param float  $amount       Payment amount
	 * @param string $description  Payment description
	 * @param string $callback_url Callback URL for verification
	 * @param array  $user_data    User data array
	 * @return array Payment result with status, authority, redirect_url, etc.
	 */
	public function process_payment( $amount, $description, $callback_url, $user_data );

	/**
	 * Verify payment after callback
	 *
	 * @param string $authority Payment authority/token
	 * @param float  $amount    Expected payment amount
	 * @return array Verification result with status, ref_id, etc.
	 */
	public function verify_payment( $authority, $amount );

	/**
	 * Get payment redirect URL
	 *
	 * @param string $authority Payment authority
	 * @return string Redirect URL
	 */
	public function get_redirect_url( $authority );

	/**
	 * Get gateway name
	 *
	 * @return string Gateway name
	 */
	public function get_gateway_name();

	/**
	 * Get descriptive metadata about the gateway for the admin UI.
	 *
	 * @return array { 'description' => string }
	 */
	public function get_gateway_info();

	/**
	 * Check if gateway is available
	 *
	 * @return bool True if available
	 */
	public function is_available();

	/**
	 * Get gateway settings
	 *
	 * @return array Gateway settings
	 */
	public function get_settings();

	/**
	 * Validate gateway settings
	 *
	 * @return bool True if settings are valid
	 */
	public function validate_settings();

	/**
	 * Validate settings input
	 *
	 * @param array $settings Settings to validate
	 * @return array|WP_Error Validated settings or error
	 */
	public function validate_settings_input( $settings );

	/**
	 * Get the ISO 4217 currency codes this gateway supports.
	 *
	 * An empty array means "no restriction" — the gateway accepts any
	 * currency the site is configured for. Used by the Gateway Manager to
	 * compute the intersection of currencies across all active gateways.
	 *
	 * @return string[] Array of uppercase ISO 4217 codes (empty = all).
	 */
	public function get_supported_currencies();
}
