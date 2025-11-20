<?php
/**
 * AISK Admin Class
 *
 * @category WordPress
 * @package  AISK
 * @author   WishCart Team <support@wishcart.chat>
 * @license  GPL-2.0+ http://www.gnu.org/licenses/gpl-2.0.txt
 * @link     https://wishcart.com
 */

if ( ! defined('ABSPATH') ) {
	exit;
}

/**
 * AISK Admin Class handles all admin-related functionality
 *
 * @category Class
 * @package  AISK
 * @author   WishCart Team <support@wishcart.chat>
 * @license  GPL-2.0+ http://www.gnu.org/licenses/gpl-2.0.txt
 * @link     https://wishcart.com
 */
class WISHCART_Admin {


    private $plugin_slug = 'wishcart';
    private static $instance = null;

    /**
     * Get singleton instance of the class
     *
     * @since 1.0.0
     *
     * @return WISHCART_Admin Instance of the class
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
        if ( is_admin() ) {
            add_action('admin_menu', [ $this, 'register_admin_menu' ]);
            add_action('admin_enqueue_scripts', [ $this, 'enqueue_admin_scripts' ]);
            add_action('admin_enqueue_scripts', [ $this, 'enqueue_admin_styles' ]);
        }

        add_action('rest_api_init', [ $this, 'wishcart_register_settings_endpoints' ]);
    }
    
    /**
     * Menu Left Style
     */
    public function enqueue_admin_styles( $hook_suffix ) {
        wp_enqueue_style( 
            'wishcart-admin-style', 
            plugin_dir_url( dirname( __FILE__ ) ) . 'assets/css/admin-style.css',
            [],
            WISHCART_VERSION
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
            esc_html__( 'WishCart', 'wish-cart' ),
            esc_html__( 'WishCart', 'wish-cart' ),
            'manage_options',
            $this->plugin_slug,
            [ $this, 'render_settings_page' ],
            plugin_dir_url( dirname( __FILE__ ) ) . 'assets/images/icons/menu-icon-short.svg', 
            30
        );

        // Add Settings submenu
        add_submenu_page(
            $this->plugin_slug,
            esc_html__( 'Settings', 'wish-cart' ),
            esc_html__( 'Settings', 'wish-cart' ),
            'manage_options',
            $this->plugin_slug . '-settings',
            [ $this, 'render_settings_page' ]
        );
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
            $this->plugin_slug . '_page_' . $this->plugin_slug . '-settings',
        ];

        if (!in_array($hook, $allowed_hooks)) {
            return;
        }

        // Load admin-specific assets
        wp_enqueue_media();

        // Register and enqueue admin styles
        wp_register_style(
            'wishcart-admin',
            WISHCART_PLUGIN_URL . 'build/admin.css',
            [],
            WISHCART_VERSION
        );
        wp_enqueue_style('wishcart-admin');

        // Register and enqueue admin scripts
        wp_register_script(
            'wishcart-admin',
            WISHCART_PLUGIN_URL . 'build/admin.js',
            ['wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n'],
            WISHCART_VERSION,
            [
                'in_footer' => true,
                'strategy' => 'defer'
            ]
        );
        wp_enqueue_script('wishcart-admin');

        wp_localize_script(
            'wishcart-admin',
            'WishCartSettings',
            [
                'apiUrl' => trailingslashit( rest_url( 'wishcart/v1' ) ),
                'nonce' => wp_create_nonce('wp_rest'),
                'pluginUrl' => WISHCART_PLUGIN_URL,
                'isFluentCartActive' => WISHCART_FluentCart_Helper::is_fluentcart_active(),
                'maxUploadSize' => wp_max_upload_size(),
            ]
        );
        wp_set_script_translations('wishcart-admin', 'wish-cart');
    }

    /**
     * Register REST API endpoints for settings
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function wishcart_register_settings_endpoints() {
        register_rest_route(
            'wishcart/v1', '/settings', [
				[
					'methods' => 'GET',
					'callback' => [ $this, 'wishcart_get_settings' ],
					'permission_callback' => function () {
						return current_user_can('manage_options');
					},
				],
				[
					'methods' => 'POST',
					'callback' => [ $this, 'wishcart_update_settings' ],
					'permission_callback' => function () {
						return current_user_can('manage_options');
					},
				],
            ]
        );
        register_rest_route('wishcart/v1', '/install-fluentcart', array(
            'methods' => 'POST',
            'callback' => array( $this, 'install_fluentcart' ),
            'permission_callback' => function () {
                return current_user_can('activate_plugins');
            },
        ));

        register_rest_route('wishcart/v1', '/check-fluentcart', array(
            'methods' => 'GET',
            'callback' => array( $this, 'check_fluentcart_status' ),
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ));

        register_rest_route('wishcart/v1', '/products', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_products'),
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ));

        // Wishlist endpoints
        register_rest_route('wishcart/v1', '/wishlist/add', array(
            'methods' => 'POST',
            'callback' => array($this, 'wishlist_add'),
            'permission_callback' => '__return_true', // Public endpoint
        ));

        register_rest_route('wishcart/v1', '/wishlist/remove', array(
            'methods' => 'POST',
            'callback' => array($this, 'wishlist_remove'),
            'permission_callback' => '__return_true', // Public endpoint
        ));

        register_rest_route('wishcart/v1', '/wishlist/track-cart', array(
            'methods' => 'POST',
            'callback' => array($this, 'wishlist_track_cart'),
            'permission_callback' => '__return_true', // Public endpoint
        ));

        register_rest_route('wishcart/v1', '/wishlist', array(
            'methods' => 'GET',
            'callback' => array($this, 'wishlist_get'),
            'permission_callback' => '__return_true', // Public endpoint
        ));

        register_rest_route('wishcart/v1', '/wishlist/check/(?P<product_id>\d+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'wishlist_check'),
            'permission_callback' => '__return_true', // Public endpoint
            'args' => array(
                'product_id' => array(
                    'required' => true,
                    'type' => 'integer',
                ),
            ),
        ));

        register_rest_route('wishcart/v1', '/wishlist/sync', array(
            'methods' => 'POST',
            'callback' => array($this, 'wishlist_sync'),
            'permission_callback' => function () {
                return is_user_logged_in();
            },
        ));

        register_rest_route('wishcart/v1', '/wishlist/users', array(
            'methods' => 'GET',
            'callback' => array($this, 'wishlist_get_users'),
            'permission_callback' => '__return_true', // Public endpoint
        ));

        // Guest email endpoints
        register_rest_route('wishcart/v1', '/guest/check-email', array(
            'methods' => 'GET',
            'callback' => array($this, 'guest_check_email'),
            'permission_callback' => '__return_true', // Public endpoint
        ));

        register_rest_route('wishcart/v1', '/guest/update-email', array(
            'methods' => 'POST',
            'callback' => array($this, 'guest_update_email'),
            'permission_callback' => '__return_true', // Public endpoint
        ));

        // Multiple wishlists endpoints
        register_rest_route('wishcart/v1', '/wishlists', array(
            'methods' => 'GET',
            'callback' => array($this, 'wishlists_get'),
            'permission_callback' => '__return_true', // Public endpoint
        ));

        register_rest_route('wishcart/v1', '/wishlists', array(
            'methods' => 'POST',
            'callback' => array($this, 'wishlists_create'),
            'permission_callback' => '__return_true', // Public endpoint
        ));

        register_rest_route('wishcart/v1', '/wishlists/(?P<id>\d+)', array(
            'methods' => 'PUT',
            'callback' => array($this, 'wishlists_update'),
            'permission_callback' => '__return_true', // Public endpoint
            'args' => array(
                'id' => array(
                    'required' => true,
                    'type' => 'integer',
                ),
            ),
        ));

        register_rest_route('wishcart/v1', '/wishlists/(?P<id>\d+)', array(
            'methods' => 'DELETE',
            'callback' => array($this, 'wishlists_delete'),
            'permission_callback' => '__return_true', // Public endpoint
            'args' => array(
                'id' => array(
                    'required' => true,
                    'type' => 'integer',
                ),
            ),
        ));

        register_rest_route('wishcart/v1', '/wishlist/share/(?P<share_code>[a-zA-Z0-9]+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'wishlist_get_by_share_code'),
            'permission_callback' => '__return_true', // Public endpoint
            'args' => array(
                'share_code' => array(
                    'required' => true,
                    'type' => 'string',
                ),
            ),
        ));

        // Analytics endpoints
        register_rest_route('wishcart/v1', '/analytics/overview', array(
            'methods' => 'GET',
            'callback' => array($this, 'analytics_get_overview'),
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ));

        register_rest_route('wishcart/v1', '/analytics/popular', array(
            'methods' => 'GET',
            'callback' => array($this, 'analytics_get_popular_products'),
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ));

        register_rest_route('wishcart/v1', '/analytics/conversion', array(
            'methods' => 'GET',
            'callback' => array($this, 'analytics_get_conversion'),
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ));

        register_rest_route('wishcart/v1', '/analytics/links', array(
            'methods' => 'GET',
            'callback' => array($this, 'analytics_get_links'),
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ));

        register_rest_route('wishcart/v1', '/analytics/product/(?P<product_id>\d+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'analytics_get_product'),
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ));

        // FluentCRM endpoints
        register_rest_route('wishcart/v1', '/fluentcrm/settings', array(
            'methods' => 'GET',
            'callback' => array($this, 'fluentcrm_get_settings'),
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ));

        register_rest_route('wishcart/v1', '/fluentcrm/settings', array(
            'methods' => 'POST',
            'callback' => array($this, 'fluentcrm_update_settings'),
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ));

        register_rest_route('wishcart/v1', '/fluentcrm/tags', array(
            'methods' => 'GET',
            'callback' => array($this, 'fluentcrm_get_tags'),
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ));

        register_rest_route('wishcart/v1', '/fluentcrm/lists', array(
            'methods' => 'GET',
            'callback' => array($this, 'fluentcrm_get_lists'),
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ));

        // Campaign endpoints
        register_rest_route('wishcart/v1', '/campaigns', array(
            'methods' => 'GET',
            'callback' => array($this, 'campaigns_get'),
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ));

        register_rest_route('wishcart/v1', '/campaigns', array(
            'methods' => 'POST',
            'callback' => array($this, 'campaigns_create'),
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ));

        register_rest_route('wishcart/v1', '/campaigns/(?P<id>\d+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'campaigns_get_single'),
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
            'args' => array(
                'id' => array(
                    'required' => true,
                    'type' => 'integer',
                ),
            ),
        ));

        register_rest_route('wishcart/v1', '/campaigns/(?P<id>\d+)', array(
            'methods' => 'PUT',
            'callback' => array($this, 'campaigns_update'),
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
            'args' => array(
                'id' => array(
                    'required' => true,
                    'type' => 'integer',
                ),
            ),
        ));

        register_rest_route('wishcart/v1', '/campaigns/(?P<id>\d+)', array(
            'methods' => 'DELETE',
            'callback' => array($this, 'campaigns_delete'),
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
            'args' => array(
                'id' => array(
                    'required' => true,
                    'type' => 'integer',
                ),
            ),
        ));

        register_rest_route('wishcart/v1', '/campaigns/(?P<id>\d+)/analytics', array(
            'methods' => 'GET',
            'callback' => array($this, 'campaigns_get_analytics'),
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
            'args' => array(
                'id' => array(
                    'required' => true,
                    'type' => 'integer',
                ),
            ),
        ));

        // Sharing endpoints
        register_rest_route('wishcart/v1', '/share/create', array(
            'methods' => 'POST',
            'callback' => array($this, 'share_create'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('wishcart/v1', '/share/(?P<share_token>[a-zA-Z0-9]+)/stats', array(
            'methods' => 'GET',
            'callback' => array($this, 'share_get_stats'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('wishcart/v1', '/share/(?P<share_token>[a-zA-Z0-9]+)/click', array(
            'methods' => 'POST',
            'callback' => array($this, 'share_track_click'),
            'permission_callback' => '__return_true',
        ));

        // Public share view endpoint (no authentication required)
        register_rest_route('wishcart/v1', '/share/(?P<share_token>[a-zA-Z0-9]+)/view', array(
            'methods' => 'GET',
            'callback' => array($this, 'share_view_wishlist'),
            'permission_callback' => '__return_true',
        ));

        // Notification endpoints
        register_rest_route('wishcart/v1', '/notifications/subscribe', array(
            'methods' => 'POST',
            'callback' => array($this, 'notifications_subscribe'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('wishcart/v1', '/notifications', array(
            'methods' => 'GET',
            'callback' => array($this, 'notifications_get'),
            'permission_callback' => function () {
                return is_user_logged_in();
            },
        ));

        register_rest_route('wishcart/v1', '/notifications/stats', array(
            'methods' => 'GET',
            'callback' => array($this, 'notifications_get_stats'),
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ));

        // Activity endpoints
        register_rest_route('wishcart/v1', '/activity/wishlist/(?P<wishlist_id>\d+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'activity_get_wishlist'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('wishcart/v1', '/activity/recent', array(
            'methods' => 'GET',
            'callback' => array($this, 'activity_get_recent'),
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ));

    }

    public function install_fluentcart() {
        if ( ! WISHCART_FluentCart_Helper::is_fluentcart_active() ) {
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
                                'wish-cart'
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
                                'wish-cart'
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
                WISHCART_FluentCart_Helper::clear_detection_cache();
                
                // Force reload of plugin cache if needed
                wp_cache_flush();
                
                // Re-check status after activation
                $is_active = WISHCART_FluentCart_Helper::is_fluentcart_active();

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
        WISHCART_FluentCart_Helper::clear_detection_cache();

        return array(
            'success' => true,
            'message' => 'FluentCart is already installed and activated',
            'isActive' => WISHCART_FluentCart_Helper::is_fluentcart_active(),
        );
    }

    /**
     * Check FluentCart status endpoint
     *
     * @return WP_REST_Response
     */
    public function check_fluentcart_status() {
        // Clear cache before checking to ensure fresh status
        WISHCART_FluentCart_Helper::clear_detection_cache();
        
        $is_active = WISHCART_FluentCart_Helper::is_fluentcart_active();
        
        return rest_ensure_response(array(
            'success' => true,
            'isActive' => $is_active,
            'message' => $is_active ? 'FluentCart is active' : 'FluentCart is not installed or not active',
        ));
    }

    /**
     * Render settings admin page
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function render_settings_page() {
        echo '<div id="wishcart-settings-app"></div>';
    }

    /**
     * Get plugin settings
     *
     * @since 1.0.0
     *
     * @return WP_REST_Response
     */
    public function wishcart_get_settings() {
        $settings = get_option('wishcart_settings', []);
        $page_id  = WISHCART_Wishlist_Page::create_wishlist_page();

        $defaults = WISHCART_Wishlist_Page::get_default_settings( $page_id );
        $changed  = false;

        if ( ! isset( $settings['wishlist'] ) || ! is_array( $settings['wishlist'] ) ) {
            $settings['wishlist'] = array();
            $changed               = true;
        }

        $merged = wp_parse_args( $settings['wishlist'], $defaults );

        if ( intval( $merged['wishlist_page_id'] ) !== intval( $page_id ) ) {
            $merged['wishlist_page_id'] = intval( $page_id );
        }

        if ( $settings['wishlist'] !== $merged ) {
            $settings['wishlist'] = $merged;
            $changed               = true;
        }

        if ( $changed ) {
            update_option( 'wishcart_settings', $settings );
        }

        return rest_ensure_response( $settings );
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
    public function wishcart_update_settings( $request ) {
        $settings = $request->get_json_params();


        update_option('wishcart_settings', $settings);
        return rest_ensure_response([ 'success' => true ]);
    }

    /**
     * Get analytics overview data - REMOVED (chat-related)
     *
     * @param WP_REST_Request $request Request object
     *
     * @since 1.0.0
     *
     * @return WP_REST_Response
     */
    public function get_analytics_overview( $request ) {
        return rest_ensure_response([]);
    }

    /**
     * Get analytics usage data - REMOVED (chat-related)
     *
     * @param WP_REST_Request $request Request object
     *
     * @since 1.0.0
     *
     * @return WP_REST_Response
     */
    public function get_analytics_usage( $request ) {
        return rest_ensure_response([]);
    }

    /**
     * Get analytics errors data - REMOVED (chat-related)
     *
     * @param WP_REST_Request $request Request object
     *
     * @since 1.0.0
     *
     * @return WP_REST_Response
     */
    public function get_analytics_errors( $request ) {
        return rest_ensure_response([]);
    }

    /**
     * Get analytics costs data - REMOVED (chat-related)
     *
     * @param WP_REST_Request $request Request object
     *
     * @since 1.0.0
     *
     * @return WP_REST_Response
     */
    public function get_analytics_costs( $request ) {
        return rest_ensure_response([]);
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
        $product_post_type = WISHCART_FluentCart_Helper::get_product_post_type();

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

    /**
     * Add product to wishlist
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function wishlist_add( $request ) {
        $handler = new WISHCART_Wishlist_Handler();
        $params = $request->get_json_params();
        $product_id = isset( $params['product_id'] ) ? intval( $params['product_id'] ) : 0;
        $session_id = isset( $params['session_id'] ) ? sanitize_text_field( wp_unslash( $params['session_id'] ) ) : null;
        $wishlist_id = isset( $params['wishlist_id'] ) ? intval( $params['wishlist_id'] ) : null;
        $guest_email = isset( $params['guest_email'] ) ? sanitize_email( wp_unslash( $params['guest_email'] ) ) : null;
        
        // Prepare options array with guest_email if provided
        $options = array();
        if ( ! empty( $guest_email ) && is_email( $guest_email ) ) {
            $options['guest_email'] = $guest_email;
        }
        
        // Handler will determine user_id or session_id automatically
        $result = $handler->add_to_wishlist( $product_id, null, $session_id, $wishlist_id, $options );

        if ( is_wp_error( $result ) ) {
            return new WP_Error(
                $result->get_error_code(),
                $result->get_error_message(),
                array( 'status' => 400 )
            );
        }

        // Get wishlist information to return
        $user_id = is_user_logged_in() ? get_current_user_id() : null;
        $wishlist_info = null;
        
        if ($wishlist_id) {
            $wishlist_info = $handler->get_wishlist($wishlist_id);
        } else {
            // Get default wishlist
            $default_wishlist = $handler->get_default_wishlist($user_id, $session_id);
            if ($default_wishlist) {
                $wishlist_info = $default_wishlist;
            }
        }

        return rest_ensure_response( array(
            'success' => true,
            'message' => __( 'Product added to wishlist', 'wish-cart' ),
            'wishlist' => $wishlist_info,
        ) );
    }

    /**
     * Remove product from wishlist
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function wishlist_remove( $request ) {
        $handler = new WISHCART_Wishlist_Handler();
        $params = $request->get_json_params();
        $product_id = isset( $params['product_id'] ) ? intval( $params['product_id'] ) : 0;
        $session_id = isset( $params['session_id'] ) ? sanitize_text_field( wp_unslash( $params['session_id'] ) ) : null;
        $wishlist_id = isset( $params['wishlist_id'] ) ? intval( $params['wishlist_id'] ) : null;
        
        // Handler will determine user_id or session_id automatically
        $result = $handler->remove_from_wishlist( $product_id, null, $session_id, $wishlist_id );

        if ( is_wp_error( $result ) ) {
            return new WP_Error(
                $result->get_error_code(),
                $result->get_error_message(),
                array( 'status' => 400 )
            );
        }

        return rest_ensure_response( array(
            'success' => true,
            'message' => __( 'Product removed from wishlist', 'wish-cart' ),
        ) );
    }

    /**
     * Track when wishlist item is added to cart
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function wishlist_track_cart( $request ) {
        global $wpdb;
        $analytics_handler = new WISHCART_Analytics_Handler();
        $handler = new WISHCART_Wishlist_Handler();
        
        $params = $request->get_json_params();
        $product_id = isset( $params['product_id'] ) ? intval( $params['product_id'] ) : 0;
        $variation_id = isset( $params['variation_id'] ) ? intval( $params['variation_id'] ) : 0;
        
        if ( $product_id <= 0 ) {
            return new WP_Error(
                'invalid_product',
                __( 'Invalid product ID', 'wish-cart' ),
                array( 'status' => 400 )
            );
        }
        
        // Track analytics event
        $track_result = $analytics_handler->track_event( $product_id, $variation_id, 'cart' );
        
        if ( ! $track_result ) {
            return new WP_Error(
                'tracking_failed',
                __( 'Failed to track cart event', 'wish-cart' ),
                array( 'status' => 500 )
            );
        }
        
        // Update wishlist item's date_added_to_cart if item exists in wishlist
        $user_id = is_user_logged_in() ? get_current_user_id() : null;
        $session_id = null;
        
        if ( ! $user_id ) {
            // Try to get session_id from cookie or request
            $cookie_name = 'wishcart_session_id';
            if ( isset( $_COOKIE[ $cookie_name ] ) && ! empty( $_COOKIE[ $cookie_name ] ) ) {
                $session_id = sanitize_text_field( wp_unslash( $_COOKIE[ $cookie_name ] ) );
            } elseif ( isset( $params['session_id'] ) ) {
                $session_id = sanitize_text_field( wp_unslash( $params['session_id'] ) );
            }
        }
        
        // Only update wishlist items if we have user_id or session_id
        if ( $user_id || $session_id ) {
            // Find wishlist items for this product
            $items_table = $wpdb->prefix . 'fc_wishlist_items';
            $wishlists_table = $wpdb->prefix . 'fc_wishlists';
            
            $where_clauses = array();
            $where_values = array();
            
            $where_clauses[] = "wi.product_id = %d";
            $where_values[] = $product_id;
            
            if ( $variation_id > 0 ) {
                $where_clauses[] = "wi.variation_id = %d";
                $where_values[] = $variation_id;
            } else {
                $where_clauses[] = "(wi.variation_id = 0 OR wi.variation_id IS NULL)";
            }
            
            $where_clauses[] = "wi.status = 'active'";
            $where_clauses[] = "wi.date_added_to_cart IS NULL";
            
            if ( $user_id ) {
                $where_clauses[] = "w.user_id = %d";
                $where_values[] = $user_id;
            } elseif ( $session_id ) {
                $where_clauses[] = "w.session_id = %s";
                $where_values[] = $session_id;
            }
            
            $where_sql = implode( ' AND ', $where_clauses );
            
            $query = $wpdb->prepare(
                "UPDATE {$items_table} wi
                INNER JOIN {$wishlists_table} w ON wi.wishlist_id = w.id
                SET wi.date_added_to_cart = NOW()
                WHERE {$where_sql}",
                $where_values
            );
            
            $wpdb->query( $query );
        }
        
        return rest_ensure_response( array(
            'success' => true,
            'message' => __( 'Cart event tracked successfully', 'wish-cart' ),
        ) );
    }

    /**
     * Get user's wishlist
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function wishlist_get( $request ) {
        global $wpdb;
        $handler = new WISHCART_Wishlist_Handler();
        $session_id = $request->get_param( 'session_id' );
        $session_id = is_string( $session_id ) ? sanitize_text_field( wp_unslash( $session_id ) ) : null;
        
        // If session_id not provided in query, try to read from cookie
        if ( empty( $session_id ) && ! is_user_logged_in() ) {
            $cookie_name = 'wishcart_session_id';
            if ( isset( $_COOKIE[ $cookie_name ] ) && ! empty( $_COOKIE[ $cookie_name ] ) ) {
                $session_id = sanitize_text_field( wp_unslash( $_COOKIE[ $cookie_name ] ) );
            }
        }
        
        // Check for share_code first (highest priority)
        $share_code = $request->get_param( 'share_code' );
        $share_code = is_string( $share_code ) ? sanitize_text_field( $share_code ) : null;
        
        // Check for wishlist_id
        $wishlist_id = $request->get_param( 'wishlist_id' );
        $wishlist_id = ! empty( $wishlist_id ) ? intval( $wishlist_id ) : null;
        
        // Check if user_id is provided (for viewing other users' wishlists)
        $requested_user_id = $request->get_param( 'user_id' );
        $requested_user_id = ! empty( $requested_user_id ) ? intval( $requested_user_id ) : null;
        
        $wishlist_items = array();
        $current_wishlist = null;
        
        // If share_code is provided, get wishlist by share code
        if ( ! empty( $share_code ) ) {
            $current_wishlist = $handler->get_wishlist_by_share_code( $share_code );
            if ( $current_wishlist ) {
                $wishlist_id = $current_wishlist['id'];
            }
        }
        
        // If wishlist_id is provided, fetch that wishlist's items
        if ( $wishlist_id ) {
            $items_table = $wpdb->prefix . 'fc_wishlist_items';
            
            $results = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT product_id, date_added FROM {$items_table} WHERE wishlist_id = %d AND status = 'active' ORDER BY position ASC, date_added DESC",
                    $wishlist_id
                ),
                ARRAY_A
            );
            
            if ( $results ) {
                foreach ( $results as $row ) {
                    $wishlist_items[] = array(
                        'product_id' => intval( $row['product_id'] ),
                        'created_at' => $row['date_added'],
                    );
                }
            }
            
            // Get wishlist info if not already retrieved
            if ( ! $current_wishlist ) {
                $current_wishlist = $handler->get_wishlist( $wishlist_id );
            }
        } elseif ( $requested_user_id ) {
            // If user_id is provided, get that user's default wishlist and fetch its items
            $user_default_wishlist = $handler->get_default_wishlist( $requested_user_id, null );
            if ( $user_default_wishlist && isset( $user_default_wishlist['id'] ) ) {
                $items_table = $wpdb->prefix . 'fc_wishlist_items';
                
                $results = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT product_id, date_added FROM {$items_table} WHERE wishlist_id = %d AND status = 'active' ORDER BY position ASC, date_added DESC",
                        $user_default_wishlist['id']
                    ),
                    ARRAY_A
                );
                
                if ( $results ) {
                    foreach ( $results as $row ) {
                        $wishlist_items[] = array(
                            'product_id' => intval( $row['product_id'] ),
                            'created_at' => $row['date_added'],
                        );
                    }
                }
                
                // Set current wishlist for response
                if ( ! $current_wishlist ) {
                    $current_wishlist = $user_default_wishlist;
                }
            }
        } else {
            // Get default wishlist for current user/session
            $default_wishlist = $handler->get_default_wishlist( null, $session_id );
            if ( $default_wishlist ) {
                $wishlist_id = $default_wishlist['id'];
                $current_wishlist = $default_wishlist;
                
                $items_table = $wpdb->prefix . 'fc_wishlist_items';
                $results = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT product_id, date_added FROM {$items_table} WHERE wishlist_id = %d AND status = 'active' ORDER BY position ASC, date_added DESC",
                        $wishlist_id
                    ),
                    ARRAY_A
                );
                
                if ( $results ) {
                    foreach ( $results as $row ) {
                        $wishlist_items[] = array(
                            'product_id' => intval( $row['product_id'] ),
                            'created_at' => $row['date_added'],
                        );
                    }
                }
            } else {
                // Fallback to old method for backward compatibility
                $wishlist_items = $handler->get_user_wishlist_with_dates( null, $session_id );
            }
        }

        // Get product details
        $products = array();
        foreach ( $wishlist_items as $item ) {
            $product_id = $item['product_id'];
            $created_at = $item['created_at'];
            
            $product = WISHCART_FluentCart_Helper::get_product( $product_id );
            if ( $product ) {
                $image_id = $product->get_image_id();
                $image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'medium' ) : '';
                
                // Format date added
                $date_added = '';
                if ( $created_at ) {
                    $date_obj = new DateTime( $created_at );
                    $date_added = $date_obj->format( 'F j, Y' ); // Format: "November 16, 2025"
                }
                
                $products[] = array(
                    'id' => $product_id,
                    'name' => $product->get_name(),
                    'price' => $product->get_price(),
                    'regular_price' => $product->get_regular_price(),
                    'sale_price' => $product->get_sale_price(),
                    'is_on_sale' => $product->is_on_sale(),
                    'image_url' => $image_url,
                    'permalink' => get_permalink( $product_id ),
                    'stock_status' => $product->get_stock_status(),
                    'date_added' => $date_added,
                );
            }
        }

        $response_data = array(
            'success' => true,
            'products' => $products,
            'count' => count( $products ),
        );

        // Include wishlist info if available
        if ( $current_wishlist ) {
            $response_data['wishlist'] = $current_wishlist;
        }

        return rest_ensure_response( $response_data );
    }

    /**
     * Check if product is in wishlist
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function wishlist_check( $request ) {
        $handler = new WISHCART_Wishlist_Handler();
        $product_id = intval( $request->get_param( 'product_id' ) );
        $session_id = $request->get_param( 'session_id' );
        $session_id = is_string( $session_id ) ? sanitize_text_field( wp_unslash( $session_id ) ) : null;
        
        // Handler will determine user_id or session_id automatically
        $is_in_wishlist = $handler->is_in_wishlist( $product_id, null, $session_id );

        return rest_ensure_response( array(
            'success' => true,
            'in_wishlist' => $is_in_wishlist,
        ) );
    }

    /**
     * Sync guest wishlist to user account
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function wishlist_sync( $request ) {
        if ( ! is_user_logged_in() ) {
            return new WP_Error(
                'not_logged_in',
                __( 'User must be logged in', 'wish-cart' ),
                array( 'status' => 401 )
            );
        }

        $handler = new WISHCART_Wishlist_Handler();
        $params = $request->get_json_params();
        $session_id = isset( $params['session_id'] ) ? sanitize_text_field( $params['session_id'] ) : null;
        $user_id = get_current_user_id();

        if ( empty( $session_id ) ) {
            return new WP_Error(
                'missing_session_id',
                __( 'Session ID is required', 'wish-cart' ),
                array( 'status' => 400 )
            );
        }

        $result = $handler->sync_guest_wishlist_to_user( $session_id, $user_id );

        if ( is_wp_error( $result ) ) {
            return new WP_Error(
                $result->get_error_code(),
                $result->get_error_message(),
                array( 'status' => 400 )
            );
        }

        return rest_ensure_response( array(
            'success' => true,
            'message' => __( 'Wishlist synced successfully', 'wish-cart' ),
        ) );
    }

    /**
     * Get list of users with wishlist items
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function wishlist_get_users( $request ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wishcart_wishlist';
        
        // Get distinct user IDs that have wishlist items (excluding NULL and session-based entries)
        $user_ids = $wpdb->get_results(
            "SELECT DISTINCT user_id, COUNT(*) as wishlist_count 
             FROM {$table_name} 
             WHERE user_id IS NOT NULL 
             GROUP BY user_id 
             ORDER BY wishlist_count DESC",
            ARRAY_A
        );

        $users = array();
        foreach ( $user_ids as $row ) {
            $user_id = intval( $row['user_id'] );
            $user = get_userdata( $user_id );
            
            if ( $user ) {
                $users[] = array(
                    'id' => $user_id,
                    'name' => $user->display_name,
                    'wishlist_count' => intval( $row['wishlist_count'] ),
                );
            }
        }

        return rest_ensure_response( array(
            'success' => true,
            'users' => $users,
            'count' => count( $users ),
        ) );
    }

    /**
     * Get user's wishlists
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function wishlists_get( $request ) {
        $handler = new WISHCART_Wishlist_Handler();
        $session_id = $request->get_param( 'session_id' );
        $session_id = is_string( $session_id ) ? sanitize_text_field( wp_unslash( $session_id ) ) : null;
        
        // If session_id not provided in query, try to read from cookie
        if ( empty( $session_id ) && ! is_user_logged_in() ) {
            $cookie_name = 'wishcart_session_id';
            if ( isset( $_COOKIE[ $cookie_name ] ) && ! empty( $_COOKIE[ $cookie_name ] ) ) {
                $session_id = sanitize_text_field( wp_unslash( $_COOKIE[ $cookie_name ] ) );
            }
        }
        
        $wishlists = $handler->get_user_wishlists( null, $session_id );
        
        return rest_ensure_response( array(
            'success' => true,
            'wishlists' => $wishlists,
            'count' => count( $wishlists ),
        ) );
    }

    /**
     * Create new wishlist
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function wishlists_create( $request ) {
        $handler = new WISHCART_Wishlist_Handler();
        $params = $request->get_json_params();
        
        $name = isset( $params['name'] ) ? sanitize_text_field( $params['name'] ) : 'New Wishlist';
        $is_default = isset( $params['is_default'] ) ? (bool) $params['is_default'] : false;
        $session_id = isset( $params['session_id'] ) ? sanitize_text_field( $params['session_id'] ) : null;
        
        // If session_id not provided in request body, try to read from cookie
        if ( empty( $session_id ) && ! is_user_logged_in() ) {
            $cookie_name = 'wishcart_session_id';
            if ( isset( $_COOKIE[ $cookie_name ] ) && ! empty( $_COOKIE[ $cookie_name ] ) ) {
                $session_id = sanitize_text_field( wp_unslash( $_COOKIE[ $cookie_name ] ) );
            }
        }
        
        $result = $handler->create_wishlist( $name, null, $session_id, $is_default );
        
        if ( is_wp_error( $result ) ) {
            return new WP_Error(
                $result->get_error_code(),
                $result->get_error_message(),
                array( 'status' => 400 )
            );
        }
        
        return rest_ensure_response( array(
            'success' => true,
            'wishlist' => $result,
            'message' => __( 'Wishlist created successfully', 'wish-cart' ),
        ) );
    }

    /**
     * Update wishlist
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function wishlists_update( $request ) {
        $handler = new WISHCART_Wishlist_Handler();
        $wishlist_id = intval( $request->get_param( 'id' ) );
        $params = $request->get_json_params();
        
        $result = $handler->update_wishlist( $wishlist_id, $params );
        
        if ( is_wp_error( $result ) ) {
            return new WP_Error(
                $result->get_error_code(),
                $result->get_error_message(),
                array( 'status' => 400 )
            );
        }
        
        $wishlist = $handler->get_wishlist( $wishlist_id );
        
        return rest_ensure_response( array(
            'success' => true,
            'wishlist' => $wishlist,
            'message' => __( 'Wishlist updated successfully', 'wish-cart' ),
        ) );
    }

    /**
     * Delete wishlist
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function wishlists_delete( $request ) {
        $handler = new WISHCART_Wishlist_Handler();
        $wishlist_id = intval( $request->get_param( 'id' ) );
        
        $result = $handler->delete_wishlist( $wishlist_id );
        
        if ( is_wp_error( $result ) ) {
            return new WP_Error(
                $result->get_error_code(),
                $result->get_error_message(),
                array( 'status' => 400 )
            );
        }
        
        return rest_ensure_response( array(
            'success' => true,
            'message' => __( 'Wishlist deleted successfully', 'wish-cart' ),
        ) );
    }

    /**
     * Get wishlist by share code
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function wishlist_get_by_share_code( $request ) {
        global $wpdb;
        $handler = new WISHCART_Wishlist_Handler();
        $share_code = sanitize_text_field( $request->get_param( 'share_code' ) );
        
        $wishlist = $handler->get_wishlist_by_share_code( $share_code );
        
        if ( ! $wishlist ) {
            return new WP_Error(
                'not_found',
                __( 'Wishlist not found', 'wish-cart' ),
                array( 'status' => 404 )
            );
        }
        
        // Get wishlist items
        $wishlist_id = $wishlist['id'];
        $wishlist_items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT product_id, created_at FROM {$wpdb->prefix}wishcart_wishlist 
                WHERE wishlist_id = %d ORDER BY created_at DESC",
                $wishlist_id
            ),
            ARRAY_A
        );
        
        // Get product details
        $products = array();
        foreach ( $wishlist_items as $item ) {
            $product_id = $item['product_id'];
            $created_at = $item['created_at'];
            
            $product = WISHCART_FluentCart_Helper::get_product( $product_id );
            if ( $product ) {
                $image_id = $product->get_image_id();
                $image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'medium' ) : '';
                
                // Format date added
                $date_added = '';
                if ( $created_at ) {
                    $date_obj = new DateTime( $created_at );
                    $date_added = $date_obj->format( 'F j, Y' );
                }
                
                $products[] = array(
                    'id' => $product_id,
                    'name' => $product->get_name(),
                    'price' => $product->get_price(),
                    'regular_price' => $product->get_regular_price(),
                    'sale_price' => $product->get_sale_price(),
                    'is_on_sale' => $product->is_on_sale(),
                    'image_url' => $image_url,
                    'permalink' => get_permalink( $product_id ),
                    'stock_status' => $product->get_stock_status(),
                    'date_added' => $date_added,
                );
            }
        }
        
        return rest_ensure_response( array(
            'success' => true,
            'wishlist' => $wishlist,
            'products' => $products,
            'count' => count( $products ),
        ) );
    }

    /**
     * Get analytics overview
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function analytics_get_overview($request) {
        $analytics = new WISHCART_Analytics_Handler();
        $overview = $analytics->get_overview();
        
        return rest_ensure_response(array(
            'success' => true,
            'data' => $overview,
        ));
    }

    /**
     * Get popular products analytics
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function analytics_get_popular_products($request) {
        $analytics = new WISHCART_Analytics_Handler();
        $limit = $request->get_param('limit') ? intval($request->get_param('limit')) : 10;
        $order_by = $request->get_param('order_by') ? sanitize_text_field($request->get_param('order_by')) : 'wishlist_count';
        
        $products = $analytics->get_popular_products($limit, $order_by);
        
        return rest_ensure_response(array(
            'success' => true,
            'products' => $products,
            'count' => count($products),
        ));
    }

    /**
     * Get conversion funnel analytics
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function analytics_get_conversion($request) {
        $analytics = new WISHCART_Analytics_Handler();
        $funnel = $analytics->get_conversion_funnel();
        
        return rest_ensure_response(array(
            'success' => true,
            'data' => $funnel,
        ));
    }

    /**
     * Get link details with items and click counts
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function analytics_get_links($request) {
        $analytics = new WISHCART_Analytics_Handler();
        $link_details = $analytics->get_link_details();
        
        return rest_ensure_response(array(
            'success' => true,
            'total_links' => $link_details['total_links'],
            'links' => $link_details['links'],
        ));
    }

    /**
     * Get product analytics
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function analytics_get_product($request) {
        $analytics = new WISHCART_Analytics_Handler();
        $product_id = intval($request->get_param('product_id'));
        $variation_id = $request->get_param('variation_id') ? intval($request->get_param('variation_id')) : 0;
        
        $data = $analytics->get_product_analytics($product_id, $variation_id);
        
        if (!$data) {
            return new WP_Error('not_found', __('Analytics data not found', 'wish-cart'), array('status' => 404));
        }
        
        return rest_ensure_response(array(
            'success' => true,
            'data' => $data,
        ));
    }

    /**
     * Create share
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function share_create($request) {
        $sharing = new WISHCART_Sharing_Handler();
        $params = $request->get_json_params();
        
        $wishlist_id = isset($params['wishlist_id']) ? intval($params['wishlist_id']) : 0;
        $share_type = isset($params['share_type']) ? sanitize_text_field($params['share_type']) : 'link';
        
        $options = array();
        if (isset($params['shared_with_email'])) {
            $options['shared_with_email'] = sanitize_email($params['shared_with_email']);
        }
        if (isset($params['share_message'])) {
            $options['share_message'] = sanitize_textarea_field($params['share_message']);
        }
        if (isset($params['expiration_days'])) {
            $options['expiration_days'] = intval($params['expiration_days']);
        }
        
        $result = $sharing->create_share($wishlist_id, $share_type, $options);
        
        if (is_wp_error($result)) {
            return new WP_Error(
                $result->get_error_code(),
                $result->get_error_message(),
                array('status' => 400)
            );
        }
        
        return rest_ensure_response(array(
            'success' => true,
            'share' => $result,
            'share_url' => $sharing->get_share_url($result['share_token'], $share_type),
        ));
    }

    /**
     * Get share statistics
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function share_get_stats($request) {
        $sharing = new WISHCART_Sharing_Handler();
        $share_token = $request->get_param('share_token');
        
        $share = $sharing->get_share_by_token($share_token);
        if (!$share) {
            return new WP_Error('not_found', __('Share not found', 'wish-cart'), array('status' => 404));
        }
        
        $stats = $sharing->get_share_statistics($share['wishlist_id']);
        
        return rest_ensure_response(array(
            'success' => true,
            'share' => $share,
            'stats' => $stats,
        ));
    }

    /**
     * Track share click
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function share_track_click($request) {
        $sharing = new WISHCART_Sharing_Handler();
        $share_token = $request->get_param('share_token');
        
        $share = $sharing->get_share_by_token($share_token);
        if (!$share) {
            return new WP_Error('not_found', __('Share not found', 'wish-cart'), array('status' => 404));
        }
        
        $sharing->track_share_click($share['share_id']);
        
        return rest_ensure_response(array(
            'success' => true,
            'message' => __('Click tracked', 'wish-cart'),
        ));
    }

    /**
     * View shared wishlist publicly
     * 
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function share_view_wishlist($request) {
        global $wpdb;
        
        $share_token = $request->get_param('share_token');
        $sharing = new WISHCART_Sharing_Handler();
        
        // Get share by token
        $share = $sharing->get_share_by_token($share_token);
        if (!$share) {
            return new WP_Error('not_found', __('Shared wishlist not found or has expired', 'wish-cart'), array('status' => 404));
        }
        
        // Get wishlist details
        $wishlists_table = $wpdb->prefix . 'fc_wishlists';
        $wishlist = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wishlists_table} WHERE id = %d AND status = 'active'",
                $share['wishlist_id']
            ),
            ARRAY_A
        );
        
        if (!$wishlist) {
            return new WP_Error('not_found', __('Wishlist not found', 'wish-cart'), array('status' => 404));
        }
        
        // Check privacy status - only public and shared wishlists can be viewed via share link
        if ($wishlist['privacy_status'] === 'private') {
            return new WP_Error('forbidden', __('This wishlist is private', 'wish-cart'), array('status' => 403));
        }
        
        // Get wishlist items with product details
        $items_table = $wpdb->prefix . 'fc_wishlist_items';
        $items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$items_table} WHERE wishlist_id = %d AND status = 'active' ORDER BY position ASC, date_added DESC",
                $share['wishlist_id']
            ),
            ARRAY_A
        );
        
        // Enrich items with product data
        $products = array();
        foreach ($items as $item) {
            $product_id = $item['product_id'];
            $product = WISHCART_FluentCart_Helper::get_product($product_id);
            
            if (!$product) {
                continue;
            }
            
            $product_data = array(
                'id' => $product_id,
                'name' => $product->get_name(),
                'permalink' => get_permalink($product_id),
                'price' => $product->get_price(),
                'regular_price' => $product->get_regular_price(),
                'sale_price' => $product->get_sale_price(),
                'is_on_sale' => $product->is_on_sale(),
                'stock_status' => $product->get_stock_status(),
                'image_url' => wp_get_attachment_url($product->get_image_id()),
                'quantity' => $item['quantity'],
                'notes' => $item['notes'],
                'date_added' => $item['date_added'],
                'variation_id' => $item['variation_id'],
            );
            
            // If it's a variation, get variation details
            if ($item['variation_id'] && $item['variation_id'] > 0) {
                $variation = WISHCART_FluentCart_Helper::get_product($item['variation_id']);
                if ($variation) {
                    // For variations, update prices
                    $product_data['price'] = $variation->get_price();
                    $product_data['regular_price'] = $variation->get_regular_price();
                    $product_data['sale_price'] = $variation->get_sale_price();
                }
            }
            
            $products[] = $product_data;
        }
        
        // Track click
        $sharing->track_share_click($share['share_id']);
        
        // Log activity
        if (class_exists('WISHCART_Activity_Logger')) {
            $logger = new WISHCART_Activity_Logger();
            $session_id = isset($_COOKIE['wishcart_session']) ? sanitize_text_field($_COOKIE['wishcart_session']) : null;
            $ip_address = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : null;
            $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : null;
            $referrer = isset($_SERVER['HTTP_REFERER']) ? esc_url_raw($_SERVER['HTTP_REFERER']) : null;
            
            $logger->log(
                $share['wishlist_id'],
                null,
                $session_id,
                'viewed',
                $share['share_id'],
                'share',
                array(
                    'share_token' => $share_token,
                    'share_type' => $share['share_type'],
                    'ip_address' => $ip_address,
                    'user_agent' => $user_agent,
                    'referrer_url' => $referrer
                )
            );
        }
        
        // Get owner info (optional based on settings)
        $owner_name = null;
        if ($wishlist['user_id']) {
            $user = get_userdata($wishlist['user_id']);
            if ($user) {
                $owner_name = $user->display_name;
            }
        }
        
        return rest_ensure_response(array(
            'success' => true,
            'wishlist' => array(
                'id' => $wishlist['id'],
                'name' => $wishlist['wishlist_name'],
                'description' => $wishlist['description'],
                'privacy_status' => $wishlist['privacy_status'],
                'owner_name' => $owner_name,
                'date_created' => $wishlist['dateadded'],
            ),
            'products' => $products,
            'share_info' => array(
                'share_type' => $share['share_type'],
                'share_message' => $share['share_message'],
                'click_count' => $share['click_count'],
            ),
        ));
    }

    /**
     * Subscribe to notifications
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function notifications_subscribe($request) {
        $notifications = new WISHCART_Notifications_Handler();
        $params = $request->get_json_params();
        
        $notification_type = isset($params['notification_type']) ? sanitize_text_field($params['notification_type']) : '';
        $email_to = isset($params['email']) ? sanitize_email($params['email']) : '';
        
        $data = array(
            'product_id' => isset($params['product_id']) ? intval($params['product_id']) : null,
            'wishlist_id' => isset($params['wishlist_id']) ? intval($params['wishlist_id']) : null,
            'user_id' => is_user_logged_in() ? get_current_user_id() : null,
        );
        
        $result = $notifications->queue_notification($notification_type, $email_to, $data);
        
        if (is_wp_error($result)) {
            return new WP_Error(
                $result->get_error_code(),
                $result->get_error_message(),
                array('status' => 400)
            );
        }
        
        return rest_ensure_response(array(
            'success' => true,
            'message' => __('Subscription created', 'wish-cart'),
            'notification_id' => $result,
        ));
    }

    /**
     * Get user notifications
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function notifications_get($request) {
        if (!is_user_logged_in()) {
            return new WP_Error('not_logged_in', __('User must be logged in', 'wish-cart'), array('status' => 401));
        }
        
        $notifications = new WISHCART_Notifications_Handler();
        $user_id = get_current_user_id();
        $status = $request->get_param('status');
        
        $user_notifications = $notifications->get_user_notifications($user_id, $status);
        
        return rest_ensure_response(array(
            'success' => true,
            'notifications' => $user_notifications,
            'count' => count($user_notifications),
        ));
    }

    /**
     * Get notification statistics
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function notifications_get_stats($request) {
        $notifications = new WISHCART_Notifications_Handler();
        $stats = $notifications->get_statistics();
        
        return rest_ensure_response(array(
            'success' => true,
            'stats' => $stats,
        ));
    }

    /**
     * Get wishlist activity
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function activity_get_wishlist($request) {
        $logger = new WISHCART_Activity_Logger();
        $wishlist_id = intval($request->get_param('wishlist_id'));
        $limit = $request->get_param('limit') ? intval($request->get_param('limit')) : 50;
        $offset = $request->get_param('offset') ? intval($request->get_param('offset')) : 0;
        
        $activities = $logger->get_wishlist_activities($wishlist_id, $limit, $offset);
        
        return rest_ensure_response(array(
            'success' => true,
            'activities' => $activities,
            'count' => count($activities),
        ));
    }

    /**
     * Get recent activities
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function activity_get_recent($request) {
        $logger = new WISHCART_Activity_Logger();
        $limit = $request->get_param('limit') ? intval($request->get_param('limit')) : 20;
        $activity_type = $request->get_param('type');
        
        $activities = $logger->get_recent_activities($limit, $activity_type);
        
        return rest_ensure_response(array(
            'success' => true,
            'activities' => $activities,
            'count' => count($activities),
        ));
    }

    /**
     * Get FluentCRM settings
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function fluentcrm_get_settings($request) {
        if (!class_exists('WISHCART_FluentCRM_Integration')) {
            return new WP_Error('not_available', __('FluentCRM integration not available', 'wish-cart'), array('status' => 404));
        }

        // Clear cache to force fresh detection
        WISHCART_FluentCRM_Integration::clear_detection_cache();
        
        $fluentcrm = new WISHCART_FluentCRM_Integration();
        $settings = $fluentcrm->get_settings();
        $is_available = $fluentcrm->is_available();

        return rest_ensure_response(array(
            'success' => true,
            'settings' => $settings,
            'is_available' => $is_available,
        ));
    }

    /**
     * Update FluentCRM settings
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function fluentcrm_update_settings($request) {
        if (!class_exists('WISHCART_FluentCRM_Integration')) {
            return new WP_Error('not_available', __('FluentCRM integration not available', 'wish-cart'), array('status' => 404));
        }

        $fluentcrm = new WISHCART_FluentCRM_Integration();
        $params = $request->get_json_params();
        
        $result = $fluentcrm->update_settings($params);

        if (!$result) {
            return new WP_Error('update_failed', __('Failed to update settings', 'wish-cart'), array('status' => 500));
        }

        return rest_ensure_response(array(
            'success' => true,
            'message' => __('Settings updated successfully', 'wish-cart'),
        ));
    }

    /**
     * Get FluentCRM tags
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function fluentcrm_get_tags($request) {
        if (!class_exists('WISHCART_FluentCRM_Integration')) {
            return new WP_Error('not_available', __('FluentCRM integration not available', 'wish-cart'), array('status' => 404));
        }

        $fluentcrm = new WISHCART_FluentCRM_Integration();
        $tags = $fluentcrm->get_tags();

        return rest_ensure_response(array(
            'success' => true,
            'tags' => $tags,
        ));
    }

    /**
     * Get FluentCRM lists
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function fluentcrm_get_lists($request) {
        if (!class_exists('WISHCART_FluentCRM_Integration')) {
            return new WP_Error('not_available', __('FluentCRM integration not available', 'wish-cart'), array('status' => 404));
        }

        $fluentcrm = new WISHCART_FluentCRM_Integration();
        $lists = $fluentcrm->get_lists();

        return rest_ensure_response(array(
            'success' => true,
            'lists' => $lists,
        ));
    }

    /**
     * Get campaigns
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function campaigns_get($request) {
        if (!class_exists('WISHCART_CRM_Campaign_Handler')) {
            return new WP_Error('not_available', __('Campaign handler not available', 'wish-cart'), array('status' => 404));
        }

        $campaign_handler = new WISHCART_CRM_Campaign_Handler();
        $trigger_type = $request->get_param('trigger_type');
        $status = $request->get_param('status');

        if ($trigger_type) {
            $campaigns = $campaign_handler->get_campaigns_by_trigger($trigger_type, $status);
        } else {
            global $wpdb;
            $table = $wpdb->prefix . 'fc_wishlist_crm_campaigns';
            $where = '1=1';
            $params = array();

            if ($status) {
                $where .= ' AND status = %s';
                $params[] = $status;
            }

            $query = "SELECT * FROM {$table} WHERE {$where} ORDER BY date_created DESC";
            $campaigns = $wpdb->get_results(
                $wpdb->prepare($query, $params),
                ARRAY_A
            );

            foreach ($campaigns as &$campaign) {
                $campaign['trigger_conditions'] = json_decode($campaign['trigger_conditions'], true);
                $campaign['email_sequence'] = json_decode($campaign['email_sequence'], true);
                $campaign['target_segment'] = json_decode($campaign['target_segment'], true);
                $campaign['stats'] = json_decode($campaign['stats'], true);
            }
        }

        return rest_ensure_response(array(
            'success' => true,
            'campaigns' => $campaigns,
            'count' => count($campaigns),
        ));
    }

    /**
     * Create campaign
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function campaigns_create($request) {
        if (!class_exists('WISHCART_CRM_Campaign_Handler')) {
            return new WP_Error('not_available', __('Campaign handler not available', 'wish-cart'), array('status' => 404));
        }

        $campaign_handler = new WISHCART_CRM_Campaign_Handler();
        $params = $request->get_json_params();

        $result = $campaign_handler->create_campaign($params);

        if (is_wp_error($result)) {
            return new WP_Error(
                $result->get_error_code(),
                $result->get_error_message(),
                array('status' => 400)
            );
        }

        return rest_ensure_response(array(
            'success' => true,
            'campaign_id' => $result,
            'message' => __('Campaign created successfully', 'wish-cart'),
        ));
    }

    /**
     * Get single campaign
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function campaigns_get_single($request) {
        if (!class_exists('WISHCART_CRM_Campaign_Handler')) {
            return new WP_Error('not_available', __('Campaign handler not available', 'wish-cart'), array('status' => 404));
        }

        $campaign_handler = new WISHCART_CRM_Campaign_Handler();
        $campaign_id = intval($request->get_param('id'));

        $campaign = $campaign_handler->get_campaign($campaign_id);

        if (!$campaign) {
            return new WP_Error('not_found', __('Campaign not found', 'wish-cart'), array('status' => 404));
        }

        return rest_ensure_response(array(
            'success' => true,
            'campaign' => $campaign,
        ));
    }

    /**
     * Update campaign
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function campaigns_update($request) {
        if (!class_exists('WISHCART_CRM_Campaign_Handler')) {
            return new WP_Error('not_available', __('Campaign handler not available', 'wish-cart'), array('status' => 404));
        }

        $campaign_handler = new WISHCART_CRM_Campaign_Handler();
        $campaign_id = intval($request->get_param('id'));
        $params = $request->get_json_params();

        $result = $campaign_handler->update_campaign($campaign_id, $params);

        if (is_wp_error($result)) {
            return new WP_Error(
                $result->get_error_code(),
                $result->get_error_message(),
                array('status' => 400)
            );
        }

        return rest_ensure_response(array(
            'success' => true,
            'message' => __('Campaign updated successfully', 'wish-cart'),
        ));
    }

    /**
     * Delete campaign
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function campaigns_delete($request) {
        if (!class_exists('WISHCART_CRM_Campaign_Handler')) {
            return new WP_Error('not_available', __('Campaign handler not available', 'wish-cart'), array('status' => 404));
        }

        $campaign_id = intval($request->get_param('id'));
        global $wpdb;
        $table = $wpdb->prefix . 'fc_wishlist_crm_campaigns';

        $result = $wpdb->delete($table, array('campaign_id' => $campaign_id), array('%d'));

        if (false === $result) {
            return new WP_Error('delete_failed', __('Failed to delete campaign', 'wish-cart'), array('status' => 500));
        }

        return rest_ensure_response(array(
            'success' => true,
            'message' => __('Campaign deleted successfully', 'wish-cart'),
        ));
    }

    /**
     * Get campaign analytics
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function campaigns_get_analytics($request) {
        if (!class_exists('WISHCART_CRM_Campaign_Handler')) {
            return new WP_Error('not_available', __('Campaign handler not available', 'wish-cart'), array('status' => 404));
        }

        $campaign_handler = new WISHCART_CRM_Campaign_Handler();
        $campaign_id = intval($request->get_param('id'));

        $campaign = $campaign_handler->get_campaign($campaign_id);

        if (!$campaign) {
            return new WP_Error('not_found', __('Campaign not found', 'wish-cart'), array('status' => 404));
        }

        $stats = $campaign['stats'] ? $campaign['stats'] : array();

        return rest_ensure_response(array(
            'success' => true,
            'analytics' => $stats,
            'campaign' => array(
                'id' => $campaign['campaign_id'],
                'trigger_type' => $campaign['wishlist_trigger_type'],
                'status' => $campaign['status'],
            ),
        ));
    }

    /**
     * Check if guest has email
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function guest_check_email( $request ) {
        $session_id = $request->get_param( 'session_id' );
        $session_id = is_string( $session_id ) ? sanitize_text_field( wp_unslash( $session_id ) ) : null;

        if ( empty( $session_id ) ) {
            return rest_ensure_response( array(
                'has_email' => false,
                'email' => null,
            ) );
        }

        $guest_handler = new WISHCART_Guest_Handler();
        $guest = $guest_handler->get_guest_by_session( $session_id );

        if ( $guest && ! empty( $guest['guest_email'] ) ) {
            return rest_ensure_response( array(
                'has_email' => true,
                'email' => $guest['guest_email'],
            ) );
        }

        return rest_ensure_response( array(
            'has_email' => false,
            'email' => null,
        ) );
    }

    /**
     * Update guest email
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function guest_update_email( $request ) {
        $params = $request->get_json_params();
        $email = isset( $params['email'] ) ? sanitize_email( wp_unslash( $params['email'] ) ) : null;
        $session_id = isset( $params['session_id'] ) ? sanitize_text_field( wp_unslash( $params['session_id'] ) ) : null;

        if ( empty( $email ) || ! is_email( $email ) ) {
            return new WP_Error(
                'invalid_email',
                __( 'Invalid email address', 'wish-cart' ),
                array( 'status' => 400 )
            );
        }

        if ( empty( $session_id ) ) {
            return new WP_Error(
                'invalid_session',
                __( 'Session ID is required', 'wish-cart' ),
                array( 'status' => 400 )
            );
        }

        $guest_handler = new WISHCART_Guest_Handler();
        $result = $guest_handler->create_or_update_guest( $session_id, array(
            'guest_email' => $email,
        ) );

        if ( is_wp_error( $result ) ) {
            return new WP_Error(
                $result->get_error_code(),
                $result->get_error_message(),
                array( 'status' => 400 )
            );
        }

        // Sync to FluentCRM if available
        if ( class_exists( 'WISHCART_FluentCRM_Integration' ) ) {
            $fluentcrm = new WISHCART_FluentCRM_Integration();
            if ( $fluentcrm->is_available() ) {
                $settings = $fluentcrm->get_settings();
                if ( $settings['enabled'] ) {
                    // Create or update contact in FluentCRM
                    $contact_id = $fluentcrm->create_or_update_contact( null, $email, array() );
                    if ( ! is_wp_error( $contact_id ) ) {
                        // Ensure contact is added to default list
                        $default_list_id = $fluentcrm->get_or_create_default_list();
                        if ( ! is_wp_error( $default_list_id ) ) {
                            $fluentcrm->attach_lists( $contact_id, array( $default_list_id ) );
                        }
                    }
                }
            }
        }

        return rest_ensure_response( array(
            'success' => true,
            'message' => __( 'Email saved successfully', 'wish-cart' ),
            'email' => $email,
        ) );
    }
}

WISHCART_Admin::get_instance();
