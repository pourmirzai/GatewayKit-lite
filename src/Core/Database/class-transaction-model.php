<?php
/**
 * Transaction Model
 *
 * Handles transaction data operations
 *
 * @package GatewayKit
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Transaction Model Class
 */
class GatewayKit_Transaction_Model {

	/*
	 * This model performs direct database operations against the
	 * gatewaykit_payment_transactions table. The table name is always the
	 * hardcoded base 'gatewaykit_payment_transactions' concatenated with
	 * $wpdb->prefix (internal, no user input), and user-supplied values use
	 * $wpdb->prepare() placeholders. The DB coding-standard sniffs cannot
	 * trace this, so they are disabled for the whole class. The single
	 * error_log() is a WP_DEBUG-gated diagnostic.
	 */
	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.PHP.DevelopmentFunctions.error_log_error_log

	/**
	 * Transaction data
	 */
	private $data = array();

	/**
	 * Constructor
	 *
	 * @param array $data Transaction data
	 */
	public function __construct( $data = array() ) {
		$this->data = $data;
	}

	/**
	 * Create new transaction
	 *
	 * @param array $data Transaction data
	 * @return GatewayKit_Transaction_Model|WP_Error Transaction object or error
	 */
	public static function create( $data ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'gatewaykit_payment_transactions';

		// Generate a unique, uppercase alphanumeric receipt token in format XXXXXX-YYYYYY
		// Optimized to prevent infinite loops with collision limit
		$chars        = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
		$chars_len    = strlen( $chars );
		$max_attempts = 100; // Prevent infinite loops
		$attempts     = 0;

		do {
			$raw = '';
			for ( $i = 0; $i < 12; $i++ ) {
				$raw .= $chars[ random_int( 0, $chars_len - 1 ) ];
			}
			$receipt_token = substr( $raw, 0, 6 ) . '-' . substr( $raw, 6, 6 );

			// Check collision
			$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE receipt_token = %s LIMIT 1', $table_name, $receipt_token ) );
			++$attempts;

			// Safety check to prevent infinite loops
			if ( $attempts >= $max_attempts ) {
				GatewayKit_Logger::get_instance()->error(
					'Receipt token generation failed - too many collisions',
					array(
						'attempts'     => $attempts,
						'max_attempts' => $max_attempts,
					)
				);
				return new WP_Error( 'token_generation_failed', __( 'Failed to generate unique receipt token. Please try again.', 'gatewaykit' ) );
			}
		} while ( ! empty( $exists ) );

		// Prepare data
		$currency    = isset( $data['currency'] ) ? sanitize_text_field( $data['currency'] ) : '';
		$currency    = $currency ? strtoupper( $currency ) : GatewayKit_Gateway_Manager::get_instance()->get_default_currency();
		$insert_data = array(
			'user_id'                  => isset( $data['user_id'] ) ? intval( $data['user_id'] ) : null,
			'form_id'                  => sanitize_key( $data['form_id'] ),
			'post_id'                  => intval( $data['post_id'] ),
			'gateway'                  => sanitize_key( $data['gateway'] ),
			'amount'                   => floatval( $data['amount'] ),
			'discount_id'              => ! empty( $data['discount_id'] ) ? intval( $data['discount_id'] ) : null,
			'discount_amount'          => isset( $data['discount_amount'] ) ? floatval( $data['discount_amount'] ) : 0,
			'currency'                 => $currency,
			'description'              => sanitize_text_field( $data['description'] ),
			'status'                   => isset( $data['status'] ) ? $data['status'] : 'pending',
			'form_data'                => wp_json_encode( $data['form_data'] ),
			'user_data'                => wp_json_encode( $data['user_data'] ),
			'callback_url'             => esc_url_raw( $data['callback_url'] ),
			'success_url'              => esc_url_raw( isset( $data['success_url'] ) ? urldecode( $data['success_url'] ) : '' ),
			'failure_url'              => esc_url_raw( isset( $data['failure_url'] ) ? urldecode( $data['failure_url'] ) : '' ),
			'receipt_token'            => $receipt_token,
			'ip_address'               => self::get_client_ip(),
			'user_agent'               => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
			'receipt_token_created_at' => current_time( 'mysql' ),
			'created_at'               => current_time( 'mysql' ),
			'updated_at'               => current_time( 'mysql' ),
		);

		// Adjust format array based on available columns
		// Prepare format array dynamically based on data types
		$format = array();
		foreach ( $insert_data as $key => $value ) {
			if ( is_int( $value ) ) {
				$format[] = '%d';
			} elseif ( is_float( $value ) ) {
				$format[] = '%f';
			} else {
				$format[] = '%s';
			}
		}

		// Insert into database
		$result = $wpdb->insert(
			$table_name,
			$insert_data,
			$format
		);

		if ( $result === false ) {
			GatewayKit_Logger::get_instance()->error(
				'Failed to create transaction',
				array(
					'error' => $wpdb->last_error,
					'data'  => $insert_data,
				)
			);

			return new WP_Error( 'db_insert_error', __( 'Failed to create transaction record.', 'gatewaykit' ) );
		}

		$transaction_id = $wpdb->insert_id;

		// Log transaction creation
		GatewayKit_Logger::get_instance()->info(
			'Transaction created',
			array(
				'transaction_id' => $transaction_id,
				'gateway'        => $insert_data['gateway'],
				'amount'         => $insert_data['amount'],
			)
		);

		// Create transaction object
		$insert_data['id'] = $transaction_id;

		// Clear relevant caches
		self::clear_cache();

		// Trigger action for other cache clearing
		do_action( 'gatewaykit_transaction_created', $transaction_id );

		return new self( $insert_data );
	}

	/**
	 * Find transaction by ID
	 *
	 * @param int $id Transaction ID
	 * @return GatewayKit_Transaction_Model|null Transaction object or null
	 */
	public static function find( $id ) {
		global $wpdb;

		// Check cache first
		$cache_key = 'gatewaykit_transaction_' . $id;
		$cache_ttl = 600; // 10 minutes cache

		$cached_transaction = get_transient( $cache_key );
		if ( $cached_transaction !== false ) {
			return new self( $cached_transaction );
		}

		$table_name = $wpdb->prefix . 'gatewaykit_payment_transactions';

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT id, user_id, form_id, post_id, gateway, authority, ref_id, amount, discount_id, discount_amount, currency, description, status, gateway_response, form_data, user_data, callback_url, success_url, failure_url, receipt_token, receipt_token_created_at, ip_address, user_agent, created_at, updated_at, completed_at FROM %i WHERE id = %d', $table_name, $id ),
			ARRAY_A
		);

		if ( $row ) {
			// Cache the result
			set_transient( $cache_key, $row, $cache_ttl );
			return new self( $row );
		}

		return null;
	}

	/**
	 * Find transaction by authority
	 *
	 * @param string $authority Payment authority
	 * @return GatewayKit_Transaction_Model|null Transaction object or null
	 */
	public static function find_by_authority( $authority ) {
		global $wpdb;

		// Check cache first
		$cache_key = 'gatewaykit_transaction_auth_' . md5( $authority );
		$cache_ttl = 600; // 10 minutes cache

		$cached_transaction = get_transient( $cache_key );
		if ( $cached_transaction !== false ) {
			return new self( $cached_transaction );
		}

		$table_name = $wpdb->prefix . 'gatewaykit_payment_transactions';

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT id, user_id, form_id, post_id, gateway, authority, ref_id, amount, discount_id, discount_amount, currency, description, status, gateway_response, form_data, user_data, callback_url, success_url, failure_url, receipt_token, receipt_token_created_at, ip_address, user_agent, created_at, updated_at, completed_at FROM %i WHERE authority = %s', $table_name, $authority ),
			ARRAY_A
		);

		if ( $row ) {
			// Cache the result
			set_transient( $cache_key, $row, $cache_ttl );
			return new self( $row );
		}

		return null;
	}

	/**
	 * Find transaction by receipt token
	 *
	 * @param string $token Receipt token in format XXXXXX-YYYYYY
	 * @return GatewayKit_Transaction_Model|null
	 */
	public static function find_by_receipt_token( $token ) {
		global $wpdb;

		// Check cache first
		$cache_key = 'gatewaykit_transaction_receipt_' . md5( $token );
		$cache_ttl = 600; // 10 minutes cache

		$cached_transaction = get_transient( $cache_key );
		if ( $cached_transaction !== false ) {
			return new self( $cached_transaction );
		}

		$table_name = $wpdb->prefix . 'gatewaykit_payment_transactions';

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT id, user_id, form_id, post_id, gateway, authority, ref_id, amount, discount_id, discount_amount, currency, description, status, gateway_response, form_data, user_data, callback_url, success_url, failure_url, receipt_token, receipt_token_created_at, ip_address, user_agent, created_at, updated_at, completed_at FROM %i WHERE receipt_token = %s', $table_name, $token ),
			ARRAY_A
		);

		if ( $row ) {
			// Cache the result
			set_transient( $cache_key, $row, $cache_ttl );
			return new self( $row );
		}

		return null;
	}

	/**
	 * Get user transactions
	 *
	 * @param int $user_id User ID
	 * @param int $limit   Number of transactions
	 * @return array Array of transaction objects
	 */
	public static function get_user_transactions( $user_id, $limit = 50 ) {
		global $wpdb;

		// Check cache first
		$cache_key = 'gatewaykit_user_transactions_' . $user_id . '_' . $limit;
		$cache_ttl = 300; // 5 minutes cache

		$cached_transactions = get_transient( $cache_key );
		if ( $cached_transactions !== false ) {
			$transactions = array();
			foreach ( $cached_transactions as $row ) {
				$transactions[] = new self( $row );
			}
			return $transactions;
		}

		$table_name = $wpdb->prefix . 'gatewaykit_payment_transactions';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, user_id, form_id, post_id, gateway, authority, ref_id, amount, discount_id, discount_amount, currency, description, status, gateway_response, form_data, user_data, callback_url, success_url, failure_url, receipt_token, receipt_token_created_at, ip_address, user_agent, created_at, updated_at, completed_at FROM %i WHERE user_id = %d ORDER BY created_at DESC LIMIT %d',
				$table_name,
				$user_id,
				$limit
			),
			ARRAY_A
		);

		$transactions = array();
		foreach ( $rows as $row ) {
			$transactions[] = new self( $row );
		}

		// Cache the result
		set_transient( $cache_key, $rows, $cache_ttl );

		return $transactions;
	}

	/**
	 * Update transaction
	 *
	 * @param array $data Data to update
	 * @return bool True on success
	 */
	public function update( $data ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'gatewaykit_payment_transactions';

		$update_data = array();
		$format      = array();

		// Prepare update data
		if ( isset( $data['authority'] ) ) {
			$update_data['authority'] = sanitize_text_field( $data['authority'] );
			$format[]                 = '%s';
		}

		if ( isset( $data['ref_id'] ) ) {
			$update_data['ref_id'] = sanitize_text_field( $data['ref_id'] );
			$format[]              = '%s';
		}

		if ( isset( $data['status'] ) ) {
			$update_data['status'] = $data['status'];
			$format[]              = '%s';
		}

		if ( isset( $data['gateway_response'] ) ) {
			$update_data['gateway_response'] = wp_json_encode( $data['gateway_response'] );
			$format[]                        = '%s';
		}

		if ( isset( $data['completed_at'] ) ) {
			$update_data['completed_at'] = $data['completed_at'];
			$format[]                    = '%s';
		}

		// Error tracking fields
		if ( isset( $data['error_message'] ) ) {
			$update_data['error_message'] = sanitize_text_field( $data['error_message'] );
			$format[]                     = '%s';
		}

		if ( isset( $data['error_code'] ) ) {
			$update_data['error_code'] = sanitize_text_field( $data['error_code'] );
			$format[]                  = '%s';
		}

		if ( isset( $data['error_type'] ) ) {
			$valid_error_types = array( 'license', 'gateway', 'configuration', 'network', 'validation', 'unknown' );
			if ( in_array( $data['error_type'], $valid_error_types ) ) {
				$update_data['error_type'] = $data['error_type'];
				$format[]                  = '%s';
			}
		}

		if ( isset( $data['error_details'] ) ) {
			$update_data['error_details'] = wp_json_encode( $data['error_details'] );
			$format[]                     = '%s';
		}

		if ( isset( $data['error_timestamp'] ) ) {
			$update_data['error_timestamp'] = $data['error_timestamp'];
			$format[]                       = '%s';
		}

		if ( array_key_exists( 'discount_id', $data ) ) {
			$update_data['discount_id'] = ! empty( $data['discount_id'] ) ? intval( $data['discount_id'] ) : null;
			$format[]                   = '%d';
		}

		if ( array_key_exists( 'discount_amount', $data ) ) {
			$update_data['discount_amount'] = floatval( $data['discount_amount'] );
			$format[]                       = '%f';
		}

		$update_data['updated_at'] = current_time( 'mysql' );
		$format[]                  = '%s';

		if ( empty( $update_data ) ) {
			return false;
		}

		// Update database
		$result = $wpdb->update(
			$table_name,
			$update_data,
			array( 'id' => $this->data['id'] ),
			$format,
			array( '%d' )
		);

		if ( $result !== false ) {
			// Update local data
			$this->data = array_merge( $this->data, $update_data );

			// Clear relevant caches
			self::clear_cache( $this->data['id'] );

			// Trigger action for other cache clearing
			do_action( 'gatewaykit_transaction_updated', $this->data['id'] );

			// Log update
			GatewayKit_Logger::get_instance()->info(
				'Transaction updated',
				array(
					'transaction_id' => $this->data['id'],
					'updates'        => array_keys( $update_data ),
				)
			);

			return true;
		}

		GatewayKit_Logger::get_instance()->error(
			'Failed to update transaction',
			array(
				'transaction_id' => $this->data['id'],
				'error'          => $wpdb->last_error,
			)
		);

		return false;
	}

	/**
	 * Delete transaction
	 *
	 * @return bool True on success
	 */
	public function delete() {
		global $wpdb;

		$table_name  = $wpdb->prefix . 'gatewaykit_payment_transactions';
		$logs_table  = $wpdb->prefix . 'gatewaykit_payment_logs';
		$notes_table = $wpdb->prefix . 'gatewaykit_transaction_notes';

		// Delete associated logs first (no FK cascade — app-level cleanup).
		$wpdb->delete(
			$logs_table,
			array( 'transaction_id' => $this->data['id'] ),
			array( '%d' )
		);

		// Delete associated notes (app-level cleanup — no FK).
		$wpdb->delete(
			$notes_table,
			array( 'transaction_id' => $this->data['id'] ),
			array( '%d' )
		);

		$result = $wpdb->delete(
			$table_name,
			array( 'id' => $this->data['id'] ),
			array( '%d' )
		);

		if ( $result !== false ) {
			// Clear relevant caches
			self::clear_cache( $this->data['id'] );

			// Trigger action for other cache clearing
			do_action( 'gatewaykit_transaction_deleted', $this->data['id'] );

			GatewayKit_Logger::get_instance()->info(
				'Transaction deleted',
				array(
					'transaction_id' => $this->data['id'],
				)
			);

			return true;
		}

		return false;
	}

	/**
	 * Add a note to this transaction.
	 *
	 * @param string $note The note text.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function add_note( $note ) {
		global $wpdb;

		$note = sanitize_textarea_field( $note );
		if ( '' === $note ) {
			return new WP_Error( 'empty_note', __( 'Note cannot be empty.', 'gatewaykit' ) );
		}

		$table_name = $wpdb->prefix . 'gatewaykit_transaction_notes';
		$result     = $wpdb->insert(
			$table_name,
			array(
				'transaction_id' => (int) $this->data['id'],
				'note'           => $note,
				'author_id'      => get_current_user_id(),
				'created_at'     => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%d', '%s' )
		);

		if ( false === $result ) {
			return new WP_Error( 'db_error', __( 'Failed to save note.', 'gatewaykit' ) );
		}

		return true;
	}

	/**
	 * Get all notes for this transaction, ordered newest first.
	 *
	 * @return array Array of note objects.
	 */
	public function get_notes() {
		global $wpdb;

		$table_name = $wpdb->prefix . 'gatewaykit_transaction_notes';
		$results    = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT n.*, u.display_name AS author_name FROM {$table_name} n LEFT JOIN {$wpdb->users} u ON n.author_id = u.ID WHERE n.transaction_id = %d ORDER BY n.created_at DESC",
				(int) $this->data['id']
			)
		);

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Get transaction data
	 *
	 * @param string $key Data key
	 * @return mixed Data value
	 */
	public function get( $key ) {
		if ( isset( $this->data[ $key ] ) ) {
			// Decode JSON fields
			if ( in_array( $key, array( 'form_data', 'user_data', 'gateway_response', 'error_details' ) ) ) {
				return json_decode( $this->data[ $key ], true );
			}

			return $this->data[ $key ];
		}

		return null;
	}

	/**
	 * Magic getter
	 *
	 * @param string $key Property key
	 * @return mixed Property value
	 */
	public function __get( $key ) {
		return $this->get( $key );
	}

	/**
	 * Magic isset — required for isset() and ?? operator to work on
	 * dynamic properties. Without this, isset($transaction->discount_id)
	 * always returns false even when the data exists in $this->data.
	 *
	 * @param string $key Property key
	 * @return bool
	 */
	public function __isset( $key ) {
		return array_key_exists( $key, $this->data );
	}

	/**
	 * Check if transaction is completed
	 *
	 * @return bool True if completed
	 */
	public function is_completed() {
		return $this->get( 'status' ) === 'completed';
	}

	/**
	 * Check if transaction is pending
	 *
	 * @return bool True if pending
	 */
	public function is_pending() {
		return $this->get( 'status' ) === 'pending';
	}

	/**
	 * Check if transaction is failed
	 *
	 * @return bool True if failed
	 */
	public function is_failed() {
		return $this->get( 'status' ) === 'failed';
	}

	/**
	 * Set error information for the transaction
	 *
	 * @param string $message   Error message
	 * @param string $code      Error code (optional)
	 * @param string $type      Error type (license, gateway, configuration, network, validation, unknown)
	 * @param mixed  $details   Additional error details (array/object will be JSON encoded)
	 * @return bool True on success
	 */
	public function set_error( $message, $code = '', $type = 'unknown', $details = null ) {
		global $wpdb;

		$error_data = array(
			'error_message'   => $message,
			'error_code'      => $code,
			'error_type'      => $type,
			'error_timestamp' => current_time( 'mysql' ),
		);

		if ( $details !== null ) {
			$error_data['error_details'] = $details;
		}

		// Suppress database errors to prevent AJAX response corruption
		$wpdb->suppress_errors( true );
		$result = $this->update( $error_data );
		$wpdb->suppress_errors( false );

		return $result;
	}

	/**
	 * Get client IP address
	 *
	 * @return string Client IP
	 */
	public static function get_client_ip() {
		return GatewayKit_IP_Helper::get_client_ip();
	}

	/**
	 * Get transaction statistics
	 *
	 * @param array $filters Filters array
	 * @return array Statistics
	 */
	public static function get_statistics( $filters = array() ) {
		global $wpdb;

		// Create cache key based on filters
		$cache_key = 'gatewaykit_transaction_stats_' . md5( serialize( $filters ) );
		$cache_ttl = 600; // 10 minutes cache

		$cached_stats = get_transient( $cache_key );
		if ( $cached_stats !== false ) {
			return $cached_stats;
		}

		$table_name = $wpdb->prefix . 'gatewaykit_payment_transactions';

		$where        = array();
		$where_values = array();

		// Apply filters
		if ( ! empty( $filters['gateway'] ) ) {
			$where[]        = 'gateway = %s';
			$where_values[] = $filters['gateway'];
		}

		if ( ! empty( $filters['status'] ) ) {
			$where[]        = 'status = %s';
			$where_values[] = $filters['status'];
		}

		if ( ! empty( $filters['date_from'] ) ) {
			$where[]        = 'created_at >= %s';
			$where_values[] = $filters['date_from'];
		}

		if ( ! empty( $filters['date_to'] ) ) {
			$where[]        = 'created_at <= %s';
			$where_values[] = $filters['date_to'];
		}

		$where_clause = ! empty( $where ) ? 'WHERE ' . implode( ' AND ', $where ) : '';

		$sql = "SELECT
	           COUNT(*) as total_count,
	           SUM(amount) as total_amount,
	           AVG(amount) as avg_amount,
	           MIN(amount) as min_amount,
	           MAX(amount) as max_amount
	       FROM %i {$where_clause}";

		// Always prepare (at minimum for the %i table identifier).
		$prepare_values = array_merge( array( $table_name ), $where_values );
		$sql            = $wpdb->prepare( $sql, $prepare_values );

		$stats = $wpdb->get_row( $sql, ARRAY_A );

		$result = $stats ?: array();

		// Cache the result
		set_transient( $cache_key, $result, $cache_ttl );

		return $result;
	}

	/**
	 * Clear transaction cache
	 *
	 * @param int $transaction_id Transaction ID (optional)
	 * @return void
	 */
	public static function clear_cache( $transaction_id = null ) {
		if ( $transaction_id ) {
			// Clear specific transaction cache
			delete_transient( 'gatewaykit_transaction_' . $transaction_id );
		}

		// Clear user transactions cache (clear all since we don't know which users are affected)
		global $wpdb;
		$cache_keys = $wpdb->get_col( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name LIKE %s', $wpdb->options, '_transient_gatewaykit_user_transactions_%' ) );
		foreach ( $cache_keys as $key ) {
			$transient_key = str_replace( '_transient_', '', $key );
			delete_transient( $transient_key );
		}

		// Clear statistics cache
		$stats_cache_keys = $wpdb->get_col( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name LIKE %s', $wpdb->options, '_transient_gatewaykit_transaction_stats_%' ) );
		foreach ( $stats_cache_keys as $key ) {
			$transient_key = str_replace( '_transient_', '', $key );
			delete_transient( $transient_key );
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'GatewayKit: Transaction cache cleared' . ( $transaction_id ? ' for transaction ID ' . $transaction_id : '' ) );
		}
	}

	// phpcs:enable
}
