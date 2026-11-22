<?php
/** Proposed commercial plans. No payment or entitlement actions run from this file. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
function uw_pricing_data() {
    static $data;
    if ( null === $data ) {
        $php = get_template_directory() . '/inc/pricing-data.php';
        $data = is_readable( $php ) ? include $php : null;
        if ( ! is_array( $data ) ) {
            $json = get_template_directory() . '/inc/pricing-data.json';
            $data = is_readable( $json ) ? json_decode( file_get_contents( $json ), true ) : array();
        }
    }
    return $data;
}
add_action( 'acf/init', function () {
    if ( ! function_exists( 'acf_register_block_type' ) ) { return; }
    acf_register_block_type( array(
        'name' => 'uploadwriter-pricing', 'title' => 'UploadWriter: Pricing',
        'description' => 'Launch pricing, plan comparison, thumbnail credits, and FAQs.',
        'category' => 'layout', 'icon' => 'tickets-alt', 'mode' => 'preview',
        'render_template' => 'template-parts/blocks/uploadwriter-pricing.php',
        'supports' => array( 'anchor' => true, 'align' => false ),
        'enqueue_style' => get_template_directory_uri() . '/assets/uploadwriter.css',
    ) );
    acf_add_local_field_group( array(
        'key' => 'group_uw_pricing', 'title' => 'UploadWriter pricing introduction',
        'fields' => array(
            array( 'key' => 'field_uw_pricing_eyebrow', 'name' => 'uw_pricing_eyebrow', 'label' => 'Eyebrow', 'type' => 'text' ),
            array( 'key' => 'field_uw_pricing_heading', 'name' => 'uw_pricing_heading', 'label' => 'Heading', 'type' => 'text' ),
            array( 'key' => 'field_uw_pricing_description', 'name' => 'uw_pricing_description', 'label' => 'Introduction', 'type' => 'textarea' ),
        ),
        'location' => array( array( array( 'param' => 'block', 'operator' => '==', 'value' => 'acf/uploadwriter-pricing' ) ) ),
    ) );
} );
