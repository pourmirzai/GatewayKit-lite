<?php
/**
 * Rate Limiter
 *
 * Implements IP-based rate limiting using WordPress transients and options
 *
 * @package GatewayKit
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GatewayKit Rate Limiter Class
 */
class GatewayKit_Rate_Limiter {

	/**
	 * Transient prefix for rate limiting
	 */
	const TRANSIENT_PREFIX = 'gatewaykit_rate_limit_';

	/**
	 * Default time window in seconds (5 minutes)
	 */
	const DEFAULT_TIME_WINDOW = 300;

	/**
	 * Default max requests per IP
	 */
	const DEFAULT_MAX_REQUESTS = 30;

	/**
	 * Get singleton instance
	 *
	 * @return GatewayKit_Rate_Limiter
	 */
	public static function get_instance() {
		static $instance = null;
		if ( $instance === null ) {
			$instance = new self();
		}
		return $instance;
	}

	/**
	 * Check if rate limiting is enabled globally
	 *
	 * @return bool True if enabled
	 */
	public function is_enabled() {
		return get_option( 'gatewaykit_rate_limit_enabled', false );
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
	 * Get rate limit threshold for an endpoint
	 *
	 * @param string $endpoint Endpoint identifier
	 * @return int Max requests allowed
	 */
	private function get_threshold( $endpoint ) {
		$thresholds = array(
			'elementor_action' => get_option( 'gatewaykit_elementor_action_rate_limit', self::DEFAULT_MAX_REQUESTS ),
			'admin_ajax'       => get_option( 'gatewaykit_admin_ajax_rate_limit', self::DEFAULT_MAX_REQUESTS ),
			'callback'         => get_option( 'gatewaykit_callback_rate_limit', self::DEFAULT_MAX_REQUESTS ),
			// Dedicated bucket for the open gatewaykit_process_payment endpoint
			// (F13): caps pending-transaction creation per IP independently of
			// the generic admin_ajax limit. Default 10 / 5 min.
			'process_payment'  => get_option( 'gatewaykit_process_payment_rate_limit', 10 ),
		);

		return isset( $thresholds[ $endpoint ] ) ? intval( $thresholds[ $endpoint ] ) : self::DEFAULT_MAX_REQUESTS;
	}

	/**
	 * Get transient key for IP and endpoint
	 *
	 * @param string $ip Client IP
	 * @param string $endpoint Endpoint identifier
	 * @return string Transient key
	 */
	private function get_transient_key( $ip, $endpoint ) {
		return self::TRANSIENT_PREFIX . md5( $ip . '_' . $endpoint );
	}

	/**
	 * Check if request exceeds rate limit
	 *
	 * @param string $endpoint Endpoint identifier ('elementor_action', 'admin_ajax', 'callback')
	 * @return bool|WP_Error True if allowed, WP_Error if rate limited
	 */
	public function check_rate_limit( $endpoint ) {
		// Rate limiting is opt-in globally, but the process_payment bucket is
		// abuse protection on a public endpoint and is ALWAYS active regardless
		// of the gatewaykit_rate_limit_enabled toggle (N6). Its limit stays
		// tunable via gatewaykit_process_payment_rate_limit (see get_threshold).
		if ( 'process_payment' !== $endpoint && ! $this->is_enabled() ) {
			return true;
		}

		$ip            = $this->get_client_ip();
		$threshold     = $this->get_threshold( $endpoint );
		$transient_key = $this->get_transient_key( $ip, $endpoint );

		// Get current request count
		$current_count = get_transient( $transient_key );
		if ( $current_count === false ) {
			$current_count = 0;
		}

		// Check if threshold exceeded
		if ( $current_count >= $threshold ) {
			GatewayKit_Logger::get_instance()->warning(
				'Rate limit exceeded',
				array(
					'ip'            => $ip,
					'endpoint'      => $endpoint,
					'current_count' => $current_count,
					'threshold'     => $threshold,
				)
			);

			return new WP_Error(
				'rate_limit_exceeded',
				__( 'Too many requests. Please try again later.', 'gatewaykit' )
			);
		}

		// Increment and update transient
		$new_count = $current_count + 1;
		set_transient( $transient_key, $new_count, self::DEFAULT_TIME_WINDOW );

		GatewayKit_Logger::get_instance()->debug(
			'Rate limit check passed',
			array(
				'ip'        => $ip,
				'endpoint'  => $endpoint,
				'new_count' => $new_count,
				'threshold' => $threshold,
			)
		);

		return true;
	}

	/**
	 * Get current rate limit status for debugging
	 *
	 * @param string $endpoint Endpoint identifier
	 * @return array Status information
	 */
	public function get_status( $endpoint ) {
		$ip            = $this->get_client_ip();
		$transient_key = $this->get_transient_key( $ip, $endpoint );
		$current_count = get_transient( $transient_key );

		return array(
			'ip'            => $ip,
			'endpoint'      => $endpoint,
			'current_count' => $current_count ?: 0,
			'threshold'     => $this->get_threshold( $endpoint ),
			'time_window'   => self::DEFAULT_TIME_WINDOW,
			'enabled'       => 'process_payment' === $endpoint ? true : $this->is_enabled(),
		);
	}
}
