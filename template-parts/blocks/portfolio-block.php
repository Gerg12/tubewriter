<?php
/**
 * Portfolio Block Template.
 *
 * @param   array $block The block settings and attributes.
 * @param   string $content The block inner HTML (empty).
 * @param   bool $is_preview True during AJAX preview.
 * @param   (int|string) $post_id The post ID this block is saved to.
 */

// Create id attribute allowing for custom "anchor" value.
$id = 'portfolio-block-' . $block['id'];
if( !empty($block['anchor']) ) {
    $id = $block['anchor'];
}

// Create class attribute allowing for custom "className" and "align" values.
$className = 'portfolio-block';
if( !empty($block['className']) ) {
    $className .= ' ' . $block['className'];
}
if( !empty($block['align']) ) {
    $className .= ' align' . $block['align'];
}

// Get custom fields
$portfolio_title = get_field('portfolio_title');
$portfolio_subtitle = get_field('portfolio_subtitle');
$portfolio_repeater = get_field('portfolio_repeater');
?>
<section id="<?php echo esc_attr($id); ?>" class="portfolio-block-section gutter bg-offset <?php echo esc_attr($className); ?>">
    <div class="container">
        <div class="text-center">
            <?php if($portfolio_title): ?>
                <h2 class="section-title"><?php echo esc_html($portfolio_title); ?></h2>
            <?php endif; ?>
            <?php if($portfolio_subtitle): ?>
                <p class="section-subtitle"><?php echo esc_html($portfolio_subtitle); ?></p>
            <?php endif; ?>
        </div>
        <?php if($portfolio_repeater): ?>
            <div class="grid grid-cols-2">
                <?php foreach($portfolio_repeater as $index => $portfolio_item): ?>
                    <a href="<?php echo esc_url(isset($portfolio_item['portfolio_repeater_link']['url']) ? $portfolio_item['portfolio_repeater_link']['url'] : '#'); ?>" class="portfolio-item" data-aos="fade-up" data-aos-delay="<?php echo $index * 100; ?>">
                        <?php if($portfolio_item['portfolio_repeater_image']): ?>
                            <img src="<?php echo esc_url($portfolio_item['portfolio_repeater_image']['url']); ?>" alt="<?php echo esc_attr($portfolio_item['portfolio_repeater_image']['alt']); ?>">
                        <?php endif; ?>
                        <div class="content">
                            <div>
                                <?php if($portfolio_item['portfolio_repeater_title']): ?>
                                    <h3 class="title"><?php echo esc_html($portfolio_item['portfolio_repeater_title']); ?></h3>
                                <?php endif; ?>
                                <?php if($portfolio_item['portfolio_repeater_subtitle']): ?>
                                    <div class="desc"><?php echo apply_filters('the_content', $portfolio_item['portfolio_repeater_subtitle']); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>