<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * FluentCart Helper functionality
 *
 * Provides FluentCart compatibility functions to replace WooCommerce functions
 *
 * @category Functionality
 * @package  AISK
 * @author   WishCart Team <support@wishcart.chat>
 * @license  GPL-2.0+ https://www.gnu.org/licenses/gpl-2.0.html
 * @link     https://wishcart.chat
 */

/**
 * AISK FluentCart Product Wrapper Class
 */
class WISHCART_FluentCart_Product {
    private $post_id;
    private $post;
    private $meta_cache = [];
    private $variants = null;

    public function __construct( $post_id ) {
        if ( is_object( $post_id ) && isset( $post_id->ID ) ) {
            $this->post_id = $post_id->ID;
            $this->post = $post_id;
        } else {
            $this->post_id = $post_id;
            $this->post = get_post( $post_id );
        }
    }

    public function get_id() {
        return $this->post_id;
    }

    public function get_name() {
        return $this->post ? $this->post->post_title : '';
    }

    /**
     * Get product price from FluentCart's native API
     *
     * @return float Product price in decimal format (converted from cents)
     */
    public function get_price() {
        $variant = $this->get_default_variant();
        if ( $variant && isset( $variant['item_price'] ) ) {
            return $this->convert_from_cents( $variant['item_price'] );
        }
        return 0;
    }

    /**
     * Get regular price from FluentCart's native API
     *
     * @return float Regular price in decimal format (converted from cents)
     */
    public function get_regular_price() {
        $variant = $this->get_default_variant();
        if ( $variant && isset( $variant['compare_price'] ) && $variant['compare_price'] > 0 ) {
            return $this->convert_from_cents( $variant['compare_price'] );
        }
        return $this->get_price();
    }

    /**
     * Get sale price from FluentCart's native API
     *
     * @return float Sale price in decimal format (converted from cents) or null if not on sale
     */
    public function get_sale_price() {
        if ( $this->is_on_sale() ) {
            return $this->get_price();
        }
        return null;
    }

    /**
     * Check if product is on sale
     *
     * @return bool True if product is on sale
     */
    public function is_on_sale() {
        $variant = $this->get_default_variant();
        if ( ! $variant ) {
            return false;
        }
        
        $compare_price = isset( $variant['compare_price'] ) ? $variant['compare_price'] : 0;
        $item_price = isset( $variant['item_price'] ) ? $variant['item_price'] : 0;
        
        return $compare_price > $item_price && $item_price > 0;
    }

    public function is_in_stock() {
        $variant = $this->get_default_variant();
        if ( $variant && isset( $variant['stock_status'] ) ) {
            return $variant['stock_status'] !== 'out-of-stock';
        }
        return true; // Default to in stock if variant not found
    }

    public function get_image_id() {
        return get_post_thumbnail_id( $this->post_id );
    }

    public function get_short_description() {
        return $this->post ? $this->post->post_excerpt : '';
    }

    public function get_average_rating() {
        $rating = $this->get_meta( '_average_rating', 0 );
        return floatval( $rating );
    }

    public function get_parent_id() {
        return $this->post ? $this->post->post_parent : 0;
    }

    /**
     * Get product variants using FluentCart's native API
     *
     * @return array|null Array of variants or null if error
     */
    private function get_variants() {
        if ( $this->variants !== null ) {
            return $this->variants;
        }

        // Try to use FluentCart's native API if available
        if ( class_exists( '\FluentCart\Api\Resource\ProductResource' ) ) {
            try {
                $product_data = \FluentCart\Api\Resource\ProductResource::find( $this->post_id );
                if ( $product_data && isset( $product_data['variants'] ) ) {
                    $this->variants = $product_data['variants'];
                    return $this->variants;
                }
            } catch ( Exception $e ) {
                // Fallback to direct database query
            }
        }

        // Fallback: Direct database query
        global $wpdb;
        $table_name = $wpdb->prefix . 'fct_product_variations';
        
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        if ( $wpdb->get_var( "SHOW TABLES LIKE '$table_name'" ) === $table_name ) {
            $variants = $wpdb->get_results( $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT * FROM {$table_name} WHERE post_id = %d ORDER BY serial_index ASC",
                $this->post_id
            ), ARRAY_A );

            if ( is_array( $variants ) ) {
                $this->variants = $variants;
                return $this->variants;
            }
        }

        $this->variants = [];
        return $this->variants;
    }

    /**
     * Get the default/first variant for the product
     *
     * @return array|null Variant data or null
     */
    private function get_default_variant() {
        $variants = $this->get_variants();
        if ( ! empty( $variants ) && is_array( $variants ) ) {
            return reset( $variants );
        }
        return null;
    }

    /**
     * Convert price from cents to decimal format
     *
     * @param int|float $amount Amount in cents
     * @return float Amount in decimal format
     */
    private function convert_from_cents( $amount ) {
        if ( ! is_numeric( $amount ) ) {
            return 0;
        }
        
        // Use FluentCart's Helper if available
        if ( class_exists( '\FluentCart\App\Helpers\Helper' ) ) {
            return \FluentCart\App\Helpers\Helper::toDecimal( $amount, false, null, false );
        }
        
        // Fallback conversion: divide by 100
        return floatval( $amount ) / 100;
    }

    private function get_meta( $key, $default = '' ) {
        if ( ! isset( $this->meta_cache[ $key ] ) ) {
            $this->meta_cache[ $key ] = get_post_meta( $this->post_id, $key, true );
        }
        return $this->meta_cache[ $key ] !== '' ? $this->meta_cache[ $key ] : $default;
    }
}

/**
 * AISK FluentCart Order Wrapper Class
 */
class WISHCART_FluentCart_Order {
    private $fc_order;
    private $meta_cache = [];

    public function __construct( $fc_order_or_id ) {
        // Check if it's a FluentCart Order model object
        if ( is_object( $fc_order_or_id ) && method_exists( $fc_order_or_id, 'getAttribute' ) ) {
            $this->fc_order = $fc_order_or_id;
        } else {
            // Fallback for old behavior (should not be used, but kept for compatibility)
            $this->fc_order = null;
        }
    }

    public function get_id() {
        if ( ! $this->fc_order ) {
            return 0;
        }
        return $this->fc_order->id;
    }

    public function get_order_number() {
        if ( ! $this->fc_order ) {
            return '';
        }
        // Return receipt_number if available, otherwise invoice_no, fallback to ID
        return $this->fc_order->receipt_number ? $this->fc_order->receipt_number : 
               ( $this->fc_order->invoice_no ? $this->fc_order->invoice_no : (string) $this->fc_order->id );
    }

    public function get_status() {
        if ( ! $this->fc_order ) {
            return '';
        }
        return $this->fc_order->status;
    }

    public function get_billing_email() {
        if ( ! $this->fc_order ) {
            return '';
        }
        
        // Get customer email from relationship
        if ( $this->fc_order->customer ) {
            return $this->fc_order->customer->email;
        }
        
        // Fallback to billing address email if available
        if ( $this->fc_order->billing_address ) {
            return isset( $this->fc_order->billing_address->email ) ? $this->fc_order->billing_address->email : '';
        }
        
        return '';
    }

    public function get_billing_phone() {
        if ( ! $this->fc_order ) {
            return '';
        }
        
        // FluentCart stores phone in CustomerAddresses, not OrderAddress
        // Try to get from customer's primary billing address if available
        if ( $this->fc_order->customer && $this->fc_order->customer->primary_billing_address ) {
            return isset( $this->fc_order->customer->primary_billing_address->phone ) ? $this->fc_order->customer->primary_billing_address->phone : '';
        }
        
        return '';
    }

    public function get_date_created() {
        if ( ! $this->fc_order ) {
            return null;
        }
        return new WISHCART_FluentCart_DateTime( $this->fc_order->created_at );
    }

    public function get_formatted_order_total() {
        if ( ! $this->fc_order ) {
            return '';
        }
        
        $total = $this->fc_order->total_amount;
        $currency = $this->fc_order->currency;
        
        // Convert from cents to decimal if using FluentCart's Helper
        if ( class_exists( '\FluentCart\App\Helpers\Helper' ) ) {
            $total_decimal = \FluentCart\App\Helpers\Helper::toDecimal( $total, false, $currency, false );
        } else {
            $total_decimal = floatval( $total ) / 100;
        }
        
        // Use wc_price if available, otherwise format manually
        if ( function_exists( 'wc_price' ) ) {
            return wc_price( $total_decimal, [ 'currency' => $currency ] );
        }
        
        return $currency . ' ' . number_format( $total_decimal, 2 );
    }

    public function get_shipping_method() {
        if ( ! $this->fc_order ) {
            return '';
        }
        // FluentCart doesn't store shipping method as a simple field, so return empty
        // Can be extended later if needed
        return $this->get_meta( '_shipping_method', '' );
    }

    public function get_address( $type = 'billing' ) {
        if ( ! $this->fc_order ) {
            return [
                'address_1' => '',
                'address_2' => '',
                'city' => '',
                'state' => '',
                'postcode' => '',
                'country' => '',
            ];
        }
        
        $address = [];
        
        // Get address from appropriate relationship
        if ( $type === 'shipping' && $this->fc_order->shipping_address ) {
            $fc_address = $this->fc_order->shipping_address;
            $address = [
                'address_1' => isset( $fc_address->address_1 ) ? $fc_address->address_1 : '',
                'address_2' => isset( $fc_address->address_2 ) ? $fc_address->address_2 : '',
                'city' => isset( $fc_address->city ) ? $fc_address->city : '',
                'state' => isset( $fc_address->state ) ? $fc_address->state : '',
                'postcode' => isset( $fc_address->postcode ) ? $fc_address->postcode : '',
                'country' => isset( $fc_address->country ) ? $fc_address->country : '',
            ];
        } elseif ( $type === 'billing' && $this->fc_order->billing_address ) {
            $fc_address = $this->fc_order->billing_address;
            $address = [
                'address_1' => isset( $fc_address->address_1 ) ? $fc_address->address_1 : '',
                'address_2' => isset( $fc_address->address_2 ) ? $fc_address->address_2 : '',
                'city' => isset( $fc_address->city ) ? $fc_address->city : '',
                'state' => isset( $fc_address->state ) ? $fc_address->state : '',
                'postcode' => isset( $fc_address->postcode ) ? $fc_address->postcode : '',
                'country' => isset( $fc_address->country ) ? $fc_address->country : '',
            ];
        } else {
            $address = [
                'address_1' => '',
                'address_2' => '',
                'city' => '',
                'state' => '',
                'postcode' => '',
                'country' => '',
            ];
        }
        
        return $address;
    }

    public function get_items() {
        if ( ! $this->fc_order ) {
            return [];
        }
        
        $items = [];
        
        // Get order items from relationship
        if ( $this->fc_order->order_items ) {
            foreach ( $this->fc_order->order_items as $fc_item ) {
                $items[] = new WISHCART_FluentCart_Order_Item( [
                    'id' => $fc_item->id,
                    'name' => $fc_item->title,
                    'product_id' => $fc_item->post_id,
                    'quantity' => $fc_item->quantity,
                    'total' => $fc_item->line_total / 100, // Convert from cents to decimal
                ] );
            }
        }
        
        return $items;
    }

    public function get_formatted_line_subtotal( $item ) {
        if ( is_object( $item ) && method_exists( $item, 'get_total' ) ) {
            $total = $item->get_total();
        } elseif ( is_array( $item ) && isset( $item['total'] ) ) {
            $total = $item['total'];
        } else {
            return '';
        }

        if ( function_exists( 'wc_price' ) ) {
            return wc_price( floatval( $total ) );
        }
        return number_format( floatval( $total ), 2 );
    }

    public function get_meta( $key, $default = '' ) {
        if ( ! $this->fc_order ) {
            return $default;
        }
        
        if ( ! isset( $this->meta_cache[ $key ] ) ) {
            // Get meta from order_meta relationship
            if ( $this->fc_order->orderMeta ) {
                foreach ( $this->fc_order->orderMeta as $meta ) {
                    if ( $meta->meta_key === $key ) {
                        $this->meta_cache[ $key ] = $meta->meta_value;
                        break;
                    }
                }
            }
            
            // If not found, return default
            if ( ! isset( $this->meta_cache[ $key ] ) ) {
                $this->meta_cache[ $key ] = $default;
            }
        }
        
        return $this->meta_cache[ $key ];
    }
}

/**
 * AISK FluentCart Order Item Wrapper Class
 */
class WISHCART_FluentCart_Order_Item {
    private $item_data;

    public function __construct( $item_data ) {
        $this->item_data = $item_data;
    }

    public function get_name() {
        return isset( $this->item_data['name'] ) ? $this->item_data['name'] : '';
    }

    public function get_quantity() {
        return isset( $this->item_data['quantity'] ) ? intval( $this->item_data['quantity'] ) : 1;
    }

    public function get_product() {
        $product_id = isset( $this->item_data['product_id'] ) ? $this->item_data['product_id'] : null;
        return $product_id ? WISHCART_FluentCart_Helper::get_product( $product_id ) : null;
    }

    public function get_total() {
        return isset( $this->item_data['total'] ) ? floatval( $this->item_data['total'] ) : 0;
    }
}

/**
 * AISK FluentCart DateTime Wrapper Class
 */
class WISHCART_FluentCart_DateTime {
    private $datetime;

    public function __construct( $datetime_string ) {
        $this->datetime = new DateTime( $datetime_string );
    }

    public function format( $format ) {
        return $this->datetime->format( $format );
    }
}

/**
 * WISHCART_FluentCart_Helper Class
 *
 * Handles FluentCart-specific operations for products and orders
 *
 * @category Class
 * @package  AISK
 * @author   WishCart Team <support@wishcart.chat>
 * @license  GPL-2.0+ https://www.gnu.org/licenses/gpl-2.0.html
 * @link     https://wishcart.chat
 */
class WISHCART_FluentCart_Helper {

    /**
     * Cached result of FluentCart detection to avoid repeated checks
     *
     * @var bool|null
     */
    private static $is_active_cache = null;

    /**
     * Check if FluentCart is active
     *
     * Checks if the FluentCart plugin is active using WordPress is_plugin_active function.
     * Results are cached to avoid repeated expensive checks.
     *
     * @return bool
     */
    public static function is_fluentcart_active() {
        // Return cached result if available
        if ( self::$is_active_cache !== null ) {
            return self::$is_active_cache;
        }

        // Include plugin.php if needed
        if ( ! function_exists( 'is_plugin_active' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        // Check for both possible plugin paths
        $possible_paths = [
            'fluent-cart/fluent-cart.php', // WordPress.org version
            'fluentcart/fluentcart.php',   // Alternative version
            'fluentcart-pro/fluentcart-pro.php', // Pro version
        ];

        foreach ( $possible_paths as $path ) {
            if ( is_plugin_active( $path ) ) {
                self::$is_active_cache = true;
                return true;
            }
        }

        // Plugin is not active
        self::$is_active_cache = false;
        return false;
    }

    /**
     * Clear the FluentCart detection cache
     *
     * Useful when plugins are activated/deactivated
     *
     * @return void
     */
    public static function clear_detection_cache() {
        self::$is_active_cache = null;
    }

    /**
     * Get FluentCart product post type
     *
     * @return string
     */
    public static function get_product_post_type() {
        // Try to auto-detect common FluentCart product post type slugs
        $candidates = [ 'fc_product', 'fluent-products', 'fluent_product', 'fluentcart_product' ];
        foreach ( $candidates as $slug ) {
            if ( post_type_exists( $slug ) ) {
                return apply_filters( 'wishcart_fluentcart_product_post_type', $slug );
            }
        }
        // Fallback to default
        return apply_filters( 'wishcart_fluentcart_product_post_type', 'fc_product' );
    }

    /**
     * Get FluentCart order post type
     *
     * @return string
     */
    public static function get_order_post_type() {
        return apply_filters( 'wishcart_fluentcart_order_post_type', 'fc_order' );
    }

    /**
     * Get product by ID (replaces wc_get_product)
     *
     * @param int|WP_Post $product_id Product ID or post object
     * @return WISHCART_FluentCart_Product|null FluentCart product object or null
     */
    public static function get_product( $product_id ) {
        if ( ! self::is_fluentcart_active() ) {
            return null;
        }

        if ( is_object( $product_id ) && isset( $product_id->ID ) ) {
            $product_id = $product_id->ID;
        }

        $post = get_post( $product_id );
        if ( ! $post ) {
            return null;
        }

        // Check if it's a FluentCart product post type
        $product_type = self::get_product_post_type();
        if ( $post->post_type !== $product_type && $post->post_type !== 'product' ) {
            return null;
        }

        return new WISHCART_FluentCart_Product( $post );
    }

    /**
     * Get order by ID (replaces wc_get_order)
     *
     * @param int|string $order_id Order ID or order number
     * @return WISHCART_FluentCart_Order|null FluentCart order object or null
     */
    public static function get_order( $order_id ) {
        if ( ! self::is_fluentcart_active() ) {
            return null;
        }

        // Try to find by order number if string
        if ( is_string( $order_id ) && ! is_numeric( $order_id ) ) {
            $order_id = self::find_order_by_number( $order_id );
            if ( ! $order_id ) {
                return null;
            }
        }

        // Use FluentCart's native Order model
        if ( class_exists( '\FluentCart\App\Models\Order' ) ) {
            try {
                $fc_order = \FluentCart\App\Models\Order::with( [ 
                    'customer', 
                    'customer.primary_billing_address',
                    'customer.primary_shipping_address',
                    'billing_address', 
                    'shipping_address', 
                    'order_items', 
                    'orderMeta' 
                ] )
                    ->find( $order_id );
                
                if ( $fc_order ) {
                    return new WISHCART_FluentCart_Order( $fc_order );
                }
            } catch ( Exception $e ) {
                return null;
            }
        }

        return null;
    }

    /**
     * Find order by order number
     *
     * @param string $order_number Order number
     * @return int|null Order ID or null
     */
    public static function find_order_by_number( $order_number ) {
        global $wpdb;
        
        // Try FluentCart's native table first
        $table_name = $wpdb->prefix . 'fct_orders';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        if ( $wpdb->get_var( "SHOW TABLES LIKE '$table_name'" ) === $table_name ) {
            $order_id = $wpdb->get_var( $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT id FROM {$table_name} 
                WHERE id = %d OR invoice_no = %s OR receipt_number = %s 
                LIMIT 1",
                intval( $order_number ),
                $order_number,
                $order_number
            ) );
            
            if ( $order_id ) {
                return intval( $order_id );
            }
        }
        
        return null;
    }

    /**
     * Get featured product IDs (replaces wc_get_featured_product_ids)
     *
     * @return array Featured product IDs
     */
    public static function get_featured_product_ids() {
        if ( ! self::is_fluentcart_active() ) {
            return [];
        }

        $args = [
            'post_type' => [ self::get_product_post_type(), 'product' ],
            'posts_per_page' => -1,
            'post_status' => 'publish',
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
            'meta_query' => [
                [
                    'key' => '_featured',
                    'value' => 'yes',
                    'compare' => '=',
                ],
            ],
            'fields' => 'ids',
        ];

        $query = new WP_Query( $args );
        return $query->posts;
    }

    /**
     * Get orders (replaces wc_get_orders)
     *
     * @param array $args Query arguments
     * @return array Order objects
     */
    public static function get_orders( $args = [] ) {
        if ( ! self::is_fluentcart_active() ) {
            return [];
        }

        // Use FluentCart's native Order model
        if ( class_exists( '\FluentCart\App\Models\Order' ) ) {
            try {
                $query = \FluentCart\App\Models\Order::with( [ 
                    'customer', 
                    'customer.primary_billing_address',
                    'customer.primary_shipping_address',
                    'billing_address', 
                    'shipping_address', 
                    'order_items', 
                    'orderMeta' 
                ] );
                
                // Handle customer_id
                if ( isset( $args['customer_id'] ) ) {
                    $query->where( 'customer_id', $args['customer_id'] );
                }
                
                // Handle limit
                $limit = isset( $args['limit'] ) ? intval( $args['limit'] ) : -1;
                if ( $limit > 0 ) {
                    $query->limit( $limit );
                }
                
                // Handle orderby and order
                $orderby = isset( $args['orderby'] ) ? $args['orderby'] : 'id';
                $order = isset( $args['order'] ) ? $args['order'] : 'DESC';
                $query->orderBy( $orderby, $order );
                
                $fc_orders = $query->get();
                $orders = [];
                
                foreach ( $fc_orders as $fc_order ) {
                    $orders[] = new WISHCART_FluentCart_Order( $fc_order );
                }
                
                return $orders;
            } catch ( Exception $e ) {
                return [];
            }
        }

        return [];
    }

    /**
     * Get placeholder image source (replaces wc_placeholder_img_src)
     *
     * @param string $size Image size
     * @return string Image URL
     */
    public static function placeholder_img_src( $size = 'medium' ) {
        $placeholder_id = get_option( 'woocommerce_placeholder_image', 0 );
        if ( $placeholder_id ) {
            $image = wp_get_attachment_image_url( $placeholder_id, $size );
            if ( $image ) {
                return $image;
            }
        }
        // Fallback placeholder
        return WISHCART_PLUGIN_URL . 'assets/images/placeholder.png';
    }

    /**
     * Get capability for managing e-commerce (replaces manage_woocommerce)
     *
     * @return string Capability name
     */
    public static function get_manage_capability() {
        return apply_filters( 'wishcart_fluentcart_manage_capability', 'manage_options' );
    }
}
