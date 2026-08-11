<?php
// src/Gateways/PayPal/module.php — PayPal gateway module.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-paypal-gateway.php';

// F5: warn the merchant when PayPal is enabled without a Webhook ID,
// because inbound webhooks are rejected (HTTP 403) in that case.
add_action(
	'admin_notices',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$enabled = get_option( 'gatewaykit_enabled_gateways', array() );
		if ( empty( $enabled['paypal'] ) || '1' !== $enabled['paypal'] ) {
			return;
		}
		$gateway = GatewayKit_Gateway_Manager::get_instance()->get_gateway( 'paypal' );
		if ( ! $gateway ) {
			return;
		}
		$settings = $gateway->get_settings();
		if ( empty( $settings['webhook_id'] ) ) {
			echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'PayPal', 'gatewaykit' ) . ':</strong> '
				. wp_kses(
					sprintf(
						/* translators: %s: gateway settings URL */
						__( 'The Webhook ID is not configured — inbound PayPal webhooks are rejected (HTTP 403) until you add it on the <a href="%s">gateway settings</a>.', 'gatewaykit' ),
						esc_url( admin_url( 'admin.php?page=gatewaykit-settings' ) )
					),
					array( 'a' => array( 'href' => array() ) )
				)
				. '</p></div>';
		}
	}
);

return array(
	'id'    => 'paypal',
	'name'  => 'PayPal',
	'class' => 'GatewayKit_PayPal_Gateway',
);
