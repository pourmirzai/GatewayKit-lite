<?php
/**
 * NOWPayments crypto gateway module.
 *
 * @package GatewayKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-gatewaykit-nowpayments-gateway.php';

return array(
	'id'    => 'nowpayments',
	'name'  => 'NOWPayments',
	'class' => 'GatewayKit_NOWPayments_Gateway',
);
