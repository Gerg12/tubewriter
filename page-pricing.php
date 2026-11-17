<?php
/** Template for the pricing page. */
get_header(); ?>
<main id="primary" tabindex="-1" class="uw-pricing-page">
<?php
$content = get_post_field( 'post_content', get_queried_object_id() );
if ( false !== strpos( (string) $content, 'wp:acf/uploadwriter-pricing' ) ) {
    while ( have_posts() ) { the_post(); the_content(); }
} else {
    get_template_part( 'template-parts/blocks/uploadwriter-pricing' );
}
?>
</main>
<?php get_footer(); ?>
