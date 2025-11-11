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
        $page_id = self::locate_existing_page();

        if ( ! $page_id ) {
            $page_data = array(
                'post_title'   => __( 'Wishlist', 'wish-cart' ),
                'post_content' => '[wishcart_wishlist]',
                'post_status'  => 'publish',
                'post_type'    => 'page',
                'post_name'    => 'wishlist',
            );

            $page_id = wp_insert_post( $page_data );

            if ( is_wp_error( $page_id ) ) {
                return 0;
            }
            self::ensure_page_ready( $page_id );
        } else {
            self::ensure_page_ready( $page_id );
        }

        if ( $page_id ) {
            update_option( 'wishcart_wishlist_page_id', $page_id );
            self::ensure_default_settings( $page_id );
        }

        return intval( $page_id );
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

    /**
     * Try to locate an existing wishlist page
     *
     * @return int Page ID
     */
    private static function locate_existing_page() {
        $stored_id = intval( get_option( 'wishcart_wishlist_page_id', 0 ) );

        if ( $stored_id && get_post( $stored_id ) ) {
            return $stored_id;
        }

        $settings = get_option( 'wishcart_settings', array() );
        if ( isset( $settings['wishlist']['wishlist_page_id'] ) ) {
            $settings_id = intval( $settings['wishlist']['wishlist_page_id'] );
            if ( $settings_id && get_post( $settings_id ) ) {
                return $settings_id;
            }
        }

        $slug_page = get_page_by_path( 'wishlist' );
        if ( $slug_page ) {
            self::ensure_page_ready( $slug_page->ID );
            return intval( $slug_page->ID );
        }

        $existing = get_posts(
            array(
                'post_type'      => 'page',
                'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
                'posts_per_page' => 1,
                's'              => 'wishlist',
            )
        );

        if ( ! empty( $existing ) ) {
            self::ensure_page_ready( $existing[0]->ID );
            return intval( $existing[0]->ID );
        }

        return 0;
    }

    /**
     * Ensure wishlist page is published and includes shortcode
     *
     * @param int $page_id Page ID.
     * @return void
     */
    private static function ensure_page_ready( $page_id ) {
        $page = get_post( $page_id );
        if ( ! $page ) {
            return;
        }

        $needs_update = false;
        $updated_data = array( 'ID' => $page_id );

        if ( 'publish' !== $page->post_status ) {
            $updated_data['post_status'] = 'publish';
            $needs_update              = true;
        }

        $content = $page->post_content ?? '';
        if ( strpos( $content, '[wishcart_wishlist]' ) === false ) {
            $content                     = trim( $content . "\n\n[wishcart_wishlist]\n" );
            $updated_data['post_content'] = $content;
            $needs_update                = true;
        }

        if ( empty( $page->post_name ) ) {
            $updated_data['post_name'] = 'wishlist';
            $needs_update              = true;
        }

        if ( $needs_update ) {
            wp_update_post( $updated_data );
        }
    }

    /**
     * Ensure wishlist defaults are stored alongside page ID
     *
     * @param int $page_id Page ID.
     * @return void
     */
    private static function ensure_default_settings( $page_id ) {
        $settings          = get_option( 'wishcart_settings', array() );
        $wishlist_defaults = self::get_default_settings( $page_id );

        if ( ! isset( $settings['wishlist'] ) || ! is_array( $settings['wishlist'] ) ) {
            $settings['wishlist'] = array();
        }

        $settings['wishlist'] = wp_parse_args( $settings['wishlist'], $wishlist_defaults );
        $settings['wishlist']['wishlist_page_id'] = intval( $page_id );

        update_option( 'wishcart_settings', $settings );
    }

    /**
     * Retrieve default wishlist settings
     *
     * @param int $page_id Page ID.
     * @return array
     */
    public static function get_default_settings( $page_id = 0 ) {
        return array(
            'enabled'              => true,
            'shop_page_button'     => true,
            'product_page_button'  => true,
            'button_position'      => 'bottom',
            'custom_css'           => '',
            'wishlist_page_id'     => intval( $page_id ),
            'guest_cookie_expiry'  => 30,
        );
    }
}

