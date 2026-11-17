<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
$uw_slides = uw_field( 'uw_slides', array() );
if ( ! is_array( $uw_slides ) || ! $uw_slides ) {
    $uw_first_heading = uw_field( 'uw_heading', 'Great videos deserve better words.' );
    $uw_slides = array(
        array( 'label' => 'Find your words', 'eyebrow' => uw_field( 'uw_eyebrow', 'YOUR CHANNEL. YOUR CREATIVE CONTROL.' ), 'heading' => 'Great videos deserve better words.' === $uw_first_heading ? 'Great videos deserve' : $uw_first_heading, 'accent' => 'Great videos deserve better words.' === $uw_first_heading ? 'better words.' : '', 'description' => uw_field( 'uw_description', 'Write sharper titles, craft descriptions, and turn the work after the upload into your creative advantage.' ), 'image' => uw_field( 'uw_image', 0 ), 'fallback' => 'creator-recording', 'note' => 'Your voice. A little more polished.' ),
        array( 'label' => 'Make your next move', 'eyebrow' => 'LESS TAB HOPPING. MORE CREATING.', 'heading' => 'A clearer view of', 'accent' => 'what’s next.', 'description' => 'Bring your videos, drafts, and publishing schedule into one focused workspace. Make room for your next great idea.', 'fallback' => 'creator-editing', 'note' => 'From a rough idea to a ready-to-review draft.' ),
        array( 'label' => 'Stay in control', 'eyebrow' => 'YOUR IDEAS. YOUR FINAL SAY.', 'heading' => 'You make the videos.', 'accent' => 'Own the next step.', 'description' => 'Bring your AI key, shape every detail, and review your changes before they reach YouTube. Your channel stays yours.', 'fallback' => 'creator-equipment', 'note' => 'Create with confidence. Publish on your terms.' ),
    );
}
$uw_slider_id = wp_unique_id( 'uw-hero-slider-' );
?>
<section class="uw-cinema" data-uw-slider role="region" aria-roledescription="carousel" aria-label="Discover UploadWriter" <?php if ( ! empty( $block['anchor'] ) ) { echo 'id="' . esc_attr( $block['anchor'] ) . '"'; } ?>>
    <div class="uw-cinema-slides" id="<?php echo esc_attr( $uw_slider_id ); ?>">
        <?php foreach ( $uw_slides as $uw_i => $uw_slide ) : ?>
        <article class="uw-cinema-slide" data-uw-slide role="group" aria-roledescription="slide" aria-label="<?php echo esc_attr( ( $uw_i + 1 ) . ' of ' . count( $uw_slides ) ); ?>" <?php echo $uw_i ? 'hidden' : ''; ?>>
            <div class="uw-cinema-media" aria-hidden="true"><?php uw_image( $uw_slide['image'] ?? 0, $uw_slide['fallback'] ?? 'creator-recording', '', 'uw-cinema-photo', 0 === $uw_i, '100vw', 'eager' ); ?></div>
            <div class="uw-cinema-shade" aria-hidden="true"></div>
            <div class="uw-cinema-content uw-wrap">
                <div class="uw-cinema-copy">
                    <p class="uw-cinema-eyebrow"><span class="uw-live-dot" aria-hidden="true"></span><?php echo esc_html( $uw_slide['eyebrow'] ?? '' ); ?></p>
                    <?php $uw_tag = 0 === $uw_i ? 'h1' : 'h2'; ?>
                    <<?php echo $uw_tag; ?> class="uw-cinema-title"><?php echo esc_html( $uw_slide['heading'] ?? '' ); ?> <em><?php echo esc_html( $uw_slide['accent'] ?? '' ); ?></em></<?php echo $uw_tag; ?>>
                    <p class="uw-cinema-description"><?php echo esc_html( $uw_slide['description'] ?? '' ); ?></p>
                    <div class="uw-cinema-actions"><?php uw_button( 'Start creating' ); ?><a href="#workflow" class="uw-cinema-secondary">See how it works <span aria-hidden="true">↗</span></a></div>
                    <p class="uw-cinema-assurance"><span aria-hidden="true">✓</span> Bring your AI key <span aria-hidden="true">✓</span> Review before you publish</p>
                </div>
                <?php if ( ! empty( $uw_slide['note'] ) ) : ?><p class="uw-cinema-note"><span aria-hidden="true">↗</span><?php echo esc_html( $uw_slide['note'] ); ?></p><?php endif; ?>
            </div>
        </article>
        <?php endforeach; ?>
    </div>
    <?php if ( count( $uw_slides ) > 1 ) : ?>
    <div class="uw-cinema-nav uw-wrap" data-uw-slider-controls hidden>
        <div class="uw-cinema-choices" role="group" aria-label="Choose a slide">
            <?php foreach ( $uw_slides as $uw_i => $uw_slide ) : ?>
            <button type="button" class="uw-cinema-choice" data-uw-slide-to="<?php echo (int) $uw_i; ?>" aria-controls="<?php echo esc_attr( $uw_slider_id ); ?>" <?php echo 0 === $uw_i ? 'aria-current="true"' : ''; ?>><span class="uw-cinema-choice-number" aria-hidden="true"><?php echo esc_html( sprintf( '%02d', $uw_i + 1 ) ); ?></span><span><?php echo esc_html( $uw_slide['label'] ?? 'Slide ' . ( $uw_i + 1 ) ); ?></span></button>
            <?php endforeach; ?>
        </div>
        <div class="uw-cinema-transport"><button type="button" data-uw-slider-prev aria-label="Previous slide">←</button><button type="button" data-uw-slider-pause aria-label="Pause slideshow">Pause</button><button type="button" data-uw-slider-next aria-label="Next slide">→</button></div>
    </div>
    <p class="screen-reader-text" data-uw-slider-status aria-live="polite" aria-atomic="true"></p>
    <?php endif; ?>
</section>
<div class="uw-capabilities"><span>Made for the work after the upload</span><strong>Titles &amp; descriptions</strong><span aria-hidden="true">✦</span><strong>Chapters &amp; tags</strong><span aria-hidden="true">✦</span><strong>Thumbnails</strong><span aria-hidden="true">✦</span><strong>Scheduling</strong></div>
