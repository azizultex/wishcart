<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Database handling class for AISK plugin
 *
 * @category WordPress
 * @package  AISK
 * @author   WishCart Team <support@wishcart.chat>
 * @license  GPL-2.0+ https://www.gnu.org/licenses/gpl-2.0.html
 * @link     https://wishcart.chat
 */

/**
 * WISHCART_Database Class
 *
 * @category WordPress
 * @package  AISK
 * @author   WishCart Team <support@wishcart.chat>
 * @license  GPL-2.0+ https://www.gnu.org/licenses/gpl-2.0.html
 * @link     https://wishcart.chat
 */
class WISHCART_Database {


    private $wpdb;
    private $table_prefix;

    /**
     * Initialize the database class
     *
     * @return void
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_prefix = $wpdb->prefix;
		
		$this->log_debug('WISHCART_Database::__construct start');
		$this->create_tables();
		$this->log_debug('WISHCART_Database::__construct end');
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

        // Wishlists metadata table (for multiple wishlists)
        $sql_wishlists = "CREATE TABLE IF NOT EXISTS {$this->table_prefix}wishcart_wishlists (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) DEFAULT NULL,
            session_id varchar(50) DEFAULT NULL,
            name varchar(255) DEFAULT 'Default wishlist',
            share_code varchar(50) NOT NULL,
            is_default tinyint(1) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY session_id (session_id),
            UNIQUE KEY share_code (share_code)
        ) $charset_collate;";

        // Wishlist items table (modified to support wishlist_id)
        $sql_wishlist = "CREATE TABLE IF NOT EXISTS {$this->table_prefix}wishcart_wishlist (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) DEFAULT NULL,
            session_id varchar(50) DEFAULT NULL,
            wishlist_id bigint(20) DEFAULT NULL,
            product_id bigint(20) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY session_id (session_id),
            KEY product_id (product_id),
            KEY wishlist_id (wishlist_id),
            UNIQUE KEY user_product_wishlist (user_id, product_id, wishlist_id),
            UNIQUE KEY session_product_wishlist (session_id, product_id, wishlist_id)
        ) $charset_collate;";

        include_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta($sql_wishlists);
        dbDelta($sql_wishlist);
		
		// Migrate existing data if needed
		$this->migrate_existing_data();
		
		$this->log_debug('create_tables: end');
    }

	/**
	 * Migrate existing wishlist data to new structure
	 *
	 * @return void
	 */
	private function migrate_existing_data() {
		$wishlists_table = $this->table_prefix . 'wishcart_wishlists';
		$wishlist_table = $this->table_prefix . 'wishcart_wishlist';
		
		// Check if migration already done
		$migration_done = get_option( 'wishcart_wishlists_migrated', false );
		if ( $migration_done ) {
			return;
		}
		
		// Check if wishlist_id column exists
		$column_exists = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
				WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'wishlist_id'",
				DB_NAME,
				$wishlist_table
			)
		);
		
		// Add wishlist_id column if it doesn't exist
		if ( empty( $column_exists ) ) {
			$this->wpdb->query(
				"ALTER TABLE {$wishlist_table} 
				ADD COLUMN wishlist_id bigint(20) DEFAULT NULL AFTER session_id,
				ADD KEY wishlist_id (wishlist_id),
				DROP INDEX IF EXISTS user_product,
				DROP INDEX IF EXISTS session_product,
				ADD UNIQUE KEY user_product_wishlist (user_id, product_id, wishlist_id),
				ADD UNIQUE KEY session_product_wishlist (session_id, product_id, wishlist_id)"
			);
		}
		
		// Get all unique user_id and session_id combinations
		$users = $this->wpdb->get_results(
			"SELECT DISTINCT user_id, session_id 
			FROM {$wishlist_table} 
			WHERE (user_id IS NOT NULL OR session_id IS NOT NULL)"
		);
		
		foreach ( $users as $user ) {
			$user_id = $user->user_id;
			$session_id = $user->session_id;
			
			// Check if default wishlist already exists
			$existing_wishlist = $this->wpdb->get_row(
				$this->wpdb->prepare(
					"SELECT id FROM {$wishlists_table} 
					WHERE user_id = %s AND session_id = %s AND is_default = 1",
					$user_id,
					$session_id
				)
			);
			
			if ( ! $existing_wishlist ) {
				// Create default wishlist
				$share_code = $this->generate_unique_share_code();
				$this->wpdb->insert(
					$wishlists_table,
					array(
						'user_id' => $user_id,
						'session_id' => $session_id,
						'name' => 'Default wishlist',
						'share_code' => $share_code,
						'is_default' => 1,
					),
					array( '%d', '%s', '%s', '%s', '%d' )
				);
				
				$wishlist_id = $this->wpdb->insert_id;
				
				// Update existing items to use this wishlist_id
				if ( $user_id ) {
					$this->wpdb->update(
						$wishlist_table,
						array( 'wishlist_id' => $wishlist_id ),
						array( 'user_id' => $user_id, 'wishlist_id' => null ),
						array( '%d' ),
						array( '%d', '%s' )
					);
				} else {
					$this->wpdb->update(
						$wishlist_table,
						array( 'wishlist_id' => $wishlist_id ),
						array( 'session_id' => $session_id, 'wishlist_id' => null ),
						array( '%d' ),
						array( '%s', '%s' )
					);
				}
			}
		}
		
		// Mark migration as done
		update_option( 'wishcart_wishlists_migrated', true );
	}
	
	/**
	 * Generate unique share code
	 *
	 * @return string
	 */
	private function generate_unique_share_code() {
		$wishlists_table = $this->table_prefix . 'wishcart_wishlists';
		$max_attempts = 10;
		$attempt = 0;
		
		do {
			// Generate 6-character alphanumeric code
			$code = strtolower( wp_generate_password( 6, false ) );
			$exists = $this->wpdb->get_var(
				$this->wpdb->prepare(
					"SELECT COUNT(*) FROM {$wishlists_table} WHERE share_code = %s",
					$code
				)
			);
			$attempt++;
		} while ( $exists > 0 && $attempt < $max_attempts );
		
		if ( $attempt >= $max_attempts ) {
			// Fallback: use timestamp-based code
			$code = 'w' . substr( md5( time() . wp_rand() ), 0, 5 );
		}
		
		return $code;
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
