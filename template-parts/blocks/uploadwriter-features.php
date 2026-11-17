<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
$items = uw_field( 'uw_items', array(
    array( 'title' => 'Find the words that fit.', 'copy' => 'Draft titles, descriptions, chapters, and tags with AI, then shape every detail into your own voice.' ),
    array( 'title' => 'Give every video a better first impression.', 'copy' => 'Explore thumbnail ideas alongside your metadata. Bring your own AI key and keep the creative direction yours.' ),
    array( 'title' => 'See the bigger picture.', 'copy' => 'Search your video library, filter by visibility, and see scheduled uploads in one clear publishing calendar.' ),
) );
$photos = array(
    array( 'creator-recording', 'A creator sharing an idea with her camera in a home studio.' ),
    array( 'creator-equipment', 'A camera, headphones, microphone, and notebook in a creator studio.' ),
    array( 'creator-editing', 'A creator reviewing sunset footage at his editing desk.' ),
);
$labels = array( 'THE WORDS', 'THE FIRST IMPRESSION', 'THE BIG PICTURE' );
?>
<section class="uw-showcase" id="<?php echo esc_attr( ! empty( $block['anchor'] ) ? $block['anchor'] : 'features' ); ?>">
    <div class="uw-wrap">
        <div class="uw-showcase-heading">
            <div><p class="uw-eyebrow"><?php echo esc_html( uw_field( 'uw_eyebrow', 'A SMALL TOOLKIT. A BIG DIFFERENCE.' ) ); ?></p><h2><?php echo esc_html( uw_field( 'uw_heading', 'Your next upload, all together.' ) ); ?></h2></div>
            <p><?php echo esc_html( uw_field( 'uw_description', 'Everything around your video, with a little more focus and a lot less friction.' ) ); ?></p>
            <?php uw_float_art( 'spark', 'uw-showcase-spark' ); ?>
        </div>
        <div class="uw-story-grid">
        <?php foreach ( $items as $i => $item ) : $photo = $photos[ $i % 3 ]; ?>
            <article class="uw-story uw-story--<?php echo (int) ( $i % 3 + 1 ); ?>">
                <div class="uw-story-image">
                    <?php uw_image( $item['image'] ?? 0, $photo[0], $photo[1], 'uw-photo', false, '(max-width: 760px) 100vw, 60vw' ); ?>
                    <span class="uw-story-image-label"><span aria-hidden="true">↗</span> MADE FOR YOUR NEXT CHAPTER</span>
                    <?php if ( 0 === $i % 3 ) : ?><div class="uw-story-sticker" aria-hidden="true"><span>Aa</span> YOUR VOICE.<br>MORE POSSIBILITY.</div><?php endif; ?>
                    <?php if ( 1 === $i % 3 ) { uw_float_art( 'play', 'uw-story-play' ); } ?>
                    <?php if ( 2 === $i % 3 ) : ?><div class="uw-story-sticker uw-story-sticker--schedule" aria-hidden="true"><span>↗</span> A LITTLE LESS ADMIN.<br>A LOT MORE CREATING.</div><?php endif; ?>
                </div>
                <div class="uw-story-copy">
                    <p class="uw-story-kicker"><span><?php echo esc_html( sprintf( '%02d', $i + 1 ) ); ?></span><?php echo esc_html( $labels[ $i % 3 ] ); ?></p>
                    <h3><?php echo esc_html( $item['title'] ); ?></h3><p><?php echo esc_html( $item['copy'] ); ?></p>
                    <a class="uw-story-link" href="<?php echo esc_url( uw_app_url() ); ?>">Explore your workspace <span aria-hidden="true">↗</span></a>
                </div>
            </article>
        <?php endforeach; ?>
        </div>
    </div>
</section>
