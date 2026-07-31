<?php
/**
 * Payment Result Shortcode
 *
 * Provides shortcode for displaying payment results and transaction details
 *
 * @package GatewayKit
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;

}

/**
 * Payment Result Shortcode Class
 */
class GatewayKit_Payment_Result_Shortcode {

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->init();
	}

	/**
	 * Initialize the shortcode
	 */
	public function init() {
		add_shortcode( 'gatewaykit_receipt', array( $this, 'render_payment_result' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_receipt_assets' ) );
	}

	/**
	 * Enqueue receipt CSS (screen + print styles).
	 */
	public function enqueue_receipt_assets() {
		wp_enqueue_style(
			'gatewaykit-receipt',
			GATEWAYKIT_PLUGIN_URL . 'assets/css/receipt.css',
			array( 'gatewaykit-ui-kit' ),
			GATEWAYKIT_VERSION
		);
	}

	/**
	 * Render payment result shortcode
	 *
	 * @param array $atts Shortcode attributes
	 * @return string HTML output
	 */
	public function render_payment_result( $atts ) {
		// Default attributes
		$atts = shortcode_atts(
			array(
				'receipt_code' => '',
				'show_details' => 'true',
				'show_receipt' => 'true',
			),
			$atts,
			'gatewaykit_receipt'
		);

		// Get transaction from URL parameters or shortcode attribute.
		// NOTE: This is a public read-only receipt lookup (no state change), so
		// a nonce is not applicable here.
		$receipt_token = '';
		if ( ! empty( $atts['receipt_code'] ) ) {
			$receipt_token = sanitize_text_field( $atts['receipt_code'] );
		} elseif ( isset( $_GET['gatewaykit_receipt'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$receipt_token = sanitize_text_field( wp_unslash( $_GET['gatewaykit_receipt'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public read-only receipt lookup
		}

		// If no receipt token provided, show input form
		if ( empty( $receipt_token ) ) {
			return $this->render_receipt_input_form();
		}

		// Validate token format (XXXXXX-YYYYYY, letters/numbers)
		if ( ! preg_match( '/^[A-Z0-9]{6}-[A-Z0-9]{6}$/i', $receipt_token ) ) {
			return $this->render_error( __( 'Invalid receipt code format. Please use the format XXXXXX-YYYYYY', 'gatewaykit' ) );
		}

		// Apply rate limiting for failed lookups
		if ( $this->is_rate_limited() ) {
			return $this->render_error( __( 'Too many failed attempts. Please try again later.', 'gatewaykit' ) );
		}

		// Convert to uppercase for consistency
		$receipt_token = strtoupper( $receipt_token );

		// Get transaction by receipt token
		$transaction = GatewayKit_Transaction_Model::find_by_receipt_token( $receipt_token );

		if ( ! $transaction ) {
			// Log failed attempt for rate limiting
			$this->log_failed_attempt();
			return $this->render_error( __( 'Transaction not found with the provided receipt code.', 'gatewaykit' ) );
		}

		// Clear failed attempts on successful lookup
		$this->clear_failed_attempts();

		// Render payment result
		return $this->render_payment_result_content( $transaction, $atts );
	}

	/**
	 * Render payment result content
	 *
	 * @param GatewayKit_Transaction_Model $transaction Transaction object
	 * @param array                        $atts        Shortcode attributes
	 * @return string HTML output
	 */
	private function render_payment_result_content( $transaction, $atts ) {
		$show_details = filter_var( $atts['show_details'], FILTER_VALIDATE_BOOLEAN );
		$show_receipt = filter_var( $atts['show_receipt'], FILTER_VALIDATE_BOOLEAN );

		// Determine status, message, and icon SVG.
		$banner_class   = '';
		$status_message = '';
		$icon_svg       = '';

		switch ( $transaction->status ) {
			case 'completed':
				$banner_class   = 'success';
				$status_message = __( 'Payment Completed Successfully', 'gatewaykit' );
				$icon_svg       = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>';
				break;
			case 'failed':
				$banner_class   = 'error';
				$status_message = __( 'Payment Failed', 'gatewaykit' );
				$icon_svg       = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>';
				break;
			case 'pending':
				$banner_class   = 'pending';
				$status_message = __( 'Payment Pending', 'gatewaykit' );
				$icon_svg       = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>';
				break;
			default:
				$banner_class   = 'unknown';
				$status_message = __( 'Unknown Status', 'gatewaykit' );
				$icon_svg       = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>';
		}

		// Build HTML.
		$html  = '<div class="gatewaykit-ui">';
		$html .= '<div class="gk-receipt-page">';

		// Status banner.
		$html .= '<div class="gk-receipt-banner gk-receipt-banner--' . esc_attr( $banner_class ) . '">';
		$html .= '<div class="gk-receipt-banner__icon">' . $icon_svg . '</div>'; // SVG is safe (hardcoded markup).
		$html .= '<h2 class="gk-receipt-banner__title">' . esc_html( $status_message ) . '</h2>';
		$html .= '<div class="gk-receipt-banner__actions">';

		// Action buttons (completed only).
		if ( $transaction->status === 'completed' ) {
			$html .= '<button class="gk-receipt-btn gk-receipt-btn--outline gatewaykit-print-receipt" onclick="window.print()">';
			$html .= esc_html__( 'Print Receipt', 'gatewaykit' );
			$html .= '</button>';

			$invoice_base = ! empty( $transaction->success_url ) ? $transaction->success_url : home_url( '/' );
			$invoice_url  = add_query_arg( 'gatewaykit_invoice', $transaction->receipt_token, $invoice_base );
			$html        .= '<a href="' . esc_url( $invoice_url ) . '" class="gk-receipt-btn gk-receipt-btn--primary gatewaykit-download-invoice" target="_blank" rel="noopener noreferrer">';
			$html       .= esc_html__( 'Download Invoice', 'gatewaykit' );
			$html       .= '</a>';
		}

		$html .= '</div>'; // .gk-receipt-banner__actions.
		$html .= '</div>'; // .gk-receipt-banner.

		// Receipt card.
		if ( $show_receipt || $show_details ) {
			$html .= '<div class="gk-receipt-card">';
			$html .= '<div class="gk-receipt-card__header">';
			$html .= '<h3 class="gk-receipt-card__title">' . esc_html__( 'Payment Receipt', 'gatewaykit' ) . '</h3>';
			$html .= '</div>';
			$html .= '<div class="gk-receipt-card__body">';
			$html .= $this->render_unified_receipt( $transaction, $show_receipt, $show_details );
			$html .= '</div>';
			$html .= '</div>';
		}

		$html .= '</div>'; // .gk-receipt-page.
		$html .= '</div>'; // .gatewaykit-ui.

		return $html;
	}

	/**
	 * Render payment receipt
	 *
	 * @param GatewayKit_Transaction_Model $transaction Transaction object
	 * @return string HTML output
	 */
	private function render_payment_receipt( $transaction ) {
		$html  = '<div class="gatewaykit-payment-receipt">';
		$html .= '<h3 class="gatewaykit-receipt-title">' . esc_html__( 'Payment Receipt', 'gatewaykit' ) . '</h3>';

		$html .= '<table class="gatewaykit-receipt-table">';
		$html .= '<tbody>';

		$html .= '<tr class="gatewaykit-receipt-row">';
		$html .= '<td class="gatewaykit-receipt-label">' . esc_html__( 'Receipt Code', 'gatewaykit' ) . ':</td>';
		$html .= '<td class="gatewaykit-receipt-value">';
		$html .= '<span class="gatewaykit-receipt-code">' . esc_html( $transaction->receipt_token ) . '</span>';
		$html .= '<button class="gatewaykit-copy-btn" type="button" title="' . esc_attr__( 'Copy to clipboard', 'gatewaykit' ) . '">';
		$html .= '<span>&#x1F4CB;</span> ' . esc_html__( 'Copy', 'gatewaykit' );
		$html .= '</button>';
		$html .= '</td>';
		$html .= '</tr>';

		if ( ! empty( $transaction->ref_id ) ) {
			$html .= '<tr class="gatewaykit-receipt-row">';
			$html .= '<td class="gatewaykit-receipt-label">' . esc_html__( 'Reference ID', 'gatewaykit' ) . ':</td>';
			$html .= '<td class="gatewaykit-receipt-value gatewaykit-reference-id">' . esc_html( $transaction->ref_id ) . '</td>';
			$html .= '</tr>';
		}

		$html .= '<tr class="gatewaykit-receipt-row gatewaykit-amount-row">';
		$html .= '<td class="gatewaykit-receipt-label">' . esc_html__( 'Amount', 'gatewaykit' ) . ':</td>';
		$html .= '<td class="gatewaykit-receipt-value gatewaykit-amount-value">' . esc_html( number_format( $transaction->amount, 0, ',', '.' ) ) . ' ' . esc_html( $transaction->currency ) . '</td>';
		$html .= '</tr>';

		$html .= '<tr class="gatewaykit-receipt-row">';
		$html .= '<td class="gatewaykit-receipt-label">' . esc_html__( 'Payment Date', 'gatewaykit' ) . ':</td>';
		$html .= '<td class="gatewaykit-receipt-value gatewaykit-payment-date">' . esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $transaction->completed_at ) ) ) . '</td>';
		$html .= '</tr>';

		$html .= '<tr class="gatewaykit-receipt-row">';
		$html .= '<td class="gatewaykit-receipt-label">' . esc_html__( 'Payment Gateway', 'gatewaykit' ) . ':</td>';
		$html .= '<td class="gatewaykit-receipt-value gatewaykit-gateway-name">' . esc_html( ucfirst( $transaction->gateway ) ) . '</td>';
		$html .= '</tr>';

		$form_data = array();
		if ( is_array( $transaction->form_data ) ) {
			$form_data = $transaction->form_data;
		} elseif ( is_string( $transaction->form_data ) ) {
			$decoded = json_decode( $transaction->form_data, true );
			if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
				$form_data = $decoded;
			}
		}

		$desc_source   = 'fallback';
		$raw_db_desc   = is_string( $transaction->description ) ? $transaction->description : '';
		$raw_form_desc = ( is_array( $form_data ) && isset( $form_data['description'] ) ) ? (string) $form_data['description'] : '';

		if ( ! empty( $raw_db_desc ) ) {
			$description = $raw_db_desc;
			$desc_source = 'db';
		} elseif ( ! empty( $raw_form_desc ) ) {
			$description = $raw_form_desc;
			$desc_source = 'form_data';
		} else {
			$description = '-';
		}

		GatewayKit_Logger::get_instance()->debug(
			'receipt_description_extraction',
			array(
				'transaction_id'    => $transaction->id,
				'status'            => $transaction->status,
				'db_desc_len'       => strlen( trim( $raw_db_desc ) ),
				'form_desc_len'     => strlen( trim( $raw_form_desc ) ),
				'desc_source'       => $desc_source,
				'final_description' => $description,
			),
			$transaction->id
		);

		$html .= '<tr class="gatewaykit-receipt-row">';
		$html .= '<td class="gatewaykit-receipt-label">' . esc_html__( 'Description', 'gatewaykit' ) . ':</td>';
		$html .= '<td class="gatewaykit-receipt-value gatewaykit-description">' . esc_html( $description ) . '</td>';
		$html .= '</tr>';

		$html .= '</tbody>';
		$html .= '</table>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * Render transaction details
	 *
	 * @param GatewayKit_Transaction_Model $transaction Transaction object
	 * @return string HTML output
	 */
	private function render_transaction_details( $transaction ) {
		$html  = '<div class="gatewaykit-transaction-details">';
		$html .= '<h3 class="gatewaykit-details-title">' . esc_html__( 'Transaction Details', 'gatewaykit' ) . '</h3>';

		$html .= '<table class="gatewaykit-details-table">';
		$html .= '<tbody>';

		$html .= '<tr class="gatewaykit-detail-row">';
		$html .= '<td class="gatewaykit-detail-label">' . esc_html__( 'Status', 'gatewaykit' ) . ':</td>';
		$html .= '<td class="gatewaykit-detail-value">';
		$html .= '<span class="gatewaykit-ui-badge gatewaykit-status-badge gatewaykit-status-' . esc_attr( $transaction->status ) . '">';
		$html .= esc_html( ucfirst( $transaction->status ) );
		$html .= '</span>';
		$html .= '</td>';
		$html .= '</tr>';

		$html .= '<tr class="gatewaykit-detail-row">';
		$html .= '<td class="gatewaykit-detail-label">' . esc_html__( 'Created', 'gatewaykit' ) . ':</td>';
		$html .= '<td class="gatewaykit-detail-value gatewaykit-created-date">' . esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $transaction->created_at ) ) ) . '</td>';
		$html .= '</tr>';

		if ( ! empty( $transaction->completed_at ) ) {
			$html .= '<tr class="gatewaykit-detail-row">';
			$html .= '<td class="gatewaykit-detail-label">' . esc_html__( 'Completed', 'gatewaykit' ) . ':</td>';
			$html .= '<td class="gatewaykit-detail-value gatewaykit-completed-date">' . esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $transaction->completed_at ) ) ) . '</td>';
			$html .= '</tr>';
		}

		if ( ! empty( $transaction->ip_address ) && current_user_can( 'manage_options' ) ) {
			$html .= '<tr class="gatewaykit-detail-row">';
			$html .= '<td class="gatewaykit-detail-label">' . esc_html__( 'IP Address', 'gatewaykit' ) . ':</td>';
			$html .= '<td class="gatewaykit-detail-value gatewaykit-ip-address">' . esc_html( $transaction->ip_address ) . '</td>';
			$html .= '</tr>';
		}

		$html .= '</tbody>';
		$html .= '</table>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * Render unified receipt combining all fields
	 *
	 * @param GatewayKit_Transaction_Model $transaction Transaction object
	 * @param bool                         $show_receipt Whether to show receipt-specific fields
	 * @param bool                         $show_details Whether to show detail fields
	 * @return string HTML output
	 */
	private function render_unified_receipt( $transaction, $show_receipt = true, $show_details = true ) {
		$html = '<table class="gk-receipt-table">';

		// Receipt Code (always shown).
		$html .= '<tr>';
		$html .= '<td>' . esc_html__( 'Receipt Code', 'gatewaykit' ) . '</td>';
		$html .= '<td>';
		$html .= '<span class="gk-receipt-code">' . esc_html( $transaction->receipt_token ) . '</span>';
		$html .= '<button class="gk-receipt-copy" type="button" data-gk-copy="' . esc_attr( $transaction->receipt_token ) . '" title="' . esc_attr__( 'Copy to clipboard', 'gatewaykit' ) . '">';
		$html .= esc_html__( 'Copy', 'gatewaykit' );
		$html .= '</button>';
		$html .= '</td>';
		$html .= '</tr>';

		// Reference ID (if available).
		if ( ! empty( $transaction->ref_id ) ) {
			$html .= '<tr>';
			$html .= '<td>' . esc_html__( 'Reference ID', 'gatewaykit' ) . '</td>';
			$html .= '<td><span class="gk-receipt-code">' . esc_html( $transaction->ref_id ) . '</span></td>';
			$html .= '</tr>';
		}

		// Status badge.
		$html .= '<tr>';
		$html .= '<td>' . esc_html__( 'Status', 'gatewaykit' ) . '</td>';
		$html .= '<td>';
		$html .= '<span class="gk-receipt-status-badge gk-receipt-status-badge--' . esc_attr( $transaction->status ) . '">';
		$html .= esc_html( ucfirst( $transaction->status ) );
		$html .= '</span>';
		$html .= '</td>';
		$html .= '</tr>';

		// Discount breakdown (Pro discount feature).
		$tx_discount_id     = isset( $transaction->discount_id ) ? (int) $transaction->discount_id : 0;
		$tx_discount_amount = isset( $transaction->discount_amount ) ? (float) $transaction->discount_amount : 0.0;
		$tx_paid            = (float) $transaction->amount;
		$has_discount       = ( $tx_discount_amount > 0 || $tx_discount_id > 0 );
		$is_free_order      = ( $tx_paid <= 0 && 'completed' === $transaction->status );

		if ( $has_discount ) {
			$discount_code = '';
			if ( $tx_discount_id > 0 && class_exists( 'GatewayKit_Discount_Model' ) && method_exists( 'GatewayKit_Discount_Model', 'get_by_id' ) ) {
				$discount_obj = GatewayKit_Discount_Model::get_by_id( $tx_discount_id );
				if ( $discount_obj ) {
					$discount_code = $discount_obj->get_data( 'code' );
				}
			}

			$tx_original = $tx_paid + $tx_discount_amount;

			$html .= '<tr class="gk-field--original">';
			$html .= '<td>' . esc_html__( 'Original Amount', 'gatewaykit' ) . '</td>';
			$html .= '<td>' . esc_html( number_format( $tx_original, 0, ',', '.' ) ) . ' ' . esc_html( $transaction->currency ) . '</td>';
			$html .= '</tr>';

			if ( $discount_code ) {
				$html .= '<tr class="gk-field--discount">';
				$html .= '<td>' . esc_html__( 'Discount Code', 'gatewaykit' ) . '</td>';
				$html .= '<td>' . esc_html( $discount_code ) . '</td>';
				$html .= '</tr>';
			}

			$html .= '<tr class="gk-field--discount">';
			$html .= '<td>' . esc_html__( 'Discount', 'gatewaykit' ) . '</td>';
			$html .= '<td>-' . esc_html( number_format( $tx_discount_amount, 0, ',', '.' ) ) . ' ' . esc_html( $transaction->currency ) . '</td>';
			$html .= '</tr>';
		}

		// Amount paid (highlighted).
		$amount_label = $has_discount ? esc_html__( 'Amount Paid', 'gatewaykit' ) : esc_html__( 'Amount', 'gatewaykit' );

		$html .= '<tr class="gk-field--amount">';
		$html .= '<td>' . $amount_label . '</td>';
		$html .= '<td>' . esc_html( number_format( $transaction->amount, 0, ',', '.' ) ) . ' ' . esc_html( $transaction->currency ) . '</td>';
		$html .= '</tr>';

		// Free-order notice.
		if ( $is_free_order ) {
			$html .= '<tr class="gk-field--free-note">';
			$html .= '<td>' . esc_html__( 'Note', 'gatewaykit' ) . '</td>';
			$html .= '<td>' . esc_html__( 'Fully discounted -- no payment was required.', 'gatewaykit' ) . '</td>';
			$html .= '</tr>';
		}

		// Payment Date (if completed).
		if ( ! empty( $transaction->completed_at ) ) {
			$html .= '<tr>';
			$html .= '<td>' . esc_html__( 'Payment Date', 'gatewaykit' ) . '</td>';
			$html .= '<td>' . esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $transaction->completed_at ) ) ) . '</td>';
			$html .= '</tr>';
		}

		// Payment Gateway.
		$html .= '<tr class="gk-field--gateway">';
		$html .= '<td>' . esc_html__( 'Payment Gateway', 'gatewaykit' ) . '</td>';
		$html .= '<td>' . ( $is_free_order ? esc_html__( '--- (no payment processed)', 'gatewaykit' ) : esc_html( ucfirst( $transaction->gateway ) ) ) . '</td>';
		$html .= '</tr>';

		// Description.
		$form_data = array();
		if ( is_array( $transaction->form_data ) ) {
			$form_data = $transaction->form_data;
		} elseif ( is_string( $transaction->form_data ) ) {
			$decoded = json_decode( $transaction->form_data, true );
			if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
				$form_data = $decoded;
			}
		}

		$desc_source   = 'fallback';
		$raw_db_desc   = is_string( $transaction->description ) ? $transaction->description : '';
		$raw_form_desc = ( is_array( $form_data ) && isset( $form_data['description'] ) ) ? (string) $form_data['description'] : '';

		if ( ! empty( $raw_db_desc ) ) {
			$description = $raw_db_desc;
			$desc_source = 'db';
		} elseif ( ! empty( $raw_form_desc ) ) {
			$description = $raw_form_desc;
			$desc_source = 'form_data';
		} else {
			$description = '-';
		}

		GatewayKit_Logger::get_instance()->debug(
			'unified_receipt_description_extraction',
			array(
				'transaction_id'    => $transaction->id,
				'status'            => $transaction->status,
				'db_desc_len'       => strlen( trim( $raw_db_desc ) ),
				'form_desc_len'     => strlen( trim( $raw_form_desc ) ),
				'desc_source'       => $desc_source,
				'final_description' => $description,
			),
			$transaction->id
		);

		$html .= '<tr class="gk-field--description">';
		$html .= '<td>' . esc_html__( 'Description', 'gatewaykit' ) . '</td>';
		$html .= '<td>' . esc_html( $description ) . '</td>';
		$html .= '</tr>';

		// Created Date.
		$html .= '<tr>';
		$html .= '<td>' . esc_html__( 'Created Date', 'gatewaykit' ) . '</td>';
		$html .= '<td>' . esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $transaction->created_at ) ) ) . '</td>';
		$html .= '</tr>';

		// IP Address (admins only).
		if ( ! empty( $transaction->ip_address ) && current_user_can( 'manage_options' ) ) {
			$html .= '<tr>';
			$html .= '<td>' . esc_html__( 'IP Address', 'gatewaykit' ) . '</td>';
			$html .= '<td>' . esc_html( $transaction->ip_address ) . '</td>';
			$html .= '</tr>';
		}

		$html .= '</table>';

		return $html;
	}

	/**
	 * Get print JavaScript and conditional print CSS
	 *
	 * @param string $mode Print mode option
	 * @return string JavaScript/CSS for print functionality
	 */
	private function get_print_javascript( $mode = 'receipt_only' ) {
		// Print CSS is now enqueued via enqueue_receipt_assets() as receipt.css.
		return '';
	}

	/**
	 * Render receipt input form for when no receipt code is provided
	 *
	 * @return string HTML output
	 */
	private function render_receipt_input_form() {
		$html  = '<div class="gatewaykit-ui">';
		$html .= '<div class="gk-receipt-page">';
		$html .= '<div class="gk-receipt-card">';
		$html .= '<div class="gk-receipt-input">';

		// Icon.
		$html .= '<div class="gk-receipt-input__icon">';
		$html .= '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>';
		$html .= '</div>';

		$html .= '<h2 class="gk-receipt-input__title">' . esc_html__( 'Enter Receipt Code', 'gatewaykit' ) . '</h2>';
		$html .= '<p class="gk-receipt-input__desc">' . esc_html__( 'Please enter your receipt code to view your payment details.', 'gatewaykit' ) . '</p>';

		$html .= '<form method="get" class="gk-receipt-input__form">';
		$html .= '<input type="text"
			name="gatewaykit_receipt"
			placeholder="XXXXXX-YYYYYY"
			pattern="[A-Za-z0-9]{6}-[A-Za-z0-9]{6}"
			maxlength="13"
			required
			class="gk-receipt-input__field"
			autocomplete="off"
			spellcheck="false" />';
		$html .= '<button type="submit" class="gk-receipt-btn gk-receipt-btn--primary">' . esc_html__( 'View Receipt', 'gatewaykit' ) . '</button>';
		$html .= '</form>';

		$html .= '</div>'; // .gk-receipt-input.
		$html .= '</div>'; // .gk-receipt-card.
		$html .= '</div>'; // .gk-receipt-page.
		$html .= '</div>'; // .gatewaykit-ui.

		return $html;
	}

	/**
	 * Check if rate limited based on failed lookup attempts
	 *
	 * @return bool
	 */
	private function is_rate_limited() {
		$ip              = $this->get_client_ip();
		$transient_key   = 'gatewaykit_receipt_rate_limit_' . md5( $ip );
		$failed_attempts = intval( get_transient( $transient_key ) );
		return $failed_attempts >= 20;
	}

	/**
	 * Log a failed receipt lookup attempt
	 */
	private function log_failed_attempt() {
		$ip              = $this->get_client_ip();
		$transient_key   = 'gatewaykit_receipt_rate_limit_' . md5( $ip );
		$failed_attempts = intval( get_transient( $transient_key ) );
		++$failed_attempts;
		set_transient( $transient_key, $failed_attempts, MINUTE_IN_SECONDS );
	}

	/**
	 * Clear failed attempts on successful lookup
	 */
	private function clear_failed_attempts() {
		$ip            = $this->get_client_ip();
		$transient_key = 'gatewaykit_receipt_rate_limit_' . md5( $ip );
		delete_transient( $transient_key );
	}

	/**
	 * Get client IP address for rate limiting
	 *
	 * @return string
	 */
	private function get_client_ip() {
		return GatewayKit_IP_Helper::get_client_ip();
	}

	/**
	 * Render error message
	 *
	 * @param string $message Error message
	 * @return string HTML output
	 */
	private function render_error( $message ) {
		return '<div class="gatewaykit-ui"><div class="gk-receipt-page"><div class="gk-receipt-error">' . esc_html( $message ) . '</div></div></div>';
	}
}

// Initialize shortcode
new GatewayKit_Payment_Result_Shortcode();