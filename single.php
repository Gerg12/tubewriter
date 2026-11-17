<?php
/** Single posts share the editorial layout and block width system with pages. */
get_header(); ?>
<main id="primary" class="uw-document" tabindex="-1">
<?php
while ( have_posts() ) :
    the_post();
    get_template_part( 'template-parts/content', 'document' );
    the_post_navigation( array(
        'prev_text' => '<span class="nav-subtitle">' . esc_html__( 'Previous article', 'hpm-theme' ) . '</span><span class="nav-title">%title</span>',
        'next_text' => '<span class="nav-subtitle">' . esc_html__( 'Next article', 'hpm-theme' ) . '</span><span class="nav-title">%title</span>',
    ) );
    if ( comments_open() || get_comments_number() ) {
        comments_template();
    }
endwhile;
?>
</main>
<?php get_footer();
