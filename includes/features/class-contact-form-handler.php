<?php
if ( ! defined('ABSPATH') ) {
    exit;
}

/**
 * Handles the contact form functionality for AISK
 *
 * @category Functionality
 * @package  AISK
 * @author   WishCart Team <support@wishcart.chat>
 * @license  GPL-2.0+ https://www.gnu.org/licenses/gpl-2.0.html
 * @link     https://wishcart.chat
 */

/**
 * WISHCART_Contact_Form_Handler Class
 *
 * Manages the creation, display and handling of the AISK contact form
 *
 * @category Class
 * @package  AISK
 * @author   WishCart Team <support@wishcart.chat>
 * @license  GPL-2.0+ https://www.gnu.org/licenses/gpl-2.0.html
 * @link     https://wishcart.chat
 */
class WISHCART_Contact_Form_Handler {

    private static $instance = null;
    private $page_slug = 'wishcart-contact-form';
    private $template_name = 'templates/contact-form.php';

    /**
     * Get singleton instance of the class
     *
     * @category Instance
     * @package  AISK
     * @author   WishCart Team <support@wishcart.chat>
     * @license  GPL-2.0+ https://www.gnu.org/licenses/gpl-2.0.html
     * @link     https://wishcart.chat
     *
     * @return WISHCART_Contact_Form_Handler Instance of the class
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Initialize the contact form functionality if enabled
     *
     * @category Initialization
     * @package  AISK
     * @author   WishCart Team <support@wishcart.chat>
     * @license  GPL-2.0+ https://www.gnu.org/licenses/gpl-2.0.html
     * @link     https://wishcart.chat
     *
     * @return void
     */
    public static function maybe_init() {
        $settings = get_option('wishcart_settings', []);
        $enabled = ! empty($settings['integrations']['contact_form']['enabled']);

        if ( $enabled ) {
            $instance = self::get_instance();
            $instance->ensure_page_and_template_exists();
            add_filter('wishcart_script_data', [ $instance, 'add_form_data' ]);
        } else {
            $instance = self::get_instance();
            $instance->maybe_disable_form_page();
        }
    }

    /**
     * Check if contact form is enabled in settings
     *
     * @category Utility
     * @package  AISK
     * @author   WishCart Team <support@wishcart.chat>
     * @license  GPL-2.0+ https://www.gnu.org/licenses/gpl-2.0.html
     * @link     https://wishcart.chat
     *
     * @return boolean True if enabled, false otherwise
     */
    public function is_enabled() {
        $settings = get_option('wishcart_settings', []);
        return ! empty($settings['integrations']['contact_form']['enabled']);
    }

    /**
     * Add contact form URL to script data
     *
     * @param array $data Existing script data
     *
     * @category Data
     * @package  AISK
     * @author   WishCart Team <support@wishcart.chat>
     * @license  GPL-2.0+ https://www.gnu.org/licenses/gpl-2.0.html
     * @link     https://wishcart.chat
     *
     * @return array Modified script data with contact form URL
     */
    public function add_form_data( $data ) {
        $data['contactFormUrl'] = get_permalink(get_page_by_path($this->page_slug));
        return $data;
    }

    /**
     * Ensure contact form page and template exist
     *
     * @category Setup
     * @package  AISK
     * @author   WishCart Team <support@wishcart.chat>
     * @license  GPL-2.0+ https://www.gnu.org/licenses/gpl-2.0.html
     * @link     https://wishcart.chat
     *
     * @return void
     */
    public function ensure_page_and_template_exists() {
        $this->create_theme_template();

        $existing_page = get_page_by_path($this->page_slug);

        if ( ! $existing_page ) {
            // Create the contact form page
            $page_data = array(
                'post_title'    => 'WishCart Contact Form',
                'post_name'     => $this->page_slug,
                'post_status'   => 'publish',
                'post_type'     => 'page',
                'post_content'  => '',
            );

            $page_id = wp_insert_post($page_data);

            if ( ! is_wp_error($page_id) ) {
                update_post_meta($page_id, '_wp_page_template', $this->template_name);
            }
        } else {
            // Update existing page template if necessary
            $current_template = get_post_meta($existing_page->ID, '_wp_page_template', true);
            if ( $current_template !== $this->template_name ) {
                update_post_meta($existing_page->ID, '_wp_page_template', $this->template_name);
            }
        }

        $this->maybe_publish_form_page();
    }

    /**
     * Create contact form template in theme directory
     *
     * @category Setup
     * @package  AISK
     * @author   WishCart Team <support@wishcart.chat>
     * @license  GPL-2.0+ https://www.gnu.org/licenses/gpl-2.0.html
     * @link     https://wishcart.chat
     *
     * @return void
     */
    private function create_theme_template() {
        $theme_template_path = get_template_directory() . '/' . $this->template_name;

        // Only create template if it doesn't exist in theme directory
        if ( ! file_exists($theme_template_path) ) {
            // Create templates directory if it doesn't exist
            wp_mkdir_p(dirname($theme_template_path));

            // Copy template from plugin
            $plugin_template = WISHCART_PLUGIN_DIR . '/includes/' . $this->template_name;
            if ( file_exists($plugin_template) ) {
                copy($plugin_template, $theme_template_path);
            }
        }
    }

    /**
     * Disable contact form page by setting status to draft
     *
     * @category Management
     * @package  AISK
     * @author   WishCart Team <support@wishcart.chat>
     * @license  GPL-2.0+ https://www.gnu.org/licenses/gpl-2.0.html
     * @link     https://wishcart.chat
     *
     * @return void
     */
    private function maybe_disable_form_page() {
        $page = get_page_by_path($this->page_slug);
        if ( $page ) {
            // Instead of deleting, we'll update the status to draft
            wp_update_post(
                [
					'ID' => $page->ID,
					'post_status' => 'draft',
                ]
            );
        }
    }

    /**
     * Publish contact form page if it's in draft status
     *
     * @category Management
     * @package  AISK
     * @author   WishCart Team <support@wishcart.chat>
     * @license  GPL-2.0+ https://www.gnu.org/licenses/gpl-2.0.html
     * @link     https://wishcart.chat
     *
     * @return void
     */
    private function maybe_publish_form_page() {
        // Get page in any status, not just published
        $page = get_page_by_path($this->page_slug, OBJECT, 'page');

        if ( 'draft' === $page->post_status && $page ) {
            // Update to publish if it's currently draft
            wp_update_post(
                [
					'ID' => $page->ID,
					'post_status' => 'publish',
                ]
            );
        }
    }
}
