<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
$items = uw_field( 'uw_items', array(
    array( 'title' => 'Make yourself at home.', 'copy' => 'Create your account, add your AI key, and connect the YouTube channel you manage.' ),
    array( 'title' => 'Give your uploads a fresh draft.', 'copy' => 'Sync your videos. Open an upload to work on metadata and explore thumbnail ideas.' ),
    array( 'title' => 'Make the final call.', 'copy' => 'Review your changes, refine the details, and send them to YouTube when you are ready.' ),
) ); ?>
<section class="uw-journey" id="<?php echo esc_attr( ! empty( $block['anchor'] ) ? $block['anchor'] : 'workflow' ); ?>">
    <?php uw_float_art( 'orbit', 'uw-journey-orbit' ); ?>
    <div class="uw-wrap uw-journey-inner">
        <div class="uw-journey-visual">
            <figure><?php uw_image( uw_field( 'uw_image', 0 ), ( is_page( 'how-it-works' ) ? 'how-source' : 'creator-planning' ), ( is_page( 'how-it-works' ) ? 'A creator listening through headphones while making notes from source material.' : 'A creator planning her next video with a notebook and laptop in a sunlit studio.' ), 'uw-photo', false, '(max-width: 760px) 100vw, (max-width: 1600px) 80vw, 1000px' ); ?><figcaption><span aria-hidden="true">●</span> MORE TIME FOR THE PART YOU LOVE.</figcaption></figure>
            <div class="uw-journey-ticket"><span class="uw-journey-ticket-mark" aria-hidden="true">↗</span><p>Made by you.<br><em>Fine-tuned with UploadWriter.</em></p><span class="uw-journey-ticket-line" aria-hidden="true"></span></div>
            <?php uw_float_art( 'arrow', 'uw-journey-arrow' ); ?>
        </div>
        <div class="uw-journey-copy">
            <p class="uw-eyebrow"><?php echo esc_html( uw_field( 'uw_eyebrow', 'LESS FRICTION. MORE FORWARD.' ) ); ?></p>
            <h2><?php echo esc_html( uw_field( 'uw_heading', 'From “uploaded” to “that’s the one.”' ) ); ?></h2>
            <p class="uw-journey-intro"><?php echo esc_html( uw_field( 'uw_description', 'AI helps with the first draft. You keep the last word. Nothing is published until you choose.' ) ); ?></p>
            <ol class="uw-journey-steps"><?php foreach ( $items as $i => $item ) : ?><li><span class="uw-journey-number"><?php echo esc_html( sprintf( '%02d', $i + 1 ) ); ?></span><div><h3><?php echo esc_html( $item['title'] ); ?></h3><p><?php echo esc_html( $item['copy'] ); ?></p></div></li><?php endforeach; ?></ol>
            <a class="uw-journey-link" href="<?php echo esc_url( uw_app_url() ); ?>">Let’s get your channel ready <span aria-hidden="true">↗</span></a>
        </div>
    </div>
</section>
