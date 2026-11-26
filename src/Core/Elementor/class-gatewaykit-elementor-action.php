<?php
/**
 * Elementor Action
 *
 * Integrates payment processing with Elementor forms.
 *
 * @package GatewayKit
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Check if Elementor Pro is active.
if ( ! class_exists( '\ElementorPro\Modules\Forms\Classes\Action_Base' ) ) {
	return;
}

use ElementorPro\Modules\Forms\Classes\Action_Base;
use ElementorPro\Modules\Forms\Classes\Form_Record;
use ElementorPro\Modules\Forms\Classes\Ajax_Handler;
use ElementorPro\Modules\Forms\Module;
use Elementor\Controls_Manager;
use Elementor\Widget_Base;

/**
 * Elementor Action Class for GatewayKit
 *
 * Integrates payment processing with Elementor forms.
 *
 * @package GatewayKit
 * @extends Action_Base
 * @uses Module
 * @uses Controls_Manager
 */
class GatewayKit_Elementor_Action extends Action_Base {

	/**
	 * Action name.
	 */
	const ACTION_NAME = 'payment_gateway';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'elementor/editor/after_enqueue_scripts', array( $this, 'enqueue_editor_styles' ) );
		$this->init();
	}

	/**
	 * Initialize the action.
	 */
	public function init() {
		Module::instance()->add_form_action(
			self::ACTION_NAME,
			$this
		);
	}

	/**
	 * Get action name.
	 *
	 * @return string Action name.
	 */
	public function get_name() {
		return self::ACTION_NAME;
	}

	/**
	 * Get action label.
	 *
	 * @return string Action label.
	 */
	public function get_label() {
		return __( 'Payment Gateway', 'gatewaykit' );
	}

	/**
	 * Register settings section for the action.
	 *
	 * @param Widget_Base $widget Widget instance.
	 */
	public function register_settings_section( $widget ) {
		GatewayKit_Elementor_Form_Controls::register_settings_section( $widget, $this );
	}

	/**
	 * Enqueue editor styles.
	 */
	public function enqueue_editor_styles() {
		wp_enqueue_style( 'gatewaykit-elementor-editor', GATEWAYKIT_PLUGIN_URL . 'assets/css/elementor-editor.css', array(), GATEWAYKIT_VERSION );
	}

	/**
	 * Get post ID from form record with fallback to current post.
	 *
	 * @param Form_Record $record Form record.
	 * @return int Post ID.
	 */
	private function get_record_post_id( $record ) {
		$post_id = $record->get_form_meta( 'post_id' );
		return ! empty( $post_id ) ? (int) $post_id : (int) get_the_ID();
	}

	/**
	 * Run the action (orchestrator: validate -> delegate).
	 *
	 * @param Form_Record  $record       Elementor Form Record.
	 * @param Ajax_Handler $ajax_handler Elementor AJAX Handler.
	 */
	public function run( $record, $ajax_handler ) {
		try {
			// Diagnostic logging for AJAX debugging.
			$this->log_action_diagnostics( $record );

			// Rate limiting check.
			$rate_limiter = GatewayKit_Rate_Limiter::get_instance();
			$rate_check   = $rate_limiter->check_rate_limit( 'elementor_action' );
			if ( is_wp_error( $rate_check ) ) {
				$ajax_handler->add_error_message( $rate_check->get_error_message() );
				return;
			}

			// Nonce verification.
			if ( ! $this->verify_nonce() ) {
				GatewayKit_Logger::get_instance()->error(
					'Nonce verification failed — payment blocked',
					array(
						'post_id' => $this->get_record_post_id( $record ),
					)
				);
				$ajax_handler->add_error_message( esc_html__( 'Security check failed. Please reload the page and try again.', 'gatewaykit' ) );
				return;
			}

			$settings = null;
			$fields   = null;
			if ( ! $this->validate_submission( $record, $ajax_handler, $settings, $fields ) ) {
				return;
			}

			// Optional payment check (Pro donation-style).
			if ( $this->is_optional_no_payment( $settings, $fields ) ) {
				$this->process_optional_no_payment( $record, $ajax_handler, $settings, $fields );
				return;
			}

			// Calculate base amount.
			$amount = $this->calculate_amount( $settings, $fields );
			if ( is_wp_error( $amount ) ) {
				$ajax_handler->add_error_message( $amount->get_error_message() );
				return;
			}

			// Partial payment adjustment (Pro).
			$amount = $this->apply_partial_payment( $settings, $amount );

			// Discount calculation & reservation (Pro).
			$discount_info = $this->process_discount( $settings, $fields, $amount, $ajax_handler );
			if ( is_wp_error( $discount_info ) ) {
				$ajax_handler->add_error_message( $discount_info->get_error_message() );
				return;
			}
			$amount = $discount_info['amount'];

			// Prepare structured payment data.
			$payment_data = $this->build_payment_data( $record, $settings, $fields, $amount, $discount_info );

			// Free order (100% discount).
			if ( (float) $amount <= 0 ) {
				$this->process_free_order( $payment_data, $ajax_handler, $discount_info );
				return;
			}

			// Validate and execute payment.
			$this->process_paid_order( $payment_data, $ajax_handler, $discount_info, $amount );

		} catch ( \Throwable $e ) {
			$error_handler = GatewayKit_Error_Handler::get_instance();
			$error_handler->log_error(
				'network',
				'exception',
				array(
					'error'         => $e->getMessage(),
					'trace'         => $e->getTraceAsString(),
					'post_id'       => $this->get_record_post_id( $record ),
					'settings_type' => isset( $settings ) ? gettype( $settings ) : 'null',
				),
				'Payment action exception'
			);
			$error_message = $error_handler->get_error_message( 'network', 'exception', false );
			$ajax_handler->add_error_message( $error_message );
		}
	}

	/**
	 * Log diagnostic details for debugging form submissions.
	 *
	 * @param Form_Record $record Elementor Form Record.
	 */
	private function log_action_diagnostics( $record ) {
		$logger = GatewayKit_Logger::get_instance();
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- diagnostic logging only; nonce verified below.
		$logger->debug(
			'AJAX Action Debug - GatewayKit Elementor Action Started',
			array(
				'post_id'                  => $this->get_record_post_id( $record ),
				'is_preview'               => is_preview(),
				'wp_doing_ajax'            => wp_doing_ajax(),
				'http_referer'             => isset( $_SERVER['HTTP_REFERER'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : 'none',
				'ajax_action'              => isset( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : 'unknown',
				'gatewaykit_action'        => isset( $_POST['gatewaykit_action'] ) ? sanitize_key( wp_unslash( $_POST['gatewaykit_action'] ) ) : 'none',
				'wpnonce_present'          => isset( $_POST['_wpnonce'] ),
				'gatewaykit_nonce_present' => isset( $_POST['gatewaykit_nonce'] ),
				'request_method'           => isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'unknown',
			)
		);
		// phpcs:enable
	}

	/**
	 * Validate submission structure, fields, gateway, and URLs.
	 *
	 * @param Form_Record  $record       Form record.
	 * @param Ajax_Handler $ajax_handler AJAX handler.
	 * @param array|null   $settings     Output settings array.
	 * @param array|null   $fields       Output fields array.
	 * @return bool True if valid, false if error added.
	 */
	private function validate_submission( $record, $ajax_handler, &$settings, &$fields ) {
		$settings = $record->get( 'form_settings' );
		$fields   = $record->get( 'fields' );

		if ( ! is_array( $settings ) ) {
			GatewayKit_Logger::get_instance()->error(
				'Invalid form settings structure',
				array(
					'settings_type'  => gettype( $settings ),
					'settings_value' => is_string( $settings ) ? substr( $settings, 0, 100 ) : $settings,
					'post_id'        => $this->get_record_post_id( $record ),
					'fields_count'   => is_array( $fields ) ? count( $fields ) : 'not_array',
				)
			);
			$ajax_handler->add_error_message( esc_html__( 'Form configuration error. Please refresh the page and try again.', 'gatewaykit' ) );
			return false;
		}

		$validator        = GatewayKit_Input_Validator::get_instance();
		$validated_fields = $validator->validate_form_data( $fields );
		if ( is_wp_error( $validated_fields ) ) {
			$ajax_handler->add_error_message( $validated_fields->get_error_message() );
			return false;
		}
		$fields = $validated_fields;

		if ( ! isset( $settings['gatewaykit_gateway'] ) ) {
			GatewayKit_Logger::get_instance()->error( 'Missing gateway setting', array( 'settings' => $settings ) );
			$ajax_handler->add_error_message( esc_html__( 'Payment gateway not selected.', 'gatewaykit' ) );
			return false;
		}
		$gateway = $validator->validate_gateway( $settings['gatewaykit_gateway'] );
		if ( is_wp_error( $gateway ) ) {
			$ajax_handler->add_error_message( $gateway->get_error_message() );
			return false;
		}
		$settings['gatewaykit_gateway'] = $gateway;

		if ( ! isset( $settings['gatewaykit_success_url']['url'] ) ) {
			GatewayKit_Logger::get_instance()->error( 'Missing success URL setting', array( 'settings' => $settings ) );
			$ajax_handler->add_error_message( esc_html__( 'Success URL not configured.', 'gatewaykit' ) );
			return false;
		}
		$success_url = $validator->validate_required_url( $settings['gatewaykit_success_url']['url'] );
		if ( is_wp_error( $success_url ) ) {
			$ajax_handler->add_error_message( $success_url->get_error_message() );
			return false;
		}
		$settings['gatewaykit_success_url']['url'] = $success_url;

		$settings['gatewaykit_description'] = isset( $settings['gatewaykit_description'] ) ? sanitize_text_field( $settings['gatewaykit_description'] ) : __( 'Payment for services', 'gatewaykit' );

		GatewayKit_Logger::get_instance()->info(
			'Payment form settings',
			array(
				'redirect_url' => $settings['gatewaykit_success_url']['url'],
				'post_id'      => $this->get_record_post_id( $record ),
			)
		);

		return true;
	}

	/**
	 * Check whether submission opted out of payment.
	 *
	 * @param array $settings Form settings.
	 * @param array $fields   Form fields.
	 * @return bool True if optional payment is enabled and user opted out.
	 */
	private function is_optional_no_payment( $settings, $fields ) {
		if ( defined( 'GATEWAYKIT_PRO_VERSION' ) && ! empty( $settings['gatewaykit_optional_payment_enabled'] ) && 'yes' === $settings['gatewaykit_optional_payment_enabled'] ) {
			$opt_field     = sanitize_key( $settings['gatewaykit_optional_payment_field'] ?? '' );
			$opt_val       = ( $opt_field && isset( $fields[ $opt_field ]['value'] ) ) ? strtolower( trim( (string) $fields[ $opt_field ]['value'] ) ) : '';
			$wants_payment = in_array( $opt_val, array( 'on', 'true', '1', 'yes', 'checked' ), true );
			return ! $wants_payment;
		}
		return false;
	}

	/**
	 * Process no-payment submission for optional payment forms.
	 *
	 * @param Form_Record  $record       Form record.
	 * @param Ajax_Handler $ajax_handler AJAX handler.
	 * @param array        $settings     Form settings.
	 * @param array        $fields       Form fields.
	 */
	private function process_optional_no_payment( $record, $ajax_handler, $settings, $fields ) {
		$payment_data = array(
			'gateway'         => $settings['gatewaykit_gateway'],
			'amount'          => 0,
			'discount_id'     => null,
			'discount_amount' => 0,
			'description'     => ! empty( $settings['gatewaykit_description'] ) ? $settings['gatewaykit_description'] : __( 'Payment for services', 'gatewaykit' ),
			'form_data'       => $fields,
			'user_data'       => $this->get_user_data(),
			'user_id'         => is_user_logged_in() ? get_current_user_id() : null,
			'callback_url'    => '',
			'success_url'     => ! empty( $settings['gatewaykit_success_url']['url'] ) ? $settings['gatewaykit_success_url']['url'] : '',
			'failure_url'     => '',
			'form_id'         => $record->get_form_meta( 'id' ),
			'post_id'         => $this->get_record_post_id( $record ),
		);

		$transaction = GatewayKit_Transaction_Model::create( $payment_data );
		if ( is_wp_error( $transaction ) ) {
			$ajax_handler->add_error_message( $transaction->get_error_message() );
			return;
		}

		$transaction->update(
			array(
				'status'       => 'completed',
				'ref_id'       => 'NO_PAYMENT',
				'completed_at' => current_time( 'mysql' ),
			)
		);

		do_action( 'gatewaykit_payment_completed', $transaction );

		$msg = ! empty( $settings['gatewaykit_optional_no_payment_message'] )
			? wp_kses_post( $settings['gatewaykit_optional_no_payment_message'] )
			: __( 'Thank you. Your submission was received.', 'gatewaykit' );

		$ajax_handler->add_response_data( 'gatewaykit_no_payment', true );
		$ajax_handler->add_response_data( 'gatewaykit_no_payment_message', $msg );
		$ajax_handler->add_response_data( 'success_message', $msg );
	}

	/**
	 * Apply partial payment percentage if enabled (Pro).
	 *
	 * @param array $settings Form settings.
	 * @param float $amount   Calculated amount.
	 * @return float Adjusted amount.
	 */
	private function apply_partial_payment( $settings, $amount ) {
		if ( defined( 'GATEWAYKIT_PRO_VERSION' )
			&& isset( $settings['gatewaykit_partial_enabled'] )
			&& 'yes' === $settings['gatewaykit_partial_enabled']
		) {
			$percent = isset( $settings['gatewaykit_partial_percent'] ) ? (float) $settings['gatewaykit_partial_percent'] : 100;
			if ( $percent < 1 ) {
				$percent = 1;
			}
			if ( $percent > 100 ) {
				$percent = 100;
			}
			return round( (float) $amount * ( $percent / 100 ), 2 );
		}
		return $amount;
	}

	/**
	 * Process discount code validation and usage reservation (Pro).
	 *
	 * @param array        $settings     Form settings.
	 * @param array        $fields       Form fields.
	 * @param float        $amount       Current amount.
	 * @param Ajax_Handler $ajax_handler AJAX handler.
	 * @return array|WP_Error Discount info array or WP_Error.
	 */
	private function process_discount( $settings, $fields, $amount, $ajax_handler ) {
		$info = array(
			'applied'         => false,
			'id'              => 0,
			'amount'          => $amount,
			'discount_amount' => 0.0,
			'original_amount' => (float) $amount,
		);

		if ( ! class_exists( 'GatewayKit_Discount_Model' ) ) {
			return $info;
		}

		// Read discount code from POST or processed fields.
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce already verified.
		$discount_code = isset( $_POST['form_fields']['_gatewaykit_discount_code'] )
			? sanitize_text_field( wp_unslash( $_POST['form_fields']['_gatewaykit_discount_code'] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' === $discount_code ) {
			foreach ( $fields as $fid => $fdata ) {
				if ( isset( $fdata['type'] ) && 'gatewaykit_discount_code' === $fdata['type'] && ! empty( $fdata['value'] ) ) {
					$discount_code = sanitize_text_field( $fdata['value'] );
					break;
				}
			}
		}

		if ( '' === $discount_code ) {
			$discount_field_id = isset( $settings['gatewaykit_discount_field'] ) ? sanitize_key( $settings['gatewaykit_discount_field'] ) : '';
			if ( $discount_field_id && isset( $fields[ $discount_field_id ]['value'] ) ) {
				$discount_code = sanitize_text_field( $fields[ $discount_field_id ]['value'] );
			}
		}

		if ( '' === $discount_code ) {
			return $info;
		}

		$current_user_id = is_user_logged_in() ? get_current_user_id() : 0;
		$valid           = GatewayKit_Discount_Model::validate_code( $discount_code, $amount, $current_user_id );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$calc = GatewayKit_Discount_Model::calculate_discount( $discount_code, $amount );
		if ( is_wp_error( $calc ) ) {
			return $calc;
		}

		$discount_obj = GatewayKit_Discount_Model::get_by_code( $discount_code );
		if ( ! $discount_obj ) {
			return new WP_Error( 'invalid_discount', esc_html__( 'Invalid discount code.', 'gatewaykit' ) );
		}

		$discount_id     = (int) $discount_obj->get_data( 'id' );
		$discount_amount = (float) $calc['discount_amount'];

		if ( ! GatewayKit_Discount_Model::increment_usage( $discount_id ) ) {
			return new WP_Error( 'limit_reached', esc_html__( 'This discount code has reached its usage limit.', 'gatewaykit' ) );
		}

		$final_amount = (float) $calc['final_amount'];
		if ( $final_amount < 0 ) {
			$final_amount = 0.0;
		}

		$info['applied']         = true;
		$info['id']              = $discount_id;
		$info['discount_amount'] = $discount_amount;
		$info['amount']          = $final_amount;

		return $info;
	}

	/**
	 * Build standardized payment data array.
	 *
	 * @param Form_Record $record        Form record.
	 * @param array       $settings      Form settings.
	 * @param array       $fields        Form fields.
	 * @param float       $amount        Payment amount.
	 * @param array       $discount_info Discount info.
	 * @return array Structured payment data.
	 */
	private function build_payment_data( $record, $settings, $fields, $amount, $discount_info ) {
		$user_data = $this->get_user_data();

		if ( ! empty( $settings['gatewaykit_email_receipt_enabled'] ) && 'yes' === $settings['gatewaykit_email_receipt_enabled'] ) {
			$email_field_id = isset( $settings['gatewaykit_email_field'] ) ? sanitize_key( $settings['gatewaykit_email_field'] ) : '';
			$receipt_email  = '';
			if ( $email_field_id && isset( $fields[ $email_field_id ]['value'] ) ) {
				$receipt_email = sanitize_email( $fields[ $email_field_id ]['value'] );
			}
			if ( '' === $receipt_email && is_user_logged_in() ) {
				$current_user  = wp_get_current_user();
				$receipt_email = $current_user->user_email;
			}
			$user_data['email_receipt_enabled'] = 'yes';
			$user_data['email_receipt_to']      = $receipt_email;
		}

		if ( defined( 'GATEWAYKIT_PRO_VERSION' ) && ! empty( $settings['gatewaykit_subscription_enabled'] ) && 'yes' === $settings['gatewaykit_subscription_enabled'] ) {
			$price_id = isset( $settings['gatewaykit_stripe_price_id'] ) ? sanitize_text_field( $settings['gatewaykit_stripe_price_id'] ) : '';
			if ( '' !== $price_id && preg_match( '/^price_[A-Za-z0-9]+$/', $price_id ) ) {
				$user_data['subscription_price_id'] = $price_id;

				$email_field = isset( $settings['gatewaykit_customer_email_field'] ) ? sanitize_key( $settings['gatewaykit_customer_email_field'] ) : '';
				if ( $email_field && isset( $fields[ $email_field ]['value'] ) ) {
					$user_data['customer_email'] = sanitize_email( $fields[ $email_field ]['value'] );
				}
			}
		}

		$failure_url = '';
		if ( isset( $settings['gatewaykit_failure_url']['url'] ) && '' !== $settings['gatewaykit_failure_url']['url'] ) {
			$validator   = GatewayKit_Input_Validator::get_instance();
			$val_fail    = $validator->validate_url( $settings['gatewaykit_failure_url']['url'] );
			$failure_url = is_wp_error( $val_fail ) ? '' : $val_fail;
		}

		return array(
			'gateway'         => $settings['gatewaykit_gateway'],
			'amount'          => $amount,
			'discount_id'     => $discount_info['id'] > 0 ? $discount_info['id'] : null,
			'discount_amount' => $discount_info['discount_amount'],
			'description'     => ! empty( $settings['gatewaykit_description'] ) ? $settings['gatewaykit_description'] : __( 'Payment for services', 'gatewaykit' ),
			'form_data'       => $fields,
			'user_data'       => $user_data,
			'user_id'         => is_user_logged_in() ? get_current_user_id() : null,
			'callback_url'    => str_replace( '{gateway}', $settings['gatewaykit_gateway'], $this->get_callback_url() ),
			'success_url'     => ! empty( $settings['gatewaykit_success_url']['url'] ) ? $settings['gatewaykit_success_url']['url'] : '',
			'failure_url'     => $failure_url,
			'form_id'         => $record->get_form_meta( 'id' ),
			'post_id'         => $this->get_record_post_id( $record ),
		);
	}

	/**
	 * Process free order (e.g. 100% discount).
	 *
	 * @param array        $payment_data  Payment data.
	 * @param Ajax_Handler $ajax_handler  AJAX handler.
	 * @param array        $discount_info Discount info.
	 */
	private function process_free_order( $payment_data, $ajax_handler, $discount_info ) {
		$payment_data['amount'] = 0;
		$transaction            = GatewayKit_Transaction_Model::create( $payment_data );

		if ( is_wp_error( $transaction ) ) {
			if ( $discount_info['applied'] ) {
				GatewayKit_Discount_Model::decrement_usage( $discount_info['id'] );
			}
			$ajax_handler->add_error_message( $transaction->get_error_message() );
			return;
		}

		$transaction->update(
			array(
				'status'       => 'completed',
				'ref_id'       => 'FREE',
				'completed_at' => current_time( 'mysql' ),
			)
		);

		if ( $discount_info['applied'] ) {
			$recorded = GatewayKit_Discount_Usage_Model::record_usage(
				array(
					'discount_id'     => $discount_info['id'],
					'transaction_id'  => (int) $transaction->get( 'id' ),
					'user_id'         => is_user_logged_in() ? get_current_user_id() : null,
					'amount_saved'    => $discount_info['discount_amount'],
					'original_amount' => $discount_info['original_amount'],
					'final_amount'    => 0.0,
					'ip_address'      => GatewayKit_IP_Helper::get_client_ip(),
				)
			);
			if ( is_wp_error( $recorded ) ) {
				GatewayKit_Logger::get_instance()->error(
					'Discount usage audit insert failed (free order); counter kept incremented',
					array(
						'discount_id'    => $discount_info['id'],
						'transaction_id' => (int) $transaction->get( 'id' ),
						'error'          => $recorded->get_error_message(),
					)
				);
			}
		}

		do_action( 'gatewaykit_payment_completed', $transaction );

		$success_url = $payment_data['success_url'];
		if ( $success_url ) {
			$redirect_url = add_query_arg( 'gatewaykit_receipt', $transaction->get( 'receipt_token' ), $success_url );
			$ajax_handler->add_response_data( 'redirect_url', $redirect_url );
		} else {
			$ajax_handler->add_response_data( 'success_message', esc_html__( 'Your discount covered the full amount — no payment required. Your order is complete.', 'gatewaykit' ) );
		}
	}

	/**
	 * Process paid order through gateway.
	 *
	 * @param array        $payment_data  Payment data.
	 * @param Ajax_Handler $ajax_handler  AJAX handler.
	 * @param array        $discount_info Discount info.
	 * @param float        $amount        Final charge amount.
	 */
	private function process_paid_order( $payment_data, $ajax_handler, $discount_info, $amount ) {
		$security_manager = GatewayKit_Security_Manager::get_instance();
		$validated_data   = $security_manager->validate_payment_data( $payment_data, array( 'discounted' => $discount_info['applied'] ) );

		if ( is_wp_error( $validated_data ) ) {
			GatewayKit_Logger::get_instance()->error(
				'Payment data validation failed',
				array(
					'errors'       => $validated_data->get_error_messages(),
					'payment_data' => array_intersect_key( $payment_data, array_flip( array( 'gateway', 'amount', 'description' ) ) ),
				)
			);
			$ajax_handler->add_error_message( esc_html__( 'Payment validation failed. Please check your form settings.', 'gatewaykit' ) );
			return;
		}

		$result = $this->process_payment( $validated_data );

		if ( is_wp_error( $result ) ) {
			if ( $discount_info['applied'] ) {
				GatewayKit_Discount_Model::decrement_usage( $discount_info['id'] );
			}

			$error_message = $result->get_error_message();
			if ( empty( $error_message ) ) {
				$error_handler = GatewayKit_Error_Handler::get_instance();
				$error_data    = $result->get_error_data();
				$error_type    = is_array( $error_data ) && isset( $error_data['error_type'] ) ? $error_data['error_type'] : 'gateway';
				$error_code    = is_array( $error_data ) && isset( $error_data['error_code'] ) ? $error_data['error_code'] : 'payment_failed';
				$error_details = is_array( $error_data ) && isset( $error_data['error_details'] ) ? $error_data['error_details'] : array();
				$error_message = $error_handler->get_error_message(
					$error_type,
					$error_code,
					current_user_can( 'manage_options' ),
					$error_details
				);
			}
			$ajax_handler->add_error_message( $error_message );
			return;
		}

		if ( isset( $result['status'] ) && 'success' === $result['status'] && ! empty( $result['redirect_url'] ) ) {
			if ( $discount_info['applied'] ) {
				if ( empty( $result['transaction_id'] ) ) {
					GatewayKit_Discount_Model::decrement_usage( $discount_info['id'] );
					GatewayKit_Logger::get_instance()->error(
						'Discount commit skipped: payment succeeded without a transaction id',
						array( 'discount_id' => $discount_info['id'] )
					);
				} else {
					$recorded = GatewayKit_Discount_Usage_Model::record_usage(
						array(
							'discount_id'     => $discount_info['id'],
							'transaction_id'  => (int) $result['transaction_id'],
							'user_id'         => is_user_logged_in() ? get_current_user_id() : null,
							'amount_saved'    => $discount_info['discount_amount'],
							'original_amount' => $discount_info['original_amount'],
							'final_amount'    => (float) $amount,
							'ip_address'      => GatewayKit_IP_Helper::get_client_ip(),
						)
					);
					if ( is_wp_error( $recorded ) ) {
						GatewayKit_Logger::get_instance()->error(
							'Discount usage audit insert failed; counter kept incremented for reconciliation',
							array(
								'discount_id'    => $discount_info['id'],
								'transaction_id' => (int) $result['transaction_id'],
								'error'          => $recorded->get_error_message(),
							)
						);
					}
				}
			}
			$ajax_handler->add_response_data( 'redirect_url', $result['redirect_url'] );
		} else {
			if ( $discount_info['applied'] && ! empty( $discount_info['id'] ) ) {
				GatewayKit_Discount_Model::decrement_usage( $discount_info['id'] );
			}
			if ( ! empty( $result['transaction_id'] ) ) {
				$failed_transaction = GatewayKit_Transaction_Model::find( $result['transaction_id'] );
				if ( $failed_transaction ) {
					$failed_transaction->update( array( 'status' => 'failed' ) );
				}
			}
			$ajax_handler->add_error_message( esc_html__( 'Payment gateway did not return a redirect URL. Please try again.', 'gatewaykit' ) );
		}
	}

	/**
	 * Calculate payment amount.
	 *
	 * @param array $settings Form settings.
	 * @param array $fields   Form fields.
	 * @return float|WP_Error Amount or error.
	 */
	private function calculate_amount( $settings, $fields ) {
		$validator = GatewayKit_Input_Validator::get_instance();

		if ( isset( $settings['gatewaykit_amount_type'] ) && 'fixed' === $settings['gatewaykit_amount_type'] ) {
			$amount = ! empty( $settings['gatewaykit_amount'] ) ? $settings['gatewaykit_amount'] : 0;
		} else {
			$field_id = ! empty( $settings['gatewaykit_amount_field'] ) ? sanitize_key( $settings['gatewaykit_amount_field'] ) : '';
			if ( empty( $field_id ) || ! isset( $fields[ $field_id ] ) ) {
				return new WP_Error( 'amount_field_missing', esc_html__( 'Amount field not found.', 'gatewaykit' ) );
			}
			$amount = $fields[ $field_id ]['value'];
		}

		$amount = $validator->validate_amount( $amount );
		if ( is_wp_error( $amount ) ) {
			return $amount;
		}

		return $amount;
	}

	/**
	 * Get user data.
	 *
	 * @return array User data.
	 */
	private function get_user_data() {
		return GatewayKit_Payment_Service::get_user_data();
	}

	/**
	 * Get callback URL.
	 *
	 * @return string Callback URL.
	 */
	private function get_callback_url() {
		return GatewayKit_Payment_Service::get_callback_url();
	}

	/**
	 * Process payment.
	 *
	 * @param array $payment_data Payment data.
	 * @return array|WP_Error Result or error.
	 */
	private function process_payment( $payment_data ) {
		return GatewayKit_Payment_Service::process_payment( $payment_data );
	}

	/**
	 * Verify CSRF nonce for form submission.
	 *
	 * @return bool True if a valid nonce matched, false otherwise.
	 */
	private function verify_nonce() {
		return GatewayKit_Nonce_Service::verify_elementor_action_nonce();
	}

	/**
	 * Get available gateways for select control.
	 *
	 * @return array Gateway options.
	 */
	public function get_available_gateways() {
		return GatewayKit_Elementor_Form_Controls::get_available_gateways();
	}

	/**
	 * Export action settings.
	 *
	 * @param array $element Element settings.
	 * @return array
	 */
	public function on_export( $element ) {
		return $element;
	}

	/**
	 * Collect all Elementor forms on current page with optional payment.
	 *
	 * @return array
	 */
	public static function get_optional_payment_forms_for_current_page() {
		return GatewayKit_Elementor_Page_Scanner::get_optional_payment_forms_for_current_page();
	}

	/**
	 * Recursively walk Elementor elements data and collect optional-payment forms.
	 *
	 * @param array $elements Element tree.
	 * @param array $forms    Collected forms output array.
	 */
	private static function collect_optional_payment_forms( $elements, &$forms ) {
		GatewayKit_Elementor_Page_Scanner::collect_optional_payment_forms( $elements, $forms );
	}

	/**
	 * Import action settings.
	 *
	 * @param array $settings Settings.
	 * @param array $element  Element.
	 * @return array
	 */
	public function on_import( $settings, $element ) {
		return $settings;
	}
}
