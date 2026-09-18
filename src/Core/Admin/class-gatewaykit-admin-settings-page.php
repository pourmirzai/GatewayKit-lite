<?php
/**
 * Admin Settings Page
 *
 * Handles settings registration, sanitization, tabbed settings UI,
 * dashboard view, usage guide, and database tools.
 *
 * @package GatewayKit
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin Settings Page Class
 */
class GatewayKit_Admin_Settings_Page {

	/**
	 * Settings page slug.
	 */
	const PAGE_SLUG = 'gatewaykit-settings';

	/**
	 * Register settings.
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

		// Gateway settings.
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
				// Sensitive fields: remove null bytes and trim, preserve special characters.
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
	 * Render the dashboard page.
	 */
	public function dashboard_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'gatewaykit' ) );
		}

		// Use minified assets in production, full assets in debug mode.
		$suffix = defined( 'WP_DEBUG' ) && WP_DEBUG ? '' : '.min';

		wp_enqueue_style( 'gatewaykit-admin-styles', GATEWAYKIT_PLUGIN_URL . 'assets/css/admin' . $suffix . '.css', array(), GATEWAYKIT_VERSION );
		wp_enqueue_style( 'gatewaykit-validation-states', GATEWAYKIT_PLUGIN_URL . 'assets/css/validation-states.css', array( 'gatewaykit-admin-styles' ), GATEWAYKIT_VERSION );

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
	 * Documentation URL.
	 *
	 * @return string
	 */
	public function get_docs_url() {
		return 'https://gatewaykit.pourmirzai.com/docs/';
	}

	/**
	 * Render settings page.
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

		// Handle form submission.
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
	 * Render settings tabs.
	 */
	public function render_settings_tabs() {
		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$wl_available = class_exists( 'GatewayKit_WhiteLabel' );
		$wl_enabled   = false;
		$wl_unlocked  = false;
		if ( $wl_available ) {
			$wl_enabled  = GatewayKit_WhiteLabel::get_instance()->is_enabled();
			$user_id     = get_current_user_id();
			$wl_unlocked = (bool) get_transient( 'gatewaykit_wl_unlocked_' . $user_id );
		}
		$wl_can_edit = $wl_available && ( ! $wl_enabled || $wl_unlocked );
		?>
		<div class="gatewaykit-settings-tabs">
			<nav class="gatewaykit-settings-tabs-nav">
				<a href="?page=<?php echo esc_attr( self::PAGE_SLUG ); ?>&tab=general" class="gatewaykit-settings-tab-link <?php echo 'general' === $active_tab ? 'active' : ''; ?>">
					<?php esc_html_e( 'General Settings', 'gatewaykit' ); ?>
				</a>
				<a href="?page=<?php echo esc_attr( self::PAGE_SLUG ); ?>&tab=email" class="gatewaykit-settings-tab-link <?php echo 'email' === $active_tab ? 'active' : ''; ?>">
					<?php esc_html_e( 'Email Settings', 'gatewaykit' ); ?>
				</a>
				<a href="?page=<?php echo esc_attr( self::PAGE_SLUG ); ?>&tab=gateways" class="gatewaykit-settings-tab-link <?php echo 'gateways' === $active_tab ? 'active' : ''; ?>">
					<?php esc_html_e( 'Gateway Settings', 'gatewaykit' ); ?>
				</a>
				<?php if ( defined( 'GATEWAYKIT_PRO_VERSION' ) ) : ?>
					<a href="?page=<?php echo esc_attr( self::PAGE_SLUG ); ?>&tab=webhooks" class="gatewaykit-settings-tab-link <?php echo 'webhooks' === $active_tab ? 'active' : ''; ?>">
						<?php esc_html_e( 'Webhooks', 'gatewaykit' ); ?>
					</a>
				<?php endif; ?>
				<a href="?page=<?php echo esc_attr( self::PAGE_SLUG ); ?>&tab=tools" class="gatewaykit-settings-tab-link <?php echo 'tools' === $active_tab ? 'active' : ''; ?>">
					<?php esc_html_e( 'Database', 'gatewaykit' ); ?>
				</a>
			<?php if ( $wl_available && $wl_can_edit ) : ?>
				<a href="?page=<?php echo esc_attr( self::PAGE_SLUG ); ?>&tab=whitelabel" class="gatewaykit-settings-tab-link <?php echo 'whitelabel' === $active_tab ? 'active' : ''; ?>">
					<?php esc_html_e( 'White Label', 'gatewaykit' ); ?>
				</a>
			<?php endif; ?>
			</nav>

			<div class="gatewaykit-settings-tabs-content">
				<?php if ( 'general' === $active_tab ) : ?>
					<?php $this->render_general_settings(); ?>
				<?php elseif ( 'email' === $active_tab ) : ?>
					<?php $this->render_email_settings(); ?>
				<?php elseif ( 'tools' === $active_tab ) : ?>
					<?php $this->render_database_tools(); ?>
				<?php elseif ( 'webhooks' === $active_tab && defined( 'GATEWAYKIT_PRO_VERSION' ) ) : ?>
					<?php $this->render_webhooks_settings(); ?>
				<?php elseif ( 'whitelabel' === $active_tab && $wl_available ) : ?>
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
	 * Render general settings.
	 */
	public function render_general_settings() {
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
	public function render_webhooks_settings() {
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
	 * Render email settings.
	 */
	public function render_email_settings() {
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
	public function render_whitelabel_settings() {
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
	 * Render gateway settings.
	 */
	public function render_gateway_settings() {
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
	 * Check if gateway is enabled.
	 *
	 * @param string $gateway_id Gateway ID.
	 * @return bool True if enabled.
	 */
	public function is_gateway_enabled( $gateway_id ) {
		$enabled_gateways = get_option( 'gatewaykit_enabled_gateways', array() );
		return isset( $enabled_gateways[ $gateway_id ] ) && '1' === $enabled_gateways[ $gateway_id ];
	}

	/**
	 * Get gateway logo.
	 *
	 * @param string $gateway_id Gateway ID.
	 * @return string Logo HTML.
	 */
	public function get_gateway_logo( $gateway_id ) {
		$gateway_logos = array();
		$logo_text     = isset( $gateway_logos[ $gateway_id ] ) ? $gateway_logos[ $gateway_id ] : strtoupper( $gateway_id );

		$logo_path = GATEWAYKIT_PLUGIN_DIR . 'assets/images/gateways/' . $gateway_id . '.png';
		if ( file_exists( $logo_path ) ) {
			return '<img src="' . esc_url( GATEWAYKIT_PLUGIN_URL . 'assets/images/gateways/' . $gateway_id . '.png' ) . '" alt="' . esc_attr( $logo_text ) . '" class="gatewaykit-gateway-logo" />';
		}

		$colors   = array();
		$bg_color = isset( $colors[ $gateway_id ] ) ? $colors[ $gateway_id ] : '#C96FD1';

		return '<div class="gatewaykit-gateway-text-logo" style="background: ' . esc_attr( $bg_color ) . ';">' . esc_html( substr( $logo_text, 0, 2 ) ) . '</div>';
	}

	/**
	 * Render gateway settings form.
	 *
	 * @param string $gateway_id Gateway ID.
	 * @param object $gateway    Gateway instance.
	 */
	public function render_gateway_settings_form( $gateway_id, $gateway ) {
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
	 * Render settings field.
	 *
	 * @param string $gateway_id       Gateway ID.
	 * @param string $field_id         Field ID.
	 * @param array  $field_config     Field configuration.
	 * @param array  $current_settings Current settings array.
	 */
	public function render_settings_field( string $gateway_id, string $field_id, array $field_config, array $current_settings ) {
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

				if ( ! empty( $field_config['description'] ) && 'checkbox' !== $field_config['type'] ) {
					echo '<p class="description">' . esc_html( $field_config['description'] ) . '</p>';
				}
				?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Save settings.
	 */
	public function save_settings() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified via wp_verify_nonce() in settings_page() before save_settings() runs
		$active_tab = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : 'general';

		// Debug: Log received data.
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

		// Save general settings.
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

		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- nonce verified in settings_page(); each value sanitized by its per-field callback below
		foreach ( $general_settings as $setting ) {
			if ( isset( $_POST[ $setting ] ) ) {
				if ( 'gatewaykit_rate_limit_enabled' === $setting ) {
					update_option( $setting, '1' );
				} elseif ( in_array( $setting, array( 'gatewaykit_elementor_action_rate_limit', 'gatewaykit_admin_ajax_rate_limit', 'gatewaykit_callback_rate_limit' ), true ) ) {
					update_option( $setting, absint( wp_unslash( $_POST[ $setting ] ) ) );
				} elseif ( 'gatewaykit_trusted_proxies' === $setting ) {
					update_option( $setting, $this->sanitize_trusted_proxies( wp_unslash( $_POST[ $setting ] ) ) );
				} elseif ( 'gatewaykit_receipt_email_attach_pdf' === $setting ) {
					update_option( $setting, '1' );
				} else {
					$value = sanitize_text_field( wp_unslash( $_POST[ $setting ] ) );
					if ( 'gatewaykit_currency' === $setting && '' === $value ) {
						$value = GatewayKit_Gateway_Manager::get_instance()->get_default_currency();
					}
					update_option( $setting, $value );
				}
			} elseif ( ( 'gatewaykit_rate_limit_enabled' === $setting || 'gatewaykit_receipt_email_attach_pdf' === $setting )
				&& 'general' === $active_tab
			) {
				update_option( $setting, '0' );
			}
		}

		// Webhook settings (Pro only).
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
				update_option( 'gatewaykit_webhook_events', array() );
			}
			if ( isset( $_POST['gatewaykit_webhook_secret'] ) && '' !== trim( wp_unslash( $_POST['gatewaykit_webhook_secret'] ) ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized immediately below
				update_option( 'gatewaykit_webhook_secret', sanitize_text_field( wp_unslash( $_POST['gatewaykit_webhook_secret'] ) ) );
			}
		}

		// Save gateway settings.
		$gateway_manager = GatewayKit_Gateway_Manager::get_instance();
		$gateways        = $gateway_manager->get_registered_gateways();

		foreach ( $gateways as $gateway_id => $class_name ) {
			$setting_key = 'gatewaykit_' . $gateway_id . '_settings';

			if ( isset( $_POST[ $setting_key ] ) ) {
				$settings = wp_unslash( $_POST[ $setting_key ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- array of gateway settings; each value sanitized below

				$sanitized_settings = array();
				foreach ( $settings as $key => $value ) {
					$sanitized_settings[ sanitize_key( $key ) ] = sanitize_text_field( $value );
				}

				$gateway_manager->update_gateway_settings( $gateway_id, $sanitized_settings );
			}
		}

		// Save enabled gateways with validation.
		if ( isset( $_POST['gatewaykit_gateway_enabled'] ) ) {
			$enabled_raw       = wp_unslash( $_POST['gatewaykit_gateway_enabled'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- array of gateway enable flags; each key sanitized below
			$enabled_gateways  = array();
			$validation_errors = array();

			foreach ( $enabled_raw as $gateway_id => $enabled ) {
				$gateway_id = sanitize_key( $gateway_id );
				if ( '1' === (string) $enabled ) {
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

			if ( ! empty( $validation_errors ) ) {
				foreach ( $validation_errors as $error ) {
					echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $error ) . '</p></div>';
				}
				return;
			}

			update_option( 'gatewaykit_enabled_gateways', $enabled_gateways );
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
	}

	/**
	 * Usage guide page.
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
	 * Render database tools section.
	 */
	public function render_database_tools() {
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
	 * Handle database upgrade action.
	 */
	public function handle_upgrade_database() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified via wp_verify_nonce() in caller render_database_tools() above
		if ( ! isset( $_POST['gatewaykit_action'] ) || 'upgrade_database' !== sanitize_text_field( wp_unslash( $_POST['gatewaykit_action'] ) ) ) {
			return;
		}

		$db_manager = GatewayKit_Database_Manager::get_instance();
		$db_manager->check_db_version();

		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Database has been upgraded successfully.', 'gatewaykit' ) . '</p></div>';
	}
}
