<?php
/** Template for the How It Works page. */
get_header(); ?>
<main id="primary" tabindex="-1" class="uw-how-it-works-page">
<?php
$content = get_post_field( 'post_content', get_queried_object_id() );
$block_ready = class_exists( 'WP_Block_Type_Registry' ) && WP_Block_Type_Registry::get_instance()->is_registered( 'acf/uploadwriter-workflow' );
if ( $block_ready && false !== strpos( (string) $content, 'wp:acf/uploadwriter-workflow' ) ) {
    while ( have_posts() ) { the_post(); the_content(); }
} else {
    get_template_part( 'template-parts/blocks/uploadwriter-workflow' );
    get_template_part( 'template-parts/blocks/uploadwriter-cta' );
}
?>
</main>
<?php get_footer(); ?>
