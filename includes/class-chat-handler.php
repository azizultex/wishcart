<?php
/**
 * Chat Handler Class for AISK
 *
 * This file contains the chat handling functionality for the AISK plugin,
 * managing conversations, messages, and AI interactions.
 *
 * @category WordPress
 * @package  AISK
 * @author   AISK Team <support@aisk.chat>
 * @license  GPL-2.0+ https://www.gnu.org/licenses/gpl-2.0.html
 * @link     https://aisk.chat
 */

if ( ! defined('ABSPATH') ) {
	exit;
}

/**
 * AISK Chat Handler Class
 *
 * Handles all chat related functionality including message processing,
 * intent classification, and conversation management.
 *
 * @category Class
 * @package  AISK
 * @author   AISK Team <support@aisk.chat>
 * @license  GPL-2.0+ https://www.gnu.org/licenses/gpl-2.0.html
 * @link     https://aisk.chat
 */
class AISK_Chat_Handler {

    private $embeddings_handler;
    private $product_handler;
    private $order_handler;
    private $chat_storage;
    private $usage_tracker;

    private $openai_key;

    /**
     * Constructor for the Chat Handler
     *
     * Initializes handlers and loads required settings
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function __construct() {
        $this->embeddings_handler = new AISK_Embeddings_Handler();
        $this->product_handler = new AISK_Product_Handler();
        $this->order_handler = new AISK_Order_Handler();
        $this->chat_storage = AISK_Chat_Storage::get_instance();
        $this->usage_tracker = class_exists('AISK_API_Usage_Tracker') ? AISK_API_Usage_Tracker::get_instance() : null;
        $settings = get_option('aisk_settings');
        // auth_key deprecated and no longer required
        $this->openai_key = isset($settings['general']['openai_key']) ? $settings['general']['openai_key'] : '';

        add_action('rest_api_init', [ $this, 'register_routes' ]);
    }

    /**
     * Register REST API routes
     *
     * Sets up all the REST API endpoints for chat functionality
     *
     * @since 1.0.0
     *
     * @return void
     */

    public function register_routes() {
        // Chat endpoint - Public access with nonce verification
        register_rest_route(
            'aisk/v1', '/chat', [
                'methods' => 'POST',
                'callback' => [ $this, 'handle_chat_request' ],
                'permission_callback' => [ $this, 'verify_chat_request_authenticated' ],
            ]
        );

        // Auth endpoint - Public access with nonce verification
        register_rest_route(
            'aisk/v1', '/auth', [
                'methods' => 'POST',
                'callback' => [ $this, 'handle_auth_request' ],
                'permission_callback' => [ $this, 'verify_auth_request' ],
            ]
        );

        // Conversations endpoints - Authenticated access required
        register_rest_route(
            'aisk/v1', '/conversations', [
                [
                    'methods' => 'GET',
                    'callback' => [ $this, 'get_conversations' ],
                    'permission_callback' => [ $this, 'verify_chat_request_authenticated' ],
                ],
                [
                    'methods' => 'POST',
                    'callback' => [ $this, 'create_conversation' ],
                    'permission_callback' => [ $this, 'verify_chat_request_authenticated' ],
                ],
            ]
        );

        // Single conversation endpoint - Authenticated access required
        register_rest_route(
            'aisk/v1', '/conversations/(?P<id>[a-zA-Z0-9-]+)', [
                'methods' => 'GET',
                'callback' => [ $this, 'get_conversation' ],
                'permission_callback' => [ $this, 'verify_chat_request_authenticated' ],
                'args' => [
                    'id' => [
                        'required' => true,
                        'validate_callback' => function ( $param ) {
                            return is_string($param) && ! empty($param);
                        },
                    ],
                ],
            ]
        );

        // Messages endpoint - Authenticated access required
        register_rest_route(
            'aisk/v1', '/messages/(?P<conversation_id>[a-zA-Z0-9-]+)', [
                'methods' => 'GET',
                'callback' => [ $this, 'get_messages' ],
                'permission_callback' => [ $this, 'verify_conversation_access' ],
                'args' => [
                    'conversation_id' => [
                        'required' => true,
                        'validate_callback' => function ( $param ) {
                            return is_string($param) && ! empty($param);
                        },
                    ],
                ],
            ]
        );

        // Submit inquiry endpoint - Public access with nonce verification
        register_rest_route(
            'aisk/v1', '/submit-inquiry', [
                'methods' => 'POST',
                'callback' => [ $this, 'handle_inquiry_submission' ],
                'permission_callback' => [ $this, 'verify_chat_request_authenticated' ],
            ]
        );

        // Classify Intent endpoint - Public access with nonce verification
        register_rest_route(
            'aisk/v1', '/classify-intent', [
                'methods' => 'POST',
                'callback' => [ $this, 'classify_intent' ],
                'permission_callback' => [ $this, 'verify_chat_request_authenticated' ],
                'args' => [
                    'message' => [
                        'required' => true,
                        'type' => 'string',
                    ],
                    'conversation_history' => [
                        'required' => true,
                        'type' => 'array',
                    ],
                ],
            ]
        );

        // Admin-only endpoints
        register_rest_route(
            'aisk/v1', '/inquiries', [
                'methods' => 'GET',
                'callback' => [ $this, 'get_inquiries' ],
                'permission_callback' => function () {
                    return current_user_can(AISK_FluentCart_Helper::get_manage_capability());
                },
                'args' => [
                    'page' => [
                        'default' => 1,
                        'sanitize_callback' => 'absint',
                    ],
                    'per_page' => [
                        'default' => 20,
                        'sanitize_callback' => 'absint',
                    ],
                    'status' => [
                        'validate_callback' => function ( $param ) {
                            return in_array($param, [ 'pending', 'in_progress', 'resolved', '' ]);
                        },
                    ],
                    'search' => [
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'start_date' => [
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'end_date' => [
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ]
        );

        // Get single inquiry details
        register_rest_route(
            'aisk/v1', '/inquiries/(?P<id>\d+)', [
				'methods' => 'GET',
				'callback' => [ $this, 'get_inquiry_details' ],
				'permission_callback' => function () {
					return current_user_can(AISK_FluentCart_Helper::get_manage_capability());
				},
				'args' => [
					'id' => [
						'validate_callback' => function ( $param ) {
							return is_numeric($param);
						},
					],
				],
            ]
        );

        // Get inquiry notes
        register_rest_route(
            'aisk/v1', '/inquiries/(?P<id>\d+)/notes', [
				[
					'methods' => 'GET',
					'callback' => [ $this, 'get_inquiry_notes' ],
					'permission_callback' => function () {
						return current_user_can(AISK_FluentCart_Helper::get_manage_capability());
					},
				],
				[
					'methods' => 'POST',
					'callback' => [ $this, 'add_inquiry_note' ],
					'permission_callback' => function () {
						return current_user_can(AISK_FluentCart_Helper::get_manage_capability());
					},
				],
            ]
        );

        // Update inquiry status
        register_rest_route(
            'aisk/v1', '/inquiries/(?P<id>\d+)/status', [
				'methods' => 'POST',
				'callback' => [ $this, 'update_inquiry_status' ],
				'permission_callback' => function () {
					return current_user_can(AISK_FluentCart_Helper::get_manage_capability());
				},
				'args' => [
					'id' => [
						'validate_callback' => function ( $param ) {
							return is_numeric($param);
						},
					],
					'status' => [
						'required' => true,
						'validate_callback' => function ( $param ) {
							return in_array($param, [ 'pending', 'in_progress', 'resolved' ]);
						},
					],
				],
            ]
        );
    }

    /**
     * Verify chat request permission
     *
     * @return bool Whether the request is authorized
     */
    public function verify_chat_request_authenticated() {
        // Verify nonce if present
        $nonce = isset( $_SERVER['HTTP_X_WP_NONCE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_WP_NONCE'] ) ) : '';
        if ( ! empty( $nonce ) && ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
            return false;
        }

        // Allow if user is logged in or has a valid chat session
        if (is_user_logged_in() || ! empty( $_COOKIE[ AISK_CHAT_SESSION_COOKIE ])) {
            return true;
        }

        // For incognito mode, be more lenient - allow based on nonce only
        // This is a fallback for cases where cookies don't work
        if (!empty($nonce) && wp_verify_nonce($nonce, 'wp_rest')) {
            return true;
        }

        return false;
    }

    /**
     * Verify auth request permission
     *
     * @return bool Whether the request is authorized
     */
    public function verify_auth_request() {
        // Verify nonce
        $nonce = isset( $_SERVER['HTTP_X_WP_NONCE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_WP_NONCE'] ) ) : '';
        return ! empty( $nonce ) && wp_verify_nonce( $nonce, 'wp_rest' );
    }

    /**
     * Verify conversation access permission
     *
     * @param WP_REST_Request $request The request object
     * @return bool Whether the user has access to the conversation
     */
    public function verify_conversation_access($request) {
        // Allow access if user is logged in
        if (is_user_logged_in()) {
            return true;
        }

        // Get conversation ID from request
        $conversation_id = $request->get_param('conversation_id');
        if (empty($conversation_id)) {
            return false;
        }

        // Get conversation from storage
        $conversation = $this->chat_storage->get_conversation($conversation_id);
        if (!$conversation) {
            return false;
        }

        // Allow access if IP address matches
        $current_ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        if (!empty($conversation->ip_address) && $conversation->ip_address === $current_ip) {
            return true;
        }

        // Allow access if user agent matches
        $current_user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
        if (!empty($conversation->user_agent) && $conversation->user_agent === $current_user_agent) {
            return true;
        }

        return false;
    }
    /**
     * Handle incoming chat requests
     *
     * Processes chat messages, determines intent, and returns appropriate responses
     *
     * @param WP_REST_Request $request The incoming request object
     *
     * @since 1.0.0
     *
     * @return array | WP_Error Response object or error
     */
    public function handle_chat_request( $request ) {
        if ( empty($this->openai_key) ) {
            return new WP_Error('no_openai_key', 'OpenAI key required. Please configure in the settings.', [ 'status' => 400 ]);
        }

        $params = $request->get_params();
        if ( empty($params['message']) ) {
            return new WP_Error('no_message', 'No message provided', [ 'status' => 400 ]);
        }

        $message = sanitize_text_field($params['message']);
        $conversation_id = isset($params['conversation_id']) ? sanitize_text_field($params['conversation_id']) : '';

        // Check for quick responses first
        $quick_response = $this->get_quick_response($message);
        if ($quick_response) {
            // Store user message
            if ($conversation_id) {
                $this->chat_storage->add_message($conversation_id, 'user', $message);
                // Store bot response
                $this->chat_storage->add_message($conversation_id, 'bot', $quick_response);
            }
            
            return [
                'message' => $quick_response,
                'is_quick_response' => true
            ];
        }

        // Get conversation to determine platform
        $conversation = null;
        if ( $conversation_id ) {
            $conversation = $this->chat_storage->get_conversation($conversation_id);
            $this->chat_storage->add_message($conversation_id, 'user', $message);
        }

        // Determine platform from conversation
        $platform = 'web'; // default
        if ($conversation && isset($conversation->platform)) {
            $platform = $conversation->platform;
        }

        // Process based on intent
        try {
            
            $intent = $this->classify_intent_internal($message, $conversation_id);
            
            if ( is_wp_error($intent) ) {
                return [
                    'message' => $intent->get_error_message(),
                    'status' => $intent->get_error_data()['status'],
                ];
            }

            // Check FluentCart state for product-related intents
            $settings = get_option('aisk_settings');
            $is_fluentcart_enabled = !empty($settings['ai_config']['fluentcart_enabled']);
            
            if (!$is_fluentcart_enabled && in_array($intent['intent_type'], ['product_search', 'product_info_search'])) {
                return [
                    'message' => __("Product search is currently disabled. Please enable FluentCart integration in the settings.", 'aisk-ai-chat-for-fluentcart'),
                    'products' => []
                ];
            }
            
            $response = null;
            switch ( $intent['intent_type'] ) {
                case 'product_search':
                    $content_type = [ 'product' ];
                    $response = $this->handle_product_search( $intent, $message, $content_type );
                    break;

                case 'product_info_search':
                    $content_type = [ 'product' ];
                    $response = $this->handle_product_info_search($message, $conversation_id, $content_type, $platform);
                    break;
                    
                    case 'order_status':
                        $response = $this->handle_order_status_request($intent);
                    break;

                case 'contact_support':
                    $content_type = ['settings'];
                    $contact_details = $this->handle_general_query($message, $conversation_id, $content_type, $platform);
                    
                    // Check if contact form is enabled from settings
                    $settings = get_option('aisk_settings');
                    $is_contact_form_enabled = !empty($settings['integrations']['contact_form']['enabled']);
                    
                    // Get the response type from the intent
                    $response_type = isset($intent['response_type']) ? $intent['response_type'] : 'info';
                    
                    // Only show form if explicitly requested and enabled
                    $should_show_form = $is_contact_form_enabled && $response_type === 'form';
                    
                    // Prepare the response
                    $response = [
                        'message' => $contact_details['message'],
                        'type' => 'contact_support',
                        'support' => $should_show_form,
                        'response_type' => $response_type
                    ];
                    break;

                case 'general_conversation':
                    $content_type = ['settings'];
                    $response = $this->handle_general_query($message, $conversation_id, $content_type, $platform);
                    break;

                case 'general_inquiries':
                    $content_type = ['settings', 'page', 'post'];
                    $response = $this->handle_general_query($message, $conversation_id, $content_type, $platform);
                    break;

                default:
                    $content_type = [ 'external_url', 'pdf', 'page', 'post', 'settings' ];
                    $response = $this->handle_general_query($message, $conversation_id, $content_type, $platform);
            }

            // Store bot response
            if ( $conversation_id && !empty($response['message']) ) {
                // Ensure we have a valid message type
                $message_type = 'bot';
                
                // Prepare metadata based on response type
                $metadata = [
                    'products' => isset($response['products']) ? $response['products'] : null,
                    'order' => isset($response['order']) ? $response['order'] : null,
                    'type' => isset($response['type']) ? $response['type'] : null,
                    'support' => isset($response['support']) ? $response['support'] : null,
                    'response_type' => isset($response['response_type']) ? $response['response_type'] : null,
                    'contact_info' => isset($response['contact_info']) ? $response['contact_info'] : null,
                    'form_fields' => isset($response['form_fields']) ? $response['form_fields'] : null
                ];
                
                // Store the message with metadata
                $this->chat_storage->add_message(
                    $conversation_id,
                    $message_type,
                    $response['message'],
                    $metadata
                );
            }

            // Ensure order data is included in the response
            if (isset($response['order'])) {
                $response['order_data'] = $response['order'];
            }
            
            return $response;
        } catch ( Exception $e ) {
            return new WP_Error(
                'processing_failed',
                $e->getMessage(),
                [ 'status' => 500 ]
            );
        }
    }

    /**
     * Get contact information from settings
     *
     * @return array Contact information
     */
    private function get_contact_info() {
        $settings = get_option('aisk_settings');
        return [
            'email' => isset($settings['contact']['email']) ? $settings['contact']['email'] : '',
            'phone' => isset($settings['contact']['phone']) ? $settings['contact']['phone'] : '',
            'hours' => isset($settings['contact']['hours']) ? $settings['contact']['hours'] : '',
            'address' => isset($settings['contact']['address']) ? $settings['contact']['address'] : '',
        ];
    }

    /**
     * Get quick response for common queries
     *
     * @param string $message The user's message
     * @return string|null Quick response or null if no quick response available
     */
    private function get_quick_response($message) {
        $lower_message = strtolower(trim($message));
        
        // Professional greeting responses
        if (in_array($lower_message, ['hi', 'hello', 'hey', 'hi there', 'hello there']) ||
            strpos($lower_message, 'hi ') === 0 || strpos($lower_message, 'hello ') === 0) {
            return 'Hello! Welcome to our support chat. How may I assist you today?';
        }
        
        // Time-based greetings
        if (in_array($lower_message, ['good morning', 'morning'])) {
            return 'Good morning! I hope you\'re having a great start to your day. How can I help you?';
        }
        if (in_array($lower_message, ['good afternoon', 'afternoon'])) {
            return 'Good afternoon! Thank you for reaching out. What can I do for you today?';
        }
        if (in_array($lower_message, ['good evening', 'evening'])) {
            return 'Good evening! I\'m here to help with any questions you may have.';
        }
        if (in_array($lower_message, ['good night', 'night'])) {
            return 'Good night! If you have any urgent questions, I\'m here to help.';
        }
        
        // Professional goodbye responses
        if (strpos($lower_message, 'bye') !== false || strpos($lower_message, 'goodbye') !== false ||
            strpos($lower_message, 'see you') !== false || strpos($lower_message, 'farewell') !== false ||
            in_array($lower_message, ['bye', 'goodbye', 'see ya', 'take care'])) {
            return 'Thank you for contacting us today. Have a wonderful day and feel free to reach out anytime!';
        }
        
        // How are you responses
        if (strpos($lower_message, 'how are you') !== false || strpos($lower_message, 'how\'s it going') !== false ||
            strpos($lower_message, 'how do you do') !== false || $lower_message === 'how are you?' ||
            strpos($lower_message, 'what\'s up') !== false || strpos($lower_message, 'how\'s your day') !== false) {
            return 'I\'m doing well, thank you for asking! I\'m here and ready to assist you with any questions or concerns you may have.';
        }
        
        // Help requests
        if ($lower_message === 'help' || $lower_message === 'help me' || 
            strpos($lower_message, 'i need help') === 0 || strpos($lower_message, 'can you help') === 0 ||
            strpos($lower_message, 'i need assistance') === 0) {
            return 'I\'d be happy to help you! I can assist with product information, order inquiries, technical support, and general questions. What specific area do you need help with?';
        }
        
        return null; // No quick response available
    }


    /**
     * Handle inquiry submission
     *
     * @param WP_REST_Request $request The incoming request object
     * @return array|WP_Error Success response or error
     */
    public function handle_inquiry_submission($request) {
        $params = $request->get_params();

        $required_params = ['note', 'order_number', 'conversation_id'];
        $missing_params = array_filter(
            $required_params,
            function($param) use ($params) {
                return empty($params[$param]);
            }
        );

        if (!empty($missing_params)) {
            return new WP_Error(
                'missing_params',
                'Missing required parameters: ' . implode(', ', $missing_params),
                ['status' => 400]
            );
        }

        // Get customer details from order
        $order = AISK_FluentCart_Helper::get_order($params['order_number']);
        if (!$order) {
            return new WP_Error('invalid_order', 'Order not found', ['status' => 404]);
        }

        // @codingStandardsIgnoreStart
        global $wpdb;
        $result = $wpdb->insert(
            $wpdb->prefix . 'aisk_inquiries',
            [
                'conversation_id' => $params['conversation_id'],
                'order_number' => $params['order_number'],
                'customer_email' => $order->get_billing_email(),
                'customer_phone' => $order->get_billing_phone(),
                'note' => sanitize_textarea_field($params['note']),
                'status' => 'pending',
                'created_at' => gmdate('Y-m-d H:i:s'),
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]
        );
        // @codingStandardsIgnoreEnd

        if (!$result) {
            return new WP_Error('db_error', 'Failed to save inquiry', ['status' => 500]);
        }

        // Clear any related caches
        wp_cache_delete('aisk_inquiries_' . md5(serialize(['conversation_id' => $params['conversation_id']])), 'aisk_inquiries');
        wp_cache_delete('aisk_inquiries_total_' . md5(serialize([])), 'aisk_inquiries');

        return [
            'success' => true,
            'message' => 'Inquiry submitted successfully',
        ];
    }

    /**
     * Get list of inquiries
     *
     * Retrieves paginated list of inquiries with optional filters
     *
     * @param WP_REST_Request $request The incoming request object
     *
     * @since 1.0.0
     *
     * @return array List of inquiries with pagination data
     */
    public function get_inquiries($request) {
        global $wpdb;

        // Get parameters
        $user_page = $request->get_param('page');
        $user_per_page = $request->get_param('per_page');
        $page = isset($user_page) ? $user_page : 1;
        $per_page = isset($user_per_page) ? $user_per_page : 20;
        $status = $request->get_param('status');
        $search = $request->get_param('search');
        $start_date = $request->get_param('start_date');
        $end_date = $request->get_param('end_date');

        $offset = ($page - 1) * $per_page;

        // Start building query
        $where_clauses = [];
        $where_values = [];

        // Add status filter
        if (!empty($status)) {
            $where_clauses[] = 'status = %s';
            $where_values[] = $status;
        }

        // Add date range filter in server's local timezone
        if (!empty($start_date)) {
            $where_clauses[] = 'created_at >= %s';
            // Format the start date with beginning of day
            $formatted_start_date = gmdate('Y-m-d H:i:s', strtotime($start_date . ' 00:00:00'));
            $where_values[] = $formatted_start_date;
        }

        if (!empty($end_date)) {
            $where_clauses[] = 'created_at <= %s';
            // Format the end date with end of day
            $formatted_end_date = gmdate('Y-m-d H:i:s', strtotime($end_date . ' 23:59:59'));
            $where_values[] = $formatted_end_date;
        }

        // Add search filter
        if (!empty($search)) {
            $search_term = '%' . $wpdb->esc_like($search) . '%';
            $where_clauses[] = '(order_number LIKE %s OR customer_email LIKE %s OR customer_phone LIKE %s)';
            $where_values[] = $search_term;
            $where_values[] = $search_term;
            $where_values[] = $search_term;
        }

        // Combine the where clauses
        $where_sql = '';
        if (!empty($where_clauses)) {
            $where_sql = 'WHERE ' . implode(' AND ', $where_clauses);
        }

        // Generate a unique cache key based on query parameters
        $cache_key = 'aisk_inquiries_' . md5(serialize([$where_sql, $where_values, $per_page, $offset]));

        // Try to get cached results
        $inquiries = wp_cache_get($cache_key, 'aisk_inquiries');
        if (false === $inquiries) {
            // @codingStandardsIgnoreStart
            $inquiries = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}aisk_inquiries
                $where_sql
                ORDER BY created_at DESC
                LIMIT %d OFFSET %d",
                array_merge($where_values, [$per_page, $offset])
            ));
            // @codingStandardsIgnoreEnd
            wp_cache_set($cache_key, $inquiries, 'aisk_inquiries', 300); // Cache for 5 minutes
        }

        // Get total count for pagination
        $total_cache_key = 'aisk_inquiries_total_' . md5(serialize([$where_sql, $where_values]));
        $total = wp_cache_get($total_cache_key, 'aisk_inquiries');
        if (false === $total) {
            // @codingStandardsIgnoreStart
            if (empty($where_values)) {
                $total = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}aisk_inquiries"));
            } else {
                $total = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}aisk_inquiries %s", $where_sql));
            }
            // @codingStandardsIgnoreEnd
            wp_cache_set($total_cache_key, $total, 'aisk_inquiries', 300); // Cache for 5 minutes
        }

        // Convert created_at and updated_at to ISO 8601 with Z (UTC) for each inquiry
        if ($inquiries) {
            foreach ($inquiries as &$inquiry) {
                if (isset($inquiry->created_at)) {
                    $inquiry->created_at = gmdate('Y-m-d\TH:i:s\Z', strtotime($inquiry->created_at));
                }
                if (isset($inquiry->updated_at)) {
                    $inquiry->updated_at = gmdate('Y-m-d\TH:i:s\Z', strtotime($inquiry->updated_at));
                }
            }
        }

        return [
            'inquiries' => $inquiries ? $inquiries : [],
            'total' => (int) $total,
            'pages' => ceil($total / $per_page),
            'current_page' => $page,
        ];
    }

    /**
     * Get inquiry details
     *
     * @param WP_REST_Request $request The incoming request object
     * @return array|WP_Error Inquiry details or error
     */
    public function get_inquiry_details($request) {
        global $wpdb;
        $inquiry_id = $request->get_param('id');

        // Generate cache key
        $cache_key = 'aisk_inquiry_details_' . $inquiry_id;
        
        // Try to get cached inquiry
        $inquiry = wp_cache_get($cache_key, 'aisk_inquiries');
        
        if (false === $inquiry) {
            // @codingStandardsIgnoreStart
            $inquiry = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}aisk_inquiries WHERE id = %d",
                    $inquiry_id
                )
            );
            // @codingStandardsIgnoreEnd
            
            if ($inquiry) {
                wp_cache_set($cache_key, $inquiry, 'aisk_inquiries', 300); // Cache for 5 minutes
            }
        }

        if (!$inquiry) {
            return new WP_Error(
                'inquiry_not_found',
                'Inquiry not found',
                ['status' => 404]
            );
        }

        // Convert created_at and updated_at to ISO 8601 UTC
        if (isset($inquiry->created_at)) {
            $inquiry->created_at = gmdate('Y-m-d\TH:i:s\Z', strtotime($inquiry->created_at));
        }
        if (isset($inquiry->updated_at)) {
            $inquiry->updated_at = gmdate('Y-m-d\TH:i:s\Z', strtotime($inquiry->updated_at));
        }

        // Get notes for this inquiry
        $notes = $this->get_inquiry_notes($request);
        // Convert created_at for each note
        if ($notes) {
            foreach ($notes as &$note) {
                if (isset($note->created_at)) {
                    $note->created_at = gmdate('Y-m-d\TH:i:s\Z', strtotime($note->created_at));
                }
            }
        }

        return [
            'inquiry' => $inquiry,
            'notes' => $notes,
        ];
    }

    /**
     * Get inquiry notes
     *
     * @param WP_REST_Request $request The incoming request object
     * @return array List of inquiry notes
     */
    public function get_inquiry_notes($request) {
        global $wpdb;
        $inquiry_id = $request->get_param('id');

        // Generate cache key
        $cache_key = 'aisk_inquiry_notes_' . $inquiry_id;
        
        // Try to get cached notes
        $notes = wp_cache_get($cache_key, 'aisk_inquiries');
        
        if (false === $notes) {
            // @codingStandardsIgnoreStart
            $notes = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}aisk_inquiry_notes 
                    WHERE inquiry_id = %d 
                    ORDER BY created_at DESC",
                    $inquiry_id
                )
            );
            // @codingStandardsIgnoreEnd
            
            if ($notes) {
                wp_cache_set($cache_key, $notes, 'aisk_inquiries', 300); // Cache for 5 minutes
            }
        }

        return $notes;
    }

    /**
     * Add note to inquiry
     *
     * @param WP_REST_Request $request The incoming request object
     * @return array|WP_Error Success response or error
     */
    public function add_inquiry_note($request) {
        global $wpdb;

        $inquiry_id = $request->get_param('id');
        $note = sanitize_textarea_field($request->get_param('note'));
        $current_user = wp_get_current_user();

        if (empty($note)) {
            return new WP_Error(
                'empty_note',
                'Note cannot be empty',
                ['status' => 400]
            );
        }

        // @codingStandardsIgnoreStart
        $result = $wpdb->insert(
            $wpdb->prefix . 'aisk_inquiry_notes',
            [
                'inquiry_id' => $inquiry_id,
                'note' => $note,
                'author_id' => get_current_user_id(),
                'author' => $current_user->display_name,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ],
            ['%d', '%s', '%d', '%s', '%s']
        );
        // @codingStandardsIgnoreEnd

        if (false === $result) {
            return new WP_Error(
                'note_creation_failed',
                'Failed to create note',
                ['status' => 500]
            );
        }

        // Clear related caches
        wp_cache_delete('aisk_inquiry_notes_' . $inquiry_id, 'aisk_inquiries');
        wp_cache_delete('aisk_inquiry_details_' . $inquiry_id, 'aisk_inquiries');

        // Send email notification to customer
        $this->send_note_notification($inquiry_id, $note);

        return [
            'success' => true,
            'note_id' => $wpdb->insert_id,
        ];
    }

    /**
     * Send note notification
     *
     * @param int    $inquiry_id The inquiry ID
     * @param string $note       The note content
     * @return void
     */
    private function send_note_notification($inquiry_id, $note) {
        global $wpdb;

        // Get inquiry details from cache or database
        $cache_key = 'aisk_inquiry_details_' . $inquiry_id;
        $inquiry = wp_cache_get($cache_key, 'aisk_inquiries');
        
        if (false === $inquiry) {
            // @codingStandardsIgnoreStart
            $inquiry = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}aisk_inquiries WHERE id = %d",
                    $inquiry_id
                )
            );
            // @codingStandardsIgnoreEnd
            
            if ($inquiry) {
                wp_cache_set($cache_key, $inquiry, 'aisk_inquiries', 300);
            }
        }

        if (!$inquiry) {
            return;
        }

        $to = $inquiry->customer_email;
        $subject = sprintf('Update on your inquiry #%d', $inquiry_id);

        $message = "Hello,\n\n";
        $message .= "There has been an update on your inquiry:\n\n";
        $message .= $note . "\n\n";
        $message .= "Best regards,\n";
        $message .= get_bloginfo('name');

        wp_mail($to, $subject, $message);
    }

    /**
     * Update inquiry status
     *
     * @param WP_REST_Request $request The incoming request object
     * @return array|WP_Error Success response or error
     */
    public function update_inquiry_status($request) {
        global $wpdb;

        $inquiry_id = $request->get_param('id');
        $new_status = $request->get_param('status');

        // Get current inquiry data from cache or database
        $cache_key = 'aisk_inquiry_details_' . $inquiry_id;
        $inquiry = wp_cache_get($cache_key, 'aisk_inquiries');
        
        if (false === $inquiry) {
            // @codingStandardsIgnoreStart
            $inquiry = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}aisk_inquiries WHERE id = %d",
                    $inquiry_id
                )
            );
            // @codingStandardsIgnoreEnd
        }

        if (!$inquiry) {
            return new WP_Error('inquiry_not_found', 'Inquiry not found', ['status' => 404]);
        }

        // Update status
        // @codingStandardsIgnoreStart
        $result = $wpdb->update(
            $wpdb->prefix . 'aisk_inquiries',
            [
                'status' => $new_status,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ],
            ['id' => $inquiry_id],
            ['%s'],
            ['%d']
        );
        // @codingStandardsIgnoreEnd

        if (false === $result) {
            return new WP_Error('status_update_failed', 'Failed to update status', ['status' => 500]);
        }

        // Clear related caches
        wp_cache_delete($cache_key, 'aisk_inquiries');
        wp_cache_delete('aisk_inquiry_notes_' . $inquiry_id, 'aisk_inquiries');

        // Send email notification
        $this->send_status_notification($inquiry, $new_status);

        return [
            'success' => true,
            'status' => $new_status,
        ];
    }

    /**
     * Send status notification
     *
     * @param object $inquiry    The inquiry object
     * @param string $new_status The new status
     * @return void
     */
    private function send_status_notification($inquiry, $new_status) {
        $to = $inquiry->customer_email;
        $subject = sprintf('Update on your inquiry #%d', $inquiry->id);

        $status_messages = [
            'pending' => 'Your inquiry is pending review by our support team.',
            'in_progress' => 'Our support team is currently working on your inquiry.',
            'resolved' => 'Your inquiry has been resolved. Please let us know if you need any further assistance.',
        ];

        $message = "Hello,\n\n";
        $message .= 'The status of your inquiry has been updated to: ' . strtoupper($new_status) . "\n\n";
        $message .= $status_messages[ $new_status ] . "\n\n";
        $message .= 'Order Number: ' . $inquiry->order_number . "\n";
        $message .= 'Original Inquiry: ' . $inquiry->note . "\n\n";
        $message .= "Best regards,\n";
        $message .= get_bloginfo('name');

        wp_mail($to, $subject, $message);
    }

    /**
     * Create new conversation
     *
     * Creates a new chat conversation with user details
     *
     * @param WP_REST_Request $request The incoming request object
     *
     * @since 1.0.0
     *
     * @return array New conversation details
     */
    public function create_conversation( $request ) {
        $user_id = get_current_user_id();
        $params = $request->get_params();
        
        $conversation_data = [
            'user_id' => $user_id ? $user_id : null,
            'user_name' => $user_id ? wp_get_current_user()->display_name : null,
            'user_email' => $user_id ? wp_get_current_user()->user_email : null,
            'ip_address' => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : null,
            'user_agent' => sanitize_text_field(isset($params['userAgent']) ? $params['userAgent'] : ''),
            'page_url' => esc_url_raw(isset($params['url']) ? $params['url'] : ''),
            'platform' => 'web',
            'city' => isset($params['city']) ? sanitize_text_field($params['city']) : null,
            'country' => isset($params['country']) ? sanitize_text_field($params['country']) : null,
            'country_code' => isset($params['country_code']) ? sanitize_text_field($params['country_code']) : null,
            'intents' => json_encode([]),
        ];

        try {
            $conversation_id = $this->chat_storage->create_conversation($conversation_data);
            
            // Verify the conversation was created
            $created_conversation = $this->chat_storage->get_conversation($conversation_id);
            
            if (!$created_conversation) {
                return new WP_Error('conversation_creation_failed', 'Failed to verify conversation creation');
            }

            // Add welcome message
            // $settings = get_option('aisk_settings');
            // $welcome_message = isset($settings['general']['welcome_message']) 
            //     ? $settings['general']['welcome_message'] 
            //     : 'Hello! How can I assist you today?';
            
            // $this->chat_storage->add_message($conversation_id, 'bot', $welcome_message);
            
            return [
                'conversation_id' => $conversation_id,
                'created_at' => gmdate('c'),
            ];
        } catch (Exception $e) {
            return new WP_Error('conversation_creation_failed', $e->getMessage());
        }
    }

    /**
     * Update conversation intents
     *
     * @param string $conversation_id The conversation ID
     * @param array  $intents         The intents to store
     * @return int|false Number of rows affected or false on error
     */
    public function update_conversation_intents($conversation_id, $intents) {
        // Generate cache key
        $cache_key = 'aisk_conversation_intents_' . $conversation_id;
        
        // @codingStandardsIgnoreStart
        global $wpdb;
        $result = $wpdb->update(
            $wpdb->prefix . 'aisk_conversations',
            [
                'intents' => json_encode($intents),
                'updated_at' => gmdate('c'),
            ],
            ['conversation_id' => $conversation_id]
        );
        // @codingStandardsIgnoreEnd

        if ($result !== false) {
            // Clear the cache after successful update
            wp_cache_delete($cache_key, 'aisk_conversations');
            wp_cache_delete('aisk_conversations_' . md5(serialize(['conversation_id' => $conversation_id])), 'aisk_conversations');
        }

        return $result;
    }

    /**
     * Get conversations
     *
     * @param WP_REST_Request $request The incoming request object
     * @return array List of conversations
     */
    public function get_conversations($request) {
        global $wpdb;

        $page = max(1, intval($request->get_param('page')));
        $per_page = max(10, intval($request->get_param('per_page')));
        $location_filter = sanitize_text_field($request->get_param('location_filter'));
        $time_filter = sanitize_text_field($request->get_param('time_filter'));

        // Generate cache key based on request parameters
        $cache_key = 'aisk_conversations_' . md5(serialize([
            'page' => $page,
            'per_page' => $per_page,
            'location' => $location_filter,
            'time' => $time_filter,
            'user_id' => get_current_user_id(),
            'is_admin' => current_user_can('manage_options')
        ]));

        // Try to get cached results
        $cached_data = wp_cache_get($cache_key, 'aisk_conversations');
        if (false !== $cached_data) {
            return $cached_data;
        }

        if (current_user_can('manage_options')) {
            // Admin query
            $query = "SELECT * FROM {$wpdb->prefix}aisk_conversations WHERE 1=1";
            $params = [];

            if ($location_filter !== 'all' && !empty($location_filter)) {
                $query .= " AND LOWER(city) = LOWER(%s)";
                $params[] = $location_filter;
            }
            if (!empty($time_filter)) {
                $query .= " AND created_at >= DATE_SUB(NOW(), INTERVAL %s DAY)";
                $params[] = intval(str_replace('days', '', $time_filter));
            }

            // @codingStandardsIgnoreStart
            $total_query = str_replace("SELECT *", "SELECT COUNT(*)", $query);
            $total = !empty($params) ? $wpdb->get_var($wpdb->prepare($total_query, ...$params)) : $wpdb->get_var($total_query);
            
            $query .= " ORDER BY created_at DESC LIMIT %d OFFSET %d";
            $params[] = $per_page;
            $params[] = ($page - 1) * $per_page;

            $conversations = !empty($params) ? $wpdb->get_results($wpdb->prepare($query, ...$params)) : $wpdb->get_results($query);

            $locations_query = "SELECT DISTINCT city FROM {$wpdb->prefix}aisk_conversations WHERE city IS NOT NULL AND city != '' ORDER BY city ASC";
            $locations = $wpdb->get_col($locations_query);
            // @codingStandardsIgnoreEnd

            $result = [
                'conversations' => $conversations,
                'total' => intval($total),
                'pages' => ceil($total / $per_page),
                'current_page' => $page,
                'locations' => $locations,
            ];
        } else {
            // For non-admin users
            $user_id = get_current_user_id();
            $ip_address = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
            $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
            
            $query = "SELECT * FROM {$wpdb->prefix}aisk_conversations WHERE ";
            $params = [];
            
            if ($user_id) {
                $query .= "user_id = %d";
                $params[] = $user_id;
            } else {
                $query .= "(ip_address = %s AND user_agent = %s)";
                $params[] = $ip_address;
                $params[] = $user_agent;
            }
            
            if (!empty($time_filter)) {
                $query .= " AND created_at >= DATE_SUB(NOW(), INTERVAL %s DAY)";
                $params[] = intval(str_replace('days', '', $time_filter));
            }
            
            if ($location_filter !== 'all' && !empty($location_filter)) {
                $query .= " AND LOWER(city) = LOWER(%s)";
                $params[] = $location_filter;
            }
            
            // @codingStandardsIgnoreStart
            $total_query = str_replace("SELECT *", "SELECT COUNT(*)", $query);
            $total = $wpdb->get_var($wpdb->prepare($total_query, ...$params));
            
            $query .= " ORDER BY created_at DESC LIMIT %d OFFSET %d";
            $params[] = $per_page;
            $params[] = ($page - 1) * $per_page;
            
            $conversations = $wpdb->get_results($wpdb->prepare($query, ...$params));
            // @codingStandardsIgnoreEnd
            
            $result = [
                'conversations' => $conversations ? $conversations : [],
                'total' => intval($total),
                'pages' => ceil($total / $per_page),
                'current_page' => $page,
            ];
        }

        // Cache the results for 5 minutes
        wp_cache_set($cache_key, $result, 'aisk_conversations', 300);

        return $result;
    }

    /**
     * Get single conversation
     *
     * Retrieves details of a specific conversation
     *
     * @param WP_REST_Request $request The incoming request object
     *
     * @since 1.0.0
     *
     * @return array|WP_Error Conversation details or error
     */
    public function get_conversation( $request ) {
        $conversation_id = $request->get_param('id');
        $conversation = $this->chat_storage->get_conversation($conversation_id);

        if ( ! $conversation ) {
            return new WP_Error(
                'conversation_not_found',
                'Conversation not found',
                [ 'status' => 404 ]
            );
        }

        // Check if user has access to this conversation
        if ( ! current_user_can('manage_options') ) {
            $user_id = get_current_user_id();
            $ip_address = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
            $user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

            // For authenticated users, check user_id
            if ($user_id) {
                if ($conversation->user_id !== $user_id) {
                    return new WP_Error(
                        'unauthorized',
                        'You do not have permission to view this conversation',
                        [ 'status' => 403 ]
                    );
                }
            } else {
                // For unauthenticated users, check both IP and user agent
                if ($conversation->ip_address !== $ip_address || $conversation->user_agent !== $user_agent) {
                    return new WP_Error(
                        'unauthorized',
                        'You do not have permission to view this conversation',
                        [ 'status' => 403 ]
                    );
                }
            }
        }

        return $conversation;
    }

    /**
     * Get conversation messages
     *
     * Retrieves messages for a specific conversation
     *
     * @param WP_REST_Request $request The incoming request object
     *
     * @since 1.0.0
     *
     * @return array|WP_Error List of messages or error
     */
    public function get_messages($request) {
        $conversation_id = $request->get_param('conversation_id');
        if (empty($conversation_id)) {
            return new WP_Error(
                'missing_conversation_id',
                'Conversation ID is required',
                ['status' => 400]
            );
        }

        // Verify access to the conversation
        if (!$this->verify_conversation_access($request)) {
            return new WP_Error(
                'unauthorized',
                'You do not have access to this conversation',
                ['status' => 401]
            );
        }

        $messages = $this->chat_storage->get_messages($conversation_id);
        
        if (empty($messages)) {
            return [];
        }

        return $messages;
    }

    /**
     * Handle product search
     *
     * Processes product search requests and returns matching products
     *
     * @param array $intent The classified intent data
     *
     * @since 1.0.0
     *
     * @return array Search response with products
     */

    private function handle_product_search( $intent, $message, $content_type = '' ) {
        // Check if FluentCart is enabled
        $settings = get_option('aisk_settings');
        if (empty($settings['ai_config']['fluentcart_enabled'])) {
            return [
                'message' => __("Product search is currently disabled. Please enable FluentCart integration in the settings.", 'aisk-ai-chat-for-fluentcart'),
                'products' => []
            ];
        }

        // Get relevant content from embeddings with lower threshold for better product discovery
        $similar_ids = $this->embeddings_handler->find_similar_content( $message, 5, 0.2, $content_type, 'product_search' );
        $products = $this->product_handler->search_products($similar_ids);

        if ( empty($products) ) {
            $default_message = sprintf(
                /* translators: %s: product category or 'products' */
                /* translators: %s: Content type (e.g., products, articles) */
            __("I couldn't find any %s matching your request. Could you please try describing what you're looking for in a different way?", 'aisk-ai-chat-for-fluentcart'),
                isset($intent['category']) ? $intent['category'] : __('products', 'aisk-ai-chat-for-fluentcart')
            );

                    $message = isset($intent['responses']['not_found']) ? $intent['responses']['not_found'] : $default_message;
        $formatted_message = AISK_Response_Formatter::format_response($message);
        
        return [
            'message' => $message,
            'products' => [],
        ];
        }

        $message = isset($intent['responses']['found']) ? $intent['responses']['found'] : __('Awesome! Here are some products you might like:', 'aisk-ai-chat-for-fluentcart');
        $formatted_message = AISK_Response_Formatter::format_response($message);
        
        return [
            'message' => $message,
            'products' => $products,
        ];
    }

    /**
     * Handle order requests
     *
     * Processes order-related requests and returns order information
     *
     * @param array $intent The classified intent data
     *
     * @since 1.0.0
     *
     * @return array Order response data
     */
    private function handle_order_status_request( $intent ) {
        $response = $this->order_handler->get_order_status($intent);

        if ( 'order_verified' !== $response['type'] ) {
            return [
                'message' => $response['message'],
                'order' => $response,
            ];
        }

        // Format order details for OrderStatus component
        $order_info = $response['order_info'];
        $formatted_order = [
            'order_number' => $order_info['order_number'],
            'status' => $order_info['status'],
            'date_created' => $order_info['date_created'],
            'total' => $order_info['total'],
            'shipping_method' => $order_info['shipping_method'],
            'shipping_address' => $order_info['shipping_address'],
            'tracking_number' => $order_info['tracking_number'] ?? '',
            'items' => array_map(function($item) {
                return [
                    'name' => $item['name'],
                    'quantity' => $item['quantity'],
                    'total' => $item['total'],
                    'image' => $item['image'] ?? ''
                ];
            }, $order_info['items'] ?? [])
        ];

        $order_message = $this->order_handler->format_order_status_response($response);
        $formatted_order_message = AISK_Response_Formatter::format_response($order_message);
        
        return [
            'message' => $formatted_order_message,
            'order' => [
                'type' => 'order_verified',
                'order_info' => $formatted_order
            ]
        ];
    }

    /**
     * Handle general query
     *
     * Processes general chat queries using AI
     *
     * @param string $message         The user's message
     * @param string $conversation_id The conversation ID
     *
     * @since 1.0.0
     *
     * @return array Response message
     */
    private function handle_general_query( $message, $conversation_id, $content_type = '', $platform = 'web' ) {
        try {
            $response = $this->get_ai_response( $message, $conversation_id, $content_type, $platform );
            if (is_wp_error($response)) {
                return [
                    'message' => $response->get_error_message()
                ];
            }
            return $response;
        } catch ( Exception $e ) {
            return [
                'message' => "I apologize, but I'm having trouble processing your request. Please try again in a moment.",
            ];
        }
    }

    private function handle_product_info_search( $message, $conversation_id, $content_type = '', $platform = 'web' ) {
        try {
            // First, try with 'product' content type only
            $primary_content_types = ['product'];
            $response = $this->get_ai_response($message, $conversation_id, $primary_content_types, $platform);
            // If the response is empty or generic, fallback to broader content types
            $no_info_phrases = [
                "I'm sorry, but I don't have specific information",
                "I don't have specific information",
                "I recommend checking",
                "I apologize, but I'm having trouble processing your request. Please try again in a moment."
            ];
            $is_empty = false;
            if (is_array($response) && isset($response['message'])) {
                foreach ($no_info_phrases as $phrase) {
                    if (stripos($response['message'], $phrase) !== false) {
                        $is_empty = true;
                        break;
                    }
                }
            } elseif (is_string($response)) {
                foreach ($no_info_phrases as $phrase) {
                    if (stripos($response, $phrase) !== false) {
                        $is_empty = true;
                        break;
                    }
                }
            }
            // If empty or generic, fallback to all content types
            if ($is_empty || empty($response) || (is_array($response) && empty($response['message']))) {
                $fallback_content_types = ['external_url', 'post', 'page', 'pdf', 'webpage'];
                $response = $this->get_ai_response($message, $conversation_id, $fallback_content_types, $platform);
            }
            return is_array($response) ? $response : [ 'message' => $response ];
        } catch ( Exception $e ) {
            return [
                'message' => "I apologize, but I'm having trouble processing your request. Please try again in a moment.",
            ];
        }
    }

    /**
     * Get AI response
     *
     * Retrieves AI-generated response for a message
     *
     * @param string $message         The user's message
     * @param string $conversation_id The conversation ID
     *
     * @since 1.0.0
     *
     * @return array|WP_Error AI response or error
     */
    private function get_ai_response( $message, $conversation_id, $content_type = '', $platform = 'web' ) {
        try {
            // Check for cached response for simple queries (no conversation history needed)
            $cache_key = 'aisk_ai_response_' . md5($message . $platform);
            $cached_response = wp_cache_get($cache_key, 'aisk_ai_responses');
            
            // Only use cache for simple queries without conversation context
            $history = $this->chat_storage->get_recent_message_history($conversation_id, 5);
            if (empty($history) && $cached_response) {
                return $cached_response;
            }
            
            $conversation_context = '';
            
            // Format conversation history
            foreach ($history as $msg) {
                $role = $msg['bot'] ? 'Assistant' : 'User';
                $conversation_context .= "{$role}: {$msg['message']}\n";
            }

            // Get relevant content with lower similarity threshold
            $similar_content = $this->embeddings_handler->find_similar_content(
                $message,  
                6,        
                0.3,      // Lowered threshold for better matches
                $content_type,
                ''        
            );

            // Enhanced context preparation
            $content_context = '';
            if (!empty($similar_content)) {
                // Sort content by similarity score if available
                if (isset($similar_content[0]['similarity_score'])) {
                    usort($similar_content, function($a, $b) {
                        $scoreA = isset($a['similarity_score']) ? $a['similarity_score'] : 0;
                        $scoreB = isset($b['similarity_score']) ? $b['similarity_score'] : 0;
                        return $scoreB <=> $scoreA;
                    });
                }

                // Add most relevant content first
                foreach ($similar_content as $content) {
                    if (!isset($content['content_type']) || !isset($content['content_chunk'])) {
                        continue; // Skip invalid entries
                    }

                    if ($content['content_type'] === 'settings') {
                        // For settings, directly use the content as it contains structured information
                        $content_context .= $content['content_chunk'] . "\n\n";
                    } else {
                        $content_context .= "Source ({$content['content_type']}): " . $content['content_chunk'] . "\n\n";
                    }
                }
            }

            // Prepare system message with enhanced context and instructions
            $system_message = "You are a helpful AI assistant for an e-commerce website. ";
            $system_message .= "Use the following information to answer the user's question accurately and directly:\n\n";
            $system_message .= $content_context;
            $system_message .= "\nPrevious conversation:\n" . $conversation_context;
            $system_message .= "\nImportant instructions:";
            $system_message .= "\n1. For general conversation (greetings, small talk):";
            $system_message .= "\n   - Use friendly, casual responses";
            $system_message .= "\n   - Match the user's tone and formality level";
            $system_message .= "\n   - Keep it light and welcoming";
            $system_message .= "\n2. For general inquiries (store policies, information):";
            $system_message .= "\n   - Use informative, helpful responses";
            $system_message .= "\n   - Be clear about what information you're providing";
            $system_message .= "\n   - Include relevant details from the context";
            $system_message .= "\n3. If the question is about store information (hours, contact, etc), use ONLY the provided store information.";
            $system_message .= "\n4. Give direct, specific answers based on the provided information.";
            $system_message .= "\n5. Do not make assumptions or provide generic responses when specific information is available.";
            $system_message .= "\n6. If specific information is not found in the context, acknowledge that and suggest contacting support.";
            $system_message .= "\n7. For contact support queries:";
            $system_message .= "\n   - If user needs to submit a form (account issues, technical problems, urgent assistance), set response_type to 'form'";
            $system_message .= "\n   - If user just needs information (store hours, location, general contact), set response_type to 'info'";
            if (in_array($platform, ['telegram', 'whatsapp'])) {
            } else {
                $system_message .= "\n - When providing a single-line response, wrap the primary answer in 'others **main answer** others'. If the response contains multiple important text segments (one or two key phrases), wrap each in **bold** for emphasis.";
            }

            // Prepare messages for API with enhanced instructions
            $messages = [
                ['role' => 'system', 'content' => $system_message],
                ['role' => 'user', 'content' => $message]
            ];

            // Call OpenAI API with adjusted parameters
            $request_id = uniqid('chat_', true);
            $start_time = microtime(true);
            $response = wp_remote_post(AISK_OPENAI_API_URL . '/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->openai_key,
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode([
                    'model' => 'gpt-4o-mini',
                    'messages' => $messages,
                    'temperature' => 0.3, // Lowered for more consistent responses
                    'max_tokens' => 500,
                    'presence_penalty' => 0.3, // Adjusted for better context adherence
                    'frequency_penalty' => 0.3
                ]),
                'timeout' => 30
            ]);

            if (is_wp_error($response)) {
                if ($this->usage_tracker) {
                    $latency_ms = (int) ((microtime(true) - $start_time) * 1000);
                    $this->usage_tracker->log_chat_usage(
                        $request_id,
                        $conversation_id,
                        'openai',
                        'gpt-4o-mini',
                        'error',
                        $latency_ms,
                        strlen($message) > 0 ? intval(strlen($message) / 4) : 0,
                        0,
                        'request_failed'
                    );
                }
                return new WP_Error('api_error', 'Failed to generate response');
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);
            
            if (empty($body['choices'][0]['message']['content'])) {
                if ($this->usage_tracker) {
                    $latency_ms = (int) ((microtime(true) - $start_time) * 1000);
                    $this->usage_tracker->log_chat_usage(
                        $request_id,
                        $conversation_id,
                        'openai',
                        'gpt-4o-mini',
                        'error',
                        $latency_ms,
                        strlen($message) > 0 ? intval(strlen($message) / 4) : 0,
                        0,
                        'empty_content'
                    );
                }
                return new WP_Error('api_error', 'Invalid response from API');
            }

            if (isset($body['error'])) {
                $body['choices'][0]['message']['content'] = $body['error']['message'];
            }

            $ai_response = $body['choices'][0]['message']['content'];
            $usage = isset($body['usage']) ? $body['usage'] : null;
            $prompt_tokens = isset($usage['prompt_tokens']) ? intval($usage['prompt_tokens']) : (strlen($message) > 0 ? intval(strlen($message) / 4) : 0);
            $completion_tokens = isset($usage['completion_tokens']) ? intval($usage['completion_tokens']) : (strlen($ai_response) > 0 ? intval(strlen($ai_response) / 4) : 0);

            // Log successful usage
            if ($this->usage_tracker) {
                $latency_ms = (int) ((microtime(true) - $start_time) * 1000);
                $tokens_in = $prompt_tokens;
                $tokens_out = $completion_tokens;
                $this->usage_tracker->log_chat_usage(
                    $request_id,
                    $conversation_id,
                    'openai',
                    'gpt-4o-mini',
                    'success',
                    $latency_ms,
                    $tokens_in,
                    $tokens_out,
                    null
                );
            }

            // Format the response using the Response Formatter
            $formatted_response = AISK_Response_Formatter::format_response($ai_response);

            // Prepare response based on platform
            $response_data = [];
            if (in_array($platform, ['telegram', 'whatsapp'])) {
                $response_data = [
                    'message' => $ai_response,
                    'content_type' => $content_type
                ];
            } else {
                $response_data = [
                    'message' => $formatted_response,
                    'content_type' => $content_type
                ];
            }

            // Cache simple responses (without conversation history) for faster future responses
            if (empty($history)) {
                wp_cache_set($cache_key, $response_data, 'aisk_ai_responses', 300); // Cache for 5 minutes
            }

            return $response_data;

        } catch (Exception $e) {
            return new WP_Error('response_error', 'Failed to generate response: ' . $e->getMessage());
        }
    }

    /**
     * Prepare context for AI
     *
     * Formats content for AI context
     *
     * @param array $similar_content Array of similar content
     *
     * @since 1.0.0
     *
     * @return string Formatted context
     */
    private function prepare_context($similar_content) {
        $context = '';
        
        // Group content by type
        $grouped_content = [];
        foreach ($similar_content as $content) {
            $type = $content['content_type'];
            if (!isset($grouped_content[$type])) {
                $grouped_content[$type] = [];
            }
            $grouped_content[$type][] = $content;
        }

        // Add content type headers and format content
        foreach ($grouped_content as $type => $contents) {
            switch ($type) {
                case 'product':
                    $context .= esc_html__('Product Information:', 'aisk-ai-chat-for-fluentcart') . "\n";
                    foreach ($contents as $content) {
                        $context .= "- " . esc_html($content['content_chunk']) . "\n";
                    }
                    break;
                case 'order':
                    $context .= esc_html__('Order Information:', 'aisk-ai-chat-for-fluentcart') . "\n";
                    foreach ($contents as $content) {
                        $context .= "- " . esc_html($content['content_chunk']) . "\n";
                    }
                    break;
                case 'pdf':
                    $context .= esc_html__('Document Information:', 'aisk-ai-chat-for-fluentcart') . "\n";
                    foreach ($contents as $content) {
                        $context .= "- " . esc_html($content['content_chunk']) . "\n";
                    }
                    break;
                case 'external_url':
                    $context .= esc_html__('External Resource Information:', 'aisk-ai-chat-for-fluentcart') . "\n";
                    foreach ($contents as $content) {
                        $context .= "- " . esc_html($content['content_chunk']) . "\n";
                    }
                    break;
                case 'post':
                    $context .= esc_html__('Blog Post Information:', 'aisk-ai-chat-for-fluentcart') . "\n";
                    foreach ($contents as $content) {
                        $context .= "- " . esc_html($content['content_chunk']) . "\n";
                    }
                    break;
                case 'page':
                    $context .= esc_html__('Page Information:', 'aisk-ai-chat-for-fluentcart') . "\n";
                    foreach ($contents as $content) {
                        $context .= "- " . esc_html($content['content_chunk']) . "\n";
                    }
                    break;
                case 'settings':
                    $context .= esc_html__('Store Information:', 'aisk-ai-chat-for-fluentcart') . "\n";
                    foreach ($contents as $content) {
                        $context .= "- " . esc_html($content['content_chunk']) . "\n";
                    }
                    break;
                default:
                    $context .= esc_html__('Additional Information:', 'aisk-ai-chat-for-fluentcart') . "\n";
                    foreach ($contents as $content) {
                        $context .= "- " . esc_html($content['content_chunk']) . "\n";
                    }
            }
            $context .= "\n";
        }

        return $context;
    }

    /**
     * Internal method to classify intent (for internal use)
     *
     * @param string $message
     * @param string $conversation_id
     * @return array|WP_Error
     */
    private function classify_intent_internal($message, $conversation_id) {
        // Get recent message history
        $message_history = $this->chat_storage->get_recent_message_history($conversation_id);
        
        // Create a mock request object
        $request = new WP_REST_Request('POST', '/aisk/v1/classify-intent');
        $request->set_param('message', $message);
        $request->set_param('conversation_history', $message_history);
        
        // Call the public method
        $response = $this->classify_intent($request);
        
        // If it's a WP_Error, return it directly
        if (is_wp_error($response)) {
            return $response;
        }
        
        // If it's a WP_REST_Response, get the data
        if ($response instanceof WP_REST_Response) {
            return $response->get_data();
        }
        
        // Otherwise return as is
        return $response;
    }

    /**
     * Classify intent for a given message
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function classify_intent($request) {
        $message = $request->get_param('message');
        $conversation_history = $request->get_param('conversation_history');
        
        // Get OpenAI key from settings
        if (empty($this->openai_key)) {
            return new WP_Error(
                'openai_key_missing',
                'OpenAI key is required',
                ['status' => 401]
            );
        }

        try {
            // Format conversation history with clear role labels
            $formatted_history = $this->format_conversation_history($conversation_history);
            
            // Create a focused system message
            $system_message = [
                'role' => 'system',
                'content' => $this->get_classify_system_prompt()
            ];
    
            // Add example exchanges to help with few-shot learning
            $few_shot_examples = [
                [
                    'role' => 'user',
                    'content' => 'Show me some blue sunglasses under $100'
                ],
                [
                    'role' => 'assistant',
                    'content' => json_encode([
                        'intent_type' => 'product_search',
                        'search_term' => 'blue',
                        'category' => 'sunglasses',
                        'filters' => [
                            'price_max' => 100,
                            'color' => 'blue'
                        ],
                        'responses' => [
                            'found' => 'I found some stylish blue sunglasses under $100 that you might like.',
                            'not_found' => 'I couldn\'t find any blue sunglasses in that price range, but I can show you other options.'
                        ]
                    ])
                ],
                [
                    'role' => 'user',
                    'content' => 'Hi there!'
                ],
                [
                    'role' => 'assistant',
                    'content' => json_encode([
                        'intent_type' => 'general_conversation',
                        'response'=> 'Hello! How can I help you today?',
                        'content_type' => ['settings']
                    ])
                ],
                [
                    'role' => 'user',
                    'content' => 'What\'s your return policy?'
                ],
                [
                    'role' => 'assistant',
                    'content' => json_encode([
                        'intent_type' => 'general_inquiries',
                        'query_topic'=> 'return policy',
                        'content_type'=> ['settings', 'page'],
                        'response'=> 'Let me find our return policy information for you.'
                    ])
                ],
                [
                    'role' => 'user',
                    'content' => 'I needs support via form'
                ],
                [
                    'role' => 'assistant',
                    'content' => json_encode([
                        'intent_type' => 'contact_support',
                        'query_topic'=> 'contact support',
                        'response'=> 'your response here',
                        'response_type'=> 'form'
                    ])
                ]
            ];
    
            // Combine all messages
            $messages = [
                $system_message,
                ...$few_shot_examples,
                ...$formatted_history,
                [
                    'role' => 'user',
                    'content' => $message
                ]
            ];
    
            $clf_request_id = uniqid('clf_', true);
            $clf_start = microtime(true);
            $response = wp_remote_post(AISK_OPENAI_API_URL . '/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->openai_key,
                    'Content-Type' => 'application/json'
                ],
                'body' => json_encode([
                    'model' => 'gpt-3.5-turbo',
                    'messages' => $messages,
                    'temperature' => 0.3,
                    'max_tokens' => 500,
                    'response_format' => ['type' => 'json_object']
                ]),
                'timeout' => 15
            ]);
    
            if (is_wp_error($response)) {
                if ($this->usage_tracker) {
                    $this->usage_tracker->log_classify_usage(
                        $clf_request_id,
                        $conversation_id,
                        'openai',
                        'gpt-3.5-turbo',
                        'error',
                        (int)((microtime(true) - $clf_start) * 1000),
                        strlen($message) > 0 ? intval(strlen($message) / 4) : 0,
                        0,
                        'request_failed'
                    );
                }
                return new WP_Error(
                    'openai_request_failed',
                    'Failed to connect to OpenAI: ' . esc_html( $response->get_error_message() ),
                    ['status' => 502]
                );
            }
    
            $response_code = wp_remote_retrieve_response_code($response);
            if ($response_code !== 200) {
                if ($this->usage_tracker) {
                    $this->usage_tracker->log_classify_usage(
                        $clf_request_id,
                        $conversation_id,
                        'openai',
                        'gpt-3.5-turbo',
                        'error',
                        (int)((microtime(true) - $clf_start) * 1000),
                        strlen($message) > 0 ? intval(strlen($message) / 4) : 0,
                        0,
                        'api_error'
                    );
                }
                return new WP_Error(
                    'openai_api_error',
                    'OpenAI API error: ' . wp_remote_retrieve_body($response),
                    ['status' => $response_code]
                );
            }
    
            $body = json_decode(wp_remote_retrieve_body($response), true);
            $content = $body['choices'][0]['message']['content'];
            $clf_usage = isset($body['usage']) ? $body['usage'] : null;
            $clf_prompt_tokens = isset($clf_usage['prompt_tokens']) ? intval($clf_usage['prompt_tokens']) : (strlen($message) > 0 ? intval(strlen($message) / 4) : 0);
            $clf_completion_tokens = isset($clf_usage['completion_tokens']) ? intval($clf_usage['completion_tokens']) : (strlen($content) > 0 ? intval(strlen($content) / 4) : 0);
    
            // Validate and clean the response
            if (empty($content)) {
                if ($this->usage_tracker) {
                    $this->usage_tracker->log_classify_usage(
                        $clf_request_id,
                        $conversation_id,
                        'openai',
                        'gpt-3.5-turbo',
                        'error',
                        (int)((microtime(true) - $clf_start) * 1000),
                        strlen($message) > 0 ? intval(strlen($message) / 4) : 0,
                        0,
                        'empty_content'
                    );
                }
                return new WP_Error('empty_response', 'Empty response from OpenAI', ['status' => 502]);
            }
    
            if (isset($content)) {
                $content = trim($content);
                if (strpos($content, '{') !== 0) {
                    return new WP_Error('invalid_json', 'OpenAI response is not valid JSON', ['status' => 502]);
                }
                $parsed = json_decode($content, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    // Ensure response_type is always present
                    if (!isset($parsed['response_type'])) {
                        // Determine response_type based on intent_type
                        if ($parsed['intent_type'] === 'product_search') {
                            $parsed['response_type'] = 'info';
                        } elseif ($parsed['intent_type'] === 'contact_support') {
                            $parsed['response_type'] = 'form';
                        } elseif ($parsed['intent_type'] === 'general_inquiries') {
                            $parsed['response_type'] = 'info';
                        } else {
                            $parsed['response_type'] = 'info'; // default fallback
                        }
                    }
                    
                    if($parsed['intent_type'] === 'product_search') {
                        $format_product_query = $this->format_product_query($parsed);
                        return rest_ensure_response([
                            'intent_type' => $parsed['intent_type'],
                            'response_type' => $parsed['response_type'],
                            'responses' => $parsed['responses']
                        ]);
                    }
                    if ($this->usage_tracker) {
                        $this->usage_tracker->log_classify_usage(
                            $clf_request_id,
                            $conversation_id,
                            'openai',
                            'gpt-3.5-turbo',
                            'success',
                            (int)((microtime(true) - $clf_start) * 1000),
                            $clf_prompt_tokens,
                            $clf_completion_tokens,
                            null
                        );
                    }
                    return rest_ensure_response($parsed);
                }
            }

            return new WP_Error(
                'invalid_response',
                'Invalid response from OpenAI',
                ['status' => 502]
            );
    
        } catch (Exception $e) {
            return new WP_Error(
                'intent_classification_failed',
                'Exception while classifying intent: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Format conversation history for OpenAI API
     *
     * @param array $history
     * @return array
     */
    private function format_conversation_history($history) {
        $formatted = [];
        foreach ($history as $msg) {
            $formatted[] = [
                'role' => $msg['bot'] == 'bot' ? 'assistant' : 'user',
                'content' => $msg['message']
            ];
        }
        return $formatted;
    }

    /**
     * Format product query parameters for FluentCart
     *
     * @param array $params
     * @return array
     */
    private function format_product_query($params){
        $args = [
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => 6,
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
            'tax_query' => [],
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
            'meta_query' => []
        ];

        // Add search term
        if (!empty($params['search_term'])) {
            $args['s'] = $params['search_term'];
        }

        // Add category
        if (!empty($params['category'])) {
            $args['tax_query'][] = [
                'taxonomy' => 'product_cat',
                'field' => 'name',
                'terms' => $params['category'],
                'operator' => 'LIKE'
            ];
        }

        // Add price range
        if (!empty($params['price_min']) || !empty($params['price_max'])) {
            $price_query = ['relation' => 'AND'];

            if (!empty($params['price_min'])) {
                $price_query[] = [
                    'key' => '_price',
                    'value' => $params['price_min'],
                    'compare' => '>=',
                    'type' => 'NUMERIC'
                ];
            }

            if (!empty($params['price_max'])) {
                $price_query[] = [
                    'key' => '_price',
                    'value' => $params['price_max'],
                    'compare' => '<=',
                    'type' => 'NUMERIC'
                ];
            }

            $args['meta_query'][] = $price_query;
        }

        // Add color attribute if specified
        if (!empty($params['color'])) {
            $args['tax_query'][] = [
                'taxonomy' => 'pa_color',
                'field' => 'name',
                'terms' => $params['color'],
                'operator' => 'LIKE'
            ];
        }

        // Add brand if specified
        if (!empty($params['brand'])) {
            $args['tax_query'][] = [
                'taxonomy' => 'pa_brand',
                'field' => 'name',
                'terms' => $params['brand'],
                'operator' => 'LIKE'
            ];
        }

        // Add sorting
        if (!empty($params['sort_by'])) {
            switch ($params['sort_by']) {
                case 'price_low':
                    $args['orderby'] = 'meta_value_num';
                    // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                    $args['meta_key'] = '_price';
                    $args['order'] = 'ASC';
                    break;
                case 'price_high':
                    $args['orderby'] = 'meta_value_num';
                    // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                    $args['meta_key'] = '_price';
                    $args['order'] = 'DESC';
                    break;
                case 'newest':
                    $args['orderby'] = 'date';
                    $args['order'] = 'DESC';
                    break;
                case 'popularity':
                    $args['orderby'] = 'meta_value_num';
                    // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                    $args['meta_key'] = 'total_sales';
                    $args['order'] = 'DESC';
                    break;
            }
        }

        return $args;
    }

    /**
     * Get system prompt for intent classification
     *
     * @return string
     */
    private function get_classify_system_prompt() {
        return 'You are a context-aware shopping assistant. For each query, analyze the intent and if it\'s a product search, extract search parameters. Respond in JSON format with these fields:

{
    "intent_type": "product_search|order_status|contact_support|general_inquiries",
    "response_type": "form|info",
    "search_term": "extract only specific descriptive terms, exclude category",
    "category": "product category (sunglasses, glasses, shirts, pants etc.)",
    "price_min": "minimum price if mentioned (number only)",
    "price_max": "maximum price if mentioned (number only)",
    "color": "color if mentioned",
    "size": "size if mentioned",
    "brand": "brand if mentioned",
    "sort_by": "price_low|price_high|newest|popularity if mentioned",
    "attributes": {
        "material": "material type if mentioned",
        "style": "style if mentioned",
        "gender": "men|women|kids if mentioned",
        "season": "summer|winter|etc if mentioned"
    },
    "responses": {
        "found": "warm response when products are found. Mention the specific item they asked about.",
        "not_found": "empathetic response when products are not found. Suggest alternatives or ask for clarification."
    }
}

Treat questions like "Do you have X?" or "Are there any X?" as product searches

Example Mappings:
- "Do you have X?" -> Show me X
- "Is X available?" -> Show me X
- "Any X in stock?" -> Show me X
- "Looking for X" -> Show me X


If users ask about orders and intent type is order_status, extract order numbers and email addresses using the contextual information. Pay careful attention to distinguish between order numbers and verification codes (OTPs). Respond in JSON format:

{
    "intent_type": "product_search|order_status|contact_support|general_inquiries",
    "response_type": "form|info",
    "order_info": {
        "order_number": "extract any number that looks like an order number",
        "email": "extract email if mentioned",
        "auth_status": "new|needs_auth|awaiting_otp",
        "otp": "extract any number that is explicitly provided as a verification code",
        "query_type": "status|tracking|cancel|modify"
    },
    "responses": {
        "auth_required": "polite response asking for authentication info",
        "order_status": "friendly response about order status"
    }
}

IMPORTANT: Context-based OTP vs Order Number Detection
- If the previous assistant message mentioned "verification code" or "code" and the user responds with only a number (especially a 6-digit number), interpret it as an OTP, not an order number
- If the conversation context indicates we are in the verification phase (after email was provided), treat numeric input as an OTP
        - Order numbers typically come earlier in the conversation, before an email is provided or verification is mentioned
        - OTPs are almost always 6 digits (e.g., "398355") and appear after a verification request
        - Order numbers are usually shorter (often 3-5 digits, e.g., "140") and appear at the beginning of the order inquiry process
        - If the conversation flow is: user asks about order → assistant asks for order number → user provides number → assistant asks for email → user provides email → assistant mentions verification code → user provides number, then this last number is an OTP, not a new order number

Example order queries:
1. "What\'s the status of order #12345" -> extract order_number: "12345"
2. "Track my order, email is user@example.com" -> extract email
3. "Where is my order?" -> set auth_status: "needs_auth"
4. "Check order 12345 for user@example.com" -> extract both

Sample conversation flow inference:
- If conversation history shows: assistant asked for order number → user provided "140" → assistant asked for email → user provided email → assistant mentioned verification code → user responded with "398355", then "398355" is definitely an OTP, not an order number
        - In this case, maintain "order_number": "140" and set "otp": "398355" with "auth_status": "awaiting_otp"

Rules:
- Always identify main product type as category (sunglasses, glasses, shirts, etc.)
- search_term should exclude the category and only include descriptive terms
- If multiple product types mentioned, use the main one as category
- Common product mappings:
  - sunglass/sunglasses -> category: sunglasses
  - glass/glasses -> category: glasses
  - tshirt/t-shirt -> category: tshirts
  etc.
- Generate both found and not_found responses
- Keep responses conversational and brief
- Use emoji occasionally but appropriately
- found response should express excitement about showing options
- not_found response should be helpful and suggest alternatives

For non-product and order_status searches, include intent_type and response_type. Extract as many parameters as possible from the query. Use null for missing values.

IMPORTANT: Always include response_type in your JSON response. This field is crucial for determining how the frontend should handle the response.';
    }
}

new AISK_Chat_Handler();
