<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Chat Storage Class - Handles database operations for chat functionality
 *
 * @category WordPress_Plugin
 * @package  AISK
 * @author   AISK Team <support@wishcart.chat>
 * @license  GPL-2.0+ https://www.gnu.org/licenses/gpl-2.0.html
 * @link     https://wishcart.chat
 */

/**
 * WISHCART_Chat_Storage Class
 *
 * Handles all database operations for chat conversations and messages
 *
 * @category Class
 * @package  AISK
 * @author   AISK Team <support@wishcart.chat>
 * @license  GPL-2.0+ https://www.gnu.org/licenses/gpl-2.0.html
 * @link     https://wishcart.chat
 */
class WISHCART_Chat_Storage {

    private static $instance = null;
    private $wpdb;
    private $state_table;

    /**
     * Get singleton instance of the class
     *
     * @return WISHCART_Chat_Storage Instance of the class
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->state_table = $this->wpdb->prefix . 'wishcart_user_states';
    }

    /**
     * Creates a new conversation
     *
     * @param array $data Conversation data
     *
     * @since 1.0.0
     *
     * @return string Generated conversation ID
     */
    public function create_conversation( $data ) {
        // Generate a unique conversation ID
        $conversation_id = wp_generate_uuid4();
        
        $insert_data = array_merge(
            [
                'conversation_id' => $conversation_id,
                'created_at' => gmdate('c'),
                'updated_at' => gmdate('c'),
            ], $data
        );
        
        try {
            $result = $this->wpdb->insert(
                $this->wpdb->prefix . 'wishcart_conversations',
                $insert_data
            );
            
            if ($result === false) {
                throw new Exception('Failed to insert conversation: ' . $this->wpdb->last_error);
            }
            
            // Cache the new conversation
            $cache_key = 'wishcart_conversation_' . $conversation_id;
            wp_cache_set($cache_key, $insert_data, 'wishcart_chat_storage', 300);
            
            return $conversation_id;
        } catch (Exception $e) {
            throw $e;
        }
    }

    private function invalidate_conversation_cache($conversation_id) {
        wp_cache_delete('wishcart_messages_' . $conversation_id, 'wishcart_chat');
    }


    /**
     * Adds a message to a conversation
     *
     * @param string $conversation_id The conversation ID
     * @param string $message_type    The type of message
     * @param string $message         The message content
     * @param array  $metadata        Optional metadata for the message
     *
     * @since 1.0.0
     *
     * @return int The inserted message ID
     */
    public function add_message( $conversation_id, $message_type, $message, $metadata = null ) {
        // Validate message type
        if (!in_array($message_type, ['user', 'bot'])) {
            $message_type = 'bot'; // Default to bot if invalid
        }
        
        // Handle message content - ensure it's a string
        $message_content = '';
        if (is_array($message)) {
            if (isset($message['error']) && isset($message['error']['message'])) {
                $message_content = $message['error']['message'];
            } elseif (isset($message['message'])) {
                $message_content = $message['message'];
            } else {
                $message_content = json_encode($message);
            }
        } elseif (is_wp_error($message)) {
            $message_content = $message->get_error_message();
        } else {
            $message_content = (string)$message;
        }

        // Check cache for last message
        $cache_key = 'wishcart_last_message_' . $conversation_id;
        $last_message = wp_cache_get($cache_key, 'wishcart_chat_storage');

        if (false === $last_message) {
            // @codingStandardsIgnoreStart
            global $wpdb;
            $last_message = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}wishcart_messages 
                    WHERE conversation_id = %s 
                    ORDER BY created_at DESC 
                    LIMIT 1",
                    $conversation_id
                )
            );
            wp_cache_set($cache_key, $last_message, 'wishcart_chat_storage', 300);
        }

        // If the last message is identical, don't insert
        if ($last_message && 
            $last_message->message_type === $message_type && 
            $last_message->message === $message_content) {
            return $last_message->id;
        }

        $message_data = [
            'conversation_id' => $conversation_id,
            'message_type' => $message_type,
            'message' => $message_content,
            'metadata' => $metadata ? json_encode($metadata) : null,
            'created_at' => gmdate('c'),
        ];

        $result = $this->wpdb->insert(
            $this->wpdb->prefix . 'wishcart_messages',
            $message_data
        );

        if ($result === false) {
            return false;
        }

        $message_id = $this->wpdb->insert_id;

        // Update conversation's updated_at timestamp
        $this->wpdb->update(
            $this->wpdb->prefix . 'wishcart_conversations',
            ['updated_at' => gmdate('c')],
            ['conversation_id' => $conversation_id]
        );

        // Clear related caches
        wp_cache_delete('wishcart_messages_' . $conversation_id, 'wishcart_chat_storage');
        wp_cache_delete('wishcart_messages_' . $conversation_id . '_15', 'wishcart_chat_storage');
        wp_cache_delete('wishcart_last_message_' . $conversation_id, 'wishcart_chat_storage');
        wp_cache_delete('wishcart_conversation_' . $conversation_id, 'wishcart_chat_storage');

        return $message_id;
    }

    /**
     * Gets a specific conversation by ID
     *
     * @param string $conversation_id The conversation ID
     *
     * @since 1.0.0
     *
     * @return object|null Conversation data or null if not found
     */
    public function get_conversation( $conversation_id ) {
        $cache_key = 'wishcart_conversation_'. $conversation_id;
        $conversation = wp_cache_get($cache_key, 'wishcart_chat_storage');

        if (false === $conversation) {
            // @codingStandardsIgnoreStart
            global $wpdb;
            $conversation = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}wishcart_conversations WHERE conversation_id = %s",
                    $conversation_id
                )
            );
            // @codingStandardsIgnoreEnd

            // Cache the results for 5 minutes
            if ($conversation) {
                wp_cache_set($cache_key, $conversation, 'wishcart_chat_storage', 300);
            } else {
                // Cache null result for 1 minute to prevent repeated DB queries for non-existent records
                wp_cache_set($cache_key, null, 'wishcart_chat_storage', 60);
            }
        }

        return $conversation;
    }

    /**
     * Gets all messages for a conversation
     *
     * @param string $conversation_id The conversation ID
     *
     * @since 1.0.0
     *
     * @return array Array of message objects
     */
    public function get_messages( $conversation_id ) {
        // Try to get from cache first
        $cache_key = 'wishcart_messages_' . $conversation_id;
        $messages = wp_cache_get($cache_key, 'wishcart_chat_storage');
        
        if (false === $messages) {
            global $wpdb;
            // @codingStandardsIgnoreStart
            $messages = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}wishcart_messages WHERE conversation_id = %s ORDER BY created_at ASC",
                    $conversation_id
                )
            );
            // @codingStandardsIgnoreEnd
            
            if ($messages) {
                // Cache the results for 5 minutes
                wp_cache_set($cache_key, $messages, 'wishcart_chat_storage', 300);
            } else {
                // Cache empty result for 1 minute
                wp_cache_set($cache_key, array(), 'wishcart_chat_storage', 60);
            }
        }
        
        return $messages;
    }

    /**
     * Gets conversations for a specific user or IP address
     *
     * @param int    $user_id    Optional user ID
     * @param string $ip_address Optional IP address
     * @param int    $limit      Maximum number of conversations to return
     *
     * @since 1.0.0
     *
     * @return array Array of conversation objects
     */
    public function get_user_conversations( $user_id = null, $ip_address = null, $limit = 10 ) {
        $cache_key = 'wishcart_user_conversations_'. $user_id . '_' . $ip_address . '_' . $limit;
        $conversations = wp_cache_get($cache_key, 'wishcart_chat_storage');
        
        if (false === $conversations) {
            // @codingStandardsIgnoreStart
            global $wpdb;
            $query = "SELECT * FROM {$wpdb->prefix}wishcart_conversations WHERE 1=1";
            $query_args = array();

            if ($user_id && $ip_address) {
                $query .= " AND (user_id = %d OR ip_address = %s)";
                $query_args[] = $user_id;
                $query_args[] = $ip_address;
            } elseif ($user_id) {
                $query .= " AND user_id = %d";
                $query_args[] = $user_id;
            } elseif ($ip_address) {
                $query .= " AND ip_address = %s";
                $query_args[] = $ip_address;
            }

            $query .= " ORDER BY updated_at DESC LIMIT %d";
            $query_args[] = $limit;

            $conversations = $wpdb->get_results(
                $wpdb->prepare($query, $query_args)
            );
            // @codingStandardsIgnoreEnd

            if ($conversations) {
                wp_cache_set($cache_key, $conversations, 'wishcart_chat_storage', 300);
            } else {
                wp_cache_set($cache_key, array(), 'wishcart_chat_storage', 60);
            }
        }

        return $conversations;
    }
    /**
     * Gets all conversations with pagination and filters
     *
     * @param int   $page     Page number
     * @param int   $per_page Items per page
     * @param array $filters  Array of filters
     *
     * @since 1.0.0
     *
     * @return array Array containing conversations, total count and page count
     */
    public function get_all_conversations( $page = 1, $per_page = 20, $filters = [] ) {
        $cache_key = 'wishcart_all_conversations_'. $page. '_'. $per_page. '_'. md5(serialize($filters));
        $result = wp_cache_get($cache_key, 'wishcart_chat_storage');
        
        if (false === $result) {
            // @codingStandardsIgnoreStart
            global $wpdb;
            $offset = ($page - 1) * $per_page;
            $query = "SELECT * FROM {$wpdb->prefix}wishcart_conversations WHERE 1=1";
            $count_query = "SELECT COUNT(*) FROM {$wpdb->prefix}wishcart_conversations WHERE 1=1";
            $query_args = array();

            // Apply location filter
            if (!empty($filters['location_filter']) && 'all' !== $filters['location_filter']) {
                $query .= " AND country = %s";
                $count_query .= " AND country = %s";
                $query_args[] = $filters['location_filter'];
            }

            // Apply time filter
            if (!empty($filters['time_filter'])) {
                $interval = '';
                switch ($filters['time_filter']) {
                    case '7days':
                        $interval = '7 DAY';
                        break;
                    case '30days':
                        $interval = '30 DAY';
                        break;
                    case '90days':
                        $interval = '90 DAY';
                        break;
                }
                if ($interval) {
                    $query .= " AND created_at >= DATE_SUB(NOW(), INTERVAL {$interval})";
                    $count_query .= " AND created_at >= DATE_SUB(NOW(), INTERVAL {$interval})";
                }
            }

            $query .= " ORDER BY created_at DESC LIMIT %d OFFSET %d";
            $query_args[] = $per_page;
            $query_args[] = $offset;

            $conversations = $wpdb->get_results(
                $wpdb->prepare($query, $query_args)
            );

            $total = $wpdb->get_var(
                $wpdb->prepare($count_query, array_slice($query_args, 0, -2))
            );
            // @codingStandardsIgnoreEnd

            $result = [
                'conversations' => $conversations,
                'total' => (int) $total,
                'pages' => ceil($total / $per_page),
            ];

            wp_cache_set($cache_key, $result, 'wishcart_chat_storage', 300);
        }

        return $result;
    }

    /**
     * Closes a conversation
     *
     * @param string $conversation_id The conversation ID
     *
     * @since 1.0.0
     *
     * @return int|false Number of rows affected or false on error
     */
    public function close_conversation( $conversation_id ) {
        // @codingStandardsIgnoreStart
        global $wpdb;
        $result = $wpdb->update(
            $wpdb->prefix . 'wishcart_conversations',
            [ 'status' => 'closed' ],
            [ 'conversation_id' => $conversation_id ]
        );
        // @codingStandardsIgnoreEnd

        if ($result !== false) {
            // Clear related caches
            wp_cache_delete('wishcart_conversation_' . $conversation_id, 'wishcart_chat_storage');
            wp_cache_delete('wishcart_messages_' . $conversation_id, 'wishcart_chat_storage');
            wp_cache_delete('wishcart_messages_' . $conversation_id . '_15', 'wishcart_chat_storage');
        }

        return $result;
    }

    /**
     * Gets recent message history for a conversation
     *
     * @param string $conversation_id The conversation ID
     * @param int    $limit           Maximum number of messages to return
     *
     * @since 1.0.0
     *
     * @return array Array of formatted messages
     */
    public function get_recent_message_history($conversation_id, $limit = 15) {
        $cache_key = 'wishcart_messages_' . $conversation_id . '_' . $limit;
        $messages = wp_cache_get($cache_key, 'wishcart_chat_storage');
        
        if (false === $messages) {
            // @codingStandardsIgnoreStart
            global $wpdb;
            $messages = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT message_type, message, metadata 
                    FROM {$wpdb->prefix}wishcart_messages 
                    WHERE conversation_id = %s 
                    ORDER BY created_at DESC 
                    LIMIT %d",
                    $conversation_id,
                    $limit
                )
            );
            // @codingStandardsIgnoreEnd

            if ($messages) {
                wp_cache_set($cache_key, $messages, 'wishcart_chat_storage', 300);
            } else {
                wp_cache_set($cache_key, array(), 'wishcart_chat_storage', 60);
            }
        }

        // Reverse array to maintain chronological order
        $messages = array_reverse($messages);

        // Format messages for AI context
        return array_map(
            function ($msg) {
                return [
                    'bot' => $msg->message_type,
                    'message' => $msg->message,
                ];
            },
            $messages
        );
    }

    /**
     * Gets active WhatsApp conversation for a user
     *
     * @param string $wa_id WhatsApp user ID
     *
     * @since 1.0.0
     *
     * @return object|null Conversation data or null if not found
     */
    public function get_whatsapp_conversation($wa_id) {
        $cache_key = 'wishcart_wa_conv_' . $wa_id;
        $conversation = wp_cache_get($cache_key, 'wishcart_chat_storage');
        
        if (false === $conversation) {
            // @codingStandardsIgnoreStart
            global $wpdb;
            $conversation = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}wishcart_conversations 
                    WHERE user_phone = %s 
                    AND platform = 'whatsapp'
                    ORDER BY created_at DESC 
                    LIMIT 1",
                    $wa_id
                )
            );
            // @codingStandardsIgnoreEnd

            if ($conversation) {
                wp_cache_set($cache_key, $conversation, 'wishcart_chat_storage', 300);
            } else {
                wp_cache_set($cache_key, null, 'wishcart_chat_storage', 60);
            }
        }

        return $conversation;
    }

    /**
     * Gets active Telegram conversation for a user
     *
     * @param string $user_phone Telegram user phone
     *
     * @since 1.0.0
     *
     * @return object|null Conversation data or null if not found
     */
    public function get_telegram_conversation( $user_phone ) {
        $cache_key = 'wishcart_telegram_conv_' . $user_phone;
        $conversation = wp_cache_get($cache_key, 'wishcart_chat_storage');
        
        if (false === $conversation) {
            // @codingStandardsIgnoreStart
            global $wpdb;
            $conversation = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}wishcart_conversations 
                    WHERE user_phone = %s 
                    AND platform = 'telegram'
                    ORDER BY created_at DESC 
                    LIMIT 1",
                    $user_phone
                )
            );
            // @codingStandardsIgnoreEnd
            
            if ($conversation) {
                wp_cache_set($cache_key, $conversation, 'wishcart_chat_storage', 300);
            } else {
                wp_cache_set($cache_key, null, 'wishcart_chat_storage', 60);
            }
        }
        
        return $conversation;
    }

    /**
     * Sets user state data
     *
     * @param string $platform_user_id User ID for the platform
     * @param string $platform         Platform identifier
     * @param array  $state_data       State data to store
     *
     * @since 1.0.0
     *
     * @return int|false Number of rows affected or false on error
     */
    public function set_user_state( $platform_user_id, $platform, $state_data ) {
        // @codingStandardsIgnoreStart
        global $wpdb;
        $result = $wpdb->replace(
            $this->state_table,
            [
                'platform_user_id' => $platform_user_id,
                'platform' => $platform,
                'state_data' => json_encode($state_data),
            ],
            [ '%s', '%s', '%s' ]
        );
        // @codingStandardsIgnoreEnd

        if ($result !== false) {
            // Cache the state data
            $cache_key = 'wishcart_user_state_' . $platform . '_' . $platform_user_id;
            wp_cache_set($cache_key, $state_data, 'wishcart_chat_storage', 300);
        }

        return $result;
    }

    /**
     * Gets user state data
     *
     * @param string $platform_user_id User ID for the platform
     * @param string $platform         Platform identifier
     *
     * @since 1.0.0
     *
     * @return array|null State data or null if not found
     */
    public function get_user_state( $platform_user_id, $platform ) {
        $cache_key = 'wishcart_user_state_' . $platform . '_' . $platform_user_id;
        $state = wp_cache_get($cache_key, 'wishcart_chat_storage');
        
        if (false === $state) {
            // @codingStandardsIgnoreStart
            global $wpdb;
            $result = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT state_data FROM {$wpdb->prefix}wishcart_user_states 
                    WHERE platform_user_id = %s AND platform = %s",
                    $platform_user_id,
                    $platform
                )
            );
            // @codingStandardsIgnoreEnd
            
            if ($result) {
                $state = json_decode($result, true);
                wp_cache_set($cache_key, $state, 'wishcart_chat_storage', 300);
            } else {
                wp_cache_set($cache_key, null, 'wishcart_chat_storage', 60);
            }
        }
        
        return $state;
    }

    /**
     * Clears user state data
     *
     * @param string $platform_user_id User ID for the platform
     * @param string $platform         Platform identifier
     *
     * @since 1.0.0
     *
     * @return int|false Number of rows affected or false on error
     */
    public function clear_user_state( $platform_user_id, $platform ) {
        // @codingStandardsIgnoreStart
        global $wpdb;
        $result = $wpdb->delete(
            $this->state_table,
            [
                'platform_user_id' => $platform_user_id,
                'platform' => $platform,
            ],
            [ '%s', '%s' ]
        );
        // @codingStandardsIgnoreEnd

        if ($result !== false) {
            // Clear the cached state data
            $cache_key = 'wishcart_user_state_' . $platform . '_' . $platform_user_id;
            wp_cache_delete($cache_key, 'wishcart_chat_storage');
        }

        return $result;
    }
}