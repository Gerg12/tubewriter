<?php
/** Template Name: UploadWriter studio entry */
get_header(); ?>
<main id="primary" tabindex="-1" class="uw-auth-main">
<?php while ( have_posts() ) {
    the_post();
    if ( ! WP_Block_Type_Registry::get_instance()->is_registered( 'acf/uploadwriter-auth' ) && has_block( 'acf/uploadwriter-auth' ) ) {
        $blocks = parse_blocks( get_the_content() );
        $view = $blocks[0]['attrs']['data']['uw_auth_view'] ?? 'login';
        uw_render_auth_page( 'register' === $view ? 'register' : 'login' );
    } else { the_content(); }
} ?>
</main>
<?php get_footer(); ?>
