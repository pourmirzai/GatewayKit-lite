<?php
/**
 * GatewayKit Form Admin List
 *
 * Handles admin list table columns, duplication, and notices for payment forms.
 *
 * @package GatewayKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GatewayKit_Form_Admin_List
 */
class GatewayKit_Form_Admin_List {

	/**
	 * Single instance.
	 *
	 * @var GatewayKit_Form_Admin_List|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return GatewayKit_Form_Admin_List
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
		add_filter( 'manage_' . GatewayKit_Form_CPT::POST_TYPE . '_posts_columns', array( $this, 'register_admin_columns' ) );
		add_action( 'manage_' . GatewayKit_Form_CPT::POST_TYPE . '_posts_custom_column', array( $this, 'render_admin_columns' ), 10, 2 );
		add_filter( 'post_row_actions', array( $this, 'add_duplicate_row_action' ), 10, 2 );
		add_action( 'admin_action_gatewaykit_duplicate_form', array( $this, 'handle_duplicate_form' ) );
		add_action( 'admin_notices', array( $this, 'render_admin_notices' ) );
	}

	/**
	 * Register custom columns for the forms list table.
	 *
	 * @param array $columns Existing columns.
	 * @return array Modified columns.
	 */
	public function register_admin_columns( $columns ) {
		$new_columns = array();
		foreach ( $columns as $key => $title ) {
			$new_columns[ $key ] = $title;
			if ( 'title' === $key ) {
				$new_columns['gk_shortcode'] = __( 'Shortcode', 'gatewaykit' );
				$new_columns['gk_mode']      = __( 'Type', 'gatewaykit' );
				$new_columns['gk_amount']    = __( 'Amount', 'gatewaykit' );
				$new_columns['gk_currency']  = __( 'Currency', 'gatewaykit' );
			}
		}
		return $new_columns;
	}

	/**
	 * Render custom column content.
	 *
	 * @param string $column  Column identifier.
	 * @param int    $post_id Post ID.
	 */
	public function render_admin_columns( $column, $post_id ) {
		$config = get_post_meta( $post_id, GatewayKit_Form_CPT::META_KEY, true );
		if ( ! is_array( $config ) ) {
			$config = GatewayKit_Form_CPT::get_default_config();
		}

		switch ( $column ) {
			case 'gk_shortcode':
				printf(
					'<code>[gatewaykit_form id="%d"]</code>',
					absint( $post_id )
				);
				break;

			case 'gk_mode':
				$mode = $config['amount_mode'] ?? 'fixed';
				if ( 'donation' === $mode ) {
					echo '<span class="dashicons dashicons-heart" style="color:#ec4899; vertical-align:text-bottom;"></span> ' . esc_html__( 'Donation', 'gatewaykit' );
				} elseif ( 'custom' === $mode ) {
					echo '<span class="dashicons dashicons-edit" style="color:#0284c7; vertical-align:text-bottom;"></span> ' . esc_html__( 'Custom', 'gatewaykit' );
				} else {
					echo '<span class="dashicons dashicons-cart" style="color:#10b981; vertical-align:text-bottom;"></span> ' . esc_html__( 'Fixed Price', 'gatewaykit' );
				}
				break;

			case 'gk_amount':
				$mode = $config['amount_mode'] ?? 'fixed';
				if ( 'donation' === $mode ) {
					echo esc_html( $config['donation_presets'] ?? '10, 25, 50' );
				} elseif ( 'custom' === $mode ) {
					esc_html_e( 'User input', 'gatewaykit' );
				} else {
					echo esc_html( number_format( (float) ( $config['fixed_amount'] ?? 0 ), 2 ) );
				}
				break;

			case 'gk_currency':
				echo '<strong>' . esc_html( strtoupper( $config['currency'] ?? 'USD' ) ) . '</strong>';
				break;
		}
	}

	/**
	 * Add "Duplicate" row action to the form post list table.
	 *
	 * @param array   $actions Current row actions.
	 * @param WP_Post $post    Post object.
	 * @return array
	 */
	public function add_duplicate_row_action( $actions, $post ) {
		if ( GatewayKit_Form_CPT::POST_TYPE !== $post->post_type || ! current_user_can( 'edit_posts' ) ) {
			return $actions;
		}

		$duplicate_url = wp_nonce_url(
			admin_url( 'admin.php?action=gatewaykit_duplicate_form&post=' . $post->ID ),
			'gatewaykit_duplicate_form_' . $post->ID
		);

		$actions['gatewaykit_duplicate'] = sprintf(
			'<a href="%1$s" aria-label="%2$s">%3$s</a>',
			esc_url( $duplicate_url ),
			/* translators: %s: Form title */
			esc_attr( sprintf( __( 'Duplicate &#8220;%s&#8221;', 'gatewaykit' ), $post->post_title ) ),
			esc_html__( 'Duplicate', 'gatewaykit' )
		);

		return $actions;
	}

	/**
	 * Handle duplicate form action.
	 */
	public function handle_duplicate_form() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to duplicate this form.', 'gatewaykit' ) );
		}

		$post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;
		if ( ! $post_id || ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'gatewaykit_duplicate_form_' . $post_id ) ) {
			wp_die( esc_html__( 'Security check failed. Please try again.', 'gatewaykit' ) );
		}

		$source = get_post( $post_id );
		if ( ! $source || GatewayKit_Form_CPT::POST_TYPE !== $source->post_type ) {
			wp_die( esc_html__( 'Payment form not found.', 'gatewaykit' ) );
		}

		/* translators: %s: Original form title */
		$new_title = sprintf( __( '%s (Copy)', 'gatewaykit' ), $source->post_title );

		$new_post_id = wp_insert_post(
			array(
				'post_title'     => $new_title,
				'post_status'    => 'draft',
				'post_type'      => GatewayKit_Form_CPT::POST_TYPE,
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
			)
		);

		if ( ! is_wp_error( $new_post_id ) && $new_post_id ) {
			$meta = get_post_meta( $post_id, GatewayKit_Form_CPT::META_KEY, true );
			if ( ! empty( $meta ) ) {
				update_post_meta( $new_post_id, GatewayKit_Form_CPT::META_KEY, $meta );
			}

			wp_safe_redirect(
				add_query_arg(
					array(
						'post_type'             => GatewayKit_Form_CPT::POST_TYPE,
						'gatewaykit_duplicated' => 1,
					),
					admin_url( 'edit.php' )
				)
			);
			exit;
		}

		wp_die( esc_html__( 'Failed to duplicate form.', 'gatewaykit' ) );
	}

	/**
	 * Render admin dismissible notices (e.g. after form duplication).
	 */
	public function render_admin_notices() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || GatewayKit_Form_CPT::POST_TYPE !== $screen->post_type ) {
			return;
		}

		if ( ! empty( $_GET['gatewaykit_duplicated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'Payment form duplicated successfully as draft.', 'gatewaykit' ); ?></p>
			</div>
			<?php
		}
	}
}
