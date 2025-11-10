<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Wishlist Page Handler
 *
 * Creates and manages the wishlist page
 *
 * @category WordPress
 * @package  WishCart
 * @author   WishCart Team <support@wishcart.chat>
 * @license  GPL-2.0+ https://www.gnu.org/licenses/gpl-2.0.html
 * @link     https://wishcart.chat
 */
class WISHCART_Wishlist_Page {

    /**
     * Create default wishlist page on activation
     *
     * @return int Page ID
     */
    public static function create_wishlist_page() {
        // Check if page already exists
        $existing_page = get_option( 'wishcart_wishlist_page_id', 0 );
        
        if ( $existing_page && get_post( $existing_page ) ) {
            return $existing_page;
        }

        // Create new page
        $page_data = array(
            'post_title'   => __( 'My Wishlist', 'wish-cart' ),
            'post_content' => '[wishcart_wishlist]',
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_name'    => 'wishlist',
        );

        $page_id = wp_insert_post( $page_data );

        if ( $page_id && ! is_wp_error( $page_id ) ) {
            update_option( 'wishcart_wishlist_page_id', $page_id );
            
            // Update settings
            $settings = get_option( 'wishcart_settings', array() );
            if ( ! isset( $settings['wishlist'] ) ) {
                $settings['wishlist'] = array();
            }
            $settings['wishlist']['wishlist_page_id'] = $page_id;
            update_option( 'wishcart_settings', $settings );
        }

        return $page_id;
    }

    /**
     * Get wishlist page ID
     *
     * @return int Page ID
     */
    public static function get_wishlist_page_id() {
        $settings = get_option( 'wishcart_settings', array() );
        
        if ( isset( $settings['wishlist']['wishlist_page_id'] ) && $settings['wishlist']['wishlist_page_id'] > 0 ) {
            return intval( $settings['wishlist']['wishlist_page_id'] );
        }

        // Fallback to option
        return intval( get_option( 'wishcart_wishlist_page_id', 0 ) );
    }
}

