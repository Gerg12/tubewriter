<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
$items = uw_field( 'uw_items', array(
    array( 'title' => 'Review before you publish.', 'copy' => 'See the changes before you confirm. If the video changes in Studio after review, publishing stops for another look.' ),
    array( 'title' => 'A backup before metadata changes.', 'copy' => 'Metadata publishing and restores save the current live details first. Your version history gives you a way back.' ),
    array( 'title' => 'Your work stays yours.', 'copy' => 'Bring your own AI keys. UploadWriter never deletes your videos, playlists, or playlist entries.' ),
) ); ?>
<section class="uw-studio-trust"<?php if ( ! empty( $block['anchor'] ) ) { echo ' id="' . esc_attr( $block['anchor'] ) . '"'; } ?>>
<div class="uw-wrap uw-studio-trust__inner">
    <figure><?php uw_image( uw_field( 'uw_image', 0 ), ( is_page( 'how-it-works' ) ? 'how-review' : 'creator-editing' ), ( is_page( 'how-it-works' ) ? 'Hands comparing two proof sheets with a fountain pen before a final decision.' : 'A creator taking a careful look at her work at a warm studio desk.' ), 'uw-photo', false, '(max-width: 760px) 100vw, 45vw' ); ?><figcaption>THE LAST WORD IS ALWAYS YOURS.</figcaption></figure>
    <div><p class="uw-eyebrow"><?php echo esc_html( uw_field( 'uw_eyebrow', 'CRAFT NEEDS A LITTLE CONFIDENCE' ) ); ?></p><h2><?php echo esc_html( uw_field( 'uw_heading', 'Care for the work you put your name to.' ) ); ?></h2><p class="uw-studio-trust__lead"><?php echo esc_html( uw_field( 'uw_description', 'AI can help you get started. The decisions stay with you, right through to the final confirmation.' ) ); ?></p>
    <ul><?php foreach ( $items as $item ) : ?><li><h3><?php echo esc_html( $item['title'] ); ?></h3><p><?php echo esc_html( $item['copy'] ); ?></p></li><?php endforeach; ?></ul></div>
</div>
</section>
