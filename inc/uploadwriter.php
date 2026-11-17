<?php
/** UploadWriter brand, marketing blocks, and shared design assets. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
function uw_app_url() {
    return class_exists( 'TW_Frontend' ) ? TW_Frontend::url( is_user_logged_in() ? 'account' : 'register' ) : wp_login_url();
}
function uw_field( $name, $default ) {
    $value = function_exists( 'get_field' ) ? get_field( $name ) : null;
    return null === $value || false === $value || '' === $value ? $default : $value;
}
function uw_brand() {
    echo '<span class="uw-mark" aria-hidden="true"><svg viewBox="0 0 64 64" fill="none" focusable="false"><path d="m18 40 24-24 10 10-24 24-14 4 4-14Z" stroke="currentColor" stroke-width="3" stroke-linejoin="round"/><path d="m36 22 10 10M18 40l10 10" stroke="currentColor" stroke-width="3"/><path d="M14 58h38" stroke="var(--uw-primary)" stroke-width="4"/></svg></span><span class="uw-wordmark">Upload<span class="uw-wordmark-writer">Writer</span><span class="uw-domain">.com</span></span>';
}
add_action( 'wp_enqueue_scripts', function () {
    // The new header owns its menu; the old script assumes portfolio-only elements exist.
    wp_dequeue_script( 'hpm-theme-scripts' );
    // The app renders its own document; keep the portfolio theme assets out of it.
    if ( get_query_var( 'tw_app' ) ) {
        foreach ( array( 'hpm-theme-style' ) as $handle ) { wp_dequeue_style( $handle ); }
        foreach ( array( 'select2', 'slick-scripts', 'aos-scripts', 'hpm-theme-scripts' ) as $handle ) { wp_dequeue_script( $handle ); }
    }
    wp_enqueue_style( 'uploadwriter', get_template_directory_uri() . '/assets/uploadwriter.css', array(), filemtime( get_template_directory() . '/assets/uploadwriter.css' ) );
    wp_enqueue_script( 'uploadwriter', get_template_directory_uri() . '/assets/uploadwriter.js', array(), filemtime( get_template_directory() . '/assets/uploadwriter.js' ), true );
}, 30 );
add_action( 'after_setup_theme', function () {
    add_theme_support( 'editor-styles' );
    add_editor_style( 'assets/uploadwriter.css' );
} );
add_action( 'acf/init', function () {
    if ( ! function_exists( 'acf_register_block_type' ) ) { return; }
    $sections = array( 'hero' => 'Full-width hero slider', 'features' => 'Feature grid', 'workflow' => 'How it works', 'cta' => 'Workspace call to action' );
    foreach ( $sections as $slug => $title ) {
        acf_register_block_type( array(
            'name' => 'uploadwriter-' . $slug, 'title' => 'UploadWriter: ' . $title,
            'description' => 'Editable UploadWriter homepage section.', 'category' => 'layout', 'icon' => 'video-alt3',
            'render_template' => 'template-parts/blocks/uploadwriter-' . $slug . '.php',
            'mode' => 'preview', 'supports' => array( 'anchor' => true, 'align' => false ),
            'enqueue_style' => get_template_directory_uri() . '/assets/uploadwriter.css',
        ) );
        $fields = array();
        foreach ( array( 'eyebrow' => 'Eyebrow', 'heading' => 'Heading', 'description' => 'Description' ) as $name => $label ) {
            $fields[] = array( 'key' => 'field_uw_' . $slug . '_' . $name, 'name' => 'uw_' . $name, 'label' => $label, 'type' => 'description' === $name ? 'textarea' : 'text', 'instructions' => 'Leave blank to use the UploadWriter default.' );
        }
        if ( in_array( $slug, array( 'hero', 'workflow', 'cta' ), true ) ) {
            $fields[] = array( 'key' => 'field_uw_' . $slug . '_image', 'name' => 'uw_image', 'label' => 'Section photograph', 'type' => 'image', 'return_format' => 'id', 'preview_size' => 'medium', 'instructions' => 'Optional. Leave blank to use the included creator photograph. Set alt text in the Media Library.' );
        }
        if ( in_array( $slug, array( 'hero', 'cta' ), true ) ) {
            $fields[] = array( 'key' => 'field_uw_' . $slug . '_link', 'name' => 'uw_link', 'label' => 'Primary button (optional)', 'type' => 'link', 'return_format' => 'array', 'instructions' => 'Defaults to registration, or the video workspace for signed-in visitors.' );
        } else {
            $fields[] = array( 'key' => 'field_uw_' . $slug . '_items', 'name' => 'uw_items', 'label' => 'Cards', 'type' => 'repeater', 'layout' => 'block', 'button_label' => 'Add card', 'sub_fields' => array(
                array( 'key' => 'field_uw_' . $slug . '_title', 'name' => 'title', 'label' => 'Title', 'type' => 'text' ),
                array( 'key' => 'field_uw_' . $slug . '_copy', 'name' => 'copy', 'label' => 'Description', 'type' => 'textarea' ),

            ) );
        }
        if ( 'hero' === $slug ) {
            $fields[] = array( 'key' => 'field_uw_hero_slides', 'name' => 'uw_slides', 'label' => 'Hero slides (optional)', 'type' => 'repeater', 'layout' => 'block', 'min' => 0, 'max' => 5, 'button_label' => 'Add slide', 'instructions' => 'Leave empty for the three included slides. Existing hero fields customize the first default slide. Use wide photographs with room for text on the left.', 'sub_fields' => array(
                array( 'key' => 'field_uw_slide_label', 'name' => 'label', 'label' => 'Navigation label', 'type' => 'text', 'required' => 1 ),
                array( 'key' => 'field_uw_slide_eyebrow', 'name' => 'eyebrow', 'label' => 'Eyebrow', 'type' => 'text' ),
                array( 'key' => 'field_uw_slide_heading', 'name' => 'heading', 'label' => 'Headline', 'type' => 'text', 'required' => 1 ),
                array( 'key' => 'field_uw_slide_accent', 'name' => 'accent', 'label' => 'Italic headline line', 'type' => 'text' ),
                array( 'key' => 'field_uw_slide_description', 'name' => 'description', 'label' => 'Description', 'type' => 'textarea' ),
                array( 'key' => 'field_uw_slide_image', 'name' => 'image', 'label' => 'Background photograph', 'type' => 'image', 'return_format' => 'id', 'preview_size' => 'medium' ),
                array( 'key' => 'field_uw_slide_note', 'name' => 'note', 'label' => 'Photograph caption', 'type' => 'text' ),
            ) );
        }
        if ( 'features' === $slug ) {
            $fields[ count( $fields ) - 1 ]['sub_fields'][] = array( 'key' => 'field_uw_features_card_image', 'name' => 'image', 'label' => 'Card photograph (optional)', 'type' => 'image', 'return_format' => 'id', 'preview_size' => 'medium' );
        }
        acf_add_local_field_group( array( 'key' => 'group_uw_' . $slug, 'title' => 'UploadWriter ' . $title, 'fields' => $fields, 'location' => array( array( array( 'param' => 'block', 'operator' => '==', 'value' => 'acf/uploadwriter-' . $slug ) ) ) ) );
    }
} );
add_action( 'init', function () {
    if ( ! function_exists( 'register_block_pattern' ) ) { return; }
    register_block_pattern( 'uploadwriter/homepage', array( 'title' => 'UploadWriter homepage', 'categories' => array( 'featured' ), 'content' => '<!-- wp:acf/uploadwriter-hero /--><!-- wp:acf/uploadwriter-features /--><!-- wp:acf/uploadwriter-workflow /--><!-- wp:acf/uploadwriter-cta /-->' ) );
} );
function uw_button( $default = 'Start your workspace' ) {
    $link = uw_field( 'uw_link', array() );
    $url = ! empty( $link['url'] ) ? $link['url'] : uw_app_url();
    $label = ! empty( $link['title'] ) ? $link['title'] : ( is_user_logged_in() ? 'Open video workspace' : $default );
    echo '<a class="uw-button" href="' . esc_url( $url ) . '"' . ( ! empty( $link['target'] ) && '_blank' === $link['target'] ? ' target="_blank" rel="noopener"' : '' ) . '>' . esc_html( $label ) . ' <span aria-hidden="true">↗</span></a>';
}


/** Responsive bundled photographs, with optional Media Library overrides. */
function uw_image( $attachment, $fallback, $alt, $class, $priority = false, $sizes = '(max-width: 760px) 100vw, 50vw', $loading = null ) {
    $loading = $loading ?: ( $priority ? 'eager' : 'lazy' );
    $id = is_array( $attachment ) ? (int) ( $attachment['ID'] ?? 0 ) : (int) $attachment;
    if ( $id && wp_attachment_is_image( $id ) ) {
        $saved_alt = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
        echo wp_get_attachment_image( $id, 'large', false, array(
            'class' => $class, 'alt' => $saved_alt ?: $alt, 'sizes' => $sizes,
            'loading' => $loading, 'decoding' => 'async',
            'fetchpriority' => $priority ? 'high' : 'auto',
        ) );
        return;
    }
    $base = get_template_directory_uri() . '/assets/images/' . sanitize_file_name( $fallback );
    printf(
        '<img class="%1$s" src="%2$s-960.webp" srcset="%2$s-640.webp 640w, %2$s-960.webp 960w, %2$s-1536.webp 1536w" sizes="%3$s" width="1536" height="1024" alt="%4$s" loading="%5$s" decoding="async" fetchpriority="%6$s">',
        esc_attr( $class ), esc_url( $base ), esc_attr( $sizes ), esc_attr( $alt ),
        $loading, $priority ? 'high' : 'auto'
    );
}


/** Decorative, code-native artwork shared by homepage sections. */
function uw_float_art( $kind = 'spark', $class = '' ) {
    $art = array(
        'spark' => '<path d="M80 8 91 59 139 38 106 80 151 102 100 100 104 151 80 111 47 152 58 102 8 105 51 80 20 40 68 58Z"/>',
        'play' => '<circle cx="80" cy="80" r="68"/><path d="m48 88 45-45 19 19-45 45-26 7 7-26ZM81 55l19 19M48 88l19 19M40 128h80"/>',
        'orbit' => '<ellipse cx="80" cy="80" rx="73" ry="29" transform="rotate(-30 80 80)"/><ellipse cx="80" cy="80" rx="73" ry="29" transform="rotate(30 80 80)"/><circle cx="80" cy="80" r="18"/><circle cx="136" cy="39" r="7"/>',
        'arrow' => '<path d="M13 133C14 70 50 44 130 42M83 13l48 29-35 44"/>',
        'camera' => '<rect x="19" y="42" width="88" height="77" rx="16"/><path d="m107 66 34-19v67l-34-19M41 42l8-18h31l8 18"/><circle cx="62" cy="81" r="20"/>',
    );
    echo '<svg class="uw-float-art ' . esc_attr( $class ) . '" viewBox="0 0 160 160" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . ( $art[ $kind ] ?? $art['spark'] ) . '</svg>';
}
