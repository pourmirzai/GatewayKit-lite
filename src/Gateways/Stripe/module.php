<?php
// src/Gateways/Stripe/module.php — Stripe gateway module (Pro).

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-stripe-gateway.php';

return array(
	'id'    => 'stripe',
	'name'  => 'Stripe',
	'class' => 'GatewayKit_Stripe_Gateway',
);
