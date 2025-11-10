<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Wishlist Handler Class
 *
 * Handles wishlist CRUD operations for both logged-in and guest users
 *
 * @category WordPress
 * @package  WishCart
 * @author   WishCart Team <support@wishcart.chat>
 * @license  GPL-2.0+ https://www.gnu.org/licenses/gpl-2.0.html
 * @link     https://wishcart.chat
 */
class WISHCART_Wishlist_Handler {

    private $wpdb;
    private $table_name;

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_name = $wpdb->prefix . 'wishcart_wishlist';
    }

    /**
     * Get or create session ID for guest users
     *
     * @return string Session ID
     */
    public function get_or_create_session_id() {
        $cookie_name = 'wishcart_session_id';
        
        // Check if session ID exists in cookie
        if ( isset( $_COOKIE[ $cookie_name ] ) && ! empty( $_COOKIE[ $cookie_name ] ) ) {
            return sanitize_text_field( wp_unslash( $_COOKIE[ $cookie_name ] ) );
        }

        // Generate new session ID
        $session_id = wp_generate_password( 32, false );
        
        // Set cookie (30 days expiry by default)
        $settings = get_option( 'wishcart_settings', [] );
        $expiry_days = isset( $settings['wishlist']['guest_cookie_expiry'] ) ? intval( $settings['wishlist']['guest_cookie_expiry'] ) : 30;
        $expiry = time() + ( $expiry_days * DAY_IN_SECONDS );
        
        setcookie( $cookie_name, $session_id, $expiry, '/', '', is_ssl(), true );
        $_COOKIE[ $cookie_name ] = $session_id;
        
        return $session_id;
    }

    /**
     * Add product to wishlist
     *
     * @param int    $product_id Product ID
     * @param int|null $user_id User ID (null for guests)
     * @param string|null $session_id Session ID (null for logged-in users)
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public function add_to_wishlist( $product_id, $user_id = null, $session_id = null ) {
        $product_id = intval( $product_id );
        
        if ( $product_id <= 0 ) {
            return new WP_Error( 'invalid_product', __( 'Invalid product ID', 'wish-cart' ) );
        }

        // Verify product exists
        $product = WISHCART_FluentCart_Helper::get_product( $product_id );
        if ( ! $product ) {
            return new WP_Error( 'product_not_found', __( 'Product not found', 'wish-cart' ) );
        }

        // Determine user_id or session_id
        if ( is_user_logged_in() ) {
            $user_id = get_current_user_id();
            $session_id = null;
        } else {
            if ( empty( $session_id ) ) {
                $session_id = $this->get_or_create_session_id();
            }
            $user_id = null;
        }

        // Check if already in wishlist
        if ( $this->is_in_wishlist( $product_id, $user_id, $session_id ) ) {
            return true; // Already added
        }

        // Insert into database
        $result = $this->wpdb->insert(
            $this->table_name,
            [
                'user_id' => $user_id,
                'session_id' => $session_id,
                'product_id' => $product_id,
            ],
            [
                '%d',
                '%s',
                '%d',
            ]
        );

        if ( false === $result ) {
            return new WP_Error( 'db_error', __( 'Failed to add product to wishlist', 'wish-cart' ) );
        }

        // Clear cache
        $this->clear_wishlist_cache( $user_id, $session_id );

        return true;
    }

    /**
     * Remove product from wishlist
     *
     * @param int    $product_id Product ID
     * @param int|null $user_id User ID (null for guests)
     * @param string|null $session_id Session ID (null for logged-in users)
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public function remove_from_wishlist( $product_id, $user_id = null, $session_id = null ) {
        $product_id = intval( $product_id );
        
        if ( $product_id <= 0 ) {
            return new WP_Error( 'invalid_product', __( 'Invalid product ID', 'wish-cart' ) );
        }

        // Determine user_id or session_id
        if ( is_user_logged_in() ) {
            $user_id = get_current_user_id();
            $session_id = null;
        } else {
            if ( empty( $session_id ) ) {
                $session_id = $this->get_or_create_session_id();
            }
            $user_id = null;
        }

        // Build where clause
        $where = [ 'product_id' => $product_id ];
        $where_format = [ '%d' ];

        if ( $user_id ) {
            $where['user_id'] = $user_id;
            $where_format[] = '%d';
        } else {
            $where['session_id'] = $session_id;
            $where_format[] = '%s';
        }

        // Delete from database
        $result = $this->wpdb->delete(
            $this->table_name,
            $where,
            $where_format
        );

        if ( false === $result ) {
            return new WP_Error( 'db_error', __( 'Failed to remove product from wishlist', 'wish-cart' ) );
        }

        // Clear cache
        $this->clear_wishlist_cache( $user_id, $session_id );

        return true;
    }

    /**
     * Get user's wishlist
     *
     * @param int|null $user_id User ID (null for guests)
     * @param string|null $session_id Session ID (null for logged-in users)
     * @return array Array of product IDs
     */
    public function get_user_wishlist( $user_id = null, $session_id = null ) {
        // Determine user_id or session_id
        if ( is_user_logged_in() ) {
            $user_id = get_current_user_id();
            $session_id = null;
        } else {
            if ( empty( $session_id ) ) {
                $session_id = $this->get_or_create_session_id();
            }
            $user_id = null;
        }

        // Check cache
        $cache_key = $this->get_cache_key( $user_id, $session_id );
        $cached = wp_cache_get( $cache_key, 'wishcart_wishlist' );
        
        if ( false !== $cached ) {
            return $cached;
        }

        // Build query
        if ( $user_id ) {
            $results = $this->wpdb->get_results(
                $this->wpdb->prepare(
                    "SELECT product_id FROM {$this->table_name} WHERE user_id = %d ORDER BY created_at DESC",
                    $user_id
                ),
                ARRAY_A
            );
        } else {
            $results = $this->wpdb->get_results(
                $this->wpdb->prepare(
                    "SELECT product_id FROM {$this->table_name} WHERE session_id = %s ORDER BY created_at DESC",
                    $session_id
                ),
                ARRAY_A
            );
        }

        $product_ids = [];
        if ( $results ) {
            foreach ( $results as $row ) {
                $product_ids[] = intval( $row['product_id'] );
            }
        }

        // Cache results
        wp_cache_set( $cache_key, $product_ids, 'wishcart_wishlist', 3600 );

        return $product_ids;
    }

    /**
     * Check if product is in wishlist
     *
     * @param int    $product_id Product ID
     * @param int|null $user_id User ID (null for guests)
     * @param string|null $session_id Session ID (null for logged-in users)
     * @return bool True if in wishlist
     */
    public function is_in_wishlist( $product_id, $user_id = null, $session_id = null ) {
        $product_id = intval( $product_id );
        
        if ( $product_id <= 0 ) {
            return false;
        }

        // Determine user_id or session_id
        if ( is_user_logged_in() ) {
            $user_id = get_current_user_id();
            $session_id = null;
        } else {
            if ( empty( $session_id ) ) {
                $session_id = $this->get_or_create_session_id();
            }
            $user_id = null;
        }

        // Build query
        if ( $user_id ) {
            $count = $this->wpdb->get_var(
                $this->wpdb->prepare(
                    "SELECT COUNT(*) FROM {$this->table_name} WHERE user_id = %d AND product_id = %d",
                    $user_id,
                    $product_id
                )
            );
        } else {
            $count = $this->wpdb->get_var(
                $this->wpdb->prepare(
                    "SELECT COUNT(*) FROM {$this->table_name} WHERE session_id = %s AND product_id = %d",
                    $session_id,
                    $product_id
                )
            );
        }

        return ( $count > 0 );
    }

    /**
     * Sync guest wishlist to user account on login
     *
     * @param string $session_id Guest session ID
     * @param int    $user_id User ID
     * @return bool|WP_Error True on success
     */
    public function sync_guest_wishlist_to_user( $session_id, $user_id ) {
        if ( empty( $session_id ) || empty( $user_id ) ) {
            return new WP_Error( 'invalid_params', __( 'Invalid parameters', 'wish-cart' ) );
        }

        // Get guest wishlist
        $guest_products = $this->wpdb->get_col(
            $this->wpdb->prepare(
                "SELECT product_id FROM {$this->table_name} WHERE session_id = %s",
                $session_id
            )
        );

        if ( empty( $guest_products ) ) {
            return true; // Nothing to sync
        }

        // Get user's existing wishlist
        $user_products = $this->wpdb->get_col(
            $this->wpdb->prepare(
                "SELECT product_id FROM {$this->table_name} WHERE user_id = %d",
                $user_id
            )
        );

        $user_products = array_map( 'intval', $user_products );

        // Add guest products to user wishlist (skip duplicates)
        foreach ( $guest_products as $product_id ) {
            $product_id = intval( $product_id );
            
            if ( in_array( $product_id, $user_products, true ) ) {
                continue; // Already in user wishlist
            }

            // Insert with user_id
            $this->wpdb->insert(
                $this->table_name,
                [
                    'user_id' => $user_id,
                    'session_id' => null,
                    'product_id' => $product_id,
                ],
                [
                    '%d',
                    '%s',
                    '%d',
                ]
            );
        }

        // Delete guest wishlist entries
        $this->wpdb->delete(
            $this->table_name,
            [ 'session_id' => $session_id ],
            [ '%s' ]
        );

        // Clear caches
        $this->clear_wishlist_cache( $user_id, null );
        $this->clear_wishlist_cache( null, $session_id );

        return true;
    }

    /**
     * Get cache key for wishlist
     *
     * @param int|null $user_id User ID
     * @param string|null $session_id Session ID
     * @return string Cache key
     */
    private function get_cache_key( $user_id, $session_id ) {
        if ( $user_id ) {
            return 'wishlist_user_' . $user_id;
        }
        return 'wishlist_session_' . $session_id;
    }

    /**
     * Clear wishlist cache
     *
     * @param int|null $user_id User ID
     * @param string|null $session_id Session ID
     * @return void
     */
    private function clear_wishlist_cache( $user_id, $session_id ) {
        $cache_key = $this->get_cache_key( $user_id, $session_id );
        wp_cache_delete( $cache_key, 'wishcart_wishlist' );
    }
}

