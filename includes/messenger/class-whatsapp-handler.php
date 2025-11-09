<?php
/**
 * WhatsApp Integration Handler
 *
 * This file handles all WhatsApp related functionality including webhook processing,
 * message handling, and communication with the Twilio WhatsApp API.
 *
 * @category   Messenger
 * @package    AISK
 * @subpackage Messenger
 * @author     WishCart Team <support@wishcart.chat>
 * @license    GPL-2.0+ https://www.gnu.org/licenses/gpl-2.0.html
 * @link       https://wishcart.chat
 */

if ( ! defined('ABSPATH') ) {
	exit;
}
/**
 * Class WISHCART_WhatsApp_Handler
 *
 * Manages all WhatsApp interactions including:
 * - Webhook registration and processing
 * - Message handling and responses
 * - Product and order information display
 * - User state management
 * - API communication with Twilio
 *
 * @category Class
 * @package  AISK
 * @author   WishCart Team <support@wishcart.chat>
 * @license  GPL-2.0+ https://www.gnu.org/licenses/gpl-2.0.html
 * @link     https://wishcart.chat
 */
class WISHCART_WhatsApp_Handler {

    private $chat_handler;
    private $chat_storage;
    private $account_sid;
    private $auth_token;
    private $from_number;
    private $webhook_url;
    private $is_sandbox;
    

    /**
     * Initialize WhatsApp handler and set up webhook endpoints.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function __construct() {
        $this->chat_handler = new WISHCART_Chat_Handler();
        $this->chat_storage = WISHCART_Chat_Storage::get_instance();
        
        // Get webhook URL from settings or use default
        $settings = get_option('wishcart_settings', []);
        $this->webhook_url = isset($settings['integrations']['whatsapp']['webhook_url']) && !empty($settings['integrations']['whatsapp']['webhook_url'])
            ? $settings['integrations']['whatsapp']['webhook_url']
            : get_site_url(null, '/wp-json/wishcart/v1/whatsapp-webhook');
        // $this->webhook_url = 'https://275f-103-25-248-196.ngrok-free.app/wp-json/wishcart/v1/whatsapp-webhook';
        $this->account_sid = isset($settings['integrations']['whatsapp']['account_sid']) ? $settings['integrations']['whatsapp']['account_sid'] : '';
        $this->auth_token = isset($settings['integrations']['whatsapp']['auth_token']) ? $settings['integrations']['whatsapp']['auth_token'] : '';
        $this->from_number = isset($settings['integrations']['whatsapp']['phone_number']) ? $settings['integrations']['whatsapp']['phone_number'] : '';

        add_action('rest_api_init', [ $this, 'register_webhook_endpoint' ]);
    }

    /**
     * Register the WhatsApp webhook endpoint with WordPress REST API.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function register_webhook_endpoint() {
        register_rest_route(
            'wishcart/v1', '/whatsapp-webhook', [
				'methods' => 'POST',
				'callback' => [ $this, 'handle_webhook' ],
				'permission_callback' => [ $this, 'verify_twilio_request' ],
            ]
        );
    }

    /**
     * Verify incoming Twilio webhook request signature.
     *
     * @param WP_REST_Request $request The incoming request object.
     *
     * @since 1.0.0
     *
     * @return bool True if signature is valid or auth disabled.
     */
    public function verify_twilio_request($request) {
        // Check if we're in a development environment
        $is_local = defined('WP_DEBUG') && WP_DEBUG;
        if ($is_local) {
            return true;
        }

        if (empty($this->auth_token)) {
            $from = $request->get_param('From');
            $message = 'WhatsApp: Missing Twilio auth token';
            $this->send_message_to_twilio($from, $message);
            return true; // Allow for development
        }

        // Get the Twilio signature from the request header
        $twilio_signature = $request->get_header('X-Twilio-Signature');

        // Get the actual request URL with proper validation and sanitization
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : '';
        $uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        
        // Validate host and URI to prevent injection
        if (!preg_match('/^[a-zA-Z0-9.-]+$/', $host) || !preg_match('/^[a-zA-Z0-9._~:/?#[\]@!$&\'()*+,;=%\-]*$/', $uri)) {
            return new WP_Error('invalid_request', 'Invalid request URL');
        }
        
        $url = $protocol . '://' . $host . $uri;
        // Use esc_url_raw for security when building URLs
        $url = esc_url_raw($url);
        
        // Get POST params
        $params = $request->get_params();

        // Sort parameters alphabetically for consistent signing
        ksort($params);

        // Create the string to sign from URL + sorted params
        $validation_string = $url;
        foreach ($params as $key => $value) {
            $validation_string .= $key . $value;
        }

        // Calculate expected signature
        $calculated_signature = base64_encode(hash_hmac('sha1', $validation_string, $this->auth_token, true));

        $is_valid = hash_equals($twilio_signature, $calculated_signature);

        return $is_valid;
    }

    /**
     * Handle incoming webhook requests from Twilio.
     *
     * @param WP_REST_Request $request The incoming request object.
     *
     * @since 1.0.0
     *
     * @return mixed Response to send back to Twilio.
     */
    public function handle_webhook( $request ) {
        
        try {
            // Get WhatsApp details
            $wa_id = $request->get_param('WaId');
            $from = $request->get_param('From');
            $body = $request->get_param('Body');
            $profile_name = $request->get_param('ProfileName');

            if ( empty($body) ) {
                return $this->format_twilio_response(null);
            }

            // Check if user is in inquiry state
            $state = $this->chat_storage->get_user_state($wa_id, 'whatsapp');
            // Safely check for inquiry state
            if (is_array($state) && isset($state['action']) && 'inquiry' === $state['action']) {
                return $this->handle_inquiry_submission($wa_id, $state['order_id'], $body, $from);
            }

            // Get or create conversation
            $conversation_id = $this->get_or_create_conversation($wa_id, $profile_name);

            // Check if user wants to submit an inquiry
            if ( strtolower($body) === 'yes' && $state && isset($state['order_info']) ) {
                $this->start_inquiry_process($wa_id, $from, $state['order_info']['order_number']);
                return $this->format_twilio_response(null);
            }

            // Create parameters for chat handler
            $chat_params = new WP_REST_Request();
            $chat_params->set_param('message', $body);
            $chat_params->set_param('conversation_id', $conversation_id);

            // Process message
            $response = $this->chat_handler->handle_chat_request($chat_params);

            if ( is_wp_error($response) ) {
                $this->send_message_to_twilio($from, "I'm currently offline. Please try again shortly, and I'll get back to you as soon as I'm available!");
                return $this->format_twilio_response(null);
            }

            // Handle different types of responses
            if ( ! empty($response['products']) ) {
                $this->send_product_messages($from, $response);
            } else if ( ! empty($response['order']) ) {
                if ( 'order_verified' === $response['order']['type'] ) {
                    // Store order info in state
                    $this->chat_storage->set_user_state(
                        $wa_id, 'whatsapp', [
                            'order_info' => $response['order']['order_info'],
                        ]
                    );

                    // Send order details and inquiry option
                    $order_message = $this->format_order_message($response['order']);
                    $this->send_message_to_twilio($from, $order_message);

                    // Send inquiry prompt
                    $inquiry_message = "\nWould you like to submit an inquiry about this order?\nReply 'YES' to submit an inquiry.";
                    $this->send_message_to_twilio($from, $inquiry_message);
                } else {
                    $this->send_message_to_twilio($from, $response['order']['message']);
                }
            } else if ( isset($response['message']) ) {
                $this->send_message_to_twilio($from, $response['message']);
            }

            return $this->format_twilio_response(null);

        } catch ( Exception $e ) {
            return $this->format_twilio_response('Sorry, something went wrong. Please try again later.');
        }
    }

    /**
     * Start the inquiry submission process for an order.
     *
     * @param string $wa_id    WhatsApp ID of the user.
     * @param string $from     User's phone number.
     * @param string $order_id Order ID for the inquiry.
     *
     * @since 1.0.0
     *
     * @return void
     */
    private function start_inquiry_process( $wa_id, $from, $order_id ) {
        $this->chat_storage->set_user_state(
            $wa_id, 'whatsapp', [
				'action' => 'inquiry',
				'order_id' => $order_id,
            ]
        );

        $message = "Please describe your inquiry about Order #{$order_id}. Type your message below:\n\n"
            . "Type 'cancel' to cancel submission.";

        $this->send_message_to_twilio($from, $message);
    }

    /**
     * Handle submission of an order inquiry.
     *
     * @param string $wa_id    WhatsApp ID of the user.
     * @param string $order_id Order ID for the inquiry.
     * @param string $note     Inquiry message content.
     * @param string $from     User's phone number.
     *
     * @since 1.0.0
     *
     * @return mixed Response to send back to Twilio.
     */
    private function handle_inquiry_submission( $wa_id, $order_id, $note, $from ) {
        if ( strtolower($note) === 'cancel' ) {
            $this->chat_storage->clear_user_state($wa_id, 'whatsapp');
            $this->send_message_to_twilio($from, '❌ Inquiry submission cancelled.');
            return $this->format_twilio_response(null);
        }

        $conversation_id = $this->get_or_create_conversation($wa_id, null);

        // Create request for inquiry submission
        $request = new WP_REST_Request();
        $request->set_param('note', $note);
        $request->set_param('order_number', $order_id);
        $request->set_param('conversation_id', $conversation_id);

        // Submit inquiry using chat handler
        $result = $this->chat_handler->handle_inquiry_submission($request);

        // Clear user state
        $this->chat_storage->clear_user_state($wa_id, 'whatsapp');

        if ( ! is_wp_error($result) ) {
            $this->send_message_to_twilio(
                $from,
                "✅ Thank you! Your inquiry for Order #{$order_id} has been submitted successfully. Our team will review it shortly."
            );
        } else {
            $this->send_message_to_twilio(
                $from,
                '❌ Sorry, there was an error submitting your inquiry. Please try again later.'
            );
        }

        return $this->format_twilio_response(null);
    }

    /**
     * Get existing conversation or create new one.
     *
     * @param string      $wa_id        WhatsApp ID of the user.
     * @param string|null $profile_name User's profile name.
     *
     * @since 1.0.0
     *
     * @return string Conversation ID.
     */
    private function get_or_create_conversation( $wa_id, $profile_name ) {

        $conversation = $this->chat_storage->get_whatsapp_conversation($wa_id);

        if ( $conversation ) {
            return $conversation->conversation_id;
        }

        $conversation_data = [
            'user_id' => null,
            'user_name' => $profile_name,
            'user_phone' => $wa_id,
            'platform' => 'whatsapp',
            'ip_address' => null,
            'user_agent' => 'WhatsApp Bot',
        ];

        return $this->chat_storage->create_conversation($conversation_data);
    }

    /**
     * Send product information messages via WhatsApp.
     *
     * @param string $to       Recipient's phone number.
     * @param array  $response Response containing product data.
     *
     * @since 1.0.0
     *
     * @return void
     */
    private function send_product_messages( $to, $response ) {
        $intro_message = 'Here are some products you might like:';
        $this->send_message_to_twilio($to, $intro_message);

        usleep(500000); // 0.5 second delay

        foreach ( $response['products'] as $product ) {
            // Send product details
            $message = "*{$product['name']}*\n";
            $message .= '💰 Price: $' . $product['price'] . "\n";
            if ( ! empty($product['description']) ) {
                $message .= '📝 ' . $product['description'] . "\n";
            }
            $message .= "\n🔗 View product: {$product['url']}";

             if ( ! empty($product['image']) ) {
                $this->send_message_to_twilio($to, $message, $product['image']);
             } else {
                $this->send_message_to_twilio($to, $message);
            }

            // Add delay between messages
            usleep(500000);
        }
    }

    /**
     * Format order details into readable message.
     *
     * @param array $order Order data to format.
     *
     * @since 1.0.0
     *
     * @return string Formatted message.
     */
    private function format_order_message( $order ) {
        $order_info = $order['order_info'];
        $message = esc_html__('*Order Details*', 'wish-cart') . "\n\n";
        
        /* translators: %s: Order number */
        $message .= sprintf(
            // translators: %s: Order number
            esc_html__('Order #%s', 'wish-cart') . "\n",
            esc_html($order_info['order_number'])
        );
        
        /* translators: %s: Order status */
        $message .= sprintf(
            // translators: %s: Order status
            esc_html__('Status: %s', 'wish-cart') . "\n",
            esc_html($order_info['status'])
        );
        
        /* translators: %s: Order date */
        $message .= sprintf(
            // translators: %s: Order date
            esc_html__('Date: %s', 'wish-cart') . "\n",
            esc_html($order_info['date_created'])
        );
        
        /* translators: %s: Order total amount */
        $message .= sprintf(
            // translators: %s: Order total
            esc_html__('Total: %s', 'wish-cart') . "\n\n",
            esc_html($order_info['total'])
        );

        if ( ! empty($order_info['items']) ) {
            $message .= esc_html__('*Items:*', 'wish-cart') . "\n";
            foreach ( $order_info['items'] as $item ) {
                /* translators: 1: Item quantity, 2: Item name, 3: Item total */
                $message .= sprintf(
                    // translators: %s: Item quantity
                    esc_html__('• %1$dx %2$s (%3$s)', 'wish-cart') . "\n",
                    esc_html($item['quantity']),
                    esc_html($item['name']),
                    esc_html($item['total'])
                );
            }
        }

        return $message;
    }

    /**
     * Send message to user via Twilio API.
     *
     * @param string $to      Recipient's phone number.
     * @param string $message Message content.
     *
     * @since 1.0.0
     *
     * @return bool Success status.
     */
    private function send_message_to_twilio( $to, $message, $product_image=false ) {
        if ( empty($this->account_sid) || empty($this->auth_token) || empty($this->from_number) ) {
            return false;
        }

        $url = "https://api.twilio.com/2010-04-01/Accounts/{$this->account_sid}/Messages.json";

        // Define supported image extensions
        $supported_extensions = ['jpg', 'jpeg', 'png', 'gif'];

        // Check if product image URL is valid
        $is_valid_image = false;
        if ($product_image !== false) {
            $parsed_url = wp_parse_url($product_image);
            if (isset($parsed_url['path'])) {
                $ext = strtolower(pathinfo($parsed_url['path'], PATHINFO_EXTENSION));
                if (in_array($ext, $supported_extensions)) {
                    $is_valid_image = true;
                }
            }
        }

        // Prepare request body
        if (!$is_valid_image) {
            $body = [
                'To' => $to,
                'From' => $this->from_number,
                'Body' => $message,
            ];
        } else {
            $body = [
                'To' => $to,
                'From' => $this->from_number,
                'Body' => $message,
                'MediaUrl' => $product_image,
            ];
        }

        $args = [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($this->account_sid . ':' . $this->auth_token),
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => $body,
            'timeout' => 15,
        ];

        $response = wp_remote_post($url, $args);

        if ( is_wp_error($response) ) {
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ( 201 !== $response_code ) {
            return false;
        }

        return true;
    }

    /**
     * Format response for Twilio webhook.
     *
     * @param string|null $message Optional message to include.
     *
     * @since 1.0.0
     *
     * @return string XML response.
     */
    private function format_twilio_response( $message = null ) {
        $twiml = new SimpleXMLElement('<Response/>');

        if ( $message ) {
            $msg = $twiml->addChild('Message');
            $msg->addChild('Body', esc_xml($message));
        }

        header('Content-Type: text/xml');
        return $twiml->asXML();
    }

    /**
     * Escape string for XML output
     *
     * @param string $string String to escape
     * @return string Escaped string
     */
    private function esc_xml($string) {
        return htmlspecialchars($string, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}

new WISHCART_WhatsApp_Handler();
