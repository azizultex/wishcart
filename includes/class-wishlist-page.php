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
     * Constructor - Initialize rewrite rules
     */
    public function __construct() {
        add_action( 'init', array( $this, 'add_rewrite_rules' ) );
        add_filter( 'query_vars', array( $this, 'add_query_vars' ) );
        add_action( 'template_redirect', array( $this, 'handle_wishlist_share_code' ) );
    }

    /**
     * Add rewrite rules for wishlist share codes
     *
     * @return void
     */
    public function add_rewrite_rules() {
        $page_id = self::get_wishlist_page_id();
        if ( ! $page_id ) {
            return;
        }

        $page = get_post( $page_id );
        if ( ! $page ) {
            return;
        }

        $page_slug = $page->post_name;
        add_rewrite_rule(
            '^' . $page_slug . '/([^/]+)/?$',
            'index.php?pagename=' . $page_slug . '&wishlist_share_code=$matches[1]',
            'top'
        );
    }

    /**
     * Add query vars for wishlist share code
     *
     * @param array $vars Query vars
     * @return array
     */
    public function add_query_vars( $vars ) {
        $vars[] = 'wishlist_share_code';
        return $vars;
    }

    /**
     * Handle wishlist share code in URL
     *
     * @return void
     */
    public function handle_wishlist_share_code() {
        $share_code = get_query_var( 'wishlist_share_code' );
        if ( ! empty( $share_code ) ) {
            // Share code will be passed to shortcode via global or filter
            set_query_var( 'wishlist_share_code', $share_code );
        }
    }

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
            'shared_wishlist_page_id' => 0,
            'guest_cookie_expiry'  => 30,
            'enable_multiple_wishlists' => false,
            'button_customization' => array(
                'colors' => array(
                    'background'      => '#ffffff',
                    'text'            => '#374151',
                    'border'          => 'rgba(107, 114, 128, 0.3)',
                    'hoverBackground' => '#f3f4f6',
                    'hoverText'       => '#374151',
                    'activeBackground' => '#fef2f2',
                    'activeText'      => '#991b1b',
                    'activeBorder'   => 'rgba(220, 38, 38, 0.4)',
                    'focusBorder'    => '#3b82f6',
                ),
                'icon' => array(
                    'type'      => 'predefined',
                    'value'      => 'heart',
                    'customUrl'  => '',
                ),
                'labels' => array(
                    'add'    => __( 'Add to Wishlist', 'wish-cart' ),
                    'saved'  => __( 'Saved to Wishlist', 'wish-cart' ),
                ),
            ),
        );
    }
}

