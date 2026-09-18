<?php
/**
 * GatewayKit Gutenberg Blocks
 *
 * Handles registration and server-side rendering for GatewayKit Gutenberg blocks.
 *
 * @package GatewayKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GatewayKit_Blocks
 */
class GatewayKit_Blocks {

	/**
	 * Single instance of this class.
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
	public function __construct() {
		add_action( 'init', array( $this, 'register_blocks' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_block_editor_assets' ) );
	}

	/**
	 * Enqueue styles in the Gutenberg block editor canvas and iframe.
	 */
	public function enqueue_block_editor_assets() {
		$suffix  = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';
		$css_url = GATEWAYKIT_PLUGIN_URL . 'assets/css/frontend-form' . $suffix . '.css';
		$css_dir = GATEWAYKIT_PLUGIN_DIR . 'assets/css/frontend-form' . $suffix . '.css';

		if ( ! file_exists( $css_dir ) ) {
			$css_url = GATEWAYKIT_PLUGIN_URL . 'assets/css/frontend-form.css';
		}

		wp_enqueue_style(
			'gatewaykit-frontend-form',
			$css_url,
			array(),
			GATEWAYKIT_VERSION
		);

		wp_enqueue_style(
			'gatewaykit-form-style-modern',
			GATEWAYKIT_PLUGIN_URL . 'assets/css/form-style-modern' . $suffix . '.css',
			array( 'gatewaykit-frontend-form' ),
			GATEWAYKIT_VERSION
		);

		wp_enqueue_style(
			'gatewaykit-form-style-card',
			GATEWAYKIT_PLUGIN_URL . 'assets/css/form-style-card' . $suffix . '.css',
			array( 'gatewaykit-frontend-form' ),
			GATEWAYKIT_VERSION
		);

		wp_enqueue_style(
			'gatewaykit-form-style-gatewaykit',
			GATEWAYKIT_PLUGIN_URL . 'assets/css/form-style-gatewaykit' . $suffix . '.css',
			array( 'gatewaykit-frontend-form' ),
			GATEWAYKIT_VERSION
		);
	}

	/**
	 * Register Gutenberg blocks and editor assets.
	 */
	public function register_blocks() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		$suffix = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';
		$js_url = GATEWAYKIT_PLUGIN_URL . 'assets/js/block-payment-form' . $suffix . '.js';
		$js_dir = GATEWAYKIT_PLUGIN_DIR . 'assets/js/block-payment-form' . $suffix . '.js';

		if ( ! file_exists( $js_dir ) ) {
			$js_url = GATEWAYKIT_PLUGIN_URL . 'assets/js/block-payment-form.js';
		}

		$css_url = GATEWAYKIT_PLUGIN_URL . 'assets/css/frontend-form' . $suffix . '.css';
		$css_dir = GATEWAYKIT_PLUGIN_DIR . 'assets/css/frontend-form' . $suffix . '.css';
		if ( ! file_exists( $css_dir ) ) {
			$css_url = GATEWAYKIT_PLUGIN_URL . 'assets/css/frontend-form.css';
		}

		wp_register_style(
			'gatewaykit-frontend-form',
			$css_url,
			array(),
			GATEWAYKIT_VERSION
		);

		wp_register_script(
			'gatewaykit-block-payment-form',
			$js_url,
			array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-i18n', 'wp-server-side-render' ),
			GATEWAYKIT_VERSION,
			true
		);

		// Pass available forms and metadata to editor JS.
		$forms      = array();
		$form_posts = get_posts(
			array(
				'post_type'      => 'gatewaykit_form',
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => 100,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		if ( is_array( $form_posts ) ) {
			foreach ( $form_posts as $fp ) {
				$title = ! empty( $fp->post_title ) ? $fp->post_title : sprintf(
					/* translators: %d: form ID */
					__( 'Form #%d', 'gatewaykit' ),
					(int) $fp->ID
				);
				if ( 'draft' === $fp->post_status ) {
					$title .= ' ' . __( '(Draft)', 'gatewaykit' );
				}
				$forms[] = array(
					'id'    => (int) $fp->ID,
					'title' => $title,
				);
			}
		}

		wp_localize_script(
			'gatewaykit-block-payment-form',
			'gatewaykitBlockData',
			array(
				'forms'           => $forms,
				'newFormUrl'      => admin_url( 'post-new.php?post_type=gatewaykit_form' ),
				'editFormUrlBase' => admin_url( 'post.php?action=edit&post=' ),
				'strings'         => array(
					'title'        => __( 'GatewayKit Payment Form', 'gatewaykit' ),
					'description'  => __( 'Embed a GatewayKit payment or donation form.', 'gatewaykit' ),
					'selectForm'   => __( '-- Select a Payment Form --', 'gatewaykit' ),
					'formSettings' => __( 'Form Settings', 'gatewaykit' ),
					'chooseForm'   => __( 'Select Form', 'gatewaykit' ),
					'noFormsFound' => __( 'No payment forms found.', 'gatewaykit' ),
					'createForm'   => __( 'Create a Payment Form', 'gatewaykit' ),
					'editForm'     => __( 'Edit this form in builder ↗', 'gatewaykit' ),
					'instructions' => __( 'Select a payment form from the dropdown to embed it on this page.', 'gatewaykit' ),
				),
			)
		);

		register_block_type(
			'gatewaykit/payment-form',
			array(
				'editor_script'   => 'gatewaykit-block-payment-form',
				'editor_style'    => 'gatewaykit-frontend-form',
				'style'           => 'gatewaykit-frontend-form',
				'render_callback' => array( $this, 'render_block' ),
				'attributes'      => array(
					'formId' => array(
						'type'    => 'number',
						'default' => 0,
					),
				),
			)
		);
	}

	/**
	 * Server-side render callback for the block.
	 *
	 * @param array $attributes Block attributes.
	 * @return string Rendered HTML.
	 */
	public function render_block( $attributes ) {
		$form_id = isset( $attributes['formId'] ) ? absint( $attributes['formId'] ) : 0;

		$is_editor_preview = is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST );

		if ( ! $form_id ) {
			if ( $is_editor_preview ) {
				return '<div class="gatewaykit-block-placeholder" style="padding: 24px; border: 1.5px dashed #cbd5e1; border-radius: 8px; text-align: center; color: #64748b; background: #f8fafc; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Oxygen-Sans, Ubuntu, Cantarell, \'Helvetica Neue\', sans-serif;">' .
					'<div style="font-size: 24px; margin-bottom: 8px;">💳</div>' .
					'<p style="margin: 0 0 6px 0; font-size: 14px; font-weight: 600; color: #1e293b;">' . esc_html__( 'GatewayKit Payment Form', 'gatewaykit' ) . '</p>' .
					'<p style="margin: 0; font-size: 12px;">' . esc_html__( 'Please select a payment form in the block settings panel.', 'gatewaykit' ) . '</p>' .
				'</div>';
			}
			return '<!-- GatewayKit: No payment form selected. -->';
		}

		if ( ! class_exists( 'GatewayKit_Form_Renderer' ) ) {
			return '<!-- GatewayKit: Form renderer not found. -->';
		}

		$post = get_post( $form_id );
		if ( ! $post || 'gatewaykit_form' !== $post->post_type ) {
			if ( $is_editor_preview ) {
				return '<div class="gatewaykit-block-placeholder" style="padding: 16px; border: 1.5px dashed #ef4444; border-radius: 8px; text-align: center; color: #ef4444; background: #fef2f2; font-family: sans-serif;">' .
					'<p style="margin: 0; font-size: 13px;">' .
					/* translators: %d: form ID */
					sprintf( esc_html__( 'GatewayKit: Payment form #%d was not found or has been deleted.', 'gatewaykit' ), $form_id ) .
					'</p>' .
				'</div>';
			}
			return '<!-- GatewayKit: Payment form not found. -->';
		}

		return GatewayKit_Form_Renderer::render( $form_id );
	}
}
