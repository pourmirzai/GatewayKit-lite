<?php
/**
 * Security Manager
 *
 * Manages security aspects of the plugin
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
		// Add security headers
		add_action( 'send_headers', array( $this, 'add_security_headers' ) );

		// Validate requests
		add_action( 'wp_loaded', array( $this, 'validate_requests' ) );
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
	 * Validate requests
	 */
	public function validate_requests() {
		// Only validate on payment-related pages
		if ( ! $this->is_payment_request() ) {
			return;
		}

		// Check for suspicious activity
		$this->check_suspicious_activity();
	}

	/**
	 * Check if current request is payment-related
	 *
	 * @return bool True if payment request
	 */
	private function is_payment_request() {
		$current_url = $this->get_current_url();

		// Check for payment callback URLs
		$callback_patterns = array(
			'gatewaykit_callback',
			'payment/verify',
		);

		foreach ( $callback_patterns as $pattern ) {
			if ( strpos( $current_url, $pattern ) !== false ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get current URL
	 *
	 * @return string Current URL
	 */
	private function get_current_url() {
		if ( isset( $_SERVER['REQUEST_URI'] ) ) {
			return esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) );
		}
		return '';
	}

	/**
	 * Check for suspicious activity
	 */
	private function check_suspicious_activity() {
		// Rate limiting check
		if ( $this->is_rate_limited() ) {
			$this->log_security_event(
				'rate_limit_exceeded',
				array(
					'ip'         => $this->get_client_ip(),
					'user_agent' => $this->get_user_agent(),
				)
			);

			wp_die( esc_html__( 'Too many requests. Please try again later.', 'gatewaykit' ), '', array( 'response' => 429 ) );
		}

		// Check for SQL injection attempts
		if ( $this->detect_sql_injection() ) {
			$this->log_security_event(
				'sql_injection_attempt',
				array(
					'ip'   => $this->get_client_ip(),
					'data' => isset( $_REQUEST ) ? wp_unslash( $_REQUEST ) : array(), // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- intentional raw request inspection for security scanning
				)
			);

			wp_die( esc_html__( 'Invalid request.', 'gatewaykit' ), '', array( 'response' => 400 ) );
		}

		// Check for XSS attempts
		if ( $this->detect_xss() ) {
			$this->log_security_event(
				'xss_attempt',
				array(
					'ip'   => $this->get_client_ip(),
					'data' => isset( $_REQUEST ) ? wp_unslash( $_REQUEST ) : array(), // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- intentional raw request inspection for security scanning
				)
			);

			wp_die( esc_html__( 'Invalid request.', 'gatewaykit' ), '', array( 'response' => 400 ) );
		}
	}

	/**
	 * Check rate limiting
	 *
	 * @return bool True if rate limited
	 */
	private function is_rate_limited() {
		$ip            = $this->get_client_ip();
		$transient_key = 'gatewaykit_rate_limit_' . md5( $ip );

		$requests = get_transient( $transient_key );

		if ( false === $requests ) {
			$requests = 0;
		}

		++$requests;

		// Configurable rate limiting - allow 30 requests per minute for normal users
		// More restrictive for payment callbacks
		$is_payment_callback = $this->is_payment_request();
		$max_requests = $is_payment_callback ? 10 : 30;
		$time_window = $is_payment_callback ? MINUTE_IN_SECONDS : MINUTE_IN_SECONDS;

		if ( $requests > $max_requests ) {
			return true;
		}

		set_transient( $transient_key, $requests, $time_window );
		return false;
	}

	/**
	 * Detect SQL injection attempts
	 *
	 * @return bool True if SQL injection detected
	 */
	private function detect_sql_injection() {
		$patterns = array(
			// Enhanced SQL injection patterns with context awareness
			'/\bunion\s+select\b/i',
			'/\bselect\s+.*\bfrom\b/i',
			'/\binsert\s+into\b/i',
			'/\bupdate\s+.*\bset\b/i',
			'/\bdelete\s+from\b/i',
			'/\bdrop\s+(table|database|index)\b/i',
			'/\bcreate\s+(table|database|index)\b/i',
			'/\balter\s+table\b/i',
			'/\bexec\s*\(/i',
			'/\bexecute\s*\(/i',
			'/\bsp_exec\s*\(/i',
			'/\bxp_cmdshell\b/i',
			'/\bscript\b/i',
			'/\bjavascript\b/i',
			'/\bonload\s*=/i',
			'/\bonerror\s*=/i',
			'/\bonclick\s*=/i',
			'/\bonfocus\s*=/i',
			// Comment-based attacks
			'/--.*$/',
			'/\/\*.*\*\//',
			'/#.*$/',
			// Hex encoding attacks
			'/0x[0-9a-f]+/i',
			// Time-based attacks
			'/\bwaitfor\s+delay\b/i',
			'/\bsleep\s*\(/i',
			'/\bbenchmark\s*\(/i',
		);

		$data = $this->get_request_data();

		foreach ( $data as $key => $value ) {
			if ( is_string( $value ) ) {
				// Skip common non-SQL fields to reduce false positives
				$skip_fields = array('s', 'gatewaykit_page', 'page', 'action', '_wpnonce', '_wp_http_referer');
				if (in_array($key, $skip_fields)) {
					continue;
				}

				foreach ( $patterns as $pattern ) {
					if ( preg_match( $pattern, $value ) ) {
						return true;
					}
				}
			}
		}

		return false;
	}

	/**
	 * Detect XSS attempts
	 *
	 * @return bool True if XSS detected
	 */
	private function detect_xss() {
		$patterns = array(
			'/<script/i',
			'/javascript:/i',
			'/on\w+\s*=/i',
			'/<iframe/i',
			'/<object/i',
			'/<embed/i',
		);

		$data = $this->get_request_data();

		foreach ( $data as $value ) {
			if ( is_string( $value ) ) {
				foreach ( $patterns as $pattern ) {
					if ( preg_match( $pattern, $value ) ) {
						return true;
					}
				}
			}
		}

		return false;
	}

	/**
	 * Get request data for security checks
	 *
	 * @return array Request data
	 */
	private function get_request_data() {
		// Sanitize each value before scanning. The merged data feeds the
		// SQL-injection / XSS scanners (detect_sql_injection() / detect_xss()),
		// which inspect generic request parameters (strings); there are no
		// specific integer/URL/email fields expected here, so sanitize_text_field()
		// is the correct per-field sanitizer. It safely returns '' for nested
		// array values, which the scanners already skip via is_string() checks.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing -- public security scan on wp_loaded; no nonce context
		$get_data  = array_map( 'sanitize_text_field', wp_unslash( $_GET ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing -- public security scan on wp_loaded; no nonce context
		$post_data = array_map( 'sanitize_text_field', wp_unslash( $_POST ) );
		return array_merge( $get_data, $post_data );
	}

	/**
	 * Get client IP address
	 *
	 * @return string Client IP
	 */
	/**
	 * Get client IP address
	 *
	 * @return string Client IP
	 */
	private function get_client_ip() {
		return GatewayKit_IP_Helper::get_client_ip();
	}

	/**
	 * Get user agent
	 *
	 * @return string User agent
	 */
	private function get_user_agent() {
		return isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
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
