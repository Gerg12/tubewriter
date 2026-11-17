<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
$data = uw_pricing_data();
$plans = $data['plans'];
$faqs = array(
    'Can I subscribe today?' => 'Not yet. These are our planned launch prices and allowances. Paid checkout and hosted AI credits are not available today. You can explore the current workspace using your own AI API key; no subscription is purchased from this page.',
    'What counts as a writing credit?' => 'Free includes 3 starter writing credits, granted once. Creator and Studio allowances refresh monthly. One successful AI draft or rewrite uses one writing credit. A draft can include a title, description, chapters, and tags together. Asking for another version uses another credit. Viewing a draft and making manual changes use no writing credits.',
    'How do thumbnail credits work?' => 'Studio includes 10 thumbnail credits per month. A standard generated image uses 1 credit; a high-detail image uses 3. Each additional version or variation uses credits again. A successful image includes its concept brief. A standalone concept brief on Studio uses one writing credit. You see the credit cost before generating. Failed generations do not consume your credits.',
    'Do the plans include AI costs?' => 'The proposed launch plans include AI usage up to the listed allowances, with no separate AI API key required for those credits. That hosted service is planned. The workspace available today uses your own key, and your provider bills those calls separately.',
    'What stays free?' => 'The proposed Free plan keeps your core channel workspace: browse your library, make manual edits, use the calendar, review changes, publish, and restore saved details. Paid plans add UploadWriter’s AI writing and image work. Connecting a channel and using YouTube remain subject to Google’s permissions and availability.',
    'What happens when I use my allowance?' => 'New AI writing pauses when your balance is used. Free starter credits do not refill; paid writing allowances refresh monthly, or you can choose a plan upgrade. Core workspace tools stay available. Studio users can choose an extra 10-thumbnail-credit pack for $4.99. There are no automatic overage charges. Paid monthly credits reset and do not roll over; purchased packs are planned to remain available while the account is active.',
    'Is this unlimited, or does it support teams?' => 'Each proposed plan is for one creator and one connected YouTube channel. AI usage is metered, and long transcripts may need shortening. Team seats, multiple channels, automatic bulk publishing, and audio transcription are not included in these plans.',
    'Will anything publish automatically?' => 'You stay in control. Review and approve your changes before sending them to YouTube. A generated draft or thumbnail does not publish itself. YouTube verification requirements and shared API limits still apply to every plan.',
);
?>
<section class="uw-wrap uw-pricing-intro" <?php if ( ! empty( $block['anchor'] ) ) { echo 'id="' . esc_attr( $block['anchor'] ) . '"'; } ?>>
    <p class="uw-eyebrow"><?php echo esc_html( uw_field( 'uw_pricing_eyebrow', 'A LITTLE LESS ADMIN. A LOT MORE CREATING.' ) ); ?></p>
    <h1><?php echo esc_html( uw_field( 'uw_pricing_heading', 'A better upload, from $4.99.' ) ); ?></h1>
    <p class="uw-pricing-lead"><?php echo esc_html( uw_field( 'uw_pricing_description', 'Start with a better first draft. Add a creative partner when you need one. Keep the final say, every step of the way.' ) ); ?></p>
    <div class="uw-pricing-status" id="pricing-status"><span class="uw-pricing-status-label">LAUNCH PREVIEW</span><p>These are planned monthly prices. Paid subscriptions and included AI credits are not live yet. <a href="#launch-status">See what is available today.</a></p></div>
</section>
<section class="uw-wrap uw-pricing-plans" aria-label="Planned monthly pricing">
    <?php foreach ( $plans as $plan ) : $studio = 'studio' === $plan['id']; ?>
        <article class="uw-price-card<?php echo $studio ? ' uw-price-card--studio' : ''; ?>" aria-labelledby="uw-plan-<?php echo esc_attr( $plan['id'] ); ?>">
            <div class="uw-plan-heading"><h2 id="uw-plan-<?php echo esc_attr( $plan['id'] ); ?>"><?php echo esc_html( $plan['name'] ); ?></h2><?php if ( $studio ) : ?><span class="uw-plan-tag">WITH THUMBNAILS</span><?php endif; ?></div>
            <p class="uw-plan-description"><?php echo esc_html( $plan['description'] ); ?></p>
            <p class="uw-price"><span class="uw-price-amount">$<?php echo esc_html( $plan['price'] ); ?></span><span>USD / month</span></p>
            <p class="uw-plan-allowance"><strong><?php echo esc_html( $plan['writing_credits'] ); ?></strong> writing credits<?php echo 'free' === $plan['id'] ? ' to try, once' : ' / month'; ?><?php if ( $studio ) : ?><span> + <strong><?php echo esc_html( $plan['thumbnail_credits'] ); ?></strong> thumbnail credits</span><?php endif; ?></p>
            <ul class="uw-plan-features"><?php foreach ( $plan['features'] as $feature ) : ?><li><span aria-hidden="true">✓</span><?php echo esc_html( $feature ); ?></li><?php endforeach; ?></ul>
            <?php if ( 'free' === $plan['id'] ) : ?>
                <a class="uw-button uw-plan-button" href="<?php echo esc_url( uw_app_url() ); ?>">Explore the current workspace <span aria-hidden="true">↗</span></a>
            <?php else : ?>
                <a class="uw-button uw-plan-button" href="#launch-status">See <?php echo esc_html( $plan['name'] ); ?> launch details <span aria-hidden="true">↓</span></a>
            <?php endif; ?>
            <p class="uw-plan-footnote"><?php echo 'free' === $plan['id'] ? 'Current access uses your own AI key.' : 'Planned subscription. Checkout is not open.'; ?></p>
        </article>
    <?php endforeach; ?>
</section>
<p class="uw-wrap uw-price-note">All prices in USD, before any applicable taxes. Monthly billing is planned. Allowances apply to the future hosted AI service.</p>
<section class="uw-wrap uw-pricing-extra" aria-labelledby="uw-thumbnail-title">
    <div class="uw-pricing-extra-photo"><?php uw_image( 0, 'creator-equipment', 'Camera, headphones, and microphone ready for a creator’s next project.', 'uw-photo', false, '(max-width: 760px) 100vw, 33vw' ); ?></div>
    <div class="uw-pricing-extra-copy"><p class="uw-eyebrow">THE FIRST IMPRESSION MATTERS</p><h2 id="uw-thumbnail-title">A little more room to experiment.</h2><p>Studio gives you thumbnail credits for the images around your ideas. Need more variations? Add a pack when you choose.</p><p class="uw-pack-price"><strong>$<?php echo esc_html( $data['thumbnail_pack']['price'] ); ?></strong> for <?php echo esc_html( $data['thumbnail_pack']['credits'] ); ?> extra thumbnail credits</p><ul><li>Standard image: <?php echo esc_html( $data['standard_thumbnail_credits'] ); ?> credit</li><li>High-detail image: <?php echo esc_html( $data['high_detail_thumbnail_credits'] ); ?> credits</li><li>No surprise overages or automatic packs</li></ul><p>Planned for Studio subscribers at launch.</p></div>
</section>
<section class="uw-wrap uw-plan-comparison" aria-labelledby="uw-comparison-title">
    <div class="uw-section-heading"><p class="uw-eyebrow">THE DETAILS, WITHOUT THE GUESSWORK</p><h2 id="uw-comparison-title">What comes with each plan.</h2></div>
    <div class="uw-comparison-scroll" tabindex="0" role="region" aria-label="Plan comparison; scroll horizontally on small screens">
        <table><caption>Proposed allowances. Free writing credits are granted once; paid allowances refresh monthly. Core workspace tools are included in every plan.</caption><thead><tr><th scope="col">Included</th><?php foreach ( $plans as $plan ) : ?><th scope="col"><?php echo esc_html( $plan['name'] ); ?></th><?php endforeach; ?></tr></thead><tbody>
            <tr><th scope="row">AI writing credits</th><?php foreach ( $plans as $plan ) : ?><td><?php echo esc_html( $plan['writing_credits'] ); ?><?php echo 'free' === $plan['id'] ? ' starter credits, once' : ' / month'; ?></td><?php endforeach; ?></tr>
            <tr><th scope="row">Thumbnail credits</th><?php foreach ( $plans as $plan ) : ?><td><?php echo $plan['thumbnail_credits'] ? esc_html( $plan['thumbnail_credits'] ) : 'Not included'; ?></td><?php endforeach; ?></tr>
            <tr><th scope="row">Library, manual edits, and calendar</th><td>Included</td><td>Included</td><td>Included</td></tr>
            <tr><th scope="row">Review, publish, and restore</th><td>Included</td><td>Included</td><td>Included</td></tr>
            <tr><th scope="row">Channel voice and custom prompts</th><td>Included</td><td>Included</td><td>Included</td></tr>
            <tr><th scope="row">Generated thumbnail concepts &amp; images</th><td>Not included</td><td>Not included</td><td>Included</td></tr>
            <tr><th scope="row">Optional thumbnail credit packs</th><td>Not available</td><td>Not available</td><td>$4.99 for 10 credits</td></tr>
            <tr><th scope="row">Creator / connected channel</th><td>1 / 1</td><td>1 / 1</td><td>1 / 1</td></tr>
        </tbody></table>
    </div>
</section>
<section class="uw-pricing-faq"><div class="uw-wrap uw-pricing-faq-inner">
    <div><p class="uw-eyebrow">A FEW GOOD QUESTIONS</p><h2>Clear plans.<br>No fine-print maze.</h2><p>Your core workspace stays yours. The upgrade is for the extra creative work.</p></div>
    <div class="uw-faq-list"><?php foreach ( $faqs as $question => $answer ) : ?><details><summary><?php echo esc_html( $question ); ?><span aria-hidden="true">+</span></summary><p><?php echo esc_html( $answer ); ?></p></details><?php endforeach; ?></div>
</div></section>
<section class="uw-wrap uw-pricing-launch" id="launch-status" aria-labelledby="uw-launch-title"><p class="uw-eyebrow">WHERE WE ARE TODAY</p><h2 id="uw-launch-title">Explore now. Choose a plan at launch.</h2><p>The current UploadWriter workspace connects your YouTube channel and your own AI API keys. The plans above describe our proposed hosted AI service. There is no paid checkout here, and visiting this page does not start a subscription.</p><a class="uw-button" href="<?php echo esc_url( uw_app_url() ); ?>">Explore the current workspace <span aria-hidden="true">↗</span></a></section>

