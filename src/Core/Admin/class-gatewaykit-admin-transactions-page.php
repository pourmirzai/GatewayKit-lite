<?php
/**
 * Admin Transactions Page
 *
 * Handles the Transactions list table view, transaction export actions,
 * refund processing, and transaction details/notes AJAX endpoints.
 *
 * @package GatewayKit
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin Transactions Page Class
 */
class GatewayKit_Admin_Transactions_Page {

	/**
	 * Register hooks for transactions admin actions.
	 */
	public function register_hooks() {
		add_action( 'admin_init', array( $this, 'handle_transaction_exports' ) );
		add_action( 'admin_init', array( $this, 'handle_refund_action' ) );
		add_action( 'wp_ajax_gatewaykit_get_form_data', array( $this, 'ajax_get_form_data' ) );
		add_action( 'wp_ajax_gatewaykit_get_error_details', array( $this, 'ajax_get_error_details' ) );
		add_action( 'wp_ajax_gatewaykit_get_transaction_notes', array( $this, 'ajax_get_transaction_notes' ) );
		add_action( 'wp_ajax_gatewaykit_add_transaction_note', array( $this, 'ajax_add_transaction_note' ) );
	}

	/**
	 * Handle transaction CSV exports and action notices.
	 */
	public function handle_transaction_exports() {
		// Admin page routing; protected by capability checks below, no nonce needed for reading the page slug.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['page'] ) || sanitize_key( wp_unslash( $_GET['page'] ) ) !== 'gatewaykit-transactions' ) {
			return;
		}
		// phpcs:enable

		// Handle export all transactions coming from our explicit buttons (gatewaykit_action).
		if ( isset( $_POST['gatewaykit_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$action = sanitize_key( wp_unslash( $_POST['gatewaykit_action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			// Handle Export All Transactions.
			if ( 'export_all' === $action ) {
				if ( ! isset( $_POST['gatewaykit_export_all_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['gatewaykit_export_all_nonce'] ) ), 'gatewaykit_export_all_transactions_nonce' ) ) {
					wp_die( esc_html__( 'Security check failed.', 'gatewaykit' ) );
				}

				if ( ! current_user_can( 'manage_options' ) ) {
					wp_die( esc_html__( 'You do not have permission to perform this action.', 'gatewaykit' ) );
				}

				// Defense-in-depth license gate. The "Export All" button is
				// rendered disabled on Lite (ARCH-022) and the bulk action
				// is hidden from the dropdown, but a forged POST could still
				// reach this endpoint — never stream the CSV without Pro.
				if ( ! gatewaykit_is_pro_licensed() ) {
					wp_die(
						sprintf(
							/* translators: %s: upgrade URL */
							esc_html__( 'CSV export is a GatewayKit Pro feature. Upgrade to Pro to unlock it: %s', 'gatewaykit' ),
							esc_url( gatewaykit_get_upgrade_url() )
						)
					);
				}

				$transaction_table = new GatewayKit_Transaction_List_Table();
				$transaction_table->export_all_transactions();
				exit;
			}
		}

		// Handle notices after actions.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['deleted'] ) ) {
			$deleted_count = absint( wp_unslash( $_GET['deleted'] ) );
			if ( $deleted_count > 0 ) {
				add_action(
					'admin_notices',
					function () use ( $deleted_count ) {
						/* translators: %s: number of deleted transactions */
						echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( _n( '%s transaction deleted.', '%s transactions deleted.', $deleted_count, 'gatewaykit' ), $deleted_count ) ) . '</p></div>';
					}
				);
			}
		}

		if ( isset( $_GET['gatewaykit_notice'] ) && sanitize_key( wp_unslash( $_GET['gatewaykit_notice'] ) ) === 'no_transactions_selected_for_export' ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'No transactions selected for export.', 'gatewaykit' ) . '</p></div>';
				}
			);
		}

		if ( isset( $_GET['gatewaykit_notice'] ) ) {
			$notice_type = sanitize_key( wp_unslash( $_GET['gatewaykit_notice'] ) );

			if ( 'refund_success' === $notice_type ) {
				add_action(
					'admin_notices',
					function () {
						echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Refund processed successfully.', 'gatewaykit' ) . '</p></div>';
					}
				);
			}

			if ( 'refund_failed' === $notice_type ) {
				$message = isset( $_GET['gatewaykit_message'] ) ? sanitize_text_field( wp_unslash( $_GET['gatewaykit_message'] ) ) : '';
				add_action(
					'admin_notices',
					function () use ( $message ) {
						echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Refund failed: ', 'gatewaykit' ) . esc_html( $message ) . '</p></div>';
					}
				);
			}
		}
		// phpcs:enable
	}

	/**
	 * Handle single-row refund action before any output.
	 *
	 * Must run on admin_init so wp_safe_redirect() can send headers.
	 */
	public function handle_refund_action() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['page'] ) || 'gatewaykit-transactions' !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
			return;
		}
		if ( ! isset( $_GET['action'] ) || 'refund' !== sanitize_key( wp_unslash( $_GET['action'] ) ) ) {
			return;
		}
		// phpcs:enable

		// Defense-in-depth: refund is a Pro feature.
		if ( ! gatewaykit_is_pro_licensed() ) {
			wp_die(
				sprintf(
					/* translators: %s: upgrade URL */
					esc_html__( 'Refunds are a GatewayKit Pro feature. Upgrade to Pro to unlock it: %s', 'gatewaykit' ),
					esc_url( gatewaykit_get_upgrade_url() )
				)
			);
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'gatewaykit' ) );
		}

		$transaction_id = isset( $_GET['transaction_id'] ) ? absint( wp_unslash( $_GET['transaction_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! $transaction_id ) {
			wp_die( esc_html__( 'Invalid transaction ID.', 'gatewaykit' ) );
		}

		// Verify nonce.
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'gatewaykit_refund_' . $transaction_id ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			wp_die( esc_html__( 'Security check failed.', 'gatewaykit' ) );
		}

		$refund_service = GatewayKit_Refund_Service::get_instance();
		$result         = $refund_service->process_refund( $transaction_id );

		if ( is_wp_error( $result ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'               => 'gatewaykit-transactions',
						'gatewaykit_notice'  => 'refund_failed',
						'gatewaykit_message' => rawurlencode( $result->get_error_message() ),
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'              => 'gatewaykit-transactions',
					'gatewaykit_notice' => 'refund_success',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Render the transactions list table page.
	 */
	public function transactions_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'gatewaykit' ) );
		}

		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Payment Transactions', 'gatewaykit' ); ?></h1>

			<?php
			$transaction_table = new GatewayKit_Transaction_List_Table();
			$transaction_table->prepare_items();

			// CSV export is a Pro-only feature. When unlicensed we render the
			// button disabled with an inline upgrade hint so the entry point
			// stays visible (discoverable upsell) without ever executing the
			// export. The export endpoint itself is also gated (defense in
			// depth) in handle_transaction_exports().
			$can_export  = gatewaykit_is_pro_licensed();
			$upgrade_url = gatewaykit_get_upgrade_url();

			// Read filter context from GET so hidden export fields carry the
			// currently-active filters. These are also used by the filter form
			// further down. Protected by the manage_options capability check
			// (standard WP pattern, no nonce for filtering).
			// phpcs:disable WordPress.Security.NonceVerification.Recommended
			$cur_orderby   = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : '';
			$cur_order     = isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : '';
			$cur_status    = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
			$cur_gateway   = isset( $_GET['gateway'] ) ? sanitize_key( wp_unslash( $_GET['gateway'] ) ) : '';
			$cur_date_from = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '';
			$cur_date_to   = isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '';
			$cur_search    = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
			// phpcs:enable
			?>

			<?php if ( $can_export ) : ?>
				<!-- Export All Transactions Form (Pro) -->
				<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=gatewaykit-transactions' ) ); ?>" style="display: inline-block; margin-right: 10px;">
					<?php wp_nonce_field( 'gatewaykit_export_all_transactions_nonce', 'gatewaykit_export_all_nonce' ); ?>
					<input type="hidden" name="gatewaykit_action" value="export_all" />
					<!-- Pass current filter context so export respects active filters -->
					<input type="hidden" name="gatewaykit_export_status" value="<?php echo esc_attr( $cur_status ); ?>" />
					<input type="hidden" name="gatewaykit_export_gateway" value="<?php echo esc_attr( $cur_gateway ); ?>" />
					<input type="hidden" name="gatewaykit_export_date_from" value="<?php echo esc_attr( $cur_date_from ); ?>" />
					<input type="hidden" name="gatewaykit_export_date_to" value="<?php echo esc_attr( $cur_date_to ); ?>" />
					<input type="hidden" name="gatewaykit_export_s" value="<?php echo esc_attr( $cur_search ); ?>" />
					<button type="submit" class="button button-primary">
						<span class="dashicons dashicons-download" style="margin-top: 3px;"></span>
						<?php esc_html_e( 'Export All Transactions', 'gatewaykit' ); ?>
					</button>
				</form>
			<?php else : ?>
				<!-- Export All Transactions — locked (Lite). Pro unlocks CSV export. -->
				<span class="gatewaykit-locked-action" style="display: inline-block; margin-right: 10px; vertical-align: middle;">
					<button type="button" class="button button-primary" disabled="disabled" aria-disabled="true"
						title="<?php esc_attr_e( 'CSV export is a GatewayKit Pro feature. Upgrade to unlock.', 'gatewaykit' ); ?>">
						<span class="dashicons dashicons-lock" style="margin-top: 3px;"></span>
						<?php esc_html_e( 'Export All Transactions', 'gatewaykit' ); ?>
					</button>
					<a class="gatewaykit-upgrade-pill" href="<?php echo esc_url( $upgrade_url ); ?>" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'Upgrade to Pro', 'gatewaykit' ); ?>
					</a>
				</span>
			<?php endif; ?>

			<!-- Filters and Search Form (GET) -->
			<?php
			// Filter context ($cur_*) already read above and shared with the
			// export form. No need to re-read from $_GET.
			?>
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="wp-clearfix" style="display:block; margin:10px 0;">
				<input type="hidden" name="page" value="gatewaykit-transactions" />
				<?php if ( ! empty( $cur_orderby ) ) : ?>
					<input type="hidden" name="orderby" value="<?php echo esc_attr( $cur_orderby ); ?>" />
				<?php endif; ?>
				<?php if ( ! empty( $cur_order ) ) : ?>
					<input type="hidden" name="order" value="<?php echo esc_attr( $cur_order ); ?>" />
				<?php endif; ?>

				<div class="alignleft actions">
					<select name="status" id="gatewaykit-filter-status">
						<option value=""><?php esc_html_e( 'All Statuses', 'gatewaykit' ); ?></option>
						<option value="pending" <?php selected( $cur_status, 'pending' ); ?>><?php esc_html_e( 'Pending', 'gatewaykit' ); ?></option>
						<option value="processing" <?php selected( $cur_status, 'processing' ); ?>><?php esc_html_e( 'Processing', 'gatewaykit' ); ?></option>
						<option value="completed" <?php selected( $cur_status, 'completed' ); ?>><?php esc_html_e( 'Completed', 'gatewaykit' ); ?></option>
						<option value="failed" <?php selected( $cur_status, 'failed' ); ?>><?php esc_html_e( 'Failed', 'gatewaykit' ); ?></option>
						<option value="cancelled" <?php selected( $cur_status, 'cancelled' ); ?>><?php esc_html_e( 'Cancelled', 'gatewaykit' ); ?></option>
					</select>

					<select name="gateway" id="gatewaykit-filter-gateway">
						<option value=""><?php esc_html_e( 'All Gateways', 'gatewaykit' ); ?></option>
						<?php
						$gateway_manager = GatewayKit_Gateway_Manager::get_instance();
						$gateways        = $gateway_manager->get_available_gateways();
						foreach ( $gateways as $gateway_id => $gateway ) {
							echo '<option value="' . esc_attr( $gateway_id ) . '" ' . selected( $cur_gateway, $gateway_id, false ) . '>' . esc_html( $gateway->get_gateway_name() ) . '</option>';
						}
						?>
					</select>

					<?php
					// Date range filter — always available (moved to Lite in 1.2.0).
					?>
					<span class="gatewaykit-date-filter">
						<label class="screen-reader-text" for="gatewaykit-filter-date-from"><?php esc_html_e( 'Date From', 'gatewaykit' ); ?></label>
						<input type="date" name="date_from" id="gatewaykit-filter-date-from"
							value="<?php echo esc_attr( $cur_date_from ); ?>"
							placeholder="<?php esc_attr_e( 'From', 'gatewaykit' ); ?>"
						/>
						<label class="screen-reader-text" for="gatewaykit-filter-date-to"><?php esc_html_e( 'Date To', 'gatewaykit' ); ?></label>
						<input type="date" name="date_to" id="gatewaykit-filter-date-to"
							value="<?php echo esc_attr( $cur_date_to ); ?>"
							placeholder="<?php esc_attr_e( 'To', 'gatewaykit' ); ?>"
						/>
					</span>

					<button type="submit" id="gatewaykit-filter-btn" class="button"><?php esc_html_e( 'Filter', 'gatewaykit' ); ?></button>
				</div>

				<div class="alignright actions">
					<p class="search-box">
						<label class="screen-reader-text" for="transaction-search-input"><?php esc_html_e( 'Search Transactions:', 'gatewaykit' ); ?></label>
						<input type="search" id="transaction-search-input" name="s" value="<?php echo esc_attr( $cur_search ); ?>" placeholder="<?php esc_attr_e( 'Search by name, receipt code, email, phone...', 'gatewaykit' ); ?>" />
						<button type="submit" id="gatewaykit-search-btn" class="button"><?php esc_html_e( 'Search Transactions', 'gatewaykit' ); ?></button>
					</p>
				</div>
			</form>

			<!-- WP_List_Table Form for Bulk Actions (POST) -->
			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=gatewaykit-transactions' ) ); ?>">
				<input type="hidden" name="page" value="gatewaykit-transactions" />
				<?php $transaction_table->display(); ?>
			</form>

			<!-- Form Data Modal -->
			<div id="gatewaykit-form-data-modal" class="gatewaykit-modal" style="display: none;">
				<div class="gatewaykit-modal-overlay"></div>
				<div class="gatewaykit-modal-content">
					<div class="gatewaykit-modal-header">
						<h2><?php esc_html_e( 'Transaction Form Data', 'gatewaykit' ); ?></h2>
						<button type="button" class="gatewaykit-modal-close">&times;</button>
					</div>
					<div class="gatewaykit-modal-body">
						<div id="gatewaykit-form-data-content">
							<p><?php esc_html_e( 'Loading...', 'gatewaykit' ); ?></p>
						</div>
					</div>
				</div>
			</div>

			<!-- Transaction Notes Modal -->
			<div id="gatewaykit-notes-modal" class="gatewaykit-modal" style="display: none;">
				<div class="gatewaykit-modal-overlay"></div>
				<div class="gatewaykit-modal-content">
					<div class="gatewaykit-modal-header">
						<h2><?php esc_html_e( 'Transaction Notes', 'gatewaykit' ); ?></h2>
						<button type="button" class="gatewaykit-modal-close">&times;</button>
					</div>
					<div class="gatewaykit-modal-body">
						<div id="gatewaykit-notes-content">
							<p><?php esc_html_e( 'Loading...', 'gatewaykit' ); ?></p>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX handler to get form data for a transaction.
	 */
	public function ajax_get_form_data() {
		if ( ! GatewayKit_Admin_Ajax_Guard::verify() ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified via GatewayKit_Admin_Ajax_Guard::verify().
		// Get transaction ID from request.
		$transaction_id = isset( $_POST['transaction_id'] ) ? absint( wp_unslash( $_POST['transaction_id'] ) ) : 0;
		// phpcs:enable

		if ( empty( $transaction_id ) ) {
			wp_send_json_error( __( 'Transaction ID is required.', 'gatewaykit' ) );
			return;
		}

		global $wpdb;
		$table_name = $wpdb->prefix . 'gatewaykit_payment_transactions';

		// Get transaction data (AJAX admin-only request; no cache — real-time view).
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnsupportedIdentifierPlaceholder -- admin AJAX; real-time single-record lookup by PK, %i supported in WP 6.2+
		$transaction = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id, user_id, form_id, post_id, gateway, authority, ref_id, amount, discount_id, discount_amount, currency, description, status, gateway_response, form_data, user_data, callback_url, success_url, failure_url, receipt_token, receipt_token_created_at, ip_address, user_agent, created_at, updated_at, completed_at FROM %i WHERE id = %d',
				$table_name,
				$transaction_id
			)
		);
		// phpcs:enable

		if ( ! $transaction ) {
			wp_send_json_error( __( 'Transaction not found.', 'gatewaykit' ) );
			return;
		}

		// Get user data if user exists.
		$user_data = array();
		if ( $transaction->user_id ) {
			$user = get_user_by( 'id', $transaction->user_id );
			if ( $user ) {
				$user_data = array(
					'id'              => $user->ID,
					'display_name'    => $user->display_name,
					'user_email'      => $user->user_email,
					'user_registered' => $user->user_registered,
				);
			}
		}

		// Parse form data.
		$form_data = array();
		if ( ! empty( $transaction->form_data ) ) {
			$form_data = json_decode( $transaction->form_data, true );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				$form_data = array( 'raw' => $transaction->form_data );
			}
		}

		// Format date using WordPress date_i18n for consistency with WP-Parsi plugin.
		$created_at = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $transaction->created_at ) );

		// Prepare response.
		$tx_discount_amount = (float) ( $transaction->discount_amount ?? 0 );
		$tx_discount_id     = (int) ( $transaction->discount_id ?? 0 );
		$discount_code      = '';
		if ( $tx_discount_id > 0 && class_exists( 'GatewayKit_Discount_Model' ) && method_exists( 'GatewayKit_Discount_Model', 'get_by_id' ) ) {
			$d = GatewayKit_Discount_Model::get_by_id( $tx_discount_id );
			if ( $d ) {
				$discount_code = $d->get_data( 'code' );
			}
		}

		$response = array(
			'transaction_id'  => $transaction->id,
			'form_data'       => ! empty( $form_data ) ? $form_data : array(),
			'user_data'       => ! empty( $user_data ) ? $user_data : array(),
			'user_id'         => $transaction->user_id,
			'amount'          => $transaction->amount,
			'currency'        => $transaction->currency,
			'original_amount' => (float) $transaction->amount + $tx_discount_amount,
			'discount_amount' => $tx_discount_amount,
			'discount_code'   => $discount_code,
			'is_free_order'   => ( (float) $transaction->amount <= 0 && 'completed' === $transaction->status ),
			'status'          => $transaction->status,
			'description'     => $transaction->description,
			'created_at'      => $created_at,
			'receipt_code'    => $transaction->receipt_code ?? '',
			'payment_gateway' => $transaction->payment_gateway ?? '',
		);

		// Send JSON response.
		wp_send_json_success( $response );
	}

	/**
	 * AJAX handler to get error details for a transaction.
	 */
	public function ajax_get_error_details() {
		if ( ! GatewayKit_Admin_Ajax_Guard::verify() ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified via GatewayKit_Admin_Ajax_Guard::verify().
		// Get transaction ID from request.
		$transaction_id = isset( $_POST['transaction_id'] ) ? absint( wp_unslash( $_POST['transaction_id'] ) ) : 0;
		// phpcs:enable

		if ( empty( $transaction_id ) ) {
			wp_send_json_error( __( 'Transaction ID is required.', 'gatewaykit' ) );
			return;
		}

		// Get transaction.
		$transaction = GatewayKit_Transaction_Model::find( $transaction_id );
		if ( ! $transaction ) {
			wp_send_json_error( __( 'Transaction not found.', 'gatewaykit' ) );
			return;
		}

		// Get error details.
		$error_message   = $transaction->get( 'error_message' );
		$error_code      = $transaction->get( 'error_code' );
		$error_type      = $transaction->get( 'error_type' );
		$error_details   = $transaction->get( 'error_details' );
		$error_timestamp = $transaction->get( 'error_timestamp' );

		// Format error type label.
		$error_type_labels = array(
			'license'       => __( 'License Error', 'gatewaykit' ),
			'gateway'       => __( 'Gateway Error', 'gatewaykit' ),
			'configuration' => __( 'Configuration Error', 'gatewaykit' ),
			'network'       => __( 'Network Error', 'gatewaykit' ),
			'validation'    => __( 'Validation Error', 'gatewaykit' ),
			'unknown'       => __( 'Unknown Error', 'gatewaykit' ),
		);
		$fallback_type     = ! empty( $error_type ) ? $error_type : 'unknown';
		$error_type_label  = isset( $error_type_labels[ $error_type ] ) ? $error_type_labels[ $error_type ] : ucfirst( $fallback_type );

		// Format timestamp.
		$formatted_timestamp = '';
		if ( $error_timestamp ) {
			$formatted_timestamp = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $error_timestamp ) );
		}

		// Prepare response.
		$response = array(
			'error_message'    => ! empty( $error_message ) ? $error_message : __( 'No error message available', 'gatewaykit' ),
			'error_code'       => ! empty( $error_code ) ? $error_code : '',
			'error_type'       => ! empty( $error_type ) ? $error_type : 'unknown',
			'error_type_label' => $error_type_label,
			'error_details'    => ! empty( $error_details ) ? $error_details : array(),
			'error_timestamp'  => $formatted_timestamp,
		);

		// Send JSON response.
		wp_send_json_success( $response );
	}

	/**
	 * AJAX handler: get transaction notes.
	 */
	public function ajax_get_transaction_notes() {
		if ( ! GatewayKit_Admin_Ajax_Guard::verify() ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified via GatewayKit_Admin_Ajax_Guard::verify().
		$transaction_id = isset( $_POST['transaction_id'] ) ? absint( wp_unslash( $_POST['transaction_id'] ) ) : 0;
		// phpcs:enable

		if ( ! $transaction_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid transaction ID.', 'gatewaykit' ) ) );
			return;
		}

		$transaction = GatewayKit_Transaction_Model::find( $transaction_id );
		if ( ! $transaction ) {
			wp_send_json_error( array( 'message' => __( 'Transaction not found.', 'gatewaykit' ) ) );
			return;
		}

		wp_send_json_success( array( 'notes' => $transaction->get_notes() ) );
	}

	/**
	 * AJAX handler: add a note to a transaction.
	 */
	public function ajax_add_transaction_note() {
		if ( ! GatewayKit_Admin_Ajax_Guard::verify() ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified via GatewayKit_Admin_Ajax_Guard::verify().
		$transaction_id = isset( $_POST['transaction_id'] ) ? absint( wp_unslash( $_POST['transaction_id'] ) ) : 0;
		$note           = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';
		// phpcs:enable

		if ( ! $transaction_id || '' === $note ) {
			wp_send_json_error( array( 'message' => __( 'Transaction ID and note are required.', 'gatewaykit' ) ) );
			return;
		}

		$transaction = GatewayKit_Transaction_Model::find( $transaction_id );
		if ( ! $transaction ) {
			wp_send_json_error( array( 'message' => __( 'Transaction not found.', 'gatewaykit' ) ) );
			return;
		}

		$result = $transaction->add_note( $note );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			return;
		}

		wp_send_json_success( array( 'notes' => $transaction->get_notes() ) );
	}
}
