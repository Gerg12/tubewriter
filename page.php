<?php
/** Default pages, including nested pages. Blocks own their individual widths. */
get_header(); ?>
<main id="primary" class="uw-document" tabindex="-1">
<?php
while ( have_posts() ) :
    the_post();
    get_template_part( 'template-parts/content', 'document' );
    if ( comments_open() || get_comments_number() ) {
        comments_template();
    }
endwhile;
?>
</main>
<?php get_footer();
