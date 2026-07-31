<?php
/**
 * IP Helper
 *
 * Centralized IP address handling
 *
 * @package GatewayKit
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * IP Helper Class
 */
class GatewayKit_IP_Helper {

	/**
	 * Get client IP address
	 *
	 * @return string Client IP
	 */
	public static function get_client_ip() {
		$ip_headers = array(
			'HTTP_CF_CONNECTING_IP',
			'HTTP_CLIENT_IP',
			'HTTP_X_FORWARDED_FOR',
			'HTTP_X_FORWARDED',
			'HTTP_X_CLUSTER_CLIENT_IP',
			'HTTP_FORWARDED_FOR',
			'HTTP_FORWARDED',
			'REMOTE_ADDR',
		);

		foreach ( $ip_headers as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );

				// Handle comma-separated IPs (like X-Forwarded-For)
				if ( strpos( $ip, ',' ) !== false ) {
					$ip = trim( explode( ',', $ip )[0] );
				}

				// Validate IP - using the strict flags we want to enforce
				if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
					return $ip;
				}
			}
		}

		return '127.0.0.1'; // Fallback
	}

	/**
		* Check if IP is from private range
		*
		* @param string $ip IP address to check
		* @return bool True if private IP
		*/
	public static function is_private_ip( $ip ) {
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false;
		}

		$private_ranges = array(
			'10.0.0.0/8',
			'172.16.0.0/12',
			'192.168.0.0/16',
			'127.0.0.0/8',
			'169.254.0.0/16',
			'::1/128',
			'fc00::/7',
			'fe80::/10',
		);

		foreach ( $private_ranges as $range ) {
			if ( self::ip_in_range( $ip, $range ) ) {
				return true;
			}
		}

		return false;
	}

	/**
		* Check if IP is in given range
		*
		* @param string $ip IP address
		* @param string $range IP range in CIDR notation
		* @return bool True if IP is in range
		*/
	private static function ip_in_range( $ip, $range ) {
		if ( strpos( $range, '/' ) === false ) {
			return $ip === $range;
		}

		list( $range_ip, $netmask ) = explode( '/', $range );
		$range_decimal = ip2long( $range_ip );
		$ip_decimal = ip2long( $ip );
		$wildcard_decimal = pow( 2, ( 32 - $netmask ) ) - 1;
		$netmask_decimal = ~ $wildcard_decimal;

		return ( $ip_decimal & $netmask_decimal ) === ( $range_decimal & $netmask_decimal );
	}
}
