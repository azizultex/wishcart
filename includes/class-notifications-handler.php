<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Notifications Handler Class
 *
 * Handles email notifications for wishlist events
 *
 * @category WordPress
 * @package  WishCart
 * @author   WishCart Team <support@wishcart.chat>
 * @license  GPL-2.0+ https://www.gnu.org/licenses/gpl-2.0.html
 * @link     https://wishcart.chat
 */
class WISHCART_Notifications_Handler {

    private $wpdb;
    private $notifications_table;

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->notifications_table = $wpdb->prefix . 'fc_wishlist_notifications';
    }

    /**
     * Queue notification
     *
     * @param string $notification_type Type of notification
     * @param string $email_to Recipient email
     * @param array $data Notification data
     * @return int|WP_Error Notification ID or error
     */
    public function queue_notification($notification_type, $email_to, $data = array()) {
        $valid_types = array('price_drop', 'back_in_stock', 'promotional', 'reminder', 'share_notification', 'estimate_request');
        
        if (!in_array($notification_type, $valid_types)) {
            return new WP_Error('invalid_type', __('Invalid notification type', 'wish-cart'));
        }

        if (!is_email($email_to)) {
            return new WP_Error('invalid_email', __('Invalid email address', 'wish-cart'));
        }

        $user_id = isset($data['user_id']) ? intval($data['user_id']) : null;
        $wishlist_id = isset($data['wishlist_id']) ? intval($data['wishlist_id']) : null;
        $product_id = isset($data['product_id']) ? intval($data['product_id']) : null;

        // Generate email content based on type
        $email_data = $this->generate_email_content($notification_type, $data);

        $insert_data = array(
            'user_id' => $user_id,
            'wishlist_id' => $wishlist_id,
            'product_id' => $product_id,
            'notification_type' => $notification_type,
            'email_to' => $email_to,
            'email_subject' => $email_data['subject'],
            'email_content' => $email_data['content'],
            'trigger_data' => isset($data['trigger_data']) ? wp_json_encode($data['trigger_data']) : null,
            'scheduled_date' => isset($data['scheduled_date']) ? $data['scheduled_date'] : current_time('mysql'),
            'status' => 'pending',
        );

        $format = array('%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s');

        $result = $this->wpdb->insert($this->notifications_table, $insert_data, $format);

        if (false === $result) {
            return new WP_Error('db_error', __('Failed to queue notification', 'wish-cart'));
        }

        return $this->wpdb->insert_id;
    }

    /**
     * Generate email content based on notification type
     *
     * @param string $notification_type Notification type
     * @param array $data Data for email generation
     * @return array Array with 'subject' and 'content'
     */
    private function generate_email_content($notification_type, $data) {
        $site_name = get_bloginfo('name');
        $subject = '';
        $content = '';

        switch ($notification_type) {
            case 'price_drop':
                $product_name = isset($data['product_name']) ? $data['product_name'] : __('Product', 'wish-cart');
                $old_price = isset($data['old_price']) ? $data['old_price'] : '';
                $new_price = isset($data['new_price']) ? $data['new_price'] : '';
                $product_url = isset($data['product_url']) ? $data['product_url'] : '';

                $subject = sprintf(__('Price Drop Alert: %s', 'wish-cart'), $product_name);
                $content = sprintf(
                    __('Good news! A product in your wishlist has dropped in price.%s%sProduct: %s%sOld Price: %s%sNew Price: %s%s%sView Product: %s', 'wish-cart'),
                    "\n\n",
                    "\n",
                    $product_name,
                    "\n",
                    $old_price,
                    "\n",
                    $new_price,
                    "\n\n",
                    "\n",
                    $product_url
                );
                break;

            case 'back_in_stock':
                $product_name = isset($data['product_name']) ? $data['product_name'] : __('Product', 'wish-cart');
                $product_url = isset($data['product_url']) ? $data['product_url'] : '';

                $subject = sprintf(__('Back in Stock: %s', 'wish-cart'), $product_name);
                $content = sprintf(
                    __('Great news! A product in your wishlist is back in stock.%s%sProduct: %s%s%sView Product: %s', 'wish-cart'),
                    "\n\n",
                    "\n",
                    $product_name,
                    "\n\n",
                    "\n",
                    $product_url
                );
                break;

            case 'promotional':
                $subject = isset($data['subject']) ? $data['subject'] : __('Special Offer on Your Wishlist', 'wish-cart');
                $content = isset($data['message']) ? $data['message'] : '';
                break;

            case 'reminder':
                $wishlist_name = isset($data['wishlist_name']) ? $data['wishlist_name'] : __('Your Wishlist', 'wish-cart');
                $wishlist_url = isset($data['wishlist_url']) ? $data['wishlist_url'] : '';
                $item_count = isset($data['item_count']) ? intval($data['item_count']) : 0;

                $subject = sprintf(__('Reminder: You have %d items in your wishlist', 'wish-cart'), $item_count);
                $content = sprintf(
                    __('Hi there,%s%sJust a friendly reminder that you have %d items waiting in your wishlist "%s".%s%sView Your Wishlist: %s', 'wish-cart'),
                    "\n\n",
                    "\n\n",
                    $item_count,
                    $wishlist_name,
                    "\n\n",
                    "\n",
                    $wishlist_url
                );
                break;

            case 'share_notification':
                $shared_by = isset($data['shared_by']) ? $data['shared_by'] : __('Someone', 'wish-cart');
                $wishlist_name = isset($data['wishlist_name']) ? $data['wishlist_name'] : __('a wishlist', 'wish-cart');
                $wishlist_url = isset($data['wishlist_url']) ? $data['wishlist_url'] : '';
                $message = isset($data['message']) ? $data['message'] : '';

                $subject = sprintf(__('%s shared %s with you', 'wish-cart'), $shared_by, $wishlist_name);
                $content = sprintf(
                    __('Hi,%s%s%s has shared a wishlist with you: "%s"', 'wish-cart'),
                    "\n\n",
                    "\n\n",
                    $shared_by,
                    $wishlist_name
                );

                if (!empty($message)) {
                    $content .= "\n\n" . __('Message:', 'wish-cart') . "\n" . $message;
                }

                $content .= "\n\n" . __('View Wishlist:', 'wish-cart') . "\n" . $wishlist_url;
                break;

            case 'estimate_request':
                $subject = __('Wishlist Estimate Request', 'wish-cart');
                $content = isset($data['message']) ? $data['message'] : '';
                break;

            default:
                $subject = __('Wishlist Notification', 'wish-cart');
                $content = isset($data['message']) ? $data['message'] : '';
        }

        // Add footer
        $content .= "\n\n---\n" . sprintf(__('This email was sent by %s', 'wish-cart'), $site_name);

        return array(
            'subject' => $subject,
            'content' => $content,
        );
    }

    /**
     * Process notification queue (send pending notifications)
     *
     * @param int $limit Number of notifications to process
     * @return array Results
     */
    public function process_queue($limit = 10) {
        $results = array(
            'success' => true,
            'sent' => 0,
            'failed' => 0,
            'errors' => array(),
        );

        // Get pending notifications
        $notifications = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->notifications_table}
                WHERE status = 'pending'
                    AND scheduled_date <= NOW()
                    AND attempts < 3
                ORDER BY scheduled_date ASC
                LIMIT %d",
                $limit
            ),
            ARRAY_A
        );

        foreach ($notifications as $notification) {
            $send_result = $this->send_notification($notification['notification_id']);
            
            if (is_wp_error($send_result)) {
                $results['failed']++;
                $results['errors'][] = sprintf(
                    'Notification %d failed: %s',
                    $notification['notification_id'],
                    $send_result->get_error_message()
                );
            } else {
                $results['sent']++;
            }
        }

        return $results;
    }

    /**
     * Send notification
     *
     * @param int $notification_id Notification ID
     * @return bool|WP_Error Success or error
     */
    public function send_notification($notification_id) {
        $notification = $this->get_notification($notification_id);
        
        if (!$notification) {
            return new WP_Error('not_found', __('Notification not found', 'wish-cart'));
        }

        // Increment attempts
        $this->wpdb->update(
            $this->notifications_table,
            array('attempts' => $notification['attempts'] + 1),
            array('notification_id' => $notification_id),
            array('%d'),
            array('%d')
        );

        // Send email
        $result = wp_mail(
            $notification['email_to'],
            $notification['email_subject'],
            $notification['email_content'],
            array('Content-Type: text/plain; charset=UTF-8')
        );

        if ($result) {
            // Mark as sent
            $this->wpdb->update(
                $this->notifications_table,
                array(
                    'status' => 'sent',
                    'sent_date' => current_time('mysql'),
                ),
                array('notification_id' => $notification_id),
                array('%s', '%s'),
                array('%d')
            );

            return true;
        } else {
            // Mark as failed
            $error_message = __('Failed to send email', 'wish-cart');
            $this->wpdb->update(
                $this->notifications_table,
                array(
                    'status' => 'failed',
                    'error_message' => $error_message,
                ),
                array('notification_id' => $notification_id),
                array('%s', '%s'),
                array('%d')
            );

            return new WP_Error('send_failed', $error_message);
        }
    }

    /**
     * Get notification by ID
     *
     * @param int $notification_id Notification ID
     * @return array|null Notification data
     */
    public function get_notification($notification_id) {
        return $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->notifications_table} WHERE notification_id = %d",
                $notification_id
            ),
            ARRAY_A
        );
    }

    /**
     * Get user's notifications
     *
     * @param int $user_id User ID
     * @param string $status Status filter (pending, sent, failed, cancelled)
     * @return array Array of notifications
     */
    public function get_user_notifications($user_id, $status = null) {
        $where = "user_id = %d";
        $params = array($user_id);

        if ($status) {
            $where .= " AND status = %s";
            $params[] = $status;
        }

        $query = "SELECT * FROM {$this->notifications_table} WHERE {$where} ORDER BY date_created DESC LIMIT 100";

        return $this->wpdb->get_results(
            $this->wpdb->prepare($query, $params),
            ARRAY_A
        );
    }

    /**
     * Track email open
     *
     * @param int $notification_id Notification ID
     * @return bool Success
     */
    public function track_email_open($notification_id) {
        $result = $this->wpdb->update(
            $this->notifications_table,
            array('opened_date' => current_time('mysql')),
            array('notification_id' => $notification_id),
            array('%s'),
            array('%d')
        );

        return $result !== false;
    }

    /**
     * Track email click
     *
     * @param int $notification_id Notification ID
     * @return bool Success
     */
    public function track_email_click($notification_id) {
        $result = $this->wpdb->update(
            $this->notifications_table,
            array('clicked_date' => current_time('mysql')),
            array('notification_id' => $notification_id),
            array('%s'),
            array('%d')
        );

        return $result !== false;
    }

    /**
     * Cancel notification
     *
     * @param int $notification_id Notification ID
     * @return bool|WP_Error Success or error
     */
    public function cancel_notification($notification_id) {
        $result = $this->wpdb->update(
            $this->notifications_table,
            array('status' => 'cancelled'),
            array('notification_id' => $notification_id),
            array('%s'),
            array('%d')
        );

        if (false === $result) {
            return new WP_Error('db_error', __('Failed to cancel notification', 'wish-cart'));
        }

        return true;
    }

    /**
     * Check for price drops and queue notifications
     *
     * @return array Results
     */
    public function check_price_drops() {
        $results = array(
            'success' => true,
            'notifications_queued' => 0,
            'errors' => array(),
        );

        // Get all active wishlist items with prices
        $items_table = $this->wpdb->prefix . 'fc_wishlist_items';
        $wishlists_table = $this->wpdb->prefix . 'fc_wishlists';

        $items = $this->wpdb->get_results(
            "SELECT wi.*, w.user_id, w.wishlist_token
            FROM {$items_table} wi
            JOIN {$wishlists_table} w ON wi.wishlist_id = w.id
            WHERE wi.status = 'active'
                AND wi.original_price IS NOT NULL
                AND w.status = 'active'",
            ARRAY_A
        );

        foreach ($items as $item) {
            $product = WISHCART_FluentCart_Helper::get_product($item['product_id']);
            
            if (!$product) {
                continue;
            }

            $current_price = $product->get_price();
            $original_price = floatval($item['original_price']);

            // Check if price dropped
            if ($current_price < $original_price) {
                // Get user email
                if ($item['user_id']) {
                    $user = get_userdata($item['user_id']);
                    if ($user && $user->user_email) {
                        // Queue notification
                        $notification_data = array(
                            'user_id' => $item['user_id'],
                            'wishlist_id' => $item['wishlist_id'],
                            'product_id' => $item['product_id'],
                            'product_name' => $product->get_name(),
                            'old_price' => $original_price,
                            'new_price' => $current_price,
                            'product_url' => get_permalink($item['product_id']),
                        );

                        $queue_result = $this->queue_notification('price_drop', $user->user_email, $notification_data);
                        
                        if (!is_wp_error($queue_result)) {
                            $results['notifications_queued']++;
                            
                            // Update original price
                            $this->wpdb->update(
                                $items_table,
                                array('original_price' => $current_price),
                                array('item_id' => $item['item_id']),
                                array('%f'),
                                array('%d')
                            );
                        }
                    }
                }
            }
        }

        return $results;
    }

    /**
     * Check for back-in-stock products and queue notifications
     *
     * @return array Results
     */
    public function check_back_in_stock() {
        $results = array(
            'success' => true,
            'notifications_queued' => 0,
            'errors' => array(),
        );

        // Get all wishlist items
        $items_table = $this->wpdb->prefix . 'fc_wishlist_items';
        $wishlists_table = $this->wpdb->prefix . 'fc_wishlists';

        $items = $this->wpdb->get_results(
            "SELECT wi.*, w.user_id
            FROM {$items_table} wi
            JOIN {$wishlists_table} w ON wi.wishlist_id = w.id
            WHERE wi.status = 'active'
                AND w.status = 'active'",
            ARRAY_A
        );

        foreach ($items as $item) {
            $product = WISHCART_FluentCart_Helper::get_product($item['product_id']);
            
            if (!$product) {
                continue;
            }

            // Check if product is now in stock (and was out of stock before)
            // This requires tracking stock status changes - simplified version
            if ($product->is_in_stock()) {
                // Get user email
                if ($item['user_id']) {
                    $user = get_userdata($item['user_id']);
                    if ($user && $user->user_email) {
                        // Check if we already sent a notification recently (avoid spam)
                        $recent_notification = $this->wpdb->get_var(
                            $this->wpdb->prepare(
                                "SELECT COUNT(*) FROM {$this->notifications_table}
                                WHERE user_id = %d
                                    AND product_id = %d
                                    AND notification_type = 'back_in_stock'
                                    AND date_created > DATE_SUB(NOW(), INTERVAL 7 DAY)",
                                $item['user_id'],
                                $item['product_id']
                            )
                        );

                        if ($recent_notification > 0) {
                            continue; // Skip if already notified recently
                        }

                        // Queue notification
                        $notification_data = array(
                            'user_id' => $item['user_id'],
                            'wishlist_id' => $item['wishlist_id'],
                            'product_id' => $item['product_id'],
                            'product_name' => $product->get_name(),
                            'product_url' => get_permalink($item['product_id']),
                        );

                        $queue_result = $this->queue_notification('back_in_stock', $user->user_email, $notification_data);
                        
                        if (!is_wp_error($queue_result)) {
                            $results['notifications_queued']++;
                        }
                    }
                }
            }
        }

        return $results;
    }

    /**
     * Get notification statistics
     *
     * @return array Statistics
     */
    public function get_statistics() {
        $stats = $this->wpdb->get_row(
            "SELECT 
                COUNT(*) as total_notifications,
                SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent_count,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_count,
                SUM(CASE WHEN opened_date IS NOT NULL THEN 1 ELSE 0 END) as opened_count,
                SUM(CASE WHEN clicked_date IS NOT NULL THEN 1 ELSE 0 END) as clicked_count
            FROM {$this->notifications_table}",
            ARRAY_A
        );

        // Calculate rates
        $sent_count = intval($stats['sent_count']);
        $stats['open_rate'] = $sent_count > 0 ? round((intval($stats['opened_count']) / $sent_count) * 100, 2) : 0;
        $stats['click_rate'] = $sent_count > 0 ? round((intval($stats['clicked_count']) / $sent_count) * 100, 2) : 0;

        return $stats;
    }

    /**
     * Clean up old notifications
     *
     * @param int $days Delete notifications older than X days
     * @return array Results
     */
    public function cleanup_old_notifications($days = 90) {
        $results = array(
            'success' => true,
            'deleted' => 0,
        );

        // Delete old sent/failed notifications
        $result = $this->wpdb->query(
            $this->wpdb->prepare(
                "DELETE FROM {$this->notifications_table}
                WHERE status IN ('sent', 'failed', 'cancelled')
                    AND date_created < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days
            )
        );

        $results['deleted'] = $result !== false ? $result : 0;

        return $results;
    }
}

