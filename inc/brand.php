<?php
/** UploadWriter browser identity and public link previews. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function uw_brand_asset( $file ) {
    return get_template_directory_uri() . '/assets/brand/' . $file;
}

function uw_brand_icon_path() {
    return get_template_directory() . '/assets/brand/icon-512.png';
}

function uw_brand_social_path() {
    return get_template_directory() . '/assets/brand/uploadwriter-social.png';
}

/**
 * Ensure the WordPress Site Icon uses the UploadWriter 512 PNG once.
 * Sideloads from the theme when no Site Icon is set yet.
 */
function uw_ensure_site_icon() {
    if ( get_option( 'uw_site_icon_ready' ) ) {
        return;
    }
    $existing = (int) get_option( 'site_icon' );
    if ( $existing && wp_attachment_is_image( $existing ) ) {
        update_option( 'uw_site_icon_ready', $existing, false );
        return;
    }
    if ( ! file_exists( uw_brand_icon_path() ) ) {
        return;
    }
    if ( ! function_exists( 'media_handle_sideload' ) ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
    }
    $tmp = wp_tempnam( 'uploadwriter-icon-512.png' );
    if ( ! $tmp || ! copy( uw_brand_icon_path(), $tmp ) ) {
        return;
    }
    $file = array(
        'name'     => 'uploadwriter-icon-512.png',
        'type'     => 'image/png',
        'tmp_name' => $tmp,
        'error'    => 0,
        'size'     => filesize( $tmp ),
    );
    $id = media_handle_sideload( $file, 0, 'UploadWriter site icon' );
    if ( is_wp_error( $id ) ) {
        @unlink( $tmp );
        return;
    }
    update_post_meta( $id, '_wp_attachment_image_alt', 'UploadWriter logo' );
    update_option( 'site_icon', (int) $id );
    update_option( 'uw_site_icon_ready', (int) $id, false );
}
add_action( 'init', 'uw_ensure_site_icon', 30 );

// The frontend emits a complete icon set; WordPress keeps its Site Icon in admin/login.
remove_action( 'wp_head', 'wp_site_icon', 99 );
add_action( 'wp_head', function () {
    $version = (string) filemtime( uw_brand_icon_path() );
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
    $title = 'UploadWriter — YouTube Creative Workspace';
    $description = 'Draft sharper YouTube titles, descriptions, chapters, and thumbnails in one workspace. Review before you publish. Your channel. Your keys. Your final say.';
    $url = home_url( '/' );
    if ( ! $app && ! is_front_page() && is_singular() && is_post_publicly_viewable( get_queried_object_id() ) ) {
        $title = get_the_title( get_queried_object_id() ) . ' — UploadWriter.com';
        $url = get_permalink( get_queried_object_id() );
        if ( is_page( 'pricing' ) || is_page_template( 'page-pricing.php' ) ) {
            $description = 'Explore planned UploadWriter plans for drafting titles, descriptions, chapters, and thumbnails. Compare tools and keep the final say on every upload.';
        } elseif ( is_page( 'how-it-works' ) ) {
            $description = 'Create an account, connect YouTube, draft metadata with AI, and publish only after you review. See how UploadWriter fits your upload workflow.';
        } elseif ( has_excerpt( get_queried_object_id() ) ) {
            $description = wp_trim_words( wp_strip_all_tags( get_the_excerpt( get_queried_object_id() ) ), 35, '…' );
        }
    }
    return array( 'title' => wp_strip_all_tags( $title ), 'description' => $description, 'url' => $url );
}

add_action( 'wp_head', function () {
    if ( is_feed() || is_404() ) { return; }
    // Yoast owns SEO tags when active; keep theme tags for the app shell and as a fallback.
    if ( defined( 'WPSEO_VERSION' ) && ! get_query_var( 'tw_app' ) ) {
        return;
    }
    $meta = uw_share_metadata();
    $image = uw_brand_asset( 'uploadwriter-social.png' ) . '?v=' . filemtime( uw_brand_social_path() );
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

// Homepage Yoast title/description (posts front page uses these templates).
add_filter( 'option_wpseo_titles', function ( $opts ) {
    if ( ! is_array( $opts ) ) {
        return $opts;
    }
    $opts['title-home-wpseo'] = 'UploadWriter — YouTube Creative Workspace';
    $opts['metadesc-home-wpseo'] = 'Draft sharper YouTube titles, descriptions, chapters, and thumbnails in one workspace. Review before you publish.';
    $opts['company_name'] = 'UploadWriter';
    $opts['org-legal-name'] = 'Gregory S Johnson LLC';
    $opts['org-email'] = 'hello@uploadwriter.com';
    $opts['company_or_person'] = 'company';
    $icon = uw_brand_asset( 'icon-512.png' );
    if ( empty( $opts['company_logo'] ) ) {
        $opts['company_logo'] = $icon;
    }
    $social = uw_brand_asset( 'uploadwriter-social.png' );
    if ( empty( $opts['open_graph_frontpage_image'] ) ) {
        $opts['open_graph_frontpage_image'] = $social;
    }
    $opts['open_graph_frontpage_title'] = 'UploadWriter — YouTube Creative Workspace';
    $opts['open_graph_frontpage_desc'] = 'Draft sharper YouTube titles, descriptions, chapters, and thumbnails in one workspace. Review before you publish.';
    return $opts;
} );

add_filter( 'option_wpseo_social', function ( $opts ) {
    if ( ! is_array( $opts ) ) {
        return $opts;
    }
    $social = uw_brand_asset( 'uploadwriter-social.png' );
    if ( empty( $opts['og_default_image'] ) ) {
        $opts['og_default_image'] = $social;
    }
    if ( empty( $opts['og_frontpage_image'] ) ) {
        $opts['og_frontpage_image'] = $social;
    }
    if ( empty( $opts['og_frontpage_title'] ) ) {
        $opts['og_frontpage_title'] = 'UploadWriter — YouTube Creative Workspace';
    }
    if ( empty( $opts['og_frontpage_desc'] ) ) {
        $opts['og_frontpage_desc'] = 'Draft sharper YouTube titles, descriptions, chapters, and thumbnails in one workspace. Review before you publish.';
    }
    return $opts;
} );

// Prefer the theme social image when Yoast has no custom image for a URL.
add_filter( 'wpseo_opengraph_image', function ( $image ) {
    if ( empty( $image ) ) {
        return uw_brand_asset( 'uploadwriter-social.png' );
    }
    return $image;
} );

add_filter( 'wpseo_twitter_image', function ( $image ) {
    if ( empty( $image ) ) {
        return uw_brand_asset( 'uploadwriter-social.png' );
    }
    return $image;
} );

// App workspace routes and thin utility archives stay out of the index.
add_filter( 'wp_robots', function ( $robots ) {
    if ( get_query_var( 'tw_app' ) || is_search() || is_author() || is_date() || is_attachment() ) {
        $robots['noindex'] = true;
        unset( $robots['index'] );
    }
    return $robots;
} );

add_filter( 'wpseo_robots', function ( $robots ) {
    if ( get_query_var( 'tw_app' ) || is_search() || is_author() || is_date() || is_attachment() ) {
        return 'noindex, follow';
    }
    return $robots;
} );
