<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Wishlist Frontend Handler Class
 *
 * Handles frontend wishlist button rendering and hooks
 *
 * @category WordPress
 * @package  WishCart
 * @author   WishCart Team <support@wishcart.chat>
 * @license  GPL-2.0+ https://www.gnu.org/licenses/gpl-2.0.html
 * @link     https://wishcart.chat
 */
class WISHCART_Wishlist_Frontend {

    private $handler;

    /**
     * Constructor
     */
    public function __construct() {
        $this->handler = new WISHCART_Wishlist_Handler();
        
        // Enqueue scripts and styles
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
        
        // Output custom CSS
        add_action( 'wp_head', array( $this, 'output_custom_css' ) );
        
        // Hook into FluentCart product display
        $this->add_product_hooks();
        
        // Sync guest wishlist on login
        add_action( 'wp_login', array( $this, 'sync_on_login' ), 10, 2 );
    }

    /**
     * Enqueue frontend scripts and styles
     *
     * @return void
     */
    public function enqueue_scripts() {
        // Enqueue on product pages, shop pages, or wishlist page
        $wishlist_page_id = WISHCART_Wishlist_Page::get_wishlist_page_id();
        $is_wishlist_page = $wishlist_page_id > 0 && is_page( $wishlist_page_id );
        
        if ( ! $this->is_product_page() && ! $is_wishlist_page ) {
            return;
        }

        // Enqueue wishlist frontend script
        wp_enqueue_script(
            'wishcart-wishlist-frontend',
            WISHCART_PLUGIN_URL . 'build/wishlist-frontend.js',
            array( 'wp-element', 'wp-api-fetch' ),
            WISHCART_VERSION,
            true
        );

        wp_enqueue_style(
            'wishcart-wishlist-frontend',
            WISHCART_PLUGIN_URL . 'build/wishlist-frontend.css',
            array(),
            WISHCART_VERSION
        );

        // Localize script
        $session_id = $this->handler->get_or_create_session_id();
        wp_localize_script(
            'wishcart-wishlist-frontend',
            'WishCartWishlist',
            array(
                'apiUrl' => rest_url( 'wishcart/v1' ),
                'nonce' => wp_create_nonce( 'wp_rest' ),
                'sessionId' => $session_id,
                'isLoggedIn' => is_user_logged_in(),
                'userId' => get_current_user_id(),
            )
        );
    }

    /**
     * Add product hooks for wishlist button
     *
     * @return void
     */
    private function add_product_hooks() {
        $settings = get_option( 'wishcart_settings', array() );
        $wishlist_settings = isset( $settings['wishlist'] ) ? $settings['wishlist'] : array();
        
        if ( empty( $wishlist_settings['enabled'] ) ) {
            return;
        }

        // Hook for shop page (archive)
        if ( ! empty( $wishlist_settings['shop_page_button'] ) ) {
            // FluentCart shop hooks - try common hooks
            add_action( 'fluentcart_after_product_loop_item', array( $this, 'render_wishlist_button' ), 10 );
            add_action( 'woocommerce_after_shop_loop_item', array( $this, 'render_wishlist_button' ), 10 );
            add_action( 'fc_product_loop_item_end', array( $this, 'render_wishlist_button' ), 10 );
        }

        // Hook for product detail page
        if ( ! empty( $wishlist_settings['product_page_button'] ) ) {
            $position = isset( $wishlist_settings['button_position'] ) ? $wishlist_settings['button_position'] : 'after';
            
            if ( $position === 'before' ) {
                add_action( 'fluentcart_before_add_to_cart_button', array( $this, 'render_wishlist_button' ), 10 );
                add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'render_wishlist_button' ), 10 );
            } else {
                add_action( 'fluentcart_after_add_to_cart_button', array( $this, 'render_wishlist_button' ), 10 );
                add_action( 'woocommerce_after_add_to_cart_button', array( $this, 'render_wishlist_button' ), 10 );
            }
        }
    }

    /**
     * Render wishlist button
     *
     * @param int|null $product_id Product ID (optional, will try to get from global)
     * @return void
     */
    public function render_wishlist_button( $product_id = null ) {
        if ( empty( $product_id ) ) {
            global $product, $post;
            
            if ( is_object( $product ) && method_exists( $product, 'get_id' ) ) {
                $product_id = $product->get_id();
            } elseif ( $post ) {
                $product_id = $post->ID;
            }
        }

        if ( empty( $product_id ) ) {
            return;
        }

        // Check if it's a FluentCart product
        $product_type = WISHCART_FluentCart_Helper::get_product_post_type();
        $post_type = get_post_type( $product_id );
        
        if ( $post_type !== $product_type && $post_type !== 'product' ) {
            return;
        }

        // Render button container (React will mount here)
        echo '<div class="wishcart-wishlist-button-container" data-product-id="' . esc_attr( $product_id ) . '"></div>';
    }

    /**
     * Sync guest wishlist on user login
     *
     * @param string $user_login User login name
     * @param WP_User $user User object
     * @return void
     */
    public function sync_on_login( $user_login, $user ) {
        // Get session ID from cookie
        $cookie_name = 'wishcart_session_id';
        if ( ! isset( $_COOKIE[ $cookie_name ] ) || empty( $_COOKIE[ $cookie_name ] ) ) {
            return;
        }

        $session_id = sanitize_text_field( wp_unslash( $_COOKIE[ $cookie_name ] ) );
        
        // Sync wishlist
        $this->handler->sync_guest_wishlist_to_user( $session_id, $user->ID );
    }

    /**
     * Output custom CSS from settings
     *
     * @return void
     */
    public function output_custom_css() {
        $settings = get_option( 'wishcart_settings', array() );
        $custom_css = isset( $settings['wishlist']['custom_css'] ) ? $settings['wishlist']['custom_css'] : '';
        
        if ( ! empty( $custom_css ) ) {
            echo '<style id="wishcart-wishlist-custom-css">' . wp_strip_all_tags( $custom_css ) . '</style>' . "\n";
        }
    }

    /**
     * Check if current page is a product page
     *
     * @return bool
     */
    private function is_product_page() {
        $product_type = WISHCART_FluentCart_Helper::get_product_post_type();
        
        return is_singular( $product_type ) || 
               is_singular( 'product' ) || 
               is_post_type_archive( $product_type ) ||
               is_post_type_archive( 'product' ) ||
               ( function_exists( 'is_shop' ) && is_shop() );
    }
}

