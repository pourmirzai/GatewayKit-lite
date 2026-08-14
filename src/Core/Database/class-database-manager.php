<?php
/**
 * Database Manager
 *
 * Handles database schema creation and low-level table utilities.
 *
 * @package GatewayKit
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Database Manager Class
 */
class GatewayKit_Database_Manager {

	/*
	 * SECURITY MODEL (mandatory — see PRINCIPLE in docs/memory.md):
	 * Every database call is wrapped in $wpdb->prepare(). Table, column and
	 * index names are identifiers and go through the %i placeholder (available
	 * since WP 6.2, the plugin's minimum version), additionally validated by
	 * validate_identifier() / table_name_raw() as defense in depth. Values use
	 * %s / %d / %f. The only non-prepared SQL is dbDelta() CREATE TABLE
	 * statements (schema creation, not a query) and the wpdb::insert/update/
	 * delete helpers (which prepare internally).
	 *
	 * SCHEMA MODEL:
	 * This is the first public release, so there is a SINGLE schema defined in
	 * create_tables() and NO migration chain. create_tables() uses dbDelta(),
	 * which is idempotent: it creates missing tables/columns/indexes and leaves
	 * existing ones untouched, so it is safe to re-run at any time.
	 */
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Single instance
	 */
	private static $instance = null;

	/**
	 * Current database schema version.
	 *
	 * Bumping this forces create_tables() to run again on the next load
	 * (dbDelta applies any schema changes idempotently).
	 */
	const DB_VERSION = '1.2.0';

	/**
	 * Whitelist of allowed table bases for security.
	 *
	 * @var array
	 */
	private $allowed_table_bases = array(
		'gatewaykit_payment_transactions',
		'gatewaykit_payment_logs',
		'gatewaykit_discounts',
		'gatewaykit_discount_usage',
		'gatewaykit_transaction_notes',
		'gatewaykit_subscriptions',
	);

	/**
	 * Validates a MySQL identifier via strict regex and returns it RAW (no
	 * backticks), ready to be passed as a %i argument to $wpdb->prepare().
	 *
	 * Defense in depth: %i already escapes identifiers at the wpdb layer, but
	 * this guarantees the identifier matches the strict MySQL identifier
	 * charset ([a-zA-Z_][a-zA-Z0-9_]*) before it ever reaches SQL.
	 *
	 * @param string $identifier The identifier to validate.
	 * @return string The validated identifier (no backticks).
	 * @throws Exception If the identifier is invalid.
	 *
	 * @see https://developer.wordpress.org/reference/classes/wpdb/
	 * @see https://developer.wordpress.org/plugins/security/securing-input/
	 */
	private function validate_identifier( $identifier ) {
		if ( ! preg_match( '/^[a-zA-Z_][a-zA-Z0-9_]*$/', $identifier ) ) {
			throw new Exception( "Invalid identifier: $identifier" );
		}
		return $identifier;
	}

	/**
	 * Validates a MySQL identifier and returns it backticked for direct
	 * interpolation into dbDelta() CREATE TABLE statements only.
	 *
	 * For any $wpdb->query/get_results/get_var/get_row call, prefer
	 * validate_identifier() + the %i placeholder inside $wpdb->prepare().
	 *
	 * @param string $identifier The identifier to validate.
	 * @return string The backticked identifier.
	 * @throws Exception If the identifier is invalid.
	 */
	private function safe_identifier( $identifier ) {
		return '`' . $this->validate_identifier( $identifier ) . '`';
	}

	/**
	 * Returns the full table name (prefix + whitelisted base), validated and
	 * backticked for direct use in dbDelta() CREATE TABLE statements.
	 *
	 * @param string $base The table base name (must be in whitelist).
	 * @return string The full backticked table name.
	 * @throws Exception If the base is not whitelisted.
	 */
	private function table_name( $base ) {
		if ( ! in_array( $base, $this->allowed_table_bases, true ) ) {
			throw new Exception( "Table base not allowed: $base" );
		}
		global $wpdb;
		return $this->safe_identifier( $wpdb->prefix . $base );
	}

	/**
	 * Returns the raw (un-backticked) table name for use as a %i argument in
	 * $wpdb->prepare(), or for SHOW TABLES LIKE patterns / wpdb::insert/update/
	 * delete (which wrap the name in their own backticks).
	 *
	 * @param string $base The table base name (must be in whitelist).
	 * @return string The raw table name (prefix + base, no backticks).
	 * @throws Exception If the base is not whitelisted.
	 */
	private function table_name_raw( $base ) {
		if ( ! in_array( $base, $this->allowed_table_bases, true ) ) {
			throw new Exception( "Table base not allowed: $base" );
		}
		global $wpdb;
		return $wpdb->prefix . $base;
	}

	/**
	 * Get single instance
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 */
	private function __construct() {
		add_action( 'plugins_loaded', array( $this, 'check_db_version' ) );
	}

	/**
	 * Ensure the database schema exists and is current.
	 *
	 * Runs create_tables() (idempotent dbDelta) when the stored schema version
	 * is behind the current one. Called on plugins_loaded and on activation.
	 */
	public function check_db_version() {
		$current_version = get_option( 'gatewaykit_db_version', '0' );

		if ( version_compare( $current_version, self::DB_VERSION, '<' ) ) {
			$this->create_tables();
			update_option( 'gatewaykit_db_version', self::DB_VERSION );

			GatewayKit_Logger::get_instance()->info(
				'Database schema created/updated',
				array(
					'from_version' => $current_version,
					'to_version'   => self::DB_VERSION,
				)
			);

			set_transient( 'gatewaykit_db_updated', self::DB_VERSION, 60 );
		}
	}

	/**
	 * Create (or update) all plugin tables to the current schema.
	 *
	 * This is the SINGLE source of truth for the database schema. It uses
	 * dbDelta(), which is idempotent: on a fresh install it creates every
	 * table; on an existing install it adds any missing tables/columns/indexes
	 * and leaves the rest untouched. Safe to call any time (activation,
	 * version bump, or the admin "Verify schema" tool).
	 */
	public function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate  = $wpdb->get_charset_collate();
		$default_currency = GatewayKit_Gateway_Manager::get_instance()->get_default_currency();

		// Transactions table — the complete, final schema.
		$table_transactions = $this->table_name( 'gatewaykit_payment_transactions' );
		$sql_transactions   = "CREATE TABLE $table_transactions (
			id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			user_id BIGINT(20) UNSIGNED NULL,
			form_id VARCHAR(50) NOT NULL,
			post_id BIGINT(20) UNSIGNED NOT NULL,
			gateway VARCHAR(50) NOT NULL,
			authority VARCHAR(255) NULL,
			ref_id VARCHAR(255) NULL,
			amount DECIMAL(15,2) NOT NULL,
			discount_id BIGINT(20) UNSIGNED NULL,
			discount_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
			currency VARCHAR(10) DEFAULT '$default_currency',
			description TEXT,
			status ENUM('pending', 'processing', 'completed', 'failed', 'cancelled', 'refunded', 'partially_refunded') DEFAULT 'pending',
			gateway_response LONGTEXT,
			form_data LONGTEXT,
			user_data JSON,
			callback_url TEXT,
			success_url TEXT,
			failure_url TEXT,
			receipt_token VARCHAR(20) NOT NULL,
			receipt_token_created_at TIMESTAMP NULL,
			ip_address VARCHAR(45),
			user_agent TEXT,
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			completed_at TIMESTAMP NULL,
			error_message TEXT NULL,
			error_code VARCHAR(100) NULL,
			error_type ENUM('license', 'gateway', 'configuration', 'network', 'validation', 'unknown') NULL,
			error_details LONGTEXT NULL,
			error_timestamp TIMESTAMP NULL,
			INDEX idx_user_id (user_id),
			INDEX idx_authority (authority),
			INDEX idx_ref_id (ref_id),
			INDEX idx_status (status),
			INDEX idx_gateway (gateway),
			INDEX idx_created_at (created_at),
			INDEX idx_discount_id (discount_id),
			INDEX idx_error_type (error_type),
			INDEX idx_error_timestamp (error_timestamp),
			INDEX idx_user_status (user_id, status),
			INDEX idx_gateway_status (gateway, status),
			INDEX idx_status_created (status, created_at),
			INDEX idx_user_created (user_id, created_at),
			INDEX idx_form_post (form_id, post_id),
			INDEX idx_amount_status (amount, status),
			UNIQUE KEY unique_receipt_token (receipt_token)
		) $charset_collate;";
		dbDelta( $sql_transactions );

		// Logs table.
		$table_logs = $this->table_name( 'gatewaykit_payment_logs' );
		$sql_logs   = "CREATE TABLE $table_logs (
			id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			transaction_id BIGINT(20) UNSIGNED,
			level ENUM('debug', 'info', 'warning', 'error') DEFAULT 'info',
			message TEXT,
			context JSON,
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			INDEX idx_transaction_id (transaction_id),
			INDEX idx_level (level),
			INDEX idx_created_at (created_at)
		) $charset_collate;";
		dbDelta( $sql_logs );

		// Discounts table.
		$table_discounts = $this->table_name( 'gatewaykit_discounts' );
		$sql_discounts   = "CREATE TABLE $table_discounts (
			id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			code VARCHAR(100) NOT NULL,
			description TEXT NULL,
			type ENUM('fixed','percentage') NOT NULL DEFAULT 'fixed',
			value DECIMAL(15,2) NOT NULL,
			max_amount DECIMAL(15,2) NULL,
			min_amount DECIMAL(15,2) NULL,
			usage_limit INT(10) UNSIGNED NULL,
			used_count INT(10) UNSIGNED NOT NULL DEFAULT 0,
			per_user_limit INT(10) UNSIGNED NOT NULL DEFAULT 0,
			is_active TINYINT(1) NOT NULL DEFAULT 1,
			valid_from DATETIME NULL,
			valid_until DATETIME NULL,
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			UNIQUE KEY unique_code (code),
			INDEX idx_is_active (is_active)
		) $charset_collate;";
		dbDelta( $sql_discounts );

		// Discount usage table.
		$table_usage = $this->table_name( 'gatewaykit_discount_usage' );
		$sql_usage   = "CREATE TABLE $table_usage (
			id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			discount_id BIGINT(20) UNSIGNED NOT NULL,
			transaction_id BIGINT(20) UNSIGNED NOT NULL,
			user_id BIGINT(20) UNSIGNED NULL,
			amount_saved DECIMAL(15,2) NOT NULL,
			original_amount DECIMAL(15,2) NOT NULL,
			final_amount DECIMAL(15,2) NOT NULL,
			ip_address VARCHAR(45) NULL,
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			INDEX idx_discount_id (discount_id),
			INDEX idx_transaction_id (transaction_id),
			INDEX idx_user_id (user_id),
			UNIQUE KEY unique_transaction_discount (transaction_id, discount_id)
		) $charset_collate;";
		dbDelta( $sql_usage );

		// Transaction notes table (admin timeline, Feature H — v1.2.0).
		$table_notes = $this->table_name( 'gatewaykit_transaction_notes' );
		$sql_notes   = "CREATE TABLE $table_notes (
			id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			transaction_id BIGINT(20) UNSIGNED NOT NULL,
			note TEXT NOT NULL,
			author_id BIGINT(20) UNSIGNED NULL,
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			INDEX idx_transaction_id (transaction_id)
		) $charset_collate;";
		dbDelta( $sql_notes );

		// Subscriptions table (recurring payments — Pro feature).
		$table_subs = $this->table_name( 'gatewaykit_subscriptions' );
		$sql_subs   = "CREATE TABLE $table_subs (
			id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			transaction_id BIGINT(20) UNSIGNED NOT NULL,
			gateway_subscription_id VARCHAR(255) NOT NULL DEFAULT '',
			gateway VARCHAR(50) NOT NULL DEFAULT '',
			status VARCHAR(30) NOT NULL DEFAULT 'active',
			price_id VARCHAR(255) NOT NULL DEFAULT '',
			amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
			currency VARCHAR(3) NOT NULL DEFAULT '',
			interval_unit VARCHAR(20) NOT NULL DEFAULT 'month',
			interval_count INT(11) NOT NULL DEFAULT 1,
			current_period_start DATETIME DEFAULT NULL,
			current_period_end DATETIME DEFAULT NULL,
			cancelled_at DATETIME DEFAULT NULL,
			trial_end DATETIME DEFAULT NULL,
			renewal_count INT(11) NOT NULL DEFAULT 0,
			metadata LONGTEXT DEFAULT NULL,
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			KEY idx_transaction_id (transaction_id),
			KEY idx_gateway_sub_id (gateway_subscription_id),
			KEY idx_status (status)
		) $charset_collate;";
		dbDelta( $sql_subs );

		GatewayKit_Logger::get_instance()->info( 'Database schema verified for version ' . self::DB_VERSION );
	}

	/**
	 * Get table status
	 *
	 * @return array Table status information
	 */
	public function get_table_status() {
		global $wpdb;

		$tables_raw = array(
			'transactions' => $this->table_name_raw( 'gatewaykit_payment_transactions' ),
			'logs'         => $this->table_name_raw( 'gatewaykit_payment_logs' ),
		);

		$status = array();

		foreach ( $tables_raw as $name => $table_raw ) {
			// LIKE matches the raw table name; compare against $table_raw.
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_raw ) ) === $table_raw;

			if ( $exists ) {
				// Count rows via a prepared identifier.
				$count           = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table_raw ) );
				$status[ $name ] = array(
					'exists' => true,
					'count'  => intval( $count ),
				);
			} else {
				$status[ $name ] = array(
					'exists' => false,
					'count'  => 0,
				);
			}
		}

		return $status;
	}

	/**
	 * Optimize tables
	 *
	 * @return void
	 */
	public function optimize_tables() {
		global $wpdb;

		$tables = array(
			$this->table_name_raw( 'gatewaykit_payment_transactions' ),
			$this->table_name_raw( 'gatewaykit_payment_logs' ),
		);

		foreach ( $tables as $table ) {
			$result = $wpdb->query(
				$wpdb->prepare( 'OPTIMIZE TABLE %i', $table )
			);
			if ( $result === false ) {
				GatewayKit_Logger::get_instance()->error(
					'Failed to optimize table',
					array(
						'table' => $table,
						'error' => $wpdb->last_error,
					)
				);
			}
		}

		GatewayKit_Logger::get_instance()->info( 'Database tables optimized' );
	}

	// phpcs:enable
}
