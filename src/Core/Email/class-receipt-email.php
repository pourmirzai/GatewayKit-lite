<?php
/**
 * Customer Receipt Email (Lite)
 *
 * Sends a full payment receipt to the customer after successful payment.
 * Hooked on `gatewaykit_payment_completed` with priority 20 (after webhook
 * completion). Guarded against double-send via per-transaction meta flag.
 *
 * @package GatewayKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Receipt email sender.
 */
class GatewayKit_Receipt_Email {

	/**
	 * Singleton instance.
	 *
	 * @var GatewayKit_Receipt_Email|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return GatewayKit_Receipt_Email
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor — hooks payment completed.
	 */
	private function __construct() {
		add_action( 'gatewaykit_payment_completed', array( $this, 'on_payment_completed' ), 20, 1 );
	}

	/**
	 * Handle payment completed event.
	 *
	 * @param GatewayKit_Transaction_Model $transaction The completed transaction.
	 */
	public function on_payment_completed( $transaction ) {
		$user_data = $transaction->get( 'user_data' );

		// Check if email receipt is enabled for this transaction.
		$email_enabled = isset( $user_data['email_receipt_enabled'] ) && 'yes' === $user_data['email_receipt_enabled'];
		if ( ! $email_enabled ) {
			return;
		}

		// Double-send guard.
		$tx_id = (int) $transaction->get( 'id' );
		if ( get_transient( 'gatewaykit_receipt_sent_' . $tx_id ) ) {
			return;
		}

		$this->send( $transaction );
		set_transient( 'gatewaykit_receipt_sent_' . $tx_id, true, DAY_IN_SECONDS );
	}

	/**
	 * Send the receipt email.
	 *
	 * @param GatewayKit_Transaction_Model $transaction The transaction.
	 */
	public function send( $transaction ) {
		$user_data = $transaction->get( 'user_data' );

		// Resolve recipient.
		$to = '';
		if ( ! empty( $user_data['email_receipt_to'] ) ) {
			$to = sanitize_email( $user_data['email_receipt_to'] );
		}
		if ( '' === $to && ! empty( $user_data['email'] ) ) {
			$to = sanitize_email( $user_data['email'] );
		}
		if ( '' === $to && is_user_logged_in() ) {
			$user = wp_get_current_user();
			$to   = $user->user_email;
		}

		if ( '' === $to || ! is_email( $to ) ) {
			GatewayKit_Logger::get_instance()->info( 'Receipt email: no valid recipient', array( 'transaction_id' => $transaction->get( 'id' ) ) );
			return;
		}

		$subject = $this->get_subject( $transaction );
		$html    = $this->build_html( $transaction );
		$plain   = $this->build_plain( $transaction );

		// Check if PDF attachment is enabled.
		$attach_pdf = get_option( 'gatewaykit_receipt_email_attach_pdf', '1' ) === '1';

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . $this->get_from_name() . ' <' . $this->get_from_email() . '>',
		);

		$attachments = array();

		if ( $attach_pdf ) {
			$pdf_content = $this->generate_pdf( $transaction );
			if ( $pdf_content ) {
				$filename   = GatewayKit_Receipt_PDF::get_filename( $transaction, 'receipt' );
				$upload_dir = wp_upload_dir();
				$temp_file  = $upload_dir['basedir'] . '/gatewaykit-temp-' . $filename;

				if ( file_put_contents( $temp_file, $pdf_content ) !== false ) {
					$attachments[] = $temp_file;
				}
			}
		}

		$result = wp_mail( $to, $subject, $html, $headers, $attachments );

		// Clean up temp file.
		if ( ! empty( $temp_file ) && file_exists( $temp_file ) ) {
			wp_delete_file( $temp_file );
		}

		if ( $result ) {
			GatewayKit_Logger::get_instance()->info(
				'Receipt email sent',
				array(
					'transaction_id' => $transaction->get( 'id' ),
					'to'             => $to,
					'has_pdf'        => ! empty( $attachments ),
				)
			);
		} else {
			GatewayKit_Logger::get_instance()->error(
				'Receipt email failed',
				array(
					'transaction_id' => $transaction->get( 'id' ),
					'to'             => $to,
				)
			);
		}
	}

	/**
	 * Generate PDF content for the transaction.
	 *
	 * @param GatewayKit_Transaction_Model $transaction The transaction.
	 * @return string|false PDF binary content or false on failure.
	 */
	private function generate_pdf( $transaction ) {
		if ( ! class_exists( 'GatewayKit_Receipt_PDF' ) ) {
			$pdf_path = GATEWAYKIT_PLUGIN_DIR . 'src/Core/Email/class-receipt-pdf.php';
			if ( file_exists( $pdf_path ) ) {
				require_once $pdf_path;
			}
		}

		if ( class_exists( 'GatewayKit_Receipt_PDF' ) ) {
			try {
				return GatewayKit_Receipt_PDF::generate( $transaction, 'receipt' );
			} catch ( \Throwable $e ) {
				GatewayKit_Logger::get_instance()->error(
					'PDF generation failed',
					array(
						'transaction_id' => $transaction->get( 'id' ),
						'error'          => $e->getMessage(),
					)
				);
			}
		}

		return false;
	}

	/**
	 * Build the email subject with placeholder substitution.
	 *
	 * @param GatewayKit_Transaction_Model $transaction The transaction.
	 * @return string
	 */
	private function get_subject( $transaction ) {
		$template = get_option( 'gatewaykit_receipt_email_subject', '' );
		if ( '' === $template ) {
			/* translators: %s: site name */
			$template = __( 'Payment Receipt from %s', 'gatewaykit' );
		}

		$replacements = array(
			'{site_name}'    => get_bloginfo( 'name' ),
			'{amount}'       => number_format( (float) $transaction->amount, 2 ) . ' ' . $transaction->currency,
			'{receipt_code}' => $transaction->receipt_token,
		);

		return str_replace( array_keys( $replacements ), array_values( $replacements ), $template );
	}

	/**
	 * Get the From Name for receipt emails.
	 *
	 * @return string
	 */
	private function get_from_name() {
		$name = get_option( 'gatewaykit_receipt_email_from_name', '' );
		return '' !== $name ? $name : get_bloginfo( 'name' );
	}

	/**
	 * Get the From Email for receipt emails.
	 *
	 * @return string
	 */
	private function get_from_email() {
		$email = get_option( 'gatewaykit_receipt_email_from_address', '' );
		return '' !== $email ? $email : get_option( 'admin_email' );
	}

	/**
	 * Build the HTML email body.
	 *
	 * @param GatewayKit_Transaction_Model $transaction The transaction.
	 * @return string
	 */
	private function build_html( $transaction ) {
		$site_name       = get_bloginfo( 'name' );
		$receipt_code    = $transaction->receipt_token;
		$paid_amount     = (float) $transaction->amount;
		$discount_amount = (float) ( $transaction->discount_amount ?? 0 );
		$has_discount    = $discount_amount > 0;
		$original_amount = $paid_amount + $discount_amount;
		$currency        = $transaction->currency;
		$gateway         = ucfirst( $transaction->gateway );
		$date            = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $transaction->completed_at ) );
		$receipt_base    = ! empty( $transaction->success_url ) ? $transaction->success_url : home_url( '/' );
		$receipt_url     = add_query_arg( 'gatewaykit_receipt', $receipt_code, $receipt_base );

		$description = $transaction->description;
		if ( empty( $description ) ) {
			$form_data = $transaction->get( 'form_data' );
			if ( is_array( $form_data ) && isset( $form_data['description'] ) ) {
				$description = $form_data['description'];
			}
		}
		if ( empty( $description ) ) {
			$description = '-';
		}

		// Resolve discount code label if available.
		$discount_code_label = '';
		if ( $has_discount ) {
			$discount_id = (int) ( $transaction->discount_id ?? 0 );
			if ( $discount_id > 0 && class_exists( 'GatewayKit_Discount_Model' ) && method_exists( 'GatewayKit_Discount_Model', 'get_by_id' ) ) {
				$dobj = GatewayKit_Discount_Model::get_by_id( $discount_id );
				if ( $dobj ) {
					$discount_code_label = $dobj->get_data( 'code' );
				}
			}
		}

		// Amount row(s).
		$amount_rows_html = '';
		if ( $has_discount ) {
			$amount_rows_html .= '<tr>';
			$amount_rows_html .= '<td style="padding:12px 0;border-bottom:1px solid #eee;color:#666;font-size:14px;">' . esc_html__( 'Original Amount', 'gatewaykit' ) . '</td>';
			$amount_rows_html .= '<td style="padding:12px 0;border-bottom:1px solid #eee;color:#1d2327;font-size:14px;text-align:right;">' . esc_html( number_format( $original_amount, 2 ) . ' ' . $currency ) . '</td>';
			$amount_rows_html .= '</tr>';

			$discount_label_text = esc_html__( 'Discount', 'gatewaykit' );
			if ( $discount_code_label ) {
				$discount_label_text .= ' (' . esc_html( $discount_code_label ) . ')';
			}
			$amount_rows_html .= '<tr>';
			$amount_rows_html .= '<td style="padding:12px 0;border-bottom:1px solid #eee;color:#666;font-size:14px;">' . $discount_label_text . '</td>';
			$amount_rows_html .= '<td style="padding:12px 0;border-bottom:1px solid #eee;color:#c0392b;font-size:14px;text-align:right;">-' . esc_html( number_format( $discount_amount, 2 ) . ' ' . $currency ) . '</td>';
			$amount_rows_html .= '</tr>';

			$amount_rows_html .= '<tr>';
			$amount_rows_html .= '<td style="padding:12px 0;border-bottom:1px solid #eee;color:#666;font-size:14px;">' . esc_html__( 'Amount Paid', 'gatewaykit' ) . '</td>';
			$amount_rows_html .= '<td style="padding:12px 0;border-bottom:1px solid #eee;color:#1d2327;font-size:18px;font-weight:700;text-align:right;">' . esc_html( number_format( $paid_amount, 2 ) . ' ' . $currency ) . '</td>';
			$amount_rows_html .= '</tr>';
		} else {
			$amount_rows_html .= '<tr>';
			$amount_rows_html .= '<td style="padding:12px 0;border-bottom:1px solid #eee;color:#666;font-size:14px;">' . esc_html__( 'Amount', 'gatewaykit' ) . '</td>';
			$amount_rows_html .= '<td style="padding:12px 0;border-bottom:1px solid #eee;color:#1d2327;font-size:18px;font-weight:700;text-align:right;">' . esc_html( number_format( $paid_amount, 2 ) . ' ' . $currency ) . '</td>';
			$amount_rows_html .= '</tr>';
		}

		ob_start();
		?>
		<!DOCTYPE html>
		<html>
		<head>
			<meta charset="UTF-8">
			<meta name="viewport" content="width=device-width, initial-scale=1.0">
		</head>
		<body style="margin:0;padding:0;background-color:#f4f4f4;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
			<table width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f4f4;padding:30px 0;">
				<tr>
					<td align="center">
						<table width="600" cellpadding="0" cellspacing="0" style="background-color:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.06);">
							<tr>
								<td style="background-color:#2271b1;padding:30px;text-align:center;">
									<h1 style="color:#ffffff;margin:0;font-size:24px;"><?php echo esc_html( $site_name ); ?></h1>
									<p style="color:#d0e5f5;margin:8px 0 0;font-size:14px;"><?php esc_html_e( 'Payment Receipt', 'gatewaykit' ); ?></p>
								</td>
							</tr>
							<tr>
								<td style="padding:30px;">
									<table width="100%" cellpadding="0" cellspacing="0">
										<tr>
											<td style="padding:12px 0;border-bottom:1px solid #eee;color:#666;font-size:14px;"><?php esc_html_e( 'Receipt Code', 'gatewaykit' ); ?></td>
											<td style="padding:12px 0;border-bottom:1px solid #eee;color:#1d2327;font-size:14px;font-weight:600;text-align:right;"><?php echo esc_html( $receipt_code ); ?></td>
										</tr>
										<?php echo $amount_rows_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped above ?>
										<tr>
											<td style="padding:12px 0;border-bottom:1px solid #eee;color:#666;font-size:14px;"><?php esc_html_e( 'Payment Gateway', 'gatewaykit' ); ?></td>
											<td style="padding:12px 0;border-bottom:1px solid #eee;color:#1d2327;font-size:14px;text-align:right;"><?php echo esc_html( $gateway ); ?></td>
										</tr>
										<tr>
											<td style="padding:12px 0;border-bottom:1px solid #eee;color:#666;font-size:14px;"><?php esc_html_e( 'Payment Date', 'gatewaykit' ); ?></td>
											<td style="padding:12px 0;border-bottom:1px solid #eee;color:#1d2327;font-size:14px;text-align:right;"><?php echo esc_html( $date ); ?></td>
										</tr>
										<tr>
											<td style="padding:12px 0;border-bottom:1px solid #eee;color:#666;font-size:14px;"><?php esc_html_e( 'Description', 'gatewaykit' ); ?></td>
											<td style="padding:12px 0;border-bottom:1px solid #eee;color:#1d2327;font-size:14px;text-align:right;"><?php echo esc_html( $description ); ?></td>
										</tr>
									</table>
									<table width="100%" cellpadding="0" cellspacing="0" style="margin-top:25px;">
										<tr>
											<td align="center">
												<a href="<?php echo esc_url( $receipt_url ); ?>" style="display:inline-block;background-color:#2271b1;color:#ffffff;padding:12px 30px;border-radius:4px;text-decoration:none;font-size:14px;font-weight:600;"><?php esc_html_e( 'View Receipt', 'gatewaykit' ); ?></a>
											</td>
										</tr>
									</table>
								</td>
							</tr>
							<tr>
								<td style="background-color:#f9f9f9;padding:20px 30px;text-align:center;color:#999;font-size:12px;">
									<?php
									/* translators: %s: site name */
									echo esc_html( sprintf( __( 'Thank you for your payment. This email is your official receipt from %s.', 'gatewaykit' ), $site_name ) );
									?>
								</td>
							</tr>
						</table>
					</td>
				</tr>
			</table>
		</body>
		</html>
		<?php
		return ob_get_clean();
	}

	/**
	 * Build the plain text fallback.
	 *
	 * @param GatewayKit_Transaction_Model $transaction The transaction.
	 * @return string
	 */
	private function build_plain( $transaction ) {
		$paid_amount     = (float) $transaction->amount;
		$discount_amount = (float) ( $transaction->discount_amount ?? 0 );
		$currency        = $transaction->currency;

		$lines = array(
			__( 'Payment Receipt', 'gatewaykit' ),
			'',
			/* translators: %s: site name */
			sprintf( __( 'From: %s', 'gatewaykit' ), get_bloginfo( 'name' ) ),
			/* translators: %s: receipt code */
			sprintf( __( 'Receipt Code: %s', 'gatewaykit' ), $transaction->receipt_token ),
		);

		if ( $discount_amount > 0 ) {
			/* translators: %s: formatted original amount with currency */
			$lines[] = sprintf( __( 'Original Amount: %s', 'gatewaykit' ), number_format( $paid_amount + $discount_amount, 2 ) . ' ' . $currency );
			/* translators: %s: formatted discount amount with currency */
			$lines[] = sprintf( __( 'Discount: -%s', 'gatewaykit' ), number_format( $discount_amount, 2 ) . ' ' . $currency );
			/* translators: %s: formatted paid amount with currency */
			$lines[] = sprintf( __( 'Amount Paid: %s', 'gatewaykit' ), number_format( $paid_amount, 2 ) . ' ' . $currency );
		} else {
			/* translators: %s: formatted amount with currency */
			$lines[] = sprintf( __( 'Amount: %s', 'gatewaykit' ), number_format( $paid_amount, 2 ) . ' ' . $currency );
		}

		/* translators: %s: payment gateway name */
		$lines[] = sprintf( __( 'Payment Gateway: %s', 'gatewaykit' ), ucfirst( $transaction->gateway ) );
		/* translators: %s: formatted payment date */
		$lines[] = sprintf( __( 'Payment Date: %s', 'gatewaykit' ), date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $transaction->completed_at ) ) );
		$lines[] = '';
		/* translators: %s: receipt page URL */
		$lines[] = sprintf( __( 'View your receipt: %s', 'gatewaykit' ), add_query_arg( 'gatewaykit_receipt', $transaction->receipt_token, ! empty( $transaction->success_url ) ? $transaction->success_url : home_url( '/' ) ) );

		return implode( "\n", $lines );
	}
}
