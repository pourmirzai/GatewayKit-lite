<?php
/**
 * Elementor Action
 *
 * Integrates payment processing with Elementor forms
 *
 * @package GatewayKit
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Check if Elementor Pro is active
if ( ! class_exists( '\ElementorPro\Modules\Forms\Classes\Action_Base' ) ) {
	return;
}

use \ElementorPro\Modules\Forms\Classes\Action_Base;
use \ElementorPro\Modules\Forms\Classes\Form_Record;
use \ElementorPro\Modules\Forms\Classes\Ajax_Handler;
use \ElementorPro\Modules\Forms\Module;
use \Elementor\Controls_Manager;
use \Elementor\Widget_Base;

/**
 * Elementor Action Class for GatewayKit
 *
 * Integrates payment processing with Elementor forms
 *
 * @package GatewayKit
 * @extends Action_Base
 * @uses Module
 * @uses Controls_Manager
 */
class GatewayKit_Elementor_Action extends Action_Base {

	/**
	 * Action name
	 */
	const ACTION_NAME = 'payment_gateway';

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'elementor/editor/after_enqueue_scripts', array( $this, 'enqueue_editor_styles' ) );
		$this->init();
	}

	/**
	 * Initialize the action
	 */
	public function init() {
		// Register the action
		Module::instance()->add_form_action(
			self::ACTION_NAME,
			$this
		);
	}

	/**
	 * Get action name
	 *
	 * @return string Action name
	 */
	public function get_name() {
		return self::ACTION_NAME;
	}

	/**
	 * Get action label
	 *
	 * @return string Action label
	 */
	public function get_label() {
		return __( 'Payment Gateway', 'gatewaykit' );
	}

	/**
	 * Register action controls
	 *
	 * @param Widget_Base $widget Widget instance
	 */
	public function register_settings_section( $widget ) {
		$widget->start_controls_section(
			'section_gatewaykit_payment_gateway',
			array(
				'label'     => __( 'Payment Gateway', 'gatewaykit' ),
				'condition' => array(
					'submit_actions' => $this->get_name(),
				),
			)
		);

		$widget->add_control(
			'gatewaykit_gateway',
			array(
				'label'       => __( 'Payment Gateway', 'gatewaykit' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => $this->get_available_gateways(),
				'default'     => '',
				'description' => __( 'Select the payment gateway to use.', 'gatewaykit' ),
			)
		);

		$widget->add_control(
			'gatewaykit_amount_type',
			array(
				'label'   => __( 'Amount Type', 'gatewaykit' ),
				'type'    => Controls_Manager::SELECT,
				'options' => array(
					'fixed' => __( 'Fixed Amount', 'gatewaykit' ),
					'field' => __( 'From Form Field', 'gatewaykit' ),
				),
				'default' => 'fixed',
			)
		);

		$widget->add_control(
			'gatewaykit_amount',
			array(
				'label'       => __( 'Amount', 'gatewaykit' ),
				'type'        => Controls_Manager::NUMBER,
				'default'     => '',
				'condition'   => array(
					'gatewaykit_amount_type' => 'fixed',
				),
				'description' => __( 'Enter the fixed payment amount.', 'gatewaykit' ),
			)
		);

		$widget->add_control(
			'gatewaykit_amount_field',
			array(
				'label'       => __( 'Amount Field ID', 'gatewaykit' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '',
				'condition'   => array(
					'gatewaykit_amount_type' => 'field',
				),
				'description' => __( 'Enter the form field ID that contains the amount.', 'gatewaykit' ),
			)
		);

		$widget->add_control(
			'gatewaykit_description',
			array(
				'label'       => __( 'Payment Description', 'gatewaykit' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => __( 'Payment for services', 'gatewaykit' ),
				'description' => __( 'Description shown to the user during payment.', 'gatewaykit' ),
			)
		);

		$widget->add_control(
			'gatewaykit_success_url',
			array(
				'label'       => __( 'Redirect URL after payment completion', 'gatewaykit' ),
				'type'        => Controls_Manager::URL,
				'default'     => array(
					'url'         => '',
					'is_external' => false,
					'nofollow'    => false,
				),
				'description' => __( 'URL to redirect after payment completion.', 'gatewaykit' ),
				'required'    => true,
				'label_block' => true,
			)
		);

		$widget->add_control(
			'gatewaykit_success_url_info',
			array(
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => '<p style="color: #9b9b9b; font-size: 12px; margin-top: -10px; margin-bottom: 15px;">' .
						__( 'This field is required. The destination page must contain the <code>[gatewaykit_receipt]</code> shortcode to display payment results.', 'gatewaykit' ) .
						'</p>',
				'content_classes' => '',
				'separator'       => 'none',
			)
		);

		// --- Email receipt controls (Lite). ---
		$widget->add_control(
			'gatewaykit_email_receipt_enabled',
			array(
				'label'       => __( 'Email Receipt to Customer', 'gatewaykit' ),
				'type'        => Controls_Manager::SWITCHER,
				'label_on'    => __( 'Yes', 'gatewaykit' ),
				'label_off'   => __( 'No', 'gatewaykit' ),
				'default'     => '',
				'description' => __( 'Send a payment receipt email to the customer after successful payment.', 'gatewaykit' ),
				'separator'   => 'before',
			)
		);

		$widget->add_control(
			'gatewaykit_email_field',
			array(
				'label'       => __( 'Customer Email Field ID', 'gatewaykit' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '',
				'condition'   => array(
					'gatewaykit_email_receipt_enabled' => 'yes',
				),
				'description' => __( 'Short Code (field ID) of an email field whose submitted value is the customer email.', 'gatewaykit' ),
			)
		);

		// --- Optional Payment controls (Pro only). ---
		if ( defined( 'GATEWAYKIT_PRO_VERSION' ) ) {
			$widget->add_control(
				'gatewaykit_optional_payment_enabled',
				array(
					'label'       => __( 'Optional Payment (donation style)', 'gatewaykit' ),
					'type'        => Controls_Manager::SWITCHER,
					'label_on'    => __( 'Yes', 'gatewaykit' ),
					'label_off'   => __( 'No', 'gatewaykit' ),
					'default'     => '',
					'description' => __( 'Allow the customer to submit the form without making a payment.', 'gatewaykit' ),
					'separator'   => 'before',
				)
			);

			$widget->add_control(
				'gatewaykit_optional_payment_field',
				array(
					'label'       => __( 'Payment opt-in Field ID', 'gatewaykit' ),
					'type'        => Controls_Manager::TEXT,
					'default'     => '',
					'condition'   => array(
						'gatewaykit_optional_payment_enabled' => 'yes',
					),
					'description' => __( 'Short Code (field ID) of a checkbox field. When unchecked the form submits without payment.', 'gatewaykit' ),
				)
			);

			$widget->add_control(
				'gatewaykit_optional_no_payment_message',
				array(
					'label'       => __( 'Message when submitted without payment', 'gatewaykit' ),
					'type'        => Controls_Manager::TEXTAREA,
					'default'     => '',
					'condition'   => array(
						'gatewaykit_optional_payment_enabled' => 'yes',
					),
					'description' => __( 'Shown after a no-payment submission. Leave empty for the default message.', 'gatewaykit' ),
				)
			);
		}

		// --- Failure redirect URL (used when a payment fails). ---
		$widget->add_control(
			'gatewaykit_failure_url',
				array(
					'label'       => __( 'Redirect URL after payment failure', 'gatewaykit' ),
					'type'        => Controls_Manager::URL,
					'default'     => array(
						'url'         => '',
						'is_external' => false,
						'nofollow'    => false,
					),
					'description' => __( 'Page to redirect to when the payment fails. Leave empty to reuse the success page.', 'gatewaykit' ),
					'label_block' => true,
					'separator'   => 'before',
				)
			);

		// --- Partial Payment (Pro only). ---
		if ( defined( 'GATEWAYKIT_PRO_VERSION' ) ) {
			$widget->add_control(
				'gatewaykit_partial_enabled',
				array(
					'label'       => __( 'Partial Payment', 'gatewaykit' ),
					'type'        => Controls_Manager::SWITCHER,
					'label_on'    => __( 'Yes', 'gatewaykit' ),
					'label_off'   => __( 'No', 'gatewaykit' ),
					'default'     => '',
					'description' => __( 'Charge only a percentage of the total amount (e.g. a deposit).', 'gatewaykit' ),
				)
			);

			$widget->add_control(
				'gatewaykit_partial_percent',
				array(
					'label'       => __( 'Partial Payment Percentage', 'gatewaykit' ),
					'type'        => Controls_Manager::NUMBER,
					'default'     => 50,
					'min'         => 1,
					'max'         => 100,
					'condition'   => array(
						'gatewaykit_partial_enabled' => 'yes',
					),
					'description' => __( 'Percentage of the total amount to charge (1-100).', 'gatewaykit' ),
				)
			);
		}

		// --- Subscription controls (Pro only, Stripe only). ---
		if ( defined( 'GATEWAYKIT_PRO_VERSION' ) ) {
			$widget->add_control(
				'gatewaykit_subscription_enabled',
				array(
					'label'       => __( 'Recurring Payment (Stripe Subscription)', 'gatewaykit' ),
					'type'        => Controls_Manager::SWITCHER,
					'label_on'    => __( 'Yes', 'gatewaykit' ),
					'label_off'   => __( 'No', 'gatewaykit' ),
					'default'     => '',
					'description' => __( 'Create a Stripe subscription instead of a one-time payment.', 'gatewaykit' ),
					'separator'   => 'before',
				)
			);

			$widget->add_control(
				'gatewaykit_stripe_price_id',
				array(
					'label'       => __( 'Stripe Price ID', 'gatewaykit' ),
					'type'        => Controls_Manager::TEXT,
					'default'     => '',
					'condition'   => array(
						'gatewaykit_subscription_enabled' => 'yes',
					),
					'description' => __( 'The Stripe Price ID (price_...) for the subscription.', 'gatewaykit' ),
				)
			);

			$widget->add_control(
				'gatewaykit_customer_email_field',
				array(
					'label'       => __( 'Customer Email Field ID', 'gatewaykit' ),
					'type'        => Controls_Manager::TEXT,
					'default'     => '',
					'condition'   => array(
						'gatewaykit_subscription_enabled' => 'yes',
					),
					'description' => __( 'Optional. Short Code (field ID) of an email field mapped to the Stripe customer.', 'gatewaykit' ),
				)
			);
		}

		$widget->end_controls_section();
	}

	/**
	 * Enqueue editor styles
	 */
	public function enqueue_editor_styles() {
		wp_enqueue_style( 'gatewaykit-elementor-editor', GATEWAYKIT_PLUGIN_URL . 'assets/css/elementor-editor.css', array(), GATEWAYKIT_VERSION );
	}

	/**
	 * Run the action
	 *
	 * @param Form_Record $record Form record
	 * @param Ajax_Handler $ajax_handler Ajax handler
	 */
	public function run( $record, $ajax_handler ) {
		try {
			// Critical diagnostic logging for AJAX debugging
			$logger = GatewayKit_Logger::get_instance();
			$logger->debug('AJAX Action Debug - GatewayKit Elementor Action Started', array(
				'post_id' => $record->get_form_meta('post_id') ?: get_the_ID(),
				'is_preview' => is_preview(),
				'wp_doing_ajax' => wp_doing_ajax(),
				'http_referer' => isset( $_SERVER['HTTP_REFERER'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : 'none',
				// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- diagnostic logging only; nonce verified below
				'ajax_action' => isset($_POST['action']) ? sanitize_key(wp_unslash($_POST['action'])) : 'unknown',
				'gatewaykit_action' => isset($_POST['gatewaykit_action']) ? sanitize_key(wp_unslash($_POST['gatewaykit_action'])) : 'none',
				'wpnonce_present' => isset($_POST['_wpnonce']),
				'gatewaykit_nonce_present' => isset($_POST['gatewaykit_nonce']),
				// phpcs:enable
				'request_method' => isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'unknown'
			));

			// Rate limiting check
			$rate_limiter = GatewayKit_Rate_Limiter::get_instance();
			$rate_check   = $rate_limiter->check_rate_limit( 'elementor_action' );
			if ( is_wp_error( $rate_check ) ) {
				$ajax_handler->add_error_message( $rate_check->get_error_message() );
				return;
			}

			// CSRF nonce verification for security
			// @see https://developer.wordpress.org/plugins/security/nonces/
			// @see https://developer.wordpress.org/apis/security/
			$nonce_verified = $this->verify_nonce();
			$nonce_required = get_option( 'gatewaykit_nonce_required', true );

			// Enhanced nonce verification logging
			// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- diagnostic logging only; nonce verified below
			$logger->debug('Nonce Verification Debug', array(
				'nonce_verified' => $nonce_verified,
				'nonce_required' => $nonce_required,
				'nonce_action' => 'gatewaykit_elementor_action',
				'wpnonce_present' => isset($_POST['_wpnonce']),
				'gatewaykit_nonce_present' => isset($_POST['gatewaykit_nonce']),
				'_nonce_present' => isset($_POST['_nonce'])
			));
			// phpcs:enable

			if ( ! $nonce_verified ) {
				$logger->error( 'Nonce verification failed — payment blocked', array(
					'post_id' => $record->get_form_meta( 'post_id' ) ?: get_the_ID(),
				) );
				$ajax_handler->add_error_message( esc_html__( 'Security check failed. Please reload the page and try again.', 'gatewaykit' ) );
				return;
			}

			$settings = $record->get( 'form_settings' );
			$fields   = $record->get( 'fields' );

			// Validate form settings structure
			if ( ! is_array( $settings ) ) {
				GatewayKit_Logger::get_instance()->error(
					'Invalid form settings structure',
					array(
						'settings_type'  => gettype( $settings ),
						'settings_value' => is_string( $settings ) ? substr( $settings, 0, 100 ) : $settings,
						'post_id'        => $record->get_form_meta( 'post_id' ) ?: get_the_ID(),
						'fields_count'   => is_array( $fields ) ? count( $fields ) : 'not_array',
					)
				);
				$ajax_handler->add_error_message( esc_html__( 'Form configuration error. Please refresh the page and try again.', 'gatewaykit' ) );
				return;
			}

			// Sanitize form fields using centralized validator
			// @see https://developer.wordpress.org/plugins/security/securing-input/
			$validator = GatewayKit_Input_Validator::get_instance();
			$fields    = $validator->validate_form_data( $fields );
			if ( is_wp_error( $fields ) ) {
				$ajax_handler->add_error_message( $fields->get_error_message() );
				return;
			}

			// Validate gateway using centralized validator
			if ( ! isset( $settings['gatewaykit_gateway'] ) ) {
				GatewayKit_Logger::get_instance()->error( 'Missing gateway setting', array( 'settings' => $settings ) );
				$ajax_handler->add_error_message( esc_html__( 'Payment gateway not selected.', 'gatewaykit' ) );
				return;
			}
			$gateway = $validator->validate_gateway( $settings['gatewaykit_gateway'] );
			if ( is_wp_error( $gateway ) ) {
				$ajax_handler->add_error_message( $gateway->get_error_message() );
				return;
			}
			$settings['gatewaykit_gateway'] = $gateway;

			// Validate success URL using centralized validator
			if ( ! isset( $settings['gatewaykit_success_url']['url'] ) ) {
				GatewayKit_Logger::get_instance()->error( 'Missing success URL setting', array( 'settings' => $settings ) );
				$ajax_handler->add_error_message( esc_html__( 'Success URL not configured.', 'gatewaykit' ) );
				return;
			}
			$success_url = $validator->validate_required_url( $settings['gatewaykit_success_url']['url'] );
			if ( is_wp_error( $success_url ) ) {
				$ajax_handler->add_error_message( $success_url->get_error_message() );
				return;
			}
			$settings['gatewaykit_success_url']['url'] = $success_url;

			// Sanitize description
			$settings['gatewaykit_description'] = isset( $settings['gatewaykit_description'] ) ? sanitize_text_field( $settings['gatewaykit_description'] ) : __( 'Payment for services', 'gatewaykit' );

			// Log form settings for debugging
			GatewayKit_Logger::get_instance()->info(
				'Payment form settings',
				array(
					'redirect_url' => $settings['gatewaykit_success_url']['url'],
					'post_id'      => $record->get_form_meta( 'post_id' ) ?: get_the_ID(),
				)
			);

			// Calculate amount with validation
			$amount = $this->calculate_amount( $settings, $fields );
			if ( is_wp_error( $amount ) ) {
				$ajax_handler->add_error_message( $amount->get_error_message() );
				return;
			}

			// --- Optional Payment (Pro, donation-style). ---
			// When enabled and the opt-in checkbox is unchecked, skip the
			// gateway entirely: record a completed transaction with amount 0,
			// show the admin-configured message, and return.
			$optional_enabled = false;
			$wants_payment    = true;
			if ( defined( 'GATEWAYKIT_PRO_VERSION' ) && ! empty( $settings['gatewaykit_optional_payment_enabled'] ) && 'yes' === $settings['gatewaykit_optional_payment_enabled'] ) {
				$optional_enabled = true;
				$opt_field        = sanitize_key( $settings['gatewaykit_optional_payment_field'] ?? '' );
				$opt_val          = ( $opt_field && isset( $fields[ $opt_field ]['value'] ) ) ? strtolower( trim( (string) $fields[ $opt_field ]['value'] ) ) : '';
				$wants_payment    = in_array( $opt_val, array( 'on', 'true', '1', 'yes', 'checked' ), true );
			}

			if ( $optional_enabled && ! $wants_payment ) {
				// Skip discount logic — no charge means no discount to apply.
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
					'post_id'         => $record->get_form_meta( 'post_id' ) ?: get_the_ID(),
				);

				$transaction = GatewayKit_Transaction_Model::create( $payment_data );
				if ( is_wp_error( $transaction ) ) {
					$ajax_handler->add_error_message( $transaction->get_error_message() );
					return;
				}

				$transaction->update( array(
					'status'       => 'completed',
					'ref_id'       => 'NO_PAYMENT',
					'completed_at' => current_time( 'mysql' ),
				) );

				do_action( 'gatewaykit_payment_completed', $transaction );

				$msg = ! empty( $settings['gatewaykit_optional_no_payment_message'] )
					? wp_kses_post( $settings['gatewaykit_optional_no_payment_message'] )
					: __( 'Thank you. Your submission was received.', 'gatewaykit' );

				$ajax_handler->add_response_data( 'gatewaykit_no_payment', true );
				$ajax_handler->add_response_data( 'gatewaykit_no_payment_message', $msg );
				$ajax_handler->add_response_data( 'success_message', $msg );
				return;
			}

		// --- Pro: partial payment (charge a percentage of the total). ---
		// Applies to the pre-discount base amount; the discount is then
		// applied on top of the partial amount.
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
			$amount = round( (float) $amount * ( $percent / 100 ), 2 );
		}

		// --- Discount handling ---
		// A discount code is processed whenever one is present in the
		// submitted form data — no toggle required.  The dedicated Discount
		// Code field has its own Apply button with live AJAX validation, so
		// the admin simply adds the field to the form and the server honours
		// any valid code automatically.
		$discount_applied  = false;
		$discount_id       = 0;
		$discount_amount   = 0.0;
		$discount_original = (float) $amount;

		if ( class_exists( 'GatewayKit_Discount_Model' ) ) {
				// Read the discount code directly from the raw POST data.
				// We cannot rely solely on $record->get('fields') because
				// Elementor Pro's Form_Record only populates fields that are
				// registered in the form's field list — and the hidden input
				// injected by frontend JS (_gatewaykit_discount_code) is not a
				// registered Elementor field, so it gets stripped.
			// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce already verified above; reading form field value only.
			$discount_code = isset( $_POST['form_fields']['_gatewaykit_discount_code'] )
				? sanitize_text_field( wp_unslash( $_POST['form_fields']['_gatewaykit_discount_code'] ) )
				: '';
			// phpcs:enable WordPress.Security.NonceVerification.Missing

				// Fallback: scan $fields (Elementor's processed list) for the
				// custom discount field type or any configured legacy field.
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

				if ( '' !== $discount_code ) {
					$current_user_id = is_user_logged_in() ? get_current_user_id() : 0;

					// Validate the code against the cart amount.
					$valid = GatewayKit_Discount_Model::validate_code( $discount_code, $amount, $current_user_id );
					if ( is_wp_error( $valid ) ) {
						$ajax_handler->add_error_message( $valid->get_error_message() );
						return;
					}

					// Calculate the discount and the final charge amount.
					$calc = GatewayKit_Discount_Model::calculate_discount( $discount_code, $amount );
					if ( is_wp_error( $calc ) ) {
						$ajax_handler->add_error_message( $calc->get_error_message() );
						return;
					}

					$discount_obj = GatewayKit_Discount_Model::get_by_code( $discount_code );
					if ( ! $discount_obj ) {
						$ajax_handler->add_error_message( esc_html__( 'Invalid discount code.', 'gatewaykit' ) );
						return;
					}

					$discount_id     = (int) $discount_obj->get_data( 'id' );
					$discount_amount = (float) $calc['discount_amount'];

					// Race-safe reservation: increment the atomic counter before
					// initiating payment so concurrent submissions cannot exceed
					// the global limit. Rolled back via decrement_usage() on failure.
					if ( ! GatewayKit_Discount_Model::increment_usage( $discount_id ) ) {
						$ajax_handler->add_error_message( esc_html__( 'This discount code has reached its usage limit.', 'gatewaykit' ) );
						return;
					}
					$discount_applied = true;

					// Charge the discounted (final) amount, clamped to >= 0.
					$amount = (float) $calc['final_amount'];
					if ( $amount < 0 ) {
						$amount = 0;
					}

					// NOTE: a fully-free order (e.g. a 100%-off code) is now
					// completed without a gateway charge — handled below after
					// the payment data is assembled. Do not error out here.
				}
			}

		// Prepare payment data
		$user_data = $this->get_user_data();

		// --- Email receipt data (Lite). ---
		if ( ! empty( $settings['gatewaykit_email_receipt_enabled'] ) && 'yes' === $settings['gatewaykit_email_receipt_enabled'] ) {
			$email_field_id = isset( $settings['gatewaykit_email_field'] ) ? sanitize_key( $settings['gatewaykit_email_field'] ) : '';
			$receipt_email  = '';
			if ( $email_field_id && isset( $fields[ $email_field_id ]['value'] ) ) {
				$receipt_email = sanitize_email( $fields[ $email_field_id ]['value'] );
			}
			if ( '' === $receipt_email && is_user_logged_in() ) {
				$current_user = wp_get_current_user();
				$receipt_email = $current_user->user_email;
			}
			$user_data['email_receipt_enabled'] = 'yes';
			$user_data['email_receipt_to']      = $receipt_email;
		}

		// --- Subscription fields (Pro only, Stripe). ---
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

		// --- Pro: separate failure redirect page. ---
		$failure_url = '';
		if ( isset( $settings['gatewaykit_failure_url']['url'] ) && '' !== $settings['gatewaykit_failure_url']['url'] ) {
			$failure_url = $validator->validate_url( $settings['gatewaykit_failure_url']['url'] );
			if ( is_wp_error( $failure_url ) ) {
				$failure_url = '';
			}
		}

		$payment_data = array(
			'gateway'         => $settings['gatewaykit_gateway'],
			'amount'          => $amount,
			'discount_id'     => $discount_id > 0 ? $discount_id : null,
			'discount_amount' => $discount_amount,
			'description'     => ! empty( $settings['gatewaykit_description'] ) ? $settings['gatewaykit_description'] : __( 'Payment for services', 'gatewaykit' ),
			'form_data'    => $fields,
			'user_data'    => $user_data,
			'user_id'      => is_user_logged_in() ? get_current_user_id() : null,
			'callback_url' => str_replace( '{gateway}', $settings['gatewaykit_gateway'], $this->get_callback_url() ),
			'success_url'  => ! empty( $settings['gatewaykit_success_url']['url'] ) ? $settings['gatewaykit_success_url']['url'] : '',
			'failure_url'  => $failure_url,
			'form_id'      => $record->get_form_meta( 'id' ),
			'post_id'      => $record->get_form_meta( 'post_id' ) ?: get_the_ID(),
		);

		// --- Fully-free order (e.g. a 100%-off discount code). ---
		// No gateway charge is required: record a completed transaction
		// directly, commit the discount, and route to the success/receipt page.
		// This works regardless of the selected gateway, in Pro.
		if ( (float) $amount <= 0 ) {
			$payment_data['amount'] = 0;

			$transaction = GatewayKit_Transaction_Model::create( $payment_data );
			if ( is_wp_error( $transaction ) ) {
				// Roll back the reserved discount usage, if any.
				if ( $discount_applied ) {
					GatewayKit_Discount_Model::decrement_usage( $discount_id );
				}
				$ajax_handler->add_error_message( $transaction->get_error_message() );
				return;
			}

			// Mark the order complete immediately (no payment to capture).
			$transaction->update( array(
				'status'       => 'completed',
				'ref_id'       => 'FREE',
				'completed_at' => current_time( 'mysql' ),
			) );

			// Commit the reserved discount usage.
			if ( $discount_applied ) {
				$recorded = GatewayKit_Discount_Usage_Model::record_usage( array(
					'discount_id'     => $discount_id,
					'transaction_id'  => (int) $transaction->get( 'id' ),
					'user_id'         => is_user_logged_in() ? get_current_user_id() : null,
					'amount_saved'    => $discount_amount,
					'original_amount' => $discount_original,
					'final_amount'    => 0.0,
					'ip_address'      => GatewayKit_IP_Helper::get_client_ip(),
				) );
				if ( is_wp_error( $recorded ) ) {
					GatewayKit_Logger::get_instance()->error(
						'Discount usage audit insert failed (free order); counter kept incremented',
						array(
							'discount_id'    => $discount_id,
							'transaction_id' => (int) $transaction->get( 'id' ),
							'error'          => $recorded->get_error_message(),
						)
					);
				}
			}

			do_action( 'gatewaykit_payment_completed', $transaction );

// Route to the success/receipt page, or show an inline confirmation
			// when no success URL is configured.
			$success_url = $payment_data['success_url'];
			if ( $success_url ) {
				$redirect_url = add_query_arg( 'gatewaykit_receipt', $transaction->get( 'receipt_token' ), $success_url );
				$ajax_handler->add_response_data( 'redirect_url', $redirect_url );
			} else {
				$ajax_handler->add_response_data( 'success_message', esc_html__( 'Your discount covered the full amount — no payment required. Your order is complete.', 'gatewaykit' ) );
			}
			return;
		}

		// Validate payment data. Discounted orders bypass the amount floor
			// since the final total may legitimately fall below it.
			$security_manager = GatewayKit_Security_Manager::get_instance();
			$validated_data   = $security_manager->validate_payment_data( $payment_data, array( 'discounted' => $discount_applied ) );

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

			// Process payment
			$result = $this->process_payment( $validated_data );
	
			if ( is_wp_error( $result ) ) {
				// Roll back the reserved discount usage on failure.
				if ( $discount_applied ) {
					GatewayKit_Discount_Model::decrement_usage( $discount_id );
				}

				// Use error handler to get appropriate error message
				$error_handler = GatewayKit_Error_Handler::get_instance();
				
				// Check if this is a gateway error with detailed information
				$error_data = $result->get_error_data();
				if (!empty($error_data) && isset($error_data['error_type'])) {
					// This is a gateway error with detailed information
					$error_message = $error_handler->get_error_message(
						$error_data['error_type'],
						$error_data['error_code'],
						false, // Not admin context
						$error_data['error_details']
					);
					$ajax_handler->add_error_message($error_message);
				} else {
					// Fallback to original error message
					$ajax_handler->add_error_message($result->get_error_message());
				}
				return;
			}

			// Handle success
			if ( $result['status'] === 'success' && ! empty( $result['redirect_url'] ) ) {
				// Commit the reserved discount usage now that payment was initiated.
				if ( $discount_applied ) {
					if ( empty( $result['transaction_id'] ) ) {
						// No transaction was created — release the reserved slot.
						GatewayKit_Discount_Model::decrement_usage( $discount_id );
						GatewayKit_Logger::get_instance()->error(
							'Discount commit skipped: payment succeeded without a transaction id',
							array( 'discount_id' => $discount_id )
						);
					} else {
						$recorded = GatewayKit_Discount_Usage_Model::record_usage( array(
							'discount_id'     => $discount_id,
							'transaction_id'  => (int) $result['transaction_id'],
							'user_id'         => is_user_logged_in() ? get_current_user_id() : null,
							'amount_saved'    => $discount_amount,
							'original_amount' => $discount_original,
							'final_amount'    => (float) $amount,
							'ip_address'      => GatewayKit_IP_Helper::get_client_ip(),
						) );
						if ( is_wp_error( $recorded ) ) {
							// The audit row failed to insert, but the payment
							// already succeeded and the discount was genuinely
							// consumed — keep used_count incremented so it stays
							// accurate vs. actual charges. Surface the mismatch
							// for manual reconciliation / deferred retry.
							GatewayKit_Logger::get_instance()->error(
								'Discount usage audit insert failed; counter kept incremented for reconciliation',
								array(
									'discount_id'    => $discount_id,
									'transaction_id' => (int) $result['transaction_id'],
									'error'          => $recorded->get_error_message(),
								)
							);
						}
					}
				}
				$ajax_handler->add_response_data( 'redirect_url', $result['redirect_url'] );
			} else {
				// Gateway returned success but no redirect URL — rollback and fail.
				if ( $discount_applied && ! empty( $discount_id ) ) {
					GatewayKit_Discount_Model::decrement_usage( $discount_id );
				}
				if ( ! empty( $result['transaction_id'] ) ) {
					GatewayKit_Transaction_Model::find( $result['transaction_id'] )?->update( array( 'status' => 'failed' ) );
				}
				$ajax_handler->add_error_message( esc_html__( 'Payment gateway did not return a redirect URL. Please try again.', 'gatewaykit' ) );
				return;
			}
		} catch ( \Throwable $e ) {
			$error_handler = GatewayKit_Error_Handler::get_instance();
			
			// Log the error with error handler
			$error_handler->log_error('network', 'exception', array(
				'error'         => $e->getMessage(),
				'trace'         => $e->getTraceAsString(),
				'post_id'       => $record->get_form_meta( 'post_id' ) ?: get_the_ID(),
				'settings_type' => isset( $settings ) ? gettype( $settings ) : 'null',
			), 'Payment action exception' );
			
			// Get appropriate error message
			$error_message = $error_handler->get_error_message('network', 'exception', false);
			$ajax_handler->add_error_message($error_message);
		}
	}

	/**
	 * Calculate payment amount
	 *
	 * @param array $settings Form settings
	 * @param array $fields   Form fields
	 * @return float|WP_Error Amount or error
	 */
	private function calculate_amount( $settings, $fields ) {
		$validator = GatewayKit_Input_Validator::get_instance();

		if ( $settings['gatewaykit_amount_type'] === 'fixed' ) {
			$amount = ! empty( $settings['gatewaykit_amount'] ) ? $settings['gatewaykit_amount'] : 0;
		} else {
			$field_id = ! empty( $settings['gatewaykit_amount_field'] ) ? sanitize_key( $settings['gatewaykit_amount_field'] ) : '';
			if ( empty( $field_id ) || ! isset( $fields[ $field_id ] ) ) {
				return new WP_Error( 'amount_field_missing', esc_html__( 'Amount field not found.', 'gatewaykit' ) );
			}
			$amount = $fields[ $field_id ]['value'];
		}

		// Validate amount using centralized validator
		// @see https://developer.wordpress.org/plugins/security/securing-input/
		$amount = $validator->validate_amount( $amount );
		if ( is_wp_error( $amount ) ) {
			return $amount; // Always enforce validation
		}

		return $amount;
	}

	/**
	 * Get user data
	 *
	 * @return array User data
	 */
	private function get_user_data() {
		$user_data = array();

		if ( is_user_logged_in() && get_current_user_id() ) {
			$user      = wp_get_current_user();
			$user_data = array(
				'id'    => $user->ID,
				'email' => $user->user_email,
				'name'  => $user->display_name,
			);
		}

		$user_data['ip']         = GatewayKit_IP_Helper::get_client_ip();
		$user_data['user_agent'] = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) );

		return $user_data;
	}

	/**
	 * Get callback URL
	 *
	 * @return string Callback URL
	 */
	private function get_callback_url() {
		return add_query_arg(
			array(
				'gatewaykit_callback' => '1',
				'gateway'          => '{gateway}',
			),
			home_url( '/payment-callback/' )
		);
	}

	/**
	 * Process payment
	 *
	 * @param array $payment_data Payment data
	 * @return array|WP_Error Result or error
	 */
	private function process_payment( $payment_data ) {
		try {
			// Create transaction record
			$transaction = GatewayKit_Transaction_Model::create( $payment_data );

			if ( is_wp_error( $transaction ) ) {
				return $transaction;
			}

			// Get gateway instance
			$gateway_manager = GatewayKit_Gateway_Manager::get_instance();
			$gateway         = $gateway_manager->get_gateway( $payment_data['gateway'] );

			if ( ! $gateway ) {
				return new WP_Error( 'gateway_not_found', esc_html__( 'Payment gateway not found.', 'gatewaykit' ) );
			}

			// Double-check that the gateway is enabled and available
			$enabled_gateways = get_option( 'gatewaykit_enabled_gateways', array() );
			if ( ! isset( $enabled_gateways[ $payment_data['gateway'] ] ) || $enabled_gateways[ $payment_data['gateway'] ] !== '1' ) {
				return new WP_Error( 'gateway_disabled', esc_html__( 'Selected payment gateway is not enabled.', 'gatewaykit' ) );
			}

			if ( ! $gateway->is_available() ) {
				return new WP_Error( 'gateway_unavailable', esc_html__( 'Selected payment gateway is not properly configured.', 'gatewaykit' ) );
			}

		// Make the transaction receipt token available to gateways so that
		// redirect-based flows (e.g. Stripe Checkout / PayPal / CoinGate
		// cancellation) can embed it in their return URLs and let the callback
		// handler resolve the order on cancel without an authority.
		$user_data = $payment_data['user_data'];
		if ( ! is_array( $user_data ) ) {
			$user_data = array();
		}
		$user_data['receipt_token'] = $transaction->get( 'receipt_token' );

		$result = $gateway->process_payment(
			$payment_data['amount'],
			$payment_data['description'],
			$payment_data['callback_url'],
			$user_data
		);

			if ( isset( $result['authority'] ) ) {
				// Update transaction with authority
				$transaction->update( array( 'authority' => $result['authority'] ) );

				// Get redirect URL
				$redirect_url = $gateway->get_redirect_url( $result['authority'] );

				return array(
					'status'         => 'success',
					'redirect_url'   => $redirect_url,
					'authority'      => $result['authority'],
					'transaction_id' => (int) $transaction->get( 'id' ),
				);
			}

			// Handle payment failure - store error details in transaction
			if ( isset( $result['status'] ) && $result['status'] === 'error' ) {
				$transaction->update( array(
					'status' => 'failed',
				) );

				// Store error details if available
				if ( isset( $result['error_message'] ) || isset( $result['error_type'] ) ) {
					$transaction->set_error(
						$result['error_message'] ?? $result['message'] ?? 'Payment failed',
						$result['error_code'] ?? '',
						$result['error_type'] ?? 'unknown',
						$result['error_details'] ?? null
					);
				}

				// Use error handler to create proper WP_Error with preserved details
				$error_handler = GatewayKit_Error_Handler::get_instance();
				return $error_handler->create_wp_error_from_gateway($result, false);
			}

			return new WP_Error( 'payment_failed', esc_html__( 'Payment initiation failed. Please check your payment details and try again.', 'gatewaykit' ) );

		} catch ( \Throwable $e ) {
			GatewayKit_Logger::get_instance()->error( 'Payment processing error', array( 'error' => $e->getMessage() ) );
			return new WP_Error( 'payment_error', esc_html__( 'An error occurred during payment processing.', 'gatewaykit' ) );
		}
	}

	/**
	 * Verify CSRF nonce for form submission.
	 *
	 * Elementor Pro may deliver the nonce under different field names and minted
	 * against different actions depending on its version. To remain compatible
	 * we accept any combination of known field names and known actions. When
	 * nothing verifies we log a detailed diagnostic at error level so the exact
	 * cause (which field present, which actions failed) is surfaced.
	 *
	 * @return bool True if a valid nonce matched, false otherwise
	 */
	private function verify_nonce() {
		$logger = GatewayKit_Logger::get_instance();

		// Field names Elementor / WordPress may use to carry the nonce.
		$nonce_fields  = array( '_wpnonce', 'nonce', 'gatewaykit_nonce', '_ajax_nonce', '_nonce' );
		// Actions the nonce may have been minted against.
		$nonce_actions = array( 'elementor_ajax', 'elementor-pro-forms', 'elementor_pro_forms_send_form' );

		$fields_present = array();
		$results        = array();

		foreach ( $nonce_fields as $field ) {
			if ( ! isset( $_POST[ $field ] ) ) {
				continue;
			}
			$fields_present[] = $field;
			$value = sanitize_text_field( wp_unslash( $_POST[ $field ] ) );

			foreach ( $nonce_actions as $action ) {
				$valid = (bool) wp_verify_nonce( $value, $action );
				$results[ $field . ' / ' . $action ] = $valid;
				if ( $valid ) {
					$logger->info( 'Nonce verification succeeded', array(
						'field'  => $field,
						'action' => $action,
					) );
					return true;
				}
			}
		}

		// Nothing matched - surface a detailed diagnostic at error level.
		$logger->error( 'Nonce verification failed - no valid nonce matched', array(
			'fields_checked' => $nonce_fields,
			'fields_present' => $fields_present,
			'results'        => $results,
			'ajax_action'    => isset( $_POST['action'] ) ? sanitize_text_field( wp_unslash( $_POST['action'] ) ) : 'unknown',
			'post_id'        => isset( $_POST['post_id'] ) ? sanitize_text_field( wp_unslash( $_POST['post_id'] ) ) : 'unknown',
		) );

		return false;
	}

	/**
	 * Get available gateways for select control
	 *
	 * @return array Gateway options
	 */
	private function get_available_gateways() {
		$gateway_manager = GatewayKit_Gateway_Manager::get_instance();
		$gateways        = $gateway_manager->get_available_gateways();

		$options = array( '' => __( 'Select Payment Gateway', 'gatewaykit' ) );

		foreach ( $gateways as $id => $gateway ) {
			$options[ $id ] = $gateway->get_gateway_name();
		}

		return $options;
	}

	/**
	 * Export action settings
	 *
	 * @param array $element
	 * @return array
	 */
	public function on_export( $element ) {
		return $element;
	}

	/**
	 * Collect all Elementor forms on the current page that have the optional
	 * payment feature enabled (Pro only).
	 *
	 * Used to decide whether to enqueue the gatewaykit-pro-forms frontend assets.
	 *
	 * @return array {
	 *     @type array {
	 *         @type string $form_id      Elementor form ID.
	 *         @type string $opt_field    Checkbox field ID for the opt-in.
	 *         @type string $no_payment_message Custom message for no-payment submissions.
	 *     }
	 * }
	 */
	public static function get_optional_payment_forms_for_current_page() {
		if ( ! class_exists( '\Elementor\Plugin' ) || ! is_singular() || ! defined( 'GATEWAYKIT_PRO_VERSION' ) ) {
			return array();
		}

		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return array();
		}

		$document = \Elementor\Plugin::$instance->documents->get_doc_for_frontend( $post_id );
		if ( ! $document ) {
			return array();
		}

		$elements = $document->get_elements_data();
		$forms    = array();
		self::collect_optional_payment_forms( $elements, $forms );

		return $forms;
	}

	/**
	 * Recursively walk Elementor elements data and collect optional-payment forms.
	 *
	 * @param array $elements Elementor elements tree.
	 * @param array $forms    Collected forms (passed by reference).
	 */
	private static function collect_optional_payment_forms( $elements, &$forms ) {
		if ( ! is_array( $elements ) ) {
			return;
		}

		foreach ( $elements as $element ) {
			$is_form_widget = isset( $element['elType'] )
				&& 'widget' === $element['elType']
				&& isset( $element['widgetType'] )
				&& 'form' === $element['widgetType'];

			if ( $is_form_widget && defined( 'GATEWAYKIT_PRO_VERSION' ) ) {
				$settings    = isset( $element['settings'] ) ? $element['settings'] : array();
				$optional_on = isset( $settings['gatewaykit_optional_payment_enabled'] ) && 'yes' === $settings['gatewaykit_optional_payment_enabled'];

				// Detect payment gateway action by the presence of its
				// settings key, which is more reliable than checking
				// submit_actions.
				$has_payment = isset( $settings['gatewaykit_gateway'] );

				if ( $has_payment && $optional_on ) {
					$forms[] = array(
						'form_id'              => isset( $settings['form_id'] ) ? sanitize_key( $settings['form_id'] ) : '',
						'opt_field'            => isset( $settings['gatewaykit_optional_payment_field'] ) ? sanitize_key( $settings['gatewaykit_optional_payment_field'] ) : '',
						'no_payment_message'   => isset( $settings['gatewaykit_optional_no_payment_message'] ) ? wp_kses_post( $settings['gatewaykit_optional_no_payment_message'] ) : '',
					);
				}
			}

			if ( ! empty( $element['elements'] ) ) {
				self::collect_optional_payment_forms( $element['elements'], $forms );
			}
		}
	}

	/**
	 * Import action settings
	 *
	 * @param array $settings
	 * @param array $element
	 * @return array
	 */
	public function on_import( $settings, $element ) {
		return $settings;
	}
}
