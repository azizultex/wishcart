<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Analytics Handler Class
 *
 * Handles wishlist analytics tracking and reporting
 *
 * @category WordPress
 * @package  WishCart
 * @author   WishCart Team <support@wishcart.chat>
 * @license  GPL-2.0+ https://www.gnu.org/licenses/gpl-2.0.html
 * @link     https://wishcart.chat
 */
class WISHCART_Analytics_Handler {

    private $wpdb;
    private $analytics_table;
    private $items_table;
    private $wishlists_table;

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->analytics_table = $wpdb->prefix . 'fc_wishlist_analytics';
        $this->items_table = $wpdb->prefix . 'fc_wishlist_items';
        $this->wishlists_table = $wpdb->prefix . 'fc_wishlists';
    }

    /**
     * Track event (add, remove, view, cart, purchase, share)
     *
     * @param int $product_id Product ID
     * @param int $variation_id Variation ID
     * @param string $event_type Event type
     * @return bool Success
     */
    public function track_event($product_id, $variation_id = 0, $event_type = 'view') {
        // Get or create analytics record
        $analytics = $this->get_or_create_analytics($product_id, $variation_id);
        
        if (!$analytics) {
            return false;
        }

        $update_data = array();
        $update_format = array();

        switch ($event_type) {
            case 'add':
                $update_data['wishlist_count'] = $analytics['wishlist_count'] + 1;
                $update_data['last_added_date'] = current_time('mysql');
                if (empty($analytics['first_added_date'])) {
                    $update_data['first_added_date'] = current_time('mysql');
                }
                $update_format = array('%d', '%s', '%s');
                break;

            case 'remove':
                $update_data['wishlist_count'] = max(0, $analytics['wishlist_count'] - 1);
                $update_format = array('%d');
                break;

            case 'view':
            case 'click':
                $update_data['click_count'] = $analytics['click_count'] + 1;
                $update_format = array('%d');
                break;

            case 'cart':
                $update_data['add_to_cart_count'] = $analytics['add_to_cart_count'] + 1;
                $update_format = array('%d');
                break;

            case 'purchase':
                $update_data['purchase_count'] = $analytics['purchase_count'] + 1;
                $update_data['last_purchased_date'] = current_time('mysql');
                $update_format = array('%d', '%s');
                break;

            case 'share':
                $update_data['share_count'] = $analytics['share_count'] + 1;
                $update_format = array('%d');
                break;

            default:
                return false;
        }

        // Calculate conversion rate
        if (isset($update_data['purchase_count']) || isset($update_data['wishlist_count'])) {
            $wishlist_count = isset($update_data['wishlist_count']) ? $update_data['wishlist_count'] : $analytics['wishlist_count'];
            $purchase_count = isset($update_data['purchase_count']) ? $update_data['purchase_count'] : $analytics['purchase_count'];
            
            if ($wishlist_count > 0) {
                $update_data['conversion_rate'] = round(($purchase_count / $wishlist_count) * 100, 2);
                $update_format[] = '%f';
            }
        }

        if (empty($update_data)) {
            return false;
        }

        $result = $this->wpdb->update(
            $this->analytics_table,
            $update_data,
            array(
                'product_id' => $product_id,
                'variation_id' => $variation_id,
            ),
            $update_format,
            array('%d', '%d')
        );

        return $result !== false;
    }

    /**
     * Get or create analytics record for product
     *
     * @param int $product_id Product ID
     * @param int $variation_id Variation ID
     * @return array|null Analytics data
     */
    private function get_or_create_analytics($product_id, $variation_id = 0) {
        $analytics = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->analytics_table} WHERE product_id = %d AND variation_id = %d",
                $product_id,
                $variation_id
            ),
            ARRAY_A
        );

        if (!$analytics) {
            // Create new analytics record
            $result = $this->wpdb->insert(
                $this->analytics_table,
                array(
                    'product_id' => $product_id,
                    'variation_id' => $variation_id,
                    'wishlist_count' => 0,
                    'click_count' => 0,
                    'add_to_cart_count' => 0,
                    'purchase_count' => 0,
                    'share_count' => 0,
                    'average_days_in_wishlist' => 0,
                    'conversion_rate' => 0,
                ),
                array('%d', '%d', '%d', '%d', '%d', '%d', '%d', '%f', '%f')
            );

            if ($result) {
                $analytics = $this->wpdb->get_row(
                    $this->wpdb->prepare(
                        "SELECT * FROM {$this->analytics_table} WHERE product_id = %d AND variation_id = %d",
                        $product_id,
                        $variation_id
                    ),
                    ARRAY_A
                );
            }
        }

        return $analytics;
    }

    /**
     * Get popular products
     *
     * @param int $limit Number of products to return
     * @param string $order_by Order by field (wishlist_count, conversion_rate, share_count)
     * @return array Array of popular products with analytics
     */
    public function get_popular_products($limit = 10, $order_by = 'wishlist_count') {
        $valid_order_fields = array('wishlist_count', 'conversion_rate', 'share_count', 'add_to_cart_count', 'purchase_count');
        
        if (!in_array($order_by, $valid_order_fields)) {
            $order_by = 'wishlist_count';
        }

        $results = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->analytics_table} WHERE wishlist_count > 0 ORDER BY {$order_by} DESC LIMIT %d",
                $limit
            ),
            ARRAY_A
        );

        // Enrich with product data
        $products = array();
        foreach ($results as $row) {
            $product = WISHCART_FluentCart_Helper::get_product($row['product_id']);
            if ($product) {
                $products[] = array(
                    'product_id' => $row['product_id'],
                    'variation_id' => $row['variation_id'],
                    'product_name' => $product->get_name(),
                    'product_url' => get_permalink($row['product_id']),
                    'wishlist_count' => intval($row['wishlist_count']),
                    'click_count' => intval($row['click_count']),
                    'add_to_cart_count' => intval($row['add_to_cart_count']),
                    'purchase_count' => intval($row['purchase_count']),
                    'share_count' => intval($row['share_count']),
                    'conversion_rate' => floatval($row['conversion_rate']),
                    'average_days_in_wishlist' => floatval($row['average_days_in_wishlist']),
                );
            }
        }

        return $products;
    }

    /**
     * Get analytics for specific product
     *
     * @param int $product_id Product ID
     * @param int $variation_id Variation ID
     * @return array|null Analytics data
     */
    public function get_product_analytics($product_id, $variation_id = 0) {
        return $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->analytics_table} WHERE product_id = %d AND variation_id = %d",
                $product_id,
                $variation_id
            ),
            ARRAY_A
        );
    }

    /**
     * Get analytics overview/dashboard data
     *
     * @return array Overview statistics
     */
    public function get_overview() {
        // Total wishlists
        $total_wishlists = $this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->wishlists_table} WHERE status = 'active'"
        );

        // Total items in wishlists
        $total_items = $this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->items_table} WHERE status = 'active'"
        );

        // Total unique products wishlisted
        $unique_products = $this->wpdb->get_var(
            "SELECT COUNT(DISTINCT product_id) FROM {$this->items_table} WHERE status = 'active'"
        );

        // Average items per wishlist
        $avg_items = $total_wishlists > 0 ? round($total_items / $total_wishlists, 2) : 0;

        // Total conversions (purchases)
        $total_purchases = $this->wpdb->get_var(
            "SELECT SUM(purchase_count) FROM {$this->analytics_table}"
        );

        // Overall conversion rate
        $total_wishlist_adds = $this->wpdb->get_var(
            "SELECT SUM(wishlist_count) FROM {$this->analytics_table}"
        );
        $overall_conversion_rate = $total_wishlist_adds > 0 ? round(($total_purchases / $total_wishlist_adds) * 100, 2) : 0;

        // Total shares
        $total_shares = $this->wpdb->get_var(
            "SELECT SUM(share_count) FROM {$this->analytics_table}"
        );

        // Get growth data (last 30 days)
        $growth_data = $this->get_growth_data(30);

        return array(
            'total_wishlists' => intval($total_wishlists),
            'total_items' => intval($total_items),
            'unique_products' => intval($unique_products),
            'avg_items_per_wishlist' => $avg_items,
            'total_purchases' => intval($total_purchases),
            'overall_conversion_rate' => $overall_conversion_rate,
            'total_shares' => intval($total_shares),
            'growth_data' => $growth_data,
        );
    }

    /**
     * Get growth data for chart
     *
     * @param int $days Number of days to look back
     * @return array Daily growth data
     */
    public function get_growth_data($days = 30) {
        $results = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT 
                    DATE(dateadded) as date,
                    COUNT(*) as wishlists_created
                FROM {$this->wishlists_table}
                WHERE dateadded >= DATE_SUB(NOW(), INTERVAL %d DAY)
                    AND status = 'active'
                GROUP BY DATE(dateadded)
                ORDER BY date ASC",
                $days
            ),
            ARRAY_A
        );

        $data = array();
        foreach ($results as $row) {
            $data[] = array(
                'date' => $row['date'],
                'wishlists_created' => intval($row['wishlists_created']),
            );
        }

        return $data;
    }

    /**
     * Get conversion funnel data
     *
     * @return array Funnel statistics
     */
    public function get_conversion_funnel() {
        // Total products added to wishlist
        $added_to_wishlist = $this->wpdb->get_var(
            "SELECT SUM(wishlist_count) FROM {$this->analytics_table}"
        );

        // Total viewed/clicked
        $total_clicks = $this->wpdb->get_var(
            "SELECT SUM(click_count) FROM {$this->analytics_table}"
        );

        // Total added to cart from wishlist
        $added_to_cart = $this->wpdb->get_var(
            "SELECT SUM(add_to_cart_count) FROM {$this->analytics_table}"
        );

        // Total purchased
        $purchased = $this->wpdb->get_var(
            "SELECT SUM(purchase_count) FROM {$this->analytics_table}"
        );

        return array(
            'added_to_wishlist' => intval($added_to_wishlist),
            'clicked' => intval($total_clicks),
            'added_to_cart' => intval($added_to_cart),
            'purchased' => intval($purchased),
            'wishlist_to_cart_rate' => $added_to_wishlist > 0 ? round(($added_to_cart / $added_to_wishlist) * 100, 2) : 0,
            'cart_to_purchase_rate' => $added_to_cart > 0 ? round(($purchased / $added_to_cart) * 100, 2) : 0,
            'overall_conversion_rate' => $added_to_wishlist > 0 ? round(($purchased / $added_to_wishlist) * 100, 2) : 0,
        );
    }

    /**
     * Calculate and update average days in wishlist
     *
     * @param int $product_id Product ID
     * @param int $variation_id Variation ID
     * @return bool Success
     */
    public function calculate_average_days($product_id, $variation_id = 0) {
        // Get all items for this product that have been added to cart or purchased
        $results = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT 
                    DATEDIFF(COALESCE(date_added_to_cart, NOW()), date_added) as days_in_wishlist
                FROM {$this->items_table}
                WHERE product_id = %d AND variation_id = %d
                    AND (date_added_to_cart IS NOT NULL OR status = 'purchased')",
                $product_id,
                $variation_id
            ),
            ARRAY_A
        );

        if (empty($results)) {
            return false;
        }

        $total_days = 0;
        $count = 0;

        foreach ($results as $row) {
            if (isset($row['days_in_wishlist']) && $row['days_in_wishlist'] >= 0) {
                $total_days += intval($row['days_in_wishlist']);
                $count++;
            }
        }

        if ($count === 0) {
            return false;
        }

        $average_days = round($total_days / $count, 2);

        // Update analytics
        $result = $this->wpdb->update(
            $this->analytics_table,
            array('average_days_in_wishlist' => $average_days),
            array(
                'product_id' => $product_id,
                'variation_id' => $variation_id,
            ),
            array('%f'),
            array('%d', '%d')
        );

        return $result !== false;
    }

    /**
     * Recalculate all analytics (for cron job)
     *
     * @return array Results
     */
    public function recalculate_all_analytics() {
        $results = array(
            'success' => true,
            'updated' => 0,
            'errors' => array(),
        );

        // Get all products in analytics
        $products = $this->wpdb->get_results(
            "SELECT DISTINCT product_id, variation_id FROM {$this->analytics_table}",
            ARRAY_A
        );

        foreach ($products as $product) {
            // Recalculate wishlist count
            $wishlist_count = $this->wpdb->get_var(
                $this->wpdb->prepare(
                    "SELECT COUNT(*) FROM {$this->items_table} WHERE product_id = %d AND variation_id = %d AND status = 'active'",
                    $product['product_id'],
                    $product['variation_id']
                )
            );

            // Get purchase count and calculate conversion rate
            $analytics = $this->get_product_analytics($product['product_id'], $product['variation_id']);
            $purchase_count = $analytics ? intval($analytics['purchase_count']) : 0;
            $conversion_rate = $wishlist_count > 0 ? round(($purchase_count / $wishlist_count) * 100, 2) : 0;

            // Update analytics
            $update_result = $this->wpdb->update(
                $this->analytics_table,
                array(
                    'wishlist_count' => $wishlist_count,
                    'conversion_rate' => $conversion_rate,
                ),
                array(
                    'product_id' => $product['product_id'],
                    'variation_id' => $product['variation_id'],
                ),
                array('%d', '%f'),
                array('%d', '%d')
            );

            if ($update_result !== false) {
                $results['updated']++;
                // Calculate average days
                $this->calculate_average_days($product['product_id'], $product['variation_id']);
            } else {
                $results['errors'][] = "Failed to update product {$product['product_id']}";
            }
        }

        return $results;
    }

    /**
     * Clean up old analytics data
     *
     * @param int $days Delete analytics older than X days
     * @return array Results
     */
    public function cleanup_old_analytics($days = 365) {
        $results = array(
            'success' => true,
            'deleted' => 0,
        );

        // Delete analytics for products that are no longer in any wishlist and haven't been updated in X days
        $result = $this->wpdb->query(
            $this->wpdb->prepare(
                "DELETE FROM {$this->analytics_table}
                WHERE wishlist_count = 0
                    AND date_updated < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days
            )
        );

        $results['deleted'] = $result !== false ? $result : 0;

        return $results;
    }
}

