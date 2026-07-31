<?php
// src/Gateways/NOWPayments/module.php — NOWPayments crypto gateway module (Lite).

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-nowpayments-gateway.php';

return array(
	'id'    => 'nowpayments',
	'name'  => 'NOWPayments',
	'class' => 'GatewayKit_NOWPayments_Gateway',
);
