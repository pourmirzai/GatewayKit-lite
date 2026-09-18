<?php
/**
 * GatewayKit Admin Notices
 *
 * Handles admin notices, dismissals, and notice assets.
 *
 * @package GatewayKit
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GatewayKit_Admin_Notices
 */
class GatewayKit_Admin_Notices {

	/**
	 * Determine whether this install is running the Lite (free) build.
	 *
	 * @return bool
	 */
	public static function is_lite_build() {
		return ! class_exists( 'GatewayKit_Pro_Features' );
	}

	/**
	 * Show a dismissible admin notice promoting GatewayKit Pro (only when Pro is absent).
	 */
	public static function maybe_show_pro_upgrade_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Only show the upsell on the Lite build.
		if ( ! self::is_lite_build() ) {
			return;
		}

		// Freemius's native "Add-Ons" submenu replaces this custom notice.
		if ( function_exists( 'gatewaykit_fs' ) ) {
			return;
		}

		$user_id = get_current_user_id();
		if ( get_user_meta( $user_id, 'gatewaykit_pro_notice_dismissed', true ) ) {
			return;
		}

		$upgrade_url = apply_filters( 'gatewaykit_pro_upgrade_url', 'https://gatewaykit.pourmirzai.com/' );

		?>
		<div class="notice notice-info is-dismissible" id="gatewaykit-pro-notice">
			<p>
				<strong><?php esc_html_e( 'GatewayKit Pro', 'gatewaykit' ); ?></strong>
				<?php esc_html_e( 'unlocks discount codes, outgoing webhooks, white label mode, payment info fields, advanced transaction log, per-form redirects, and partial payments.', 'gatewaykit' ); ?>
				<a href="<?php echo esc_url( $upgrade_url ); ?>" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Get GatewayKit Pro &rarr;', 'gatewaykit' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Show an admin notice when the database schema has been updated.
	 */
	public static function maybe_show_db_update_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$db_version = get_transient( 'gatewaykit_db_updated' );
		if ( false === $db_version ) {
			return;
		}

		?>
		<div class="notice notice-success is-dismissible">
			<p>
				<strong><?php esc_html_e( 'GatewayKit Database Updated', 'gatewaykit' ); ?></strong>
				<?php
				printf(
					/* translators: %s: database version */
					esc_html__( 'The database schema has been updated to version %s. Any missing tables or columns have been created.', 'gatewaykit' ),
					esc_html( $db_version )
				);
				?>
			</p>
		</div>
		<?php

		delete_transient( 'gatewaykit_db_updated' );
	}

	/**
	 * Show an admin notice when the WordPress secret constants required for
	 * at-rest encryption are missing.
	 */
	public static function maybe_show_crypto_key_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( class_exists( 'GatewayKit_Crypto' ) && GatewayKit_Crypto::is_available() ) {
			return;
		}

		?>
		<div class="notice notice-error">
			<p>
				<strong><?php esc_html_e( 'GatewayKit', 'gatewaykit' ); ?></strong>
				<?php
				echo wp_kses(
					sprintf(
						/* translators: %s: wp-config.php file name */
						__( 'cannot encrypt payment gateway credentials because the WordPress secret constants (SECURE_AUTH_KEY / AUTH_KEY) are missing from %s. Add them using the WordPress secret-key generator, then re-save your gateway settings.', 'gatewaykit' ),
						'<code>wp-config.php</code>'
					),
					array( 'code' => array() )
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * AJAX handler: dismiss the Pro upgrade notice (per-user).
	 */
	public static function dismiss_pro_notice() {
		check_ajax_referer( 'gatewaykit_dismiss_pro_notice', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Security check failed.', 'gatewaykit' ) );
		}

		update_user_meta( get_current_user_id(), 'gatewaykit_pro_notice_dismissed', 1 );

		wp_send_json_success();
	}

	/**
	 * Elementor Pro missing notice.
	 */
	public static function elementor_pro_missing_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['activate'] ) ) {
			unset( $_GET['activate'] );
		}

		$message = sprintf(
			/* translators: 1: Plugin name 2: Elementor Pro */
			esc_html__( '"%1$s" requires "%2$s" to be installed and activated.', 'gatewaykit' ),
			'<strong>' . esc_html__( 'GatewayKit', 'gatewaykit' ) . '</strong>',
			'<strong>' . esc_html__( 'Elementor Pro', 'gatewaykit' ) . '</strong>'
		);

		printf( '<div class="notice notice-warning is-dismissible"><p>%1$s</p></div>', wp_kses( $message, array( 'strong' => array() ) ) );
	}

	/**
	 * Enqueue admin-side assets (notice interaction scripts).
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public static function enqueue_admin_assets( $hook ) {
		unset( $hook ); // Parameter required by WP admin_enqueue_scripts action.
		$suffix = defined( 'WP_DEBUG' ) && WP_DEBUG ? '' : '.min';

		wp_enqueue_script(
			'gatewaykit-admin-notices',
			GATEWAYKIT_PLUGIN_URL . 'assets/js/admin-notices' . $suffix . '.js',
			array(),
			GATEWAYKIT_VERSION,
			true
		);

		wp_localize_script(
			'gatewaykit-admin-notices',
			'GatewayKitAdminNotices',
			array(
				'ajaxurl'  => admin_url( 'admin-ajax.php' ),
				'proNonce' => wp_create_nonce( 'gatewaykit_dismiss_pro_notice' ),
				'i18n'     => array(
					'ajaxError'  => __( 'AJAX error occurred. Please try again.', 'gatewaykit' ),
					'dismissing' => __( 'Dismissing...', 'gatewaykit' ),
				),
			)
		);
	}
}
