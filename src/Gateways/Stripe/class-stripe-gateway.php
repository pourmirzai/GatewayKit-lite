<?php
/**
 * Stripe Payment Gateway (Pro)
 *
 * Implements Stripe Checkout (hosted redirect page). Stripe handles SCA/3DS
 * automatically. Secrets are encrypted at rest with GatewayKit_Crypto.
 *
 * Flow: Create Checkout Session -> redirect to Stripe hosted page -> buyer
 * returns to the callback URL (authority = session id) -> verify by retrieving
 * the session. Webhooks (checkout.session.completed / payment_intent.
 * payment_failed) provide async confirmation.
 *
 * @package GatewayKit_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stripe Gateway Class.
 */
class GatewayKit_Stripe_Gateway extends GatewayKit_Abstract_Payment_Gateway {

	/**
	 * Live API base URL.
	 */
	const LIVE_BASE = 'https://api.stripe.com/v1';

	/**
	 * Zero-decimal currencies (no minor units) per Stripe's spec.
	 *
	 * @var array
	 */
	private static $zero_decimal = array(
		'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF',
		'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
	);

	/**
	 * Get gateway ID.
	 *
	 * @return string
	 */
	protected function get_gateway_id() {
		return 'stripe';
	}

	/**
	 * Gateway display name.
	 *
	 * @return string
	 */
	public function get_gateway_name() {
		return __( 'Stripe', 'gatewaykit' );
	}

	/**
	 * Currencies supported by Stripe (subset of PayPal list that Stripe
	 * also supports). This keeps the Pro compatibility intersection
	 * accurate — any currency PayPal lists but Stripe does not accept
	 * will be filtered out.
	 *
	 * @return string[]
	 */
	public function get_supported_currencies() {
		return array(
			'USD', 'EUR', 'GBP', 'AUD', 'BRL', 'CAD', 'CNY', 'CZK', 'DKK',
			'HKD', 'HUF', 'INR', 'JPY', 'MYR', 'MXN', 'NZD', 'NOK', 'PHP',
			'PLN', 'SGD', 'SEK', 'CHF', 'THB',
		);
	}

	/**
	 * Check if Pro license is active.
	 *
	 * @return bool
	 */
	private function is_pro_licensed() {
		return function_exists( 'gatewaykit_pro_is_licensed' ) && gatewaykit_pro_is_licensed();
	}

	/**
	 * Required settings (credentials).
	 *
	 * Only the secret key is strictly required to run a Stripe Checkout
	 * (hosted) flow: the Checkout Session is created server-side with the
	 * secret key and the buyer is redirected to Stripe's hosted page, so the
	 * publishable key is never consumed by this integration. Keeping it
	 * optional lets merchants who only generated a restricted key (rk_) —
	 * Stripe's recommended server-side key — go live without an extra field.
	 * Webhook secret stays optional for payment processing but is required
	 * to verify inbound webhooks.
	 *
	 * @return array
	 */
	protected function get_required_settings() {
		return array( 'secret_key' );
	}

	/**
	 * Admin UI settings fields.
	 *
	 * @return array
	 */
	public function get_settings_fields() {
		$fields = array(
			'sandbox_mode' => array(
				'type'        => 'checkbox',
				'label'       => __( 'Test Mode', 'gatewaykit' ),
				'description' => __( 'Use Stripe sandbox (test) keys. Check this when using rk_test_ / sk_test_ keys.', 'gatewaykit' ),
			),
			'publishable_key' => array(
				'type'        => 'text',
				'label'       => __( 'Publishable Key (optional)', 'gatewaykit' ),
				'description' => __( 'Optional. Not used by the hosted Checkout flow. Paste pk_live_... or pk_test_... only if you need it elsewhere.', 'gatewaykit' ),
			),
			'secret_key' => array(
				'type'        => 'password',
				'label'       => __( 'Secret Key', 'gatewaykit' ),
				'description' => __( 'Your server-side key: a restricted key (rk_live_... / rk_test_ — recommended) or a secret key (sk_live_... / sk_test_...). Stored encrypted.', 'gatewaykit' ),
			),
			'webhook_secret' => array(
				'type'        => 'password',
				'label'       => __( 'Webhook Signing Secret', 'gatewaykit' ),
				'description' => __( 'The signing secret for your Stripe webhook endpoint (whsec_...). Required to verify webhooks.', 'gatewaykit' ),
			),
			'checkout_mode' => array(
				'type'        => 'select',
				'label'       => __( 'Checkout Display Mode', 'gatewaykit' ),
				'options'     => array(
					'hosted'   => __( 'Hosted Page (redirect to Stripe)', 'gatewaykit' ),
					'embedded' => __( 'Embedded (no redirect, form on your site)', 'gatewaykit' ),
				),
				'default'     => 'hosted',
				'description' => __( 'Embedded mode keeps customers on your site for a smoother experience. Requires Publishable Key. Hosted mode redirects to Stripe’s optimized checkout page.', 'gatewaykit' ),
			),
			'enable_apple_pay' => array(
				'type'        => 'checkbox',
				'label'       => __( 'Apple Pay', 'gatewaykit' ),
				'description' => __( 'Show Apple Pay button on checkout. Requires domain verification in Stripe Dashboard → Settings → Payment Methods → Apple Pay.', 'gatewaykit' ),
			),
			'enable_google_pay' => array(
				'type'        => 'checkbox',
				'label'       => __( 'Google Pay', 'gatewaykit' ),
				'description' => __( 'Show Google Pay button on checkout. Requires domain verification in Stripe Dashboard.', 'gatewaykit' ),
			),
			'enable_klarna' => array(
				'type'        => 'checkbox',
				'label'       => __( 'Klarna (Buy Now Pay Later)', 'gatewaykit' ),
				'description' => __( 'Let customers pay in installments with Klarna. Availability depends on your Stripe account region.', 'gatewaykit' ),
			),
			'enable_afterpay' => array(
				'type'        => 'checkbox',
				'label'       => __( 'Afterpay / Clearpay (Buy Now Pay Later)', 'gatewaykit' ),
				'description' => __( 'Let customers pay in 4 installments. Availability depends on your Stripe account region.', 'gatewaykit' ),
			),
		);

		// Mark subscription-related settings as Pro features (handled via Elementor form fields, not gateway settings).
		// The subscription_price_id is passed via user_data from the Elementor form.
		// No Pro fields in gateway settings currently.

		return $fields;
	}

	/**
	 * Gateway metadata for the admin UI.
	 *
	 * @return array
	 */
	public function get_gateway_info() {
		return array(
			'description' => __( 'Accept credit/debit cards, Apple Pay, Google Pay, Klarna, and Afterpay / Clearpay via Stripe Checkout (hosted page). 3D Secure handled automatically. <strong>Restricted keys (rk_test_/rk_live_) with "Checkout Sessions" read/write permissions are recommended for security.</strong>', 'gatewaykit' ),
		);
	}

	/**
	 * Decrypt secrets after loading settings.
	 */
	protected function load_settings() {
		parent::load_settings();
		foreach ( array( 'secret_key', 'webhook_secret' ) as $key ) {
			if ( ! empty( $this->settings[ $key ] ) ) {
				$this->settings[ $key ] = GatewayKit_Crypto::decrypt( $this->settings[ $key ] );
			}
		}
	}

	/**
	 * Encrypt secrets before persisting settings.
	 *
	 * @param array $settings Raw settings input.
	 * @return array|WP_Error
	 */
	public function validate_settings_input( $settings ) {
		$validated = parent::validate_settings_input( $settings );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		foreach ( array( 'secret_key', 'webhook_secret' ) as $key ) {
			if ( isset( $validated[ $key ] ) && '' !== $validated[ $key ] ) {
				$validated[ $key ] = GatewayKit_Crypto::encrypt( $validated[ $key ] );
			}
		}

		// Embedded Checkout renders the session client-side via Stripe.js, so
		// the publishable key is required for that mode.
		$checkout_mode = isset( $validated['checkout_mode'] ) ? $validated['checkout_mode'] : 'hosted';
		$publishable   = isset( $validated['publishable_key'] ) ? $validated['publishable_key'] : '';
		if ( 'embedded' === $checkout_mode && '' === $publishable ) {
			return new WP_Error( 'stripe_embedded_requires_publishable_key', __( 'Publishable Key is required for Embedded Checkout mode.', 'gatewaykit' ) );
		}

		return $validated;
	}

	/**
	 * Format amount into Stripe's minor units for the given currency.
	 *
	 * Stripe expects integer minor units for most currencies (e.g. cents) and
	 * the whole amount for zero-decimal currencies (e.g. JPY). When no
	 * currency is passed, the transaction currency is resolved from settings.
	 *
	 * @param float  $amount   Amount.
	 * @param string $currency ISO 4217 currency code (optional, resolved from settings when empty).
	 * @return int Minor units.
	 */
	protected function format_amount( $amount, $currency = '' ) {
		if ( '' === $currency ) {
			$currency = $this->get_currency();
		}
		$currency = strtoupper( $currency );

		if ( in_array( $currency, self::$zero_decimal, true ) ) {
			return (int) round( (float) $amount );
		}

		return (int) round( (float) $amount * 100 );
	}

	/**
	 * Get the Stripe secret key (decrypted).
	 *
	 * @return string
	 */
	private function get_secret_key() {
		return $this->get_setting( 'secret_key', '' );
	}

	/**
	 * Test the Stripe credentials with a lightweight authenticated call.
	 *
	 * Validates the key prefix against the test/live mode toggle, then makes
	 * a minimal authenticated GET to the Checkout Sessions endpoint to prove
	 * the key authenticates. Designed to run BEFORE the gateway is enabled so
	 * merchants can verify their key (rk_/sk_) without going live.
	 *
	 * Result codes:
	 *   - 200: key authentic AND has Checkout Sessions read access.
	 *   - 401: invalid / revoked / wrong-type key.
	 *   - 403: key authentic but missing permission (still a valid key).
	 *
	 * @param array $overrides Raw settings from the admin form (unsaved).
	 *                         Keys: sandbox_mode, secret_key.
	 * @return array { 'success' => bool, 'message' => string }
	 */
	public function test_connection( $overrides = array() ) {
		// Resolve the secret key from overrides (unsaved form input) first,
		// then fall back to the saved (decrypted) setting.
		$secret = '';
		if ( isset( $overrides['secret_key'] ) && '' !== $overrides['secret_key'] ) {
			$secret = is_string( $overrides['secret_key'] ) ? trim( $overrides['secret_key'] ) : '';
		}
		if ( '' === $secret ) {
			$secret = $this->get_secret_key();
		}

		if ( '' === $secret ) {
			return array(
				'success' => false,
				'message' => __( 'Secret key is not configured. Enter your restricted key (rk_test_/rk_live_) or secret key (sk_test_/sk_live_).', 'gatewaykit' ),
			);
		}

		// Resolve the effective sandbox mode (override takes precedence).
		$sandbox = isset( $overrides['sandbox_mode'] ) ? ( '1' === (string) $overrides['sandbox_mode'] ) : $this->is_sandbox();

		// Prefix validation against the documented key types.
		$is_test = ( 0 === strpos( $secret, 'sk_test_' ) || 0 === strpos( $secret, 'rk_test_' ) );
		$is_live = ( 0 === strpos( $secret, 'sk_live_' ) || 0 === strpos( $secret, 'rk_live_' ) );

		if ( ! $is_test && ! $is_live ) {
			return array(
				'success' => false,
				'message' => __( 'Secret key format not recognized. It must start with sk_test_, rk_test_, sk_live_, or rk_live_.', 'gatewaykit' ),
			);
		}

		if ( $sandbox && ! $is_test ) {
			return array(
				'success' => false,
				'message' => __( 'Test Mode is ON but the key looks like a LIVE key (sk_live_/rk_live_). Either turn Test Mode off or use a test key.', 'gatewaykit' ),
			);
		}
		if ( ! $sandbox && ! $is_live ) {
			return array(
				'success' => false,
				'message' => __( 'Test Mode is OFF but the key is a TEST key (sk_test_/rk_test_). Enable Test Mode to test, or use a live key.', 'gatewaykit' ),
			);
		}

		// Lightweight authenticated read. GatewayKit needs Checkout Sessions
		// access anyway, and Write includes Read per Stripe's permission model.
		$url  = self::LIVE_BASE . '/checkout/sessions?limit=1';
		$resp = wp_remote_get(
			$url,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $secret,
				),
				'timeout' => 20,
			)
		);

		if ( is_wp_error( $resp ) ) {
			$this->log( 'error', 'Stripe test connection: request failed', array( 'message' => $resp->get_error_message() ) );
			return array(
				'success' => false,
				'message' => __( 'Could not reach Stripe. Check your server connection/cURL and try again.', 'gatewaykit' ),
			);
		}

		$code    = (int) wp_remote_retrieve_response_code( $resp );
		$body    = wp_remote_retrieve_body( $resp );
		$decoded = json_decode( $body, true );

		if ( 200 === $code ) {
			$mode = $is_test ? __( 'test', 'gatewaykit' ) : __( 'live', 'gatewaykit' );
			$this->log( 'info', 'Stripe test connection succeeded', array( 'mode' => $is_test ? 'test' : 'live' ) );
			return array(
				'success' => true,
				/* translators: %s: test or live */
				'message' => sprintf( __( 'Connected successfully. Your %s-mode key is valid and Checkout Sessions access is confirmed.', 'gatewaykit' ), $mode ),
			);
		}

		// Extract Stripe's error message when available.
		$detail = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : '';
		$etype  = isset( $decoded['error']['type'] ) ? $decoded['error']['type'] : '';

		if ( 401 === $code || 'invalid_request_error' === $etype || false !== strpos( $detail, 'Invalid API Key' ) ) {
			$this->log( 'error', 'Stripe test connection: invalid key', array( 'status' => $code, 'body' => $decoded ) );
			return array(
				'success' => false,
				'message' => __( 'Stripe rejected the key (unauthorized). The key is invalid, revoked, or the wrong type. Generate a new restricted key (rk_) or secret key (sk_) in the Stripe Dashboard.', 'gatewaykit' ),
			);
		}

		if ( 403 === $code ) {
			// Authentication succeeded; the key just lacks this permission.
			$this->log( 'warning', 'Stripe test connection: permission denied', array( 'status' => $code, 'body' => $decoded ) );
			return array(
				'success' => true,
				'message' => __( 'The key authenticated, but it lacks read access to Checkout Sessions. Grant Checkout Sessions permission to this restricted key in the Stripe Dashboard, or payments may fail.', 'gatewaykit' ),
			);
		}

		$this->log( 'error', 'Stripe test connection: unexpected status', array( 'status' => $code, 'body' => $decoded ) );
		/* translators: %d: HTTP status code */
		$msg = $detail ? $detail : sprintf( __( 'Stripe returned an unexpected response (HTTP %d).', 'gatewaykit' ), $code );
		return array(
			'success' => false,
			'message' => $msg,
		);
	}

	/**
	 * Perform an authenticated Stripe API call (form-encoded).
	 *
	 * @param string $path   API path (e.g. /checkout/sessions).
	 * @param array  $params Request parameters.
	 * @param string $method HTTP method.
	 * @return array|WP_Error Decoded response or error.
	 */
	private function api_request( $path, $params = array(), $method = 'POST' ) {
		$secret = $this->get_secret_key();
		if ( '' === $secret ) {
			return new WP_Error( 'stripe_missing_secret', __( 'Stripe secret key is not configured.', 'gatewaykit' ) );
		}

		$url  = self::LIVE_BASE . $path;
		$args = array(
			'method'  => $method,
			'headers' => array(
				'Authorization' => 'Bearer ' . $secret,
			),
			'timeout' => 30,
		);

		if ( ! empty( $params ) ) {
			$args['body'] = $params; // wp_remote_post form-encodes arrays automatically.
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			$this->log( 'error', 'Stripe request failed: ' . $response->get_error_message(), array( 'path' => $path ) );
			return new WP_Error( 'stripe_request_failed', __( 'Could not connect to Stripe. Please try again later.', 'gatewaykit' ) );
		}

		$code     = wp_remote_retrieve_response_code( $response );
		$decoded  = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$message = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : __( 'Stripe request failed.', 'gatewaykit' );
			$this->log( 'error', 'Stripe API error', array( 'path' => $path, 'status' => $code, 'body' => $decoded ) );
			return new WP_Error( 'stripe_api_error', $message );
		}

		return $decoded;
	}

	/**
	 * Create a reusable Stripe Payment Link for a fixed amount.
	 *
	 * The link is hosted on Stripe's domain and lets merchants collect payment
	 * without a form (invoices, WhatsApp/Telegram sales, email links). Payments
	 * made through the link go directly to the merchant's Stripe account and
	 * are NOT tracked by GatewayKit (they bypass the Elementor form flow).
	 *
	 * @param float  $amount      Payment amount in major units.
	 * @param string $currency    ISO 4217 currency code (e.g. USD).
	 * @param string $name        Product or service name.
	 * @param string $description Optional description.
	 * @return array|WP_Error Decoded Stripe response or error.
	 */
	public function create_payment_link( $amount, $currency, $name, $description = '' ) {
		$currency  = strtoupper( $currency );
		$formatted = $this->format_amount( $amount, $currency );

		if ( $formatted <= 0 ) {
			return new WP_Error( 'stripe_invalid_amount', __( 'Payment amount must be greater than zero.', 'gatewaykit' ) );
		}

		$product_data = array(
			'name' => mb_substr( (string) $name, 0, 200 ),
		);

		if ( '' !== $description ) {
			$product_data['description'] = mb_substr( (string) $description, 0, 2000 );
		}

		$params = array(
			'line_items' => array(
				array(
					'quantity'   => 1,
					'price_data' => array(
						'currency'     => strtolower( $currency ),
						'unit_amount'  => $formatted,
						'product_data' => $product_data,
					),
				),
			),
		);

		$response = $this->api_request( '/payment_links', $params, 'POST' );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$this->log( 'info', 'Stripe payment link created', array( 'amount' => $formatted, 'currency' => $currency ) );

		return $response;
	}

	/**
	 * Create a Stripe Checkout Session.
	 *
	 * @param float  $amount       Payment amount.
	 * @param string $description  Payment description.
	 * @param string $callback_url Callback/return URL.
	 * @param array  $user_data    User data array.
	 * @return array Payment result.
	 */
	public function process_payment( $amount, $description, $callback_url, $user_data ) {
		$formatted = $this->format_amount( $amount );

		if ( $formatted <= 0 ) {
			return array(
				'status'        => 'error',
				'error_type'    => 'invalid_amount',
				'error_message' => __( 'Payment amount must be greater than zero.', 'gatewaykit' ),
			);
		}

		$currency   = $this->get_currency();
		// Recompute formatted amount now that currency may have changed.
		$formatted  = $this->format_amount( $amount );

		// Embedded mode keeps the buyer on the site, so the {CHECKOUT_SESSION_ID}
		// template is not supported. The frontend redirects via JS after the
		// embedded checkout completes, using the session id returned in the API
		// response. Hosted mode relies on Stripe replacing the placeholder.
		$is_embedded = ( 'embedded' === $this->get_setting( 'checkout_mode', 'hosted' ) );
		if ( $is_embedded ) {
			$success_url = $callback_url;
		} else {
			$success_url = add_query_arg( array( 'authority' => '{CHECKOUT_SESSION_ID}' ), $callback_url );
		}

		// Carry the transaction receipt token on cancel so the callback handler
		// can resolve the order and route to the configured failure URL.
		$cancel_args = array( 'gatewaykit_cancel' => '1' );
		if ( ! empty( $user_data['receipt_token'] ) ) {
			$cancel_args['token'] = sanitize_text_field( $user_data['receipt_token'] );
		}
		$cancel_url = add_query_arg( $cancel_args, $callback_url );

		// Subscription mode: when a Stripe Price ID is provided, create a
		// subscription Checkout Session instead of a one-time payment.
		// Requires Pro license.
		$subscription_price_id = isset( $user_data['subscription_price_id'] ) ? sanitize_text_field( $user_data['subscription_price_id'] ) : '';
		$is_subscription      = $this->is_pro_licensed() && '' !== $subscription_price_id && preg_match( '/^price_[A-Za-z0-9]+$/', $subscription_price_id );

		if ( $is_subscription ) {
			$params = array(
				'mode'         => 'subscription',
				'success_url'  => $success_url,
				'cancel_url'   => $cancel_url,
				'line_items'   => array(
					array(
						'price'    => $subscription_price_id,
						'quantity' => 1,
					),
				),
			);

			if ( ! empty( $user_data['customer_email'] ) ) {
				$params['customer_email'] = sanitize_email( $user_data['customer_email'] );
			}
		} else {
			$params = array(
				'mode'         => 'payment',
				'success_url'  => $success_url,
				'cancel_url'   => $cancel_url,
				'line_items'   => array(
					array(
						'quantity'   => 1,
						'price_data' => array(
							'currency'     => strtolower( $currency ),
							'unit_amount'  => $formatted,
							'product_data' => array(
								'name' => $description ? mb_substr( (string) $description, 0, 200 ) : __( 'Payment', 'gatewaykit' ),
							),
						),
					),
				),
			);
		}

		// Embedded Checkout renders the Checkout Session in an iframe on the
		// site, so Stripe requires ui_mode=embedded and does not return a hosted
		// redirect URL — instead the response carries a client_secret.
		if ( $is_embedded ) {
			$params['ui_mode'] = 'embedded';
		}

		// Build the payment_method_types array for one-time payments when any
		// enhanced method (Apple Pay / Google Pay / Klarna / Afterpay) is
		// enabled. Defaults to Stripe Dashboard defaults when none are checked
		// (backward compatible). BNPL / wallet methods are not supported for
		// subscriptions, so only the card type applies there.
		if ( ! $is_subscription ) {
			$payment_method_types = array( 'card' );

			$enhanced_methods = array(
				'enable_apple_pay'  => 'apple_pay',
				'enable_google_pay' => 'google_pay',
				'enable_klarna'     => 'klarna',
				'enable_afterpay'   => 'afterpay_clearpay',
			);

			foreach ( $enhanced_methods as $setting_key => $method_type ) {
				if ( '1' === (string) $this->get_setting( $setting_key, '' ) ) {
					$payment_method_types[] = $method_type;
				}
			}

			// Only send the param when at least one non-card method is enabled,
			// so existing installs without these settings keep using Stripe
			// Dashboard defaults.
			if ( count( $payment_method_types ) > 1 ) {
				$params['payment_method_types'] = $payment_method_types;
			}
		}

		$response = $this->api_request( '/checkout/sessions', $params, 'POST' );

		if ( is_wp_error( $response ) ) {
			return array(
				'status'        => 'error',
				'error_type'    => 'gateway',
				'error_message' => $response->get_error_message(),
			);
		}

		$session_id    = isset( $response['id'] ) ? $response['id'] : '';
		$redirect_url  = isset( $response['url'] ) ? $response['url'] : '';
		$client_secret = isset( $response['client_secret'] ) ? $response['client_secret'] : '';

		if ( '' === $session_id ) {
			return array(
				'status'        => 'error',
				'error_type'    => 'gateway',
				'error_message' => __( 'Stripe did not return a checkout session.', 'gatewaykit' ),
			);
		}

		// Hosted sessions always return a redirect URL; embedded sessions
		// return a client_secret instead.
		if ( $is_embedded && '' === $client_secret ) {
			return array(
				'status'        => 'error',
				'error_type'    => 'gateway',
				'error_message' => __( 'Stripe did not return a client secret for the embedded checkout session.', 'gatewaykit' ),
			);
		}
		if ( ! $is_embedded && '' === $redirect_url ) {
			return array(
				'status'        => 'error',
				'error_type'    => 'gateway',
				'error_message' => __( 'Stripe did not return a checkout session.', 'gatewaykit' ),
			);
		}

		// Cache the redirect URL so get_redirect_url() can resolve it.
		if ( '' !== $redirect_url ) {
			set_transient( 'gatewaykit_stripe_redirect_' . $session_id, $redirect_url, DAY_IN_SECONDS );
		}

		// Cache the client secret so get_embedded_client_secret() can resolve it.
		if ( '' !== $client_secret ) {
			set_transient( 'gatewaykit_stripe_embedded_' . $session_id, $client_secret, DAY_IN_SECONDS );
		}

		// Cache subscription mode flag for verify_payment().
		if ( $is_subscription ) {
			set_transient( 'gatewaykit_stripe_sub_' . $session_id, true, WEEK_IN_SECONDS );
		}

		$this->log( 'info', 'Stripe checkout session created', array( 'session_id' => $session_id, 'amount' => $formatted, 'currency' => $currency, 'mode' => $is_embedded ? 'embedded' : 'hosted' ) );

		return array(
			'status'       => 'success',
			'authority'    => $session_id,
			'redirect_url' => $redirect_url,
			'client_secret' => $client_secret,
		);
	}

	/**
	 * Verify a Stripe Checkout Session after the buyer returns.
	 *
	 * @param string $authority Stripe session id.
	 * @param float  $amount    Expected payment amount.
	 * @return array Verification result.
	 */
	public function verify_payment( $authority, $amount = 0 ) {
		$authority = sanitize_text_field( (string) $authority );

		if ( '' === $authority ) {
			return array(
				'status'        => 'failed',
				'error_message' => __( 'Missing Stripe session id.', 'gatewaykit' ),
			);
		}

		$session = $this->api_request( '/checkout/sessions/' . rawurlencode( $authority ), array(), 'GET' );

		if ( is_wp_error( $session ) ) {
			return array(
				'status'        => 'failed',
				'error_message' => $session->get_error_message(),
			);
		}

		$status         = isset( $session['status'] ) ? $session['status'] : '';
		$payment_status = isset( $session['payment_status'] ) ? $session['payment_status'] : '';

		if ( 'complete' !== $status || 'paid' !== $payment_status ) {
			return array(
				'status'        => 'failed',
				'error_message' => sprintf(
					/* translators: %1$s: session status, %2$s: payment status */
					__( 'Stripe payment not completed (session: %1$s, payment: %2$s).', 'gatewaykit' ),
					$status,
					$payment_status
				),
			);
		}

		// Validate amount. Fail closed if the session omits amount_total.
		if ( ! isset( $session['amount_total'] ) ) {
			$this->log( 'error', 'Stripe Checkout session missing amount_total — rejecting payment', array(
				'session_id' => isset( $session['id'] ) ? $session['id'] : 'unknown',
				'authority'  => $authority,
			) );
			return array(
				'status'        => 'failed',
				'error_message' => __( 'Stripe payment amount could not be verified.', 'gatewaykit' ),
			);
		}

		$expected   = $this->format_amount( $amount );
		$paid_total = (int) $session['amount_total'];
		if ( $paid_total > 0 && abs( $paid_total - $expected ) > 0 ) {
			$this->log( 'error', 'Stripe amount mismatch', array( 'expected' => $expected, 'paid' => $paid_total ) );
			return array(
				'status'        => 'failed',
				'error_message' => __( 'Stripe payment amount does not match the order amount.', 'gatewaykit' ),
			);
		}

		$ref_id = isset( $session['payment_intent'] ) ? (string) $session['payment_intent'] : $authority;

		// Subscription mode: ref_id = subscription id.
		$is_subscription = get_transient( 'gatewaykit_stripe_sub_' . $authority );
		if ( $is_subscription && isset( $session['subscription'] ) ) {
			$ref_id = (string) $session['subscription'];
		}

		$this->log( 'info', 'Stripe payment verified', array( 'session_id' => $authority, 'ref_id' => $ref_id ) );

		return array(
			'status' => 'success',
			'ref_id' => $ref_id,
		);
	}

	/**
	 * Resolve the redirect URL for a session.
	 *
	 * @param string $authority Session id.
	 * @return string
	 */
	public function get_redirect_url( $authority ) {
		$authority = sanitize_text_field( (string) $authority );
		$cached    = get_transient( 'gatewaykit_stripe_redirect_' . $authority );
		return $cached ? $cached : '';
	}

	/**
	 * Resolve the embedded checkout client secret for a session.
	 *
	 * @param string $session_id Session id.
	 * @return string
	 */
	public function get_embedded_client_secret( $session_id ) {
		$session_id = sanitize_text_field( (string) $session_id );
		$cached     = get_transient( 'gatewaykit_stripe_embedded_' . $session_id );
		return $cached ? $cached : '';
	}

	/**
	 * Handle an inbound Stripe webhook.
	 *
	 * Verifies the Stripe-Signature header against the webhook secret, then
	 * processes checkout.session.completed (success) and
	 * payment_intent.payment_failed (failure) events.
	 */
	public function handle_webhook() {
		$secret = $this->get_setting( 'webhook_secret', '' );
		$raw    = file_get_contents( 'php://input' );
		$header = isset( $_SERVER['HTTP_STRIPE_SIGNATURE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_STRIPE_SIGNATURE'] ) ) : '';

		if ( '' === $secret || '' === $header ) {
			GatewayKit_Logger::get_instance()->warning( 'Stripe webhook: missing signature or signing secret.' );
			status_header( 400 );
			exit;
		}

		$verified = $this->verify_signature( $header, $raw, $secret );

		if ( ! $verified ) {
			GatewayKit_Logger::get_instance()->warning( 'Stripe webhook: signature verification failed.' );
			status_header( 400 );
			exit;
		}

		$event = json_decode( $raw, true );
		if ( ! is_array( $event ) || empty( $event['type'] ) ) {
			status_header( 400 );
			exit;
		}

		$type    = $event['type'];
		$object  = isset( $event['data']['object'] ) ? $event['data']['object'] : array();
		$session = $this->extract_session( $type, $object );

		// Handle subscription lifecycle events (invoice.paid, customer.subscription.deleted).
		// Requires Pro license.
		if ( $this->is_pro_licensed() && 'invoice.paid' === $type ) {
			$billing_reason = isset( $object['billing_reason'] ) ? $object['billing_reason'] : '';
			$subscription_id = isset( $object['subscription'] ) ? (string) $object['subscription'] : '';

			if ( 'subscription_cycle' === $billing_reason && '' !== $subscription_id ) {
				// Dispatch to subscription service for renewal processing.
				do_action( 'gatewaykit_stripe_subscription_renewed', $subscription_id, $object );
			}

			status_header( 200 );
			exit;
		}

		if ( $this->is_pro_licensed() && 'invoice.payment_failed' === $type ) {
			$subscription_id = isset( $object['subscription'] ) ? (string) $object['subscription'] : '';
			if ( '' !== $subscription_id ) {
				do_action( 'gatewaykit_stripe_subscription_past_due', $subscription_id, $object );
			}

			status_header( 200 );
			exit;
		}

		if ( $this->is_pro_licensed() && 'customer.subscription.deleted' === $type ) {
			$subscription_id = isset( $object['id'] ) ? (string) $object['id'] : '';
			if ( '' !== $subscription_id ) {
				do_action( 'gatewaykit_stripe_subscription_deleted', $subscription_id, $object );
			}

			status_header( 200 );
			exit;
		}

		if ( '' === $session ) {
			GatewayKit_Logger::get_instance()->info( 'Stripe webhook ignored', array( 'event_type' => $type ) );
			status_header( 200 );
			exit;
		}

		$transaction = GatewayKit_Transaction_Model::find_by_authority( $session );

		if ( ! $transaction ) {
			GatewayKit_Logger::get_instance()->warning( 'Stripe webhook: transaction not found', array( 'session' => $session ) );
			status_header( 200 );
			exit;
		}

		if ( in_array( $transaction->status, array( 'pending', 'processing' ), true ) ) {
			$is_success = ( 'checkout.session.completed' === $type ) && isset( $object['payment_status'] ) && 'paid' === $object['payment_status'];

			if ( $is_success ) {
				$transaction->update( array(
					'status'       => 'completed',
					'ref_id'       => isset( $object['payment_intent'] ) ? (string) $object['payment_intent'] : '',
					'completed_at' => current_time( 'mysql' ),
				) );
				do_action( 'gatewaykit_payment_completed', $transaction );
			} else {
				$transaction->update( array( 'status' => 'failed' ) );
				do_action( 'gatewaykit_payment_failed', $transaction, array( 'event' => $type ) );
			}
		}

		status_header( 200 );
		exit;
	}

	/**
	 * Extract the session id for the relevant webhook event types.
	 *
	 * @param string $type   Event type.
	 * @param array  $object Event data object.
	 * @return string Session id or empty string when unsupported.
	 */
	private function extract_session( $type, $object ) {
		if ( 'checkout.session.completed' === $type ) {
			return isset( $object['id'] ) ? (string) $object['id'] : '';
		}

		// payment_intent.payment_failed -> resolve the session that owns it.
		if ( 'payment_intent.payment_failed' === $type ) {
			$pi = isset( $object['id'] ) ? (string) $object['id'] : '';
			if ( '' === $pi ) {
				return '';
			}
			$list = $this->api_request( '/checkout/sessions', array( 'payment_intent' => $pi ), 'GET' );
			if ( is_array( $list ) && isset( $list['data'][0]['id'] ) ) {
				return (string) $list['data'][0]['id'];
			}
			return '';
		}

		return '';
	}

	/**
	 * Verify a Stripe webhook signature.
	 *
	 * @param string $header Stripe-Signature header.
	 * @param string $payload Raw request body.
	 * @param string $secret  Webhook signing secret.
	 * @return bool
	 */
	private function verify_signature( $header, $payload, $secret ) {
		$parts = array();
		foreach ( explode( ',', $header ) as $seg ) {
			$kv = explode( '=', trim( $seg ), 2 );
			if ( 2 === count( $kv ) ) {
				$parts[ $kv[0] ] = $kv[1];
			}
		}

		if ( empty( $parts['t'] ) || empty( $parts['v1'] ) ) {
			return false;
		}

		$timestamp = (int) $parts['t'];
		// Reject significantly old signatures (5 minute tolerance).
		if ( abs( time() - $timestamp ) > 300 ) {
			return false;
		}

		$signed    = $timestamp . '.' . $payload;
		$expected  = hash_hmac( 'sha256', $signed, $secret );

		return hash_equals( $expected, $parts['v1'] );
	}
}
