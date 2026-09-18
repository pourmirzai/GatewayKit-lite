<?php
/**
 * GatewayKit Nonce Service
 *
 * Centralized service for cache-safe, cookie-bound nonce generation and verification (F13).
 *
 * @package GatewayKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GatewayKit_Nonce_Service
 */
class GatewayKit_Nonce_Service {

	/**
	 * Name of the nonce-bind cookie (F13).
	 *
	 * Context-aware to prevent cookie collision on mixed pages that contain both
	 * an Elementor form and a GatewayKit standalone form.
	 *
	 * @param string $context Context ('elementor' or 'form').
	 * @return string Cookie name.
	 */
	public static function nonce_bind_cookie_name( $context = 'elementor' ) {
		return ( 'form' === $context ) ? 'gatewaykit_nbf' : 'gatewaykit_nb';
	}

	/**
	 * Compute the bind value for a faucet-issued nonce (F13).
	 *
	 * HMAC-SHA256 keyed by the WP nonce salt so the value is bound to this
	 * site and the nonce it accompanies.
	 *
	 * @param string $nonce Nonce issued by ajax_get_nonce().
	 * @return string
	 */
	public static function compute_nonce_bind( $nonce ) {
		return hash_hmac( 'sha256', (string) $nonce, wp_salt( 'nonce' ) );
	}

	/**
	 * Set the short-lived nonce-bind cookie (F13).
	 *
	 * @param string $nonce   Nonce issued by ajax_get_nonce().
	 * @param string $context Context ('elementor' or 'form').
	 */
	public static function set_nonce_bind_cookie( $nonce, $context = 'elementor' ) {
		$name    = self::nonce_bind_cookie_name( $context );
		$value   = self::compute_nonce_bind( $nonce );
		$expire  = time() + ( 15 * MINUTE_IN_SECONDS );
		$path    = defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/';
		$options = array(
			'expires'  => $expire,
			'path'     => $path,
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		);

		setcookie( $name, $value, $options );
		// Make the value available immediately for same-request callers.
		$_COOKIE[ $name ] = $value;
	}

	/**
	 * Verify that the nonce-bind cookie matches the submitted nonce (F13).
	 *
	 * @param string $nonce   Nonce submitted with the payment request.
	 * @param string $context Context ('elementor' or 'form').
	 * @return bool True when the cookie is present and matches the nonce.
	 */
	public static function verify_nonce_bind( $nonce, $context = 'elementor' ) {
		$name = self::nonce_bind_cookie_name( $context );
		if ( empty( $_COOKIE[ $name ] ) ) {
			return false;
		}
		$expected = self::compute_nonce_bind( $nonce );
		$supplied = sanitize_text_field( wp_unslash( $_COOKIE[ $name ] ) );
		return hash_equals( $expected, $supplied );
	}

	/**
	 * Generate and issue nonce + set bind cookie for AJAX faucet.
	 *
	 * @param string $context Context ('elementor' or 'form').
	 * @return array Array with 'nonce' and 'context'.
	 */
	public static function issue_nonce( $context = 'elementor' ) {
		$action = ( 'form' === $context ) ? 'gatewaykit_form_payment' : 'elementor_ajax';
		$nonce  = wp_create_nonce( $action );
		self::set_nonce_bind_cookie( $nonce, $context );
		return array(
			'nonce'   => $nonce,
			'context' => $context,
		);
	}

	/**
	 * Verify Elementor Pro Form Action nonce from submitted request data.
	 *
	 * Checks candidates against expected actions and logs diagnostic details on failure.
	 *
	 * @param array $fields  Optional custom field candidates.
	 * @param array $actions Optional custom action candidates.
	 * @return bool True if a valid nonce matched, false otherwise.
	 */
	public static function verify_elementor_action_nonce( array $fields = array(), array $actions = array() ) {
		$logger = GatewayKit_Logger::get_instance();

		if ( empty( $fields ) ) {
			$fields = array( '_wpnonce', 'nonce', 'gatewaykit_nonce', '_ajax_nonce', '_nonce' );
		}
		if ( empty( $actions ) ) {
			$actions = array( 'elementor_ajax', 'elementor-pro-forms', 'elementor_pro_forms_send_form' );
		}

		$fields_present = array();
		$results        = array();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonces are being read here specifically to verify them.
		foreach ( $fields as $field ) {
			if ( ! isset( $_POST[ $field ] ) ) {
				continue;
			}
			$fields_present[] = $field;
			$value            = sanitize_text_field( wp_unslash( $_POST[ $field ] ) );

			foreach ( $actions as $action ) {
				$valid                               = (bool) wp_verify_nonce( $value, $action );
				$results[ $field . ' / ' . $action ] = $valid;
				if ( $valid ) {
					$logger->info(
						'Nonce verification succeeded',
						array(
							'field'  => $field,
							'action' => $action,
						)
					);
					return true;
				}
			}
		}

		// Nothing matched - surface a detailed diagnostic at error level.
		$logger->error(
			'Nonce verification failed - no valid nonce matched',
			array(
				'fields_checked' => $fields,
				'fields_present' => $fields_present,
				'results'        => $results,
				'ajax_action'    => isset( $_POST['action'] ) ? sanitize_text_field( wp_unslash( $_POST['action'] ) ) : 'unknown',
				'post_id'        => isset( $_POST['post_id'] ) ? sanitize_text_field( wp_unslash( $_POST['post_id'] ) ) : 'unknown',
			)
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return false;
	}
}
