<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Database handling class for AISK plugin
 *
 * @category WordPress
 * @package  AISK
 * @author   Aisk Team <support@aisk.chat>
 * @license  GPL-2.0+ https://www.gnu.org/licenses/gpl-2.0.html
 * @link     https://aisk.chat
 */

/**
 * AISK_Database Class
 *
 * @category WordPress
 * @package  AISK
 * @author   Aisk Team <support@aisk.chat>
 * @license  GPL-2.0+ https://www.gnu.org/licenses/gpl-2.0.html
 * @link     https://aisk.chat
 */
class AISK_Database {


    private $wpdb;
    private $table_prefix;
    private $embedding_table;

    /**
     * Initialize the database class
     *
     * @return void
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_prefix = $wpdb->prefix;
        $this->embedding_table = $this->table_prefix . 'aisk_embeddings';
		
		$this->log_debug('AISK_Database::__construct start');
		// Ensure tables are created using the previously stored version
		// so version-based creation logic (e.g., aisk_api_usage) can run.
		$this->create_tables();
		$this->check_version_upgrade();
		$this->log_debug('AISK_Database::__construct end');
    }

    /**
     * Create required database tables
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function create_tables() {
		$charset_collate = $this->wpdb->get_charset_collate();
		$this->log_debug('create_tables: start');

        $sql_conversations = "CREATE TABLE IF NOT EXISTS {$this->table_prefix}aisk_conversations (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            conversation_id varchar(50) NOT NULL,
            user_id bigint(20),
            user_name varchar(100),
            user_email varchar(100),
            user_phone varchar(50),
            platform varchar(20) DEFAULT 'web',
            ip_address varchar(45),
            city varchar(100),
            country varchar(100),
            country_code varchar(2),
            intents JSON,
            user_agent varchar(255),
            page_url varchar(255),
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY conversation_id (conversation_id),
            KEY user_id (user_id),
            KEY user_phone (user_phone),
            KEY platform (platform),
            KEY city (city),
            KEY country (country)
        ) $charset_collate;";

        // Messages table
        $sql_messages = "CREATE TABLE IF NOT EXISTS {$this->table_prefix}aisk_messages (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            conversation_id varchar(50) NOT NULL,
            message_type enum('user', 'bot') NOT NULL,
            message TEXT NOT NULL,
            metadata JSON,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY conversation_id (conversation_id),
            KEY message_type (message_type)
        ) $charset_collate;";

        // Add new table for user states
        $sql_states = "CREATE TABLE IF NOT EXISTS {$this->table_prefix}aisk_user_states (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            platform_user_id varchar(50) NOT NULL,
            platform varchar(20) NOT NULL,
            state_data JSON,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY platform_user (platform_user_id, platform)
        ) $charset_collate;";

        $sql_inquiries = "CREATE TABLE IF NOT EXISTS {$this->table_prefix}aisk_inquiries (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            conversation_id varchar(50) NOT NULL,
            order_number varchar(50) NOT NULL,
            customer_email varchar(100),
            customer_phone varchar(30),
            note TEXT NOT NULL,
            status varchar(20) DEFAULT 'pending',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY conversation_id (conversation_id),
            KEY order_number (order_number),
            KEY status (status)
        ) $charset_collate;";

        $sql_inquiry_notes = "CREATE TABLE IF NOT EXISTS {$this->table_prefix}aisk_inquiry_notes (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            inquiry_id bigint(20) NOT NULL,
            note text NOT NULL,
            author_id bigint(20) NOT NULL,
            author varchar(100) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY inquiry_id (inquiry_id)
        ) $charset_collate;";

        $sql_embeddings = "CREATE TABLE IF NOT EXISTS {$this->table_prefix}aisk_embeddings (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            content_type varchar(50) NOT NULL,
            content_id bigint(20) NOT NULL,
            crawled_url varchar(255) DEFAULT NULL,
            file_path varchar(255) DEFAULT NULL,
            embedding longtext NOT NULL,
            content_chunk longtext NOT NULL,
            parent_url varchar(255) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY content_type_id (content_type, content_id),
            KEY parent_url (parent_url)
        ) {$charset_collate};";

		$sql_api_usage = $this->get_api_usage_table_sql($this->table_prefix . 'aisk_api_usage', $charset_collate);

        include_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta($sql_conversations);
        dbDelta($sql_messages);
        dbDelta($sql_states);
        dbDelta($sql_inquiries);
        dbDelta($sql_inquiry_notes);
        dbDelta($sql_embeddings);
        
		// Only create API usage table if it's a fresh install (2.5.0+) or upgrade from 2.4.1
		$should_create = $this->should_create_api_usage_table();
		$this->log_debug('create_tables: should_create_api_usage_table=' . ($should_create ? 'true' : 'false'));
		if ($should_create) {
			$result = dbDelta($sql_api_usage);
			$this->log_debug('create_tables: dbDelta(api_usage) outcome=' . json_encode(array_values($result)));
		}

		// Safety net: ensure the API usage table exists regardless of version option state
		$api_usage_table = $this->table_prefix . 'aisk_api_usage';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- False positive, this is properly prepared
		$prepared_query = $this->wpdb->prepare('SHOW TABLES LIKE %s', $api_usage_table);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Using a pre-prepared query variable
		$existing_table = $this->wpdb->get_var($prepared_query);
		$this->log_debug('create_tables: SHOW TABLES LIKE result=' . ($existing_table ? $existing_table : 'null'));
		if ($existing_table !== $api_usage_table) {
			$this->log_debug('create_tables: safety net creating aisk_api_usage');
			$result2 = dbDelta($sql_api_usage);
			$this->log_debug('create_tables: safety net dbDelta outcome=' . json_encode(array_values($result2)));
		}
		$this->log_debug('create_tables: end');
    }

	/**
	 * Build SQL for creating the aisk_api_usage table with a single source of truth
	 *
	 * @param string $table_name
	 * @param string $charset_collate
	 * @return string
	 */
	private function get_api_usage_table_sql($table_name, $charset_collate) {
		return "CREATE TABLE IF NOT EXISTS {$table_name} (
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
	}



    /**
     * Store embedding data in database
     *
     * @param array $data Embedding data to store
     *
     * @since 1.0.0
     *
     * @return int|false Number of rows affected or false on error
     */
    public function store_embedding( $data ) {
        // Remove any existing embeddings for this content
        $this->delete_embedding($data['content_type'], $data['content_id']);

        // Insert new embedding
        return $this->wpdb->insert(
            $this->embedding_table,
            [
                'content_type' => $data['content_type'],
                'content_id' => $data['content_id'],
                'embedding' => $data['embedding'],
                'content_chunk' => $data['content_chunk'],
            ],
            [ '%s', '%d', '%s', '%s' ]
        );
    }

    /**
     * Delete embedding for specific content
     *
     * @param string $content_type Content type to delete
     * @param int    $content_id   Content ID to delete
     *
     * @since 1.0.0
     *
     * @return int|false Number of rows affected or false on error
     */
    public function delete_embedding( $content_type, $content_id ) {
        return $this->wpdb->delete(
            $this->embedding_table,
            [
                'content_type' => $content_type,
                'content_id' => $content_id,
            ],
            [ '%s', '%d' ]
        );
    }

    /**
     * Get embeddings for specific content
     *
     * @param string $content_type Content type to retrieve
     * @param int    $content_id   Content ID to retrieve
     *
     * @since 1.0.0
     *
     * @return array Array of embedding results
     */
    public function get_embeddings($content_type, $content_id)
    {
        $cache_key = 'aisk_embedding_' . $content_type . '_' . $content_id;
        $embeddings = wp_cache_get($cache_key, 'aisk_embeddings');
        
        if (false === $embeddings) {
            // @codingStandardsIgnoreStart
            global $wpdb;
            
            $embeddings = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$this->embedding_table} 
                    WHERE content_type = %s AND content_id = %d",
                    $content_type,
                    $content_id
                )
            );
            // @codingStandardsIgnoreEnd
            
            if ($embeddings) {
                wp_cache_set($cache_key, $embeddings, 'aisk_embeddings', 3600); // Cache for 1 hour
            }
        }
        
        return $embeddings ?: array();
    }

    /**
     * Get all embeddings from database
     *
     * @since 1.0.0
     *
     * @return array Array of all embeddings
     */
    public function get_all_embeddings()
    {
        $cache_key = 'aisk_all_embeddings';
        $embeddings = wp_cache_get($cache_key, 'aisk_embeddings');
        
        if (false === $embeddings) {
            // @codingStandardsIgnoreStart
            global $wpdb;
            
            $embeddings = $wpdb->get_results(
                "SELECT * FROM {$this->embedding_table}"
            );
            // @codingStandardsIgnoreEnd
            
            if ($embeddings) {
                wp_cache_set($cache_key, $embeddings, 'aisk_embeddings', 3600); // Cache for 1 hour
            }
        }
        
        return $embeddings ?: array();
    }

    /**
     * Clear all embeddings from database
     *
     * @since 1.0.0
     *
     * @return int|false Number of rows affected or false on error
     */
    public function clear_all_embeddings() {
        global $wpdb;
        return $wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %s', $this->embedding_table ) );
    }

    /**
     * Store API usage data in database
     *
     * @param array $data API usage data to store
     *
     * @since 1.0.0
     *
     * @return int|false Number of rows affected or false on error
     */
    public function store_api_usage( $data ) {
        $table_name = $this->table_prefix . 'aisk_api_usage';
        
        return $this->wpdb->insert(
            $table_name,
            [
                'request_id' => $data['request_id'],
                'conversation_id' => $data['conversation_id'] ?? null,
                'user_id' => $data['user_id'] ?? null,
                'user_role' => $data['user_role'] ?? null,
                'channel' => $data['channel'],
                'feature' => $data['feature'],
                'provider' => $data['provider'],
                'endpoint' => $data['endpoint'] ?? null,
                'model' => $data['model'] ?? null,
                'status' => $data['status'],
                'error_code' => $data['error_code'] ?? null,
                'latency_ms' => $data['latency_ms'] ?? null,
                'tokens_in' => $data['tokens_in'] ?? 0,
                'tokens_out' => $data['tokens_out'] ?? 0,
                'cost_usd' => $data['cost_usd'] ?? 0.0000,
                'ip_hash' => $data['ip_hash'] ?? null,
                'country' => $data['country'] ?? null,
                'url' => $data['url'] ?? null,
                'context_id' => $data['context_id'] ?? null,
                'metadata' => isset($data['metadata']) ? json_encode($data['metadata']) : null,
            ],
            [
                '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', 
                '%s', '%d', '%d', '%d', '%f', '%s', '%s', '%s', '%s', '%s'
            ]
        );
    }

    /**
     * Check if this is a version upgrade and handle accordingly
     *
     * @since 2.5.0
     *
     * @return void
     */
    private function check_version_upgrade() {
        $stored_version = get_option('aisk_plugin_version', '1.0.0');
        $current_version = AISK_VERSION;
		$this->log_debug('check_version_upgrade: stored_version=' . $stored_version . ', current_version=' . $current_version);
        // If versions are different, this is an upgrade
        if (version_compare($stored_version, $current_version, '!=')) {
            // Update the stored version
            update_option('aisk_plugin_version', $current_version);
            $this->log_debug("check_version_upgrade: updated option aisk_plugin_version to {$current_version}");
        }
    }

    /**
     * Determine if the API usage table should be created
     *
     * @since 2.5.0
     *
     * @return bool True if table should be created, false otherwise
     */
    private function should_create_api_usage_table() {
        $stored_version = get_option('aisk_plugin_version', '1.0.0');
        $current_version = AISK_VERSION;
		$this->log_debug('should_create_api_usage_table: stored=' . $stored_version . ', current=' . $current_version);
        // Case 1: Fresh install of 2.5.0+ (no stored version or very old version)
        if (version_compare($stored_version, '2.5.0', '<')) {
			$this->log_debug('should_create_api_usage_table: CASE1 true');
            return true;
        }
        
        // Case 2: Upgrade from 2.4.1 to 2.5.0+
        if (version_compare($stored_version, '2.4.1', '>=') && 
            version_compare($stored_version, '2.5.0', '<') && 
            version_compare($current_version, '2.5.0', '>=')) {
			$this->log_debug('should_create_api_usage_table: CASE2 true');
            return true;
        }
        
        // Case 3: Already on 2.5.0+ - don't recreate
		$this->log_debug('should_create_api_usage_table: CASE3 false');
        return false;
    }

	/**
	 * Lightweight debug logger
	 *
	 * @param string $message
	 * @return void
	 */
	private function log_debug($message) {
		if (defined('WP_DEBUG') && WP_DEBUG) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging is properly guarded
			error_log('[AISK DB] ' . $message);
		}
	}
}
