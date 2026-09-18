<?php
/**
 * GatewayKit Form Admin Save
 *
 * Handles saving and sanitizing payment form configuration post meta.
 *
 * @package GatewayKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GatewayKit_Form_Admin_Save
 */
class GatewayKit_Form_Admin_Save {

	/**
	 * Single instance.
	 *
	 * @var GatewayKit_Form_Admin_Save|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return GatewayKit_Form_Admin_Save
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_action( 'save_post_' . GatewayKit_Form_CPT::POST_TYPE, array( $this, 'save_form_meta' ), 10, 2 );
	}

	/**
	 * Save form configuration meta.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public function save_form_meta( $post_id, $post ) {
		unset( $post );

		// Verify nonce.
		if ( ! isset( $_POST['gatewaykit_form_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['gatewaykit_form_nonce'] ) ), 'gatewaykit_save_form_config' ) ) {
			return;
		}

		// Check autosave.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Check permissions.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$raw_config = isset( $_POST['gk_config'] ) && is_array( $_POST['gk_config'] ) ? wp_unslash( $_POST['gk_config'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$mode = isset( $raw_config['amount_mode'] ) && in_array( $raw_config['amount_mode'], array( 'fixed', 'donation', 'custom' ), true )
			? $raw_config['amount_mode']
			: 'fixed';

		// Amount position (Phase B).
		$amount_position = isset( $raw_config['amount_position'] ) && 'before' === $raw_config['amount_position'] ? 'before' : 'after';

		// Style preset & Custom CSS (Phase C / Pro gated).
		$is_pro          = function_exists( 'gatewaykit_is_pro_licensed' ) && gatewaykit_is_pro_licensed();
		$allowed_presets = $is_pro
			? array( 'theme', 'modern', 'card', 'gatewaykit', 'custom' )
			: array( 'theme', 'modern' );

		$style_preset = isset( $raw_config['style_preset'] ) && in_array( $raw_config['style_preset'], $allowed_presets, true )
			? $raw_config['style_preset']
			: 'modern';

		$custom_css = '';
		if ( $is_pro && isset( $raw_config['custom_css'] ) ) {
			$raw_css    = wp_strip_all_tags( (string) $raw_config['custom_css'] );
			$custom_css = preg_replace( '/<\s*\/?\s*(?:style|script)[^>]*>/i', '', $raw_css );
		}

		$clean_config = array(
			'amount_mode'           => $mode,
			'amount_position'       => $amount_position,
			'style_preset'          => $style_preset,
			'custom_css'            => $custom_css,
			'fixed_amount'          => isset( $raw_config['fixed_amount'] ) ? (float) $raw_config['fixed_amount'] : 25.00,
			'currency'              => get_option( 'gatewaykit_currency', 'USD' ),
			'donation_presets'      => isset( $raw_config['donation_presets'] ) ? sanitize_text_field( $raw_config['donation_presets'] ) : '10, 25, 50, 100',
			'allow_custom_donation' => ! empty( $raw_config['allow_custom_donation'] ) ? 1 : 0,
			'amount_label'          => isset( $raw_config['amount_label'] ) ? sanitize_text_field( $raw_config['amount_label'] ) : '',
			'min_amount'            => 1.00,
			'max_amount'            => 0.00,
			'submit_label'          => isset( $raw_config['submit_label'] ) ? sanitize_text_field( $raw_config['submit_label'] ) : __( 'Pay Now', 'gatewaykit' ),
			'success_url'           => isset( $raw_config['success_url'] ) ? esc_url_raw( $raw_config['success_url'] ) : '',
			'description'           => isset( $raw_config['description'] ) ? sanitize_text_field( $raw_config['description'] ) : '',
			'gateways'              => array(),
			'fields'                => array(),
			'terms_text'            => isset( $raw_config['terms_text'] ) ? wp_kses_post( $raw_config['terms_text'] ) : '',
		);

		// Handle min/max depending on mode.
		if ( 'donation' === $mode ) {
			$clean_config['min_amount'] = isset( $raw_config['min_amount'] ) ? (float) $raw_config['min_amount'] : 1.00;
			$clean_config['max_amount'] = isset( $raw_config['max_amount'] ) ? (float) $raw_config['max_amount'] : 0.00;
		} elseif ( 'custom' === $mode ) {
			$clean_config['min_amount'] = isset( $raw_config['min_amount_custom'] ) ? (float) $raw_config['min_amount_custom'] : 1.00;
			$clean_config['max_amount'] = isset( $raw_config['max_amount_custom'] ) ? (float) $raw_config['max_amount_custom'] : 0.00;
		}

		// Gateways whitelist.
		if ( ! empty( $raw_config['gateways'] ) && is_array( $raw_config['gateways'] ) ) {
			$clean_config['gateways'] = array_map( 'sanitize_key', $raw_config['gateways'] );
		}

		// Customer form fields.
		$clean_fields  = array();
		$allowed_types = array_keys( GatewayKit_Form_CPT::get_allowed_field_types() );
		$has_email     = false;

		$raw_fields = isset( $raw_config['fields'] ) && is_array( $raw_config['fields'] ) ? $raw_config['fields'] : array();

		// If posted in old associative format, convert via normalize_fields first.
		if ( isset( $raw_fields['name'] ) || isset( $raw_fields['email'] ) || ! isset( $raw_fields[0]['type'] ) ) {
			$raw_fields = GatewayKit_Form_CPT::normalize_fields( $raw_fields, $clean_config['terms_text'] );
		}

		foreach ( $raw_fields as $rf ) {
			if ( ! is_array( $rf ) ) {
				continue;
			}
			$type = isset( $rf['type'] ) && in_array( $rf['type'], $allowed_types, true ) ? $rf['type'] : 'text';
			$id   = ! empty( $rf['id'] ) ? sanitize_key( $rf['id'] ) : 'f_' . wp_generate_password( 6, false, false );
			if ( ! preg_match( '/^[a-z_]/', $id ) ) {
				$id = 'f_' . $id;
			}

			$label       = isset( $rf['label'] ) ? sanitize_text_field( $rf['label'] ) : '';
			$placeholder = isset( $rf['placeholder'] ) ? sanitize_text_field( $rf['placeholder'] ) : '';
			$help_text   = isset( $rf['help_text'] ) ? sanitize_text_field( $rf['help_text'] ) : '';
			$width       = isset( $rf['width'] ) && '50' === $rf['width'] ? '50' : '100';
			$required    = ( in_array( $type, array( 'heading', 'divider', 'hidden' ), true ) ) ? 0 : ( ! empty( $rf['required'] ) ? 1 : 0 );
			$default     = isset( $rf['default'] ) ? sanitize_text_field( $rf['default'] ) : '';

			$options = array();
			if ( ! empty( $rf['options'] ) ) {
				if ( is_array( $rf['options'] ) ) {
					$options = array_values( array_filter( array_map( 'sanitize_text_field', $rf['options'] ) ) );
				} else {
					$raw_lines = explode( "\n", str_replace( "\r", '', (string) $rf['options'] ) );
					foreach ( $raw_lines as $line ) {
						$trimmed = sanitize_text_field( trim( $line ) );
						if ( '' !== $trimmed ) {
							$options[] = $trimmed;
						}
					}
				}
			}

			if ( 'email' === $type ) {
				$has_email = true;
				$required  = 1; // Email is strictly mandatory for customer receipt delivery.
			}

			$clean_fields[] = array(
				'id'          => $id,
				'type'        => $type,
				'label'       => $label,
				'placeholder' => $placeholder,
				'required'    => $required,
				'help_text'   => $help_text,
				'width'       => $width,
				'options'     => $options,
				'default'     => $default,
			);
		}

		if ( ! $has_email ) {
			$clean_fields[] = array(
				'id'          => 'f_email',
				'type'        => 'email',
				'label'       => __( 'Email Address', 'gatewaykit' ),
				'placeholder' => __( 'jane@example.com', 'gatewaykit' ),
				'required'    => 1,
				'help_text'   => '',
				'width'       => '100',
				'options'     => array(),
				'default'     => '',
			);
		}

		$clean_config['fields'] = $clean_fields;

		update_post_meta( $post_id, GatewayKit_Form_CPT::META_KEY, $clean_config );
	}
}
