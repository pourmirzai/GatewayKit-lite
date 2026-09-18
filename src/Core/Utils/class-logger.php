<?php
/**
 * Logger
 *
 * Handles logging for the plugin
 *
 * @package GatewayKit
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Logger Class
 */
class GatewayKit_Logger {

	/*
	 * This logger persists entries to the gatewaykit_payment_logs table. The
	 * table name is always the hardcoded base 'gatewaykit_payment_logs'
	 * concatenated with $wpdb->prefix (internal, no user input), and all
	 * user-supplied filter values use $wpdb->prepare() placeholders. The DB
	 * coding-standard sniffs cannot trace this, so they are disabled for the
	 * whole class. The error_log() calls are intentional: one is the fallback
	 * sink when the DB insert itself fails, the other writes to the WordPress
	 * debug log (the logger's documented WP_DEBUG output).
	 */
	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.UnsupportedIdentifierPlaceholder, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.PHP.DevelopmentFunctions.error_log_error_log

	/**
	 * Single instance
	 */
	private static $instance = null;

	/**
	 * Log levels
	 */
	const LEVEL_DEBUG   = 'debug';
	const LEVEL_INFO    = 'info';
	const LEVEL_WARNING = 'warning';
	const LEVEL_ERROR   = 'error';

	/**
	 * Minimum log level to write
	 */
	private $min_level = self::LEVEL_INFO;

	/**
	 * Get single instance
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
		$this->min_level = get_option( 'gatewaykit_log_level', self::LEVEL_INFO );
	}

	/**
	 * Log debug message
	 *
	 * @param string $message Log message
	 * @param array  $context Context data
	 * @param int    $transaction_id Transaction ID
	 */
	public function debug( $message, $context = array(), $transaction_id = null ) {
		$this->log( self::LEVEL_DEBUG, $message, $context, $transaction_id );
	}

	/**
	 * Log info message
	 *
	 * @param string $message Log message
	 * @param array  $context Context data
	 * @param int    $transaction_id Transaction ID
	 */
	public function info( $message, $context = array(), $transaction_id = null ) {
		$this->log( self::LEVEL_INFO, $message, $context, $transaction_id );
	}

	/**
	 * Log warning message
	 *
	 * @param string $message Log message
	 * @param array  $context Context data
	 * @param int    $transaction_id Transaction ID
	 */
	public function warning( $message, $context = array(), $transaction_id = null ) {
		$this->log( self::LEVEL_WARNING, $message, $context, $transaction_id );
	}

	/**
	 * Log error message
	 *
	 * @param string $message Log message
	 * @param array  $context Context data
	 * @param int    $transaction_id Transaction ID
	 */
	public function error( $message, $context = array(), $transaction_id = null ) {
		$this->log( self::LEVEL_ERROR, $message, $context, $transaction_id );
	}

	/**
	 * Log message
	 *
	 * @param string $level          Log level
	 * @param string $message        Log message
	 * @param array  $context        Context data
	 * @param int    $transaction_id Transaction ID
	 */
	public function log( $level, $message, $context = array(), $transaction_id = null ) {
		// Check if level should be logged
		if ( ! $this->should_log( $level ) ) {
			return;
		}

		// Prepare log data
		$log_data = array(
			'level'          => $level,
			'message'        => $this->sanitize_message( $message ),
			'context'        => $this->sanitize_context( $context ),
			'transaction_id' => $transaction_id,
			'created_at'     => current_time( 'mysql' ),
		);

		// Write to database
		$this->write_to_database( $log_data );

		// Also write to WordPress debug log if WP_DEBUG is enabled
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$this->write_to_wp_debug( $level, $message, $context );
		}
	}

	/**
	 * Check if level should be logged
	 *
	 * @param string $level Log level
	 * @return bool True if should log
	 */
	private function should_log( $level ) {
		$levels = array(
			self::LEVEL_DEBUG   => 1,
			self::LEVEL_INFO    => 2,
			self::LEVEL_WARNING => 3,
			self::LEVEL_ERROR   => 4,
		);

		$current_level_value = isset( $levels[ $this->min_level ] ) ? $levels[ $this->min_level ] : 2;
		$message_level_value = isset( $levels[ $level ] ) ? $levels[ $level ] : 2;

		return $message_level_value >= $current_level_value;
	}

	/**
	 * Sanitize log message
	 *
	 * @param string $message Message to sanitize
	 * @return string Sanitized message
	 */
	private function sanitize_message( $message ) {
		return sanitize_text_field( $message );
	}

	/**
	 * Sanitize context data
	 *
	 * @param array $context Context data
	 * @return string JSON encoded context
	 */
	private function sanitize_context( $context ) {
		// Comprehensive sensitive data patterns for better security
		$sensitive_patterns = array(
			'password',
			'token',
			'key',
			'secret',
			'merchant_id',
			'authorization',
			'authority',
			'receipt_token',
			'ref_id',
			'api_key',
			'access_token',
			'refresh_token',
			'client_secret',
			'private_key',
			'public_key',
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

		$sanitized = array();

		foreach ( $context as $key => $value ) {
			$key_lower = strtolower( $key );

			// Check for sensitive patterns in key
			$is_sensitive = false;
			foreach ( $sensitive_patterns as $pattern ) {
				if ( strpos( $key_lower, $pattern ) !== false ) {
					$is_sensitive = true;
					break;
				}
			}

			if ( $is_sensitive ) {
				// Partial redaction for debugging (show first/last few chars)
				if ( is_string( $value ) && strlen( $value ) > 8 ) {
					$sanitized[ $key ] = substr( $value, 0, 4 ) . '[REDACTED]' . substr( $value, -4 );
				} else {
					$sanitized[ $key ] = '[REDACTED]';
				}
			} elseif ( is_array( $value ) || is_object( $value ) ) {
				$sanitized[ $key ] = $this->sanitize_context( (array) $value );
			} elseif ( is_string( $value ) ) {
				// Additional sanitization for string values
				$sanitized[ $key ] = sanitize_text_field( $value );
			} else {
				// Handle other data types safely
				$sanitized[ $key ] = $value;
			}
		}

		return wp_json_encode( $sanitized );
	}

	/**
	 * Write log to database
	 *
	 * @param array $log_data Log data
	 */
	private function write_to_database( $log_data ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'gatewaykit_payment_logs';

		$result = $wpdb->insert(
			$table_name,
			array(
				'transaction_id' => $log_data['transaction_id'],
				'level'          => $log_data['level'],
				'message'        => $log_data['message'],
				'context'        => $log_data['context'],
				'created_at'     => $log_data['created_at'],
			),
			array( '%d', '%s', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			// Fallback to error_log if database insert fails
			error_log( 'gatewaykit Logger DB Error: ' . $wpdb->last_error );
		}
	}

	/**
	 * Write to WordPress debug log
	 *
	 * @param string $level   Log level
	 * @param string $message Log message
	 * @param array  $context Context data
	 */
	private function write_to_wp_debug( $level, $message, $context ) {
		$prefix      = 'gatewaykit [' . strtoupper( $level ) . '] ';
		$log_message = $prefix . $message;

		if ( ! empty( $context ) ) {
			// Use sanitized context for public WP_DEBUG logs to prevent sensitive data exposure
			$sanitized_context = $this->sanitize_context( $context );
			$log_message      .= ' | Context: ' . $sanitized_context;
		}

		error_log( $log_message );
	}

	/**
	 * Get logs for transaction
	 *
	 * @param int $transaction_id Transaction ID
	 * @param int $limit          Number of logs to retrieve
	 * @return array Logs
	 */
	public function get_transaction_logs( $transaction_id, $limit = 50 ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'gatewaykit_payment_logs';

		$logs = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, transaction_id, level, message, context, created_at FROM %i WHERE transaction_id = %d ORDER BY created_at DESC LIMIT %d',
				$table_name,
				$transaction_id,
				$limit
			)
		);

		return $logs;
	}

	/**
	 * Get logs by level
	 *
	 * @param string $level Log level
	 * @param int    $limit Number of logs to retrieve
	 * @return array Logs
	 */
	public function get_logs_by_level( $level, $limit = 100 ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'gatewaykit_payment_logs';

		$logs = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, transaction_id, level, message, context, created_at FROM %i WHERE level = %s ORDER BY created_at DESC LIMIT %d',
				$table_name,
				$level,
				$limit
			)
		);

		return $logs;
	}

	/**
	 * Get logs with flexible filtering and pagination.
	 *
	 * Supported filters:
	 *  - level          string  Filter by log level (debug|info|warning|error).
	 *  - date_from      string  ISO date (Y-m-d) inclusive lower bound.
	 *  - date_to        string  ISO date (Y-m-d) inclusive upper bound.
	 *  - transaction_id int     Filter by transaction id.
	 *  - search         string  Free-text search over message column.
	 *
	 * @param array $filters See above.
	 * @param int   $limit   Max number of rows.
	 * @param int   $offset  Offset for pagination.
	 * @return array Log rows (ordered newest first).
	 */
	public function get_logs( $filters = array(), $limit = 100, $offset = 0 ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'gatewaykit_payment_logs';

		list( $where, $params ) = $this->build_logs_where_clause( $filters );

		$sql = "SELECT id, transaction_id, level, message, context, created_at FROM %i{$where} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d";
		array_unshift( $params, $table_name );
		$params[] = max( 0, (int) $limit );
		$params[] = max( 0, (int) $offset );

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
	}

	/**
	 * Get total log count matching the given filters (for pagination).
	 *
	 * @param array $filters Same shape as get_logs().
	 * @return int
	 */
	public function get_logs_count( $filters = array() ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'gatewaykit_payment_logs';

		list( $where, $params ) = $this->build_logs_where_clause( $filters );

		$sql = "SELECT COUNT(*) FROM %i{$where}";

		array_unshift( $params, $table_name );

		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
	}

	/**
	 * Get a single log row by ID.
	 *
	 * @param int $log_id Log ID.
	 * @return object|null
	 */
	public function get_log_by_id( $log_id ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'gatewaykit_payment_logs';

		return $wpdb->get_row(
			$wpdb->prepare( 'SELECT id, transaction_id, level, message, context, created_at FROM %i WHERE id = %d', $table_name, (int) $log_id )
		);
	}

	/**
	 * Get multiple log rows by their IDs.
	 *
	 * @param array $log_ids Array of log IDs.
	 * @return array
	 */
	public function get_logs_by_ids( $log_ids = array() ) {
		global $wpdb;

		$log_ids = array_filter( array_map( 'intval', (array) $log_ids ) );
		if ( empty( $log_ids ) ) {
			return array();
		}

		$table_name   = $wpdb->prefix . 'gatewaykit_payment_logs';
		$placeholders = implode( ',', array_fill( 0, count( $log_ids ), '%d' ) );

		return $wpdb->get_results(
			$wpdb->prepare( "SELECT id, transaction_id, level, message, context, created_at FROM %i WHERE id IN ({$placeholders}) ORDER BY created_at DESC", array_merge( array( $table_name ), $log_ids ) )
		);
	}

	/**
	 * Build a WHERE clause (with bound params) for log filtering.
	 *
	 * @param array $filters Filter array.
	 * @return array [where_clause, params]
	 */
	private function build_logs_where_clause( $filters ) {
		global $wpdb;

		$where  = '';
		$params = array();

		if ( ! empty( $filters['level'] ) ) {
			$where   .= ( '' === $where ? ' WHERE ' : ' AND ' ) . 'level = %s';
			$params[] = sanitize_text_field( $filters['level'] );
		}

		if ( ! empty( $filters['transaction_id'] ) ) {
			$where   .= ( '' === $where ? ' WHERE ' : ' AND ' ) . 'transaction_id = %d';
			$params[] = (int) $filters['transaction_id'];
		}

		if ( ! empty( $filters['date_from'] ) ) {
			$where   .= ( '' === $where ? ' WHERE ' : ' AND ' ) . 'DATE(created_at) >= %s';
			$params[] = sanitize_text_field( $filters['date_from'] );
		}

		if ( ! empty( $filters['date_to'] ) ) {
			$where   .= ( '' === $where ? ' WHERE ' : ' AND ' ) . 'DATE(created_at) <= %s';
			$params[] = sanitize_text_field( $filters['date_to'] );
		}

		if ( ! empty( $filters['search'] ) ) {
			$search   = '%' . $wpdb->esc_like( sanitize_text_field( $filters['search'] ) ) . '%';
			$where   .= ( '' === $where ? ' WHERE ' : ' AND ' ) . 'message LIKE %s';
			$params[] = $search;
		}

		return array( $where, $params );
	}

	/**
	 * Clean old logs
	 *
	 * @param int $days Days to keep logs
	 */
	public function clean_old_logs( $days = 30 ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'gatewaykit_payment_logs';

		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)',
				$table_name,
				$days
			)
		);
	}

	/**
	 * Clear all logs (truncate or delete all rows).
	 *
	 * @return int|bool Number of deleted rows or false on failure.
	 */
	public function clear_all_logs() {
		global $wpdb;

		$table_name = $wpdb->prefix . 'gatewaykit_payment_logs';

		$result = $wpdb->query(
			$wpdb->prepare( 'TRUNCATE TABLE %i', $table_name )
		);

		if ( false === $result ) {
			$result = $wpdb->query(
				$wpdb->prepare( 'DELETE FROM %i', $table_name )
			);
		}

		return $result;
	}

	/**
	 * Set minimum log level
	 *
	 * @param string $level Log level
	 */
	public function set_min_level( $level ) {
		if ( in_array( $level, array( self::LEVEL_DEBUG, self::LEVEL_INFO, self::LEVEL_WARNING, self::LEVEL_ERROR ) ) ) {
			$this->min_level = $level;
			update_option( 'gatewaykit_log_level', $level );
		}
	}

	// phpcs:enable
}
