<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Wishlist Handler Class
 *
 * Handles wishlist CRUD operations for both logged-in and guest users
 * Updated for 7-table structure with full feature support
 *
 * @category WordPress
 * @package  WishCart
 * @author   WishCart Team <support@wishcart.chat>
 * @license  GPL-2.0+ https://www.gnu.org/licenses/gpl-2.0.html
 * @link     https://wishcart.chat
 */
class WISHCART_Wishlist_Handler {

    private $wpdb;
    private $wishlists_table;
    private $items_table;
    private $guest_cookie_name = 'wishcart_guest_wishlist';

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->wishlists_table = $wpdb->prefix . 'fc_wishlists';
        $this->items_table = $wpdb->prefix . 'fc_wishlist_items';
    }

    /**
     * Generate unique wishlist token (64 characters)
     *
     * @return string
     */
    public function generate_wishlist_token() {
        $max_attempts = 10;
        $attempt = 0;
        
        do {
            $token = bin2hex(random_bytes(32)); // 64 character hex string
            $exists = $this->wpdb->get_var(
                $this->wpdb->prepare(
                    "SELECT COUNT(*) FROM {$this->wishlists_table} WHERE wishlist_token = %s",
                    $token
                )
            );
            $attempt++;
        } while ( $exists > 0 && $attempt < $max_attempts );
        
        if ( $attempt >= $max_attempts ) {
            // Fallback: use hash-based token
            $token = hash('sha256', uniqid('wishcart_', true) . wp_rand());
        }
        
        return $token;
    }

    /**
     * Generate wishlist slug from name
     *
     * @param string $name Wishlist name
     * @param int|null $user_id User ID
     * @return string
     */
    private function generate_wishlist_slug($name, $user_id = null) {
        $base_slug = sanitize_title($name);
        $slug = $base_slug;
        $counter = 1;
        
        // Ensure unique slug for user
        while ($this->slug_exists($slug, $user_id)) {
            $slug = $base_slug . '-' . $counter;
            $counter++;
        }
        
        return $slug;
    }

    /**
     * Check if slug exists for user
     *
     * @param string $slug
     * @param int|null $user_id
     * @return bool
     */
    private function slug_exists($slug, $user_id = null) {
        if ($user_id) {
            $exists = $this->wpdb->get_var(
                $this->wpdb->prepare(
                    "SELECT COUNT(*) FROM {$this->wishlists_table} WHERE wishlist_slug = %s AND user_id = %d",
                    $slug,
                    $user_id
                )
            );
        } else {
            $exists = $this->wpdb->get_var(
                $this->wpdb->prepare(
                    "SELECT COUNT(*) FROM {$this->wishlists_table} WHERE wishlist_slug = %s",
                    $slug
                )
            );
        }
        
        return $exists > 0;
    }

    /**
     * Get or create session ID for guest users
     *
     * @return string Session ID
     */
    public function get_or_create_session_id() {
        $cookie_name = 'wishcart_session_id';
        
        // Check if session ID exists in cookie (check multiple sources)
        if ( isset( $_COOKIE[ $cookie_name ] ) && ! empty( $_COOKIE[ $cookie_name ] ) ) {
            $existing_id = sanitize_text_field( wp_unslash( $_COOKIE[ $cookie_name ] ) );
            // Only return if it's not empty after sanitization
            if ( ! empty( $existing_id ) ) {
                return $existing_id;
            }
        }

        // Also check if there's a session ID in the request headers (for REST API)
        // Some setups might send cookies in headers
        if ( function_exists( 'getallheaders' ) ) {
            $headers = getallheaders();
            if ( $headers && isset( $headers['Cookie'] ) ) {
                $cookies = explode( ';', $headers['Cookie'] );
                foreach ( $cookies as $cookie ) {
                    $cookie = trim( $cookie );
                    if ( strpos( $cookie, $cookie_name . '=' ) === 0 ) {
                        $value = substr( $cookie, strlen( $cookie_name ) + 1 );
                        if ( ! empty( $value ) ) {
                            $session_id = sanitize_text_field( $value );
                            if ( ! empty( $session_id ) ) {
                                // Update $_COOKIE for future use
                                $_COOKIE[ $cookie_name ] = $session_id;
                                return $session_id;
                            }
                        }
                    }
                }
            }
        }

        // Generate new session ID only if none exists
        // Use format compatible with frontend (wc_ prefix for consistency)
        $session_id = 'wc_' . wp_generate_password( 32, false );
        
        // Set cookie (30 days expiry by default)
        // Note: HttpOnly set to false so JavaScript can read it for API requests
        $settings = get_option( 'wishcart_settings', [] );
        $expiry_days = isset( $settings['wishlist']['guest_cookie_expiry'] ) ? intval( $settings['wishlist']['guest_cookie_expiry'] ) : 30;
        $expiry = time() + ( $expiry_days * DAY_IN_SECONDS );
        
        // Set HttpOnly to false so JavaScript can access the cookie
        // This is necessary for the frontend to read and send the session_id in API requests
        setcookie( $cookie_name, $session_id, $expiry, '/', '', is_ssl(), false );
        $_COOKIE[ $cookie_name ] = $session_id;
        
        return $session_id;
    }

    /**
     * Create new wishlist
     *
     * @param string $name Wishlist name
     * @param int|null $user_id User ID
     * @param string|null $session_id Session ID
     * @param bool $is_default Is default wishlist
     * @param array $options Additional options (description, privacy_status, expiration_date, wishlist_type)
     * @return array|WP_Error Wishlist data or error
     */
    public function create_wishlist($name, $user_id = null, $session_id = null, $is_default = false, $options = array()) {
        if ( is_user_logged_in() ) {
            $user_id = get_current_user_id();
            $session_id = null;
        } else {
            if ( empty( $session_id ) ) {
                $session_id = $this->get_or_create_session_id();
            }
            $user_id = null;
        }

        // If setting as default, unset other defaults
        if ( $is_default ) {
            if ( $user_id ) {
                $this->wpdb->update(
                    $this->wishlists_table,
                    array( 'is_default' => 0 ),
                    array( 'user_id' => $user_id ),
                    array( '%d' ),
                    array( '%d' )
                );
            } else {
                $this->wpdb->update(
                    $this->wishlists_table,
                    array( 'is_default' => 0 ),
                    array( 'session_id' => $session_id ),
                    array( '%d' ),
                    array( '%s' )
                );
            }
        }

        $token = $this->generate_wishlist_token();
        $slug = $this->generate_wishlist_slug($name, $user_id);

        $data = array(
            'wishlist_token' => $token,
            'user_id' => $user_id,
            'session_id' => $session_id,
            'wishlist_name' => sanitize_text_field($name),
            'wishlist_slug' => $slug,
            'is_default' => $is_default ? 1 : 0,
            'privacy_status' => isset($options['privacy_status']) ? $options['privacy_status'] : 'private',
            'description' => isset($options['description']) ? sanitize_textarea_field($options['description']) : null,
            'expiration_date' => isset($options['expiration_date']) ? $options['expiration_date'] : null,
            'wishlist_type' => isset($options['wishlist_type']) ? $options['wishlist_type'] : 'wishlist',
            'status' => 'active',
        );

        $format = array('%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s');

        $result = $this->wpdb->insert($this->wishlists_table, $data, $format);

        if ( false === $result ) {
            return new WP_Error( 'db_error', __( 'Failed to create wishlist', 'wish-cart' ) );
        }

        $wishlist_id = $this->wpdb->insert_id;
        
        // Log activity
        $this->log_activity($wishlist_id, 'created', null, 'wishlist');

        // Update guest user tracking if this is a guest
        if (!empty($session_id) && empty($user_id)) {
            $this->update_guest_tracking($session_id, $wishlist_id);
        }

        return $this->get_wishlist($wishlist_id);
    }

    /**
     * Get wishlist by ID
     *
     * @param int $wishlist_id Wishlist ID
     * @return array|null Wishlist data or null
     */
    public function get_wishlist($wishlist_id) {
        return $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->wishlists_table} WHERE id = %d AND status = 'active'",
                $wishlist_id
            ),
            ARRAY_A
        );
    }

    /**
     * Get wishlist by token
     *
     * @param string $token Wishlist token
     * @return array|null Wishlist data or null
     */
    public function get_wishlist_by_token($token) {
        return $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->wishlists_table} WHERE wishlist_token = %s AND status = 'active'",
                $token
            ),
            ARRAY_A
        );
    }

    /**
     * Get wishlist by slug
     *
     * @param string $slug Wishlist slug
     * @param int|null $user_id User ID
     * @return array|null Wishlist data or null
     */
    public function get_wishlist_by_slug($slug, $user_id = null) {
        if ($user_id) {
            return $this->wpdb->get_row(
                $this->wpdb->prepare(
                    "SELECT * FROM {$this->wishlists_table} WHERE wishlist_slug = %s AND user_id = %d AND status = 'active'",
                    $slug,
                    $user_id
                ),
                ARRAY_A
            );
        } else {
            return $this->wpdb->get_row(
                $this->wpdb->prepare(
                    "SELECT * FROM {$this->wishlists_table} WHERE wishlist_slug = %s AND status = 'active'",
                    $slug
                ),
                ARRAY_A
            );
        }
    }

    /**
     * Get default wishlist for user
     *
     * @param int|null $user_id User ID
     * @param string|null $session_id Session ID
     * @return array|null Wishlist data or null
     */
    public function get_default_wishlist($user_id = null, $session_id = null) {
        if ( is_user_logged_in() ) {
            $user_id = get_current_user_id();
            $session_id = null;
        } else {
            if ( empty( $session_id ) ) {
                $session_id = $this->get_or_create_session_id();
            }
            $user_id = null;
        }

        $wishlist = null;

        if ( $user_id ) {
            $wishlist = $this->wpdb->get_row(
                $this->wpdb->prepare(
                    "SELECT * FROM {$this->wishlists_table} WHERE user_id = %d AND is_default = 1 AND status = 'active' LIMIT 1",
                    $user_id
                ),
                ARRAY_A
            );
        } else {
            $wishlist = $this->wpdb->get_row(
                $this->wpdb->prepare(
                    "SELECT * FROM {$this->wishlists_table} WHERE session_id = %s AND is_default = 1 AND status = 'active' LIMIT 1",
                    $session_id
                ),
                ARRAY_A
            );
        }

        // Create default wishlist if it doesn't exist
        if ( ! $wishlist ) {
            $wishlist = $this->create_wishlist('My Wishlist', $user_id, $session_id, true);
        }

        return $wishlist;
    }

    /**
     * Get all wishlists for user
     *
     * @param int|null $user_id User ID
     * @param string|null $session_id Session ID
     * @return array Array of wishlists
     */
    public function get_user_wishlists($user_id = null, $session_id = null) {
        if ( is_user_logged_in() ) {
            $user_id = get_current_user_id();
            $session_id = null;
        } else {
            if ( empty( $session_id ) ) {
                $session_id = $this->get_or_create_session_id();
            }
            $user_id = null;
        }

        if ( $user_id ) {
            $wishlists = $this->wpdb->get_results(
                $this->wpdb->prepare(
                    "SELECT * FROM {$this->wishlists_table} WHERE user_id = %d AND status = 'active' ORDER BY is_default DESC, dateadded DESC",
                    $user_id
                ),
                ARRAY_A
            );
        } else {
            $wishlists = $this->wpdb->get_results(
                $this->wpdb->prepare(
                    "SELECT * FROM {$this->wishlists_table} WHERE session_id = %s AND status = 'active' ORDER BY is_default DESC, dateadded DESC",
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
    public function update_wishlist($wishlist_id, $data) {
        $allowed_fields = array('wishlist_name', 'description', 'privacy_status', 'is_default', 'expiration_date', 'menu_order', 'wishlist_type');
        $update_data = array();
        $update_format = array();

        foreach ($allowed_fields as $field) {
            if (isset($data[$field])) {
                if ($field === 'is_default') {
                    $update_data[$field] = $data[$field] ? 1 : 0;
                    $update_format[] = '%d';
                } elseif ($field === 'menu_order') {
                    $update_data[$field] = intval($data[$field]);
                    $update_format[] = '%d';
                } elseif ($field === 'description') {
                    $update_data[$field] = sanitize_textarea_field($data[$field]);
                    $update_format[] = '%s';
                } else {
                    $update_data[$field] = sanitize_text_field($data[$field]);
                    $update_format[] = '%s';
                }
            }
        }

        // Update slug if name changed
        if (isset($update_data['wishlist_name'])) {
            $wishlist = $this->get_wishlist($wishlist_id);
            if ($wishlist) {
                $update_data['wishlist_slug'] = $this->generate_wishlist_slug($update_data['wishlist_name'], $wishlist['user_id']);
                $update_format[] = '%s';
            }
        }

        if (empty($update_data)) {
            return new WP_Error('invalid_data', __('No valid fields to update', 'wish-cart'));
        }

        // If setting as default, unset other defaults
        if (isset($update_data['is_default']) && $update_data['is_default']) {
            $wishlist = $this->get_wishlist($wishlist_id);
            if ($wishlist) {
                if ($wishlist['user_id']) {
                    $this->wpdb->update(
                        $this->wishlists_table,
                        array('is_default' => 0),
                        array('user_id' => $wishlist['user_id']),
                        array('%d'),
                        array('%d')
                    );
                } else {
                    $this->wpdb->update(
                        $this->wishlists_table,
                        array('is_default' => 0),
                        array('session_id' => $wishlist['session_id']),
                        array('%d'),
                        array('%s')
                    );
                }
            }
        }

        $result = $this->wpdb->update(
            $this->wishlists_table,
            $update_data,
            array('id' => $wishlist_id),
            $update_format,
            array('%d')
        );

        if (false === $result) {
            return new WP_Error('db_error', __('Failed to update wishlist', 'wish-cart'));
        }

        // Log activity
        $this->log_activity($wishlist_id, 'updated', null, 'wishlist', wp_json_encode($data));

        return true;
    }

    /**
     * Delete wishlist
     *
     * @param int $wishlist_id Wishlist ID
     * @return bool|WP_Error
     */
    public function delete_wishlist($wishlist_id) {
        $wishlist = $this->get_wishlist($wishlist_id);
        if (!$wishlist) {
            return new WP_Error('not_found', __('Wishlist not found', 'wish-cart'));
        }

        // Don't allow deleting default wishlist
        if ($wishlist['is_default']) {
            return new WP_Error('cannot_delete_default', __('Cannot delete default wishlist', 'wish-cart'));
        }

        // Soft delete: update status to 'deleted'
        $result = $this->wpdb->update(
            $this->wishlists_table,
            array('status' => 'deleted'),
            array('id' => $wishlist_id),
            array('%s'),
            array('%d')
        );

        if (false === $result) {
            return new WP_Error('db_error', __('Failed to delete wishlist', 'wish-cart'));
        }

        // Log activity
        $this->log_activity($wishlist_id, 'deleted', null, 'wishlist');

        return true;
    }

    /**
     * Add product to wishlist
     *
     * @param int $product_id Product ID
     * @param int|null $user_id User ID (null for guests)
     * @param string|null $session_id Session ID (null for logged-in users)
     * @param int|null $wishlist_id Wishlist ID (null for default wishlist)
     * @param array $options Additional options (variation_id, variation_data, quantity, notes, custom_attributes)
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public function add_to_wishlist($product_id, $user_id = null, $session_id = null, $wishlist_id = null, $options = array()) {
        $product_id = intval($product_id);
        
        if ($product_id <= 0) {
            return new WP_Error('invalid_product', __('Invalid product ID', 'wish-cart'));
        }

        // Verify product exists
        $product = WISHCART_FluentCart_Helper::get_product($product_id);
        if (!$product) {
            return new WP_Error('product_not_found', __('Product not found', 'wish-cart'));
        }

        // Determine user_id or session_id
        if (is_user_logged_in()) {
            $user_id = get_current_user_id();
            $session_id = null;
        } else {
            if (empty($session_id)) {
                $session_id = $this->get_or_create_session_id();
            }
            $user_id = null;
        }

        // Get or create default wishlist if wishlist_id not provided
        if (empty($wishlist_id)) {
            $default_wishlist = $this->get_default_wishlist($user_id, $session_id);
            if ($default_wishlist) {
                $wishlist_id = $default_wishlist['id'];
            } else {
                return new WP_Error('no_wishlist', __('Could not find or create wishlist', 'wish-cart'));
            }
        }

        $variation_id = isset($options['variation_id']) ? intval($options['variation_id']) : 0;

        // Check if already in wishlist
        $exists = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->items_table} WHERE wishlist_id = %d AND product_id = %d AND variation_id = %d AND status = 'active'",
                $wishlist_id,
                $product_id,
                $variation_id
            )
        );

        if ($exists > 0) {
            return true; // Already added
        }

        // Get product price for tracking
        $original_price = $product->get_price();
        $original_currency = 'USD'; // TODO: Get from settings or WooCommerce
        $on_sale = $product->is_on_sale() ? 1 : 0;

        // Get highest position for ordering
        $max_position = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT MAX(position) FROM {$this->items_table} WHERE wishlist_id = %d",
                $wishlist_id
            )
        );
        $position = ($max_position !== null) ? $max_position + 1 : 0;

        $data = array(
            'wishlist_id' => $wishlist_id,
            'product_id' => $product_id,
            'variation_id' => $variation_id,
            'variation_data' => isset($options['variation_data']) ? wp_json_encode($options['variation_data']) : null,
            'quantity' => isset($options['quantity']) ? intval($options['quantity']) : 1,
            'position' => $position,
            'original_price' => $original_price,
            'original_currency' => $original_currency,
            'on_sale' => $on_sale,
            'notes' => isset($options['notes']) ? sanitize_textarea_field($options['notes']) : null,
            'user_id' => $user_id,
            'custom_attributes' => isset($options['custom_attributes']) ? wp_json_encode($options['custom_attributes']) : null,
            'status' => 'active',
        );

        $format = array('%d', '%d', '%d', '%s', '%d', '%d', '%f', '%s', '%d', '%s', '%d', '%s', '%s');

        $result = $this->wpdb->insert($this->items_table, $data, $format);

        if (false === $result) {
            return new WP_Error('db_error', __('Failed to add product to wishlist', 'wish-cart'));
        }

        // Log activity
        $this->log_activity($wishlist_id, 'added_item', $product_id, 'product');

        // Update analytics
        $this->update_analytics($product_id, $variation_id, 'add');

        // Clear cache
        $this->clear_wishlist_cache($user_id, $session_id);

        // Update guest user tracking if this is a guest
        if (!empty($session_id) && empty($user_id)) {
            $this->update_guest_tracking($session_id, $wishlist_id);
        }

        return true;
    }

    /**
     * Remove product from wishlist
     *
     * @param int $product_id Product ID
     * @param int|null $user_id User ID (null for guests)
     * @param string|null $session_id Session ID (null for logged-in users)
     * @param int|null $wishlist_id Wishlist ID (null for default wishlist)
     * @param int $variation_id Variation ID
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public function remove_from_wishlist($product_id, $user_id = null, $session_id = null, $wishlist_id = null, $variation_id = 0) {
        $product_id = intval($product_id);
        
        if ($product_id <= 0) {
            return new WP_Error('invalid_product', __('Invalid product ID', 'wish-cart'));
        }

        // Determine user_id or session_id
        if (is_user_logged_in()) {
            $user_id = get_current_user_id();
            $session_id = null;
        } else {
            if (empty($session_id)) {
                $session_id = $this->get_or_create_session_id();
            }
            $user_id = null;
        }

        // Get default wishlist if wishlist_id not provided
        if (empty($wishlist_id)) {
            $default_wishlist = $this->get_default_wishlist($user_id, $session_id);
            if ($default_wishlist) {
                $wishlist_id = $default_wishlist['id'];
            }
        }

        if (empty($wishlist_id)) {
            return new WP_Error('no_wishlist', __('Wishlist not found', 'wish-cart'));
        }

        // Delete from database
        $result = $this->wpdb->delete(
            $this->items_table,
            array(
                'wishlist_id' => $wishlist_id,
                'product_id' => $product_id,
                'variation_id' => $variation_id,
            ),
            array('%d', '%d', '%d')
        );

        if (false === $result) {
            return new WP_Error('db_error', __('Failed to remove product from wishlist', 'wish-cart'));
        }

        // Log activity
        $this->log_activity($wishlist_id, 'removed_item', $product_id, 'product');

        // Update analytics
        $this->update_analytics($product_id, $variation_id, 'remove');

        // Clear cache
        $this->clear_wishlist_cache($user_id, $session_id);

        return true;
    }

    /**
     * Get wishlist items
     *
     * @param int $wishlist_id Wishlist ID
     * @return array Array of items
     */
    public function get_wishlist_items($wishlist_id) {
        $items = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->items_table} WHERE wishlist_id = %d AND status = 'active' ORDER BY position ASC, date_added DESC",
                $wishlist_id
            ),
            ARRAY_A
        );

        return $items ? $items : array();
    }

    /**
     * Update wishlist item
     *
     * @param int $item_id Item ID
     * @param array $data Data to update
     * @return bool|WP_Error
     */
    public function update_wishlist_item($item_id, $data) {
        $allowed_fields = array('quantity', 'position', 'notes', 'custom_attributes');
        $update_data = array();
        $update_format = array();

        foreach ($allowed_fields as $field) {
            if (isset($data[$field])) {
                if ($field === 'quantity' || $field === 'position') {
                    $update_data[$field] = intval($data[$field]);
                    $update_format[] = '%d';
                } elseif ($field === 'notes') {
                    $update_data[$field] = sanitize_textarea_field($data[$field]);
                    $update_format[] = '%s';
                } elseif ($field === 'custom_attributes') {
                    $update_data[$field] = is_array($data[$field]) ? wp_json_encode($data[$field]) : $data[$field];
                    $update_format[] = '%s';
                }
            }
        }

        if (empty($update_data)) {
            return new WP_Error('invalid_data', __('No valid fields to update', 'wish-cart'));
        }

        $result = $this->wpdb->update(
            $this->items_table,
            $update_data,
            array('item_id' => $item_id),
            $update_format,
            array('%d')
        );

        if (false === $result) {
            return new WP_Error('db_error', __('Failed to update wishlist item', 'wish-cart'));
        }

        return true;
    }

    /**
     * Check if product is in wishlist
     *
     * @param int $product_id Product ID
     * @param int|null $user_id User ID (null for guests)
     * @param string|null $session_id Session ID (null for logged-in users)
     * @param int $variation_id Variation ID
     * @return bool True if in wishlist
     */
    public function is_in_wishlist($product_id, $user_id = null, $session_id = null, $variation_id = 0) {
        $product_id = intval($product_id);
        
        if ($product_id <= 0) {
            return false;
        }

        // Determine user_id or session_id
        if (is_user_logged_in()) {
            $user_id = get_current_user_id();
            $session_id = null;
        } else {
            if (empty($session_id)) {
                $session_id = $this->get_or_create_session_id();
            }
            $user_id = null;
        }

        // Get default wishlist
        $default_wishlist = $this->get_default_wishlist($user_id, $session_id);
        if (!$default_wishlist) {
            return false;
        }

        $count = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->items_table} WHERE wishlist_id = %d AND product_id = %d AND variation_id = %d AND status = 'active'",
                $default_wishlist['id'],
                $product_id,
                $variation_id
            )
        );

        return ($count > 0);
    }

    /**
     * Sync guest wishlist to user account on login
     *
     * @param string $session_id Guest session ID
     * @param int $user_id User ID
     * @return bool|WP_Error True on success
     */
    public function sync_guest_wishlist_to_user($session_id, $user_id) {
        if (empty($user_id)) {
            return new WP_Error('invalid_params', __('Invalid parameters', 'wish-cart'));
        }

        // Get guest's default wishlist
        $guest_wishlist = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->wishlists_table} WHERE session_id = %s AND is_default = 1 AND status = 'active'",
                $session_id
            ),
            ARRAY_A
        );

        if (!$guest_wishlist) {
            return true; // Nothing to sync
        }

        // Get user's default wishlist
        $user_wishlist = $this->get_default_wishlist($user_id, null);

        // Get guest wishlist items
        $guest_items = $this->get_wishlist_items($guest_wishlist['id']);

        // Get user's existing items to avoid duplicates
        $user_items = $this->get_wishlist_items($user_wishlist['id']);
        $existing_products = array();
        foreach ($user_items as $item) {
            $key = $item['product_id'] . '_' . $item['variation_id'];
            $existing_products[$key] = true;
        }

        // Add guest items to user wishlist
        foreach ($guest_items as $item) {
            $key = $item['product_id'] . '_' . $item['variation_id'];
            
            if (isset($existing_products[$key])) {
                continue; // Skip duplicates
            }

            $this->wpdb->insert(
                $this->items_table,
                array(
                    'wishlist_id' => $user_wishlist['id'],
                    'product_id' => $item['product_id'],
                    'variation_id' => $item['variation_id'],
                    'variation_data' => $item['variation_data'],
                    'quantity' => $item['quantity'],
                    'position' => $item['position'],
                    'original_price' => $item['original_price'],
                    'original_currency' => $item['original_currency'],
                    'on_sale' => $item['on_sale'],
                    'notes' => $item['notes'],
                    'user_id' => $user_id,
                    'custom_attributes' => $item['custom_attributes'],
                    'status' => 'active',
                ),
                array('%d', '%d', '%d', '%s', '%d', '%d', '%f', '%s', '%d', '%s', '%d', '%s', '%s')
            );
        }

        // Delete guest wishlist (soft delete)
        $this->wpdb->update(
            $this->wishlists_table,
            array('status' => 'deleted'),
            array('id' => $guest_wishlist['id']),
            array('%s'),
            array('%d')
        );

        // Clear caches
        $this->clear_wishlist_cache($user_id, null);
        $this->clear_wishlist_cache(null, $session_id);

        return true;
    }

    /**
     * Log activity
     *
     * @param int $wishlist_id Wishlist ID
     * @param string $activity_type Activity type
     * @param int|null $object_id Object ID
     * @param string|null $object_type Object type
     * @param string|null $activity_data Additional data
     * @return void
     */
    private function log_activity($wishlist_id, $activity_type, $object_id = null, $object_type = null, $activity_data = null) {
        // Use activity logger if available
        if (class_exists('WISHCART_Activity_Logger')) {
            $logger = new WISHCART_Activity_Logger();
            $logger->log($wishlist_id, $activity_type, $object_id, $object_type, $activity_data);
        }
    }

    /**
     * Update analytics
     *
     * @param int $product_id Product ID
     * @param int $variation_id Variation ID
     * @param string $action Action type (add, remove, view, cart, purchase)
     * @return void
     */
    private function update_analytics($product_id, $variation_id, $action) {
        // Use analytics handler if available
        if (class_exists('WISHCART_Analytics_Handler')) {
            $analytics = new WISHCART_Analytics_Handler();
            $analytics->track_event($product_id, $variation_id, $action);
        }
    }

    /**
     * Update guest user tracking
     * Creates or updates guest record in wp_fc_wishlist_guest_users table
     *
     * @param string $session_id Session ID
     * @param int $wishlist_id Wishlist ID to add to guest tracking
     * @return void
     */
    private function update_guest_tracking($session_id, $wishlist_id) {
        if (empty($session_id) || empty($wishlist_id)) {
            return;
        }

        // Use guest handler if available
        if (class_exists('WISHCART_Guest_Handler')) {
            $guest_handler = new WISHCART_Guest_Handler();
            
            // Create or update guest record
            $guest_handler->create_or_update_guest($session_id);
            
            // Add wishlist to guest's wishlist data
            $guest_handler->add_wishlist_to_guest($session_id, $wishlist_id);
        }
    }

    /**
     * Get cache key for wishlist
     *
     * @param int|null $user_id User ID
     * @param string|null $session_id Session ID
     * @return string Cache key
     */
    private function get_cache_key($user_id, $session_id) {
        if ($user_id) {
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
    private function clear_wishlist_cache($user_id, $session_id) {
        $cache_key = $this->get_cache_key($user_id, $session_id);
        wp_cache_delete($cache_key, 'wishcart_wishlist');
    }
}
