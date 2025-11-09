<?php

/**
 * Plugin Name:  Aisk — AI Sales Chatbot for FluentCart | Knowledgebase & Support bot
 * Plugin URI:  https://aisk.chat
 * Description: AI chatbot for WordPress & FluentCart with WhatsApp & Telegram integration. Provides instant answers, product recommendations, order status, inquiry submit and much more.
 * Version:     1.0.0
 * Requires PHP: 7.4
 * Author:      Aisk Team <support@aisk.chat>
 * Author URI:  https://aisk.chat/
 * Contributors: aisk, zrshishir, sabbirxprt
 * Text Domain:  aisk-ai-chat-for-fluentcart
 * Domain Path: /languages/
 * License: GPL2
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 *
 * @category WordPress
 * @package  AISK
 * @author   Aisk Team <support@aisk.chat>
 * @license  GPL-2.0+ http://www.gnu.org/licenses/gpl-2.0.txt
 * @link     https://aisk.chat
 *
 * Third-party Libraries:
 * - Smalot/PdfParser: Required for PDF text extraction and processing
 
 */


if (defined('WP_DEBUG') && WP_DEBUG) {
    ob_start();
    register_activation_hook(__FILE__, function() {
        $output = ob_get_contents();
        if (!empty($output)) {
            // Silent handling of activation output in production
            return;
        }
    });
}

if ( ! defined('ABSPATH') ) {
	exit;
}

// Load Composer autoloader if available
if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    // Log error but don't stop plugin execution
    if ( defined('WP_DEBUG') && WP_DEBUG ) {
        // error_log('Aisk AI Chat: Composer autoloader not found. Some features may not work properly.');
    }
}

/**
 * Main plugin class for AISK AI Chatbot
 *
 * Handles initialization, dependencies loading, and core functionality
 * of the AISK chatbot plugin for WordPress and FluentCart.
 *
 * @category WordPress
 * @package  AISK
 * @author   Aisk Team <support@aisk.chat>
 * @license  GPL-2.0+ http://www.gnu.org/licenses/gpl-2.0.txt
 * @link     https://aisk.chat
 */
class AISK_AI_Chatbot {

    private static $instance = null;
    private $pdf_queue_handler = null;

    /**
     * Get singleton instance of this class
     *
     * @return AISK_AI_Chatbot Instance of this class
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor for initializing the plugin
     */
    private function __construct() {
        // Define constants
        define('AISK_PLUGIN_FILE', __FILE__);
        define('AISK_VERSION', '1.0.0');
        define('AISK_PLUGIN_DIR', plugin_dir_path(__FILE__));
        define('AISK_PLUGIN_URL', plugin_dir_url(__FILE__));
        define('AISK_TEXT_DOMAIN', 'aisk-ai-chat-for-fluentcart');
        define('AISK_CHAT_SESSION_COOKIE', 'aisk_chat_session');
        define('AISK_GREAP_KEY', 'zcP5AsW6bwGApWF5NdyF4TvyKZs6w7KP');
        define('AISK_APPSERO_TOKEN', '444f5bd6-5aae-4079-8757-43e72a1180c4');
        define('AISK_OPENAI_API_URL', 'https://api.openai.com/v1');


        // Initialize components
        add_action('init', [ $this, 'init' ]);
        add_action('init', [ $this, 'aisk_increase_upload_limits' ]);
        register_activation_hook(__FILE__, [ $this, 'activate' ]);

        // Ensure API Usage table exists on upgrade to 2.5.0+
        // Runs on every load but only acts when needed
        add_action('plugins_loaded', [ $this, 'ensure_api_usage_table_on_upgrade' ], 1);
        
        $this->include_appsero_client();
        $this->appsero_init_tracker_aisk_ai_chat();

        // Load required files
        $this->load_dependencies();
        
        // Initialize PDF queue handler
        $this->init_pdf_queue_handler();

        add_action( 'admin_enqueue_scripts', [ $this, 'gleap_widget_script' ] );
    }

    /**
     * Generate Gleap inline script for admin
     *
     * @param WP_User $user
     * @param string $user_role
     * @return string
     */
    private function get_gleap_inline_script( $user, $user_role ) {
        return wp_sprintf(
            '!function(Gleap,t,i){
                if(!(Gleap=window.Gleap=window.Gleap||[]).invoked){for(window.GleapActions=[],Gleap.invoked=!0,Gleap.methods=["identify","setEnvironment","setTicketAttribute","setTags","attachCustomData","setCustomData","removeCustomData","clearCustomData","registerCustomAction","trackEvent","setUseCookies","log","preFillForm","showSurvey","sendSilentCrashReport","startFeedbackFlow","startBot","setAppBuildNumber","setAppVersionCode","setApiUrl","setFrameUrl","isOpened","open","close","on","setLanguage","setOfflineMode","startClassicForm","initialize","disableConsoleLogOverwrite","logEvent","hide","enableShortcuts","showFeedbackButton","destroy","getIdentity","isUserIdentified","clearIdentity","openConversations","openConversation","openHelpCenterCollection","openHelpCenterArticle","openHelpCenter","searchHelpCenter","openNewsArticle","openChecklists","startChecklist","openNews","openFeatureRequests","isLiveMode"],Gleap.f=function(e){return function(){var t=Array.prototype.slice.call(arguments);window.GleapActions.push({e:e,a:t})}},t=0;t<Gleap.methods.length;t++)Gleap[i=Gleap.methods[t]]=Gleap.f(i);Gleap.load=function(){var t=document.getElementsByTagName("head")[0],i=document.createElement("script");i.type="text/javascript",i.async=!0,i.src="https://sdk.gleap.io/latest/index.js",t.appendChild(i)},Gleap.load();
                Gleap.identify("%s", {
                    email: "%s",
                    name: "%s",
                    role: "%s",
                    plugin_name: "AISK AI Chat",
                    version: "%s",
                    site_url: "%s",
                    is_multisite: "%s",
                    wp_version: "%s",
                    php_version: "%s",
                    theme_name: "%s",
                });
                Gleap.initialize("%s")
            }}();',
            esc_js( defined('NONCE_SALT') ? NONCE_SALT . '-' . $user->ID : $user->ID ),
            esc_js( $user->user_email ),
            esc_js( $user->display_name ),
            esc_js( $user_role ),
            esc_js( defined('AISK_VERSION') ? AISK_VERSION : '' ),
            esc_js( function_exists('get_site_url') ? get_site_url() : '' ),
            esc_js( function_exists('is_multisite') && is_multisite() ? 'Yes' : 'No' ),
            esc_js( function_exists('get_bloginfo') ? get_bloginfo('version') : '' ),
            esc_js( defined('PHP_VERSION') ? PHP_VERSION : '' ),
            esc_js( function_exists('get_bloginfo') ? get_bloginfo('name') : '' ),
            esc_js( defined('AISK_GREAP_KEY') ? AISK_GREAP_KEY : '' )
        );
    }

    /**
     * Gleap widget script
     *
     * @return void
     */
    public function gleap_widget_script() {
        // Only load on stock notifier plugin pages
        $screen = get_current_screen();
        $current_page = isset($_GET['page']) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
        
        // Verify nonce for security
        if (isset($_GET['page']) && !wp_verify_nonce(
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verification handles sanitization internally
            wp_unslash($_GET['_wpnonce'] ?? ''), 'aisk_admin_nonce')) {
            return;
        }

        
        // Check if we're on stock notifier pages
        if (
            (!$screen || strpos($screen->base, 'aisk') === false) && 
            $current_page !== 'aisk-inquiries' && $current_page !== 'aisk-history' && $current_page !== 'aisk-uses' && $current_page !== 'aisk-settings'
        ) {
            return;
        }
        
        $user = wp_get_current_user();
        $user_role = ( !empty($user->roles) && isset($user->roles[0]) ) ? $user->roles[0] : '';

        wp_register_script( 'aisk-gleap-dummy', false, [], AISK_VERSION, true );
        wp_enqueue_script( 'aisk-gleap-dummy' );
        wp_add_inline_script( 'aisk-gleap-dummy', $this->get_gleap_inline_script( $user, $user_role ) );
    }

    /**
     * Initialize the plugin tracker
     *
     * @return void
     */
    public function appsero_init_tracker_aisk_ai_chat() {
        $client = new Aisk_Ai_Chat\Appsero\Client( AISK_APPSERO_TOKEN, 'Aisk — AI Sales Chatbot for FluentCart | Knowledgebase & Support bot', AISK_PLUGIN_FILE );

        // Active insights.
        $client->insights()->init();
    }

    /**
     * Includes the Appsero client by creating a class alias if necessary.
     *
     * If the 'Appsero\Client' class is not defined, it will create an alias to
     * 'AISK_AI_CHAT\Appsero\Client'.
     *
     * @version 1.0.0
     * @return void
     */
    public function include_appsero_client() {
        if ( ! class_exists( 'Aisk_Ai_Chat\Appsero\Client' ) ) {
            require_once __DIR__ . '/appsero/client/src/Client.php';
        }

        if ( ! class_exists('Aisk_Ai_Chat\Appsero\Client') ) {
            class_alias('Aisk_Ai_Chat\Appsero\Client','Aisk_Ai_Chat\Appsero\Client');
        }
    }
    /**
     * Initialize the PDF queue handler
     */
    private function init_pdf_queue_handler() {
        $this->pdf_queue_handler = new AISK_PDF_Queue_Handler();
        add_action('wp_loaded', [ $this, 'handle_pdf_queue_processing' ]);
    }

    /**
     * Handle PDF queue processing requests
     */
    public function handle_pdf_queue_processing() {
        // Check for real cron request and verify nonce
        if (!isset($_GET['aisk_cron']) || $_GET['aisk_cron'] !== 'process_pdf_queue' || 
            !isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'aisk_pdf_queue')) {
            return;
        }

        // Verify the security key
        if (!isset($_GET['key']) || $_GET['key'] !== wp_hash('aisk_pdf_queue')) {
            wp_die('Invalid security key', 'Security Error', array('response' => 403));
        }

        // Process the queue
        $processed = $this->pdf_queue_handler->process_pending_jobs();
        
        // Return JSON response
        wp_send_json(array(
            'success' => true,
            'processed' => $processed,
            'timestamp' => current_time('mysql')
        ));
    }

    function aisk_increase_upload_limits() {
        // Increase PHP limits using WordPress filters instead of ini_set
        add_filter('upload_max_filesize', function() { return '128M'; });
        add_filter('post_max_size', function() { return '128M'; });
        add_filter('memory_limit', function() { return '512M'; });
        add_filter('max_execution_time', function() { return 600; }); // 10 minutes
        add_filter('max_input_time', function() { return 600; });
        
        // Increase WordPress limits
        add_filter('upload_size_limit', function($size) {
            return 64 * 1024 * 1024; // 64MB
        });
        
        // Try to modify .htaccess if we have access
        if (is_admin() && current_user_can('manage_options')) {
            global $wp_filesystem;
            if (empty($wp_filesystem)) {
                require_once(ABSPATH . '/wp-admin/includes/file.php');
                WP_Filesystem();
            }
            $htaccess_path = ABSPATH . '.htaccess';
            if ($wp_filesystem->is_writable($htaccess_path)) {
                $htaccess_content = $wp_filesystem->get_contents($htaccess_path);
                
                // Check if our rules already exist
                if (strpos($htaccess_content, '# BEGIN AISK Upload Limits') === false) {
                    $upload_rules = "
                        # BEGIN AISK Upload Limits
                        <IfModule mod_php7.c>
                            php_value upload_max_filesize 128M
                            php_value post_max_size 128M
                            php_value memory_limit 512M
                            php_value max_execution_time 600
                            php_value max_input_time 600
                        </IfModule>
                        <IfModule mod_php.c>
                            php_value upload_max_filesize 128M
                            php_value post_max_size 128M
                            php_value memory_limit 512M
                            php_value max_execution_time 600
                            php_value max_input_time 600
                        </IfModule>
                        # END AISK Upload Limits
                        ";
                    // Add our rules after the WordPress rules
                    $htaccess_content = preg_replace('/(# BEGIN WordPress[\s\S]+?# END WordPress)/', '$1' . "\n" . $upload_rules, $htaccess_content);
                    file_put_contents($htaccess_path, $htaccess_content);
                }
            }
        }
    }
    
    /**
     * Initialize plugin hooks and features
     *
     * @return void
     */
    public function init() {
        // Enqueue scripts
        add_action('wp_enqueue_scripts', [ $this, 'enqueue_scripts' ]);

        // Add chat widget to footer
        add_action('wp_footer', [ $this, 'render_chat_widget' ]);

        $this->maybe_load_integrations();

        // Initialize contact form handler if enabled
        add_action('wp_loaded', [ 'AISK_Contact_Form_Handler', 'maybe_init' ]);

        // Clear FluentCart detection cache when plugins are activated/deactivated
        add_action('activated_plugin', [ $this, 'clear_fluentcart_cache' ]);
        add_action('deactivated_plugin', [ $this, 'clear_fluentcart_cache' ]);
    }

    /**
     * Clear FluentCart detection cache when plugins change
     *
     * @param string $plugin Plugin path
     * @return void
     */
    public function clear_fluentcart_cache( $plugin ) {
        // Clear cache if FluentCart or this plugin was activated/deactivated
        if ( strpos( $plugin, 'fluentcart' ) !== false || 
             strpos( $plugin, 'fluent-cart' ) !== false ||
             strpos( $plugin, 'aisk-ai-chat-for-fluentcart' ) !== false ) {
            if ( class_exists( 'AISK_FluentCart_Helper' ) ) {
                AISK_FluentCart_Helper::clear_detection_cache();
            }
        }
    }

    /**
     * Load required plugin files and dependencies
     *
     * @return void
     */
    private function load_dependencies() {
        include_once AISK_PLUGIN_DIR . 'includes/class-database.php';
        include_once AISK_PLUGIN_DIR . 'includes/class-api-usage-tracker.php';
        include_once AISK_PLUGIN_DIR . 'includes/class-fluentcart-helper.php';
        include_once AISK_PLUGIN_DIR . 'includes/class-embeddings-handler.php';
        include_once AISK_PLUGIN_DIR . 'includes/class-external-embeddings-handler.php';
        include_once AISK_PLUGIN_DIR . 'includes/class-product-handler.php';
        include_once AISK_PLUGIN_DIR . 'includes/class-order-handler.php';
        include_once AISK_PLUGIN_DIR . 'includes/class-chat-storage.php';
        include_once AISK_PLUGIN_DIR . 'includes/class-response-formatter.php';
        include_once AISK_PLUGIN_DIR . 'includes/class-chat-handler.php';
        include_once AISK_PLUGIN_DIR . 'includes/class-script-loader.php';
        include_once AISK_PLUGIN_DIR . 'includes/class-aisk-admin.php';
        include_once AISK_PLUGIN_DIR . 'includes/services/queue/class-pdf-queue-handler.php';

        // Initialize admin class
        if (is_admin()) {
            add_action('admin_init', function() {
                AISK_Admin::get_instance();
            });
        }

        // Load contact form feature handlers
        include_once AISK_PLUGIN_DIR . 'includes/features/class-contact-form-handler.php';

        // Ensure database tables exist even after updates (without reactivation)
        // Safe to call: dbDelta is idempotent
        try { new AISK_Database(); } catch ( \Throwable $e ) {}

        // Initialize PDF Queue Handler
        new AISK_PDF_Queue_Handler();
    }

    /**
     * Ensure `aisk_api_usage` exists when upgrading to >= 2.5.0
     * Mirrors the user's version-gated pattern
     *
     * @return void
     */
    public function ensure_api_usage_table_on_upgrade() {
        global $wpdb;

        $stored_version = get_option('aisk_plugin_version', '1.0.0');
        $current_version = defined('AISK_VERSION') ? AISK_VERSION : '0.0.0';

        $stored_num = floatval(str_replace('.', '', $stored_version));
        $current_num = floatval(str_replace('.', '', $current_version));
        $threshold_num = floatval(str_replace('.', '', '2.5.0'));

        // Only act when upgrading to >= 2.5.0 from a lower version
        if ($stored_num < $threshold_num && $current_num >= $threshold_num) {
            $table_name = $wpdb->prefix . 'aisk_api_usage';

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table existence check during upgrade needs real-time data
            $exists = $wpdb->get_var(
                $wpdb->prepare('SHOW TABLES LIKE %s', $table_name)
            );
            if ($exists !== $table_name) {
                $charset_collate = $wpdb->get_charset_collate();
                $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
                    id bigint(20) NOT NULL AUTO_INCREMENT,
                    request_id varchar(100) NOT NULL,
                    conversation_id varchar(50),
                    user_id bigint(20),
                    user_role varchar(50),
                    channel varchar(50) NOT NULL,
                    feature varchar(50) NOT NULL,
                    provider varchar(50) NOT NULL,
                    endpoint varchar(255),
                    model varchar(100),
                    status varchar(20) NOT NULL,
                    error_code varchar(100),
                    latency_ms int(11),
                    tokens_in int(11) DEFAULT 0,
                    tokens_out int(11) DEFAULT 0,
                    cost_usd decimal(10,4) DEFAULT 0.0000,
                    ip_hash varchar(64),
                    country varchar(100),
                    url varchar(255),
                    context_id varchar(100),
                    metadata JSON,
                    created_at datetime DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY request_id (request_id),
                    KEY conversation_id (conversation_id),
                    KEY user_id (user_id),
                    KEY channel (channel),
                    KEY feature (feature),
                    KEY provider (provider),
                    KEY status (status),
                    KEY created_at (created_at),
                    KEY provider_feature (provider, feature),
                    KEY created_at_provider (created_at, provider),
                    KEY created_at_feature (created_at, feature)
                ) {$charset_collate};";

                include_once ABSPATH . 'wp-admin/includes/upgrade.php';
                dbDelta($sql);
            }
        }
    }

    /**
     * Load integration modules if enabled in settings
     *
     * @return void
     */
    private function maybe_load_integrations() {
        $settings = get_option('aisk_settings', []);

        // Load WhatsApp class if enabled
        if ( ! empty($settings['integrations']['whatsapp']['enabled']) ) {
            include_once AISK_PLUGIN_DIR . 'includes/messenger/class-whatsapp-handler.php';
            new AISK_WhatsApp_Handler();
        }

        // Load Telegram class if enabled
        if ( ! empty($settings['integrations']['telegram']['enabled']) ) {
            include_once AISK_PLUGIN_DIR . 'includes/messenger/class-telegram-handler.php';
            new AISK_Telegram_Handler();
        }
    }
    

    /**
     * Handle plugin activation aisks
     *
     * @return void
     */
    public function activate() {
        // Initialize contact form if enabled
        $contact_form = AISK_Contact_Form_Handler::get_instance();
        if ( $contact_form->is_enabled() ) {
            $contact_form->ensure_page_and_template_exists();
        }

        new AISK_Database();
    }

    /**
     * Enqueue required frontend scripts and styles
     *
     * @return void
     */
    public function enqueue_scripts() {
        if ( ! is_page('aisk-contact-form') ) {
            AISK_Scripts::load_chat_widget_assets();
        }
    }

    /**
     * Render the chat widget container in footer
     *
     * @return void
     */
    public function render_chat_widget() {
        // Check if we're on the contact form page
        if ( ! is_page('aisk-contact-form') ) {
            echo '<div id="aisk-chat-widget"></div>';
        }
    }

}

// Initialize the plugin
AISK_AI_Chatbot::get_instance();