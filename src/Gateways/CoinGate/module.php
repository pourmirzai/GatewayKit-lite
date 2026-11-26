<?php
/**
 * CoinGate crypto gateway module.
 *
 * @package GatewayKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-gatewaykit-coingate-gateway.php';

return array(
	'id'    => 'coingate',
	'name'  => 'CoinGate',
	'class' => 'GatewayKit_CoinGate_Gateway',
);
