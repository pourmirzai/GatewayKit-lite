<?php
/**
 * Paystack gateway module.
 *
 * @package GatewayKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-gatewaykit-paystack-gateway.php';

return array(
	'id'    => 'paystack',
	'name'  => 'Paystack',
	'class' => 'GatewayKit_Paystack_Gateway',
);
