<?php
/**
 * GatewayKit Setup Wizard
 *
 * 3-step onboarding wizard for quick store currency, gateway, and starter form configuration.
 *
 * @package GatewayKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GatewayKit_Setup_Wizard
 */
class GatewayKit_Setup_Wizard {

	/**
	 * Single instance of this class.
	 *
	 * @var self|null
	 */
	private static $instance = null;

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
	public function __construct() {
		add_action( 'admin_init', array( $this, 'handle_submissions' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_onboarding_notice' ) );
		add_action( 'wp_ajax_gatewaykit_dismiss_wizard_notice', array( $this, 'ajax_dismiss_notice' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Enqueue wizard scripts and styles when viewing wizard screen.
	 *
	 * @param string $hook Admin page hook suffix.
	 */
	public function enqueue_assets( $hook ) {
		if ( false === strpos( $hook, 'gatewaykit-setup-wizard' ) ) {
			return;
		}

		$suffix = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';

		$css_url = GATEWAYKIT_PLUGIN_URL . 'assets/css/admin-setup-wizard' . $suffix . '.css';
		$css_dir = GATEWAYKIT_PLUGIN_DIR . 'assets/css/admin-setup-wizard' . $suffix . '.css';
		if ( ! file_exists( $css_dir ) ) {
			$css_url = GATEWAYKIT_PLUGIN_URL . 'assets/css/admin-setup-wizard.css';
		}

		$js_url = GATEWAYKIT_PLUGIN_URL . 'assets/js/admin-setup-wizard' . $suffix . '.js';
		$js_dir = GATEWAYKIT_PLUGIN_DIR . 'assets/js/admin-setup-wizard' . $suffix . '.js';
		if ( ! file_exists( $js_dir ) ) {
			$js_url = GATEWAYKIT_PLUGIN_URL . 'assets/js/admin-setup-wizard.js';
		}

		wp_enqueue_style( 'gatewaykit-setup-wizard', $css_url, array(), GATEWAYKIT_VERSION );
		wp_enqueue_script( 'gatewaykit-setup-wizard', $js_url, array( 'jquery' ), GATEWAYKIT_VERSION, true );
	}

	/**
	 * Render dismissible admin notice on fresh install.
	 */
	public function maybe_show_onboarding_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( get_option( 'gatewaykit_setup_completed' ) ) {
			return;
		}

		$user_id = get_current_user_id();
		if ( get_user_meta( $user_id, 'gatewaykit_dismiss_wizard_notice', true ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only check for active screen.
		if ( isset( $_GET['page'] ) && 'gatewaykit-setup-wizard' === $_GET['page'] ) {
			return;
		}

		$wizard_url = admin_url( 'admin.php?page=gatewaykit-setup-wizard' );
		$nonce      = wp_create_nonce( 'gatewaykit_dismiss_wizard' );
		?>
		<div class="notice notice-info is-dismissible gatewaykit-wizard-notice" data-nonce="<?php echo esc_attr( $nonce ); ?>" style="border-left-color: #2563eb;">
			<p style="font-size: 14px;">
				<strong><?php esc_html_e( 'Welcome to GatewayKit!', 'gatewaykit' ); ?></strong>
				<?php esc_html_e( 'Configure your currency, payment gateway, and create your first payment form in 2 minutes.', 'gatewaykit' ); ?>
			</p>
			<p>
				<a href="<?php echo esc_url( $wizard_url ); ?>" class="button button-primary" style="background: #2563eb; border-color: #1d4ed8;">
					<?php esc_html_e( '🚀 Launch Setup Wizard', 'gatewaykit' ); ?>
				</a>
				<a href="#" class="button button-secondary gatewaykit-dismiss-wizard-btn" style="margin-left: 6px;">
					<?php esc_html_e( 'Dismiss', 'gatewaykit' ); ?>
				</a>
			</p>
		</div>
		<script>
		jQuery(document).ready(function($) {
			$(document).on('click', '.gatewaykit-wizard-notice .notice-dismiss, .gatewaykit-dismiss-wizard-btn', function(e) {
				e.preventDefault();
				var $notice = $('.gatewaykit-wizard-notice');
				var nonce = $notice.data('nonce');
				$notice.fadeTo(100, 0, function() { $notice.slideUp(100); });
				$.post(ajaxurl, {
					action: 'gatewaykit_dismiss_wizard_notice',
					nonce: nonce
				});
			});
		});
		</script>
		<?php
	}

	/**
	 * AJAX dismiss handler for the onboarding notice.
	 */
	public function ajax_dismiss_notice() {
		check_ajax_referer( 'gatewaykit_dismiss_wizard', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}

		update_user_meta( get_current_user_id(), 'gatewaykit_dismiss_wizard_notice', '1' );
		wp_send_json_success();
	}

	/**
	 * Process wizard form submissions.
	 */
	public function handle_submissions() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked immediately below with check_admin_referer.
		if ( ! isset( $_POST['gatewaykit_wizard_action'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized access.', 'gatewaykit' ), 403 );
		}

		check_admin_referer( 'gatewaykit_setup_wizard', 'gatewaykit_wizard_nonce' );

		$action = sanitize_key( $_POST['gatewaykit_wizard_action'] );

		if ( 'step_1' === $action ) {
			$currency = isset( $_POST['currency'] ) ? sanitize_text_field( wp_unslash( $_POST['currency'] ) ) : 'USD';
			update_option( 'gatewaykit_currency', strtoupper( $currency ) );

			$receipt_page = isset( $_POST['receipt_page'] ) ? absint( $_POST['receipt_page'] ) : 0;
			if ( $receipt_page > 0 ) {
				update_option( 'gatewaykit_receipt_page_id', $receipt_page );
			}

			wp_safe_redirect( admin_url( 'admin.php?page=gatewaykit-setup-wizard&step=2' ) );
			exit;
		}

		if ( 'step_2' === $action ) {
			$gateway_id = isset( $_POST['gateway_id'] ) ? sanitize_key( $_POST['gateway_id'] ) : 'stripe';
			$test_mode  = ! empty( $_POST['test_mode'] ) ? 'yes' : 'no';

			$manager  = GatewayKit_Gateway_Manager::get_instance();
			$instance = $manager->get_gateway( $gateway_id );

			$gateway_settings = array(
				'enabled'   => 'yes',
				'test_mode' => $test_mode,
			);

			// Populate gateway-specific fields from POST.
			$field_map = array(
				'stripe'      => array( 'secret_key', 'publishable_key', 'webhook_secret' ),
				'paypal'      => array( 'client_id', 'client_secret' ),
				'mollie'      => array( 'api_key', 'profile_id' ),
				'nowpayments' => array( 'api_key', 'ipn_secret' ),
				'coingate'    => array( 'api_token' ),
				'coinify'     => array( 'api_key', 'api_secret' ),
				'razorpay'    => array( 'key_id', 'key_secret', 'webhook_secret' ),
				'paystack'    => array( 'secret_key', 'public_key' ),
				'mercadopago' => array( 'access_token', 'public_key', 'webhook_secret' ),
			);

			if ( isset( $field_map[ $gateway_id ] ) ) {
				foreach ( $field_map[ $gateway_id ] as $field ) {
					// phpcs:ignore WordPress.Security.NonceVerification.Missing
					if ( isset( $_POST[ $gateway_id . '_' . $field ] ) ) {
						// phpcs:ignore WordPress.Security.NonceVerification.Missing
						$gateway_settings[ $field ] = sanitize_text_field( wp_unslash( $_POST[ $gateway_id . '_' . $field ] ) );
					}
				}
			}

			// Validate and encrypt via gateway instance if available.
			if ( $instance && method_exists( $instance, 'validate_settings_input' ) ) {
				$validated = $instance->validate_settings_input( $gateway_settings );
				if ( ! is_wp_error( $validated ) ) {
					$gateway_settings = $validated;
				}
			}

			update_option( 'gatewaykit_' . $gateway_id . '_settings', $gateway_settings );
			update_option( 'gatewaykit_wizard_configured_gateway', $gateway_id );

			wp_safe_redirect( admin_url( 'admin.php?page=gatewaykit-setup-wizard&step=3' ) );
			exit;
		}

		if ( 'step_3' === $action ) {
			$template_key = isset( $_POST['template'] ) ? sanitize_key( $_POST['template'] ) : 'donation';
			$created_id   = 0;

			if ( 'none' !== $template_key && class_exists( 'GatewayKit_Form_Templates' ) && class_exists( 'GatewayKit_Form_CPT' ) ) {
				$template = GatewayKit_Form_Templates::get_template( $template_key );
				if ( $template ) {
					$form_title = ! empty( $_POST['form_title'] )
						? sanitize_text_field( wp_unslash( $_POST['form_title'] ) )
						: $template['title'];

					$post_id = wp_insert_post(
						array(
							'post_type'   => GatewayKit_Form_CPT::POST_TYPE,
							'post_status' => 'publish',
							'post_title'  => $form_title,
						)
					);

					if ( $post_id && ! is_wp_error( $post_id ) ) {
						$created_id = $post_id;
						$config     = $template['config'];

						$configured_gw = get_option( 'gatewaykit_wizard_configured_gateway', '' );
						if ( ! empty( $configured_gw ) ) {
							$config['gateways'] = array( $configured_gw );
						}

						$current_curr       = get_option( 'gatewaykit_currency', 'USD' );
						$config['currency'] = $current_curr;

						update_post_meta( $post_id, GatewayKit_Form_CPT::META_KEY, $config );
					}
				}
			}

			update_option( 'gatewaykit_setup_completed', 1 );

			$redirect = admin_url( 'admin.php?page=gatewaykit-setup-wizard&step=3&completed=1' );
			if ( $created_id > 0 ) {
				$redirect = add_query_arg( 'form_id', $created_id, $redirect );
			}

			wp_safe_redirect( $redirect );
			exit;
		}
	}

	/**
	 * Render the setup wizard page.
	 */
	public function render_page() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$step = isset( $_GET['step'] ) ? absint( $_GET['step'] ) : 1;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$completed = isset( $_GET['completed'] ) && '1' === $_GET['completed'];
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$form_id = isset( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : 0;

		if ( $step < 1 || $step > 3 ) {
			$step = 1;
		}
		?>
		<div class="wrap gatewaykit-wizard-wrap">
			<div class="gatewaykit-wizard-container">
				<!-- Header -->
				<div class="gatewaykit-wizard-header">
					<div class="gatewaykit-wizard-logo">
						<span class="dashicons dashicons-money-alt"></span>
						<h1><?php esc_html_e( 'GatewayKit Setup Wizard', 'gatewaykit' ); ?></h1>
					</div>
					<p class="gatewaykit-wizard-subtitle">
						<?php esc_html_e( 'Get your payment forms running in 3 quick steps.', 'gatewaykit' ); ?>
					</p>

					<!-- Progress Stepper -->
					<div class="gatewaykit-stepper">
						<div class="gatewaykit-step-item <?php echo 1 === $step ? 'active' : ( $step > 1 ? 'completed' : '' ); ?>">
							<span class="step-badge">1</span>
							<span class="step-label"><?php esc_html_e( 'Currency', 'gatewaykit' ); ?></span>
						</div>
						<div class="step-connector <?php echo $step > 1 ? 'completed' : ''; ?>"></div>
						<div class="gatewaykit-step-item <?php echo 2 === $step ? 'active' : ( $step > 2 ? 'completed' : '' ); ?>">
							<span class="step-badge">2</span>
							<span class="step-label"><?php esc_html_e( 'Gateway', 'gatewaykit' ); ?></span>
						</div>
						<div class="step-connector <?php echo $step > 2 ? 'completed' : ''; ?>"></div>
						<div class="gatewaykit-step-item <?php echo 3 === $step ? 'active' : ''; ?>">
							<span class="step-badge">3</span>
							<span class="step-label"><?php esc_html_e( 'Starter Form', 'gatewaykit' ); ?></span>
						</div>
					</div>
				</div>

				<!-- Body Card -->
				<div class="gatewaykit-wizard-card">
					<?php
					if ( $completed ) {
						$this->render_completed_screen( $form_id );
					} elseif ( 1 === $step ) {
						$this->render_step_1();
					} elseif ( 2 === $step ) {
						$this->render_step_2();
					} elseif ( 3 === $step ) {
						$this->render_step_3();
					}
					?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render Step 1: Currency & General.
	 */
	private function render_step_1() {
		$current_currency = strtoupper( get_option( 'gatewaykit_currency', 'USD' ) );
		$currencies       = array(
			'USD' => 'USD ($) - US Dollar',
			'EUR' => 'EUR (€) - Euro',
			'GBP' => 'GBP (£) - British Pound',
			'CAD' => 'CAD ($) - Canadian Dollar',
			'AUD' => 'AUD ($) - Australian Dollar',
			'JPY' => 'JPY (¥) - Japanese Yen',
			'CHF' => 'CHF - Swiss Franc',
			'INR' => 'INR (₹) - Indian Rupee',
			'BRL' => 'BRL (R$) - Brazilian Real',
			'NGN' => 'NGN (₦) - Nigerian Naira',
		);

		$receipt_page_id = (int) get_option( 'gatewaykit_receipt_page_id', 0 );
		$pages           = get_pages( array( 'number' => 50 ) );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=gatewaykit-setup-wizard' ) ); ?>">
			<?php wp_nonce_field( 'gatewaykit_setup_wizard', 'gatewaykit_wizard_nonce' ); ?>
			<input type="hidden" name="gatewaykit_wizard_action" value="step_1" />

			<div class="wizard-section-heading">
				<h2><?php esc_html_e( 'Step 1: Store & Currency Settings', 'gatewaykit' ); ?></h2>
				<p><?php esc_html_e( 'Choose the default currency for all transactions across your payment forms.', 'gatewaykit' ); ?></p>
			</div>

			<div class="wizard-field-group">
				<label for="currency"><strong><?php esc_html_e( 'Default Currency', 'gatewaykit' ); ?></strong></label>
				<select name="currency" id="currency" class="widefat-select">
					<?php foreach ( $currencies as $code => $label ) : ?>
						<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $current_currency, $code ); ?>>
							<?php echo esc_html( $label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<p class="description"><?php esc_html_e( 'You can also override the currency per form in individual form settings.', 'gatewaykit' ); ?></p>
			</div>

			<div class="wizard-field-group" style="margin-top: 20px;">
				<label for="receipt_page"><strong><?php esc_html_e( 'Payment Receipt / Success Page', 'gatewaykit' ); ?></strong></label>
				<select name="receipt_page" id="receipt_page" class="widefat-select">
					<option value="0"><?php esc_html_e( '-- Default Built-in Receipt Page --', 'gatewaykit' ); ?></option>
					<?php if ( is_array( $pages ) ) : ?>
						<?php foreach ( $pages as $p ) : ?>
							<option value="<?php echo esc_attr( $p->ID ); ?>" <?php selected( $receipt_page_id, $p->ID ); ?>>
								<?php echo esc_html( $p->post_title ); ?>
							</option>
						<?php endforeach; ?>
					<?php endif; ?>
				</select>
				<p class="description"><?php esc_html_e( 'Customers are redirected here upon successful payment.', 'gatewaykit' ); ?></p>
			</div>

			<div class="wizard-actions">
				<span></span>
				<button type="submit" class="button button-primary wizard-next-btn">
					<?php esc_html_e( 'Save & Continue to Gateway →', 'gatewaykit' ); ?>
				</button>
			</div>
		</form>
		<?php
	}

	/**
	 * Render Step 2: Gateway Configuration.
	 */
	private function render_step_2() {
		$configured_gw = get_option( 'gatewaykit_wizard_configured_gateway', 'stripe' );
		$gateways      = array(
			'stripe'      => array(
				'title' => 'Stripe',
				'badge' => __( 'Cards / Apple Pay', 'gatewaykit' ),
			),
			'paypal'      => array(
				'title' => 'PayPal',
				'badge' => __( 'Global Standard', 'gatewaykit' ),
			),
			'mollie'      => array(
				'title' => 'Mollie',
				'badge' => __( 'iDEAL / Europe', 'gatewaykit' ),
			),
			'nowpayments' => array(
				'title' => 'NOWPayments',
				'badge' => __( 'Crypto / 300+ Coins', 'gatewaykit' ),
			),
			'coingate'    => array(
				'title' => 'CoinGate',
				'badge' => __( 'Crypto Checkout', 'gatewaykit' ),
			),
			'coinify'     => array(
				'title' => 'Coinify',
				'badge' => __( 'Crypto Payments', 'gatewaykit' ),
			),
			'razorpay'    => array(
				'title' => 'Razorpay',
				'badge' => __( 'UPI / India', 'gatewaykit' ),
			),
			'paystack'    => array(
				'title' => 'Paystack',
				'badge' => __( 'Africa', 'gatewaykit' ),
			),
			'mercadopago' => array(
				'title' => 'Mercado Pago',
				'badge' => __( 'Latin America', 'gatewaykit' ),
			),
		);
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=gatewaykit-setup-wizard' ) ); ?>">
			<?php wp_nonce_field( 'gatewaykit_setup_wizard', 'gatewaykit_wizard_nonce' ); ?>
			<input type="hidden" name="gatewaykit_wizard_action" value="step_2" />

			<div class="wizard-section-heading">
				<h2><?php esc_html_e( 'Step 2: Connect Your Payment Gateway', 'gatewaykit' ); ?></h2>
				<p><?php esc_html_e( 'Select your primary gateway to accept payments. All 9 gateways are included for free in GatewayKit.', 'gatewaykit' ); ?></p>
			</div>

			<!-- Gateway Selector Cards -->
			<div class="wizard-gateway-grid">
				<?php foreach ( $gateways as $gid => $ginfo ) : ?>
					<label class="wizard-gateway-card <?php echo $configured_gw === $gid ? 'selected' : ''; ?>">
						<input type="radio" name="gateway_id" value="<?php echo esc_attr( $gid ); ?>" <?php checked( $configured_gw, $gid ); ?> />
						<div class="gw-card-content">
							<strong class="gw-title"><?php echo esc_html( $ginfo['title'] ); ?></strong>
							<span class="gw-badge"><?php echo esc_html( $ginfo['badge'] ); ?></span>
						</div>
					</label>
				<?php endforeach; ?>
			</div>

			<!-- Test Mode Toggle -->
			<div class="wizard-field-group" style="margin-top: 20px;">
				<label>
					<input type="checkbox" name="test_mode" value="yes" checked="checked" />
					<strong><?php esc_html_e( 'Enable Sandbox / Test Mode', 'gatewaykit' ); ?></strong>
				</label>
				<p class="description"><?php esc_html_e( 'Recommended while setting up to test transactions safely without real money.', 'gatewaykit' ); ?></p>
			</div>

			<!-- Dynamic Gateway Credential Panels -->
			<div class="wizard-gateway-panels" style="margin-top: 20px;">
				<!-- Stripe -->
				<div class="gw-panel" id="panel-stripe">
					<h3><?php esc_html_e( 'Stripe API Credentials', 'gatewaykit' ); ?></h3>
					<div class="wizard-field-group">
						<label for="stripe_secret_key"><?php esc_html_e( 'Secret Key', 'gatewaykit' ); ?></label>
						<input type="password" name="stripe_secret_key" id="stripe_secret_key" class="widefat" placeholder="sk_test_... or sk_live_..." />
					</div>
					<div class="wizard-field-group" style="margin-top: 10px;">
						<label for="stripe_publishable_key"><?php esc_html_e( 'Publishable Key', 'gatewaykit' ); ?></label>
						<input type="text" name="stripe_publishable_key" id="stripe_publishable_key" class="widefat" placeholder="pk_test_... or pk_live_..." />
					</div>
				</div>

				<!-- PayPal -->
				<div class="gw-panel" id="panel-paypal" style="display: none;">
					<h3><?php esc_html_e( 'PayPal REST API Credentials', 'gatewaykit' ); ?></h3>
					<div class="wizard-field-group">
						<label for="paypal_client_id"><?php esc_html_e( 'Client ID', 'gatewaykit' ); ?></label>
						<input type="text" name="paypal_client_id" id="paypal_client_id" class="widefat" />
					</div>
					<div class="wizard-field-group" style="margin-top: 10px;">
						<label for="paypal_client_secret"><?php esc_html_e( 'Client Secret', 'gatewaykit' ); ?></label>
						<input type="password" name="paypal_client_secret" id="paypal_client_secret" class="widefat" />
					</div>
				</div>

				<!-- Mollie -->
				<div class="gw-panel" id="panel-mollie" style="display: none;">
					<h3><?php esc_html_e( 'Mollie API Credentials', 'gatewaykit' ); ?></h3>
					<div class="wizard-field-group">
						<label for="mollie_api_key"><?php esc_html_e( 'API Key', 'gatewaykit' ); ?></label>
						<input type="password" name="mollie_api_key" id="mollie_api_key" class="widefat" placeholder="test_... or live_..." />
					</div>
				</div>

				<!-- NOWPayments -->
				<div class="gw-panel" id="panel-nowpayments" style="display: none;">
					<h3><?php esc_html_e( 'NOWPayments Crypto Credentials', 'gatewaykit' ); ?></h3>
					<div class="wizard-field-group">
						<label for="nowpayments_api_key"><?php esc_html_e( 'API Key', 'gatewaykit' ); ?></label>
						<input type="password" name="nowpayments_api_key" id="nowpayments_api_key" class="widefat" />
					</div>
					<div class="wizard-field-group" style="margin-top: 10px;">
						<label for="nowpayments_ipn_secret"><?php esc_html_e( 'IPN Secret Key', 'gatewaykit' ); ?></label>
						<input type="password" name="nowpayments_ipn_secret" id="nowpayments_ipn_secret" class="widefat" />
					</div>
				</div>

				<!-- CoinGate -->
				<div class="gw-panel" id="panel-coingate" style="display: none;">
					<h3><?php esc_html_e( 'CoinGate Credentials', 'gatewaykit' ); ?></h3>
					<div class="wizard-field-group">
						<label for="coingate_api_token"><?php esc_html_e( 'API Token', 'gatewaykit' ); ?></label>
						<input type="password" name="coingate_api_token" id="coingate_api_token" class="widefat" />
					</div>
				</div>

				<!-- Coinify -->
				<div class="gw-panel" id="panel-coinify" style="display: none;">
					<h3><?php esc_html_e( 'Coinify Credentials', 'gatewaykit' ); ?></h3>
					<div class="wizard-field-group">
						<label for="coinify_api_key"><?php esc_html_e( 'API Key', 'gatewaykit' ); ?></label>
						<input type="password" name="coinify_api_key" id="coinify_api_key" class="widefat" />
					</div>
					<div class="wizard-field-group" style="margin-top: 10px;">
						<label for="coinify_api_secret"><?php esc_html_e( 'API Secret', 'gatewaykit' ); ?></label>
						<input type="password" name="coinify_api_secret" id="coinify_api_secret" class="widefat" />
					</div>
				</div>

				<!-- Razorpay -->
				<div class="gw-panel" id="panel-razorpay" style="display: none;">
					<h3><?php esc_html_e( 'Razorpay Credentials', 'gatewaykit' ); ?></h3>
					<div class="wizard-field-group">
						<label for="razorpay_key_id"><?php esc_html_e( 'Key ID', 'gatewaykit' ); ?></label>
						<input type="text" name="razorpay_key_id" id="razorpay_key_id" class="widefat" />
					</div>
					<div class="wizard-field-group" style="margin-top: 10px;">
						<label for="razorpay_key_secret"><?php esc_html_e( 'Key Secret', 'gatewaykit' ); ?></label>
						<input type="password" name="razorpay_key_secret" id="razorpay_key_secret" class="widefat" />
					</div>
				</div>

				<!-- Paystack -->
				<div class="gw-panel" id="panel-paystack" style="display: none;">
					<h3><?php esc_html_e( 'Paystack Credentials', 'gatewaykit' ); ?></h3>
					<div class="wizard-field-group">
						<label for="paystack_secret_key"><?php esc_html_e( 'Secret Key', 'gatewaykit' ); ?></label>
						<input type="password" name="paystack_secret_key" id="paystack_secret_key" class="widefat" />
					</div>
					<div class="wizard-field-group" style="margin-top: 10px;">
						<label for="paystack_public_key"><?php esc_html_e( 'Public Key', 'gatewaykit' ); ?></label>
						<input type="text" name="paystack_public_key" id="paystack_public_key" class="widefat" />
					</div>
				</div>

				<!-- Mercado Pago -->
				<div class="gw-panel" id="panel-mercadopago" style="display: none;">
					<h3><?php esc_html_e( 'Mercado Pago Credentials', 'gatewaykit' ); ?></h3>
					<div class="wizard-field-group">
						<label for="mercadopago_access_token"><?php esc_html_e( 'Access Token', 'gatewaykit' ); ?></label>
						<input type="password" name="mercadopago_access_token" id="mercadopago_access_token" class="widefat" />
					</div>
				</div>
			</div>

			<div class="wizard-actions">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=gatewaykit-setup-wizard&step=1' ) ); ?>" class="button button-secondary">
					<?php esc_html_e( '← Back', 'gatewaykit' ); ?>
				</a>
				<button type="submit" class="button button-primary wizard-next-btn">
					<?php esc_html_e( 'Save & Continue to Forms →', 'gatewaykit' ); ?>
				</button>
			</div>
		</form>
		<?php
	}

	/**
	 * Render Step 3: Starter Form Creation.
	 */
	private function render_step_3() {
		$templates = array(
			'donation' => array(
				'title' => __( 'Donation Campaign', 'gatewaykit' ),
				'desc'  => __( 'Preset donation buttons ($10, $25, $50, $100) + custom amount, donor name and message.', 'gatewaykit' ),
				'icon'  => 'dashicons-heart',
			),
			'product'  => array(
				'title' => __( 'Single Product / Service', 'gatewaykit' ),
				'desc'  => __( 'Clean fixed checkout for products, services, or digital goods.', 'gatewaykit' ),
				'icon'  => 'dashicons-cart',
			),
			'invoice'  => array(
				'title' => __( 'Invoice Payment', 'gatewaykit' ),
				'desc'  => __( 'Allows clients to pay an open invoice with an invoice number and custom amount.', 'gatewaykit' ),
				'icon'  => 'dashicons-media-spreadsheet',
			),
			'event'    => array(
				'title' => __( 'Event Registration & Tickets', 'gatewaykit' ),
				'desc'  => __( 'Ticket tier selector (VIP, General, Early Bird), attendee details, and registration checkout.', 'gatewaykit' ),
				'icon'  => 'dashicons-tickets-alt',
			),
			'none'     => array(
				'title' => __( 'Skip (I will build later)', 'gatewaykit' ),
				'desc'  => __( 'Finish setup now without generating a starter form.', 'gatewaykit' ),
				'icon'  => 'dashicons-no',
			),
		);
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=gatewaykit-setup-wizard' ) ); ?>">
			<?php wp_nonce_field( 'gatewaykit_setup_wizard', 'gatewaykit_wizard_nonce' ); ?>
			<input type="hidden" name="gatewaykit_wizard_action" value="step_3" />

			<div class="wizard-section-heading">
				<h2><?php esc_html_e( 'Step 3: Create Your First Payment Form', 'gatewaykit' ); ?></h2>
				<p><?php esc_html_e( 'Select a pre-built starter template to immediately start accepting payments or donations.', 'gatewaykit' ); ?></p>
			</div>

			<div class="wizard-template-grid">
				<?php foreach ( $templates as $key => $tpl ) : ?>
					<label class="wizard-template-card <?php echo 'donation' === $key ? 'selected' : ''; ?>">
						<input type="radio" name="template" value="<?php echo esc_attr( $key ); ?>" <?php checked( 'donation', $key ); ?> />
						<div class="tpl-card-icon"><span class="dashicons <?php echo esc_attr( $tpl['icon'] ); ?>"></span></div>
						<div class="tpl-card-body">
							<strong><?php echo esc_html( $tpl['title'] ); ?></strong>
							<p><?php echo esc_html( $tpl['desc'] ); ?></p>
						</div>
					</label>
				<?php endforeach; ?>
			</div>

			<div class="wizard-field-group" id="form-title-group" style="margin-top: 24px;">
				<label for="form_title"><strong><?php esc_html_e( 'Form Title', 'gatewaykit' ); ?></strong></label>
				<input type="text" name="form_title" id="form_title" class="widefat" value="<?php esc_attr_e( 'Support Our Cause', 'gatewaykit' ); ?>" />
				<p class="description"><?php esc_html_e( 'Title displayed in the WordPress admin.', 'gatewaykit' ); ?></p>
			</div>

			<div class="wizard-actions">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=gatewaykit-setup-wizard&step=2' ) ); ?>" class="button button-secondary">
					<?php esc_html_e( '← Back', 'gatewaykit' ); ?>
				</a>
				<button type="submit" class="button button-primary wizard-next-btn">
					<?php esc_html_e( 'Create Form & Complete Setup 🎉', 'gatewaykit' ); ?>
				</button>
			</div>
		</form>
		<?php
	}

	/**
	 * Render the completed celebration screen.
	 *
	 * @param int $form_id Form post ID if created.
	 */
	private function render_completed_screen( $form_id ) {
		$forms_url     = admin_url( 'edit.php?post_type=gatewaykit_form' );
		$dashboard_url = admin_url( 'admin.php?page=gatewaykit' );
		$edit_url      = $form_id ? admin_url( 'post.php?action=edit&post=' . $form_id ) : '';
		$shortcode     = $form_id ? '[gatewaykit_form id="' . $form_id . '"]' : '';
		?>
		<div class="wizard-completed-card">
			<div class="completed-icon">🎉</div>
			<h2><?php esc_html_e( 'GatewayKit Is Ready to Accept Payments!', 'gatewaykit' ); ?></h2>
			<p class="completed-subtext">
				<?php esc_html_e( 'Your settings and payment gateway are configured. You are ready to embed payment forms across your site.', 'gatewaykit' ); ?>
			</p>

			<?php if ( $form_id > 0 ) : ?>
				<div class="wizard-created-form-box">
					<p style="margin: 0 0 8px 0; font-weight: 600; color: #1e293b;">
						<?php
						/* translators: %d: form ID */
						printf( esc_html__( 'Starter Form Created (ID: %d):', 'gatewaykit' ), (int) $form_id );
						?>
					</p>
					<div class="shortcode-copy-row">
						<input type="text" readonly="readonly" value="<?php echo esc_attr( $shortcode ); ?>" class="shortcode-input" id="gk-created-shortcode" />
						<button type="button" class="button button-secondary" onclick="navigator.clipboard.writeText('<?php echo esc_js( $shortcode ); ?>'); alert('<?php esc_html_e( 'Shortcode copied to clipboard!', 'gatewaykit' ); ?>');">
							<?php esc_html_e( 'Copy Shortcode', 'gatewaykit' ); ?>
						</button>
					</div>
				</div>
			<?php endif; ?>

			<div class="wizard-embed-guides">
				<div class="embed-guide-item">
					<span class="dashicons dashicons-block-default"></span>
					<div>
						<strong><?php esc_html_e( 'Gutenberg Block', 'gatewaykit' ); ?></strong>
						<p><?php esc_html_e( 'Insert the "GatewayKit Payment Form" block on any page for a live visual preview.', 'gatewaykit' ); ?></p>
					</div>
				</div>
				<div class="embed-guide-item">
					<span class="dashicons dashicons-layout"></span>
					<div>
						<strong><?php esc_html_e( 'Elementor Widget', 'gatewaykit' ); ?></strong>
						<p><?php esc_html_e( 'Use the "GatewayKit Payment" widget or Elementor Pro Form Action.', 'gatewaykit' ); ?></p>
					</div>
				</div>
				<div class="embed-guide-item">
					<span class="dashicons dashicons-shortcode"></span>
					<div>
						<strong><?php esc_html_e( 'Shortcode', 'gatewaykit' ); ?></strong>
						<p><?php esc_html_e( 'Paste the shortcode into any page builder, widget, or post.', 'gatewaykit' ); ?></p>
					</div>
				</div>
			</div>

			<div class="completed-buttons">
				<?php if ( $edit_url ) : ?>
					<a href="<?php echo esc_url( $edit_url ); ?>" class="button button-secondary button-hero">
						<?php esc_html_e( 'Customize This Form', 'gatewaykit' ); ?>
					</a>
				<?php endif; ?>
				<a href="<?php echo esc_url( $dashboard_url ); ?>" class="button button-primary button-hero" style="background: #2563eb; border-color: #1d4ed8;">
					<?php esc_html_e( 'Go to GatewayKit Dashboard →', 'gatewaykit' ); ?>
				</a>
			</div>
		</div>
		<?php
	}
}
