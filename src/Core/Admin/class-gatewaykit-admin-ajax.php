<?php
/**
 * Admin AJAX Handlers
 *
 * Handles AJAX requests for testing gateways and webhooks.
 *
 * @package GatewayKit
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin AJAX Class
 */
class GatewayKit_Admin_Ajax {

	/**
	 * Register AJAX action hooks.
	 */
	public function register_hooks() {
		add_action( 'wp_ajax_gatewaykit_test_gateway', array( $this, 'ajax_test_gateway' ) );
		add_action( 'wp_ajax_gatewaykit_test_webhook', array( $this, 'ajax_test_webhook' ) );
	}

	/**
	 * AJAX handler: test gateway connectivity with unsaved credentials.
	 */
	public function ajax_test_gateway() {
		if ( ! GatewayKit_Admin_Ajax_Guard::verify() ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified via GatewayKit_Admin_Ajax_Guard::verify().
		$gateway_id = isset( $_POST['gateway'] ) ? sanitize_key( wp_unslash( $_POST['gateway'] ) ) : '';
		if ( '' === $gateway_id ) {
			wp_send_json_error( __( 'Gateway is required.', 'gatewaykit' ) );
			return;
		}

		// Collect raw (unsaved) settings overrides sent from the admin form.
		$overrides    = array();
		$raw_settings = isset( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- array of settings; each value sanitized in the loop below
		if ( is_array( $raw_settings ) ) {
			foreach ( $raw_settings as $key => $value ) {
				if ( is_string( $value ) ) {
					$overrides[ sanitize_key( $key ) ] = sanitize_text_field( $value );
				}
			}
		}

		$gateway_manager = GatewayKit_Gateway_Manager::get_instance();
		$registered      = $gateway_manager->get_registered_gateways();

		if ( ! isset( $registered[ $gateway_id ] ) ) {
			wp_send_json_error( __( 'Gateway is not registered.', 'gatewaykit' ) );
			return;
		}

		$result = $gateway_manager->test_gateway( $gateway_id, $overrides );

		if ( ! empty( $result['success'] ) ) {
			wp_send_json_success( array( 'message' => $result['message'] ) );
		} else {
			wp_send_json_error( isset( $result['message'] ) ? $result['message'] : __( 'Test failed.', 'gatewaykit' ) );
		}
		// phpcs:enable
	}

	/**
	 * AJAX handler: dispatch a test webhook event to all configured endpoints.
	 */
	public function ajax_test_webhook() {
		if ( ! GatewayKit_Admin_Ajax_Guard::verify() ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified via GatewayKit_Admin_Ajax_Guard::verify().
		$urls_raw = isset( $_POST['webhook_urls'] ) ? sanitize_textarea_field( wp_unslash( $_POST['webhook_urls'] ) ) : '';
		$secret   = isset( $_POST['webhook_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['webhook_secret'] ) ) : '';

		$urls = array_filter( array_map( 'trim', explode( "\n", $urls_raw ) ) );
		$urls = array_filter( array_map( 'esc_url_raw', $urls ) );

		if ( empty( $urls ) ) {
			wp_send_json_error( array( 'message' => __( 'No valid webhook URLs configured. Please add at least one URL and save before testing.', 'gatewaykit' ) ) );
			return;
		}

		$payload = array(
			'event'          => 'webhook_test',
			'transaction_id' => 0,
			'status'         => 'test',
			'amount'         => 0.0,
			'currency'       => 'USD',
			'gateway'        => 'test',
			'description'    => __( 'This is a test webhook delivery from GatewayKit.', 'gatewaykit' ),
			'receipt_token'  => '',
			'created_at'     => current_time( 'mysql' ),
			'completed_at'   => current_time( 'mysql' ),
			'customer'       => array(
				'email' => 'test@example.com',
				'name'  => 'Test User',
			),
			'test'           => true,
		);

		/**
		 * Filter the test webhook payload before dispatch.
		 *
		 * @param array $payload The test payload.
		 */
		$payload = apply_filters( 'gatewaykit_webhook_test_payload', $payload );

		$body = wp_json_encode( $payload );

		if ( false === $body ) {
			wp_send_json_error( array( 'message' => __( 'Failed to encode test payload.', 'gatewaykit' ) ) );
			return;
		}

		$signature = '' !== $secret ? hash_hmac( 'sha256', $body, $secret ) : '';
		$results   = array();

		foreach ( $urls as $url ) {
			$headers = array(
				'Content-Type' => 'application/json',
			);
			if ( '' !== $signature ) {
				$headers['X-GatewayKit-Signature'] = $signature;
			}

			$response = wp_remote_post(
				$url,
				array(
					'headers' => $headers,
					'body'    => $body,
					'timeout' => 15,
				)
			);

			$status_code = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );

			$results[] = array(
				'url'         => $url,
				'status_code' => $status_code,
				'success'     => ! is_wp_error( $response ) && $status_code >= 200 && $status_code < 300,
				'error'       => is_wp_error( $response ) ? $response->get_error_message() : '',
			);
		}

		$all_success = true;
		foreach ( $results as $r ) {
			if ( ! $r['success'] ) {
				$all_success = false;
				break;
			}
		}

		if ( $all_success ) {
			wp_send_json_success(
				array(
					'message' => sprintf(
					/* translators: %d: number of URLs */
						__( 'Test webhook sent successfully to %d URL(s).', 'gatewaykit' ),
						count( $results )
					),
					'results' => $results,
				)
			);
		} else {
			$failed = array();
			foreach ( $results as $r ) {
				if ( ! $r['success'] ) {
					$failed[] = $r['url'] . ' (' . ( '' !== $r['error'] ? $r['error'] : 'HTTP ' . $r['status_code'] ) . ')';
				}
			}
			wp_send_json_error(
				array(
					'message' => sprintf(
					/* translators: %s: list of failed URLs */
						__( 'Test webhook failed for: %s', 'gatewaykit' ),
						implode( ', ', $failed )
					),
					'results' => $results,
				)
			);
		}
		// phpcs:enable
	}
}
