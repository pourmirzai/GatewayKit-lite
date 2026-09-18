<?php
/**
 * GatewayKit Form Renderer
 *
 * Renders standalone payment forms for shortcodes, Gutenberg, and Elementor.
 *
 * @package GatewayKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GatewayKit_Form_Renderer
 */
class GatewayKit_Form_Renderer {

	/**
	 * Render payment form via shortcode: [gatewaykit_form id="123"]
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string Form HTML.
	 */
	public static function render_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'id' => 0,
			),
			$atts,
			'gatewaykit_form'
		);

		$form_id = absint( $atts['id'] );

		if ( ! $form_id ) {
			if ( current_user_can( 'manage_options' ) ) {
				return '<div class="gatewaykit-notice gatewaykit-notice-warning"><p>' . esc_html__( 'GatewayKit: Please specify a form ID in the shortcode, e.g. [gatewaykit_form id="123"]', 'gatewaykit' ) . '</p></div>';
			}
			return '<!-- GatewayKit: No payment form ID specified. -->';
		}

		return self::render( $form_id );
	}

	/**
	 * Render the payment form HTML by Form ID.
	 *
	 * @param int $form_id Form Post ID.
	 * @return string HTML output.
	 */
	public static function render( $form_id ) {
		$post = get_post( $form_id );
		if ( ! $post || GatewayKit_Form_CPT::POST_TYPE !== $post->post_type ) {
			return '<!-- GatewayKit: Payment form not found. -->';
		}

		$config = GatewayKit_Form_CPT::get_form_config( $form_id );

		// Enqueue frontend scripts & styles.
		self::enqueue_assets();

		$currency        = ! empty( $config['currency'] ) ? strtoupper( $config['currency'] ) : 'USD';
		$currency_symbol = self::get_currency_symbol( $currency );
		$fields          = $config['fields'] ?? array();
		$submit_label    = ! empty( $config['submit_label'] ) ? $config['submit_label'] : __( 'Pay Now', 'gatewaykit' );
		$description     = ! empty( $config['description'] ) ? $config['description'] : '';
		$success_url     = ! empty( $config['success_url'] ) ? $config['success_url'] : self::get_default_receipt_url();
		$amount_position = ! empty( $config['amount_position'] ) && 'before' === $config['amount_position'] ? 'before' : 'after';
		$is_pro          = function_exists( 'gatewaykit_is_pro_licensed' ) && gatewaykit_is_pro_licensed();
		$allowed_presets = $is_pro
			? array( 'theme', 'modern', 'card', 'gatewaykit', 'custom' )
			: array( 'theme', 'modern' );

		$style_preset = ! empty( $config['style_preset'] ) && in_array( $config['style_preset'], $allowed_presets, true ) ? $config['style_preset'] : 'modern';

		// Enqueue preset or custom CSS.
		self::enqueue_preset_assets( $style_preset, $is_pro ? ( $config['custom_css'] ?? '' ) : '' );

		// Filter active and currency-compatible gateways.
		$gateways = self::get_compatible_gateways( $config, $currency );

		ob_start();
		?>
		<div class="gatewaykit-form-wrap gk-style-<?php echo esc_attr( $style_preset ); ?>" id="gatewaykit-form-<?php echo esc_attr( $form_id ); ?>">
			<form class="gatewaykit-payment-form" method="post" data-form-id="<?php echo esc_attr( $form_id ); ?>" data-currency="<?php echo esc_attr( $currency ); ?>" data-currency-symbol="<?php echo esc_attr( $currency_symbol ); ?>">
				<input type="hidden" name="action" value="gatewaykit_process_payment" />
				<input type="hidden" name="gatewaykit_action" value="process_payment" />
				<input type="hidden" name="gatewaykit_form_id" value="<?php echo esc_attr( $form_id ); ?>" />
				<input type="hidden" name="form_id" value="<?php echo esc_attr( $form_id ); ?>" />
				<input type="hidden" name="currency" value="<?php echo esc_attr( $currency ); ?>" />
				<input type="hidden" name="success_url" value="<?php echo esc_url( $success_url ); ?>" />
				<input type="hidden" name="description" value="<?php echo esc_attr( $description ? $description : get_the_title( $form_id ) ); ?>" />
				<input type="hidden" name="form_page_url" value="<?php echo esc_url( get_permalink( $form_id ) ? get_permalink( $form_id ) : home_url() ); ?>" />
				<?php wp_nonce_field( 'gatewaykit_form_payment', 'gatewaykit_nonce' ); ?>

				<div style="display:none !important;" aria-hidden="true">
					<label for="gk_hp_url_<?php echo esc_attr( $form_id ); ?>"><?php esc_html_e( 'Leave this field empty', 'gatewaykit' ); ?></label>
					<input type="text" name="gk_hp_url" id="gk_hp_url_<?php echo esc_attr( $form_id ); ?>" value="" tabindex="-1" autocomplete="off" />
				</div>

				<?php if ( ! empty( $description ) ) : ?>
					<div class="gk-form-header">
						<p class="gk-form-description"><?php echo esc_html( $description ); ?></p>
					</div>
				<?php endif; ?>

				<?php
				if ( 'before' === $amount_position ) {
					self::render_amount_section( $form_id, $config, $currency_symbol, $currency );
					self::render_fields_section( $form_id, $fields, $currency_symbol );
				} else {
					self::render_fields_section( $form_id, $fields, $currency_symbol );
					self::render_amount_section( $form_id, $config, $currency_symbol, $currency );
				}
				?>

				<!-- Gateway Selector -->
				<div class="gk-gateways-container">
					<label class="gk-field-label"><?php esc_html_e( 'Select Payment Method', 'gatewaykit' ); ?> <span class="gk-required">*</span></label>

					<?php if ( empty( $gateways ) ) : ?>
						<div class="gk-notice gk-notice-error">
							<?php esc_html_e( 'No payment gateways are currently configured or compatible with this currency.', 'gatewaykit' ); ?>
						</div>
					<?php else : ?>
						<div class="gk-gateway-grid">
							<?php
							$first_gw = true;
							foreach ( $gateways as $gw_id => $gw ) :
								$gw_title = method_exists( $gw, 'get_gateway_name' ) ? $gw->get_gateway_name() : ( method_exists( $gw, 'get_title' ) ? $gw->get_title() : ucfirst( $gw_id ) );
								?>
								<label class="gk-gateway-card <?php echo $first_gw ? 'is-selected' : ''; ?>">
									<input type="radio" name="gateway" value="<?php echo esc_attr( $gw_id ); ?>" <?php checked( $first_gw, true ); ?> />
									<span class="gk-gw-title"><?php echo esc_html( $gw_title ); ?></span>
								</label>
								<?php
								$first_gw = false;
							endforeach;
							?>
						</div>
					<?php endif; ?>
				</div>

				<!-- Notices & Alerts -->
				<div class="gk-form-notices" style="display:none;"></div>

				<!-- Submit Button -->
				<div class="gk-submit-wrapper">
					<button type="submit" class="gk-submit-btn" <?php disabled( empty( $gateways ) ); ?>>
						<span class="gk-btn-text"><?php echo esc_html( $submit_label ); ?></span>
						<span class="gk-spinner" style="display:none;"></span>
					</button>
				</div>
			</form>

			<?php if ( ! $is_pro && apply_filters( 'gatewaykit_show_form_footer_credit', true, $form_id ) ) : ?>
				<div class="gk-form-footer-badge">
					<a href="<?php echo esc_url( 'https://gatewaykit.pourmirzai.com/?utm_source=powered_by&utm_medium=form_footer&utm_campaign=lite_free' ); ?>" target="_blank" rel="noopener">
						<?php esc_html_e( 'Powered by GatewayKit', 'gatewaykit' ); ?>
					</a>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render the amount selection section.
	 *
	 * @param int    $form_id         Form ID.
	 * @param array  $config          Form configuration.
	 * @param string $currency_symbol Currency symbol.
	 * @param string $currency        Currency code.
	 */
	private static function render_amount_section( $form_id, $config, $currency_symbol, $currency ) {
		$amount_mode      = $config['amount_mode'] ?? 'fixed';
		$fixed_amount     = (float) ( $config['fixed_amount'] ?? 25.00 );
		$min_amount       = (float) ( $config['min_amount'] ?? 1.00 );
		$max_amount       = (float) ( $config['max_amount'] ?? 0.00 );
		$donation_presets = ! empty( $config['donation_presets'] ) ? array_map( 'trim', explode( ',', $config['donation_presets'] ) ) : array( '10', '25', '50', '100' );
		$allow_custom_don = ! empty( $config['allow_custom_donation'] );
		?>
		<!-- Amount Selection -->
		<div class="gk-amount-container">
			<?php if ( 'fixed' === $amount_mode ) : ?>
				<input type="hidden" name="amount" class="gk-amount-input" value="<?php echo esc_attr( number_format( $fixed_amount, 2, '.', '' ) ); ?>" />
				<div class="gk-fixed-amount-card">
					<span class="gk-amount-label"><?php echo esc_html( ! empty( $config['amount_label'] ) ? $config['amount_label'] : __( 'Total Amount', 'gatewaykit' ) ); ?></span>
					<span class="gk-amount-value"><?php echo esc_html( $currency_symbol . number_format( $fixed_amount, 2 ) . ' ' . $currency ); ?></span>
				</div>

			<?php elseif ( 'donation' === $amount_mode ) : ?>
				<label class="gk-field-label"><?php echo esc_html( ! empty( $config['amount_label'] ) ? $config['amount_label'] : __( 'Select Donation Amount', 'gatewaykit' ) ); ?></label>
				<div class="gk-donation-presets">
					<?php
					$first          = true;
					$default_amount = 0;
					foreach ( $donation_presets as $preset ) :
						$parsed = class_exists( 'GatewayKit_Form_CPT' ) ? GatewayKit_Form_CPT::parse_preset_item( $preset ) : array(
							'amount' => (float) $preset,
							'label'  => '',
						);
						$val    = $parsed['amount'];
						if ( $val <= 0 ) {
							continue;
						}
						if ( $first ) {
							$default_amount = $val;
						}
						$display_text = ! empty( $parsed['label'] )
							? $parsed['label'] . ' (' . $currency_symbol . number_format( $val, 2 ) . ')'
							: $currency_symbol . $val;
						?>
						<button type="button" class="gk-preset-pill <?php echo $first ? 'is-selected' : ''; ?>" data-amount="<?php echo esc_attr( $val ); ?>">
							<?php echo esc_html( $display_text ); ?>
						</button>
						<?php
						$first = false;
					endforeach;
					?>
					<?php if ( $allow_custom_don ) : ?>
						<button type="button" class="gk-preset-pill gk-custom-pill" data-amount="custom">
							<?php esc_html_e( 'Custom', 'gatewaykit' ); ?>
						</button>
					<?php endif; ?>
				</div>

				<div class="gk-custom-amount-wrapper" style="<?php echo ( $allow_custom_don && empty( $donation_presets ) ) ? 'display:block;' : 'display:none;'; ?>">
					<div class="gk-input-group">
						<span class="gk-input-prefix"><?php echo esc_html( $currency_symbol ); ?></span>
						<input type="number" step="0.01" min="<?php echo esc_attr( $min_amount ); ?>" <?php echo $max_amount > 0 ? 'max="' . esc_attr( $max_amount ) . '"' : ''; ?> class="gk-custom-amount-input" placeholder="<?php esc_attr_e( 'Enter custom amount', 'gatewaykit' ); ?>" />
					</div>
					<?php if ( $min_amount > 0 ) : ?>
						<small class="gk-helper-text"><?php printf( /* translators: %s: minimum amount */ esc_html__( 'Minimum: %s', 'gatewaykit' ), esc_html( $currency_symbol . $min_amount ) ); ?></small>
					<?php endif; ?>
				</div>
				<input type="hidden" name="amount" class="gk-amount-input" value="<?php echo esc_attr( $default_amount > 0 ? $default_amount : $min_amount ); ?>" />

			<?php else : // Custom amount. ?>
				<div class="gk-form-group">
					<label class="gk-field-label" for="gk-amount-<?php echo esc_attr( $form_id ); ?>">
						<?php esc_html_e( 'Payment Amount', 'gatewaykit' ); ?> <span class="gk-required">*</span>
					</label>
					<div class="gk-input-group">
						<span class="gk-input-prefix"><?php echo esc_html( $currency_symbol ); ?></span>
						<input type="number" step="0.01" min="<?php echo esc_attr( $min_amount ); ?>" <?php echo $max_amount > 0 ? 'max="' . esc_attr( $max_amount ) . '"' : ''; ?> name="amount" id="gk-amount-<?php echo esc_attr( $form_id ); ?>" class="gk-amount-input gk-text-input" value="<?php echo esc_attr( $min_amount ); ?>" required />
					</div>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render customer fields section.
	 *
	 * @param int    $form_id         Form ID.
	 * @param array  $fields          Form fields.
	 * @param string $currency_symbol Currency symbol.
	 */
	private static function render_fields_section( $form_id, $fields, $currency_symbol = '$' ) {
		if ( empty( $fields ) ) {
			return;
		}
		?>
		<!-- Customer Fields -->
		<div class="gk-fields-container">
			<?php
			foreach ( $fields as $f ) :
				$f_id       = esc_attr( $f['id'] );
				$f_type     = $f['type'];
				$f_label    = $f['label'];
				$f_place    = $f['placeholder'] ?? '';
				$f_req      = ! empty( $f['required'] );
				$f_width    = ( isset( $f['width'] ) && '50' === $f['width'] ) ? 'gk-col-50' : 'gk-col-100';
				$f_help     = $f['help_text'] ?? '';
				$f_def      = $f['default'] ?? '';
				$f_opts     = ( isset( $f['options'] ) && is_array( $f['options'] ) ) ? $f['options'] : array();
				$input_id   = 'gk-' . $f_id . '-' . $form_id;
				$input_name = 'form_data[' . $f_id . ']';
				$req_attr   = $f_req ? 'required' : '';
				?>
				<?php if ( 'heading' === $f_type ) : ?>
					<div class="gk-form-group <?php echo esc_attr( $f_width ); ?> gk-heading-group">
						<h4 class="gk-section-heading"><?php echo esc_html( $f_label ); ?></h4>
						<?php if ( ! empty( $f_help ) ) : ?>
							<p class="gk-help-text"><?php echo esc_html( $f_help ); ?></p>
						<?php endif; ?>
					</div>
				<?php elseif ( 'divider' === $f_type ) : ?>
					<div class="gk-form-group gk-col-100 gk-divider-group">
						<hr class="gk-divider" />
					</div>
				<?php elseif ( 'hidden' === $f_type ) : ?>
					<input type="hidden" name="<?php echo esc_attr( $input_name ); ?>" value="<?php echo esc_attr( $f_def ); ?>" />
					<?php
				elseif ( in_array( $f_type, array( 'text', 'email', 'phone', 'number', 'date' ), true ) ) :
					$html_type = ( 'phone' === $f_type ) ? 'tel' : $f_type;
					?>
					<div class="gk-form-group <?php echo esc_attr( $f_width ); ?>">
						<label class="gk-field-label" for="<?php echo esc_attr( $input_id ); ?>">
							<?php echo esc_html( $f_label ); ?>
							<?php if ( $f_req ) : ?>
								<span class="gk-required">*</span>
							<?php endif; ?>
						</label>
						<input type="<?php echo esc_attr( $html_type ); ?>" name="<?php echo esc_attr( $input_name ); ?>" id="<?php echo esc_attr( $input_id ); ?>" class="gk-text-input" placeholder="<?php echo esc_attr( $f_place ); ?>" value="<?php echo esc_attr( $f_def ); ?>" <?php echo esc_attr( $req_attr ); ?> />
						<?php if ( ! empty( $f_help ) ) : ?>
							<p class="gk-help-text"><?php echo esc_html( $f_help ); ?></p>
						<?php endif; ?>
					</div>
				<?php elseif ( 'textarea' === $f_type ) : ?>
					<div class="gk-form-group <?php echo esc_attr( $f_width ); ?>">
						<label class="gk-field-label" for="<?php echo esc_attr( $input_id ); ?>">
							<?php echo esc_html( $f_label ); ?>
							<?php if ( $f_req ) : ?>
								<span class="gk-required">*</span>
							<?php endif; ?>
						</label>
						<textarea name="<?php echo esc_attr( $input_name ); ?>" id="<?php echo esc_attr( $input_id ); ?>" rows="3" class="gk-text-input" placeholder="<?php echo esc_attr( $f_place ); ?>" <?php echo esc_attr( $req_attr ); ?>><?php echo esc_textarea( $f_def ); ?></textarea>
						<?php if ( ! empty( $f_help ) ) : ?>
							<p class="gk-help-text"><?php echo esc_html( $f_help ); ?></p>
						<?php endif; ?>
					</div>
				<?php elseif ( 'select' === $f_type ) : ?>
					<div class="gk-form-group <?php echo esc_attr( $f_width ); ?>">
						<label class="gk-field-label" for="<?php echo esc_attr( $input_id ); ?>">
							<?php echo esc_html( $f_label ); ?>
							<?php if ( $f_req ) : ?>
								<span class="gk-required">*</span>
							<?php endif; ?>
						</label>
						<select name="<?php echo esc_attr( $input_name ); ?>" id="<?php echo esc_attr( $input_id ); ?>" class="gk-text-input gk-select-input" <?php echo esc_attr( $req_attr ); ?>>
							<option value=""><?php echo esc_html( ! empty( $f_place ) ? $f_place : __( 'Select an option...', 'gatewaykit' ) ); ?></option>
							<?php
							foreach ( $f_opts as $opt_key => $opt_val ) :
								$opt_str    = is_string( $opt_val ) ? $opt_val : (string) $opt_key;
								$parsed_opt = class_exists( 'GatewayKit_Form_CPT' ) ? GatewayKit_Form_CPT::parse_preset_item( $opt_str ) : array(
									'amount' => 0.0,
									'label'  => '',
								);
								$opt_label  = $opt_str;
								$opt_price  = null;
								if ( $parsed_opt['amount'] > 0 && ! empty( $parsed_opt['label'] ) ) {
									$opt_label = $parsed_opt['label'] . ' (' . $currency_symbol . number_format( $parsed_opt['amount'], 2 ) . ')';
									$opt_price = $parsed_opt['amount'];
								}
								?>
								<option value="<?php echo esc_attr( $opt_str ); ?>" <?php selected( $opt_str, $f_def ); ?>
								<?php
								if ( null !== $opt_price ) :
									?>
									data-price="<?php echo esc_attr( $opt_price ); ?>"<?php endif; ?>><?php echo esc_html( $opt_label ); ?></option>
							<?php endforeach; ?>
						</select>
						<?php if ( ! empty( $f_help ) ) : ?>
							<p class="gk-help-text"><?php echo esc_html( $f_help ); ?></p>
						<?php endif; ?>
					</div>
				<?php elseif ( 'radio' === $f_type ) : ?>
					<div class="gk-form-group <?php echo esc_attr( $f_width ); ?>">
						<label class="gk-field-label">
							<?php echo esc_html( $f_label ); ?>
							<?php if ( $f_req ) : ?>
								<span class="gk-required">*</span>
							<?php endif; ?>
						</label>
						<div class="gk-choice-list">
							<?php
							foreach ( $f_opts as $opt_key => $opt_val ) :
								$opt_str    = is_string( $opt_val ) ? $opt_val : (string) $opt_key;
								$parsed_opt = class_exists( 'GatewayKit_Form_CPT' ) ? GatewayKit_Form_CPT::parse_preset_item( $opt_str ) : array(
									'amount' => 0.0,
									'label'  => '',
								);
								$opt_label  = $opt_str;
								$opt_price  = null;
								if ( $parsed_opt['amount'] > 0 && ! empty( $parsed_opt['label'] ) ) {
									$opt_label = $parsed_opt['label'] . ' (' . $currency_symbol . number_format( $parsed_opt['amount'], 2 ) . ')';
									$opt_price = $parsed_opt['amount'];
								}
								?>
								<label class="gk-choice-option">
									<input type="radio" name="<?php echo esc_attr( $input_name ); ?>" value="<?php echo esc_attr( $opt_str ); ?>" <?php checked( $opt_str, $f_def ); ?> <?php echo esc_attr( $req_attr ); ?>
									<?php
									if ( null !== $opt_price ) :
										?>
										data-price="<?php echo esc_attr( $opt_price ); ?>"<?php endif; ?> />
									<span><?php echo esc_html( $opt_label ); ?></span>
								</label>
							<?php endforeach; ?>
						</div>
						<?php if ( ! empty( $f_help ) ) : ?>
							<p class="gk-help-text"><?php echo esc_html( $f_help ); ?></p>
						<?php endif; ?>
					</div>
				<?php elseif ( 'checkbox' === $f_type ) : ?>
					<div class="gk-form-group <?php echo esc_attr( $f_width ); ?> gk-terms-group">
						<div class="gk-checkbox-single">
							<label class="gk-choice-option gk-terms-label">
								<input type="checkbox" name="<?php echo esc_attr( $input_name ); ?>" value="1" <?php checked( '1', $f_def ); ?> <?php echo esc_attr( $req_attr ); ?> />
								<span>
									<?php echo wp_kses_post( $f_label ); ?>
									<?php if ( $f_req ) : ?>
										<span class="gk-required">*</span>
									<?php endif; ?>
								</span>
							</label>
						</div>
						<?php if ( ! empty( $f_help ) ) : ?>
							<p class="gk-help-text"><?php echo esc_html( $f_help ); ?></p>
						<?php endif; ?>
					</div>
				<?php elseif ( 'checkbox-group' === $f_type ) : ?>
					<div class="gk-form-group <?php echo esc_attr( $f_width ); ?>">
						<label class="gk-field-label">
							<?php echo esc_html( $f_label ); ?>
							<?php if ( $f_req ) : ?>
								<span class="gk-required">*</span>
							<?php endif; ?>
						</label>
						<div class="gk-choice-list">
							<?php foreach ( $f_opts as $opt ) : ?>
								<label class="gk-choice-option">
									<input type="checkbox" name="<?php echo esc_attr( $input_name ); ?>[]" value="<?php echo esc_attr( $opt ); ?>" />
									<span><?php echo esc_html( $opt ); ?></span>
								</label>
							<?php endforeach; ?>
						</div>
						<?php if ( ! empty( $f_help ) ) : ?>
							<p class="gk-help-text"><?php echo esc_html( $f_help ); ?></p>
						<?php endif; ?>
					</div>
				<?php elseif ( 'discount' === $f_type ) : ?>
					<?php if ( class_exists( 'GatewayKit_Discount_Model' ) && function_exists( 'gatewaykit_is_pro_licensed' ) && gatewaykit_is_pro_licensed() ) : ?>
						<div class="gk-form-group <?php echo esc_attr( $f_width ); ?> gk-discount-group">
							<label class="gk-field-label" for="<?php echo esc_attr( $input_id ); ?>">
								<?php echo esc_html( $f_label ); ?>
								<?php if ( $f_req ) : ?>
									<span class="gk-required">*</span>
								<?php endif; ?>
							</label>
							<div class="gk-discount-input-wrapper">
								<input type="text" name="<?php echo esc_attr( $input_name ); ?>" id="<?php echo esc_attr( $input_id ); ?>" class="gk-text-input gk-discount-input" placeholder="<?php echo esc_attr( ! empty( $f_place ) ? $f_place : __( 'Enter discount code', 'gatewaykit' ) ); ?>" value="<?php echo esc_attr( $f_def ); ?>" <?php echo esc_attr( $req_attr ); ?> autocomplete="off" />
								<button type="button" class="gk-discount-apply-btn"><?php esc_html_e( 'Apply', 'gatewaykit' ); ?></button>
							</div>
							<div class="gk-discount-feedback" style="display:none;"></div>
							<?php if ( ! empty( $f_help ) ) : ?>
								<p class="gk-help-text"><?php echo esc_html( $f_help ); ?></p>
							<?php endif; ?>
						</div>
					<?php endif; ?>
				<?php endif; ?>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Enqueue preset or custom styles.
	 *
	 * @param string $style_preset Style preset name.
	 * @param string $custom_css   Optional custom CSS string.
	 */
	public static function enqueue_preset_assets( $style_preset, $custom_css = '' ) {
		$suffix = defined( 'WP_DEBUG' ) && WP_DEBUG ? '' : '.min';

		if ( 'modern' === $style_preset ) {
			wp_enqueue_style(
				'gatewaykit-form-style-modern',
				GATEWAYKIT_PLUGIN_URL . 'assets/css/form-style-modern' . $suffix . '.css',
				array( 'gatewaykit-frontend-form' ),
				GATEWAYKIT_VERSION
			);
		} elseif ( 'card' === $style_preset ) {
			wp_enqueue_style(
				'gatewaykit-form-style-card',
				GATEWAYKIT_PLUGIN_URL . 'assets/css/form-style-card' . $suffix . '.css',
				array( 'gatewaykit-frontend-form' ),
				GATEWAYKIT_VERSION
			);
		} elseif ( 'gatewaykit' === $style_preset ) {
			wp_enqueue_style(
				'gatewaykit-form-style-gatewaykit',
				GATEWAYKIT_PLUGIN_URL . 'assets/css/form-style-gatewaykit' . $suffix . '.css',
				array( 'gatewaykit-frontend-form' ),
				GATEWAYKIT_VERSION
			);
		} elseif ( 'custom' === $style_preset && ! empty( $custom_css ) ) {
			$clean_css = wp_strip_all_tags( $custom_css );
			$clean_css = str_ireplace( array( '</style', '<style', '</script', '<script' ), '', $clean_css );
			if ( ! empty( $clean_css ) ) {
				wp_add_inline_style( 'gatewaykit-frontend-form', $clean_css );
			}
		}
	}

	/**
	 * Get list of gateways compatible with this form and currency.
	 *
	 * @param array  $config   Form configuration.
	 * @param string $currency Form currency code.
	 * @return array Array of Gateway instances.
	 */
	private static function get_compatible_gateways( $config, $currency ) {
		$gateway_manager = GatewayKit_Gateway_Manager::get_instance();
		$all_gateways    = $gateway_manager->get_registered_gateways();
		$allowed_ids     = ! empty( $config['gateways'] ) && is_array( $config['gateways'] ) ? $config['gateways'] : array_keys( $all_gateways );

		$compatible = array();
		foreach ( $all_gateways as $id => $class_name ) {
			if ( ! in_array( $id, $allowed_ids, true ) ) {
				continue;
			}
			$gw = $gateway_manager->get_gateway( $id );
			if ( ! $gw instanceof GatewayKit_Payment_Gateway_Interface ) {
				continue;
			}
			if ( ! $gw->is_available() ) {
				continue;
			}

			// Currency support check (ARCH-012).
			$supported_currencies = (array) $gw->get_supported_currencies();
			if ( ! empty( $supported_currencies ) && ! in_array( $currency, $supported_currencies, true ) ) {
				continue;
			}

			$compatible[ $id ] = $gw;
		}

		return $compatible;
	}

	/**
	 * Get the default receipt page URL.
	 *
	 * @return string
	 */
	private static function get_default_receipt_url() {
		$receipt_page_id = get_option( 'gatewaykit_receipt_page_id', 0 );
		if ( $receipt_page_id && get_post_status( $receipt_page_id ) === 'publish' ) {
			return get_permalink( $receipt_page_id );
		}
		return home_url( '/receipt/' );
	}

	/**
	 * Enqueue styles and scripts for frontend form rendering.
	 */
	public static function enqueue_assets() {
		$suffix = defined( 'WP_DEBUG' ) && WP_DEBUG ? '' : '.min';

		wp_enqueue_style(
			'gatewaykit-frontend-form',
			GATEWAYKIT_PLUGIN_URL . 'assets/css/frontend-form' . $suffix . '.css',
			array(),
			GATEWAYKIT_VERSION
		);

		wp_enqueue_script(
			'gatewaykit-frontend-form',
			GATEWAYKIT_PLUGIN_URL . 'assets/js/frontend-form' . $suffix . '.js',
			array( 'jquery' ),
			GATEWAYKIT_VERSION,
			true
		);

		wp_localize_script(
			'gatewaykit-frontend-form',
			'GatewayKitFormConfig',
			array(
				'ajaxurl'        => admin_url( 'admin-ajax.php' ),
				'context'        => 'form',
				'nonce'          => wp_create_nonce( 'gatewaykit_form_payment' ),
				'discount_nonce' => wp_create_nonce( 'gatewaykit_discount_nonce' ),
				'i18n'           => array(
					'processing'        => __( 'Processing...', 'gatewaykit' ),
					'security_error'    => __( 'Security verification failed. Please refresh the page.', 'gatewaykit' ),
					'generic_error'     => __( 'An error occurred. Please try again.', 'gatewaykit' ),
					'select_gateway'    => __( 'Please select a payment method.', 'gatewaykit' ),
					'enter_amount'      => __( 'Please enter a valid amount.', 'gatewaykit' ),
					'payment_cancelled' => __( 'Payment was cancelled. You can try again below.', 'gatewaykit' ),
					'payment_failed'    => __( 'Payment failed. Please try again or use another payment method.', 'gatewaykit' ),
					'apply'             => __( 'Apply', 'gatewaykit' ),
					'applying'          => __( 'Applying...', 'gatewaykit' ),
					'enter_code'        => __( 'Please enter a discount code.', 'gatewaykit' ),
					'discount_applied'  => __( 'Discount applied:', 'gatewaykit' ),
				),
			)
		);
	}

	/**
	 * Return standard currency symbol.
	 *
	 * @param string $currency Currency code.
	 * @return string Symbol or code.
	 */
	public static function get_currency_symbol( $currency ) {
		$symbols = array(
			'USD' => '$',
			'EUR' => '€',
			'GBP' => '£',
			'AUD' => 'A$',
			'CAD' => 'C$',
			'JPY' => '¥',
			'INR' => '₹',
			'BRL' => 'R$',
			'NGN' => '₦',
			'GHS' => 'GH₵',
		);
		return $symbols[ $currency ] ?? $currency . ' ';
	}
}
