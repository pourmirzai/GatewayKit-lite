<?php
// src/Gateways/Razorpay/module.php — Razorpay gateway module.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-razorpay-gateway.php';

return array(
	'id'    => 'razorpay',
	'name'  => 'Razorpay',
	'class' => 'GatewayKit_Razorpay_Gateway',
);
