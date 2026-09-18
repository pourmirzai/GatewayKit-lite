<?php
/**
 * GatewayKit Elementor Payment Form Widget
 *
 * Dedicated widget for embedding standalone GatewayKit payment forms in Elementor Free and Pro.
 *
 * @package GatewayKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\Elementor\Widget_Base' ) ) {
	return;
}

use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;
use Elementor\Group_Control_Border;
use Elementor\Group_Control_Box_Shadow;

/**
 * Class GatewayKit_Elementor_Payment_Widget
 */
class GatewayKit_Elementor_Payment_Widget extends Widget_Base {

	/**
	 * Get widget name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'gatewaykit_payment_form';
	}

	/**
	 * Get widget title.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'Payment Form', 'gatewaykit' );
	}

	/**
	 * Get widget icon.
	 *
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-form-horizontal';
	}

	/**
	 * Get widget categories.
	 *
	 * @return array
	 */
	public function get_categories() {
		return array( 'general' );
	}

	/**
	 * Get widget keywords.
	 *
	 * @return array
	 */
	public function get_keywords() {
		return array( 'payment', 'form', 'donation', 'stripe', 'paypal', 'checkout', 'gatewaykit' );
	}

	/**
	 * Register widget controls.
	 */
	protected function register_controls() {
		// Content Controls.
		$this->start_controls_section(
			'section_content',
			array(
				'label' => __( 'Payment Form', 'gatewaykit' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			)
		);

		$forms_options = $this->get_form_options();

		$this->add_control(
			'form_id',
			array(
				'label'       => __( 'Select Form', 'gatewaykit' ),
				'type'        => Controls_Manager::SELECT,
				'default'     => ! empty( $forms_options ) ? (string) array_key_first( $forms_options ) : '0',
				'options'     => $forms_options,
				'description' => __( 'Choose a payment form created in GatewayKit > Payment Forms.', 'gatewaykit' ),
			)
		);

		$this->end_controls_section();

		// Style Controls — Button.
		$this->start_controls_section(
			'section_style_button',
			array(
				'label' => __( 'Submit Button', 'gatewaykit' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'button_bg_color',
			array(
				'label'     => __( 'Background Color', 'gatewaykit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .gk-submit-btn' => 'background-color: {{VALUE}};',
					'{{WRAPPER}} .gk-preset-pill.is-selected' => 'background-color: {{VALUE}}; border-color: {{VALUE}};',
					'{{WRAPPER}} .gk-gateway-card.is-selected' => 'border-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'button_text_color',
			array(
				'label'     => __( 'Text Color', 'gatewaykit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .gk-submit-btn' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'button_typography',
				'selector' => '{{WRAPPER}} .gk-submit-btn',
			)
		);

		$this->add_control(
			'button_border_radius',
			array(
				'label'      => __( 'Border Radius', 'gatewaykit' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', '%' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 50,
					),
				),
				'selectors'  => array(
					'{{WRAPPER}} .gk-submit-btn' => 'border-radius: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();

		// Style Controls — Container Card.
		$this->start_controls_section(
			'section_style_container',
			array(
				'label' => __( 'Form Card', 'gatewaykit' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'card_bg_color',
			array(
				'label'     => __( 'Card Background', 'gatewaykit' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .gatewaykit-form-wrap' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			Group_Control_Border::get_type(),
			array(
				'name'     => 'card_border',
				'selector' => '{{WRAPPER}} .gatewaykit-form-wrap',
			)
		);

		$this->add_group_control(
			Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'card_box_shadow',
				'selector' => '{{WRAPPER}} .gatewaykit-form-wrap',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Get list of published forms as key-value pairs for the Select control.
	 *
	 * @return array
	 */
	private function get_form_options() {
		$options = array();

		$forms = get_posts(
			array(
				'post_type'      => GatewayKit_Form_CPT::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 50,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		if ( empty( $forms ) ) {
			$options['0'] = __( 'No payment forms found', 'gatewaykit' );
			return $options;
		}

		foreach ( $forms as $form ) {
			/* translators: %d: Form post ID */
			$options[ (string) $form->ID ] = $form->post_title ? $form->post_title : sprintf( __( 'Form #%d', 'gatewaykit' ), $form->ID );
		}

		return $options;
	}

	/**
	 * Render widget output on frontend.
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();
		$form_id  = isset( $settings['form_id'] ) ? absint( $settings['form_id'] ) : 0;

		if ( ! $form_id ) {
			if ( \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
				echo '<div style="padding:20px; background:#f8fafc; border:1px dashed #cbd5e1; border-radius:8px; text-align:center; color:#64748b;">';
				echo esc_html__( 'Please select a payment form from the widget settings.', 'gatewaykit' );
				echo '</div>';
			}
			return;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- GatewayKit_Form_Renderer::render() produces safe HTML
		echo GatewayKit_Form_Renderer::render( $form_id );
	}
}
