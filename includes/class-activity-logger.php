<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Activity Logger Class
 *
 * Logs all wishlist activities for audit trails and user history
 *
 * @category WordPress
 * @package  WishCart
 * @author   WishCart Team <support@wishcart.chat>
 * @license  GPL-2.0+ https://www.gnu.org/licenses/gpl-2.0.html
 * @link     https://wishcart.chat
 */
class WISHCART_Activity_Logger {

    private $wpdb;
    private $activities_table;

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->activities_table = $wpdb->prefix . 'fc_wishlist_activities';
    }

    /**
     * Log activity
     *
     * @param int $wishlist_id Wishlist ID
     * @param string $activity_type Activity type
     * @param int|null $object_id Object ID (product_id, share_id, etc.)
     * @param string|null $object_type Object type (product, share, wishlist, etc.)
     * @param string|null $activity_data Additional data as JSON string
     * @return int|WP_Error Activity ID or error
     */
    public function log($wishlist_id, $activity_type, $object_id = null, $object_type = null, $activity_data = null) {
        $valid_types = array(
            'created', 'added_item', 'removed_item', 'moved_item', 
            'shared', 'viewed', 'renamed', 'deleted', 'purchased', 'updated'
        );

        if (!in_array($activity_type, $valid_types)) {
            return new WP_Error('invalid_type', __('Invalid activity type', 'wish-cart'));
        }

        $user_id = is_user_logged_in() ? get_current_user_id() : null;
        $session_id = null;

        if (!$user_id) {
            // Get session ID for guests
            $cookie_name = 'wishcart_session_id';
            if (isset($_COOKIE[$cookie_name])) {
                $session_id = sanitize_text_field(wp_unslash($_COOKIE[$cookie_name]));
            }
        }

        // Get IP address
        $ip_address = $this->get_client_ip();

        // Get user agent
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])), 0, 500) : null;

        // Get referrer
        $referrer_url = isset($_SERVER['HTTP_REFERER']) ? esc_url_raw(wp_unslash($_SERVER['HTTP_REFERER'])) : null;

        $data = array(
            'wishlist_id' => $wishlist_id,
            'user_id' => $user_id,
            'session_id' => $session_id,
            'activity_type' => $activity_type,
            'object_id' => $object_id,
            'object_type' => $object_type,
            'activity_data' => $activity_data,
            'ip_address' => $ip_address,
            'user_agent' => $user_agent,
            'referrer_url' => $referrer_url,
        );

        $format = array('%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s');

        $result = $this->wpdb->insert($this->activities_table, $data, $format);

        if (false === $result) {
            return new WP_Error('db_error', __('Failed to log activity', 'wish-cart'));
        }

        return $this->wpdb->insert_id;
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
            // For IPv4, limit to 45 characters (IPv6 max length)
            $ip = substr($ip, 0, 45);
        }

        return $ip;
    }

    /**
     * Get wishlist activity history
     *
     * @param int $wishlist_id Wishlist ID
     * @param int $limit Number of activities to return
     * @param int $offset Offset for pagination
     * @return array Array of activities
     */
    public function get_wishlist_activities($wishlist_id, $limit = 50, $offset = 0) {
        $activities = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->activities_table}
                WHERE wishlist_id = %d
                ORDER BY date_created DESC
                LIMIT %d OFFSET %d",
                $wishlist_id,
                $limit,
                $offset
            ),
            ARRAY_A
        );

        // Enrich activities with additional data
        $enriched_activities = array();
        foreach ($activities as $activity) {
            $enriched = $activity;

            // Add user name if user_id exists
            if ($activity['user_id']) {
                $user = get_userdata($activity['user_id']);
                $enriched['user_name'] = $user ? $user->display_name : __('Unknown User', 'wish-cart');
            } else {
                $enriched['user_name'] = __('Guest', 'wish-cart');
            }

            // Add product name if object_type is product
            if ($activity['object_type'] === 'product' && $activity['object_id']) {
                $product = WISHCART_FluentCart_Helper::get_product($activity['object_id']);
                $enriched['product_name'] = $product ? $product->get_name() : __('Unknown Product', 'wish-cart');
            }

            // Parse activity_data if it's JSON
            if ($activity['activity_data']) {
                $parsed_data = json_decode($activity['activity_data'], true);
                if (is_array($parsed_data)) {
                    $enriched['parsed_data'] = $parsed_data;
                }
            }

            $enriched_activities[] = $enriched;
        }

        return $enriched_activities;
    }

    /**
     * Get user's activity history across all wishlists
     *
     * @param int $user_id User ID
     * @param int $limit Number of activities to return
     * @param int $offset Offset for pagination
     * @return array Array of activities
     */
    public function get_user_activities($user_id, $limit = 50, $offset = 0) {
        $activities = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->activities_table}
                WHERE user_id = %d
                ORDER BY date_created DESC
                LIMIT %d OFFSET %d",
                $user_id,
                $limit,
                $offset
            ),
            ARRAY_A
        );

        return $this->enrich_activities($activities);
    }

    /**
     * Get recent activities (for admin dashboard)
     *
     * @param int $limit Number of activities to return
     * @param string $activity_type Filter by activity type
     * @return array Array of activities
     */
    public function get_recent_activities($limit = 20, $activity_type = null) {
        $where = '';
        $params = array();

        if ($activity_type) {
            $where = ' WHERE activity_type = %s';
            $params[] = $activity_type;
        }

        $params[] = $limit;

        $activities = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->activities_table}
                {$where}
                ORDER BY date_created DESC
                LIMIT %d",
                ...$params
            ),
            ARRAY_A
        );

        return $this->enrich_activities($activities);
    }

    /**
     * Enrich activities with additional data
     *
     * @param array $activities Array of activities
     * @return array Enriched activities
     */
    private function enrich_activities($activities) {
        $enriched_activities = array();

        foreach ($activities as $activity) {
            $enriched = $activity;

            // Add user name
            if ($activity['user_id']) {
                $user = get_userdata($activity['user_id']);
                $enriched['user_name'] = $user ? $user->display_name : __('Unknown User', 'wish-cart');
            } else {
                $enriched['user_name'] = __('Guest', 'wish-cart');
            }

            // Add wishlist name
            if ($activity['wishlist_id']) {
                $wishlists_table = $this->wpdb->prefix . 'fc_wishlists';
                $wishlist = $this->wpdb->get_row(
                    $this->wpdb->prepare(
                        "SELECT wishlist_name FROM {$wishlists_table} WHERE id = %d",
                        $activity['wishlist_id']
                    ),
                    ARRAY_A
                );
                $enriched['wishlist_name'] = $wishlist ? $wishlist['wishlist_name'] : __('Unknown Wishlist', 'wish-cart');
            }

            // Add object name based on type
            if ($activity['object_type'] === 'product' && $activity['object_id']) {
                $product = WISHCART_FluentCart_Helper::get_product($activity['object_id']);
                $enriched['object_name'] = $product ? $product->get_name() : __('Unknown Product', 'wish-cart');
            }

            // Parse activity_data if it's JSON
            if ($activity['activity_data']) {
                $parsed_data = json_decode($activity['activity_data'], true);
                if (is_array($parsed_data)) {
                    $enriched['parsed_data'] = $parsed_data;
                }
            }

            $enriched_activities[] = $enriched;
        }

        return $enriched_activities;
    }

    /**
     * Get activity statistics
     *
     * @param int|null $wishlist_id Wishlist ID (null for all wishlists)
     * @param int|null $user_id User ID (null for all users)
     * @return array Statistics
     */
    public function get_statistics($wishlist_id = null, $user_id = null) {
        $where_clauses = array();
        $params = array();

        if ($wishlist_id) {
            $where_clauses[] = 'wishlist_id = %d';
            $params[] = $wishlist_id;
        }

        if ($user_id) {
            $where_clauses[] = 'user_id = %d';
            $params[] = $user_id;
        }

        $where = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

        $query = "SELECT 
            COUNT(*) as total_activities,
            SUM(CASE WHEN activity_type = 'created' THEN 1 ELSE 0 END) as created_count,
            SUM(CASE WHEN activity_type = 'added_item' THEN 1 ELSE 0 END) as added_count,
            SUM(CASE WHEN activity_type = 'removed_item' THEN 1 ELSE 0 END) as removed_count,
            SUM(CASE WHEN activity_type = 'shared' THEN 1 ELSE 0 END) as shared_count,
            SUM(CASE WHEN activity_type = 'viewed' THEN 1 ELSE 0 END) as viewed_count,
            SUM(CASE WHEN activity_type = 'purchased' THEN 1 ELSE 0 END) as purchased_count
        FROM {$this->activities_table}
        {$where}";

        if (!empty($params)) {
            $stats = $this->wpdb->get_row(
                $this->wpdb->prepare($query, ...$params),
                ARRAY_A
            );
        } else {
            $stats = $this->wpdb->get_row($query, ARRAY_A);
        }

        return $stats ? $stats : array(
            'total_activities' => 0,
            'created_count' => 0,
            'added_count' => 0,
            'removed_count' => 0,
            'shared_count' => 0,
            'viewed_count' => 0,
            'purchased_count' => 0,
        );
    }

    /**
     * Get activity timeline (grouped by date)
     *
     * @param int $days Number of days to look back
     * @param int|null $wishlist_id Filter by wishlist ID
     * @return array Timeline data
     */
    public function get_timeline($days = 30, $wishlist_id = null) {
        $where = '';
        $params = array($days);

        if ($wishlist_id) {
            $where = ' AND wishlist_id = %d';
            $params[] = $wishlist_id;
        }

        $query = "SELECT 
            DATE(date_created) as activity_date,
            activity_type,
            COUNT(*) as count
        FROM {$this->activities_table}
        WHERE date_created >= DATE_SUB(NOW(), INTERVAL %d DAY)
        {$where}
        GROUP BY DATE(date_created), activity_type
        ORDER BY activity_date DESC, activity_type";

        $results = $this->wpdb->get_results(
            $this->wpdb->prepare($query, ...$params),
            ARRAY_A
        );

        // Group by date
        $timeline = array();
        foreach ($results as $row) {
            $date = $row['activity_date'];
            if (!isset($timeline[$date])) {
                $timeline[$date] = array(
                    'date' => $date,
                    'activities' => array(),
                    'total' => 0,
                );
            }

            $timeline[$date]['activities'][$row['activity_type']] = intval($row['count']);
            $timeline[$date]['total'] += intval($row['count']);
        }

        return array_values($timeline);
    }

    /**
     * Clean up old activities (GDPR compliance)
     *
     * @param int $days Delete activities older than X days
     * @param bool $anonymize If true, anonymize instead of delete (remove IP, user agent)
     * @return array Results
     */
    public function cleanup_old_activities($days = 365, $anonymize = true) {
        $results = array(
            'success' => true,
            'processed' => 0,
        );

        if ($anonymize) {
            // Anonymize old activities (remove personal data)
            $result = $this->wpdb->query(
                $this->wpdb->prepare(
                    "UPDATE {$this->activities_table}
                    SET ip_address = NULL,
                        user_agent = NULL,
                        referrer_url = NULL
                    WHERE date_created < DATE_SUB(NOW(), INTERVAL %d DAY)
                        AND ip_address IS NOT NULL",
                    $days
                )
            );
        } else {
            // Delete old activities
            $result = $this->wpdb->query(
                $this->wpdb->prepare(
                    "DELETE FROM {$this->activities_table}
                    WHERE date_created < DATE_SUB(NOW(), INTERVAL %d DAY)",
                    $days
                )
            );
        }

        $results['processed'] = $result !== false ? $result : 0;

        return $results;
    }

    /**
     * Delete all activities for a user (GDPR right to deletion)
     *
     * @param int $user_id User ID
     * @return bool|WP_Error Success or error
     */
    public function delete_user_activities($user_id) {
        $result = $this->wpdb->delete(
            $this->activities_table,
            array('user_id' => $user_id),
            array('%d')
        );

        if (false === $result) {
            return new WP_Error('db_error', __('Failed to delete user activities', 'wish-cart'));
        }

        return true;
    }

    /**
     * Export user activities (GDPR data export)
     *
     * @param int $user_id User ID
     * @return array User's activities
     */
    public function export_user_activities($user_id) {
        $activities = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT 
                    activity_type,
                    object_type,
                    activity_data,
                    date_created
                FROM {$this->activities_table}
                WHERE user_id = %d
                ORDER BY date_created DESC",
                $user_id
            ),
            ARRAY_A
        );

        return $activities ? $activities : array();
    }
}

