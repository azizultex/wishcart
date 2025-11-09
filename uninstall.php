<?php

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

class AISK_Uninstaller {

    /**
     * Array of table names
     *
     * @var array
     */
    private $tables;

    /**
     * Array of option names
     *
     * @var array
     */
    private $options;

    /**
     * Initialize the uninstaller
     */
    public function __construct() {
        $this->tables = [
            'aisk_conversations',
            'aisk_messages',
            'aisk_user_states',
            'aisk_inquiries',
            'aisk_inquiry_notes',
            'aisk_embeddings',
            'aisk_pdf_queue',
            'aisk_api_usage',
        ];

        $this->options = [
            'aisk_settings',
            'aisk_last_pdf_processing',
        ];
    }

    /**
     * Run the uninstallation process
     */
    public function uninstall() {
        $this->drop_tables();
        $this->remove_options();
        $this->clear_cache();
    }

    /**
     * Drop all plugin tables
     */
    private function drop_tables() {
        global $wpdb;
        // @codingStandardsIgnoreStart
        foreach ( $this->tables as $table ) {
            $table_name = $wpdb->prefix . $table;
            $wpdb->query("DROP TABLE IF EXISTS `" . esc_sql($table_name) . "`");
        }
        // @codingStandardsIgnoreEnd
    }

    /**
     * Remove all plugin options
     */
    private function remove_options() {
        // @codingStandardsIgnoreStart
        foreach ( $this->options as $option ) {
			delete_option( $option );
        }
        // @codingStandardsIgnoreEnd
    }

    /**
     * Clear WordPress cache
     */
    private function clear_cache() {
        // @codingStandardsIgnoreStart
        wp_cache_flush();
        // @codingStandardsIgnoreEnd
    }
}

// Execute the uninstallation
$uninstaller = new AISK_Uninstaller();
$uninstaller->uninstall();
