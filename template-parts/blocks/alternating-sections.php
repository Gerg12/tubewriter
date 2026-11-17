<?php
/**
 * Alternating Sections Block Template.
 *
 * @param   array $block The block settings and attributes.
 * @param   string $content The block inner HTML (empty).
 * @param   bool $is_preview True during AJAX preview.
 * @param   (int|string) $post_id The post ID this block is saved to.
 */

// Create id attribute allowing for custom "anchor" value.
$id = 'alternating-sections-' . $block['id'];
if( !empty($block['anchor']) ) {
    $id = $block['anchor'];
}

// Create class attribute allowing for custom "className" and "align" values.
$className = 'alternating-sections-block';
if( !empty($block['className']) ) {
    $className .= ' ' . $block['className'];
}
if( !empty($block['align']) ) {
    $className .= ' align' . $block['align'];
}

$alternating_sections_title = get_field('alternating_sections_title');
$alternating_sections_subtitle = get_field('alternating_sections_subtitle');
?>
<section id="<?php echo esc_attr($id); ?>" class="alternating-section no-gutter <?php echo esc_attr($className); ?>">
  <div class="text-center">
      <?php if($alternating_sections_title): ?>
          <h2 class="section-title"><?php echo esc_html($alternating_sections_title); ?></h2>
      <?php endif; ?>
      <?php if($alternating_sections_subtitle): ?>
        <div class="section-subtitle">
          <p><?php echo esc_html($alternating_sections_subtitle); ?></p>
        </div>
      <?php endif; ?>
  </div>
  <div class="container-full">
  <?php if(have_rows('alternating_sections_repeater')): ?>
    <?php while(have_rows('alternating_sections_repeater')): the_row(); ?>
      <?php if(get_sub_field('alternating_sections_icon')): 
        $icon_class = 'has-icon';
      endif;?>
      <div class="alternating-section-item ">
        <div class="text-container">
          <div class="text-container-inner" data-aos="fade-up">
            <?php if(get_sub_field('alternating_sections_icon')): ?>
              <img class="alternating-icon-image" src="<?php echo get_sub_field('alternating_sections_icon'); ?>" alt="Helo Icon">
            <?php endif; ?>
            <?php if(get_sub_field('alternating_sections_title')): ?>
              <h3 class="header2-simple"><?php echo get_sub_field('alternating_sections_title'); ?></h3>
            <?php endif; ?>
            <?php if(get_sub_field('alternating_sections_text')): ?>
              <div class="paragraph-sm">
                <?php echo get_sub_field('alternating_sections_text'); ?>
              </div>
            <?php endif; ?>
            <?php if(get_sub_field('alternating_sections_link_text')): ?>
              <a href="<?php echo get_sub_field('alternating_sections_link_url'); ?>" class="alternating-text-link"><?php echo get_sub_field('alternating_sections_link_text'); ?></a>
            <?php endif; ?>
          </div>
        </div>
        <div class="image-container">
          <img src="<?php echo get_sub_field('alternating_sections_image')['url']; ?>" alt="<?php echo get_sub_field('alternating_sections_image')['alt']; ?>">
        </div>
      </div>
    <?php endwhile; //while(have_rows('alternating_sections_repeater')): the_row(); ?>
  <?php endif; //if(have_rows('alternating_sections_repeater')): ?>
    
  </div>
</section>