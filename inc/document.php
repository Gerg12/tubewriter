<?php
/** Singular page assets and editor width choices. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
add_action( 'after_setup_theme', function () {
    add_theme_support( 'align-wide' );
    add_theme_support( 'responsive-embeds' );
    add_editor_style( 'assets/document.css' );
} );
add_action( 'wp_enqueue_scripts', function () {
    if ( is_singular() && ! is_front_page() && ! get_query_var( 'tw_app' ) ) {
        wp_enqueue_style( 'uploadwriter-document', get_template_directory_uri() . '/assets/document.css', array( 'uploadwriter' ), filemtime( get_template_directory() . '/assets/document.css' ) );
    }
}, 40 );
