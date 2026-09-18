<?php
/**
 * GatewayKit Form Templates.
 *
 * Pre-configured starter templates for payment forms.
 *
 * @package GatewayKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GatewayKit_Form_Templates
 *
 * Provides starter configurations for common payment scenarios.
 */
class GatewayKit_Form_Templates {

	/**
	 * Get all registered form templates.
	 *
	 * @return array Array of template definitions.
	 */
	public static function get_templates() {
		return array(
			'blank'    => array(
				'id'          => 'blank',
				'title'       => __( 'Blank Form', 'gatewaykit' ),
				'description' => __( 'A clean canvas with amount and required email fields.', 'gatewaykit' ),
				'icon'        => 'dashicons-media-default',
				'config'      => array(
					'amount_mode'           => 'fixed',
					'fixed_amount'          => 10.00,
					'min_amount'            => 1.00,
					'max_amount'            => 0.00,
					'donation_presets'      => '10, 25, 50, 100',
					'allow_custom_donation' => 1,
					'description'           => '',
					'submit_label'          => __( 'Pay Now', 'gatewaykit' ),
					'success_url'           => '',
					'gateways'              => array(),
					'fields'                => array(
						array(
							'id'          => 'f_email',
							'type'        => 'email',
							'label'       => __( 'Email Address', 'gatewaykit' ),
							'placeholder' => __( 'jane@example.com', 'gatewaykit' ),
							'required'    => 1,
							'help_text'   => '',
							'width'       => '100',
							'options'     => array(),
							'default'     => '',
						),
					),
					'terms_text'            => '',
				),
			),
			'donation' => array(
				'id'          => 'donation',
				'title'       => __( 'Donation Campaign', 'gatewaykit' ),
				'description' => __( 'Accept donations with preset amounts and optional custom gift.', 'gatewaykit' ),
				'icon'        => 'dashicons-heart',
				'config'      => array(
					'amount_mode'           => 'donation',
					'fixed_amount'          => 25.00,
					'min_amount'            => 5.00,
					'max_amount'            => 0.00,
					'donation_presets'      => '10, 25, 50, 100',
					'allow_custom_donation' => 1,
					'description'           => __( 'Support our project with a secure donation.', 'gatewaykit' ),
					'submit_label'          => __( 'Donate Now', 'gatewaykit' ),
					'success_url'           => '',
					'gateways'              => array(),
					'fields'                => array(
						array(
							'id'          => 'f_name',
							'type'        => 'text',
							'label'       => __( 'Full Name', 'gatewaykit' ),
							'placeholder' => __( 'Jane Doe', 'gatewaykit' ),
							'required'    => 1,
							'help_text'   => '',
							'width'       => '100',
							'options'     => array(),
							'default'     => '',
						),
						array(
							'id'          => 'f_email',
							'type'        => 'email',
							'label'       => __( 'Email Address', 'gatewaykit' ),
							'placeholder' => __( 'jane@example.com', 'gatewaykit' ),
							'required'    => 1,
							'help_text'   => '',
							'width'       => '100',
							'options'     => array(),
							'default'     => '',
						),
						array(
							'id'          => 'f_phone',
							'type'        => 'phone',
							'label'       => __( 'Phone Number', 'gatewaykit' ),
							'placeholder' => '',
							'required'    => 0,
							'help_text'   => '',
							'width'       => '100',
							'options'     => array(),
							'default'     => '',
						),
						array(
							'id'          => 'f_message',
							'type'        => 'textarea',
							'label'       => __( 'Donor Note / Message', 'gatewaykit' ),
							'placeholder' => __( 'Leave a message of encouragement...', 'gatewaykit' ),
							'required'    => 0,
							'help_text'   => '',
							'width'       => '100',
							'options'     => array(),
							'default'     => '',
						),
					),
					'terms_text'            => '',
				),
			),
			'product'  => array(
				'id'          => 'product',
				'title'       => __( 'Single Product / Service', 'gatewaykit' ),
				'description' => __( 'Sell a single item or service with fixed pricing.', 'gatewaykit' ),
				'icon'        => 'dashicons-cart',
				'config'      => array(
					'amount_mode'           => 'fixed',
					'fixed_amount'          => 25.00,
					'min_amount'            => 1.00,
					'max_amount'            => 0.00,
					'donation_presets'      => '10, 25, 50, 100',
					'allow_custom_donation' => 1,
					'description'           => __( 'Simple checkout form for services or products.', 'gatewaykit' ),
					'submit_label'          => __( 'Pay Now', 'gatewaykit' ),
					'success_url'           => '',
					'gateways'              => array(),
					'fields'                => array(
						array(
							'id'          => 'f_name',
							'type'        => 'text',
							'label'       => __( 'Full Name', 'gatewaykit' ),
							'placeholder' => __( 'Jane Doe', 'gatewaykit' ),
							'required'    => 1,
							'help_text'   => '',
							'width'       => '100',
							'options'     => array(),
							'default'     => '',
						),
						array(
							'id'          => 'f_email',
							'type'        => 'email',
							'label'       => __( 'Email Address', 'gatewaykit' ),
							'placeholder' => __( 'jane@example.com', 'gatewaykit' ),
							'required'    => 1,
							'help_text'   => '',
							'width'       => '100',
							'options'     => array(),
							'default'     => '',
						),
						array(
							'id'          => 'f_phone',
							'type'        => 'phone',
							'label'       => __( 'Phone Number', 'gatewaykit' ),
							'placeholder' => '',
							'required'    => 0,
							'help_text'   => '',
							'width'       => '50',
							'options'     => array(),
							'default'     => '',
						),
						array(
							'id'          => 'f_notes',
							'type'        => 'textarea',
							'label'       => __( 'Order Notes', 'gatewaykit' ),
							'placeholder' => __( 'Special delivery or handling instructions...', 'gatewaykit' ),
							'required'    => 0,
							'help_text'   => '',
							'width'       => '100',
							'options'     => array(),
							'default'     => '',
						),
						array(
							'id'          => 'f_terms',
							'type'        => 'checkbox',
							'label'       => __( 'I agree to the terms of service.', 'gatewaykit' ),
							'placeholder' => '',
							'required'    => 1,
							'help_text'   => '',
							'width'       => '100',
							'options'     => array(),
							'default'     => '',
						),
					),
					'terms_text'            => __( 'I agree to the terms of service.', 'gatewaykit' ),
				),
			),
			'invoice'  => array(
				'id'          => 'invoice',
				'title'       => __( 'Invoice Payment', 'gatewaykit' ),
				'description' => __( 'Allow clients to pay an open invoice with an invoice number and custom amount.', 'gatewaykit' ),
				'icon'        => 'dashicons-media-spreadsheet',
				'config'      => array(
					'amount_mode'           => 'custom',
					'fixed_amount'          => 25.00,
					'min_amount'            => 1.00,
					'max_amount'            => 0.00,
					'donation_presets'      => '10, 25, 50, 100',
					'allow_custom_donation' => 1,
					'description'           => __( 'Pay an outstanding invoice or consulting fee.', 'gatewaykit' ),
					'submit_label'          => __( 'Pay Invoice', 'gatewaykit' ),
					'success_url'           => '',
					'gateways'              => array(),
					'fields'                => array(
						array(
							'id'          => 'f_name',
							'type'        => 'text',
							'label'       => __( 'Client / Company Name', 'gatewaykit' ),
							'placeholder' => __( 'Acme Corp', 'gatewaykit' ),
							'required'    => 1,
							'help_text'   => '',
							'width'       => '50',
							'options'     => array(),
							'default'     => '',
						),
						array(
							'id'          => 'f_email',
							'type'        => 'email',
							'label'       => __( 'Billing Email', 'gatewaykit' ),
							'placeholder' => __( 'billing@acme.com', 'gatewaykit' ),
							'required'    => 1,
							'help_text'   => '',
							'width'       => '50',
							'options'     => array(),
							'default'     => '',
						),
						array(
							'id'          => 'f_invoice_num',
							'type'        => 'text',
							'label'       => __( 'Invoice Number', 'gatewaykit' ),
							'placeholder' => __( 'INV-1001', 'gatewaykit' ),
							'required'    => 1,
							'help_text'   => '',
							'width'       => '100',
							'options'     => array(),
							'default'     => '',
						),
						array(
							'id'          => 'f_notes',
							'type'        => 'textarea',
							'label'       => __( 'Notes (Optional)', 'gatewaykit' ),
							'placeholder' => '',
							'required'    => 0,
							'help_text'   => '',
							'width'       => '100',
							'options'     => array(),
							'default'     => '',
						),
					),
					'terms_text'            => '',
				),
			),
			'event'    => array(
				'id'          => 'event',
				'title'       => __( 'Event Registration & Tickets', 'gatewaykit' ),
				'description' => __( 'Sell tickets or accept paid registrations for events, conferences, or webinars.', 'gatewaykit' ),
				'icon'        => 'dashicons-tickets-alt',
				'config'      => array(
					'amount_mode'           => 'donation',
					'fixed_amount'          => 25.00,
					'min_amount'            => 25.00,
					'max_amount'            => 0.00,
					'donation_presets'      => 'Early Bird: 25, General Admission: 50, VIP Pass: 100',
					'allow_custom_donation' => 0,
					'amount_label'          => __( 'Select Ticket Tier', 'gatewaykit' ),
					'description'           => __( 'Secure your registration and select your ticket tier.', 'gatewaykit' ),
					'submit_label'          => __( 'Register & Pay', 'gatewaykit' ),
					'success_url'           => '',
					'gateways'              => array(),
					'fields'                => array(
						array(
							'id'          => 'f_name',
							'type'        => 'text',
							'label'       => __( 'Attendee Full Name', 'gatewaykit' ),
							'placeholder' => __( 'Jane Doe', 'gatewaykit' ),
							'required'    => 1,
							'help_text'   => '',
							'width'       => '50',
							'options'     => array(),
							'default'     => '',
						),
						array(
							'id'          => 'f_email',
							'type'        => 'email',
							'label'       => __( 'Attendee Email', 'gatewaykit' ),
							'placeholder' => __( 'jane@example.com', 'gatewaykit' ),
							'required'    => 1,
							'help_text'   => '',
							'width'       => '50',
							'options'     => array(),
							'default'     => '',
						),
						array(
							'id'          => 'f_phone',
							'type'        => 'phone',
							'label'       => __( 'Phone Number', 'gatewaykit' ),
							'placeholder' => '',
							'required'    => 0,
							'help_text'   => '',
							'width'       => '100',
							'options'     => array(),
							'default'     => '',
						),
						array(
							'id'          => 'f_notes',
							'type'        => 'textarea',
							'label'       => __( 'Special Requests or Dietary Requirements', 'gatewaykit' ),
							'placeholder' => __( 'e.g. Vegetarian, wheelchair access...', 'gatewaykit' ),
							'required'    => 0,
							'help_text'   => '',
							'width'       => '100',
							'options'     => array(),
							'default'     => '',
						),
						array(
							'id'          => 'f_terms',
							'type'        => 'checkbox',
							'label'       => __( 'I agree to the event terms and refund policy.', 'gatewaykit' ),
							'placeholder' => '',
							'required'    => 1,
							'help_text'   => '',
							'width'       => '100',
							'options'     => array(),
							'default'     => '',
						),
					),
					'terms_text'            => __( 'I agree to the event terms and refund policy.', 'gatewaykit' ),
				),
			),
		);
	}

	/**
	 * Get a specific template by ID.
	 *
	 * @param string $template_id Template identifier.
	 * @return array|null Template definition or null if not found.
	 */
	public static function get_template( $template_id ) {
		$templates = self::get_templates();
		return isset( $templates[ $template_id ] ) ? $templates[ $template_id ] : null;
	}
}
