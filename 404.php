<?php
/**
 * Branded 404 for UploadWriter.
 *
 * @package UploadWriter
 */

get_header();
?>
<main id="primary" tabindex="-1" class="uw-404">
	<section class="uw-wrap uw-404-inner">
		<p class="uw-eyebrow">PAGE NOT FOUND</p>
		<h1>That page took a wrong turn.</h1>
		<p class="uw-404-lead">The link may be outdated, or the page may have moved. Head home, or open your workspace if you already have an account.</p>
		<div class="uw-404-actions">
			<a class="uw-button" href="<?php echo esc_url( home_url( '/' ) ); ?>">Back to UploadWriter.com <span aria-hidden="true">↗</span></a>
			<a class="uw-text-link" href="<?php echo esc_url( home_url( '/pricing/' ) ); ?>">See pricing</a>
			<a class="uw-text-link" href="<?php echo esc_url( uw_app_url() ); ?>">Open workspace</a>
		</div>
	</section>
</main>
<?php
get_footer();
