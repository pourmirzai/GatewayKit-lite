<?php
/**
 * GatewayKit plugin controller.
 *
 * Moved out of gatewaykit.php as part of the single-branch monorepo refactor.
 * gatewaykit.php is now a thin loader: it defines constants, boots the
 * autoloader, requires this class, and calls GatewayKit::get_instance().
 *
 * Gateways and features are discovered automatically from
 * the module.php file inside each gateway and feature folder.
 *
 * @package GatewayKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GatewayKit
 */
class GatewayKit {

	/**
	 * Single instance of the plugin.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Admin settings instance.
	 *
	 * @var GatewayKit_Admin_Settings|null
	 */
	private $admin_settings = null;

	/**
	 * Admin discounts instance.
	 *
	 * @var GatewayKit_Admin_Discounts|null
	 */
	private $admin_discounts = null;

	/**
	 * Analytics dashboard instance (Pro only).
	 *
	 * Instantiated once in init_admin_components() (registers the AJAX handler
	 * on admin_init, which is the only hook that fires on admin-ajax.php) and
	 * reused by register_admin_menus() for the page callback.
	 *
	 * @var GatewayKit_Analytics_Dashboard|null
	 */
	private $analytics_dashboard = null;

	/**
	 * IDs of loaded feature modules (used for gateway dependency checks).
	 *
	 * @var array
	 */
	private $loaded_features = array();

	/**
	 * Get single instance.
	 *
	 * @return self
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
		$this->init_hooks();
	}

	/**
	 * Initialize hooks.
	 */
	private function init_hooks() {
		register_activation_hook( GATEWAYKIT_PLUGIN_FILE, array( $this, 'activate' ) );
		register_deactivation_hook( GATEWAYKIT_PLUGIN_FILE, array( $this, 'deactivate' ) );

		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ), 1 );
		add_action( 'plugins_loaded', array( $this, 'init' ), 10 );

		// Auto-discover gateways from src/Gateways/ via each folder's module.php.
		add_action( 'gatewaykit_register_gateways', array( $this, 'discover_gateways' ) );

		// Invoice PDF download endpoint (public, receipt token is the key).
		add_action( 'template_redirect', array( $this, 'handle_invoice_download' ), 5 );

		// Admin notices and dismissal.
		add_action( 'admin_notices', array( $this, 'maybe_show_pro_upgrade_notice' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_db_update_notice' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_crypto_key_notice' ) );
		add_action( 'wp_ajax_gatewaykit_dismiss_pro_notice', array( $this, 'dismiss_pro_notice' ) );

		// Register admin components early.
		add_action( 'admin_init', array( 'GatewayKit_Transaction_List_Table', 'dispatch_bulk_action' ), 5 );
		add_action( 'admin_init', array( $this, 'init_admin_components' ), 1 );

		// Register admin menus at the proper hook.
		add_action( 'admin_menu', array( $this, 'register_admin_menus' ), 10 );

		// Enqueue admin assets.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

		// Register AJAX handlers for payment processing.
		add_action( 'wp_ajax_gatewaykit_process_payment', array( $this, 'process_payment_ajax' ) );
		add_action( 'wp_ajax_nopriv_gatewaykit_process_payment', array( $this, 'process_payment_ajax' ) );

		// Cache-safe nonce endpoint.
		add_action( 'wp_ajax_gatewaykit_get_nonce', array( $this, 'ajax_get_nonce' ) );
		add_action( 'wp_ajax_nopriv_gatewaykit_get_nonce', array( $this, 'ajax_get_nonce' ) );

		// Public + private live discount-code validation.
		add_action( 'wp_ajax_gatewaykit_validate_discount_code', array( $this, 'ajax_validate_discount_code' ) );
		add_action( 'wp_ajax_nopriv_gatewaykit_validate_discount_code', array( $this, 'ajax_validate_discount_code' ) );

		// Scheduled task for payment verification retries.
		add_action( 'gatewaykit_retry_payment_verification', array( $this, 'retry_payment_verification' ) );

		// Scheduled cleanup of old payment logs.
		add_action( 'gatewaykit_cleanup_old_logs', array( $this, 'cleanup_old_logs_event' ) );
	}

	/**
	 * Plugin activation.
	 */
	public function activate() {
		GatewayKit_Installer::activate();
	}

	/**
	 * Plugin deactivation.
	 */
	public function deactivate() {
		GatewayKit_Installer::deactivate();
	}

	/**
	 * Create default sample content (receipt page and forms) on first activation.
	 */
	public function maybe_create_sample_content() {
		GatewayKit_Installer::maybe_create_sample_content();
	}

	/**
	 * Scheduled event callback: remove logs older than the retention window.
	 */
	public function cleanup_old_logs_event() {
		$logger = GatewayKit_Logger::get_instance();
		$days   = (int) get_option( 'gatewaykit_log_retention_days', 30 );
		$logger->clean_old_logs( $days > 0 ? $days : 30 );
	}

	/**
	 * Apply the admin_ajax rate limit to a request.
	 */
	private function check_admin_rate_limit() {
		$rate_limiter = GatewayKit_Rate_Limiter::get_instance();
		$rate_check   = $rate_limiter->check_rate_limit( 'admin_ajax' );
		if ( is_wp_error( $rate_check ) ) {
			wp_send_json_error( $rate_check->get_error_message() );
		}
	}

	/**
	 * Load plugin textdomain.
	 */
	public function load_textdomain() {
		// phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- kept for custom translation loading; WP 4.6+ auto-loads .org translations but this ensures bundled .po/.mo files work when present.
		load_plugin_textdomain(
			'gatewaykit',
			false,
			dirname( GATEWAYKIT_PLUGIN_BASENAME ) . '/languages/'
		);
	}

	/**
	 * Initialize plugin.
	 */
	public function init() {
		// Initialize autoloader FIRST to ensure all classes are available.
		GatewayKit_Autoloader::get_instance();

		// Signal that GatewayKit Core is fully loaded.
		do_action( 'gatewaykit_lite_loaded' );
		do_action( 'gatewaykit_loaded' );

		// Load feature modules from src/Features/.
		$this->load_features();

		// Initialize core components.
		$this->init_components();

		// Initialize standalone form builder CPT and renderer.
		GatewayKit_Form_CPT::get_instance();

		// Initialize Gutenberg blocks.
		if ( class_exists( 'GatewayKit_Blocks' ) ) {
			GatewayKit_Blocks::get_instance();
		}

		// Register universal payment form shortcode: [gatewaykit_form id="..."].
		add_shortcode( 'gatewaykit_form', array( 'GatewayKit_Form_Renderer', 'render_shortcode' ) );

		// Register Elementor Free widget on elementor/widgets/register.
		add_action( 'elementor/widgets/register', array( $this, 'register_elementor_widgets' ) );

		// If Elementor Pro is active, hook into its form actions.
		if ( $this->is_elementor_pro_active() ) {
			add_action( 'elementor_pro/init', array( $this, 'init_elementor_pro_components' ) );
		}

		// Initialize callback handler (always needed for payment processing).
		require_once GATEWAYKIT_PLUGIN_DIR . 'src/Core/callback-handler.php';

		// Initialize payment result shortcode (always needed).
		require_once GATEWAYKIT_PLUGIN_DIR . 'src/Core/class-payment-result-shortcode.php';

		// Enqueue frontend styles and scripts.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
	}

	/**
	 * Initialize plugin components.
	 */
	private function init_components() {
		GatewayKit_Database_Manager::get_instance();
		GatewayKit_Security_Manager::get_instance();
		GatewayKit_Logger::get_instance();
		GatewayKit_Gateway_Manager::get_instance();

		if ( class_exists( 'GatewayKit_Receipt_Email' ) ) {
			GatewayKit_Receipt_Email::get_instance();
		}

		if ( class_exists( 'GatewayKit_Subscription_Service' ) && gatewaykit_is_pro_licensed() ) {
			GatewayKit_Subscription_Service::get_instance();
		}
	}

	/**
	 * Initialize admin components.
	 */
	public function init_admin_components() {
		if ( ! is_admin() ) {
			return;
		}

		if ( ! $this->admin_settings instanceof GatewayKit_Admin_Settings ) {
			$this->admin_settings = new GatewayKit_Admin_Settings();
		}
		new GatewayKit_Dashboard_Widgets();
		if ( class_exists( 'GatewayKit_Admin_Discounts' ) && ! $this->admin_discounts instanceof GatewayKit_Admin_Discounts ) {
			$this->admin_discounts = new GatewayKit_Admin_Discounts();
		}

		if ( class_exists( 'GatewayKit_Payment_Links' ) ) {
			new GatewayKit_Payment_Links();
		}

		if ( class_exists( 'GatewayKit_Setup_Wizard' ) ) {
			GatewayKit_Setup_Wizard::get_instance();
		}

		if ( class_exists( 'GatewayKit_Analytics_Dashboard' ) && gatewaykit_is_pro_licensed() && ! $this->analytics_dashboard instanceof GatewayKit_Analytics_Dashboard ) {
			$this->analytics_dashboard = new GatewayKit_Analytics_Dashboard();
		}
	}

	/**
	 * Register admin menus.
	 */
	public function register_admin_menus() {
		if ( ! is_admin() ) {
			return;
		}

		$admin_menus = new GatewayKit_Admin_Menus( $this->admin_settings, null, null );
		$admin_menus->register_all_menus( $this->admin_settings, $this->admin_discounts, $this->analytics_dashboard );
	}

	/**
	 * Render an upgrade CTA page for locked Pro features.
	 */
	public function pro_feature_page() {
		GatewayKit_Admin_Menus::render_pro_feature_page();
	}

	/**
	 * Initialize Elementor Pro components.
	 */
	public function init_elementor_pro_components() {
		new GatewayKit_Elementor_Action();
	}

	/**
	 * Register GatewayKit Elementor widgets.
	 *
	 * @param \Elementor\Widgets_Manager $widgets_manager Elementor widgets manager.
	 */
	public function register_elementor_widgets( $widgets_manager ) {
		if ( class_exists( 'GatewayKit_Elementor_Payment_Widget' ) ) {
			$widgets_manager->register( new GatewayKit_Elementor_Payment_Widget() );
		}
	}

	/**
	 * Detect whether Elementor Pro is active and loaded.
	 *
	 * @return bool
	 */
	private function is_elementor_pro_active() {
		return did_action( 'elementor_pro/loaded' )
			|| class_exists( '\ElementorPro\Plugin' )
			|| defined( 'ELEMENTOR_PRO_VERSION' );
	}

	/**
	 * Discover and register gateways from src/Gateways/.
	 *
	 * @param GatewayKit_Gateway_Manager $manager Gateway manager instance.
	 */
	public function discover_gateways( $manager ) {
		foreach ( (array) glob( GATEWAYKIT_PLUGIN_DIR . 'src/Gateways/*/module.php' ) as $module_file ) {
			$meta = include $module_file;
			if ( ! is_array( $meta ) || empty( $meta['class'] ) || empty( $meta['id'] ) ) {
				continue;
			}
			if ( ! $this->requirements_met( $meta ) ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					GatewayKit_Logger::get_instance()->warning(
						'Gateway module skipped: unmet requirements',
						array(
							'gateway'  => $meta['id'],
							'requires' => isset( $meta['requires'] ) ? $meta['requires'] : array(),
						)
					);
				}
				continue;
			}
			$manager->register_gateway( $meta['id'], $meta['class'] );
		}
	}

	/**
	 * Load feature modules from src/Features/.
	 */
	public function load_features() {
		foreach ( (array) glob( GATEWAYKIT_PLUGIN_DIR . 'src/Features/*/module.php' ) as $module_file ) {
			$meta = include $module_file;
			if ( is_array( $meta ) && ! empty( $meta['id'] ) ) {
				$this->loaded_features[] = $meta['id'];
			}
		}
	}

	/**
	 * Whether a module's declared requirements are satisfied by the loaded feature set.
	 *
	 * @param array $meta Module metadata.
	 * @return bool
	 */
	private function requirements_met( $meta ) {
		$requires = isset( $meta['requires'] ) ? (array) $meta['requires'] : array();
		foreach ( $requires as $need ) {
			if ( ! in_array( $need, $this->loaded_features, true ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether the active build is the Lite product.
	 *
	 * @return bool
	 */
	private function is_lite_build() {
		return ! defined( 'GATEWAYKIT_PRO_VERSION' );
	}

	/**
	 * Show dismissible admin notice promoting GatewayKit Pro.
	 */
	public function maybe_show_pro_upgrade_notice() {
		GatewayKit_Admin_Notices::maybe_show_pro_upgrade_notice();
	}

	/**
	 * Show admin notice when database schema has been updated.
	 */
	public function maybe_show_db_update_notice() {
		GatewayKit_Admin_Notices::maybe_show_db_update_notice();
	}

	/**
	 * Show admin notice when secret constants are missing.
	 */
	public function maybe_show_crypto_key_notice() {
		GatewayKit_Admin_Notices::maybe_show_crypto_key_notice();
	}

	/**
	 * AJAX handler: dismiss the Pro upgrade notice.
	 */
	public function dismiss_pro_notice() {
		GatewayKit_Admin_Notices::dismiss_pro_notice();
	}

	/**
	 * Set default options.
	 */
	private function set_default_options() {
		GatewayKit_Installer::set_default_options();
	}

	/**
	 * Elementor Pro missing notice.
	 */
	public function elementor_pro_missing_notice() {
		GatewayKit_Admin_Notices::elementor_pro_missing_notice();
	}

	/**
	 * Enqueue frontend assets for payment result pages and forms.
	 */
	public function enqueue_frontend_assets() {
		GatewayKit_Frontend_Assets::enqueue();
	}

	/**
	 * Enqueue admin-side assets.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueue_admin_assets( $hook ) {
		GatewayKit_Admin_Notices::enqueue_admin_assets( $hook );
	}

	/**
	 * Determine whether current request is an Elementor-rendered page.
	 *
	 * @return bool
	 */
	private function is_elementor_page() {
		return GatewayKit_Frontend_Assets::is_elementor_page();
	}

	/**
	 * Retry payment verification for failed transactions.
	 *
	 * @param int $transaction_id Transaction ID.
	 */
	public function retry_payment_verification( $transaction_id ) {
		GatewayKit_Verification_Retry::retry( $transaction_id );
	}

	/**
	 * Cache-safe nonce provider.
	 */
	public function ajax_get_nonce() {
		$this->check_admin_rate_limit();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$context = isset( $_REQUEST['context'] ) && 'form' === sanitize_key( wp_unslash( $_REQUEST['context'] ) ) ? 'form' : 'elementor';

		$issued = GatewayKit_Nonce_Service::issue_nonce( $context );
		wp_send_json_success( $issued );
	}

	/**
	 * Name of the nonce-bind cookie.
	 *
	 * @param string $context Context ('elementor' or 'form').
	 * @return string Cookie name.
	 */
	private function nonce_bind_cookie_name( $context = 'elementor' ) {
		return GatewayKit_Nonce_Service::nonce_bind_cookie_name( $context );
	}

	/**
	 * Compute the bind value for a faucet-issued nonce.
	 *
	 * @param string $nonce Nonce.
	 * @return string
	 */
	private function compute_nonce_bind( $nonce ) {
		return GatewayKit_Nonce_Service::compute_nonce_bind( $nonce );
	}

	/**
	 * Set the short-lived nonce-bind cookie.
	 *
	 * @param string $nonce   Nonce.
	 * @param string $context Context ('elementor' or 'form').
	 */
	private function set_nonce_bind_cookie( $nonce, $context = 'elementor' ) {
		GatewayKit_Nonce_Service::set_nonce_bind_cookie( $nonce, $context );
	}

	/**
	 * Verify that the nonce-bind cookie matches the submitted nonce.
	 *
	 * @param string $nonce   Nonce.
	 * @param string $context Context ('elementor' or 'form').
	 * @return bool
	 */
	private function verify_nonce_bind( $nonce, $context = 'elementor' ) {
		return GatewayKit_Nonce_Service::verify_nonce_bind( $nonce, $context );
	}

	/**
	 * Public + private AJAX handler for live discount-code validation.
	 */
	public function ajax_validate_discount_code() {
		if ( ! class_exists( 'GatewayKit_Discount_Model' ) ) {
			wp_send_json_error( __( 'Discount codes are not available.', 'gatewaykit' ) );
		}

		$rate_limiter = GatewayKit_Rate_Limiter::get_instance();
		$rate_check   = $rate_limiter->check_rate_limit( 'discount_preview' );
		if ( is_wp_error( $rate_check ) ) {
			wp_send_json_error( $rate_check->get_error_message() );
		}

		check_ajax_referer( 'gatewaykit_discount_nonce', 'nonce' );

		$code   = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$amount = isset( $_POST['amount'] ) ? floatval( wp_unslash( $_POST['amount'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( '' === $code ) {
			wp_send_json_error( __( 'Please enter a discount code.', 'gatewaykit' ) );
		}
		if ( $amount <= 0 ) {
			wp_send_json_error( __( 'Invalid amount.', 'gatewaykit' ) );
		}

		$user_id = is_user_logged_in() ? get_current_user_id() : 0;

		$valid = GatewayKit_Discount_Model::validate_code( $code, $amount, $user_id );
		if ( is_wp_error( $valid ) ) {
			wp_send_json_error( $valid->get_error_message() );
		}

		$calc = GatewayKit_Discount_Model::calculate_discount( $code, $amount );
		if ( is_wp_error( $calc ) ) {
			wp_send_json_error( $calc->get_error_message() );
		}

		$final = (float) $calc['final_amount'];
		if ( $final < 0 ) {
			$final = 0;
		}

		wp_send_json_success(
			array(
				'discount_amount' => (float) $calc['discount_amount'],
				'final_amount'    => $final,
				'original_amount' => $amount,
				'valid'           => true,
			)
		);
	}

	/**
	 * AJAX handler for payment processing.
	 */
	public function process_payment_ajax() {
		GatewayKit_Ajax_Payment_Handler::process_payment_ajax();
	}

	/**
	 * Process direct payment AJAX request.
	 *
	 * @param array $data Payment data.
	 * @return array|WP_Error Payment result or error.
	 */
	public function process_direct_payment_ajax( $data ) {
		return GatewayKit_Ajax_Payment_Handler::process_direct_payment_ajax( $data );
	}

	/**
	 * Sanitize form data for AJAX requests.
	 *
	 * @param array $form_data Form data.
	 * @return array Sanitized form data.
	 */
	private function sanitize_form_data( $form_data ) {
		return GatewayKit_Ajax_Payment_Handler::sanitize_form_data( $form_data );
	}

	/**
	 * Get user data for AJAX requests.
	 *
	 * @return array User data.
	 */
	private function get_user_data_for_ajax() {
		return GatewayKit_Payment_Service::get_user_data();
	}

	/**
	 * Get callback URL for AJAX requests.
	 *
	 * @param string $gateway Gateway ID.
	 * @return string Callback URL.
	 */
	private function get_callback_url_for_ajax( $gateway ) {
		return GatewayKit_Payment_Service::get_callback_url( $gateway );
	}

	/**
	 * Process payment directly without Elementor integration.
	 *
	 * @param array $payment_data Payment data.
	 * @return array|WP_Error Payment result or error.
	 */
	private function process_payment_direct( $payment_data ) {
		return GatewayKit_Payment_Service::process_payment( $payment_data );
	}

	/**
	 * Recursively scan Elementor elements for GatewayKit Pro form fields.
	 *
	 * @param array $elements Elementor elements tree.
	 * @return bool True if a pro form field is found.
	 */
	private static function scan_for_pro_form_fields( $elements ) {
		return GatewayKit_Elementor_Page_Scanner::scan_for_pro_form_fields( $elements );
	}

	/**
	 * Handle invoice PDF download endpoint.
	 */
	public function handle_invoice_download() {
		GatewayKit_Invoice_Controller::handle_download();
	}
}
