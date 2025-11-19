<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Cron Handler Class
 *
 * Manages WordPress cron jobs for background processing
 *
 * @category WordPress
 * @package  WishCart
 * @author   WishCart Team <support@wishcart.chat>
 * @license  GPL-2.0+ https://www.gnu.org/licenses/gpl-2.0.html
 * @link     https://wishcart.chat
 */
class WISHCART_Cron_Handler {

    /**
     * Constructor
     */
    public function __construct() {
        // Register cron hooks
        add_action('wishcart_process_notifications', array($this, 'process_notifications'));
        add_action('wishcart_check_price_drops', array($this, 'check_price_drops'));
        add_action('wishcart_check_back_in_stock', array($this, 'check_back_in_stock'));
        add_action('wishcart_cleanup_expired_guests', array($this, 'cleanup_expired_guests'));
        add_action('wishcart_cleanup_expired_shares', array($this, 'cleanup_expired_shares'));
        add_action('wishcart_recalculate_analytics', array($this, 'recalculate_analytics'));
        add_action('wishcart_cleanup_old_data', array($this, 'cleanup_old_data'));
        add_action('wishcart_process_time_based_campaigns', array($this, 'process_time_based_campaigns'));
        add_action('wishcart_send_scheduled_email', array($this, 'send_scheduled_email'), 10, 4);
    }

    /**
     * Schedule all cron jobs
     *
     * @return void
     */
    public static function schedule_events() {
        // Process notification queue (every 5 minutes)
        if (!wp_next_scheduled('wishcart_process_notifications')) {
            wp_schedule_event(time(), 'wishcart_5min', 'wishcart_process_notifications');
        }

        // Check for price drops (hourly)
        if (!wp_next_scheduled('wishcart_check_price_drops')) {
            wp_schedule_event(time(), 'hourly', 'wishcart_check_price_drops');
        }

        // Check for back-in-stock products (hourly)
        if (!wp_next_scheduled('wishcart_check_back_in_stock')) {
            wp_schedule_event(time(), 'hourly', 'wishcart_check_back_in_stock');
        }

        // Cleanup expired guest sessions (daily)
        if (!wp_next_scheduled('wishcart_cleanup_expired_guests')) {
            wp_schedule_event(time(), 'daily', 'wishcart_cleanup_expired_guests');
        }

        // Cleanup expired shares (daily)
        if (!wp_next_scheduled('wishcart_cleanup_expired_shares')) {
            wp_schedule_event(time(), 'daily', 'wishcart_cleanup_expired_shares');
        }

        // Recalculate analytics (daily)
        if (!wp_next_scheduled('wishcart_recalculate_analytics')) {
            wp_schedule_event(time(), 'daily', 'wishcart_recalculate_analytics');
        }

        // Cleanup old data (weekly)
        if (!wp_next_scheduled('wishcart_cleanup_old_data')) {
            wp_schedule_event(time(), 'weekly', 'wishcart_cleanup_old_data');
        }

        // Process time-based campaigns (daily)
        if (!wp_next_scheduled('wishcart_process_time_based_campaigns')) {
            wp_schedule_event(time(), 'daily', 'wishcart_process_time_based_campaigns');
        }
    }

    /**
     * Unschedule all cron jobs
     *
     * @return void
     */
    public static function unschedule_events() {
        $events = array(
            'wishcart_process_notifications',
            'wishcart_check_price_drops',
            'wishcart_check_back_in_stock',
            'wishcart_cleanup_expired_guests',
            'wishcart_cleanup_expired_shares',
            'wishcart_recalculate_analytics',
            'wishcart_cleanup_old_data',
            'wishcart_process_time_based_campaigns',
        );

        foreach ($events as $event) {
            $timestamp = wp_next_scheduled($event);
            if ($timestamp) {
                wp_unschedule_event($timestamp, $event);
            }
        }
    }

    /**
     * Register custom cron schedules
     *
     * @param array $schedules Existing schedules
     * @return array Modified schedules
     */
    public static function add_cron_schedules($schedules) {
        // Add 5-minute interval
        if (!isset($schedules['wishcart_5min'])) {
            $schedules['wishcart_5min'] = array(
                'interval' => 300, // 5 minutes in seconds
                'display' => __('Every 5 Minutes', 'wish-cart'),
            );
        }

        return $schedules;
    }

    /**
     * Process notification queue
     *
     * @return void
     */
    public function process_notifications() {
        $this->log_debug('Processing notification queue...');
        
        $notifications = new WISHCART_Notifications_Handler();
        $result = $notifications->process_queue(10); // Process up to 10 notifications per run
        
        if ($result['sent'] > 0 || $result['failed'] > 0) {
            $this->log_debug(sprintf(
                'Notifications processed: %d sent, %d failed',
                $result['sent'],
                $result['failed']
            ));
        }
    }

    /**
     * Check for price drops
     *
     * @return void
     */
    public function check_price_drops() {
        $this->log_debug('Checking for price drops...');
        
        $notifications = new WISHCART_Notifications_Handler();
        $result = $notifications->check_price_drops();
        
        if ($result['notifications_queued'] > 0) {
            $this->log_debug(sprintf(
                'Price drop notifications queued: %d',
                $result['notifications_queued']
            ));
        }
    }

    /**
     * Check for back-in-stock products
     *
     * @return void
     */
    public function check_back_in_stock() {
        $this->log_debug('Checking for back-in-stock products...');
        
        $notifications = new WISHCART_Notifications_Handler();
        $result = $notifications->check_back_in_stock();
        
        if ($result['notifications_queued'] > 0) {
            $this->log_debug(sprintf(
                'Back-in-stock notifications queued: %d',
                $result['notifications_queued']
            ));
        }
    }

    /**
     * Cleanup expired guest sessions
     *
     * @return void
     */
    public function cleanup_expired_guests() {
        $this->log_debug('Cleaning up expired guest sessions...');
        
        $guest_handler = new WISHCART_Guest_Handler();
        
        // Get settings
        $settings = get_option('wishcart_settings', array());
        $delete_data = isset($settings['wishlist']['delete_expired_guests']) ? (bool) $settings['wishlist']['delete_expired_guests'] : false;
        
        $result = $guest_handler->cleanup_expired_sessions($delete_data);
        
        if ($result['processed'] > 0) {
            $this->log_debug(sprintf(
                'Expired guest sessions processed: %d',
                $result['processed']
            ));
        }
    }

    /**
     * Cleanup expired shares
     *
     * @return void
     */
    public function cleanup_expired_shares() {
        $this->log_debug('Cleaning up expired shares...');
        
        $sharing = new WISHCART_Sharing_Handler();
        $result = $sharing->cleanup_expired_shares();
        
        if ($result['deleted'] > 0) {
            $this->log_debug(sprintf(
                'Expired shares cleaned up: %d',
                $result['deleted']
            ));
        }
    }

    /**
     * Recalculate analytics
     *
     * @return void
     */
    public function recalculate_analytics() {
        $this->log_debug('Recalculating analytics...');
        
        $analytics = new WISHCART_Analytics_Handler();
        $result = $analytics->recalculate_all_analytics();
        
        if ($result['updated'] > 0) {
            $this->log_debug(sprintf(
                'Analytics recalculated for %d products',
                $result['updated']
            ));
        }
    }

    /**
     * Cleanup old data (weekly maintenance)
     *
     * @return void
     */
    public function cleanup_old_data() {
        $this->log_debug('Running weekly cleanup...');
        
        // Get settings
        $settings = get_option('wishcart_settings', array());
        $activity_retention_days = isset($settings['wishlist']['activity_retention_days']) ? intval($settings['wishlist']['activity_retention_days']) : 365;
        $notification_retention_days = isset($settings['wishlist']['notification_retention_days']) ? intval($settings['wishlist']['notification_retention_days']) : 90;
        $analytics_retention_days = isset($settings['wishlist']['analytics_retention_days']) ? intval($settings['wishlist']['analytics_retention_days']) : 365;
        
        // Cleanup activities (anonymize instead of delete for audit)
        $activity_logger = new WISHCART_Activity_Logger();
        $activity_result = $activity_logger->cleanup_old_activities($activity_retention_days, true);
        
        // Cleanup notifications
        $notifications = new WISHCART_Notifications_Handler();
        $notification_result = $notifications->cleanup_old_notifications($notification_retention_days);
        
        // Cleanup analytics
        $analytics = new WISHCART_Analytics_Handler();
        $analytics_result = $analytics->cleanup_old_analytics($analytics_retention_days);
        
        // Anonymize old guest data
        $guest_handler = new WISHCART_Guest_Handler();
        $guest_result = $guest_handler->anonymize_old_guests(90);
        
        $this->log_debug(sprintf(
            'Weekly cleanup completed: %d activities anonymized, %d notifications deleted, %d analytics deleted, %d guests anonymized',
            $activity_result['processed'],
            $notification_result['deleted'],
            $analytics_result['deleted'],
            $guest_result['anonymized']
        ));
    }

    /**
     * Get cron status
     *
     * @return array Status of all cron jobs
     */
    public static function get_cron_status() {
        $events = array(
            'wishcart_process_notifications' => __('Process Notifications', 'wish-cart'),
            'wishcart_check_price_drops' => __('Check Price Drops', 'wish-cart'),
            'wishcart_check_back_in_stock' => __('Check Back in Stock', 'wish-cart'),
            'wishcart_cleanup_expired_guests' => __('Cleanup Expired Guests', 'wish-cart'),
            'wishcart_cleanup_expired_shares' => __('Cleanup Expired Shares', 'wish-cart'),
            'wishcart_recalculate_analytics' => __('Recalculate Analytics', 'wish-cart'),
            'wishcart_cleanup_old_data' => __('Cleanup Old Data', 'wish-cart'),
            'wishcart_process_time_based_campaigns' => __('Process Time-Based Campaigns', 'wish-cart'),
        );

        $status = array();
        foreach ($events as $hook => $label) {
            $timestamp = wp_next_scheduled($hook);
            $status[] = array(
                'hook' => $hook,
                'label' => $label,
                'scheduled' => (bool) $timestamp,
                'next_run' => $timestamp ? date('Y-m-d H:i:s', $timestamp) : null,
                'next_run_relative' => $timestamp ? human_time_diff($timestamp, time()) : null,
            );
        }

        return $status;
    }

    /**
     * Manually trigger a cron job (for testing/debugging)
     *
     * @param string $hook Cron hook name
     * @return array Result
     */
    public static function trigger_cron($hook) {
        $valid_hooks = array(
            'wishcart_process_notifications',
            'wishcart_check_price_drops',
            'wishcart_check_back_in_stock',
            'wishcart_cleanup_expired_guests',
            'wishcart_cleanup_expired_shares',
            'wishcart_recalculate_analytics',
            'wishcart_cleanup_old_data',
            'wishcart_process_time_based_campaigns',
        );

        if (!in_array($hook, $valid_hooks)) {
            return array(
                'success' => false,
                'message' => __('Invalid cron hook', 'wish-cart'),
            );
        }

        // Trigger the action
        do_action($hook);

        return array(
            'success' => true,
            'message' => sprintf(__('Cron job %s triggered successfully', 'wish-cart'), $hook),
        );
    }

    /**
     * Process time-based campaigns
     *
     * @return void
     */
    public function process_time_based_campaigns() {
        $this->log_debug('Processing time-based campaigns...');
        
        if (class_exists('WISHCART_CRM_Campaign_Handler')) {
            $campaign_handler = new WISHCART_CRM_Campaign_Handler();
            $campaign_handler->process_time_based_campaigns();
            
            $this->log_debug('Time-based campaigns processed');
        }
    }

    /**
     * Send scheduled email
     *
     * @param int $contact_id Contact ID
     * @param string $subject Email subject
     * @param string $body Email body
     * @param array $event_data Event data
     * @return void
     */
    public function send_scheduled_email($contact_id, $subject, $body, $event_data) {
        if (class_exists('WISHCART_FluentCRM_Integration')) {
            $fluentcrm = new WISHCART_FluentCRM_Integration();
            $options = array();
            if (isset($event_data['campaign_id'])) {
                $options['campaign_id'] = $event_data['campaign_id'];
            }
            $fluentcrm->send_email($contact_id, $subject, $body, $options);
        }
    }

    /**
     * Debug logger
     *
     * @param string $message Message to log
     * @return void
     */
    private function log_debug($message) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[WishCart Cron] ' . $message);
        }
    }
}

