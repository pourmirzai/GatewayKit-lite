<?php
/**
 * GatewayKit Transaction CSV Exporter
 *
 * Handles streaming CSV export of transaction records.
 *
 * @package GatewayKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GatewayKit_Transaction_CSV_Exporter
 */
class GatewayKit_Transaction_CSV_Exporter {

	/**
	 * Export all transactions matching current or provided filters.
	 *
	 * @param array|null $filters Optional filter array.
	 */
	public static function export_all_transactions( $filters = null ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			GatewayKit_Logger::get_instance()->debug( 'Starting export_all_transactions (filtered)' );
		}

		if ( null === $filters ) {
			// Read filter context from export form hidden inputs.
			// phpcs:disable WordPress.Security.NonceVerification.Missing
			$filters = array(
				'status'    => isset( $_POST['gatewaykit_export_status'] ) ? sanitize_key( wp_unslash( $_POST['gatewaykit_export_status'] ) ) : '',
				'gateway'   => isset( $_POST['gatewaykit_export_gateway'] ) ? sanitize_key( wp_unslash( $_POST['gatewaykit_export_gateway'] ) ) : '',
				'date_from' => isset( $_POST['gatewaykit_export_date_from'] ) ? sanitize_text_field( wp_unslash( $_POST['gatewaykit_export_date_from'] ) ) : '',
				'date_to'   => isset( $_POST['gatewaykit_export_date_to'] ) ? sanitize_text_field( wp_unslash( $_POST['gatewaykit_export_date_to'] ) ) : '',
				'search'    => isset( $_POST['gatewaykit_export_s'] ) ? sanitize_text_field( wp_unslash( $_POST['gatewaykit_export_s'] ) ) : '',
			);
			// phpcs:enable
		}

		$transaction_ids = GatewayKit_Transaction_Query::get_filtered_transaction_ids( $filters );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			GatewayKit_Logger::get_instance()->debug(
				'Retrieved filtered transaction IDs',
				array(
					'count' => count( $transaction_ids ),
					'ids'   => array_slice( $transaction_ids, 0, 5 ),
				)
			);
		}

		self::export_transactions( $transaction_ids );
	}

	/**
	 * Export transactions to CSV with batch processing.
	 *
	 * @param array $transaction_ids List of integer IDs.
	 */
	public static function export_transactions( array $transaction_ids ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			GatewayKit_Logger::get_instance()->debug( 'Starting export_transactions', array( 'transaction_ids_count' => count( $transaction_ids ) ) );
		}

		// Clear all output buffers to prevent conflicts.
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

		// Check if headers already sent.
		if ( headers_sent( $file, $line ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				GatewayKit_Logger::get_instance()->error(
					'Headers already sent before export',
					array(
						'file' => $file,
						'line' => $line,
					)
				);
			}
			wp_die( esc_html__( 'Headers already sent.', 'gatewaykit' ) );
		}

		// Set proper headers for CSV download with UTF-8 support.
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename=transactions-' . gmdate( 'Y-m-d-H-i-s' ) . '.csv' );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			GatewayKit_Logger::get_instance()->debug( 'Headers set for CSV export' );
		}

		// Output UTF-8 BOM for proper Persian character display in Excel.
		echo "\xEF\xBB\xBF";

		$output = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( ! $output ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				GatewayKit_Logger::get_instance()->error( 'Unable to open php://output for CSV' );
			}
			wp_die( esc_html__( 'Unable to create CSV output.', 'gatewaykit' ) );
		}

		// CSV headers.
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

		// Process transactions in batches to prevent memory issues.
		$batch_size = 100;
		$total_ids  = count( $transaction_ids );

		// Pre-load gateway manager to avoid repeated instantiation.
		$gateway_manager = GatewayKit_Gateway_Manager::get_instance();

		for ( $i = 0; $i < $total_ids; $i += $batch_size ) {
			$batch_ids          = array_slice( $transaction_ids, $i, $batch_size );
			$batch_transactions = GatewayKit_Transaction_Query::get_batch_for_export( $batch_ids );

			// Process each transaction in the batch.
			foreach ( $batch_transactions as $transaction_data ) {
				try {
					$transaction = new GatewayKit_Transaction_Model( $transaction_data );

					$user_name = '';
					if ( ! empty( $transaction->user_id ) ) {
						$user      = get_userdata( $transaction->user_id );
						$user_name = $user ? $user->display_name : __( 'Guest', 'gatewaykit' );
					}

					$receipt_code = ! empty( $transaction->receipt_token ) ? $transaction->receipt_token : '';

					$gateway      = $gateway_manager->get_gateway( $transaction->gateway );
					$gateway_name = $gateway ? $gateway->get_gateway_name() : ucfirst( $transaction->gateway );

					$row = array(
						$transaction->id,
						$receipt_code,
						$user_name,
						$gateway_name,
						$transaction->amount,
						$transaction->currency ? $transaction->currency : GatewayKit_Gateway_Manager::get_instance()->get_default_currency(),
						$transaction->description ? $transaction->description : '',
						$transaction->status,
						$transaction->authority ? $transaction->authority : '',
						$transaction->ref_id ? $transaction->ref_id : '',
						date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $transaction->created_at ) ),
					);

					// Ensure all data is properly encoded in UTF-8 for Persian characters.
					$row = array_map(
						function ( $value ) {
							if ( is_string( $value ) ) {
								return mb_convert_encoding( $value, 'UTF-8', mb_detect_encoding( $value, 'UTF-8, ISO-8859-1, Windows-1252', true ) ? mb_detect_encoding( $value, 'UTF-8, ISO-8859-1, Windows-1252', true ) : 'UTF-8' );
							}
							return $value;
						},
						$row
					);

					gatewaykit_fputcsv( $output, $row );

				} catch ( Exception $e ) {
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						GatewayKit_Logger::get_instance()->error(
							'Error exporting transaction',
							array(
								'transaction_id' => $transaction_data['id'],
								'error'          => $e->getMessage(),
							)
						);
					}
					continue;
				}
			}

			// Clear memory periodically.
			if ( $i % ( $batch_size * 5 ) === 0 ) {
				if ( function_exists( 'gc_collect_cycles' ) ) {
					gc_collect_cycles();
				}
			}
		}

		fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}
}
