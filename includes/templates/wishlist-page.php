<?php
/**
 * Template Name: Wishlist Page
 * 
 * This template is used for displaying the wishlist page.
 * The actual content is rendered via the [wishcart_wishlist] shortcode.
 *
 * @package WishCart
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

get_header(); ?>

<div class="wishcart-wishlist-wrapper">
    <?php
    while ( have_posts() ) :
        the_post();
        ?>
        <article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
            <header class="entry-header">
                <?php the_title( '<h1 class="entry-title">', '</h1>' ); ?>
            </header>

            <div class="entry-content">
                <?php
                the_content();
                ?>
            </div>
        </article>
        <?php
    endwhile;
    ?>
</div>

<?php
get_footer();

