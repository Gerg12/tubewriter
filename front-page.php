<?php
/** Marketing homepage. Existing ACF marketing content takes priority over defaults. */
get_header(); ?>
<main id="primary" tabindex="-1" class="uw-home">
<?php
$content = is_page() ? get_post_field( 'post_content', get_queried_object_id() ) : '';
$block_ready = class_exists( 'WP_Block_Type_Registry' ) && WP_Block_Type_Registry::get_instance()->is_registered( 'acf/uploadwriter-hero' );
if ( $block_ready && false !== strpos( (string) $content, 'wp:acf/uploadwriter-' ) ) {
    while ( have_posts() ) { the_post(); the_content(); }
} else {
    foreach ( array( 'hero', 'features', 'workflow', 'cta' ) as $section ) {
        get_template_part( 'template-parts/blocks/uploadwriter-' . $section );
    }
}
?>
</main>
<?php get_footer(); ?>

