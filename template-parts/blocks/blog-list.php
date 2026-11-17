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
if( !empty($block['id']) ) {
	$id = 'blog-list-' . $block['id'];
}
else {
	$id = 'blog-list';
	$block = 'not-block';
}
if( !empty($block['anchor']) ) {
		$id = $block['anchor'];
}

// Create class attribute allowing for custom "className" and "align" values.
$className = 'blog-list-block';
if( !empty($block['className']) ) {
		$className .= ' ' . $block['className'];
}
if( !empty($block['align']) ) {
		$className .= ' align' . $block['align'];
}


$blog_list_style = get_field('blog_list_style');
$blog_list_type = get_field('blog_list_type');
?>
<section id="<?php echo esc_attr($id); ?>" class="blog-list-section gutter gutter-lg <?php echo esc_attr($className); ?> style-<?php echo $blog_list_style; ?>">
	<div id="blog-list" class="container">
		<div class="blog-list-inner">
			<div class="blog-list__header">
				<h2 class="blog-list__title">
					<?php echo get_field('blog_list_title'); ?>
				</h2>
			</div>
			<div class="blog-list__wrapper">
				<?php
				$paged = (get_query_var('paged')) ? get_query_var('paged') : 1;
				$query = new WP_Query(array(
						'post_type' => $blog_list_type,
						'post_status' => 'publish',
						'orderby' => 'date',
						'order' => 'DESC',
						'posts_per_page' => 6,
						'paged' => $paged,
				));
				?>
				<?php if ($query->have_posts()) : ?>
					<?php while ($query->have_posts()) : $query->the_post();?>
						<div class="blog-list__item">
							<a class="blog-list__item-link" href="<?php echo get_the_permalink(); ?>">
								<div class="blog-list__item-inner">
									<div class="blog-list__image-box">
										<div class="image-container">
										<?php if (has_post_thumbnail()): ?>
												<?php the_post_thumbnail('full', ['class' => 'blog-list-repeater__item-graphic']); ?>
										<?php endif; ?>
										</div>
									</div>
									<div class="blog-list__content-box">
										<div class="text-container-inner">
											<?php if (get_the_title()): ?>
												<h2 class="blog-list-repeater__item-title"><?php echo get_the_title(); ?></h2>
											<?php endif; ?>
											<?php
											$podcast_icon = get_field('podcast_icon', get_the_ID());
											if($podcast_icon): ?>
												<div class="blog-list__podcast-icon">
													<img src="<?php echo esc_url($podcast_icon['url']); ?>" alt="<?php echo esc_attr($podcast_icon['alt']); ?>">
												</div>
											<?php endif; ?>
											<?php $date = get_the_date('F d, Y'); ?>
													<?php if ($date): ?>
															<div class="blog-list-repeater__item-date"><?php echo $date; ?></div>
													<?php endif; ?>
											<?php if (has_excerpt()): ?>
												<div class="blog-list-repeater__item-text">
														<?php echo apply_filters('the_excerpt', get_the_excerpt()); ?>
												</div>
											<?php endif; ?>
										</div>
									</div>
								</div>
							</a>
			
						</div>
					<?php endwhile; ?>
			
				<?php endif; ?>
				<?php wp_reset_postdata(); ?>
			</div>
		</div>
		<div class="pagination">
					<?php
						$big = 999999999; // need an unlikely integer
						echo paginate_links(array(
							'base' => str_replace($big, '%#%', esc_url(get_pagenum_link($big))),
							'format' => '?paged=%#%#blog',
							'current' => max(1, get_query_var('paged')),
							'total' => $query->max_num_pages,
						));
					?>
				</div>
				<script>
					jQuery(function($) {
						$(document).ready(function() {
							$('.pagination a').each(function() {
									var href = $(this).attr('href');
									$(this).attr('href', href + '#blog-list');
							});
						});
						$(document).on('click', '.pagination-disable a', function(e) {
							// e.preventDefault();
							// var href = $(this).attr('href');
							// var pageNumber = href.match(/\/page\/(\d+)\/?$/)[1];
							// console.log('Page Number:', pageNumber);
							// loadPosts(pageNumber);
						});

						function loadPosts(page) {
							$.ajax({
								url: '<?php echo admin_url('admin-ajax.php'); ?>',
								type: 'POST',
								data: {
									action: 'load_posts',
									page: page
								},
								success: function(response) {
									$('.blog-list__wrapper').html(response);
								}
							});
						}
					});
				</script>
		
	</div>
</section>