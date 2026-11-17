<?php
/** Shared singular content. No constrained wrapper around the block stream. */
?>
<article id="post-<?php the_ID(); ?>" <?php post_class( 'uw-article' ); ?>>
    <header class="uw-document-header">
        <div class="uw-document-heading">
            <nav class="uw-breadcrumbs" aria-label="<?php esc_attr_e( 'Breadcrumb', 'hpm-theme' ); ?>">
                <ol>
                    <li><a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Home', 'hpm-theme' ); ?></a></li>
                    <?php foreach ( array_reverse( get_post_ancestors( get_the_ID() ) ) as $ancestor ) : ?>
                        <li><a href="<?php echo esc_url( get_permalink( $ancestor ) ); ?>"><?php echo esc_html( get_the_title( $ancestor ) ); ?></a></li>
                    <?php endforeach; ?>
                    <li aria-current="page"><?php the_title(); ?></li>
                </ol>
            </nav>
            <?php the_title( '<h1 class="uw-document-title">', '</h1>' ); ?>
            <?php if ( 'post' === get_post_type() ) : ?>
                <div class="uw-document-meta"><?php hpm_theme_posted_on(); ?><?php hpm_theme_posted_by(); ?></div>
            <?php endif; ?>
        </div>
    </header>
    <?php if ( has_post_thumbnail() && ! post_password_required() ) : ?>
        <figure class="uw-document-image">
            <?php the_post_thumbnail( 'full', array( 'sizes' => '(max-width: 1200px) 100vw, 1200px' ) ); ?>
            <?php if ( get_the_post_thumbnail_caption() ) : ?>
                <figcaption><?php echo wp_kses_post( get_the_post_thumbnail_caption() ); ?></figcaption>
            <?php endif; ?>
        </figure>
    <?php endif; ?>
    <div class="entry-content uw-prose uw-content-grid">
        <?php
        the_content();
        wp_link_pages( array(
            'before' => '<nav class="page-links" aria-label="' . esc_attr__( 'Content pages', 'hpm-theme' ) . '">' . esc_html__( 'Pages:', 'hpm-theme' ),
            'after' => '</nav>',
        ) );
        ?>
    </div>
    <?php if ( get_edit_post_link() ) : ?>
        <footer class="uw-document-footer"><?php edit_post_link( esc_html__( 'Edit this page', 'hpm-theme' ) ); ?></footer>
    <?php endif; ?>
</article>

