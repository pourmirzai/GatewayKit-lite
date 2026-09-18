<?php
/**
 * Admin Logs Page
 *
 * Handles rendering the payment logs list table and related AJAX actions
 * (copy to clipboard, export as file).
 *
 * @package GatewayKit
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin Logs Page Class
 */
class GatewayKit_Admin_Logs_Page {

	/**
	 * Register AJAX hooks.
	 */
	public function register_hooks() {
		add_action( 'wp_ajax_gatewaykit_copy_logs', array( $this, 'ajax_copy_logs' ) );
		add_action( 'wp_ajax_gatewaykit_export_logs', array( $this, 'ajax_export_logs' ) );
		add_action( 'wp_ajax_gatewaykit_clear_logs', array( $this, 'ajax_clear_logs' ) );
	}

	/**
	 * Render payment logs page.
	 */
	public function logs_page() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Payment Logs', 'gatewaykit' ); ?></h1>

			<div class="gatewaykit-logs-container">
				<?php $this->render_logs_content(); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render logs content.
	 *
	 * Filter form (GET) + Copy/Export buttons (AJAX) + WP_List_Table.
	 */
	public function render_logs_content() {
		// Capability check (defense in depth; the menu also requires manage_options).
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'gatewaykit' ) );
		}

		$list_table = new GatewayKit_Log_List_Table();
		$list_table->prepare_items();

		// Current filter values (for repopulating the form).
		// Admin list-table GET filters; protected by the manage_options check above.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$cur_level       = isset( $_GET['log_level'] ) ? sanitize_key( wp_unslash( $_GET['log_level'] ) ) : '';
		$cur_date_from   = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '';
		$cur_date_to     = isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '';
		$cur_transaction = isset( $_GET['transaction_id'] ) ? absint( wp_unslash( $_GET['transaction_id'] ) ) : '';
		$cur_search      = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		// phpcs:enable
		?>
		<!-- Copy / Export / Clear action buttons (AJAX via admin-logs.js) -->
		<div class="gatewaykit-logs-actions alignleft actions">
			<button type="button" class="button button-secondary gatewaykit-logs-copy">
				<span class="dashicons dashicons-clipboard" style="margin-top:2px;"></span>
				<?php esc_html_e( 'Copy Selected', 'gatewaykit' ); ?>
			</button>
			<button type="button" class="button button-secondary gatewaykit-logs-export">
				<span class="dashicons dashicons-download" style="margin-top:2px;"></span>
				<?php esc_html_e( 'Export Selected', 'gatewaykit' ); ?>
			</button>
			<button type="button" class="button button-secondary button-link-delete gatewaykit-logs-clear" style="color:#b32d2e;">
				<span class="dashicons dashicons-trash" style="margin-top:2px;"></span>
				<?php esc_html_e( 'Clear All Logs', 'gatewaykit' ); ?>
			</button>
			<span class="gatewaykit-logs-notice" role="status" aria-live="polite"></span>
		</div>

		<!-- Filters & Search Form (GET) -->
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="gatewaykit-logs-filters wp-clearfix">
			<input type="hidden" name="page" value="gatewaykit-logs" />

			<div class="alignleft actions">
				<label for="gatewaykit-log-filter-level" class="screen-reader-text"><?php esc_html_e( 'Filter by level', 'gatewaykit' ); ?></label>
				<select name="log_level" id="gatewaykit-log-filter-level">
					<option value=""><?php esc_html_e( 'All Levels', 'gatewaykit' ); ?></option>
					<option value="error" <?php selected( $cur_level, 'error' ); ?>><?php esc_html_e( 'Error', 'gatewaykit' ); ?></option>
					<option value="warning" <?php selected( $cur_level, 'warning' ); ?>><?php esc_html_e( 'Warning', 'gatewaykit' ); ?></option>
					<option value="info" <?php selected( $cur_level, 'info' ); ?>><?php esc_html_e( 'Info', 'gatewaykit' ); ?></option>
					<option value="debug" <?php selected( $cur_level, 'debug' ); ?>><?php esc_html_e( 'Debug', 'gatewaykit' ); ?></option>
				</select>

				<label for="gatewaykit-log-filter-date-from" class="screen-reader-text"><?php esc_html_e( 'Date from', 'gatewaykit' ); ?></label>
				<input type="date" name="date_from" id="gatewaykit-log-filter-date-from" value="<?php echo esc_attr( $cur_date_from ); ?>" placeholder="<?php esc_attr_e( 'From date', 'gatewaykit' ); ?>" />

				<label for="gatewaykit-log-filter-date-to" class="screen-reader-text"><?php esc_html_e( 'Date to', 'gatewaykit' ); ?></label>
				<input type="date" name="date_to" id="gatewaykit-log-filter-date-to" value="<?php echo esc_attr( $cur_date_to ); ?>" placeholder="<?php esc_attr_e( 'To date', 'gatewaykit' ); ?>" />

				<label for="gatewaykit-log-filter-transaction" class="screen-reader-text"><?php esc_html_e( 'Transaction ID', 'gatewaykit' ); ?></label>
				<input type="number" name="transaction_id" id="gatewaykit-log-filter-transaction" value="<?php echo esc_attr( $cur_transaction ); ?>" placeholder="<?php esc_attr_e( 'Transaction ID', 'gatewaykit' ); ?>" min="1" style="width:130px;" />

				<button type="submit" class="button"><?php esc_html_e( 'Filter', 'gatewaykit' ); ?></button>
			</div>

			<div class="alignright actions">
				<label class="screen-reader-text" for="gatewaykit-log-search-input"><?php esc_html_e( 'Search logs:', 'gatewaykit' ); ?></label>
				<input type="search" id="gatewaykit-log-search-input" name="s" value="<?php echo esc_attr( $cur_search ); ?>" placeholder="<?php esc_attr_e( 'Search message...', 'gatewaykit' ); ?>" />
				<button type="submit" class="button"><?php esc_html_e( 'Search Logs', 'gatewaykit' ); ?></button>
			</div>
		</form>

		<!-- Bulk actions form wrapping the list table (POST). Copy/export intercepted by JS. -->
		<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=gatewaykit-logs' ) ); ?>" id="gatewaykit-logs-table-form">
			<?php wp_nonce_field( 'bulk-logs' ); ?>
			<?php $list_table->display(); ?>
		</form>
		<?php
	}

	/**
	 * Documentation URL.
	 *
	 * @return string
	 */
	public function get_docs_url() {
		return 'https://gatewaykit.pourmirzai.com/docs/';
	}

	/**
	 * AJAX handler: copy selected logs to clipboard (returns formatted text).
	 *
	 * Requires: nonce, manage_options capability, admin_ajax rate limit.
	 */
	public function ajax_copy_logs() {
		if ( ! GatewayKit_Admin_Ajax_Guard::verify() ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified via GatewayKit_Admin_Ajax_Guard::verify().
		// Collect requested log IDs.
		$log_ids_raw = isset( $_POST['log_ids'] ) ? wp_unslash( $_POST['log_ids'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized via parse_log_ids() below
		$log_ids     = $this->parse_log_ids( $log_ids_raw );
		// phpcs:enable

		if ( empty( $log_ids ) ) {
			wp_send_json_error( __( 'No valid log IDs provided.', 'gatewaykit' ) );
			return;
		}

		$logs = GatewayKit_Logger::get_instance()->get_logs_by_ids( $log_ids );

		if ( empty( $logs ) ) {
			wp_send_json_error( __( 'No logs found for the selected entries.', 'gatewaykit' ) );
			return;
		}

		$text    = GatewayKit_Log_Formatter::get_instance()->format_for_export( $logs, true );
		$preview = GatewayKit_Log_Formatter::get_instance()->format_log_entry( $logs[0] );

		wp_send_json_success(
			array(
				'text'     => $text,
				'count'    => count( $logs ),
				'first_id' => $preview['id'],
			)
		);
	}

	/**
	 * AJAX handler: download selected logs as a .txt file.
	 *
	 * Streams a text/plain attachment (Content-Disposition) so the browser
	 * downloads it. Requires: nonce, manage_options capability, rate limit.
	 */
	public function ajax_export_logs() {
		// Rate limiting check.
		$rate_limiter = GatewayKit_Rate_Limiter::get_instance();
		$rate_check   = $rate_limiter->check_rate_limit( 'admin_ajax' );
		if ( is_wp_error( $rate_check ) ) {
			wp_send_json_error( $rate_check->get_error_message() );
			return;
		}

		// Verify nonce for security (supports both POST and GET for download links).
		$nonce = isset( $_REQUEST['nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'gatewaykit_ajax_nonce' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'gatewaykit' ), '', array( 'response' => 403 ) );
		}

		// Check user capabilities.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'gatewaykit' ), '', array( 'response' => 403 ) );
		}

		// Collect requested log IDs.
		$log_ids_raw = isset( $_REQUEST['log_ids'] ) ? wp_unslash( $_REQUEST['log_ids'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized via parse_log_ids() below
		$log_ids     = $this->parse_log_ids( $log_ids_raw );

		if ( empty( $log_ids ) ) {
			wp_die( esc_html__( 'No valid log IDs provided.', 'gatewaykit' ), '', array( 'response' => 400 ) );
		}

		$logs = GatewayKit_Logger::get_instance()->get_logs_by_ids( $log_ids );

		if ( empty( $logs ) ) {
			wp_die( esc_html__( 'No logs found for the selected entries.', 'gatewaykit' ), '', array( 'response' => 404 ) );
		}

		$text = GatewayKit_Log_Formatter::get_instance()->format_for_export( $logs, true );

		$filename = 'gatewaykit-logs-' . current_time( 'Y-m-d-His' ) . '.txt';

		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $text ) );

		echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text export, no HTML context
		exit;
	}

	/**
	 * AJAX handler: clear all logs.
	 *
	 * Requires: nonce, manage_options capability, admin_ajax rate limit.
	 */
	public function ajax_clear_logs() {
		if ( ! GatewayKit_Admin_Ajax_Guard::verify() ) {
			return;
		}

		GatewayKit_Logger::get_instance()->clear_all_logs();

		wp_send_json_success(
			array(
				'message' => __( 'All payment logs have been cleared successfully.', 'gatewaykit' ),
			)
		);
	}

	/**
	 * Normalize and sanitize a list of log IDs coming from an AJAX request.
	 *
	 * Accepts a comma-separated string or an array of IDs.
	 *
	 * @param mixed $raw Raw input.
	 * @return int[] Unique, positive log IDs.
	 */
	public function parse_log_ids( $raw ) {
		if ( is_string( $raw ) ) {
			$raw = explode( ',', $raw );
		}

		if ( ! is_array( $raw ) ) {
			return array();
		}

		$ids = array();
		foreach ( $raw as $id ) {
			$id = absint( $id );
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		return array_values( array_unique( $ids ) );
	}
}
