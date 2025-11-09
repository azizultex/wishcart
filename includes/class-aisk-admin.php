<?php
/**
 * AISK Admin Class
 *
 * @category WordPress
 * @package  AISK
 * @author   Aisk Team <support@aisk.chat>
 * @license  GPL-2.0+ http://www.gnu.org/licenses/gpl-2.0.txt
 * @link     https://aisk.com
 */

if ( ! defined('ABSPATH') ) {
	exit;
}

/**
 * AISK Admin Class handles all admin-related functionality
 *
 * @category Class
 * @package  AISK
 * @author   Aisk Team <support@aisk.chat>
 * @license  GPL-2.0+ http://www.gnu.org/licenses/gpl-2.0.txt
 * @link     https://aisk.com
 */
class AISK_Admin {


    private $plugin_slug = 'aisk';
    private static $instance = null;

    /**
     * Get singleton instance of the class
     *
     * @since 1.0.0
     *
     * @return AISK_Admin Instance of the class
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
    public function __construct() {
        add_action('admin_menu', [ $this, 'register_admin_menu' ]);
        add_action('admin_enqueue_scripts', [ $this, 'enqueue_admin_scripts' ]);
        add_action('admin_enqueue_scripts', [ $this, 'enqueue_admin_styles' ]);

        add_action('rest_api_init', [ $this, 'aisk_register_settings_endpoints' ]);
    }
    
    /**
     * Menu Left Style
     */
    public function enqueue_admin_styles( $hook_suffix ) {
        wp_enqueue_style( 
            'aisk-admin-style', 
            plugin_dir_url( dirname( __FILE__ ) ) . 'assets/css/admin-style.css',
            [],
            AISK_VERSION
        );
    }

    /**
     * Register admin menu items
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function register_admin_menu() {

        add_menu_page(
            esc_html__( 'Aisk', 'aisk-ai-chat-for-fluentcart' ),
            esc_html__( 'Aisk', 'aisk-ai-chat-for-fluentcart' ),
            'manage_options',
            $this->plugin_slug,
            [ $this, 'render_dashboard_page' ],
            plugin_dir_url( dirname( __FILE__ ) ) . 'assets/images/icons/menu-icon-short.svg', 
            30
        );

        add_submenu_page(
            $this->plugin_slug,
            esc_html__( 'Inquiries', 'aisk-ai-chat-for-fluentcart' ),
            esc_html__( 'Inquiries', 'aisk-ai-chat-for-fluentcart' ),
            'manage_options',
            $this->plugin_slug . '-inquiries',
            [ $this, 'render_inquiries_page' ]
        );

        // Add Chat History submenu
        add_submenu_page(
            $this->plugin_slug,
            esc_html__( 'Chat History', 'aisk-ai-chat-for-fluentcart' ),
            esc_html__( 'Chat History', 'aisk-ai-chat-for-fluentcart' ),
            'manage_options',
            $this->plugin_slug . '-history',
            [ $this, 'render_history_page' ]
        );

        // Add Uses Analytics submenu
        add_submenu_page(
            $this->plugin_slug,
            esc_html__( 'API Usage', 'aisk-ai-chat-for-fluentcart' ),
            esc_html__( 'API Usage', 'aisk-ai-chat-for-fluentcart' ),
            'manage_options',
            $this->plugin_slug . '-uses',
            [ $this, 'render_uses_page' ]
        );

        // Add Settings submenu
        add_submenu_page(
            $this->plugin_slug,
            esc_html__( 'Settings', 'aisk-ai-chat-for-fluentcart' ),
            esc_html__( 'Settings', 'aisk-ai-chat-for-fluentcart' ),
            'manage_options',
            $this->plugin_slug . '-settings',
            [ $this, 'render_settings_page' ]
        );

        // Remove default submenu page
        remove_submenu_page($this->plugin_slug, $this->plugin_slug);
    }
    /**
     * Enqueue admin scripts and styles
     *
     * @param string $hook Current admin page hook
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function enqueue_admin_scripts($hook) {
        $allowed_hooks = [
            'toplevel_page_' . $this->plugin_slug,
            $this->plugin_slug . '_page_' . $this->plugin_slug . '-inquiries',
            $this->plugin_slug . '_page_' . $this->plugin_slug . '-history',
            $this->plugin_slug . '_page_' . $this->plugin_slug . '-uses',
            $this->plugin_slug . '_page_' . $this->plugin_slug . '-settings',
        ];

        if (!in_array($hook, $allowed_hooks)) {
            return;
        }

        // First load the common chat widget assets
        AISK_Scripts::load_chat_widget_assets();

        // Then load admin-specific assets
        wp_enqueue_media();

        // Register and enqueue admin styles
        wp_register_style(
            'aisk-admin',
            AISK_PLUGIN_URL . 'build/chat-admin.css',
            [],
            AISK_VERSION
        );
        wp_enqueue_style('aisk-admin');

        // Register and enqueue admin scripts
        wp_register_script(
            'aisk-admin',
            AISK_PLUGIN_URL . 'build/chat-admin.js',
            ['wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n'],
            AISK_VERSION,
            [
                'in_footer' => true,
                'strategy' => 'defer'
            ]
        );
        wp_enqueue_script('aisk-admin');

        wp_localize_script(
            'aisk-admin',
            'AiskSettings',
            [
                'apiUrl' => rest_url('aisk/v1'),
                'nonce' => wp_create_nonce('wp_rest'),
                'pluginUrl' => AISK_PLUGIN_URL,
                'isFluentCartActive' => AISK_FluentCart_Helper::is_fluentcart_active(),
                'maxUploadSize' => wp_max_upload_size(),
            ]
        );
        wp_set_script_translations('aisk-admin', 'aisk-ai-chat-for-fluentcart');
    }

    /**
     * Register REST API endpoints for settings
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function aisk_register_settings_endpoints() {
        register_rest_route(
            'aisk/v1', '/settings', [
				[
					'methods' => 'GET',
					'callback' => [ $this, 'aisk_get_settings' ],
					'permission_callback' => function () {
						return current_user_can('manage_options');
					},
				],
				[
					'methods' => 'POST',
					'callback' => [ $this, 'aisk_update_settings' ],
					'permission_callback' => function () {
						return current_user_can('manage_options');
					},
				],
            ]
        );
        register_rest_route('aisk/v1', '/install-fluentcart', array(
            'methods' => 'POST',
            'callback' => array( $this, 'install_fluentcart' ),
            'permission_callback' => function () {
                return current_user_can('activate_plugins');
            },
        ));

        register_rest_route('aisk/v1', '/check-fluentcart', array(
            'methods' => 'GET',
            'callback' => array( $this, 'check_fluentcart_status' ),
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ));

        register_rest_route('aisk/v1', '/products', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_products'),
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ));

        // Analytics endpoints
        register_rest_route('aisk/v1', '/analytics/overview', array(
            'methods' => 'GET',
            'callback' => array( $this, 'get_analytics_overview' ),
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ));

        register_rest_route('aisk/v1', '/analytics/usage', array(
            'methods' => 'GET',
            'callback' => array( $this, 'get_analytics_usage' ),
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ));


        register_rest_route('aisk/v1', '/analytics/errors', array(
            'methods' => 'GET',
            'callback' => array( $this, 'get_analytics_errors' ),
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ));

        register_rest_route('aisk/v1', '/analytics/costs', array(
            'methods' => 'GET',
            'callback' => array( $this, 'get_analytics_costs' ),
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ));
    }

    public function install_fluentcart() {
        if ( ! AISK_FluentCart_Helper::is_fluentcart_active() ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
            require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
            require_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';

            try {
                // First, check if FluentCart might already be installed in various locations
                // Note: WordPress.org plugin uses 'fluent-cart' slug, so the directory is likely 'fluent-cart'
                $possible_paths = [
                    'fluent-cart/fluent-cart.php', // WordPress.org version
                    'fluentcart/fluentcart.php',
                    'fluentcart-pro/fluentcart-pro.php',
                ];
                
                $found_path = null;
                foreach ( $possible_paths as $path ) {
                    if ( file_exists( WP_PLUGIN_DIR . '/' . $path ) ) {
                        $found_path = $path;
                        break;
                    }
                }
                
                // If FluentCart is not found, try to install it
                if ( ! $found_path ) {
                    // Get FluentCart download URL from WordPress repository
                    // Note: The WordPress.org slug is 'fluent-cart' (with hyphen)
                    $api = plugins_api('plugin_information', array(
                        'slug' => 'fluent-cart',
                        'fields' => array( 'download_link' => true ),
                    ));

                    if ( is_wp_error($api) ) {
                        // If primary slug fails, try alternative slug
                        $api = plugins_api('plugin_information', array(
                            'slug' => 'fluentcart',
                            'fields' => array( 'download_link' => true ),
                        ));
                    }

                    if ( is_wp_error($api) ) {
                        // Both slugs failed - provide helpful error message with manual installation instructions
                        $error_message = sprintf(
                            // translators: %s: URL to the FluentCart plugin page on WordPress.org
                            __(
                                'FluentCart could not be automatically installed from the WordPress repository. Please install FluentCart manually: 1. Go to %s and download FluentCart. 2. Go to WordPress Admin > Plugins > Add New > Upload Plugin. 3. Upload the FluentCart zip file. 4. Activate the plugin. 5. Click the "Refresh" button here to detect it. Alternatively, if FluentCart is already installed but not detected, click "Refresh" to re-check.',
                                'aisk-ai-chat-for-fluentcart'
                            ),
                            'https://wordpress.org/plugins/fluent-cart/'
                        );
                        return new WP_Error( 'api_error', $error_message );
                    }

                    // Install FluentCart
                    $skin = new WP_Ajax_Upgrader_Skin();
                    $upgrader = new Plugin_Upgrader($skin);
                    $result = $upgrader->install($api->download_link);

                    if ( is_wp_error($result) ) {
                        return new WP_Error('installation_failed', $result->get_error_message());
                    }
                    
                    // After installation, try to find the installed path
                    foreach ( $possible_paths as $path ) {
                        if ( file_exists( WP_PLUGIN_DIR . '/' . $path ) ) {
                            $found_path = $path;
                            break;
                        }
                    }
                    
                    if ( ! $found_path ) {
                        return new WP_Error(
                            'installation_failed',
                            __(
                                'FluentCart was installed but could not be found. Please check the plugins directory and activate it manually, then click "Refresh".',
                                'aisk-ai-chat-for-fluentcart'
                            )
                        );
                    }
                }
                
                // Activate FluentCart using the found path
                if ( ! is_plugin_active($found_path) ) {
                    $result = activate_plugin($found_path);
                    if ( is_wp_error($result) ) {
                        return new WP_Error( 'activation_failed', $result->get_error_message() );
                    }
                }

                // Clear detection cache after installation
                AISK_FluentCart_Helper::clear_detection_cache();
                
                // Force reload of plugin cache if needed
                wp_cache_flush();
                
                // Re-check status after activation
                $is_active = AISK_FluentCart_Helper::is_fluentcart_active();

                return array(
                    'success' => true,
                    'message' => $is_active ? 'FluentCart installed and activated successfully' : 'FluentCart installed. Please click "Refresh" to update status.',
                    'isActive' => $is_active,
                );

            } catch ( Exception $e ) {
                return new WP_Error('installation_error', $e->getMessage());
            }
        }

        // Clear cache and return current status
        AISK_FluentCart_Helper::clear_detection_cache();

        return array(
            'success' => true,
            'message' => 'FluentCart is already installed and activated',
            'isActive' => AISK_FluentCart_Helper::is_fluentcart_active(),
        );
    }

    /**
     * Check FluentCart status endpoint
     *
     * @return WP_REST_Response
     */
    public function check_fluentcart_status() {
        // Clear cache before checking to ensure fresh status
        AISK_FluentCart_Helper::clear_detection_cache();
        
        $is_active = AISK_FluentCart_Helper::is_fluentcart_active();
        
        return rest_ensure_response(array(
            'success' => true,
            'isActive' => $is_active,
            'message' => $is_active ? 'FluentCart is active' : 'FluentCart is not installed or not active',
        ));
    }

    /**
     * Render inquiries admin page
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function render_inquiries_page() {
        echo '<div id="aisk-inquiries"></div>';
    }

    /**
     * Render chat history admin page
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function render_history_page() {
        echo '<div id="aisk-history"></div>';
    }

    /**
     * Render uses analytics admin page
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function render_uses_page() {
        echo '<div id="aisk-uses"></div>';
    }

    /**
     * Render settings admin page
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function render_settings_page() {
        echo '<div id="aisk-settings-app"></div>';
    }

    /**
     * Get plugin settings
     *
     * @since 1.0.0
     *
     * @return WP_REST_Response
     */
    public function aisk_get_settings() {
        $settings = get_option('aisk_settings', []);
        return rest_ensure_response($settings);
    }

    /**
     * Update plugin settings
     *
     * @param WP_REST_Request $request Request object
     *
     * @since 1.0.0
     *
     * @return WP_REST_Response
     */
    public function aisk_update_settings( $request ) {
        $settings = $request->get_json_params();

        // Basic sanitization for excluded FluentCart products
        if (isset($settings['ai_config']['excluded_products']) && is_array($settings['ai_config']['excluded_products'])) {
            $sanitized_products = array();
            foreach ($settings['ai_config']['excluded_products'] as $item) {
                if (is_array($item) && isset($item['value']) && isset($item['label'])) {
                    $sanitized_products[] = array(
                        'value' => intval($item['value']),
                        'label' => sanitize_text_field($item['label']),
                        'type'  => 'product',
                    );
                } elseif (is_numeric($item)) {
                    $sanitized_products[] = array(
                        'value' => intval($item),
                        'label' => '',
                        'type'  => 'product',
                    );
                }
            }
            $settings['ai_config']['excluded_products'] = $sanitized_products;
        }

        update_option('aisk_settings', $settings);
        return rest_ensure_response([ 'success' => true ]);
    }

    /**
     * Get analytics overview data
     *
     * @param WP_REST_Request $request Request object
     *
     * @since 1.0.0
     *
     * @return WP_REST_Response
     */
    public function get_analytics_overview( $request ) {
        $time_filter = $request->get_param('time_filter') ?: '7days';
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'aisk_api_usage';
        
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table names cannot be parameterized, analytics data needs real-time queries
        
        // Calculate date range based on filter
        $days = 7;
        switch ($time_filter) {
            case '30days':
                $days = 30;
                break;
            case '90days':
                $days = 90;
                break;
        }
        
        $date_from = gmdate('Y-m-d H:i:s', strtotime("-{$days} days"));
        $feature = $request->get_param('feature');
        $feature = is_string($feature) ? sanitize_text_field($feature) : '';
        
        // Get total requests
        if (!empty($feature) && $feature !== 'all') {
            $table_name_sql = esc_sql($table_name);
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be parameterized
            $total_requests = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table_name_sql} WHERE created_at >= %s AND feature = %s",
                $date_from, $feature
            ));
        } else {
            $table_name_sql = esc_sql($table_name);
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be parameterized
            $total_requests = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table_name_sql} WHERE created_at >= %s",
                $date_from
            ));
        }

        // Get chat requests (not filtered by feature to show overall chat volume)
        $table_name_sql = esc_sql($table_name);
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be parameterized
        $chat_requests = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_name_sql} WHERE created_at >= %s AND feature = 'chat'",
            $date_from
        ));

        // Get classify requests (not filtered by feature to show overall classify volume)
        $table_name_sql = esc_sql($table_name);
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be parameterized
        $classify_requests = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_name_sql} WHERE created_at >= %s AND feature = 'classify'",
            $date_from
        ));
        
        // Get successful requests
        if (!empty($feature) && $feature !== 'all') {
            $table_name_sql = esc_sql($table_name);
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be parameterized
            $successful_requests = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table_name_sql} WHERE created_at >= %s AND status = 'success' AND feature = %s",
                $date_from, $feature
            ));
        } else {
            $table_name_sql = esc_sql($table_name);
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be parameterized
            $successful_requests = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table_name_sql} WHERE created_at >= %s AND status = 'success'",
                $date_from
            ));
        }
        
        // Calculate success rate
        $success_rate = $total_requests > 0 ? round(($successful_requests / $total_requests) * 100, 1) : 0;
        
        // Get average latency
        if (!empty($feature) && $feature !== 'all') {
            $table_name_sql = esc_sql($table_name);
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be parameterized
            $avg_latency = $wpdb->get_var($wpdb->prepare(
                "SELECT AVG(latency_ms) FROM {$table_name_sql} WHERE created_at >= %s AND latency_ms > 0 AND feature = %s",
                $date_from, $feature
            ));
        } else {
            $table_name_sql = esc_sql($table_name);
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be parameterized
            $avg_latency = $wpdb->get_var($wpdb->prepare(
                "SELECT AVG(latency_ms) FROM {$table_name_sql} WHERE created_at >= %s AND latency_ms > 0",
                $date_from
            ));
        }
        $avg_latency = $avg_latency ? round($avg_latency / 1000, 2) : 0; // Convert to seconds
        
        // Total cost removed as requested
        
        // Get total tokens (include system prompt tokens from metadata.system_tokens)
        $tokens_expr = "(tokens_in + tokens_out + COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.system_tokens')) AS UNSIGNED), 0))";
        if (!empty($feature) && $feature !== 'all') {
            $table_name_sql = esc_sql($table_name);
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name and expression cannot be parameterized
            $total_tokens = $wpdb->get_var($wpdb->prepare(
                "SELECT SUM({$tokens_expr}) FROM {$table_name_sql} WHERE created_at >= %s AND feature = %s",
                $date_from, $feature
            ));
        } else {
            $table_name_sql = esc_sql($table_name);
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name and expression cannot be parameterized
            $total_tokens = $wpdb->get_var($wpdb->prepare(
                "SELECT SUM({$tokens_expr}) FROM {$table_name_sql} WHERE created_at >= %s",
                $date_from
            ));
        }
        $total_tokens = $total_tokens ? intval($total_tokens) : 0;
        
        // Calculate error rate
        $error_rate = $total_requests > 0 ? round((($total_requests - $successful_requests) / $total_requests) * 100, 1) : 0;
        
        $overview_data = [
            'totalRequests' => intval($total_requests),
            'chatRequests' => intval($chat_requests),
            'classifyRequests' => intval($classify_requests),
            'successRate' => $success_rate,
            'avgLatency' => $avg_latency,
            // 'totalCost' removed
            'totalTokens' => $total_tokens,
            'errorRate' => $error_rate
        ];
        
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

        return rest_ensure_response($overview_data);
    }

    /**
     * Get analytics usage data
     *
     * @param WP_REST_Request $request Request object
     *
     * @since 1.0.0
     *
     * @return WP_REST_Response
     */
    public function get_analytics_usage( $request ) {
        $time_filter = $request->get_param('time_filter') ?: '7days';
        $feature = $request->get_param('feature');
        $feature = is_string($feature) ? sanitize_text_field($feature) : '';
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'aisk_api_usage';
        
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table names cannot be parameterized, analytics data needs real-time queries
        
        // Calculate date range based on filter
        $days = 7;
        switch ($time_filter) {
            case '30days':
                $days = 30;
                break;
            case '90days':
                $days = 90;
                break;
        }
        
        $date_from = gmdate('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        // Get daily usage data (include system prompt tokens from metadata.system_tokens)
        $tokens_expr = "(tokens_in + tokens_out + COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.system_tokens')) AS UNSIGNED), 0))";
        if (!empty($feature) && $feature !== 'all') {
            $table_name_sql = esc_sql($table_name);
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name and expression cannot be parameterized
            $usage_data = $wpdb->get_results($wpdb->prepare(
                "SELECT 
                    DATE(created_at) as date,
                    COUNT(*) as requests,
                    SUM({$tokens_expr}) as tokens
                FROM {$table_name_sql} 
                WHERE created_at >= %s AND feature = %s
                GROUP BY DATE(created_at) 
                ORDER BY date ASC",
                $date_from, $feature
            ));
        } else {
            $table_name_sql = esc_sql($table_name);
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name and expression cannot be parameterized
            $usage_data = $wpdb->get_results($wpdb->prepare(
                "SELECT 
                    DATE(created_at) as date,
                    COUNT(*) as requests,
                    SUM({$tokens_expr}) as tokens
                FROM {$table_name_sql} 
                WHERE created_at >= %s 
                GROUP BY DATE(created_at) 
                ORDER BY date ASC",
                $date_from
            ));
        }
        
        // Format the data
        $formatted_data = [];
        foreach ($usage_data as $row) {
            $formatted_data[] = [
                'date' => $row->date,
                'requests' => intval($row->requests),
                'tokens' => intval($row->tokens)
            ];
        }
        
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

        return rest_ensure_response($formatted_data);
    }



    /**
     * Get analytics errors data
     *
     * @param WP_REST_Request $request Request object
     *
     * @since 1.0.0
     *
     * @return WP_REST_Response
     */
    public function get_analytics_errors( $request ) {
        $time_filter = $request->get_param('time_filter') ?: '7days';
        $feature = $request->get_param('feature');
        $feature = is_string($feature) ? sanitize_text_field($feature) : '';
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'aisk_api_usage';
        
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table names cannot be parameterized, analytics data needs real-time queries
        
        // Calculate date range based on filter
        $days = 7;
        switch ($time_filter) {
            case '30days':
                $days = 30;
                break;
            case '90days':
                $days = 90;
                break;
        }
        
        $date_from = gmdate('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        // Get error data
        if (!empty($feature) && $feature !== 'all') {
            $table_name_sql = esc_sql($table_name);
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be parameterized
            $errors_data = $wpdb->get_results($wpdb->prepare(
                "SELECT 
                    error_code,
                    COUNT(*) as count
                FROM {$table_name_sql} 
                WHERE created_at >= %s AND status != 'success' AND error_code IS NOT NULL AND feature = %s
                GROUP BY error_code 
                ORDER BY count DESC",
                $date_from, $feature
            ));
        } else {
            $table_name_sql = esc_sql($table_name);
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be parameterized
            $errors_data = $wpdb->get_results($wpdb->prepare(
                "SELECT 
                    error_code,
                    COUNT(*) as count
                FROM {$table_name_sql} 
                WHERE created_at >= %s AND status != 'success' AND error_code IS NOT NULL
                GROUP BY error_code 
                ORDER BY count DESC",
                $date_from
            ));
        }
        
        // Calculate total errors for percentage
        $total_errors = 0;
        foreach ($errors_data as $row) {
            $total_errors += intval($row->count);
        }
        
        // Format the data
        $formatted_data = [];
        foreach ($errors_data as $row) {
            $percentage = $total_errors > 0 ? round((intval($row->count) / $total_errors) * 100, 1) : 0;
            
            $formatted_data[] = [
                'error' => ucfirst(str_replace('_', ' ', $row->error_code)),
                'count' => intval($row->count),
                'percentage' => $percentage
            ];
        }
        
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

        return rest_ensure_response($formatted_data);
    }

    /**
     * Get analytics costs data
     *
     * @param WP_REST_Request $request Request object
     *
     * @since 1.0.0
     *
     * @return WP_REST_Response
     */
    public function get_analytics_costs( $request ) {
        $time_filter = $request->get_param('time_filter') ?: '7days';
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'aisk_api_usage';
        
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table names cannot be parameterized, analytics data needs real-time queries
        
        // Calculate date range based on filter
        $days = 7;
        switch ($time_filter) {
            case '30days':
                $days = 30;
                break;
            case '90days':
                $days = 90;
                break;
        }
        
        $date_from = gmdate('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        // Get daily OpenAI cost data only (current provider)
        $table_name_sql = esc_sql($table_name);
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be parameterized
        $costs_data = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                DATE(created_at) as date,
                provider,
                SUM(cost_usd) as cost
            FROM {$table_name_sql} 
            WHERE created_at >= %s AND provider = 'openai'
            GROUP BY DATE(created_at), provider 
            ORDER BY date ASC, provider ASC",
            $date_from
        ));
        
        // Format the data by date and provider
        $formatted_data = [];
        $date_costs = [];
        
        foreach ($costs_data as $row) {
            $date = $row->date;
            if (!isset($date_costs[$date])) {
                $date_costs[$date] = ['date' => $date, 'total' => 0];
            }
            
            $date_costs[$date]['total'] += floatval($row->cost);
            $date_costs[$date][strtolower($row->provider)] = round(floatval($row->cost), 2);
        }
        
        // Convert to array format
        foreach ($date_costs as $date => $costs) {
            $costs['total'] = round($costs['total'], 2);
            $formatted_data[] = $costs;
        }
        
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

        return rest_ensure_response($formatted_data);
    }

    /**
     * Get products for FluentCart only (used by admin settings UI)
     *
     * @param WP_REST_Request $request Request object
     *
     * @since 1.0.0
     *
     * @return WP_REST_Response
     */
    public function get_products( $request ) {
        $product_post_type = AISK_FluentCart_Helper::get_product_post_type();

        $search = is_string( $request->get_param( 'search' ) ) ? sanitize_text_field( $request->get_param( 'search' ) ) : '';
        $per_page = intval( $request->get_param( 'per_page' ) );
        if ( $per_page <= 0 || $per_page > 100 ) {
            $per_page = 100;
        }

        // Allow explicit override for debugging (not exposed in UI)
        $forced_type = is_string( $request->get_param( 'post_type' ) ) ? sanitize_key( $request->get_param( 'post_type' ) ) : '';
        $candidates = array_unique( array_filter( [
            $forced_type,
            $product_post_type,
            'fc_product',
            'fluent-products',
            'fluent_product',
            'fluentcart_product',
        ] ) );

        $products = array();

        foreach ( $candidates as $candidate_type ) {
            $args = array(
                'post_type' => array( $candidate_type ),
                'post_status' => 'publish',
                'posts_per_page' => $per_page,
                'orderby' => 'title',
                'order' => 'ASC',
                's' => $search,
                'no_found_rows' => true,
            );

            // Remove empty search to avoid slow queries
            if ( '' === $search ) {
                unset( $args['s'] );
            }

            $query = new WP_Query( $args );

            if ( $query->have_posts() ) {
                while ( $query->have_posts() ) {
                    $query->the_post();
                    $products[] = array(
                        'id' => get_the_ID(),
                        'name' => get_the_title(),
                        'type' => 'product',
                    );
                }
                wp_reset_postdata();
                break; // we found the correct type
            }
            wp_reset_postdata();
        }

        return rest_ensure_response( $products );
    }
}

AISK_Admin::get_instance();
