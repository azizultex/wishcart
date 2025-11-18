<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Database Migration Handler for WishCart Plugin
 *
 * Handles migration from old 2-table structure to new 7-table structure
 *
 * @category WordPress
 * @package  WishCart
 * @author   WishCart Team <support@wishcart.chat>
 * @license  GPL-2.0+ https://www.gnu.org/licenses/gpl-2.0.html
 * @link     https://wishcart.chat
 */
class WISHCART_Database_Migration {

    private $wpdb;
    private $table_prefix;

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_prefix = $wpdb->prefix;
    }

    /**
     * Run migration from old to new database structure
     *
     * @return array Migration results
     */
    public function migrate() {
        $results = array(
            'success' => true,
            'archived_tables' => array(),
            'errors' => array(),
        );

        // Check if migration has already been done
        $migration_version = get_option('wishcart_migration_version', '0');
        if ($migration_version === '2.0') {
            $results['message'] = 'Migration already completed';
            return $results;
        }

        // Archive old tables
        $old_tables = array(
            'wishcart_wishlists',
            'wishcart_wishlist',
        );

        $timestamp = date('Y_m_d_His');

        foreach ($old_tables as $old_table) {
            $full_table_name = $this->table_prefix . $old_table;
            $backup_table_name = $full_table_name . '_backup_' . $timestamp;

            // Check if old table exists
            $table_exists = $this->wpdb->get_var(
                $this->wpdb->prepare(
                    "SHOW TABLES LIKE %s",
                    $full_table_name
                )
            );

            if ($table_exists) {
                // Rename old table to backup
                $rename_result = $this->wpdb->query(
                    "RENAME TABLE {$full_table_name} TO {$backup_table_name}"
                );

                if ($rename_result !== false) {
                    $results['archived_tables'][] = array(
                        'old' => $full_table_name,
                        'new' => $backup_table_name,
                    );
                    $this->log_debug("Archived table: {$full_table_name} to {$backup_table_name}");
                } else {
                    $results['errors'][] = "Failed to archive table: {$full_table_name}";
                    $results['success'] = false;
                }
            }
        }

        // Create new 7-table structure
        if ($results['success']) {
            try {
                $database = new WISHCART_Database();
                $this->log_debug('New 7-table structure created successfully');
            } catch (Exception $e) {
                $results['errors'][] = 'Failed to create new tables: ' . $e->getMessage();
                $results['success'] = false;
            }
        }

        // Mark migration as complete
        if ($results['success']) {
            update_option('wishcart_migration_version', '2.0');
            update_option('wishcart_migration_date', current_time('mysql'));
            $results['message'] = 'Migration completed successfully';
        }

        return $results;
    }

    /**
     * Rollback migration (restore old tables)
     *
     * @param string $timestamp Timestamp of the backup to restore
     * @return array Rollback results
     */
    public function rollback($timestamp = null) {
        $results = array(
            'success' => true,
            'restored_tables' => array(),
            'errors' => array(),
        );

        // If no timestamp provided, find the most recent backup
        if (empty($timestamp)) {
            $tables = $this->wpdb->get_results(
                $this->wpdb->prepare(
                    "SHOW TABLES LIKE %s",
                    $this->table_prefix . 'wishcart_%_backup_%'
                ),
                ARRAY_N
            );

            if (empty($tables)) {
                $results['success'] = false;
                $results['errors'][] = 'No backup tables found';
                return $results;
            }

            // Extract timestamps from backup table names
            $timestamps = array();
            foreach ($tables as $table) {
                if (preg_match('/_backup_(\d{4}_\d{2}_\d{2}_\d{6})$/', $table[0], $matches)) {
                    $timestamps[] = $matches[1];
                }
            }

            if (empty($timestamps)) {
                $results['success'] = false;
                $results['errors'][] = 'No valid backup timestamps found';
                return $results;
            }

            // Get most recent timestamp
            rsort($timestamps);
            $timestamp = $timestamps[0];
        }

        // Drop new tables
        $new_tables = array(
            'fc_wishlists',
            'fc_wishlist_items',
            'fc_wishlist_shares',
            'fc_wishlist_analytics',
            'fc_wishlist_notifications',
            'fc_wishlist_activities',
            'fc_wishlist_guest_users',
        );

        foreach ($new_tables as $new_table) {
            $full_table_name = $this->table_prefix . $new_table;
            $this->wpdb->query("DROP TABLE IF EXISTS {$full_table_name}");
        }

        // Restore old tables
        $old_tables = array(
            'wishcart_wishlists',
            'wishcart_wishlist',
        );

        foreach ($old_tables as $old_table) {
            $full_table_name = $this->table_prefix . $old_table;
            $backup_table_name = $full_table_name . '_backup_' . $timestamp;

            // Check if backup exists
            $backup_exists = $this->wpdb->get_var(
                $this->wpdb->prepare(
                    "SHOW TABLES LIKE %s",
                    $backup_table_name
                )
            );

            if ($backup_exists) {
                // Rename backup back to original
                $rename_result = $this->wpdb->query(
                    "RENAME TABLE {$backup_table_name} TO {$full_table_name}"
                );

                if ($rename_result !== false) {
                    $results['restored_tables'][] = array(
                        'backup' => $backup_table_name,
                        'restored' => $full_table_name,
                    );
                } else {
                    $results['errors'][] = "Failed to restore table: {$backup_table_name}";
                    $results['success'] = false;
                }
            }
        }

        // Update migration version
        if ($results['success']) {
            update_option('wishcart_migration_version', '1.0');
            delete_option('wishcart_migration_date');
            $results['message'] = 'Rollback completed successfully';
        }

        return $results;
    }

    /**
     * Clean up old backup tables
     *
     * @param int $days_old Only delete backups older than X days (default: 30)
     * @return array Cleanup results
     */
    public function cleanup_old_backups($days_old = 30) {
        $results = array(
            'success' => true,
            'deleted_tables' => array(),
            'errors' => array(),
        );

        // Find all backup tables
        $tables = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SHOW TABLES LIKE %s",
                $this->table_prefix . 'wishcart_%_backup_%'
            ),
            ARRAY_N
        );

        $cutoff_date = strtotime("-{$days_old} days");

        foreach ($tables as $table) {
            $table_name = $table[0];

            // Extract timestamp from table name
            if (preg_match('/_backup_(\d{4})_(\d{2})_(\d{2})_(\d{6})$/', $table_name, $matches)) {
                $year = $matches[1];
                $month = $matches[2];
                $day = $matches[3];

                $table_date = strtotime("{$year}-{$month}-{$day}");

                if ($table_date < $cutoff_date) {
                    // Delete old backup table
                    $drop_result = $this->wpdb->query("DROP TABLE IF EXISTS {$table_name}");

                    if ($drop_result !== false) {
                        $results['deleted_tables'][] = $table_name;
                    } else {
                        $results['errors'][] = "Failed to delete table: {$table_name}";
                        $results['success'] = false;
                    }
                }
            }
        }

        $results['message'] = count($results['deleted_tables']) . ' backup tables deleted';
        return $results;
    }

    /**
     * Get migration status
     *
     * @return array Migration status information
     */
    public function get_migration_status() {
        $migration_version = get_option('wishcart_migration_version', '0');
        $migration_date = get_option('wishcart_migration_date', null);

        $status = array(
            'version' => $migration_version,
            'date' => $migration_date,
            'is_migrated' => ($migration_version === '2.0'),
            'backup_tables' => array(),
        );

        // Find backup tables
        $tables = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SHOW TABLES LIKE %s",
                $this->table_prefix . 'wishcart_%_backup_%'
            ),
            ARRAY_N
        );

        foreach ($tables as $table) {
            $table_name = $table[0];
            
            // Get table size
            $size_query = $this->wpdb->get_row(
                $this->wpdb->prepare(
                    "SELECT 
                        table_name AS `table`,
                        ROUND(((data_length + index_length) / 1024 / 1024), 2) AS `size_mb`
                    FROM information_schema.TABLES
                    WHERE table_schema = %s
                        AND table_name = %s",
                    DB_NAME,
                    $table_name
                ),
                ARRAY_A
            );

            $status['backup_tables'][] = array(
                'name' => $table_name,
                'size_mb' => isset($size_query['size_mb']) ? $size_query['size_mb'] : 0,
            );
        }

        return $status;
    }

    /**
     * Lightweight debug logger
     *
     * @param string $message
     * @return void
     */
    private function log_debug($message) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[WishCart Migration] ' . $message);
        }
    }
}

