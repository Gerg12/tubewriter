<?php
/**
 * Graphic Block Template.
 *
 * @param   array $block The block settings and attributes.
 * @param   string $content The block inner HTML (empty).
 * @param   bool $is_preview True during AJAX preview.
 * @param   (int|string) $post_id The post ID this block is saved to.
 */

// Create id attribute allowing for custom "anchor" value.
if( !empty($block['id']) ) {
  $id = 'graphic-block-' . $block['id'];
}
else {
  $id = 'graphic-block';
  $block = 'not-block';
}
if( !empty($block['anchor']) ) {
    $id = $block['anchor'];
}

// Create class attribute allowing for custom "className" and "align" values.
$className = 'graphic-block';
if( !empty($block['className']) ) {
    $className .= ' ' . $block['className'];
}
if( !empty($block['align']) ) {
    $className .= ' align' . $block['align'];
}

// Get custom fields
$graphic_block_subtitle = get_field('graphic_block_subtitle');
$graphic_block_title = get_field('graphic_block_title');
$graphic_block_text = get_field('graphic_block_text');
$graphic_block_link_1 = get_field('graphic_block_link_1');
$graphic_block_link_2 = get_field('graphic_block_link_2');
?>
<section id="<?php echo esc_attr($id); ?>" class="graphic-block-section gutter <?php echo esc_attr($className); ?>">
  <div class="container">
    <div class="graphic-grid">
        <div class="hero-content" data-aos="fade-up">
            <?php if($graphic_block_subtitle): ?>
                <span class="text-primary uppercase font-mono" style="font-size: 0.875rem; font-weight: 500; letter-spacing: 0.1em;"><?php echo esc_html($graphic_block_subtitle); ?></span>
            <?php endif; ?>
            <?php if($graphic_block_title): ?>
                <h1 style="margin-top: 1rem;"><?php echo esc_html($graphic_block_title); ?></h1>
            <?php endif; ?>
            <?php if($graphic_block_text): ?>
                <div><?php echo apply_filters('the_content', $graphic_block_text); ?></div>
            <?php endif; ?>
            <div>
                <?php if($graphic_block_link_1): ?>
                    <a href="<?php echo esc_url($graphic_block_link_1['url']); ?>" class="btn btn-primary"<?php echo $graphic_block_link_1['target'] ? ' target="' . esc_attr($graphic_block_link_1['target']) . '"' : ''; ?>><?php echo esc_html($graphic_block_link_1['title']); ?></a>
                <?php endif; ?>
                <?php if($graphic_block_link_2): ?>
                    <a href="<?php echo esc_url($graphic_block_link_2['url']); ?>" class="btn btn-secondary" style="margin-left: 1rem;"<?php echo $graphic_block_link_2['target'] ? ' target="' . esc_attr($graphic_block_link_2['target']) . '"' : ''; ?>><?php echo esc_html($graphic_block_link_2['title']); ?></a>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="hero-imagery" data-aos="fade-left">
            <div class="relative w-full max-w-lg mx-auto">
                <!-- Base SVG - Increased Complexity -->
                <svg class="w-full h-auto" viewBox="0 0 600 500" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <!-- Main Window -->
                    <rect x="0.5" y="0.5" width="599" height="499" rx="15.5" fill="var(--color-dark-card)" stroke="var(--color-dark-border)"></rect>
                    <circle cx="32" cy="32" r="8" fill="var(--color-accent-red)"></circle>
                    <circle cx="60" cy="32" r="8" fill="var(--color-accent-yellow)"></circle>
                    <circle cx="88" cy="32" r="8" fill="var(--color-accent-green)"></circle>
                    
                    <!-- Code Lines using tspan for syntax highlighting -->
                    <text x="40" y="120" font-family="var(--font-mono)" font-size="14" fill="var(--color-text-med)">
                        <tspan fill="#c4b5fd">// Let's build something amazing together.</tspan>
                    </text>
                    <text x="40" y="160" font-family="var(--font-mono)" font-size="14" fill="var(--color-text-med)">
                        <tspan fill="#60A5FA">function</tspan> launchClientSuccess( <tspan fill="#d1d5db">client</tspan> ) {
                    </text>
                    <text x="40" y="185" font-family="var(--font-mono)" font-size="14" fill="var(--color-text-med)">
                        <tspan fill="#60A5FA">if</tspan> ( client.goal === <tspan fill="var(--color-accent-green)">'awesome_website'</tspan> ) {
                    </text>
                    <text x="60" y="210" font-family="var(--font-mono)" font-size="14" fill="var(--color-text-med)">
                        <tspan fill="#d1d5db">const</tspan> site = <tspan fill="var(--color-accent-yellow)">createMagic</tspan>();
                    </text>
                    <text x="60" y="235" font-family="var(--font-mono)" font-size="14" fill="var(--color-text-med)">
                        site.<tspan fill="var(--color-accent-yellow)">addComponents</tspan>([<tspan fill="var(--color-accent-green)">'stunning_design'</tspan>, <tspan fill="var(--color-accent-green)">'fast_performance'</tspan>]);
                    </text>
                    <text x="60" y="260" font-family="var(--font-mono)" font-size="14" fill="var(--color-text-med)">
                        <tspan fill="#60A5FA">return</tspan> { status: <tspan fill="var(--color-accent-green)">'launched'</tspan>, happiness: <tspan fill="var(--color-accent-yellow)">100</tspan>% };
                    </text>
                     <text x="40" y="285" font-family="var(--font-mono)" font-size="14" fill="var(--color-text-med)">
                        }
                    </text>
                     <text x="25" y="310" font-family="var(--font-mono)" font-size="14" fill="var(--color-text-med)">
                        }
                    </text>
                    <text x="25" y="350" font-family="var(--font-mono)" font-size="14" fill="var(--color-text-med)">
                        <tspan fill="var(--color-accent-yellow)">launchClientSuccess</tspan>( currentClient );
                    </text>
                </svg>

                <!-- Planet with Rings and Orbiting Moon -->
                <div class="floating-element anim-float-1" style="top: 10%; left: -15%;">
                    <svg width="150" height="150" viewBox="0 0 150 150" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <circle cx="75" cy="75" r="40" fill="var(--color-primary)" fill-opacity="0.5"></circle>
                        <ellipse cx="75" cy="75" rx="70" ry="20" stroke="var(--color-primary)" stroke-width="2" stroke-opacity="0.7"></ellipse>
                    </svg>
                    <!-- Orbiting moon -->
                    <div class="floating-element anim-orbit-1" style="top: 0; left: 0;">
                         <svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <circle cx="10" cy="10" r="10" fill="var(--color-text-med)"></circle>
                        </svg>
                    </div>
                </div>

                <!-- Floating UI Element -->
                <div class="floating-element anim-float-2" style="bottom: -10%; right: -2%;">
                     <svg width="180" height="180" viewBox="0 0 180 180" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <rect width="180" height="180" rx="20" fill="var(--color-dark-card)" fill-opacity="0.8" stroke="var(--color-dark-border)" stroke-width="1"></rect>
                        <circle cx="130" cy="50" r="10" fill="var(--color-primary)"></circle>
                        <rect x="30" y="80" width="120" height="8" rx="4" fill="var(--color-dark-border)"></rect>
                        <rect x="30" y="100" width="80" height="8" rx="4" fill="var(--color-dark-border)"></rect>
                    </svg>
                    <!-- Second orbiting element -->
                    <div class="floating-element anim-orbit-2" style="top: 0; left: 0; transform-origin: 90px 90px;">
                         <svg width="15" height="15" viewBox="0 0 15 15" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <rect width="15" height="15" rx="4" fill="var(--color-accent-green)"></rect>
                        </svg>
                    </div>
                </div>
                
                <!-- Comet -->
                <div class="floating-element anim-comet" style="top: 20%; left: 0%;">
                    <svg width="80" height="20" viewBox="0 0 80 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M10 10 L70 10" stroke="url(#comet-gradient)" stroke-width="4" stroke-linecap="round"></path>
                        <circle cx="10" cy="10" r="10" fill="var(--color-primary)"></circle>
                        <defs>
                            <linearGradient id="comet-gradient" x1="10" y1="10" x2="70" y2="10" gradientUnits="userSpaceOnUse">
                            <stop stop-color="var(--color-primary)"></stop>
                            <stop offset="1" stop-color="var(--color-primary)" stop-opacity="0"></stop>
                            </linearGradient>
                        </defs>
                    </svg>
                </div>
            </div>
        </div>
    </div>
  </div>
</section>