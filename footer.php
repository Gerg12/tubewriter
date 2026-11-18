<footer class="uw-footer">
    <div><a class="uw-logo" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php uw_brand(); ?></a><p>A little less admin. A lot more creating.</p></div>
    <div class="uw-footer-links">
        <a href="<?php echo esc_url( home_url( '/pricing/' ) ); ?>">Pricing</a>
        <a href="<?php echo esc_url( uw_app_url() ); ?>">Video workspace ↗</a>
        <?php if ( get_privacy_policy_url() ) : ?>
            <a href="<?php echo esc_url( get_privacy_policy_url() ); ?>">Privacy Policy</a>
        <?php else : ?>
            <a href="<?php echo esc_url( home_url( '/privacy-policy/' ) ); ?>">Privacy Policy</a>
        <?php endif; ?>
        <a href="<?php echo esc_url( home_url( '/terms-of-use/' ) ); ?>">Terms of Use</a>
        <a href="mailto:hello@uploadwriter.com">Contact</a>
        <span>&copy; <?php echo esc_html( wp_date( 'Y' ) ); ?> Gregory S Johnson LLC</span>
    </div>
    <p class="uw-footer-note">An independent tool for YouTube creators. Not affiliated with or endorsed by YouTube or Google.</p>
</footer>
</div>
<?php wp_footer(); ?>
</body></html>
