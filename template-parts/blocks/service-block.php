<?php
/**
 * Service Block Template.
 *
 * @param   array $block The block settings and attributes.
 * @param   string $content The block inner HTML (empty).
 * @param   bool $is_preview True during AJAX preview.
 * @param   (int|string) $post_id The post ID this block is saved to.
 */

// Create id attribute allowing for custom "anchor" value.
$id = 'service-block-' . $block['id'];
if( !empty($block['anchor']) ) {
    $id = $block['anchor'];
}

// Create class attribute allowing for custom "className" and "align" values.
$className = 'service-block';
if( !empty($block['className']) ) {
    $className .= ' ' . $block['className'];
}
if( !empty($block['align']) ) {
    $className .= ' align' . $block['align'];
}

// Get custom fields
$service_block_subtitle = get_field('service_block_subtitle');
$service_block_title = get_field('service_block_title');
$service_block_text = get_field('service_block_text');
$service_block_repeater = get_field('service_block_repeater');
$service_block_link_1 = get_field('service_block_link_1');
$service_block_link_2 = get_field('service_block_link_2');
?>
<section id="<?php echo esc_attr($id); ?>" class="service-block-section gutter <?php echo esc_attr($className); ?>">
    <div class="container">
        <div class="text-center">
            <?php if($service_block_subtitle): ?>
                <p class="section-subtitle"><?php echo esc_html($service_block_subtitle); ?></p>
            <?php endif; ?>
            <?php if($service_block_title): ?>
                <h2 class="section-title"><?php echo esc_html($service_block_title); ?></h2>
            <?php endif; ?>
            <?php if($service_block_text): ?>
                <div class="section-subtitle"><?php echo apply_filters('the_content', $service_block_text); ?></div>
            <?php endif; ?>
        </div>
        <?php if($service_block_repeater): ?>
            <div class="grid grid-cols-3">
                <?php foreach($service_block_repeater as $index => $service): ?>
                    <div class="service-card" data-aos="fade-up" data-aos-delay="<?php echo ($index + 1) * 100; ?>">
                        <?php if($service['service_block_repeater_title']): ?>
                            <h3><?php echo esc_html($service['service_block_repeater_title']); ?></h3>
                        <?php endif; ?>
                        <?php if($service['service_block_repeater_text']): ?>
                            <p><?php echo apply_filters('the_content', $service['service_block_repeater_text']); ?></p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if($service_block_link_1 || $service_block_link_2): ?>
            <div class="text-center" style="margin-top: 2rem;">
                <?php if($service_block_link_1): ?>
                    <a href="<?php echo esc_url($service_block_link_1['url']); ?>" class="btn btn-primary"<?php echo $service_block_link_1['target'] ? ' target="' . esc_attr($service_block_link_1['target']) . '"' : ''; ?>><?php echo esc_html($service_block_link_1['title']); ?></a>
                <?php endif; ?>
                <?php if($service_block_link_2): ?>
                    <a href="<?php echo esc_url($service_block_link_2['url']); ?>" class="btn btn-secondary" style="margin-left: 1rem;"<?php echo $service_block_link_2['target'] ? ' target="' . esc_attr($service_block_link_2['target']) . '"' : ''; ?>><?php echo esc_html($service_block_link_2['title']); ?></a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</section>