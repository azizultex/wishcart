<?php
if ( ! defined( 'ABSPATH' ) ) exit;
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

/**
 * AISK_Embeddings_Handler Class
 *
 * Manages the generation, storage and retrieval of embeddings for various content types
 *
 * @category Class
 * @package  AISK
 * @author   Aisk Team <support@aisk.chat>
 * @license  GPL-2.0+ https://www.gnu.org/licenses/gpl-2.0.html
 * @link     https://aisk.chat
 */
class AISK_Embeddings_Handler
{

    private $db;
    private $api_key;
    private $auth_key;
    private $model = 'text-embedding-3-small';
    private $batch_size = 10;
    private $settings;
    private $external_embeddings_handler;
    private $processed = 0;

    /**
     * Class constructor
     *
     * @param void
     *
     * @return void
     * @since 1.0.0
     *
     */
    public function __construct()
    {
        $this->external_embeddings_handler = new AISK_External_Embeddings_Handler();
        $this->settings = get_option('aisk_settings');
        $this->api_key = isset($this->settings['general']['openai_key']) ? $this->settings['general']['openai_key'] : '';
        $this->auth_key = isset($this->settings['general']['auth_key']) ? $this->settings['general']['auth_key'] : '';

        // Register REST API endpoint for processing
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        //        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);

        // Register hook to handle settings updates
        add_action('update_option_aisk_settings', array($this, 'handle_settings_update'), 10, 3);

        // clean up embedding for deleted content
        add_action('before_delete_post', [$this, 'delete_content_embeddings']);
        add_action('fluentcart_before_delete_product', [$this, 'delete_content_embeddings']);
        add_action('fluentcart_before_delete_product_variation', [$this, 'delete_content_embeddings']);
    }

    /**
     * Register REST API routes
     *
     * @param void
     *
     * @return void
     * @since 1.0.0
     *
     */
    public function register_rest_routes()
    {
        register_rest_route(
            'aisk/v1', '/process-content', [
                'methods' => 'POST',
                'callback' => [$this, 'process_content'],
                'permission_callback' => function () {
                    return current_user_can('manage_options');
                },
                'args' => [
                    'offset' => [
                        'required' => false,
                        'type' => 'integer',
                        'default' => 0
                    ],
                    'excluded_posts' => [
                        'required' => false,
                        'type' => 'array',
                        'default' => []
                    ],
                    'excluded_pages' => [
                        'required' => false,
                        'type' => 'array',
                        'default' => []
                    ],
                    'excluded_products' => [
                        'required' => false,
                        'type' => 'array',
                        'default' => []
                    ]
                ]
            ]
        );

        register_rest_route(
            'aisk/v1', '/get-unprocessed-count', [
                'methods' => 'GET',
                'callback' => [$this, 'get_unprocessed_count'],
                'permission_callback' => function () {
                    return current_user_can('manage_options');
                }
            ]
        );

        register_rest_route(
            'aisk/v1', '/cleanup-excluded-embeddings', [
                'methods' => 'POST',
                'callback' => [$this, 'cleanup_excluded_embeddings_endpoint'],
                'permission_callback' => function () {
                    return current_user_can('manage_options');
                }
            ]
        );

        // Add filter to ensure proper content type header
        add_filter('rest_pre_serve_request', function ($served, $result, $request, $server) {
            $server->send_header('Content-Type', 'application/json; charset=' . get_option('blog_charset'));
            return $served;
        }, 10, 4);
    }

    public function cleanup_excluded_embeddings()
    {
        $settings = get_option('aisk_settings');
        $removed_count = 0;

        // Get all excluded content from settings
        $excluded_posts = isset($settings['ai_config']['excluded_posts'])
            ? $this->extract_ids($settings['ai_config']['excluded_posts'])
            : array();

        $excluded_pages = isset($settings['ai_config']['excluded_pages'])
            ? $this->extract_ids($settings['ai_config']['excluded_pages'])
            : array();

        $excluded_products = isset($settings['ai_config']['excluded_products'])
            ? $this->extract_ids($settings['ai_config']['excluded_products'])
            : array();

        // Remove embeddings for each content type
        if (!empty($excluded_posts)) {
            $removed_count += $this->remove_embeddings_for_content('post', $excluded_posts);
        }

        if (!empty($excluded_pages)) {
            $removed_count += $this->remove_embeddings_for_content('page', $excluded_pages);
        }

        if (!empty($excluded_products)) {
            $removed_count += $this->remove_embeddings_for_content('product', $excluded_products);
        }

        return array(
            'removed_count' => $removed_count
        );
    }

    /**
     * Handle settings update to check for excluded content changes
     *
     * @param mixed $old_value The old option value
     * @param mixed $new_value The new option value
     * @param string $option The option name
     * @return void
     */
    public function handle_settings_update($old_value, $new_value, $option)
    {
        // Extract the old and new excluded content
        $old_excluded = array(
            'posts' => isset($old_value['ai_config']['excluded_posts']) ? $old_value['ai_config']['excluded_posts'] : array(),
            'pages' => isset($old_value['ai_config']['excluded_pages']) ? $old_value['ai_config']['excluded_pages'] : array(),
            'products' => isset($old_value['ai_config']['excluded_products']) ? $old_value['ai_config']['excluded_products'] : array(),
        );

        $new_excluded = array(
            'posts' => isset($new_value['ai_config']['excluded_posts']) ? $new_value['ai_config']['excluded_posts'] : array(),
            'pages' => isset($new_value['ai_config']['excluded_pages']) ? $new_value['ai_config']['excluded_pages'] : array(),
            'products' => isset($new_value['ai_config']['excluded_products']) ? $new_value['ai_config']['excluded_products'] : array(),
        );

        // Find newly excluded content
        $newly_excluded = array(
            'post' => $this->find_newly_excluded_ids($old_excluded['posts'], $new_excluded['posts']),
            'page' => $this->find_newly_excluded_ids($old_excluded['pages'], $new_excluded['pages']),
            'product' => $this->find_newly_excluded_ids($old_excluded['products'], $new_excluded['products']),
        );

        // Process removals for newly excluded content
        foreach ($newly_excluded as $content_type => $ids) {
            if (!empty($ids)) {
                $this->remove_embeddings_for_content($content_type, $ids);
            }
        }

        // Check if contact info or custom content has changed
        $old_contact_info = isset($old_value['ai_config']['contact_info']) ? $old_value['ai_config']['contact_info'] : '';
        $new_contact_info = isset($new_value['ai_config']['contact_info']) ? $new_value['ai_config']['contact_info'] : '';
        $old_custom_content = isset($old_value['ai_config']['custom_content']) ? $old_value['ai_config']['custom_content'] : '';
        $new_custom_content = isset($new_value['ai_config']['custom_content']) ? $new_value['ai_config']['custom_content'] : '';

        // If either contact info or custom content has changed, update the settings embedding
        if ($old_contact_info !== $new_contact_info || $old_custom_content !== $new_custom_content) {
            $settings_content = $this->get_settings_content_for_embedding();
            if (!empty($settings_content)) {
                $this->store_embedding('settings', 0, $settings_content);
            }
        }
    }

    /**
     * Find IDs that are newly excluded (in new but not in old)
     *
     * @param array $old_excluded Old excluded IDs
     * @param array $new_excluded New excluded IDs
     * @return array Newly excluded IDs
     */
    private function find_newly_excluded_ids($old_excluded, $new_excluded)
    {
        $old_ids = $this->extract_ids($old_excluded);
        $new_ids = $this->extract_ids($new_excluded);

        return array_diff($new_ids, $old_ids);
    }

    /**
     * Find IDs that are newly included (in old but not in new)
     *
     * @param array $old_excluded Old excluded IDs
     * @param array $new_excluded New excluded IDs
     * @return array Newly included IDs
     */
    private function find_newly_included_ids($old_excluded, $new_excluded)
    {
        $old_ids = $this->extract_ids($old_excluded);
        $new_ids = $this->extract_ids($new_excluded);

        return array_diff($old_ids, $new_ids);
    }

    /**
     * Extract numeric IDs from the settings array format
     *
     * @param array $items Array of items with 'value' key
     * @return array Array of numeric IDs
     */
    private function extract_ids($items)
    {
        if (empty($items)) {
            return array();
        }

        // Check if we're dealing with the new format (array of objects with 'value' property)
        if (is_array($items) && isset($items[0]) && is_array($items[0]) && isset($items[0]['value'])) {
            return array_map(function ($item) {
                return intval($item['value']);
            }, $items);
        }

        // For backward compatibility, handle the case when it's just an array of IDs
        if (is_array($items)) {
            return array_map('intval', $items);
        }

        return array();
    }

    /**
     * Remove embeddings for specified content
     *
     * @param string $content_type Content type (post, page, product)
     * @param array $content_ids Array of content IDs
     * @return int Number of removed embeddings
     */
    private function remove_embeddings_for_content($content_type, $content_ids)
    {
        if (empty($content_ids)) {
            return 0;
        }

        $removed_count = 0;
        $cache_key = 'aisk_embeddings_' . $content_type . '_' . md5(serialize($content_ids));
        
        // @codingStandardsIgnoreStart
        global $wpdb;
        $table_name = $wpdb->prefix . 'aisk_embeddings';
        
        foreach ($content_ids as $content_id) {
            $result = $wpdb->delete(
                $table_name,
                [
                    'content_type' => $content_type,
                    'content_id' => $content_id
                ],
                ['%s', '%d']
            );
            
            if ($result !== false) {
                $removed_count += $result;
                // Clear related caches
                wp_cache_delete('aisk_embedding_' . $content_type . '_' . $content_id, 'aisk_embeddings');
            }
        }
        // @codingStandardsIgnoreEnd

        return $removed_count;
    }


    /**
     * Clean up all embeddings for currently excluded content
     *
     * @return WP_Error|WP_HTTP_Response|WP_REST_Response Result with removed count
     */
    public function cleanup_excluded_embeddings_endpoint($request)
    {
        $result = $this->cleanup_excluded_embeddings();

        return rest_ensure_response(array(
            'success' => true,
            'removed_count' => $result['removed_count'],
            'message' => sprintf(
            /* translators: %d: Number of embeddings removed */
                __('Successfully removed %d embeddings for excluded content.', 'aisk-ai-chat-for-fluentcart'),
                $result['removed_count']
            ),
        ));
    }

    /**
     * Get unprocessed items for embedding
     *
     * @param int $offset The offset for pagination
     * @param int $batch_size Number of items to retrieve
     *
     * @return array Array of unprocessed items
     * @since 1.0.0
     *
     */
    private function get_unprocessed_items($offset, $batch_size)
    {
        $cache_key = 'aisk_unprocessed_items_' . $offset . '_' . $batch_size;
        $items = wp_cache_get($cache_key, 'aisk_embeddings');
        
        if (false === $items) {
            // @codingStandardsIgnoreStart
            global $wpdb;
            $embedding_table = $wpdb->prefix . 'aisk_embeddings';

            // Get included post types with defaults
            $included_types = isset($this->settings['ai_config']['included_post_types'])
                ? $this->settings['ai_config']['included_post_types']
                : ['post', 'page'];

            // Add FluentCart types if enabled
            if (!empty($this->settings['ai_config']['fluentcart_enabled'])) {
                $included_types[] = 'product';
                $included_types[] = 'product_variation';
                $included_types[] = AISK_FluentCart_Helper::get_product_post_type();
            }

            // If no post types are included, return empty array
            if (empty($included_types)) {
                return [];
            }

            // Get excluded content IDs
            $excluded_posts = isset($this->settings['ai_config']['excluded_posts'])
                ? array_column($this->settings['ai_config']['excluded_posts'], 'value')
                : [];
            $excluded_pages = isset($this->settings['ai_config']['excluded_pages'])
                ? array_column($this->settings['ai_config']['excluded_pages'], 'value')
                : [];
            $excluded_products = isset($this->settings['ai_config']['excluded_products'])
                ? array_column($this->settings['ai_config']['excluded_products'], 'value')
                : [];

            // Combine all excluded IDs
            $excluded_ids = array_map('intval', array_merge($excluded_posts, $excluded_pages, $excluded_products));

            // Build the base query
            $embedding_table_sql = esc_sql( $embedding_table );
            $query = "SELECT p.*
                FROM {$wpdb->posts} p
                LEFT JOIN {$embedding_table_sql} e
                    ON p.ID = e.content_id
                    AND p.post_type = e.content_type
                WHERE p.post_status = 'publish'
                AND p.post_type IN (" . implode(',', array_fill(0, count($included_types), '%s')) . ")
                AND e.id IS NULL";

            $query_params = $included_types;

            // Add exclusion condition if there are excluded IDs
            if (!empty($excluded_ids)) {
                $query .= " AND p.ID NOT IN (" . implode(',', array_fill(0, count($excluded_ids), '%d')) . ")";
                $query_params = array_merge($query_params, $excluded_ids);
            }

            // Add pagination
            $query .= " LIMIT %d OFFSET %d";
            $query_params[] = $batch_size;
            $query_params[] = $offset;

            // Prepare and execute the query
            $prepared_query = $wpdb->prepare($query, $query_params);
            $items = $wpdb->get_results($prepared_query);
            // @codingStandardsIgnoreEnd

            if ($items) {
                wp_cache_set($cache_key, $items, 'aisk_embeddings', 300); // Cache for 5 minutes
            }
        }

        return $items ?: [];
    }


    /**
     * Get count of unprocessed items
     *
     * @param WP_REST_Request $request The request object
     * @return array|WP_Error Count of unprocessed items and success status
     */
    public function get_unprocessed_count($request)
    {
        try {
            // Get included post types with defaults
            $included_types = isset($this->settings['ai_config']['included_post_types'])
                ? $this->settings['ai_config']['included_post_types']
                : ['post', 'page'];

            // Add FluentCart types if enabled
            if (!empty($this->settings['ai_config']['fluentcart_enabled'])) {
                $included_types[] = 'product';
                $included_types[] = 'product_variation';
                $included_types[] = AISK_FluentCart_Helper::get_product_post_type();
            }

            // If no post types are included, return 0
            if (empty($included_types)) {
                return rest_ensure_response([
                    'success' => true,
                    'count' => 0
                ]);
            }

            // Get excluded content IDs
            $excluded_posts = isset($this->settings['ai_config']['excluded_posts'])
                ? array_column($this->settings['ai_config']['excluded_posts'], 'value')
                : [];
            $excluded_pages = isset($this->settings['ai_config']['excluded_pages'])
                ? array_column($this->settings['ai_config']['excluded_pages'], 'value')
                : [];
            $excluded_products = isset($this->settings['ai_config']['excluded_products'])
                ? array_column($this->settings['ai_config']['excluded_products'], 'value')
                : [];

            // Combine all excluded IDs
            $excluded_ids = array_map('intval', array_merge($excluded_posts, $excluded_pages, $excluded_products));

            // @codingStandardsIgnoreStart
            global $wpdb;
            $embedding_table = $wpdb->prefix . 'aisk_embeddings';

            // Build the query to count unprocessed items
            $embedding_table_sql = esc_sql( $embedding_table );
            $query = "SELECT COUNT(*)
                FROM {$wpdb->posts} p
                LEFT JOIN {$embedding_table_sql} e
                    ON p.ID = e.content_id
                    AND p.post_type = e.content_type
                WHERE p.post_status = 'publish'
                AND p.post_type IN (" . implode(',', array_fill(0, count($included_types), '%s')) . ")
                AND e.id IS NULL";

            $query_params = $included_types;

            // Add exclusion condition if there are excluded IDs
            if (!empty($excluded_ids)) {
                $query .= " AND p.ID NOT IN (" . implode(',', array_fill(0, count($excluded_ids), '%d')) . ")";
                $query_params = array_merge($query_params, $excluded_ids);
            }

            // Prepare and execute the query
            $prepared_query = $wpdb->prepare($query, $query_params);
            $count = (int) $wpdb->get_var($prepared_query);
            // @codingStandardsIgnoreEnd

            // Check if contact info or custom content has changed
            $has_settings_changes = false;
            if (!empty($this->settings['ai_config']['contact_info']) || !empty($this->settings['ai_config']['custom_content'])) {
                $has_settings_changes = true;
            }

            // Add 1 to count if there are settings changes and settings embedding does not exist
            if ($has_settings_changes) {
                // Check if settings embedding exists
                $embedding_table_sql = esc_sql( $embedding_table );
                // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name cannot be parameterized, settings check needs real-time data
                $settings_exists = $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$embedding_table_sql} WHERE content_type = %s AND content_id = %d",
                    'settings', 0
                ) );
                // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
                if (!$settings_exists) {
                    $count += 1;
                }
            }

            return rest_ensure_response([
                'success' => true,
                'count' => $count
            ]);

        } catch (Exception $e) {
            return new WP_Error(
                'count_error',
                $e->getMessage(),
                array('status' => 500)
            );
        }
    }

    /**
     * Get settings content for embedding
     *
     * @param void
     *
     * @return string Settings content
     * @since 1.0.0
     *
     */
    private function get_settings_content_for_embedding()
    {
        $settings = get_option('aisk_settings', []);
        $content = '';

        // Add contact information with clear context
        if (!empty($settings['ai_config']['contact_info'])) {
            $content .= "CONTACT INFORMATION:\n";
            $content .= $settings['ai_config']['contact_info'] . "\n\n";
        }

        // Add custom content with clear context
        if (!empty($settings['ai_config']['custom_content'])) {
            $content .= "BUSINESS INFORMATION:\n";
            $content .= $settings['ai_config']['custom_content'] . "\n\n";
        }

        return $content;
    }

    /**
     * Process content for embedding
     *
     * @param WP_REST_Request $request The REST request object
     *
     * @return array|WP_Error Processing results or error
     * @since 1.0.0
     *
     */
    public function process_content($request)
    {

        try {
            // Verify nonce
            $nonce = $request->get_header('X-WP-Nonce');
            if (!$nonce || !wp_verify_nonce($nonce, 'wp_rest')) {
                return new WP_Error(
                    'invalid_nonce',
                    __('Invalid security token. Please refresh the page and try again.', 'aisk-ai-chat-for-fluentcart'),
                    array('status' => 403)
                );
            }

            // Check if user is logged in
            if (!is_user_logged_in()) {
                return new WP_Error(
                    'not_logged_in',
                    __('You must be logged in to process content.', 'aisk-ai-chat-for-fluentcart'),
                    array('status' => 401)
                );
            }

            // Check user capabilities
            if (!current_user_can('manage_options')) {
                return new WP_Error(
                    'insufficient_permissions',
                    __('You do not have permission to process content.', 'aisk-ai-chat-for-fluentcart'),
                    array('status' => 403)
                );
            }

            if (empty($this->api_key)) {
                return new WP_Error(
                    'missing_api_key',
                    __('OpenAI API key is not configured', 'aisk-ai-chat-for-fluentcart'),
                    array('status' => 400)
                );
            }

            $offset = $request->get_param('offset') ? intval($request->get_param('offset')) : 0;
            $batch_size = isset($this->settings['ai_config']['batch_size']) ?
                intval($this->settings['ai_config']['batch_size']) : 10;

            try {
                // Get unprocessed items for each content type
                $items = $this->get_unprocessed_items($offset, $batch_size);

                if (!empty($items)) {
                    // $processed = 0;
                    $errors = [];

                    foreach ($items as $item) {
                        try {
                            // Skip if content is excluded
                            if ($this->is_excluded_content($item)) {
                                continue;
                            }

                            // Get content based on type
                            $content = $this->get_content_for_embedding($item);
                            if (!empty($content)) {
                                $this->store_embedding($item->post_type, $item->ID, $content);
                                $this->processed++;
                            }
                        } catch (Exception $e) {
                            $errors[] = sprintf(
                            /* translators: 1: Content type 2: Content ID 3: Error message */
                                __('Error processing %1$s ID: %2$d - %3$s', 'aisk-ai-chat-for-fluentcart'),
                                $item->post_type,
                                $item->ID,
                                $e->getMessage()
                            );
                        }
                    }
                    // return rest_ensure_response(array(
                    //     'success' => true,
                    //     'processed' => 0,
                    //     'total' => 0,
                    //     'done' => true,
                    //     'message' => __('No content available to process', 'aisk-ai-chat-for-fluentcart')
                    // ));
                }

                // Process settings content
                try {
                    $settings_content = $this->get_settings_content_for_embedding();
                    if (!empty($settings_content)) {
                        $this->store_embedding('settings', 0, $settings_content);
                        $this->processed++;
                    }
                } catch (Exception $e) {
                    $errors[] = sprintf(
                    /* translators: %s: Error message */
                        __('Error processing settings content - %s', 'aisk-ai-chat-for-fluentcart'),
                        $e->getMessage()
                    );
                }

                // Get total unprocessed count using the same request object
                $unprocessed_count = $this->get_unprocessed_count($request);
                if ($unprocessed_count instanceof WP_REST_Response) {
                    $unprocessed_count = $unprocessed_count->get_data();
                }
                if (is_wp_error($unprocessed_count)) {
                    return $unprocessed_count;
                }

                // Initialize errors array
                $errors = array();

                $response = array(
                    'success' => true,
                    'processed' => $this->processed,
                    'total' => $unprocessed_count['count'],
                    'done' => count($items) < $batch_size,
                    'errors' => $errors
                );

                return rest_ensure_response($response);

            } catch (Exception $e) {
                return new WP_Error(
                    'processing_error',
                    $e->getMessage(),
                    array('status' => 500)
                );
            }
        } catch (Exception $e) {
            return new WP_Error(
                'server_error',
                __('An unexpected error occurred: ', 'aisk-ai-chat-for-fluentcart') . $e->getMessage(),
                array('status' => 500)
            );
        }
    }

    /**
     * Check if content should be excluded from processing
     *
     * @param object $item Content item
     * @return bool True if content should be excluded
     */
    private function is_excluded_content($item)
    {
        $settings = get_option('aisk_settings');

        // Check post type specific exclusions
        switch ($item->post_type) {
            case 'post':
                $excluded_posts = isset($settings['ai_config']['excluded_posts']) ?
                    array_column($settings['ai_config']['excluded_posts'], 'value') : [];
                return in_array($item->ID, $excluded_posts);

            case 'page':
                $excluded_pages = isset($settings['ai_config']['excluded_pages']) ?
                    array_column($settings['ai_config']['excluded_pages'], 'value') : [];
                return in_array($item->ID, $excluded_pages);

            case 'product':
            case 'fc_product':
            case 'fluent-products':
                $excluded_products = isset($settings['ai_config']['excluded_products']) ?
                    array_column($settings['ai_config']['excluded_products'], 'value') : [];
                return in_array($item->ID, $excluded_products) || $this->is_excluded_category($item->ID);

            default:
                return false;
        }
    }

    /**
     * Get content for embedding based on content type
     *
     * @param object $item Content item
     *
     * @return string Formatted content for embedding
     * @since 1.0.0
     *
     */
    private function get_content_for_embedding($item)
    {
        switch ($item->post_type) {
            case 'product':
            case 'fc_product':
            case 'fluent-products':
                $product = AISK_FluentCart_Helper::get_product($item->ID);
                return $product ? $this->get_product_content($product) : '';

            case 'product_variation':
                $variation = AISK_FluentCart_Helper::get_product($item->ID);
                $parent_product = $variation ? AISK_FluentCart_Helper::get_product($variation->get_parent_id()) : null;
                return $variation && $parent_product ?
                    $this->get_variation_content($variation, $parent_product) : '';

            case 'post':
            case 'page':
                return $this->get_post_content($item->ID);

            default:
                return '';
        }
    }

    /**
     * Get product content for embedding
     *
     * @param object $product FluentCart product object
     *
     * @return string Formatted product content
     * @since 1.0.0
     *
     */
    private function get_product_content($product)
    {
        if (!$product) {
            return '';
        }

        $product_id = $product->get_id();
        $product_url = get_permalink($product_id);
        $image_id = $product->get_image_id();
        $image_url = wp_get_attachment_image_url($image_id, 'medium') ?: AISK_FluentCart_Helper::placeholder_img_src('medium');

        // Get plain numeric price
        $plain_price = $product->get_price();
        $regular_price = $product->get_regular_price();
        $sale_price = $product->is_on_sale() ? $product->get_sale_price() : null;
 
        // Get full description from post content
        $post = get_post($product_id);
        $full_description = $post ? wp_strip_all_tags($post->post_content) : '';
        $short_description = wp_trim_words(wp_strip_all_tags($product->get_short_description()), 20);

        // Build context data for AI
        $content = "id: {$product_id}\n\n";
        $content .= "url: {$product_url}\n\n";
        $content .= "image: {$image_url}\n\n";
        $content .= "name: {$product->get_name()}\n\n";
        $content .= "in_stock: " . ($product->is_in_stock() ? 'Yes' : 'No') . "\n\n";
        $content .= "average_rating: {$product->get_average_rating()}\n\n";

        // Price info (plain format)
        $content .= "Regular Price: {$regular_price}\n";
        if (null !== $sale_price) {
            $content .= "Sale Price: {$sale_price}\n";
        }
        $content .= "price: {$plain_price}\n\n";

        // Categories
        $categories = wp_get_post_terms($product_id, 'product_cat', ['fields' => 'names']);
        if (!empty($categories) && is_array($categories) && !is_wp_error($categories)) {
            $content .= "Categories: " . implode(', ', $categories) . "\n\n";
        }

        // Tags
        $tags = wp_get_post_terms($product_id, 'product_tag', ['fields' => 'names']);
        if (!empty($tags) && is_array($tags) && !is_wp_error($tags)) {
            $content .= "Tags: " . implode(', ', $tags) . "\n\n";
        }

        // Get product attributes from post meta
        $product_attributes = get_post_meta($product_id, '_product_attributes', true);
        if (!empty($product_attributes) && is_array($product_attributes)) {
            $content .= "Attributes:\n";
            foreach ($product_attributes as $attribute_name => $attribute) {
                if (isset($attribute['is_taxonomy']) && $attribute['is_taxonomy']) {
                    $terms = wp_get_post_terms($product_id, $attribute_name, ['fields' => 'names']);
                    if (!empty($terms) && is_array($terms) && !is_wp_error($terms)) {
                        $content .= $attribute_name . ": " . implode(', ', $terms) . "\n";
                    }
                } else {
                    $values = isset($attribute['value']) ? explode('|', $attribute['value']) : [];
                    if (!empty($values)) {
                        $content .= $attribute_name . ": " . implode(', ', $values) . "\n";
                    }
                }
            }
            $content .= "\n";
        }

        // Description
        if (!empty($full_description)) {
            $content .= "description:\n" . $full_description . "\n\n";
        }
        if ($short_description) {
            $content .= "Short Description:\n" . $short_description . "\n\n";
        }
        
        // Get SKU from post meta
        $sku = get_post_meta($product_id, '_sku', true);
        if ($sku) {
            $content .= "SKU: {$sku}\n\n";
        }

        return $content;
    }


    /**
     * Get post content for embedding
     *
     * @param int $post_id Post ID
     *
     * @return string Formatted post content
     * @since 1.0.0
     *
     */
    private function get_post_content($post_id)
    {
        $post = get_post($post_id);
        if (!$post) {
            return '';
        }

        /* translators: %s: Post title */
        $content = sprintf(
            // translators: %s: Post title
            esc_html__('Title: %s', 'aisk-ai-chat-for-fluentcart') . "\n\n",
            esc_html($post->post_title)
        );

        // Categories
        $categories = wp_get_post_categories($post_id, ['fields' => 'names']);
        if (!empty($categories)) {
            /* translators: %s: Comma-separated list of category names */
            $content .= sprintf(
                // translators: %s: Post categories
                esc_html__('Categories: %s', 'aisk-ai-chat-for-fluentcart') . "\n\n",
                esc_html(implode(', ', $categories))
            );
        }

        // Tags
        $tags = wp_get_post_tags($post_id, ['fields' => 'names']);
        if (!empty($tags)) {
            /* translators: %s: Comma-separated list of tag names */
            $content .= sprintf(
                // translators: %s: Post tags
                esc_html__('Tags: %s', 'aisk-ai-chat-for-fluentcart') . "\n\n",
                esc_html(implode(', ', $tags))
            );
        }

        // Content
        $content .= esc_html__('Content:', 'aisk-ai-chat-for-fluentcart') . "\n" . wp_strip_all_tags($post->post_content) . "\n\n";

        // Excerpt
        if (!empty($post->post_excerpt)) {
            $content .= esc_html__('Excerpt:', 'aisk-ai-chat-for-fluentcart') . "\n" . wp_strip_all_tags($post->post_excerpt) . "\n\n";
        }

        return $content;
    }

    /**
     * Get variation content for embedding
     *
     * @param object $variation Product variation object
     * @param object $parent_product Parent product object
     *
     * @return string Formatted variation content
     * @since 1.0.0
     *
     */
    private function get_variation_content($variation, $parent_product)
    {
        if (!$variation || !$parent_product) {
            return '';
        }

        $variation_id = $variation->get_id();
        
        $content = 'Product: ' . $parent_product->get_name() . "\n\n";
        $content .= 'Type: Variation' . "\n\n";

        // Get variation attributes from post meta
        $variation_attributes = get_post_meta($variation_id, '_product_attributes', true);
        if (!empty($variation_attributes) && is_array($variation_attributes)) {
            $content .= "Attributes:\n";
            foreach ($variation_attributes as $attr_name => $attr_value) {
                // Handle both taxonomy and custom attributes
                if (is_array($attr_value) && isset($attr_value['value'])) {
                    $values = explode('|', $attr_value['value']);
                    $content .= $attr_name . ": " . implode(', ', $values) . "\n";
                } else {
                    $content .= $attr_name . ": " . $attr_value . "\n";
                }
            }
            $content .= "\n";
        }

        // Price info
        $content .= 'Regular Price: ' . $variation->get_regular_price() . "\n";
        if ($variation->is_on_sale()) {
            $content .= 'Sale Price: ' . $variation->get_sale_price() . "\n";
        }
        $content .= "\n";

        // Additional details from post meta
        $sku = get_post_meta($variation_id, '_sku', true);
        if ($sku) {
            $content .= 'SKU: ' . $sku . "\n\n";
        }

        $manage_stock = get_post_meta($variation_id, '_manage_stock', true);
        if ($manage_stock === 'yes') {
            $stock_status = get_post_meta($variation_id, '_stock_status', true);
            $stock_quantity = get_post_meta($variation_id, '_stock', true);
            $content .= 'Stock Status: ' . $stock_status . "\n";
            $content .= 'Stock Quantity: ' . $stock_quantity . "\n\n";
        }

        // Get description from post content
        $post = get_post($variation_id);
        if ($post && !empty($post->post_content)) {
            $content .= "Description:\n" . wp_strip_all_tags($post->post_content) . "\n\n";
        }

        return $content;
    }

    /**
     * Split content into chunks
     *
     * @param string $content Content to split
     * @param int $max_chars Maximum characters per chunk
     *
     * @return array Array of content chunks
     * @since 1.0.0
     *
     */
    private function split_content($content, $max_chars = 8000)
    {
        if (strlen($content) <= $max_chars) {
            return [$content];
        }

        $chunks = [];
        $words = explode(' ', $content);
        $current_chunk = '';

        foreach ($words as $word) {
            if (strlen($current_chunk . ' ' . $word) > $max_chars) {
                $chunks[] = $current_chunk;
                $current_chunk = $word;
            } else {
                $current_chunk .= ('' === $current_chunk ? '' : ' ') . $word;
            }
        }

        if (!empty($current_chunk)) {
            $chunks[] = $current_chunk;
        }

        return $chunks;
    }

    /**
     * Check if product is in excluded category
     *
     * @param int $product_id Product ID
     *
     * @return bool True if product is in excluded category
     * @since 1.0.0
     *
     */
    private function is_excluded_category($product_id)
    {
        $exclude_cats = isset($this->settings['ai_config']['exclude_categories']) ? $this->settings['ai_config']['exclude_categories'] : [];
        if (empty($exclude_cats)) {
            return false;
        }

        // Support WooCommerce taxonomy and FluentCart taxonomy
        $product_cats_wc = wp_get_post_terms($product_id, 'product_cat', ['fields' => 'ids']);
        $product_cats_fc = wp_get_post_terms($product_id, 'product-categories', ['fields' => 'ids']);
        
        // Ensure we have arrays, not WP_Error objects
        $product_cats_wc = is_array($product_cats_wc) && !is_wp_error($product_cats_wc) ? $product_cats_wc : [];
        $product_cats_fc = is_array($product_cats_fc) && !is_wp_error($product_cats_fc) ? $product_cats_fc : [];
        
        $product_cats = array_unique(array_merge($product_cats_wc, $product_cats_fc));
        return !empty(array_intersect($exclude_cats, $product_cats));
    }

    /**
     * Generate embedding using OpenAI API
     *
     * @param string $text Text to generate embedding for
     *
     * @return string JSON encoded embedding
     * @throws Exception If API request fails
     *
     * @since 1.0.0
     */
    private function generate_embedding($text)
    {
        if (empty($text)) {
            throw new Exception('The text parameter is empty.');
        }

        if (empty($this->api_key)) {
            throw new Exception('OpenAI API key is not configured');
        }

        $response = wp_remote_post(
            AISK_OPENAI_API_URL . '/embeddings', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->api_key,
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode(
                    [
                        'input' => $text,
                        'model' => $this->model,
                    ]
                ),
                'timeout' => 30, // Increased timeout for larger texts
            ]
        );

        if (is_wp_error($response)) {
            throw new Exception('API request failed: ' . esc_html($response->get_error_message()));
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $status = wp_remote_retrieve_response_code($response);

        if (200 !== $status) {
            $error = isset($body['error']['message']) ? $body['error']['message'] : 'Unknown API error';
            throw new Exception('API error: ' . esc_html($error));
        }

        if (empty($body['data'][0]['embedding'])) {
            throw new Exception('No embedding in API response');
        }
        return json_encode($body['data'][0]['embedding']);
    }


    /**
     * Find similar content using embeddings
     *
     * @param string $query Query text
     * @param int $limit Maximum results to return
     * @param float $threshold Similarity threshold
     *
     * @return array Array of similar content
     * @since 1.0.0
     *
     */
    public function find_similar_content($query, $limit, $threshold, $content_type, $intent_type = '')
    {
        // If it's a product search and FluentCart is disabled, return empty
        if ($intent_type === 'product_search') {
            $settings = get_option('aisk_settings');
            if (empty($settings['ai_config']['fluentcart_enabled'])) {
                return [];
            }
        }

        $cache_key = 'aisk_similar_content_' . md5($query . $limit . $threshold . serialize($content_type) . $intent_type);
        $cached_results = wp_cache_get($cache_key, 'aisk_embeddings');
        
        if (false !== $cached_results) {
            return $cached_results;
        }

        try {
            // Generate embedding for the query
            $query_embedding = $this->generate_embedding($query);

            if (!$query_embedding) {
                return [];
            }

            // @codingStandardsIgnoreStart
            global $wpdb;

            // **Query Optimization: Search Only Relevant Content**
            if (!empty($content_type)) {
                // Check if $content_type is an array
                if (is_array($content_type) && count($content_type) > 0) {
                    // If product is in content_type, also include FluentCart product types
                    $expanded_content_types = $content_type;
                    if (in_array('product', $content_type)) {
                        $expanded_content_types = array_merge($expanded_content_types, ['fc_product', 'fluent-products', 'fluent_product']);
                    }
                    $expanded_content_types = array_unique($expanded_content_types);
                    $placeholders = implode(',', array_fill(0, count($expanded_content_types), '%s'));
                    $query_sql = "SELECT * FROM `{$wpdb->prefix}aisk_embeddings` WHERE content_type IN ($placeholders)";
                    $query_params = $expanded_content_types;
                } else {
                    // If searching for product, also search FluentCart product types
                    if ($content_type === 'product') {
                        $query_sql = "SELECT * FROM `{$wpdb->prefix}aisk_embeddings` WHERE content_type IN ('product', 'fc_product', 'fluent-products', 'fluent_product')";
                        $query_params = [];
                    } else {
                        $query_sql = "SELECT * FROM `{$wpdb->prefix}aisk_embeddings` WHERE content_type = %s";
                        $query_params = [$content_type];
                    }
                }
            } else {
                $query_sql = "SELECT * FROM `{$wpdb->prefix}aisk_embeddings`";
                $query_params = [];
            }

            // Fetch relevant results
            $results = empty($query_params)
                ? $wpdb->get_results($query_sql)
                : $wpdb->get_results($wpdb->prepare($query_sql, ...$query_params));
            // @codingStandardsIgnoreEnd

            if (!$results) {
                return [];
            }

            // **Similarity Calculation**
            $similarities = [];
            foreach ($results as $result) {
                $similarity = $this->calculate_enhanced_similarity($query_embedding, $result->embedding, $result->content_type);

                if ($similarity >= $threshold) {
                    $chunk_relevance = $this->calculate_chunk_relevance($query, $result->content_chunk);
                    $final_score = ($similarity * 0.7) + ($chunk_relevance * 0.3);

                    $similarities[] = [
                        'content_type' => $result->content_type,
                        'content_id' => $result->content_id,
                        'similarity' => $final_score,
                        'content_chunk' => $result->content_chunk,
                        'raw_similarity' => $similarity,
                        'chunk_relevance' => $chunk_relevance,
                    ];
                }
            }

            // **Enhanced Sorting**
            usort(
                $similarities, function ($a, $b) {
                    $type_priority_a = $this->get_content_type_priority($a['content_type']);
                    $type_priority_b = $this->get_content_type_priority($b['content_type']);

                    if ($type_priority_a !== $type_priority_b) {
                        return $type_priority_b - $type_priority_a;
                    }

                    return ($b['similarity'] > $a['similarity']) ? 1 : (($b['similarity'] < $a['similarity']) ? -1 : 0);
                }
            );

            // Limit the results
            $limited_results = array_slice($similarities, 0, $limit);

            // **If searching for products, return only product IDs**
            if ($intent_type === 'product_search') {
                // Filter to keep only product types if any exist (including FluentCart product types)
                $product_types = ['product', 'fc_product', 'fluent-products', 'fluent_product'];
                $product_results = array_filter($limited_results, function ($item) use ($product_types) {
                    return in_array($item['content_type'], $product_types);
                });

                // If we have product results, return just their IDs
                if (!empty($product_results)) {
                    $product_ids = array_map(function ($item) {
                        return intval($item['content_id']);
                    }, $product_results);

                    // Filter out excluded products
                    $settings = get_option('aisk_settings');
                    $excluded_products = isset($settings['ai_config']['excluded_products'])
                        ? array_column($settings['ai_config']['excluded_products'], 'value')
                        : [];
                    
                    if (!empty($excluded_products)) {
                        $excluded_products = array_map('intval', $excluded_products);
                        $product_ids = array_diff($product_ids, $excluded_products);
                        $product_ids = array_values($product_ids); // Re-index array
                    }

                    wp_cache_set($cache_key, $product_ids, 'aisk_embeddings', 300); // Cache for 5 minutes
                    return $product_ids;
                }

                // If no product results found but we're in product search intent
                return [];
            }

            // **For other content types, return full similarity data**
            wp_cache_set($cache_key, $limited_results, 'aisk_embeddings', 300); // Cache for 5 minutes
            return $limited_results;

        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Calculate enhanced similarity between embeddings
     *
     * @param string $embedding1 First embedding
     * @param string $embedding2 Second embedding
     * @param string $content_type Content type for weighting
     *
     * @return float Similarity score
     * @since 1.0.0
     *
     */
    private function calculate_enhanced_similarity($embedding1, $embedding2, $content_type)
    {
        $vec1 = json_decode($embedding1);
        $vec2 = json_decode($embedding2);

        if (!$vec1 || !$vec2) {
            return 0;
        }

        // Improved cosine similarity calculation with L2 normalization
        $dot_product = 0;
        $norm1 = 0;
        $norm2 = 0;
        $loop_length = count($vec1);
        for ($i = 0; $i < $loop_length; $i++) {
            $dot_product += $vec1[$i] * $vec2[$i];
            $norm1 += $vec1[$i] * $vec1[$i];
            $norm2 += $vec2[$i] * $vec2[$i];
        }

        $norm1 = sqrt($norm1);
        $norm2 = sqrt($norm2);

        if (0 === $norm1 || 0 === $norm2) {
            return 0;
        }

        $cosine_similarity = $dot_product / ($norm1 * $norm2);

        // Apply content type weighting
        $weight = $this->get_content_type_weight($content_type);
        return $cosine_similarity * $weight;
    }

    /**
     * Calculate chunk relevance score
     *
     * @param string $query Query text
     * @param string $chunk Content chunk
     *
     * @return float Relevance score
     * @since 1.0.0
     *
     */
    private function calculate_chunk_relevance($query, $chunk)
    {
        // Convert to lowercase for better matching
        $query = strtolower($query);
        $chunk = strtolower($chunk);

        // Calculate keyword overlap
        $query_words = str_word_count($query, 1);
        $chunk_words = str_word_count($chunk, 1);

        $matching_words = array_intersect($query_words, $chunk_words);
        $overlap_score = count($matching_words) / count($query_words);

        // Check for exact phrase matches
        $phrase_score = 0;
        if (strpos($chunk, $query) !== false) {
            $phrase_score = 0.3;
        }

        // Check for semantic relevance using key terms
        $semantic_score = $this->calculate_semantic_relevance($query_words, $chunk);

        // Combine scores with weights
        return ($overlap_score * 0.4) + $phrase_score + ($semantic_score * 0.3);
    }

    /**
     * Calculate semantic relevance between query and chunk
     *
     * @param array $query_words Array of query words
     * @param string $chunk Content chunk
     *
     * @return float Semantic relevance score
     * @since 1.0.0
     *
     */
    private function calculate_semantic_relevance($query_words, $chunk)
    {
        // Define semantic relationships (you can expand this)
        $semantic_mappings = [
            'price' => ['cost', 'pricing', 'affordable', 'expensive'],
            'shipping' => ['delivery', 'shipment', 'shipping', 'deliver'],
            'size' => ['dimensions', 'measurement', 'large', 'small'],
            'color' => ['colored', 'shade', 'tone', 'hue'],
            // Add more mappings as needed
        ];

        $score = 0;
        foreach ($query_words as $word) {
            // Check direct semantic relationships
            foreach ($semantic_mappings as $key => $related_terms) {
                if ($word === $key || in_array($word, $related_terms)) {
                    foreach ($related_terms as $term) {
                        if (strpos($chunk, $term) !== false) {
                            $score += 0.2;
                        }
                    }
                }
            }
        }

        return min($score, 1.0); // Cap at 1.0
    }

    /**
     * Get content type priority
     *
     * @param string $type Content type
     *
     * @return int Priority value
     * @since 1.0.0
     *
     */
    private function get_content_type_priority($type)
    {
        $priorities = [
            'settings' => 4,
            'product' => 3,
            'post' => 2,
            'page' => 2,
            'product_variation' => 1,
            'pdf' => 1,
            'external_url' => 1,
        ];

        return isset($priorities[$type]) ? $priorities[$type] : 0;
    }

    /**
     * Get content type weight
     *
     * @param string $type Content type
     *
     * @return float Weight value
     * @since 1.0.0
     *
     */
    private function get_content_type_weight($type)
    {
        $weights = [
            'settings' => 1.3,
            'product' => 1.2,
            'post' => 1.0,
            'page' => 1.0,
            'product_variation' => 0.8,
            'pdf' => 0.8,
            'external_url' => 0.8,
        ];

        return isset($weights[$type]) ? $weights[$type] : 1.0;
    }

    /**
     * Delete embeddings for deleted content
     *
     * @param int $post_id Post ID
     *
     * @return void
     * @since 1.0.0
     *
     */
    public function delete_content_embeddings($post_id)
    {
        // Get post type to determine content type
        $post_type = get_post_type($post_id);
        $content_type = 'product_variation' === $post_type ? 'product_variation' : $post_type;
        
        $cache_key = 'aisk_embedding_' . $content_type . '_' . $post_id;
        
        // @codingStandardsIgnoreStart
        global $wpdb;
        $table_name = $wpdb->prefix . 'aisk_embeddings';

        // Delete all embeddings for this content
        $result = $wpdb->delete(
            $table_name,
            [
                'content_type' => $content_type,
                'content_id' => $post_id,
            ],
            ['%s', '%d']
        );
        // @codingStandardsIgnoreEnd

        if ($result !== false) {
            // Clear related caches
            wp_cache_delete($cache_key, 'aisk_embeddings');
            wp_cache_delete('aisk_embeddings_type_' . $content_type, 'aisk_embeddings');
        }
    }


    /**
     * Store embedding in database
     */
    public function store_embedding($content_type, $content_id, $content, $extra_data = [])
    {
        $cache_key = 'aisk_embedding_' . $content_type . '_' . $content_id;
        $chunks = $this->split_content($content);
        $success = false;

        // @codingStandardsIgnoreStart
        global $wpdb;
        
        // For settings, check if an entry already exists
        if ('settings' === $content_type && 0 === $content_id) {
            $existing = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}aisk_embeddings 
                    WHERE content_type = %s AND content_id = %d",
                    'settings',
                    0
                )
            );

            if ($existing) {
                // For settings, only keep one entry - update it
                foreach ($chunks as $chunk) {
                    $embedding = $this->generate_embedding($chunk);
                    if ($embedding) {
                        $result = $wpdb->update(
                            "{$wpdb->prefix}aisk_embeddings",
                            [
                                'embedding' => $embedding,
                                'content_chunk' => $chunk,
                                'updated_at' => current_time('mysql'),
                            ],
                            [
                                'id' => $existing->id,
                            ],
                            ['%s', '%s', '%s'],
                            ['%d']
                        );

                        if (false !== $result) {
                            $success = true;
                            wp_cache_delete($cache_key, 'aisk_embeddings');
                        }
                    }
                }
                return $success;
            }
        }

        // Insert new embeddings for all content types
        foreach ($chunks as $chunk) {
            $embedding = $this->generate_embedding($chunk);
            if ($embedding) {
                $data = [
                    'content_type' => $content_type,
                    'content_id' => $content_id,
                    'embedding' => $embedding,
                    'content_chunk' => $chunk,
                ];
                $format = ['%s', '%d', '%s', '%s'];

                // For external URLs, add additional fields if provided
                if ('external_url' === $content_type && !empty($extra_data)) {
                    if (isset($extra_data['parent_url'])) {
                        $data['parent_url'] = $extra_data['parent_url'];
                        $format[] = '%s';
                    }
                    if (isset($extra_data['crawled_url'])) {
                        $data['crawled_url'] = $extra_data['crawled_url'];
                        $format[] = '%s';
                    }
                }

                $result = $wpdb->insert("{$wpdb->prefix}aisk_embeddings", $data, $format);
                if (false !== $result) {
                    $success = true;
                    wp_cache_delete($cache_key, 'aisk_embeddings');
                    wp_cache_delete('aisk_embeddings_type_' . $content_type, 'aisk_embeddings');
                }
            }
        }
        // @codingStandardsIgnoreEnd

        return $success;
    }

    public function get_embeddings($content_type, $content_id)
    {
        $cache_key = 'aisk_embedding_' . $content_type . '_' . $content_id;
        $embeddings = wp_cache_get($cache_key, 'aisk_embeddings');
        
        if (false === $embeddings) {
            // @codingStandardsIgnoreStart
            global $wpdb;
            $table_name = $wpdb->prefix . 'aisk_embeddings';
            
            $embeddings = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table_name} WHERE content_type = %s AND content_id = %d",
                    $content_type,
                    $content_id
                ),
                ARRAY_A
            );
            // @codingStandardsIgnoreEnd
            
            if ($embeddings) {
                wp_cache_set($cache_key, $embeddings, 'aisk_embeddings', 3600); // Cache for 1 hour
            }
        }
        
        return $embeddings ?: array();
    }

    public function get_embeddings_by_type($content_type)
    {
        $cache_key = 'aisk_embeddings_type_' . $content_type;
        $embeddings = wp_cache_get($cache_key, 'aisk_embeddings');
        
        if (false === $embeddings) {
            // @codingStandardsIgnoreStart
            global $wpdb;
            $table_name = $wpdb->prefix . 'aisk_embeddings';
            
            $embeddings = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table_name} WHERE content_type = %s",
                    $content_type
                ),
                ARRAY_A
            );
            // @codingStandardsIgnoreEnd
            
            if ($embeddings) {
                wp_cache_set($cache_key, $embeddings, 'aisk_embeddings', 3600); // Cache for 1 hour
            }
        }
        
        return $embeddings ?: array();
    }

    public function get_embeddings_by_ids($content_ids)
    {
        if (empty($content_ids)) {
            return array();
        }

        $cache_key = 'aisk_embeddings_ids_' . md5(serialize($content_ids));
        $embeddings = wp_cache_get($cache_key, 'aisk_embeddings');
        
        if (false === $embeddings) {
            // @codingStandardsIgnoreStart
            global $wpdb;
            $table_name = $wpdb->prefix . 'aisk_embeddings';
            
            $placeholders = implode(',', array_fill(0, count($content_ids), '%d'));
            $embeddings = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table_name} WHERE content_id IN ({$placeholders})",
                    $content_ids
                ),
                ARRAY_A
            );
            // @codingStandardsIgnoreEnd
            
            if ($embeddings) {
                wp_cache_set($cache_key, $embeddings, 'aisk_embeddings', 3600); // Cache for 1 hour
            }
        }
        
        return $embeddings ?: array();
    }

    public function get_embeddings_by_status($status)
    {
        $cache_key = 'aisk_embeddings_status_' . $status;
        $embeddings = wp_cache_get($cache_key, 'aisk_embeddings');
        
        if (false === $embeddings) {
            // @codingStandardsIgnoreStart
            global $wpdb;
            $table_name = $wpdb->prefix . 'aisk_embeddings';
            
            $embeddings = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table_name} WHERE status = %s",
                    $status
                ),
                ARRAY_A
            );
            // @codingStandardsIgnoreEnd
            
            if ($embeddings) {
                wp_cache_set($cache_key, $embeddings, 'aisk_embeddings', 3600); // Cache for 1 hour
            }
        }
        
        return $embeddings ?: array();
    }

    public function get_embeddings_by_type_and_status($content_type, $status)
    {
        $cache_key = 'aisk_embeddings_type_status_' . $content_type . '_' . $status;
        $embeddings = wp_cache_get($cache_key, 'aisk_embeddings');
        
        if (false === $embeddings) {
            // @codingStandardsIgnoreStart
            global $wpdb;
            $table_name = $wpdb->prefix . 'aisk_embeddings';
            
            $embeddings = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table_name} WHERE content_type = %s AND status = %s",
                    $content_type,
                    $status
                ),
                ARRAY_A
            );
            // @codingStandardsIgnoreEnd
            
            if ($embeddings) {
                wp_cache_set($cache_key, $embeddings, 'aisk_embeddings', 3600); // Cache for 1 hour
            }
        }
        
        return $embeddings ?: array();
    }
}
