<?php
if ( ! defined('ABSPATH') ) {
    exit;
}

/**
 * Script and style loader functionality
 *
 * @category Functionality
 * @package  AISK
 * @author   Aisk Team <support@aisk.chat>
 * @license  GPL-2.0+ https://www.gnu.org/licenses/gpl-2.0.html
 * @link     https://aisk.chat
 */

/**
 * AISK_Scripts Class
 *
 * Handles loading and localization of scripts and styles for the chat widget
 *
 * @category Class
 * @package  AISK
 * @author   Aisk Team <support@aisk.chat>
 * @license  GPL-2.0+ https://www.gnu.org/licenses/gpl-2.0.html
 * @link     https://aisk.chat
 */
class AISK_Scripts {

    /**
     * Loads chat widget assets and localizes settings
     *
     * Enqueues required CSS/JS files and prepares frontend settings
     * for the chat widget functionality.
     *
     * @return array Processed frontend settings
     */
    public static function load_chat_widget_assets() {
        // Register and enqueue chat widget styles
        wp_register_style(
            'aisk-chat-widget-styles',
            AISK_PLUGIN_URL . 'build/chat-widget.css',
            [],
            AISK_VERSION
        );
        wp_enqueue_style('aisk-chat-widget-styles');

        // Register and enqueue chat formatter styles
        wp_register_style(
            'aisk-chat-formatter-styles',
            AISK_PLUGIN_URL . 'assets/css/chat-formatter.css',
            ['aisk-chat-widget-styles'],
            AISK_VERSION
        );
        wp_enqueue_style('aisk-chat-formatter-styles');

        // Register and enqueue chat widget scripts
        wp_register_script(
            'aisk-chat-widget',
            AISK_PLUGIN_URL . 'build/chat-widget.js',
            ['wp-element', 'react', 'react-dom'],
            AISK_VERSION,
            [
                'in_footer' => true,
                'strategy' => 'defer'
            ]
        );
        wp_enqueue_script('aisk-chat-widget');

        // Get settings
        $settings = get_option('aisk_settings', []);
        // Create frontend-only settings object
        $frontend_settings = [
            'chatwidget' => [
                'chat_icon' => isset($settings['chatwidget']['chat_icon']) ? $settings['chatwidget']['chat_icon'] : '',
                'widget_logo' => isset($settings['chatwidget']['widget_logo']) ? $settings['chatwidget']['widget_logo'] : '',
                'widget_text' => isset($settings['chatwidget']['widget_text']) && "" !== $settings['chatwidget']['widget_text'] ? $settings['chatwidget']['widget_text'] : get_bloginfo('name'),
                'widget_color' => isset($settings['chatwidget']['widget_color']) ? $settings['chatwidget']['widget_color'] : '#4F46E5',
                'suggested_questions' => isset($settings['chatwidget']['suggested_questions']) ? $settings['chatwidget']['suggested_questions'] : [],
                'widget_position' => isset($settings['chatwidget']['widget_position']) ? $settings['chatwidget']['widget_position'] : 'bottom-right',
                'widget_greeting' => isset($settings['chatwidget']['widget_greeting']) && "" !== $settings['chatwidget']['widget_greeting'] ? $settings['chatwidget']['widget_greeting'] : 'Hi there! How can I help you?',
                'widget_placeholder' => isset($settings['chatwidget']['widget_placeholder']) && "" !== $settings['chatwidget']['widget_placeholder'] ? $settings['chatwidget']['widget_placeholder'] : 'Type your message...',
                'widget_title' => isset($settings['chatwidget']['widget_title']) ? $settings['chatwidget']['widget_title'] : '',
                'widget_subtitle' => isset($settings['chatwidget']['widget_subtitle']) ? $settings['chatwidget']['widget_subtitle'] : '',
                'bubble_type' => isset($settings['chatwidget']['bubble_type']) ? $settings['chatwidget']['bubble_type'] : 'default',
                'default_message' => isset($settings['chatwidget']['default_message']) ? $settings['chatwidget']['default_message'] : 'Hey, need help? 👋',
                'rolling_messages' => isset($settings['chatwidget']['rolling_messages']) ? $settings['chatwidget']['rolling_messages'] : [
                    '👋 Need help?',
                    '💬 Chat with us!',
                    '🛍️ Find products',
                ],
            ],
            'integrations' => [
                'whatsapp' => [
                    'enabled' => isset($settings['integrations']['whatsapp']['enabled']) ? $settings['integrations']['whatsapp']['enabled'] : false,
                    'phone_number' => isset($settings['integrations']['whatsapp']['phone_number']) ? $settings['integrations']['whatsapp']['phone_number'] : '',

                ],
                'telegram' => [
                    'enabled' => isset($settings['integrations']['telegram']['enabled']) ? $settings['integrations']['telegram']['enabled'] : false,
                    'bot_username' => isset($settings['integrations']['telegram']['bot_username']) ? $settings['integrations']['telegram']['bot_username'] : '',
                ],
                'contact_form' => [
                    'enabled' => isset($settings['integrations']['contact_form']['enabled']) ? $settings['integrations']['contact_form']['enabled'] : false,
                    'shortcode' => isset($settings['integrations']['contact_form']['shortcode']) ? $settings['integrations']['contact_form']['shortcode'] : '',
                ],
            ],
            'colors' => [
                'primary' => isset($settings['colors']['primary']) ? $settings['colors']['primary'] : '#4F46E5',
                'secondary' => isset($settings['colors']['secondary']) ? $settings['colors']['secondary'] : '#E0E7FF',
                'text' => isset($settings['colors']['text']) ? $settings['colors']['text'] : '#FFFFFF',
            ],
            // Essential system paths/urls
            'pluginUrl' => AISK_PLUGIN_URL,
            'apiUrl' => rest_url('aisk/v1'),
            'adminUrl' => admin_url('admin.php'),
            'nonce' => wp_create_nonce('wp_rest'),
        ];

        // Add custom CSS if exists
        if ( ! empty($settings['misc']['custom_css']) ) {
            wp_add_inline_style(
                'aisk-chat-widget-styles',
                esc_html( $settings['misc']['custom_css'] )
            );
        }

        // Allow features to modify settings
        $settings = apply_filters('aisk_script_data', $frontend_settings);

        // Localize script
        wp_localize_script('aisk-chat-widget', 'AiskData', $settings);

        return $settings;
    }
}
