<?php
/**
 * Admin Menus and Assets
 *
 * Handles menu registration, screen options, and asset enqueueing
 * for GatewayKit admin pages.
 *
 * @package GatewayKit
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin Menus Class
 */
class GatewayKit_Admin_Menus {

	/**
	 * Settings page slug.
	 */
	const PAGE_SLUG = 'gatewaykit-settings';

	/**
	 * Settings page handler or callable.
	 *
	 * @var callable|object|null
	 */
	private $settings_handler;

	/**
	 * Transactions page handler or callable.
	 *
	 * @var callable|object|null
	 */
	private $transactions_handler;

	/**
	 * Logs page handler or callable.
	 *
	 * @var callable|object|null
	 */
	private $logs_handler;

	/**
	 * Constructor.
	 *
	 * @param object|null $settings_handler     Optional handler for settings and guide page.
	 * @param object|null $transactions_handler Optional handler for transactions page.
	 * @param object|null $logs_handler         Optional handler for logs page.
	 */
	public function __construct( $settings_handler = null, $transactions_handler = null, $logs_handler = null ) {
		$this->settings_handler     = $settings_handler;
		$this->transactions_handler = $transactions_handler;
		$this->logs_handler         = $logs_handler;
	}

	/**
	 * Register screen options early so WordPress renders the column
	 * visibility toggles in the Screen Options panel (must run before
	 * admin-header.php calls WP_Screen::render_screen_meta).
	 *
	 * @param WP_Screen $screen Current screen object.
	 */
	public function register_screen_options( $screen ) {
		if ( false === strpos( $screen->id, 'gatewaykit-transactions' ) ) {
			return;
		}

		// Wire our list-table columns into the manage_{$screen->id}_columns
		// filter so get_column_headers() returns them. This makes
		// render_list_table_columns_preferences() render the column toggles
		// in the Screen Options panel, and WP's AJAX handler
		// (wp_ajax_hidden_columns) persists toggles to user meta.
		add_filter(
			"manage_{$screen->id}_columns",
			function () {
				$list_table = new GatewayKit_Transaction_List_Table();
				return $list_table->get_columns();
			}
		);

		$screen->add_option(
			'hidden_columns',
			array(
				'target' => array(
					'cb',
					'id',
					'receipt_code',
					'user',
					'gateway',
					'amount',
					'description',
					'status',
					'error_details',
					'authority',
					'created_at',
				),
			)
		);

		$screen->add_option(
			'per_page',
			array(
				'label'   => __( 'Transactions', 'gatewaykit' ),
				'default' => 20,
				'option'  => 'gatewaykit_transactions_per_page',
			)
		);
	}

	/**
	 * Add admin menu.
	 */
	public function add_admin_menu() {
		// Only add menu in admin context.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings_callback = is_object( $this->settings_handler ) && method_exists( $this->settings_handler, 'settings_page' )
			? array( $this->settings_handler, 'settings_page' )
			: array( $this, 'render_settings_page' );

		$logs_callback = is_object( $this->logs_handler ) && method_exists( $this->logs_handler, 'logs_page' )
			? array( $this->logs_handler, 'logs_page' )
			: array( $this, 'render_logs_page' );

		$guide_callback = is_object( $this->settings_handler ) && method_exists( $this->settings_handler, 'usage_guide_page' )
			? array( $this->settings_handler, 'usage_guide_page' )
			: array( $this, 'render_usage_guide_page' );

		// Submenu for settings.
		add_submenu_page(
			'gatewaykit',
			__( 'Settings', 'gatewaykit' ),
			__( 'Settings', 'gatewaykit' ),
			'manage_options',
			self::PAGE_SLUG,
			$settings_callback
		);

		// Submenu for logs.
		add_submenu_page(
			'gatewaykit',
			__( 'Logs', 'gatewaykit' ),
			__( 'Logs', 'gatewaykit' ),
			'manage_options',
			'gatewaykit-logs',
			$logs_callback
		);

		// Submenu for usage guide.
		add_submenu_page(
			'gatewaykit',
			__( 'Usage Guide', 'gatewaykit' ),
			__( 'Usage Guide', 'gatewaykit' ),
			'manage_options',
			'gatewaykit-usage-guide',
			$guide_callback
		);

		// Submenu for setup wizard.
		if ( class_exists( 'GatewayKit_Setup_Wizard' ) ) {
			add_submenu_page(
				'gatewaykit',
				__( 'Setup Wizard', 'gatewaykit' ),
				__( 'Setup Wizard', 'gatewaykit' ),
				'manage_options',
				'gatewaykit-setup-wizard',
				array( GatewayKit_Setup_Wizard::get_instance(), 'render_page' )
			);
		}
	}

	/**
	 * Register the Transactions submenu separately so callers control ordering.
	 */
	public function add_transactions_menu() {
		if ( ! is_admin() ) {
			return;
		}

		$transactions_callback = is_object( $this->transactions_handler ) && method_exists( $this->transactions_handler, 'transactions_page' )
			? array( $this->transactions_handler, 'transactions_page' )
			: array( $this, 'render_transactions_page' );

		add_submenu_page(
			'gatewaykit',
			__( 'Transactions', 'gatewaykit' ),
			__( 'Transactions', 'gatewaykit' ),
			'manage_options',
			'gatewaykit-transactions',
			$transactions_callback
		);
	}

	/**
	 * Fallback renderer for settings page.
	 */
	public function render_settings_page() {
		if ( class_exists( 'GatewayKit_Admin_Settings_Page' ) ) {
			$page = new GatewayKit_Admin_Settings_Page();
			$page->settings_page();
		}
	}

	/**
	 * Fallback renderer for logs page.
	 */
	public function render_logs_page() {
		if ( class_exists( 'GatewayKit_Admin_Logs_Page' ) ) {
			$page = new GatewayKit_Admin_Logs_Page();
			$page->logs_page();
		}
	}

	/**
	 * Fallback renderer for usage guide page.
	 */
	public function render_usage_guide_page() {
		if ( class_exists( 'GatewayKit_Admin_Settings_Page' ) ) {
			$page = new GatewayKit_Admin_Settings_Page();
			$page->usage_guide_page();
		}
	}

	/**
	 * Fallback renderer for transactions page.
	 */
	public function render_transactions_page() {
		if ( class_exists( 'GatewayKit_Admin_Transactions_Page' ) ) {
			$page = new GatewayKit_Admin_Transactions_Page();
			$page->transactions_page();
		}
	}

	/**
	 * Enqueue admin scripts and styles.
	 *
	 * @param string $hook The current admin page hook.
	 */
	public function enqueue_scripts( string $hook ) {
		if ( strpos( $hook, 'gatewaykit' ) === false ) {
			return;
		}

		// Use minified assets in production, full assets in debug mode.
		$suffix = defined( 'WP_DEBUG' ) && WP_DEBUG ? '' : '.min';

		wp_enqueue_style( 'gatewaykit-admin-styles', GATEWAYKIT_PLUGIN_URL . 'assets/css/admin' . $suffix . '.css', array(), GATEWAYKIT_VERSION );
		wp_enqueue_style( 'gatewaykit-validation-states', GATEWAYKIT_PLUGIN_URL . 'assets/css/validation-states.css', array( 'gatewaykit-admin-styles' ), GATEWAYKIT_VERSION );

		$current_page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( 'gatewaykit-transactions' === $current_page ) {
			wp_register_script( 'gatewaykit-admin-transactions', false, array( 'gatewaykit-admin-scripts' ), GATEWAYKIT_VERSION, true );
			wp_enqueue_script( 'gatewaykit-admin-scripts', GATEWAYKIT_PLUGIN_URL . 'assets/js/admin-transactions' . $suffix . '.js', array( 'jquery' ), GATEWAYKIT_VERSION, true );
		} else {
			wp_register_script( 'gatewaykit-admin-settings', false, array( 'gatewaykit-admin-scripts' ), GATEWAYKIT_VERSION, true );
			wp_enqueue_script( 'gatewaykit-admin-scripts', GATEWAYKIT_PLUGIN_URL . 'assets/js/admin-settings' . $suffix . '.js', array( 'jquery' ), GATEWAYKIT_VERSION, true );
		}
		wp_enqueue_script( 'gatewaykit-ui-kit', GATEWAYKIT_PLUGIN_URL . 'assets/js/ui-kit' . $suffix . '.js', array( 'jquery' ), GATEWAYKIT_VERSION, true );

		// Localize script for AJAX and translations.
		wp_localize_script(
			'gatewaykit-admin-scripts',
			'gatewaykit_ajax',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'gatewaykit_ajax_nonce' ),
			)
		);

		// Localize script for translations.
		wp_localize_script(
			'gatewaykit-admin-scripts',
			'gatewaykit_admin_vars',
			array(
				'confirm_delete'       => __( 'Are you sure you want to delete the selected transactions?', 'gatewaykit' ),
				'validation_error'     => __( 'Please check the form for errors.', 'gatewaykit' ),
				'testing'              => __( 'Testing...', 'gatewaykit' ),
				'test_failed'          => __( 'Test failed', 'gatewaykit' ),
				'ajax_error'           => __( 'AJAX error occurred', 'gatewaykit' ),
				'loading'              => __( 'Loading...', 'gatewaykit' ),
				'transaction_details'  => __( 'Transaction Details', 'gatewaykit' ),
				'id'                   => __( 'ID', 'gatewaykit' ),
				'amount'               => __( 'Amount', 'gatewaykit' ),
				'status'               => __( 'Status', 'gatewaykit' ),
				'date'                 => __( 'Date', 'gatewaykit' ),
				'user_id'              => __( 'User ID', 'gatewaykit' ),
				'description'          => __( 'Description', 'gatewaykit' ),
				'form_data'            => __( 'Form Data', 'gatewaykit' ),
				'field'                => __( 'Field', 'gatewaykit' ),
				'value'                => __( 'Value', 'gatewaykit' ),
				'no_form_data'         => __( 'No form data available.', 'gatewaykit' ),
				'user_data'            => __( 'User Data', 'gatewaykit' ),
				'error'                => __( 'Error', 'gatewaykit' ),
				'unknown_error'        => __( 'Unknown error', 'gatewaykit' ),
				'ajax_error_occurred'  => __( 'AJAX error occurred.', 'gatewaykit' ),
				'default_currency'     => GatewayKit_Gateway_Manager::get_instance()->get_default_currency(),
				'transaction_url'      => esc_url( admin_url( 'admin.php?page=gatewaykit-transactions&transaction=' ) ),
				'merchant_id_required' => __( 'Merchant ID is required', 'gatewaykit' ),
				'invalid_api_key'      => __( 'Invalid API key format', 'gatewaykit' ),
				'enabled'              => __( 'Enabled', 'gatewaykit' ),
				'disabled'             => __( 'Disabled', 'gatewaykit' ),
				'copied'               => __( 'Copied!', 'gatewaykit' ),
				'notes'                => __( 'Notes', 'gatewaykit' ),
				'no_notes'             => __( 'No notes yet.', 'gatewaykit' ),
				'add_note_placeholder' => __( 'Add a note...', 'gatewaykit' ),
				'save_note'            => __( 'Save Note', 'gatewaykit' ),
				'saving'               => __( 'Saving...', 'gatewaykit' ),
				'unknown_user'         => __( 'Unknown', 'gatewaykit' ),
				'sending_test'         => __( 'Sending...', 'gatewaykit' ),
				'discount_code'        => __( 'Discount Code', 'gatewaykit' ),
				'discount_amount'      => __( 'Discount Amount', 'gatewaykit' ),
				'original_amount'      => __( 'Original Amount', 'gatewaykit' ),
			)
		);

		// Localize UI Kit script for translations.
		wp_localize_script(
			'gatewaykit-ui-kit',
			'gatewaykit_ui_vars',
			array(
				'copied' => __( 'Copied!', 'gatewaykit' ),
				'failed' => __( 'Failed', 'gatewaykit' ),
			)
		);

		// Logs page assets (only on gatewaykit-logs).
		$current_page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'gatewaykit-logs' === $current_page ) {
			wp_enqueue_style( 'gatewaykit-admin-logs', GATEWAYKIT_PLUGIN_URL . 'assets/css/admin-logs' . $suffix . '.css', array( 'gatewaykit-admin-styles' ), GATEWAYKIT_VERSION );
			wp_enqueue_script( 'gatewaykit-admin-logs', GATEWAYKIT_PLUGIN_URL . 'assets/js/admin-logs' . $suffix . '.js', array( 'jquery', 'gatewaykit-admin-scripts' ), GATEWAYKIT_VERSION, true );

			wp_localize_script(
				'gatewaykit-admin-logs',
				'gatewaykit_logs_vars',
				array(
					'none_selected' => __( 'Please select at least one log entry.', 'gatewaykit' ),
					'copying'       => __( 'Copying...', 'gatewaykit' ),
					/* translators: %s: number of copied log entries */
					'copied'        => __( 'Copied %s log entries to clipboard.', 'gatewaykit' ),
					'copy_failed'   => __( 'Failed to copy logs.', 'gatewaykit' ),
					'ajax_error'    => __( 'An error occurred. Please try again.', 'gatewaykit' ),
					'confirm_copy'  => __( 'Copy', 'gatewaykit' ),
					'confirm_clear' => __( 'Are you sure you want to delete ALL logs? This cannot be undone.', 'gatewaykit' ),
					'cleared'       => __( 'All logs have been cleared.', 'gatewaykit' ),
					'clear_failed'  => __( 'Failed to clear logs.', 'gatewaykit' ),
				)
			);
		}
	}

	/**
	 * Render an upgrade CTA page for locked Pro features.
	 */
	public static function render_pro_feature_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'gatewaykit' ) );
		}

		$min = defined( 'WP_DEBUG' ) && WP_DEBUG ? '' : '.min';
		wp_enqueue_style( 'gatewaykit-admin-styles', GATEWAYKIT_PLUGIN_URL . 'assets/css/admin' . $min . '.css', array(), GATEWAYKIT_VERSION );
		$brand_name = apply_filters( 'gatewaykit_brand', __( 'GatewayKit', 'gatewaykit' ) );
		?>
		<div class="wrap">
			<h1><?php /* translators: %s: Brand name. */ printf( esc_html__( '%s Pro Feature', 'gatewaykit' ), esc_html( $brand_name ) ); ?></h1>
			<div class="gatewaykit-upgrade-box">
				<h2><?php esc_html_e( 'This feature requires GatewayKit Pro', 'gatewaykit' ); ?></h2>
				<p><?php esc_html_e( 'Unlock advanced features including an interactive analytics dashboard with revenue charts, discount codes, recurring subscriptions, outgoing webhooks, white-label branding, CSV exports, refunds, and more.', 'gatewaykit' ); ?></p>
				<p>
					<a href="<?php echo esc_url( gatewaykit_get_upgrade_url() ); ?>" class="button button-primary button-hero" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'Get GatewayKit Pro →', 'gatewaykit' ); ?>
					</a>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Instance proxy for pro_feature_page.
	 */
	public function pro_feature_page() {
		self::render_pro_feature_page();
	}

	/**
	 * Register full admin menus tree.
	 *
	 * @param object|null $admin_settings     Admin settings instance.
	 * @param object|null $admin_discounts    Admin discounts instance.
	 * @param object|null $analytics_dashboard Analytics dashboard instance.
	 */
	public function register_all_menus( $admin_settings = null, $admin_discounts = null, $analytics_dashboard = null ) {
		if ( ! is_admin() ) {
			return;
		}

		// Ensure admin settings instance exists.
		if ( ! $admin_settings instanceof GatewayKit_Admin_Settings ) {
			$admin_settings = new GatewayKit_Admin_Settings();
		}
		if ( ! $this->settings_handler ) {
			$this->settings_handler = $admin_settings;
		}

		// Ensure admin discounts instance exists in pro builds.
		if ( class_exists( 'GatewayKit_Admin_Discounts' ) && ! $admin_discounts instanceof GatewayKit_Admin_Discounts ) {
			$admin_discounts = new GatewayKit_Admin_Discounts();
		}

		// Brand name helper — respects white-label renaming.
		$brand_name = apply_filters( 'gatewaykit_brand', __( 'GatewayKit', 'gatewaykit' ) );

		// Add main GatewayKit dashboard menu.
		$menu_title = __( 'GatewayKit', 'gatewaykit' );
		if ( class_exists( 'GatewayKit_WhiteLabel' ) ) {
			$menu_title = GatewayKit_WhiteLabel::get_instance()->brand( $menu_title );
		}
		$main_menu_result = add_menu_page(
			/* translators: %s: brand name */
			sprintf( __( '%s Dashboard', 'gatewaykit' ), $brand_name ),
			$menu_title,
			'manage_options',
			'gatewaykit',
			array( $admin_settings, 'dashboard_page' ),
			'dashicons-money-alt'
		);

		$pro_licensed = gatewaykit_is_pro_licensed();

		// --- Analytics ---
		if ( $pro_licensed && class_exists( 'GatewayKit_Analytics_Dashboard' ) ) {
			if ( ! $analytics_dashboard instanceof GatewayKit_Analytics_Dashboard ) {
				$analytics_dashboard = new GatewayKit_Analytics_Dashboard();
			}
			add_submenu_page(
				'gatewaykit',
				__( 'Analytics', 'gatewaykit' ),
				__( 'Analytics', 'gatewaykit' ),
				'manage_options',
				'gatewaykit-analytics',
				array( $analytics_dashboard, 'render_page' )
			);
		} else {
			add_submenu_page(
				'gatewaykit',
				__( 'Analytics', 'gatewaykit' ),
				__( 'Analytics (Pro)', 'gatewaykit' ),
				'manage_options',
				'gatewaykit-analytics',
				array( $this, 'pro_feature_page' )
			);
		}

		// --- Transactions ---
		if ( method_exists( $admin_settings, 'add_transactions_menu' ) ) {
			$admin_settings->add_transactions_menu();
		} else {
			$this->add_transactions_menu();
		}

		// --- Discounts ---
		if ( $pro_licensed && class_exists( 'GatewayKit_Admin_Discounts' ) ) {
			if ( ! $admin_discounts instanceof GatewayKit_Admin_Discounts ) {
				$admin_discounts = new GatewayKit_Admin_Discounts();
			}
			add_submenu_page(
				'gatewaykit',
				__( 'Discounts', 'gatewaykit' ),
				__( 'Discounts', 'gatewaykit' ),
				'manage_options',
				'gatewaykit-discounts',
				array( $admin_discounts, 'render_page' )
			);
		} else {
			add_submenu_page(
				'gatewaykit',
				__( 'Discounts', 'gatewaykit' ),
				__( 'Discounts (Pro)', 'gatewaykit' ),
				'manage_options',
				'gatewaykit-discounts',
				array( $this, 'pro_feature_page' )
			);
		}

		// --- Subscriptions ---
		if ( $pro_licensed && class_exists( 'GatewayKit_Admin_Subscriptions' ) ) {
			$subs_instance = new GatewayKit_Admin_Subscriptions();
			add_submenu_page(
				'gatewaykit',
				__( 'Subscriptions', 'gatewaykit' ),
				__( 'Subscriptions', 'gatewaykit' ),
				'manage_options',
				'gatewaykit-subscriptions',
				array( $subs_instance, 'render_page' )
			);
		} else {
			add_submenu_page(
				'gatewaykit',
				__( 'Subscriptions', 'gatewaykit' ),
				__( 'Subscriptions (Pro)', 'gatewaykit' ),
				'manage_options',
				'gatewaykit-subscriptions',
				array( $this, 'pro_feature_page' )
			);
		}

		// --- Payment Links ---
		if ( class_exists( 'GatewayKit_Payment_Links' ) ) {
			$pl = new GatewayKit_Payment_Links();
			$pl->add_menu();
		}

		// --- Settings, Logs, Usage Guide ---
		if ( method_exists( $admin_settings, 'add_admin_menu' ) ) {
			$admin_settings->add_admin_menu();
		} else {
			$this->add_admin_menu();
		}
	}
}
