<?php
/**
 * Admin Settings
 *
 * Handles plugin settings in WordPress admin
 *
 * @package GatewayKit
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin Settings Class
 */
class GatewayKit_Admin_Settings {

	/**
	 * Settings page slug
	 */
	const PAGE_SLUG = 'gatewaykit-settings';

	/**
	 * Constructor
	 */
	public function __construct() {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			GatewayKit_Logger::get_instance()->debug( 'GatewayKit_Admin_Settings instantiated' );
		}
		// Admin menus are registered by the main plugin class on admin_menu
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_init', array( $this, 'handle_transaction_exports' ) );
		add_action( 'admin_init', array( $this, 'handle_refund_action' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'current_screen', array( $this, 'register_screen_options' ) );
		add_action( 'wp_ajax_gatewaykit_get_form_data', array( $this, 'ajax_get_form_data' ) );
		add_action( 'wp_ajax_gatewaykit_get_error_details', array( $this, 'ajax_get_error_details' ) );
		add_action( 'wp_ajax_gatewaykit_copy_logs', array( $this, 'ajax_copy_logs' ) );
		add_action( 'wp_ajax_gatewaykit_export_logs', array( $this, 'ajax_export_logs' ) );
		add_action( 'wp_ajax_gatewaykit_test_gateway', array( $this, 'ajax_test_gateway' ) );
		add_action( 'wp_ajax_gatewaykit_get_transaction_notes', array( $this, 'ajax_get_transaction_notes' ) );
		add_action( 'wp_ajax_gatewaykit_add_transaction_note', array( $this, 'ajax_add_transaction_note' ) );
		add_action( 'wp_ajax_gatewaykit_test_webhook', array( $this, 'ajax_test_webhook' ) );
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
	 * Add admin menu
	 */
	public function add_admin_menu() {
		// Only add menu in admin context
		if ( ! is_admin() ) {
			return;
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			GatewayKit_Logger::get_instance()->debug( 'Adding admin menu at hook: ' . current_filter() . ', priority: ' . has_action( 'admin_menu', array( $this, 'add_admin_menu' ) ) );
		}

		// NOTE: the Transactions submenu is registered separately via
		// add_transactions_menu() so GatewayKit::register_admin_menus() can
		// control the exact admin-menu ordering.

		// Submenu for settings
		add_submenu_page(
			'gatewaykit',
			__( 'Settings', 'gatewaykit' ),
			__( 'Settings', 'gatewaykit' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'settings_page' )
		);

		// Submenu for logs
		add_submenu_page(
			'gatewaykit',
			__( 'Logs', 'gatewaykit' ),
			__( 'Logs', 'gatewaykit' ),
			'manage_options',
			'gatewaykit-logs',
			array( $this, 'logs_page' )
		);

		// Submenu for usage guide
		add_submenu_page(
			'gatewaykit',
			__( 'Usage Guide', 'gatewaykit' ),
			__( 'Usage Guide', 'gatewaykit' ),
			'manage_options',
			'gatewaykit-usage-guide',
			array( $this, 'usage_guide_page' )
		);

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			GatewayKit_Logger::get_instance()->debug( 'Admin menu added successfully' );
		}
	}

	/**
	 * Register the Transactions submenu separately so callers control ordering.
	 */
	public function add_transactions_menu() {
		if ( ! is_admin() ) {
			return;
		}

		add_submenu_page(
			'gatewaykit',
			__( 'Transactions', 'gatewaykit' ),
			__( 'Transactions', 'gatewaykit' ),
			'manage_options',
			'gatewaykit-transactions',
			array( $this, 'transactions_page' )
		);
	}

	/**
	 * Register settings
	 */
	public function register_settings() {
		// General settings — each registered with a sanitization callback.
		register_setting( 'gatewaykit_settings', 'gatewaykit_currency', array( 'sanitize_callback' => array( $this, 'sanitize_currency_setting' ) ) );
		register_setting( 'gatewaykit_settings', 'gatewaykit_log_level', array( 'sanitize_callback' => array( $this, 'sanitize_log_level_setting' ) ) );
		register_setting( 'gatewaykit_settings', 'gatewaykit_print_mode', array( 'sanitize_callback' => array( $this, 'sanitize_print_mode_setting' ) ) );
		register_setting( 'gatewaykit_settings', 'gatewaykit_rate_limit_enabled', array( 'sanitize_callback' => array( $this, 'sanitize_bool_setting' ) ) );
		register_setting( 'gatewaykit_settings', 'gatewaykit_elementor_action_rate_limit', array( 'sanitize_callback' => 'absint' ) );
		register_setting( 'gatewaykit_settings', 'gatewaykit_admin_ajax_rate_limit', array( 'sanitize_callback' => 'absint' ) );
		register_setting( 'gatewaykit_settings', 'gatewaykit_callback_rate_limit', array( 'sanitize_callback' => 'absint' ) );
		register_setting( 'gatewaykit_settings', 'gatewaykit_trusted_proxies', array( 'sanitize_callback' => array( $this, 'sanitize_trusted_proxies' ) ) );

		// Webhook settings (Pro only).
		if ( defined( 'GATEWAYKIT_PRO_VERSION' ) ) {
			register_setting( 'gatewaykit_settings', 'gatewaykit_webhook_urls', array( 'sanitize_callback' => array( $this, 'sanitize_webhook_urls' ) ) );
			register_setting( 'gatewaykit_settings', 'gatewaykit_webhook_events', array( 'sanitize_callback' => array( $this, 'sanitize_webhook_events' ) ) );
			register_setting( 'gatewaykit_settings', 'gatewaykit_webhook_secret', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		}

		// Email receipt settings.
		register_setting( 'gatewaykit_settings', 'gatewaykit_receipt_email_from_name', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'gatewaykit_settings', 'gatewaykit_receipt_email_from_address', array( 'sanitize_callback' => 'sanitize_email' ) );
		register_setting( 'gatewaykit_settings', 'gatewaykit_receipt_email_subject', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'gatewaykit_settings', 'gatewaykit_receipt_email_attach_pdf', array( 'sanitize_callback' => array( $this, 'sanitize_bool_setting' ) ) );

		// Gateway settings
		$gateway_manager = GatewayKit_Gateway_Manager::get_instance();
		$gateways        = $gateway_manager->get_registered_gateways();

		foreach ( $gateways as $gateway_id => $class_name ) {
			register_setting( 'gatewaykit_gateway_' . $gateway_id, 'gatewaykit_' . $gateway_id . '_settings', array( 'sanitize_callback' => array( $this, 'sanitize_gateway_settings_array' ) ) );
		}
	}

	/**
	 * Sanitize the currency option against the available currencies list.
	 *
	 * @param mixed $value Raw input.
	 * @return string
	 */
	public function sanitize_currency_setting( $value ) {
		$value     = strtoupper( sanitize_key( $value ) );
		$available = GatewayKit_Gateway_Manager::get_instance()->get_available_currencies();
		return isset( $available[ $value ] ) ? $value : '';
	}

	/**
	 * Sanitize the log level option.
	 *
	 * @param mixed $value Raw input.
	 * @return string
	 */
	public function sanitize_log_level_setting( $value ) {
		$value = sanitize_key( $value );
		return in_array( $value, array( 'error', 'warning', 'info', 'debug' ), true ) ? $value : 'info';
	}

	/**
	 * Sanitize the print mode option.
	 *
	 * @param mixed $value Raw input.
	 * @return string
	 */
	public function sanitize_print_mode_setting( $value ) {
		$value = sanitize_key( $value );
		return in_array( $value, array( 'full_page', 'receipt_only' ), true ) ? $value : 'receipt_only';
	}

	/**
	 * Sanitize a boolean (checkbox) option to '1' or '0'.
	 *
	 * @param mixed $value Raw input.
	 * @return string
	 */
	public function sanitize_bool_setting( $value ) {
		return empty( $value ) ? '0' : '1';
	}

	/**
	 * Sanitize webhook URLs (one per line, esc_url_raw).
	 *
	 * @param mixed $value Raw textarea value.
	 * @return string
	 */
	public function sanitize_webhook_urls( $value ) {
		$lines = array_filter( array_map( 'trim', explode( "\n", (string) $value ) ) );
		$lines = array_filter( array_map( 'esc_url_raw', $lines ) );
		return implode( "\n", $lines );
	}

	/**
	 * Sanitize the trusted-proxy CIDR list (one CIDR or single IP per line).
	 *
	 * Each non-empty line is validated as either a single IP (IPv4/IPv6) or a
	 * CIDR. Invalid lines are dropped so GatewayKit_IP_Helper never sees a
	 * malformed entry. (F12)
	 *
	 * @param mixed $value Raw textarea value.
	 * @return string Newline-separated, validated CIDRs/IPs.
	 */
	public function sanitize_trusted_proxies( $value ) {
		$clean = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $value ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}

			if ( false !== strpos( $line, '/' ) ) {
				list( $ip, $mask ) = explode( '/', $line, 2 );
				$mask              = (int) $mask;
				if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					continue;
				}
				$max_mask = false !== strpos( $ip, ':' ) ? 128 : 32;
				if ( $mask <= 0 || $mask > $max_mask ) {
					continue;
				}
				$clean[] = $ip . '/' . $mask;
			} elseif ( filter_var( $line, FILTER_VALIDATE_IP ) ) {
				$clean[] = $line;
			}
		}

		return implode( "\n", $clean );
	}

	/**
	 * Sanitize webhook events (whitelist of known events).
	 *
	 * @param mixed $value Raw input (array or string).
	 * @return array
	 */
	public function sanitize_webhook_events( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$allowed = array( 'payment_completed', 'payment_failed' );
		return array_values( array_intersect( $value, $allowed ) );
	}

	/**
	 * Sanitize a gateway settings array (key/value pairs).
	 *
	 * @param mixed $settings Raw input.
	 * @return array
	 */
	public function sanitize_gateway_settings_array( $settings ) {
		if ( ! is_array( $settings ) ) {
			return array();
		}

		$sanitized        = array();
		$sensitive_fields = array( 'client_secret', 'api_key', 'secret_key', 'password', 'access_token', 'webhook_secret' );

		foreach ( $settings as $key => $value ) {
			$key_clean = sanitize_key( $key );

			if ( in_array( $key_clean, $sensitive_fields, true ) ) {
				// Sensitive fields: remove null bytes and trim, preserve special characters
				// that sanitize_text_field would strip (e.g. base64 secrets with +/=).
				$clean                   = str_replace( chr( 0 ), '', (string) $value );
				$sanitized[ $key_clean ] = trim( wp_strip_all_tags( $clean ) );
			} elseif ( is_array( $value ) ) {
				$sanitized[ $key_clean ] = array_map( 'sanitize_text_field', $value );
			} else {
				$sanitized[ $key_clean ] = sanitize_text_field( $value );
			}
		}

		return $sanitized;
	}

	/**
	 * Handle transaction export requests before any output
	 */
	public function handle_transaction_exports() {
		// Admin page routing; protected by capability checks below, no nonce needed for reading the page slug.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['page'] ) || sanitize_key( wp_unslash( $_GET['page'] ) ) !== 'gatewaykit-transactions' ) {
			return;
		}
		// phpcs:enable

		// Handle export all transactions
		// Handle actions coming from our explicit buttons (gatewaykit_action)
		if ( isset( $_POST['gatewaykit_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$action = sanitize_key( wp_unslash( $_POST['gatewaykit_action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			// Handle Export All Transactions
			if ( $action === 'export_all' ) {
				if ( ! isset( $_POST['gatewaykit_export_all_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['gatewaykit_export_all_nonce'] ) ), 'gatewaykit_export_all_transactions_nonce' ) ) {
					wp_die( esc_html__( 'Security check failed.', 'gatewaykit' ) );
				}

				if ( ! current_user_can( 'manage_options' ) ) {
					wp_die( esc_html__( 'You do not have permission to perform this action.', 'gatewaykit' ) );
				}

				// Defense-in-depth license gate. The "Export All" button is
				// rendered disabled on Lite (ARCH-022) and the bulk action
				// is hidden from the dropdown, but a forged POST could still
				// reach this endpoint — never stream the CSV without Pro.
				if ( ! gatewaykit_is_pro_licensed() ) {
					wp_die(
						sprintf(
							/* translators: %s: upgrade URL */
							esc_html__( 'CSV export is a GatewayKit Pro feature. Upgrade to Pro to unlock it: %s', 'gatewaykit' ),
							esc_url( gatewaykit_get_upgrade_url() )
						)
					);
				}

				$transaction_table = new GatewayKit_Transaction_List_Table();
				$transaction_table->export_all_transactions();
				exit;
			}
		}

		// Handle notices after actions
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['deleted'] ) ) {
			$deleted_count = absint( wp_unslash( $_GET['deleted'] ) );
			if ( $deleted_count > 0 ) {
				add_action(
					'admin_notices',
					function () use ( $deleted_count ) {
						/* translators: %s: number of deleted transactions */
						echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( _n( '%s transaction deleted.', '%s transactions deleted.', $deleted_count, 'gatewaykit' ), $deleted_count ) ) . '</p></div>';
					}
				);
			}
		}

		if ( isset( $_GET['gatewaykit_notice'] ) && sanitize_key( wp_unslash( $_GET['gatewaykit_notice'] ) ) === 'no_transactions_selected_for_export' ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'No transactions selected for export.', 'gatewaykit' ) . '</p></div>';
				}
			);
		}

		if ( isset( $_GET['gatewaykit_notice'] ) ) {
			$notice_type = sanitize_key( wp_unslash( $_GET['gatewaykit_notice'] ) );

			if ( 'refund_success' === $notice_type ) {
				add_action(
					'admin_notices',
					function () {
						echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Refund processed successfully.', 'gatewaykit' ) . '</p></div>';
					}
				);
			}

			if ( 'refund_failed' === $notice_type ) {
				$message = isset( $_GET['gatewaykit_message'] ) ? sanitize_text_field( wp_unslash( $_GET['gatewaykit_message'] ) ) : '';
				add_action(
					'admin_notices',
					function () use ( $message ) {
						echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Refund failed: ', 'gatewaykit' ) . esc_html( $message ) . '</p></div>';
					}
				);
			}
		}
		// phpcs:enable
	}

	/**
	 * Handle single-row refund action before any output.
	 *
	 * Must run on admin_init so wp_safe_redirect() can send headers.
	 * Moved out of WP_List_Table::prepare_items() to avoid "headers already sent".
	 */
	public function handle_refund_action() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['page'] ) || 'gatewaykit-transactions' !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
			return;
		}
		if ( ! isset( $_GET['action'] ) || 'refund' !== sanitize_key( wp_unslash( $_GET['action'] ) ) ) {
			return;
		}
		// phpcs:enable

		// Defense-in-depth: refund is a Pro feature.
		if ( ! gatewaykit_is_pro_licensed() ) {
			wp_die(
				sprintf(
					/* translators: %s: upgrade URL */
					esc_html__( 'Refunds are a GatewayKit Pro feature. Upgrade to Pro to unlock it: %s', 'gatewaykit' ),
					esc_url( gatewaykit_get_upgrade_url() )
				)
			);
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'gatewaykit' ) );
		}

		$transaction_id = isset( $_GET['transaction_id'] ) ? absint( wp_unslash( $_GET['transaction_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! $transaction_id ) {
			wp_die( esc_html__( 'Invalid transaction ID.', 'gatewaykit' ) );
		}

		// Verify nonce.
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'gatewaykit_refund_' . $transaction_id ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			wp_die( esc_html__( 'Security check failed.', 'gatewaykit' ) );
		}

		$refund_service = GatewayKit_Refund_Service::get_instance();
		$result         = $refund_service->process_refund( $transaction_id );

		if ( is_wp_error( $result ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'               => 'gatewaykit-transactions',
						'gatewaykit_notice'  => 'refund_failed',
						'gatewaykit_message' => rawurlencode( $result->get_error_message() ),
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'              => 'gatewaykit-transactions',
					'gatewaykit_notice' => 'refund_success',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Dashboard page
	 */
	public function dashboard_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'gatewaykit' ) );
		}

		// Use minified assets in production, full assets in debug mode
		$suffix = defined( 'WP_DEBUG' ) && WP_DEBUG ? '' : '.min';

		// Enqueue required styles
		wp_enqueue_style( 'gatewaykit-admin-styles', GATEWAYKIT_PLUGIN_URL . 'assets/css/admin' . $suffix . '.css', array(), GATEWAYKIT_VERSION );
		wp_enqueue_style( 'gatewaykit-validation-states', GATEWAYKIT_PLUGIN_URL . 'assets/css/validation-states.css', array( 'gatewaykit-admin-styles' ), GATEWAYKIT_VERSION );

		// Create dashboard widgets instance
		$dashboard_widgets = new GatewayKit_Dashboard_Widgets();
		$brand_name        = apply_filters( 'gatewaykit_brand', __( 'GatewayKit', 'gatewaykit' ) );

		?>
		<div class="wrap">
			<h1><?php /* translators: %s: Brand name. */ printf( esc_html__( '%s Dashboard', 'gatewaykit' ), esc_html( $brand_name ) ); ?></h1>

			<div class="gatewaykit-dashboard-container">
					<!-- Quick Actions (full width, top) -->
					<div class="gatewaykit-dashboard-widget">
						<div class="gatewaykit-widget-header">
							<h2><?php esc_html_e( 'Quick Actions', 'gatewaykit' ); ?></h2>
						</div>
						<div class="gatewaykit-widget-content">
							<div class="gatewaykit-quick-actions">
								<?php if ( gatewaykit_is_pro_licensed() ) : ?>
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=gatewaykit-analytics' ) ); ?>" class="button button-primary">
										<?php esc_html_e( 'View Analytics', 'gatewaykit' ); ?>
									</a>
								<?php endif; ?>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=gatewaykit-transactions' ) ); ?>" class="button button-primary">
									<?php esc_html_e( 'View All Transactions', 'gatewaykit' ); ?>
								</a>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=gatewaykit-settings' ) ); ?>" class="button">
									<?php esc_html_e( 'Settings', 'gatewaykit' ); ?>
								</a>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=gatewaykit-logs' ) ); ?>" class="button">
									<?php esc_html_e( 'View Logs', 'gatewaykit' ); ?>
								</a>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=gatewaykit-usage-guide' ) ); ?>" class="button">
									<?php esc_html_e( 'Usage Guide', 'gatewaykit' ); ?>
								</a>
							</div>
						</div>
					</div>

					<!-- Two-column grid for remaining widgets -->
					<div class="gatewaykit-dashboard-grid">
						<!-- Payment Overview Widget -->
						<div class="gatewaykit-dashboard-widget">
							<div class="gatewaykit-widget-header">
								<h2><?php esc_html_e( 'Payment Overview', 'gatewaykit' ); ?></h2>
							</div>
							<div class="gatewaykit-widget-content">
								<?php $dashboard_widgets->payment_overview_widget(); ?>
							</div>
						</div>

						<!-- Recent Transactions Widget -->
						<div class="gatewaykit-dashboard-widget">
							<div class="gatewaykit-widget-header">
								<h2><?php esc_html_e( 'Recent Transactions', 'gatewaykit' ); ?></h2>
							</div>
							<div class="gatewaykit-widget-content">
								<?php $dashboard_widgets->recent_transactions_widget(); ?>
							</div>
						</div>

						<!-- Gateway Performance Widget -->
						<div class="gatewaykit-dashboard-widget">
							<div class="gatewaykit-widget-header">
								<h2><?php esc_html_e( 'Gateway Performance', 'gatewaykit' ); ?></h2>
							</div>
							<div class="gatewaykit-widget-content">
								<?php $dashboard_widgets->gateway_performance_widget(); ?>
							</div>
						</div>
					</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Settings page
	 */
	public function settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'gatewaykit' ) );
		}

		// Handle WL "email me the link" request.
		if ( isset( $_GET['gatewaykit_wl_email'] ) && class_exists( 'GatewayKit_WhiteLabel' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['gatewaykit_wl_email_nonce'] ?? '' ) ), 'gatewaykit_wl_email_nonce' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$secret    = get_option( 'gatewaykit_whitelabel_secret', '' );
				$magic_url = add_query_arg( 'gatewaykit_wl', $secret, home_url( '/' ) );
				$to        = wp_get_current_user()->user_email;
				$subject   = __( 'GatewayKit White Label Settings Link', 'gatewaykit' );
				$message   = sprintf(
					/* translators: %1$s: newline, %2$s: magic link URL */
					__( 'Use the following link to access the White Label settings for 1 hour:%1$s%2$s', 'gatewaykit' ),
					"\n\n",
					$magic_url
				);
				wp_mail( $to, $subject, $message );
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Magic link sent to your email.', 'gatewaykit' ) . '</p></div>';
				GatewayKit_Logger::get_instance()->info( 'White-label magic link emailed', array( 'user_id' => get_current_user_id() ) );
			}
		}

		// Handle form submission
		if ( isset( $_POST['gatewaykit_settings_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['gatewaykit_settings_nonce'] ) ), 'gatewaykit_settings_save' ) ) {
			$this->save_settings();
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved successfully.', 'gatewaykit' ) . '</p></div>';
		} elseif ( isset( $_POST['gatewaykit_settings_nonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Form validation error. Please refresh the page and try again.', 'gatewaykit' ) . '</p></div>';
		}

		$brand_name = apply_filters( 'gatewaykit_brand', __( 'GatewayKit', 'gatewaykit' ) );
		?>
		<div class="wrap">
			<h1><?php /* translators: %s: Brand name. */ printf( esc_html__( '%s Settings', 'gatewaykit' ), esc_html( $brand_name ) ); ?></h1>
			
			<div class="gatewaykit-settings-header">
				<p class="gatewaykit-settings-description"><?php esc_html_e( 'Configure your payment gateways and general settings.', 'gatewaykit' ); ?></p>
				<a href="<?php echo esc_url( $this->get_docs_url() ); ?>" target="_blank" class="button button-secondary">
					<?php esc_html_e( 'View Documentation', 'gatewaykit' ); ?>
				</a>
			</div>

			<?php
			$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			// The White Label tab renders its OWN options.php form, so it must
			// stay OUTSIDE the main settings form. Nested forms are invalid
			// HTML — the browser folds the inner fields into the outer form,
			// whose handler never saves gatewaykit_whitelabel_* options
			// (ERR-041). The hidden `tab` field lets save_settings() know which
			// tab was submitted (ERR-043).
			if ( 'whitelabel' !== $active_tab ) :
				?>
			<form method="post" action="">
				<?php wp_nonce_field( 'gatewaykit_settings_save', 'gatewaykit_settings_nonce' ); ?>
				<input type="hidden" name="tab" value="<?php echo esc_attr( $active_tab ); ?>" />

			<div class="gatewaykit-settings-container">
				<?php $this->render_settings_tabs(); ?>
			</div>

				<?php submit_button( __( 'Save Settings', 'gatewaykit' ) ); ?>
			</form>
			<?php else : ?>
			<div class="gatewaykit-settings-container">
				<?php $this->render_settings_tabs(); ?>
			</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render settings tabs
	 */
	private function render_settings_tabs() {
		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Check if the White Label class is available (the separate "White Label"
		// menu item handles discovery — the tab should always be accessible).
		$wl_available = class_exists( 'GatewayKit_WhiteLabel' );

		// WL mode status + magic-link unlock state.
		$wl_enabled  = false;
		$wl_unlocked = false;
		if ( $wl_available ) {
			$wl_enabled  = GatewayKit_WhiteLabel::get_instance()->is_enabled();
			$user_id     = get_current_user_id();
			$wl_unlocked = (bool) get_transient( 'gatewaykit_wl_unlocked_' . $user_id );
		}
		// Settings are editable when WL is OFF (first-time config) OR when unlocked via magic link.
		$wl_can_edit = $wl_available && ( ! $wl_enabled || $wl_unlocked );
		?>
		<div class="gatewaykit-settings-tabs">
			<nav class="gatewaykit-settings-tabs-nav">
				<a href="?page=<?php echo esc_attr( self::PAGE_SLUG ); ?>&tab=general" class="gatewaykit-settings-tab-link <?php echo $active_tab === 'general' ? 'active' : ''; ?>">
					<?php esc_html_e( 'General Settings', 'gatewaykit' ); ?>
				</a>
				<a href="?page=<?php echo esc_attr( self::PAGE_SLUG ); ?>&tab=email" class="gatewaykit-settings-tab-link <?php echo $active_tab === 'email' ? 'active' : ''; ?>">
					<?php esc_html_e( 'Email Settings', 'gatewaykit' ); ?>
				</a>
				<a href="?page=<?php echo esc_attr( self::PAGE_SLUG ); ?>&tab=gateways" class="gatewaykit-settings-tab-link <?php echo $active_tab === 'gateways' ? 'active' : ''; ?>">
					<?php esc_html_e( 'Gateway Settings', 'gatewaykit' ); ?>
				</a>
				<?php if ( defined( 'GATEWAYKIT_PRO_VERSION' ) ) : ?>
					<a href="?page=<?php echo esc_attr( self::PAGE_SLUG ); ?>&tab=webhooks" class="gatewaykit-settings-tab-link <?php echo $active_tab === 'webhooks' ? 'active' : ''; ?>">
						<?php esc_html_e( 'Webhooks', 'gatewaykit' ); ?>
					</a>
				<?php endif; ?>
				<a href="?page=<?php echo esc_attr( self::PAGE_SLUG ); ?>&tab=tools" class="gatewaykit-settings-tab-link <?php echo $active_tab === 'tools' ? 'active' : ''; ?>">
					<?php esc_html_e( 'Database', 'gatewaykit' ); ?>
				</a>
			<?php if ( $wl_available && $wl_can_edit ) : ?>
				<a href="?page=<?php echo esc_attr( self::PAGE_SLUG ); ?>&tab=whitelabel" class="gatewaykit-settings-tab-link <?php echo $active_tab === 'whitelabel' ? 'active' : ''; ?>">
					<?php esc_html_e( 'White Label', 'gatewaykit' ); ?>
				</a>
			<?php endif; ?>
			</nav>

			<div class="gatewaykit-settings-tabs-content">
				<?php if ( $active_tab === 'general' ) : ?>
					<?php $this->render_general_settings(); ?>
				<?php elseif ( $active_tab === 'email' ) : ?>
					<?php $this->render_email_settings(); ?>
				<?php elseif ( $active_tab === 'tools' ) : ?>
					<?php $this->render_database_tools(); ?>
				<?php elseif ( $active_tab === 'webhooks' && defined( 'GATEWAYKIT_PRO_VERSION' ) ) : ?>
					<?php $this->render_webhooks_settings(); ?>
				<?php elseif ( $active_tab === 'whitelabel' && $wl_available ) : ?>
					<?php if ( $wl_can_edit ) : ?>
						<?php $this->render_whitelabel_settings(); ?>
					<?php else : ?>
						<div class="gatewaykit-settings-section">
							<h2><?php esc_html_e( 'White Label', 'gatewaykit' ); ?></h2>
							<p><?php esc_html_e( 'White Label mode is active, so these settings are hidden. Use the magic link sent to your email to re-access them temporarily.', 'gatewaykit' ); ?></p>
						</div>
					<?php endif; ?>
				<?php else : ?>
					<?php $this->render_gateway_settings(); ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render general settings
	 */
	private function render_general_settings() {
		?>
		<div class="gatewaykit-settings-section">
			<h2><?php esc_html_e( 'General Settings', 'gatewaykit' ); ?></h2>

			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Currency', 'gatewaykit' ); ?></th>
					<td>
						<?php
						$gm               = GatewayKit_Gateway_Manager::get_instance();
						$currencies       = $gm->get_available_currencies();
						$default_currency = $gm->get_default_currency();
						$current_currency = strtoupper( get_option( 'gatewaykit_currency', $default_currency ) );
						?>
						<select name="gatewaykit_currency">
							<?php foreach ( $currencies as $code => $label ) : ?>
								<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $current_currency, $code ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Default currency for payments.', 'gatewaykit' ); ?></p>
					</td>
				</tr>


				<tr>
						<th scope="row"><?php esc_html_e( 'Log Level', 'gatewaykit' ); ?></th>
						<td>
							<select name="gatewaykit_log_level">
								<option value="error" <?php selected( get_option( 'gatewaykit_log_level', 'info' ), 'error' ); ?>><?php esc_html_e( 'Error', 'gatewaykit' ); ?></option>
								<option value="warning" <?php selected( get_option( 'gatewaykit_log_level', 'info' ), 'warning' ); ?>><?php esc_html_e( 'Warning', 'gatewaykit' ); ?></option>
								<option value="info" <?php selected( get_option( 'gatewaykit_log_level', 'info' ), 'info' ); ?>><?php esc_html_e( 'Info', 'gatewaykit' ); ?></option>
								<option value="debug" <?php selected( get_option( 'gatewaykit_log_level', 'info' ), 'debug' ); ?>><?php esc_html_e( 'Debug', 'gatewaykit' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Minimum level of logs to record.', 'gatewaykit' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Print Mode', 'gatewaykit' ); ?></th>
						<td>
							<select name="gatewaykit_print_mode">
								<option value="full_page" <?php selected( get_option( 'gatewaykit_print_mode', 'receipt_only' ), 'full_page' ); ?>><?php esc_html_e( 'Print Full Page', 'gatewaykit' ); ?></option>
								<option value="receipt_only" <?php selected( get_option( 'gatewaykit_print_mode', 'receipt_only' ), 'receipt_only' ); ?>><?php esc_html_e( 'Print Receipt Only', 'gatewaykit' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Choose what should be printed when clicking the print button on payment result page.', 'gatewaykit' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Enable Rate Limiting', 'gatewaykit' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="gatewaykit_rate_limit_enabled" value="1" <?php checked( get_option( 'gatewaykit_rate_limit_enabled', false ), '1' ); ?> />
								<?php esc_html_e( 'Enable rate limiting for sensitive endpoints', 'gatewaykit' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Protect against abuse by limiting requests from the same IP address.', 'gatewaykit' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Elementor Action Rate Limit', 'gatewaykit' ); ?></th>
						<td>
							<input type="number" name="gatewaykit_elementor_action_rate_limit" value="<?php echo esc_attr( get_option( 'gatewaykit_elementor_action_rate_limit', '10' ) ); ?>" min="1" max="100" />
							<p class="description"><?php esc_html_e( 'Maximum requests per IP per 15 minutes for payment form submissions.', 'gatewaykit' ); ?></p>
						</td>
					</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'Admin AJAX Rate Limit', 'gatewaykit' ); ?></th>
					<td>
						<input type="number" name="gatewaykit_admin_ajax_rate_limit" value="<?php echo esc_attr( get_option( 'gatewaykit_admin_ajax_rate_limit', '10' ) ); ?>" min="1" max="100" />
						<p class="description"><?php esc_html_e( 'Maximum requests per IP per 15 minutes for admin AJAX calls.', 'gatewaykit' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'Trusted Proxy CIDRs', 'gatewaykit' ); ?></th>
					<td>
						<textarea name="gatewaykit_trusted_proxies" rows="4" cols="60" class="large-text" placeholder="e.g. 173.245.48.0/20"><?php echo esc_textarea( get_option( 'gatewaykit_trusted_proxies', '' ) ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Forwarded headers (X-Forwarded-For, CF-Connecting-IP, …) are only honoured when the incoming connection comes from one of these CIDRs. Leave empty to always trust REMOTE_ADDR — recommended unless the site is behind Cloudflare or a known reverse proxy.', 'gatewaykit' ); ?></p>
					</td>
				</tr>


		</table>
		</div>
		<?php
	}

	/**
	 * Render outgoing webhooks settings (Pro only).
	 */
	private function render_webhooks_settings() {
		$webhook_events = get_option( 'gatewaykit_webhook_events', array() );
		?>
		<div class="gatewaykit-settings-section">
			<h2><?php esc_html_e( 'Outgoing Webhooks', 'gatewaykit' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Send payment events to Zapier, Make, N8N, or any webhook receiver.', 'gatewaykit' ); ?></p>
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Webhook URLs', 'gatewaykit' ); ?></th>
					<td>
						<textarea name="gatewaykit_webhook_urls" rows="4" cols="60" class="large-text"><?php echo esc_textarea( get_option( 'gatewaykit_webhook_urls', '' ) ); ?></textarea>
						<p class="description"><?php esc_html_e( 'One URL per line. Each enabled event will POST a JSON payload to all URLs.', 'gatewaykit' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Events', 'gatewaykit' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="gatewaykit_webhook_events[]" value="payment_completed" <?php checked( in_array( 'payment_completed', $webhook_events, true ) ); ?> />
							<?php esc_html_e( 'Payment Completed', 'gatewaykit' ); ?>
						</label><br/>
						<label>
							<input type="checkbox" name="gatewaykit_webhook_events[]" value="payment_failed" <?php checked( in_array( 'payment_failed', $webhook_events, true ) ); ?> />
							<?php esc_html_e( 'Payment Failed', 'gatewaykit' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Select which events trigger a webhook delivery.', 'gatewaykit' ); ?></p>
					</td>
				</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Secret Key', 'gatewaykit' ); ?></th>
						<td>
							<input type="password" name="gatewaykit_webhook_secret" value="" class="regular-text" placeholder="<?php esc_attr_e( 'Leave blank to keep the current secret', 'gatewaykit' ); ?>" autocomplete="new-password" />
							<p class="description"><?php esc_html_e( 'Optional. When set, each delivery includes an X-GatewayKit-Signature header (HMAC-SHA256) for verification. The stored secret is never shown; leave blank to keep the existing one.', 'gatewaykit' ); ?></p>
						</td>
					</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Test Webhook', 'gatewaykit' ); ?></th>
					<td>
						<button type="button" class="button button-secondary gatewaykit-test-webhook">
							<?php esc_html_e( 'Send Test', 'gatewaykit' ); ?>
						</button>
						<span class="description"><?php esc_html_e( 'Send a test payload to all configured URLs to verify your setup.', 'gatewaykit' ); ?></span>
						<div class="gatewaykit-test-webhook-result" style="display:none; margin-top:10px;"></div>
					</td>
				</tr>
			</table>
		</div>
		<?php
	}

		/**
		 * Render email settings
		 */
	private function render_email_settings() {
		?>
			<div class="gatewaykit-settings-section">
				<h2><?php esc_html_e( 'Customer Email Receipt', 'gatewaykit' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Default settings for customer payment receipt emails. Per-form toggles are in the Elementor form action settings.', 'gatewaykit' ); ?></p>
				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'From Name', 'gatewaykit' ); ?></th>
						<td>
							<input type="text" name="gatewaykit_receipt_email_from_name" value="<?php echo esc_attr( get_option( 'gatewaykit_receipt_email_from_name', get_bloginfo( 'name' ) ) ); ?>" class="regular-text" />
							<p class="description"><?php esc_html_e( 'Name shown as the sender. Defaults to site name.', 'gatewaykit' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'From Email', 'gatewaykit' ); ?></th>
						<td>
							<input type="email" name="gatewaykit_receipt_email_from_address" value="<?php echo esc_attr( get_option( 'gatewaykit_receipt_email_from_address', get_option( 'admin_email' ) ) ); ?>" class="regular-text" />
							<p class="description"><?php esc_html_e( 'Sender email address. Defaults to admin email.', 'gatewaykit' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Subject Template', 'gatewaykit' ); ?></th>
						<td>
							<input type="text" name="gatewaykit_receipt_email_subject" value="<?php echo esc_attr( get_option( 'gatewaykit_receipt_email_subject', '' ) ); ?>" class="large-text" placeholder="<?php esc_attr_e( 'Payment Receipt from {site_name}', 'gatewaykit' ); ?>" />
							<p class="description"><?php esc_html_e( 'Placeholders: {site_name}, {amount}, {receipt_code}.', 'gatewaykit' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Attach PDF Receipt', 'gatewaykit' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="gatewaykit_receipt_email_attach_pdf" value="1" <?php checked( get_option( 'gatewaykit_receipt_email_attach_pdf', '1' ), '1' ); ?> />
							<?php esc_html_e( 'Attach a PDF receipt to the confirmation email.', 'gatewaykit' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'When enabled, a professionally formatted PDF receipt will be generated and attached to the customer receipt email.', 'gatewaykit' ); ?></p>
						</td>
					</tr>
				</table>
			</div>
			<?php
	}

		/**
		 * Render white-label settings (only reachable via magic link).
		 */
	private function render_whitelabel_settings() {
		$enabled   = get_option( 'gatewaykit_whitelabel_enabled', '0' );
		$name      = get_option( 'gatewaykit_whitelabel_name', '' );
		$desc      = get_option( 'gatewaykit_whitelabel_description', '' );
		$secret    = get_option( 'gatewaykit_whitelabel_secret', '' );
		$magic_url = add_query_arg( 'gatewaykit_wl', $secret, home_url( '/' ) );
		?>
		<div class="gatewaykit-settings-section">
			<h2><?php esc_html_e( 'White Label', 'gatewaykit' ); ?></h2>
			<p><?php esc_html_e( 'Rename the plugin in the WordPress admin. After saving, this tab will be hidden. Use the magic link below to re-access these settings.', 'gatewaykit' ); ?></p>

			<form method="post" action="options.php">
				<?php settings_fields( 'gatewaykit_whitelabel' ); ?>
				<input type="hidden" name="gatewaykit_whitelabel_secret" value="<?php echo esc_attr( $secret ); ?>" />
				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable White Label', 'gatewaykit' ); ?></th>
						<td>
							<input type="checkbox" name="gatewaykit_whitelabel_enabled" value="1" <?php checked( $enabled, '1' ); ?> />
							<p class="description"><?php esc_html_e( 'When enabled, the plugin name and description will be replaced with your custom values.', 'gatewaykit' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Custom Name', 'gatewaykit' ); ?></th>
						<td>
							<input type="text" name="gatewaykit_whitelabel_name" value="<?php echo esc_attr( $name ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'My Payment Gateway', 'gatewaykit' ); ?>" />
							<p class="description"><?php esc_html_e( 'The name shown in the admin menu, dashboard, and plugins list.', 'gatewaykit' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Custom Description', 'gatewaykit' ); ?></th>
						<td>
							<textarea name="gatewaykit_whitelabel_description" class="large-text" rows="3" placeholder="<?php esc_attr_e( 'Optional description for the plugins list.', 'gatewaykit' ); ?>"><?php echo esc_textarea( $desc ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Optional. Shown in the plugins list.', 'gatewaykit' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Magic Link', 'gatewaykit' ); ?></th>
						<td>
							<input type="text" readonly value="<?php echo esc_url( $magic_url ); ?>" class="large-text" id="gatewaykit-wl-magic-url" />
							<p class="description"><?php esc_html_e( 'Bookmark this link or click "Email me the link" to re-access White Label settings after this tab hides.', 'gatewaykit' ); ?></p>
							<button type="button" class="button" id="gatewaykit-wl-copy-url"><?php esc_html_e( 'Copy Link', 'gatewaykit' ); ?></button>
							<?php
							$user_id  = get_current_user_id();
							$mail_url = wp_nonce_url(
								add_query_arg( array( 'gatewaykit_wl_email' => '1' ), admin_url( 'admin.php?page=gatewaykit-settings&tab=whitelabel' ) ),
								'gatewaykit_wl_email_nonce'
							);
							?>
							<a href="<?php echo esc_url( $mail_url ); ?>" class="button"><?php esc_html_e( 'Email me the link', 'gatewaykit' ); ?></a>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save White Label Settings', 'gatewaykit' ) ); ?>
			</form>

			<div class="gatewaykit-wl-notice" style="margin-top: 16px;">
				<div class="notice notice-info inline">
					<p><strong><?php esc_html_e( 'Scope Limitation:', 'gatewaykit' ); ?></strong> <?php esc_html_e( 'Freemius "Account" and "Add-Ons" submenus remain visible. They handle license/subscription management and cannot be renamed safely.', 'gatewaykit' ); ?></p>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render gateway settings
	 */
	private function render_gateway_settings() {
		$gateway_manager = GatewayKit_Gateway_Manager::get_instance();
		$gateways        = $gateway_manager->get_registered_gateways();

		?>
		<div class="gatewaykit-gateway-settings">
			<div class="gatewaykit-gateway-settings-intro">
				<p><?php esc_html_e( 'Configure your payment gateways. Enable or disable gateways and set their specific settings below.', 'gatewaykit' ); ?></p>
				<div class="gatewaykit-gateway-request-notice">
					<div class="notice notice-info inline">
						<p><?php esc_html_e( 'Need an additional payment gateway? Please contact support to request it.', 'gatewaykit' ); ?></p>
					</div>
				</div>
			</div>

			<?php foreach ( $gateways as $gateway_id => $class_name ) : ?>
				<?php
				$gateway = $gateway_manager->get_gateway( $gateway_id );
				if ( ! $gateway ) {
					continue;
				}

				$gateway_info = $gateway->get_gateway_info();
				$is_enabled   = $this->is_gateway_enabled( $gateway_id );
				$is_available = $gateway->is_available();
				?>
				<div class="gatewaykit-gateway-card <?php echo $is_enabled ? 'enabled' : 'disabled'; ?>">
					<div class="gatewaykit-gateway-card-header">
						<div class="gatewaykit-gateway-info">
							<div class="gatewaykit-gateway-icon">
								<?php echo $this->get_gateway_logo( $gateway_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- returns pre-escaped HTML ?>
							</div>
							<div class="gatewaykit-gateway-details">
								<h3 class="gatewaykit-gateway-name"><?php echo esc_html( $gateway->get_gateway_name() ); ?></h3>
								<p class="gatewaykit-gateway-description"><?php echo esc_html( $gateway_info['description'] ?? '' ); ?></p>
							</div>
						</div>

						<div class="gatewaykit-gateway-actions">
							<div class="gatewaykit-gateway-toggle" data-gateway="<?php echo esc_attr( $gateway_id ); ?>">
								<input type="hidden"
										name="gatewaykit_gateway_enabled[<?php echo esc_attr( $gateway_id ); ?>]"
										value="<?php echo $is_enabled ? '1' : '0'; ?>"
										class="gatewaykit-gateway-status-input" />
								<span class="gatewaykit-toggle-track <?php echo $is_enabled ? 'enabled' : 'disabled'; ?>">
									<span class="gatewaykit-toggle-slider"></span>
								</span>
								<span class="gatewaykit-toggle-label <?php echo $is_enabled ? 'active' : ''; ?>">
									<?php echo $is_enabled ? esc_html__( 'Enabled', 'gatewaykit' ) : esc_html__( 'Disabled', 'gatewaykit' ); ?>
								</span>
							</div>
						</div>
					</div>

					<div class="gatewaykit-gateway-card-content <?php echo $is_enabled ? 'visible' : 'hidden'; ?>">
						<?php if ( ! $is_available ) : ?>
							<div class="gatewaykit-gateway-notice">
								<div class="notice notice-warning inline">
									<p><?php esc_html_e( 'This gateway is not properly configured. Please check your settings below.', 'gatewaykit' ); ?></p>
								</div>
							</div>
						<?php endif; ?>

						<div class="gatewaykit-gateway-settings-form">
							<?php $this->render_gateway_settings_form( $gateway_id, $gateway ); ?>
							<p class="gatewaykit-gateway-test-row">
								<button type="button" class="button button-secondary gatewaykit-test-gateway" data-gateway="<?php echo esc_attr( $gateway_id ); ?>">
									<?php esc_html_e( 'Test Connection', 'gatewaykit' ); ?>
								</button>
								<span class="description"><?php esc_html_e( 'Verify your credentials before enabling the gateway. No payment is created.', 'gatewaykit' ); ?></span>
							</p>
						</div>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Check if gateway is enabled
	 *
	 * @param string $gateway_id Gateway ID
	 * @return bool True if enabled
	 */
	private function is_gateway_enabled( $gateway_id ) {
		$enabled_gateways = get_option( 'gatewaykit_enabled_gateways', array() );
		return isset( $enabled_gateways[ $gateway_id ] ) && $enabled_gateways[ $gateway_id ] === '1';
	}

	/**
	 * Get gateway logo
	 *
	 * @param string $gateway_id Gateway ID
	 * @return string Logo HTML
	 */
	private function get_gateway_logo( $gateway_id ) {
		// No hardcoded per-gateway branding in the shared core. Gateways
		// auto-discovered from src/Gateways/ supply a logo image at
		// assets/images/gateways/<gateway_id>.png when available; otherwise a
		// generic text badge is rendered.
		$gateway_logos = array();

		$logo_text = isset( $gateway_logos[ $gateway_id ] ) ? $gateway_logos[ $gateway_id ] : strtoupper( $gateway_id );

		// Use logo images if available
		$logo_path = GATEWAYKIT_PLUGIN_DIR . 'assets/images/gateways/' . $gateway_id . '.png';
		if ( file_exists( $logo_path ) ) {
			return '<img src="' . esc_url( GATEWAYKIT_PLUGIN_URL . 'assets/images/gateways/' . $gateway_id . '.png' ) . '" alt="' . esc_attr( $logo_text ) . '" class="gatewaykit-gateway-logo" />';
		}

		// Fallback to styled text logo
		$colors = array();

		$bg_color = isset( $colors[ $gateway_id ] ) ? $colors[ $gateway_id ] : '#C96FD1';

		return '<div class="gatewaykit-gateway-text-logo" style="background: ' . esc_attr( $bg_color ) . ';">' . esc_html( substr( $logo_text, 0, 2 ) ) . '</div>';
	}

	/**
	 * Render gateway settings form
	 *
	 * @param string $gateway_id Gateway ID
	 * @param object $gateway Gateway instance
	 */
	private function render_gateway_settings_form( $gateway_id, $gateway ) {
		$settings_fields  = $gateway->get_settings_fields();
		$current_settings = $gateway->get_settings();

		if ( empty( $settings_fields ) ) {
			?>
			<div class="gatewaykit-no-settings">
				<p><?php esc_html_e( 'This gateway has no configurable settings.', 'gatewaykit' ); ?></p>
			</div>
			<?php
			return;
		}

		?>
		<table class="form-table gatewaykit-gateway-settings-table">
			<tbody>
				<?php foreach ( $settings_fields as $field_id => $field_config ) : ?>
					<?php $this->render_settings_field( $gateway_id, $field_id, $field_config, $current_settings ); ?>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render settings field
	 */
	private function render_settings_field( string $gateway_id, string $field_id, array $field_config, array $current_settings ) {
		$field_name  = 'gatewaykit_' . $gateway_id . '_settings[' . $field_id . ']';
		$field_value = isset( $current_settings[ $field_id ] ) ? $current_settings[ $field_id ] : '';

		?>
		<tr>
			<th scope="row"><?php echo esc_html( $field_config['label'] ); ?></th>
			<td>
				<?php
				switch ( $field_config['type'] ) {
					case 'text':
						echo '<input type="text" name="' . esc_attr( $field_name ) . '" value="' . esc_attr( $field_value ) . '" class="regular-text" />';
						break;

					case 'password':
						echo '<input type="password" name="' . esc_attr( $field_name ) . '" value="' . esc_attr( $field_value ) . '" class="regular-text" />';
						break;

					case 'checkbox':
						echo '<label><input type="checkbox" name="' . esc_attr( $field_name ) . '" value="1" ' . checked( $field_value, '1', false ) . ' /> ' . esc_html( $field_config['description'] ) . '</label>';
						break;

					case 'select':
						echo '<select name="' . esc_attr( $field_name ) . '">';
						foreach ( $field_config['options'] as $option_value => $option_label ) {
							echo '<option value="' . esc_attr( $option_value ) . '" ' . selected( $field_value, $option_value, false ) . '>' . esc_html( $option_label ) . '</option>';
						}
						echo '</select>';
						break;
				}

				if ( ! empty( $field_config['description'] ) && $field_config['type'] !== 'checkbox' ) {
					echo '<p class="description">' . esc_html( $field_config['description'] ) . '</p>';
				}
				?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Save settings
	 */
	private function save_settings() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified via wp_verify_nonce() in settings_page() before save_settings() runs
		$active_tab = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : 'general';

		// Debug: Log received data
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- debug logging of raw request shape
			GatewayKit_Logger::get_instance()->debug(
				'Save settings called',
				array(
					'post_data'       => wp_unslash( $_POST ),
					'gateway_enabled' => isset( $_POST['gatewaykit_gateway_enabled'] ) ? wp_unslash( $_POST['gatewaykit_gateway_enabled'] ) : 'not set',
				)
			);
			// phpcs:enable
		}

		// Save general settings
		$general_settings = array(
			'gatewaykit_currency',
			'gatewaykit_log_level',
			'gatewaykit_print_mode',
			'gatewaykit_rate_limit_enabled',
			'gatewaykit_elementor_action_rate_limit',
			'gatewaykit_admin_ajax_rate_limit',
			'gatewaykit_callback_rate_limit',
			'gatewaykit_trusted_proxies',
		);

		// Email receipt settings.
		$general_settings[] = 'gatewaykit_receipt_email_from_name';
		$general_settings[] = 'gatewaykit_receipt_email_from_address';
		$general_settings[] = 'gatewaykit_receipt_email_subject';
		$general_settings[] = 'gatewaykit_receipt_email_attach_pdf';

		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- nonce verified in settings_page(); each value sanitized by its per-field callback (absint / sanitize_text_field / sanitize_trusted_proxies) below
		foreach ( $general_settings as $setting ) {
			if ( isset( $_POST[ $setting ] ) ) {
				if ( $setting === 'gatewaykit_rate_limit_enabled' ) {
					update_option( $setting, '1' );
				} elseif ( in_array( $setting, array( 'gatewaykit_elementor_action_rate_limit', 'gatewaykit_admin_ajax_rate_limit', 'gatewaykit_callback_rate_limit' ), true ) ) {
					update_option( $setting, absint( wp_unslash( $_POST[ $setting ] ) ) );
				} elseif ( 'gatewaykit_trusted_proxies' === $setting ) {
					update_option( $setting, $this->sanitize_trusted_proxies( wp_unslash( $_POST[ $setting ] ) ) );
				} elseif ( $setting === 'gatewaykit_receipt_email_attach_pdf' ) {
					update_option( $setting, '1' );
				} else {
					$value = sanitize_text_field( wp_unslash( $_POST[ $setting ] ) );
					if ( 'gatewaykit_currency' === $setting && '' === $value ) {
						$value = GatewayKit_Gateway_Manager::get_instance()->get_default_currency();
					}
					update_option( $setting, $value );
				}
			} elseif ( ( $setting === 'gatewaykit_rate_limit_enabled' || $setting === 'gatewaykit_receipt_email_attach_pdf' )
				&& 'general' === $active_tab
			) {
				update_option( $setting, '0' );
			}
		}

		// Webhook settings — textarea, checkbox array, and secret (Pro only).
		// Scoped to the Webhooks tab: the hidden `tab` input guarantees that
		// saving any OTHER tab can never wipe configured webhook events or
		// touch the secret (ERR-043).
		if ( defined( 'GATEWAYKIT_PRO_VERSION' ) && 'webhooks' === $active_tab ) {
			if ( isset( $_POST['gatewaykit_webhook_urls'] ) ) {
				$raw_urls       = wp_unslash( $_POST['gatewaykit_webhook_urls'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized immediately below
				$sanitized_urls = $this->sanitize_webhook_urls( $raw_urls );
				update_option( 'gatewaykit_webhook_urls', $sanitized_urls );
			}
			if ( isset( $_POST['gatewaykit_webhook_events'] ) && is_array( $_POST['gatewaykit_webhook_events'] ) ) {
				$raw_events       = wp_unslash( $_POST['gatewaykit_webhook_events'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized immediately below
				$sanitized_events = $this->sanitize_webhook_events( $raw_events );
				update_option( 'gatewaykit_webhook_events', $sanitized_events );
			} elseif ( isset( $_POST['submit'] ) ) {
				// Webhook events section was submitted with all checkboxes unchecked.
				update_option( 'gatewaykit_webhook_events', array() );
			}
			// Only overwrite the secret when a non-empty replacement is provided.
			if ( isset( $_POST['gatewaykit_webhook_secret'] ) && '' !== trim( wp_unslash( $_POST['gatewaykit_webhook_secret'] ) ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized immediately below
				update_option( 'gatewaykit_webhook_secret', sanitize_text_field( wp_unslash( $_POST['gatewaykit_webhook_secret'] ) ) );
			}
		}

		// Save gateway settings first
		$gateway_manager = GatewayKit_Gateway_Manager::get_instance();
		$gateways        = $gateway_manager->get_registered_gateways();

		foreach ( $gateways as $gateway_id => $class_name ) {
			$setting_key = 'gatewaykit_' . $gateway_id . '_settings';

			if ( isset( $_POST[ $setting_key ] ) ) {
				$settings = wp_unslash( $_POST[ $setting_key ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- array of gateway settings; each value sanitized in the loop below

				// Sanitize settings
				$sanitized_settings = array();
				foreach ( $settings as $key => $value ) {
					$sanitized_settings[ sanitize_key( $key ) ] = sanitize_text_field( $value );
				}

				// Always save via update_gateway_settings() so that
				// validate_settings_input() (which encrypts secrets and
				// enforces validation) runs for every gateway, regardless of
				// whether it is enabled. The enabled flag is a separate
				// option, not a bypass condition for saving credentials.
				$gateway_manager->update_gateway_settings( $gateway_id, $sanitized_settings );
			}
		}

		// Save enabled gateways with validation
		if ( isset( $_POST['gatewaykit_gateway_enabled'] ) ) {
			$enabled_raw       = wp_unslash( $_POST['gatewaykit_gateway_enabled'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- array of gateway enable flags; each key sanitized in the loop below
			$enabled_gateways  = array();
			$validation_errors = array();

			foreach ( $enabled_raw as $gateway_id => $enabled ) {
				$gateway_id = sanitize_key( $gateway_id );
				if ( '1' === (string) $enabled ) {
					// Validate gateway settings before enabling
					$gateway = $gateway_manager->get_gateway( $gateway_id );
					if ( $gateway && ! $gateway->is_available() ) {
						$validation_errors[] = sprintf(
						/* translators: %s: gateway name */
							__( 'Gateway "%s" cannot be enabled because its settings are incomplete. Please enter the required settings first.', 'gatewaykit' ),
							$gateway->get_gateway_name()
						);
					} else {
						$enabled_gateways[ $gateway_id ] = '1';
					}
				}
			}
			// phpcs:enable

			// Show validation errors if any
			if ( ! empty( $validation_errors ) ) {
				foreach ( $validation_errors as $error ) {
					echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $error ) . '</p></div>';
				}
				return; // Don't save if there are validation errors
			}

			update_option( 'gatewaykit_enabled_gateways', $enabled_gateways );

			// Force cache refresh by updating timestamp
			update_option( 'gatewaykit_gateway_cache_version', time() );

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				GatewayKit_Logger::get_instance()->debug(
					'Enabled gateways saved',
					array(
						'enabled_gateways' => $enabled_gateways,
					)
				);
			}
		}
		// Note: If $_POST['gatewaykit_gateway_enabled'] is not set, we
		// intentionally skip updating the option to preserve the current
		// gateway state. The old else branch would reset all gateways to
		// disabled, which caused bugs during plugin updates or partial
		// form submissions.
	}

	/**
	 * Transactions page
	 */
	public function transactions_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'gatewaykit' ) );
		}

		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Payment Transactions', 'gatewaykit' ); ?></h1>

			<?php
			$transaction_table = new GatewayKit_Transaction_List_Table();
			$transaction_table->prepare_items();

			// CSV export is a Pro-only feature. When unlicensed we render the
			// button disabled with an inline upgrade hint so the entry point
			// stays visible (discoverable upsell) without ever executing the
			// export. The export endpoint itself is also gated (defense in
			// depth) in handle_transaction_exports().
			$can_export  = gatewaykit_is_pro_licensed();
			$upgrade_url = gatewaykit_get_upgrade_url();

			// Read filter context from GET so hidden export fields carry the
			// currently-active filters. These are also used by the filter form
			// further down. Protected by the manage_options capability check
			// (standard WP pattern, no nonce for filtering).
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
			$cur_orderby   = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : '';
			$cur_order     = isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : '';
			$cur_status    = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
			$cur_gateway   = isset( $_GET['gateway'] ) ? sanitize_key( wp_unslash( $_GET['gateway'] ) ) : '';
			$cur_date_from = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '';
			$cur_date_to   = isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '';
			$cur_search    = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		// phpcs:enable
			?>

			<?php if ( $can_export ) : ?>
				<!-- Export All Transactions Form (Pro) -->
				<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=gatewaykit-transactions' ) ); ?>" style="display: inline-block; margin-right: 10px;">
					<?php wp_nonce_field( 'gatewaykit_export_all_transactions_nonce', 'gatewaykit_export_all_nonce' ); ?>
					<input type="hidden" name="gatewaykit_action" value="export_all" />
					<!-- Pass current filter context so export respects active filters -->
					<input type="hidden" name="gatewaykit_export_status" value="<?php echo esc_attr( $cur_status ); ?>" />
					<input type="hidden" name="gatewaykit_export_gateway" value="<?php echo esc_attr( $cur_gateway ); ?>" />
					<input type="hidden" name="gatewaykit_export_date_from" value="<?php echo esc_attr( $cur_date_from ); ?>" />
					<input type="hidden" name="gatewaykit_export_date_to" value="<?php echo esc_attr( $cur_date_to ); ?>" />
					<input type="hidden" name="gatewaykit_export_s" value="<?php echo esc_attr( $cur_search ); ?>" />
					<button type="submit" class="button button-primary">
						<span class="dashicons dashicons-download" style="margin-top: 3px;"></span>
						<?php esc_html_e( 'Export All Transactions', 'gatewaykit' ); ?>
					</button>
				</form>
			<?php else : ?>
				<!-- Export All Transactions — locked (Lite). Pro unlocks CSV export. -->
				<span class="gatewaykit-locked-action" style="display: inline-block; margin-right: 10px; vertical-align: middle;">
					<button type="button" class="button button-primary" disabled="disabled" aria-disabled="true"
						title="<?php esc_attr_e( 'CSV export is a GatewayKit Pro feature. Upgrade to unlock.', 'gatewaykit' ); ?>">
						<span class="dashicons dashicons-lock" style="margin-top: 3px;"></span>
						<?php esc_html_e( 'Export All Transactions', 'gatewaykit' ); ?>
					</button>
					<a class="gatewaykit-upgrade-pill" href="<?php echo esc_url( $upgrade_url ); ?>" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'Upgrade to Pro', 'gatewaykit' ); ?>
					</a>
				</span>
			<?php endif; ?>

		<!-- Filters and Search Form (GET) -->
		<?php
		// Filter context ($cur_*) already read above and shared with the
		// export form. No need to re-read from $_GET.
		?>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="wp-clearfix" style="display:block; margin:10px 0;">
				<input type="hidden" name="page" value="gatewaykit-transactions" />
				<?php if ( ! empty( $cur_orderby ) ) : ?>
					<input type="hidden" name="orderby" value="<?php echo esc_attr( $cur_orderby ); ?>" />
				<?php endif; ?>
				<?php if ( ! empty( $cur_order ) ) : ?>
					<input type="hidden" name="order" value="<?php echo esc_attr( $cur_order ); ?>" />
				<?php endif; ?>

				<div class="alignleft actions">
					<select name="status" id="gatewaykit-filter-status">
						<option value=""><?php esc_html_e( 'All Statuses', 'gatewaykit' ); ?></option>
						<option value="pending" <?php selected( $cur_status, 'pending' ); ?>><?php esc_html_e( 'Pending', 'gatewaykit' ); ?></option>
						<option value="processing" <?php selected( $cur_status, 'processing' ); ?>><?php esc_html_e( 'Processing', 'gatewaykit' ); ?></option>
						<option value="completed" <?php selected( $cur_status, 'completed' ); ?>><?php esc_html_e( 'Completed', 'gatewaykit' ); ?></option>
						<option value="failed" <?php selected( $cur_status, 'failed' ); ?>><?php esc_html_e( 'Failed', 'gatewaykit' ); ?></option>
						<option value="cancelled" <?php selected( $cur_status, 'cancelled' ); ?>><?php esc_html_e( 'Cancelled', 'gatewaykit' ); ?></option>
					</select>

				<select name="gateway" id="gatewaykit-filter-gateway">
					<option value=""><?php esc_html_e( 'All Gateways', 'gatewaykit' ); ?></option>
					<?php
					$gateway_manager = GatewayKit_Gateway_Manager::get_instance();
					$gateways        = $gateway_manager->get_available_gateways();
					foreach ( $gateways as $gateway_id => $gateway ) {
						echo '<option value="' . esc_attr( $gateway_id ) . '" ' . selected( $cur_gateway, $gateway_id, false ) . '>' . esc_html( $gateway->get_gateway_name() ) . '</option>';
					}
					?>
				</select>

			<?php
			// Date range filter — always available (moved to Lite in 1.2.0).
			?>
			<span class="gatewaykit-date-filter">
				<label class="screen-reader-text" for="gatewaykit-filter-date-from"><?php esc_html_e( 'Date From', 'gatewaykit' ); ?></label>
				<input type="date" name="date_from" id="gatewaykit-filter-date-from"
					value="<?php echo esc_attr( $cur_date_from ); ?>"
					placeholder="<?php esc_attr_e( 'From', 'gatewaykit' ); ?>"
				/>
				<label class="screen-reader-text" for="gatewaykit-filter-date-to"><?php esc_html_e( 'Date To', 'gatewaykit' ); ?></label>
				<input type="date" name="date_to" id="gatewaykit-filter-date-to"
					value="<?php echo esc_attr( $cur_date_to ); ?>"
					placeholder="<?php esc_attr_e( 'To', 'gatewaykit' ); ?>"
				/>
			</span>

				<button type="submit" id="gatewaykit-filter-btn" class="button"><?php esc_html_e( 'Filter', 'gatewaykit' ); ?></button>
				</div>

				<div class="alignright actions">
					<p class="search-box">
						<label class="screen-reader-text" for="transaction-search-input"><?php esc_html_e( 'Search Transactions:', 'gatewaykit' ); ?></label>
						<input type="search" id="transaction-search-input" name="s" value="<?php echo esc_attr( $cur_search ); ?>" placeholder="<?php esc_attr_e( 'Search by name, receipt code, email, phone...', 'gatewaykit' ); ?>" />
						<button type="submit" id="gatewaykit-search-btn" class="button"><?php esc_html_e( 'Search Transactions', 'gatewaykit' ); ?></button>
					</p>
				</div>
			</form>

			<!-- WP_List_Table Form for Bulk Actions (POST) -->
			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=gatewaykit-transactions' ) ); ?>">
				<input type="hidden" name="page" value="gatewaykit-transactions" />
				<?php $transaction_table->display(); ?>
			</form>

			<!-- Form Data Modal -->
			<div id="gatewaykit-form-data-modal" class="gatewaykit-modal" style="display: none;">
				<div class="gatewaykit-modal-overlay"></div>
				<div class="gatewaykit-modal-content">
					<div class="gatewaykit-modal-header">
						<h2><?php esc_html_e( 'Transaction Form Data', 'gatewaykit' ); ?></h2>
						<button type="button" class="gatewaykit-modal-close">&times;</button>
					</div>
				<div class="gatewaykit-modal-body">
					<div id="gatewaykit-form-data-content">
						<p><?php esc_html_e( 'Loading...', 'gatewaykit' ); ?></p>
					</div>
				</div>
				</div>
			</div>

			<!-- Transaction Notes Modal -->
			<div id="gatewaykit-notes-modal" class="gatewaykit-modal" style="display: none;">
				<div class="gatewaykit-modal-overlay"></div>
				<div class="gatewaykit-modal-content">
					<div class="gatewaykit-modal-header">
						<h2><?php esc_html_e( 'Transaction Notes', 'gatewaykit' ); ?></h2>
						<button type="button" class="gatewaykit-modal-close">&times;</button>
					</div>
				<div class="gatewaykit-modal-body">
					<div id="gatewaykit-notes-content">
						<p><?php esc_html_e( 'Loading...', 'gatewaykit' ); ?></p>
						</div>
					</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Logs page
	 */
	public function logs_page() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Payment Logs', 'gatewaykit' ); ?></h1>

			<div class="gatewaykit-logs-container">
				<?php $this->render_logs_content(); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render logs content
	 *
	 * Filter form (GET) + Copy/Export buttons (AJAX) + WP_List_Table.
	 */
	private function render_logs_content() {
		// Capability check (defense in depth; the menu also requires manage_options).
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'gatewaykit' ) );
		}

		$list_table = new GatewayKit_Log_List_Table();
		$list_table->prepare_items();

		// Current filter values (for repopulating the form).
		// Admin list-table GET filters; protected by the manage_options check above.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$cur_level       = isset( $_GET['log_level'] ) ? sanitize_key( wp_unslash( $_GET['log_level'] ) ) : '';
		$cur_date_from   = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '';
		$cur_date_to     = isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '';
		$cur_transaction = isset( $_GET['transaction_id'] ) ? absint( wp_unslash( $_GET['transaction_id'] ) ) : '';
		$cur_search      = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		// phpcs:enable
		?>
		<!-- Copy / Export action buttons (AJAX via admin-logs.js) -->
		<div class="gatewaykit-logs-actions alignleft actions">
			<button type="button" class="button button-secondary gatewaykit-logs-copy">
				<span class="dashicons dashicons-clipboard" style="margin-top:2px;"></span>
				<?php esc_html_e( 'Copy Selected', 'gatewaykit' ); ?>
			</button>
			<button type="button" class="button button-secondary gatewaykit-logs-export">
				<span class="dashicons dashicons-download" style="margin-top:2px;"></span>
				<?php esc_html_e( 'Export Selected', 'gatewaykit' ); ?>
			</button>
			<span class="gatewaykit-logs-notice" role="status" aria-live="polite"></span>
		</div>

		<!-- Filters & Search Form (GET) -->
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="gatewaykit-logs-filters wp-clearfix">
			<input type="hidden" name="page" value="gatewaykit-logs" />

			<div class="alignleft actions">
				<label for="gatewaykit-log-filter-level" class="screen-reader-text"><?php esc_html_e( 'Filter by level', 'gatewaykit' ); ?></label>
				<select name="log_level" id="gatewaykit-log-filter-level">
					<option value=""><?php esc_html_e( 'All Levels', 'gatewaykit' ); ?></option>
					<option value="error" <?php selected( $cur_level, 'error' ); ?>><?php esc_html_e( 'Error', 'gatewaykit' ); ?></option>
					<option value="warning" <?php selected( $cur_level, 'warning' ); ?>><?php esc_html_e( 'Warning', 'gatewaykit' ); ?></option>
					<option value="info" <?php selected( $cur_level, 'info' ); ?>><?php esc_html_e( 'Info', 'gatewaykit' ); ?></option>
					<option value="debug" <?php selected( $cur_level, 'debug' ); ?>><?php esc_html_e( 'Debug', 'gatewaykit' ); ?></option>
				</select>

				<label for="gatewaykit-log-filter-date-from" class="screen-reader-text"><?php esc_html_e( 'Date from', 'gatewaykit' ); ?></label>
				<input type="date" name="date_from" id="gatewaykit-log-filter-date-from" value="<?php echo esc_attr( $cur_date_from ); ?>" placeholder="<?php esc_attr_e( 'From date', 'gatewaykit' ); ?>" />

				<label for="gatewaykit-log-filter-date-to" class="screen-reader-text"><?php esc_html_e( 'Date to', 'gatewaykit' ); ?></label>
				<input type="date" name="date_to" id="gatewaykit-log-filter-date-to" value="<?php echo esc_attr( $cur_date_to ); ?>" placeholder="<?php esc_attr_e( 'To date', 'gatewaykit' ); ?>" />

				<label for="gatewaykit-log-filter-transaction" class="screen-reader-text"><?php esc_html_e( 'Transaction ID', 'gatewaykit' ); ?></label>
				<input type="number" name="transaction_id" id="gatewaykit-log-filter-transaction" value="<?php echo esc_attr( $cur_transaction ); ?>" placeholder="<?php esc_attr_e( 'Transaction ID', 'gatewaykit' ); ?>" min="1" style="width:130px;" />

				<button type="submit" class="button"><?php esc_html_e( 'Filter', 'gatewaykit' ); ?></button>
			</div>

			<div class="alignright actions">
				<label class="screen-reader-text" for="gatewaykit-log-search-input"><?php esc_html_e( 'Search logs:', 'gatewaykit' ); ?></label>
				<input type="search" id="gatewaykit-log-search-input" name="s" value="<?php echo esc_attr( $cur_search ); ?>" placeholder="<?php esc_attr_e( 'Search message...', 'gatewaykit' ); ?>" />
				<button type="submit" class="button"><?php esc_html_e( 'Search Logs', 'gatewaykit' ); ?></button>
			</div>
		</form>

		<!-- Bulk actions form wrapping the list table (POST). Copy/export intercepted by JS. -->
		<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=gatewaykit-logs' ) ); ?>" id="gatewaykit-logs-table-form">
			<?php wp_nonce_field( 'bulk-logs' ); ?>
			<?php $list_table->display(); ?>
		</form>
		<?php
	}

	/**
	 * Documentation URL.
	 *
	 * @return string
	 */
	private function get_docs_url() {
		return 'https://gatewaykit.pourmirzai.com/docs/';
	}

	/**
	 * Usage guide page
	 */
	public function usage_guide_page() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Usage Guide', 'gatewaykit' ); ?></h1>

			<div class="gatewaykit-settings-header">
				<p class="gatewaykit-settings-description"><?php esc_html_e( 'Learn how to set up and use the GatewayKit plugin with Elementor forms.', 'gatewaykit' ); ?></p>
				<a href="<?php echo esc_url( $this->get_docs_url() ); ?>" target="_blank" class="button button-secondary">
					<?php esc_html_e( 'View Documentation', 'gatewaykit' ); ?>
				</a>
			</div>

			<div class="gatewaykit-usage-guide">
				<p class="gatewaykit-guide-intro"><?php esc_html_e( 'Follow these steps to set up and use the GatewayKit plugin:', 'gatewaykit' ); ?></p>

				<div class="gatewaykit-guide-steps">
					<div class="gatewaykit-step">
						<div class="gatewaykit-step-number">1</div>
						<div class="gatewaykit-step-content">
							<h3><?php esc_html_e( 'Activate a Payment Gateway', 'gatewaykit' ); ?></h3>
							<p><?php esc_html_e( 'Ensure at least one payment gateway is active and properly configured in the Settings page.', 'gatewaykit' ); ?></p>
						</div>
					</div>

					<div class="gatewaykit-step">
						<div class="gatewaykit-step-number">2</div>
						<div class="gatewaykit-step-content">
							<h3><?php esc_html_e( 'Create an Elementor Form', 'gatewaykit' ); ?></h3>
							<p><?php esc_html_e( 'Build a new form using Elementor\'s form widget with the necessary fields for your payment process.', 'gatewaykit' ); ?></p>
						</div>
					</div>

					<div class="gatewaykit-step">
						<div class="gatewaykit-step-number">3</div>
						<div class="gatewaykit-step-content">
							<h3><?php esc_html_e( 'Configure Form Actions', 'gatewaykit' ); ?></h3>
							<p><?php esc_html_e( 'In the form\'s "Actions After Submit" section, enable only the Payment Gateway action and disable all other actions.', 'gatewaykit' ); ?></p>
						</div>
					</div>

					<div class="gatewaykit-step">
						<div class="gatewaykit-step-number">4</div>
						<div class="gatewaykit-step-content">
							<h3><?php esc_html_e( 'Set Payment Settings', 'gatewaykit' ); ?></h3>
							<p><?php esc_html_e( 'Configure the payment amount and redirect page settings in the Payment Gateway action configuration.', 'gatewaykit' ); ?></p>
						</div>
					</div>

					<div class="gatewaykit-step">
						<div class="gatewaykit-step-number">5</div>
						<div class="gatewaykit-step-content">
							<h3><?php esc_html_e( 'Create Results Page', 'gatewaykit' ); ?></h3>
							<p>
								<?php esc_html_e( 'Create a new page and add the shortcode', 'gatewaykit' ); ?>
								<code>[gatewaykit_receipt]</code>
								<?php esc_html_e( 'to display payment results.', 'gatewaykit' ); ?>
							</p>
							<div class="gatewaykit-shortcode-box">
								<code class="gatewaykit-shortcode-display">[gatewaykit_receipt]</code>
								<button type="button" class="gatewaykit-copy-shortcode" data-copy-text="[gatewaykit_receipt]">
									<span class="dashicons dashicons-clipboard"></span>
									<span class="gatewaykit-copy-text"><?php esc_html_e( 'Copy', 'gatewaykit' ); ?></span>
								</button>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render database tools section
	 */
	private function render_database_tools() {
		// Handle upgrade submission.
		if ( isset( $_POST['gatewaykit_tools_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['gatewaykit_tools_nonce'] ) ), 'gatewaykit_tools_action' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$this->handle_upgrade_database();
		}

		$db_manager    = GatewayKit_Database_Manager::get_instance();
		$table_status  = $db_manager->get_table_status();
		$current_ver   = get_option( 'gatewaykit_db_version', '0' );
		$target_ver    = GatewayKit_Database_Manager::DB_VERSION;
		$needs_upgrade = version_compare( $current_ver, $target_ver, '<' );
		?>
		<div class="gatewaykit-tools-section">

			<div class="gatewaykit-database-status-card <?php echo $needs_upgrade ? 'needs-upgrade' : 'up-to-date'; ?>">
				<div class="gatewaykit-database-status-header">
					<h2><?php esc_html_e( 'Database Status', 'gatewaykit' ); ?></h2>
				</div>

				<div class="gatewaykit-database-status-body">
					<?php if ( $needs_upgrade ) : ?>
						<div class="gatewaykit-database-alert gatewaykit-database-alert--warning">
							<span class="gatewaykit-database-alert-icon">&#9888;</span>
							<div class="gatewaykit-database-alert-content">
								<strong><?php esc_html_e( 'Database upgrade available', 'gatewaykit' ); ?></strong>
								<p><?php esc_html_e( 'Your database schema is outdated and needs to be upgraded to match the current plugin version. This will create any missing tables or columns without affecting your existing data.', 'gatewaykit' ); ?></p>
							</div>
						</div>

						<div class="gatewaykit-database-version-row">
							<div class="gatewaykit-database-version-item">
								<span class="gatewaykit-database-version-label"><?php esc_html_e( 'Current version', 'gatewaykit' ); ?></span>
								<code class="gatewaykit-database-version-value gatewaykit-database-version-value--old"><?php echo esc_html( $current_ver ); ?></code>
							</div>
							<div class="gatewaykit-database-version-arrow">&rarr;</div>
							<div class="gatewaykit-database-version-item">
								<span class="gatewaykit-database-version-label"><?php esc_html_e( 'Target version', 'gatewaykit' ); ?></span>
								<code class="gatewaykit-database-version-value gatewaykit-database-version-value--new"><?php echo esc_html( $target_ver ); ?></code>
							</div>
						</div>

						<form method="post" action="" class="gatewaykit-database-upgrade-form">
							<?php wp_nonce_field( 'gatewaykit_tools_action', 'gatewaykit_tools_nonce' ); ?>
							<button type="submit" name="gatewaykit_action" value="upgrade_database" class="button button-primary gatewaykit-upgrade-button">
								<?php esc_html_e( 'Upgrade Database', 'gatewaykit' ); ?>
							</button>
						</form>
					<?php else : ?>
						<div class="gatewaykit-database-alert gatewaykit-database-alert--success">
							<span class="gatewaykit-database-alert-icon">&#10003;</span>
							<div class="gatewaykit-database-alert-content">
								<strong><?php esc_html_e( 'Database is up to date', 'gatewaykit' ); ?></strong>
								<p>
								<?php
									printf(
										/* translators: %s: database version number */
										esc_html__( 'Your database is running the latest schema (version %s). No action is needed.', 'gatewaykit' ),
										esc_html( $target_ver )
									);
								?>
								</p>
							</div>
						</div>
					<?php endif; ?>
				</div>
			</div>

			<div class="gatewaykit-database-tables-card">
				<div class="gatewaykit-database-tables-header">
					<h3><?php esc_html_e( 'Tables', 'gatewaykit' ); ?></h3>
				</div>
				<table class="gatewaykit-database-tables-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Table', 'gatewaykit' ); ?></th>
							<th><?php esc_html_e( 'Records', 'gatewaykit' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $table_status as $table_name => $status ) : ?>
							<tr>
								<td class="gatewaykit-database-table-name">
									<code><?php echo esc_html( 'wp_gatewaykit_' . $table_name ); ?></code>
								</td>
								<td class="gatewaykit-database-table-records">
									<?php if ( $status['exists'] ) : ?>
										<span class="gatewaykit-database-table-count"><?php echo esc_html( number_format_i18n( intval( $status['count'] ) ) ); ?></span>
									<?php else : ?>
										<span class="gatewaykit-database-table-missing"><?php esc_html_e( 'Missing', 'gatewaykit' ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>

		</div>
		<?php
	}

	/**
	 * Handle database upgrade action
	 */
	private function handle_upgrade_database() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified via wp_verify_nonce() in caller render_database_tools() above
		if ( ! isset( $_POST['gatewaykit_action'] ) || 'upgrade_database' !== sanitize_text_field( wp_unslash( $_POST['gatewaykit_action'] ) ) ) {
			return;
		}

		$db_manager = GatewayKit_Database_Manager::get_instance();
		$db_manager->check_db_version();

		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Database has been upgraded successfully.', 'gatewaykit' ) . '</p></div>';
	}


	/**
	 * AJAX handler to get form data for a transaction
	 */
	public function ajax_get_form_data() {
		// Rate limiting check
		$rate_limiter = GatewayKit_Rate_Limiter::get_instance();
		$rate_check   = $rate_limiter->check_rate_limit( 'admin_ajax' );
		if ( is_wp_error( $rate_check ) ) {
			wp_send_json_error( $rate_check->get_error_message() );
			return;
		}

		// Verify nonce for security
		check_ajax_referer( 'gatewaykit_ajax_nonce', 'nonce' );

		// Check user capabilities
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'You do not have sufficient permissions to access this page.', 'gatewaykit' ) );
			return;
		}

		// Get transaction ID from request
		$transaction_id = isset( $_POST['transaction_id'] ) ? absint( wp_unslash( $_POST['transaction_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $transaction_id ) ) {
			wp_send_json_error( __( 'Transaction ID is required.', 'gatewaykit' ) );
			return;
		}

		global $wpdb;
		$table_name = $wpdb->prefix . 'gatewaykit_payment_transactions';

		// Get transaction data (AJAX admin-only request; no cache — real-time view).
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin AJAX; real-time single-record lookup by PK
		$transaction = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id, user_id, form_id, post_id, gateway, authority, ref_id, amount, discount_id, discount_amount, currency, description, status, gateway_response, form_data, user_data, callback_url, success_url, failure_url, receipt_token, receipt_token_created_at, ip_address, user_agent, created_at, updated_at, completed_at FROM %i WHERE id = %d',
				$table_name,
				$transaction_id
			)
		);
		// phpcs:enable

		if ( ! $transaction ) {
			wp_send_json_error( __( 'Transaction not found.', 'gatewaykit' ) );
			return;
		}

		// Get user data if user exists
		$user_data = array();
		if ( $transaction->user_id ) {
			$user = get_user_by( 'id', $transaction->user_id );
			if ( $user ) {
				$user_data = array(
					'id'              => $user->ID,
					'display_name'    => $user->display_name,
					'user_email'      => $user->user_email,
					'user_registered' => $user->user_registered,
				);
			}
		}

		// Parse form data
		$form_data = array();
		if ( ! empty( $transaction->form_data ) ) {
			$form_data = json_decode( $transaction->form_data, true );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				$form_data = array( 'raw' => $transaction->form_data );
			}
		}

		// Format date using WordPress date_i18n for consistency with WP-Parsi plugin
		$created_at = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $transaction->created_at ) );

		// Prepare response
		$tx_discount_amount = (float) ( $transaction->discount_amount ?? 0 );
		$tx_discount_id     = (int) ( $transaction->discount_id ?? 0 );
		$discount_code      = '';
		if ( $tx_discount_id > 0 && class_exists( 'GatewayKit_Discount_Model' ) && method_exists( 'GatewayKit_Discount_Model', 'get_by_id' ) ) {
			$d = GatewayKit_Discount_Model::get_by_id( $tx_discount_id );
			if ( $d ) {
				$discount_code = $d->get_data( 'code' );
			}
		}

		$response = array(
			'transaction_id'  => $transaction->id,
			'form_data'       => $form_data ?: array(),
			'user_data'       => $user_data ?: array(),
			'user_id'         => $transaction->user_id,
			'amount'          => $transaction->amount,
			'currency'        => $transaction->currency,
			'original_amount' => (float) $transaction->amount + $tx_discount_amount,
			'discount_amount' => $tx_discount_amount,
			'discount_code'   => $discount_code,
			'is_free_order'   => ( (float) $transaction->amount <= 0 && 'completed' === $transaction->status ),
			'status'          => $transaction->status,
			'description'     => $transaction->description,
			'created_at'      => $created_at,
			'receipt_code'    => $transaction->receipt_code ?? '',
			'payment_gateway' => $transaction->payment_gateway ?? '',
		);

		// Send JSON response
		wp_send_json_success( $response );
	}

	/**
	 * AJAX handler to get error details for a transaction
	 */
	public function ajax_get_error_details() {
		// Rate limiting check
		$rate_limiter = GatewayKit_Rate_Limiter::get_instance();
		$rate_check   = $rate_limiter->check_rate_limit( 'admin_ajax' );
		if ( is_wp_error( $rate_check ) ) {
			wp_send_json_error( $rate_check->get_error_message() );
			return;
		}

		// Verify nonce for security
		check_ajax_referer( 'gatewaykit_ajax_nonce', 'nonce' );

		// Check user capabilities
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'You do not have sufficient permissions to access this page.', 'gatewaykit' ) );
			return;
		}

		// Get transaction ID from request
		$transaction_id = isset( $_POST['transaction_id'] ) ? absint( wp_unslash( $_POST['transaction_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $transaction_id ) ) {
			wp_send_json_error( __( 'Transaction ID is required.', 'gatewaykit' ) );
			return;
		}

		// Get transaction
		$transaction = GatewayKit_Transaction_Model::find( $transaction_id );
		if ( ! $transaction ) {
			wp_send_json_error( __( 'Transaction not found.', 'gatewaykit' ) );
			return;
		}

		// Get error details
		$error_message   = $transaction->get( 'error_message' );
		$error_code      = $transaction->get( 'error_code' );
		$error_type      = $transaction->get( 'error_type' );
		$error_details   = $transaction->get( 'error_details' );
		$error_timestamp = $transaction->get( 'error_timestamp' );

		// Format error type label
		$error_type_labels = array(
			'license'       => __( 'License Error', 'gatewaykit' ),
			'gateway'       => __( 'Gateway Error', 'gatewaykit' ),
			'configuration' => __( 'Configuration Error', 'gatewaykit' ),
			'network'       => __( 'Network Error', 'gatewaykit' ),
			'validation'    => __( 'Validation Error', 'gatewaykit' ),
			'unknown'       => __( 'Unknown Error', 'gatewaykit' ),
		);
		$error_type_label  = isset( $error_type_labels[ $error_type ] ) ? $error_type_labels[ $error_type ] : ucfirst( $error_type ?: 'unknown' );

		// Format timestamp
		$formatted_timestamp = '';
		if ( $error_timestamp ) {
			$formatted_timestamp = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $error_timestamp ) );
		}

		// Prepare response
		$response = array(
			'error_message'    => $error_message ?: __( 'No error message available', 'gatewaykit' ),
			'error_code'       => $error_code ?: '',
			'error_type'       => $error_type ?: 'unknown',
			'error_type_label' => $error_type_label,
			'error_details'    => $error_details ?: array(),
			'error_timestamp'  => $formatted_timestamp,
		);

		// Send JSON response
		wp_send_json_success( $response );
	}

	/**
	 * AJAX handler: test a gateway's credentials before enabling it.
	 *
	 * Accepts unsaved form input (POST['settings']) so merchants can verify
	 * their keys without first saving/enabling the gateway. Requires: nonce,
	 * manage_options capability, admin_ajax rate limit.
	 */
	public function ajax_test_gateway() {
		// Rate limiting check
		$rate_limiter = GatewayKit_Rate_Limiter::get_instance();
		$rate_check   = $rate_limiter->check_rate_limit( 'admin_ajax' );
		if ( is_wp_error( $rate_check ) ) {
			wp_send_json_error( $rate_check->get_error_message() );
			return;
		}

		// Verify nonce for security
		check_ajax_referer( 'gatewaykit_ajax_nonce', 'nonce' );

		// Check user capabilities
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'You do not have sufficient permissions to access this page.', 'gatewaykit' ) );
			return;
		}

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
	}

	/**
	 * AJAX handler: copy selected logs to clipboard (returns formatted text).
	 *
	 * Requires: nonce, manage_options capability, admin_ajax rate limit.
	 */
	public function ajax_copy_logs() {
		// Rate limiting check
		$rate_limiter = GatewayKit_Rate_Limiter::get_instance();
		$rate_check   = $rate_limiter->check_rate_limit( 'admin_ajax' );
		if ( is_wp_error( $rate_check ) ) {
			wp_send_json_error( $rate_check->get_error_message() );
			return;
		}

		// Verify nonce for security
		check_ajax_referer( 'gatewaykit_ajax_nonce', 'nonce' );

		// Check user capabilities
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'You do not have sufficient permissions to access this page.', 'gatewaykit' ) );
			return;
		}

		// Collect requested log IDs
		$log_ids_raw = isset( $_POST['log_ids'] ) ? wp_unslash( $_POST['log_ids'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized via parse_log_ids() below
		$log_ids     = $this->parse_log_ids( $log_ids_raw );

		if ( empty( $log_ids ) ) {
			wp_send_json_error( __( 'No valid log IDs provided.', 'gatewaykit' ) );
			return;
		}

		$logs = GatewayKit_Logger::get_instance()->get_logs_by_ids( $log_ids );

		if ( empty( $logs ) ) {
			wp_send_json_error( __( 'No logs found for the selected entries.', 'gatewaykit' ) );
			return;
		}

		$text    = GatewayKit_Log_Formatter::get_instance()->format_for_export( $logs, true );
		$preview = GatewayKit_Log_Formatter::get_instance()->format_log_entry( $logs[0] );

		wp_send_json_success(
			array(
				'text'     => $text,
				'count'    => count( $logs ),
				'first_id' => $preview['id'],
			)
		);
	}

	/**
	 * AJAX handler: download selected logs as a .txt file.
	 *
	 * Streams a text/plain attachment (Content-Disposition) so the browser
	 * downloads it. Requires: nonce, manage_options capability, rate limit.
	 */
	public function ajax_export_logs() {
		// Rate limiting check
		$rate_limiter = GatewayKit_Rate_Limiter::get_instance();
		$rate_check   = $rate_limiter->check_rate_limit( 'admin_ajax' );
		if ( is_wp_error( $rate_check ) ) {
			wp_send_json_error( $rate_check->get_error_message() );
			return;
		}

		// Verify nonce for security (supports both POST and GET for download links)
		$nonce = isset( $_REQUEST['nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'gatewaykit_ajax_nonce' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'gatewaykit' ), '', array( 'response' => 403 ) );
		}

		// Check user capabilities
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'gatewaykit' ), '', array( 'response' => 403 ) );
		}

		// Collect requested log IDs
		$log_ids_raw = isset( $_REQUEST['log_ids'] ) ? wp_unslash( $_REQUEST['log_ids'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized via parse_log_ids() below
		$log_ids     = $this->parse_log_ids( $log_ids_raw );

		if ( empty( $log_ids ) ) {
			wp_die( esc_html__( 'No valid log IDs provided.', 'gatewaykit' ), '', array( 'response' => 400 ) );
		}

		$logs = GatewayKit_Logger::get_instance()->get_logs_by_ids( $log_ids );

		if ( empty( $logs ) ) {
			wp_die( esc_html__( 'No logs found for the selected entries.', 'gatewaykit' ), '', array( 'response' => 404 ) );
		}

		$text = GatewayKit_Log_Formatter::get_instance()->format_for_export( $logs, true );

		$filename = 'gatewaykit-logs-' . current_time( 'Y-m-d-His' ) . '.txt';

		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $text ) );

		echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text export, no HTML context
		exit;
	}

	/**
	 * AJAX handler: send a test webhook to all configured URLs.
	 *
	 * Requires: nonce, manage_options capability, admin_ajax rate limit.
	 */
	public function ajax_test_webhook() {
		// Rate limiting check.
		$rate_limiter = GatewayKit_Rate_Limiter::get_instance();
		$rate_check   = $rate_limiter->check_rate_limit( 'admin_ajax' );
		if ( is_wp_error( $rate_check ) ) {
			wp_send_json_error( array( 'message' => $rate_check->get_error_message() ) );
			return;
		}

		// Verify nonce for security.
		check_ajax_referer( 'gatewaykit_ajax_nonce', 'nonce' );

		// Check user capabilities.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have sufficient permissions to access this page.', 'gatewaykit' ) ) );
			return;
		}

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
	}

	/**
	 * Normalize and sanitize a list of log IDs coming from an AJAX request.
	 *
	 * Accepts a comma-separated string or an array of IDs.
	 *
	 * @param mixed $raw Raw input.
	 * @return int[] Unique, positive log IDs.
	 */
	private function parse_log_ids( $raw ) {
		if ( is_string( $raw ) ) {
			$raw = explode( ',', $raw );
		}

		if ( ! is_array( $raw ) ) {
			return array();
		}

		$ids = array();
		foreach ( $raw as $id ) {
			$id = absint( $id );
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Enqueue scripts and styles
	 */
	public function enqueue_scripts( string $hook ) {
		if ( strpos( $hook, 'gatewaykit' ) === false ) {
			return;
		}

		// Use minified assets in production, full assets in debug mode
		$suffix = defined( 'WP_DEBUG' ) && WP_DEBUG ? '' : '.min';

		wp_enqueue_style( 'gatewaykit-admin-styles', GATEWAYKIT_PLUGIN_URL . 'assets/css/admin' . $suffix . '.css', array(), GATEWAYKIT_VERSION );
		wp_enqueue_style( 'gatewaykit-validation-states', GATEWAYKIT_PLUGIN_URL . 'assets/css/validation-states.css', array( 'gatewaykit-admin-styles' ), GATEWAYKIT_VERSION );
		wp_enqueue_script( 'gatewaykit-admin-scripts', GATEWAYKIT_PLUGIN_URL . 'assets/js/admin' . $suffix . '.js', array( 'jquery' ), GATEWAYKIT_VERSION, true );
		wp_enqueue_script( 'gatewaykit-ui-kit', GATEWAYKIT_PLUGIN_URL . 'assets/js/ui-kit' . $suffix . '.js', array( 'jquery' ), GATEWAYKIT_VERSION, true );

		// Localize script for AJAX and translations
		wp_localize_script(
			'gatewaykit-admin-scripts',
			'gatewaykit_ajax',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'gatewaykit_ajax_nonce' ),
			)
		);

		// Localize script for translations
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

		// Localize UI Kit script for translations
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
				)
			);
		}
	}

	/**
	 * AJAX handler: get transaction notes.
	 *
	 * @return void
	 */
	public function ajax_get_transaction_notes() {
		check_ajax_referer( 'gatewaykit_ajax_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'gatewaykit' ) ) );
		}

		$transaction_id = isset( $_POST['transaction_id'] ) ? absint( wp_unslash( $_POST['transaction_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified via check_ajax_referer() above

		if ( ! $transaction_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid transaction ID.', 'gatewaykit' ) ) );
		}

		$transaction = GatewayKit_Transaction_Model::find( $transaction_id );
		if ( ! $transaction ) {
			wp_send_json_error( array( 'message' => __( 'Transaction not found.', 'gatewaykit' ) ) );
		}

		wp_send_json_success( array( 'notes' => $transaction->get_notes() ) );
	}

	/**
	 * AJAX handler: add a note to a transaction.
	 *
	 * @return void
	 */
	public function ajax_add_transaction_note() {
		check_ajax_referer( 'gatewaykit_ajax_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'gatewaykit' ) ) );
		}

		$transaction_id = isset( $_POST['transaction_id'] ) ? absint( wp_unslash( $_POST['transaction_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified via check_ajax_referer() above
		$note           = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified via check_ajax_referer() above

		if ( ! $transaction_id || '' === $note ) {
			wp_send_json_error( array( 'message' => __( 'Transaction ID and note are required.', 'gatewaykit' ) ) );
		}

		$transaction = GatewayKit_Transaction_Model::find( $transaction_id );
		if ( ! $transaction ) {
			wp_send_json_error( array( 'message' => __( 'Transaction not found.', 'gatewaykit' ) ) );
		}

		$result = $transaction->add_note( $note );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'notes' => $transaction->get_notes() ) );
	}
}
