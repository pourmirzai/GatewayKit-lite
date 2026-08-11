<?php
/**
 * Payment Links (Stripe)
 *
 * Lets merchants generate shareable Stripe Payment Links for a fixed amount
 * without needing an Elementor form. Useful for invoices, WhatsApp/Telegram
 * sales, and email links.
 *
 * Payments made through a generated link go directly to the merchant's Stripe
 * account and are NOT tracked by GatewayKit (they bypass the Elementor form
 * flow) — this is a convenience feature, not a tracked transaction.
 *
 * @package GatewayKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Payment Links admin page.
 */
class GatewayKit_Payment_Links {

	/**
	 * Menu slug.
	 */
	const PAGE_SLUG = 'gatewaykit-payment-links';

	/**
	 * Maximum number of generated links kept in the option.
	 */
	const MAX_LINKS = 50;

	/**
	 * Constructor — hooks admin menu and AJAX handler.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_gatewaykit_create_payment_link', array( $this, 'ajax_create_payment_link' ) );
	}

	/**
	 * Register the Payment Links submenu under GatewayKit.
	 */
	public function add_menu() {
		if ( ! is_admin() ) {
			return;
		}

		add_submenu_page(
			'gatewaykit',
			__( 'Payment Links', 'gatewaykit' ),
			__( 'Payment Links', 'gatewaykit' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue payment-link assets only on this page.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( false === strpos( $hook_suffix, self::PAGE_SLUG ) ) {
			return;
		}

		$min = defined( 'WP_DEBUG' ) && WP_DEBUG ? '' : '.min';

		wp_enqueue_style(
			'gatewaykit-admin-styles',
			GATEWAYKIT_PLUGIN_URL . 'assets/css/admin' . $min . '.css',
			array(),
			GATEWAYKIT_VERSION
		);

		wp_enqueue_script(
			'gatewaykit-payment-links',
			GATEWAYKIT_PLUGIN_URL . 'assets/js/payment-links' . $min . '.js',
			array( 'jquery' ),
			GATEWAYKIT_VERSION,
			true
		);

		wp_localize_script(
			'gatewaykit-payment-links',
			'GatewayKitPaymentLinks',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'gatewaykit_payment_link_nonce' ),
				'i18n'     => array(
					'generating' => __( 'Generating link...', 'gatewaykit' ),
					'generate'   => __( 'Generate Payment Link', 'gatewaykit' ),
					'copy'       => __( 'Copy', 'gatewaykit' ),
					'open'       => __( 'Open', 'gatewaykit' ),
					'copied'     => __( 'Copied!', 'gatewaykit' ),
					'error'      => __( 'An error occurred. Please try again.', 'gatewaykit' ),
				),
			)
		);
	}

	/**
	 * Render the Payment Links page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'gatewaykit' ) );
		}

		$manager          = GatewayKit_Gateway_Manager::get_instance();
		$currencies       = $manager->get_available_currencies();
		$default_currency = strtoupper( get_option( 'gatewaykit_currency', $manager->get_default_currency() ) );

		$stripe       = $manager->get_gateway( 'stripe' );
		$stripe_ready = ( $stripe && $this->is_gateway_enabled( 'stripe' ) && $stripe->is_available() );

		$links = get_option( 'gatewaykit_payment_links', array() );
		if ( ! is_array( $links ) ) {
			$links = array();
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'GatewayKit Payment Links', 'gatewaykit' ); ?></h1>
			<p><?php esc_html_e( 'Generate a shareable Stripe Payment Link for a fixed amount — no Elementor form needed. Send it by email, WhatsApp, or Telegram to collect payment.', 'gatewaykit' ); ?></p>

			<?php if ( ! $stripe_ready ) : ?>
				<div class="notice notice-warning inline">
					<p>
						<?php
						printf(
							/* translators: %s: GatewayKit settings page URL */
							esc_html__( 'Stripe must be enabled and configured with a Secret Key before you can generate payment links. Configure it in %s.', 'gatewaykit' ),
							'<a href="' . esc_url( admin_url( 'admin.php?page=gatewaykit-settings&tab=gateways' ) ) . '">' . esc_html__( 'Gateway Settings', 'gatewaykit' ) . '</a>'
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<form id="gatewaykit-payment-link-form" method="post">
				<?php wp_nonce_field( 'gatewaykit_payment_link_nonce', 'gatewaykit_payment_link_nonce' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="gk-pl-amount"><?php esc_html_e( 'Amount', 'gatewaykit' ); ?></label>
						</th>
						<td>
							<input type="number" name="amount" id="gk-pl-amount" class="regular-text" step="0.01" min="0.01" required />
							<p class="description"><?php esc_html_e( 'The amount to collect. Must be greater than zero.', 'gatewaykit' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="gk-pl-currency"><?php esc_html_e( 'Currency', 'gatewaykit' ); ?></label>
						</th>
						<td>
							<select name="currency" id="gk-pl-currency">
								<?php foreach ( $currencies as $code => $label ) : ?>
									<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $code, $default_currency ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="gk-pl-name"><?php esc_html_e( 'Product / Service Name', 'gatewaykit' ); ?></label>
						</th>
						<td>
							<input type="text" name="name" id="gk-pl-name" class="regular-text" maxlength="200" required />
							<p class="description"><?php esc_html_e( 'Shown to the customer on the Stripe checkout page.', 'gatewaykit' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="gk-pl-description"><?php esc_html_e( 'Description', 'gatewaykit' ); ?></label>
						</th>
						<td>
							<textarea name="description" id="gk-pl-description" class="large-text" rows="3" maxlength="2000"></textarea>
							<p class="description"><?php esc_html_e( 'Optional. A short description of the product or service.', 'gatewaykit' ); ?></p>
						</td>
					</tr>
				</table>

				<p>
					<button type="submit" class="button button-primary" id="gk-pl-generate">
						<?php esc_html_e( 'Generate Payment Link', 'gatewaykit' ); ?>
					</button>
				</p>
			</form>

			<div id="gatewaykit-pl-result"></div>

			<?php if ( ! empty( $links ) ) : ?>
				<h2><?php esc_html_e( 'Recently Generated Links', 'gatewaykit' ); ?></h2>
				<table class="widefat striped gatewaykit-pl-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Product / Service', 'gatewaykit' ); ?></th>
							<th><?php esc_html_e( 'Amount', 'gatewaykit' ); ?></th>
							<th><?php esc_html_e( 'Created', 'gatewaykit' ); ?></th>
							<th><?php esc_html_e( 'Link', 'gatewaykit' ); ?></th>
						</tr>
					</thead>
					<tbody id="gatewaykit-pl-list">
						<?php foreach ( $links as $link ) : ?>
							<?php $this->render_link_row( $link ); ?>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render a single row of the generated-links table.
	 *
	 * @param array $link Link entry: amount, currency, name, url, created_at.
	 */
	private function render_link_row( $link ) {
		$name     = isset( $link['name'] ) ? $link['name'] : '';
		$amount   = isset( $link['amount'] ) ? (float) $link['amount'] : 0;
		$currency = isset( $link['currency'] ) ? strtoupper( $link['currency'] ) : '';
		$url      = isset( $link['url'] ) ? $link['url'] : '';
		$created  = isset( $link['created_at'] ) ? $link['created_at'] : '';
		?>
		<tr>
			<td><?php echo esc_html( $name ); ?></td>
			<td><?php echo esc_html( number_format_i18n( $amount, 2 ) . ' ' . $currency ); ?></td>
			<td><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $created ) ) ); ?></td>
			<td>
				<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer" class="button button-small"><?php esc_html_e( 'Open', 'gatewaykit' ); ?></a>
				<button type="button" class="button button-small gatewaykit-pl-copy" data-copy="<?php echo esc_attr( $url ); ?>"><?php esc_html_e( 'Copy', 'gatewaykit' ); ?></button>
			</td>
		</tr>
		<?php
	}

	/**
	 * AJAX handler: create a Stripe Payment Link.
	 */
	public function ajax_create_payment_link() {
		// Rate limiting check.
		$rate_limiter = GatewayKit_Rate_Limiter::get_instance();
		$rate_check   = $rate_limiter->check_rate_limit( 'admin_ajax' );
		if ( is_wp_error( $rate_check ) ) {
			wp_send_json_error( $rate_check->get_error_message() );
		}

		// Verify nonce.
		check_ajax_referer( 'gatewaykit_payment_link_nonce', 'nonce' );

		// Check capability.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'You do not have sufficient permissions to perform this action.', 'gatewaykit' ) );
		}

		$amount      = isset( $_POST['amount'] ) ? floatval( wp_unslash( $_POST['amount'] ) ) : 0;
		$currency    = isset( $_POST['currency'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['currency'] ) ) ) : '';
		$name        = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$description = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';

		if ( $amount <= 0 ) {
			wp_send_json_error( __( 'Please enter an amount greater than zero.', 'gatewaykit' ) );
		}
		if ( '' === $currency ) {
			wp_send_json_error( __( 'Please select a currency.', 'gatewaykit' ) );
		}
		if ( '' === $name ) {
			wp_send_json_error( __( 'Please enter a product or service name.', 'gatewaykit' ) );
		}

		// Validate the currency against the available list.
		$manager    = GatewayKit_Gateway_Manager::get_instance();
		$currencies = $manager->get_available_currencies();
		if ( ! isset( $currencies[ $currency ] ) ) {
			wp_send_json_error( __( 'The selected currency is not supported.', 'gatewaykit' ) );
		}

		// Stripe must be registered, enabled, and configured.
		$stripe = $manager->get_gateway( 'stripe' );
		if ( ! $stripe || ! $this->is_gateway_enabled( 'stripe' ) || ! $stripe->is_available() ) {
			wp_send_json_error( __( 'Stripe is not enabled or configured. Enable Stripe and save a Secret Key before generating payment links.', 'gatewaykit' ) );
		}

		if ( ! method_exists( $stripe, 'create_payment_link' ) ) {
			wp_send_json_error( __( 'Payment links are not available for the Stripe gateway.', 'gatewaykit' ) );
		}

		$response = $stripe->create_payment_link( $amount, $currency, $name, $description );

		if ( is_wp_error( $response ) ) {
			GatewayKit_Logger::get_instance()->warning( 'Stripe payment link creation failed', array( 'message' => $response->get_error_message() ) );
			wp_send_json_error( $response->get_error_message() );
		}

		$url = isset( $response['url'] ) ? $response['url'] : '';
		if ( '' === $url ) {
			wp_send_json_error( __( 'Stripe did not return a payment link URL.', 'gatewaykit' ) );
		}

		$entry = array(
			'amount'     => round( $amount, 2 ),
			'currency'   => $currency,
			'name'       => $name,
			'url'        => esc_url_raw( $url ),
			'created_at' => current_time( 'mysql' ),
		);

		$links = get_option( 'gatewaykit_payment_links', array() );
		if ( ! is_array( $links ) ) {
			$links = array();
		}
		array_unshift( $links, $entry );
		update_option( 'gatewaykit_payment_links', array_slice( $links, 0, self::MAX_LINKS ) );

		wp_send_json_success( array( 'url' => $entry['url'], 'entry' => $entry ) );
	}

	/**
	 * Whether a gateway is enabled in the admin settings.
	 *
	 * @param string $gateway_id Gateway ID.
	 * @return bool
	 */
	private function is_gateway_enabled( $gateway_id ) {
		$enabled = get_option( 'gatewaykit_enabled_gateways', array() );
		if ( ! is_array( $enabled ) ) {
			return false;
		}
		return isset( $enabled[ $gateway_id ] ) && '1' === (string) $enabled[ $gateway_id ];
	}
}
