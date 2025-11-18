<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Shared Wishlist Shortcode Handler
 *
 * @category WordPress
 * @package  WishCart
 * @author   WishCart Team <support@wishcart.chat>
 * @license  GPL-2.0+ https://www.gnu.org/licenses/gpl-2.0.html
 * @link     https://wishcart.chat
 */
class WISHCART_Shared_Wishlist_Shortcode {

    /**
     * Constructor
     */
    public function __construct() {
        add_shortcode( 'wishcart_shared_wishlist', array( $this, 'render_shared_wishlist' ) );
    }

    /**
     * Render shared wishlist shortcode
     *
     * @param array $atts Shortcode attributes
     * @return string HTML output
     */
    public function render_shared_wishlist( $atts ) {
        $atts = shortcode_atts( array(
            'token' => '',
        ), $atts, 'wishcart_shared_wishlist' );

        // Get token from query parameter if not in shortcode
        if ( empty( $atts['token'] ) ) {
            $atts['token'] = isset( $_GET['token'] ) ? sanitize_text_field( $_GET['token'] ) : '';
        }

        // Enqueue scripts and styles
        wp_enqueue_script(
            'wishcart-shared-wishlist',
            WISHCART_PLUGIN_URL . 'build/wishlist-frontend.js',
            array( 'wp-element', 'wp-api-fetch' ),
            WISHCART_VERSION,
            true
        );

        wp_enqueue_style(
            'wishcart-shared-wishlist',
            WISHCART_PLUGIN_URL . 'build/wishlist-frontend.css',
            array(),
            WISHCART_VERSION
        );

        // Localize script with API data
        wp_localize_script(
            'wishcart-shared-wishlist',
            'WishCartShared',
            array(
                'apiUrl' => trailingslashit( rest_url( 'wishcart/v1' ) ),
                'shareToken' => sanitize_text_field( $atts['token'] ),
                'nonce' => wp_create_nonce( 'wp_rest' ),
                'siteUrl' => home_url(),
                'isUserLoggedIn' => is_user_logged_in(),
                'userId' => get_current_user_id(),
            )
        );

        // Return container for React component
        return '<div id="shared-wishlist-app" data-share-token="' . esc_attr( $atts['token'] ) . '"></div>';
    }
}

new WISHCART_Shared_Wishlist_Shortcode();

