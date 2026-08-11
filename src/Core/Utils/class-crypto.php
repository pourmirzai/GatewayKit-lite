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
 * Security (F8): when neither SECURE_AUTH_KEY nor AUTH_KEY is defined the key
 * derivation returns an empty string and encrypt()/decrypt() refuse to
 * operate (return ''). Previously the code fell back to get_site_url() — a
 * fully public, deterministic value — which made "encrypted" secrets trivially
 * decryptable by anyone knowing the site URL. On a healthy WP install these
 * constants always exist; the plugin surfaces an admin notice when they are
 * missing.
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
	 * Whether crypto can operate securely.
	 *
	 * Returns true only when at least one of the WordPress secret constants
	 * (SECURE_AUTH_KEY / AUTH_KEY) is defined and non-empty. Gateways and the
	 * admin UI use this to surface a clear "secrets cannot be protected"
	 * warning and to mark themselves unavailable (F8).
	 *
	 * @return bool
	 */
	public static function is_available() {
		return ( defined( 'SECURE_AUTH_KEY' ) && SECURE_AUTH_KEY )
			|| ( defined( 'AUTH_KEY' ) && AUTH_KEY );
	}

	/**
	 * Derive a 32-byte key from the site secret.
	 *
	 * Returns an empty string when neither SECURE_AUTH_KEY nor AUTH_KEY is
	 * available; encrypt()/decrypt() treat that as "refuse to operate" (F8).
	 *
	 * @return string Raw 32-byte key, or '' when no auth key is configured.
	 */
	private static function get_key() {
		$secret = '';
		if ( defined( 'SECURE_AUTH_KEY' ) && SECURE_AUTH_KEY ) {
			$secret = SECURE_AUTH_KEY;
		} elseif ( defined( 'AUTH_KEY' ) && AUTH_KEY ) {
			$secret = AUTH_KEY;
		}

		if ( '' === $secret ) {
			// F8: refuse to derive a key from a public value. Every healthy
			// WP install has these constants; an admin notice points the site
			// owner at wp-config.php when they are missing.
			return '';
		}

		// Sha256 yields exactly 32 bytes for aes-256-cbc.
		return hash( 'sha256', $secret, true );
	}

	/**
	 * Encrypt a plaintext secret.
	 *
	 * @param string $value Plaintext.
	 * @return string Encrypted, base64-encoded bundle (iv + ciphertext),
	 *                or '' when the value is empty or crypto is unavailable.
	 */
	public static function encrypt( $value ) {
		if ( '' === (string) $value ) {
			return '';
		}

		$key = self::get_key();
		if ( '' === $key ) {
			self::log_unavailable();
			return '';
		}

		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return (string) $value;
		}

		$iv_len = openssl_cipher_iv_length( self::CIPHER );
		$iv     = function_exists( 'random_bytes' ) ? random_bytes( $iv_len ) : openssl_random_pseudo_bytes( $iv_len );

		$encrypted = openssl_encrypt( (string) $value, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );
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
	 * @return string Plaintext, or empty string when undecryptable or when
	 *                crypto is unavailable (no AUTH key configured).
	 */
	public static function decrypt( $bundle ) {
		$bundle = (string) $bundle;
		if ( '' === $bundle ) {
			return '';
		}

		$key = self::get_key();
		if ( '' === $key ) {
			// F8: cannot securely decrypt — fail safe to an empty secret so
			// gateways surface as unavailable instead of trusting a value
			// derived from a public string.
			self::log_unavailable();
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
		$decrypted = openssl_decrypt( $cipher, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );

		return ( false === $decrypted ) ? '' : $decrypted;
	}

	/**
	 * Log that crypto operations are unavailable due to missing WP auth keys.
	 */
	private static function log_unavailable() {
		if ( class_exists( 'GatewayKit_Logger' ) ) {
			GatewayKit_Logger::get_instance()->error(
				'GatewayKit crypto unavailable: SECURE_AUTH_KEY / AUTH_KEY constants are missing in wp-config.php — gateway secrets cannot be encrypted or decrypted'
			);
		}
	}
}
