<?php
/**
 * GatewayKit Elementor Form Controls
 *
 * Registers the Elementor form settings section and controls for GatewayKit.
 *
 * @package GatewayKit
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Elementor\Controls_Manager;

/**
 * Class GatewayKit_Elementor_Form_Controls
 */
class GatewayKit_Elementor_Form_Controls {

	/**
	 * Get available gateways for select control.
	 *
	 * @return array Gateway options.
	 */
	public static function get_available_gateways() {
		$gateway_manager = GatewayKit_Gateway_Manager::get_instance();
		$gateways        = $gateway_manager->get_available_gateways();
		$options         = array( '' => __( 'Select Gateway', 'gatewaykit' ) );

		foreach ( $gateways as $id => $gateway ) {
			if ( is_object( $gateway ) && method_exists( $gateway, 'get_gateway_name' ) ) {
				$options[ $id ] = $gateway->get_gateway_name();
			} elseif ( is_array( $gateway ) && isset( $gateway['title'] ) ) {
				$options[ $id ] = $gateway['title'];
			} else {
				$options[ $id ] = ucfirst( (string) $id );
			}
		}

		return $options;
	}

	/**
	 * Register GatewayKit settings section in Elementor Form widget.
	 *
	 * @param object $widget Elementor form widget instance.
	 * @param object $action GatewayKit Elementor action instance.
	 */
	public static function register_settings_section( $widget, $action ) {
		$action_name = is_object( $action ) && method_exists( $action, 'get_name' ) ? $action->get_name() : 'payment_gateway';

		$widget->start_controls_section(
			'section_gatewaykit_payment_gateway',
			array(
				'label'     => __( 'Payment Gateway', 'gatewaykit' ),
				'condition' => array(
					'submit_actions' => $action_name,
				),
			)
		);

		$widget->add_control(
			'gatewaykit_gateway',
			array(
				'label'       => __( 'Payment Gateway', 'gatewaykit' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => self::get_available_gateways(),
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
}
