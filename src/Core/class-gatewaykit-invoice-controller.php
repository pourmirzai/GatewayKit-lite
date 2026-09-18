<?php
/**
 * GatewayKit Invoice Controller
 *
 * Handles invoice PDF download endpoint (?gatewaykit_invoice=<receipt_token>).
 *
 * @package GatewayKit
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GatewayKit_Invoice_Controller
 */
class GatewayKit_Invoice_Controller {

	/**
	 * Handle invoice PDF download endpoint.
	 *
	 * Endpoint: ?gatewaykit_invoice=<receipt_token>
	 * Returns PDF with Content-Disposition: attachment.
	 */
	public static function handle_download() {
		if ( empty( $_GET['gatewaykit_invoice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$receipt_token = sanitize_text_field( wp_unslash( $_GET['gatewaykit_invoice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! preg_match( '/^[A-Z0-9]{6}-[A-Z0-9]{6}$/i', $receipt_token ) ) {
			wp_die( esc_html__( 'Invalid receipt code format.', 'gatewaykit' ), 400 );
		}

		$receipt_token = strtoupper( $receipt_token );

		$transaction = GatewayKit_Transaction_Model::find_by_receipt_token( $receipt_token );

		if ( ! $transaction ) {
			wp_die( esc_html__( 'Transaction not found.', 'gatewaykit' ), 404 );
		}

		if ( 'completed' !== $transaction->status ) {
			wp_die( esc_html__( 'Invoice is only available for completed payments.', 'gatewaykit' ), 403 );
		}

		if ( ! class_exists( 'GatewayKit_Receipt_PDF' ) ) {
			$pdf_path = GATEWAYKIT_PLUGIN_DIR . 'src/Core/Email/class-receipt-pdf.php';
			if ( file_exists( $pdf_path ) ) {
				require_once $pdf_path;
			}
		}

		if ( ! class_exists( 'GatewayKit_Receipt_PDF' ) ) {
			wp_die( esc_html__( 'PDF generator not available.', 'gatewaykit' ), 500 );
		}

		try {
			$pdf_content = GatewayKit_Receipt_PDF::generate( $transaction, 'invoice' );
			$filename    = GatewayKit_Receipt_PDF::get_filename( $transaction, 'invoice' );

			nocache_headers();
			header( 'Content-Type: application/pdf' );
			header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
			header( 'Content-Length: ' . strlen( $pdf_content ) );

			echo $pdf_content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			exit;
		} catch ( \Throwable $e ) {
			GatewayKit_Logger::get_instance()->error(
				'Invoice PDF download failed',
				array(
					'transaction_id' => $transaction->get( 'id' ),
					'error'          => $e->getMessage(),
				)
			);
			wp_die( esc_html__( 'Failed to generate invoice.', 'gatewaykit' ), 500 );
		}
	}
}
