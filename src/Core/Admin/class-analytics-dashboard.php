<?php
/**
 * Analytics Dashboard (Pro)
 *
 * Displays revenue charts, gateway performance, success rates, and top forms
 * in a dedicated admin page under the GatewayKit menu.
 *
 * @package GatewayKit_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Analytics dashboard.
 */
class GatewayKit_Analytics_Dashboard {

	/**
	 * Transient TTL for cached analytics data (5 minutes).
	 */
	const CACHE_TTL = 300;

	/**
	 * Menu slug.
	 */
	const PAGE_SLUG = 'gatewaykit-analytics';

	/**
	 * Constructor — hooks admin menu.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_gatewaykit_analytics_data', array( $this, 'ajax_get_data' ) );
	}

	/**
	 * Register admin submenu.
	 */
	public function add_menu() {
		if ( ! is_admin() ) {
			return;
		}

		add_submenu_page(
			'gatewaykit',
			__( 'Analytics', 'gatewaykit' ),
			__( 'Analytics', 'gatewaykit' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue analytics assets only on our page.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( false === strpos( $hook_suffix, self::PAGE_SLUG ) ) {
			return;
		}

		$min = defined( 'WP_DEBUG' ) && WP_DEBUG ? '' : '.min';

		wp_enqueue_style(
			'gatewaykit-analytics',
			GATEWAYKIT_PLUGIN_URL . 'assets/css/admin-analytics' . $min . '.css',
			array(),
			GATEWAYKIT_VERSION
		);

		wp_enqueue_script(
			'gatewaykit-analytics',
			GATEWAYKIT_PLUGIN_URL . 'assets/js/admin-analytics' . $min . '.js',
			array( 'jquery' ),
			GATEWAYKIT_VERSION,
			true
		);

		wp_localize_script(
			'gatewaykit-analytics',
			'GatewayKitAnalytics',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'gatewaykit_analytics_nonce' ),
				'i18n'     => array(
					'loading'    => __( 'Loading analytics...', 'gatewaykit' ),
					'error'      => __( 'Failed to load analytics data.', 'gatewaykit' ),
					'no_data'    => __( 'No transaction data available for the selected period.', 'gatewaykit' ),
					'revenue'    => __( 'Revenue', 'gatewaykit' ),
					'transactions' => __( 'Transactions', 'gatewaykit' ),
					'avg_order'  => __( 'Avg. Order Value', 'gatewaykit' ),
					'success_rate' => __( 'Success Rate', 'gatewaykit' ),
				),
			)
		);
	}

	/**
	 * Render the analytics page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'gatewaykit' ) );
		}

		$min = defined( 'WP_DEBUG' ) && WP_DEBUG ? '' : '.min';
		wp_enqueue_style( 'gatewaykit-admin-styles', GATEWAYKIT_PLUGIN_URL . 'assets/css/admin' . $min . '.css', array(), GATEWAYKIT_VERSION );
		?>
		<div class="wrap gatewaykit-analytics-wrap">
			<h1><?php esc_html_e( 'GatewayKit Analytics', 'gatewaykit' ); ?></h1>

			<div class="gatewaykit-analytics-header">
				<div class="gatewaykit-analytics-filters">
					<label for="gk-period"><?php esc_html_e( 'Period:', 'gatewaykit' ); ?></label>
					<select id="gk-period" class="gatewaykit-analytics-select">
						<option value="7"><?php esc_html_e( 'Last 7 days', 'gatewaykit' ); ?></option>
						<option value="30" selected><?php esc_html_e( 'Last 30 days', 'gatewaykit' ); ?></option>
						<option value="90"><?php esc_html_e( 'Last 90 days', 'gatewaykit' ); ?></option>
						<option value="365"><?php esc_html_e( 'Last 12 months', 'gatewaykit' ); ?></option>
					</select>

					<label for="gk-gateway"><?php esc_html_e( 'Gateway:', 'gatewaykit' ); ?></label>
					<select id="gk-gateway" class="gatewaykit-analytics-select">
						<option value=""><?php esc_html_e( 'All Gateways', 'gatewaykit' ); ?></option>
						<?php
						$gm      = GatewayKit_Gateway_Manager::get_instance();
						$gateways = $gm->get_registered_gateways();
						foreach ( $gateways as $gid => $gclass ) :
							$gw = $gm->get_gateway( $gid );
							if ( $gw ) :
							?>
								<option value="<?php echo esc_attr( $gid ); ?>"><?php echo esc_html( $gw->get_gateway_name() ); ?></option>
							<?php
							endif;
						endforeach;
						?>
					</select>
				</div>
			</div>

			<!-- KPI Cards -->
			<div class="gatewaykit-analytics-kpis" id="gk-kpis">
				<div class="gk-kpi-card">
					<div class="gk-kpi-label"><?php esc_html_e( 'Total Revenue', 'gatewaykit' ); ?></div>
					<div class="gk-kpi-value" id="gk-kpi-revenue">—</div>
				</div>
				<div class="gk-kpi-card">
					<div class="gk-kpi-label"><?php esc_html_e( 'Transactions', 'gatewaykit' ); ?></div>
					<div class="gk-kpi-value" id="gk-kpi-count">—</div>
				</div>
				<div class="gk-kpi-card">
					<div class="gk-kpi-label"><?php esc_html_e( 'Avg. Order Value', 'gatewaykit' ); ?></div>
					<div class="gk-kpi-value" id="gk-kpi-avg">—</div>
				</div>
				<div class="gk-kpi-card">
					<div class="gk-kpi-label"><?php esc_html_e( 'Success Rate', 'gatewaykit' ); ?></div>
					<div class="gk-kpi-value" id="gk-kpi-rate">—</div>
				</div>
			</div>

			<!-- Charts Row -->
			<div class="gatewaykit-analytics-charts">
				<div class="gk-chart-box gk-chart-box--wide">
					<h2><?php esc_html_e( 'Revenue Over Time', 'gatewaykit' ); ?></h2>
					<div class="gk-chart-container" id="gk-revenue-chart" style="height:300px;"></div>
				</div>
				<div class="gk-chart-box">
					<h2><?php esc_html_e( 'Transactions by Gateway', 'gatewaykit' ); ?></h2>
					<div class="gk-chart-container" id="gk-gateway-chart" style="height:300px;"></div>
				</div>
			</div>

			<!-- Bottom Row -->
			<div class="gatewaykit-analytics-charts">
				<div class="gk-chart-box">
					<h2><?php esc_html_e( 'Success vs Failed', 'gatewaykit' ); ?></h2>
					<div class="gk-chart-container" id="gk-status-chart" style="height:250px;"></div>
				</div>
				<div class="gk-chart-box">
					<h2><?php esc_html_e( 'Top Forms by Revenue', 'gatewaykit' ); ?></h2>
					<div id="gk-top-forms" class="gk-top-forms-list"></div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX endpoint: return analytics data as JSON.
	 */
	public function ajax_get_data() {
		check_ajax_referer( 'gatewaykit_analytics_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'gatewaykit' ) ) );
		}

		$days    = isset( $_POST['days'] ) ? absint( wp_unslash( $_POST['days'] ) ) : 30;
		$gateway = isset( $_POST['gateway'] ) ? sanitize_key( wp_unslash( $_POST['gateway'] ) ) : '';

		$cache_key = 'gk_analytics_' . md5( $days . '_' . $gateway );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			wp_send_json_success( $cached );
		}

		global $wpdb;
		$table_name = $wpdb->prefix . 'gatewaykit_payment_transactions';

		$date_from = gmdate( 'Y-m-d 00:00:00', strtotime( '-' . $days . ' days' ) );

		// Build a parameterised WHERE clause. Each condition text carries its
		// own %s placeholder; the corresponding values are collected in
		// $where_values so $wpdb->prepare() can fill them correctly.
		$where_parts = array( 'created_at >= %s' );
		$where_values = array( $date_from );

		if ( '' !== $gateway ) {
			$where_parts[] = 'gateway = %s';
			$where_values[] = $gateway;
		}

		$where_sql = 'WHERE ' . implode( ' AND ', $where_parts );

		/*
		 * The five queries below use %i for the table identifier (WP 6.2+)
		 * and interpolate $where_sql which already contains %s placeholders.
		 * PHPCS cannot statically trace placeholders through a variable, but
		 * the construction is safe because:
		 *   - $table_name uses a hardcoded wpdb-prefixed string
		 *   - $where_parts are hardcoded condition fragments
		 *   - $where_values contain only sanitized/trusted data
		 *
		 * Transient caching (set_transient / get_transient) is used at the
		 * caller level (lines 206-211 and 375), so the NoCaching warnings
		 * do not apply — the data is not fetched on every page load.
		 */
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter

		// 1. KPI summary.
		$summary_sql = $wpdb->prepare(
			"SELECT
				COUNT(*) as total_count,
				SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END) as revenue,
				AVG(CASE WHEN status = 'completed' THEN amount END) as avg_order,
				SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_count
			FROM %i {$where_sql}",
			array_merge( array( $table_name ), $where_values )
		);
		$summary = $wpdb->get_row( $summary_sql, ARRAY_A );

		$total_count    = (int) ( $summary['total_count'] ?? 0 );
		$revenue        = (float) ( $summary['revenue'] ?? 0 );
		$avg_order      = (float) ( $summary['avg_order'] ?? 0 );
		$completed_count = (int) ( $summary['completed_count'] ?? 0 );
		$success_rate   = $total_count > 0 ? round( ( $completed_count / $total_count ) * 100, 1 ) : 0;

		// 2. Revenue over time (daily).
		$daily_sql = $wpdb->prepare(
			"SELECT DATE(created_at) as date_label,
				SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END) as daily_revenue,
				COUNT(*) as daily_count
			FROM %i {$where_sql}
			GROUP BY DATE(created_at)
			ORDER BY date_label ASC",
			array_merge( array( $table_name ), $where_values )
		);
		$daily_rows = $wpdb->get_results( $daily_sql, ARRAY_A );

		$revenue_labels = array();
		$revenue_data   = array();
		$count_data     = array();
		foreach ( $daily_rows as $row ) {
			$revenue_labels[] = $row['date_label'];
			$revenue_data[]   = (float) $row['daily_revenue'];
			$count_data[]     = (int) $row['daily_count'];
		}

		// 3. Transactions by gateway.
		$gateway_sql = $wpdb->prepare(
			"SELECT gateway,
				COUNT(*) as cnt,
				SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END) as gw_revenue
			FROM %i {$where_sql}
			GROUP BY gateway
			ORDER BY gw_revenue DESC",
			array_merge( array( $table_name ), $where_values )
		);
		$gateway_rows = $wpdb->get_results( $gateway_sql, ARRAY_A );

		$gateway_labels = array();
		$gateway_revenue = array();
		$gateway_counts  = array();
		$gm              = GatewayKit_Gateway_Manager::get_instance();
		foreach ( $gateway_rows as $row ) {
			$gw = $gm->get_gateway( $row['gateway'] );
			$gateway_labels[]  = $gw ? $gw->get_gateway_name() : ucfirst( $row['gateway'] );
			$gateway_revenue[] = (float) $row['gw_revenue'];
			$gateway_counts[]  = (int) $row['cnt'];
		}

		// 4. Status breakdown.
		$status_sql = $wpdb->prepare(
			"SELECT status, COUNT(*) as cnt
			FROM %i {$where_sql}
			GROUP BY status",
			array_merge( array( $table_name ), $where_values )
		);
		$status_rows = $wpdb->get_results( $status_sql, ARRAY_A );

		$status_labels = array();
		$status_data   = array();
		foreach ( $status_rows as $row ) {
			$status_labels[] = ucfirst( $row['status'] );
			$status_data[]   = (int) $row['cnt'];
		}

		// 5. Top forms by revenue.
		$form_sql = $wpdb->prepare(
			"SELECT form_id, post_id,
				SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END) as form_revenue,
				COUNT(*) as form_count
			FROM %i {$where_sql}
			GROUP BY form_id, post_id
			ORDER BY form_revenue DESC
			LIMIT 10",
			array_merge( array( $table_name ), $where_values )
		);
		$form_rows = $wpdb->get_results( $form_sql, ARRAY_A );

		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter

		$top_forms = array();
		foreach ( $form_rows as $row ) {
			$form_title = '';
			if ( ! empty( $row['post_id'] ) ) {
				$title      = get_the_title( $row['post_id'] );
				$form_title = $title ? $title : sprintf(
					/* translators: %s: post ID */
					__( 'Page #%s', 'gatewaykit' ),
					$row['post_id']
				);
			} else {
				$form_title = sprintf(
					/* translators: %s: form ID */
					__( 'Form %s', 'gatewaykit' ),
					$row['form_id']
				);
			}

			$top_forms[] = array(
				'label'   => $form_title,
				'revenue' => (float) $row['form_revenue'],
				'count'   => (int) $row['form_count'],
			);
		}

		$data = array(
			'kpi' => array(
				'revenue'      => $revenue,
				'count'        => $total_count,
				'avg_order'    => $avg_order,
				'success_rate' => $success_rate,
			),
			'revenue_chart' => array(
				'labels' => $revenue_labels,
				'data'   => $revenue_data,
				'counts' => $count_data,
			),
			'gateway_chart' => array(
				'labels'  => $gateway_labels,
				'revenue' => $gateway_revenue,
				'counts'  => $gateway_counts,
			),
			'status_chart' => array(
				'labels' => $status_labels,
				'data'   => $status_data,
			),
			'top_forms' => $top_forms,
			'currency'  => GatewayKit_Gateway_Manager::get_instance()->get_default_currency(),
		);

		set_transient( $cache_key, $data, self::CACHE_TTL );

		wp_send_json_success( $data );
	}
}
