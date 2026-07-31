<?php
// src/Gateways/Coinify/module.php — Coinify crypto gateway module (Pro).

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-coinify-gateway.php';

return array(
	'id'    => 'coinify',
	'name'  => 'Coinify',
	'class' => 'GatewayKit_Coinify_Gateway',
);
