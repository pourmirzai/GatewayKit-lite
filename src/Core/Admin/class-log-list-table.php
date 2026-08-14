<?php
/**
 * Log List Table
 *
 * Displays GatewayKit logs in a WordPress admin list table.
 *
 * Columns: id, time, level, message, context, transaction.
 * Context is rendered via GatewayKit_Log_Formatter::format_log_entry()
 * (parsed + sensitive-key redaction).
 *
 * Bulk actions (copy/export) are wired to the Phase 2 AJAX handlers
 * (gatewaykit_copy_logs / gatewaykit_export_logs) from assets/js/admin-logs.js.
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
 * Log List Table Class
 */
class GatewayKit_Log_List_Table extends WP_List_Table {

	/**
	 * Active filters (read from $_GET), normalized.
	 *
	 * @var array
	 */
	private $filters = array();

	/**
	 * Constructor
	 */
	public function __construct() {
		parent::__construct(
			array(
				// Stable, non-translated slugs (affect nonce action).
				'singular' => 'log',
				'plural'   => 'logs',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Get columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'cb'          => '<input type="checkbox" />',
			'id'          => __( 'ID', 'gatewaykit' ),
			'time'        => __( 'Time', 'gatewaykit' ),
			'level'       => __( 'Level', 'gatewaykit' ),
			'message'     => __( 'Message', 'gatewaykit' ),
			'context'     => __( 'Context', 'gatewaykit' ),
			'transaction' => __( 'Transaction', 'gatewaykit' ),
		);
	}

	/**
	 * Get sortable columns.
	 *
	 * @return array
	 */
	public function get_sortable_columns() {
		return array(
			'id'   => array( 'id', true ),
			'time' => array( 'created_at', true ),
		);
	}

	/**
	 * Read and normalize the current filter set from $_GET.
	 *
	 * @return array
	 */
	private function get_filters() {
		// Admin list-table GET filters; protected by the manage_options capability
		// check in prepare_items() rather than a nonce (standard WP pattern).
		$level          = isset( $_GET['log_level'] ) ? sanitize_key( wp_unslash( $_GET['log_level'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$valid_levels   = array( 'debug', 'info', 'warning', 'error' );
		$transaction_id = isset( $_GET['transaction_id'] ) ? absint( wp_unslash( $_GET['transaction_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$date_from      = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$date_to        = isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search         = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$filters = array();

		if ( in_array( $level, $valid_levels, true ) ) {
			$filters['level'] = $level;
		}
		if ( $transaction_id > 0 ) {
			$filters['transaction_id'] = $transaction_id;
		}
		if ( '' !== $date_from ) {
			$filters['date_from'] = $date_from;
		}
		if ( '' !== $date_to ) {
			$filters['date_to'] = $date_to;
		}
		if ( '' !== $search ) {
			$filters['search'] = $search;
		}

		return $filters;
	}

	/**
	 * Prepare items.
	 */
	public function prepare_items() {
		// Capability guard (defense in depth; menu also enforces manage_options).
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'gatewaykit' ) );
		}

		$columns               = $this->get_columns();
		$hidden                = array();
		$sortable              = $this->get_sortable_columns();
		$this->_column_headers = array( $columns, $hidden, $sortable );

		$this->filters = $this->get_filters();

		$logger = GatewayKit_Logger::get_instance();

		$per_page     = apply_filters( 'gatewaykit_logs_per_page', 30 );
		$per_page     = max( 1, (int) $per_page );
		$current_page = $this->get_pagenum();
		$offset       = ( $current_page - 1 ) * $per_page;

		$this->items = $logger->get_logs( $this->filters, $per_page, $offset );

		$total_items = $logger->get_logs_count( $this->filters );

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => ceil( $total_items / $per_page ),
			)
		);
	}

	/**
	 * Render the checkbox column.
	 *
	 * @param object $item Log row.
	 * @return string
	 */
	public function column_cb( $item ) {
		return sprintf(
			'<input type="checkbox" class="gatewaykit-log-checkbox" name="log_ids[]" value="%d" />',
			(int) $item->id
		);
	}

	/**
	 * Render the ID column.
	 *
	 * @param object $item Log row.
	 * @return string
	 */
	public function column_id( $item ) {
		return '<code>#' . esc_html( (int) $item->id ) . '</code>';
	}

	/**
	 * Render the time column.
	 *
	 * @param object $item Log row.
	 * @return string
	 */
	public function column_time( $item ) {
		if ( empty( $item->created_at ) ) {
			return '&mdash;';
		}
		return esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $item->created_at ) ) );
	}

	/**
	 * Render the level column (color-coded badge).
	 *
	 * @param object $item Log row.
	 * @return string
	 */
	public function column_level( $item ) {
		$formatter = GatewayKit_Log_Formatter::get_instance();
		$entry     = $formatter->format_log_entry( $item );

		return '<span class="gatewaykit-log-level ' . esc_attr( $entry['level_class'] ) . '">' . esc_html( $entry['level'] ) . '</span>';
	}

	/**
	 * Render the message column.
	 *
	 * @param object $item Log row.
	 * @return string
	 */
	public function column_message( $item ) {
		$formatter = GatewayKit_Log_Formatter::get_instance();
		$entry     = $formatter->format_log_entry( $item );

		return esc_html( $entry['message'] );
	}

	/**
	 * Render the context column (formatted + redacted via the formatter).
	 *
	 * @param object $item Log row.
	 * @return string
	 */
	public function column_context( $item ) {
		$formatter = GatewayKit_Log_Formatter::get_instance();
		$entry     = $formatter->format_log_entry( $item );

		$context_text = isset( $entry['context_text'] ) ? trim( (string) $entry['context_text'] ) : '';

		if ( '' === $context_text ) {
			return '<span class="gatewaykit-log-context-empty">&mdash;</span>';
		}

		// Collapsible block: keep raw context readable without bloating the row.
		return '<details class="gatewaykit-log-context"><summary>' .
				esc_html__( 'Show', 'gatewaykit' ) .
				'</summary><pre>' . esc_html( $context_text ) . '</pre></details>';
	}

	/**
	 * Render the transaction column.
	 *
	 * @param object $item Log row.
	 * @return string
	 */
	public function column_transaction( $item ) {
		$transaction_id = isset( $item->transaction_id ) ? (int) $item->transaction_id : 0;

		if ( $transaction_id <= 0 ) {
			return '<span class="gatewaykit-log-context-empty">&mdash;</span>';
		}

		$url = admin_url( 'admin.php?page=gatewaykit-transactions&s=' . $transaction_id );

		return '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">#' . esc_html( $transaction_id ) . '</a>';
	}

	/**
	 * Default column fallback.
	 *
	 * @param object $item        Log row.
	 * @param string $column_name Column name.
	 * @return string
	 */
	public function column_default( $item, $column_name ) {
		if ( isset( $item->{$column_name} ) ) {
			return esc_html( $item->{$column_name} );
		}
		return '';
	}

	/**
	 * Add the log level as a CSS class on each row for color tinting.
	 *
	 * @param object $item Log row.
	 */
	public function single_row( $item ) {
		$level = isset( $item->level ) ? sanitize_key( $item->level ) : '';
		echo '<tr class="gatewaykit-log-' . esc_attr( $level ) . '">';
		$this->single_row_columns( $item );
		echo '</tr>';
	}

	/**
	 * Bulk actions.
	 *
	 * Copy/Export are handled client-side via AJAX (Phase 2 handlers),
	 * but we expose them in the dropdown for discoverability.
	 *
	 * @return array
	 */
	public function get_bulk_actions() {
		return array(
			'copy'   => __( 'Copy Selected', 'gatewaykit' ),
			'export' => __( 'Export Selected', 'gatewaykit' ),
		);
	}

	/**
	 * No server-side bulk processing: copy/export run through AJAX.
	 *
	 * @return void
	 */
	public function process_bulk_action() {
		// Intentionally empty — handled by assets/js/admin-logs.js.
	}

	/**
	 * Extra table nav (kept empty; filters render at page level).
	 *
	 * @param string $which Top or bottom.
	 */
	public function extra_tablenav( $which ) {
		return;
	}

	/**
	 * No items message.
	 *
	 * @return void
	 */
	public function no_items() {
		esc_html_e( 'No logs found.', 'gatewaykit' );
	}
}
