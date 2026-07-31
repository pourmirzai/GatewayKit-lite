<?php
/**
 * Log Formatter
 *
 * Converts raw log rows (from GatewayKit_Logger) into human-readable
 * key-value representations and plain-text export strings suitable
 * for pasting into support tickets.
 *
 * @package GatewayKit
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Log Formatter Class
 */
class GatewayKit_Log_Formatter {

	/**
	 * Single instance
	 *
	 * @var GatewayKit_Log_Formatter|null
	 */
	private static $instance = null;

	/**
	 * Sensitive key patterns that should never appear in formatted output.
	 *
	 * @var string[]
	 */
	private $sensitive_patterns = array(
		'password',
		'token',
		'secret',
		'api_key',
		'apikey',
		'authorization',
		'access_token',
		'refresh_token',
		'client_secret',
		'private_key',
		'card_number',
		'cvv',
		'expiry',
		'cardholder',
		'ssn',
		'bank_account',
		'routing_number',
		'credit_card',
		'debit_card',
	);

	/**
	 * Get single instance
	 *
	 * @return GatewayKit_Log_Formatter
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 */
	private function __construct() {
		/**
		 * Allow integrations to extend the sensitive-key denylist.
		 *
		 * @param string[] $sensitive_patterns
		 */
		$extra = apply_filters( 'gatewaykit_log_sensitive_patterns', array() );
		if ( is_array( $extra ) && ! empty( $extra ) ) {
			$this->sensitive_patterns = array_unique( array_merge( $this->sensitive_patterns, array_map( 'strtolower', $extra ) ) );
		}
	}

	/**
	 * Format a single log row into a human-readable key-value array.
	 *
	 * @param object|array $log Log row object/array from the DB.
	 * @return array {
	 *     @type string $id           Log ID.
	 *     @type string $time         Localized timestamp.
	 *     @type string $level        Uppercase level label.
	 *     @type string $level_class  CSS class for the level badge.
	 *     @type string $message      Sanitized message.
	 *     @type int    $transaction_id Transaction ID.
	 *     @type array  $context      Parsed & redacted context (key-value).
	 *     @type string $context_text Plain-text rendering of the context.
	 * }
	 */
	public function format_log_entry( $log ) {
		$log = (object) $log;

		$level       = isset( $log->level ) ? strtolower( (string) $log->level ) : 'info';
		$level_label = $this->get_level_label( $level );
		$context     = $this->format_context( isset( $log->context ) ? $log->context : '' );

		$time = '';
		if ( ! empty( $log->created_at ) ) {
			$time = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $log->created_at ) );
		}

		return array(
			'id'             => isset( $log->id ) ? (int) $log->id : 0,
			'time'           => $time,
			'level'          => $level_label,
			'level_class'    => 'gatewaykit-log-' . $level,
			'message'        => isset( $log->message ) ? sanitize_text_field( $log->message ) : '',
			'transaction_id' => isset( $log->transaction_id ) ? (int) $log->transaction_id : 0,
			'context'        => $context,
			'context_text'   => $this->context_to_text( $context ),
		);
	}

	/**
	 * Parse a context JSON string into a clean key-value array.
	 *
	 * - Decodes JSON (returns empty array on failure).
	 * - Recursively strips sensitive keys.
	 * - Handles nested arrays/objects gracefully.
	 *
	 * @param string|array $context_json JSON string or already-parsed array.
	 * @return array
	 */
	public function format_context( $context_json ) {
		$data = array();

		if ( is_array( $context_json ) ) {
			$data = $context_json;
		} elseif ( is_string( $context_json ) && '' !== trim( $context_json ) ) {
			$decoded = json_decode( $context_json, true );
			if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
				$data = $decoded;
			} else {
				// Not JSON; keep the raw string as a single entry.
				return array( 'raw' => sanitize_text_field( $context_json ) );
			}
		}

		return $this->sanitize_context_recursive( $data );
	}

	/**
	 * Render a list of logs as plain text for export / copy-paste.
	 *
	 * @param array $logs              Array of log row objects.
	 * @param bool  $include_metadata  Whether to include id/time/level metadata.
	 * @return string
	 */
	public function format_for_export( $logs, $include_metadata = true ) {
		if ( empty( $logs ) ) {
			return __( 'No logs to export.', 'gatewaykit' );
		}

		$lines   = array();
		$lines[] = '=== GatewayKit Logs (' . count( $logs ) . ' entries) ===';
		$lines[] = sprintf(
			/* translators: export generation timestamp */
			__( 'Generated: %s', 'gatewaykit' ),
			current_time( 'Y-m-d H:i:s' )
		);
		$lines[] = '';

		foreach ( $logs as $log ) {
			$entry = $this->format_log_entry( $log );

			if ( $include_metadata ) {
				$lines[] = sprintf( '#%d  [%s]  %s', $entry['id'], $entry['level'], $entry['time'] );
				if ( ! empty( $entry['transaction_id'] ) ) {
					$lines[] = sprintf( '%s: %d', __( 'Transaction', 'gatewaykit' ), $entry['transaction_id'] );
				}
				$lines[] = sprintf( '%s: %s', __( 'Message', 'gatewaykit' ), $entry['message'] );
			} else {
				$lines[] = sprintf( '%s: %s', __( 'Message', 'gatewaykit' ), $entry['message'] );
			}

			if ( ! empty( $entry['context_text'] ) ) {
				$lines[] = sprintf( '%s:', __( 'Context', 'gatewaykit' ) );
				$lines[] = $entry['context_text'];
			}

			$lines[] = str_repeat( '-', 40 );
		}

		return implode( "\n", $lines );
	}

	/**
	 * Get a translated, capitalized label for a log level.
	 *
	 * @param string $level Log level (debug|info|warning|error).
	 * @return string
	 */
	public function get_level_label( $level ) {
		$labels = array(
			'debug'   => __( 'Debug', 'gatewaykit' ),
			'info'    => __( 'Info', 'gatewaykit' ),
			'warning' => __( 'Warning', 'gatewaykit' ),
			'error'   => __( 'Error', 'gatewaykit' ),
		);

		return isset( $labels[ $level ] ) ? $labels[ $level ] : ucfirst( $level );
	}

	/**
	 * Render a context array as readable, indented plain text.
	 *
	 * @param array $context Sanitized context array.
	 * @param int   $depth   Current indentation depth.
	 * @return string
	 */
	private function context_to_text( $context, $depth = 0 ) {
		if ( empty( $context ) ) {
			return '';
		}

		$indent  = str_repeat( '  ', $depth );
		$lines   = array();

		foreach ( $context as $key => $value ) {
			$key = sanitize_text_field( (string) $key );

			if ( is_array( $value ) ) {
				$lines[] = $indent . $key . ':';
				$nested  = $this->context_to_text( $value, $depth + 1 );
				if ( '' !== $nested ) {
					$lines[] = $nested;
				}
			} else {
				$lines[] = $indent . $key . ': ' . $this->scalar_to_text( $value );
			}
		}

		return implode( "\n", $lines );
	}

	/**
	 * Convert a scalar value to its text representation.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function scalar_to_text( $value ) {
		if ( null === $value ) {
			return 'null';
		}
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		if ( is_scalar( $value ) ) {
			return sanitize_text_field( (string) $value );
		}
		return '';
	}

	/**
	 * Recursively sanitize a context array, redacting sensitive keys.
	 *
	 * @param mixed $data Data to sanitize.
	 * @return array|string
	 */
	private function sanitize_context_recursive( $data ) {
		if ( ! is_array( $data ) ) {
			return is_scalar( $data ) ? sanitize_text_field( (string) $data ) : '';
		}

		$sanitized = array();

		foreach ( $data as $key => $value ) {
			$key_lower = strtolower( (string) $key );

			if ( $this->is_sensitive_key( $key_lower ) ) {
				$sanitized[ $key ] = '[REDACTED]';
				continue;
			}

			if ( is_array( $value ) ) {
				$sanitized[ $key ] = $this->sanitize_context_recursive( $value );
			} elseif ( is_scalar( $value ) ) {
				$sanitized[ $key ] = sanitize_text_field( (string) $value );
			} else {
				$sanitized[ $key ] = '';
			}
		}

		return $sanitized;
	}

	/**
	 * Determine whether a key name matches any sensitive pattern.
	 *
	 * @param string $key_lower Lowercased key.
	 * @return bool
	 */
	private function is_sensitive_key( $key_lower ) {
		foreach ( $this->sensitive_patterns as $pattern ) {
			if ( false !== strpos( $key_lower, $pattern ) ) {
				return true;
			}
		}
		return false;
	}
}
