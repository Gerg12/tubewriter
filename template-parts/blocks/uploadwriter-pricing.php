<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
$data = uw_pricing_data();
$plans = isset( $data['plans'] ) && is_array( $data['plans'] ) ? $data['plans'] : array();
$quota = isset( $data['quota'] ) && is_array( $data['quota'] ) ? $data['quota'] : array();
$daily_cap = (int) ( $quota['daily_cap'] ?? 10000 );
$full_units = (int) ( $quota['full_video_units'] ?? 100 );
$free_creator_day = (int) ( $quota['free_creator_full_per_day'] ?? 1 );
$studio_day = (int) ( $quota['studio_full_per_day'] ?? 2 );
$std_thumb = (int) ( $data['standard_thumbnail_credits'] ?? 1 );
$hi_thumb = (int) ( $data['high_detail_thumbnail_credits'] ?? 3 );
$pack_price = isset( $data['thumbnail_pack']['price'] ) ? $data['thumbnail_pack']['price'] : 4.99;
$pack_credits = (int) ( $data['thumbnail_pack']['credits'] ?? 10 );
$workspace_url = function_exists( 'uw_app_url' ) ? uw_app_url() : home_url( '/tubewriter/' );
$quota_notice = sprintf(
	'YouTube gives UploadWriter %s API units a day, shared by every user. A full video push uses %s units. We cap pushes per user per day, Free and Creator %s full video a day, Studio %s full videos a day, and queue the rest to tomorrow. A queued push does not spend a credit.',
	number_format_i18n( $daily_cap ),
	number_format_i18n( $full_units ),
	number_format_i18n( $free_creator_day ),
	number_format_i18n( $studio_day )
);
$faqs = array(
	'Can I subscribe today?' => 'No. These are planned launch prices. Paid checkout and hosted AI credits are not available today. You can explore the current workspace using your own AI API key, no subscription is purchased from this page.',
	'What counts as a writing credit?' => 'One successful AI draft or rewrite uses one writing credit. A draft can include a title, description, chapters, and tags together. Asking for another version uses another credit. Viewing a draft and making manual changes use no writing credits.',
	'How do thumbnail credits work?' => 'Studio includes 10 thumbnail credits per month. A standard generated image uses 1 credit, a high-detail image uses 3. Each additional version or variation uses credits again. A successful image includes its concept brief. A standalone concept brief on Studio uses one writing credit. You see the credit cost before generating. Failed generations do not consume your credits.',
	'Do the plans include AI costs?' => 'The proposed launch plans include AI usage up to the listed allowances, with no separate AI API key required for those credits. That hosted service is planned. The workspace available today uses your own key, and your provider bills those calls separately.',
	'What happens when I use my allowance?' => 'New AI writing pauses when your balance is used. Free starter credits do not refill, paid writing allowances refresh monthly, or you can choose a plan upgrade. Core workspace tools stay available. Studio users can choose an extra 10-thumbnail-credit pack for $4.99, hosted only. There are no automatic overage charges. Paid monthly credits reset and do not roll over, purchased packs are planned to remain available while the account is active.',
	'Is this unlimited, or does it support teams?' => 'Each proposed plan is for one creator and one connected YouTube channel. AI usage is metered, and long transcripts may need shortening. Team seats, multiple channels, automatic bulk publishing, and audio transcription are not included in these plans.',
	'What about the YouTube daily limit?' => $quota_notice,
);
$waitlist_flash = function_exists( 'uw_waitlist_flash' ) ? uw_waitlist_flash() : null;
$money = static function ( $amount ) {
	$amount = (float) $amount;
	if ( 0.0 === $amount ) {
		return '$0';
	}
	return '$' . number_format( $amount, 2, '.', '' );
};
?>
<section class="uw-wrap uw-pricing-intro" <?php if ( ! empty( $block['anchor'] ) ) { echo 'id="' . esc_attr( $block['anchor'] ) . '"'; } ?>>
	<p class="uw-eyebrow"><?php echo esc_html( uw_field( 'uw_pricing_eyebrow', 'A LITTLE LESS ADMIN. A LOT MORE CREATING.' ) ); ?></p>
	<h1><?php echo esc_html( uw_field( 'uw_pricing_heading', 'A better upload, from $4.99.' ) ); ?></h1>
	<p class="uw-pricing-lead"><?php echo esc_html( uw_field( 'uw_pricing_description', 'Start with your own AI key today. Hosted AI credits are planned for launch, checkout is not open yet.' ) ); ?></p>
	<div class="uw-pricing-status" id="pricing-status">
		<span class="uw-pricing-status-label">LAUNCH PREVIEW</span>
		<p>These are planned monthly prices. Paid subscriptions and included AI credits are not live yet. Current access uses your own AI key. <a href="#launch-status">See what is available today.</a></p>
	</div>
</section>

<div class="uw-wrap uw-pricing-track" data-uw-pricing-track role="group" aria-label="Billing track">
	<span class="uw-pricing-track-label" id="uw-track-label">Show prices for</span>
	<div class="uw-pricing-track-toggle" role="radiogroup" aria-labelledby="uw-track-label">
		<label class="uw-pricing-track-option">
			<input type="radio" name="uw_pricing_track" value="hosted" data-uw-track="hosted">
			<span>Hosted</span>
		</label>
		<label class="uw-pricing-track-option">
			<input type="radio" name="uw_pricing_track" value="byok" data-uw-track="byok" checked>
			<span>BYOK</span>
		</label>
	</div>
	<p class="uw-pricing-track-note" data-uw-track-note>Available today, bring your own Gemini or OpenAI key.</p>
</div>

<section class="uw-wrap uw-pricing-plans" aria-label="Planned monthly pricing" data-uw-pricing-plans>
	<?php foreach ( $plans as $plan ) :
		$studio = ( 'studio' === $plan['id'] );
		$hosted = isset( $plan['price_hosted'] ) ? (float) $plan['price_hosted'] : (float) ( $plan['price'] ?? 0 );
		$byok   = isset( $plan['price_byok'] ) ? (float) $plan['price_byok'] : (float) ( $plan['price'] ?? 0 );
		?>
		<article class="uw-price-card<?php echo $studio ? ' uw-price-card--studio' : ''; ?>" aria-labelledby="uw-plan-<?php echo esc_attr( $plan['id'] ); ?>"
			data-price-hosted="<?php echo esc_attr( $money( $hosted ) ); ?>"
			data-price-byok="<?php echo esc_attr( $money( $byok ) ); ?>">
			<div class="uw-plan-heading">
				<h2 id="uw-plan-<?php echo esc_attr( $plan['id'] ); ?>"><?php echo esc_html( $plan['name'] ); ?></h2>
				<?php if ( $studio ) : ?><span class="uw-plan-tag">WITH THUMBNAILS</span><?php endif; ?>
			</div>
			<p class="uw-plan-description"><?php echo esc_html( $plan['description'] ); ?></p>
			<p class="uw-price">
				<span class="uw-price-amount" data-uw-price-amount><?php echo esc_html( $money( $byok ) ); ?></span>
				<span>USD / month</span>
			</p>
			<p class="uw-plan-price-note" data-uw-price-note>Available today, bring your own Gemini or OpenAI key.</p>
			<p class="uw-plan-allowance">
				<strong><?php echo esc_html( (string) $plan['writing_credits'] ); ?></strong>
				writing credits<?php echo 'free' === $plan['id'] ? ', once, not monthly' : ' / month'; ?>
				<?php if ( $studio ) : ?>
					<span> + <strong><?php echo esc_html( (string) $plan['thumbnail_credits'] ); ?></strong> thumbnail credits / month</span>
				<?php endif; ?>
			</p>
			<ul class="uw-plan-features">
				<?php foreach ( (array) $plan['features'] as $feature ) : ?>
					<li><span aria-hidden="true">✓</span><?php echo esc_html( $feature ); ?></li>
				<?php endforeach; ?>
			</ul>
			<a class="uw-button uw-plan-button" href="#waitlist">Join the beta waitlist <span aria-hidden="true">↓</span></a>
			<p class="uw-plan-alt"><a href="<?php echo esc_url( $workspace_url ); ?>">Explore the current workspace <span aria-hidden="true">↗</span></a></p>
			<p class="uw-plan-footnote"><?php echo 'free' === $plan['id'] ? 'Current access uses your own AI key.' : 'Planned subscription. Checkout is not open.'; ?></p>
		</article>
	<?php endforeach; ?>
</section>
<p class="uw-wrap uw-price-note">All prices in USD, before any applicable taxes. Hosted prices are planned and not buyable yet. BYOK is available today with your own key. Allowances are the same in both tracks.</p>

<section class="uw-wrap uw-pricing-explainers" aria-label="Credit and access details">
	<div class="uw-pricing-explainer">
		<h2>Thumbnail credits</h2>
		<p>Standard image: <?php echo esc_html( (string) $std_thumb ); ?> credit. High-detail image: <?php echo esc_html( (string) $hi_thumb ); ?> credits. Failed generation: no credit.</p>
		<p>Each extra version costs credits again. You see the cost before you generate.</p>
		<p>Thumbnail pack, hosted only, planned: $<?php echo esc_html( number_format( (float) $pack_price, 2, '.', '' ) ); ?> for <?php echo esc_html( (string) $pack_credits ); ?> credits.</p>
	</div>
	<div class="uw-pricing-explainer">
		<h2>Bring your own key</h2>
		<p>Today you bring your own Gemini or OpenAI key. Your provider bills those calls separately, about $0.10 a video in the normal case.</p>
		<p>Hosted AI is planned. With hosted, you pay UploadWriter, no key needed, up to the allowances on your plan.</p>
		<p>BYOK is cheaper because Greg does not pay your AI bill. Hosted is simpler because you do not need a key.</p>
	</div>
	<div class="uw-pricing-explainer uw-pricing-explainer--quota">
		<h2>YouTube daily limit</h2>
		<p><?php echo esc_html( $quota_notice ); ?></p>
	</div>
</section>

<section class="uw-wrap uw-pricing-extra" aria-labelledby="uw-thumbnail-title">
	<div class="uw-pricing-extra-photo"><?php uw_image( 0, 'creator-equipment', 'Camera, headphones, and microphone ready for a creator’s next project.', 'uw-photo', false, '(max-width: 760px) 100vw, 33vw' ); ?></div>
	<div class="uw-pricing-extra-copy">
		<p class="uw-eyebrow">THE FIRST IMPRESSION MATTERS</p>
		<h2 id="uw-thumbnail-title">A little more room to experiment.</h2>
		<p>Studio gives you thumbnail credits for the images around your ideas. Need more variations? A hosted pack is planned for launch.</p>
		<p class="uw-pack-price"><strong>$<?php echo esc_html( number_format( (float) $pack_price, 2, '.', '' ) ); ?></strong> for <?php echo esc_html( (string) $pack_credits ); ?> extra thumbnail credits</p>
		<ul>
			<li>Standard image: <?php echo esc_html( (string) $std_thumb ); ?> credit</li>
			<li>High-detail image: <?php echo esc_html( (string) $hi_thumb ); ?> credits</li>
			<li>Failed generation: no credit. No surprise overages.</li>
		</ul>
		<p>Planned for Studio on the hosted track at launch. BYOK users pay their image provider directly.</p>
	</div>
</section>

<section class="uw-wrap uw-plan-comparison" aria-labelledby="uw-comparison-title">
	<div class="uw-section-heading">
		<p class="uw-eyebrow">THE DETAILS, WITHOUT THE GUESSWORK</p>
		<h2 id="uw-comparison-title">What comes with each plan.</h2>
	</div>
	<div class="uw-comparison-scroll" tabindex="0" role="region" aria-label="Plan comparison; scroll horizontally on small screens">
		<table>
			<caption>Proposed allowances. Free writing credits are granted once. Paid allowances refresh monthly. Core workspace tools are included in every plan. Hosted and BYOK share the same allowances.</caption>
			<thead>
				<tr>
					<th scope="col">Included</th>
					<?php foreach ( $plans as $plan ) : ?>
						<th scope="col"><?php echo esc_html( $plan['name'] ); ?></th>
					<?php endforeach; ?>
				</tr>
			</thead>
			<tbody>
				<tr>
					<th scope="row">BYOK price, per month</th>
					<?php foreach ( $plans as $plan ) : ?>
						<td><?php echo esc_html( $money( isset( $plan['price_byok'] ) ? $plan['price_byok'] : $plan['price'] ) ); ?></td>
					<?php endforeach; ?>
				</tr>
				<tr>
					<th scope="row">Hosted price, per month</th>
					<?php foreach ( $plans as $plan ) : ?>
						<td><?php echo esc_html( $money( isset( $plan['price_hosted'] ) ? $plan['price_hosted'] : $plan['price'] ) ); ?><?php echo (float) ( $plan['price_hosted'] ?? 0 ) > 0 ? ' (planned)' : ''; ?></td>
					<?php endforeach; ?>
				</tr>
				<tr>
					<th scope="row">AI writing credits</th>
					<?php foreach ( $plans as $plan ) : ?>
						<td><?php echo esc_html( (string) $plan['writing_credits'] ); ?><?php echo 'free' === $plan['id'] ? ' starter credits, once' : ' / month'; ?></td>
					<?php endforeach; ?>
				</tr>
				<tr>
					<th scope="row">Thumbnail credits</th>
					<?php foreach ( $plans as $plan ) : ?>
						<td><?php echo ! empty( $plan['thumbnail_credits'] ) ? esc_html( (string) $plan['thumbnail_credits'] ) . ' / month' : 'Not included'; ?></td>
					<?php endforeach; ?>
				</tr>
				<tr><th scope="row">Library, manual edits, and calendar</th><td>Included</td><td>Included</td><td>Included</td></tr>
				<tr><th scope="row">Review, publish, and restore</th><td>Included</td><td>Included</td><td>Included</td></tr>
				<tr><th scope="row">Channel voice and custom prompts</th><td>Included</td><td>Included</td><td>Included</td></tr>
				<tr><th scope="row">Generated thumbnail concepts and images</th><td>Not included</td><td>Not included</td><td>Included</td></tr>
				<tr><th scope="row">Optional thumbnail credit packs</th><td>Not available</td><td>Not available</td><td>$4.99 for 10 credits, hosted only, planned</td></tr>
				<tr><th scope="row">Creator / connected channel</th><td>1 / 1</td><td>1 / 1</td><td>1 / 1</td></tr>
			</tbody>
		</table>
	</div>
</section>

<section class="uw-pricing-faq">
	<div class="uw-wrap uw-pricing-faq-inner">
		<div>
			<p class="uw-eyebrow">A FEW GOOD QUESTIONS</p>
			<h2>Clear plans.<br>No fine-print maze.</h2>
			<p>Your core workspace stays yours. The upgrade is for the extra creative work.</p>
		</div>
		<div class="uw-faq-list">
			<?php foreach ( $faqs as $question => $answer ) : ?>
				<details>
					<summary><?php echo esc_html( $question ); ?><span aria-hidden="true">+</span></summary>
					<p><?php echo esc_html( $answer ); ?></p>
				</details>
			<?php endforeach; ?>
		</div>
	</div>
</section>

<section class="uw-wrap uw-pricing-launch" id="launch-status" aria-labelledby="uw-launch-title">
	<p class="uw-eyebrow">WHERE WE ARE TODAY</p>
	<h2 id="uw-launch-title">Explore now. Choose a plan at launch.</h2>
	<p>The current UploadWriter workspace connects your YouTube channel and your own AI API keys. The plans above describe our proposed hosted and BYOK launch prices. There is no paid checkout here, and visiting this page does not start a subscription.</p>
	<a class="uw-button" href="<?php echo esc_url( $workspace_url ); ?>">Explore the current workspace <span aria-hidden="true">↗</span></a>
</section>

<section class="uw-wrap uw-pricing-waitlist" id="waitlist" aria-labelledby="uw-waitlist-title">
	<p class="uw-eyebrow">PHASE 1 BETA</p>
	<h2 id="uw-waitlist-title">Join the beta waitlist</h2>
	<p>Invite-only for the first 25 creators. After that, new requests stay on the list. No card required. No checkout.</p>
	<?php if ( $waitlist_flash ) : ?>
		<div class="uw-waitlist-flash uw-waitlist-flash--<?php echo esc_attr( $waitlist_flash['type'] ); ?>" role="status">
			<p><?php echo esc_html( $waitlist_flash['message'] ); ?></p>
		</div>
	<?php endif; ?>
	<form class="uw-waitlist-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'uw_waitlist_join' ); ?>
		<input type="hidden" name="action" value="uw_waitlist_join">
		<p>
			<label for="uw_waitlist_email">Email</label>
			<input type="email" id="uw_waitlist_email" name="email" required autocomplete="email" maxlength="190">
		</p>
		<p>
			<label for="uw_waitlist_channel">YouTube channel URL</label>
			<input type="url" id="uw_waitlist_channel" name="channel_url" required placeholder="https://www.youtube.com/@" maxlength="500">
		</p>
		<p>
			<label for="uw_waitlist_videos">Videos per month</label>
			<select id="uw_waitlist_videos" name="videos_per_month" required>
				<option value="">Select one</option>
				<option value="1-4">1 to 4</option>
				<option value="5-12">5 to 12</option>
				<option value="13-30">13 to 30</option>
				<option value="31-plus">31 plus</option>
			</select>
		</p>
		<fieldset class="uw-waitlist-key">
			<legend>I have my own Gemini or OpenAI key</legend>
			<label><input type="radio" name="has_key" value="yes" required> Yes</label>
			<label><input type="radio" name="has_key" value="no"> No</label>
			<label><input type="radio" name="has_key" value="not_yet"> Not yet</label>
		</fieldset>
		<button type="submit" class="uw-button">Join the waitlist</button>
		<p class="uw-waitlist-fine">We will not send a confirmation email that promises a date. Checkout is not open.</p>
	</form>
</section>
