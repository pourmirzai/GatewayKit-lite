<?php
// src/Gateways/PayPal/module.php — PayPal gateway module.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-paypal-gateway.php';

return array(
	'id'    => 'paypal',
	'name'  => 'PayPal',
	'class' => 'GatewayKit_PayPal_Gateway',
);
