<?php
/**
 * Admin Settings
 *
 * Facade coordinating admin menus, settings, transactions, logs, and AJAX
 * components in the WordPress admin.
 *
 * @package GatewayKit
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin Settings Class
 */
class GatewayKit_Admin_Settings {

	/**
	 * Settings page slug.
	 */
	const PAGE_SLUG = 'gatewaykit-settings';

	/**
	 * Settings page component.
	 *
	 * @var GatewayKit_Admin_Settings_Page
	 */
	public $settings_page;

	/**
	 * Transactions page component.
	 *
	 * @var GatewayKit_Admin_Transactions_Page
	 */
	public $transactions_page;

	/**
	 * Logs page component.
	 *
	 * @var GatewayKit_Admin_Logs_Page
	 */
	public $logs_page;

	/**
	 * Menus and assets component.
	 *
	 * @var GatewayKit_Admin_Menus
	 */
	public $menus;

	/**
	 * Admin AJAX component.
	 *
	 * @var GatewayKit_Admin_Ajax
	 */
	public $ajax;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->settings_page     = new GatewayKit_Admin_Settings_Page();
		$this->transactions_page = new GatewayKit_Admin_Transactions_Page();
		$this->logs_page         = new GatewayKit_Admin_Logs_Page();
		$this->menus             = new GatewayKit_Admin_Menus( $this->settings_page, $this->transactions_page, $this->logs_page );
		$this->ajax              = new GatewayKit_Admin_Ajax();

		// Admin hooks.
		add_action( 'admin_init', array( $this->settings_page, 'register_settings' ) );
		add_action( 'admin_init', array( $this->transactions_page, 'handle_transaction_exports' ) );
		add_action( 'admin_init', array( $this->transactions_page, 'handle_refund_action' ) );
		add_action( 'admin_enqueue_scripts', array( $this->menus, 'enqueue_scripts' ) );
		add_action( 'current_screen', array( $this->menus, 'register_screen_options' ) );

		// AJAX endpoints.
		add_action( 'wp_ajax_gatewaykit_get_form_data', array( $this->transactions_page, 'ajax_get_form_data' ) );
		add_action( 'wp_ajax_gatewaykit_get_error_details', array( $this->transactions_page, 'ajax_get_error_details' ) );
		add_action( 'wp_ajax_gatewaykit_copy_logs', array( $this->logs_page, 'ajax_copy_logs' ) );
		add_action( 'wp_ajax_gatewaykit_export_logs', array( $this->logs_page, 'ajax_export_logs' ) );
		add_action( 'wp_ajax_gatewaykit_test_gateway', array( $this->ajax, 'ajax_test_gateway' ) );
		add_action( 'wp_ajax_gatewaykit_test_webhook', array( $this->ajax, 'ajax_test_webhook' ) );
		add_action( 'wp_ajax_gatewaykit_get_transaction_notes', array( $this->transactions_page, 'ajax_get_transaction_notes' ) );
		add_action( 'wp_ajax_gatewaykit_add_transaction_note', array( $this->transactions_page, 'ajax_add_transaction_note' ) );
	}

	/**
	 * Register screen options for transactions table.
	 *
	 * @param WP_Screen $screen Current screen object.
	 */
	public function register_screen_options( $screen ) {
		$this->menus->register_screen_options( $screen );
	}

	/**
	 * Add admin submenu pages.
	 */
	public function add_admin_menu() {
		$this->menus->add_admin_menu();
	}

	/**
	 * Register the Transactions submenu.
	 */
	public function add_transactions_menu() {
		$this->menus->add_transactions_menu();
	}

	/**
	 * Register settings.
	 */
	public function register_settings() {
		$this->settings_page->register_settings();
	}

	/**
	 * Sanitize currency setting.
	 *
	 * @param mixed $value Raw input.
	 * @return string
	 */
	public function sanitize_currency_setting( $value ) {
		return $this->settings_page->sanitize_currency_setting( $value );
	}

	/**
	 * Sanitize log level setting.
	 *
	 * @param mixed $value Raw input.
	 * @return string
	 */
	public function sanitize_log_level_setting( $value ) {
		return $this->settings_page->sanitize_log_level_setting( $value );
	}

	/**
	 * Sanitize print mode setting.
	 *
	 * @param mixed $value Raw input.
	 * @return string
	 */
	public function sanitize_print_mode_setting( $value ) {
		return $this->settings_page->sanitize_print_mode_setting( $value );
	}

	/**
	 * Sanitize boolean setting.
	 *
	 * @param mixed $value Raw input.
	 * @return string
	 */
	public function sanitize_bool_setting( $value ) {
		return $this->settings_page->sanitize_bool_setting( $value );
	}

	/**
	 * Sanitize webhook URLs.
	 *
	 * @param mixed $value Raw input.
	 * @return string
	 */
	public function sanitize_webhook_urls( $value ) {
		return $this->settings_page->sanitize_webhook_urls( $value );
	}

	/**
	 * Sanitize trusted proxies.
	 *
	 * @param mixed $value Raw input.
	 * @return string
	 */
	public function sanitize_trusted_proxies( $value ) {
		return $this->settings_page->sanitize_trusted_proxies( $value );
	}

	/**
	 * Sanitize webhook events.
	 *
	 * @param mixed $value Raw input.
	 * @return array
	 */
	public function sanitize_webhook_events( $value ) {
		return $this->settings_page->sanitize_webhook_events( $value );
	}

	/**
	 * Sanitize gateway settings array.
	 *
	 * @param mixed $settings Raw input.
	 * @return array
	 */
	public function sanitize_gateway_settings_array( $settings ) {
		return $this->settings_page->sanitize_gateway_settings_array( $settings );
	}

	/**
	 * Handle transaction export actions.
	 */
	public function handle_transaction_exports() {
		$this->transactions_page->handle_transaction_exports();
	}

	/**
	 * Handle single-row refund action.
	 */
	public function handle_refund_action() {
		$this->transactions_page->handle_refund_action();
	}

	/**
	 * Render the dashboard page.
	 */
	public function dashboard_page() {
		$this->settings_page->dashboard_page();
	}

	/**
	 * Render the settings page.
	 */
	public function settings_page() {
		$this->settings_page->settings_page();
	}

	/**
	 * Render the transactions list table page.
	 */
	public function transactions_page() {
		$this->transactions_page->transactions_page();
	}

	/**
	 * Render payment logs page.
	 */
	public function logs_page() {
		$this->logs_page->logs_page();
	}

	/**
	 * Render usage guide page.
	 */
	public function usage_guide_page() {
		$this->settings_page->usage_guide_page();
	}

	/**
	 * AJAX handler: get transaction form data.
	 */
	public function ajax_get_form_data() {
		$this->transactions_page->ajax_get_form_data();
	}

	/**
	 * AJAX handler: get error details.
	 */
	public function ajax_get_error_details() {
		$this->transactions_page->ajax_get_error_details();
	}

	/**
	 * AJAX handler: test gateway connection.
	 */
	public function ajax_test_gateway() {
		$this->ajax->ajax_test_gateway();
	}

	/**
	 * AJAX handler: copy logs.
	 */
	public function ajax_copy_logs() {
		$this->logs_page->ajax_copy_logs();
	}

	/**
	 * AJAX handler: export logs.
	 */
	public function ajax_export_logs() {
		$this->logs_page->ajax_export_logs();
	}

	/**
	 * AJAX handler: test webhook delivery.
	 */
	public function ajax_test_webhook() {
		$this->ajax->ajax_test_webhook();
	}

	/**
	 * Enqueue admin scripts and styles.
	 *
	 * @param string $hook The current admin page hook.
	 */
	public function enqueue_scripts( string $hook ) {
		$this->menus->enqueue_scripts( $hook );
	}

	/**
	 * AJAX handler: get transaction notes.
	 */
	public function ajax_get_transaction_notes() {
		$this->transactions_page->ajax_get_transaction_notes();
	}

	/**
	 * AJAX handler: add transaction note.
	 */
	public function ajax_add_transaction_note() {
		$this->transactions_page->ajax_add_transaction_note();
	}
}
