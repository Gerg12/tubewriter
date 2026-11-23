<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
$items = uw_field( 'uw_items', array(
    array( 'title' => 'Start with the source.', 'copy' => 'Pull captions or paste a transcript. Add your notes, then generate a first draft or fill it in by hand.' ),
    array( 'title' => 'Find the right words.', 'copy' => 'Work on titles, your description, chapters, tags, and hashtags. Refine the draft in your own voice.' ),
    array( 'title' => 'Give it a face.', 'copy' => 'Explore a thumbnail brief and image, or skip this step. Uploading a thumbnail is a separate action with its own confirmation.' ),
    array( 'title' => 'Choose its place.', 'copy' => 'Pick an existing playlist or prepare a new one. Playlist changes wait until you publish.' ),
    array( 'title' => 'Choose the moment.', 'copy' => 'Prepare to publish now, choose a schedule, or keep the current visibility.' ),
    array( 'title' => 'Read it one more time.', 'copy' => 'Compare what is live with what will change. Fix any errors before moving forward.' ),
    array( 'title' => 'Have the final say.', 'copy' => 'Tick the checkbox and confirm. UploadWriter backs up the live details before applying your changes.' ),
) );
?>
<section class="uw-process"<?php if ( ! empty( $block['anchor'] ) ) { echo ' id="' . esc_attr( $block['anchor'] ) . '"'; } ?>>
<div class="uw-wrap">
    <header class="uw-process__intro"><p class="uw-eyebrow"><?php echo esc_html( uw_field( 'uw_eyebrow', 'THE WORK AFTER THE UPLOAD' ) ); ?></p><h2><?php echo esc_html( uw_field( 'uw_heading', 'A good draft. A careful review. Your final say.' ) ); ?></h2><p><?php echo esc_html( uw_field( 'uw_description', 'One video, one focused workspace. Move from raw material to words you are ready to put your name to.' ) ); ?></p></header>
    <ol class="uw-process__steps"><?php foreach ( $items as $item ) : ?><li><h3><?php echo esc_html( $item['title'] ); ?></h3><p><?php echo esc_html( $item['copy'] ); ?></p></li><?php endforeach; ?></ol>
    <p class="uw-process__note">Metadata and playlist changes wait for your final confirmation. Optional thumbnail uploads have their own confirmation in the thumbnail step.</p>
</div>
</section>
