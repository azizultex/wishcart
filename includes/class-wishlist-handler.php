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
    private $wishlists_table_name;
    private $guest_cookie_name = 'wishcart_guest_wishlist';

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_name = $wpdb->prefix . 'wishcart_wishlist';
        $this->wishlists_table_name = $wpdb->prefix . 'wishcart_wishlists';
    }

    /**
     * Get guest wishlist cookie name
     *
     * @return string
     */
    private function get_guest_cookie_name() {
        return $this->guest_cookie_name;
    }

    /**
     * Get configured guest cookie expiry (days)
     *
     * @return int
     */
    private function get_guest_cookie_expiry_days() {
        $settings = get_option( 'wishcart_settings', [] );
        $expiry_days = isset( $settings['wishlist']['guest_cookie_expiry'] ) ? intval( $settings['wishlist']['guest_cookie_expiry'] ) : 30;

        return $expiry_days > 0 ? $expiry_days : 30;
    }

    /**
     * Get guest wishlist from cookie
     *
     * @return array<int>
     */
    private function get_guest_wishlist_from_cookie() {
        $cookie_name = $this->get_guest_cookie_name();
        $wishlist = [];

        if ( isset( $_COOKIE[ $cookie_name ] ) && '' !== $_COOKIE[ $cookie_name ] ) {
            $raw = wp_unslash( $_COOKIE[ $cookie_name ] );
            $decoded = json_decode( $raw, true );

            if ( is_array( $decoded ) ) {
                foreach ( $decoded as $product_id ) {
                    $product_id = intval( $product_id );
                    if ( $product_id > 0 ) {
                        $wishlist[] = $product_id;
                    }
                }
            }
        }

        return array_values( array_unique( $wishlist ) );
    }

    /**
     * Persist guest wishlist to cookie
     *
     * @param array<int> $product_ids
     * @return void
     */
    private function set_guest_wishlist_cookie( $product_ids ) {
        $cookie_name = $this->get_guest_cookie_name();
        $product_ids = array_values(
            array_unique(
                array_filter(
                    array_map( 'intval', (array) $product_ids ),
                    function ( $id ) {
                        return $id > 0;
                    }
                )
            )
        );

        $json = wp_json_encode( $product_ids );
        if ( false === $json ) {
            $json = '[]';
        }

        $expiry = time() + ( $this->get_guest_cookie_expiry_days() * DAY_IN_SECONDS );
        setcookie( $cookie_name, $json, $expiry, '/', '', is_ssl(), true );
        $_COOKIE[ $cookie_name ] = $json;
    }

    /**
     * Clear guest wishlist cookie
     *
     * @return void
     */
    private function clear_guest_wishlist_cookie() {
        $cookie_name = $this->get_guest_cookie_name();
        setcookie( $cookie_name, '', time() - DAY_IN_SECONDS, '/', '', is_ssl(), true );
        unset( $_COOKIE[ $cookie_name ] );
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
     * @param int|null $wishlist_id Wishlist ID (null for default wishlist)
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public function add_to_wishlist( $product_id, $user_id = null, $session_id = null, $wishlist_id = null ) {
        $product_id = intval( $product_id );
        
        if ( $product_id <= 0 ) {
            return new WP_Error( 'invalid_product', __( 'Invalid product ID', 'wish-cart' ) );
        }

        // Verify product exists
        $product = WISHCART_FluentCart_Helper::get_product( $product_id );
        if ( ! $product ) {
            return new WP_Error( 'product_not_found', __( 'Product not found', 'wish-cart' ) );
        }

        // Determine user_id or session_id and get wishlist_id
        if ( is_user_logged_in() ) {
            $user_id = get_current_user_id();
            $session_id = null;

            // Get or create default wishlist if wishlist_id not provided
            if ( empty( $wishlist_id ) ) {
                $default_wishlist = $this->get_default_wishlist( $user_id, null );
                if ( $default_wishlist ) {
                    $wishlist_id = $default_wishlist['id'];
                }
            }

            // Check if already in wishlist
            if ( $wishlist_id ) {
                $exists = $this->wpdb->get_var(
                    $this->wpdb->prepare(
                        "SELECT COUNT(*) FROM {$this->table_name} WHERE wishlist_id = %d AND product_id = %d",
                        $wishlist_id,
                        $product_id
                    )
                );
                if ( $exists > 0 ) {
                    return true; // Already added
                }
            } else {
                if ( $this->is_in_wishlist( $product_id, $user_id, $session_id ) ) {
                    return true; // Already added
                }
            }

            // Insert into database
            $result = $this->wpdb->insert(
                $this->table_name,
                [
                    'user_id' => $user_id,
                    'session_id' => $session_id,
                    'wishlist_id' => $wishlist_id,
                    'product_id' => $product_id,
                ],
                [
                    '%d',
                    '%s',
                    '%d',
                    '%d',
                ]
            );

            if ( false === $result ) {
                return new WP_Error( 'db_error', __( 'Failed to add product to wishlist', 'wish-cart' ) );
            }

            // Clear cache
            $this->clear_wishlist_cache( $user_id, null );

            return true;
        }

        if ( empty( $session_id ) ) {
            $session_id = $this->get_or_create_session_id();
        }

        // Get or create default wishlist if wishlist_id not provided
        if ( empty( $wishlist_id ) ) {
            $default_wishlist = $this->get_default_wishlist( null, $session_id );
            if ( $default_wishlist ) {
                $wishlist_id = $default_wishlist['id'];
            }
        }

        // For guest users, still use cookie for backward compatibility
        // But also store in database if wishlist_id is available
        if ( $wishlist_id ) {
            // Check if already in wishlist
            $exists = $this->wpdb->get_var(
                $this->wpdb->prepare(
                    "SELECT COUNT(*) FROM {$this->table_name} WHERE wishlist_id = %d AND product_id = %d",
                    $wishlist_id,
                    $product_id
                )
            );
            if ( $exists > 0 ) {
                return true; // Already added
            }

            // Insert into database
            $result = $this->wpdb->insert(
                $this->table_name,
                [
                    'user_id' => $user_id,
                    'session_id' => $session_id,
                    'wishlist_id' => $wishlist_id,
                    'product_id' => $product_id,
                ],
                [
                    '%d',
                    '%s',
                    '%d',
                    '%d',
                ]
            );

            if ( false === $result ) {
                return new WP_Error( 'db_error', __( 'Failed to add product to wishlist', 'wish-cart' ) );
            }
        } else {
            // Fallback to cookie method
            $wishlist = $this->get_guest_wishlist_from_cookie();
            if ( in_array( $product_id, $wishlist, true ) ) {
                return true;
            }

            $wishlist[] = $product_id;
            $this->set_guest_wishlist_cookie( $wishlist );
        }

        return true;
    }

    /**
     * Remove product from wishlist
     *
     * @param int    $product_id Product ID
     * @param int|null $user_id User ID (null for guests)
     * @param string|null $session_id Session ID (null for logged-in users)
     * @param int|null $wishlist_id Wishlist ID (null for default wishlist)
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public function remove_from_wishlist( $product_id, $user_id = null, $session_id = null, $wishlist_id = null ) {
        $product_id = intval( $product_id );
        
        if ( $product_id <= 0 ) {
            return new WP_Error( 'invalid_product', __( 'Invalid product ID', 'wish-cart' ) );
        }

        // Determine user_id or session_id and get wishlist_id
        if ( is_user_logged_in() ) {
            $user_id = get_current_user_id();
            $session_id = null;
            
            // Get default wishlist if wishlist_id not provided
            if ( empty( $wishlist_id ) ) {
                $default_wishlist = $this->get_default_wishlist( $user_id, null );
                if ( $default_wishlist ) {
                    $wishlist_id = $default_wishlist['id'];
                }
            }
            
            // Build where clause
            $where = [ 'product_id' => $product_id ];
            $where_format = [ '%d' ];

            if ( $wishlist_id ) {
                $where['wishlist_id'] = $wishlist_id;
                $where_format[] = '%d';
            } else {
                $where['user_id'] = $user_id;
                $where_format[] = '%d';
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
            $this->clear_wishlist_cache( $user_id, null );

            return true;
        }

        if ( empty( $session_id ) ) {
            $session_id = $this->get_or_create_session_id();
        }

        // Get default wishlist if wishlist_id not provided
        if ( empty( $wishlist_id ) ) {
            $default_wishlist = $this->get_default_wishlist( null, $session_id );
            if ( $default_wishlist ) {
                $wishlist_id = $default_wishlist['id'];
            }
        }

        if ( $wishlist_id ) {
            // Delete from database
            $result = $this->wpdb->delete(
                $this->table_name,
                [
                    'wishlist_id' => $wishlist_id,
                    'product_id' => $product_id,
                ],
                [
                    '%d',
                    '%d',
                ]
            );

            if ( false === $result ) {
                return new WP_Error( 'db_error', __( 'Failed to remove product from wishlist', 'wish-cart' ) );
            }
        } else {
            // Fallback to cookie method
            $wishlist = $this->get_guest_wishlist_from_cookie();
            $index = array_search( $product_id, $wishlist, true );

            if ( false === $index ) {
                return true;
            }

            unset( $wishlist[ $index ] );
            $this->set_guest_wishlist_cookie( $wishlist );

            if ( ! empty( $session_id ) ) {
                $this->wpdb->delete(
                    $this->table_name,
                    [
                        'session_id' => $session_id,
                        'product_id' => $product_id,
                    ],
                    [
                        '%s',
                        '%d',
                    ]
                );
            }
        }

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

            $wishlist = $this->get_guest_wishlist_from_cookie();
            if ( ! empty( $wishlist ) ) {
                return $wishlist;
            }

            // Backwards compatibility: fall back to legacy database storage if present.
            $results = $this->wpdb->get_results(
                $this->wpdb->prepare(
                    "SELECT product_id FROM {$this->table_name} WHERE session_id = %s ORDER BY created_at DESC",
                    $session_id
                ),
                ARRAY_A
            );

            $product_ids = [];
            if ( $results ) {
                foreach ( $results as $row ) {
                    $product_ids[] = intval( $row['product_id'] );
                }
            }

            if ( ! empty( $product_ids ) ) {
                $this->set_guest_wishlist_cookie( $product_ids );
            }

            return $product_ids;
        }

        // Check cache
        $cache_key = $this->get_cache_key( $user_id, $session_id );
        $cached = wp_cache_get( $cache_key, 'wishcart_wishlist' );
        
        if ( false !== $cached ) {
            return $cached;
        }

        // Build query
        $results = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT product_id FROM {$this->table_name} WHERE user_id = %d ORDER BY created_at DESC",
                $user_id
            ),
            ARRAY_A
        );

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
     * Get user's wishlist with dates
     *
     * @param int|null $user_id User ID (null for guests)
     * @param string|null $session_id Session ID (null for logged-in users)
     * @return array Array of arrays with 'product_id' and 'created_at' keys
     */
    public function get_user_wishlist_with_dates( $user_id = null, $session_id = null ) {
        // Determine user_id or session_id
        if ( is_user_logged_in() ) {
            $user_id = get_current_user_id();
            $session_id = null;
        } else {
            if ( empty( $session_id ) ) {
                $session_id = $this->get_or_create_session_id();
            }

            $wishlist = $this->get_guest_wishlist_from_cookie();
            if ( ! empty( $wishlist ) ) {
                // For cookie-based wishlist, we don't have dates, so use current time
                $items = [];
                foreach ( $wishlist as $product_id ) {
                    $items[] = [
                        'product_id' => intval( $product_id ),
                        'created_at' => current_time( 'mysql' ),
                    ];
                }
                return $items;
            }

            // Backwards compatibility: fall back to legacy database storage if present.
            $results = $this->wpdb->get_results(
                $this->wpdb->prepare(
                    "SELECT product_id, created_at FROM {$this->table_name} WHERE session_id = %s ORDER BY created_at DESC",
                    $session_id
                ),
                ARRAY_A
            );

            $items = [];
            if ( $results ) {
                foreach ( $results as $row ) {
                    $items[] = [
                        'product_id' => intval( $row['product_id'] ),
                        'created_at' => $row['created_at'],
                    ];
                }
            }

            if ( ! empty( $items ) ) {
                $product_ids = array_column( $items, 'product_id' );
                $this->set_guest_wishlist_cookie( $product_ids );
            }

            return $items;
        }

        // Build query to get product_id and created_at
        $results = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT product_id, created_at FROM {$this->table_name} WHERE user_id = %d ORDER BY created_at DESC",
                $user_id
            ),
            ARRAY_A
        );

        $items = [];
        if ( $results ) {
            foreach ( $results as $row ) {
                $items[] = [
                    'product_id' => intval( $row['product_id'] ),
                    'created_at' => $row['created_at'],
                ];
            }
        }

        return $items;
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

            $count = $this->wpdb->get_var(
                $this->wpdb->prepare(
                    "SELECT COUNT(*) FROM {$this->table_name} WHERE user_id = %d AND product_id = %d",
                    $user_id,
                    $product_id
                )
            );

            return ( $count > 0 );
        }

        if ( empty( $session_id ) ) {
            $session_id = $this->get_or_create_session_id();
        }

        $wishlist = $this->get_guest_wishlist_from_cookie();
        if ( in_array( $product_id, $wishlist, true ) ) {
            return true;
        }

        // Backwards compatibility: check legacy database entries and migrate.
        $count = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table_name} WHERE session_id = %s AND product_id = %d",
                $session_id,
                $product_id
            )
        );

        if ( $count > 0 ) {
            $this->set_guest_wishlist_cookie( array_merge( $wishlist, [ $product_id ] ) );
            return true;
        }

        return false;
    }

    /**
     * Sync guest wishlist to user account on login
     *
     * @param string $session_id Guest session ID
     * @param int    $user_id User ID
     * @return bool|WP_Error True on success
     */
    public function sync_guest_wishlist_to_user( $session_id, $user_id ) {
        if ( empty( $user_id ) ) {
            return new WP_Error( 'invalid_params', __( 'Invalid parameters', 'wish-cart' ) );
        }

        $guest_products = $this->get_guest_wishlist_from_cookie();
        $guest_products = array_map( 'intval', $guest_products );

        if ( ! empty( $session_id ) ) {
            $legacy_products = $this->wpdb->get_col(
                $this->wpdb->prepare(
                    "SELECT product_id FROM {$this->table_name} WHERE session_id = %s",
                    $session_id
                )
            );

            if ( $legacy_products ) {
                $guest_products = array_unique( array_merge( $guest_products, array_map( 'intval', $legacy_products ) ) );
            }
        }

        if ( empty( $guest_products ) ) {
            $this->clear_guest_wishlist_cookie();
            if ( ! empty( $session_id ) ) {
                $this->wpdb->delete(
                    $this->table_name,
                    [ 'session_id' => $session_id ],
                    [ '%s' ]
                );
            }
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
        if ( ! empty( $session_id ) ) {
            $this->wpdb->delete(
                $this->table_name,
                [ 'session_id' => $session_id ],
                [ '%s' ]
            );
        }

        $this->clear_guest_wishlist_cookie();

        // Clear caches
        $this->clear_wishlist_cache( $user_id, null );
        if ( ! empty( $session_id ) ) {
            $this->clear_wishlist_cache( null, $session_id );
        }

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

    /**
     * Generate unique share code
     *
     * @return string
     */
    public function generate_share_code() {
        $max_attempts = 10;
        $attempt = 0;
        
        do {
            // Generate 6-character alphanumeric code
            $code = strtolower( wp_generate_password( 6, false ) );
            $exists = $this->wpdb->get_var(
                $this->wpdb->prepare(
                    "SELECT COUNT(*) FROM {$this->wishlists_table_name} WHERE share_code = %s",
                    $code
                )
            );
            $attempt++;
        } while ( $exists > 0 && $attempt < $max_attempts );
        
        if ( $attempt >= $max_attempts ) {
            // Fallback: use timestamp-based code
            $code = 'w' . substr( md5( time() . wp_rand() ), 0, 5 );
        }
        
        return $code;
    }

    /**
     * Get wishlist by share code
     *
     * @param string $share_code Share code
     * @return array|null Wishlist data or null if not found
     */
    public function get_wishlist_by_share_code( $share_code ) {
        $wishlist = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->wishlists_table_name} WHERE share_code = %s",
                $share_code
            ),
            ARRAY_A
        );

        if ( ! $wishlist ) {
            return null;
        }

        return $wishlist;
    }

    /**
     * Get default wishlist for user
     *
     * @param int|null $user_id User ID
     * @param string|null $session_id Session ID
     * @return array|null Wishlist data or null
     */
    public function get_default_wishlist( $user_id = null, $session_id = null ) {
        if ( is_user_logged_in() ) {
            $user_id = get_current_user_id();
            $session_id = null;
        } else {
            if ( empty( $session_id ) ) {
                $session_id = $this->get_or_create_session_id();
            }
        }

        $where = array();
        $where_format = array();

        if ( $user_id ) {
            $where['user_id'] = $user_id;
            $where_format[] = '%d';
        } else {
            $where['session_id'] = $session_id;
            $where_format[] = '%s';
        }

        $where['is_default'] = 1;
        $where_format[] = '%d';

        $wishlist = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->wishlists_table_name} 
                WHERE " . ( $user_id ? 'user_id = %d' : 'session_id = %s' ) . " AND is_default = 1 
                LIMIT 1",
                $user_id ? $user_id : $session_id
            ),
            ARRAY_A
        );

        // Create default wishlist if it doesn't exist
        if ( ! $wishlist ) {
            $wishlist = $this->create_wishlist( 'Default wishlist', $user_id, $session_id, true );
        }

        return $wishlist;
    }

    /**
     * Create new wishlist
     *
     * @param string $name Wishlist name
     * @param int|null $user_id User ID
     * @param string|null $session_id Session ID
     * @param bool $is_default Is default wishlist
     * @return array|WP_Error Wishlist data or error
     */
    public function create_wishlist( $name, $user_id = null, $session_id = null, $is_default = false ) {
        if ( is_user_logged_in() ) {
            $user_id = get_current_user_id();
            $session_id = null;
        } else {
            if ( empty( $session_id ) ) {
                $session_id = $this->get_or_create_session_id();
            }
        }

        // If setting as default, unset other defaults
        if ( $is_default ) {
            if ( $user_id ) {
                $this->wpdb->update(
                    $this->wishlists_table_name,
                    array( 'is_default' => 0 ),
                    array( 'user_id' => $user_id ),
                    array( '%d' ),
                    array( '%d' )
                );
            } else {
                $this->wpdb->update(
                    $this->wishlists_table_name,
                    array( 'is_default' => 0 ),
                    array( 'session_id' => $session_id ),
                    array( '%d' ),
                    array( '%s' )
                );
            }
        }

        $share_code = $this->generate_share_code();

        $result = $this->wpdb->insert(
            $this->wishlists_table_name,
            array(
                'user_id' => $user_id,
                'session_id' => $session_id,
                'name' => sanitize_text_field( $name ),
                'share_code' => $share_code,
                'is_default' => $is_default ? 1 : 0,
            ),
            array( '%d', '%s', '%s', '%s', '%d' )
        );

        if ( false === $result ) {
            return new WP_Error( 'db_error', __( 'Failed to create wishlist', 'wish-cart' ) );
        }

        $wishlist_id = $this->wpdb->insert_id;
        return $this->get_wishlist( $wishlist_id );
    }

    /**
     * Get wishlist by ID
     *
     * @param int $wishlist_id Wishlist ID
     * @return array|null Wishlist data or null
     */
    public function get_wishlist( $wishlist_id ) {
        return $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->wishlists_table_name} WHERE id = %d",
                $wishlist_id
            ),
            ARRAY_A
        );
    }

    /**
     * Get all wishlists for user
     *
     * @param int|null $user_id User ID
     * @param string|null $session_id Session ID
     * @return array Array of wishlists
     */
    public function get_user_wishlists( $user_id = null, $session_id = null ) {
        if ( is_user_logged_in() ) {
            $user_id = get_current_user_id();
            $session_id = null;
        } else {
            if ( empty( $session_id ) ) {
                $session_id = $this->get_or_create_session_id();
            }
        }

        if ( $user_id ) {
            $wishlists = $this->wpdb->get_results(
                $this->wpdb->prepare(
                    "SELECT * FROM {$this->wishlists_table_name} WHERE user_id = %d ORDER BY is_default DESC, created_at DESC",
                    $user_id
                ),
                ARRAY_A
            );
        } else {
            $wishlists = $this->wpdb->get_results(
                $this->wpdb->prepare(
                    "SELECT * FROM {$this->wishlists_table_name} WHERE session_id = %s ORDER BY is_default DESC, created_at DESC",
                    $session_id
                ),
                ARRAY_A
            );
        }

        return $wishlists ? $wishlists : array();
    }

    /**
     * Update wishlist
     *
     * @param int $wishlist_id Wishlist ID
     * @param array $data Data to update
     * @return bool|WP_Error
     */
    public function update_wishlist( $wishlist_id, $data ) {
        $allowed_fields = array( 'name', 'is_default' );
        $update_data = array();
        $update_format = array();

        foreach ( $allowed_fields as $field ) {
            if ( isset( $data[ $field ] ) ) {
                if ( $field === 'is_default' ) {
                    $update_data[ $field ] = $data[ $field ] ? 1 : 0;
                    $update_format[] = '%d';
                } else {
                    $update_data[ $field ] = sanitize_text_field( $data[ $field ] );
                    $update_format[] = '%s';
                }
            }
        }

        if ( empty( $update_data ) ) {
            return new WP_Error( 'invalid_data', __( 'No valid fields to update', 'wish-cart' ) );
        }

        // If setting as default, unset other defaults
        if ( isset( $update_data['is_default'] ) && $update_data['is_default'] ) {
            $wishlist = $this->get_wishlist( $wishlist_id );
            if ( $wishlist ) {
                if ( $wishlist['user_id'] ) {
                    $this->wpdb->update(
                        $this->wishlists_table_name,
                        array( 'is_default' => 0 ),
                        array( 'user_id' => $wishlist['user_id'] ),
                        array( '%d' ),
                        array( '%d' )
                    );
                } else {
                    $this->wpdb->update(
                        $this->wishlists_table_name,
                        array( 'is_default' => 0 ),
                        array( 'session_id' => $wishlist['session_id'] ),
                        array( '%d' ),
                        array( '%s' )
                    );
                }
            }
        }

        $result = $this->wpdb->update(
            $this->wishlists_table_name,
            $update_data,
            array( 'id' => $wishlist_id ),
            $update_format,
            array( '%d' )
        );

        if ( false === $result ) {
            return new WP_Error( 'db_error', __( 'Failed to update wishlist', 'wish-cart' ) );
        }

        return true;
    }

    /**
     * Delete wishlist
     *
     * @param int $wishlist_id Wishlist ID
     * @return bool|WP_Error
     */
    public function delete_wishlist( $wishlist_id ) {
        $wishlist = $this->get_wishlist( $wishlist_id );
        if ( ! $wishlist ) {
            return new WP_Error( 'not_found', __( 'Wishlist not found', 'wish-cart' ) );
        }

        // Don't allow deleting default wishlist
        if ( $wishlist['is_default'] ) {
            return new WP_Error( 'cannot_delete_default', __( 'Cannot delete default wishlist', 'wish-cart' ) );
        }

        // Delete wishlist items
        $this->wpdb->delete(
            $this->table_name,
            array( 'wishlist_id' => $wishlist_id ),
            array( '%d' )
        );

        // Delete wishlist
        $result = $this->wpdb->delete(
            $this->wishlists_table_name,
            array( 'id' => $wishlist_id ),
            array( '%d' )
        );

        if ( false === $result ) {
            return new WP_Error( 'db_error', __( 'Failed to delete wishlist', 'wish-cart' ) );
        }

        return true;
    }
}

