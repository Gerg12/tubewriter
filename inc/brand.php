<?php
/** UploadWriter browser identity and public link previews. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function uw_brand_asset( $file ) {
    return get_template_directory_uri() . '/assets/brand/' . $file;
}

// The frontend emits a complete icon set; WordPress keeps its Site Icon in admin/login.
remove_action( 'wp_head', 'wp_site_icon', 99 );
add_action( 'wp_head', function () {
    $version = (string) filemtime( get_template_directory() . '/assets/brand/icon-512.png' );
    echo '<link rel="icon" href="' . esc_url( uw_brand_asset( 'favicon.svg' ) . '?v=' . $version ) . '" type="image/svg+xml">' . "\n";
    echo '<link rel="icon" href="' . esc_url( uw_brand_asset( 'favicon.ico' ) . '?v=' . $version ) . '" sizes="16x16 32x32 48x48">' . "\n";
    foreach ( array( 32, 192 ) as $size ) {
        echo '<link rel="icon" type="image/png" sizes="' . (int) $size . 'x' . (int) $size . '" href="' . esc_url( uw_brand_asset( 'icon-' . $size . '.png' ) . '?v=' . $version ) . '">' . "\n";
    }
    echo '<link rel="apple-touch-icon" sizes="180x180" href="' . esc_url( uw_brand_asset( 'icon-180.png' ) . '?v=' . $version ) . '">' . "\n";
    echo '<link rel="manifest" href="' . esc_url( uw_brand_asset( 'site.webmanifest' ) ) . '">' . "\n";
    echo '<meta name="theme-color" content="#242321">' . "\n";
}, 5 );

add_action( 'do_faviconico', function () {
    wp_redirect( uw_brand_asset( 'favicon.ico' ), 302 );
    exit;
} );

function uw_share_metadata() {
    $app = (bool) get_query_var( 'tw_app' );
    $title = 'UploadWriter.com — Your YouTube creative workspace';
    $description = 'Write sharper titles, craft descriptions, explore thumbnails, and plan your next upload. Your channel. Your keys. Your final say.';
    $url = home_url( '/' );
    if ( ! $app && ! is_front_page() && is_singular() && is_post_publicly_viewable( get_queried_object_id() ) ) {
        $title = get_the_title( get_queried_object_id() ) . ' — UploadWriter.com';
        $url = get_permalink( get_queried_object_id() );
        if ( is_page( 'pricing' ) || is_page_template( 'page-pricing.php' ) ) {
            $description = 'Explore UploadWriter plans for your YouTube creative workflow. Compare tools for writing, thumbnails, and managing your channel.';
        } elseif ( has_excerpt( get_queried_object_id() ) ) {
            $description = wp_trim_words( wp_strip_all_tags( get_the_excerpt( get_queried_object_id() ) ), 35, '…' );
        }
    }
    return array( 'title' => wp_strip_all_tags( $title ), 'description' => $description, 'url' => $url );
}

add_action( 'wp_head', function () {
    if ( is_feed() || is_404() ) { return; }
    $meta = uw_share_metadata();
    $image = uw_brand_asset( 'uploadwriter-social.png' ) . '?v=' . filemtime( get_template_directory() . '/assets/brand/uploadwriter-social.png' );
    echo '<meta name="description" content="' . esc_attr( $meta['description'] ) . '">' . "\n";
    $tags = array(
        'og:type' => 'website', 'og:site_name' => 'UploadWriter.com', 'og:locale' => str_replace( '-', '_', get_bloginfo( 'language' ) ),
        'og:title' => $meta['title'], 'og:description' => $meta['description'], 'og:url' => $meta['url'],
        'og:image' => $image, 'og:image:type' => 'image/png', 'og:image:width' => '1200', 'og:image:height' => '630',
        'og:image:alt' => 'UploadWriter.com — Great videos. Better words. Your YouTube creative workspace.',
    );
    foreach ( $tags as $property => $value ) {
        echo '<meta property="' . esc_attr( $property ) . '" content="' . esc_attr( $value ) . '">' . "\n";
    }
    foreach ( array( 'twitter:card' => 'summary_large_image', 'twitter:title' => $meta['title'], 'twitter:description' => $meta['description'], 'twitter:image' => $image, 'twitter:image:alt' => $tags['og:image:alt'] ) as $name => $value ) {
        echo '<meta name="' . esc_attr( $name ) . '" content="' . esc_attr( $value ) . '">' . "\n";
    }
}, 6 );

// Account and editor URLs always use generic branding, never private video data.
add_filter( 'wp_robots', function ( $robots ) {
    if ( get_query_var( 'tw_app' ) ) { $robots['noindex'] = true; unset( $robots['index'] ); }
    return $robots;
} );
