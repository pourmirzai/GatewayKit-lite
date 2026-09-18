<?php
/**
 * GatewayKit AJAX Payment Handler
 *
 * Handles AJAX payment requests for standalone and direct form submissions.
 *
 * @package GatewayKit
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GatewayKit_Ajax_Payment_Handler
 */
class GatewayKit_Ajax_Payment_Handler {

	/**
	 * AJAX handler for payment processing
	 *
	 * This handles direct AJAX requests to /wp-admin/admin-ajax.php?action=gatewaykit_process_payment
	 *
	 * @return void
	 */
	public static function process_payment_ajax() {
		// Enable error logging for debugging.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			GatewayKit_Logger::get_instance()->debug( 'AJAX payment processing started' );
		}

		$logger        = GatewayKit_Logger::get_instance();
		$error_handler = GatewayKit_Error_Handler::get_instance();

		try {
			// Log the AJAX request for debugging.
			$logger->debug(
				'AJAX Payment Processing Started',
				array(
					'action'            => isset( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : 'unknown', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					'gatewaykit_action' => isset( $_POST['gatewaykit_action'] ) ? sanitize_key( wp_unslash( $_POST['gatewaykit_action'] ) ) : 'none', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					'wp_doing_ajax'     => wp_doing_ajax(),
					'http_referer'      => isset( $_SERVER['HTTP_REFERER'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : 'none',
				)
			);

			// Rate limiting check — dedicated process_payment bucket (F13) so
			// transaction-creation flood protection is independent of the
			// generic admin-ajax / faucet bucket.
			$rate_limiter = GatewayKit_Rate_Limiter::get_instance();
			$rate_check   = $rate_limiter->check_rate_limit( 'process_payment' );
			if ( is_wp_error( $rate_check ) ) {
				$logger->warning(
					'Rate limit exceeded for AJAX payment',
					array(
						'rate_check' => $rate_check->get_error_message(),
					)
				);
				wp_send_json_error( $rate_check->get_error_message() );
				return;
			}

			// Verify nonce for security.
			$nonce = '';
			if ( isset( $_POST['_wpnonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$nonce = sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
			} elseif ( isset( $_POST['gatewaykit_nonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$nonce = sanitize_text_field( wp_unslash( $_POST['gatewaykit_nonce'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
			}

			if ( empty( $nonce ) ) {
				$logger->error( 'No nonce provided in AJAX payment request' );
				wp_send_json_error( __( 'Security check failed. No nonce provided.', 'gatewaykit' ) );
				return;
			}

			// Detect context: GatewayKit CPT form vs Elementor Pro Form.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$is_gk_form = isset( $_POST['gatewaykit_form_id'] ) || ( isset( $_POST['gatewaykit_action'] ) && sanitize_key( wp_unslash( $_POST['gatewaykit_action'] ) ) === 'process_payment' && ! empty( $_POST['form_id'] ) );
			$context    = $is_gk_form ? 'form' : 'elementor';
			$action     = $is_gk_form ? 'gatewaykit_form_payment' : 'elementor_ajax';

			// Verify nonce for the expected context action, falling back to elementor_ajax for legacy calls.
			if ( ! wp_verify_nonce( $nonce, $action ) ) {
				if ( ! wp_verify_nonce( $nonce, 'elementor_ajax' ) ) {
					$logger->error(
						'Nonce verification failed for AJAX payment',
						array(
							'nonce_value' => substr( $nonce, 0, 10 ) . '...',
							'action'      => $action,
							'context'     => $context,
						)
					);
					wp_send_json_error( __( 'Security check failed. Please reload and try again.', 'gatewaykit' ) );
					return;
				}
				$context = 'elementor';
			}

			// F13: the nonce must also be bound to the short-lived cookie
			// issued by ajax_get_nonce(). This prevents a nonce harvested from
			// a cached page or a third party from being replayed from a
			// different client (which would not have the matching cookie).
			if ( ! GatewayKit_Nonce_Service::verify_nonce_bind( $nonce, $context ) ) {
				$logger->warning(
					'Nonce cookie bind verification failed for AJAX payment',
					array( 'context' => $context )
				);
				wp_send_json_error( __( 'Security check failed. Please reload the page and try again.', 'gatewaykit' ) );
				return;
			}

			// Check if this is an Elementor form submission.
			if ( isset( $_POST['action'] ) && sanitize_text_field( wp_unslash( $_POST['action'] ) ) === 'elementor_pro_forms_send_form' ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				// This is handled by the Elementor action, so we should not process it here.
				$logger->debug( 'Elementor form submission detected - handled by Elementor action' );
				wp_send_json_error( __( 'Form submission already being processed by Elementor.', 'gatewaykit' ) );
				return;
			}

			// For direct AJAX calls, we need to simulate the Elementor form data.
			if ( isset( $_POST['gatewaykit_action'] ) && sanitize_text_field( wp_unslash( $_POST['gatewaykit_action'] ) ) === 'process_payment' ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				// Process direct payment AJAX call.
				$result = self::process_direct_payment_ajax( $_POST );

				if ( is_wp_error( $result ) ) {
					$error_data = $result->get_error_data();
					if ( ! empty( $error_data ) && isset( $error_data['error_type'] ) ) {
						// This is a gateway error with detailed information.
						$error_message = $error_handler->get_error_message(
							$error_data['error_type'],
							$error_data['error_code'],
							false, // Not admin context.
							$error_data['error_details']
						);
						wp_send_json_error( $error_message );
					} else {
						// Fallback to original error message.
						wp_send_json_error( $result->get_error_message() );
					}
				} else {
					// Success response with proper structure.
					wp_send_json_success(
						array(
							'success' => true,
							'data'    => $result,
							'message' => __( 'Payment processed successfully.', 'gatewaykit' ),
						)
					);
				}
				return;
			}

			// Default response for unrecognized requests.
			$logger->warning(
				'Invalid payment request received',
				array(
					'post_data' => array_keys( $_POST ),
				)
			);
			wp_send_json_error( __( 'Invalid payment request.', 'gatewaykit' ) );

		} catch ( Exception $e ) {
			$logger->error(
				'AJAX Payment Processing Exception',
				array(
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				)
			);

			// Use error handler to get appropriate error message.
			$error_message = $error_handler->get_error_message( 'network', 'exception', false );
			wp_send_json_error( $error_message );
		}
	}

	/**
	 * Process direct payment AJAX request
	 *
	 * @param array $data Payment data.
	 * @return array|WP_Error Payment result or error
	 */
	public static function process_direct_payment_ajax( $data ) {
		$logger    = GatewayKit_Logger::get_instance();
		$validator = GatewayKit_Input_Validator::get_instance();

		$logger->debug(
			'Processing direct payment AJAX',
			array(
				'data_keys' => array_keys( $data ),
			)
		);

		// Anti-spam Honeypot trap check.
		if ( ! empty( $data['gk_hp_url'] ) ) {
			$logger->warning( 'Spam bot submission blocked via honeypot field', array( 'ip' => GatewayKit_IP_Helper::get_client_ip() ) );
			return new WP_Error( 'payment_failed', __( 'Payment could not be processed. Please try again.', 'gatewaykit' ) );
		}

		// Validate required fields.
		$required_fields = array( 'amount', 'gateway', 'description', 'success_url' );
		foreach ( $required_fields as $field ) {
			if ( empty( $data[ $field ] ) ) {
				$logger->error( 'Missing required field in direct payment', array( 'field' => $field ) );
				/* translators: %s: missing field name */
				return new WP_Error( 'missing_field', sprintf( __( 'Required field missing: %s', 'gatewaykit' ), $field ) );
			}
		}

		// Validate amount.
		$amount = $validator->validate_amount( $data['amount'] );
		if ( is_wp_error( $amount ) ) {
			$logger->error( 'Invalid amount in direct payment', array( 'amount' => $data['amount'] ) );
			return $amount;
		}

		// Validate gateway.
		$gateway = $validator->validate_gateway( $data['gateway'] );
		if ( is_wp_error( $gateway ) ) {
			$logger->error( 'Invalid gateway in direct payment', array( 'gateway' => $data['gateway'] ) );
			return $gateway;
		}

		// Verify gateway exists and is globally available.
		$gateway_manager = GatewayKit_Gateway_Manager::get_instance();
		$gateway_obj     = $gateway_manager->get_gateway( $gateway );
		if ( ! $gateway_obj || ! $gateway_obj->is_available() ) {
			$logger->error( 'Gateway not available in direct payment', array( 'gateway' => $gateway ) );
			return new WP_Error( 'gateway_unavailable', __( 'The selected payment gateway is currently unavailable.', 'gatewaykit' ) );
		}

		// If this is a standalone GatewayKit form, validate currency, allowed gateways, and amount constraints.
		$currency    = get_option( 'gatewaykit_currency', 'USD' );
		$cpt_form_id = 0;
		if ( ! empty( $data['gatewaykit_form_id'] ) && is_numeric( $data['gatewaykit_form_id'] ) ) {
			$cpt_form_id = absint( $data['gatewaykit_form_id'] );
		} elseif ( ! empty( $data['form_id'] ) && is_numeric( $data['form_id'] ) ) {
			$cpt_form_id = absint( $data['form_id'] );
		} elseif ( ! empty( $data['post_id'] ) && is_numeric( $data['post_id'] ) ) {
			$cpt_form_id = absint( $data['post_id'] );
		}

		$config = array();
		if ( $cpt_form_id > 0 ) {
			$form_id      = $cpt_form_id;
			$saved_config = get_post_meta( $form_id, '_gatewaykit_form_config', true );
			if ( is_array( $saved_config ) ) {
				$config = $saved_config;
				// Currency compatibility check (ARCH-012).
				$supported_currencies = (array) $gateway_obj->get_supported_currencies();
				if ( ! empty( $supported_currencies ) && ! in_array( $currency, $supported_currencies, true ) ) {
					$logger->error(
						'Gateway currency not supported',
						array(
							'gateway'  => $gateway,
							'currency' => $currency,
						)
					);
					$gateway_title = method_exists( $gateway_obj, 'get_gateway_name' ) ? $gateway_obj->get_gateway_name() : ( method_exists( $gateway_obj, 'get_title' ) ? $gateway_obj->get_title() : ucfirst( $gateway ) );
					return new WP_Error(
						'currency_not_supported',
						/* translators: 1: gateway name, 2: currency code */
						sprintf( __( '%1$s does not support currency %2$s.', 'gatewaykit' ), $gateway_title, $currency )
					);
				}

				// Form-specific gateway whitelist check.
				if ( ! empty( $config['gateways'] ) && is_array( $config['gateways'] ) ) {
					if ( ! in_array( $gateway, $config['gateways'], true ) ) {
						return new WP_Error( 'gateway_not_allowed', __( 'Selected gateway is not enabled for this form.', 'gatewaykit' ) );
					}
				}

				// Amount constraints validation.
				$mode = ! empty( $config['amount_mode'] ) ? $config['amount_mode'] : 'fixed';
				if ( 'fixed' === $mode ) {
					$raw_form_data          = isset( $data['form_data'] ) && is_array( $data['form_data'] ) ? $data['form_data'] : array();
					$selected_priced_fields = array();

					// Check if any choice fields (select / radio) have priced options.
					if ( ! empty( $config['fields'] ) && is_array( $config['fields'] ) ) {
						foreach ( $config['fields'] as $field ) {
							$f_type = isset( $field['type'] ) ? $field['type'] : '';
							if ( ! in_array( $f_type, array( 'select', 'radio' ), true ) || empty( $field['options'] ) ) {
								continue;
							}

							$opts          = is_array( $field['options'] ) ? $field['options'] : explode( "\n", (string) $field['options'] );
							$has_pricing   = false;
							$parsed_by_val = array();

							foreach ( $opts as $opt ) {
								$opt_str = trim( (string) $opt );
								if ( '' === $opt_str ) {
									continue;
								}
								$parsed = class_exists( 'GatewayKit_Form_CPT' ) ? GatewayKit_Form_CPT::parse_preset_item( $opt_str ) : array( 'amount' => 0.0 );
								if ( $parsed['amount'] > 0 ) {
									$has_pricing = true;
								}
								$parsed_by_val[ $opt_str ] = (float) $parsed['amount'];
							}

							if ( ! $has_pricing ) {
								continue;
							}

							$f_id = isset( $field['id'] ) ? $field['id'] : '';
							if ( ! empty( $f_id ) && isset( $raw_form_data[ $f_id ] ) ) {
								$submitted_val = trim( (string) $raw_form_data[ $f_id ] );
								if ( '' !== $submitted_val ) {
									$opt_amount = isset( $parsed_by_val[ $submitted_val ] )
										? $parsed_by_val[ $submitted_val ]
										: (float) ( class_exists( 'GatewayKit_Form_CPT' ) ? GatewayKit_Form_CPT::parse_preset_item( $submitted_val )['amount'] : 0.0 );

									if ( $opt_amount > 0 ) {
										$selected_priced_fields[] = array(
											'field_id' => $f_id,
											'amount'   => $opt_amount,
											'value'    => $submitted_val,
										);
									}
								}
							}
						}
					}

					if ( count( $selected_priced_fields ) > 1 ) {
						return new WP_Error(
							'invalid_amount',
							__( 'Multiple priced options selected. Only one pricing selection is permitted per payment.', 'gatewaykit' )
						);
					}

					if ( 1 === count( $selected_priced_fields ) ) {
						$expected_option_price = (float) $selected_priced_fields[0]['amount'];
						if ( abs( (float) $amount - $expected_option_price ) > 0.009 ) {
							return new WP_Error(
								'invalid_amount',
								__( 'Submitted amount does not match the selected option price.', 'gatewaykit' )
							);
						}
					} else {
						// Fallback: no priced option selected, validate against fixed_amount or whitelist.
						$expected     = isset( $config['fixed_amount'] ) ? (float) $config['fixed_amount'] : 0.0;
						$valid_prices = array( $expected );

						if ( ! empty( $config['fields'] ) && is_array( $config['fields'] ) ) {
							foreach ( $config['fields'] as $field ) {
								if ( ! empty( $field['options'] ) ) {
									$opts = is_array( $field['options'] ) ? $field['options'] : explode( "\n", (string) $field['options'] );
									foreach ( $opts as $opt ) {
										$parsed = class_exists( 'GatewayKit_Form_CPT' ) ? GatewayKit_Form_CPT::parse_preset_item( $opt ) : array( 'amount' => 0.0 );
										if ( $parsed['amount'] > 0 ) {
											$valid_prices[] = $parsed['amount'];
										}
									}
								}
							}
						}

						$matched = false;
						foreach ( $valid_prices as $vp ) {
							if ( abs( (float) $amount - $vp ) <= 0.009 ) {
								$matched = true;
								break;
							}
						}
						if ( ! $matched ) {
							return new WP_Error( 'invalid_amount', __( 'Submitted amount does not match the form price.', 'gatewaykit' ) );
						}
					}
				} elseif ( 'donation' === $mode ) {
					$allow_custom = ! empty( $config['allow_custom_donation'] );
					$presets_str  = ! empty( $config['donation_presets'] ) ? (string) $config['donation_presets'] : '';
					$presets      = array_filter( array_map( 'trim', explode( ',', $presets_str ) ) );

					if ( ! $allow_custom ) {
						// Custom amount is disabled: amount must match one of the presets.
						$matched = false;
						foreach ( $presets as $preset ) {
							$parsed = class_exists( 'GatewayKit_Form_CPT' ) ? GatewayKit_Form_CPT::parse_preset_item( $preset ) : array( 'amount' => (float) $preset );
							if ( abs( (float) $amount - $parsed['amount'] ) <= 0.009 ) {
								$matched = true;
								break;
							}
						}
						if ( ! $matched ) {
							return new WP_Error( 'invalid_amount', __( 'Please select a valid donation amount.', 'gatewaykit' ) );
						}
					} else {
						// Custom donation allowed: validate min/max.
						$min = isset( $config['min_amount'] ) && is_numeric( $config['min_amount'] ) ? (float) $config['min_amount'] : 1.0;
						$max = isset( $config['max_amount'] ) && is_numeric( $config['max_amount'] ) ? (float) $config['max_amount'] : 0.0;
						if ( (float) $amount < $min ) {
							/* translators: %s: minimum amount */
							return new WP_Error( 'amount_too_low', sprintf( __( 'Minimum payment amount is %s.', 'gatewaykit' ), $min ) );
						}
						if ( $max > 0 && (float) $amount > $max ) {
							/* translators: %s: maximum amount */
							return new WP_Error( 'amount_too_high', sprintf( __( 'Maximum payment amount is %s.', 'gatewaykit' ), $max ) );
						}
					}
				} elseif ( 'custom' === $mode ) {
					$min = isset( $config['min_amount'] ) && is_numeric( $config['min_amount'] ) ? (float) $config['min_amount'] : 1.0;
					$max = isset( $config['max_amount'] ) && is_numeric( $config['max_amount'] ) ? (float) $config['max_amount'] : 0.0;
					if ( (float) $amount < $min ) {
						/* translators: %s: minimum amount */
						return new WP_Error( 'amount_too_low', sprintf( __( 'Minimum payment amount is %s.', 'gatewaykit' ), $min ) );
					}
					if ( $max > 0 && (float) $amount > $max ) {
						/* translators: %s: maximum amount */
						return new WP_Error( 'amount_too_high', sprintf( __( 'Maximum payment amount is %s.', 'gatewaykit' ), $max ) );
					}
				}
			}
		}

		// Custom field validation for standalone payment forms.
		$validated_form_data = array();
		$customer_email      = '';
		$customer_name       = '';

		if ( $cpt_form_id > 0 && ! empty( $config['fields'] ) && is_array( $config['fields'] ) ) {
			$raw_submitted_data = isset( $data['form_data'] ) && is_array( $data['form_data'] ) ? $data['form_data'] : array();

			foreach ( $config['fields'] as $fld ) {
				$f_id    = $fld['id'];
				$f_type  = $fld['type'];
				$f_label = ! empty( $fld['label'] ) ? $fld['label'] : $f_id;
				$f_req   = ! empty( $fld['required'] );
				$raw_val = isset( $raw_submitted_data[ $f_id ] ) ? $raw_submitted_data[ $f_id ] : null;

				// Skip layout/decorative fields.
				if ( in_array( $f_type, array( 'heading', 'divider' ), true ) ) {
					continue;
				}

				// Check required fields.
				$is_empty = false;
				if ( null === $raw_val || '' === $raw_val ) {
					$is_empty = true;
				} elseif ( is_array( $raw_val ) && empty( $raw_val ) ) {
					$is_empty = true;
				} elseif ( is_string( $raw_val ) && '' === trim( $raw_val ) ) {
					$is_empty = true;
				}

				if ( $f_req && $is_empty ) {
					return new WP_Error(
						'field_required',
						/* translators: %s: field label */
						sprintf( __( '%s is required.', 'gatewaykit' ), $f_label )
					);
				}

				// Per-type validation and sanitization.
				if ( ! $is_empty ) {
					switch ( $f_type ) {
						case 'email':
							$clean_email = sanitize_email( $raw_val );
							if ( ! is_email( $clean_email ) ) {
								return new WP_Error(
									'invalid_email',
									/* translators: %s: field label */
									sprintf( __( '%s must be a valid email address.', 'gatewaykit' ), $f_label )
								);
							}
							$validated_form_data[ $f_id ] = $clean_email;
							if ( empty( $customer_email ) ) {
								$customer_email = $clean_email;
							}
							break;

						case 'number':
							if ( ! is_numeric( $raw_val ) ) {
								return new WP_Error(
									'invalid_number',
									/* translators: %s: field label */
									sprintf( __( '%s must be a number.', 'gatewaykit' ), $f_label )
								);
							}
							$validated_form_data[ $f_id ] = (float) $raw_val;
							break;

						case 'date':
							if ( ! is_string( $raw_val ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', trim( $raw_val ) ) ) {
								return new WP_Error(
									'invalid_date',
									/* translators: %s: field label */
									sprintf( __( '%s must be a valid date (YYYY-MM-DD).', 'gatewaykit' ), $f_label )
								);
							}
							$validated_form_data[ $f_id ] = sanitize_text_field( trim( $raw_val ) );
							break;

						case 'select':
						case 'radio':
							$allowed_opts = isset( $fld['options'] ) ? ( is_array( $fld['options'] ) ? $fld['options'] : array_filter( array_map( 'trim', explode( "\n", (string) $fld['options'] ) ) ) ) : array();
							$clean_val    = sanitize_text_field( $raw_val );
							if ( ! empty( $allowed_opts ) && ! in_array( $clean_val, $allowed_opts, true ) ) {
								return new WP_Error(
									'invalid_option',
									/* translators: %s: field label */
									sprintf( __( 'Invalid option selected for %s.', 'gatewaykit' ), $f_label )
								);
							}
							$validated_form_data[ $f_id ] = $clean_val;
							break;

						case 'checkbox-group':
							$allowed_opts = isset( $fld['options'] ) && is_array( $fld['options'] ) ? $fld['options'] : array();
							$selected     = is_array( $raw_val ) ? $raw_val : array( $raw_val );
							$clean_items  = array();
							foreach ( $selected as $item ) {
								$item_clean = sanitize_text_field( $item );
								if ( ! empty( $allowed_opts ) && ! in_array( $item_clean, $allowed_opts, true ) ) {
									return new WP_Error(
										'invalid_option',
										/* translators: %s: field label */
										sprintf( __( 'Invalid option selected for %s.', 'gatewaykit' ), $f_label )
									);
								}
								$clean_items[] = $item_clean;
							}
							$validated_form_data[ $f_id ] = $clean_items;
							break;

						case 'checkbox':
							$validated_form_data[ $f_id ] = ! empty( $raw_val ) ? 1 : 0;
							break;

						case 'textarea':
							$validated_form_data[ $f_id ] = sanitize_textarea_field( $raw_val );
							break;

						case 'discount':
							$validated_form_data[ $f_id ] = sanitize_text_field( trim( $raw_val ) );
							break;

						default: // text, phone, hidden.
							$clean_val                    = sanitize_text_field( $raw_val );
							$validated_form_data[ $f_id ] = $clean_val;
							if ( empty( $customer_name ) && in_array( $f_id, array( 'name', 'f_name' ), true ) ) {
								$customer_name = $clean_val;
							}
							break;
					}
				}
			}
		}

		// Validate success URL — must point at this site (F13). The open
		// gatewaykit_process_payment endpoint must not be usable as an
		// open redirect / payment-initiation proxy to third-party hosts.
		$success_url = $validator->validate_local_url( $data['success_url'] );
		if ( is_wp_error( $success_url ) ) {
			$logger->error( 'Invalid success URL in direct payment', array( 'url' => isset( $data['success_url'] ) ? '(rejected)' : '(missing)' ) );
			return $success_url;
		}

		// Determine and validate failure URL (Phase A).
		$failure_url = '';
		if ( ! empty( $config['failure_url'] ) ) {
			$validated_fail = $validator->validate_local_url( $config['failure_url'] );
			if ( ! is_wp_error( $validated_fail ) ) {
				$failure_url = $validated_fail;
			}
		}

		if ( empty( $failure_url ) && ! empty( $data['form_page_url'] ) ) {
			$validated_fail = $validator->validate_local_url( $data['form_page_url'] );
			if ( ! is_wp_error( $validated_fail ) ) {
				$failure_url = $validated_fail;
			}
		}

		// Extract discount code (if any) from top-level or custom fields.
		$discount_code = '';
		if ( ! empty( $data['discount_code'] ) ) {
			$discount_code = sanitize_text_field( trim( $data['discount_code'] ) );
		} elseif ( ! empty( $config['fields'] ) && is_array( $config['fields'] ) ) {
			foreach ( $config['fields'] as $fld ) {
				if ( 'discount' === ( $fld['type'] ?? '' ) ) {
					$fid = $fld['id'] ?? '';
					if ( ! empty( $validated_form_data[ $fid ] ) ) {
						$discount_code = $validated_form_data[ $fid ];
						break;
					} elseif ( ! empty( $data['form_data'][ $fid ] ) ) {
						$discount_code = sanitize_text_field( trim( $data['form_data'][ $fid ] ) );
						break;
					}
				}
			}
		}

		$discount_applied  = false;
		$discount_id       = 0;
		$discount_amount   = 0.0;
		$discount_original = (float) $amount;

		if ( class_exists( 'GatewayKit_Discount_Model' ) && function_exists( 'gatewaykit_is_pro_licensed' ) && gatewaykit_is_pro_licensed() && '' !== $discount_code ) {
			$current_user_id = is_user_logged_in() ? get_current_user_id() : 0;

			// Validate code against amount.
			$valid = GatewayKit_Discount_Model::validate_code( $discount_code, $amount, $current_user_id );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}

			// Calculate discount and final amount.
			$calc = GatewayKit_Discount_Model::calculate_discount( $discount_code, $amount );
			if ( is_wp_error( $calc ) ) {
				return $calc;
			}

			$discount_obj = GatewayKit_Discount_Model::get_by_code( $discount_code );
			if ( ! $discount_obj ) {
				return new WP_Error( 'invalid_discount', __( 'Invalid discount code.', 'gatewaykit' ) );
			}

			$discount_id     = (int) $discount_obj->get_data( 'id' );
			$discount_amount = (float) $calc['discount_amount'];

			// Atomic race-safe reservation.
			if ( ! GatewayKit_Discount_Model::increment_usage( $discount_id ) ) {
				return new WP_Error( 'usage_limit_reached', __( 'This discount code has reached its usage limit.', 'gatewaykit' ) );
			}
			$discount_applied = true;

			// Final charge amount.
			$amount = (float) $calc['final_amount'];
			if ( $amount < 0 ) {
				$amount = 0.0;
			}
		}

		// Prepare payment data.
		$payment_data = array(
			'gateway'         => $gateway,
			'amount'          => $amount,
			'discount_id'     => $discount_id > 0 ? $discount_id : null,
			'discount_amount' => $discount_amount,
			'currency'        => $currency,
			'description'     => sanitize_text_field( $data['description'] ),
			'form_data'       => ! empty( $validated_form_data ) ? $validated_form_data : ( isset( $data['form_data'] ) ? self::sanitize_form_data( $data['form_data'] ) : array() ),
			'user_data'       => GatewayKit_Payment_Service::get_user_data(),
			'user_id'         => is_user_logged_in() ? get_current_user_id() : null,
			'callback_url'    => GatewayKit_Payment_Service::get_callback_url( $gateway ),
			'success_url'     => $success_url,
			'failure_url'     => ! empty( $failure_url ) ? $failure_url : null,
			'form_id'         => isset( $data['form_id'] ) ? sanitize_key( $data['form_id'] ) : 'direct_ajax',
			'post_id'         => isset( $data['post_id'] ) ? intval( $data['post_id'] ) : ( $cpt_form_id > 0 ? $cpt_form_id : 0 ),
		);

		if ( ! empty( $customer_email ) && empty( $payment_data['user_data']['email'] ) ) {
			$payment_data['user_data']['email'] = $customer_email;
		}
		if ( ! empty( $customer_name ) && empty( $payment_data['user_data']['name'] ) ) {
			$payment_data['user_data']['name'] = $customer_name;
		}

		// Fully-free order (100% discount code).
		if ( (float) $amount <= 0 ) {
			$payment_data['amount'] = 0;

			$transaction = GatewayKit_Transaction_Model::create( $payment_data );
			if ( is_wp_error( $transaction ) ) {
				if ( $discount_applied ) {
					GatewayKit_Discount_Model::decrement_usage( $discount_id );
				}
				return $transaction;
			}

			// Mark order complete immediately (no gateway to capture).
			$transaction->update(
				array(
					'status'       => 'completed',
					'ref_id'       => 'FREE',
					'completed_at' => current_time( 'mysql' ),
				)
			);

			// Commit discount usage audit log.
			if ( $discount_applied && class_exists( 'GatewayKit_Discount_Usage_Model' ) ) {
				$recorded = GatewayKit_Discount_Usage_Model::record_usage(
					array(
						'discount_id'     => $discount_id,
						'transaction_id'  => (int) $transaction->get( 'id' ),
						'user_id'         => is_user_logged_in() ? get_current_user_id() : null,
						'amount_saved'    => $discount_amount,
						'original_amount' => $discount_original,
						'final_amount'    => 0.0,
						'ip_address'      => GatewayKit_IP_Helper::get_client_ip(),
					)
				);
				if ( is_wp_error( $recorded ) ) {
					$logger->error(
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

			$receipt_token = $transaction->get( 'receipt_token' );
			$redirect_url  = $payment_data['success_url'];
			if ( $receipt_token && $redirect_url ) {
				$redirect_url = add_query_arg( 'gatewaykit_receipt', $receipt_token, $redirect_url );
			} elseif ( $receipt_token ) {
				$receipt_page_id = get_option( 'gatewaykit_receipt_page_id', 0 );
				if ( $receipt_page_id && get_post_status( $receipt_page_id ) === 'publish' ) {
					$redirect_url = add_query_arg( 'gatewaykit_receipt', $receipt_token, get_permalink( $receipt_page_id ) );
				} else {
					$redirect_url = add_query_arg( 'gatewaykit_receipt', $receipt_token, home_url( '/receipt/' ) );
				}
			}

			return array(
				'success'      => true,
				'redirect_url' => $redirect_url,
				'authority'    => 'FREE',
				'message'      => __( 'Your discount covered the full amount — no payment required.', 'gatewaykit' ),
			);
		}

		// Validate payment data using security manager.
		$security_manager = GatewayKit_Security_Manager::get_instance();
		$validated_data   = $security_manager->validate_payment_data( $payment_data, array( 'discounted' => $discount_applied ) );

		if ( is_wp_error( $validated_data ) ) {
			if ( $discount_applied ) {
				GatewayKit_Discount_Model::decrement_usage( $discount_id );
			}
			$logger->error(
				'Payment data validation failed for direct payment',
				array(
					'errors' => $validated_data->get_error_messages(),
				)
			);
			return $validated_data;
		}

		// Process payment using direct gateway integration.
		$result = GatewayKit_Payment_Service::process_payment( $validated_data );

		if ( is_wp_error( $result ) ) {
			if ( $discount_applied ) {
				GatewayKit_Discount_Model::decrement_usage( $discount_id );
			}
			return $result;
		}

		// Commit discount usage audit row on success.
		if ( $discount_applied && class_exists( 'GatewayKit_Discount_Usage_Model' ) ) {
			if ( empty( $result['transaction_id'] ) ) {
				GatewayKit_Discount_Model::decrement_usage( $discount_id );
				$logger->error(
					'Discount commit skipped: payment succeeded without a transaction id',
					array( 'discount_id' => $discount_id )
				);
			} else {
				$recorded = GatewayKit_Discount_Usage_Model::record_usage(
					array(
						'discount_id'     => $discount_id,
						'transaction_id'  => (int) $result['transaction_id'],
						'user_id'         => is_user_logged_in() ? get_current_user_id() : null,
						'amount_saved'    => $discount_amount,
						'original_amount' => $discount_original,
						'final_amount'    => (float) $amount,
						'ip_address'      => GatewayKit_IP_Helper::get_client_ip(),
					)
				);
				if ( is_wp_error( $recorded ) ) {
					$logger->error(
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

		return array(
			'success'      => true,
			'redirect_url' => $result['redirect_url'],
			'authority'    => $result['authority'],
			'message'      => __( 'Payment initiated successfully.', 'gatewaykit' ),
		);
	}

	/**
	 * Sanitize form data for AJAX requests
	 *
	 * @param array $form_data Form data.
	 * @return array Sanitized form data
	 */
	public static function sanitize_form_data( $form_data ) {
		$sanitized = array();
		foreach ( $form_data as $key => $value ) {
			$sanitized[ $key ] = is_array( $value ) ? array_map( 'sanitize_text_field', $value ) : sanitize_text_field( $value );
		}
		return $sanitized;
	}
}
