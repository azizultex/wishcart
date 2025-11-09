<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Product Handler functionality
 *
 * @category Functionality
 * @package  AISK
 * @author   WishCart Team <support@wishcart.chat>
 * @license  GPL-2.0+ https://www.gnu.org/licenses/gpl-2.0.html
 * @link     https://wishcart.chat
 */

/**
 * WISHCART_Product_Handler Class
 *
 * Handles product-related operations including searching and formatting product data
 *
 * @category Class
 * @package  AISK
 * @author   WishCart Team <support@wishcart.chat>
 * @license  GPL-2.0+ https://www.gnu.org/licenses/gpl-2.0.html
 * @link     https://wishcart.chat
 */
class WISHCART_Product_Handler {

    public function __construct() {
    }
    public static $instance = null;

    /**
     * Gets singleton instance of the class
     *
     * @return WISHCART_Product_Handler Instance of this class
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Search for products based on provided arguments
     *
     * @param array $args WP_Query arguments for product search
     *
     * @return array Array of formatted product data
     */
    public function search_products( $product_ids ) {
        if ( empty( $product_ids ) || ! is_array( $product_ids ) ) {
            return [];
        }

        
        $products = [];

        foreach ( $product_ids as $product_id ) {
            $product = WISHCART_FluentCart_Helper::get_product( $product_id );

            if ( ! $product ) {
                continue;
            }

            $products[] = [
                'id' => $product->get_id(),
                'name' => $product->get_name(),
                'price' => $product->get_price(),
                'url' => get_permalink($product->get_id()),
                'image' => wp_get_attachment_image_url($product->get_image_id(), 'full'),
                'description' => wp_trim_words($product->get_short_description(), 20),
                'in_stock' => $product->is_in_stock(),
                'average_rating' => $product->get_average_rating(),
            ];
        }

                return $products;
    }

    public function search_products_info( $product_ids ) {
        if ( empty( $product_ids ) || ! is_array( $product_ids ) ) {
            return [];
        }

        
        $products = [];

        foreach ( $product_ids as $product_id ) {
            $product = WISHCART_FluentCart_Helper::get_product( $product_id );

            if ( ! $product ) {
                continue;
            }

            $products[] = [
                'id' => $product->get_id(),
                'name' => $product->get_name(),
                'price' => $product->get_price(),
                'url' => get_permalink($product->get_id()),
                'image' => wp_get_attachment_image_url($product->get_image_id(), 'full'),
                'description' => wp_trim_words($product->get_short_description(), 20),
                'in_stock' => $product->is_in_stock(),
                'average_rating' => $product->get_average_rating(),
            ];
        }

                return $products;
    }


    /**
     * Get featured products from the store
     *
     * @param int $limit Number of products to return, defaults to 6
     *
     * @return array Array of formatted featured products
     */
    public function get_featured_products( $limit = 6 ) {
        // Get featured product IDs using FluentCart helper
        $featured_ids = WISHCART_FluentCart_Helper::get_featured_product_ids();
        
        // Limit the number of products
        $featured_ids = array_slice($featured_ids, 0, $limit);
        
        // Format and return the products
        return $this->format_products(array_map('get_post', $featured_ids));
    }

    /**
     * Format product data into a consistent structure
     *
     * @param array $posts Array of WP_Post objects
     *
     * @return array Formatted product data
     */
    private function format_products( $posts ) {
        $products = [];
        foreach ( $posts as $post ) {
            $product = WISHCART_FluentCart_Helper::get_product($post);
            if ( ! $product ) {
				continue;
            }

            $products[] = [
                'id' => $product->get_id(),
                'name' => $product->get_name(),
                'price' => $product->get_price(),
                'url' => get_permalink($product->get_id()),
                'image' => wp_get_attachment_image_url($product->get_image_id(), 'medium'),
                'description' => wp_trim_words($product->get_short_description(), 20),
                'in_stock' => $product->is_in_stock(),
                'average_rating' => $product->get_average_rating(),
            ];
        }
        return $products;
    }
}
