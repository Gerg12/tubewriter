<?php if ( ! defined( 'ABSPATH' ) ) { exit; } ?>
<section class="uw-finale" <?php if ( ! empty( $block['anchor'] ) ) { echo 'id="' . esc_attr( $block['anchor'] ) . '"'; } ?>>
    <div class="uw-wrap uw-finale-inner">
        <div class="uw-finale-copy"><p class="uw-eyebrow"><?php echo esc_html( uw_field( 'uw_eyebrow', 'YOUR NEXT CHAPTER STARTS HERE' ) ); ?></p><h2><?php echo esc_html( uw_field( 'uw_heading', 'More creating. Less clicking.' ) ); ?></h2><p><?php echo esc_html( uw_field( 'uw_description', 'Give your videos a workspace that works as hard as you do.' ) ); ?></p><div class="uw-finale-actions"><?php uw_button( 'Get started with UploadWriter' ); ?><a href="<?php echo esc_url( home_url( '/pricing/' ) ); ?>">Find your plan <span aria-hidden="true">↗</span></a></div><p class="uw-finale-note">Your channel. Your keys. Your final say.</p><button type="button" class="uw-motion-control" data-uw-motion-toggle aria-pressed="false" hidden>Pause floating artwork</button></div>
        <figure class="uw-finale-card">
            <?php uw_image( uw_field( 'uw_image', 0 ), 'creator-storyboard', 'A creator sketching a video storyboard beside a tablet and coffee.', 'uw-photo', false, '(max-width: 900px) 100vw, 45vw' ); ?>
            <figcaption><span>Your next great video<br><strong>starts with you.</strong></span><?php uw_float_art( 'spark', 'uw-finale-card-accent' ); ?></figcaption>
        </figure>
    </div>
</section>
