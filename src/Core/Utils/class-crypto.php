<?php
/**
 * GatewayKit Crypto Helper
 *
 * Encrypts/decrypts gateway secrets (e.g. PayPal client secret) at rest using
 * AES-256-CBC. This mirrors the Pro add-on's GatewayKit_Pro_Crypto so that the
 * Lite plugin can protect sensitive credentials without depending on the Pro
 * add-on being active.
 *
 * The encryption key is derived deterministically from the site's
 * SECURE_AUTH_KEY constant (defined in wp-config.php) via sha256 so it is
 * exactly 32 bytes for aes-256-cbc. This keeps secrets unreadable in the
 * database while requiring no extra configuration.
 *
 * @package GatewayKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Crypto helper class.
 */
class GatewayKit_Crypto {

	/**
	 * Cipher method.
	 */
	const CIPHER = 'aes-256-cbc';

	/**
	 * Derive a 32-byte key from the site secret.
	 *
	 * Falls back to a known-unique site value when SECURE_AUTH_KEY is not
	 * available (it always is on a properly configured WP install).
	 *
	 * @return string Raw 32-byte key.
	 */
	private static function get_key() {
		$secret = '';
		if ( defined( 'SECURE_AUTH_KEY' ) && SECURE_AUTH_KEY ) {
			$secret = SECURE_AUTH_KEY;
		} elseif ( defined( 'AUTH_KEY' ) && AUTH_KEY ) {
			$secret = AUTH_KEY;
		} else {
			// Last-resort fallback: site URL + a salt option. This is weaker
			// than a proper auth key but still better than plaintext storage.
			$secret = get_site_url() . '|gatewaykit-secret';
		}

		// Sha256 yields exactly 32 bytes for aes-256-cbc.
		return hash( 'sha256', $secret, true );
	}

	/**
	 * Encrypt a plaintext secret.
	 *
	 * @param string $value Plaintext.
	 * @return string Encrypted, base64-encoded bundle (iv + ciphertext).
	 */
	public static function encrypt( $value ) {
		if ( '' === (string) $value ) {
			return '';
		}

		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return (string) $value;
		}

		$iv_len = openssl_cipher_iv_length( self::CIPHER );
		$iv     = function_exists( 'random_bytes' ) ? random_bytes( $iv_len ) : openssl_random_pseudo_bytes( $iv_len );

		$encrypted = openssl_encrypt( (string) $value, self::CIPHER, self::get_key(), OPENSSL_RAW_DATA, $iv );
		if ( false === $encrypted ) {
			return '';
		}

		return base64_encode( $iv . $encrypted );
	}

	/**
	 * Decrypt a previously encrypted bundle.
	 *
	 * Transparently returns unencrypted (legacy / fallback) values as-is so
	 * existing plaintext settings keep working.
	 *
	 * @param string $bundle Encrypted base64 bundle.
	 * @return string Plaintext, or empty string when undecryptable.
	 */
	public static function decrypt( $bundle ) {
		$bundle = (string) $bundle;
		if ( '' === $bundle ) {
			return '';
		}

		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return $bundle;
		}

		$raw = base64_decode( $bundle, true );
		if ( false === $raw ) {
			return $bundle;
		}

		$iv_len = openssl_cipher_iv_length( self::CIPHER );
		if ( strlen( $raw ) <= $iv_len ) {
			return $bundle;
		}

		$iv        = substr( $raw, 0, $iv_len );
		$cipher    = substr( $raw, $iv_len );
		$decrypted = openssl_decrypt( $cipher, self::CIPHER, self::get_key(), OPENSSL_RAW_DATA, $iv );

		return ( false === $decrypted ) ? '' : $decrypted;
	}
}
