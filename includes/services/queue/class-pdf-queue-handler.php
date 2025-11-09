<?php
/**
 * PDF Queue Handler Class for AISK
 *
 * This file contains the PDF queue handling functionality with resource-efficient
 * background processing using WordPress's built-in capabilities.
 *
 * @category WordPress
 * @package  AISK
 * @author   AISK Team <support@wishcart.chat>
 * @license  GPL-2.0+ https://www.gnu.org/licenses/gpl-2.0.html
 * @link     https://wishcart.chat
 * @since    1.0.0
 * @location includes/services/queue/class-pdf-queue-handler.php
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * AISK PDF Queue Handler Class
 *
 * Handles PDF processing queue with resource-efficient background processing.
 *
 * @category Class
 * @package  AISK
 * @author   AISK Team <support@wishcart.chat>
 * @license  GPL-2.0+ https://www.gnu.org/licenses/gpl-2.0.html
 * @link     https://wishcart.chat
 */
class WISHCART_PDF_Queue_Handler {

    /**
     * Action hook for processing PDF files
     */
    const PROCESS_PDF_ACTION = 'wishcart_process_pdf';

    /**
     * Action hook for cleaning up failed jobs
     */
    const CLEANUP_ACTION = 'wishcart_cleanup_failed_pdf_jobs';

    /**
     * Action hook for background processing
     */
    const BACKGROUND_PROCESS_ACTION = 'wishcart_background_process_pdf';

    /**
     * Database table name for PDF queue
     */
    private $table_name;

    /**
     * Maximum number of jobs to process in one batch
     */
    const BATCH_SIZE = 1;

    /**
     * Maximum number of retry attempts
     */
    const MAX_RETRIES = 3;

    /**
     * Minimum time between processing attempts (in seconds)
     */
    const MIN_PROCESSING_INTERVAL = 600; // 10 minutes

    /**
     * Maximum processing time per job (in seconds)
     */
    const MAX_PROCESSING_TIME = 300; // 5 minutes

    /**
     * Maximum file size to process in one go (in bytes)
     */
    const MAX_FILE_SIZE = 5 * 1024 * 1024; // 5MB

    /**
     * Class constructor
     */
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'wishcart_pdf_queue';

        // Initialize WordPress cron hooks
        add_action(self::PROCESS_PDF_ACTION, array($this, 'process_pdf'), 10, 2);
        add_action(self::CLEANUP_ACTION, array($this, 'cleanup_failed_jobs'));
        add_action(self::BACKGROUND_PROCESS_ACTION, array($this, 'process_pending_jobs'));

        // Schedule cleanup wishcart if not already scheduled
        if (!wp_next_scheduled(self::CLEANUP_ACTION)) {
            wp_schedule_event(strtotime('tomorrow midnight'), 'daily', self::CLEANUP_ACTION);
        }

        // Add custom cron interval for more frequent processing
        add_filter('cron_schedules', array($this, 'add_cron_interval'));

        // Add REST API endpoint for manual processing
        add_action('rest_api_init', array($this, 'register_rest_routes'));

        // Add hooks for background processing
        add_action('shutdown', array($this, 'maybe_schedule_background_processing'));

        // Ensure background processing is scheduled
        $this->ensure_processing_scheduled();
    }

    /**
     * Add custom cron interval
     */
    public function add_cron_interval($schedules) {
        $schedules['wishcart_five_minutes'] = array(
            'interval' => self::MIN_PROCESSING_INTERVAL,
            'display'  => __('Every 5 Minutes', 'wish-cart')
        );
        return $schedules;
    }

    /**
     * Register REST API routes
     */
    public function register_rest_routes() {
        register_rest_route('wishcart/v1', '/process-pdf-queue', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_manual_processing'),
            'permission_callback' => function() {
                return current_user_can('manage_options');
            }
        ));
    }

    /**
     * Handle manual processing request
     */
    public function handle_manual_processing($request) {
        $result = $this->process_pending_jobs();
        return rest_ensure_response(array(
            'success' => true,
            'processed' => $result
        ));
    }

    /**
     * Check if server is under heavy load
     *
     * @return bool True if server is under heavy load
     */
    private function is_server_under_load() {
        // Check server load if function exists
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            if (is_array($load) && count($load) > 0) {
                // If load is above 50% of CPU cores, consider it heavy load
                return $load[0] > (count($load) * 0.5);
            }
        }

        // Check memory usage
        $memory_usage = memory_get_usage(true);
        $memory_limit = ini_get('memory_limit');
        $memory_limit_bytes = wp_convert_hr_to_bytes($memory_limit);
        
        // If memory usage is above 60%, consider it heavy load
        return ($memory_usage / $memory_limit_bytes) > 0.6;
    }

    /**
     * Maybe schedule background processing
     */
    public function maybe_schedule_background_processing() {
        $cache_key = 'wishcart_pdf_queue_pending_count';
        $pending_count = wp_cache_get($cache_key, 'wishcart_pdf_queue');
        
        if (false === $pending_count) {
            // @codingStandardsIgnoreStart
            global $wpdb;
            
            $pending_count = (int) $wpdb->get_var("
                SELECT COUNT(*) 
                FROM {$this->table_name} 
                WHERE status IN ('pending', 'failed') 
                AND attempts < " . self::MAX_RETRIES
            );
            // @codingStandardsIgnoreEnd
            
            wp_cache_set($cache_key, $pending_count, 'wishcart_pdf_queue', 300); // Cache for 5 minutes
        }

        if ($pending_count > 0) {
            // Check if we've processed recently
            $last_processed = get_option('wishcart_last_pdf_processing', 0);
            if (time() - $last_processed >= self::MIN_PROCESSING_INTERVAL) {
                // Schedule immediate processing
                wp_schedule_single_event(time(), self::BACKGROUND_PROCESS_ACTION);
                update_option('wishcart_last_pdf_processing', time());
            }
        }
    }

    /**
     * Process a PDF file from the queue
     *
     * @param int $queue_id The queue ID
     * @param int $attachment_id The attachment ID
     * @return bool Whether the processing was successful
     */
    public function process_pdf($queue_id, $attachment_id) {
        global $wpdb;
        $queue_id = intval($queue_id);
        $attachment_id = intval($attachment_id);
        $cache_key = 'wishcart_pdf_queue_item_' . $queue_id;
        $queue_item = wp_cache_get($cache_key, 'wishcart_pdf_queue');
        
        if (false === $queue_item) {
            // @codingStandardsIgnoreStart
            $queue_item = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$this->table_name} WHERE id = %d",
                $queue_id
            ));
            // @codingStandardsIgnoreEnd
            
            if ($queue_item) {
                wp_cache_set($cache_key, $queue_item, 'wishcart_pdf_queue', 300); // Cache for 5 minutes
            }
        }

        if (!$queue_item) {
            return false;
        }

        // Don't process if server is under load
        if ($this->is_server_under_load()) {
            // @codingStandardsIgnoreStart
            $wpdb->update(
                $this->table_name,
                array(
                    'status' => 'pending',
                    'next_attempt' => date('Y-m-d H:i:s', time() + self::MIN_PROCESSING_INTERVAL),
                    'attempts' => $wpdb->get_var($wpdb->prepare(
                        "SELECT attempts FROM {$this->table_name} WHERE id = %d",
                        $queue_id
                    )) + 1
                ),
                array('id' => $queue_id)
            );
            // @codingStandardsIgnoreEnd
            
            wp_cache_delete($cache_key, 'wishcart_pdf_queue');
            return true;
        }

        // Update status to processing
        // @codingStandardsIgnoreStart
        $wpdb->update(
            $this->table_name,
            array('status' => 'processing'),
            array('id' => $queue_id)
        );
        // @codingStandardsIgnoreEnd
        
        wp_cache_delete($cache_key, 'wishcart_pdf_queue');

        try {
            // Set time limit using WordPress filters
            add_filter('max_execution_time', function() { return self::MAX_PROCESSING_TIME; });
            
            // Get the embeddings handler instance
            $embeddings_handler = new WISHCART_External_Embeddings_Handler();
            
            // Process the PDF with attachment_id
            // $result = $embeddings_handler->process_pdf($queue_item->file_path, $attachment_id);
            // Check file size and process accordingly
            if ($queue_item->file_size > self::MAX_FILE_SIZE) {
                // For large files, process in chunks
                $result = $embeddings_handler->process_large_file($attachment_id, $queue_item->file_path);
            } else {
                // For smaller files, process normally
                $result = $embeddings_handler->process_file($attachment_id, $queue_item->file_path);
            }

            if ($result['success']) {
                // Update status to completed
                // @codingStandardsIgnoreStart
                $wpdb->update(
                    $this->table_name,
                    array('status' => 'completed'),
                    array('id' => $queue_id)
                );
                // @codingStandardsIgnoreEnd
            } else {
                throw new Exception($result['message']);
            }

        } catch (Exception $e) {
            // Update status to failed
            // @codingStandardsIgnoreStart
            $wpdb->update(
                $this->table_name,
                array(
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                    'attempts' => $queue_item->attempts + 1,
                    'next_attempt' => date('Y-m-d H:i:s', time() + self::MIN_PROCESSING_INTERVAL)
                ),
                array('id' => $queue_id)
            );
            // @codingStandardsIgnoreEnd
        }

        wp_cache_delete($cache_key, 'wishcart_pdf_queue');
        return $result['success'];
    }

    /**
     * Add a PDF to the processing queue
     *
     * @param int    $attachment_id The WordPress attachment ID
     * @param string $file_path     The full path to the PDF file
     * @param bool   $process_now   Whether to process the PDF immediately
     *
     * @return int|false The queue ID on success, false on failure
     */
    public function add_to_queue($attachment_id, $file_path, $process_now = false) {
        global $wpdb;
        $attachment_id = intval($attachment_id);
        $file_path = esc_sql($file_path);
        // Get file name from attachment
        $file_name = get_the_title($attachment_id);
        if (empty($file_name)) {
            $file_name = basename($file_path);
        }

        // Get file size
        $file_size = filesize($file_path);
        if ($file_size === false) {
            return false;
        }

        // @codingStandardsIgnoreStart
        $result = $wpdb->insert(
            $this->table_name,
            [
                'attachment_id' => $attachment_id,
                'file_name' => $file_name,
                'file_path' => $file_path,
                'file_size' => $file_size,
                'status' => 'pending',
                'attempts' => 0,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ],
            ['%d', '%s', '%s', '%d', '%s', '%d', '%s', '%s']
        );
        // @codingStandardsIgnoreEnd

        if ($result === false) {
            return false;
        }

        $queue_id = $wpdb->insert_id;

        // Schedule immediate processing if requested
        if ($process_now) {
            wp_schedule_single_event(time(), self::PROCESS_PDF_ACTION, array($queue_id, $attachment_id));
        } else {
            // Schedule background processing
            wp_schedule_single_event(time() + 60, self::BACKGROUND_PROCESS_ACTION);
        }

        return $queue_id;
    }

    /**
     * Ensure PDF processing jobs are scheduled
     */
    private function ensure_processing_scheduled() {
        if (!wp_next_scheduled(self::BACKGROUND_PROCESS_ACTION)) {
            wp_schedule_event(time() + 60, 'wishcart_five_minutes', self::BACKGROUND_PROCESS_ACTION);
        }
    }

    /**
     * Process pending jobs
     * This method can be called by WordPress cron or manually
     *
     * @return int Number of jobs processed
     */
    public function process_pending_jobs() {
        // Ensure processing is scheduled
        $this->ensure_processing_scheduled();

        global $wpdb;
        $cache_key = 'wishcart_pdf_pending_jobs';
        $pending_jobs = wp_cache_get($cache_key, 'wishcart_pdf_queue');
        
        if (false === $pending_jobs) {
            // @codingStandardsIgnoreStart
            $pending_jobs = $wpdb->get_results($wpdb->prepare(
                "SELECT id, attachment_id 
                FROM {$this->table_name} 
                WHERE status IN ('pending', 'failed') 
                AND attempts < %d 
                AND next_attempt <= %s
                ORDER BY created_at ASC
                LIMIT %d",
                self::MAX_RETRIES,
                current_time('mysql'),
                self::BATCH_SIZE
            ));
            // @codingStandardsIgnoreEnd
            
            if ($pending_jobs) {
                wp_cache_set($cache_key, $pending_jobs, 'wishcart_pdf_queue', 300); // Cache for 5 minutes
            }
        }

        $processed = 0;
        foreach ($pending_jobs as $job) {
            // Check server load before each job
            if ($this->is_server_under_load()) {
                break;
            }

            $this->process_pdf($job->id, $job->attachment_id);
            $processed++;

            // Add a small delay between jobs
            usleep(100000); // 100ms delay
        }

        wp_cache_delete($cache_key, 'wishcart_pdf_queue');
        return $processed;
    }

    /**
     * Clean up failed jobs older than 7 days
     */
    public function cleanup_failed_jobs() {
        // @codingStandardsIgnoreStart
        global $wpdb;
        
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table_name} 
            WHERE status = 'failed' 
            AND attempts >= 3 
            AND created_at < %s",
            date('Y-m-d H:i:s', strtotime('-7 days'))
        ));
        // @codingStandardsIgnoreEnd
        
        // Clear all related caches
        wp_cache_delete('wishcart_pdf_queue_pending_count', 'wishcart_pdf_queue');
        wp_cache_delete('wishcart_pdf_queue_pending_jobs', 'wishcart_pdf_queue');
    }

    /**
     * Get the status of a PDF processing job
     *
     * @param int $attachment_id The WordPress attachment ID
     *
     * @return array The job status information
     */
    public function get_job_status($attachment_id) {
        global $wpdb;
        $attachment_id = intval($attachment_id);
        $cache_key = 'wishcart_pdf_status_' . $attachment_id;
        $status = wp_cache_get($cache_key, 'wishcart_pdf_queue');
        if ($status === false) {
            // @codingStandardsIgnoreStart
            $status = $wpdb->get_row($wpdb->prepare(
                "SELECT status, attempts, error_message, created_at, updated_at, next_attempt 
                FROM {$this->table_name} 
                WHERE attachment_id = %d 
                ORDER BY id DESC",
                $attachment_id
            ), ARRAY_A);
            // @codingStandardsIgnoreEnd
            wp_cache_set($cache_key, $status, 'wishcart_pdf_queue', 300);
        }
        if (!$status) {
            return array(
                'status' => 'not_found',
                'message' => 'PDF processing job not found'
            );
        }
        // Sanitize output
        foreach ($status as $key => $value) {
            $status[$key] = is_string($value) ? esc_html($value) : $value;
        }
        return $status;
    }
}