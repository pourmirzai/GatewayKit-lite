<?php
/**
 * Razorpay gateway module.
 *
 * @package GatewayKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-gatewaykit-razorpay-gateway.php';

return array(
	'id'    => 'razorpay',
	'name'  => 'Razorpay',
	'class' => 'GatewayKit_Razorpay_Gateway',
);
