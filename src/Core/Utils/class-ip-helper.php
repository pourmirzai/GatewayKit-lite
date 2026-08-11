<?php
/**
 * IP Helper
 *
 * Centralized, spoof-resistant IP address handling.
 *
 * Security (F12): forwarded headers (X-Forwarded-For, CF-Connecting-IP, …)
 * are only consulted when the direct REMOTE_ADDR is inside a CIDR configured
 * in the `gatewaykit_trusted_proxies` option. On a default install this is
 * empty, so spoofing X-Forwarded-For via curl cannot bypass the per-IP rate
 * limiter. Sites behind Cloudflare / a reverse proxy add the proxy CIDRs in
 * Settings and the appropriate forwarded header is honoured again.
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
	 * Cached parsed trusted-proxy CIDR list for the current request.
	 *
	 * @var array|null
	 */
	private static $trusted_proxies = null;

	/**
	 * Get client IP address.
	 *
	 * @return string Client IP (never empty; falls back to '127.0.0.1').
	 */
	public static function get_client_ip() {
		// REMOTE_ADDR is the actual TCP-level peer — the only header that
		// cannot be spoofed by the client on a default install.
		$remote_addr = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';

		if ( '' !== $remote_addr && filter_var( $remote_addr, FILTER_VALIDATE_IP ) ) {
			// If the peer is itself a configured trusted proxy, the real
			// client IP is in one of the forwarded headers.
			if ( self::is_trusted_proxy( $remote_addr ) ) {
				$forwarded = self::get_forwarded_ip();
				if ( '' !== $forwarded ) {
					return $forwarded;
				}
			}
			return $remote_addr;
		}

		// Last-resort fallback (CLI / misconfigured server).
		return '127.0.0.1';
	}

	/**
	 * Read the first valid IP from the standard forwarded headers.
	 *
	 * Order: CF-Connecting-IP → X-Forwarded-For → Client-IP → the other
	 * Forwarded variants. X-Forwarded-For is parsed left-to-right (the
	 * leftmost entry is the original client); each candidate is validated
	 * and public/private ranges are accepted as-is (the trust decision is
	 * already made by is_trusted_proxy()).
	 *
	 * @return string Valid IP, or '' when none of the headers yield one.
	 */
	private static function get_forwarded_ip() {
		$header_priority = array(
			'HTTP_CF_CONNECTING_IP',
			'HTTP_X_FORWARDED_FOR',
			'HTTP_CLIENT_IP',
			'HTTP_X_FORWARDED',
			'HTTP_X_CLUSTER_CLIENT_IP',
			'HTTP_FORWARDED_FOR',
			'HTTP_FORWARDED',
		);

		foreach ( $header_priority as $header ) {
			if ( empty( $_SERVER[ $header ] ) ) {
				continue;
			}
			$value = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );

			// X-Forwarded-For may be a chain "client, proxy1, proxy2". The
			// leftmost non-empty entry is the originating client.
			foreach ( explode( ',', $value ) as $candidate ) {
				$candidate = trim( $candidate );
				if ( '' !== $candidate && filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
					return $candidate;
				}
			}
		}

		return '';
	}

	/**
	 * Whether the given (direct) IP is configured as a trusted proxy.
	 *
	 * Reads the `gatewaykit_trusted_proxies` option once per request and
	 * matches against CIDR / single-IP entries (one per line). Both IPv4 and
	 * IPv6 are supported.
	 *
	 * @param string $ip Validated IP address (the REMOTE_ADDR candidate).
	 * @return bool
	 */
	private static function is_trusted_proxy( $ip ) {
		if ( null === self::$trusted_proxies ) {
			$raw  = get_option( 'gatewaykit_trusted_proxies', '' );
			$raw  = is_string( $raw ) ? $raw : '';
			$list = array();
			foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
				$line = trim( $line );
				if ( '' !== $line ) {
					$list[] = $line;
				}
			}
			self::$trusted_proxies = $list;
		}

		if ( empty( self::$trusted_proxies ) ) {
			return false;
		}

		foreach ( self::$trusted_proxies as $cidr ) {
			if ( self::ip_in_range( $ip, $cidr ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check whether an IP falls inside a CIDR (IPv4 or IPv6) or matches a
	 * single IP literally.
	 *
	 * @param string $ip   Validated IP address.
	 * @param string $range CIDR (e.g. "173.245.48.0/20") or a single IP.
	 * @return bool
	 */
	private static function ip_in_range( $ip, $range ) {
		if ( false === strpos( $range, '/' ) ) {
			return $ip === $range;
		}

		list( $range_ip, $netmask ) = explode( '/', $range, 2 );
		$netmask = (int) $netmask;

		$is_ipv6      = false !== strpos( $range_ip, ':' );
		$ip_is_ipv6   = false !== strpos( $ip, ':' );

		// IPv4 vs IPv6 mismatch — not a match.
		if ( $is_ipv6 !== $ip_is_ipv6 ) {
			return false;
		}

		if ( $is_ipv6 ) {
			$range_bin = inet_pton( $range_ip );
			$ip_bin    = inet_pton( $ip );
			if ( false === $range_bin || false === $ip_bin ) {
				return false;
			}

			if ( $netmask < 0 || $netmask > 128 ) {
				return false;
			}

			$bytes_needed = (int) ceil( $netmask / 8 );
			$bits_remain  = $netmask % 8;

			// Compare the fully-covered bytes.
			if ( $bytes_needed > 0 && substr( $ip_bin, 0, $bytes_needed ) !== substr( $range_bin, 0, $bytes_needed ) ) {
				return false;
			}

			// Compare the partial byte's high bits.
			if ( 0 !== $bits_remain && isset( $ip_bin[ $bytes_needed ] ) ) {
				$mask = ( 0xFF << ( 8 - $bits_remain ) ) & 0xFF;
				if ( ( ord( $ip_bin[ $bytes_needed ] ) & $mask ) !== ( ord( $range_bin[ $bytes_needed ] ) & $mask ) ) {
					return false;
				}
			}

			return true;
		}

		// IPv4.
		$range_long = ip2long( $range_ip );
		$ip_long    = ip2long( $ip );
		if ( false === $range_long || false === $ip_long ) {
			return false;
		}
		if ( $netmask < 0 || $netmask > 32 ) {
			return false;
		}

		$mask = -1 << ( 32 - $netmask );
		// Reinterpret as unsigned 32-bit.
		$mask  = $mask & 0xFFFFFFFF;
		$range = $range_long & 0xFFFFFFFF;
		$ip_u  = $ip_long & 0xFFFFFFFF;

		return ( $ip_u & $mask ) === ( $range & $mask );
	}
}
