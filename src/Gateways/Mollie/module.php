<?php
/**
 * Mollie gateway module.
 *
 * @package GatewayKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-gatewaykit-mollie-gateway.php';

return array(
	'id'    => 'mollie',
	'name'  => 'Mollie',
	'class' => 'GatewayKit_Mollie_Gateway',
);
