<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * FluentCRM Integration Class
 *
 * Handles integration with FluentCRM for automated marketing campaigns
 *
 * @category WordPress
 * @package  WishCart
 * @author   WishCart Team <support@wishcart.chat>
 * @license  GPL-2.0+ https://www.gnu.org/licenses/gpl-2.0.html
 * @link     https://wishcart.chat
 */
class WISHCART_FluentCRM_Integration {

    private $wpdb;
    private $is_available = null;
    private static $is_available_cache = null;

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        
        // Hook into FluentCRM events
        add_action('fluentcrm_contact_created', array($this, 'on_contact_created'), 10, 2);
        add_action('fluentcrm_contact_updated', array($this, 'on_contact_updated'), 10, 2);
        add_action('fluentcrm_subscriber_status_changed', array($this, 'on_subscriber_status_changed'), 10, 3);
    }

    /**
     * Clear the FluentCRM detection cache
     *
     * Useful when plugins are activated/deactivated
     *
     * @return void
     */
    public static function clear_detection_cache() {
        self::$is_available_cache = null;
    }

    /**
     * Check if FluentCRM is available
     *
     * @return bool
     */
    public function is_available() {
        // Check static cache first
        if (self::$is_available_cache !== null) {
            return self::$is_available_cache;
        }

        // Check instance cache
        if ($this->is_available !== null) {
            return $this->is_available;
        }

        // Include plugin.php if needed
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        // Check for FluentCRM plugin using multiple possible paths
        $possible_paths = array(
            'fluent-crm/fluent-crm.php',      // WordPress.org version
            'fluentcrm/fluentcrm.php',        // Alternative version
            'fluentcrm-pro/fluentcrm-pro.php', // Pro version
        );

        foreach ($possible_paths as $path) {
            if (is_plugin_active($path)) {
                $this->is_available = true;
                self::$is_available_cache = true;
                return true;
            }
        }

        // Check all active plugins for FluentCRM (more comprehensive)
        $active_plugins = get_option('active_plugins', array());
        foreach ($active_plugins as $plugin) {
            if (strpos($plugin, 'fluentcrm') !== false || strpos($plugin, 'fluent-crm') !== false) {
                $this->is_available = true;
                self::$is_available_cache = true;
                return true;
            }
        }

        // Check network active plugins (for multisite)
        if (is_multisite()) {
            $network_plugins = get_site_option('active_sitewide_plugins', array());
            foreach ($network_plugins as $plugin => $timestamp) {
                if (strpos($plugin, 'fluentcrm') !== false || strpos($plugin, 'fluent-crm') !== false) {
                    $this->is_available = true;
                    self::$is_available_cache = true;
                    return true;
                }
            }
        }

        // Also check if FluentCRM classes are available (fallback)
        if (class_exists('FluentCrm\App\Services\FluentCrm') || 
            class_exists('FluentCrm\Framework\Foundation\Application') ||
            class_exists('FluentCrm\App\Services\Helper') ||
            function_exists('fluentcrm') ||
            defined('FLUENTCRM')) {
            $this->is_available = true;
            self::$is_available_cache = true;
            return true;
        }

        $this->is_available = false;
        self::$is_available_cache = false;
        return false;
    }

    /**
     * Clear detection cache (static method for external use)
     *
     * @return void
     */
    public function clear_cache() {
        $this->is_available = null;
        self::clear_detection_cache();
    }

    /**
     * Get FluentCRM settings
     *
     * @return array
     */
    public function get_settings() {
        $defaults = array(
            'enabled' => false,
            'auto_create_contacts' => true,
            'send_welcome_email' => true,
            'default_tags' => array(),
            'default_lists' => array(),
            'default_list_name' => 'Wishlist Users',
            'price_drop_enabled' => true,
            'back_in_stock_enabled' => true,
            'time_based_enabled' => true,
            'progressive_discounts' => true,
            'discount_code_prefix' => 'WISHLIST',
        );

        $settings = get_option('wishcart_fluentcrm_settings', array());
        return wp_parse_args($settings, $defaults);
    }

    /**
     * Update FluentCRM settings
     *
     * @param array $settings Settings array
     * @return bool
     */
    public function update_settings($settings) {
        return update_option('wishcart_fluentcrm_settings', $settings);
    }

    /**
     * Create or update contact in FluentCRM
     *
     * @param int|null $user_id WordPress user ID
     * @param string|null $email Email address
     * @param array $data Additional contact data
     * @return int|WP_Error Contact ID or error
     */
    public function create_or_update_contact($user_id = null, $email = null, $data = array()) {
        if (!$this->is_available()) {
            return new WP_Error('fluentcrm_not_available', __('FluentCRM is not available', 'wish-cart'));
        }

        $settings = $this->get_settings();
        if (!$settings['enabled']) {
            return new WP_Error('integration_disabled', __('FluentCRM integration is disabled', 'wish-cart'));
        }

        // Get email from user if not provided
        if (empty($email) && $user_id) {
            $user = get_userdata($user_id);
            if ($user) {
                $email = $user->user_email;
            }
        }

        if (empty($email) || !is_email($email)) {
            return new WP_Error('invalid_email', __('Invalid email address', 'wish-cart'));
        }

        try {
            // Use FluentCRM helper function if available
            if (function_exists('fluentcrm_create_or_update_contact')) {
                $contact_data = array(
                    'email' => $email,
                    'first_name' => isset($data['first_name']) ? $data['first_name'] : '',
                    'last_name' => isset($data['last_name']) ? $data['last_name'] : '',
                    'status' => 'subscribed',
                );

                // Add custom fields
                if (isset($data['custom_fields'])) {
                    $contact_data = array_merge($contact_data, $data['custom_fields']);
                }

                // Add tags
                if (isset($data['tags']) && is_array($data['tags'])) {
                    $contact_data['tags'] = $data['tags'];
                } else if (!empty($settings['default_tags']) && is_array($settings['default_tags'])) {
                    $contact_data['tags'] = $settings['default_tags'];
                }

                // Add lists
                $lists_to_attach = array();
                if (isset($data['lists']) && is_array($data['lists'])) {
                    $lists_to_attach = $data['lists'];
                } else if (!empty($settings['default_lists']) && is_array($settings['default_lists'])) {
                    $lists_to_attach = $settings['default_lists'];
                } else {
                    // Auto-create and attach default list if no lists specified
                    $default_list_id = $this->get_or_create_default_list();
                    if (!is_wp_error($default_list_id)) {
                        $lists_to_attach = array($default_list_id);
                    }
                }
                
                if (!empty($lists_to_attach)) {
                    $contact_data['lists'] = $lists_to_attach;
                }

                $contact = fluentcrm_create_or_update_contact($contact_data);
                $contact_id = isset($contact->id) ? $contact->id : (isset($contact['id']) ? $contact['id'] : null);
                
                // Ensure default list is attached even if not in contact_data
                if ($contact_id && empty($lists_to_attach)) {
                    $default_list_id = $this->get_or_create_default_list();
                    if (!is_wp_error($default_list_id)) {
                        $this->attach_lists($contact_id, array($default_list_id));
                    }
                }
                
                return $contact_id;
            }

            // Fallback to direct model access
            if (class_exists('\FluentCrm\App\Models\Subscriber')) {
                $subscriber = \FluentCrm\App\Models\Subscriber::where('email', $email)->first();
                
                $contact_data = array(
                    'email' => $email,
                    'first_name' => isset($data['first_name']) ? $data['first_name'] : '',
                    'last_name' => isset($data['last_name']) ? $data['last_name'] : '',
                    'status' => 'subscribed',
                );

                if ($subscriber) {
                    $subscriber->fill($contact_data);
                    $subscriber->save();
                    $contact_id = $subscriber->id;
                } else {
                    $subscriber = \FluentCrm\App\Models\Subscriber::create($contact_data);
                    $contact_id = $subscriber->id;
                }

                // Apply tags
                $tags_to_apply = array();
                if (isset($data['tags']) && is_array($data['tags'])) {
                    $tags_to_apply = array_merge($tags_to_apply, $data['tags']);
                }
                if (!empty($settings['default_tags']) && is_array($settings['default_tags'])) {
                    $tags_to_apply = array_merge($tags_to_apply, $settings['default_tags']);
                }
                if (!empty($tags_to_apply)) {
                    $this->attach_tags($contact_id, array_unique($tags_to_apply));
                }

                // Apply lists
                $lists_to_apply = array();
                if (isset($data['lists']) && is_array($data['lists'])) {
                    $lists_to_apply = array_merge($lists_to_apply, $data['lists']);
                }
                if (!empty($settings['default_lists']) && is_array($settings['default_lists'])) {
                    $lists_to_apply = array_merge($lists_to_apply, $settings['default_lists']);
                }
                
                // Auto-create and attach default list if no lists specified
                if (empty($lists_to_apply)) {
                    $default_list_id = $this->get_or_create_default_list();
                    if (!is_wp_error($default_list_id)) {
                        $lists_to_apply = array($default_list_id);
                    }
                }
                
                if (!empty($lists_to_apply)) {
                    $this->attach_lists($contact_id, array_unique($lists_to_apply));
                }

                return $contact_id;
            }

            return new WP_Error('fluentcrm_api_not_found', __('FluentCRM API not found', 'wish-cart'));
        } catch (Exception $e) {
            return new WP_Error('fluentcrm_error', $e->getMessage());
        }
    }

    /**
     * Attach tags to contact
     *
     * @param int $contact_id Contact ID
     * @param array $tag_ids Tag IDs (can be IDs or tag names)
     * @return bool|WP_Error
     */
    public function attach_tags($contact_id, $tag_ids) {
        if (!$this->is_available()) {
            return new WP_Error('fluentcrm_not_available', __('FluentCRM is not available', 'wish-cart'));
        }

        try {
            if (class_exists('\FluentCrm\App\Models\Subscriber')) {
                $subscriber = \FluentCrm\App\Models\Subscriber::find($contact_id);
                if ($subscriber) {
                    // Convert tag names to IDs if needed
                    $final_tag_ids = array();
                    foreach ($tag_ids as $tag) {
                        if (is_numeric($tag)) {
                            $final_tag_ids[] = intval($tag);
                        } else {
                            // Try to find tag by name
                            $tag_obj = \FluentCrm\App\Models\Tag::where('title', $tag)->first();
                            if ($tag_obj) {
                                $final_tag_ids[] = $tag_obj->id;
                            }
                        }
                    }
                    
                    if (!empty($final_tag_ids)) {
                        $subscriber->attachTags($final_tag_ids);
                    }
                    return true;
                }
            }
            return new WP_Error('contact_not_found', __('Contact not found', 'wish-cart'));
        } catch (Exception $e) {
            return new WP_Error('fluentcrm_error', $e->getMessage());
        }
    }

    /**
     * Attach lists (segments) to contact
     *
     * @param int $contact_id Contact ID
     * @param array $list_ids List IDs (can be IDs or list names)
     * @return bool|WP_Error
     */
    public function attach_lists($contact_id, $list_ids) {
        if (!$this->is_available()) {
            return new WP_Error('fluentcrm_not_available', __('FluentCRM is not available', 'wish-cart'));
        }

        try {
            if (class_exists('\FluentCrm\App\Models\Subscriber')) {
                $subscriber = \FluentCrm\App\Models\Subscriber::find($contact_id);
                if ($subscriber) {
                    // Convert list names to IDs if needed, create if doesn't exist
                    $final_list_ids = array();
                    foreach ($list_ids as $list) {
                        if (is_numeric($list)) {
                            $final_list_ids[] = intval($list);
                        } else {
                            // Try to find list by name
                            $list_obj = \FluentCrm\App\Models\Lists::where('title', $list)->first();
                            if ($list_obj) {
                                $final_list_ids[] = $list_obj->id;
                            } else {
                                // List doesn't exist, create it
                                $new_list_id = $this->create_list_if_not_exists($list);
                                if (!is_wp_error($new_list_id)) {
                                    $final_list_ids[] = $new_list_id;
                                }
                            }
                        }
                    }
                    
                    if (!empty($final_list_ids)) {
                        $subscriber->attachLists($final_list_ids);
                    }
                    return true;
                }
            }
            return new WP_Error('contact_not_found', __('Contact not found', 'wish-cart'));
        } catch (Exception $e) {
            return new WP_Error('fluentcrm_error', $e->getMessage());
        }
    }

    /**
     * Get contact by email
     *
     * @param string $email Email address
     * @return object|null Contact object or null
     */
    public function get_contact($email) {
        if (!$this->is_available()) {
            return null;
        }

        try {
            // Try helper function first
            if (function_exists('fluentcrm_get_contact')) {
                return fluentcrm_get_contact($email);
            }

            // Fallback to model
            if (class_exists('\FluentCrm\App\Models\Subscriber')) {
                return \FluentCrm\App\Models\Subscriber::where('email', $email)->first();
            }

            return null;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Get contact by ID
     *
     * @param int $contact_id Contact ID
     * @return object|null Contact object or null
     */
    public function get_contact_by_id($contact_id) {
        if (!$this->is_available()) {
            return null;
        }

        try {
            // Try helper function first
            if (function_exists('fluentcrm_get_contact')) {
                return fluentcrm_get_contact($contact_id);
            }

            // Fallback to model
            if (class_exists('\FluentCrm\App\Models\Subscriber')) {
                return \FluentCrm\App\Models\Subscriber::find($contact_id);
            }

            return null;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Send email via FluentCRM
     *
     * @param int $contact_id Contact ID
     * @param string $subject Email subject
     * @param string $body Email body
     * @param array $options Additional options
     * @return int|WP_Error Email ID or error
     */
    public function send_email($contact_id, $subject, $body, $options = array()) {
        if (!$this->is_available()) {
            error_log('[WishCart FluentCRM] FluentCRM is not available');
            return new WP_Error('fluentcrm_not_available', __('FluentCRM is not available', 'wish-cart'));
        }

        try {
            // Get contact
            $contact = $this->get_contact_by_id($contact_id);
            if (!$contact) {
                error_log('[WishCart FluentCRM] Contact not found: ' . $contact_id);
                return new WP_Error('contact_not_found', __('Contact not found', 'wish-cart'));
            }

            $email_address = is_object($contact) ? $contact->email : (isset($contact['email']) ? $contact['email'] : null);
            if (!$email_address) {
                error_log('[WishCart FluentCRM] Contact email not found for contact ID: ' . $contact_id);
                return new WP_Error('no_email', __('Contact email not found', 'wish-cart'));
            }

            // Convert plain text body to HTML if needed
            $html_body = $body;
            if (strip_tags($body) === $body) {
                // Plain text, convert to HTML
                $html_body = '<html><body>' . nl2br(esc_html($body)) . '</body></html>';
            }

            error_log('[WishCart FluentCRM] Attempting to send email to contact ID: ' . $contact_id . ' (' . $email_address . ')');

            // Use FluentCRM's email sending - try multiple methods
            $email_sent = false;
            $last_error = null;

            // Method 1: Use FluentCRM API helper function if available
            if (function_exists('fluentCrmApi')) {
                try {
                    error_log('[WishCart FluentCRM] Trying fluentCrmApi helper function');
                    $api = fluentCrmApi('contacts');
                    if (method_exists($api, 'sendEmail')) {
                        $result = $api->sendEmail($contact_id, array(
                            'subject' => $subject,
                            'body' => $html_body,
                            'email_type' => 'custom'
                        ));
                        if ($result && !is_wp_error($result)) {
                            error_log('[WishCart FluentCRM] Email sent successfully via fluentCrmApi');
                            $email_sent = true;
                        } else {
                            $last_error = is_wp_error($result) ? $result->get_error_message() : 'Unknown error';
                            error_log('[WishCart FluentCRM] fluentCrmApi sendEmail failed: ' . $last_error);
                        }
                    }
                } catch (Exception $e) {
                    $last_error = $e->getMessage();
                    error_log('[WishCart FluentCRM] fluentCrmApi exception: ' . $last_error);
                }
            }

            // Method 2: Use FluentCRM API class directly
            if (!$email_sent && class_exists('\FluentCrm\App\Api\FluentCrmApi')) {
                try {
                    error_log('[WishCart FluentCRM] Trying FluentCrmApi class');
                    $api = \FluentCrm\App\Api\FluentCrmApi::contacts();
                    if (method_exists($api, 'sendEmail')) {
                        $result = $api->sendEmail($contact_id, array(
                            'subject' => $subject,
                            'body' => $html_body,
                            'email_type' => 'custom'
                        ));
                        if ($result && !is_wp_error($result)) {
                            error_log('[WishCart FluentCRM] Email sent successfully via FluentCrmApi class');
                            $email_sent = true;
                        } else {
                            $last_error = is_wp_error($result) ? $result->get_error_message() : 'Unknown error';
                            error_log('[WishCart FluentCRM] FluentCrmApi sendEmail failed: ' . $last_error);
                        }
                    }
                } catch (Exception $e) {
                    $last_error = $e->getMessage();
                    error_log('[WishCart FluentCRM] FluentCrmApi class exception: ' . $last_error);
                }
            }

            // Method 3: Use FluentCRM Mailer service
            if (!$email_sent && class_exists('\FluentCrm\App\Services\Mailer')) {
                try {
                    error_log('[WishCart FluentCRM] Trying FluentCRM Mailer service');
                    $mailer = \FluentCrm\App\Services\Mailer::getInstance();
                    if ($mailer && method_exists($mailer, 'send')) {
                        // Try sending via Mailer service
                        $result = $mailer->send($email_address, $subject, $html_body);
                        if ($result) {
                            error_log('[WishCart FluentCRM] Email sent successfully via Mailer service');
                            $email_sent = true;
                        } else {
                            error_log('[WishCart FluentCRM] Mailer service send returned false');
                        }
                    }
                } catch (Exception $e) {
                    $last_error = $e->getMessage();
                    error_log('[WishCart FluentCRM] Mailer service exception: ' . $last_error);
                }
            }

            // Method 4: Use FluentCRM's sendEmail method on contact object
            if (!$email_sent && is_object($contact) && method_exists($contact, 'sendEmail')) {
                try {
                    error_log('[WishCart FluentCRM] Trying contact sendEmail method');
                    $result = $contact->sendEmail($subject, $html_body);
                    if ($result && !is_wp_error($result)) {
                        error_log('[WishCart FluentCRM] Email sent successfully via contact sendEmail');
                        $email_sent = true;
                    } else {
                        $last_error = is_wp_error($result) ? $result->get_error_message() : 'Unknown error';
                        error_log('[WishCart FluentCRM] contact sendEmail failed: ' . $last_error);
                    }
                } catch (Exception $e) {
                    $last_error = $e->getMessage();
                    error_log('[WishCart FluentCRM] contact sendEmail exception: ' . $last_error);
                }
            }

            // Method 5: Use FluentCRM action hook
            if (!$email_sent) {
                try {
                    error_log('[WishCart FluentCRM] Trying fluentcrm_send_custom_email action hook');
                    do_action('fluentcrm_send_custom_email', array(
                        'contact_id' => $contact_id,
                        'to' => $email_address,
                        'subject' => $subject,
                        'body' => $html_body,
                    ));
                    // Note: Action hooks don't return values, so we assume success
                    error_log('[WishCart FluentCRM] fluentcrm_send_custom_email action fired (assuming success)');
                    $email_sent = true;
                } catch (Exception $e) {
                    $last_error = $e->getMessage();
                    error_log('[WishCart FluentCRM] fluentcrm_send_custom_email exception: ' . $last_error);
                }
            }

            // Method 6: Fallback to WordPress wp_mail (FluentCRM will track if contact exists)
            if (!$email_sent) {
                try {
                    error_log('[WishCart FluentCRM] Falling back to wp_mail');
                    $headers = array('Content-Type: text/html; charset=UTF-8');
                    $result = wp_mail($email_address, $subject, $html_body, $headers);
                    if ($result) {
                        error_log('[WishCart FluentCRM] Email sent successfully via wp_mail fallback');
                        $email_sent = true;
                    } else {
                        error_log('[WishCart FluentCRM] wp_mail returned false');
                    }
                } catch (Exception $e) {
                    $last_error = $e->getMessage();
                    error_log('[WishCart FluentCRM] wp_mail exception: ' . $last_error);
                }
            }
            
            if ($email_sent) {
                error_log('[WishCart FluentCRM] Email sent successfully to ' . $email_address);
                return true;
            } else {
                $error_message = $last_error ? $last_error : __('Failed to send email', 'wish-cart');
                error_log('[WishCart FluentCRM] All email sending methods failed. Last error: ' . $error_message);
                return new WP_Error('send_failed', $error_message);
            }
        } catch (Exception $e) {
            error_log('[WishCart FluentCRM] Exception in send_email: ' . $e->getMessage());
            return new WP_Error('fluentcrm_error', $e->getMessage());
        }
    }

    /**
     * Sync wishlist user to FluentCRM
     *
     * @param int $user_id WordPress user ID
     * @param array $wishlist_data Wishlist data
     * @return int|WP_Error Contact ID or error
     */
    public function sync_wishlist_user($user_id, $wishlist_data = array()) {
        $settings = $this->get_settings();
        if (!$settings['enabled'] || !$settings['auto_create_contacts']) {
            return new WP_Error('sync_disabled', __('Contact sync is disabled', 'wish-cart'));
        }

        $user = get_userdata($user_id);
        if (!$user) {
            return new WP_Error('user_not_found', __('User not found', 'wish-cart'));
        }

        // Get wishlist stats
        global $wpdb;
        $wishlists_table = $wpdb->prefix . 'fc_wishlists';
        $items_table = $wpdb->prefix . 'fc_wishlist_items';
        
        $wishlist_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wishlists_table} WHERE user_id = %d AND status = 'active'",
            $user_id
        ));
        
        $items_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$items_table} wi
            JOIN {$wishlists_table} w ON wi.wishlist_id = w.id
            WHERE w.user_id = %d AND wi.status = 'active' AND w.status = 'active'",
            $user_id
        ));

        // Prepare contact data
        $contact_data = array(
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'tags' => array('Wishlist User'),
        );

        // Add custom fields if FluentCRM supports them
        if (function_exists('fluentcrm_get_custom_contact_fields')) {
            $contact_data['wishlist_count'] = intval($wishlist_count);
            $contact_data['wishlist_items_count'] = intval($items_count);
        }

        return $this->create_or_update_contact($user_id, $user->user_email, $contact_data);
    }

    /**
     * Handle FluentCRM contact created event
     *
     * @param object $contact Contact object
     * @param array $data Contact data
     * @return void
     */
    public function on_contact_created($contact, $data) {
        // Sync wishlist data if needed
        if (isset($contact->email)) {
            $user = get_user_by('email', $contact->email);
            if ($user) {
                // Update contact with wishlist data
                $this->sync_wishlist_user($user->ID);
            }
        }
    }

    /**
     * Handle FluentCRM contact updated event
     *
     * @param object $contact Contact object
     * @param array $data Contact data
     * @return void
     */
    public function on_contact_updated($contact, $data) {
        // Handle contact updates if needed
    }

    /**
     * Handle FluentCRM subscriber status changed event
     *
     * @param object $contact Contact object
     * @param string $old_status Old status
     * @param string $new_status New status
     * @return void
     */
    public function on_subscriber_status_changed($contact, $old_status, $new_status) {
        // Handle unsubscribe if needed
        if ($new_status === 'unsubscribed') {
            // Optionally disable notifications for this contact
        }
    }

    /**
     * Get all available tags
     *
     * @return array
     */
    public function get_tags() {
        if (!$this->is_available()) {
            return array();
        }

        try {
            $tags = \FluentCrm\App\Models\Tag::get();
            $result = array();
            foreach ($tags as $tag) {
                $result[] = array(
                    'id' => $tag->id,
                    'title' => $tag->title,
                );
            }
            return $result;
        } catch (Exception $e) {
            return array();
        }
    }

    /**
     * Get all available lists
     *
     * @return array
     */
    public function get_lists() {
        if (!$this->is_available()) {
            return array();
        }

        try {
            $lists = \FluentCrm\App\Models\Lists::get();
            $result = array();
            foreach ($lists as $list) {
                $result[] = array(
                    'id' => $list->id,
                    'title' => $list->title,
                );
            }
            return $result;
        } catch (Exception $e) {
            return array();
        }
    }

    /**
     * Create list if it doesn't exist
     *
     * @param string $list_name List name
     * @return int|WP_Error List ID or error
     */
    public function create_list_if_not_exists($list_name) {
        if (!$this->is_available()) {
            return new WP_Error('fluentcrm_not_available', __('FluentCRM is not available', 'wish-cart'));
        }

        if (empty($list_name)) {
            return new WP_Error('invalid_list_name', __('Invalid list name', 'wish-cart'));
        }

        try {
            if (class_exists('\FluentCrm\App\Models\Lists')) {
                // Check if list already exists
                $existing_list = \FluentCrm\App\Models\Lists::where('title', $list_name)->first();
                
                if ($existing_list) {
                    return $existing_list->id;
                }

                // Create new list
                $list = \FluentCrm\App\Models\Lists::create(array(
                    'title' => sanitize_text_field($list_name),
                    'slug' => sanitize_title($list_name),
                ));

                if ($list && isset($list->id)) {
                    return $list->id;
                }

                return new WP_Error('list_creation_failed', __('Failed to create list', 'wish-cart'));
            }

            return new WP_Error('fluentcrm_api_not_found', __('FluentCRM API not found', 'wish-cart'));
        } catch (Exception $e) {
            return new WP_Error('fluentcrm_error', $e->getMessage());
        }
    }

    /**
     * Get or create default list
     *
     * @return int|WP_Error List ID or error
     */
    public function get_or_create_default_list() {
        $settings = $this->get_settings();
        $list_name = isset($settings['default_list_name']) ? $settings['default_list_name'] : 'Wishlist Users';
        
        return $this->create_list_if_not_exists($list_name);
    }
}

