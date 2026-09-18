<?php
/**
 * GatewayKit Installer
 *
 * Handles plugin activation, deactivation, default options, and initial sample content.
 *
 * @package GatewayKit
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GatewayKit_Installer
 */
class GatewayKit_Installer {

	/**
	 * Plugin activation logic.
	 *
	 * @throws Exception If critical database initialization fails.
	 */
	public static function activate() {
		try {
			// Load required dependencies for activation.
			require_once GATEWAYKIT_PLUGIN_DIR . 'src/Core/class-autoloader.php';

			// Initialize autoloader explicitly for activation context.
			GatewayKit_Autoloader::get_instance();

			// Create database tables (single source of truth in the DB
			// manager — idempotent dbDelta). This also stamps the schema
			// version so no update notice is ever shown on a fresh install.
			GatewayKit_Database_Manager::get_instance()->check_db_version();

			// Set default options.
			self::set_default_options();

			// Register Form CPT immediately in activation context so wp_insert_post works.
			if ( class_exists( 'GatewayKit_Form_CPT' ) ) {
				GatewayKit_Form_CPT::register_post_type_now();
			}

			// Auto-create sample onboarding content (Receipt Page + 2 Sample Forms).
			self::maybe_create_sample_content();

			// Schedule daily cleanup of old payment logs.
			if ( ! wp_next_scheduled( 'gatewaykit_cleanup_old_logs' ) ) {
				wp_schedule_event( time(), 'daily', 'gatewaykit_cleanup_old_logs' );
			}

			// Flush rewrite rules.
			flush_rewrite_rules();

			// Initialize database manager to verify tables.
			$db_manager   = GatewayKit_Database_Manager::get_instance();
			$table_status = $db_manager->get_table_status();

			GatewayKit_Logger::get_instance()->info( 'Plugin activation completed successfully', array( 'table_status' => $table_status ) );
		} catch ( Exception $e ) {
			GatewayKit_Logger::get_instance()->error( 'Plugin activation error - ' . $e->getMessage() );

			// Throw the exception to prevent activation if critical database setup fails.
			throw new Exception( 'Failed to initialize plugin database: ' . $e->getMessage() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal exception for activation failure, not directly output
		}
	}

	/**
	 * Plugin deactivation logic.
	 */
	public static function deactivate() {
		// Clear scheduled log cleanup event.
		wp_clear_scheduled_hook( 'gatewaykit_cleanup_old_logs' );

		// Flush rewrite rules.
		flush_rewrite_rules();
	}

	/**
	 * Create default sample content (receipt page and forms) on first activation.
	 *
	 * Idempotent: checks gatewaykit_sample_content_created flag to ensure deleted
	 * content is never resurrected on subsequent reactivations.
	 */
	public static function maybe_create_sample_content() {
		if ( get_option( 'gatewaykit_sample_content_created', false ) ) {
			return;
		}

		// 1. Create default Payment Receipt page if not already existing.
		$receipt_page_id = (int) get_option( 'gatewaykit_receipt_page_id', 0 );
		if ( ! $receipt_page_id || ! get_post( $receipt_page_id ) ) {
			$receipt_page_id = wp_insert_post(
				array(
					'post_title'     => __( 'Payment Receipt', 'gatewaykit' ),
					'post_content'   => '[gatewaykit_receipt]',
					'post_status'    => 'publish',
					'post_type'      => 'page',
					'comment_status' => 'closed',
					'ping_status'    => 'closed',
				)
			);
			if ( ! is_wp_error( $receipt_page_id ) && $receipt_page_id ) {
				update_option( 'gatewaykit_receipt_page_id', $receipt_page_id );
			}
		}

		$created_form_ids = array();

		// 2. Create Form 1: Quick Payment Form (from Product Template).
		$form_cpt = class_exists( 'GatewayKit_Form_CPT' ) ? GatewayKit_Form_CPT::POST_TYPE : 'gatewaykit_form';
		$form1_id = wp_insert_post(
			array(
				'post_title'     => __( 'Quick Payment Form', 'gatewaykit' ),
				'post_status'    => 'publish',
				'post_type'      => $form_cpt,
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
			)
		);
		if ( ! is_wp_error( $form1_id ) && $form1_id ) {
			$tpl1                = class_exists( 'GatewayKit_Form_Templates' ) ? GatewayKit_Form_Templates::get_template( 'product' ) : null;
			$config1             = ( is_array( $tpl1 ) && ! empty( $tpl1['config'] ) ) ? $tpl1['config'] : ( class_exists( 'GatewayKit_Form_CPT' ) ? GatewayKit_Form_CPT::get_default_config() : array() );
			$config1['currency'] = get_option( 'gatewaykit_currency', 'USD' );
			$meta_key            = class_exists( 'GatewayKit_Form_CPT' ) ? GatewayKit_Form_CPT::META_KEY : '_gatewaykit_form_config';
			update_post_meta( $form1_id, $meta_key, $config1 );
			$created_form_ids[] = $form1_id;
		}

		// 3. Create Form 2: Donation Campaign (from Donation Template).
		$form2_id = wp_insert_post(
			array(
				'post_title'     => __( 'Donation Campaign', 'gatewaykit' ),
				'post_status'    => 'publish',
				'post_type'      => $form_cpt,
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
			)
		);
		if ( ! is_wp_error( $form2_id ) && $form2_id ) {
			$tpl2                = class_exists( 'GatewayKit_Form_Templates' ) ? GatewayKit_Form_Templates::get_template( 'donation' ) : null;
			$config2             = ( is_array( $tpl2 ) && ! empty( $tpl2['config'] ) ) ? $tpl2['config'] : ( class_exists( 'GatewayKit_Form_CPT' ) ? GatewayKit_Form_CPT::get_default_config() : array() );
			$config2['currency'] = get_option( 'gatewaykit_currency', 'USD' );
			$meta_key            = class_exists( 'GatewayKit_Form_CPT' ) ? GatewayKit_Form_CPT::META_KEY : '_gatewaykit_form_config';
			update_post_meta( $form2_id, $meta_key, $config2 );
			$created_form_ids[] = $form2_id;
		}

		if ( ! empty( $created_form_ids ) ) {
			update_option( 'gatewaykit_default_form_ids', $created_form_ids );
		}

		// Stamp flag so content is not re-created if user deletes it.
		update_option( 'gatewaykit_sample_content_created', true );
	}

	/**
	 * Set default options.
	 */
	public static function set_default_options() {
		$default_currency = GatewayKit_Gateway_Manager::get_instance()->get_default_currency();
		$defaults         = array(
			'gatewaykit_version'        => GATEWAYKIT_VERSION,
			'gatewaykit_currency'       => $default_currency,
			'gatewaykit_nonce_required' => true,
		);

		foreach ( $defaults as $option => $value ) {
			if ( ! get_option( $option ) ) {
				add_option( $option, $value );
			}
		}
	}
}
