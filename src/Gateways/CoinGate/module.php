<?php
// src/Gateways/CoinGate/module.php — CoinGate crypto gateway module (Pro).

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-coingate-gateway.php';

return array(
	'id'    => 'coingate',
	'name'  => 'CoinGate',
	'class' => 'GatewayKit_CoinGate_Gateway',
);
