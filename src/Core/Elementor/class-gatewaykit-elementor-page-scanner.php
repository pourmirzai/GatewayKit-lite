<?php
/**
 * GatewayKit Elementor Page Scanner
 *
 * Scans page element tree for GatewayKit forms, optional payment settings, and pro fields.
 *
 * @package GatewayKit
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GatewayKit_Elementor_Page_Scanner
 */
class GatewayKit_Elementor_Page_Scanner {

	/**
	 * Elementor action name.
	 */
	const ACTION_NAME = 'payment_gateway';

	/**
	 * Scan the current page/post for Elementor forms that have the GatewayKit action enabled
	 * and optional payment turned on.
	 *
	 * Returns an array of form descriptors:
	 * [
	 *   [
	 *     'form_id'    => string,
	 *     'field_id'   => string,
	 *     'invert'     => bool,
	 *     'no_pay_msg' => string,
	 *   ],
	 *   ...
	 * ]
	 *
	 * Returns empty array when Pro is not active or no such form is found.
	 *
	 * @return array
	 */
	public static function get_optional_payment_forms_for_current_page() {
		if ( ! defined( 'GATEWAYKIT_PRO_VERSION' ) ) {
			return array();
		}

		if ( ! class_exists( '\Elementor\Plugin' ) || ! is_singular() ) {
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
		if ( empty( $elements ) ) {
			return array();
		}

		$forms = array();
		self::collect_optional_payment_forms( $elements, $forms );
		return $forms;
	}

	/**
	 * Recursive walk of an Elementor element tree searching for form widgets with
	 * optional payment enabled.
	 *
	 * @param array $elements Element tree.
	 * @param array $forms    Output array, passed by reference.
	 */
	public static function collect_optional_payment_forms( $elements, &$forms ) {
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
				// settings key, which is more reliable than checking submit_actions.
				$has_payment = isset( $settings['gatewaykit_gateway'] );

				if ( $has_payment && $optional_on ) {
					$forms[] = array(
						'form_id'            => isset( $settings['form_id'] ) ? sanitize_key( $settings['form_id'] ) : '',
						'opt_field'          => isset( $settings['gatewaykit_optional_payment_field'] ) ? sanitize_key( $settings['gatewaykit_optional_payment_field'] ) : '',
						'no_payment_message' => isset( $settings['gatewaykit_optional_no_payment_message'] ) ? wp_kses_post( $settings['gatewaykit_optional_no_payment_message'] ) : '',
					);
				}
			}

			if ( ! empty( $element['elements'] ) ) {
				self::collect_optional_payment_forms( $element['elements'], $forms );
			}
		}
	}

	/**
	 * Recursively walk an Elementor elements array looking for GatewayKit Pro custom field types.
	 *
	 * @param array $elements Element tree.
	 * @return bool True if at least one Pro field type is found.
	 */
	public static function scan_for_pro_form_fields( $elements ) {
		if ( ! is_array( $elements ) ) {
			return false;
		}

		$pro_field_types = array(
			'gatewaykit_payment_info',
			'gatewaykit_discount_code',
		);

		foreach ( $elements as $element ) {
			$is_form_widget = isset( $element['elType'] )
				&& 'widget' === $element['elType']
				&& isset( $element['widgetType'] )
				&& 'form' === $element['widgetType'];

			if ( $is_form_widget ) {
				$fields = isset( $element['settings']['form_fields'] ) ? $element['settings']['form_fields'] : array();
				if ( is_array( $fields ) ) {
					foreach ( $fields as $field ) {
						if ( isset( $field['field_type'] ) && in_array( $field['field_type'], $pro_field_types, true ) ) {
							return true;
						}
					}
				}
			}

			if ( ! empty( $element['elements'] ) ) {
				if ( self::scan_for_pro_form_fields( $element['elements'] ) ) {
					return true;
				}
			}
		}

		return false;
	}
}
