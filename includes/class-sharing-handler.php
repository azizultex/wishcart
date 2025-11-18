<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Sharing Handler Class
 *
 * Handles wishlist sharing across social media platforms
 *
 * @category WordPress
 * @package  WishCart
 * @author   WishCart Team <support@wishcart.chat>
 * @license  GPL-2.0+ https://www.gnu.org/licenses/gpl-2.0.html
 * @link     https://wishcart.chat
 */
class WISHCART_Sharing_Handler {

    private $wpdb;
    private $shares_table;
    private $wishlists_table;

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->shares_table = $wpdb->prefix . 'fc_wishlist_shares';
        $this->wishlists_table = $wpdb->prefix . 'fc_wishlists';
    }

    /**
     * Generate unique share token
     *
     * @return string
     */
    private function generate_share_token() {
        $max_attempts = 10;
        $attempt = 0;
        
        do {
            $token = bin2hex(random_bytes(32)); // 64 character hex string
            $exists = $this->wpdb->get_var(
                $this->wpdb->prepare(
                    "SELECT COUNT(*) FROM {$this->shares_table} WHERE share_token = %s",
                    $token
                )
            );
            $attempt++;
        } while ( $exists > 0 && $attempt < $max_attempts );
        
        if ( $attempt >= $max_attempts ) {
            // Fallback: use hash-based token
            $token = hash('sha256', uniqid('share_', true) . wp_rand());
        }
        
        return $token;
    }

    /**
     * Create share link for wishlist
     *
     * @param int $wishlist_id Wishlist ID
     * @param string $share_type Share type (link, email, facebook, twitter, etc.)
     * @param array $options Additional options (shared_with_email, share_message, expiration_days)
     * @return array|WP_Error Share data or error
     */
    public function create_share($wishlist_id, $share_type = 'link', $options = array()) {
        // Verify wishlist exists
        $wishlist = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->wishlists_table} WHERE id = %d AND status = 'active'",
                $wishlist_id
            ),
            ARRAY_A
        );

        if (!$wishlist) {
            return new WP_Error('wishlist_not_found', __('Wishlist not found', 'wish-cart'));
        }

        // Check if share already exists for this type
        $existing_share = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->shares_table} WHERE wishlist_id = %d AND share_type = %s AND status = 'active'",
                $wishlist_id,
                $share_type
            ),
            ARRAY_A
        );

        if ($existing_share) {
            // Update click count
            $this->track_share_click($existing_share['share_id']);
            return $existing_share;
        }

        $share_token = $this->generate_share_token();
        $user_id = is_user_logged_in() ? get_current_user_id() : null;

        // Calculate expiration date
        $date_expires = null;
        if (isset($options['expiration_days']) && $options['expiration_days'] > 0) {
            $date_expires = date('Y-m-d H:i:s', strtotime('+' . intval($options['expiration_days']) . ' days'));
        }

        $data = array(
            'wishlist_id' => $wishlist_id,
            'share_token' => $share_token,
            'share_type' => $share_type,
            'shared_by_user_id' => $user_id,
            'shared_with_email' => isset($options['shared_with_email']) ? sanitize_email($options['shared_with_email']) : null,
            'share_key' => isset($options['share_key']) ? sanitize_text_field($options['share_key']) : null,
            'share_title' => isset($options['share_title']) ? sanitize_text_field($options['share_title']) : $wishlist['wishlist_name'],
            'share_message' => isset($options['share_message']) ? sanitize_textarea_field($options['share_message']) : null,
            'date_expires' => $date_expires,
            'status' => 'active',
        );

        $format = array('%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s');

        $result = $this->wpdb->insert($this->shares_table, $data, $format);

        if (false === $result) {
            return new WP_Error('db_error', __('Failed to create share', 'wish-cart'));
        }

        $share_id = $this->wpdb->insert_id;

        // Update analytics
        if (class_exists('WISHCART_Analytics_Handler')) {
            // Track share for all products in wishlist
            $items_table = $this->wpdb->prefix . 'fc_wishlist_items';
            $items = $this->wpdb->get_results(
                $this->wpdb->prepare(
                    "SELECT product_id, variation_id FROM {$items_table} WHERE wishlist_id = %d AND status = 'active'",
                    $wishlist_id
                ),
                ARRAY_A
            );

            $analytics = new WISHCART_Analytics_Handler();
            foreach ($items as $item) {
                $analytics->track_event($item['product_id'], $item['variation_id'], 'share');
            }
        }

        // Log activity
        if (class_exists('WISHCART_Activity_Logger')) {
            $logger = new WISHCART_Activity_Logger();
            $logger->log($wishlist_id, 'shared', $share_id, 'share', wp_json_encode(array('share_type' => $share_type)));
        }

        return $this->get_share($share_id);
    }

    /**
     * Get share by ID
     *
     * @param int $share_id Share ID
     * @return array|null Share data or null
     */
    public function get_share($share_id) {
        return $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->shares_table} WHERE share_id = %d AND status = 'active'",
                $share_id
            ),
            ARRAY_A
        );
    }

    /**
     * Get share by token
     *
     * @param string $share_token Share token
     * @return array|null Share data or null
     */
    public function get_share_by_token($share_token) {
        $share = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->shares_table} WHERE share_token = %s AND status = 'active'",
                $share_token
            ),
            ARRAY_A
        );

        // Check if expired
        if ($share && $share['date_expires']) {
            $expiry = strtotime($share['date_expires']);
            if ($expiry < time()) {
                return null; // Expired
            }
        }

        return $share;
    }

    /**
     * Get all shares for wishlist
     *
     * @param int $wishlist_id Wishlist ID
     * @return array Array of shares
     */
    public function get_wishlist_shares($wishlist_id) {
        $shares = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->shares_table} WHERE wishlist_id = %d AND status = 'active' ORDER BY date_created DESC",
                $wishlist_id
            ),
            ARRAY_A
        );

        return $shares ? $shares : array();
    }

    /**
     * Track share click
     *
     * @param int $share_id Share ID
     * @return bool Success
     */
    public function track_share_click($share_id) {
        $result = $this->wpdb->query(
            $this->wpdb->prepare(
                "UPDATE {$this->shares_table} 
                SET click_count = click_count + 1,
                    last_clicked = NOW()
                WHERE share_id = %d",
                $share_id
            )
        );

        return $result !== false;
    }

    /**
     * Track share conversion (when someone adds item to cart from shared wishlist)
     *
     * @param int $share_id Share ID
     * @return bool Success
     */
    public function track_share_conversion($share_id) {
        $result = $this->wpdb->query(
            $this->wpdb->prepare(
                "UPDATE {$this->shares_table} 
                SET conversion_count = conversion_count + 1
                WHERE share_id = %d",
                $share_id
            )
        );

        return $result !== false;
    }

    /**
     * Get share URL
     *
     * @param string $share_token Share token
     * @param string $share_type Share type
     * @return string Share URL
     */
    public function get_share_url($share_token, $share_type = 'link') {
        // Use the new shared wishlist page URL with query parameter
        $base_url = WISHCART_Shared_Wishlist_Page::get_share_url($share_token);
        
        switch ($share_type) {
            case 'facebook':
                return 'https://www.facebook.com/sharer/sharer.php?u=' . urlencode($base_url);
                
            case 'twitter':
                $text = __('Check out my wishlist!', 'wish-cart');
                return 'https://twitter.com/intent/tweet?url=' . urlencode($base_url) . '&text=' . urlencode($text);
                
            case 'pinterest':
                $description = __('My Wishlist', 'wish-cart');
                return 'https://pinterest.com/pin/create/button/?url=' . urlencode($base_url) . '&description=' . urlencode($description);
                
            case 'whatsapp':
                $text = __('Check out my wishlist:', 'wish-cart') . ' ' . $base_url;
                return 'https://wa.me/?text=' . urlencode($text);
                
            case 'instagram':
                // Instagram doesn't support direct sharing, return base URL
                return $base_url;
                
            case 'email':
                $subject = __('Check out my wishlist', 'wish-cart');
                $body = __('I wanted to share my wishlist with you:', 'wish-cart') . "\n\n" . $base_url;
                return 'mailto:?subject=' . rawurlencode($subject) . '&body=' . rawurlencode($body);
                
            default:
                return $base_url;
        }
    }

    /**
     * Get share statistics
     *
     * @param int $wishlist_id Wishlist ID
     * @return array Statistics
     */
    public function get_share_statistics($wishlist_id) {
        $stats = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT 
                    COUNT(*) as total_shares,
                    SUM(click_count) as total_clicks,
                    SUM(conversion_count) as total_conversions,
                    AVG(click_count) as avg_clicks_per_share
                FROM {$this->shares_table}
                WHERE wishlist_id = %d AND status = 'active'",
                $wishlist_id
            ),
            ARRAY_A
        );

        // Get shares by type
        $shares_by_type = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT 
                    share_type,
                    COUNT(*) as count,
                    SUM(click_count) as clicks,
                    SUM(conversion_count) as conversions
                FROM {$this->shares_table}
                WHERE wishlist_id = %d AND status = 'active'
                GROUP BY share_type",
                $wishlist_id
            ),
            ARRAY_A
        );

        $stats['shares_by_type'] = $shares_by_type ? $shares_by_type : array();

        return $stats;
    }

    /**
     * Delete share
     *
     * @param int $share_id Share ID
     * @return bool|WP_Error
     */
    public function delete_share($share_id) {
        // Soft delete: update status to 'deleted'
        $result = $this->wpdb->update(
            $this->shares_table,
            array('status' => 'deleted'),
            array('share_id' => $share_id),
            array('%s'),
            array('%d')
        );

        if (false === $result) {
            return new WP_Error('db_error', __('Failed to delete share', 'wish-cart'));
        }

        return true;
    }

    /**
     * Clean up expired shares
     *
     * @return array Results
     */
    public function cleanup_expired_shares() {
        $results = array(
            'success' => true,
            'deleted' => 0,
        );

        $result = $this->wpdb->query(
            "UPDATE {$this->shares_table} 
            SET status = 'expired'
            WHERE date_expires IS NOT NULL 
                AND date_expires < NOW()
                AND status = 'active'"
        );

        $results['deleted'] = $result !== false ? $result : 0;

        return $results;
    }

    /**
     * Send share via email
     *
     * @param int $share_id Share ID
     * @param string $to_email Recipient email
     * @param string $message Optional personal message
     * @return bool|WP_Error Success or error
     */
    public function send_share_email($share_id, $to_email, $message = '') {
        $share = $this->get_share($share_id);
        if (!$share) {
            return new WP_Error('share_not_found', __('Share not found', 'wish-cart'));
        }

        // Get wishlist
        $wishlist = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->wishlists_table} WHERE id = %d",
                $share['wishlist_id']
            ),
            ARRAY_A
        );

        if (!$wishlist) {
            return new WP_Error('wishlist_not_found', __('Wishlist not found', 'wish-cart'));
        }

        $share_url = $this->get_share_url($share['share_token'], 'link');
        $subject = sprintf(__('%s shared a wishlist with you', 'wish-cart'), get_bloginfo('name'));

        // Build email content
        $email_content = sprintf(__('Hello,%s%sYou have received a shared wishlist from %s.', 'wish-cart'), "\n\n", "\n\n", get_bloginfo('name'));
        
        if (!empty($message)) {
            $email_content .= "\n\n" . __('Personal message:', 'wish-cart') . "\n" . sanitize_textarea_field($message);
        }

        $email_content .= "\n\n" . sprintf(__('Wishlist name: %s', 'wish-cart'), $wishlist['wishlist_name']);
        $email_content .= "\n\n" . __('View the wishlist here:', 'wish-cart') . "\n" . $share_url;

        // Send email
        $result = wp_mail(
            $to_email,
            $subject,
            $email_content,
            array('Content-Type: text/plain; charset=UTF-8')
        );

        if ($result) {
            // Update share record
            $this->wpdb->update(
                $this->shares_table,
                array('shared_with_email' => $to_email),
                array('share_id' => $share_id),
                array('%s'),
                array('%d')
            );

            return true;
        }

        return new WP_Error('email_failed', __('Failed to send email', 'wish-cart'));
    }
}

