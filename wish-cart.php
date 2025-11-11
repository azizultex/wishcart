<?php

/**
 * Plugin Name:  WishCart - Wishlist for FluentCart
 * Plugin URI:  https://wishcart.chat
 * Description: Wishlist plugin for FluentCart. Add products to wishlist, manage your favorites, and share your wishlist with others.
 * Version:     1.0.0
 * Requires PHP: 7.4
 * Author:      WishCart Team <support@wishcart.chat>
 * Author URI:  https://wishcart.chat/
 * Contributors: wishcart, zrshishir, sabbirxprt
 * Text Domain:  wish-cart
 * Domain Path: /languages/
 * License: GPL2
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 *
 * @category WordPress
 * @package  AISK
 * @author   WishCart Team <support@wishcart.chat>
 * @license  GPL-2.0+ http://www.gnu.org/licenses/gpl-2.0.txt
 * @link     https://wishcart.chat
 *
 * Third-party Libraries:
 * - Smalot/PdfParser: Required for PDF text extraction and processing
 
 */


if (defined('WP_DEBUG') && WP_DEBUG) {
    ob_start();
    register_activation_hook(__FILE__, function() {
        $output = ob_get_contents();
        if (!empty($output)) {
            // Silent handling of activation output in production
            return;
        }
    });
}

if ( ! defined('ABSPATH') ) {
	exit;
}

// Load Composer autoloader if available
if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    // Log error but don't stop plugin execution
    if ( defined('WP_DEBUG') && WP_DEBUG ) {
        // error_log('WishCart AI Chat: Composer autoloader not found. Some features may not work properly.');
    }
}

/**
 * Main plugin class for WishCart Wishlist
 *
 * Handles initialization, dependencies loading, and core functionality
 * of the WishCart wishlist plugin for WordPress and FluentCart.
 *
 * @category WordPress
 * @package  WishCart
 * @author   WishCart Team <support@wishcart.chat>
 * @license  GPL-2.0+ http://www.gnu.org/licenses/gpl-2.0.txt
 * @link     https://wishcart.chat
 */
class WISHCART_Wishlist {

    private static $instance = null;

    /**
     * Get singleton instance of this class
     *
     * @return WISHCART_Wishlist Instance of this class
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor for initializing the plugin
     */
    private function __construct() {
        // Define constants
        define('WISHCART_PLUGIN_FILE', __FILE__);
        define('WISHCART_VERSION', '1.0.0');
        define('WISHCART_PLUGIN_DIR', plugin_dir_path(__FILE__));
        define('WISHCART_PLUGIN_URL', plugin_dir_url(__FILE__));
        define('WISHCART_TEXT_DOMAIN', 'wish-cart');


        // Initialize components
        add_action('init', [ $this, 'init' ]);
        register_activation_hook(__FILE__, [ $this, 'activate' ]);

        // Load required files
        $this->load_dependencies();
    }
    
    /**
     * Initialize plugin hooks and features
     *
     * @return void
     */
    public function init() {
        // Clear FluentCart detection cache when plugins are activated/deactivated
        add_action('activated_plugin', [ $this, 'clear_fluentcart_cache' ]);
        add_action('deactivated_plugin', [ $this, 'clear_fluentcart_cache' ]);
    }

    /**
     * Clear FluentCart detection cache when plugins change
     *
     * @param string $plugin Plugin path
     * @return void
     */
    public function clear_fluentcart_cache( $plugin ) {
        // Clear cache if FluentCart or this plugin was activated/deactivated
        if ( strpos( $plugin, 'fluentcart' ) !== false || 
             strpos( $plugin, 'fluent-cart' ) !== false ||
             strpos( $plugin, 'wish-cart' ) !== false ) {
            if ( class_exists( 'WISHCART_FluentCart_Helper' ) ) {
                WISHCART_FluentCart_Helper::clear_detection_cache();
            }
        }
    }

    /**
     * Load required plugin files and dependencies
     *
     * @return void
     */
    private function load_dependencies() {
        include_once WISHCART_PLUGIN_DIR . 'includes/class-database.php';
        include_once WISHCART_PLUGIN_DIR . 'includes/class-fluentcart-helper.php';
        include_once WISHCART_PLUGIN_DIR . 'includes/class-wishlist-handler.php';
        include_once WISHCART_PLUGIN_DIR . 'includes/class-wishlist-frontend.php';
        include_once WISHCART_PLUGIN_DIR . 'includes/class-wishlist-page.php';
        include_once WISHCART_PLUGIN_DIR . 'includes/shortcodes/class-wishlist-shortcode.php';
        include_once WISHCART_PLUGIN_DIR . 'includes/class-wishcart-admin.php';

        // Initialize admin/API class so REST routes register for all requests
        WISHCART_Admin::get_instance();

        // Initialize frontend handler
        new WISHCART_Wishlist_Frontend();

        // Ensure database tables exist even after updates (without reactivation)
        // Safe to call: dbDelta is idempotent
        try { new WISHCART_Database(); } catch ( \Throwable $e ) {}
    }

    /**
     * Handle plugin activation
     *
     * @return void
     */
    public function activate() {
        // Ensure database tables exist
        new WISHCART_Database();
        
        // Create wishlist page
        WISHCART_Wishlist_Page::create_wishlist_page();
    }

}

// Initialize the plugin
WISHCART_Wishlist::get_instance();