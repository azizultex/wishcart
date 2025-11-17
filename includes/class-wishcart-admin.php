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
        
        // Handler will determine user_id or session_id automatically
        $result = $handler->add_to_wishlist( $product_id, null, $session_id );

        if ( is_wp_error( $result ) ) {
            return new WP_Error(
                $result->get_error_code(),
                $result->get_error_message(),
                array( 'status' => 400 )
            );
        }

        return rest_ensure_response( array(
            'success' => true,
            'message' => __( 'Product added to wishlist', 'wish-cart' ),
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
        
        // Handler will determine user_id or session_id automatically
        $result = $handler->remove_from_wishlist( $product_id, null, $session_id );

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
     * Get user's wishlist
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function wishlist_get( $request ) {
        $handler = new WISHCART_Wishlist_Handler();
        $session_id = $request->get_param( 'session_id' );
        $session_id = is_string( $session_id ) ? sanitize_text_field( wp_unslash( $session_id ) ) : null;
        
        // Handler will determine user_id or session_id automatically
        $product_ids = $handler->get_user_wishlist( null, $session_id );

        // Get product details
        $products = array();
        foreach ( $product_ids as $product_id ) {
            $product = WISHCART_FluentCart_Helper::get_product( $product_id );
            if ( $product ) {
                $image_id = $product->get_image_id();
                $image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'medium' ) : '';
                
                $products[] = array(
                    'id' => $product_id,
                    'name' => $product->get_name(),
                    'price' => $product->get_price(),
                    'regular_price' => $product->get_regular_price(),
                    'sale_price' => $product->get_sale_price(),
                    'is_on_sale' => $product->is_on_sale(),
                    'image_url' => $image_url,
                    'permalink' => get_permalink( $product_id ),
                );
            }
        }

        return rest_ensure_response( array(
            'success' => true,
            'products' => $products,
            'count' => count( $products ),
        ) );
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
}

WISHCART_Admin::get_instance();
