<?php
/**
 * Dashboard Widgets
 *
 * Adds payment-related widgets to WordPress dashboard
 *
 * @package GatewayKit
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dashboard Widgets Class
 */
class GatewayKit_Dashboard_Widgets {

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'wp_dashboard_setup', array( $this, 'add_dashboard_widgets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_dashboard_styles' ) );
		
		// Clear cache when transactions are modified
		add_action( 'gatewaykit_transaction_created', array( $this, 'clear_dashboard_cache' ) );
		add_action( 'gatewaykit_transaction_updated', array( $this, 'clear_dashboard_cache' ) );
		add_action( 'gatewaykit_transaction_deleted', array( $this, 'clear_dashboard_cache' ) );
	}

	/**
	 * Enqueue dashboard styles
	 */
	public function enqueue_dashboard_styles( string $hook ) {
		// Only load on dashboard page
		if ( $hook !== 'index.php' ) {
			return;
		}

		wp_enqueue_style( 'gatewaykit-admin-styles', GATEWAYKIT_PLUGIN_URL . 'assets/css/admin.css', array(), GATEWAYKIT_VERSION );
	}

	/**
	 * Add dashboard widgets
	 */
	public function add_dashboard_widgets() {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_add_dashboard_widget(
			'gatewaykit_payment_overview',
			__( 'Payment Overview', 'gatewaykit' ),
			array( $this, 'payment_overview_widget' ),
			null,
			null,
			'normal',
			'high'
		);

		wp_add_dashboard_widget(
			'gatewaykit_recent_transactions',
			__( 'Recent Transactions', 'gatewaykit' ),
			array( $this, 'recent_transactions_widget' ),
			null,
			null,
			'side',
			'high'
		);

		wp_add_dashboard_widget(
			'gatewaykit_gateway_performance',
			__( 'Gateway Performance', 'gatewaykit' ),
			array( $this, 'gateway_performance_widget' ),
			null,
			null,
			'side',
			'default'
		);
	}

	/**
	 * Payment overview widget
	 */
	public function payment_overview_widget() {
		global $wpdb;

		// Check cache first
		$cache_key = 'gatewaykit_dashboard_stats';
		$cache_ttl = 300; // 5 minutes cache
		
		$cached_stats = get_transient($cache_key);
		if ($cached_stats !== false) {
			$stats = $cached_stats;
		} else {
			$table_name = $wpdb->prefix . 'gatewaykit_payment_transactions';

		// Get statistics for last 30 days (cached via transient above).
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transient-cached (5 min); admin-only dashboard widget
		$stats = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
                    COUNT(*) as total_transactions,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as successful_transactions,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_transactions,
                    SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END) as total_amount,
                    AVG(CASE WHEN status = 'completed' THEN amount ELSE NULL END) as avg_amount
                FROM %i
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
				$table_name
			)
		);
		// phpcs:enable

			if (!$stats) {
				$stats = (object) array(
					'total_transactions'      => 0,
					'successful_transactions' => 0,
					'failed_transactions'     => 0,
					'total_amount'            => 0,
					'avg_amount'              => 0,
				);
			}

			// Cache the result
			set_transient($cache_key, $stats, $cache_ttl);
		}

		$success_rate = $stats->total_transactions > 0
			? round(($stats->successful_transactions / $stats->total_transactions) * 100, 1)
			: 0;

		$currency = strtoupper( get_option( 'gatewaykit_currency', GatewayKit_Gateway_Manager::get_instance()->get_default_currency() ) );

		?>
		<div class="gatewaykit-dashboard-overview">
			<div class="gatewaykit-stat-grid">
				<div class="gatewaykit-stat-item">
					<div class="gatewaykit-stat-number"><?php echo esc_html( number_format( $stats->total_transactions ?? 0 ) ); ?></div>
					<div class="gatewaykit-stat-label"><?php esc_html_e( 'Total Transactions', 'gatewaykit' ); ?></div>
				</div>

				<div class="gatewaykit-stat-item">
					<div class="gatewaykit-stat-number"><?php echo esc_html( number_format( $stats->successful_transactions ?? 0 ) ); ?></div>
					<div class="gatewaykit-stat-label"><?php esc_html_e( 'Successful', 'gatewaykit' ); ?></div>
				</div>

				<div class="gatewaykit-stat-item">
					<div class="gatewaykit-stat-number"><?php echo esc_html( number_format( $stats->failed_transactions ?? 0 ) ); ?></div>
					<div class="gatewaykit-stat-label"><?php esc_html_e( 'Failed', 'gatewaykit' ); ?></div>
				</div>

				<div class="gatewaykit-stat-item">
					<div class="gatewaykit-stat-number"><?php echo esc_html( number_format( $stats->total_amount ?? 0 ) . ' ' . $currency ); ?></div>
					<div class="gatewaykit-stat-label"><?php esc_html_e( 'Total Amount', 'gatewaykit' ); ?></div>
				</div>

				<div class="gatewaykit-stat-item">
					<div class="gatewaykit-stat-number"><?php echo esc_html( number_format( $stats->avg_amount ?? 0, 0 ) . ' ' . $currency ); ?></div>
					<div class="gatewaykit-stat-label"><?php esc_html_e( 'Average Amount', 'gatewaykit' ); ?></div>
				</div>

				<div class="gatewaykit-stat-item">
					<div class="gatewaykit-stat-number"><?php echo esc_html( $success_rate . '%' ); ?></div>
					<div class="gatewaykit-stat-label"><?php esc_html_e( 'Success Rate', 'gatewaykit' ); ?></div>
				</div>
			</div>

			<div class="gatewaykit-quick-actions">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=gatewaykit-transactions' ) ); ?>" class="button">
					<?php esc_html_e( 'View All Transactions', 'gatewaykit' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=gatewaykit-settings' ) ); ?>" class="button">
					<?php esc_html_e( 'Settings', 'gatewaykit' ); ?>
				</a>
			</div>
		</div>
		<?php
	}

	/**
	 * Recent transactions widget
	 */
	public function recent_transactions_widget() {
		global $wpdb;

		// Check cache first
		$cache_key = 'gatewaykit_recent_transactions';
		$cache_ttl = 180; // 3 minutes cache
		
		$cached_transactions = get_transient($cache_key);
		if ($cached_transactions !== false) {
			$recent_transactions = $cached_transactions;
		} else {
			$table_name = $wpdb->prefix . 'gatewaykit_payment_transactions';

		// Cached via transient above.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transient-cached (3 min); admin-only dashboard widget
		$recent_transactions = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, amount, currency, status, created_at
                FROM %i
                ORDER BY created_at DESC
                LIMIT 5",
				$table_name
			)
		);
		// phpcs:enable

			// Cache the result
			set_transient($cache_key, $recent_transactions, $cache_ttl);
		}

		if ( empty( $recent_transactions ) ) {
			echo '<p>' . esc_html__( 'No recent transactions.', 'gatewaykit' ) . '</p>';
			return;
		}

		?>
		<div class="gatewaykit-recent-transactions">
			<ul class="gatewaykit-transaction-list">
				<?php foreach ( $recent_transactions as $transaction ) : ?>
					<li class="gatewaykit-transaction-item">
						<div class="gatewaykit-transaction-info">
							<span class="gatewaykit-transaction-id">#<?php echo esc_html( $transaction->id ); ?></span>
							<span class="gatewaykit-transaction-amount">
								<?php echo esc_html( number_format( $transaction->amount ?? 0 ) . ' ' . $transaction->currency ); ?>
							</span>
						</div>
						<div class="gatewaykit-transaction-meta">
							<span class="gatewaykit-transaction-status gatewaykit-status-<?php echo esc_attr( $transaction->status ); ?>">
								<?php
								switch ( $transaction->status ) {
									case 'completed':
										esc_html_e( 'Successful', 'gatewaykit' );
										break;
									case 'failed':
										esc_html_e( 'Failed', 'gatewaykit' );
										break;
									case 'pending':
										esc_html_e( 'Pending', 'gatewaykit' );
										break;
									default:
										echo esc_html( ucfirst( $transaction->status ) );
								}
								?>
							</span>
							<span class="gatewaykit-transaction-date">
								<?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $transaction->created_at ) ) ); ?>
							</span>
						</div>
					</li>
				<?php endforeach; ?>
			</ul>

			<div class="gatewaykit-widget-footer">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=gatewaykit-transactions' ) ); ?>">
					<?php esc_html_e( 'View All Transactions', 'gatewaykit' ); ?>
				</a>
			</div>
		</div>
		<?php
	}

	/**
	 * Gateway performance widget
	 */
	public function gateway_performance_widget() {
		$gateway_manager = GatewayKit_Gateway_Manager::get_instance();
		$gateway_stats   = $gateway_manager->get_all_gateway_stats();

		if ( empty( $gateway_stats ) ) {
			echo '<p>' . esc_html__( 'No gateway data available.', 'gatewaykit' ) . '</p>';
			return;
		}

		?>
		<div class="gatewaykit-gateway-performance">
			<?php foreach ( $gateway_stats as $gateway_id => $stats ) : ?>
				<div class="gatewaykit-gateway-stat">
					<div class="gatewaykit-gateway-name"><?php echo esc_html( $stats['name'] ); ?></div>
					<div class="gatewaykit-gateway-metrics">
						<div class="gatewaykit-gateway-transactions">
							<?php echo esc_html( number_format( $stats['total_transactions'] ?? 0 ) . ' ' . __( 'transactions', 'gatewaykit' ) ); ?>
						</div>
						<?php if ( $stats['total_transactions'] > 0 ) : ?>
							<div class="gatewaykit-gateway-success-rate">
								<?php echo esc_html( round( $stats['success_rate'], 1 ) . '% ' . __( 'success rate', 'gatewaykit' ) ); ?>
							</div>
						<?php endif; ?>
					</div>
				</div>
			<?php endforeach; ?>

			<div class="gatewaykit-widget-footer">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=gatewaykit-settings' ) ); ?>">
					<?php esc_html_e( 'Configure Gateways', 'gatewaykit' ); ?>
				</a>
			</div>
		</div>
		<?php
	}

	/**
	 * Clear dashboard cache
	 */
	public function clear_dashboard_cache() {
		delete_transient('gatewaykit_dashboard_stats');
		delete_transient('gatewaykit_recent_transactions');
	}
}
