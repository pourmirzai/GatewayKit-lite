<?php
/**
 * GatewayKit Autoloader
 *
 * Resolves `gatewaykit_*` classes to files in the modular `src/` tree.
 *
 * Strategy:
 *  1. Core classes (always-present infrastructure) use an explicit map into
 *     `src/Core/`. These are stable, hot-path classes that every build loads.
 *  2. Gateway and Feature classes are NOT mapped here. Each gateway/feature
 *     ships a `module.php` that `require_once`s its own class file(s) and is
 *     discovered automatically by the plugin loader. This means dropping a new
 *     `src/Gateways/NewGateway/` folder never requires editing Core — the
 *     architectural goal of the single-branch monorepo.
 *
 * @package GatewayKit
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Autoloader class.
 */
class GatewayKit_Autoloader {

	/**
	 * Single instance.
	 *
	 * @var GatewayKit_Autoloader|null
	 */
	private static $instance = null;

	/**
	 * Base source directory (`src/`).
	 *
	 * @var string
	 */
	private $src_path = '';

	/**
	 * Explicit map of Core class names (without the `gatewaykit_` prefix) to
	 * paths relative to `src/`.
	 *
	 * @var array<string,string>
	 */
	private $core_map = array(
		'Abstract_Payment_Gateway'  => 'Core/Abstracts/abstract-payment-gateway.php',
		'Payment_Gateway_Interface' => 'Core/Abstracts/interface-payment-gateway.php',
		'Admin_Settings'            => 'Core/Admin/class-admin-settings.php',
		'Analytics_Dashboard'       => 'Core/Admin/class-analytics-dashboard.php',
		'Transaction_List_Table'    => 'Core/Admin/class-transaction-list-table.php',
		'Log_List_Table'            => 'Core/Admin/class-log-list-table.php',
		'Dashboard_Widgets'         => 'Core/Admin/class-dashboard-widgets.php',
		'Payment_Links'             => 'Core/Admin/class-payment-links.php',
		'Gateway_Manager'           => 'Core/class-gateway-manager.php',
		'Plugin'                    => 'Core/class-plugin.php',
		'Payment_Result_Shortcode'  => 'Core/class-payment-result-shortcode.php',
		'Elementor_Action'          => 'Core/Elementor/class-elementor-action.php',
		'Transaction_Model'         => 'Core/Database/class-transaction-model.php',
		'Database_Manager'          => 'Core/Database/class-database-manager.php',
		'Security_Manager'          => 'Core/Security/class-security-manager.php',
		'Input_Validator'           => 'Core/Security/class-input-validator.php',
		'Rate_Limiter'              => 'Core/Security/class-rate-limiter.php',
		'Logger'                    => 'Core/Utils/class-logger.php',
		'Log_Formatter'             => 'Core/Utils/class-log-formatter.php',
		'IP_Helper'                 => 'Core/Utils/class-ip-helper.php',
		'Error_Handler'             => 'Core/Utils/class-error-handler.php',
		'Crypto'                    => 'Core/Utils/class-crypto.php',
		'Receipt_Email'             => 'Core/Email/class-receipt-email.php',
		'Receipt_PDF'               => 'Core/Email/class-receipt-pdf.php',
		'Refund_Service'            => 'Core/Refund/class-refund-service.php',
		'Stripe_Refund'             => 'Core/Refund/class-stripe-refund.php',
		'PayPal_Refund'             => 'Core/Refund/class-paypal-refund.php',
		'Subscription_Model'        => 'Features/Subscriptions/class-subscription-model.php',
		'Subscription_Service'      => 'Features/Subscriptions/class-subscription-service.php',
		'Admin_Subscriptions'       => 'Features/Subscriptions/class-admin-subscriptions.php',
	);

	/**
	 * Get the single instance.
	 *
	 * @return GatewayKit_Autoloader
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->src_path = GATEWAYKIT_PLUGIN_DIR . 'src/';
		spl_autoload_register( array( $this, 'autoload' ) );
	}

	/**
	 * Autoload a `gatewaykit_*` class.
	 *
	 * Only Core classes are handled here. Gateway and Feature classes are
	 * loaded by their respective `module.php` files before first use.
	 *
	 * @param string $class_name Fully-qualified class name.
	 */
	public function autoload( $class_name ) {
		// Only handle GatewayKit-prefixed classes.
		if ( 0 !== strpos( $class_name, 'GatewayKit_' ) ) {
			return;
		}

		$class_name = str_replace( 'GatewayKit_', '', $class_name );

		if ( isset( $this->core_map[ $class_name ] ) ) {
			$file_path = $this->src_path . $this->core_map[ $class_name ];
			if ( file_exists( $file_path ) ) {
				require_once $file_path;
			}
		}
	}
}

// Initialize the autoloader.
GatewayKit_Autoloader::get_instance();
