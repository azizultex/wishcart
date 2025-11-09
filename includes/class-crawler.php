<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Class WISHCART_Crawler
 * Main crawler class that coordinates URL crawling
 */
class WISHCART_Crawler {
    private $content_fetcher;
    private $url_discoverer;
    private $content_processor;
    private $embeddings_handler;

    public function __construct($content_fetcher, $url_discoverer, $content_processor) {
        $this->content_fetcher = $content_fetcher;
        $this->url_discoverer = $url_discoverer;
        $this->content_processor = $content_processor;
        $this->embeddings_handler = new WISHCART_Embeddings_Handler();
    }

    /**
     * Crawl a URL and its subordinate URLs
     *
     * @param string $url URL to crawl
     * @param array $options Crawling options
     * @return array Crawling results
     */
    public function crawl($url, $options = []) {
        $results = [
            'main_url' => [
                'url' => $url,
                'status' => 'failed',
                'user_message' => ''
            ],
            'subordinate_urls' => [],
            'warnings' => [],
            'errors' => []
        ];

        try {
            // Process main URL
            $main_content = $this->content_fetcher->fetch($url, $options['include_selectors'] ?? [], $options['exclude_selectors'] ?? []);
            if ($main_content) {
                $processed_content = $this->content_processor->process($main_content, $options['include_selectors'] ?? [], $options['exclude_selectors'] ?? []);
                if (!empty($processed_content)) {
                    $results['main_url']['status'] = 'success';
                    $results['main_url']['content'] = $processed_content;
                } else {
                    $results['main_url']['status'] = 'failed';
                    $results['main_url']['user_message'] = __(
                        'The provided URL content cannot be extracted or crawled due to bot protection or dynamic content.',
                        'wish-cart'
                    );
                    // Persist the user_message for polling
                    update_option('wishcart_url_user_message_' . md5($url), $results['main_url']['user_message']);
                    $results['errors'][] = $results['main_url']['user_message'];
                    return $results;
                }
            } else {
                $results['main_url']['status'] = 'failed';
                $results['errors'][] = "Failed to fetch content from main URL: $url";
                return $results;
            }

            // Process subordinate URLs in smaller batches if follow_links is true
            if (!empty($options['follow_links']) && $options['follow_links']) {
                $subordinate_urls = $this->url_discoverer->discover($url, $options);
                $batch_size = 5; // Process 5 URLs at a time
                $total_urls = count($subordinate_urls);
                $processed = 0;

                foreach (array_chunk($subordinate_urls, $batch_size) as $url_batch) {
                    foreach ($url_batch as $sub_url) {
                        try {
                            $sub_content = $this->content_fetcher->fetch($sub_url, $options['include_selectors'] ?? [], $options['exclude_selectors'] ?? []);
                            if ($sub_content) {
                                $processed_content = $this->content_processor->process($sub_content, $options['include_selectors'] ?? [], $options['exclude_selectors'] ?? []);
                                if (!empty($processed_content)) {
                                    $results['subordinate_urls'][] = [
                                        'url' => $sub_url,
                                        'status' => 'success',
                                        'content' => $processed_content
                                    ];
                                } else {
                                    $results['subordinate_urls'][] = [
                                        'url' => $sub_url,
                                        'status' => 'failed'
                                    ];
                                    $results['warnings'][] = "No content after processing subordinate URL: $sub_url";
                                }
                            } else {
                                $results['subordinate_urls'][] = [
                                    'url' => $sub_url,
                                    'status' => 'failed'
                                ];
                                $results['warnings'][] = "Failed to fetch content from subordinate URL: $sub_url";
                            }
                        } catch (Exception $e) {
                            $results['subordinate_urls'][] = [
                                'url' => $sub_url,
                                'status' => 'failed'
                            ];
                            $results['warnings'][] = "Error processing subordinate URL: $sub_url - " . $e->getMessage();
                        }
                        $processed++;
                    }
                    // Add a small delay between batches to prevent overwhelming the server
                    usleep(500000); // 0.5 second delay
                }
            }

            return $results;
        } catch (Exception $e) {
            $results['errors'][] = $e->getMessage();
            return $results;
        }
    }

    /**
     * Save crawling results to database
     *
     * @param array $results Crawling results
     * @return bool Success status
     */
    public function save_results($results) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wishcart_embeddings';
        
        try {
            // Save main URL content
            if (
                $results['main_url']['status'] === 'success' &&
                isset($results['main_url']['url'], $results['main_url']['content']) &&
                !empty($results['main_url']['url'])
            ) {
                $content_to_save = is_array($results['main_url']['content']) && isset($results['main_url']['content']['content'])
                    ? $results['main_url']['content']['content']
                    : $results['main_url']['content'];
                
                // Check for empty content (not bot protected)
                if (empty($content_to_save)) {
                    $user_message = 'No content to embed for this URL.';
                    update_option('wishcart_url_user_message_' . md5($results['main_url']['url']), $user_message);
                    return false; // Do not mark as completed
                }
                
                $success = $this->store_embedding(
                    'external_url',
                    md5($results['main_url']['url']),
                    $content_to_save,
                    [
                        'crawled_url' => $results['main_url']['url'],
                        'parent_url' => $results['main_url']['url']
                    ]
                );
                if (!$success) {
                    return false;
                }
            } else if ($results['main_url']['status'] === 'success') {
                $user_message = 'No content to embed for this URL.';
                update_option('wishcart_url_user_message_' . md5($results['main_url']['url']), $user_message);
                return false;
            }
            
            // Save subordinate URLs content
            foreach ($results['subordinate_urls'] as $sub_url) {
                if (
                    $sub_url['status'] === 'success' &&
                    isset($sub_url['url'], $sub_url['content']) &&
                    !empty($sub_url['url'])
                ) {
                    $content_to_save = is_array($sub_url['content']) && isset($sub_url['content']['content'])
                        ? $sub_url['content']['content']
                        : $sub_url['content'];
                        
                    if (!empty($content_to_save)) {
                        $success = $this->store_embedding(
                            'external_url',
                            md5($sub_url['url']),
                            $content_to_save,
                            [
                                'crawled_url' => $sub_url['url'],
                                'parent_url' => $results['main_url']['url']
                            ]
                        );
                        
                        if ($success) {
                        } else {
                        }
                    } else {
                    }
                } else if ($sub_url['status'] === 'success') {
                }
            }
            
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Store embedding in database
     */
    private function store_embedding($content_type, $content_id, $content, $extra_data = []) {
        return $this->embeddings_handler->store_embedding($content_type, $content_id, $content, $extra_data);
    }
} 