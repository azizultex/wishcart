<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Guest User Handler Class
 *
 * Manages guest user sessions and conversion tracking
 *
 * @category WordPress
 * @package  WishCart
 * @author   WishCart Team <support@wishcart.chat>
 * @license  GPL-2.0+ https://www.gnu.org/licenses/gpl-2.0.html
 * @link     https://wishcart.chat
 */
class WISHCART_Guest_Handler {

    private $wpdb;
    private $guest_users_table;

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->guest_users_table = $wpdb->prefix . 'fc_wishlist_guest_users';
    }

    /**
     * Create or update guest user session
     *
     * @param string $session_id Session ID
     * @param array $data Guest data (email, name, wishlist_data)
     * @return int|WP_Error Guest ID or error
     */
    public function create_or_update_guest($session_id, $data = array()) {
        if (empty($session_id)) {
            return new WP_Error('invalid_session', __('Invalid session ID', 'wish-cart'));
        }

        // Check if guest exists
        $existing_guest = $this->get_guest_by_session($session_id);

        // Get expiration date
        $settings = get_option('wishcart_settings', array());
        $expiry_days = isset($settings['wishlist']['guest_cookie_expiry']) ? intval($settings['wishlist']['guest_cookie_expiry']) : 30;
        $date_expires = date('Y-m-d H:i:s', strtotime('+' . $expiry_days . ' days'));

        // Get IP address
        $ip_address = $this->get_client_ip();

        // Get user agent
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])), 0, 500) : null;

        if ($existing_guest) {
            // Update existing guest
            $update_data = array(
                'last_activity' => current_time('mysql'),
                'date_expires' => $date_expires,
            );

            if (isset($data['guest_email'])) {
                $update_data['guest_email'] = sanitize_email($data['guest_email']);
            }

            if (isset($data['guest_name'])) {
                $update_data['guest_name'] = sanitize_text_field($data['guest_name']);
            }

            if (isset($data['wishlist_data'])) {
                $update_data['wishlist_data'] = is_array($data['wishlist_data']) ? wp_json_encode($data['wishlist_data']) : $data['wishlist_data'];
            }

            $result = $this->wpdb->update(
                $this->guest_users_table,
                $update_data,
                array('session_id' => $session_id),
                array('%s', '%s', '%s', '%s', '%s'),
                array('%s')
            );

            if (false === $result) {
                return new WP_Error('db_error', __('Failed to update guest user', 'wish-cart'));
            }

            return $existing_guest['guest_id'];
        } else {
            // Create new guest
            $insert_data = array(
                'session_id' => $session_id,
                'guest_email' => isset($data['guest_email']) ? sanitize_email($data['guest_email']) : null,
                'guest_name' => isset($data['guest_name']) ? sanitize_text_field($data['guest_name']) : null,
                'ip_address' => $ip_address,
                'user_agent' => $user_agent,
                'wishlist_data' => isset($data['wishlist_data']) ? wp_json_encode($data['wishlist_data']) : null,
                'date_expires' => $date_expires,
                'last_activity' => current_time('mysql'),
            );

            $format = array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s');

            $result = $this->wpdb->insert($this->guest_users_table, $insert_data, $format);

            if (false === $result) {
                return new WP_Error('db_error', __('Failed to create guest user', 'wish-cart'));
            }

            return $this->wpdb->insert_id;
        }
    }

    /**
     * Get client IP address
     *
     * @return string|null
     */
    private function get_client_ip() {
        $ip = null;

        if (isset($_SERVER['HTTP_CLIENT_IP']) && !empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (isset($_SERVER['HTTP_X_FORWARDED_FOR']) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } elseif (isset($_SERVER['REMOTE_ADDR']) && !empty($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }

        if ($ip) {
            $ip = sanitize_text_field(wp_unslash($ip));
            $ip = substr($ip, 0, 45);
        }

        return $ip;
    }

    /**
     * Get guest user by session ID
     *
     * @param string $session_id Session ID
     * @return array|null Guest data or null
     */
    public function get_guest_by_session($session_id) {
        return $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->guest_users_table} WHERE session_id = %s",
                $session_id
            ),
            ARRAY_A
        );
    }

    /**
     * Get guest user by ID
     *
     * @param int $guest_id Guest ID
     * @return array|null Guest data or null
     */
    public function get_guest($guest_id) {
        return $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->guest_users_table} WHERE guest_id = %d",
                $guest_id
            ),
            ARRAY_A
        );
    }

    /**
     * Get guest user by email
     *
     * @param string $email Email address
     * @return array|null Guest data or null
     */
    public function get_guest_by_email($email) {
        return $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->guest_users_table} WHERE guest_email = %s ORDER BY date_created DESC LIMIT 1",
                $email
            ),
            ARRAY_A
        );
    }

    /**
     * Convert guest to registered user
     *
     * @param string $session_id Guest session ID
     * @param int $user_id New user ID
     * @return bool|WP_Error Success or error
     */
    public function convert_guest_to_user($session_id, $user_id) {
        if (empty($session_id) || empty($user_id)) {
            return new WP_Error('invalid_params', __('Invalid parameters', 'wish-cart'));
        }

        // Get guest data
        $guest = $this->get_guest_by_session($session_id);
        if (!$guest) {
            return new WP_Error('guest_not_found', __('Guest user not found', 'wish-cart'));
        }

        // Update guest record with conversion data
        $result = $this->wpdb->update(
            $this->guest_users_table,
            array('conversion_user_id' => $user_id),
            array('session_id' => $session_id),
            array('%d'),
            array('%s')
        );

        if (false === $result) {
            return new WP_Error('db_error', __('Failed to record conversion', 'wish-cart'));
        }

        // Sync wishlists
        if (class_exists('WISHCART_Wishlist_Handler')) {
            $wishlist_handler = new WISHCART_Wishlist_Handler();
            $sync_result = $wishlist_handler->sync_guest_wishlist_to_user($session_id, $user_id);

            if (is_wp_error($sync_result)) {
                return $sync_result;
            }
        }

        // Update wishlists to use user_id instead of session_id
        $wishlists_table = $this->wpdb->prefix . 'fc_wishlists';
        $this->wpdb->update(
            $wishlists_table,
            array(
                'user_id' => $user_id,
                'session_id' => null,
            ),
            array('session_id' => $session_id),
            array('%d', '%s'),
            array('%s')
        );

        return true;
    }

    /**
     * Update last activity timestamp
     *
     * @param string $session_id Session ID
     * @return bool Success
     */
    public function update_activity($session_id) {
        $result = $this->wpdb->update(
            $this->guest_users_table,
            array('last_activity' => current_time('mysql')),
            array('session_id' => $session_id),
            array('%s'),
            array('%s')
        );

        return $result !== false;
    }

    /**
     * Get guest conversion statistics
     *
     * @return array Statistics
     */
    public function get_conversion_statistics() {
        $stats = $this->wpdb->get_row(
            "SELECT 
                COUNT(*) as total_guests,
                SUM(CASE WHEN conversion_user_id IS NOT NULL THEN 1 ELSE 0 END) as converted_count,
                AVG(CASE WHEN conversion_user_id IS NOT NULL 
                    THEN TIMESTAMPDIFF(HOUR, date_created, NOW()) 
                    ELSE NULL END) as avg_hours_to_conversion
            FROM {$this->guest_users_table}",
            ARRAY_A
        );

        $total_guests = intval($stats['total_guests']);
        $converted_count = intval($stats['converted_count']);

        return array(
            'total_guests' => $total_guests,
            'converted_count' => $converted_count,
            'conversion_rate' => $total_guests > 0 ? round(($converted_count / $total_guests) * 100, 2) : 0,
            'avg_hours_to_conversion' => floatval($stats['avg_hours_to_conversion']),
        );
    }

    /**
     * Get active guests (not expired, not converted)
     *
     * @param int $limit Number of guests to return
     * @return array Array of guests
     */
    public function get_active_guests($limit = 100) {
        $guests = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->guest_users_table}
                WHERE conversion_user_id IS NULL
                    AND (date_expires IS NULL OR date_expires > NOW())
                ORDER BY last_activity DESC
                LIMIT %d",
                $limit
            ),
            ARRAY_A
        );

        return $guests ? $guests : array();
    }

    /**
     * Get converted guests
     *
     * @param int $days Number of days to look back
     * @param int $limit Number of guests to return
     * @return array Array of converted guests
     */
    public function get_converted_guests($days = 30, $limit = 100) {
        $guests = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->guest_users_table}
                WHERE conversion_user_id IS NOT NULL
                    AND date_created >= DATE_SUB(NOW(), INTERVAL %d DAY)
                ORDER BY date_created DESC
                LIMIT %d",
                $days,
                $limit
            ),
            ARRAY_A
        );

        return $guests ? $guests : array();
    }

    /**
     * Clean up expired guest sessions
     *
     * @param bool $delete_data If true, delete guest data; if false, just mark as expired
     * @return array Results
     */
    public function cleanup_expired_sessions($delete_data = false) {
        $results = array(
            'success' => true,
            'processed' => 0,
        );

        if ($delete_data) {
            // Delete expired guest records and their wishlists
            $expired_sessions = $this->wpdb->get_col(
                "SELECT session_id FROM {$this->guest_users_table}
                WHERE date_expires < NOW()
                    AND conversion_user_id IS NULL"
            );

            foreach ($expired_sessions as $session_id) {
                // Delete guest wishlists
                $wishlists_table = $this->wpdb->prefix . 'fc_wishlists';
                $this->wpdb->update(
                    $wishlists_table,
                    array('status' => 'deleted'),
                    array('session_id' => $session_id),
                    array('%s'),
                    array('%s')
                );
            }

            // Delete guest records
            $result = $this->wpdb->query(
                "DELETE FROM {$this->guest_users_table}
                WHERE date_expires < NOW()
                    AND conversion_user_id IS NULL"
            );

            $results['processed'] = $result !== false ? $result : 0;
        } else {
            // Just update last_activity to mark as inactive
            $result = $this->wpdb->update(
                $this->guest_users_table,
                array('last_activity' => '2000-01-01 00:00:00'), // Mark as very old
                array('date_expires <' => current_time('mysql')),
                array('%s'),
                array()
            );

            $results['processed'] = $result !== false ? $result : 0;
        }

        return $results;
    }

    /**
     * Anonymize guest data (GDPR compliance)
     *
     * @param int $days Anonymize guests older than X days
     * @return array Results
     */
    public function anonymize_old_guests($days = 90) {
        $results = array(
            'success' => true,
            'anonymized' => 0,
        );

        // Anonymize guest data (remove email, name, IP, user agent)
        $result = $this->wpdb->query(
            $this->wpdb->prepare(
                "UPDATE {$this->guest_users_table}
                SET guest_email = NULL,
                    guest_name = NULL,
                    ip_address = NULL,
                    user_agent = NULL
                WHERE date_created < DATE_SUB(NOW(), INTERVAL %d DAY)
                    AND guest_email IS NOT NULL",
                $days
            )
        );

        $results['anonymized'] = $result !== false ? $result : 0;

        return $results;
    }

    /**
     * Get guest engagement metrics
     *
     * @param int $days Number of days to look back
     * @return array Metrics
     */
    public function get_engagement_metrics($days = 30) {
        $metrics = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT 
                    COUNT(DISTINCT session_id) as unique_guests,
                    AVG(TIMESTAMPDIFF(MINUTE, date_created, last_activity)) as avg_session_duration_minutes,
                    COUNT(DISTINCT CASE WHEN wishlist_data IS NOT NULL THEN session_id END) as guests_with_items
                FROM {$this->guest_users_table}
                WHERE date_created >= DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days
            ),
            ARRAY_A
        );

        // Get wishlist statistics for guests
        $wishlists_table = $this->wpdb->prefix . 'fc_wishlists';
        $items_table = $this->wpdb->prefix . 'fc_wishlist_items';

        $wishlist_stats = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT 
                    COUNT(DISTINCT w.id) as guest_wishlists,
                    COUNT(wi.item_id) as total_items,
                    AVG(item_counts.item_count) as avg_items_per_wishlist
                FROM {$wishlists_table} w
                LEFT JOIN {$items_table} wi ON w.id = wi.wishlist_id
                LEFT JOIN (
                    SELECT wishlist_id, COUNT(*) as item_count
                    FROM {$items_table}
                    WHERE status = 'active'
                    GROUP BY wishlist_id
                ) item_counts ON w.id = item_counts.wishlist_id
                WHERE w.session_id IS NOT NULL
                    AND w.user_id IS NULL
                    AND w.status = 'active'
                    AND w.dateadded >= DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days
            ),
            ARRAY_A
        );

        return array(
            'unique_guests' => intval($metrics['unique_guests']),
            'avg_session_duration_minutes' => round(floatval($metrics['avg_session_duration_minutes']), 2),
            'guests_with_items' => intval($metrics['guests_with_items']),
            'guest_wishlists' => intval($wishlist_stats['guest_wishlists']),
            'total_items' => intval($wishlist_stats['total_items']),
            'avg_items_per_wishlist' => round(floatval($wishlist_stats['avg_items_per_wishlist']), 2),
        );
    }

    /**
     * Export guest data (for user request)
     *
     * @param string $session_id Session ID
     * @return array Guest data
     */
    public function export_guest_data($session_id) {
        $guest = $this->get_guest_by_session($session_id);
        
        if (!$guest) {
            return array();
        }

        // Remove sensitive internal fields
        unset($guest['guest_id']);
        unset($guest['ip_address']);
        unset($guest['user_agent']);

        // Parse wishlist_data if it's JSON
        if ($guest['wishlist_data']) {
            $guest['wishlist_data'] = json_decode($guest['wishlist_data'], true);
        }

        return $guest;
    }

    /**
     * Delete guest data (GDPR right to deletion)
     *
     * @param string $session_id Session ID
     * @return bool|WP_Error Success or error
     */
    public function delete_guest_data($session_id) {
        // Delete guest wishlists
        $wishlists_table = $this->wpdb->prefix . 'fc_wishlists';
        $this->wpdb->update(
            $wishlists_table,
            array('status' => 'deleted'),
            array('session_id' => $session_id),
            array('%s'),
            array('%s')
        );

        // Delete guest record
        $result = $this->wpdb->delete(
            $this->guest_users_table,
            array('session_id' => $session_id),
            array('%s')
        );

        if (false === $result) {
            return new WP_Error('db_error', __('Failed to delete guest data', 'wish-cart'));
        }

        return true;
    }
}

