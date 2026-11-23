<?php
/** Editable studio layouts. Follows the existing PHP-registered ACF block pattern. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
add_action( 'acf/init', function () {
    if ( ! function_exists( 'acf_register_block_type' ) ) { return; }
    foreach ( array( 'auth' => 'Studio sign-in', 'process' => 'Publishing process', 'trust' => 'Your final say' ) as $slug => $title ) {
        acf_register_block_type( array(
            'name' => 'uploadwriter-' . $slug, 'title' => 'UploadWriter: ' . $title,
            'description' => 'Editable studio section with the shared UploadWriter identity.',
            'category' => 'layout', 'icon' => 'edit', 'mode' => 'preview',
            'render_template' => 'template-parts/blocks/uploadwriter-' . $slug . '.php',
            'supports' => array( 'anchor' => true, 'align' => false, 'multiple' => 'auth' !== $slug ),
            'enqueue_style' => get_template_directory_uri() . '/assets/uploadwriter.css',
        ) );
        $fields = array();
        foreach ( array( 'eyebrow' => 'Eyebrow', 'heading' => 'Heading', 'description' => 'Description' ) as $name => $label ) {
            $fields[] = array( 'key' => 'field_uw_' . $slug . '_' . $name, 'name' => 'uw_' . $name, 'label' => $label, 'type' => 'description' === $name ? 'textarea' : 'text', 'instructions' => 'Leave blank to use the default. Plain text only.' );
        }
        if ( 'process' !== $slug ) {
            $fields[] = array( 'key' => 'field_uw_' . $slug . '_image', 'name' => 'uw_image', 'label' => 'Studio photograph', 'type' => 'image', 'return_format' => 'id', 'preview_size' => 'medium', 'instructions' => 'Optional Media Library override. Leave blank for bundled photography.' );
        }
        if ( 'auth' === $slug ) {
            $fields[] = array( 'key' => 'field_uw_auth_view', 'name' => 'uw_auth_view', 'label' => 'Form', 'type' => 'select', 'choices' => array( 'login' => 'Log in', 'register' => 'Create account / request beta access' ), 'default_value' => 'login', 'instructions' => 'The app route always determines its own form. This choice applies to standalone pages and editor previews.' );
            $fields[] = array( 'key' => 'field_uw_auth_caption', 'name' => 'uw_caption', 'label' => 'Photo caption', 'type' => 'text' );
            $fields[] = array( 'key' => 'field_uw_auth_form_heading', 'name' => 'uw_form_heading', 'label' => 'Form heading', 'type' => 'text' );
            $fields[] = array( 'key' => 'field_uw_auth_form_lead', 'name' => 'uw_form_lead', 'label' => 'Form introduction', 'type' => 'textarea', 'instructions' => 'The private beta notice is always shown when beta approval is enabled.' );
        } else {
            $fields[] = array( 'key' => 'field_uw_' . $slug . '_items', 'name' => 'uw_items', 'label' => 'Steps / assurances', 'type' => 'repeater', 'layout' => 'block', 'max' => 'process' === $slug ? 7 : 4, 'button_label' => 'Add item', 'sub_fields' => array(
                array( 'key' => 'field_uw_' . $slug . '_title', 'name' => 'title', 'label' => 'Title', 'type' => 'text', 'required' => 1 ),
                array( 'key' => 'field_uw_' . $slug . '_copy', 'name' => 'copy', 'label' => 'Description', 'type' => 'textarea' ),
            ) );
        }
        acf_add_local_field_group( array( 'key' => 'group_uw_' . $slug, 'title' => 'UploadWriter ' . $title, 'fields' => $fields, 'location' => array( array( array( 'param' => 'block', 'operator' => '==', 'value' => 'acf/uploadwriter-' . $slug ) ) ) ) );
    }
    acf_add_local_field_group( array(
        'key' => 'group_uw_auth_sources', 'title' => 'UploadWriter sign-in pages',
        'fields' => array(
            array( 'key' => 'field_uw_login_page', 'name' => 'uw_login_page', 'label' => 'Login design page', 'type' => 'post_object', 'post_type' => array( 'page' ), 'return_format' => 'id', 'allow_null' => 1, 'instructions' => 'Choose a published page containing one Studio sign-in block. Its block stream is used on /tubewriter/login/.' ),
            array( 'key' => 'field_uw_register_page', 'name' => 'uw_register_page', 'label' => 'Registration design page', 'type' => 'post_object', 'post_type' => array( 'page' ), 'return_format' => 'id', 'allow_null' => 1, 'instructions' => 'Choose a published page containing one Studio sign-in block. Its block stream is used on /tubewriter/register/.' ),
        ),
        'location' => array( array( array( 'param' => 'options_page', 'operator' => '==', 'value' => 'site-options' ) ) ),
    ) );
} );
add_action( 'init', function () {
    if ( ! function_exists( 'register_block_pattern' ) ) { return; }
    register_block_pattern( 'uploadwriter/studio-login', array( 'title' => 'UploadWriter studio login', 'categories' => array( 'featured' ), 'content' => '<!-- wp:acf/uploadwriter-auth {"name":"acf/uploadwriter-auth","data":{"uw_auth_view":"login","_uw_auth_view":"field_uw_auth_view"},"mode":"preview"} /-->' ) );
    register_block_pattern( 'uploadwriter/studio-register', array( 'title' => 'UploadWriter studio registration', 'categories' => array( 'featured' ), 'content' => '<!-- wp:acf/uploadwriter-auth {"name":"acf/uploadwriter-auth","data":{"uw_auth_view":"register","_uw_auth_view":"field_uw_auth_view"},"mode":"preview"} /-->' ) );
    register_block_pattern( 'uploadwriter/publishing-story', array( 'title' => 'UploadWriter publishing story', 'categories' => array( 'featured' ), 'content' => '<!-- wp:acf/uploadwriter-process {"name":"acf/uploadwriter-process","mode":"preview"} /--><!-- wp:acf/uploadwriter-trust {"name":"acf/uploadwriter-trust","mode":"preview"} /--><!-- wp:acf/uploadwriter-cta {"name":"acf/uploadwriter-cta","mode":"preview"} /-->' ) );
} );
/** Count auth blocks, including blocks nested in ordinary groups. */
function uw_auth_block_count( $blocks ) {
    $count = 0;
    foreach ( $blocks as $block ) {
        $count += 'acf/uploadwriter-auth' === $block['blockName'] ? 1 : 0;
        $count += uw_auth_block_count( $block['innerBlocks'] );
    }
    return $count;
}
/** App routes use an explicitly assigned published page, or a safe bundled default. */
function uw_render_auth_page( $view ) {
    $source_id = function_exists( 'get_field' ) ? (int) get_field( 'uw_' . $view . '_page', 'option' ) : 0;
    $source = $source_id ? get_post( $source_id ) : null;
    $registered = WP_Block_Type_Registry::get_instance()->is_registered( 'acf/uploadwriter-auth' );
    if ( $registered && $source && 'page' === $source->post_type && 'publish' === $source->post_status && ! $source->post_password && 1 === uw_auth_block_count( parse_blocks( $source->post_content ) ) ) {
        // do_blocks establishes the ACF field context for each saved dynamic block.
        echo do_blocks( $source->post_content ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    } else {
        get_template_part( 'template-parts/blocks/uploadwriter-auth', null, array( 'view' => $view, 'defaults' => true ) );
    }
}

// Forms contain time-sensitive nonces and the registration timing guard.
add_action( 'template_redirect', function () {
    if ( is_page_template( 'page-studio.php' ) ) { nocache_headers(); }
} );
