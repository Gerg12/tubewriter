<?php
/**
 * Post Cards Block Template.
 *
 * @param   array $block The block settings and attributes.
 * @param   string $content The block inner HTML (empty).
 * @param   bool $is_preview True during AJAX preview.
 * @param   (int|string) $post_id The post ID this block is saved to.
 */

// Create id attribute allowing for custom "anchor" value.
if( !empty($block['id']) ) {
  $id = 'post-cards-' . $block['id'];
}
else {
  $id = 'post-cards';
  $block = 'not-block';
}
if( !empty($block['anchor']) ) {
    $id = $block['anchor'];
}

// Create class attribute allowing for custom "className" and "align" values.
$className = 'post-cards-block';
if( !empty($block['className']) ) {
    $className .= ' ' . $block['className'];
}
if( !empty($block['align']) ) {
    $className .= ' align' . $block['align'];
}

$post_cards_selector = get_field('post_cards_selector');
$post_card_width = get_field('post_card_width');
$post_cards_show_description = get_field('post_cards_show_description');
?>
<section id="<?php echo esc_attr($id); ?>" class="post-cards-section gutter <?php echo esc_attr($className); ?> post-cards-<?php echo $post_card_width; ?> style-<?php echo $post_cards_style; ?>">
  <div class="container">
		<div class="post-cards__header">
			<h2 class="post-cards__header fadein">
				<?php echo get_field('post_cards_title'); ?>
			</h2>
		</div>
		<div class="post-cards__wrapper fadein">
			<?php foreach( $post_cards_selector as $post_card ): ?>
				<?php
					// Product Vars
					$post_card_block_selector_title = get_the_title( $post_card->ID );
					$post_card_block_selector_description = get_the_excerpt( $post_card->ID );
					$post_url = get_permalink( $post_card->ID );
				?>
				<a class="post-card__link post-cards__item" href="<?php echo $post_url; ?>">
					
					<div class="post-card-block-inner" >
						<div class="post_card__image-box">
							<?php
								$featured_image_url = get_the_post_thumbnail_url($post_card->ID, 'full');
								$srcset = wp_get_attachment_image_srcset(get_post_thumbnail_id($post_card->ID), 'full');
							?>
							<?php if($featured_image_url): ?>
								<img src="<?php echo esc_url( $featured_image_url ); ?>" srcset="<?php echo esc_attr( $srcset ); ?>">
							<?php else: ?>
								<div>
									<?php
										$post_type_object = get_post_type_object(get_post_type($post_card));
										$singular_name = $post_type_object->labels->singular_name;
										echo $singular_name;
									?>
								</div>
								
							<?php endif; ?>
						</div>
						<?php if($post_cards_show_description): ?>
							<?php if($post_card_block_selector_title): ?>
								<h3 class="post-card__title">
									<?php echo  wp_kses_post( $post_card_block_selector_title ); ?>
								</h3>
							<?php endif; ?>
							<div class="post-card-block-description">
								<?php if($post_card_block_selector_description): ?>
									<?php echo apply_filters( 'the_content', $post_card_block_selector_description ); ?>
								<?php endif; ?>
							</div>
						<?php endif; ?>
					
					</div>
				</a>
			<?php endforeach; ?>
		</div>
    
  </div>
</section>