<?php
// src/Gateways/Paystack/module.php — Paystack gateway module.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-paystack-gateway.php';

return array(
	'id'    => 'paystack',
	'name'  => 'Paystack',
	'class' => 'GatewayKit_Paystack_Gateway',
);
