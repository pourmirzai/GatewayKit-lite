<?php
/**
 * GatewayKit Form Admin UI
 *
 * Handles meta boxes, template picker, and editor assets for payment forms.
 *
 * @package GatewayKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GatewayKit_Form_Admin_Ui
 */
class GatewayKit_Form_Admin_Ui {

	/**
	 * Single instance.
	 *
	 * @var GatewayKit_Form_Admin_Ui|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return GatewayKit_Form_Admin_Ui
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
		add_action( 'add_meta_boxes', array( $this, 'register_meta_boxes' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_filter( 'views_edit-' . GatewayKit_Form_CPT::POST_TYPE, array( $this, 'render_template_buttons' ) );
	}

	/**
	 * Register meta boxes for the form editor.
	 */
	public function register_meta_boxes() {
		add_meta_box(
			'gatewaykit_form_config',
			__( 'Payment & Form Settings', 'gatewaykit' ),
			array( $this, 'render_config_meta_box' ),
			GatewayKit_Form_CPT::POST_TYPE,
			'normal',
			'high'
		);

		add_meta_box(
			'gatewaykit_form_embed',
			__( 'Embed Form', 'gatewaykit' ),
			array( $this, 'render_embed_meta_box' ),
			GatewayKit_Form_CPT::POST_TYPE,
			'side',
			'high'
		);
	}

	/**
	 * Render the embed information meta box.
	 *
	 * @param WP_Post $post Post object.
	 */
	public function render_embed_meta_box( $post ) {
		$shortcode = '[gatewaykit_form id="' . esc_attr( $post->ID ) . '"]';
		?>
		<div class="gatewaykit-embed-box">
			<p><strong><?php esc_html_e( 'Shortcode:', 'gatewaykit' ); ?></strong></p>
			<p>
				<input type="text" readonly value="<?php echo esc_attr( $shortcode ); ?>" class="widefat" onclick="this.select();" style="background:#f8f9fa; font-family:monospace;" />
			</p>
			<p class="description">
				<?php esc_html_e( 'Copy and paste this shortcode into any page, post, or Gutenberg block.', 'gatewaykit' ); ?>
			</p>
			<hr style="margin:12px 0; border:0; border-top:1px solid #ddd;" />
			<p><strong><?php esc_html_e( 'Elementor:', 'gatewaykit' ); ?></strong></p>
			<p class="description">
				<?php esc_html_e( 'In the Elementor editor, drag the "GatewayKit Payment Form" widget onto the page and select this form from the list.', 'gatewaykit' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Render the main configuration meta box.
	 *
	 * @param WP_Post $post Post object.
	 */
	public function render_config_meta_box( $post ) {
		wp_nonce_field( 'gatewaykit_save_form_config', 'gatewaykit_form_nonce' );

		$saved_config = get_post_meta( $post->ID, GatewayKit_Form_CPT::META_KEY, true );
		$defaults     = GatewayKit_Form_CPT::get_default_config();

		// Check if a starter template was requested on a new form.
		if ( empty( $saved_config ) && ! empty( $_GET['template'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$template_id = sanitize_key( wp_unslash( $_GET['template'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$template    = GatewayKit_Form_Templates::get_template( $template_id );
			if ( $template && ! empty( $template['config'] ) ) {
				$defaults = wp_parse_args( $template['config'], $defaults );
			}
		}

		$config             = is_array( $saved_config ) ? wp_parse_args( $saved_config, $defaults ) : $defaults;
		$config['fields']   = GatewayKit_Form_CPT::normalize_fields( $config['fields'] ?? array(), $config['terms_text'] ?? '' );
		$config['currency'] = get_option( 'gatewaykit_currency', 'USD' );

		$gateway_manager    = GatewayKit_Gateway_Manager::get_instance();
		$available_gateways = $gateway_manager->get_registered_gateways();
		?>
		<style>
			.gk-form-section { margin-bottom: 24px; padding-bottom: 20px; border-bottom: 1px solid #e2e8f0; }
			.gk-form-section:last-child { border-bottom: none; margin-bottom: 0; padding-bottom: 0; }
			.gk-form-section h3 { margin: 0 0 12px 0; font-size: 15px; color: #1e293b; display: flex; align-items: center; gap: 8px; }
			.gk-row { display: flex; flex-wrap: wrap; gap: 20px; margin-bottom: 14px; }
			.gk-col { flex: 1; min-width: 220px; }
			.gk-label { display: block; font-weight: 600; margin-bottom: 4px; color: #334155; }
			.gk-radio-group label { display: inline-block; margin-right: 16px; margin-bottom: 6px; cursor: pointer; }
			.gk-conditional-box { background: #f8fafc; padding: 14px; border-radius: 6px; border: 1px solid #e2e8f0; margin-top: 8px; }
			.gk-checkbox-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 10px; }
			.gk-checkbox-item { display: flex; align-items: center; gap: 6px; }
		</style>

		<!-- 1. Amount & Pricing Mode -->
		<div class="gk-form-section">
			<h3><span class="dashicons dashicons-money-alt"></span> <?php esc_html_e( 'Pricing & Amount Mode', 'gatewaykit' ); ?></h3>

			<div class="gk-radio-group">
				<label>
					<input type="radio" name="gk_config[amount_mode]" value="fixed" <?php checked( $config['amount_mode'], 'fixed' ); ?> class="gk-amount-mode-toggle" />
					<strong><?php esc_html_e( 'Fixed Price', 'gatewaykit' ); ?></strong> — <?php esc_html_e( 'Sell a single product, service, or fixed fee.', 'gatewaykit' ); ?>
				</label>
				<br />
				<label>
					<input type="radio" name="gk_config[amount_mode]" value="donation" <?php checked( $config['amount_mode'], 'donation' ); ?> class="gk-amount-mode-toggle" />
					<strong><?php esc_html_e( 'Preset Buttons / Tiers', 'gatewaykit' ); ?></strong> — <?php esc_html_e( 'Selectable tier/preset buttons (e.g. Early Bird: 25, VIP: 100, or 10, 25, 50) plus optional custom gift.', 'gatewaykit' ); ?>
				</label>
				<br />
				<label>
					<input type="radio" name="gk_config[amount_mode]" value="custom" <?php checked( $config['amount_mode'], 'custom' ); ?> class="gk-amount-mode-toggle" />
					<strong><?php esc_html_e( 'Custom Amount', 'gatewaykit' ); ?></strong> — <?php esc_html_e( 'Customer enters any amount (e.g. invoices, open fee).', 'gatewaykit' ); ?>
				</label>
			</div>

			<!-- Fixed Price Sub-settings -->
			<div id="gk-mode-fixed-box" class="gk-conditional-box" style="<?php echo ( 'fixed' !== $config['amount_mode'] ) ? 'display:none;' : ''; ?>">
				<div class="gk-row">
					<div class="gk-col">
						<label class="gk-label" for="gk_fixed_amount"><?php esc_html_e( 'Amount / Price:', 'gatewaykit' ); ?></label>
						<input type="number" step="0.01" min="0.01" name="gk_config[fixed_amount]" id="gk_fixed_amount" value="<?php echo esc_attr( $config['fixed_amount'] ); ?>" class="regular-text" />
					</div>
					<div class="gk-col">
						<label class="gk-label"><?php esc_html_e( 'Currency:', 'gatewaykit' ); ?></label>
						<p style="margin: 4px 0 0 0; font-size: 14px; font-weight: 600; color: #1e293b;">
							<code><?php echo esc_html( $config['currency'] ); ?></code>
							<span style="font-size: 12px; font-weight: normal; color: #64748b; margin-left: 6px;">
								<?php
								printf(
									/* translators: %s: Settings page URL */
									wp_kses_post( __( '(Configured globally in <a href="%s" target="_blank">GatewayKit Settings</a>)', 'gatewaykit' ) ),
									esc_url( admin_url( 'admin.php?page=gatewaykit-settings' ) )
								);
								?>
							</span>
						</p>
					</div>
				</div>
			</div>

			<!-- Donation Sub-settings -->
			<div id="gk-mode-donation-box" class="gk-conditional-box" style="<?php echo ( 'donation' !== $config['amount_mode'] ) ? 'display:none;' : ''; ?>">
				<div class="gk-row">
					<div class="gk-col">
						<label class="gk-label" for="gk_donation_presets"><?php esc_html_e( 'Preset Amount Buttons / Tiers (comma-separated):', 'gatewaykit' ); ?></label>
						<input type="text" name="gk_config[donation_presets]" id="gk_donation_presets" value="<?php echo esc_attr( $config['donation_presets'] ); ?>" class="regular-text" />
						<p class="description"><?php esc_html_e( 'Example: 10, 25, 50, 100 or labeled tiers: Early Bird: 25, General Admission: 50, VIP Pass: 100', 'gatewaykit' ); ?></p>
					</div>
					<div class="gk-col">
						<label class="gk-label">
							<input type="checkbox" name="gk_config[allow_custom_donation]" value="1" <?php checked( ! empty( $config['allow_custom_donation'] ) ); ?> />
							<?php esc_html_e( 'Allow donor to enter a custom amount', 'gatewaykit' ); ?>
						</label>
					</div>
				</div>
				<div class="gk-row">
					<div class="gk-col">
						<label class="gk-label" for="gk_min_donation"><?php esc_html_e( 'Minimum Allowed Amount:', 'gatewaykit' ); ?></label>
						<input type="number" step="0.01" min="0" name="gk_config[min_amount]" id="gk_min_donation" value="<?php echo esc_attr( $config['min_amount'] ); ?>" class="regular-text" />
					</div>
					<div class="gk-col">
						<label class="gk-label" for="gk_max_donation"><?php esc_html_e( 'Maximum Allowed Amount (0 for unlimited):', 'gatewaykit' ); ?></label>
						<input type="number" step="0.01" min="0" name="gk_config[max_amount]" id="gk_max_donation" value="<?php echo esc_attr( $config['max_amount'] ); ?>" class="regular-text" />
					</div>
				</div>
				<div class="gk-row">
					<div class="gk-col">
						<label class="gk-label" for="gk_amount_label"><?php esc_html_e( 'Preset Buttons Title (optional):', 'gatewaykit' ); ?></label>
						<input type="text" name="gk_config[amount_label]" id="gk_amount_label" value="<?php echo esc_attr( $config['amount_label'] ?? '' ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Choose Ticket Tier, Select Amount...', 'gatewaykit' ); ?>" />
						<p class="description"><?php esc_html_e( 'The heading shown directly above the amount buttons on the frontend. Leave empty for default.', 'gatewaykit' ); ?></p>
					</div>
				</div>
			</div>

			<!-- Custom Amount Sub-settings -->
			<div id="gk-mode-custom-box" class="gk-conditional-box" style="<?php echo ( 'custom' !== $config['amount_mode'] ) ? 'display:none;' : ''; ?>">
				<div class="gk-row">
					<div class="gk-col">
						<label class="gk-label" for="gk_min_custom"><?php esc_html_e( 'Minimum Amount:', 'gatewaykit' ); ?></label>
						<input type="number" step="0.01" min="0" name="gk_config[min_amount_custom]" id="gk_min_custom" value="<?php echo esc_attr( $config['min_amount'] ); ?>" class="regular-text" />
					</div>
					<div class="gk-col">
						<label class="gk-label" for="gk_max_custom"><?php esc_html_e( 'Maximum Amount (0 for unlimited):', 'gatewaykit' ); ?></label>
						<input type="number" step="0.01" min="0" name="gk_config[max_amount_custom]" id="gk_max_custom" value="<?php echo esc_attr( $config['max_amount'] ); ?>" class="regular-text" />
					</div>
				</div>
			</div>
		</div>

		<!-- 2. Customer Form Fields Builder -->
		<div class="gk-form-section">
			<h3><span class="dashicons dashicons-id-alt"></span> <?php esc_html_e( 'Customer Information Fields', 'gatewaykit' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'Drag and drop rows to reorder fields. Email is always required for receipt delivery.', 'gatewaykit' ); ?>
			</p>

			<div id="gk-fields-list">
				<?php
				$allowed_types = GatewayKit_Form_CPT::get_allowed_field_types();
				foreach ( $config['fields'] as $index => $field ) :
					$fid        = esc_attr( $field['id'] );
					$ftype      = esc_attr( $field['type'] );
					$flabel     = esc_attr( $field['label'] );
					$fplace     = esc_attr( $field['placeholder'] );
					$fhelp      = esc_attr( $field['help_text'] );
					$fwidth     = esc_attr( $field['width'] ?? '100' );
					$fdef       = esc_attr( $field['default'] );
					$foptions   = is_array( $field['options'] ?? array() ) ? implode( "\n", $field['options'] ) : '';
					$freq       = ! empty( $field['required'] );
					$is_email   = ( 'email' === $field['type'] );
					$is_heading = ( 'heading' === $field['type'] );
					$is_divider = ( 'divider' === $field['type'] );
					$is_choice  = in_array( $field['type'], array( 'select', 'radio', 'checkbox-group' ), true );
					$type_name  = $allowed_types[ $field['type'] ] ?? ucfirst( $field['type'] );
					?>
					<div class="gk-field-row is-collapsed" data-field-id="<?php echo esc_attr( $fid ); ?>" data-field-type="<?php echo esc_attr( $ftype ); ?>">
						<div class="gk-field-header">
							<div class="gk-field-header-left">
								<span class="gk-field-handle"><span class="dashicons dashicons-menu"></span></span>
								<div class="gk-field-title">
									<span class="gk-field-type-badge"><?php echo esc_html( $type_name ); ?></span>
									<span class="gk-field-label-preview"><?php echo esc_html( $field['label'] ); ?></span>
									<span class="gk-field-required-indicator" <?php echo $freq ? '' : 'style="display:none;"'; ?>>*</span>
								</div>
							</div>
							<div class="gk-field-actions">
								<button type="button" class="button button-small gk-field-move-up" title="<?php esc_attr_e( 'Move Up', 'gatewaykit' ); ?>"><span class="dashicons dashicons-arrow-up-alt2"></span></button>
								<button type="button" class="button button-small gk-field-move-down" title="<?php esc_attr_e( 'Move Down', 'gatewaykit' ); ?>"><span class="dashicons dashicons-arrow-down-alt2"></span></button>
								<button type="button" class="button button-small gk-field-duplicate" title="<?php esc_attr_e( 'Duplicate', 'gatewaykit' ); ?>"><span class="dashicons dashicons-admin-page"></span></button>
								<button type="button" class="button button-small gk-field-delete" title="<?php esc_attr_e( 'Delete', 'gatewaykit' ); ?>"><span class="dashicons dashicons-trash"></span></button>
								<button type="button" class="button button-small gk-field-toggle"><span class="dashicons dashicons-arrow-right-alt2"></span></button>
							</div>
						</div>

						<div class="gk-field-body">
							<input type="hidden" name="gk_config[fields][<?php echo esc_attr( $index ); ?>][id]" class="gk-field-input-id" value="<?php echo esc_attr( $fid ); ?>" />
							<input type="hidden" name="gk_config[fields][<?php echo esc_attr( $index ); ?>][type]" class="gk-field-input-type" value="<?php echo esc_attr( $ftype ); ?>" />

							<div class="gk-builder-row">
								<div class="gk-builder-col">
									<label><?php esc_html_e( 'Field Label', 'gatewaykit' ); ?></label>
									<input type="text" name="gk_config[fields][<?php echo esc_attr( $index ); ?>][label]" class="gk-field-label-input regular-text" value="<?php echo esc_attr( $flabel ); ?>" />
								</div>
								<div class="gk-builder-col">
									<label><?php esc_html_e( 'Field Width', 'gatewaykit' ); ?></label>
									<select name="gk_config[fields][<?php echo esc_attr( $index ); ?>][width]">
										<option value="100" <?php selected( $fwidth, '100' ); ?>><?php esc_html_e( '100% (Full Width)', 'gatewaykit' ); ?></option>
										<option value="50" <?php selected( $fwidth, '50' ); ?>><?php esc_html_e( '50% (Half Width)', 'gatewaykit' ); ?></option>
									</select>
								</div>
							</div>

							<?php if ( ! $is_divider ) : ?>
								<div class="gk-builder-row">
									<?php if ( ! $is_heading && 'checkbox' !== $field['type'] && 'hidden' !== $field['type'] ) : ?>
										<div class="gk-builder-col">
											<label><?php esc_html_e( 'Placeholder', 'gatewaykit' ); ?></label>
											<input type="text" name="gk_config[fields][<?php echo esc_attr( $index ); ?>][placeholder]" class="regular-text" value="<?php echo esc_attr( $fplace ); ?>" />
										</div>
									<?php endif; ?>
									<div class="gk-builder-col">
										<label><?php esc_html_e( 'Help Text / Description', 'gatewaykit' ); ?></label>
										<input type="text" name="gk_config[fields][<?php echo esc_attr( $index ); ?>][help_text]" class="regular-text" value="<?php echo esc_attr( $fhelp ); ?>" />
									</div>
									<?php if ( ! $is_heading ) : ?>
										<div class="gk-builder-col">
											<label><?php esc_html_e( 'Default Value', 'gatewaykit' ); ?></label>
											<input type="text" name="gk_config[fields][<?php echo esc_attr( $index ); ?>][default]" class="regular-text" value="<?php echo esc_attr( $fdef ); ?>" />
										</div>
									<?php endif; ?>
								</div>
							<?php endif; ?>

							<?php if ( $is_choice ) : ?>
								<div class="gk-builder-row gk-options-box">
									<div class="gk-builder-col">
										<label><?php esc_html_e( 'Options (one option per line):', 'gatewaykit' ); ?></label>
										<textarea name="gk_config[fields][<?php echo esc_attr( $index ); ?>][options]" rows="3" class="large-text"><?php echo esc_textarea( $foptions ); ?></textarea>
										<p class="description"><?php esc_html_e( 'Optionally attach a price to each option, e.g. Early Bird | 25 or VIP Pass: 100', 'gatewaykit' ); ?></p>
									</div>
								</div>
							<?php endif; ?>

							<?php if ( ! $is_heading && ! $is_divider && 'hidden' !== $field['type'] ) : ?>
								<div class="gk-builder-row">
									<div class="gk-builder-col">
										<?php if ( $is_email ) : ?>
											<label><input type="checkbox" checked disabled /> <?php esc_html_e( 'Required Field (always required for email)', 'gatewaykit' ); ?></label>
											<input type="hidden" name="gk_config[fields][<?php echo esc_attr( $index ); ?>][required]" value="1" />
										<?php else : ?>
											<label>
												<input type="checkbox" name="gk_config[fields][<?php echo esc_attr( $index ); ?>][required]" value="1" class="gk-field-required-checkbox" <?php checked( $freq ); ?> />
												<?php esc_html_e( 'Required Field', 'gatewaykit' ); ?>
											</label>
										<?php endif; ?>
									</div>
								</div>
							<?php endif; ?>

							<?php if ( 'discount' === $field['type'] && ! gatewaykit_is_pro_licensed() ) : ?>
								<div class="gk-pro-notice-callout">
									<span class="dashicons dashicons-lock"></span>
									<em><?php esc_html_e( 'Discount codes require GatewayKit Pro. This field will only appear on live forms when a Pro license is active.', 'gatewaykit' ); ?></em>
								</div>
							<?php endif; ?>
						</div>
					</div>
				<?php endforeach; ?>
			</div>

			<!-- Add Field Palette -->
			<div class="gk-add-field-panel">
				<h4><span class="dashicons dashicons-plus-alt2"></span> <?php esc_html_e( 'Add Form Field', 'gatewaykit' ); ?></h4>
				<div class="gk-add-field-groups">
					<div class="gk-add-field-group">
						<div class="gk-add-field-group-title"><?php esc_html_e( 'Standard Fields', 'gatewaykit' ); ?></div>
						<div class="gk-add-field-buttons">
							<button type="button" class="button gk-add-field-btn" data-type="text" data-label="<?php esc_attr_e( 'Text', 'gatewaykit' ); ?>" data-default-label="<?php esc_attr_e( 'New Text Field', 'gatewaykit' ); ?>" data-placeholder=""><span class="dashicons dashicons-editor-textcolor"></span> <?php esc_html_e( 'Text', 'gatewaykit' ); ?></button>
							<button type="button" class="button gk-add-field-btn" data-type="email" data-label="<?php esc_attr_e( 'Email', 'gatewaykit' ); ?>" data-default-label="<?php esc_attr_e( 'Email Address', 'gatewaykit' ); ?>" data-placeholder="<?php esc_attr_e( 'user@example.com', 'gatewaykit' ); ?>"><span class="dashicons dashicons-email"></span> <?php esc_html_e( 'Email', 'gatewaykit' ); ?></button>
							<button type="button" class="button gk-add-field-btn" data-type="phone" data-label="<?php esc_attr_e( 'Phone', 'gatewaykit' ); ?>" data-default-label="<?php esc_attr_e( 'Phone Number', 'gatewaykit' ); ?>" data-placeholder=""><span class="dashicons dashicons-phone"></span> <?php esc_html_e( 'Phone', 'gatewaykit' ); ?></button>
							<button type="button" class="button gk-add-field-btn" data-type="number" data-label="<?php esc_attr_e( 'Number', 'gatewaykit' ); ?>" data-default-label="<?php esc_attr_e( 'Number', 'gatewaykit' ); ?>" data-placeholder=""><span class="dashicons dashicons-calculator"></span> <?php esc_html_e( 'Number', 'gatewaykit' ); ?></button>
							<button type="button" class="button gk-add-field-btn" data-type="textarea" data-label="<?php esc_attr_e( 'Textarea', 'gatewaykit' ); ?>" data-default-label="<?php esc_attr_e( 'Notes / Message', 'gatewaykit' ); ?>" data-placeholder=""><span class="dashicons dashicons-editor-paragraph"></span> <?php esc_html_e( 'Textarea', 'gatewaykit' ); ?></button>
						</div>
					</div>
					<div class="gk-add-field-group">
						<div class="gk-add-field-group-title"><?php esc_html_e( 'Choices & Options', 'gatewaykit' ); ?></div>
						<div class="gk-add-field-buttons">
							<button type="button" class="button gk-add-field-btn" data-type="select" data-label="<?php esc_attr_e( 'Select', 'gatewaykit' ); ?>" data-default-label="<?php esc_attr_e( 'Dropdown Select', 'gatewaykit' ); ?>" data-placeholder="<?php esc_attr_e( 'Select option...', 'gatewaykit' ); ?>"><span class="dashicons dashicons-arrow-down-alt2"></span> <?php esc_html_e( 'Dropdown (Select)', 'gatewaykit' ); ?></button>
							<button type="button" class="button gk-add-field-btn" data-type="radio" data-label="<?php esc_attr_e( 'Radio', 'gatewaykit' ); ?>" data-default-label="<?php esc_attr_e( 'Choose Option', 'gatewaykit' ); ?>" data-placeholder=""><span class="dashicons dashicons-marker"></span> <?php esc_html_e( 'Radio Buttons', 'gatewaykit' ); ?></button>
							<button type="button" class="button gk-add-field-btn" data-type="checkbox" data-label="<?php esc_attr_e( 'Checkbox', 'gatewaykit' ); ?>" data-default-label="<?php esc_attr_e( 'I agree to the terms', 'gatewaykit' ); ?>" data-placeholder=""><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'Checkbox (Single)', 'gatewaykit' ); ?></button>
							<button type="button" class="button gk-add-field-btn" data-type="checkbox-group" data-label="<?php esc_attr_e( 'Checkbox Group', 'gatewaykit' ); ?>" data-default-label="<?php esc_attr_e( 'Select Items', 'gatewaykit' ); ?>" data-placeholder=""><span class="dashicons dashicons-forms"></span> <?php esc_html_e( 'Checkbox Group (Multi)', 'gatewaykit' ); ?></button>
						</div>
					</div>
					<div class="gk-add-field-group">
						<div class="gk-add-field-group-title"><?php esc_html_e( 'Payment & Conversion', 'gatewaykit' ); ?></div>
						<div class="gk-add-field-buttons">
							<button type="button" class="button gk-add-field-btn" data-type="discount" data-pro="1" data-label="<?php esc_attr_e( 'Discount Code', 'gatewaykit' ); ?>" data-default-label="<?php esc_attr_e( 'Discount Code', 'gatewaykit' ); ?>" data-placeholder="<?php esc_attr_e( 'Enter coupon code', 'gatewaykit' ); ?>"><span class="dashicons dashicons-tag"></span> <?php esc_html_e( 'Discount Code', 'gatewaykit' ); ?> <span class="gk-pro-badge">PRO</span></button>
						</div>
					</div>
					<div class="gk-add-field-group">
						<div class="gk-add-field-group-title"><?php esc_html_e( 'Layout & Special', 'gatewaykit' ); ?></div>
						<div class="gk-add-field-buttons">
							<button type="button" class="button gk-add-field-btn" data-type="date" data-label="<?php esc_attr_e( 'Date', 'gatewaykit' ); ?>" data-default-label="<?php esc_attr_e( 'Date', 'gatewaykit' ); ?>" data-placeholder=""><span class="dashicons dashicons-calendar-alt"></span> <?php esc_html_e( 'Date Picker', 'gatewaykit' ); ?></button>
							<button type="button" class="button gk-add-field-btn" data-type="hidden" data-label="<?php esc_attr_e( 'Hidden', 'gatewaykit' ); ?>" data-default-label="<?php esc_attr_e( 'Hidden Field', 'gatewaykit' ); ?>" data-placeholder=""><span class="dashicons dashicons-hidden"></span> <?php esc_html_e( 'Hidden Field', 'gatewaykit' ); ?></button>
							<button type="button" class="button gk-add-field-btn" data-type="heading" data-label="<?php esc_attr_e( 'Heading', 'gatewaykit' ); ?>" data-default-label="<?php esc_attr_e( 'Section Heading', 'gatewaykit' ); ?>" data-placeholder=""><span class="dashicons dashicons-heading"></span> <?php esc_html_e( 'Section Heading', 'gatewaykit' ); ?></button>
							<button type="button" class="button gk-add-field-btn" data-type="divider" data-label="<?php esc_attr_e( 'Divider', 'gatewaykit' ); ?>" data-default-label="<?php esc_attr_e( 'Divider Line', 'gatewaykit' ); ?>" data-placeholder=""><span class="dashicons dashicons-minus"></span> <?php esc_html_e( 'Divider Line', 'gatewaykit' ); ?></button>
						</div>
					</div>
				</div>
			</div>
		</div>

		<!-- 3. Enabled Payment Gateways -->
		<div class="gk-form-section">
			<h3><span class="dashicons dashicons-shield"></span> <?php esc_html_e( 'Payment Gateways', 'gatewaykit' ); ?></h3>
			<p class="description" style="margin-bottom:12px;">
				<?php esc_html_e( 'Select which gateways are available on this form. If none are selected, all globally enabled gateways will be shown.', 'gatewaykit' ); ?>
			</p>
			<div class="gk-checkbox-grid">
				<?php
				$selected_gateways = (array) ( $config['gateways'] ?? array() );
				foreach ( $available_gateways as $id => $class_name ) :
					$gateway = $gateway_manager->get_gateway( $id );
					if ( ! $gateway instanceof GatewayKit_Payment_Gateway_Interface ) {
						continue;
					}
					$is_checked = empty( $selected_gateways ) || in_array( $id, $selected_gateways, true );
					$gw_title   = method_exists( $gateway, 'get_gateway_name' ) ? $gateway->get_gateway_name() : ( method_exists( $gateway, 'get_title' ) ? $gateway->get_title() : ucfirst( $id ) );
					?>
					<label class="gk-checkbox-item">
						<input type="checkbox" name="gk_config[gateways][]" value="<?php echo esc_attr( $id ); ?>" <?php checked( $is_checked ); ?> />
						<span><?php echo esc_html( $gw_title ); ?></span>
						<?php if ( ! $gateway->is_available() ) : ?>
							<small style="color:#ef4444;">(<?php esc_html_e( 'needs setup', 'gatewaykit' ); ?>)</small>
						<?php endif; ?>
					</label>
				<?php endforeach; ?>
			</div>
		</div>

		<!-- 4. Text & Confirmation Settings -->
		<div class="gk-form-section">
			<h3><span class="dashicons dashicons-admin-settings"></span> <?php esc_html_e( 'Button & Confirmation', 'gatewaykit' ); ?></h3>
			<div class="gk-row">
				<div class="gk-col">
					<label class="gk-label" for="gk_submit_label"><?php esc_html_e( 'Button Label:', 'gatewaykit' ); ?></label>
					<input type="text" name="gk_config[submit_label]" id="gk_submit_label" value="<?php echo esc_attr( $config['submit_label'] ); ?>" class="regular-text" />
				</div>
				<div class="gk-col">
					<label class="gk-label" for="gk_success_url"><?php esc_html_e( 'Custom Success / Redirect URL (optional):', 'gatewaykit' ); ?></label>
					<input type="url" name="gk_config[success_url]" id="gk_success_url" value="<?php echo esc_attr( $config['success_url'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr( home_url( '/receipt/' ) ); ?>" />
					<p class="description"><?php esc_html_e( 'Leave blank to use the default Payment Receipt page automatically.', 'gatewaykit' ); ?></p>
				</div>
			</div>
			<div class="gk-row">
				<div class="gk-col">
					<label class="gk-label" for="gk_description"><?php esc_html_e( 'Product / Service Description (optional):', 'gatewaykit' ); ?></label>
					<input type="text" name="gk_config[description]" id="gk_description" value="<?php echo esc_attr( $config['description'] ); ?>" class="large-text" placeholder="<?php esc_attr_e( 'Payment for service or invoice...', 'gatewaykit' ); ?>" />
				</div>
			</div>
			<div class="gk-row">
				<div class="gk-col">
					<label class="gk-label" for="gk_amount_position"><?php esc_html_e( 'Amount Section Position:', 'gatewaykit' ); ?></label>
					<select name="gk_config[amount_position]" id="gk_amount_position">
						<option value="after" <?php selected( $config['amount_position'] ?? 'after', 'after' ); ?>><?php esc_html_e( 'After Form Fields (Default)', 'gatewaykit' ); ?></option>
						<option value="before" <?php selected( $config['amount_position'] ?? 'after', 'before' ); ?>><?php esc_html_e( 'Before Form Fields', 'gatewaykit' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'Choose whether payment amounts or donation options appear before or after customer input fields.', 'gatewaykit' ); ?></p>
				</div>
			</div>
		</div>

		<!-- 5. Form Style & Appearance -->
		<div class="gk-form-section">
			<h3><span class="dashicons dashicons-art"></span> <?php esc_html_e( 'Form Style & Appearance', 'gatewaykit' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Select a visual style preset for your standalone payment form.', 'gatewaykit' ); ?></p>
			<?php
			$is_pro        = function_exists( 'gatewaykit_is_pro_licensed' ) && gatewaykit_is_pro_licensed();
			$current_style = $config['style_preset'] ?? ( $is_pro ? 'gatewaykit' : 'modern' );
			if ( ! $is_pro && ! in_array( $current_style, array( 'theme', 'modern' ), true ) ) {
				$current_style = 'modern';
			}
			?>
			<div class="gk-style-preset-cards">
				<label class="gk-style-preset-card <?php echo ( 'theme' === $current_style ) ? 'is-selected' : ''; ?>">
					<input type="radio" name="gk_config[style_preset]" value="theme" <?php checked( $current_style, 'theme' ); ?> />
					<div class="gk-style-preset-swatch gk-swatch-theme" style="background:#94a3b8;border:1px solid #64748b;"></div>
					<div class="gk-style-preset-info">
						<strong><?php esc_html_e( 'Theme Default', 'gatewaykit' ); ?></strong>
						<span><?php esc_html_e( 'Inherits clean typographic styles from your active WordPress theme.', 'gatewaykit' ); ?></span>
					</div>
				</label>

				<label class="gk-style-preset-card <?php echo ( 'modern' === $current_style ) ? 'is-selected' : ''; ?>">
					<input type="radio" name="gk_config[style_preset]" value="modern" <?php checked( $current_style, 'modern' ); ?> />
					<div class="gk-style-preset-swatch gk-swatch-modern" style="background:linear-gradient(135deg,#2563eb 0%,#1d4ed8 100%);border:1px solid #1d4ed8;"></div>
					<div class="gk-style-preset-info">
						<strong><?php esc_html_e( 'Modern Clean', 'gatewaykit' ); ?></strong>
						<span><?php esc_html_e( 'Modern rounded borders, vibrant blue gradient button, refined pills.', 'gatewaykit' ); ?></span>
					</div>
				</label>

				<label class="gk-style-preset-card <?php echo ( 'gatewaykit' === $current_style ) ? 'is-selected' : ''; ?> <?php echo ! $is_pro ? 'is-pro-locked' : ''; ?>">
					<input type="radio" name="gk_config[style_preset]" value="gatewaykit" <?php checked( $current_style, 'gatewaykit' ); ?> <?php disabled( ! $is_pro ); ?> />
					<div class="gk-style-preset-swatch gk-swatch-gatewaykit" style="background:linear-gradient(135deg,#150f23 0%,#2b1747 60%,#c2ef4e 100%);border:1.5px solid #c2ef4e;box-shadow:0 2px 4px rgba(21,15,35,0.35);"></div>
					<div class="gk-style-preset-info">
						<strong><?php esc_html_e( 'GatewayKit Midnight', 'gatewaykit' ); ?><?php echo ! $is_pro ? '<span class="gk-pro-badge">' . esc_html__( 'PRO', 'gatewaykit' ) . '</span>' : ''; ?></strong>
						<span><?php esc_html_e( 'Deep midnight navy canvas, electric lime CTA & accents, light typography.', 'gatewaykit' ); ?></span>
					</div>
				</label>

				<label class="gk-style-preset-card <?php echo ( 'card' === $current_style ) ? 'is-selected' : ''; ?> <?php echo ! $is_pro ? 'is-pro-locked' : ''; ?>">
					<input type="radio" name="gk_config[style_preset]" value="card" <?php checked( $current_style, 'card' ); ?> <?php disabled( ! $is_pro ); ?> />
					<div class="gk-style-preset-swatch gk-swatch-card" style="background:#0f172a;border:1px solid #000000;box-shadow:0 2px 4px rgba(0,0,0,0.2);"></div>
					<div class="gk-style-preset-info">
						<strong><?php esc_html_e( 'Elevated Card', 'gatewaykit' ); ?><?php echo ! $is_pro ? '<span class="gk-pro-badge">' . esc_html__( 'PRO', 'gatewaykit' ) . '</span>' : ''; ?></strong>
						<span><?php esc_html_e( 'Deep elevation shadow, dark high-contrast button, polished frame.', 'gatewaykit' ); ?></span>
					</div>
				</label>

				<label class="gk-style-preset-card <?php echo ( 'custom' === $current_style ) ? 'is-selected' : ''; ?> <?php echo ! $is_pro ? 'is-pro-locked' : ''; ?>">
					<input type="radio" name="gk_config[style_preset]" value="custom" <?php checked( $current_style, 'custom' ); ?> <?php disabled( ! $is_pro ); ?> />
					<div class="gk-style-preset-swatch gk-swatch-custom" style="background:repeating-linear-gradient(45deg,#e2e8f0,#e2e8f0 4px,#cbd5e1 4px,#cbd5e1 8px);border:1px solid #94a3b8;"></div>
					<div class="gk-style-preset-info">
						<strong><?php esc_html_e( 'Custom CSS', 'gatewaykit' ); ?><?php echo ! $is_pro ? '<span class="gk-pro-badge">' . esc_html__( 'PRO', 'gatewaykit' ) . '</span>' : ''; ?></strong>
						<span><?php esc_html_e( 'Apply your own custom CSS rules layered over the default theme styles.', 'gatewaykit' ); ?></span>
					</div>
				</label>
			</div>

			<?php if ( ! $is_pro ) : ?>
				<div class="gk-pro-notice-callout" style="margin-top:12px;">
					<span class="dashicons dashicons-lock"></span>
					<?php
					printf(
						/* translators: %s: upgrade link */
						esc_html__( 'GatewayKit Midnight, Elevated Card, and Custom CSS require %s.', 'gatewaykit' ),
						'<a href="' . esc_url( function_exists( 'gatewaykit_get_upgrade_url' ) ? gatewaykit_get_upgrade_url() : 'https://gatewaykit.com/pro/' ) . '" target="_blank">' . esc_html__( 'GatewayKit Pro', 'gatewaykit' ) . '</a>'
					);
					?>
				</div>
			<?php endif; ?>

			<div id="gk-custom-css-box" style="<?php echo ( $is_pro && 'custom' === $current_style ) ? 'display:block;' : 'display:none;'; ?> margin-top:16px;">
				<label class="gk-label" for="gk_custom_css"><?php esc_html_e( 'Custom CSS Rules:', 'gatewaykit' ); ?></label>
				<textarea name="gk_config[custom_css]" id="gk_custom_css" rows="6" class="large-text code" placeholder="<?php esc_attr_e( '.gatewaykit-form-wrap { border-color: #3b82f6; }', 'gatewaykit' ); ?>"><?php echo esc_textarea( $config['custom_css'] ?? '' ); ?></textarea>
				<p class="description"><?php esc_html_e( 'Enter custom CSS for this form. HTML tags and script/style tags will be stripped automatically.', 'gatewaykit' ); ?></p>
			</div>
		</div>

		<?php if ( ! $is_pro ) : ?>
			<div id="gk-discount-upsell-modal" class="gk-upsell-modal-backdrop" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="gk-upsell-title">
				<div class="gk-upsell-modal">
					<button type="button" class="gk-upsell-modal-close" aria-label="<?php esc_attr_e( 'Close', 'gatewaykit' ); ?>"><span class="dashicons dashicons-no-alt"></span></button>
					<div class="gk-upsell-icon-wrap">
						<span class="dashicons dashicons-tag"></span>
					</div>
					<h3 id="gk-upsell-title" class="gk-upsell-title"><?php esc_html_e( 'Unlock Discount Codes with GatewayKit Pro', 'gatewaykit' ); ?></h3>
					<p class="gk-upsell-desc"><?php esc_html_e( 'Create percentage or fixed-amount discount coupons, set usage limits, set expiration dates, and boost checkout conversions with GatewayKit Pro.', 'gatewaykit' ); ?></p>
					<div class="gk-upsell-actions">
						<a href="<?php echo esc_url( function_exists( 'gatewaykit_get_upgrade_url' ) ? gatewaykit_get_upgrade_url() : 'https://gatewaykit.pourmirzai.com/' ); ?>" class="button button-primary button-hero gk-upsell-btn-primary" target="_blank" rel="noopener noreferrer">
							<span class="dashicons dashicons-star-filled"></span>
							<?php esc_html_e( 'Get GatewayKit Pro', 'gatewaykit' ); ?>
						</a>
						<button type="button" class="button gk-upsell-btn-secondary gk-upsell-cancel"><?php esc_html_e( 'Maybe Later', 'gatewaykit' ); ?></button>
					</div>
				</div>
			</div>
		<?php endif; ?>

		<script>
		document.addEventListener('DOMContentLoaded', function() {
			var toggles = document.querySelectorAll('.gk-amount-mode-toggle');
			var fixedBox = document.getElementById('gk-mode-fixed-box');
			var donationBox = document.getElementById('gk-mode-donation-box');
			var customBox = document.getElementById('gk-mode-custom-box');

			function updateAmountMode() {
				var selected = document.querySelector('.gk-amount-mode-toggle:checked');
				if (!selected) return;
				var val = selected.value;
				if (fixedBox) fixedBox.style.display = (val === 'fixed') ? 'block' : 'none';
				if (donationBox) donationBox.style.display = (val === 'donation') ? 'block' : 'none';
				if (customBox) customBox.style.display = (val === 'custom') ? 'block' : 'none';
			}

			toggles.forEach(function(radio) {
				radio.addEventListener('change', updateAmountMode);
			});
		});
		</script>
		<?php
	}

	/**
	 * Enqueue admin stylesheets and scripts for the Form Builder.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_admin_assets( $hook ) {
		unset( $hook );
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || GatewayKit_Form_CPT::POST_TYPE !== $screen->post_type ) {
			return;
		}

		$suffix = defined( 'WP_DEBUG' ) && WP_DEBUG ? '' : '.min';

		wp_enqueue_style(
			'gatewaykit-admin-form-builder',
			GATEWAYKIT_PLUGIN_URL . 'assets/css/admin-form-builder' . $suffix . '.css',
			array( 'dashicons' ),
			GATEWAYKIT_VERSION
		);

		if ( in_array( $screen->base, array( 'post', 'post-new' ), true ) ) {
			wp_enqueue_script( 'jquery-ui-sortable' );

			wp_enqueue_script(
				'gatewaykit-admin-form-builder',
				GATEWAYKIT_PLUGIN_URL . 'assets/js/admin-form-builder' . $suffix . '.js',
				array( 'jquery', 'jquery-ui-sortable' ),
				GATEWAYKIT_VERSION,
				true
			);

			$is_pro      = function_exists( 'gatewaykit_is_pro_licensed' ) && gatewaykit_is_pro_licensed();
			$upgrade_url = function_exists( 'gatewaykit_get_upgrade_url' )
				? gatewaykit_get_upgrade_url()
				: 'https://gatewaykit.pourmirzai.com/';

			wp_localize_script(
				'gatewaykit-admin-form-builder',
				'gatewaykitFormBuilder',
				array(
					'isPro'      => $is_pro,
					'upgradeUrl' => esc_url( $upgrade_url ),
					'i18n'       => array(
						'deleteConfirm'     => __( 'Are you sure you want to delete this field?', 'gatewaykit' ),
						'cannotDeleteEmail' => __( 'An email field is required for sending payment receipts and cannot be deleted.', 'gatewaykit' ),
					),
				)
			);
		}
	}

	/**
	 * Render starter template buttons above the forms table list.
	 *
	 * @param array $views Existing views array.
	 * @return array
	 */
	public function render_template_buttons( $views ) {
		$templates = GatewayKit_Form_Templates::get_templates();
		?>
		<div class="gk-templates-bar">
			<span class="gk-templates-bar-title"><span class="dashicons dashicons-layout"></span> <?php esc_html_e( 'Starter Templates:', 'gatewaykit' ); ?></span>
			<div class="gk-templates-bar-buttons">
				<?php foreach ( $templates as $tid => $tpl ) : ?>
					<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . GatewayKit_Form_CPT::POST_TYPE . '&template=' . $tid ) ); ?>" class="button button-secondary">
						<span class="dashicons <?php echo esc_attr( $tpl['icon'] ); ?>"></span>
						<?php echo esc_html( $tpl['title'] ); ?>
					</a>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
		return $views;
	}
}
