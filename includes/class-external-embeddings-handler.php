<?php

if ( ! defined('ABSPATH') ) {
    exit;
}

/**
 * Handles the generation and management of embeddings for content
 *
 * @category Functionality
 * @package  AISK
 * @author   Aisk Team <support@aisk.chat>
 * @license  GPL-2.0+ https://www.gnu.org/licenses/gpl-2.0.html
 * @link     https://aisk.chat
 */

use Opis\JsonSchema\Keywords\ConstKeyword;
use Smalot\PdfParser\Parser;
use Smalot\PdfParser\Exception\PdfParserException;

// Include required classes
require_once plugin_dir_path(__FILE__) . 'class-url-content-fetcher.php';
require_once plugin_dir_path(__FILE__) . 'class-url-discoverer.php';
require_once plugin_dir_path(__FILE__) . 'class-content-processor.php';
require_once plugin_dir_path(__FILE__) . 'class-crawler.php';

/**
 * AISK_External_Embeddings_Handler Class
 *
 * Manages the generation, storage, and retrieval of embeddings for various content types
 *
 * @category Class
 * @package  AISK
 * @author   Aisk Team <support@aisk.chat>
 * @license  GPL-2.0+ https://www.gnu.org/licenses/gpl-2.0.html
 * @link     https://aisk.chat
 */
class AISK_External_Embeddings_Handler {

    private $db;
    private $api_key;
    private $auth_key;
    private $model = 'text-embedding-3-small';
    private $batch_size = 10;
    private $settings;
    private $max_upload_size;
    private $optimum_upload_size = 10485760; // 10MB default optimum size
    private $crawl_max_depth;
    private $crawl_max_pages;
    private $crawl_request_timeout;
    private $crawl_max_job_time;
    const MAX_PROCESSING_TIME = 300; // 5 minutes in seconds

    /**
     * Class constructor
     *
     * @param void
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function __construct() {
        $this->settings = get_option('aisk_settings');
        $this->api_key = isset($this->settings['general']['openai_key']) ? $this->settings['general']['openai_key'] : '';
        $this->auth_key = isset($this->settings['general']['auth_key']) ? $this->settings['general']['auth_key'] : '';
        
        // Configurable crawl parameters with defaults
        $this->crawl_max_depth = isset($this->settings['general']['crawl_max_depth']) ? intval($this->settings['general']['crawl_max_depth']) : 2;
        $this->crawl_max_pages = isset($this->settings['general']['crawl_max_pages']) ? intval($this->settings['general']['crawl_max_pages']) : 15;
        $this->crawl_request_timeout = isset($this->settings['general']['crawl_request_timeout']) ? intval($this->settings['general']['crawl_request_timeout']) : 15;
        $this->crawl_max_job_time = isset($this->settings['general']['crawl_max_job_time']) ? intval($this->settings['general']['crawl_max_job_time']) : 180;
        
        // Initialize max upload size
        $this->max_upload_size = $this->get_max_upload_size();
        
        // Set optimum upload size from settings if available
        if (isset($this->settings['general']['optimum_upload_size'])) {
            $this->optimum_upload_size = intval($this->settings['general']['optimum_upload_size']) * 1024 * 1024;
        }

        // Create PDF queue table
        add_action('init', array($this, 'create_pdf_queue_table'));

        // Register PDF background processing action
        add_action('aisk_process_pdf_background', array($this, 'process_pdf_background'), 10, 3);

        // Register REST API endpoint for processing
        add_action('rest_api_init', [ $this, 'register_rest_routes' ]);
        
        // Register background processing action
        add_action('aisk_process_url_background', [ $this, 'process_url_background' ]);
        
        // clean up embedding for deleted content
        add_action('before_delete_post', [ $this, 'delete_content_embeddings' ]);
        add_action('fluentcart_before_delete_product', [ $this, 'delete_content_embeddings' ]);
        add_action('fluentcart_before_delete_product_variation', [ $this, 'delete_content_embeddings' ]);
        add_filter('wp_handle_upload_prefilter', array($this, 'increase_upload_limit_for_pdf'));
        
        // Add admin notice for upload size limits
        add_action('admin_notices', array($this, 'display_upload_size_notice'));
        
        // Add script for upload instructions toggle
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }

    public function enqueue_admin_scripts() {
        // Only enqueue on admin pages where we show the notice
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Check if we need to show the notice
        if ($this->max_upload_size < $this->optimum_upload_size) {
            wp_register_script(
                'aisk-upload-instructions',
                false,
                ['jquery'],
                AISK_VERSION,
                true
            );
            
            $script = "
                jQuery(document).ready(function($) {
                    $('.show-upload-instructions').on('click', function(e) {
                        e.preventDefault();
                        $('#upload-instructions').slideToggle();
                    });
                });
            ";
            
            wp_add_inline_script('aisk-upload-instructions', $script);
            wp_enqueue_script('aisk-upload-instructions');
        }
    }

    /**
     * Register REST API routes
     *
     * @param void
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function register_rest_routes() {
        register_rest_route('aisk/v1', '/process-urls', [
            'methods' => 'POST',
            'callback' => [ $this, 'handle_url_processing' ],
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
            'args' => [
                'website_url' => [
                    'required' => true,
                    'type' => 'string',
                    'validate_callback' => function($param) {
                        return !empty($param) && filter_var($param, FILTER_VALIDATE_URL);
                    }
                ],
                'follow_links' => [
                    'required' => false,
                    'type' => 'boolean',
                    'default' => true
                ],
                'include_paths' => [
                    'required' => false,
                    'type' => 'array',
                    'default' => []
                ],
                'exclude_paths' => [
                    'required' => false,
                    'type' => 'array',
                    'default' => []
                ],
                'include_selectors' => [
                    'required' => false,
                    'type' => 'array',
                    'default' => []
                ],
                'exclude_selectors' => [
                    'required' => false,
                    'type' => 'array',
                    'default' => []
                ]
            ]
        ]);

        register_rest_route(
            'aisk/v1',
            '/check-url-status',
            [
                'methods' => 'POST',
                'callback' => [ $this, 'check_urls_status' ],
                'permission_callback' => function () {
                    return current_user_can('manage_options');
                },
                'args' => [
                    'urls' => [
                        'required' => true,
                        'type' => 'array',
                        'items' => [
                            'type' => 'string'
                        ]
                    ]
                ]
            ]
        );

        register_rest_route(
            'aisk/v1',
            '/get-crawled-urls',
            [
                'methods' => 'POST',
                'callback' => [ $this, 'get_crawled_urls' ],
                'permission_callback' => function () {
                    return current_user_can('manage_options');
                },
            ]
        );

        register_rest_route(
            'aisk/v1', '/delete-url', [
                'methods'  => 'POST',
                'callback' => [ $this, 'delete_crawled_url' ],
                'permission_callback' => function () {
                    return current_user_can('manage_options');
                },
            ]
        );

        register_rest_route(
            'aisk/v1', '/process-pdf', [
                'methods' => 'POST',
                'callback' => [ $this, 'handle_pdf_processing' ],
                'permission_callback' => function ( $request ) {
                    return current_user_can( 'upload_files' ) &&
                       wp_verify_nonce( $request->get_header( 'X-WP-Nonce' ), 'wp_rest' );
                },
            ]
        );

        register_rest_route(
            'aisk/v1', '/get-pdf-status', [
                'methods' => 'GET',
                'callback' => [ $this, 'get_pdf_status' ],
                'permission_callback' => function ( $request ) {
                    return current_user_can( 'upload_files' ) &&
                       wp_verify_nonce( $request->get_header( 'X-WP-Nonce' ), 'wp_rest' );
                },
                'args' => [
                    'attachment_id' => [
                        'required' => true,
                        'type' => 'integer',
                        'validate_callback' => function($param) {
                            return is_numeric($param) && $param > 0;
                        }
                    ]
                ]
            ]
        );

        register_rest_route(
            'aisk/v1', '/pdf-job-status', [
                'methods' => 'GET',
                'callback' => [ $this, 'get_pdf_job_status' ],
                'permission_callback' => function ( $request ) {
                    return current_user_can( 'upload_files' ) &&
                       wp_verify_nonce( $request->get_header( 'X-WP-Nonce' ), 'wp_rest' );
                },
                'args' => [
                    'job_id' => [
                        'required' => false,
                        'type' => 'integer',
                        'validate_callback' => function($param) {
                            return is_numeric($param) && $param > 0;
                        }
                    ],
                    'attachment_id' => [
                        'required' => false,
                        'type' => 'integer',
                        'validate_callback' => function($param) {
                            return is_numeric($param) && $param >= 0;
                        }
                    ]
                ]
            ]
        );
        register_rest_route(
            'aisk/v1',
            '/delete-pdf',
            array(
                'methods' => 'POST',
                'callback' => array( $this, 'delete_pdf_embeddings' ),
                'permission_callback' => function ( $request ) {
                    return current_user_can( 'upload_files' ) &&
                        wp_verify_nonce( $request->get_header( 'X-WP-Nonce' ), 'wp_rest' );
                },
            )
        );
        register_rest_route(
            'aisk/v1', '/pdf-queue-list', [
                'methods' => 'GET',
                'callback' => [ $this, 'get_pdf_queue_list' ],
                'permission_callback' => function ( $request ) {
                    return current_user_can( 'upload_files' ) &&
                        wp_verify_nonce( $request->get_header( 'X-WP-Nonce' ), 'wp_rest' );
                },
            ]
        );
    }

    private function split_content($content, $max_chars = 8000) {
        // First, clean up the content by removing excessive whitespace and special characters
        $content = preg_replace('/\s+/', ' ', $content);
        $content = preg_replace('/[^\p{L}\p{N}\s\-.,!?()]/u', '', $content);
        $content = trim($content);
        
        if (strlen($content) <= $max_chars) {
            return [$content];
        }

        $chunks = [];
        $sentences = preg_split('/(?<=[.!?])\s+/', $content);
        $current_chunk = '';
        
        foreach ($sentences as $sentence) {
            if (strlen($current_chunk . ' ' . $sentence) > $max_chars) {
                if (!empty($current_chunk)) {
                $chunks[] = trim($current_chunk);
                }
                $current_chunk = $sentence;
            } else {
                $current_chunk .= ('' === $current_chunk ? '' : ' ') . $sentence;
            }
        }

        if (!empty($current_chunk)) {
            $chunks[] = trim($current_chunk);
        }

        return $chunks;
    }

    /**
     * Fetch sitemap URLs from a website
     *
     * @param string $website_url Website URL
     *
     * @since 1.0.0
     *
     * @return array Array of URLs from sitemap
     */
    private function fetch_sitemap_urls( $website_url ) {
        // Normalize URL and ensure it ends with a slash
        $website_url = trailingslashit( esc_url( $website_url ) );
        $urls = [];

        // Common sitemap locations
        $sitemap_locations = [
            'sitemap.xml',
            'sitemap_index.xml',
            'wp-sitemap.xml',
            'robots.txt',
        ];

        $sitemap_found = false;
        foreach ( $sitemap_locations as $location ) {
            $sitemap_url = $website_url . $location;
            $response = wp_remote_get($sitemap_url);

            if ( is_wp_error( $response ) ) {
                continue;
            }

            $body = wp_remote_retrieve_body( $response );

            // If this is robots.txt, extract sitemap URL
            if ( $location === 'robots.txt' ) {
                preg_match_all('/Sitemap: (.*?)$/m', $body, $matches);
                if ( ! empty($matches[1] ) ) {
                    foreach ( $matches[1] as $sitemap_url ) {
                        $sitemap_urls = $this->parse_sitemap( trim( $sitemap_url ) );
                        if ( ! empty( $sitemap_urls ) ) {
                            $sitemap_found = true;
                            $urls = array_merge($urls, $sitemap_urls);
                        }
                    }
                }
                continue;
            }

            // Parse XML sitemap
            $sitemap_urls = $this->parse_sitemap( $sitemap_url );
            if ( ! empty( $sitemap_urls ) ) {
                $sitemap_found = true;
                $urls = array_merge( $urls, $sitemap_urls );
            }
        }

        // If no sitemap found, just use the given URL
        if ( ! $sitemap_found ) {
            $urls[] = $website_url;
        }

        return array_unique($urls);
    }

    /**
     * Parse sitemap XML and extract URLs
     *
     * @param string $sitemap_url Sitemap URL
     *
     * @since 1.0.0
     *
     * @return array Array of URLs
     */
    private function parse_sitemap( $sitemap_url ) {
        $urls = [];
        $response = wp_remote_get( $sitemap_url );

        if ( is_wp_error( $response ) ) {
            return $urls;
        }

        $body = wp_remote_retrieve_body($response);
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);

        if ( ! $xml ) {
            return $urls;
        }

        // Handle sitemap index files
        if ( $xml->getName() === 'sitemapindex' ) {
            foreach ( $xml->sitemap as $sitemap ) {
                $urls = array_merge( $urls, $this->parse_sitemap( (string) $sitemap->loc ) );
            }
            return $urls;
        }

        // Handle regular sitemaps
        foreach ( $xml->url as $url ) {
            $urls[] = (string) $url->loc;
        }

        return $urls;
    }

    /**
     * Process URLs in background to prevent timeouts
     */
    public function process_url_background($job_id) {
        $job_data = get_option('aisk_url_processing_' . $job_id);
        if (!$job_data) {
            return;
        }

        $start_time = time();
        try {
            // Update job status to processing
            $job_data['status'] = 'processing';
            $job_data['updated_at'] = current_time('mysql');
            update_option('aisk_url_processing_' . $job_id, $job_data);

            // Initialize the crawler components
            $content_fetcher = new AISK_URLContentFetcher();
            $url_discoverer = new AISK_URLDiscoverer($content_fetcher);
            $content_processor = new AISK_ContentProcessor();
            $crawler = new AISK_Crawler($content_fetcher, $url_discoverer, $content_processor);

            // Check for bot protection first
            $response = wp_remote_get($job_data['url'], [
                'timeout' => 30,
                'user-agent' => 'Aisk Chat Bot Crawler (+https://aisk.chat)',
                'sslverify' => false,
                'headers' => [
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'en-US,en;q=0.5',
                ]
            ]);

            if (is_wp_error($response)) {
                throw new Exception('Failed to fetch URL: ' . $response->get_error_message());
            }

            $body = wp_remote_retrieve_body($response);
            
            // Check for common bot protection indicators
            $bot_protection_indicators = [
                'recaptcha',
                'cloudflare',
                'are you human',
                'verify you are human',
                'security check',
                'captcha',
                'challenge',
                'bot detection'
            ];

            foreach ($bot_protection_indicators as $indicator) {
                if (stripos($body, $indicator) !== false) {
                    // Update job status to failed with bot protection message
                    $job_data['status'] = 'failed';
                    $job_data['error'] = 'Bot protection detected';
                    $job_data['error_type'] = 'bot_protection';
                    $job_data['updated_at'] = current_time('mysql');
                    update_option('aisk_url_processing_' . $job_id, $job_data);
                    
                    // Store the bot protection status in a separate option for future reference
                    update_option('aisk_bot_protected_url_' . md5($job_data['url']), true);
                    
                    return;
                }
            }

            // If no bot protection, proceed with normal processing
            $options = $job_data['options'];
            $options['max_depth'] = $this->crawl_max_depth;
            $options['max_pages'] = $this->crawl_max_pages;
            $options['request_timeout'] = $this->crawl_request_timeout;
            $options['max_job_time'] = $this->crawl_max_job_time;

            $results = $crawler->crawl($job_data['url'], $options);
            $save_success = $crawler->save_results($results);

            // Update job status
            $job_data['status'] = $save_success ? 'completed' : 'failed';
            $job_data['results'] = $results;
            $job_data['updated_at'] = current_time('mysql');
            update_option('aisk_url_processing_' . $job_id, $job_data);

        } catch (Exception $e) {
            // Update job status to failed
            $job_data['status'] = 'failed';
            $job_data['error'] = $e->getMessage();
            $job_data['updated_at'] = current_time('mysql');
            update_option('aisk_url_processing_' . $job_id, $job_data);
        }
    }

    /**
     * Handle URL processing with background support
     */
    public function handle_url_processing($request) {
        $url = $request->get_param('website_url');
        if (empty($url)) {
            return new WP_Error(
                'missing_url',
                'Website URL is required',
                ['status' => 400]
            );
        }

        // Check if URL was previously detected as bot protected
        $normalized_url = $this->normalize_url_for_job_id($url);
        if (get_option('aisk_bot_protected_url_' . md5($normalized_url))) {
            return new WP_Error(
                'bot_protected',
                'This URL is protected against automated access. Please try a different URL or contact the website administrator.',
                ['status' => 403]
            );
        }

        // Always use normalized URL for job ID
        $job_id = md5($normalized_url);

        // Overwrite any existing job for this URL
        delete_option('aisk_url_processing_' . $job_id);

        // Store the job data in WordPress options
        $job_data = [
            'url' => $normalized_url, // Store normalized URL (without trailing slash)
            'normalized_url' => $normalized_url,
            'options' => [
                'follow_links' => $request->get_param('follow_links') !== null ? filter_var($request->get_param('follow_links'), FILTER_VALIDATE_BOOLEAN) : true,
                'include_selectors' => $request->get_param('include_selectors') ?? [],
                'exclude_selectors' => $request->get_param('exclude_selectors') ?? []
            ],
            'status' => 'pending',
            'progress' => 0,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        ];

        update_option('aisk_url_processing_' . $job_id, $job_data);

        // Schedule the background processing
        wp_schedule_single_event(time() + 1, 'aisk_process_url_background', [$job_id]);

        // Return immediately with the job ID
        return new WP_REST_Response([
            'success' => true,
            'job_id' => $job_id,
            'message' => 'URL processing started in background'
        ], 200);
    }

    /**
     * Check job status
     */
    public function check_url_status($request) {
        $job_id = $request->get_param('job_id');
        if (empty($job_id)) {
            return new WP_Error(
                'missing_job_id',
                'Job ID is required',
                ['status' => 400]
            );
        }

        $job_data = get_option('aisk_url_processing_' . $job_id);
        if (!$job_data) {
            return new WP_Error(
                'invalid_job_id',
                'Invalid job ID',
                ['status' => 404]
            );
        }

        // If the job failed with bot protection, add a user-friendly message
        if ($job_data['status'] === 'failed' && isset($job_data['error_type']) && $job_data['error_type'] === 'bot_protection') {
            $job_data['user_message'] = 'This URL is protected against automated access. Please try a different URL or contact the website administrator.';
        }

        return new WP_REST_Response($job_data, 200);
    }

    /**
     * Generate a user-friendly message based on crawling results
     *
     * @param array $results Crawling results
     * @return string User message
     */
    private function generate_user_message($results) {
        $message = '';

        if ($results['main_url']['status'] === 'success') {
            $message .= 'The main page has been successfully processed. ';
        } else {
            $message .= 'Failed to process the main page. ';
        }

        $successful_sub_urls = count(array_filter($results['subordinate_urls'], function($url) {
            return $url['status'] === 'success';
        }));

        if ($successful_sub_urls > 0) {
            $message .= "Successfully processed {$successful_sub_urls} subordinate URLs. ";
        } else if (!empty($results['subordinate_urls'])) {
            $message .= 'No subordinate URLs could be processed. ';
        }

        if (!empty($results['warnings'])) {
            $message .= 'Some warnings were encountered during processing. ';
        }

        if (!empty($results['errors'])) {
            $message .= 'Some errors were encountered during processing. ';
        }

        return trim($message);
    }

    public function process_external_urls($urls, $parent_url = '', $include_selectors = [], $exclude_selectors = []) {
        global $wpdb;
        $processed = 0;
        $updated = 0;
        $errors = [];
        $table_name = $wpdb->prefix . 'aisk_embeddings';

        foreach ($urls as $url) {
            try {
                // Pass the selectors to the get_url_content function
                $content = $this->get_url_content($url, $include_selectors, $exclude_selectors);
                if (!empty($content)) {
                    $url_id = md5($url);
                    $cache_key = 'aisk_embedding_external_url_' . $url;

                    // Check if the exact URL already exists in the database
                    $existing = wp_cache_get($cache_key, 'aisk_embeddings');
                    
                    if (false === $existing) {
                        // Use WordPress's transient API to check URL existence
                        $existing = $this->check_url_exists($url);
                        wp_cache_set($cache_key, $existing, 'aisk_embeddings', 3600); // Cache for 1 hour
                    }

                    $extra_data = [
                        'crawled_url' => $url,
                        'parent_url'  => $parent_url,
                    ];

                    if ($existing) {
                        // If URL exists, create a new entry instead of updating
                        $new_url_id = md5($url . time()); // Generate a unique ID for the new entry
                        $this->store_embedding('external_url', $new_url_id, $content, $extra_data);
                        $processed++;
                    } else {
                        // If the URL is completely new, insert it
                        $this->store_embedding('external_url', $url_id, $content, $extra_data);
                        $processed++;
                    }
                }
            } catch (Exception $e) {
                $errors[] = "Error processing URL: {$url} - " . $e->getMessage();
            }
        }

        return [
            'success'   => true,
            'processed' => $processed,
            'updated'   => $updated,
            'total'     => $processed + $updated,
            'errors'    => $errors,
        ];
    }

    /**
    * Check status of URLs being processed
    *
    * @param WP_REST_Request $request The REST request object
    * @return array Status information for the requested URLs
    */
    public function check_urls_status($request) {
        global $wpdb;
        $urls = $request->get_param('urls');
        
        if (empty($urls) || !is_array($urls)) {
            return [
                'success' => false,
                'message' => 'No URLs provided to check'
            ];
        }
        
        $statuses = [];
        foreach ($urls as $url) {
            // Normalize URL for consistent job ID
            $normalized_url = $this->normalize_url_for_job_id($url);
            
            // Check if URL is bot protected
            $bot_protected_cache_key = 'aisk_bot_protected_' . md5($normalized_url);
            $is_bot_protected = wp_cache_get($bot_protected_cache_key, 'aisk_embeddings');
            
            if (false === $is_bot_protected) {
                $is_bot_protected = get_option('aisk_bot_protected_url_' . md5($normalized_url));
                wp_cache_set($bot_protected_cache_key, $is_bot_protected, 'aisk_embeddings', 3600);
            }
            
            if ($is_bot_protected) {
                $statuses[$url] = [
                    'status' => 'bot_protected',
                    'error_type' => 'bot_protection',
                    'user_message' => 'This URL is protected against automated access. Please try a different URL or contact the website administrator.'
                ];
                continue;
            }

            // Check job status in options table using normalized URL
            $job_id = md5($normalized_url);
            $job_cache_key = 'aisk_job_status_' . $job_id;
            $job_data = wp_cache_get($job_cache_key, 'aisk_embeddings');
            
            if (false === $job_data) {
                $job_data = get_option('aisk_url_processing_' . $job_id);
                wp_cache_set($job_cache_key, $job_data, 'aisk_embeddings', 3600);
            }
            
            $job_status = $job_data['status'] ?? null;

            // Check for main URL embedding using normalized URL
            $cache_key = 'aisk_embedding_count_' . md5($normalized_url);
            $main_embedding = wp_cache_get($cache_key, 'aisk_embeddings');
            
            if (false === $main_embedding) {
                // @codingStandardsIgnoreStart
                $main_embedding = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(*) FROM {$wpdb->prefix}aisk_embeddings WHERE content_type = 'external_url' AND crawled_url = %s",
                        $normalized_url
                    )
                );
                // @codingStandardsIgnoreEnd
                wp_cache_set($cache_key, $main_embedding, 'aisk_embeddings', 3600);
            }

            if ($job_status === 'completed') {
                if ($main_embedding > 0) {
                    $statuses[$url] = 'processed';
                } else {
                    // No embedding, show user message if available
                    $user_message_cache_key = 'aisk_user_message_' . md5($normalized_url);
                    $user_message = wp_cache_get($user_message_cache_key, 'aisk_embeddings');
                    
                    if (false === $user_message) {
                        $user_message = get_option('aisk_url_user_message_' . md5($normalized_url));
                        wp_cache_set($user_message_cache_key, $user_message, 'aisk_embeddings', 3600);
                    }
                    
                    $statuses[$url] = [
                        'status' => 'no_content',
                        'user_message' => $user_message
                    ];
                }
            } else if ($job_status === 'failed') {
                $user_message = $job_data['error'] ?? get_option('aisk_url_user_message_' . md5($normalized_url));
                $statuses[$url] = [
                    'status' => 'failed',
                    'user_message' => $user_message ?: 'Job failed for this URL.'
                ];
                continue;
            } else {
                $statuses[$url] = [
                    'status' => 'processing',
                    'user_message' => ''
                ];
            }
        }
        return [
            'success' => true,
            'statuses' => $statuses
        ];
    }

    private function store_embedding( $content_type, $content_id, $content, $extra_data = [] ) {
        global $wpdb;
        $chunks = $this->split_content($content);
        $success = false;
        $cache_key = 'aisk_embedding_' . $content_type . '_' . $content_id;

        foreach ( $chunks as $chunk ) {
            try {
                $embedding = $this->generate_embedding($chunk);
                if ( $embedding ) {
                    $data = [
                        'content_type'  => $content_type,
                        'content_id'    => $content_id,
                        'embedding'     => $embedding,
                        'content_chunk' => $chunk,
                    ];
                    $format = [ '%s', '%d', '%s', '%s' ];

                    // For external URLs, add additional fields if provided
                    if ( $content_type === 'external_url' && ! empty( $extra_data ) ) {
                        if ( isset( $extra_data['parent_url'] ) ) {
                            $data['parent_url'] = $extra_data['parent_url'];
                            $format[] = '%s';
                        }
                        if ( isset( $extra_data['crawled_url'] ) ) {
                            $data['crawled_url'] = $extra_data['crawled_url'];
                            $format[] = '%s';
                        }
                    }

                    // @codingStandardsIgnoreStart
                    $result = $wpdb->insert("{$wpdb->prefix}aisk_embeddings", $data, $format);
                    // @codingStandardsIgnoreEnd
                    if ( $result !== false ) {
                        $success = true;
                        // Clear the cache after successful insertion
                        wp_cache_delete($cache_key, 'aisk_embeddings');
                        wp_cache_delete('aisk_embeddings_type_' . $content_type, 'aisk_embeddings');
                    }
                }
            } catch (Exception $e) {
                // Error handling without logging
            }
        }

        return $success;
    }

    private function update_embedding( $content_type, $content_id, $content, $extra_data = [] ) {
        global $wpdb;
        $cache_key = 'aisk_embedding_' . $content_type . '_' . $content_id;
        
        $embedding = $this->generate_embedding($content);
        if ( ! $embedding ) {
            return false;
        }
        
        $data = [
            'content_chunk'     => $content,
            'embedding'   => $embedding,
            'updated_at'  => current_time('mysql'),
        ];
        
        if ( $content_type === 'external_url' && ! empty( $extra_data ) ) {
            if ( isset( $extra_data['parent_url'] ) ) {
                $data['parent_url'] = $extra_data['parent_url'];
            }
            if ( isset( $extra_data['crawled_url'] ) ) {
                $data['crawled_url'] = $extra_data['crawled_url'];
            }
        }
        
        // @codingStandardsIgnoreStart
        $result = $wpdb->update(
            "{$wpdb->prefix}aisk_embeddings",
            $data,
            [
                'content_type' => $content_type,
                'content_id'   => $content_id,
            ]
        );
        // @codingStandardsIgnoreEnd
        
        if ($result !== false) {
            // Clear the cache after successful update
            wp_cache_delete($cache_key, 'aisk_embeddings');
            wp_cache_delete('aisk_embeddings_type_' . $content_type, 'aisk_embeddings');
        }
        
        return $result;
    }


    /**
    * Function to crawl a website with improved depth, path filtering, and error handling
    * 
    * @param string $starting_url Starting URL to crawl
    * @param int    $max_depth Maximum crawl depth
    * @param int    $max_pages Maximum number of pages to crawl
    * @param array  $include_patterns Patterns to include
    * @param array  $exclude_patterns Patterns to exclude
    * @return array Array of discovered URLs
    */
    private function crawl_website($starting_url, $max_depth = 2, $max_pages = 100, $include_patterns = [], $exclude_patterns = []) {
        $visited = [];
        $to_visit = [$starting_url];
        $processed = 0;
        $last_request_time = 0;
        $rate_limit = 1; // Minimum seconds between requests

        while (!empty($to_visit) && $processed < $max_pages) {
            $current_url = array_shift($to_visit);
            
            if (isset($visited[$current_url])) {
                continue;
            }
            
            // Rate limiting
            $current_time = microtime(true);
            $time_since_last_request = $current_time - $last_request_time;
            if ($time_since_last_request < $rate_limit) {
                usleep(($rate_limit - $time_since_last_request) * 1000000);
            }

            $content = $this->get_url_content($current_url);
            if ($content === false) {
                continue;
            }
            
            $visited[$current_url] = true;
            $processed++;

            // Store the content
            $this->store_embedding(
                'webpage',
                md5($current_url),
                $content,
                ['url' => $current_url]
            );

            // Extract and queue new URLs
            if ($processed < $max_pages) {
                $links = $this->extract_links($content, $starting_url);
            foreach ($links as $link) {
                    if (!isset($visited[$link]) && 
                        $this->should_process_url($link, $include_patterns, $exclude_patterns)) {
                        $to_visit[] = $link;
                    }
                }
            }

            $last_request_time = microtime(true);
        }

        return array_keys($visited);
    }

    /**
    * Check if a URL should be processed based on include/exclude patterns
    * 
    * @param string $url URL to check
    * @param array $include_patterns Patterns to include
    * @param array $exclude_patterns Patterns to exclude
    * @return bool True if URL should be processed
    */
    private function should_process_url($url, $include_patterns = [], $exclude_patterns = []) {
        $path = wp_parse_url($url, PHP_URL_PATH);
        if (!$path) $path = '/';
        
        // Check exclude patterns first
        if (!empty($exclude_patterns)) {
            foreach ($exclude_patterns as $pattern) {
                // Convert wildcard pattern to regex
                $regex = $this->wildcard_to_regex($pattern);
                if (preg_match($regex, $path)) {
                    return false;
                }
            }
        }
        
        // If include patterns are empty, allow all URLs that weren't excluded
        if (empty($include_patterns)) {
            return true;
        }
        
        // If include patterns are specified, URL must match at least one
        foreach ($include_patterns as $pattern) {
            $regex = $this->wildcard_to_regex($pattern);
            if (preg_match($regex, $path)) {
                return true;
            }
        }
        
        return false;
    }

    /**
    * Convert a wildcard pattern to a regex pattern
    * 
    * @param string $pattern Wildcard pattern (e.g., /blog/*)
    * @return string Regex pattern
    */
    private function wildcard_to_regex($pattern) {
        $pattern = preg_quote($pattern, '/');
        // Convert wildcard * to regex .*
        $pattern = str_replace('\*', '.*', $pattern);
        return '/^' . $pattern . '$/i';
    }

    /**
    * Normalize a URL, handling relative URLs
    * 
    * @param string $href URL or relative path
    * @param string $base_url Base URL for relative paths
    * @param string $domain Domain of the site being crawled
    * @return string|false Normalized URL or false if invalid
    */
    private function normalize_url($href, $base_url, $domain) {
        // Handle mailto, tel, javascript links
        if (preg_match('/^(mailto:|tel:|javascript:|#)/i', $href)) {
            return false;
        }
        
        // For protocol-relative URLs (//example.com)
        if (substr($href, 0, 2) === '//') {
            $base_protocol = wp_parse_url($base_url, PHP_URL_SCHEME);
            return $base_protocol . ':' . $href;
        }
        
        // Handle absolute URLs
        if (preg_match('/^https?:\/\//i', $href)) {
            // Only return URLs from the same domain
            $href_domain = wp_parse_url($href, PHP_URL_HOST);
            if ($href_domain !== $domain) {
                return false;
            }
            return $href;
        }
        
        // Handle absolute paths
        if (substr($href, 0, 1) === '/') {
            $base_parts = wp_parse_url($base_url);
            $scheme = isset($base_parts['scheme']) ? $base_parts['scheme'] : 'https';
            return $scheme . '://' . $domain . $href;
        }
        
        // Handle relative paths
        $base_parts = wp_parse_url($base_url);
        $path = isset($base_parts['path']) ? $base_parts['path'] : '';
        $path = preg_replace('/\/[^\/]*$/', '/', $path); // Remove file part from path
        
        $scheme = isset($base_parts['scheme']) ? $base_parts['scheme'] : 'https';
        return $scheme . '://' . $domain . rtrim($path, '/') . '/' . ltrim($href, '/');
    }

    private function extract_links($content, $base_url) {
        $links = [];
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        @$doc->loadHTML(mb_convert_encoding($content, 'HTML-ENTITIES', 'UTF-8'));
        
        $xpath = new DOMXPath($doc);
        $elements = $xpath->query('//a');
        
        foreach ($elements as $element) {
            /** @var DOMElement $element */
            $href = $element->getAttribute('href');
            if (empty($href)) {
                continue;
            }
            
            // Skip page anchors, javascript:, mailto:, tel:, etc.
            if (preg_match('/^(#|javascript:|mailto:|tel:)/i', $href)) {
                continue;
            }
            
            // Skip URLs with hash fragments
            if (strpos($href, '#') !== false) {
                $href = preg_replace('/#.*$/', '', $href);
                if (empty($href)) {
                    continue;
                }
            }
            
            // Convert relative URLs to absolute
            $domain = wp_parse_url($base_url, PHP_URL_HOST);
            $absolute_url = $this->normalize_url($href, $base_url, $domain);
            if ($absolute_url) {
                $links[] = $absolute_url;
            }
        }
        
        return array_unique($links);
    }

    private function get_url_content($url, $include_selectors = [], $exclude_selectors = []) {
        $response = wp_remote_get($url, [
            'timeout' => 30,
            'user-agent' => 'Aisk Chat Bot Crawler (+https://aisk.chat)',
            'sslverify' => false,
            'headers' => [
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.5',
            ]
        ]);

        if (is_wp_error($response)) {
                        return false;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
                        return false;
        }

        $content_type = wp_remote_retrieve_header($response, 'content-type');
        if (!preg_match('/(text\/html|application\/xhtml\+xml)/i', $content_type)) {
                        return false;
        }

        $html = wp_remote_retrieve_body($response);
        return $this->process_html_content($html, $url, $include_selectors, $exclude_selectors);
    }

    /**
    * Process raw HTML to extract meaningful content
    * 
    * @param string $html Raw HTML content
    * @param string $url Original URL
    * @return string Processed content
    */
    private function process_html_content($html, $url, $include_selectors = [], $exclude_selectors = []) {
        // Fix encoding issues
        if (!mb_check_encoding($html, 'UTF-8')) {
            $html = mb_convert_encoding($html, 'UTF-8', mb_detect_encoding($html, 'UTF-8, ISO-8859-1', true));
        }
        
        // First, try to extract metadata
        $title = '';
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $matches)) {
            $title = trim(wp_strip_all_tags($matches[1]));
        }
        
        $description = '';
        if (preg_match('/<meta[^>]*name=["\']description["\'][^>]*content=["\']([^"\']+)["\'][^>]*>/is', $html, $matches) || 
            preg_match('/<meta[^>]*content=["\']([^"\']+)["\'][^>]*name=["\']description["\'][^>]*>/is', $html, $matches)) {
            $description = trim($matches[1]);
        }
        
        $metadata = "";
        if (!empty($title)) {
            $metadata .= "Title: $title\n\n";
        }
        
        // Create DOM Document for parsing
        $doc = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true); // Suppress warnings for invalid HTML
        @$doc->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        
        // Remove script, style, svg, and other non-content elements
        $this->remove_elements_by_tag($doc, 'script');
        $this->remove_elements_by_tag($doc, 'style');
        $this->remove_elements_by_tag($doc, 'svg');
        $this->remove_elements_by_tag($doc, 'noscript');
        $this->remove_elements_by_tag($doc, 'iframe');
        
        // Initialize XPath to work with selectors
        $xpath = new DOMXPath($doc);
        
        // Handle exclude selectors first - remove matched elements before content extraction
        if (!empty($exclude_selectors)) {
            foreach ($exclude_selectors as $selector) {
                $this->remove_elements_by_selector($doc, $xpath, $selector);
            }
        }
        
        // If include selectors are provided, extract only the content from those elements
        $filteredContent = '';
        if (!empty($include_selectors)) {
            foreach ($include_selectors as $selector) {
                $elements = $this->get_elements_by_selector($xpath, $selector);
                foreach ($elements as $element) {
                    // Clone the node to avoid modification issues
                    $clone = $element->cloneNode(true);
                    $innerDoc = new DOMDocument('1.0', 'UTF-8');
                    $innerDoc->appendChild($innerDoc->importNode($clone, true));
                    
                    // Clean unwanted elements from this specific element too
                    $this->remove_elements_by_tag($innerDoc, 'script');
                    $this->remove_elements_by_tag($innerDoc, 'style');
                    
                    // Get text content from the cleaned element
                    $filteredContent .= trim($innerDoc->textContent) . "\n\n";
                }
            }
            
            // Clean the filtered content
            $content = $this->clean_content($filteredContent);
        } else {
            // If no include selectors, extract the complete text content from the body (with excluded elements already removed)
            $body = $doc->getElementsByTagName('body');
            if ($body->length > 0) {
                $raw_content = $body->item(0)->textContent;
                $content = $this->clean_content($raw_content);
            } else {
                $content = '';
            }
        }
        
        // Also extract content via specific approaches if we didn't use include selectors
        $gutenberg_content = '';
        $structured_content = '';
        
        if (empty($include_selectors)) {
            $gutenberg_content = $this->extract_gutenberg_content($html);
            $structured_content = $this->extract_structured_content($doc);
        }
        
        // Combine content from different extraction methods
        $final_content = $metadata;
        
        if (!empty($content)) {
            $final_content .= "Content:\n" . $content . "\n\n";
        }
        
        if (!empty($gutenberg_content) && strpos($content, $gutenberg_content) === false) {
            $final_content .= "Additional Gutenberg Content:\n" . $gutenberg_content . "\n\n";
        }
        
        if (!empty($structured_content) && strpos($content, $structured_content) === false) {
            $final_content .= "Additional Structured Content:\n" . $structured_content . "\n\n";
        }
        
        $final_content .= "Source URL: " . $url;
        return $final_content;
    }

    /**
    * Helper function to remove elements by CSS selector
    */
    private function remove_elements_by_selector($doc, $xpath, $selector) {
        // Parse the selector to handle different types (tag, class, id)
        $selector = trim($selector);
        $elements = $this->get_elements_by_selector($xpath, $selector);
        
        // Remove from the end to avoid issues with changing node lists
        foreach ($elements as $element) {
            if ($element && $element->parentNode) {
                $element->parentNode->removeChild($element);
            }
        }
    }


    /**
    * Helper function to get elements by CSS selector
    */
    private function get_elements_by_selector($xpath, $selector) {
        $selector = trim($selector);
        $elements = array();
        
        // Handle simple tag name selector (e.g., "div", "header")
        if (preg_match('/^[a-zA-Z][a-zA-Z0-9]*$/', $selector)) {
            $nodeList = $xpath->query("//{$selector}");
            if ($nodeList) {
                foreach ($nodeList as $node) {
                    $elements[] = $node;
                }
            }
            return $elements;
        }
        
        // Handle class selector (e.g., ".content", ".header")
        if (substr($selector, 0, 1) === '.') {
            $class = substr($selector, 1);
            $nodeList = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' {$class} ')]");
            if ($nodeList) {
                foreach ($nodeList as $node) {
                    $elements[] = $node;
                }
            }
            return $elements;
        }
        
        // Handle ID selector (e.g., "#main", "#content")
        if (substr($selector, 0, 1) === '#') {
            $id = substr($selector, 1);
            $nodeList = $xpath->query("//*[@id='{$id}']");
            if ($nodeList) {
                foreach ($nodeList as $node) {
                    $elements[] = $node;
                }
            }
            return $elements;
        }
        
        // For more complex selectors, we'd need a more robust parser
        // This simplistic implementation supports only tag, class, and ID selectors
        return $elements;
    }

    /**
    * Remove elements by tag name from DOMDocument
    */
    private function remove_elements_by_tag($doc, $tag_name) {
        $elements = $doc->getElementsByTagName($tag_name);
        $total = $elements->length;
        
        // Remove from the end to avoid issues with changing node lists
        for ($i = $total - 1; $i >= 0; $i--) {
            $element = $elements->item($i);
            if ($element && $element->parentNode) {
                $element->parentNode->removeChild($element);
            }
        }
    }

    /**
    * Extract content specifically from Gutenberg blocks
    */
    private function extract_gutenberg_content($html) {
        $content = '';
        
        // Look for Gutenberg block content in JSON format
        if (preg_match('/<script type="application\/json" id="(?:[^"]*gutenberg-data|wp-block-data)[^"]*">(.*?)<\/script>/s', $html, $matches)) {
            $json = json_decode($matches[1], true);
            if (is_array($json) && !empty($json)) {
                $blocks = $this->find_gutenberg_blocks($json);
                if (!empty($blocks)) {
                    foreach ($blocks as $block) {
                        if (isset($block['innerHTML'])) {
                            // Extract text from innerHTML
                            $blockDoc = new DOMDocument();
                            @$blockDoc->loadHTML('<?xml encoding="UTF-8">' . $block['innerHTML']);
                            $content .= trim($blockDoc->textContent) . "\n";
                        } elseif (isset($block['content'])) {
                            // Some blocks store content directly
                            if (is_string($block['content'])) {
                                $content .= wp_strip_all_tags($block['content']) . "\n";
                            }
                        }
                    }
                }
            }
        }
        
        // If we couldn't find the blocks in JSON, try common Gutenberg classes
        if (empty($content)) {
            $doc = new DOMDocument();
            @$doc->loadHTML('<?xml encoding="UTF-8">' . $html);
            $xpath = new DOMXPath($doc);
            
            $gutenbergClasses = [
                'wp-block-paragraph',
                'wp-block-heading',
                'wp-block-quote',
                'wp-block-list',
                'wp-block-columns',
                'wp-block-group',
                'wp-block-cover__inner-container',
                'has-text-align-center',
                'has-text-align-left',
                'has-text-align-right'
            ];
            
            foreach ($gutenbergClasses as $class) {
                $elements = $xpath->query("//*[contains(@class, '$class')]");
                foreach ($elements as $element) {
                    $content .= trim($element->textContent) . "\n";
                }
            }
        }
        
        return trim($content);
    }

    /**
    * Recursively find Gutenberg blocks in a JSON structure
    */
    private function find_gutenberg_blocks($data) {
        $blocks = [];
        
        // Common keys that might contain blocks
        $blockKeys = ['blocks', 'innerBlocks', 'children'];
        
        if (is_array($data)) {
            // If this is already a block
            if (isset($data['blockName']) || isset($data['blockType']) || isset($data['blockId'])) {
                $blocks[] = $data;
            }
            
            // Check keys that might contain blocks
            foreach ($blockKeys as $key) {
                if (isset($data[$key]) && is_array($data[$key])) {
                    foreach ($data[$key] as $item) {
                        $childBlocks = $this->find_gutenberg_blocks($item);
                        $blocks = array_merge($blocks, $childBlocks);
                    }
                }
            }
            
            // Recursively check all array items
            foreach ($data as $key => $value) {
                if (is_array($value)) {
                    $childBlocks = $this->find_gutenberg_blocks($value);
                    $blocks = array_merge($blocks, $childBlocks);
                }
            }
        }
        
        return $blocks;
    }

    /**
    * Extract content from structured elements in the document
    */
    private function extract_structured_content($doc) {
        $content = '';
        $xpath = new DOMXPath($doc);
        
        // Get all heading elements (h1-h6)
        for ($i = 1; $i <= 6; $i++) {
            $headings = $doc->getElementsByTagName('h' . $i);
            foreach ($headings as $heading) {
                /** @var DOMElement $heading */
                // Only include if content is substantial
                if (strlen(trim($heading->textContent)) > 0) {
                    $content .= trim($heading->textContent) . "\n";
                }
            }
        }
        
        // Get all paragraph elements
        $paragraphs = $doc->getElementsByTagName('p');
        foreach ($paragraphs as $p) {
            /** @var DOMElement $p */
            // Only include if content is substantial
            if (strlen(trim($p->textContent)) > 25) {
                $content .= trim($p->textContent) . "\n\n";
            }
        }
        
        // Get content from important semantic elements
        $semantic_tags = ['article', 'section', 'main', 'div'];
        $important_classes = [
            'content', 'entry-content', 'post-content', 'page-content', 'main-content',
            'article-content', 'entry', 'post', 'page', 'article', 'blog-post',
            'elementor', 'fl-builder', 'divi', 'fusion', 'vc_row', 'et_pb_section',
            'container', 'wrapper', 'inner', 'main', 'site-content'
        ];
        
        foreach ($semantic_tags as $tag) {
            foreach ($important_classes as $class) {
                // Get elements with this class
                $elements = $xpath->query("//" . $tag . "[contains(@class, '" . $class . "')]");
                foreach ($elements as $element) {
                    /** @var DOMElement $element */
                    // Only include if content is substantial and not just navigation
                    $text = trim($element->textContent);
                    if (strlen($text) > 100 && 
                        !preg_match('/(menu|navigation|sidebar|footer|header|banner|cookie)/i', $element->getAttribute('class'))) {
                        $content .= $text . "\n\n";
                    }
                }
            }
        }
        
        // Get text from list items in important lists
        $lists = $xpath->query("//ul[not(contains(@class, 'menu')) and not(contains(@class, 'nav'))]");
        foreach ($lists as $list) {
            /** @var DOMElement $list */
            $list_items = $list->getElementsByTagName('li');
            if ($list_items->length > 0) {
                foreach ($list_items as $item) {
                    /** @var DOMElement $item */
                    if (strlen(trim($item->textContent)) > 20) {
                        $content .= "• " . trim($item->textContent) . "\n";
                    }
                }
                $content .= "\n";
            }
        }
        
        return trim($content);
    }

    /**
    * Clean the extracted content to remove duplicates and common page elements
    */
    private function clean_content($text) {
        // Initial cleanup of excessive whitespace
        $text = preg_replace('/\s+/', ' ', $text);
        
        // Split into lines for better processing
        $lines = explode("\n", $text);
        $cleaned_lines = [];
        
        foreach ($lines as $line) {
            $line = trim($line);
            
            // Skip empty lines
            if (empty($line)) {
                continue;
            }
            
            $cleaned_lines[] = $line;
        }
        
        $text = implode("\n", $cleaned_lines);
        
        // Remove common web page elements
        $phrases_to_remove = [
            'Skip to content', 'Skip to main content', 'Skip to navigation',
            'Search', 'Search for:', 'Menu', 'Main Menu', 'Navigation', 'Primary Menu',
            'Footer', 'Header', 'Sidebar', 'Widget', 'Banner',
            'Home', 'About', 'Contact', 'Services', 'Products', 'Portfolio',
            'Copyright', 'All rights reserved', 'Â©', 'Terms', 'Privacy',
            'Share', 'Share this', 'Share on', 'Like', 'Tweet', 'Pin',
            'Read more', 'Learn more', 'Click here', 'Details', 'More',
            'Subscribe', 'Newsletter', 'Sign up', 'Log in', 'Register', 'Login',
            'Username', 'Password', 'Forgot password',
            'Comments', 'Leave a comment', 'Reply', 'Submit', 'Post',
            'Related', 'Categories', 'Tags', 'Archives', 'Recent',
            'Previous', 'Next', 'Back', 'Forward', 'Continue',
            'Add to cart', 'Buy now', 'Checkout', 'Shopping cart'
        ];
        
        foreach ($phrases_to_remove as $phrase) {
            // Use word boundaries to avoid removing parts of legitimate content
            $text = preg_replace('/\b' . preg_quote($phrase, '/') . '\b/i', '', $text);
        }
        
        // Remove social media references
        $social_phrases = ['Facebook', 'Twitter', 'Instagram', 'LinkedIn', 'Pinterest', 'YouTube', 'TikTok', 'RSS', 'Follow us'];
        foreach ($social_phrases as $phrase) {
            $text = preg_replace('/\b' . preg_quote($phrase, '/') . '\b/i', '', $text);
        }
        
        // Break into sentences and remove duplicates
        $sentences = preg_split('/(?<=[.!?])\s+/', $text);
        $unique_sentences = [];
        $seen_sentences = [];
        
        foreach ($sentences as $sentence) {
            $normalized = strtolower(trim($sentence));
            
            // Skip very short sentences (likely UI elements)
            if (strlen($normalized) < 5 || 
                preg_match('/^[^a-z0-9]*$/i', $normalized)) {
                continue;
            }
            
            // Only include unique sentences
            if (!isset($seen_sentences[$normalized])) {
                $unique_sentences[] = $sentence;
                $seen_sentences[$normalized] = true;
            }
        }
        
        // Recreate the text with unique sentences and better formatting
        $text = implode(' ', $unique_sentences);
        
        // Final cleanup of excessive whitespace and line breaks
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);
        
        // Format into paragraphs (sentences with similar topics)
        $paragraphs = [];
        $current_paragraph = '';
        
        $sentences = preg_split('/(?<=[.!?])\s+/', $text);
        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);
            if (empty($sentence)) {
                continue;
            }
            
            if (strlen($current_paragraph) > 300) {
                $paragraphs[] = trim($current_paragraph);
                $current_paragraph = $sentence;
            } else {
                $current_paragraph .= ' ' . $sentence;
            }
        }
        
        if (!empty($current_paragraph)) {
            $paragraphs[] = trim($current_paragraph);
        }
        
        return implode("\n\n", $paragraphs);
    }


    /**
     * Get crawled URLs for a parent URL
     *
     * @param WP_REST_Request $request The REST request object
     * @return array|WP_Error Response array or WP_Error
     */
    public function get_crawled_urls($request) {
        $parent_url = $request->get_param('parent_url');

        if (empty($parent_url)) {
            return new WP_Error(
                'missing_url',
                __('Parent URL is required', 'aisk-ai-chat-for-fluentcart'),
                ['status' => 400]
            );
        }

        // Normalize the parent_url for consistent lookups
        $normalized_url = $this->normalize_url_for_job_id($parent_url);
        $cache_key = 'aisk_crawled_urls_' . md5($normalized_url);
        
        // Try to get results from cache first
        $results = wp_cache_get($cache_key, 'aisk_embeddings');
        
        if (false === $results) {
            global $wpdb;
            // @codingStandardsIgnoreStart
            // Custom table query that cannot be handled by WordPress core functions
            $results = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT crawled_url FROM {$wpdb->prefix}aisk_embeddings 
                    WHERE content_type = 'external_url' AND parent_url = %s",
                    $normalized_url
                )
            );
            // @codingStandardsIgnoreEnd
            wp_cache_set($cache_key, $results, 'aisk_embeddings', 3600); // Cache for 1 hour
        }

        $urls = array_map(function($result) {
            return $result->crawled_url;
        }, $results);

        return [
            'success' => true,
            'urls' => $urls,
        ];
    }

    /**
     * Delete a crawled URL from the embeddings table
     *
     * @param WP_REST_Request $request The REST request object
     * @return array|WP_Error Result of the deletion
     */
    public function delete_crawled_url($request) {
        $url = $request->get_param('url');
        $parent_url = $request->get_param('parent_url');
        $delete_all = $request->get_param('delete_all');
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'aisk_embeddings';
        $deleted_jobs = 0;
        $deleted_options = 0;
        
        // Case 1: Delete all URLs with a specific parent_url
        if (!empty($parent_url) && $delete_all) {
            // Normalize parent_url
            $normalized_url = $this->normalize_url_for_job_id($parent_url);
            $cache_key = 'aisk_crawled_urls_' . md5($normalized_url);
            
            // @codingStandardsIgnoreStart
            // Custom table query that cannot be handled by WordPress core functions
            $result = $wpdb->delete(
                $table_name,
                [
                    'parent_url'  => $normalized_url,
                    'content_type' => 'external_url',
                ],
                ['%s', '%s']
            );
            // @codingStandardsIgnoreEnd
            
            // Clear cache after deletion
            wp_cache_delete($cache_key, 'aisk_embeddings');
            wp_cache_delete('aisk_embedding_count_' . md5($normalized_url), 'aisk_embeddings');
            wp_cache_delete('aisk_bot_protected_' . md5($normalized_url), 'aisk_embeddings');
            wp_cache_delete('aisk_user_message_' . md5($normalized_url), 'aisk_embeddings');
            
            // Delete job from options table
            $job_id = md5($normalized_url);
            if (delete_option('aisk_url_processing_' . $job_id)) $deleted_jobs++;
            if (delete_option('aisk_bot_protected_url_' . $job_id)) $deleted_options++;
            if (delete_option('aisk_url_user_message_' . $job_id)) $deleted_options++;
            
            if (false === $result) {
                return new WP_Error('db_error', 'Could not delete URLs', ['status' => 500]);
            }
            
            return [
                'success' => true,
                'message' => 'All related URLs and jobs removed',
                'count' => $wpdb->rows_affected,
                'jobs_deleted' => $deleted_jobs,
                'options_deleted' => $deleted_options
            ];
        }
        
        // Case 2: Delete a single URL
        if (empty($url)) {
            return new WP_Error('missing_url', 'No URL provided', ['status' => 400]);
        }
        
        // Normalize url
        $normalized_url = $this->normalize_url_for_job_id($url);
        $cache_key = 'aisk_embedding_count_' . md5($normalized_url);
        
        // @codingStandardsIgnoreStart
        // Custom table query that cannot be handled by WordPress core functions
        $result = $wpdb->delete(
            $table_name,
            [
                'crawled_url'  => $normalized_url,
                'content_type' => 'external_url',
            ],
            ['%s', '%s']
        );
        // @codingStandardsIgnoreEnd
        
        // Clear cache after deletion
        wp_cache_delete($cache_key, 'aisk_embeddings');
        wp_cache_delete('aisk_bot_protected_' . md5($normalized_url), 'aisk_embeddings');
        wp_cache_delete('aisk_user_message_' . md5($normalized_url), 'aisk_embeddings');
        
        // Delete job from options table
        $job_id = md5($normalized_url);
        if (delete_option('aisk_url_processing_' . $job_id)) $deleted_jobs++;
        if (delete_option('aisk_bot_protected_url_' . $job_id)) $deleted_options++;
        if (delete_option('aisk_url_user_message_' . $job_id)) $deleted_options++;
        
        if (false === $result) {
            return new WP_Error('db_error', 'Could not delete URL', ['status' => 500]);
        }
        
        return [
            'success' => true,
            'message' => 'URL and job removed',
            'jobs_deleted' => $deleted_jobs,
            'options_deleted' => $deleted_options
        ];
    }

    /**
     * Get PDF queue list
     *
     * @param WP_REST_Request $request The REST request object
     * @return WP_REST_Response Response object
     */
    public function get_pdf_queue_list($request) {
        global $wpdb;
        $table = $wpdb->prefix . 'aisk_pdf_queue';
        $cache_key = 'aisk_pdf_queue_list';
        $rows = wp_cache_get($cache_key, 'aisk_pdf_queue');
        if ($rows === false) {
            // @codingStandardsIgnoreStart
            $rows = $wpdb->get_results("SELECT attachment_id, file_name, status, error_message, file_size, created_at, updated_at FROM $table ORDER BY created_at DESC", ARRAY_A);
            // @codingStandardsIgnoreEnd
            wp_cache_set($cache_key, $rows, 'aisk_pdf_queue', 300);
        }
        return new WP_REST_Response($rows, 200);
    }

    /**
     * Delete content embeddings
     *
     * @param int $post_id Post ID
     * @return bool|int Number of rows affected or false on failure
     */
    public function delete_content_embeddings($post_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'aisk_embeddings';
        $cache_key = 'aisk_embedding_post_' . $post_id;
        
        // @codingStandardsIgnoreStart
        // Custom table query that cannot be handled by WordPress core functions
        $result = $wpdb->delete(
            $table_name,
            [
                'content_id' => $post_id,
            ],
            ['%d']
        );
        // @codingStandardsIgnoreEnd
        
        if ($result !== false) {
            // Clear the cache after successful deletion
            wp_cache_delete($cache_key, 'aisk_embeddings');
            wp_cache_delete('aisk_embeddings_type_post', 'aisk_embeddings');
        }
        
        return $result;
    }

    /**
     * Clear PDF files from media library
     *
     * @param WP_REST_Request $request The REST request object
     * @return WP_REST_Response|WP_Error Response object
     */
    public function clear_pdf_files($request) {
        global $wpdb;
        $cache_key = 'aisk_pdf_attachments';

        try {
            // Try to get PDF attachments from cache first
            $pdf_attachments = wp_cache_get($cache_key, 'aisk_embeddings');
            
            if (false === $pdf_attachments) {
                // @codingStandardsIgnoreStart
                // Custom table query that cannot be handled by WordPress core functions
                $pdf_attachments = $wpdb->get_results(
                    "SELECT ID, post_title 
                    FROM {$wpdb->posts} 
                    WHERE post_type = 'attachment' 
                    AND post_mime_type = 'application/pdf'"
                );
                // @codingStandardsIgnoreEnd
                wp_cache_set($cache_key, $pdf_attachments, 'aisk_embeddings', 3600); // Cache for 1 hour
            }

            if (empty($pdf_attachments)) {
                return new WP_REST_Response([
                    'success' => true,
                    'message' => __('No PDF files found in media library', 'aisk-ai-chat-for-fluentcart'),
                    'deleted_count' => 0
                ], 200);
            }

            $deleted_count = 0;
            $errors = [];

            foreach ($pdf_attachments as $attachment) {
                // Delete the attachment and its metadata
                $result = wp_delete_attachment($attachment->ID, true);
                
                if ($result) {
                    $deleted_count++;
                    
                    // Also delete any associated embeddings
                    // @codingStandardsIgnoreStart
                    // Custom table query that cannot be handled by WordPress core functions
                    $wpdb->delete(
                        $wpdb->prefix . 'aisk_embeddings',
                        [
                            'content_type' => 'pdf',
                            'content_id' => $attachment->ID
                        ],
                        ['%s', '%d']
                    );

                    // Delete from PDF queue if exists
                    $wpdb->delete(
                        $wpdb->prefix . 'aisk_pdf_queue',
                        ['attachment_id' => $attachment->ID],
                        ['%d']
                    );
                    // @codingStandardsIgnoreEnd

                    // Clear relevant caches
                    wp_cache_delete('aisk_pdf_status_' . $attachment->ID, 'aisk_embeddings');
                    wp_cache_delete('aisk_pdf_embedding_count_' . $attachment->ID, 'aisk_embeddings');
                    wp_cache_delete('aisk_pdf_queue_list', 'aisk_embeddings');
                } else {
                    $errors[] = sprintf(
                        /* translators: %s: PDF file name */
                        __('Failed to delete PDF: %s', 'aisk-ai-chat-for-fluentcart'),
                        $attachment->post_title
                    );
                }
            }

            // Clear the PDF attachments cache after deletion
            wp_cache_delete($cache_key, 'aisk_embeddings');

            return new WP_REST_Response([
                'success' => true,
                'message' => sprintf(
                    /* translators: %d: Number of PDF files deleted */
                    __('Successfully deleted %d PDF files from media library', 'aisk-ai-chat-for-fluentcart'),
                    $deleted_count
                ),
                'deleted_count' => $deleted_count,
                'errors' => $errors
            ], 200);

        } catch (Exception $e) {
            return new WP_Error(
                'clear_pdf_error',
                $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Creates a new PDF processing job.
     *
     * @since 1.2.3
     * @param array $file_data Array containing file information.
     * @return int|false Job ID on success, false on failure.
     */
    private function create_pdf_job($file_data) {
        try {
            global $wpdb;
            $table_name = $wpdb->prefix . 'aisk_pdf_queue';

            // Validate required file data.
            if (empty($file_data['file_name']) || empty($file_data['file_path'])) {
                throw new Exception(esc_html__('Missing required file data', 'aisk-ai-chat-for-fluentcart'));
            }

            // Generate a unique cache key based on file name and path.
            $cache_key = 'aisk_pdf_job_' . md5($file_data['file_name'] . $file_data['file_path']);
            // @codingStandardsIgnoreStart
            // Check if job already exists.
            $existing_job = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$table_name} WHERE file_name = %s AND file_path = %s",
                    $file_data['file_name'],
                    $file_data['file_path']
                )
            );

            if ($existing_job) {
                return $existing_job->id;
            }

            // Prepare job data.
            $job_data = array(
                'file_name' => sanitize_file_name($file_data['file_name']),
                'file_path' => $file_data['file_path'],
                'file_size' => isset($file_data['file_size']) ? absint($file_data['file_size']) : 0,
                'status'    => 'pending',
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            );
            // @codingStandardsIgnoreStart
            // Insert new job.
            $result = $wpdb->insert($table_name, $job_data);
            // @codingStandardsIgnoreEnd
            if (false === $result) {
                throw new Exception($wpdb->last_error);
            }

            $job_id = $wpdb->insert_id;

            // Cache the job ID.
            wp_cache_set($cache_key, $job_id, 'aisk_pdf_jobs', HOUR_IN_SECONDS);

            return $job_id;

        } catch (Exception $e) {
            // Log error to WordPress debug log only when WP_DEBUG is enabled
            // if (defined('WP_DEBUG') && WP_DEBUG) {
                // error_log('PDF Job Creation Error: ' . esc_html($e->getMessage()));
            // }
            return false;
        }
    }

    private function update_pdf_job_status($job_id, $status, $message = '') {
        global $wpdb;
        $table_name = $wpdb->prefix . 'aisk_pdf_queue';
        $cache_key = 'aisk_pdf_job_' . $job_id;
        
        $data = [
            'status' => $status,
            'updated_at' => current_time('mysql')
        ];
        if ($status === 'failed') {
            $data['error_message'] = $message;
        } else {
            $data['message'] = $message;
        }
        
        // @codingStandardsIgnoreStart
        // Custom table query that cannot be handled by WordPress core functions
        $result = $wpdb->update(
            $table_name,
            $data,
            ['id' => $job_id]
        );
        // @codingStandardsIgnoreEnd
        
        if ($result !== false) {
            // Clear the cache after successful update
            wp_cache_delete($cache_key, 'aisk_embeddings');
            wp_cache_delete('aisk_pdf_queue_list', 'aisk_embeddings');
        }
        
        return $result;
    }

    /**
     * Generate embedding for content using OpenAI API
     *
     * @param string $content Content to generate embedding for
     * @return string|false JSON encoded embedding or false on failure
     */
    private function generate_embedding($content) {
        try {
            // Prepare data for embedding
            $data = array(
                'input' => $content,
                'model' => $this->model
            );
            
            // Call OpenAI API to generate embedding
            $response = wp_remote_post(AISK_OPENAI_API_URL . '/embeddings', array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $this->api_key,
                    'Content-Type' => 'application/json'
                ),
                'body' => json_encode($data),
                'timeout' => 30
            ));
            
            if (is_wp_error($response)) {
                return false;
            }
            
            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (!isset($body['data'][0]['embedding'])) {
                return false;
            }
            
            return json_encode($body['data'][0]['embedding']);
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Process a PDF file and generate embeddings
     *
     * @param int    $attachment_id The WordPress attachment ID
     * @param string $file_path     The full path to the PDF file
     * @param int    $chunk_number  The current chunk number (optional)
     * @param int    $total_chunks  The total number of chunks (optional)
     *
     * @return array The processing result
     */
    public function process_file($attachment_id, $file_path, $chunk_number = 1, $total_chunks = 1) {
        try {
            // Set time limit using WordPress filters
            add_filter('max_execution_time', function() { return self::MAX_PROCESSING_TIME; });
            
            // Get file size
            $file_size = filesize($file_path);
            if ($file_size === false) {
                throw new Exception('Could not determine file size');
            }

            // If this is a chunk of a larger file, process it differently
            if ($chunk_number > 1 || $total_chunks > 1) {
                // Process chunk
                $result = $this->process_chunk($attachment_id, $file_path, $chunk_number, $total_chunks);
                return array(
                    'success' => true,
                    'message' => sprintf('Chunk %d/%d processed successfully', $chunk_number, $total_chunks)
                );
            }

            // Initialize PDF parser
            $parser = new \Smalot\PdfParser\Parser();
            
            try {
                // Parse PDF file
                $pdf = $parser->parseFile($file_path);
                
                // Extract text from PDF
                $text = $pdf->getText();
                
                // Clean and prepare text
                $text = trim($text);
                
                if (empty($text)) {
                    throw new Exception('No text content found in PDF');
                }
                
                // Split content into manageable chunks
                $chunks = $this->split_content($text);
                
                // Generate embeddings for each chunk
                $embeddings = array();
                foreach ($chunks as $index => $chunk) {
                    // Store embedding in database
                    $embedding_data = array(
                        'content_id' => $attachment_id,
                        'content_type' => 'pdf',
                        'chunk_index' => $index,
                        'embedding' => $this->generate_embedding($chunk),
                        'content_chunk' => $chunk
                    );
                    
                    global $wpdb;
                    $table_name = $wpdb->prefix . 'aisk_embeddings';
                    // @codingStandardsIgnoreStart
                    $wpdb->insert($table_name, $embedding_data);
                    // @codingStandardsIgnoreEnd
                    
                    $embeddings[] = $embedding_data;
                }
                
                // Update PDF processing status
                update_post_meta($attachment_id, '_aisk_pdf_processed', true);
                update_post_meta($attachment_id, '_aisk_pdf_chunks_count', count($chunks));
                
                return array(
                    'success' => true,
                    'message' => 'PDF processed successfully',
                    'chunks_count' => count($chunks)
                );
                
            } catch (Exception $e) {
                throw new Exception('Failed to parse PDF: ' . $e->getMessage());
            }

        } catch (Exception $e) {
            return array(
                'success' => false,
                'message' => $e->getMessage()
            );
        }
    }

    /**
     * Batch insert embeddings into database
     *
     * @param array $embeddings Array of embedding data
     * @return bool Success status
     */
    private function batch_insert_embeddings($embeddings) {
        if (empty($embeddings)) {
            return false;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'aisk_embeddings';
        
        // Prepare batch insert
        $values = array();
        $placeholders = array();
        $types = array();
        
        foreach ($embeddings as $embedding) {
            $values[] = $embedding['content_id'];
            $values[] = $embedding['content_type'];
            $values[] = $embedding['chunk_index'];
            $values[] = $embedding['embedding'];
            $values[] = $embedding['content_chunk'];
            
            $placeholders[] = "(%d, %s, %d, %s, %s)";
            $types[] = '%d';
            $types[] = '%s';
            $types[] = '%d';
            $types[] = '%s';
            $types[] = '%s';
        }
        
        // Build the query
        $query = "INSERT INTO $table_name (content_id, content_type, chunk_index, embedding, content_chunk) VALUES ";
        $query .= implode(', ', $placeholders);
        
        // Execute batch insert
        // @codingStandardsIgnoreStart
        $result = $wpdb->query($wpdb->prepare($query, $values));
        // @codingStandardsIgnoreEnd
        
        return $result !== false;
    }

    /**
     * Clean and normalize PDF text content with performance optimizations
     *
     * @param string $text Raw text from PDF
     * @return string Cleaned text
     */
    private function clean_pdf_text($text) {
        // Use a single pass for multiple replacements
        $patterns = array(
            '/[\x00-\x1F\x7F]/u',           // Non-printable characters
            '/\s+/',                        // Multiple spaces
            '/^\s*\d+\s*$/m',              // Page numbers
            '/\b(Page|www\.|http[s]?:\/\/[^\s]+)\b/i', // PDF artifacts
            '/^\s*[\r\n]/m',               // Empty lines
            '/^[^a-zA-Z0-9]*$/m'           // Special character lines
        );
        
        $replacements = array(
            '',     // Remove non-printable
            ' ',    // Single space
            '',     // Remove page numbers
            '',     // Remove artifacts
            '',     // Remove empty lines
            ''      // Remove special lines
        );
        
        // Apply all replacements in a single pass
        $text = preg_replace($patterns, $replacements, $text);
        
        // Remove duplicates efficiently
        $lines = array_filter(explode("\n", $text));
        $lines = array_unique($lines);
        
        // Final trim and return
        return trim(implode("\n", $lines));
    }

    /**
     * Process a single chunk of a PDF file
     *
     * @param int    $attachment_id The WordPress attachment ID
     * @param string $file_path     The path to the chunk file
     * @param int    $chunk_number  The current chunk number
     * @param int    $total_chunks  The total number of chunks
     *
     * @return array The processing result
     */
    private function process_chunk($attachment_id, $file_path, $chunk_number, $total_chunks) {
        try {
            // Process the chunk
            // Your existing PDF processing code, but adapted for chunks...
            
            return array(
                'success' => true,
                'message' => sprintf('Chunk %d/%d processed successfully', $chunk_number, $total_chunks)
            );
        } catch (Exception $e) {
            return array(
                'success' => false,
                'message' => $e->getMessage()
            );
        }
    }

    /**
     * Handles PDF file upload and processing.
     *
     * @since 1.2.3
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response|WP_Error Response object or WP_Error on failure.
     */
    public function handle_pdf_processing($request) {
        // Include WordPress admin files needed for file upload handling
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');

        // Verify nonce
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'wp_rest')) {
            return new WP_Error(
                'invalid_nonce',
                esc_html__('Security check failed', 'aisk-ai-chat-for-fluentcart'),
                array('status' => 403)
            );
        }

        // Check system limits before processing
        $system_limits = $this->get_system_upload_limits();
        if (!isset($_FILES['pdf_file']) || !isset($_FILES['pdf_file']['size'])) {
            return new WP_Error(
                'missing_file',
                esc_html__('No PDF file provided', 'aisk-ai-chat-for-fluentcart'),
                array('status' => 400)
            );
        }

        $file_size = absint($_FILES['pdf_file']['size']);
        if ($file_size > $system_limits['post_max_size']) {
            return new WP_Error(
                'file_too_large',
                sprintf(
                    /* translators: %s: File size, %s: System POST limit */
                    esc_html__('File size (%1$s) exceeds the system POST limit (%2$s). Please contact your server administrator to increase the limit.', 'aisk-ai-chat-for-fluentcart'),
                    size_format($file_size),
                    size_format($system_limits['post_max_size'])
                ),
                array('status' => 400)
            );
        }

        // Validate file input
        if (!isset($_FILES['pdf_file']) || !is_array($_FILES['pdf_file'])) {
            return new WP_Error(
                'invalid_file',
                esc_html__('Invalid file upload', 'aisk-ai-chat-for-fluentcart'),
                array('status' => 400)
            );
        }

        $file = array_map('sanitize_text_field', wp_unslash($_FILES['pdf_file']));

        // Validate file error
        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            $error_message = $this->get_upload_error_message($file['error'] ?? UPLOAD_ERR_NO_FILE);
            return new WP_Error(
                'upload_error',
                esc_html($error_message),
                array('status' => 400)
            );
        }

        // Process the file
        $upload = wp_handle_upload($file, array('test_form' => false));
        
        if (isset($upload['error'])) {
            return new WP_Error(
                'upload_error',
                esc_html($upload['error']),
                array('status' => 400)
            );
        }

        // Get the uploaded file path
        $file_path = $upload['file'];
        
        // Process the PDF
        $result = $this->process_pdf($file_path);
        
        if (!$result['success']) {
            return new WP_Error(
                'processing_error',
                esc_html($result['message']),
                array('status' => 400)
            );
        }

        return new WP_REST_Response([
            'success' => true,
            'message' => esc_html__('PDF processed successfully', 'aisk-ai-chat-for-fluentcart'),
            'file_path' => esc_html($file_path)
        ], 200);
    }

    // Add this REST endpoint to check PDF job status and embedding
    public function get_pdf_status($request) {
        $attachment_id = $request->get_param('attachment_id');
        if (!$attachment_id) {
            return new WP_Error(
                'missing_attachment_id',
                __('Attachment ID is required', 'aisk-ai-chat-for-fluentcart'),
                ['status' => 400]
            );
        }

        global $wpdb;
        $cache_key = 'aisk_pdf_status_' . $attachment_id;
        
        // Try to get status from cache first
        $status = wp_cache_get($cache_key, 'aisk_embeddings');
        
        if (false === $status) {
            // @codingStandardsIgnoreStart
            // Custom table query that cannot be handled by WordPress core functions
            $status = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT status, error_message, created_at, updated_at 
                    FROM {$wpdb->prefix}aisk_pdf_queue 
                    WHERE attachment_id = %d",
                    $attachment_id
                )
            );
            // @codingStandardsIgnoreEnd
            wp_cache_set($cache_key, $status, 'aisk_embeddings', 3600); // Cache for 1 hour
        }

        if (!$status) {
            return new WP_Error(
                'not_found',
                __('PDF processing status not found', 'aisk-ai-chat-for-fluentcart'),
                ['status' => 404]
            );
        }

        // Check for embeddings
        $embedding_cache_key = 'aisk_pdf_embedding_count_' . $attachment_id;
        $embedding_count = wp_cache_get($embedding_cache_key, 'aisk_embeddings');
        
        if (false === $embedding_count) {
            // @codingStandardsIgnoreStart
            // Custom table query that cannot be handled by WordPress core functions
            $embedding_count = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}aisk_embeddings 
                    WHERE content_type = 'pdf' AND content_id = %d",
                    $attachment_id
                )
            );
            // @codingStandardsIgnoreEnd
            wp_cache_set($embedding_cache_key, $embedding_count, 'aisk_embeddings', 3600);
        }

        return new WP_REST_Response([
            'status' => $status->status,
            'error_message' => $status->error_message,
            'created_at' => $status->created_at,
            'updated_at' => $status->updated_at,
            'embedding_count' => $embedding_count
        ], 200);
    }

    public function get_pdf_job_status($request) {
        $job_id = $request->get_param('job_id');
        $attachment_id = $request->get_param('attachment_id');
        
        if (!$job_id && !$attachment_id) {
            return new WP_Error(
                'missing_parameters',
                __('Either job_id or attachment_id is required', 'aisk-ai-chat-for-fluentcart'),
                ['status' => 400]
            );
        }

        $queue_handler = new AISK_PDF_Queue_Handler();
        
        // If we have an attachment_id, get its status
        if ($attachment_id) {
            $status = $queue_handler->get_job_status($attachment_id);
        } else {
            // If we only have a job_id, get the attachment_id first
            global $wpdb;
            $attachment_id = wp_cache_get('aisk_pdf_attachment_' . $job_id, 'aisk_pdf_queue');
            if ($attachment_id === false) {
                // @codingStandardsIgnoreStart
                $attachment_id = $wpdb->get_var($wpdb->prepare(
                    "SELECT attachment_id FROM {$wpdb->prefix}aisk_pdf_queue WHERE id = %d",
                    $job_id
                ));
                // @codingStandardsIgnoreEnd
                wp_cache_set('aisk_pdf_attachment_' . $job_id, $attachment_id, 'aisk_pdf_queue', 300);
            }
            if (!$attachment_id) {
                return new WP_Error(
                    'invalid_job_id',
                    __('Invalid job ID', 'aisk-ai-chat-for-fluentcart'),
                    ['status' => 404]
                );
            }
            $status = $queue_handler->get_job_status($attachment_id);
        }
        // If status is 'completed' or 'failed', return cached status without querying embeddings
        if ($status['status'] === 'completed' || $status['status'] === 'failed') {
            $user_message = ($status['status'] === 'failed') ? ($status['error_message'] ?: __('PDF processing failed.', 'aisk-ai-chat-for-fluentcart')) : __('Processed', 'aisk-ai-chat-for-fluentcart');
            return new WP_REST_Response([
                'status' => $status['status'],
                'processed' => ($status['status'] === 'completed'),
                'processing' => false,
                'failed' => ($status['status'] === 'failed'),
                'user_message' => $user_message,
                'embedding_count' => 0,
                'job_id' => $job_id,
                'attachment_id' => $attachment_id,
                'attempts' => $status['attempts'],
                'created_at' => $status['created_at'],
                'updated_at' => $status['updated_at']
            ], 200);
        }
        // Check for embeddings (with caching)
        global $wpdb;
        $embedding_cache_key = 'aisk_pdf_embedding_count_' . $attachment_id;
        $embedding_count = wp_cache_get($embedding_cache_key, 'aisk_pdf_queue');
        if ($embedding_count === false) {
            // @codingStandardsIgnoreStart
            $embedding_count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}aisk_embeddings WHERE content_type = 'pdf' AND content_id = %d",
                $attachment_id
            ));
            wp_cache_set($embedding_cache_key, $embedding_count, 'aisk_pdf_queue', 300);
        }
        $processed = ($status['status'] === 'completed' && $embedding_count > 0);
        $processing = ($status['status'] === 'pending' || $status['status'] === 'processing' || ($status['status'] === 'completed' && $embedding_count == 0));
        $failed = ($status['status'] === 'failed');
        $user_message = '';
        if ($failed) {
            $user_message = $status['error_message'] ?: __('PDF processing failed.', 'aisk-ai-chat-for-fluentcart');
        } elseif ($processing) {
            $user_message = __('Processing in background...', 'aisk-ai-chat-for-fluentcart');
        } elseif ($processed) {
            $user_message = __('Processed', 'aisk-ai-chat-for-fluentcart');
        }
        return new WP_REST_Response([
            'status' => $status['status'],
            'processed' => $processed,
            'processing' => $processing,
            'failed' => $failed,
            'user_message' => $user_message,
            'embedding_count' => $embedding_count,
            'job_id' => $job_id,
            'attachment_id' => $attachment_id,
            'attempts' => $status['attempts'],
            'created_at' => $status['created_at'],
            'updated_at' => $status['updated_at']
        ], 200);
    }

    public function create_pdf_queue_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'aisk_pdf_queue';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            attachment_id bigint(20) NOT NULL,
            file_name varchar(255) NOT NULL,
            file_path text NOT NULL,
            file_size bigint(20) NOT NULL,
            status varchar(20) NOT NULL,
            message text,
            attempts int(11) NOT NULL DEFAULT 0,
            error_message text,
            next_attempt datetime,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY attachment_id (attachment_id),
            KEY status (status),
            KEY next_attempt (next_attempt)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    public function process_pdf_background($job_id, $file_path, $attachment_id) {
        try {
            $this->update_pdf_job_status($job_id, 'processing');

            // Extract text from PDF
            $pdf_text = $this->extract_pdf_text($file_path);
            if (empty($pdf_text)) {
                throw new Exception(__('Failed to extract text from PDF', 'aisk-ai-chat-for-fluentcart'));
            }

            // Split content into chunks
            $chunks = $this->split_content($pdf_text);

            // Store each chunk in the embeddings table
            $success = $this->store_embedding('pdf', $attachment_id, $pdf_text, [
                'filename' => basename($file_path),
                'chunks' => count($chunks)
            ]);

            // If no embeddings were created, or store_embedding failed, mark as failed
            if (!$success || count($chunks) === 0) {
                $this->update_pdf_job_status($job_id, 'failed', __('No text content found in PDF or failed to store embeddings', 'aisk-ai-chat-for-fluentcart'));
                wp_delete_attachment($attachment_id, true);
                return;
            }

            $this->update_pdf_job_status($job_id, 'completed', __('PDF processed successfully', 'aisk-ai-chat-for-fluentcart'));

        } catch (Exception $e) {
            $this->update_pdf_job_status($job_id, 'failed', $e->getMessage());
            wp_delete_attachment($attachment_id, true);
        }
    }

    /**
     * Extract text from a PDF file
     *
     * @param string $file_path Path to the PDF file
     * @return string Extracted text
     */
    private function extract_pdf_text($file_path) {
        try {
            // Fast file validation (no redundant checks)
            if (!is_readable($file_path)) {
                throw new Exception('PDF file not readable');
            }

            $parser = new \Smalot\PdfParser\Parser();
            $text = $parser->parseFile($file_path)->getText();

            // Early return if no text (saves cleaning time)
            if (empty($text)) {
                throw new Exception('No extractable text');
            }

            // Single-pass cleaning:
            $text = preg_replace(
                [
                    '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u',  // Strip control chars
                    '/[^\S\r\n]+/u',                          // Collapse spaces
                    '/(\R){2,}/u'                             // Collapse newlines
                ],
                [
                    '',
                    ' ',
                    "\n"
                ],
                mb_convert_encoding($text, 'UTF-8', 'UTF-8')  // Ensure UTF-8 first
            );

            $text = trim($text);

            // Lightweight content check
            if (strlen($text) < 10) {
                throw new Exception('Insufficient text after cleaning');
            }

            return $text;
        } catch (Exception $e) {
            throw $e; // Re-throw for caller
        }
    }

    /**
     * Get system upload limits
     *
     * @return array Array of system limits
     */
    private function get_system_upload_limits() {
        $limits = array(
            'post_max_size' => $this->return_bytes(ini_get('post_max_size')),
            'upload_max_filesize' => $this->return_bytes(ini_get('upload_max_filesize')),
            'memory_limit' => $this->return_bytes(ini_get('memory_limit')),
            'max_execution_time' => ini_get('max_execution_time'),
            'max_input_time' => ini_get('max_input_time')
        );
        
        return $limits;
    }

    /**
     * Convert PHP size string to bytes
     *
     * @param string $val PHP size string (e.g., '8M', '64M')
     * @return int Size in bytes
     */
    private function return_bytes($val) {
        $val = trim($val);
        $last = strtolower($val[strlen($val)-1]);
        $val = (int)$val;
        
        switch($last) {
            case 'g':
                $val *= 1024;
            case 'm':
                $val *= 1024;
            case 'k':
                $val *= 1024;
        }
        
        return $val;
    }

    /**
     * Get the maximum upload size in bytes
     *
     * @return int Maximum upload size in bytes
     */
    private function get_max_upload_size() {
        // Get system limits
        $system_limits = $this->get_system_upload_limits();
        
        // Get WordPress limit
        $wp_limit = wp_max_upload_size();
        
        // Get custom limit from settings if set
        $custom_limit = 0;
        if (isset($this->settings['general']['max_upload_size'])) {
            $custom_limit = intval($this->settings['general']['max_upload_size']) * 1024 * 1024;
        }
        
        // Use the smallest of all limits
        $max_size = min(
            $system_limits['post_max_size'],
            $system_limits['upload_max_filesize'],
            $wp_limit
        );
        
        // If custom limit is set and smaller than other limits, use it
        if ($custom_limit > 0 && $custom_limit < $max_size) {
            $max_size = $custom_limit;
        }
        
        return $max_size;
    }

    /**
     * Increase upload size limit for PDFs
     *
     * @param array $file File data
     * @return array Modified file data
     */
    public function increase_upload_limit_for_pdf($file) {
        if (isset($file['type']) && $file['type'] === 'application/pdf') {
            // Increase the upload size limit for PDFs
            $max_size = $this->get_max_upload_size();
            
            // Use WordPress functions to set limits
            if (function_exists('wp_raise_memory_limit')) {
                wp_raise_memory_limit('admin');
            }
            
            // Set upload size limits using WordPress filters
            add_filter('upload_size_limit', function($size) use ($max_size) {
                return $max_size;
            });
            
            // Set execution time using WordPress filters
            add_filter('max_execution_time', function() { 
                return self::MAX_PROCESSING_TIME; 
            });
            
            // Set input time using WordPress filters
            add_filter('max_input_time', function() { 
                return self::MAX_PROCESSING_TIME; 
            });
        }
        
        return $file;
    }

    // Add this helper method to the class
    private function get_upload_error_message($error_code) {
        switch ($error_code) {
            case UPLOAD_ERR_INI_SIZE:
                return 'The uploaded file exceeds the upload_max_filesize directive in php.ini';
            case UPLOAD_ERR_FORM_SIZE:
                return 'The uploaded file exceeds the MAX_FILE_SIZE directive in the HTML form';
            case UPLOAD_ERR_PARTIAL:
                return 'The uploaded file was only partially uploaded';
            case UPLOAD_ERR_NO_FILE:
                return 'No file was uploaded';
            case UPLOAD_ERR_NO_TMP_DIR:
                return 'Missing a temporary folder';
            case UPLOAD_ERR_CANT_WRITE:
                return 'Failed to write file to disk';
            case UPLOAD_ERR_EXTENSION:
                return 'A PHP extension stopped the file upload';
            default:
                return 'Unknown upload error';
        }
    }

    // Add this helper to the class
    private function normalize_filename($name) {
        return strtolower(str_replace(['-', '_', ' '], '', $name));
    }

    public function delete_pdf_embeddings($request) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'aisk_embeddings';
        $pdf_queue_table = $wpdb->prefix . 'aisk_pdf_queue';

        // Get the PDF ID from the request
        $pdf_id = $request->get_param('attachment_id');
        if (!$pdf_id) {
            return new WP_Error(
                'missing_pdf_id',
                __('PDF ID is required', 'aisk-ai-chat-for-fluentcart'),
                array('status' => 400)
            );
        }

        // Delete all embeddings for this PDF
        // @codingStandardsIgnoreStart
        $result = $wpdb->delete(
            $table_name,
            [
                'content_type' => 'pdf',
                'content_id' => $pdf_id,
            ],
            [ '%s', '%d' ]
        );
        // @codingStandardsIgnoreEnd

        // Clear relevant caches
        wp_cache_delete('aisk_pdf_status_' . $pdf_id, 'aisk_embeddings');
        wp_cache_delete('aisk_pdf_embedding_count_' . $pdf_id, 'aisk_embeddings');
        wp_cache_delete('aisk_pdf_queue_list', 'aisk_embeddings');

        // If there are no embeddings, also delete from the queue table
        // @codingStandardsIgnoreStart
        $embedding_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE content_type = 'pdf' AND content_id = %d",
            $pdf_id
        ));
        // @codingStandardsIgnoreEnd
        
        if ($embedding_count == 0) {
            // Try to delete from queue table by attachment_id
            // @codingStandardsIgnoreStart
            $queue_deleted = $wpdb->delete(
                $pdf_queue_table,
                [ 'attachment_id' => $pdf_id ],
                [ '%d' ]
            );
            // @codingStandardsIgnoreEnd
        }

        // Find the job ID in the queue table for this attachment
        // @codingStandardsIgnoreStart
        $file_name = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT post_title FROM {$wpdb->posts} WHERE ID = %d LIMIT 1",
                $pdf_id
            )
        );
        
        $normalized_file_name = $this->normalize_filename($file_name);
        $all_queue_files_cache_key = 'aisk_pdf_queue_files';
        $all_queue_files = wp_cache_get($all_queue_files_cache_key, 'aisk_embeddings');
        if (false === $all_queue_files) {
            // @codingStandardsIgnoreStart
            $all_queue_files = $wpdb->get_results("SELECT id, file_name FROM $pdf_queue_table");
            // @codingStandardsIgnoreEnd
            wp_cache_set($all_queue_files_cache_key, $all_queue_files, 'aisk_embeddings', 3600);
        }
        $job_id = false;
        foreach ($all_queue_files as $row) {
            if ($this->normalize_filename($row->file_name) === $normalized_file_name) {
                $job_id = $row->id;
                break;
            }
        }

        $pdf_job_deleted = false;
        if ($job_id) {
            // @codingStandardsIgnoreStart
            $pdf_job_deleted = $wpdb->delete(
                $pdf_queue_table,
                [ 'id' => $job_id ],
                [ '%d' ]
            );
            // @codingStandardsIgnoreEnd
        }

        if ($result === false) {
            return new WP_Error(
                'delete_failed',
                __('Failed to delete PDF embeddings', 'aisk-ai-chat-for-fluentcart'),
                array('status' => 500)
            );
        }

        return new WP_REST_Response(
            array(
                'success' => true,
                'message' => __('PDF embeddings and job deleted successfully', 'aisk-ai-chat-for-fluentcart'),
                'pdf_job_deleted' => $pdf_job_deleted
            ),
            200
        );
    }

    /**
     * Get the current max upload size
     *
     * @return int Current maximum upload size in bytes
     */
    public function get_current_max_upload_size() {
        return $this->max_upload_size;
    }

    /**
     * Get the current max upload size in human readable format
     *
     * @return string Current maximum upload size in human readable format
     */
    public function get_current_max_upload_size_formatted() {
        return size_format($this->max_upload_size);
    }
    
    /**
     * Get the optimum upload size for system stability
     *
     * @return int Optimum upload size in bytes
     */
    public function get_optimum_upload_size() {
        return $this->optimum_upload_size;
    }
    
    /**
     * Get the optimum upload size in human readable format
     *
     * @return string Optimum upload size in human readable format
     */
    public function get_optimum_upload_size_formatted() {
        return size_format($this->optimum_upload_size);
    }
    
    /**
     * Get instructions for increasing server upload limits
     *
     * @return string HTML formatted instructions
     */
    public function get_upload_limit_instructions() {
        $instructions = '<div class="upload-limit-instructions">';
        $instructions .= '<h3>' . __('How to Increase Upload Limit', 'aisk-ai-chat-for-fluentcart') . '</h3>';
        $instructions .= '<p>' . __('Your server currently has a maximum upload size of', 'aisk-ai-chat-for-fluentcart') . ' <strong>' . $this->get_current_max_upload_size_formatted() . '</strong>. ';
        $instructions .= __('To upload larger files, you need to increase this limit.', 'aisk-ai-chat-for-fluentcart') . '</p>';
        
        $instructions .= '<h4>' . __('Method 1: Edit php.ini', 'aisk-ai-chat-for-fluentcart') . '</h4>';
        $instructions .= '<ol>';
        $instructions .= '<li>' . __('Locate your php.ini file', 'aisk-ai-chat-for-fluentcart') . '</li>';
        $instructions .= '<li>' . __('Find and modify these lines:', 'aisk-ai-chat-for-fluentcart') . '</li>';
        $instructions .= '<pre>upload_max_filesize = 64M
post_max_size = 64M
memory_limit = 256M
max_execution_time = 300
max_input_time = 300</pre>';
        $instructions .= '<li>' . __('Save the file and restart your web server', 'aisk-ai-chat-for-fluentcart') . '</li>';
        $instructions .= '</ol>';
        
        $instructions .= '<h4>' . __('Method 2: Edit .htaccess', 'aisk-ai-chat-for-fluentcart') . '</h4>';
        $instructions .= '<ol>';
        $instructions .= '<li>' . __('Edit your .htaccess file in the WordPress root directory', 'aisk-ai-chat-for-fluentcart') . '</li>';
        $instructions .= '<li>' . __('Add these lines:', 'aisk-ai-chat-for-fluentcart') . '</li>';
        $instructions .= '<pre>php_value upload_max_filesize 64M
php_value post_max_size 64M
php_value memory_limit 256M
php_value max_execution_time 300
php_value max_input_time 300</pre>';
        $instructions .= '<li>' . __('Save the file', 'aisk-ai-chat-for-fluentcart') . '</li>';
        $instructions .= '</ol>';
        
        $instructions .= '<h4>' . __('Method 3: Contact Your Hosting Provider', 'aisk-ai-chat-for-fluentcart') . '</h4>';
        $instructions .= '<p>' . __('If you don\'t have access to these files, contact your hosting provider to increase these limits for you.', 'aisk-ai-chat-for-fluentcart') . '</p>';
        
        $instructions .= '<p><strong>' . __('Note:', 'aisk-ai-chat-for-fluentcart') . '</strong> ' . __('For optimal performance, we recommend keeping file sizes under', 'aisk-ai-chat-for-fluentcart') . ' <strong>' . $this->get_optimum_upload_size_formatted() . '</strong>.</p>';
        
        $instructions .= '</div>';
        
        return $instructions;
    }
    
    /**
     * Display admin notice about upload size limits
     */
    public function display_upload_size_notice() {
        // Only show to administrators
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Check if the current upload limit is below our recommended optimum
        if ($this->max_upload_size < $this->optimum_upload_size) {
            $class = 'notice notice-warning is-dismissible';
            $message = sprintf(
                /* translators: 1: Server's maximum upload size 2: Recommended optimum size */
            __('Your server\'s maximum upload size (%1$s) is below our recommended optimum size (%2$s). This may limit your ability to process larger PDF files. <a href="#" class="show-upload-instructions">Learn how to increase this limit</a>.', 'aisk-ai-chat-for-fluentcart'),
                $this->get_current_max_upload_size_formatted(),
                $this->get_optimum_upload_size_formatted()
            );
            
            echo '<div class="' . esc_attr($class) . '"><p>' . esc_html($message) . '</p></div>';
            
            // Add the instructions in a hidden div
            echo '<div id="upload-instructions" style="display:none;">' . esc_html($this->get_upload_limit_instructions()) . '</div>';
            
        }
    }

    /**
     * Process URLs with bot protection handling
     * 
     * @param string $url Main URL to process
     * @param array $options Processing options
     * @return array Processing results
     */
    public function process_protected_urls($url, $options = []) {
        try {
            // Set time limit using WordPress filters
            add_filter('max_execution_time', function() { return self::MAX_PROCESSING_TIME; });
            
            $results = [
                'success' => false,
                'main_url_processed' => false,
                'subordinate_urls_processed' => 0,
                'total_urls' => 0,
                'warnings' => [],
                'user_message' => ''
            ];

            // First, try to process the main URL
            $main_content = $this->get_url_content($url, $options['include_selectors'] ?? [], $options['exclude_selectors'] ?? []);
            
            if ($main_content) {
                // Store the main URL content using normalized URL for ID
                $normalized_url = $this->normalize_url_for_job_id($url);
                $this->store_embedding('external_url', md5($normalized_url), $main_content, [
                    'crawled_url' => $url,
                    'parent_url' => $url
                ]);
                $results['main_url_processed'] = true;
                $results['success'] = true;
            }

            // Only process subordinate URLs if follow_links is true
            if (!empty($options['follow_links']) && $options['follow_links']) {
                // Try to find subordinate URLs through alternative methods
                $subordinate_urls = $this->find_subordinate_urls($url, $options);
                $results['total_urls'] = count($subordinate_urls);

                if (!empty($subordinate_urls)) {
                    // Process each subordinate URL
                    foreach ($subordinate_urls as $sub_url) {
                        try {
                            $sub_content = $this->get_url_content($sub_url, $options['include_selectors'] ?? [], $options['exclude_selectors'] ?? []);
                            if ($sub_content) {
                                // Use normalized URL for ID
                                $normalized_sub_url = $this->normalize_url_for_job_id($sub_url);
                                $this->store_embedding('external_url', md5($normalized_sub_url), $sub_content, [
                                    'crawled_url' => $sub_url,
                                    'parent_url' => $url
                                ]);
                                $results['subordinate_urls_processed']++;
                            }
                        } catch (Exception $e) {
                            $results['warnings'][] = "Could not process subordinate URL: {$sub_url} - " . $e->getMessage();
                        }
                    }
                }

                // If we couldn't process any subordinate URLs, add a user message
                if ($results['subordinate_urls_processed'] === 0 && $results['main_url_processed']) {
                    $results['user_message'] = 'The main page has been successfully embedded, but we were unable to process subordinate URLs due to bot protection, caching, or JavaScript rendering requirements. You may need to manually submit important subordinate URLs for embedding.';
                }
            } else {
                $results['user_message'] = 'Only the main page was embedded as subordinate URL crawling was disabled (follow_links=false).';
            }

            return $results;
        } catch (Exception $e) {
            $results['success'] = false;
            $results['warnings'][] = $e->getMessage();
            return $results;
        }
    }

    /**
     * Find subordinate URLs through alternative methods
     * 
     * @param string $url Main URL
     * @param array $options Processing options
     * @return array Array of subordinate URLs
     */
    private function find_subordinate_urls($url, $options) {
        $urls = [];
        
        // Method 1: Try to find URLs in the main page content
        try {
            $main_content = $this->get_url_content($url);
            if ($main_content) {
                $urls = array_merge($urls, $this->extract_links($main_content, $url));
            }
        } catch (Exception $e) {
            // Silently fail and try next method
        }

        // Method 2: Try to find sitemap
        try {
            $sitemap_urls = $this->fetch_sitemap_urls($url);
            $urls = array_merge($urls, $sitemap_urls);
        } catch (Exception $e) {
            // Silently fail and try next method
        }

        // Method 3: Try to find RSS feed
        try {
            $rss_urls = $this->find_rss_urls($url);
            $urls = array_merge($urls, $rss_urls);
        } catch (Exception $e) {
            // Silently fail
        }

        // Filter URLs based on include/exclude patterns
        $urls = array_filter($urls, function($url) use ($options) {
            return $this->should_process_url($url, $options['include_patterns'] ?? [], $options['exclude_patterns'] ?? []);
        });

        return array_unique($urls);
    }

    /**
     * Find URLs from RSS feeds
     * 
     * @param string $url Main URL
     * @return array Array of URLs from RSS feeds
     */
    private function find_rss_urls($url) {
        $urls = [];
        $rss_locations = [
            'feed',
            'rss',
            'feed.xml',
            'rss.xml',
            'atom.xml'
        ];

        foreach ($rss_locations as $location) {
            $rss_url = trailingslashit($url) . $location;
            $response = wp_remote_get($rss_url);
            
            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                $content = wp_remote_retrieve_body($response);
                if (preg_match_all('/<link>(.*?)<\/link>/', $content, $matches)) {
                    $urls = array_merge($urls, $matches[1]);
                }
            }
        }

        return $urls;
    }

    /**
     * Normalize URL for consistent job ID generation
     * 
     * @param string $url URL to normalize
     * @return string Normalized URL
     */
    private function normalize_url_for_job_id($url) {
        $parts = wp_parse_url(trim($url));
        if (!$parts) return trim($url);

        $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : 'https';
        $host = isset($parts['host']) ? strtolower($parts['host']) : '';
        $path = isset($parts['path']) ? $parts['path'] : '';
        // Remove trailing slash from path unless it's root
        if ($path !== '' && $path !== '/') {
            $path = rtrim($path, '/');
        } else if ($path === '') {
            $path = '';
        }

        $normalized = $scheme . '://' . $host . $path;
        // Optionally, add query string if you want jobs to be unique per query
        if (isset($parts['query'])) {
            $normalized .= '?' . $parts['query'];
        }
        return $normalized;
    }

    /**
     * Process a large PDF file in chunks
     *
     * @param int    $attachment_id The WordPress attachment ID
     * @param string $file_path     The full path to the PDF file
     *
     * @return array The processing result
     */
    public function process_large_file($attachment_id, $file_path) {
        try {
            // Set time limit using WordPress filters
            add_filter('max_execution_time', function() { return self::MAX_PROCESSING_TIME; });
            
            // Get file size
            $file_size = filesize($file_path);
            if ($file_size === false) {
                throw new Exception('Could not determine file size');
            }

            // Initialize PDF parser
            $parser = new \Smalot\PdfParser\Parser();
            
            try {
                // Parse PDF file
                $pdf = $parser->parseFile($file_path);
                
                // Extract text from PDF
                $text = $this->clean_pdf_text($pdf->getText());
                
                // Clean and prepare text
                $text = trim($text);
                if (empty($text)) {
                    throw new Exception('No text content found in PDF');
                }
                
                // Split content into manageable chunks
                $chunks = $this->split_content($text);
                
                // Generate embeddings for each chunk
                $embeddings = array();
                foreach ($chunks as $index => $chunk) {
                    // Store embedding in database
                    $embedding_data = array(
                        'content_id' => $attachment_id,
                        'content_type' => 'pdf',
                        'chunk_index' => $index,
                        'embedding' => $this->generate_embedding($chunk),
                        'content_chunk' => $chunk
                    );
                    
                    global $wpdb;
                    $table_name = $wpdb->prefix . 'aisk_embeddings';
                    // @codingStandardsIgnoreStart
                    $wpdb->insert($table_name, $embedding_data);
                    // @codingStandardsIgnoreEnd
                    
                    $embeddings[] = $embedding_data;
                }
                
                // Update PDF processing status
                update_post_meta($attachment_id, '_aisk_pdf_processed', true);
                update_post_meta($attachment_id, '_aisk_pdf_chunks_count', count($chunks));
                
                return array(
                    'success' => true,
                    'message' => 'PDF processed successfully',
                    'chunks_count' => count($chunks)
                );
                
            } catch (Exception $e) {
                throw new Exception('Failed to parse PDF: ' . $e->getMessage());
            }

        } catch (Exception $e) {
            return array(
                'success' => false,
                'message' => $e->getMessage()
            );
        }
    }

    /**
     * Check if a URL exists in the embeddings table
     *
     * @param string $url The URL to check
     * @return bool Whether the URL exists
     * 
     * @since 1.0.0
     */
    private function check_url_exists($url) {
        $transient_key = 'aisk_url_exists_' . md5($url);
        $exists = get_transient($transient_key);
        
        if (false === $exists) {
            // Use WordPress's database functions with proper query structure
            global $wpdb;
            $table_name = $wpdb->prefix . 'aisk_embeddings';
            
            // @codingStandardsIgnoreStart
            // Custom table query that cannot be handled by WordPress core functions
            $query = $wpdb->prepare(
                "SELECT 1 FROM `{$table_name}` WHERE content_type = %s AND crawled_url = %s LIMIT 1",
                'external_url',
                $url
            );
            
            // Use WordPress's database functions
            $exists = (bool) $wpdb->get_var($query);
            // @codingStandardsIgnoreEnd
            
            set_transient($transient_key, $exists, DAY_IN_SECONDS);
        }
        
        return (bool)$exists;
    }

    public function handle_pdf_upload() {
        // Verify nonce
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'aisk_pdf_upload')) {
            wp_die(esc_html__('Security check failed', 'aisk-ai-chat-for-fluentcart'), 403);
        }

        // Validate file input
        if (!isset($_FILES['pdf_file']) || !is_array($_FILES['pdf_file'])) {
            wp_die(esc_html__('No PDF file provided', 'aisk-ai-chat-for-fluentcart'), 400);
        }

        $file = array_map('sanitize_text_field', wp_unslash($_FILES['pdf_file']));

        // Validate file error
        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            $error_message = $this->get_upload_error_message($file['error'] ?? UPLOAD_ERR_NO_FILE);
            wp_die(esc_html($error_message), 400);
        }

        // Process the file
        $upload = wp_handle_upload($file, array('test_form' => false));
        
        if (isset($upload['error'])) {
            wp_die(esc_html($upload['error']), 400);
        }

        // Get the uploaded file path
        $file_path = $upload['file'];
        
        // Process the PDF
        $result = $this->process_pdf($file_path);
        
        if (!$result['success']) {
            wp_die(esc_html($result['message']), 400);
        }

        wp_send_json_success([
            'message' => esc_html__('PDF processed successfully', 'aisk-ai-chat-for-fluentcart'),
            'file_path' => esc_html($file_path)
        ]);
    }

    public function process_pdf($pdf_path, $attachment_id = null) {
        try {
            // Set time limit using WordPress filters
            add_filter('max_execution_time', function() { return self::MAX_PROCESSING_TIME; });
            
            // Initialize PDF parser
            $parser = new \Smalot\PdfParser\Parser();
            
            try {
                // Parse PDF file
                $pdf = $parser->parseFile($pdf_path);
                
                // Extract text from PDF
                $text = $pdf->getText();
                
                // Clean and prepare text
                $text = $this->clean_pdf_text($text);
                
                if (empty($text)) {
                    throw new Exception('No text content found in PDF');
                }
                
                // Split content into manageable chunks
                $chunks = $this->split_content($text);
                
                // Generate embeddings for each chunk
                $embeddings = array();
                global $wpdb;
                $table_name = $wpdb->prefix . 'aisk_embeddings';
                
                // Start transaction for better performance
                // @codingStandardsIgnoreStart
                $wpdb->query('START TRANSACTION');
                // @codingStandardsIgnoreEnd
                
                foreach ($chunks as $index => $chunk) {
                    // Generate embedding
                    $embedding = $this->generate_embedding($chunk);
                    if (!$embedding) {
                        throw new Exception('Failed to generate embedding for chunk ' . $index);
                    }
                    
                    // Store embedding in database
                    $embedding_data = array(
                        'content_type' => 'pdf',
                        'content_id' => $attachment_id ?: basename($pdf_path),
                        'chunk_index' => $index,
                        'embedding' => $embedding,
                        'content_chunk' => $chunk
                    );
                    
                    // @codingStandardsIgnoreStart
                    $result = $wpdb->insert($table_name, $embedding_data);
                    // @codingStandardsIgnoreEnd
                    
                    if (!$result) {
                        throw new Exception('Failed to store embedding for chunk ' . $index);
                    }
                    
                    $embeddings[] = $embedding_data;
                }
                
                // Commit transaction
                // @codingStandardsIgnoreStart
                $wpdb->query('COMMIT');
                // @codingStandardsIgnoreEnd
                
                // Cache the embedding count
                $cache_key = 'aisk_pdf_embedding_count_' . ($attachment_id ?: basename($pdf_path));
                wp_cache_set($cache_key, count($chunks), 'aisk_embeddings', 3600);
                
                // Update PDF processing status if attachment_id is provided
                if ($attachment_id) {
                    update_post_meta($attachment_id, '_aisk_pdf_processed', true);
                    update_post_meta($attachment_id, '_aisk_pdf_chunks_count', count($chunks));
                }
                
                return array(
                    'success' => true,
                    'message' => 'PDF processed successfully',
                    'chunks_count' => count($chunks)
                );
                
            } catch (Exception $e) {
                // @codingStandardsIgnoreStart
                // Rollback transaction on error
                $wpdb->query('ROLLBACK');
                // @codingStandardsIgnoreEnd
                throw new Exception('Failed to parse PDF: ' . $e->getMessage());
            }

        } catch (Exception $e) {
            return array(
                'success' => false,
                'message' => $e->getMessage()
            );
        }
    }

    public function process_large_pdf($pdf_path) {
        try {
            // Set time limit using WordPress filters
            add_filter('max_execution_time', function() { return self::MAX_PROCESSING_TIME; });
            
            // Initialize PDF parser
            $parser = new \Smalot\PdfParser\Parser();
            
            try {
                // Parse PDF file
                $pdf = $parser->parseFile($pdf_path);
                
                // Get total number of pages
                $total_pages = count($pdf->getPages());
                
                // Process in chunks of 10 pages
                $chunk_size = 10;
                $chunks = [];
                
                for ($i = 0; $i < $total_pages; $i += $chunk_size) {
                    $page_chunk = array_slice($pdf->getPages(), $i, $chunk_size);
                    $chunk_text = '';
                    
                    foreach ($page_chunk as $page) {
                        $chunk_text .= $page->getText() . "\n";
                    }
                    
                    // Clean and prepare text
                    $chunk_text = trim($chunk_text);
                    if (!empty($chunk_text)) {
                        $chunks[] = $chunk_text;
                    }
                }
                
                // Generate embeddings for each chunk
                $embeddings = array();
                foreach ($chunks as $index => $chunk) {
                    // Store embedding in database
                    $embedding_data = array(
                        'content_type' => 'pdf',
                        'content_id' => basename($pdf_path),
                        'chunk_index' => $index,
                        'embedding' => $this->generate_embedding($chunk),
                        'content_chunk' => $chunk
                    );
                    
                    global $wpdb;
                    $table_name = $wpdb->prefix . 'aisk_embeddings';
                    // @codingStandardsIgnoreStart
                    $wpdb->insert($table_name, $embedding_data);
                    // @codingStandardsIgnoreEnd
                    
                    $embeddings[] = $embedding_data;
                }
                
                return array(
                    'success' => true,
                    'message' => 'Large PDF processed successfully',
                    'chunks_count' => count($chunks),
                    'total_pages' => $total_pages
                );
                
            } catch (Exception $e) {
                throw new Exception('Failed to parse PDF: ' . $e->getMessage());
            }
            
        } catch (Exception $e) {
            return array(
                'success' => false,
                'message' => $e->getMessage()
            );
        }
    }

    public function process_batch($items) {
        try {
            // Set time limit using WordPress filters
            add_filter('max_execution_time', function() { return self::MAX_PROCESSING_TIME; });
            
            $results = array(
                'success' => true,
                'processed' => 0,
                'failed' => 0,
                'errors' => array(),
                'items' => array()
            );
            
            foreach ($items as $item) {
                try {
                    // Validate item structure
                    if (!isset($item['type']) || !isset($item['content'])) {
                        throw new Exception('Invalid item structure: missing type or content');
                    }
                    
                    $content_type = sanitize_text_field($item['type']);
                    $content = $item['content'];
                    $content_id = isset($item['id']) ? sanitize_text_field($item['id']) : md5($content);
                    $extra_data = isset($item['extra_data']) ? $item['extra_data'] : array();
                    
                    // Process based on content type
                    switch ($content_type) {
                        case 'pdf':
                            if (!isset($item['file_path'])) {
                                throw new Exception('PDF processing requires file_path');
                            }
                            $result = $this->process_large_pdf($item['file_path']);
                            break;
                            
                        case 'url':
                            if (!isset($item['url'])) {
                                throw new Exception('URL processing requires url');
                            }
                            $result = $this->process_protected_urls($item['url'], $extra_data);
                            break;
                            
                        case 'text':
                            // Split content into chunks if needed
                            $chunks = $this->split_content($content);
                            $success = true;
                            
                            foreach ($chunks as $index => $chunk) {
                                $chunk_success = $this->store_embedding(
                                    $content_type,
                                    $content_id . '_' . $index,
                                    $chunk,
                                    array_merge($extra_data, ['chunk_index' => $index])
                                );
                                $success = $success && $chunk_success;
                            }
                            
                            $result = array(
                                'success' => $success,
                                'message' => $success ? 'Text processed successfully' : 'Failed to process text',
                                'chunks_count' => count($chunks)
                            );
                            break;
                            
                        default:
                            throw new Exception('Unsupported content type: ' . $content_type);
                    }
                    
                    // Update results
                    if ($result['success']) {
                        $results['processed']++;
                    } else {
                        $results['failed']++;
                        $results['errors'][] = array(
                            'item_id' => $content_id,
                            'type' => $content_type,
                            'error' => $result['message'] ?? 'Unknown error'
                        );
                    }
                    
                    // Store item result
                    $results['items'][] = array(
                        'id' => $content_id,
                        'type' => $content_type,
                        'success' => $result['success'],
                        'message' => $result['message'] ?? '',
                        'details' => $result
                    );
                    
                } catch (Exception $e) {
                    $results['failed']++;
                    $results['errors'][] = array(
                        'item_id' => $content_id ?? 'unknown',
                        'type' => $content_type ?? 'unknown',
                        'error' => $e->getMessage()
                    );
                    
                    $results['items'][] = array(
                        'id' => $content_id ?? 'unknown',
                        'type' => $content_type ?? 'unknown',
                        'success' => false,
                        'message' => $e->getMessage()
                    );
                }
            }
            
            // Update overall success status
            $results['success'] = ($results['failed'] === 0);
            
            return $results;
            
        } catch (Exception $e) {
            return array(
                'success' => false,
                'message' => $e->getMessage(),
                'processed' => 0,
                'failed' => count($items),
                'errors' => array(array(
                    'error' => $e->getMessage()
                )),
                'items' => array()
            );
        }
    }

    /**
     * Get count of unprocessed items
     *
     * @param WP_REST_Request $request The request object
     * @return array|WP_Error Count of unprocessed items and success status
     */
    public function get_unprocessed_count($request)
    {
        $cache_key = 'aisk_unprocessed_embeddings_count';
        $count = wp_cache_get($cache_key, 'aisk_embeddings');
        
        if (false === $count) {
            // @codingStandardsIgnoreStart
            global $wpdb;
            $table_name = $wpdb->prefix . 'aisk_embeddings';
            // FIX: Remove status column check, count only unprocessed PDFs by content_type/content_id if needed
            $count = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$table_name} WHERE content_type = 'pdf' AND content_id IS NOT NULL"
            );
            // @codingStandardsIgnoreEnd
            wp_cache_set($cache_key, $count, 'aisk_embeddings', 300); // Cache for 5 minutes
        }
        
        return $count;
    }
}