<?php
/**
 * Stripe gateway module.
 *
 * @package GatewayKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-gatewaykit-stripe-gateway.php';

return array(
	'id'    => 'stripe',
	'name'  => 'Stripe',
	'class' => 'GatewayKit_Stripe_Gateway',
);
