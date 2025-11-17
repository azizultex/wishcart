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
		$this->check_version_upgrade();
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

        // Wishlist table
        $sql_wishlist = "CREATE TABLE IF NOT EXISTS {$this->table_prefix}wishcart_wishlist (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) DEFAULT NULL,
            session_id varchar(50) DEFAULT NULL,
            product_id bigint(20) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY session_id (session_id),
            KEY product_id (product_id),
            UNIQUE KEY user_product (user_id, product_id),
            UNIQUE KEY session_product (session_id, product_id)
        ) $charset_collate;";

        include_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta($sql_wishlist);
		
		$this->log_debug('create_tables: end');
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
