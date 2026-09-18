<?php
/**
 * GatewayKit Frontend Assets
 *
 * Enqueues frontend scripts, styles, and localizations.
 *
 * @package GatewayKit
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GatewayKit_Frontend_Assets
 */
class GatewayKit_Frontend_Assets {

	/**
	 * Determine whether the current request is an Elementor-rendered page.
	 *
	 * @return bool
	 */
	public static function is_elementor_page() {
		// Elementor Pro exposes this on the frontend.
		if ( did_action( 'elementor/loaded' ) || class_exists( '\Elementor\Plugin' ) ) {
			return true;
		}
		// Fallback: Elementor adds this body class/config marker.
		if ( function_exists( 'elementor_load_plugin_textdomain' ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Enqueue frontend assets for payment result pages and Elementor forms.
	 */
	public static function enqueue() {
		// Use minified assets in production, full assets in debug mode.
		$suffix = defined( 'WP_DEBUG' ) && WP_DEBUG ? '' : '.min';

		// Enqueue UI Kit CSS (base styles).
		wp_enqueue_style( 'gatewaykit-ui-kit', GATEWAYKIT_PLUGIN_URL . 'assets/css/ui-kit' . $suffix . '.css', array(), GATEWAYKIT_VERSION );

		// Enqueue UI Components CSS (component styles including payment result styles).
		wp_enqueue_style( 'gatewaykit-ui-components', GATEWAYKIT_PLUGIN_URL . 'assets/css/ui-components.css', array( 'gatewaykit-ui-kit' ), GATEWAYKIT_VERSION );

		// Enqueue UI Kit JavaScript if needed.
		wp_enqueue_script( 'gatewaykit-ui-kit', GATEWAYKIT_PLUGIN_URL . 'assets/js/ui-kit' . $suffix . '.js', array( 'jquery' ), GATEWAYKIT_VERSION, true );

		// Localize UI Kit script for translations in frontend too.
		wp_localize_script(
			'gatewaykit-ui-kit',
			'gatewaykit_ui_vars',
			array(
				'copied' => __( 'Copied!', 'gatewaykit' ),
				'failed' => __( 'Failed', 'gatewaykit' ),
			)
		);

		// Cache-safe nonce refresher: only needed when Elementor is rendering the page.
		if ( self::is_elementor_page() ) {
			wp_enqueue_script( 'gatewaykit-frontend', GATEWAYKIT_PLUGIN_URL . 'assets/js/frontend' . $suffix . '.js', array( 'jquery' ), GATEWAYKIT_VERSION, true );
			wp_localize_script(
				'gatewaykit-frontend',
				'GatewayKitFrontend',
				array(
					'ajaxurl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'gatewaykit_process_payment' ),
					'debug'   => ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ? true : false,
				)
			);

			// Pro forms shared assets: optional-payment animation (Feature C)
			// and Payment Info live-update (Feature D). Ships in Lite assets;
			// inert without Pro (the page-scan methods bail when Pro is absent).
			$pro_forms_needed = false;
			$pro_forms_data   = array();
			if ( class_exists( 'GatewayKit_Elementor_Page_Scanner' ) ) {
				$optional_forms = GatewayKit_Elementor_Page_Scanner::get_optional_payment_forms_for_current_page();
				if ( ! empty( $optional_forms ) ) {
					$pro_forms_needed                 = true;
					$pro_forms_data['optional_forms'] = $optional_forms;
				}
				// Also detect forms with the Payment Info or Discount Code field types.
				if ( ! $pro_forms_needed && class_exists( '\Elementor\Plugin' ) && is_singular() ) {
					$post_id = get_the_ID();
					if ( $post_id ) {
						$document = \Elementor\Plugin::$instance->documents->get_doc_for_frontend( $post_id );
						if ( $document ) {
							$elements = $document->get_elements_data();
							if ( GatewayKit_Elementor_Page_Scanner::scan_for_pro_form_fields( $elements ) ) {
								$pro_forms_needed = true;
							}
						}
					}
				}
			}
			if ( $pro_forms_needed ) {
				wp_enqueue_style(
					'gatewaykit-pro-forms',
					GATEWAYKIT_PLUGIN_URL . 'assets/css/gatewaykit-pro-forms.css',
					array(),
					GATEWAYKIT_VERSION
				);
				wp_enqueue_script(
					'gatewaykit-pro-forms',
					GATEWAYKIT_PLUGIN_URL . 'assets/js/gatewaykit-pro-forms' . $suffix . '.js',
					array( 'jquery' ),
					GATEWAYKIT_VERSION,
					true
				);
				wp_localize_script(
					'gatewaykit-pro-forms',
					'GatewayKitProForms',
					array_merge(
						$pro_forms_data,
						array(
							'ajaxurl' => admin_url( 'admin-ajax.php' ),
							'nonce'   => wp_create_nonce( 'gatewaykit_discount_nonce' ),
							'i18n'    => array(
								'no_payment'      => __( 'Thank you. Your submission was received.', 'gatewaykit' ),
								'apply'           => __( 'Apply', 'gatewaykit' ),
								'applying'        => __( 'Applying...', 'gatewaykit' ),
								'discount'        => __( 'Discount', 'gatewaykit' ),
								'enter_code'      => __( 'Please enter a discount code.', 'gatewaykit' ),
								'amount_required' => __( 'Please enter an amount first.', 'gatewaykit' ),
								'invalid'         => __( 'Invalid or expired discount code.', 'gatewaykit' ),
								'error'           => __( 'An error occurred. Please try again.', 'gatewaykit' ),
								'removed'         => __( 'Discount removed.', 'gatewaykit' ),
							),
						)
					)
				);
			}
		}
	}
}
