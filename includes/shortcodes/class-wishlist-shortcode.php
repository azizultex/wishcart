<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Wishlist Shortcode Handler
 *
 * @category WordPress
 * @package  WishCart
 * @author   WishCart Team <support@wishcart.chat>
 * @license  GPL-2.0+ https://www.gnu.org/licenses/gpl-2.0.html
 * @link     https://wishcart.chat
 */
class WISHCART_Wishlist_Shortcode {

    /**
     * Constructor
     */
    public function __construct() {
        add_shortcode( 'wishcart_wishlist', array( $this, 'render_wishlist' ) );
    }

    /**
     * Render wishlist shortcode
     *
     * @param array $atts Shortcode attributes
     * @return string HTML output
     */
    public function render_wishlist( $atts ) {
        $atts = shortcode_atts( array(
            'share_code' => '',
        ), $atts, 'wishcart_wishlist' );

        // Get share code from query var if not in shortcode
        if ( empty( $atts['share_code'] ) ) {
            $atts['share_code'] = get_query_var( 'wishlist_share_code', '' );
        }

        // Enqueue scripts and styles
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
        $handler = new WISHCART_Wishlist_Handler();
        $session_id = $handler->get_or_create_session_id();
        
        wp_localize_script(
            'wishcart-wishlist-frontend',
            'WishCartWishlist',
            array(
                'apiUrl' => trailingslashit( rest_url( 'wishcart/v1' ) ),
                'nonce' => wp_create_nonce( 'wp_rest' ),
                'sessionId' => $session_id,
                'isLoggedIn' => is_user_logged_in(),
                'userId' => get_current_user_id(),
                'shareCode' => sanitize_text_field( $atts['share_code'] ),
            )
        );

        // Return container for React component
        return '<div id="wishcart-wishlist-page"></div>';
    }
}

new WISHCART_Wishlist_Shortcode();

