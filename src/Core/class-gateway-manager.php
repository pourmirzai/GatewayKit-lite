<?php
/**
 * Gateway Manager
 *
 * Manages payment gateways using factory pattern
 *
 * @package GatewayKit
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gateway Manager Class
 */
class GatewayKit_Gateway_Manager {

	/**
	 * Single instance
	 *
	 * @var GatewayKit_Gateway_Manager|null
	 */
	private static $instance = null;

	/**
	 * Registered gateways
	 *
	 * @var array
	 */
	private $gateways = array();

	/**
	 * Gateway instances
	 *
	 * @var array
	 */
	private $gateway_instances = array();

	/**
	 * Track if default gateways are registered
	 *
	 * @var bool
	 */
	private $defaults_registered = false;

	/**
	 * Logger instance
	 *
	 * @var GatewayKit_Logger
	 */
	private $logger;

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
		$this->logger = GatewayKit_Logger::get_instance();
		$this->register_default_gateways();
	}

	/**
	 * Register a gateway
	 *
	 * @param string $gateway_id Gateway ID
	 * @param string $class_name Gateway class name
	 * @return bool True if registered, false if invalid
	 */
	public function register_gateway( $gateway_id, $class_name ) {
		// Skip if already registered
		if ( isset( $this->gateways[ $gateway_id ] ) ) {
			return true;
		}

		if ( ! class_exists( $class_name ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				$this->logger->error(
					'Gateway class not found',
					array(
						'gateway_id' => $gateway_id,
						'class_name' => $class_name,
					)
				);
			}
			return false;
		}

		$this->gateways[ $gateway_id ] = $class_name;

		// Only log during initial registration in debug mode
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$this->logger->debug(
				'Gateway registered',
				array(
					'gateway_id' => $gateway_id,
					'class_name' => $class_name,
				)
			);
		}

		return true;
	}

	/**
	 * Get gateway instance
	 *
	 * @param string $gateway_id Gateway ID
	 * @return GatewayKit_Payment_Gateway_Interface|null Gateway instance or null
	 */
	public function get_gateway( $gateway_id ) {
		if ( ! isset( $this->gateways[ $gateway_id ] ) ) {
			$this->logger->warning( 'Gateway not registered', array( 'gateway_id' => $gateway_id ) );
			return null;
		}

		if ( ! isset( $this->gateway_instances[ $gateway_id ] ) ) {
			$class_name = $this->gateways[ $gateway_id ];

			try {
				$this->gateway_instances[ $gateway_id ] = new $class_name();
			} catch ( Exception $e ) {
				$this->logger->error(
					'Failed to instantiate gateway',
					array(
						'gateway_id' => $gateway_id,
						'class_name' => $class_name,
						'error'      => $e->getMessage(),
					)
				);
				return null;
			}
		}

		return $this->gateway_instances[ $gateway_id ];
	}

	/**
	 * Get all registered gateways
	 *
	 * @return array Registered gateways
	 */
	public function get_registered_gateways() {
		return $this->gateways;
	}

	/**
	 * Get available gateways (registered, available, and enabled)
	 *
	 * @return array Available gateways
	 */
	public function get_available_gateways() {
		// Check cache first
		$cache_key = 'gatewaykit_available_gateways';
		$cache_ttl = 300; // 5 minutes cache
		
		$cached_gateways = get_transient($cache_key);
		if ($cached_gateways !== false) {
			if (defined('WP_DEBUG') && WP_DEBUG) {
				$this->logger->debug('Available gateways loaded from cache');
			}
			return $cached_gateways;
		}

		$available = array();
		$enabled_gateways = get_option('gatewaykit_enabled_gateways', array());

		foreach ($this->gateways as $gateway_id => $class_name) {
			// Only include enabled gateways
			if (!isset($enabled_gateways[$gateway_id]) || $enabled_gateways[$gateway_id] !== '1') {
				continue;
			}

			$gateway = $this->get_gateway($gateway_id);

			if ($gateway && $gateway->is_available()) {
				$available[$gateway_id] = $gateway;
			}
		}

		// Cache the result
		set_transient($cache_key, $available, $cache_ttl);
		
		if (defined('WP_DEBUG') && WP_DEBUG) {
			$this->logger->debug('Available gateways cached for 5 minutes');
		}

		return $available;
	}

	/**
	 * Check if gateway is available
	 *
	 * @param string $gateway_id Gateway ID
	 * @return bool True if available
	 */
	public function is_gateway_available( $gateway_id ) {
		$gateway = $this->get_gateway( $gateway_id );
		return $gateway && $gateway->is_available();
	}

	/**
	 * Get gateway settings
	 *
	 * @param string $gateway_id Gateway ID
	 * @return array Gateway settings
	 */
	public function get_gateway_settings( $gateway_id ) {
		$gateway = $this->get_gateway( $gateway_id );

		if ( $gateway ) {
			return $gateway->get_settings();
		}

		return array();
	}

	/**
	 * Update gateway settings
	 *
	 * @param string $gateway_id Gateway ID
	 * @param array  $settings   Gateway settings
	 * @return bool True on success
	 */
	public function update_gateway_settings( $gateway_id, $settings ) {
		if ( ! isset( $this->gateways[ $gateway_id ] ) ) {
			return false;
		}

		$option_key = 'gatewaykit_' . $gateway_id . '_settings';

		// Validate settings
		$gateway = $this->get_gateway( $gateway_id );
		if ( $gateway && method_exists( $gateway, 'validate_settings_input' ) ) {
			$validated_settings = $gateway->validate_settings_input( $settings );
			if ( is_wp_error( $validated_settings ) ) {
				$error_message = $validated_settings->get_error_message();
				$this->logger->error(
					'Gateway settings validation failed',
					array(
						'gateway_id' => $gateway_id,
						'error_message' => $error_message,
					)
				);
				// Return false to indicate validation failure
				return false;
			}
			$settings = $validated_settings;
		}

		$updated = update_option( $option_key, $settings );

		if ( $updated ) {
			// Clear gateway instance cache to reload settings
			unset( $this->gateway_instances[ $gateway_id ] );

			// PayPal: invalidate cached OAuth access token so the next request
			// fetches a fresh token under the (possibly new) credentials.
			if ( 'paypal' === $gateway_id && class_exists( 'GatewayKit_PayPal_Gateway' ) ) {
				GatewayKit_PayPal_Gateway::invalidate_token_cache();
			}

			/**
			 * Fires after gateway settings have been persisted.
			 *
			 * @param string $gateway_id Gateway identifier.
			 * @param array  $settings   Sanitized + validated settings array.
			 */
			do_action( 'gatewaykit_gateway_settings_updated', $gateway_id, $settings );

			$this->logger->info( 'Gateway settings updated', array( 'gateway_id' => $gateway_id ) );
		}

		return $updated;
	}

	/**
	 * Clear enabled gateways cache
	 */
	private function clear_enabled_gateways_cache() {
		// Clear WordPress object cache for the enabled gateways option
		wp_cache_delete( 'gatewaykit_enabled_gateways', 'options' );
	}

	/**
	 * Register default gateways
	 */
	private function register_default_gateways() {
		// No gateways are bundled with the shared core. Each version registers
		// its own gateways (PayPal in Lite; Stripe/Mollie/CoinGate in Pro) by
		// hooking into this action.
		do_action( 'gatewaykit_register_gateways', $this );
	}

	/**
	 * Get gateway performance stats
	 *
	 * @param string $gateway_id Gateway ID
	 * @return array Performance stats
	 */
	public function get_gateway_stats( $gateway_id ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'gatewaykit_payment_transactions';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin-only stats; called infrequently
		$stats = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
                    COUNT(*) as total_transactions,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as successful_transactions,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_transactions,
                    AVG(amount) as avg_amount,
                    SUM(amount) as total_amount
                FROM %i
                WHERE gateway = %s AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
				$table_name,
				$gateway_id
			),
			ARRAY_A
		);
		// phpcs:enable

		if ( $stats ) {
			$stats['success_rate'] = $stats['total_transactions'] > 0
				? ( $stats['successful_transactions'] / $stats['total_transactions'] ) * 100
				: 0;
		}

		return $stats ?: array();
	}

	/**
	 * Get the base PayPal-supported currencies (ISO 4217).
	 *
	 * Source: PayPal REST API v2 documentation â€” currencies supported for
	 * transactional use. This list is the foundation for Lite and Pro
	 * builds; Pro intersects it with each gateway's own supported list.
	 *
	 * @return array Associative array: code => translatable label.
	 */
	public static function get_paypal_currencies() {
		return array(
			'USD' => __( 'US Dollar (USD)', 'gatewaykit' ),
			'EUR' => __( 'Euro (EUR)', 'gatewaykit' ),
			'GBP' => __( 'British Pound (GBP)', 'gatewaykit' ),
			'AUD' => __( 'Australian Dollar (AUD)', 'gatewaykit' ),
			'BRL' => __( 'Brazilian Real (BRL)', 'gatewaykit' ),
			'CAD' => __( 'Canadian Dollar (CAD)', 'gatewaykit' ),
			'CNY' => __( 'Chinese Yuan (CNY)', 'gatewaykit' ),
			'CZK' => __( 'Czech Koruna (CZK)', 'gatewaykit' ),
			'DKK' => __( 'Danish Krone (DKK)', 'gatewaykit' ),
			'HKD' => __( 'Hong Kong Dollar (HKD)', 'gatewaykit' ),
			'HUF' => __( 'Hungarian Forint (HUF)', 'gatewaykit' ),
			'ILS' => __( 'Israeli Shekel (ILS)', 'gatewaykit' ),
			'INR' => __( 'Indian Rupee (INR)', 'gatewaykit' ),
			'JPY' => __( 'Japanese Yen (JPY)', 'gatewaykit' ),
			'MYR' => __( 'Malaysian Ringgit (MYR)', 'gatewaykit' ),
			'MXN' => __( 'Mexican Peso (MXN)', 'gatewaykit' ),
			'TWD' => __( 'Taiwan Dollar (TWD)', 'gatewaykit' ),
			'NZD' => __( 'New Zealand Dollar (NZD)', 'gatewaykit' ),
			'NOK' => __( 'Norwegian Krone (NOK)', 'gatewaykit' ),
			'PHP' => __( 'Philippine Peso (PHP)', 'gatewaykit' ),
			'PLN' => __( 'Polish Zloty (PLN)', 'gatewaykit' ),
			'RUB' => __( 'Russian Ruble (RUB)', 'gatewaykit' ),
			'SGD' => __( 'Singapore Dollar (SGD)', 'gatewaykit' ),
			'SEK' => __( 'Swedish Krona (SEK)', 'gatewaykit' ),
			'CHF' => __( 'Swiss Franc (CHF)', 'gatewaykit' ),
			'THB' => __( 'Thai Baht (THB)', 'gatewaykit' ),
		);
	}

	/**
	 * Get the list of currencies selectable in the admin settings.
	 *
	 * - Lite build: full PayPal currency list.
	 * - Pro build: PayPal currency list intersected with every registered
	 *   gateway's get_supported_currencies() so only currencies accepted by
	 *   ALL active gateways remain.
	 *
	 * @return array Associative array: code => label.
	 */
	public function get_available_currencies() {
		// Lite + Pro â€” start from the PayPal currency list.
		$currencies = self::get_paypal_currencies();

		// Pro: intersect with each gateway's supported currencies.
		if ( defined( 'GATEWAYKIT_PRO_VERSION' ) ) {
			foreach ( $this->gateways as $gateway_id => $class_name ) {
				$gateway = $this->get_gateway( $gateway_id );
				if ( ! $gateway ) {
					continue;
				}
				$supported = $gateway->get_supported_currencies();
				if ( ! empty( $supported ) ) {
					$currencies = array_intersect_key( $currencies, array_flip( $supported ) );
				}
			}
		}

		return $currencies;
	}

	/**
	 * Get the default currency code for the current build.
	 *
	 * @return string ISO 4217 code.
	 */
	public function get_default_currency() {
		return 'USD';
	}

	/**
	 * Get all gateway stats (only for enabled gateways)
	 *
	 * @return array Gateway stats
	 */
	public function get_all_gateway_stats() {
		// Check cache first
		$cache_key = 'gatewaykit_gateway_stats';
		$cache_ttl = 300; // 5 minutes cache
		
		$cached_stats = get_transient($cache_key);
		if ($cached_stats !== false) {
			if (defined('WP_DEBUG') && WP_DEBUG) {
				$this->logger->debug('Gateway stats loaded from cache');
			}
			return $cached_stats;
		}

		$stats = array();
		$available_gateways = $this->get_available_gateways();

		foreach ($available_gateways as $gateway_id => $gateway) {
			$stats[$gateway_id] = $this->get_gateway_stats($gateway_id);
			$stats[$gateway_id]['name'] = $gateway->get_gateway_name();
		}

		// Cache the result
		set_transient($cache_key, $stats, $cache_ttl);
		
		if (defined('WP_DEBUG') && WP_DEBUG) {
			$this->logger->debug('Gateway stats cached for 10 minutes');
		}

		return $stats;
	}

	/**
	 * Test gateway connection.
	 *
	 * Delegates to the gateway's own test_connection() when it implements a
	 * real connectivity probe (e.g. Stripe), otherwise falls back to the
	 * generic availability check. Works WITHOUT requiring the gateway to be
	 * enabled, and accepts raw $overrides (unsaved form input) so merchants
	 * can verify credentials before going live.
	 *
	 * @param string $gateway_id Gateway ID.
	 * @param array  $overrides  Raw settings from the admin form (unsaved).
	 * @return array { 'success' => bool, 'message' => string }
	 */
	public function test_gateway( $gateway_id, $overrides = array() ) {
		$gateway = $this->get_gateway( $gateway_id );

		if ( ! $gateway ) {
			return array(
				'success' => false,
				'message' => __( 'Gateway not found.', 'gatewaykit' ),
			);
		}

		// Gateway-specific real connectivity probe (e.g. Stripe test_connection).
		if ( method_exists( $gateway, 'test_connection' ) ) {
			return $gateway->test_connection( is_array( $overrides ) ? $overrides : array() );
		}

		// Generic fallback: credentials presence/availability check.
		if ( ! $gateway->is_available() ) {
			return array(
				'success' => false,
				'message' => __( 'Gateway is not properly configured. Please complete the required settings.', 'gatewaykit' ),
			);
		}

		return array(
			'success' => true,
			'message' => __( 'Gateway is properly configured.', 'gatewaykit' ),
		);
	}
}
