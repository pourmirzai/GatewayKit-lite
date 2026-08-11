<?php
/**
 * Transaction List Table
 *
 * Displays transactions in WordPress admin
 *
 * @package GatewayKit
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Transaction List Table Class
 */
class GatewayKit_Transaction_List_Table extends WP_List_Table {

	/**
	 * Constructor
	 */
	public function __construct() {
		parent::__construct(
			array(
				// Use stable, non-translated slugs for WP_List_Table internal args (affects nonce action)
				'singular' => 'transaction',
				'plural'   => 'transactions',
				'ajax'     => false,
			)
		);
	}


	/**
	 * Get columns
	 */
	public function get_columns() {
		return array(
			'cb'           => '<input type="checkbox" />',
			'id'           => __( 'Transaction', 'gatewaykit' ),
			'receipt_code' => __( 'Receipt Code', 'gatewaykit' ),
			'user'         => __( 'User', 'gatewaykit' ),
			'gateway'      => __( 'Gateway', 'gatewaykit' ),
			'amount'       => __( 'Amount', 'gatewaykit' ),
			'description'  => __( 'Description', 'gatewaykit' ),
			'status'       => __( 'Status', 'gatewaykit' ),
			'error_details' => __( 'Error Info', 'gatewaykit' ),
			'authority'    => __( 'Authority', 'gatewaykit' ),
			'created_at'   => __( 'Date', 'gatewaykit' ),
		);
	}

	/**
	 * Get sortable columns
	 */
	public function get_sortable_columns() {
		return array(
			'id'         => array( 'id', false ),
			'amount'     => array( 'amount', false ),
			'status'     => array( 'status', false ),
			'created_at' => array( 'created_at', false ),
		);
	}

	/**
	 * Get hidden columns for the current user/screen.
	 *
	 * Reads directly from user meta using the standard WordPress meta key
	 * (manage{$screen->id}columnshidden), which is what
	 * WP_Screen::render_list_table_columns_preferences reads when rendering
	 * the checkboxes, and what wp_ajax_hidden_columns writes.
	 *
	 * @return string[]
	 */
	public function get_hidden_columns() {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return array();
		}

		$meta_key = 'manage' . $screen->id . 'columnshidden';
		$saved   = get_user_option( $meta_key );

		if ( ! is_array( $saved ) ) {
			return array();
		}

		return array_filter( array_map( 'sanitize_key', $saved ) );
	}

	/**
	 * Prepare items
	 */
	public function prepare_items() {
		$columns  = $this->get_columns();
		$sortable = $this->get_sortable_columns();
		$hidden   = $this->get_hidden_columns();

		$this->_column_headers = array( $columns, $hidden, $sortable );

		// Process bulk actions if any
		$this->process_bulk_action();

		// Get data
		// Get per-page from user meta (saved by WordPress's set_screen_options), fallback to 20.
		$per_page     = 20;
		$screen       = get_current_screen();
		if ( $screen ) {
			$saved = get_user_option( 'gatewaykit_transactions_per_page' );
			if ( false !== $saved && (int) $saved > 0 ) {
				$per_page = (int) $saved;
			}
		}
		$current_page = $this->get_pagenum();
		$offset       = ( $current_page - 1 ) * $per_page;

		$data        = $this->get_transactions( $per_page, $offset );
		$total_items = $this->get_total_transactions_count();

		$this->items = $data;

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => ceil( $total_items / $per_page ),
			)
		);
	}

	/**
	 * Get transactions with optimized queries and caching
	 */
	private function get_transactions( $per_page, $offset ) {
		global $wpdb;

		// Admin list-table GET filters; protected by the manage_options
		// capability check (standard WP pattern, no nonce for filtering).
		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$status_filter  = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$gateway_filter = isset( $_GET['gateway'] ) ? sanitize_key( wp_unslash( $_GET['gateway'] ) ) : '';
		$search_raw     = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$orderby        = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'id';
		$order          = isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'DESC';
		$request_sig    = wp_unslash( $_GET );

		// Date range filter — always available (moved to Lite in 1.2.0).
		$date_from = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '';
		$date_to   = isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '';
		// phpcs:enable

		// Generate a unique cache key based on the request parameters
		$cache_key = 'gatewaykit_transactions_' . md5(
			serialize(
				array(
					'per_page' => $per_page,
					'offset'   => $offset,
					'request'  => $request_sig,
					'user_id'  => get_current_user_id(),
				)
			)
		);

		// Try to get from cache first (disabled when searching)
		$use_cache = empty( $search_raw );
		$results   = false;
		if ( $use_cache ) {
			$results = wp_cache_get( $cache_key, 'gatewaykit_transactions' );
			if ( false !== $results ) {
				return $results;
			}
		}

		$table_name   = $wpdb->prefix . 'gatewaykit_payment_transactions';
		$where        = array();
		$where_values = array();
		$joins        = array();
		$join_values  = array();

		// Status filter
		if ( ! empty( $status_filter ) ) {
			$where[]        = 't.status = %s';
			$where_values[] = $status_filter;
		}

		// Gateway filter
		if ( ! empty( $gateway_filter ) ) {
			$where[]        = 't.gateway = %s';
			$where_values[] = $gateway_filter;
		}

		// Search filter - optimized with better query structure
		if ( ! empty( $search_raw ) ) {
			$search_term = '%' . $wpdb->esc_like( $search_raw ) . '%';

			// Prepare search conditions — push values in the SAME ORDER as conditions.
			$search_conditions = array(
				't.receipt_token LIKE %s',
				't.ref_id LIKE %s',
				't.authority LIKE %s',
				'u.display_name LIKE %s',
				'u.user_email LIKE %s',
				'u.user_login LIKE %s',
			);
			$where_values = array_merge( $where_values, array_fill( 0, 6, $search_term ) );

			// Add JSON search conditions only for longer search terms.
			if ( strlen( $search_raw ) > 2 ) {
				$search_conditions = array_merge(
					$search_conditions,
					array(
						't.form_data LIKE %s', // Simple LIKE on JSON as fallback
						'JSON_UNQUOTE(JSON_EXTRACT(t.form_data, "$.name")) LIKE %s',
						'JSON_UNQUOTE(JSON_EXTRACT(t.form_data, "$.email")) LIKE %s',
						'JSON_UNQUOTE(JSON_EXTRACT(t.form_data, "$.phone")) LIKE %s',
						'JSON_UNQUOTE(JSON_EXTRACT(t.form_data, "$.mobile")) LIKE %s',
					)
				);
				$where_values = array_merge( $where_values, array_fill( 0, 5, $search_term ) );
			}

			$where[] = '(' . implode( ' OR ', $search_conditions ) . ')';

			// Add user table join for user searches
			$joins[]      = "LEFT JOIN %i u ON t.user_id = u.ID";
			$join_values[] = $wpdb->users;
		} else {
			// Only join users if we need to display user info
			$joins[]      = "LEFT JOIN %i u ON t.user_id = u.ID";
			$join_values[] = $wpdb->users;
		}

		// Date range filter — Pro-only (ARCH-022). Inputs are disabled on
		// the page when unlicensed, and the GET-read block above discards
		// the params anyway, so this branch only fires for licensed Pro.
		if ( '' !== $date_from ) {
			$where[]        = 't.created_at >= %s';
			$where_values[] = $date_from . ' 00:00:00';
		}
		if ( '' !== $date_to ) {
			$where[]        = 't.created_at <= %s';
			$where_values[] = $date_to . ' 23:59:59';
		}

		// Build the query
		$where_clause = ! empty( $where ) ? 'WHERE ' . implode( ' AND ', $where ) : '';
		$join_clause  = ! empty( $joins ) ? implode( ' ', $joins ) : '';

		// Select only needed columns for better performance
		$select_columns = 't.*';

		// Ordering with whitelist
		$order = strtoupper( $order );
		if ( ! in_array( $order, array( 'ASC', 'DESC' ), true ) ) {
			$order = 'DESC';
		}

		$allowed_orderby = array( 'id', 'amount', 'status', 'created_at' );
		if ( ! in_array( $orderby, $allowed_orderby, true ) ) {
			$orderby = 'id';
		}

		// Use FORCE INDEX for better performance on large tables
		$force_index = '';
		$index_map    = array(
			'created_at' => 'idx_created_at',
			'status'     => 'idx_status',
			'amount'     => 'idx_amount_status',
		);
		if ( isset( $index_map[ $orderby ] ) ) {
			$force_index = "FORCE INDEX ({$index_map[ $orderby ]})";
		}

		// Build the query with proper table aliases.
		// Identifiers are whitelist-validated; values use %s/%d placeholders.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "SELECT {$select_columns} FROM %i t {$force_index} 
                {$join_clause} 
                {$where_clause} 
                ORDER BY t.{$orderby} {$order} 
                LIMIT %d OFFSET %d";
		// phpcs:enable

		// Prepare the query with all parameters
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $sql prepared above; results cached below
		$query   = $wpdb->prepare( $sql, array_merge( array( $table_name ), $join_values, $where_values, array( $per_page, $offset ) ) );
		$results = $wpdb->get_results( $query, ARRAY_A );
		// phpcs:enable

		// Cache the results for 5 minutes (skip during search to validate behavior)
		if ( $use_cache && ! empty( $results ) ) {
			wp_cache_set( $cache_key, $results, 'gatewaykit_transactions', 300 );
		}

		return $results ?: array();
	}

	/**
	 * Get total transactions count with caching
	 */
	private function get_total_transactions_count() {
		global $wpdb;

		// Admin list-table GET filters; protected by manage_options.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$status_filter  = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$gateway_filter = isset( $_GET['gateway'] ) ? sanitize_key( wp_unslash( $_GET['gateway'] ) ) : '';
		$search_raw     = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$request_sig    = wp_unslash( $_GET );

		// Date range filter — always available (moved to Lite in 1.2.0).
		$date_from = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '';
		$date_to   = isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '';
		// phpcs:enable

		// Generate a unique cache key for the count query
		$cache_key = 'gatewaykit_transactions_count_' . md5(
			serialize(
				array(
					'request' => $request_sig,
					'user_id' => get_current_user_id(),
				)
			)
		);

		// Try to get from cache first (disabled when searching)
		$use_cache = empty( $search_raw );
		$count     = false;
		if ( $use_cache ) {
			$count = wp_cache_get( $cache_key, 'gatewaykit_transactions' );
			if ( false !== $count ) {
				return (int) $count;
			}
		}

		$table_name = $wpdb->prefix . 'gatewaykit_payment_transactions';

		// Same filters as get_transactions
		$where        = array();
		$where_values = array();

		if ( ! empty( $status_filter ) ) {
			$where[]        = 'status = %s';
			$where_values[] = $status_filter;
		}

		if ( ! empty( $gateway_filter ) ) {
			$where[]        = 'gateway = %s';
			$where_values[] = $gateway_filter;
		}

		// Search filter (same as get_transactions)
		if ( ! empty( $search_raw ) ) {
			$search_term = '%' . $wpdb->esc_like( $search_raw ) . '%';

			// Simple search conditions for count query
			$search_conditions = array(
				'receipt_token LIKE %s',
				'ref_id LIKE %s',
				'authority LIKE %s',
				'description LIKE %s',
			);

			$where[] = '(' . implode( ' OR ', $search_conditions ) . ')';

			// Add search values (4 times for the different fields)
			$where_values = array_merge( $where_values, array_fill( 0, 4, $search_term ) );
		}

		// Date range filter — Pro-only (ARCH-022).
		if ( '' !== $date_from ) {
			$where[]        = 'created_at >= %s';
			$where_values[] = $date_from . ' 00:00:00';
		}
		if ( '' !== $date_to ) {
			$where[]        = 'created_at <= %s';
			$where_values[] = $date_to . ' 23:59:59';
		}

		$where_clause = ! empty( $where ) ? 'WHERE ' . implode( ' AND ', $where ) : '';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- where_clause uses placeholders
		$sql = "SELECT COUNT(*) FROM %i t {$where_clause}";
		// phpcs:enable

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $sql prepared above when placeholders exist; cached below
		$sql = $wpdb->prepare( $sql, array_merge( array( $table_name ), $where_values ) );

		$count = $wpdb->get_var( $sql ) ?: 0;
		// phpcs:enable
		
		// Cache the count for 5 minutes (skip during search)
		if ( $use_cache ) {
			wp_cache_set( $cache_key, $count, 'gatewaykit_transactions', 300 );
		}
		
		return $count;
	}

	/**
	 * Get transaction IDs matching a filter context.
	 *
	 * Used by the Pro-only "Export All Transactions" button so the exported
	 * CSV respects the active filters on the page (status, gateway, date
	 * range, search). When a filter is empty it is simply not added to the
	 * WHERE clause.
	 *
	 * @param array $filters { status, gateway, date_from, date_to, search }
	 * @return int[]
	 */
	private function get_filtered_transaction_ids( $filters ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'gatewaykit_payment_transactions';

		$where = array();
		$args  = array();

		if ( ! empty( $filters['status'] ) ) {
			$where[] = 'status = %s';
			$args[]  = $filters['status'];
		}
		if ( ! empty( $filters['gateway'] ) ) {
			$where[] = 'gateway = %s';
			$args[]  = $filters['gateway'];
		}
		if ( ! empty( $filters['date_from'] ) ) {
			$where[] = 'created_at >= %s';
			$args[]  = $filters['date_from'] . ' 00:00:00';
		}
		if ( ! empty( $filters['date_to'] ) ) {
			$where[] = 'created_at <= %s';
			$args[]  = $filters['date_to'] . ' 23:59:59';
		}
		if ( ! empty( $filters['search'] ) ) {
			$like    = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
			$where[] = '(receipt_token LIKE %s OR ref_id LIKE %s OR authority LIKE %s OR description LIKE %s)';
			$args[]  = $like;
			$args[]  = $like;
			$args[]  = $like;
			$args[]  = $like;
		}

		$where_clause = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';

		// Cap export at 10 000 rows to prevent memory exhaustion.
		$args[]    = 10000;
		$all_args  = array_merge( array( $table_name ), $args );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- WHERE clause contains placeholders PHPCS can't count; table name internal
		return $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM %i {$where_clause} ORDER BY id DESC LIMIT %d",
				$all_args
			)
		);
		// phpcs:enable
	}

	/**
	 * Export all transactions (respecting active filters).
	 *
	 * The export form on the Transactions page carries the current filter
	 * values as hidden POST fields (gatewaykit_export_*) so the exported CSV
	 * reflects exactly what the user sees on the page.
	 */
	public function export_all_transactions() {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			GatewayKit_Logger::get_instance()->debug( 'Starting export_all_transactions (filtered)' );
		}

		// Read filter context from the export form's hidden inputs.
		// These are passed through the export POST form and are already
		// protected by the nonce + capability check in handle_transaction_exports().
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$filters = array(
			'status'    => isset( $_POST['gatewaykit_export_status'] ) ? sanitize_key( wp_unslash( $_POST['gatewaykit_export_status'] ) ) : '',
			'gateway'   => isset( $_POST['gatewaykit_export_gateway'] ) ? sanitize_key( wp_unslash( $_POST['gatewaykit_export_gateway'] ) ) : '',
			'date_from' => isset( $_POST['gatewaykit_export_date_from'] ) ? sanitize_text_field( wp_unslash( $_POST['gatewaykit_export_date_from'] ) ) : '',
			'date_to'   => isset( $_POST['gatewaykit_export_date_to'] ) ? sanitize_text_field( wp_unslash( $_POST['gatewaykit_export_date_to'] ) ) : '',
			'search'    => isset( $_POST['gatewaykit_export_s'] ) ? sanitize_text_field( wp_unslash( $_POST['gatewaykit_export_s'] ) ) : '',
		);
		// phpcs:enable

		$transaction_ids = $this->get_filtered_transaction_ids( $filters );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			GatewayKit_Logger::get_instance()->debug( 'Retrieved filtered transaction IDs', array( 'count' => count( $transaction_ids ), 'ids' => array_slice( $transaction_ids, 0, 5 ) ) );
		}
		$this->export_transactions( $transaction_ids );
	}

	/**
	 * Export transactions to CSV with optimized batch processing
	 */
	private function export_transactions( $transaction_ids ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			GatewayKit_Logger::get_instance()->debug( 'Starting export_transactions', array( 'transaction_ids_count' => count( $transaction_ids ) ) );
		}

		// Clear all output buffers to prevent conflicts
		$ob_level = ob_get_level();
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			GatewayKit_Logger::get_instance()->debug( 'Output buffer level before clearing', array( 'level' => $ob_level ) );
		}
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			GatewayKit_Logger::get_instance()->debug( 'Output buffers cleared' );
		}

		// Check if headers already sent
		if ( headers_sent( $file, $line ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				GatewayKit_Logger::get_instance()->error( 'Headers already sent before export', array( 'file' => $file, 'line' => $line ) );
			}
			wp_die( esc_html__( 'Headers already sent.', 'gatewaykit' ) );
		}

		// Set proper headers for CSV download with UTF-8 support
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename=transactions-' . gmdate( 'Y-m-d-H-i-s' ) . '.csv' );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			GatewayKit_Logger::get_instance()->debug( 'Headers set for CSV export' );
		}

		// Output UTF-8 BOM for proper Persian character display in Excel
		echo "\xEF\xBB\xBF";

		$output = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming CSV to php://output, not a filesystem write

		if ( ! $output ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				GatewayKit_Logger::get_instance()->error( 'Unable to open php://output for CSV' );
			}
			wp_die( esc_html__( 'Unable to create CSV output.', 'gatewaykit' ) );
		}

		// CSV headers
		$headers = array(
			__( 'ID', 'gatewaykit' ),
			__( 'Receipt Code', 'gatewaykit' ),
			__( 'User', 'gatewaykit' ),
			__( 'Gateway', 'gatewaykit' ),
			__( 'Amount', 'gatewaykit' ),
			__( 'Currency', 'gatewaykit' ),
			__( 'Description', 'gatewaykit' ),
			__( 'Status', 'gatewaykit' ),
			__( 'Authority', 'gatewaykit' ),
			__( 'Reference ID', 'gatewaykit' ),
			__( 'Created Date', 'gatewaykit' ),
		);

		gatewaykit_fputcsv( $output, $headers );

		// Process transactions in batches to prevent memory issues
		$batch_size = 100;
		$total_ids = count( $transaction_ids );

		// Pre-load gateway manager to avoid repeated instantiation
		$gateway_manager = GatewayKit_Gateway_Manager::get_instance();

		for ( $i = 0; $i < $total_ids; $i += $batch_size ) {
			$batch_ids = array_slice( $transaction_ids, $i, $batch_size );
			
			// Get transactions in batch using optimized query
			global $wpdb;
			$table_name = $wpdb->prefix . 'gatewaykit_payment_transactions';
			$placeholders = implode( ',', array_fill( 0, count( $batch_ids ), '%d' ) );
			
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- values use %d placeholders
			$batch_transactions = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, user_id, form_id, post_id, gateway, authority, ref_id, amount, discount_id, discount_amount, currency, description, status, gateway_response, form_data, user_data, callback_url, success_url, failure_url, receipt_token, receipt_token_created_at, ip_address, user_agent, created_at, updated_at, completed_at FROM %i WHERE id IN ({$placeholders}) ORDER BY id DESC",
					array_merge( array( $table_name ), $batch_ids )
				),
				ARRAY_A
			);
			// phpcs:enable

			// Process each transaction in the batch
			foreach ( $batch_transactions as $transaction_data ) {
				try {
					$transaction = new GatewayKit_Transaction_Model( $transaction_data );
					
					$user_name = '';
					if ( ! empty( $transaction->user_id ) ) {
						$user      = get_userdata( $transaction->user_id );
						$user_name = $user ? $user->display_name : __( 'Guest', 'gatewaykit' );
					}

					$receipt_code = ! empty( $transaction->receipt_token ) ? $transaction->receipt_token : '';

					$gateway = $gateway_manager->get_gateway( $transaction->gateway );
					$gateway_name = $gateway ? $gateway->get_gateway_name() : ucfirst( $transaction->gateway );

					$row = array(
						$transaction->id,
						$receipt_code,
						$user_name,
						$gateway_name,
						$transaction->amount,
						$transaction->currency ?: GatewayKit_Gateway_Manager::get_instance()->get_default_currency(),
						$transaction->description ?: '',
						$transaction->status,
						$transaction->authority ?: '',
						$transaction->ref_id ?: '',
						date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $transaction->created_at ) ),
					);

					// Ensure all data is properly encoded in UTF-8 for Persian characters
					$row = array_map( function( $value ) {
						if ( is_string( $value ) ) {
							return mb_convert_encoding( $value, 'UTF-8', mb_detect_encoding( $value, 'UTF-8, ISO-8859-1, Windows-1252', true ) ?: 'UTF-8' );
						}
						return $value;
					}, $row );

					gatewaykit_fputcsv( $output, $row );

			} catch ( Exception $e ) {
				// Log error and continue with next transaction
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					GatewayKit_Logger::get_instance()->error( 'Error exporting transaction', array( 'transaction_id' => $transaction_data['id'], 'error' => $e->getMessage() ) );
				}
				continue;
			}
			}

			// Clear memory periodically
			if ( $i % ( $batch_size * 5 ) === 0 ) {
				if ( function_exists( 'gc_collect_cycles' ) ) {
					gc_collect_cycles();
				}
			}
		}

		fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the php://output stream, not a filesystem operation
		exit;
	}

	/**
	 * Column ID — includes row actions (Refund, etc.).
	 */
	public function column_id( $item ) {
		$output = '<a href="#" class="button button-small gatewaykit-view-form-data" data-transaction-id="' . esc_attr( $item['id'] ) . '">' .
				'<span class="dashicons dashicons-visibility" style="margin-top: 2px;"></span> ' .
				sprintf(
					/* translators: %s: transaction ID */
					__( 'Transaction #%s', 'gatewaykit' ),
					esc_html( $item['id'] )
				) .
				'</a>';

		$actions = array();

		// Notes action — opens the dedicated notes modal.
		$actions['notes'] = '<span class="dashicons dashicons-admin-comments" style="margin-top:2px;"></span> ' .
			'<a href="#" class="gatewaykit-view-notes" data-transaction-id="' . esc_attr( $item['id'] ) . '">' .
			esc_html__( 'Notes', 'gatewaykit' ) .
			'</a>';

		// Refund action — Pro only, completed transactions only.
		if ( gatewaykit_is_pro_licensed() && 'completed' === $item['status'] ) {
			$refund_url = wp_nonce_url(
				add_query_arg(
					array(
						'page'             => 'gatewaykit-transactions',
						'action'           => 'refund',
						'transaction_id'   => $item['id'],
					),
					admin_url( 'admin.php' )
				),
				'gatewaykit_refund_' . $item['id']
			);
			$actions['refund'] = '<span class="dashicons dashicons-money-alt" style="margin-top:2px;"></span> ' .
				sprintf(
					'<a href="%s" class="gatewaykit-refund-action" data-transaction-id="%s" data-amount="%s" data-currency="%s" onclick="return confirm(%s);">%s</a>',
					esc_url( $refund_url ),
					esc_attr( $item['id'] ),
					esc_attr( $item['amount'] ),
					esc_attr( $item['currency'] ),
					esc_attr( wp_json_encode( sprintf(
						/* translators: %s: refund amount */
						__( 'Are you sure you want to refund %s? This action cannot be undone.', 'gatewaykit' ),
						$item['amount'] . ' ' . $item['currency']
					) ) ),
					esc_html__( 'Refund', 'gatewaykit' )
				);
		}

		if ( ! empty( $actions ) ) {
			$output .= $this->row_actions( $actions );
		}

		return $output;
	}

	/**
	 * Column default
	 */
	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'receipt_code':
				$receipt_code = ! empty( $item['receipt_token'] ) ? $item['receipt_token'] : '';
				return $receipt_code ? '<code>' . esc_html( $receipt_code ) . '</code>' : '-';

			case 'user':
				if ( ! empty( $item['user_id'] ) ) {
					$user = get_userdata( $item['user_id'] );
					if ( $user ) {
						return '<a href="' . esc_url( get_edit_user_link( $item['user_id'] ) ) . '">' . esc_html( $user->display_name ) . '</a>';
					}
				}
				return __( 'Guest', 'gatewaykit' );

			case 'gateway':
				// Free orders (fully discounted) didn't go through a gateway.
				if ( (float) $item['amount'] <= 0 && 'completed' === $item['status'] ) {
					return '<span style="color:#888;">' . esc_html__( '— (free)', 'gatewaykit' ) . '</span>';
				}
				$gateway_manager = GatewayKit_Gateway_Manager::get_instance();
				$gateway         = $gateway_manager->get_gateway( $item['gateway'] );
				return $gateway ? esc_html( $gateway->get_gateway_name() ) : esc_html( ucfirst( $item['gateway'] ) );

			case 'amount':
				$currency = ! empty( $item['currency'] ) ? $item['currency'] : GatewayKit_Gateway_Manager::get_instance()->get_default_currency();
				$paid     = (float) $item['amount'];
				$disc     = isset( $item['discount_amount'] ) ? (float) $item['discount_amount'] : 0.0;

				$out = '<strong>' . esc_html( number_format( $paid ) . ' ' . $currency ) . '</strong>';

				if ( $disc > 0 ) {
					$orig = $paid + $disc;
					$sub  = '<div class="gatewaykit-discount-sub" style="font-size:11px;color:#666;line-height:1.4;">';
					$sub .= '<span style="text-decoration:line-through;">' . esc_html( number_format( $orig ) . ' ' . $currency ) . '</span>';
					$sub .= ' &mdash; ' . esc_html( number_format( $disc ) . ' ' . $currency ) . ' ' . __( 'discount', 'gatewaykit' );
					$sub .= '</div>';
					$out .= $sub;
				}

				return $out;

			case 'status':
				$badge = $this->get_status_badge( $item['status'] );

				// Show subscription indicator for subscription transactions (Pro only).
				if ( gatewaykit_is_pro_licensed() && ! empty( $item['ref_id'] ) && 0 === strpos( $item['ref_id'], 'sub_' ) ) {
					$badge .= ' <span class="gk-sub-badge" title="' . esc_attr__( 'Recurring subscription', 'gatewaykit' ) . '">&#x21BB;</span>';
				}

				return $badge;

			case 'error_details':
				return $this->get_error_info_display( $item );

			case 'description':
				return ! empty( $item['description'] ) ? esc_html( $item['description'] ) : '-';

			case 'authority':
				return ! empty( $item['authority'] ) ? '<code>' . esc_html( $item['authority'] ) . '</code>' : '-';

			case 'created_at':
				return date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $item['created_at'] ) );

			default:
				return isset( $item[ $column_name ] ) ? esc_html( $item[ $column_name ] ) : '';
		}
	}

	/**
	 * Column checkbox
	 */
	public function column_cb( $item ) {
		return sprintf(
			'<input type="checkbox" name="transaction_ids[]" value="%s" />',
			$item['id']
		);
	}

	/**
	 * Get status badge
	 */
	private function get_status_badge( $status ) {
		$status_labels = array(
			'pending'             => __( 'Pending', 'gatewaykit' ),
			'processing'          => __( 'Processing', 'gatewaykit' ),
			'completed'           => __( 'Completed', 'gatewaykit' ),
			'failed'              => __( 'Failed', 'gatewaykit' ),
			'cancelled'           => __( 'Cancelled', 'gatewaykit' ),
			'refunded'            => __( 'Refunded', 'gatewaykit' ),
			'partially_refunded'  => __( 'Partial Refund', 'gatewaykit' ),
		);

		$status_classes = array(
			'pending'             => 'gatewaykit-status-pending',
			'processing'          => 'gatewaykit-status-processing',
			'completed'           => 'gatewaykit-status-completed',
			'failed'              => 'gatewaykit-status-failed',
			'cancelled'           => 'gatewaykit-status-cancelled',
			'refunded'            => 'gatewaykit-status-refunded',
			'partially_refunded'  => 'gatewaykit-status-partial-refund',
		);

		$label = isset( $status_labels[ $status ] ) ? $status_labels[ $status ] : ucfirst( $status );
		$class = isset( $status_classes[ $status ] ) ? $status_classes[ $status ] : 'gatewaykit-status-default';

		return '<span class="gatewaykit-status-badge ' . esc_attr( $class ) . '">' . esc_html( $label ) . '</span>';
	}

	/**
		* Get error info display
		*/
	private function get_error_info_display( $item ) {
		// Only show error info for failed transactions
		if ( $item['status'] !== 'failed' ) {
			return '-';
		}

		$error_message = ! empty( $item['error_message'] ) ? $item['error_message'] : '';
		$error_type = ! empty( $item['error_type'] ) ? $item['error_type'] : '';
		$error_code = ! empty( $item['error_code'] ) ? $item['error_code'] : '';

		if ( empty( $error_message ) && empty( $error_type ) ) {
			return '<span class="gatewaykit-error-unknown">' . esc_html__( 'Unknown error', 'gatewaykit' ) . '</span>';
		}

		// Create tooltip content
		$tooltip_content = '';
		if ( ! empty( $error_message ) ) {
			$tooltip_content .= '<strong>' . esc_html__( 'Error:', 'gatewaykit' ) . '</strong> ' . esc_html( $error_message ) . '<br>';
		}
		if ( ! empty( $error_type ) ) {
			$error_type_labels = array(
				'license'     => __( 'License Error', 'gatewaykit' ),
				'gateway'     => __( 'Gateway Error', 'gatewaykit' ),
				'configuration' => __( 'Configuration Error', 'gatewaykit' ),
				'network'     => __( 'Network Error', 'gatewaykit' ),
				'validation'  => __( 'Validation Error', 'gatewaykit' ),
				'unknown'     => __( 'Unknown Error', 'gatewaykit' ),
			);
			$error_type_label = isset( $error_type_labels[ $error_type ] ) ? $error_type_labels[ $error_type ] : ucfirst( $error_type );
			$tooltip_content .= '<strong>' . esc_html__( 'Type:', 'gatewaykit' ) . '</strong> ' . esc_html( $error_type_label ) . '<br>';
		}
		if ( ! empty( $error_code ) ) {
			$tooltip_content .= '<strong>' . esc_html__( 'Code:', 'gatewaykit' ) . '</strong> ' . esc_html( $error_code ) . '<br>';
		}

		// Get error severity class
		$severity_class = $this->get_error_severity_class( $error_type );

		// Create display text (truncate raw text, then escape once at output).
		$raw_text = ! empty( $error_message ) ? $error_message : $error_type;
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $raw_text ) > 50 ) {
			$raw_text = mb_substr( $raw_text, 0, 47 ) . '...';
		} elseif ( strlen( $raw_text ) > 50 ) {
			$raw_text = substr( $raw_text, 0, 47 ) . '...';
		}

		return '<span class="gatewaykit-error-info ' . esc_attr( $severity_class ) . '" title="' . esc_attr( wp_strip_all_tags( $tooltip_content ) ) . '">' .
			   '<span class="dashicons dashicons-warning" style="margin-top: 2px;"></span> ' .
			   esc_html( $raw_text ) .
			   '</span>';
	}

	/**
		* Get error severity class
		*/
	private function get_error_severity_class( $error_type ) {
		$severity_classes = array(
			'license'       => 'gatewaykit-error-critical',
			'configuration' => 'gatewaykit-error-warning',
			'gateway'       => 'gatewaykit-error-error',
			'network'       => 'gatewaykit-error-error',
			'validation'    => 'gatewaykit-error-warning',
			'unknown'       => 'gatewaykit-error-error',
		);

		return isset( $severity_classes[ $error_type ] ) ? $severity_classes[ $error_type ] : 'gatewaykit-error-error';
	}

	/**
	 * Get bulk actions
	 *
	 * CSV export is a GatewayKit Pro feature (ARCH-022). When the current
	 * install is not licensed for Pro we omit the "Export Selected" bulk
	 * action entirely so the dropdown only offers "Delete Selected". The
	 * export endpoint in process_bulk_action() is also gated as a second
	 * line of defense.
	 */
	public function get_bulk_actions() {
		$actions = array(
			'delete' => __( 'Delete Selected', 'gatewaykit' ),
		);

		if ( gatewaykit_is_pro_licensed() ) {
			$actions['export'] = __( 'Export Selected', 'gatewaykit' );
		}

		return $actions;
	}

	/**
	 * Dispatch bulk actions early on admin_init.
	 *
	 * Single entry point for the Transactions page bulk actions (delete /
	 * export). Hooked on `admin_init:5` so it runs after settings init but
	 * before any page output — this lets `process_bulk_action()` safely call
	 * `wp_safe_redirect()` + `exit` without triggering "headers already sent".
	 *
	 * The actual nonce + capability + license checks live in
	 * `process_bulk_action()` (single source of truth).
	 */
	public static function dispatch_bulk_action() {
		if ( ! is_admin() ) {
			return;
		}

		// Only the transactions page issues bulk-action POSTs.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified inside process_bulk_action()
		$page = isset( $_REQUEST['page'] ) ? sanitize_key( wp_unslash( $_REQUEST['page'] ) ) : '';
		if ( 'gatewaykit-transactions' !== $page ) {
			return;
		}

		// Detect a bulk action from either the top or bottom selector.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified inside process_bulk_action()
		$action  = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified inside process_bulk_action()
		$action2 = isset( $_REQUEST['action2'] ) ? sanitize_key( wp_unslash( $_REQUEST['action2'] ) ) : '';

		if ( ! in_array( $action, array( 'delete', 'export' ), true ) && ! in_array( $action2, array( 'delete', 'export' ), true ) ) {
			return;
		}

		$table = new self();
		$table->process_bulk_action();
	}

	/**
	 * Process bulk action
	 */
	public function process_bulk_action() {
		$current_action = $this->current_action();
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			GatewayKit_Logger::get_instance()->debug( 'process_bulk_action called', array( 'current_action' => $current_action, 'plural' => $this->_args['plural'] ) );
		}

		if ( $current_action === 'delete' ) {
			// Nonce and capability checks using WordPress core helper
			check_admin_referer( 'bulk-' . $this->_args['plural'] );
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have permission to perform this action.', 'gatewaykit' ) );
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- nonce verified by check_admin_referer() above; intval sanitizes each value
			$transaction_ids = isset( $_POST['transaction_ids'] ) ? array_map( 'intval', wp_unslash( $_POST['transaction_ids'] ) ) : array();
			if ( empty( $transaction_ids ) ) {
				return; // No transactions selected
			}
			$this->bulk_delete( $transaction_ids );
			wp_safe_redirect( add_query_arg( 'deleted', count( $transaction_ids ), wp_get_referer() ) );
			exit;
		}

		if ( $current_action === 'export' ) {
			// Nonce and capability checks using WordPress core helper
			check_admin_referer( 'bulk-' . $this->_args['plural'] );
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have permission to perform this action.', 'gatewaykit' ) );
			}

			// Defense-in-depth: CSV export is a Pro feature. The bulk
			// action is hidden via get_bulk_actions() when unlicensed, but
			// a crafted POST could still reach this branch — block it.
			if ( ! gatewaykit_is_pro_licensed() ) {
				wp_die(
					sprintf(
						/* translators: %s: upgrade URL */
						esc_html__( 'CSV export is a GatewayKit Pro feature. Upgrade to Pro to unlock it: %s', 'gatewaykit' ),
						esc_url( gatewaykit_get_upgrade_url() )
					)
				);
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- nonce verified by check_admin_referer() above; intval sanitizes each value
			$transaction_ids = isset( $_POST['transaction_ids'] ) ? array_map( 'intval', wp_unslash( $_POST['transaction_ids'] ) ) : array();
			if ( empty( $transaction_ids ) ) {
				// Redirect back with an error notice if no transactions are selected
				$referer = wp_get_referer();
				if ( ! $referer ) {
					$referer = admin_url( 'admin.php?page=gatewaykit-transactions' );
				}
				wp_safe_redirect( add_query_arg(
					array(
						'gatewaykit_notice' => 'no_transactions_selected_for_export',
						'gatewaykit_notice_type' => 'error',
					),
					$referer
				) );
				exit;
			}
			$this->bulk_export( $transaction_ids );
			exit; // Exit after file download
		}
	}

	/**
	 * Bulk delete transactions
	 */
	private function bulk_delete( $transaction_ids ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'gatewaykit' ) );
		}

		foreach ( $transaction_ids as $transaction_id ) {
			$transaction = GatewayKit_Transaction_Model::find( $transaction_id );
			if ( $transaction ) {
				$transaction->delete();
			}
		}
	}

	/**
	 * Bulk export transactions
	 */
	public function bulk_export( $transaction_ids ) {
		$this->export_transactions( $transaction_ids );
	}

	/**
	 * Extra table nav
	 */
	public function extra_tablenav( $which ) {
		// Filters and search are rendered at the page level via a dedicated GET form
		// in GatewayKit_Admin_Settings::transactions_page() to avoid duplicate UI and mixed form methods.
		return;
	}

	/**
	 * Display the search box.
	 *
	 * @param string $text     The search button text.
	 * @param string $input_id The search input id.
	 */
	public function search_box( $text, $input_id ) {
		if ( empty( $_REQUEST['s'] ) && ! $this->has_items() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only read; rendered as a hidden input, no data mutation
			return;
		}

		$input_id = $input_id . '-search-input';

		if ( ! empty( $_REQUEST['orderby'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only read; rendered as a hidden input, no data mutation
			echo '<input type="hidden" name="orderby" value="' . esc_attr( sanitize_text_field( wp_unslash( $_REQUEST['orderby'] ) ) ) . '" />'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only read rendered as a hidden input
		}
		if ( ! empty( $_REQUEST['order'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only read; rendered as a hidden input, no data mutation
			echo '<input type="hidden" name="order" value="' . esc_attr( sanitize_text_field( wp_unslash( $_REQUEST['order'] ) ) ) . '" />'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only read rendered as a hidden input
		}
		?>
		<p class="search-box">
			<label class="screen-reader-text" for="<?php echo esc_attr( $input_id ); ?>"><?php echo esc_html( $text ); ?>:</label>
			<input type="search" id="<?php echo esc_attr( $input_id ); ?>" name="s" value="<?php _admin_search_query(); ?>" placeholder="<?php esc_attr_e( 'Search by name, receipt code, email, phone...', 'gatewaykit' ); ?>" />
			<?php submit_button( $text, 'button', false, false, array( 'id' => 'search-submit' ) ); ?>
		</p>
		<?php
	}

	/**
	 * No items message
	 */
	public function no_items() {
		esc_html_e( 'No transactions found.', 'gatewaykit' );
	}
}
