<?php
/**
 * GatewayKit Transaction Query
 *
 * Query builder and caching layer for transaction listings and counts.
 *
 * @package GatewayKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GatewayKit_Transaction_Query
 */
class GatewayKit_Transaction_Query {

	/**
	 * Parse request filters from $_GET.
	 *
	 * @return array Sanitized filter parameters.
	 */
	public static function parse_request_filters() {
		// Admin list-table GET filters; protected by manage_options check.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$status_filter  = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$gateway_filter = isset( $_GET['gateway'] ) ? sanitize_key( wp_unslash( $_GET['gateway'] ) ) : '';
		$search_raw     = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$orderby        = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'id';
		$order          = isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'DESC';
		$date_from      = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '';
		$date_to        = isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '';
		$request_sig    = wp_unslash( $_GET );
		// phpcs:enable

		return array(
			'status'      => $status_filter,
			'gateway'     => $gateway_filter,
			'search'      => $search_raw,
			'orderby'     => $orderby,
			'order'       => $order,
			'date_from'   => $date_from,
			'date_to'     => $date_to,
			'request_sig' => $request_sig,
		);
	}

	/**
	 * Get transactions with optimized queries and caching.
	 *
	 * @param int        $per_page Number of items per page.
	 * @param int        $offset   Offset.
	 * @param array|null $filters  Optional filter overrides.
	 * @return array List of transactions.
	 */
	public static function get_transactions( $per_page, $offset, $filters = null ) {
		global $wpdb;

		if ( null === $filters ) {
			$filters = self::parse_request_filters();
		}

		$status_filter  = $filters['status'] ?? '';
		$gateway_filter = $filters['gateway'] ?? '';
		$search_raw     = $filters['search'] ?? '';
		$orderby        = $filters['orderby'] ?? 'id';
		$order          = $filters['order'] ?? 'DESC';
		$date_from      = $filters['date_from'] ?? '';
		$date_to        = $filters['date_to'] ?? '';
		$request_sig    = $filters['request_sig'] ?? array();

		// Unique cache key based on request parameters.
		$cache_key = 'gatewaykit_transactions_' . md5(
			(string) wp_json_encode(
				array(
					'per_page' => $per_page,
					'offset'   => $offset,
					'request'  => $request_sig,
					'user_id'  => get_current_user_id(),
				)
			)
		);

		$use_cache = empty( $search_raw );
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

		if ( ! empty( $status_filter ) ) {
			$where[]        = 't.status = %s';
			$where_values[] = $status_filter;
		}

		if ( ! empty( $gateway_filter ) ) {
			$where[]        = 't.gateway = %s';
			$where_values[] = $gateway_filter;
		}

		if ( ! empty( $search_raw ) ) {
			$search_term = '%' . $wpdb->esc_like( $search_raw ) . '%';

			$search_conditions = array(
				't.receipt_token LIKE %s',
				't.ref_id LIKE %s',
				't.authority LIKE %s',
				'u.display_name LIKE %s',
				'u.user_email LIKE %s',
				'u.user_login LIKE %s',
			);
			$where_values      = array_merge( $where_values, array_fill( 0, 6, $search_term ) );

			if ( strlen( $search_raw ) > 2 ) {
				$search_conditions = array_merge(
					$search_conditions,
					array(
						't.form_data LIKE %s',
						'JSON_UNQUOTE(JSON_EXTRACT(t.form_data, "$.name")) LIKE %s',
						'JSON_UNQUOTE(JSON_EXTRACT(t.form_data, "$.email")) LIKE %s',
						'JSON_UNQUOTE(JSON_EXTRACT(t.form_data, "$.phone")) LIKE %s',
						'JSON_UNQUOTE(JSON_EXTRACT(t.form_data, "$.mobile")) LIKE %s',
					)
				);
				$where_values      = array_merge( $where_values, array_fill( 0, 5, $search_term ) );
			}

			$where[]       = '(' . implode( ' OR ', $search_conditions ) . ')';
			$joins[]       = 'LEFT JOIN %i u ON t.user_id = u.ID';
			$join_values[] = $wpdb->users;
		} else {
			$joins[]       = 'LEFT JOIN %i u ON t.user_id = u.ID';
			$join_values[] = $wpdb->users;
		}

		if ( '' !== $date_from ) {
			$where[]        = 't.created_at >= %s';
			$where_values[] = $date_from . ' 00:00:00';
		}
		if ( '' !== $date_to ) {
			$where[]        = 't.created_at <= %s';
			$where_values[] = $date_to . ' 23:59:59';
		}

		$where_clause = ! empty( $where ) ? 'WHERE ' . implode( ' AND ', $where ) : '';
		$join_clause  = ! empty( $joins ) ? implode( ' ', $joins ) : '';

		$order = strtoupper( $order );
		if ( ! in_array( $order, array( 'ASC', 'DESC' ), true ) ) {
			$order = 'DESC';
		}

		$allowed_orderby = array( 'id', 'amount', 'status', 'created_at' );
		if ( ! in_array( $orderby, $allowed_orderby, true ) ) {
			$orderby = 'id';
		}

		$force_index = '';
		$index_map   = array(
			'created_at' => 'idx_created_at',
			'status'     => 'idx_status',
			'amount'     => 'idx_amount_status',
		);
		if ( isset( $index_map[ $orderby ] ) ) {
			$force_index = "FORCE INDEX ({$index_map[ $orderby ]})";
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "SELECT t.* FROM %i t {$force_index} 
                {$join_clause} 
                {$where_clause} 
                ORDER BY t.{$orderby} {$order} 
                LIMIT %d OFFSET %d";
		// phpcs:enable

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnsupportedIdentifierPlaceholder
		$query   = $wpdb->prepare( $sql, array_merge( array( $table_name ), $join_values, $where_values, array( $per_page, $offset ) ) );
		$results = $wpdb->get_results( $query, ARRAY_A );
		// phpcs:enable

		if ( $use_cache && ! empty( $results ) ) {
			wp_cache_set( $cache_key, $results, 'gatewaykit_transactions', 300 );
		}

		return ! empty( $results ) ? $results : array();
	}

	/**
	 * Get total transactions count with caching.
	 *
	 * @param array|null $filters Optional filter overrides.
	 * @return int Total transactions count.
	 */
	public static function get_total_count( $filters = null ) {
		global $wpdb;

		if ( null === $filters ) {
			$filters = self::parse_request_filters();
		}

		$status_filter  = $filters['status'] ?? '';
		$gateway_filter = $filters['gateway'] ?? '';
		$search_raw     = $filters['search'] ?? '';
		$date_from      = $filters['date_from'] ?? '';
		$date_to        = $filters['date_to'] ?? '';
		$request_sig    = $filters['request_sig'] ?? array();

		$cache_key = 'gatewaykit_transactions_count_' . md5(
			(string) wp_json_encode(
				array(
					'request' => $request_sig,
					'user_id' => get_current_user_id(),
				)
			)
		);

		$use_cache = empty( $search_raw );
		if ( $use_cache ) {
			$count = wp_cache_get( $cache_key, 'gatewaykit_transactions' );
			if ( false !== $count ) {
				return (int) $count;
			}
		}

		$table_name   = $wpdb->prefix . 'gatewaykit_payment_transactions';
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

		if ( ! empty( $search_raw ) ) {
			$search_term = '%' . $wpdb->esc_like( $search_raw ) . '%';

			$search_conditions = array(
				'receipt_token LIKE %s',
				'ref_id LIKE %s',
				'authority LIKE %s',
				'description LIKE %s',
			);

			$where[]      = '(' . implode( ' OR ', $search_conditions ) . ')';
			$where_values = array_merge( $where_values, array_fill( 0, 4, $search_term ) );
		}

		if ( '' !== $date_from ) {
			$where[]        = 'created_at >= %s';
			$where_values[] = $date_from . ' 00:00:00';
		}
		if ( '' !== $date_to ) {
			$where[]        = 'created_at <= %s';
			$where_values[] = $date_to . ' 23:59:59';
		}

		$where_clause = ! empty( $where ) ? 'WHERE ' . implode( ' AND ', $where ) : '';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "SELECT COUNT(*) FROM %i t {$where_clause}";
		// phpcs:enable

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnsupportedIdentifierPlaceholder
		$sql   = $wpdb->prepare( $sql, array_merge( array( $table_name ), $where_values ) );
		$count = $wpdb->get_var( $sql );
		// phpcs:enable

		$final_count = ! empty( $count ) ? (int) $count : 0;

		if ( $use_cache ) {
			wp_cache_set( $cache_key, $final_count, 'gatewaykit_transactions', 300 );
		}

		return $final_count;
	}

	/**
	 * Get transaction IDs matching a filter context (capped at 10,000).
	 *
	 * @param array $filters Filter array { status, gateway, date_from, date_to, search }.
	 * @return int[] Matching transaction IDs.
	 */
	public static function get_filtered_transaction_ids( array $filters ) {
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
		$args[]   = 10000;
		$all_args = array_merge( array( $table_name ), $args );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnsupportedIdentifierPlaceholder
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM %i {$where_clause} ORDER BY id DESC LIMIT %d",
				$all_args
			)
		);
		// phpcs:enable

		return ! empty( $ids ) ? array_map( 'intval', $ids ) : array();
	}

	/**
	 * Retrieve a batch of transaction records for export.
	 *
	 * @param array $batch_ids Array of integer IDs.
	 * @return array Result rows.
	 */
	public static function get_batch_for_export( array $batch_ids ) {
		if ( empty( $batch_ids ) ) {
			return array();
		}

		global $wpdb;
		$table_name   = $wpdb->prefix . 'gatewaykit_payment_transactions';
		$placeholders = implode( ',', array_fill( 0, count( $batch_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnsupportedIdentifierPlaceholder
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, user_id, form_id, post_id, gateway, authority, ref_id, amount, discount_id, discount_amount, currency, description, status, gateway_response, form_data, user_data, callback_url, success_url, failure_url, receipt_token, receipt_token_created_at, ip_address, user_agent, created_at, updated_at, completed_at FROM %i WHERE id IN ({$placeholders}) ORDER BY id DESC",
				array_merge( array( $table_name ), $batch_ids )
			),
			ARRAY_A
		);
		// phpcs:enable

		return ! empty( $results ) ? $results : array();
	}
}
