<?php
/**
 * Telegram Bot Integration Handler
 *
 * This file handles all Telegram bot related functionality including webhook processing,
 * message handling, and communication with the Telegram Bot API.
 *
 * @category   Messenger
 * @package    AISK
 * @subpackage Messenger
 * @author     Aisk Team <support@aisk.chat>
 * @license    GPL-2.0+ https://www.gnu.org/licenses/gpl-2.0.html
 * @link       https://aisk.chat
 */

if ( ! defined('ABSPATH') ) {
	exit;
}
/**
 * Class AISK_Telegram_Handler
 *
 * Manages all Telegram bot interactions including:
 * - Webhook registration and processing
 * - Message handling and responses
 * - Product and order information display
 * - User state management
 * - API communication with Telegram
 *
 * @category Class
 * @package  AISK
 * @author   Aisk Team <support@aisk.chat>
 * @license  GPL-2.0+ https://www.gnu.org/licenses/gpl-2.0.html
 * @link     https://aisk.chat
 */
class AISK_Telegram_Handler {

    private $bot_token;
    private $chat_handler;
    private $chat_storage;
    private $product_handler;
    private $order_handler;
    private $webhook_url;
	private $logging_enabled = true; // Basic toggle in case we want to disable logs quickly

    /**
     * Constructor.
     *
     * Initializes the Telegram handler by setting up bot token, webhook URL,
     * and initializing required handlers.
     *
     * @since 1.0.0
     *
     * @return void
     */
	public function __construct() {
        $settings = get_option('aisk_settings', []);
        $this->bot_token = isset($settings['integrations']['telegram']['bot_token']) ? $settings['integrations']['telegram']['bot_token'] : '';
        $this->webhook_url = get_site_url(null, '/wp-json/aisk/v1/telegram-webhook');

		$this->log('Initialized Telegram handler', [
			'webhook_url' => $this->webhook_url,
			'bot_token_present' => ! empty($this->bot_token),
		]);

        // Initialize handlers
        $this->chat_handler = new AISK_Chat_Handler();
        $this->product_handler = new AISK_Product_Handler();
        $this->order_handler = new AISK_Order_Handler();
        $this->chat_storage = AISK_Chat_Storage::get_instance(); // Get singleton instance

        // Register webhook endpoint
		add_action('rest_api_init', [ $this, 'register_webhook' ]);
    }

    /**
     * Registers the webhook endpoint for Telegram API.
     *
     * @since  1.0.0
     * @return void
     */
	public function register_webhook() {
		$this->log('Registering Telegram webhook route');
        register_rest_route(
            'aisk/v1', '/telegram-webhook', [
				'methods' => 'POST',
				'callback' => [ $this, 'handle_webhook' ],
				'permission_callback' => [ $this, 'verify_telegram_request' ],
            ]
        );
		$this->log('Telegram webhook route registered');
    }

    /**
     * Verifies incoming requests from Telegram.
     *
     * Checks if the bot token is configured and validates the request.
     *
     * @since  1.0.0
     * @return bool True if request is valid, false otherwise.
     */
	public function verify_telegram_request() {
        // Verify the request is coming from Telegram
		if ( empty($this->bot_token) ) {
			$this->log('Permission denied: bot token missing');
            return false;
        }

        // Additional security checks can be implemented here
		$this->log('Permission granted for Telegram webhook request');
        return true;
    }

    /**
     * Handles incoming webhook requests from Telegram.
     *
     * Processes different types of updates including messages and callback queries.
     *
     * @param WP_REST_Request $request The incoming request object.
     *
     * @since 1.0.0
     *
     * @return WP_REST_Response|WP_Error Response object or error.
     */
	public function handle_webhook( $request ) {
        try {
			$update = $request->get_json_params();
			$this->log('Webhook received', [ 'raw' => $update ]);

            // Handle different types of updates
			if ( isset($update['message']) ) {
				$this->log('Processing Telegram message', [ 'chat_id' => $update['message']['chat']['id'] ?? null ]);
                return $this->handle_message($update['message']);
			} elseif ( isset($update['callback_query']) ) {
				$this->log('Processing Telegram callback_query', [ 'chat_id' => $update['callback_query']['message']['chat']['id'] ?? null ]);
                return $this->handle_callback_query($update['callback_query']);
            }

			$this->log('Invalid update received');
			return new WP_Error('invalid_update', 'Invalid update received');
        } catch ( Exception $e ) {
			$this->log('Exception in handle_webhook', [ 'error' => $e->getMessage() ]);
			return new WP_Error('webhook_error', $e->getMessage());
        }
    }

    /**
     * Handles incoming messages from Telegram.
     *
     * Processes user messages and generates appropriate responses.
     *
     * @param array $message The message data from Telegram.
     *
     * @since 1.0.0
     *
     * @return WP_REST_Response The formatted response.
     */
	private function handle_message( $message ) {
        $chat_id = $message['chat']['id'];
        $text = isset($message['text']) ? $message['text'] : '';
		$this->log('handle_message start', [ 'chat_id' => $chat_id, 'text' => $text ]);

        if ( empty($text) ) {
            return $this->format_telegram_response(null);
        }

        // Check if user is in inquiry state
        $state = $this->chat_storage->get_user_state($chat_id, 'telegram');
        if (is_array($state) && isset($state['action']) && $state['action'] === 'inquiry') {
			$this->log('User in inquiry state, forwarding to inquiry submission', [ 'chat_id' => $chat_id, 'order_id' => $state['order_id'] ]);
			return $this->handle_inquiry_submission($chat_id, $state['order_id'], $text);
        }

        // Get or create conversation
        $conversation_id = $this->get_or_create_conversation($message);

        // Create parameters for chat handler
        $chat_params = new WP_REST_Request();
        $chat_params->set_param('message', $text);
        $chat_params->set_param('conversation_id', $conversation_id);

        // Process message
		$this->log('Forwarding message to chat handler', [ 'conversation_id' => $conversation_id ]);
		$response = $this->chat_handler->handle_chat_request($chat_params);

        if ( is_wp_error($response) ) {
			$this->log('Chat handler returned WP_Error', [ 'error' => $response->get_error_message() ]);
            $this->send_message($chat_id, "I'm currently offline. Please try again shortly, and I'll get back to you as soon as I'm available!");
            return $this->format_telegram_response(null);
        }

        // Handle different types of responses
        if ( isset($response['products']) ) {
            $this->handle_product_search($chat_id, $response);
        } elseif ( isset($response['order']) ) {
            $this->send_order_details($chat_id, $response['order']);
        } elseif ( isset($response['message']) ) {
            $this->send_message($chat_id, $response['message']);
        }

		$this->log('handle_message completed', [ 'chat_id' => $chat_id ]);
		return $this->format_telegram_response(null);
    }

    /**
     * Gets existing conversation or creates new one.
     *
     * @param array $telegram_data The user data from Telegram.
     *
     * @since 1.0.0
     *
     * @return int The conversation ID.
     */
    private function get_or_create_conversation( $telegram_data ) {
        $chat_id = $telegram_data['chat']['id'];
        $username = isset($telegram_data['chat']['username']) ? $telegram_data['chat']['username'] : '';
        $first_name = isset($telegram_data['chat']['first_name']) ? $telegram_data['chat']['first_name'] : '';
        $last_name = isset($telegram_data['chat']['last_name']) ? $telegram_data['chat']['last_name'] : '';

        // Try to find existing conversation using chat_id as phone
        $conversation = $this->chat_storage->get_telegram_conversation($chat_id);

        if ( $conversation ) {
            return $conversation->conversation_id;
        }

        // If no active conversation exists, create a new one
        $display_name = trim($first_name . ' ' . $last_name);
        if ( empty($display_name) && ! empty($username) ) {
            $display_name = $username;
        }

        $conversation_data = [
            'user_id' => null,
            'user_name' => $display_name,
            'user_email' => null,
            'user_phone' => $chat_id,  // Store Telegram chat_id here
            'platform' => 'telegram',
            'ip_address' => null,
            'user_agent' => 'Telegram Bot - @' . $username,
            'page_url' => null,
        ];

        return $this->chat_storage->create_conversation($conversation_data);
    }

    /**
     * Handles callback queries from Telegram inline buttons.
     *
     * @param array $callback_query The callback query data from Telegram.
     *
     * @since 1.0.0
     *
     * @return WP_REST_Response Response object with status.
     */
	private function handle_callback_query( $callback_query ) {
        $data = json_decode($callback_query['data'], true);
        $chat_id = $callback_query['message']['chat']['id'];
		$this->log('handle_callback_query start', [ 'chat_id' => $chat_id, 'data' => $data ]);

        if ( 'inquiry' === $data['action'] ) {
            $this->start_inquiry_process($chat_id, $data['order_id']);
        } elseif ( 'cancel_inquiry' === $data['action'] ) {
            $this->cancel_inquiry_process($chat_id);
        }

		$this->log('handle_callback_query completed', [ 'chat_id' => $chat_id ]);
		return new WP_REST_Response([ 'status' => 'ok' ]);
    }

    /**
     * Initiates the inquiry process for an order.
     *
     * @param int $chat_id  The Telegram chat ID.
     * @param int $order_id The FluentCart order ID.
     *
     * @since 1.0.0
     *
     * @return void
     */
	private function start_inquiry_process( $chat_id, $order_id ) {
		$this->log('Starting inquiry process', [ 'chat_id' => $chat_id, 'order_id' => $order_id ]);
        $this->chat_storage->set_user_state(
            $chat_id, 'telegram', [
				'action' => 'inquiry',
				'order_id' => $order_id,
            ]
        );

        $message = "Please describe your inquiry about Order #{$order_id}. Type your message below:";
        $keyboard = [
            'inline_keyboard' => [
				[
					[
						'text' => '❌ Cancel',
						'callback_data' => json_encode(
														[
															'action' => 'cancel_inquiry',
														]
													),
					],
				],
			],
        ];

        $this->send_message($chat_id, $message, 'HTML', $keyboard);
    }

    /**
     * Cancels the active inquiry process.
     *
     * @param int $chat_id The Telegram chat ID.
     *
     * @since 1.0.0
     *
     * @return void
     */
	private function cancel_inquiry_process( $chat_id ) {
		$this->log('Cancelling inquiry process', [ 'chat_id' => $chat_id ]);
        $this->chat_storage->clear_user_state($chat_id, 'telegram');
        $this->send_message($chat_id, '❌ Inquiry submission cancelled.');
    }

    /**
     * Handles the submission of an inquiry.
     *
     * @param int    $chat_id  The Telegram chat ID.
     * @param int    $order_id The FluentCart order ID.
     * @param string $note     The inquiry message.
     *
     * @since 1.0.0
     *
     * @return WP_REST_Response The formatted response.
     */
	private function handle_inquiry_submission( $chat_id, $order_id, $note ) {
        try {
			$this->log('handle_inquiry_submission start', [ 'chat_id' => $chat_id, 'order_id' => $order_id ]);
            $conversation_id = $this->get_or_create_conversation([ 'chat' => [ 'id' => $chat_id ] ]);

            // Create a WP_REST_Request to use with existing handler
            $request = new WP_REST_Request();
            $request->set_param('note', $note);
            $request->set_param('order_number', $order_id);
            $request->set_param('conversation_id', $conversation_id);

            // Use existing chat handler to submit inquiry
            $result = $this->chat_handler->handle_inquiry_submission($request);

            // Clear user state
            $this->chat_storage->clear_user_state($chat_id, 'telegram');

			if ( ! is_wp_error($result) ) {
                $this->send_message(
                    $chat_id,
                    "✅ Thank you! Your inquiry for Order #{$order_id} has been submitted successfully. Our team will review it shortly."
                );
				$this->log('Inquiry submitted successfully', [ 'chat_id' => $chat_id, 'order_id' => $order_id ]);
            } else {
                $this->send_message(
                    $chat_id,
                    '❌ Sorry, there was an error submitting your inquiry. Please try again later.'
                );
				$this->log('Error submitting inquiry', [ 'chat_id' => $chat_id, 'order_id' => $order_id ]);
            }
        } catch (Exception $e) {
            $this->send_message(
                $chat_id,
                '❌ An unexpected error occurred. Please try again later.'
            );
			$this->log('Exception in handle_inquiry_submission', [ 'error' => $e->getMessage(), 'chat_id' => $chat_id ]);
        }

        return $this->format_telegram_response(null);
    }

    /**
     * Formats the response for Telegram API.
     *
     * @param string|null $message Optional message to include in response.
     *
     * @since 1.0.0
     *
     * @return WP_REST_Response The formatted response.
     */
    private function format_telegram_response( $message = null ) {
        // If no message is provided, return a simple 200 OK response
        if ( null === $message ) {
            return new WP_REST_Response([ 'status' => 'ok' ]);
        }

        // Format response according to Telegram Bot API requirements
        $response = [
            'method' => 'sendMessage',
            'text' => $message,
        ];

        // Return a proper WordPress REST response
        return new WP_REST_Response($response);
    }

    /**
     * Handles product search results and sends them to user.
     *
     * @param int   $chat_id  The Telegram chat ID.
     * @param array $response The search response data.
     *
     * @since 1.0.0
     *
     * @return void
     */
	private function handle_product_search( $chat_id, $response ) {
        // Check if we have products first
        if ( empty($response['products']) ) {
            // Show the not_found message from the response
            $not_found_message = isset($response['message']) ? $response['message'] :
                "I couldn't find any products matching your request. Could you please provide more details about what you're looking for?";

            $this->send_message($chat_id, $not_found_message);
            return;
        }

        // Show the found message before displaying products
        $found_message = isset($response['response']) ? $response['response'] :
            'Nice! Here are some products you might like:';
		$this->send_message($chat_id, $found_message);

        // Small delay before showing products
        usleep(200000); // 200ms delay

        // Send the product carousel
        $this->send_product_carousel($chat_id, $response['products']);
    }

    /**
     * Sends product carousel to user.
     *
     * @param int   $chat_id  The Telegram chat ID.
     * @param array $products Array of product data.
     *
     * @since 1.0.0
     *
     * @return void
     */
	private function send_product_carousel( $chat_id, $products ) {
        foreach ( $products as $product ) {
            // If product has image, send it first without caption
            if ( ! empty($product['image']) ) {
                try {
					$this->send_photo($chat_id, $product['image']);
                    usleep(200000); // 0.2 second delay after image
                } catch (Exception $e) {
                    // Handle error silently
					$this->log('Failed to send product image', [ 'error' => $e->getMessage(), 'chat_id' => $chat_id ]);
                }
            }

            // Send product details as a separate message
            $message_text = sprintf(
                "*%s*\n💰 Price: %s",
                $this->escape_markdown($product['name']),
                $this->escape_markdown($product['price'])
            );

            // Add description if available
            if ( ! empty($product['description']) ) {
                $message_text .= "\n\n" . $this->escape_markdown($product['description']);
            }

            // Create keyboard with product URL
            $keyboard = [
                'inline_keyboard' => [
                    [
                        [
                            'text' => '🛍 View Product',
                            'url' => $product['url'],
                        ],
                    ],
                ],
            ];

            // Send product details with keyboard
			$this->send_message($chat_id, $message_text, 'MarkdownV2', $keyboard);

            // Add delay between products
            usleep(500000); // 0.5 second delay
        }
    }

    /**
     * Sends order details to user.
     *
     * @param int   $chat_id    The Telegram chat ID.
     * @param array $order_data The order details.
     *
     * @since 1.0.0
     *
     * @return void
     */
	private function send_order_details( $chat_id, $order_data ) {
        if ( 'order_verified' === $order_data['type'] ) {
            $order_info = $order_data['order_info'];

            // Format order items in a single string
            $items_text = "*Order Items:*\n";
            foreach ( $order_info['items'] as $item ) {
                $items_text .= sprintf(
                    "• %s\n  Quantity: x%d, Total: %s\n",
                    $this->escape_markdown($item['name']),
                    $item['quantity'],
                    $this->escape_markdown($item['total'])
                );
            }

            // Main order message with all details
            $message = sprintf(
                "*Order \\#%s*\n\n"
                . "📅 Date: %s\n"
                . "💰 Total: %s\n"
                . "📦 Status: %s\n\n"
                . "🚚 Shipping Method: %s\n"
                . "📍 Shipping Address: %s\n\n"
                . '%s',
                $this->escape_markdown($order_info['order_number']),
                $this->escape_markdown($order_info['date_created']),
                $this->escape_markdown($order_info['total']),
                $this->escape_markdown(strtoupper($order_info['status'])),
                $this->escape_markdown($order_info['shipping_method']),
                $this->escape_markdown($order_info['shipping_address']),
                $items_text
            );

            // Add inquiry button
            $keyboard = [
                'inline_keyboard' => [
					[
						[
							'text' => '📝 Submit Inquiry',
							'callback_data' => json_encode(
																[
																	'action' => 'inquiry',
																	'order_id' => $order_info['order_number'],
																]
															),
						],
					],
				],
            ];

            // Send single message with all details and inquiry button
			$this->send_message($chat_id, $message, 'MarkdownV2', $keyboard);
        } else {
            $this->send_message($chat_id, $order_data['message']);
        }
    }

    /**
     * Sends a message to a Telegram chat.
     *
     * @param int        $chat_id      The Telegram chat ID.
     * @param string     $text         The message text.
     * @param string     $parse_mode   The parsing mode for the message.
     * @param array|null $reply_markup Optional keyboard markup.
     *
     * @since 1.0.0
     *
     * @return mixed The API response.
     */
	private function send_message( $chat_id, $text, $parse_mode = 'HTML', $reply_markup = null ) {
        $params = [
            'chat_id' => $chat_id,
            'text' => $text,
            'parse_mode' => $parse_mode,
            'disable_web_page_preview' => true,
        ];

        if ( $reply_markup ) {
            $params['reply_markup'] = json_encode($reply_markup);
        }

		$this->log('Sending Telegram message', [ 'chat_id' => $chat_id, 'has_reply_markup' => ! empty($reply_markup) ]);
		return $this->send_telegram_request('sendMessage', $params);
    }

    /**
     * Sends a photo message to a Telegram chat.
     *
     * @param int        $chat_id      The Telegram chat ID.
     * @param string     $photo_url    URL of the photo.
     * @param string     $caption      Optional caption for the photo.
     * @param array|null $reply_markup Optional keyboard markup.
     *
     * @since 1.0.0
     *
     * @return mixed The API response.
     */
	private function send_photo( $chat_id, $photo_url, $caption = null, $reply_markup = null ) {
        $params = [
            'chat_id' => $chat_id,
            'photo' => $photo_url,
            'parse_mode' => 'MarkdownV2',
        ];

        if ( $caption ) {
            $params['caption'] = $caption;
        }

        if ( $reply_markup ) {
            $params['reply_markup'] = json_encode($reply_markup);
        }

		$this->log('Sending Telegram photo', [ 'chat_id' => $chat_id, 'photo' => $photo_url ]);
		return $this->send_telegram_request('sendPhoto', $params);
    }

    /**
     * Escapes special characters for MarkdownV2 format.
     *
     * @param string $text The text to escape.
     *
     * @since 1.0.0
     *
     * @return string The escaped text.
     */
    private function escape_markdown( $text ) {
        // Escape special characters for MarkdownV2
        $special_chars = [ '_', '*', '[', ']', '(', ')', '~', '`', '>', '#', '+', '-', '=', '|', '{', '}', '.', '!' ];
        return str_replace(
            $special_chars, array_map(
                function ( $char ) {
                    return '\\' . $char;
                }, $special_chars
            ), $text
        );
    }

    /**
     * Sends a request to the Telegram API.
     *
     * @param string $method The API method to call.
     * @param array  $params The parameters for the API call.
     *
     * @since 1.0.0
     *
     * @return mixed|false The API response or false on failure.
     */
	private function send_telegram_request( $method, $params = [] ) {
        $url = "https://api.telegram.org/bot{$this->bot_token}/{$method}";

		$this->log('Calling Telegram API', [ 'method' => $method, 'url' => $url ]);
		$response = wp_remote_post(
            $url, [
				'headers' => [ 'Content-Type' => 'application/json' ],
				'body' => json_encode($params),
				'timeout' => 15,
            ]
        );

		if ( is_wp_error($response) ) {
			$this->log('Telegram API request failed', [ 'error' => $response->get_error_message() ]);
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
		$this->log('Telegram API response', [ 'ok' => $body['ok'] ?? null ]);

        if ( ! isset($body['ok']) || ! $body['ok'] ) {
			$this->log('Telegram API returned not ok', [ 'body' => $body ]);
            return false;
        }

        return $body['result'];
    }

	/**
	 * Lightweight logger to WP debug.log
	 *
	 * @param string $message
	 * @param array  $context
	 * @return void
	 */
	private function log( $message, $context = [] ) {
		if ( ! $this->logging_enabled ) {
			return;
		}
		// Respect WP_DEBUG if set; still allow logging when enabled here
		$prefix = '[AISK Telegram] ';
		$payload = $message;
		if ( ! empty( $context ) ) {
			// Avoid logging secrets
			if ( isset( $context['bot_token'] ) ) {
				$context['bot_token'] = '***redacted***';
			}
			$payload .= ' | ' . wp_json_encode( $context );
		}
		if (defined('WP_DEBUG') && WP_DEBUG) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging is properly guarded
			error_log( $prefix . $payload );
		}
	}
}

// Initialize the Telegram handler
new AISK_Telegram_Handler();
