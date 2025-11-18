<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Share Page Handler Class
 *
 * Handles display of shared wishlists for public viewing
 *
 * @category WordPress
 * @package  WishCart
 * @author   WishCart Team <support@wishcart.chat>
 * @license  GPL-2.0+ https://www.gnu.org/licenses/gpl-2.0.html
 * @link     https://wishcart.chat
 */
class WISHCART_Share_Page_Handler {

    /**
     * Display shared wishlist
     *
     * @param string $share_token Share token
     * @return void
     */
    public function display_shared_wishlist($share_token) {
        // Enqueue necessary scripts and styles
        wp_enqueue_style('wishcart-shared-wishlist', WISHCART_PLUGIN_URL . 'build/wishlist-frontend.css', array(), WISHCART_VERSION);
        wp_enqueue_script('wishcart-shared-wishlist', WISHCART_PLUGIN_URL . 'build/wishlist-frontend.js', array('wp-element'), WISHCART_VERSION, true);
        
        // Localize script with API data
        wp_localize_script('wishcart-shared-wishlist', 'WishCartShared', array(
            'apiUrl' => rest_url('wishcart/v1/'),
            'shareToken' => sanitize_text_field($share_token),
            'nonce' => wp_create_nonce('wp_rest'),
            'siteUrl' => home_url(),
            'isUserLoggedIn' => is_user_logged_in(),
        ));
        
        // Display header
        get_header();
        
        ?>
        <div id="wishcart-shared-wishlist-root" class="wishcart-shared-page">
            <div class="wishcart-shared-container">
                <!-- React will mount here -->
                <div id="shared-wishlist-app" data-share-token="<?php echo esc_attr($share_token); ?>"></div>
            </div>
        </div>
        <?php
        
        // Display footer
        get_footer();
    }
}

