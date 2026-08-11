<?php
// src/Gateways/Coinify/module.php — Coinify crypto gateway module (Pro).

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-coinify-gateway.php';

// F5: warn the merchant when Coinify is enabled without its API secret,
// because inbound webhooks are rejected (HTTP 403) in that case.
add_action(
	'admin_notices',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$enabled = get_option( 'gatewaykit_enabled_gateways', array() );
		if ( empty( $enabled['coinify'] ) || '1' !== $enabled['coinify'] ) {
			return;
		}
		$gateway = GatewayKit_Gateway_Manager::get_instance()->get_gateway( 'coinify' );
		if ( ! $gateway ) {
			return;
		}
		$settings = $gateway->get_settings();
		if ( empty( $settings['api_secret'] ) ) {
			echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Coinify', 'gatewaykit' ) . ':</strong> '
				. wp_kses(
					sprintf(
						/* translators: %s: gateway settings URL */
						__( 'The API secret is not configured — inbound Coinify webhooks are rejected (HTTP 403) until you add it on the <a href="%s">gateway settings</a>.', 'gatewaykit' ),
						esc_url( admin_url( 'admin.php?page=gatewaykit-settings' ) )
					),
					array( 'a' => array( 'href' => array() ) )
				)
				. '</p></div>';
		}
	}
);

return array(
	'id'    => 'coinify',
	'name'  => 'Coinify',
	'class' => 'GatewayKit_Coinify_Gateway',
);
