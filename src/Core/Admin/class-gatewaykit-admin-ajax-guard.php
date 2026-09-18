<?php
/**
 * Admin AJAX Guard
 *
 * Provides shared security verification (rate limit, nonce, capability)
 * for admin AJAX requests.
 *
 * @package GatewayKit
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin AJAX Guard Class
 */
class GatewayKit_Admin_Ajax_Guard {

	/**
	 * Verify rate limit, nonce, and user capability for an admin AJAX action.
	 *
	 * Sends a JSON error response and terminates execution if any check fails.
	 *
	 * @param string $action     Nonce action name. Default 'gatewaykit_ajax_nonce'.
	 * @param string $query_arg  Nonce request parameter key. Default 'nonce'.
	 * @param string $capability Required user capability. Default 'manage_options'.
	 * @return bool True if verification passes; terminates otherwise.
	 */
	public static function verify( $action = 'gatewaykit_ajax_nonce', $query_arg = 'nonce', $capability = 'manage_options' ) {
		// Rate limiting check.
		$rate_limiter = GatewayKit_Rate_Limiter::get_instance();
		$rate_check   = $rate_limiter->check_rate_limit( 'admin_ajax' );
		if ( is_wp_error( $rate_check ) ) {
			wp_send_json_error( $rate_check->get_error_message() );
			return false;
		}

		// Verify nonce for security.
		check_ajax_referer( $action, $query_arg );

		// Check user capabilities.
		if ( ! current_user_can( $capability ) ) {
			wp_send_json_error( __( 'You do not have sufficient permissions to access this page.', 'gatewaykit' ) );
			return false;
		}

		return true;
	}
}
