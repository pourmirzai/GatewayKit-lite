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
		'Abstract_Payment_Gateway'  => 'Core/Abstracts/class-gatewaykit-abstract-payment-gateway.php',
		'Payment_Gateway_Interface' => 'Core/Abstracts/interface-payment-gateway.php',
		'Admin_Settings'            => 'Core/Admin/class-gatewaykit-admin-settings.php',
		'Admin_Ajax_Guard'          => 'Core/Admin/class-gatewaykit-admin-ajax-guard.php',
		'Admin_Menus'               => 'Core/Admin/class-gatewaykit-admin-menus.php',
		'Admin_Ajax'                => 'Core/Admin/class-gatewaykit-admin-ajax.php',
		'Admin_Logs_Page'           => 'Core/Admin/class-gatewaykit-admin-logs-page.php',
		'Admin_Transactions_Page'   => 'Core/Admin/class-gatewaykit-admin-transactions-page.php',
		'Admin_Settings_Page'       => 'Core/Admin/class-gatewaykit-admin-settings-page.php',
		'Analytics_Dashboard'       => 'Core/Admin/class-gatewaykit-analytics-dashboard.php',
		'Transaction_List_Table'    => 'Core/Admin/class-gatewaykit-transaction-list-table.php',
		'Transaction_CSV_Exporter'  => 'Core/Admin/class-gatewaykit-transaction-csv-exporter.php',
		'Log_List_Table'            => 'Core/Admin/class-gatewaykit-log-list-table.php',
		'Dashboard_Widgets'         => 'Core/Admin/class-gatewaykit-dashboard-widgets.php',
		'Payment_Links'             => 'Core/Admin/class-gatewaykit-payment-links.php',
		'Gateway_Manager'           => 'Core/class-gatewaykit-gateway-manager.php',
		'Plugin'                    => 'Core/class-gatewaykit.php',
		'Payment_Result_Shortcode'  => 'Core/class-gatewaykit-payment-result-shortcode.php',
		'Elementor_Action'          => 'Core/Elementor/class-gatewaykit-elementor-action.php',
		'Payment_Service'           => 'Core/Payment/class-gatewaykit-payment-service.php',
		'Transaction_Model'         => 'Core/Database/class-gatewaykit-transaction-model.php',
		'Transaction_Query'         => 'Core/Database/class-gatewaykit-transaction-query.php',
		'Database_Manager'          => 'Core/Database/class-gatewaykit-database-manager.php',
		'Security_Manager'          => 'Core/Security/class-gatewaykit-security-manager.php',
		'Input_Validator'           => 'Core/Security/class-gatewaykit-input-validator.php',
		'Rate_Limiter'              => 'Core/Security/class-gatewaykit-rate-limiter.php',
		'Nonce_Service'             => 'Core/Security/class-gatewaykit-nonce-service.php',
		'Logger'                    => 'Core/Utils/class-gatewaykit-logger.php',
		'Log_Formatter'             => 'Core/Utils/class-gatewaykit-log-formatter.php',
		'IP_Helper'                 => 'Core/Utils/class-gatewaykit-ip-helper.php',
		'Error_Handler'             => 'Core/Utils/class-gatewaykit-error-handler.php',
		'Crypto'                    => 'Core/Utils/class-gatewaykit-crypto.php',
		'Receipt_Email'             => 'Core/Email/class-gatewaykit-receipt-email.php',
		'Receipt_PDF'               => 'Core/Email/class-gatewaykit-receipt-pdf.php',
		'Refund_Service'            => 'Core/Refund/class-gatewaykit-refund-service.php',
		'Stripe_Refund'             => 'Core/Refund/class-gatewaykit-stripe-refund.php',
		'PayPal_Refund'             => 'Core/Refund/class-gatewaykit-paypal-refund.php',
		'Form_CPT'                  => 'Core/Forms/class-gatewaykit-form-cpt.php',
		'Form_Admin_Ui'             => 'Core/Forms/class-gatewaykit-form-admin-ui.php',
		'Form_Admin_Save'           => 'Core/Forms/class-gatewaykit-form-admin-save.php',
		'Form_Admin_List'           => 'Core/Forms/class-gatewaykit-form-admin-list.php',
		'Form_Renderer'             => 'Core/Forms/class-gatewaykit-form-renderer.php',
		'Form_Templates'            => 'Core/Forms/class-gatewaykit-form-templates.php',
		'Elementor_Payment_Widget'  => 'Core/Elementor/class-gatewaykit-elementor-payment-widget.php',
		'Elementor_Page_Scanner'    => 'Core/Elementor/class-gatewaykit-elementor-page-scanner.php',
		'Elementor_Form_Controls'   => 'Core/Elementor/class-gatewaykit-elementor-form-controls.php',
		'Installer'                 => 'Core/class-gatewaykit-installer.php',
		'Admin_Notices'             => 'Core/Admin/class-gatewaykit-admin-notices.php',
		'Invoice_Controller'        => 'Core/class-gatewaykit-invoice-controller.php',
		'Verification_Retry'        => 'Core/Payment/class-gatewaykit-verification-retry.php',
		'Ajax_Payment_Handler'      => 'Core/Payment/class-gatewaykit-ajax-payment-handler.php',
		'Frontend_Assets'           => 'Core/class-gatewaykit-frontend-assets.php',
		'Blocks'                    => 'Core/Blocks/class-gatewaykit-blocks.php',
		'Setup_Wizard'              => 'Core/Admin/class-gatewaykit-setup-wizard.php',
		'Subscription_Model'        => 'Features/Subscriptions/class-gatewaykit-subscription-model.php',
		'Subscription_Service'      => 'Features/Subscriptions/class-gatewaykit-subscription-service.php',
		'Admin_Subscriptions'       => 'Features/Subscriptions/class-gatewaykit-admin-subscriptions.php',
		'Discount_List_Table'       => 'Features/Discount/class-gatewaykit-discount-list-table.php',
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
