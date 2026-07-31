<?php

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class GatewayKit_Receipt_PDF {

	/**
	 * Dompdf instance.
	 *
	 * @var \Dompdf\Dompdf|null
	 */
	private static $dompdf = null;

	/**
	 * Generate PDF receipt for a transaction.
	 *
	 * @param GatewayKit_Transaction_Model $transaction The transaction.
	 * @param string                       $type        'receipt' or 'invoice'.
	 * @return string PDF binary content.
	 */
	public static function generate( GatewayKit_Transaction_Model $transaction, string $type = 'receipt' ): string {
		$html = self::build_html( $transaction, $type );

		$dompdf = self::get_dompdf();
		$dompdf->loadHtml( $html );
		$dompdf->setPaper( 'A4', 'portrait' );
		$dompdf->render();

		return $dompdf->output();
	}

	/**
	 * Get Dompdf instance (lazy loaded).
	 *
	 * @return \Dompdf\Dompdf
	 */
	private static function get_dompdf(): \Dompdf\Dompdf {
		if ( null === self::$dompdf ) {
			// Guard against redeclaring the Composer autoloader init class
			// when both Lite and Pro ship identical vendor trees (ERR-023).
			// The entry-file sentinel (GATEWAYKIT_FREEMIUS_AUTOLOADED) covers
			// plugin bootstrap, but this lazy require bypasses it — so we
			// check get_declared_classes() before loading.
			$composer_loaded = false;
			foreach ( get_declared_classes() as $cls ) {
				if ( 0 === strpos( $cls, 'ComposerAutoloaderInit' ) ) {
					$composer_loaded = true;
					break;
				}
			}
			if ( ! $composer_loaded ) {
				require_once GATEWAYKIT_PLUGIN_DIR . 'vendor/autoload.php';
			}
			self::$dompdf = new \Dompdf\Dompdf();
			self::$dompdf->set_option( 'isRemoteEnabled', true );
			self::$dompdf->set_option( 'isHtml5ParserEnabled', true );
			self::$dompdf->set_option( 'fontSubsetting', true );
			self::$dompdf->set_option( 'defaultFont', 'DejaVu Sans' );
		}

		return self::$dompdf;
	}

	/**
	 * Build HTML for PDF.
	 *
	 * @param GatewayKit_Transaction_Model $transaction The transaction.
	 * @param string                       $type        'receipt' or 'invoice'.
	 * @return string HTML content.
	 */
	private static function build_html( GatewayKit_Transaction_Model $transaction, string $type ): string {
		$site_name  = get_bloginfo( 'name' );
		$site_url   = home_url();
		$logo_url   = self::get_site_logo_url();
		$currency   = $transaction->currency;
		$paid_amount   = (float) $transaction->amount;
		$discount_amount = (float) ( $transaction->discount_amount ?? 0 );
		$has_discount = $discount_amount > 0;
		$original_amount = $paid_amount + $discount_amount;
		$date       = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $transaction->completed_at ) );
		$gateway    = ucfirst( $transaction->gateway );
		$receipt_id = $transaction->receipt_token;

		$is_invoice = 'invoice' === $type;
		$title      = $is_invoice ? __( 'Invoice', 'gatewaykit' ) : __( 'Payment Receipt', 'gatewaykit' );
		$subtitle   = $is_invoice
			? sprintf(
				/* translators: %s: invoice number */
				__( 'Invoice #%s', 'gatewaykit' ),
				$receipt_id
			)
			: sprintf(
				/* translators: %s: receipt number */
				__( 'Receipt #%s', 'gatewaykit' ),
				$receipt_id
			);

		// Extract buyer name + email from the submitted form data and/or
		// logged-in user data — the transaction table has no dedicated
		// buyer_name/buyer_email columns.
		$form_data  = $transaction->get( 'form_data' );
		$user_data  = $transaction->get( 'user_data' );
		$buyer_name  = '';
		$buyer_email = '';

		if ( is_array( $form_data ) ) {
			foreach ( $form_data as $key => $fval ) {
				$field_type  = is_array( $fval ) && isset( $fval['type'] ) ? $fval['type'] : '';
				$field_value = is_array( $fval ) && isset( $fval['value'] ) ? $fval['value'] : ( is_string( $fval ) ? $fval : '' );
				$field_label = is_array( $fval ) && isset( $fval['title'] ) ? strtolower( $fval['title'] ) : strtolower( (string) $key );

				// Email: first field of type "email" or whose key/label contains "email".
				if ( '' === $buyer_email && ( 'email' === $field_type || false !== strpos( $field_label, 'email' ) ) && '' !== $field_value ) {
					$buyer_email = $field_value;
				}

				// Name: first text-like field whose key/label contains "name" (but not "username"/"fullname").
				if ( '' === $buyer_name && in_array( $field_type, array( 'text', '' ), true ) && '' !== $field_value ) {
					if ( false !== strpos( $field_label, 'name' ) && false === strpos( $field_label, 'user' ) ) {
						$buyer_name = $field_value;
					}
				}
			}
		}

		// Fallback to logged-in user data.
		if ( '' === $buyer_name && is_array( $user_data ) && ! empty( $user_data['name'] ) ) {
			$buyer_name = $user_data['name'];
		}
		if ( '' === $buyer_email && is_array( $user_data ) && ! empty( $user_data['email'] ) ) {
			$buyer_email = $user_data['email'];
		}

		$business_name    = get_option( 'gatewaykit_business_name', $site_name );
		$business_address = get_option( 'gatewaykit_business_address', '' );
		$business_email   = get_option( 'gatewaykit_business_email', get_option( 'admin_email' ) );
		$business_phone   = get_option( 'gatewaykit_business_phone', '' );
		$business_tax_id  = get_option( 'gatewaykit_business_tax_id', '' );

		$discount_amount = $transaction->discount_amount ?? 0;
		$discount_code   = $transaction->discount_code ?? '';

		ob_start();
		?>
		<!DOCTYPE html>
		<html>
		<head>
			<meta charset="utf-8">
			<title><?php echo esc_html( $title ); ?></title>
			<style>
				body { font-family: "DejaVu Sans", sans-serif; font-size: 12px; color: #333; line-height: 1.5; margin: 0; padding: 20px; }
				.container { max-width: 800px; margin: 0 auto; border: 1px solid #ddd; padding: 30px; }
				.header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 30px; border-bottom: 2px solid #2271b1; padding-bottom: 20px; }
				.logo { max-height: 60px; max-width: 200px; }
				.title-block { text-align: right; }
				.title-block h1 { margin: 0 0 5px; font-size: 28px; color: #2271b1; font-weight: 700; }
				.title-block p { margin: 0; color: #666; font-size: 14px; }
				.section { margin-bottom: 25px; }
				.section-title { font-size: 14px; font-weight: 700; color: #2271b1; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 10px; border-bottom: 1px solid #eee; padding-bottom: 5px; }
				.info-grid { display: table; width: 100%; }
				.info-row { display: table-row; }
				.info-label { display: table-cell; width: 30%; font-weight: 600; color: #666; padding: 8px 0; vertical-align: top; }
				.info-value { display: table-cell; width: 70%; color: #333; padding: 8px 0; vertical-align: top; }
				.table { width: 100%; border-collapse: collapse; margin-top: 10px; }
				.table th, .table td { border: 1px solid #ddd; padding: 12px; text-align: left; }
				.table th { background-color: #f5f5f5; font-weight: 700; color: #333; }
				.table .text-right { text-align: right; }
				.table .text-center { text-align: center; }
				.totals { margin-top: 20px; }
				.total-row { display: flex; justify-content: flex-end; margin: 8px 0; }
				.total-label { width: 300px; text-align: right; padding-right: 20px; font-weight: 600; color: #333; }
				.total-value { width: 200px; text-align: right; font-weight: 700; color: #333; }
				.total-row.grand .total-label, .total-row.grand .total-value { font-size: 14px; color: #2271b1; }
				.footer { margin-top: 40px; padding-top: 20px; border-top: 1px solid #ddd; text-align: center; color: #999; font-size: 11px; }
				.footer a { color: #2271b1; text-decoration: none; }
				@page { margin: 20mm; }
			</style>
		</head>
		<body>
			<div class="container">
				<header class="header">
					<?php if ( $logo_url ) : ?>
						<img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( $site_name ); ?>" class="logo">
					<?php else : ?>
						<div style="width:200px;height:60px;"></div>
					<?php endif; ?>
					<div class="title-block">
						<h1><?php echo esc_html( $title ); ?></h1>
						<p><?php echo esc_html( $subtitle ); ?></p>
					</div>
				</header>

				<div class="section">
					<div class="section-title"><?php esc_html_e( 'Transaction Details', 'gatewaykit' ); ?></div>
					<div class="info-grid">
						<div class="info-row">
							<div class="info-label"><?php esc_html_e( 'Date', 'gatewaykit' ); ?></div>
							<div class="info-value"><?php echo esc_html( $date ); ?></div>
						</div>
						<div class="info-row">
							<div class="info-label"><?php esc_html_e( 'Payment Gateway', 'gatewaykit' ); ?></div>
							<div class="info-value"><?php echo esc_html( $gateway ); ?></div>
						</div>
						<div class="info-row">
							<div class="info-label"><?php esc_html_e( 'Receipt / Invoice ID', 'gatewaykit' ); ?></div>
							<div class="info-value"><?php echo esc_html( $receipt_id ); ?></div>
						</div>
					</div>
				</div>

				<div class="section">
					<div class="section-title"><?php esc_html_e( 'From', 'gatewaykit' ); ?></div>
					<div class="info-grid">
						<div class="info-row">
							<div class="info-label"><?php esc_html_e( 'Business Name', 'gatewaykit' ); ?></div>
							<div class="info-value"><?php echo esc_html( $business_name ); ?></div>
						</div>
						<?php if ( $business_address ) : ?>
							<div class="info-row">
								<div class="info-label"><?php esc_html_e( 'Address', 'gatewaykit' ); ?></div>
								<div class="info-value"><?php echo esc_html( $business_address ); ?></div>
							</div>
						<?php endif; ?>
						<?php if ( $business_email ) : ?>
							<div class="info-row">
								<div class="info-label"><?php esc_html_e( 'Email', 'gatewaykit' ); ?></div>
								<div class="info-value"><a href="mailto:<?php echo esc_attr( $business_email ); ?>"><?php echo esc_html( $business_email ); ?></a></div>
							</div>
						<?php endif; ?>
						<?php if ( $business_phone ) : ?>
							<div class="info-row">
								<div class="info-label"><?php esc_html_e( 'Phone', 'gatewaykit' ); ?></div>
								<div class="info-value"><?php echo esc_html( $business_phone ); ?></div>
							</div>
						<?php endif; ?>
						<?php if ( $business_tax_id ) : ?>
							<div class="info-row">
								<div class="info-label"><?php esc_html_e( 'Tax ID', 'gatewaykit' ); ?></div>
								<div class="info-value"><?php echo esc_html( $business_tax_id ); ?></div>
							</div>
						<?php endif; ?>
					</div>
				</div>

				<div class="section">
					<div class="section-title"><?php esc_html_e( 'Bill To', 'gatewaykit' ); ?></div>
					<div class="info-grid">
						<div class="info-row">
							<div class="info-label"><?php esc_html_e( 'Name', 'gatewaykit' ); ?></div>
							<div class="info-value"><?php echo esc_html( $buyer_name ); ?></div>
						</div>
						<div class="info-row">
							<div class="info-label"><?php esc_html_e( 'Email', 'gatewaykit' ); ?></div>
							<div class="info-value"><?php echo esc_html( $buyer_email ); ?></div>
						</div>
					</div>
				</div>

				<div class="section">
					<div class="section-title"><?php esc_html_e( 'Items', 'gatewaykit' ); ?></div>
					<table class="table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Description', 'gatewaykit' ); ?></th>
								<th class="text-center"><?php esc_html_e( 'Qty', 'gatewaykit' ); ?></th>
								<th class="text-right"><?php esc_html_e( 'Unit Price', 'gatewaykit' ); ?></th>
								<th class="text-right"><?php esc_html_e( 'Total', 'gatewaykit' ); ?></th>
							</tr>
						</thead>
					<tbody>
						<tr>
							<td><?php echo esc_html( $transaction->description ?? __( 'Payment', 'gatewaykit' ) ); ?></td>
							<td class="text-center">1</td>
							<td class="text-right"><?php echo esc_html( self::format_amount( $has_discount ? $original_amount : $paid_amount, $currency ) ); ?></td>
							<td class="text-right"><?php echo esc_html( self::format_amount( $has_discount ? $original_amount : $paid_amount, $currency ) ); ?></td>
						</tr>
						<?php if ( $has_discount ) : ?>
							<tr style="background:#fff8e1;">
								<td><?php esc_html_e( 'Discount', 'gatewaykit' ); ?> <?php echo $discount_code ? '(' . esc_html( $discount_code ) . ')' : ''; ?></td>
								<td class="text-center">1</td>
								<td class="text-right">-<?php echo esc_html( self::format_amount( $discount_amount, $currency ) ); ?></td>
								<td class="text-right">-<?php echo esc_html( self::format_amount( $discount_amount, $currency ) ); ?></td>
							</tr>
						<?php endif; ?>
					</tbody>
					</table>
				</div>

				<div class="totals">
					<div class="total-row">
						<span class="total-label"><?php esc_html_e( 'Subtotal', 'gatewaykit' ); ?></span>
						<span class="total-value"><?php echo esc_html( self::format_amount( $has_discount ? $original_amount : $paid_amount, $currency ) ); ?></span>
					</div>
					<?php if ( $has_discount ) : ?>
						<div class="total-row">
							<span class="total-label"><?php esc_html_e( 'Discount', 'gatewaykit' ); ?></span>
							<span class="total-value">-<?php echo esc_html( self::format_amount( $discount_amount, $currency ) ); ?></span>
						</div>
					<?php endif; ?>
					<div class="total-row grand">
						<span class="total-label"><?php esc_html_e( 'Total Paid', 'gatewaykit' ); ?></span>
						<span class="total-value"><?php echo esc_html( self::format_amount( $paid_amount, $currency ) ); ?></span>
					</div>
				</div>

				<?php if ( $is_invoice ) : ?>
					<div class="section" style="margin-top:30px;">
						<div class="section-title"><?php esc_html_e( 'Payment Status', 'gatewaykit' ); ?></div>
						<div class="info-grid">
							<div class="info-row">
								<div class="info-label"><?php esc_html_e( 'Status', 'gatewaykit' ); ?></div>
								<div class="info-value">
									<span style="background:#d4edda;color:#155724;padding:4px 12px;border-radius:4px;font-weight:600;">
										<?php echo esc_html( ucfirst( $transaction->status ) ); ?>
									</span>
								</div>
							</div>
							<div class="info-row">
								<div class="info-label"><?php esc_html_e( 'Paid On', 'gatewaykit' ); ?></div>
								<div class="info-value"><?php echo esc_html( $date ); ?></div>
							</div>
						</div>
					</div>
				<?php endif; ?>

				<div class="footer">
					<p><?php printf(
						/* translators: %1$s: document type (receipt/invoice), %2$s: site name */
						esc_html__( 'Thank you for your payment. This %1$s is your official record from %2$s.', 'gatewaykit' ),
						esc_html( strtolower( $type ) ),
						esc_html( $site_name )
					); ?></p>
					<p><?php printf(
						/* translators: %1$s: generation timestamp, %2$s: site URL */
						esc_html__( 'Generated on %1$s via %2$s', 'gatewaykit' ),
						esc_html( current_time( 'Y-m-d H:i:s' ) ),
						esc_html( $site_url )
					); ?></p>
				</div>
			</div>
		</body>
		</html>
		<?php
		return ob_get_clean();
	}

	/**
	 * Format amount with currency.
	 *
	 * @param float|string $amount   The amount (DB may store as string).
	 * @param string       $currency The currency code.
	 * @return string Formatted amount.
	 */
	private static function format_amount( $amount, string $currency ): string {
		$amount       = (float) $amount;
		$zero_decimal = array( 'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' );
		$decimals     = in_array( strtoupper( $currency ), $zero_decimal, true ) ? 0 : 2;
		$formatted    = number_format( $amount, $decimals, '.', ',' );

		return $formatted . ' ' . strtoupper( $currency );
	}

	/**
	 * Get site logo URL.
	 *
	 * @return string|null
	 */
	private static function get_site_logo_url(): ?string {
		$custom_logo_id = get_theme_mod( 'custom_logo' );
		if ( $custom_logo_id ) {
			return wp_get_attachment_image_url( $custom_logo_id, 'full' );
		}

		$site_icon_id = get_option( 'site_icon' );
		if ( $site_icon_id ) {
			return wp_get_attachment_image_url( $site_icon_id, 'full' );
		}

		return null;
	}

	/**
	 * Get filename for PDF download.
	 *
	 * @param GatewayKit_Transaction_Model $transaction The transaction.
	 * @param string                       $type        'receipt' or 'invoice'.
	 * @return string Filename.
	 */
	public static function get_filename( GatewayKit_Transaction_Model $transaction, string $type = 'receipt' ): string {
		$prefix = 'invoice' === $type ? 'invoice' : 'receipt';
		$token  = $transaction->receipt_token;
		$date   = gmdate( 'Y-m-d', strtotime( $transaction->completed_at ) );

		return sprintf( '%s-%s-%s.pdf', $prefix, $token, $date );
	}
}