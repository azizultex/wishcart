<?php
if ( ! defined('ABSPATH') ) {
    exit;
}

/**
 * Handles order verification, authentication and status management
 *
 * @category Functionality
 * @package  AISK
 * @author   Aisk Team <support@aisk.chat>
 * @license  GPL-2.0+ https://www.gnu.org/licenses/gpl-2.0.html
 * @link     https://aisk.chat
 */

/**
 * AISK_Order_Handler Class
 *
 * Manages order verification, authentication and retrieval of order details
 *
 * @category Class
 * @package  AISK
 * @author   Aisk Team <support@aisk.chat>
 * @license  GPL-2.0+ https://www.gnu.org/licenses/gpl-2.0.html
 * @link     https://aisk.chat
 */
class AISK_Order_Handler {

    /**
     * Constructor
     *
     * @since 1.0.0
     */
    public function __construct() {
    }

    /**
     * Singleton instance of the class
     *
     * @since 1.0.0
     * @var   AISK_Order_Handler|null
     */
    public static $instance = null;

    /**
     * Get singleton instance of the class
     *
     * @since  1.0.0
     * @return AISK_Order_Handler Instance of this class
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Get order status and handle authentication flow
     *
     * @param array $intent Intent data containing order info (order number, email, OTP)
     *
     * @since 1.0.0
     *
     * @return array Response containing message, type and optional order info
     */
    public function get_order_status($intent) {
        // Extract order info from intent
        $order_info = isset($intent['order_info']) ? $intent['order_info'] : [];
        $order_number = isset($order_info['order_number']) ? $order_info['order_number'] : null;
        $email = isset($order_info['email']) ? $order_info['email'] : null;
        $otp = isset($order_info['otp']) ? $order_info['otp'] : null;

        // Handle OTP verification if provided
        if ($otp && preg_match('/^\d{6}$/', $otp)) {
            return $this->handle_otp_verification($otp, $order_number);
        }

        // Get existing authentication state
        $transient_key = $order_number ? 'aisk_order_auth_' . $order_number : null;
        $auth_state = $transient_key ? get_transient($transient_key) : null;

        // Handle based on current state and provided information
        if ($auth_state) {
            switch ($auth_state['state']) {
                case 'need_email':
                    if ($email) {
                        return $this->initiate_email_verification($order_number, $email);
                    }
                    return [
                        'message' => "For order #$order_number, please provide the email address used for this order.",
                        'type' => 'need_email',
                    ];

                case 'waiting_for_otp':
                    if ($otp) {
                        return $this->handle_otp_verification($otp);
                    }
                    return [
                        'message' => 'Please enter the 6-digit verification code sent to ' . $auth_state['email'],
                        'type' => 'waiting_for_otp',
                    ];
            }
        }

        // Initialize new authentication process
        if ($order_number) {
            // Verify if order exists and is valid
            $order = AISK_FluentCart_Helper::get_order($order_number);

            if (!$order) {
                return [
                    'message' => "Sorry, I couldn't find order #$order_number. Please double-check your order number and try again.",
                    'type' => 'order_not_found',
                ];
            }

            // Store initial auth state only if order exists
            set_transient(
                $transient_key, [
                    'state' => 'need_email',
                    'order_number' => $order_number,
                ], 5 * MINUTE_IN_SECONDS
            );

            if ($email) {
                return $this->initiate_email_verification($order_number, $email);
            }

            return [
                'message' => "For order #$order_number, please provide the email address used for this order.",
                'type' => 'need_email',
            ];
        }

        if ($email && !$order_number) {
            return [
                'message' => "I have your email ($email). What's your order number?",
                'type' => 'need_order_number',
            ];
        }

        return [
            'message' => 'To check your order details, please provide your order number and the email address used for the order.',
            'type' => 'need_auth_info',
        ];
    }

    /**
     * Initialize email verification process
     *
     * @param string $order_number Order number to verify
     * @param string $email        Email address to verify
     *
     * @since 1.0.0
     *
     * @return array  Response with verification status and next steps
     */
    private function initiate_email_verification($order_number, $email) {
        $transient_key = 'aisk_order_auth_' . $order_number;

        $auth_result = $this->authenticate_user([
            'email' => $email,
            'order_id' => $order_number,
        ]);

        if (is_wp_error($auth_result)) {
            delete_transient($transient_key);
            return [
                'message' => "Sorry, the email address doesn't match our records for order #$order_number. Please try again with the correct email.",
                'type' => 'need_email',
            ];
        }

        // Store auth state with email
        set_transient(
            $transient_key, [
                'state' => 'waiting_for_otp',
                'order_number' => $order_number,
                'email' => $email,
            ], 15 * MINUTE_IN_SECONDS
        );

        return [
            'message' => "I've sent a verification code to $email. Please enter the 6-digit code to access your order details.",
            'type' => 'waiting_for_otp',
        ];
    }

    /**
     * Handle verification of submitted OTP
     *
     * @param string $otp One-time password to verify
     *
     * @since 1.0.0
     *
     * @return array Response with verification result and order details if successful
     */
    public function handle_otp_verification($otp, $order_number = null) {
        // Find the order being verified
        // Get existing authentication state
        $transient_key = $order_number ? 'aisk_order_auth_' . $order_number : null;
        $auth_state = $transient_key ? get_transient($transient_key) : null;

        if ('waiting_for_otp' === $auth_state['state']) {
            $verify_result = $this->verify_otp([
                'order_id' => $auth_state['order_number'],
                'otp' => $otp,
            ]);


            if (!is_wp_error($verify_result)) {
                delete_transient('aisk_order_auth_' . $auth_state['order_number']);
                return [
                    'message' => 'Authentication successful! Here are your order details:',
                    'type' => 'order_verified',
                    'order_info' => $this->get_order_by_id($auth_state['order_number']),
                ];
            }
        }

        return [
            'message' => 'Invalid or expired verification code. Please try again.',
            'type' => 'waiting_for_otp'
        ];
    }

    /**
     * Authenticate user with email and order ID
     *
     * @param array $params Authentication parameters containing email and order_id.
     *
     * @since 1.0.0
     *
     * @return array|WP_Error Authentication result or error object
     */
    public function authenticate_user( $params ) {
        if ( empty($params['email']) || empty($params['order_id']) ) {
            return new WP_Error('missing_params', 'Email and order ID are required.');
        }

        $order = AISK_FluentCart_Helper::get_order($params['order_id']);
        if ( ! $order ) {
            return new WP_Error('invalid_order', 'Order not found.');
        }

        if ( $order->get_billing_email() !== $params['email'] ) {
            return new WP_Error('invalid_email', 'Email does not match order.');
        }

        // Generate OTP
        $otp = $this->generate_otp($order->get_id());

        // Send OTP email
        $this->send_otp_email($params['email'], $otp);

        return [
            'success' => true,
            'message' => 'Authentication code sent to your email.',
        ];
    }

    /**
     * Verify one-time password for order authentication
     *
     * @param array $params Verification parameters containing otp and order_id.
     *
     * @since 1.0.0
     *
     * @return array|WP_Error Verification result or error object
     */
    public function verify_otp( $params ) {

        if ( empty($params['otp']) || empty($params['order_id']) ) {
            return new WP_Error('missing_params', 'OTP and order ID are required.');
        }

        $stored_otp = get_transient('aisk_otp_' . $params['order_id']);
        if ( ! $stored_otp || $stored_otp !== $params['otp'] ) {
            return new WP_Error('invalid_otp', 'Invalid or expired authentication code.');
        }

        // Delete used OTP
        delete_transient('aisk_otp_' . $params['order_id']);

        return [
            'success' => true,
        ];
    }

    /**
     * Format order status response for display
     *
     * @param array $response Response data containing order info
     *
     * @since 1.0.0
     *
     * @return string Formatted status message
     */
    public function format_order_status_response($response) {
        if (empty($response['order_info'])) {
            /* translators: Error message shown when no order is found matching the provided information */
            return esc_html__("I couldn't find any order matching your information. Please check the order number and try again.", 'aisk-ai-chat-for-fluentcart');
        }

        return sprintf(
            /* translators: 1: Order number, 2: Order status, 3: Status details */
            esc_html__('Your order #%1$s is currently %2$s. %3$s', 'aisk-ai-chat-for-fluentcart'),
            esc_html($response['order_info']['order_number']),
            esc_html($response['order_info']['status']),
            esc_html($response['order_info']['status_details'])
        );
    }

    /**
     * Get detailed status message based on order status
     *
     * @param string $status Order status
     *
     * @since 1.0.0
     *
     * @return string Detailed status message
     */
    private function get_status_details( $status ) {
        $details = [
            'pending' => esc_html__('We are waiting for payment confirmation.', 'aisk-ai-chat-for-fluentcart'),
            'processing' => esc_html__('We are preparing your order for shipment.', 'aisk-ai-chat-for-fluentcart'),
            'on-hold' => esc_html__('Your order is currently on hold. Please check your email for more information.', 'aisk-ai-chat-for-fluentcart'),
            'completed' => esc_html__('Your order has been delivered.', 'aisk-ai-chat-for-fluentcart'),
            'cancelled' => esc_html__('This order has been cancelled.', 'aisk-ai-chat-for-fluentcart'),
            'refunded' => esc_html__('This order has been refunded.', 'aisk-ai-chat-for-fluentcart'),
            'failed' => esc_html__('There was an issue processing this order.', 'aisk-ai-chat-for-fluentcart'),
        ];

        return isset( $details[ $status ] ) ? $details[ $status ] : '';
    }

    /**
     * Get order details by order number
     *
     * @param string $order_number Order number to lookup
     *
     * @since 1.0.0
     *
     * @return array|null Order details or null if not found
     */
    private function get_order_by_number( $order_number ) {
        $order_id = $this->find_order_by_number_helper($order_number);

        if ( ! $order_id ) {
            return null;
        }

        return $this->get_order_by_id($order_id);
    }

    /**
     * Find order by order number helper
     *
     * @param string $order_number Order number
     * @return int|null Order ID or null
     */
    private function find_order_by_number_helper( $order_number ) {
        global $wpdb;
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $order_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} 
            WHERE meta_key = '_order_number' AND meta_value = %s 
            LIMIT 1",
            $order_number
        ) );

        if ( $order_id ) {
            return intval( $order_id );
        }

        // Fallback: try to find by post title
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $order_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} 
            WHERE post_type IN (%s, %s) AND (post_title = %s OR ID = %s) 
            LIMIT 1",
            AISK_FluentCart_Helper::get_order_post_type(),
            'shop_order',
            $order_number,
            $order_number
        ) );

        return $order_id ? intval( $order_id ) : null;
    }

    /**
     * Get recent orders for a user
     *
     * @param int $user_id WordPress user ID
     *
     * @since 1.0.0
     *
     * @return array|null Array of recent orders or null if none found
     */
    private function get_recent_orders( $user_id ) {
        $orders = AISK_FluentCart_Helper::get_orders(
            [
				'customer_id' => $user_id,
				'limit' => 5,
				'orderby' => 'date',
				'order' => 'DESC',
            ]
        );

        if ( empty($orders) ) {
            return null;
        }

        $formatted_orders = [];
        foreach ( $orders as $order ) {
            $formatted_orders[] = [
                'order_number' => $order->get_order_number(),
                'status' => $order->get_status(),
                'date_created' => $order->get_date_created()->format('Y-m-d'),
                'total' => $order->get_formatted_order_total(),
            ];
        }

        return $formatted_orders;
    }

    /**
     * Get formatted order items
     *
     * @param object $order FluentCart order object
     *
     * @since 1.0.0
     *
     * @return array Array of formatted order items
     */
    private function get_order_items( $order ) {
        $items = [];
        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            if ( ! $product ) {
				continue;
            }

            // Strip HTML tags and decode entities for the total
            $total = $order->get_formatted_line_subtotal($item);
            $total = html_entity_decode(wp_strip_all_tags($total));

            $items[] = [
                'name' => $item->get_name(),
                'quantity' => $item->get_quantity(),
                'total' => $total,
                'product_id' => $product->get_id(),
                'image' => wp_get_attachment_image_url($product->get_image_id(), 'thumbnail'),
            ];
        }
        return $items;
    }

    /**
     * Get detailed order information by ID
     *
     * @param int $order_id FluentCart order ID
     *
     * @since 1.0.0
     *
     * @return array|null Order details or null if not found
     */
    private function get_order_by_id( $order_id ) {
        $order = AISK_FluentCart_Helper::get_order($order_id);

        if ( ! $order ) {
            return null;
        }

        return [
            'order_id' => $order->get_id(),
            'order_number' => $order->get_order_number(),
            'status' => $order->get_status(),
            'date_created' => $order->get_date_created()->format('Y-m-d H:i:s'),
            'total' => html_entity_decode(wp_strip_all_tags($order->get_formatted_order_total())), // Clean total
            'shipping_method' => $order->get_shipping_method(),
            'shipping_address' => $this->format_address($order->get_address('shipping')),
            'tracking_number' => $order->get_meta('_tracking_number'),
            'estimated_delivery' => $order->get_meta('_estimated_delivery'),
            'items' => $this->get_order_items($order),
        ];
    }

    /**
     * Format shipping/billing address
     *
     * @param array $address Address components
     *
     * @since 1.0.0
     *
     * @return string Formatted address string
     */
    private function format_address( $address ) {
        $formatted = [];
        if ( ! empty($address['address_1']) ) {
			$formatted[] = $address['address_1'];
        }
        if ( ! empty($address['address_2']) ) {
			$formatted[] = $address['address_2'];
        }
        if ( ! empty($address['city']) ) {
			$formatted[] = $address['city'];
        }
        if ( ! empty($address['state']) ) {
			$formatted[] = $address['state'];
        }
        if ( ! empty($address['postcode']) ) {
			$formatted[] = $address['postcode'];
        }
        if ( ! empty($address['country']) ) {
            // Try to get country name from WC if available, otherwise use code
            if ( function_exists( 'WC' ) && WC()->countries ) {
                $countries = WC()->countries->get_countries();
                if ( isset( $countries[ $address['country'] ] ) ) {
                    $formatted[] = $countries[ $address['country'] ];
                } else {
                    $formatted[] = $address['country'];
                }
            } else {
                $formatted[] = $address['country'];
            }
        }

        return implode(', ', $formatted);
    }

    /**
     * Generate one-time password for order authentication
     *
     * @param int $order_id FluentCart order ID
     *
     * @since 1.0.0
     *
     * @return string Generated OTP
     */
    private function generate_otp( $order_id ) {
        $otp = wp_rand(100000, 999999);
        set_transient('aisk_otp_' . $order_id, $otp, 15 * MINUTE_IN_SECONDS);
        return $otp;
    }

    /**
     * Send OTP email to customer
     *
     * @param string $email Email address to send to
     * @param string $otp   One-time password to send
     *
     * @since 1.0.0
     *
     * @return void
     */
    private function send_otp_email( $email, $otp ) {
        $subject = 'Your Order Authentication Code';
        $message = sprintf(
            "Your authentication code is: %s\nThis code will expire in 15 minutes.",
            $otp
        );

        
        wp_mail(
            $email, $subject, $message, [
				'Content-Type: text/plain; charset=UTF-8',
            ]
        );
    }
}
