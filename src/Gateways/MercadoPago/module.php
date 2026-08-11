<?php
// src/Gateways/MercadoPago/module.php — Mercado Pago gateway module.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-mercadopago-gateway.php';

// N3: warn the merchant when Mercado Pago is enabled without a webhook
// secret, because inbound webhooks fall back to the weaker API re-fetch
// verification path until one is configured.
add_action(
	'admin_notices',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$enabled = get_option( 'gatewaykit_enabled_gateways', array() );
		if ( empty( $enabled['mercadopago'] ) || '1' !== $enabled['mercadopago'] ) {
			return;
		}
		$gateway = GatewayKit_Gateway_Manager::get_instance()->get_gateway( 'mercadopago' );
		if ( ! $gateway ) {
			return;
		}
		$settings = $gateway->get_settings();
		if ( empty( $settings['webhook_secret'] ) ) {
			echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Mercado Pago', 'gatewaykit' ) . ':</strong> '
				. wp_kses(
					sprintf(
						/* translators: %s: gateway settings URL */
						__( 'The Webhook Secret is not configured — inbound Mercado Pago webhooks fall back to API re-fetch verification (less secure) until you add it on the <a href="%s">gateway settings</a>.', 'gatewaykit' ),
						esc_url( admin_url( 'admin.php?page=gatewaykit-settings' ) )
					),
					array( 'a' => array( 'href' => array() ) )
				)
				. '</p></div>';
		}
	}
);

return array(
	'id'    => 'mercadopago',
	'name'  => 'Mercado Pago',
	'class' => 'GatewayKit_MercadoPago_Gateway',
);
