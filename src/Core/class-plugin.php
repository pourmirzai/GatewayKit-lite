<?php
/**
 * GatewayKit plugin controller.
 *
 * Moved out of gatewaykit.php as part of the single-branch monorepo refactor.
 * gatewaykit.php is now a thin loader: it defines constants, boots the
 * autoloader, requires this class, and calls GatewayKit::get_instance().
 *
 * Gateways and features are discovered automatically from
 * the module.php file inside each gateway and feature folder.
 *
 * @package GatewayKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GatewayKit {

    /**
     * Single instance of the plugin
     *
     * @var self|null
     */
    private static $instance = null;

    /**
     * Admin settings instance
     *
     * @var GatewayKit_Admin_Settings|null
     */
    private $admin_settings = null;

    /**
     * Admin discounts instance
     *
     * @var GatewayKit_Admin_Discounts|null
     */
    private $admin_discounts = null;

    /**
     * IDs of loaded feature modules (used for gateway dependency checks).
     *
     * @var array
     */
    private $loaded_features = array();

    /**
     * Get single instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->init_hooks();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        register_activation_hook(GATEWAYKIT_PLUGIN_FILE, array($this, 'activate'));
        register_deactivation_hook(GATEWAYKIT_PLUGIN_FILE, array($this, 'deactivate'));

        add_action('plugins_loaded', array($this, 'load_textdomain'), 1);
        add_action('plugins_loaded', array($this, 'init'), 10);

        // Auto-discover gateways from src/Gateways/ via each folder's
        // module.php (hooked into the gateway manager registration action).
        add_action('gatewaykit_register_gateways', array($this, 'discover_gateways'));

        // Invoice PDF download endpoint (public, receipt token is the key).
        add_action('template_redirect', array($this, 'handle_invoice_download'), 5);

        // Dismissible admin notice promoting GatewayKit Pro (only when Pro is absent).
        add_action('admin_notices', array($this, 'maybe_show_pro_upgrade_notice'));
        add_action('admin_notices', array($this, 'maybe_show_db_update_notice'));
        add_action('admin_notices', array($this, 'maybe_show_crypto_key_notice'));
        add_action('wp_ajax_gatewaykit_dismiss_pro_notice', array($this, 'dismiss_pro_notice'));


        // Register admin components early so their admin_menu callbacks are attached before admin_menu runs.
        // Transactions bulk actions (delete/export) are dispatched at
        // admin_init:5 by GatewayKit_Transaction_List_Table::dispatch_bulk_action()
        // — the single source of truth, after settings init.
        add_action('admin_init', array('GatewayKit_Transaction_List_Table', 'dispatch_bulk_action'), 5);
        add_action('admin_init', array($this, 'init_admin_components'), 1);

        // Register admin menus at the proper hook
        add_action('admin_menu', array($this, 'register_admin_menus'), 10);

        // Enqueue admin assets.
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));

        // CRITICAL FIX: Register AJAX handler for payment processing
        add_action('wp_ajax_gatewaykit_process_payment', array($this, 'process_payment_ajax'));
        add_action('wp_ajax_nopriv_gatewaykit_process_payment', array($this, 'process_payment_ajax'));

        // Cache-safe nonce endpoint: delivers a fresh Elementor nonce to the
        // browser so forms keep working even when pages are served from a full
        // page cache (WP Rocket, LiteSpeed, WP Super Cache, CDN, ...).
        add_action('wp_ajax_gatewaykit_get_nonce', array($this, 'ajax_get_nonce'));
        add_action('wp_ajax_nopriv_gatewaykit_get_nonce', array($this, 'ajax_get_nonce'));

        // Public + private live discount-code validation for Elementor forms.
        add_action('wp_ajax_gatewaykit_validate_discount_code', array($this, 'ajax_validate_discount_code'));
        add_action('wp_ajax_nopriv_gatewaykit_validate_discount_code', array($this, 'ajax_validate_discount_code'));
        
        // Add scheduled task for payment verification retries
        add_action('gatewaykit_retry_payment_verification', array($this, 'retry_payment_verification'));

        // Scheduled cleanup of old payment logs (daily, keeps 30 days by default)
        add_action('gatewaykit_cleanup_old_logs', array($this, 'cleanup_old_logs_event'));

    }

    /**
     * Plugin activation
     */
    public function activate() {
        try {
            // Load required dependencies for activation
            require_once GATEWAYKIT_PLUGIN_DIR . 'src/Core/class-autoloader.php';
            
            // Initialize autoloader explicitly for activation context
            GatewayKit_Autoloader::get_instance();

            // Create database tables (single source of truth in the DB
            // manager — idempotent dbDelta). This also stamps the schema
            // version so no update notice is ever shown on a fresh install.
            GatewayKit_Database_Manager::get_instance()->check_db_version();

            // Set default options
            $this->set_default_options();

            // Schedule daily cleanup of old payment logs
            if (!wp_next_scheduled('gatewaykit_cleanup_old_logs')) {
                wp_schedule_event(time(), 'daily', 'gatewaykit_cleanup_old_logs');
            }

            // Flush rewrite rules
            flush_rewrite_rules();

            // Initialize database manager to verify tables
            $db_manager = GatewayKit_Database_Manager::get_instance();
            $table_status = $db_manager->get_table_status();
            
            GatewayKit_Logger::get_instance()->info('Plugin activation completed successfully', array('table_status' => $table_status));
        } catch (Exception $e) {
            GatewayKit_Logger::get_instance()->error('Plugin activation error - ' . $e->getMessage());
            
            // Throw the exception to prevent activation if critical database setup fails
            throw new Exception('Failed to initialize plugin database: ' . $e->getMessage()); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal exception for activation failure, not directly output
        }
    }

    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clear scheduled log cleanup event
        wp_clear_scheduled_hook('gatewaykit_cleanup_old_logs');

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Scheduled event callback: remove logs older than the retention window.
     */
    public function cleanup_old_logs_event() {
        $logger = GatewayKit_Logger::get_instance();
        $days   = (int) get_option('gatewaykit_log_retention_days', 30);
        $logger->clean_old_logs($days > 0 ? $days : 30);
    }

    /**
     * Apply the admin_ajax rate limit to a request.
     *
     * Sends a JSON error response (and stops execution) when the limit is
     * exceeded. No-op when rate limiting is disabled.
     */
    private function check_admin_rate_limit() {
        $rate_limiter = GatewayKit_Rate_Limiter::get_instance();
        $rate_check   = $rate_limiter->check_rate_limit('admin_ajax');
        if (is_wp_error($rate_check)) {
            wp_send_json_error($rate_check->get_error_message());
        }
    }

    /**
     * Load plugin textdomain
     */
    public function load_textdomain() {
        // Add diagnostic logging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $logger = GatewayKit_Logger::get_instance();
            $logger->debug('Loading textdomain at hook: ' . current_filter());
            $logger->debug('Current action priority: ' . has_action('plugins_loaded', array($this, 'load_textdomain')));
            $logger->debug('Languages directory path: ' . dirname(GATEWAYKIT_PLUGIN_BASENAME) . '/languages/');
            $logger->debug('Languages directory exists: ' . (is_dir(GATEWAYKIT_PLUGIN_DIR . 'languages/') ? 'yes' : 'no'));
        }

        // phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- kept for custom translation loading; WP 4.6+ auto-loads .org translations but this ensures bundled .po/.mo files work when present.
        load_plugin_textdomain(
            'gatewaykit',
            false,
            dirname(GATEWAYKIT_PLUGIN_BASENAME) . '/languages/'
        );

        // Log success/failure
        if (defined('WP_DEBUG') && WP_DEBUG) {
            GatewayKit_Logger::get_instance()->debug('Textdomain loaded successfully');
        }
    }

    /**
     * Initialize plugin
     */
    public function init() {
        // Initialize autoloader FIRST to ensure all classes are available
        GatewayKit_Autoloader::get_instance();

        // Signal that GatewayKit Core is fully loaded.
        do_action( 'gatewaykit_lite_loaded' );
        do_action( 'gatewaykit_loaded' );

        // Load feature modules (Discount, Pro, ...) from
        // src/Features/ before core components so their classes are available.
        $this->load_features();

        // Initialize core components (including database manager) regardless of Elementor status
        $this->init_components();

        // GatewayKit integrates through Elementor Pro's form action, so Elementor
        // Pro is a hard requirement. When it is missing, show a notice but keep
        // core (database, gateways, etc.) running.
        if ( ! $this->is_elementor_pro_active() ) {
            add_action('admin_notices', array($this, 'elementor_pro_missing_notice'));
        } else {
            add_action('elementor_pro/init', array($this, 'init_elementor_pro_components'));
        }
        
        // Initialize callback handler (always needed for payment processing)
        require_once GATEWAYKIT_PLUGIN_DIR . 'src/Core/callback-handler.php';

        // Initialize payment result shortcode (always needed)
        require_once GATEWAYKIT_PLUGIN_DIR . 'src/Core/class-payment-result-shortcode.php';

        // Enqueue frontend styles and scripts (always needed)
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));

    }

    /**
     * Initialize plugin components
     */
    private function init_components() {
        // Initialize database manager
        GatewayKit_Database_Manager::get_instance();

        // Initialize security manager
        GatewayKit_Security_Manager::get_instance();

        // Initialize logger
        GatewayKit_Logger::get_instance();

        // Initialize gateway manager
        GatewayKit_Gateway_Manager::get_instance();

        // Initialize customer receipt email listener (Lite).
        if ( class_exists( 'GatewayKit_Receipt_Email' ) ) {
            GatewayKit_Receipt_Email::get_instance();
        }

        // Initialize Subscription Service (Pro only).
        if ( class_exists( 'GatewayKit_Subscription_Service' ) && gatewaykit_is_pro_licensed() ) {
            GatewayKit_Subscription_Service::get_instance();
        }
    }

    /**
     * Initialize admin components
     */
    public function init_admin_components() {
        if (!is_admin()) {
            return;
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            GatewayKit_Logger::get_instance()->debug('Initializing admin components at hook: ' . current_filter());
        }

        // Initialize admin components
        if (!$this->admin_settings instanceof GatewayKit_Admin_Settings) {
            $this->admin_settings = new GatewayKit_Admin_Settings();
        }
		new GatewayKit_Dashboard_Widgets();
		if ( class_exists( 'GatewayKit_Admin_Discounts' ) ) {
			$this->admin_discounts = new GatewayKit_Admin_Discounts();
		}

		// Payment Links admin page (Stripe) — self-registers its submenu + AJAX handler.
		if ( class_exists( 'GatewayKit_Payment_Links' ) ) {
			new GatewayKit_Payment_Links();
		}

        // Analytics Dashboard (Pro only — menu + AJAX handler self-register).
        if ( class_exists( 'GatewayKit_Analytics_Dashboard' ) && gatewaykit_is_pro_licensed() ) {
            new GatewayKit_Analytics_Dashboard();
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            GatewayKit_Logger::get_instance()->debug('Admin components initialized');
        }
    }

    /**
     * Register admin menus
     */
    public function register_admin_menus() {
        if (!is_admin()) {
            return;
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            GatewayKit_Logger::get_instance()->debug('register_admin_menus running at hook: ' . current_filter());
        }

        // Ensure admin settings instance exists before registering menus
        if (!$this->admin_settings instanceof GatewayKit_Admin_Settings) {
            $this->admin_settings = new GatewayKit_Admin_Settings();
        }

        // Ensure admin discounts instance exists before registering menus.
        // The Discount feature ships only in pro builds, so the class
        // is absent in lite — guard instantiation to avoid a fatal error.
        if ( class_exists( 'GatewayKit_Admin_Discounts' ) && ! $this->admin_discounts instanceof GatewayKit_Admin_Discounts ) {
            $this->admin_discounts = new GatewayKit_Admin_Discounts();
        }

        // Add main GatewayKit dashboard menu
        $menu_title = __( 'GatewayKit', 'gatewaykit' );
        if ( class_exists( 'GatewayKit_WhiteLabel' ) ) {
            $menu_title = GatewayKit_WhiteLabel::get_instance()->brand( $menu_title );
        }
        $main_menu_result = add_menu_page(
            __('GatewayKit Dashboard', 'gatewaykit'),
            $menu_title,
            'manage_options',
            'gatewaykit',
            array($this->admin_settings, 'dashboard_page'),
            'dashicons-money-alt'
        );

        if (defined('WP_DEBUG') && WP_DEBUG) {
            GatewayKit_Logger::get_instance()->debug('Main GatewayKit menu result: ' . ($main_menu_result !== false ? 'success' : 'failed'));
        }

        // Delegate menu registration to the admin settings class
        if (method_exists($this->admin_settings, 'add_admin_menu')) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                GatewayKit_Logger::get_instance()->debug('Delegating to admin_settings->add_admin_menu()');
            }
            $this->admin_settings->add_admin_menu();
        }

        // Register the Discounts submenu
        if ($this->admin_discounts instanceof GatewayKit_Admin_Discounts) {
            $this->admin_discounts->add_admin_menu();
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            GatewayKit_Logger::get_instance()->debug('Main menu registration completed');
        }
    }


    /**
     * Initialize Elementor Pro components
     */
    public function init_elementor_pro_components() {
        // Initialize Elementor integration
        new GatewayKit_Elementor_Action();
    }

    /**
     * Detect whether Elementor Pro is active and loaded.
     *
     * Elementor Pro is a hard dependency for GatewayKit's form action. Several
     * independent signals are checked so detection is reliable regardless of
     * the exact load order during `plugins_loaded`.
     *
     * @return bool
     */
    private function is_elementor_pro_active() {
        return did_action( 'elementor_pro/loaded' )
            || class_exists( '\ElementorPro\Plugin' )
            || defined( 'ELEMENTOR_PRO_VERSION' );
    }

    /**
     * Discover and register gateways from src/Gateways/.
     *
     * Each gateway folder ships a module.php that requires its own class
     * file(s) and returns metadata (id, name, class, requires). Adding a
     * gateway is therefore just "drop a folder" — no Core edit needed.
     *
     * Hooked into `gatewaykit_register_gateways` (fired by the gateway manager).
     *
     * @param GatewayKit_Gateway_Manager $manager Gateway manager instance.
     */
    public function discover_gateways( $manager ) {
        foreach ( (array) glob( GATEWAYKIT_PLUGIN_DIR . 'src/Gateways/*/module.php' ) as $module_file ) {
            $meta = include $module_file;
            if ( ! is_array( $meta ) || empty( $meta['class'] ) || empty( $meta['id'] ) ) {
                continue;
            }
            if ( ! $this->requirements_met( $meta ) ) {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    GatewayKit_Logger::get_instance()->warning(
                        'Gateway module skipped: unmet requirements',
                        array( 'gateway' => $meta['id'], 'requires' => isset( $meta['requires'] ) ? $meta['requires'] : array() )
                    );
                }
                continue;
            }
            $manager->register_gateway( $meta['id'], $meta['class'] );
        }
    }

    /**
     * Load feature modules from src/Features/.
     *
     * Each feature folder ships a module.php that requires its class file(s)
     * and self-bootstraps (registers hooks / instantiates its controller). The
     * returned metadata records the feature id so gateway dependencies resolve.
     */
    public function load_features() {
        foreach ( (array) glob( GATEWAYKIT_PLUGIN_DIR . 'src/Features/*/module.php' ) as $module_file ) {
            $meta = include $module_file;
            if ( is_array( $meta ) && ! empty( $meta['id'] ) ) {
                $this->loaded_features[] = $meta['id'];
            }
        }
    }

    /**
     * Whether a module's declared requirements are satisfied by the loaded
     * feature set.
     *
     * @param array $meta Module metadata (may contain 'requires').
     * @return bool
     */
    private function requirements_met( $meta ) {
        $requires = isset( $meta['requires'] ) ? (array) $meta['requires'] : array();
        foreach ( $requires as $need ) {
            if ( ! in_array( $need, $this->loaded_features, true ) ) {
                return false;
            }
        }
        return true;
    }

/**
	 * Whether the active build is the Lite product (no Pro add-on active).
	 * @return bool
	 */
	private function is_lite_build() {
		// If Pro add-on is active, this is not a "Lite only" build.
		return ! defined( 'GATEWAYKIT_PRO_VERSION' );
	}

    /**
     * Show the dismissible GatewayKit Pro upgrade notice (admin only).
     *
     * Suppressed automatically when GatewayKit Pro is active, because Pro
     * defines GATEWAYKIT_PRO_VERSION and fires the `gatewaykit_pro_loaded`
     * action on load.
     *
     * When the Freemius parent SDK (`gatewaykit_fs()`) is active, the native
     * "Add-Ons" submenu takes over the upsell role (powered by `has_addons`),
     * so this custom notice is short-circuited to avoid a duplicate CTA. The
     * notice only renders in environments where the SDK is absent (e.g. during
     * a partial build without vendor/, or before the SDK opt-in completes).
     */
    public function maybe_show_pro_upgrade_notice() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Only show the upsell on the Lite build (all gateways included
        // in Lite; Pro adds discounts, webhooks, analytics, refunds, etc.).
        if ( ! $this->is_lite_build() ) {
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
    public function maybe_show_db_update_notice() {
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
     * at-rest encryption are missing (F8).
     *
     * Without SECURE_AUTH_KEY / AUTH_KEY, GatewayKit_Crypto refuses to operate
     * and every gateway that stores encrypted credentials reports as
     * unavailable. This notice points the site owner at wp-config.php.
     */
    public function maybe_show_crypto_key_notice() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( class_exists( 'GatewayKit_Crypto' ) && GatewayKit_Crypto::is_available() ) {
            return;
        }

        ?>
        <div class="notice notice-error">
            <p>
                <strong><?php esc_html_e( 'GatewayKit', 'gatewaykit'); ?></strong>
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
    public function dismiss_pro_notice() {
        check_ajax_referer( 'gatewaykit_dismiss_pro_notice', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Security check failed.', 'gatewaykit' ) );
        }

        update_user_meta( get_current_user_id(), 'gatewaykit_pro_notice_dismissed', 1 );

        wp_send_json_success();
    }

    /**
     * Set default options
     */
    private function set_default_options() {
    	$default_currency = GatewayKit_Gateway_Manager::get_instance()->get_default_currency();
    	$defaults = array(
    		'gatewaykit_version' => GATEWAYKIT_VERSION,
    		'gatewaykit_currency' => $default_currency,
    		'gatewaykit_nonce_required' => true,
    	);
   
    	foreach ($defaults as $option => $value) {
    		if (!get_option($option)) {
    			add_option($option, $value);
    		}
    	}
    }

    /**
     * Elementor Pro missing notice
     */
    public function elementor_pro_missing_notice() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( isset( $_GET['activate'] ) ) {
            unset( $_GET['activate'] );
        }

        $message = sprintf(
            /* translators: 1: Plugin name 2: Elementor Pro */
            esc_html__('"%1$s" requires "%2$s" to be installed and activated.', 'gatewaykit'),
            '<strong>' . esc_html__('GatewayKit', 'gatewaykit') . '</strong>',
            '<strong>' . esc_html__('Elementor Pro', 'gatewaykit') . '</strong>'
        );

        printf('<div class="notice notice-warning is-dismissible"><p>%1$s</p></div>', wp_kses($message, array('strong' => array())));
    }

    /**
     * Enqueue frontend assets for payment result pages
     */
    public function enqueue_frontend_assets() {
        // Use minified assets in production, full assets in debug mode
        $suffix = defined('WP_DEBUG') && WP_DEBUG ? '' : '.min';

        // Enqueue UI Kit CSS (base styles)
        wp_enqueue_style('gatewaykit-ui-kit', GATEWAYKIT_PLUGIN_URL . 'assets/css/ui-kit' . $suffix . '.css', array(), GATEWAYKIT_VERSION);

        // Enqueue UI Components CSS (component styles including payment result styles)
        wp_enqueue_style('gatewaykit-ui-components', GATEWAYKIT_PLUGIN_URL . 'assets/css/ui-components.css', array('gatewaykit-ui-kit'), GATEWAYKIT_VERSION);

        // Enqueue UI Kit JavaScript if needed
        wp_enqueue_script('gatewaykit-ui-kit', GATEWAYKIT_PLUGIN_URL . 'assets/js/ui-kit' . $suffix . '.js', array('jquery'), GATEWAYKIT_VERSION, true);

        // Localize UI Kit script for translations in frontend too
        wp_localize_script(
            'gatewaykit-ui-kit',
            'gatewaykit_ui_vars',
            array(
                'copied' => __( 'Copied!', 'gatewaykit' ),
                'failed' => __( 'Failed', 'gatewaykit' ),
            )
        );

        // Cache-safe nonce refresher: only needed when Elementor is rendering
        // the page (i.e. Elementor's frontend config is present). It delivers a
        // fresh nonce to the browser so submitted forms pass nonce verification
        // even on fully cached pages. See JS file for details.
        if ( $this->is_elementor_page() ) {
            wp_enqueue_script('gatewaykit-frontend', GATEWAYKIT_PLUGIN_URL . 'assets/js/frontend' . $suffix . '.js', array('jquery'), GATEWAYKIT_VERSION, true);
            wp_localize_script(
                'gatewaykit-frontend',
                'GatewayKitFrontend',
                array(
                    'ajaxurl' => admin_url('admin-ajax.php'),
                    'nonce'   => wp_create_nonce('gatewaykit_process_payment'),
                    'debug'   => (defined('WP_DEBUG') && WP_DEBUG) ? true : false,
                )
            );

            // Pro forms shared assets: optional-payment animation (Feature C)
            // and Payment Info live-update (Feature D). Ships in Lite assets;
            // inert without Pro (the page-scan methods bail when Pro is absent).
            $pro_forms_needed = false;
            $pro_forms_data   = array();
            if ( class_exists( 'GatewayKit_Elementor_Action' ) ) {
                $optional_forms = GatewayKit_Elementor_Action::get_optional_payment_forms_for_current_page();
                if ( ! empty( $optional_forms ) ) {
                    $pro_forms_needed = true;
                    $pro_forms_data['optional_forms'] = $optional_forms;
                }
                // Also detect forms with the Payment Info or Discount Code field types.
                if ( ! $pro_forms_needed && class_exists( '\Elementor\Plugin' ) && is_singular() ) {
                    $post_id = get_the_ID();
                    if ( $post_id ) {
                        $document = \Elementor\Plugin::$instance->documents->get_doc_for_frontend( $post_id );
                        if ( $document ) {
                            $elements = $document->get_elements_data();
                            if ( self::scan_for_pro_form_fields( $elements ) ) {
                                $pro_forms_needed = true;
                            }
                        }
                    }
                }
            }
            if ( $pro_forms_needed ) {
                wp_enqueue_style(
                    'gatewaykit-pro-forms',
                    GATEWAYKIT_PLUGIN_URL . 'assets/css/gatewaykit-pro-forms.css',
                    array(),
                    GATEWAYKIT_VERSION
                );
                wp_enqueue_script(
                    'gatewaykit-pro-forms',
                    GATEWAYKIT_PLUGIN_URL . 'assets/js/gatewaykit-pro-forms' . $suffix . '.js',
                    array( 'jquery' ),
                    GATEWAYKIT_VERSION,
                    true
                );
                wp_localize_script(
                    'gatewaykit-pro-forms',
                    'GatewayKitProForms',
                    array_merge( $pro_forms_data, array(
                        'ajaxurl' => admin_url( 'admin-ajax.php' ),
                        'nonce'   => wp_create_nonce( 'gatewaykit_discount_nonce' ),
                        'i18n'    => array(
                            'no_payment' => __( 'Thank you. Your submission was received.', 'gatewaykit' ),
                            'apply'      => __( 'Apply', 'gatewaykit' ),
                            'applying'   => __( 'Applying...', 'gatewaykit' ),
                            'discount'   => __( 'Discount', 'gatewaykit' ),
                            'enter_code' => __( 'Please enter a discount code.', 'gatewaykit' ),
                            'amount_required' => __( 'Please enter an amount first.', 'gatewaykit' ),
                            'invalid'    => __( 'Invalid or expired discount code.', 'gatewaykit' ),
                            'error'      => __( 'An error occurred. Please try again.', 'gatewaykit' ),
                            'removed'    => __( 'Discount removed.', 'gatewaykit' ),
                        ),
                    ) )
                );
            }
        }
    }

    /**
     * Enqueue admin-side assets (notice interaction scripts).
     *
     * @param string $hook Current admin page hook suffix.
     */
    public function enqueue_admin_assets( $hook ) {
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
                'ajaxurl'                => admin_url( 'admin-ajax.php' ),
                'proNonce'               => wp_create_nonce( 'gatewaykit_dismiss_pro_notice' ),
                'i18n'                   => array(
                    'ajaxError'       => __( 'AJAX error occurred. Please try again.', 'gatewaykit' ),
                    'dismissing'      => __( 'Dismissing...', 'gatewaykit' ),
                ),
            )
        );
    }

    /**
     * Determine whether the current request is an Elementor-rendered page.
     *
     * Used to decide whether the cache-safe nonce refresher script should be
     * enqueued. We check the global Elementor instance, the presence of
     * Elementor's frontend config marker, and Elementor's own helper.
     *
     * @return bool
     */
    private function is_elementor_page() {
        // Elementor Pro exposes this on the frontend.
        if ( did_action('elementor/loaded') || class_exists('\Elementor\Plugin') ) {
            return true;
        }
        // Fallback: Elementor adds this body class/config marker.
        if ( function_exists('elementor_load_plugin_textdomain') ) {
            return true;
        }
        return false;
    }

    /**
     * Retry payment verification for failed transactions
     *
     * @param int $transaction_id Transaction ID
     */
    public function retry_payment_verification($transaction_id) {
        $logger = GatewayKit_Logger::get_instance();
        $error_handler = GatewayKit_Error_Handler::get_instance();
        
        $logger->info('Starting payment verification retry', array('transaction_id' => $transaction_id));
        
        try {
            $transaction = GatewayKit_Transaction_Model::find($transaction_id);
            
            if (!$transaction) {
                $logger->warning('Transaction not found for retry', array('transaction_id' => $transaction_id));
                return;
            }
            
            if ($transaction->get('status') !== 'pending') {
                $logger->info('Transaction no longer pending, skipping retry', array(
                    'transaction_id' => $transaction_id,
                    'current_status' => $transaction->get('status')
                ));
                return;
            }
            
            // Get gateway and attempt verification
            $gateway_id = $transaction->get('gateway');
            $gateway_manager = GatewayKit_Gateway_Manager::get_instance();
            $gateway = $gateway_manager->get_gateway($gateway_id);
            
            if (!$gateway) {
                $logger->error('Gateway not found for retry', array(
                    'transaction_id' => $transaction_id,
                    'gateway_id' => $gateway_id
                ));
                return;
            }
            
            // Attempt verification
            $authority = $transaction->get('authority');
            $amount = $transaction->get('amount');
            $verification_result = $gateway->verify_payment($authority, $amount);
            
            if ($verification_result && isset($verification_result['success']) && $verification_result['success']) {
                // Payment verified successfully
                $transaction->update(array(
                    'status' => 'completed',
                    'ref_id' => $verification_result['ref_id'] ?? '',
                    'gateway_response' => wp_json_encode($verification_result),
                    'completed_at' => current_time('mysql')
                ));
                
                $logger->info('Payment verification retry successful', array(
                    'transaction_id' => $transaction_id,
                    'ref_id' => $verification_result['ref_id'] ?? ''
                ));
                
            } else {
                // Verification failed again
                $transaction->update(array(
                    'status' => 'failed',
                    'gateway_response' => wp_json_encode($verification_result)
                ));
                
                $logger->error('Payment verification retry failed', array(
                    'transaction_id' => $transaction_id,
                    'verification_result' => $verification_result
                ));
            }
            
        } catch (Exception $e) {
            $logger->error('Exception during payment verification retry', array(
                'transaction_id' => $transaction_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ));
            
            // Mark as failed after retry exception
            try {
                $transaction = GatewayKit_Transaction_Model::find($transaction_id);
                if ($transaction) {
                    $transaction->update(array(
                        'status' => 'failed',
                        'gateway_response' => wp_json_encode(array(
                            'error' => 'retry_exception',
                            'message' => $e->getMessage()
                        ))
                    ));
                }
            } catch (Exception $update_exception) {
                $logger->error('Failed to update transaction after retry exception', array(
                    'transaction_id' => $transaction_id,
                    'update_error' => $update_exception->getMessage()
                ));
            }
        }
    }
   
    /**
     * Cache-safe nonce provider.
     *
     * Returns a fresh nonce for the 'elementor_ajax' action. The frontend
     * script fetches this and patches Elementor's config before the form is
     * submitted, so nonce verification succeeds even when the page (and its
     * embedded nonce) has been cached for a long time or generated for a
     * different user.
     *
     * F13: each issued nonce is hash-bound to a short-lived, HttpOnly cookie
     * so it cannot be replayed from a different client (the cookie is set on
     * the browser that requested the nonce and sent automatically with the
     * subsequent same-origin payment request).
     *
     * @return void
     */
    public function ajax_get_nonce() {
        // Rate limiting check (prevents abuse of the open nonce endpoint)
        $this->check_admin_rate_limit();

        $nonce = wp_create_nonce( 'elementor_ajax' );

        // Bind the nonce to a short-lived cookie so it cannot be reused
        // cross-client (F13). The cookie is HttpOnly (JS cannot read it) and
        // sent automatically by the browser with the payment POST.
        $this->set_nonce_bind_cookie( $nonce );

        // No nonce is required to *generate* a nonce; this is safe by design.
        wp_send_json_success(array(
            'nonce' => $nonce,
        ));
    }

    /**
     * Name of the nonce-bind cookie (F13).
     *
     * @return string
     */
    private function nonce_bind_cookie_name() {
        return 'gatewaykit_nb';
    }

    /**
     * Compute the bind value for a faucet-issued nonce (F13).
     *
     * HMAC-SHA256 keyed by the WP nonce salt so the value is bound to this
     * site and the nonce it accompanies.
     *
     * @param string $nonce Nonce issued by ajax_get_nonce().
     * @return string
     */
    private function compute_nonce_bind( $nonce ) {
        return hash_hmac( 'sha256', (string) $nonce, wp_salt( 'nonce' ) );
    }

    /**
     * Set the short-lived nonce-bind cookie (F13).
     *
     * @param string $nonce Nonce issued by ajax_get_nonce().
     */
    private function set_nonce_bind_cookie( $nonce ) {
        $name    = $this->nonce_bind_cookie_name();
        $value   = $this->compute_nonce_bind( $nonce );
        $expire  = time() + ( 15 * MINUTE_IN_SECONDS );
        $path    = defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/';
        $options = array(
            'expires'  => $expire,
            'path'     => $path,
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        );

        setcookie( $name, $value, $options );
        // Make the value available immediately for same-request callers.
        $_COOKIE[ $name ] = $value;
    }

    /**
     * Verify that the nonce-bind cookie matches the submitted nonce (F13).
     *
     * @param string $nonce Nonce submitted with the payment request.
     * @return bool True when the cookie is present and matches the nonce.
     */
    private function verify_nonce_bind( $nonce ) {
        $name = $this->nonce_bind_cookie_name();
        if ( empty( $_COOKIE[ $name ] ) ) {
            return false;
        }
        $expected = $this->compute_nonce_bind( $nonce );
        $supplied = sanitize_text_field( wp_unslash( $_COOKIE[ $name ] ) );
        return hash_equals( $expected, $supplied );
    }

    /**
     * Public + private AJAX handler for live discount-code validation.
     *
     * Validates a discount code against a cart amount and returns the discount
     * and final amounts. This is a read-only preview (no usage counters are
     * modified); the authoritative reservation happens in the Elementor action
     * when the form is submitted.
     *
     * Security: nonce + rate limit. Open to guests (nopriv) so logged-out
     * customers can validate codes before paying.
     *
     * @return void
     */
    public function ajax_validate_discount_code() {
        // Discount feature is optional; bail safely on builds without it.
        if ( ! class_exists( 'GatewayKit_Discount_Model' ) ) {
            wp_send_json_error( __( 'Discount codes are not available.', 'gatewaykit' ) );
        }

        // Rate limiting (public endpoint — dedicated bucket so bursts here
        // cannot starve the generic admin-ajax limit).
        $rate_limiter = GatewayKit_Rate_Limiter::get_instance();
        $rate_check   = $rate_limiter->check_rate_limit( 'discount_preview' );
        if ( is_wp_error( $rate_check ) ) {
            wp_send_json_error( $rate_check->get_error_message() );
        }

        // Verify nonce.
        check_ajax_referer( 'gatewaykit_discount_nonce', 'nonce' );

        $code   = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';
        $amount = isset( $_POST['amount'] ) ? floatval( wp_unslash( $_POST['amount'] ) ) : 0;

        if ( '' === $code ) {
            wp_send_json_error( __( 'Please enter a discount code.', 'gatewaykit' ) );
        }
        if ( $amount <= 0 ) {
            wp_send_json_error( __( 'Invalid amount.', 'gatewaykit' ) );
        }

        $user_id = is_user_logged_in() ? get_current_user_id() : 0;

        $valid = GatewayKit_Discount_Model::validate_code( $code, $amount, $user_id );
        if ( is_wp_error( $valid ) ) {
            wp_send_json_error( $valid->get_error_message() );
        }

        $calc = GatewayKit_Discount_Model::calculate_discount( $code, $amount );
        if ( is_wp_error( $calc ) ) {
            wp_send_json_error( $calc->get_error_message() );
        }

        // Clamp final amount defensively.
        $final = (float) $calc['final_amount'];
        if ( $final < 0 ) {
            $final = 0;
        }

        wp_send_json_success( array(
            'discount_amount' => (float) $calc['discount_amount'],
            'final_amount'    => $final,
            'original_amount' => $amount,
            'valid'           => true,
        ) );
    }

    /**
     * AJAX handler for payment processing
     *
     * This handles direct AJAX requests to /wp-admin/admin-ajax.php?action=gatewaykit_process_payment
     *
     * @return void
     */
    public function process_payment_ajax() {
    	// Enable error logging for debugging
    	if (defined('WP_DEBUG') && WP_DEBUG) {
    		GatewayKit_Logger::get_instance()->debug('AJAX payment processing started');
    	}
    
    	$logger = GatewayKit_Logger::get_instance();
    	$error_handler = GatewayKit_Error_Handler::get_instance();
    
    	try {
    		// Log the AJAX request for debugging
    		$logger->debug('AJAX Payment Processing Started', array(
    			'action' => isset($_POST['action']) ? sanitize_key(wp_unslash($_POST['action'])) : 'unknown', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    			'gatewaykit_action' => isset($_POST['gatewaykit_action']) ? sanitize_key(wp_unslash($_POST['gatewaykit_action'])) : 'none', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    			'wp_doing_ajax' => wp_doing_ajax(),
    			'http_referer' => isset( $_SERVER['HTTP_REFERER'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : 'none'
    		));
    
    		// Rate limiting check — dedicated process_payment bucket (F13) so
    		// transaction-creation flood protection is independent of the
    		// generic admin-ajax / faucet bucket.
    		$rate_limiter = GatewayKit_Rate_Limiter::get_instance();
    		$rate_check = $rate_limiter->check_rate_limit('process_payment');
    		if (is_wp_error($rate_check)) {
    			$logger->warning('Rate limit exceeded for AJAX payment', array(
    				'rate_check' => $rate_check->get_error_message()
    			));
    			wp_send_json_error($rate_check->get_error_message());
    			return;
    		}
    
    		// Verify nonce for security
    		$nonce = '';
    		if (isset($_POST['_wpnonce'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    			$nonce = sanitize_text_field(wp_unslash($_POST['_wpnonce'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
    		} elseif (isset($_POST['gatewaykit_nonce'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    			$nonce = sanitize_text_field(wp_unslash($_POST['gatewaykit_nonce'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
    		}
    		
    		if (empty($nonce)) {
    			$logger->error('No nonce provided in AJAX payment request');
    			wp_send_json_error(__('Security check failed. No nonce provided.', 'gatewaykit'));
    			return;
    		}
    
    		// Use the correct nonce action that matches what Elementor uses
    		if (!wp_verify_nonce($nonce, 'elementor_ajax')) {
    			$logger->error('Nonce verification failed for AJAX payment', array(
    				'nonce_value' => substr($nonce, 0, 10) . '...',
    				'action' => 'elementor_ajax'
    			));
    			wp_send_json_error(__('Security check failed. Please reload and try again.', 'gatewaykit'));
    			return;
    		}

    		// F13: the nonce must also be bound to the short-lived cookie
    		// issued by ajax_get_nonce(). This prevents a nonce harvested from
    		// a cached page or a third party from being replayed from a
    		// different client (which would not have the matching cookie).
    		if ( ! $this->verify_nonce_bind( $nonce ) ) {
    			$logger->warning('Nonce cookie bind verification failed for AJAX payment');
    			wp_send_json_error(__('Security check failed. Please reload the page and try again.', 'gatewaykit'));
    			return;
    		}
    
    		// Check if this is an Elementor form submission
        if ( isset( $_POST['action'] ) && sanitize_text_field( wp_unslash( $_POST['action'] ) ) === 'elementor_pro_forms_send_form' ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
    			// This is handled by the Elementor action, so we should not process it here
    			$logger->debug('Elementor form submission detected - handled by Elementor action');
    			wp_send_json_error(__('Form submission already being processed by Elementor.', 'gatewaykit'));
    			return;
    		}
    
    		// For direct AJAX calls, we need to simulate the Elementor form data
        if ( isset( $_POST['gatewaykit_action'] ) && sanitize_text_field( wp_unslash( $_POST['gatewaykit_action'] ) ) === 'process_payment' ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
    			// Process direct payment AJAX call
    			$result = $this->process_direct_payment_ajax($_POST);
    			
    			if (is_wp_error($result)) {
    				$error_data = $result->get_error_data();
    				if (!empty($error_data) && isset($error_data['error_type'])) {
    					// This is a gateway error with detailed information
    					$error_message = $error_handler->get_error_message(
    						$error_data['error_type'],
    						$error_data['error_code'],
    						false, // Not admin context
    						$error_data['error_details']
    					);
    					wp_send_json_error($error_message);
    				} else {
    					// Fallback to original error message
    					wp_send_json_error($result->get_error_message());
    				}
    			} else {
    				// Success response with proper structure
    				wp_send_json_success(array(
    					'success' => true,
    					'data' => $result,
    					'message' => __('Payment processed successfully.', 'gatewaykit')
    				));
    			}
    			return;
    		}
    
    		// Default response for unrecognized requests
    		$logger->warning('Invalid payment request received', array(
    			'post_data' => array_keys($_POST)
    		));
    		wp_send_json_error(__('Invalid payment request.', 'gatewaykit'));
    	
    	} catch (Exception $e) {
    		$logger->error('AJAX Payment Processing Exception', array(
    			'error' => $e->getMessage(),
    			'trace' => $e->getTraceAsString()
    		));
    		
    		// Use error handler to get appropriate error message
    		$error_message = $error_handler->get_error_message('network', 'exception', false);
    		wp_send_json_error($error_message);
    	}
    }
   
    /**
     * Process direct payment AJAX request
     *
     * @param array $data Payment data
     * @return array|WP_Error Payment result or error
     */
    private function process_direct_payment_ajax($data) {
    	$logger = GatewayKit_Logger::get_instance();
    	$validator = GatewayKit_Input_Validator::get_instance();
    	
    	$logger->debug('Processing direct payment AJAX', array(
    		'data_keys' => array_keys($data)
    	));
    
    	// Validate required fields
    	$required_fields = ['amount', 'gateway', 'description', 'success_url'];
    	foreach ($required_fields as $field) {
    		if (empty($data[$field])) {
    			$logger->error('Missing required field in direct payment', array('field' => $field));
    			/* translators: %s: missing field name */
    			return new WP_Error('missing_field', sprintf(__('Required field missing: %s', 'gatewaykit'), $field));
    		}
    	}
    
    	// Validate amount
    	$amount = $validator->validate_amount($data['amount']);
    	if (is_wp_error($amount)) {
    		$logger->error('Invalid amount in direct payment', array('amount' => $data['amount']));
    		return $amount;
    	}
    
    	// Validate gateway
    	$gateway = $validator->validate_gateway($data['gateway']);
    	if (is_wp_error($gateway)) {
    		$logger->error('Invalid gateway in direct payment', array('gateway' => $data['gateway']));
    		return $gateway;
    	}
    
    	// Validate success URL — must point at this site (F13). The open
    	// gatewaykit_process_payment endpoint must not be usable as an
    	// open redirect / payment-initiation proxy to third-party hosts.
    	$success_url = $validator->validate_local_url($data['success_url']);
    	if (is_wp_error($success_url)) {
    		$logger->error('Invalid success URL in direct payment', array('url' => isset($data['success_url']) ? '(rejected)' : '(missing)'));
    		return $success_url;
    	}
    
    	// Prepare payment data
    	$payment_data = array(
    		'gateway'      => $gateway,
    		'amount'       => $amount,
    		'description'  => sanitize_text_field($data['description']),
    		'form_data'    => isset($data['form_data']) ? $this->sanitize_form_data($data['form_data']) : array(),
    		'user_data'    => $this->get_user_data_for_ajax(),
    		'user_id'      => is_user_logged_in() ? get_current_user_id() : null,
    		'callback_url' => $this->get_callback_url_for_ajax($gateway),
    		'success_url'  => $success_url,
    		'form_id'      => isset($data['form_id']) ? sanitize_key($data['form_id']) : 'direct_ajax',
    		'post_id'      => isset($data['post_id']) ? intval($data['post_id']) : 0,
    	);
    
    	// Validate payment data using security manager
    	$security_manager = GatewayKit_Security_Manager::get_instance();
    	$validated_data = $security_manager->validate_payment_data($payment_data);
    
    	if (is_wp_error($validated_data)) {
    		$logger->error('Payment data validation failed for direct payment', array(
    			'errors' => $validated_data->get_error_messages()
    		));
    		return $validated_data;
    	}
    
    	// Process payment using direct gateway integration
    	$result = $this->process_payment_direct($validated_data);
    	
    	if (is_wp_error($result)) {
    		return $result;
    	}
    
    	return array(
    		'success' => true,
    		'redirect_url' => $result['redirect_url'],
    		'authority' => $result['authority'],
    		'message' => __('Payment initiated successfully.', 'gatewaykit')
    	);
    }
    
    /**
     * Sanitize form data for AJAX requests
     *
     * @param array $form_data Form data
     * @return array Sanitized form data
     */
    private function sanitize_form_data($form_data) {
    	$sanitized = array();
    	foreach ($form_data as $key => $value) {
    		$sanitized[$key] = is_array($value) ? array_map('sanitize_text_field', $value) : sanitize_text_field($value);
    	}
    	return $sanitized;
    }
    
    /**
     * Get user data for AJAX requests
     *
     * @return array User data
     */
    private function get_user_data_for_ajax() {
    	$user_data = array();
    	
    	if (is_user_logged_in() && get_current_user_id()) {
    		$user      = wp_get_current_user();
    		$user_data = array(
    			'id'    => $user->ID,
    			'email' => $user->user_email,
    			'name'  => $user->display_name,
    		);
    	}
    	
    	$user_data['ip']         = GatewayKit_IP_Helper::get_client_ip();
    	$user_data['user_agent'] = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) );
    	
    	return $user_data;
    }
    
    /**
     * Get callback URL for AJAX requests
     *
     * @param string $gateway Gateway ID
     * @return string Callback URL
     */
    private function get_callback_url_for_ajax($gateway) {
    	return add_query_arg(
    		array(
    			'gatewaykit_callback' => '1',
    			'gateway'          => $gateway,
    		),
    		home_url('/payment-callback/')
    	);
    }
    
    /**
     * Process payment directly without Elementor integration
     *
     * @param array $payment_data Payment data
     * @return array|WP_Error Payment result or error
     */
    private function process_payment_direct($payment_data) {
    	$logger = GatewayKit_Logger::get_instance();
    	
    	try {
    		// Create transaction record
    		$transaction = GatewayKit_Transaction_Model::create($payment_data);
    		
    		if (is_wp_error($transaction)) {
    			$logger->error('Failed to create transaction for direct payment', array(
    				'error' => $transaction->get_error_message()
    			));
    			return $transaction;
    		}
    		
    		// Get gateway instance
    		$gateway_manager = GatewayKit_Gateway_Manager::get_instance();
    		$gateway = $gateway_manager->get_gateway($payment_data['gateway']);
    		
    		if (!$gateway) {
    			$logger->error('Gateway not found for direct payment', array(
    				'gateway' => $payment_data['gateway']
    			));
    			return new WP_Error('gateway_not_found', esc_html__('Payment gateway not found.', 'gatewaykit'));
    		}
    		
    		// Check if gateway is enabled and available
    		$enabled_gateways = get_option('gatewaykit_enabled_gateways', array());
    		if (!isset($enabled_gateways[$payment_data['gateway']]) || $enabled_gateways[$payment_data['gateway']] !== '1') {
    			$logger->error('Gateway disabled for direct payment', array(
    				'gateway' => $payment_data['gateway']
    			));
    			return new WP_Error('gateway_disabled', esc_html__('Selected payment gateway is not enabled.', 'gatewaykit'));
    		}
    		
    		if (!$gateway->is_available()) {
    			$logger->error('Gateway unavailable for direct payment', array(
    				'gateway' => $payment_data['gateway']
    			));
    			return new WP_Error('gateway_unavailable', esc_html__('Selected payment gateway is not properly configured.', 'gatewaykit'));
    		}
    		
    		// Process payment
    		$callback_url = str_replace(
    			'{gateway}',
    			$payment_data['gateway'],
    			$payment_data['callback_url']
    		);
    		
    		$result = $gateway->process_payment(
    			$payment_data['amount'],
    			$payment_data['description'],
    			$callback_url,
    			$payment_data['user_data']
    		);
    		
    		if (isset($result['authority'])) {
    			// Update transaction with authority
    			$transaction->update(array('authority' => $result['authority']));
    			
    			// Get redirect URL
    			$redirect_url = $gateway->get_redirect_url($result['authority']);
    			
    			$logger->info('Direct payment initiated successfully', array(
    				'transaction_id' => $transaction->get('id'),
    				'gateway' => $payment_data['gateway'],
    				'authority' => $result['authority']
    			));
    			
    			return array(
    				'status' => 'success',
    				'redirect_url' => $redirect_url,
    				'authority' => $result['authority'],
    			);
    		}
    		
    		// Handle payment failure
    		if (isset($result['status']) && $result['status'] === 'error') {
    			$transaction->update(array(
    				'status' => 'failed',
    			));
    			
    			// Store error details if available
    			if (isset($result['error_message']) || isset($result['error_type'])) {
    				$transaction->set_error(
    					$result['error_message'] ?? $result['message'] ?? 'Payment failed',
    					$result['error_code'] ?? '',
    					$result['error_type'] ?? 'unknown',
    					$result['error_details'] ?? null
    				);
    			}
    			
    			$logger->error('Direct payment failed', array(
    				'transaction_id' => $transaction->get('id'),
    				'gateway' => $payment_data['gateway'],
    				'error' => $result['error_message'] ?? $result['message'] ?? 'Unknown error'
    			));
    			
    			// Use error handler to create proper WP_Error with preserved details
    			$error_handler = GatewayKit_Error_Handler::get_instance();
    			return $error_handler->create_wp_error_from_gateway($result, false);
    		}
    		
    		$logger->error('Direct payment initiation failed', array(
    			'transaction_id' => $transaction->get('id'),
    			'gateway' => $payment_data['gateway']
    		));
    		
    		return new WP_Error('payment_failed', esc_html__('Payment initiation failed. Please check your payment details and try again.', 'gatewaykit'));
    		
    	} catch (Exception $e) {
    		$logger->error('Exception during direct payment processing', array(
    			'error' => $e->getMessage(),
    			'trace' => $e->getTraceAsString()
    		));
    		return new WP_Error('payment_error', esc_html__('An error occurred during payment processing.', 'gatewaykit'));
    	}
    	}

    /**
     * Recursively scan Elementor elements for GatewayKit Pro form fields
     * (Payment Summary or Discount Code).
     *
     * @param array $elements Elementor elements tree.
     * @return bool True if a pro form field is found.
     */
    private static function scan_for_pro_form_fields( $elements ) {
        if ( ! is_array( $elements ) ) {
            return false;
        }

        $pro_field_types = array(
            'gatewaykit_payment_info',
            'gatewaykit_discount_code',
        );

        foreach ( $elements as $element ) {
            $is_form_widget = isset( $element['elType'] )
                && 'widget' === $element['elType']
                && isset( $element['widgetType'] )
                && 'form' === $element['widgetType'];

            if ( $is_form_widget ) {
                $fields = isset( $element['settings']['form_fields'] ) ? $element['settings']['form_fields'] : array();
                if ( is_array( $fields ) ) {
                    foreach ( $fields as $field ) {
                        if ( isset( $field['field_type'] ) && in_array( $field['field_type'], $pro_field_types, true ) ) {
                            return true;
                        }
                    }
                }
            }

            if ( ! empty( $element['elements'] ) ) {
                if ( self::scan_for_pro_form_fields( $element['elements'] ) ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Handle invoice PDF download endpoint.
     *
     * Endpoint: ?gatewaykit_invoice=<receipt_token>
     * Returns PDF with Content-Disposition: attachment.
     */
    public function handle_invoice_download() {
        if ( empty( $_GET['gatewaykit_invoice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return;
        }

        $receipt_token = sanitize_text_field( wp_unslash( $_GET['gatewaykit_invoice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if ( ! preg_match( '/^[A-Z0-9]{6}-[A-Z0-9]{6}$/i', $receipt_token ) ) {
            wp_die( esc_html__( 'Invalid receipt code format.', 'gatewaykit' ), 400 );
        }

        $receipt_token = strtoupper( $receipt_token );

        $transaction = GatewayKit_Transaction_Model::find_by_receipt_token( $receipt_token );

        if ( ! $transaction ) {
            wp_die( esc_html__( 'Transaction not found.', 'gatewaykit' ), 404 );
        }

        if ( 'completed' !== $transaction->status ) {
            wp_die( esc_html__( 'Invoice is only available for completed payments.', 'gatewaykit' ), 403 );
        }

        if ( ! class_exists( 'GatewayKit_Receipt_PDF' ) ) {
            $pdf_path = GATEWAYKIT_PLUGIN_DIR . 'src/Core/Email/class-receipt-pdf.php';
            if ( file_exists( $pdf_path ) ) {
                require_once $pdf_path;
            }
        }

        if ( ! class_exists( 'GatewayKit_Receipt_PDF' ) ) {
            wp_die( esc_html__( 'PDF generator not available.', 'gatewaykit' ), 500 );
        }

        try {
            $pdf_content = GatewayKit_Receipt_PDF::generate( $transaction, 'invoice' );
            $filename    = GatewayKit_Receipt_PDF::get_filename( $transaction, 'invoice' );

            nocache_headers();
            header( 'Content-Type: application/pdf' );
            header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
            header( 'Content-Length: ' . strlen( $pdf_content ) );

            echo $pdf_content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            exit;
        } catch ( \Throwable $e ) {
            GatewayKit_Logger::get_instance()->error( 'Invoice PDF download failed', array(
                'transaction_id' => $transaction->get( 'id' ),
                'error'          => $e->getMessage(),
            ) );
            wp_die( esc_html__( 'Failed to generate invoice.', 'gatewaykit' ), 500 );
        }
    }
   }
   
