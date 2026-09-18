<?php
/**
 * GatewayKit Form Custom Post Type
 *
 * Handles the registration, schema, and data access for standalone payment forms.
 *
 * @package GatewayKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GatewayKit_Form_CPT
 */
class GatewayKit_Form_CPT {

	/**
	 * Post type slug.
	 */
	const POST_TYPE = 'gatewaykit_form';

	/**
	 * Meta key for form configuration.
	 */
	const META_KEY = '_gatewaykit_form_config';

	/**
	 * Single instance of the class.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Get single instance.
	 *
	 * @return self
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
		add_action( 'init', array( self::class, 'register_post_type_now' ) );
		GatewayKit_Form_Admin_Ui::get_instance();
		GatewayKit_Form_Admin_Save::get_instance();
		GatewayKit_Form_Admin_List::get_instance();
	}

	/**
	 * Register the post type immediately.
	 *
	 * Can be called during plugin activation context before standard init fires.
	 */
	public static function register_post_type_now() {
		if ( post_type_exists( self::POST_TYPE ) ) {
			return;
		}

		$labels = array(
			'name'               => _x( 'Payment Forms', 'Post type general name', 'gatewaykit' ),
			'singular_name'      => _x( 'Payment Form', 'Post type singular name', 'gatewaykit' ),
			'menu_name'          => _x( 'Payment Forms', 'Admin Menu text', 'gatewaykit' ),
			'name_admin_bar'     => _x( 'Payment Form', 'Add New on Toolbar', 'gatewaykit' ),
			'add_new'            => __( 'Add New Form', 'gatewaykit' ),
			'add_new_item'       => __( 'Add New Payment Form', 'gatewaykit' ),
			'new_item'           => __( 'New Payment Form', 'gatewaykit' ),
			'edit_item'          => __( 'Edit Payment Form', 'gatewaykit' ),
			'view_item'          => __( 'View Payment Form', 'gatewaykit' ),
			'all_items'          => __( 'Payment Forms', 'gatewaykit' ),
			'search_items'       => __( 'Search Payment Forms', 'gatewaykit' ),
			'parent_item_colon'  => __( 'Parent Forms:', 'gatewaykit' ),
			'not_found'          => __( 'No payment forms found.', 'gatewaykit' ),
			'not_found_in_trash' => __( 'No payment forms found in Trash.', 'gatewaykit' ),
		);

		$args = array(
			'labels'             => $labels,
			'public'             => false,
			'publicly_queryable' => false,
			'show_ui'            => true,
			'show_in_menu'       => 'gatewaykit',
			'query_var'          => false,
			'rewrite'            => false,
			'capability_type'    => 'post',
			'has_archive'        => false,
			'hierarchical'       => false,
			'menu_position'      => 2,
			'supports'           => array( 'title' ),
			'show_in_rest'       => false,
		);

		register_post_type( self::POST_TYPE, $args );
	}

	/**
	 * Get allowed field types for standalone forms.
	 *
	 * @return array
	 */
	public static function get_allowed_field_types() {
		return array(
			'text'           => __( 'Text', 'gatewaykit' ),
			'email'          => __( 'Email', 'gatewaykit' ),
			'phone'          => __( 'Phone Number', 'gatewaykit' ),
			'number'         => __( 'Number', 'gatewaykit' ),
			'textarea'       => __( 'Textarea', 'gatewaykit' ),
			'select'         => __( 'Dropdown (Select)', 'gatewaykit' ),
			'radio'          => __( 'Radio Buttons', 'gatewaykit' ),
			'checkbox'       => __( 'Checkbox (Single/Consent)', 'gatewaykit' ),
			'checkbox-group' => __( 'Checkbox Group (Multi)', 'gatewaykit' ),
			'date'           => __( 'Date Picker', 'gatewaykit' ),
			'discount'       => __( 'Discount Code (Pro)', 'gatewaykit' ),
			'hidden'         => __( 'Hidden Field', 'gatewaykit' ),
			'heading'        => __( 'Section Heading', 'gatewaykit' ),
			'divider'        => __( 'Divider Line', 'gatewaykit' ),
		);
	}

	/**
	 * Parse a single preset item string which can be a number or labeled tier.
	 *
	 * Examples:
	 *   "25"                      => array( 'amount' => 25.0, 'label' => '' )
	 *   "25: Early Bird"          => array( 'amount' => 25.0, 'label' => 'Early Bird' )
	 *   "Early Bird: 25"          => array( 'amount' => 25.0, 'label' => 'Early Bird' )
	 *   "VIP Pass | 100"          => array( 'amount' => 100.0, 'label' => 'VIP Pass' )
	 *   "100 | VIP Pass"          => array( 'amount' => 100.0, 'label' => 'VIP Pass' )
	 *
	 * @param string $preset_raw Raw preset string.
	 * @return array Array with 'amount' (float) and 'label' (string).
	 */
	public static function parse_preset_item( $preset_raw ) {
		$str = trim( (string) $preset_raw );
		if ( '' === $str ) {
			return array(
				'amount' => 0.0,
				'label'  => '',
			);
		}

		// Check format: Amount followed by label.
		if ( preg_match( '/^([\d\.]+)\s*[:\|]\s*(.+)$/u', $str, $matches ) ) {
			return array(
				'amount' => (float) $matches[1],
				'label'  => trim( $matches[2] ),
			);
		}

		// Check format: Label followed by amount.
		if ( preg_match( '/^(.+?)\s*[:\|]\s*([\d\.]+)$/u', $str, $matches ) ) {
			return array(
				'amount' => (float) $matches[2],
				'label'  => trim( $matches[1] ),
			);
		}

		// Fallback: pure numeric amount without label.
		return array(
			'amount' => (float) $str,
			'label'  => '',
		);
	}

	/**
	 * Get default configuration for a form.
	 *
	 * @return array
	 */
	public static function get_default_config() {
		return array(
			'amount_mode'           => 'fixed', // Mode: fixed, donation, or custom.
			'fixed_amount'          => 25.00,
			'min_amount'            => 5.00,
			'max_amount'            => 0.00,
			'donation_presets'      => '10, 25, 50, 100',
			'allow_custom_donation' => 1,
			'currency'              => get_option( 'gatewaykit_currency', 'USD' ),
			'description'           => '',
			'submit_label'          => __( 'Pay Now', 'gatewaykit' ),
			'success_url'           => '',
			'gateways'              => array(), // Empty array means all available.
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
			),
			'terms_text'            => __( 'I agree to the terms and privacy policy.', 'gatewaykit' ),
			'amount_label'          => '',
			'amount_position'       => 'after',
			'style_preset'          => ( function_exists( 'gatewaykit_is_pro_licensed' ) && gatewaykit_is_pro_licensed() ) ? 'gatewaykit' : 'modern',
			'custom_css'            => '',
		);
	}

	/**
	 * Normalize fields array to modern structured list with lazy backward compatibility.
	 *
	 * @param mixed  $fields     Raw fields configuration from meta.
	 * @param string $terms_text Terms checkbox label if present.
	 * @return array Normalized array of field objects.
	 */
	public static function normalize_fields( $fields, $terms_text = '' ) {
		$normalized = array();

		// Handle empty or invalid format.
		if ( ! is_array( $fields ) || empty( $fields ) ) {
			$defaults = self::get_default_config();
			return $defaults['fields'];
		}

		// Detect old format: keys are non-numeric strings ('name', 'email', 'phone', etc.).
		$is_old_format = isset( $fields['name'] ) || isset( $fields['email'] ) || isset( $fields['phone'] ) || isset( $fields['message'] ) || isset( $fields['terms'] ) || ! isset( $fields[0]['type'] );

		if ( $is_old_format ) {
			if ( ! empty( $fields['name'] ) && 'hidden' !== $fields['name'] ) {
				$normalized[] = array(
					'id'          => 'f_name',
					'type'        => 'text',
					'label'       => __( 'Full Name', 'gatewaykit' ),
					'placeholder' => __( 'Jane Doe', 'gatewaykit' ),
					'required'    => ( 'required' === $fields['name'] ) ? 1 : 0,
					'help_text'   => '',
					'width'       => '100',
					'options'     => array(),
					'default'     => '',
				);
			}

			// Email is always included.
			$normalized[] = array(
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

			if ( ! empty( $fields['phone'] ) && 'hidden' !== $fields['phone'] ) {
				$normalized[] = array(
					'id'          => 'f_phone',
					'type'        => 'phone',
					'label'       => __( 'Phone Number', 'gatewaykit' ),
					'placeholder' => '',
					'required'    => ( 'required' === $fields['phone'] ) ? 1 : 0,
					'help_text'   => '',
					'width'       => '100',
					'options'     => array(),
					'default'     => '',
				);
			}

			if ( ! empty( $fields['message'] ) && 'hidden' !== $fields['message'] ) {
				$normalized[] = array(
					'id'          => 'f_message',
					'type'        => 'textarea',
					'label'       => __( 'Note / Message', 'gatewaykit' ),
					'placeholder' => '',
					'required'    => ( 'required' === $fields['message'] ) ? 1 : 0,
					'help_text'   => '',
					'width'       => '100',
					'options'     => array(),
					'default'     => '',
				);
			}

			if ( ! empty( $fields['terms'] ) && 'required' === $fields['terms'] ) {
				$normalized[] = array(
					'id'          => 'f_terms',
					'type'        => 'checkbox',
					'label'       => ! empty( $terms_text ) ? $terms_text : __( 'I agree to the terms and privacy policy.', 'gatewaykit' ),
					'placeholder' => '',
					'required'    => 1,
					'help_text'   => '',
					'width'       => '100',
					'options'     => array(),
					'default'     => '',
				);
			}
		} else {
			// Modern structured format: validate each field.
			$allowed_types = array_keys( self::get_allowed_field_types() );
			foreach ( $fields as $f ) {
				if ( ! is_array( $f ) ) {
					continue;
				}
				$type = isset( $f['type'] ) && in_array( $f['type'], $allowed_types, true ) ? $f['type'] : 'text';
				$id   = ! empty( $f['id'] ) ? sanitize_key( $f['id'] ) : 'f_' . wp_generate_password( 6, false, false );
				if ( ! preg_match( '/^[a-z_]/', $id ) ) {
					$id = 'f_' . $id;
				}

				$normalized[] = array(
					'id'          => $id,
					'type'        => $type,
					'label'       => isset( $f['label'] ) ? (string) $f['label'] : '',
					'placeholder' => isset( $f['placeholder'] ) ? (string) $f['placeholder'] : '',
					'required'    => ( in_array( $type, array( 'heading', 'divider', 'hidden' ), true ) ) ? 0 : ( ! empty( $f['required'] ) ? 1 : 0 ),
					'help_text'   => isset( $f['help_text'] ) ? (string) $f['help_text'] : '',
					'width'       => isset( $f['width'] ) && '50' === $f['width'] ? '50' : '100',
					'options'     => isset( $f['options'] ) && is_array( $f['options'] ) ? array_values( array_filter( $f['options'] ) ) : array(),
					'default'     => isset( $f['default'] ) ? (string) $f['default'] : '',
				);
			}
		}

		// Enforce mandatory email field for receipt delivery.
		$has_email = false;
		foreach ( $normalized as $f ) {
			if ( 'email' === $f['type'] ) {
				$has_email = true;
				break;
			}
		}

		if ( ! $has_email ) {
			$normalized[] = array(
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

		return $normalized;
	}

	/**
	 * Get merged form configuration for a given post ID.
	 *
	 * @param int $form_id Form Post ID.
	 * @return array
	 */
	public static function get_form_config( $form_id ) {
		$config   = get_post_meta( $form_id, self::META_KEY, true );
		$defaults = self::get_default_config();
		if ( ! is_array( $config ) ) {
			return $defaults;
		}
		$merged             = array_merge( $defaults, $config );
		$merged['currency'] = get_option( 'gatewaykit_currency', 'USD' );
		$merged['fields']   = self::normalize_fields( $merged['fields'] ?? array(), $merged['terms_text'] ?? '' );
		return $merged;
	}

	/**
	 * Forward register_meta_boxes to GatewayKit_Form_Admin_Ui.
	 */
	public function register_meta_boxes() {
		GatewayKit_Form_Admin_Ui::get_instance()->register_meta_boxes();
	}

	/**
	 * Forward render_embed_meta_box to GatewayKit_Form_Admin_Ui.
	 *
	 * @param WP_Post $post Post object.
	 */
	public function render_embed_meta_box( $post ) {
		GatewayKit_Form_Admin_Ui::get_instance()->render_embed_meta_box( $post );
	}

	/**
	 * Forward render_config_meta_box to GatewayKit_Form_Admin_Ui.
	 *
	 * @param WP_Post $post Post object.
	 */
	public function render_config_meta_box( $post ) {
		GatewayKit_Form_Admin_Ui::get_instance()->render_config_meta_box( $post );
	}

	/**
	 * Forward enqueue_admin_assets to GatewayKit_Form_Admin_Ui.
	 *
	 * @param string $hook Admin page hook.
	 */
	public function enqueue_admin_assets( $hook ) {
		GatewayKit_Form_Admin_Ui::get_instance()->enqueue_admin_assets( $hook );
	}

	/**
	 * Forward render_template_buttons to GatewayKit_Form_Admin_Ui.
	 *
	 * @param array $views Views list.
	 * @return array
	 */
	public function render_template_buttons( $views ) {
		return GatewayKit_Form_Admin_Ui::get_instance()->render_template_buttons( $views );
	}

	/**
	 * Forward save_form_meta to GatewayKit_Form_Admin_Save.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public function save_form_meta( $post_id, $post ) {
		GatewayKit_Form_Admin_Save::get_instance()->save_form_meta( $post_id, $post );
	}

	/**
	 * Forward register_admin_columns to GatewayKit_Form_Admin_List.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function register_admin_columns( $columns ) {
		return GatewayKit_Form_Admin_List::get_instance()->register_admin_columns( $columns );
	}

	/**
	 * Forward render_admin_columns to GatewayKit_Form_Admin_List.
	 *
	 * @param string $column  Column identifier.
	 * @param int    $post_id Post ID.
	 */
	public function render_admin_columns( $column, $post_id ) {
		GatewayKit_Form_Admin_List::get_instance()->render_admin_columns( $column, $post_id );
	}

	/**
	 * Forward add_duplicate_row_action to GatewayKit_Form_Admin_List.
	 *
	 * @param array   $actions Actions list.
	 * @param WP_Post $post    Post object.
	 * @return array
	 */
	public function add_duplicate_row_action( $actions, $post ) {
		return GatewayKit_Form_Admin_List::get_instance()->add_duplicate_row_action( $actions, $post );
	}

	/**
	 * Forward handle_duplicate_form to GatewayKit_Form_Admin_List.
	 */
	public function handle_duplicate_form() {
		GatewayKit_Form_Admin_List::get_instance()->handle_duplicate_form();
	}

	/**
	 * Forward render_admin_notices to GatewayKit_Form_Admin_List.
	 */
	public function render_admin_notices() {
		GatewayKit_Form_Admin_List::get_instance()->render_admin_notices();
	}
}
