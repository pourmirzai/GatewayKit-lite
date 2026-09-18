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
			'cb'            => '<input type="checkbox" />',
			'id'            => __( 'Transaction', 'gatewaykit' ),
			'receipt_code'  => __( 'Receipt Code', 'gatewaykit' ),
			'user'          => __( 'User', 'gatewaykit' ),
			'gateway'       => __( 'Gateway', 'gatewaykit' ),
			'amount'        => __( 'Amount', 'gatewaykit' ),
			'description'   => __( 'Description', 'gatewaykit' ),
			'status'        => __( 'Status', 'gatewaykit' ),
			'error_details' => __( 'Error Info', 'gatewaykit' ),
			'authority'     => __( 'Authority', 'gatewaykit' ),
			'created_at'    => __( 'Date', 'gatewaykit' ),
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
		$saved    = get_user_option( $meta_key );

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
		$per_page = 20;
		$screen   = get_current_screen();
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
	 * Get transactions with optimized queries and caching.
	 *
	 * @param int $per_page Number of items per page.
	 * @param int $offset   Offset.
	 * @return array List of transactions.
	 */
	private function get_transactions( $per_page, $offset ) {
		return GatewayKit_Transaction_Query::get_transactions( $per_page, $offset );
	}

	/**
	 * Get total transactions count with caching.
	 *
	 * @return int Total transactions count.
	 */
	private function get_total_transactions_count() {
		return GatewayKit_Transaction_Query::get_total_count();
	}

	/**
	 * Get transaction IDs matching a filter context.
	 *
	 * @param array $filters Filter array { status, gateway, date_from, date_to, search }.
	 * @return int[]
	 */
	private function get_filtered_transaction_ids( $filters ) {
		return GatewayKit_Transaction_Query::get_filtered_transaction_ids( $filters );
	}

	/**
	 * Export all transactions (respecting active filters).
	 */
	public function export_all_transactions() {
		GatewayKit_Transaction_CSV_Exporter::export_all_transactions();
	}

	/**
	 * Export transactions to CSV with optimized batch processing.
	 *
	 * @param array $transaction_ids List of integer IDs.
	 */
	private function export_transactions( $transaction_ids ) {
		GatewayKit_Transaction_CSV_Exporter::export_transactions( $transaction_ids );
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
			$refund_url        = wp_nonce_url(
				add_query_arg(
					array(
						'page'           => 'gatewaykit-transactions',
						'action'         => 'refund',
						'transaction_id' => $item['id'],
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
					esc_attr(
						wp_json_encode(
							sprintf(
							/* translators: %s: refund amount */
								__( 'Are you sure you want to refund %s? This action cannot be undone.', 'gatewaykit' ),
								$item['amount'] . ' ' . $item['currency']
							)
						)
					),
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
			'pending'            => __( 'Pending', 'gatewaykit' ),
			'processing'         => __( 'Processing', 'gatewaykit' ),
			'completed'          => __( 'Completed', 'gatewaykit' ),
			'failed'             => __( 'Failed', 'gatewaykit' ),
			'cancelled'          => __( 'Cancelled', 'gatewaykit' ),
			'refunded'           => __( 'Refunded', 'gatewaykit' ),
			'partially_refunded' => __( 'Partial Refund', 'gatewaykit' ),
		);

		$status_classes = array(
			'pending'            => 'gatewaykit-status-pending',
			'processing'         => 'gatewaykit-status-processing',
			'completed'          => 'gatewaykit-status-completed',
			'failed'             => 'gatewaykit-status-failed',
			'cancelled'          => 'gatewaykit-status-cancelled',
			'refunded'           => 'gatewaykit-status-refunded',
			'partially_refunded' => 'gatewaykit-status-partial-refund',
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
		$error_type    = ! empty( $item['error_type'] ) ? $item['error_type'] : '';
		$error_code    = ! empty( $item['error_code'] ) ? $item['error_code'] : '';

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
				'license'       => __( 'License Error', 'gatewaykit' ),
				'gateway'       => __( 'Gateway Error', 'gatewaykit' ),
				'configuration' => __( 'Configuration Error', 'gatewaykit' ),
				'network'       => __( 'Network Error', 'gatewaykit' ),
				'validation'    => __( 'Validation Error', 'gatewaykit' ),
				'unknown'       => __( 'Unknown Error', 'gatewaykit' ),
			);
			$error_type_label  = isset( $error_type_labels[ $error_type ] ) ? $error_type_labels[ $error_type ] : ucfirst( $error_type );
			$tooltip_content  .= '<strong>' . esc_html__( 'Type:', 'gatewaykit' ) . '</strong> ' . esc_html( $error_type_label ) . '<br>';
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
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
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
			GatewayKit_Logger::get_instance()->debug(
				'process_bulk_action called',
				array(
					'current_action' => $current_action,
					'plural'         => $this->_args['plural'],
				)
			);
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
				wp_safe_redirect(
					add_query_arg(
						array(
							'gatewaykit_notice'      => 'no_transactions_selected_for_export',
							'gatewaykit_notice_type' => 'error',
						),
						$referer
					)
				);
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
