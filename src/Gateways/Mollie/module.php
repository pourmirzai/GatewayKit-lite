<?php
// src/Gateways/Mollie/module.php — Mollie gateway module (Pro).

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-mollie-gateway.php';

return array(
	'id'    => 'mollie',
	'name'  => 'Mollie',
	'class' => 'GatewayKit_Mollie_Gateway',
);
