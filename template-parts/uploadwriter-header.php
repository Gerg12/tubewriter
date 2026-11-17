<header class="uw-header" id="masthead">
    <a class="uw-logo" href="<?php echo esc_url( home_url( '/' ) ); ?>" aria-label="UploadWriter.com home"><?php uw_brand(); ?></a>
    <button class="uw-menu-toggle" type="button" aria-expanded="false" aria-controls="uw-navigation">Menu <span aria-hidden="true">☰</span></button>
    <nav id="uw-navigation" class="uw-navigation" aria-label="Main navigation">
        <a href="<?php echo esc_url( home_url( '/#features' ) ); ?>">The toolkit</a>
        <a href="<?php echo esc_url( home_url( '/#workflow' ) ); ?>">How it works</a>
        <a href="<?php echo esc_url( home_url( '/pricing/' ) ); ?>"<?php echo is_page( 'pricing' ) ? ' aria-current="page"' : ''; ?>>Pricing</a>
        <a href="<?php echo esc_url( class_exists( 'TW_Frontend' ) ? TW_Frontend::url( 'account' ) : wp_login_url() ); ?>">Your videos</a>
        <?php if ( ! is_user_logged_in() ) : ?><a class="uw-login" href="<?php echo esc_url( class_exists( 'TW_Frontend' ) ? TW_Frontend::url( 'login' ) : wp_login_url() ); ?>">Log in</a><?php endif; ?>
        <?php if ( is_user_logged_in() && class_exists( 'TW_Frontend' ) ) : $uw_user = wp_get_current_user(); ?>
            <span class="uw-header-user"><?php echo esc_html( $uw_user->display_name ?: $uw_user->user_login ); ?></span>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="uw-header-logout">
                <?php wp_nonce_field( 'tw_front_logout' ); ?>
                <input type="hidden" name="action" value="tw_front_logout">
                <button type="submit">Log out <span aria-hidden="true">↗</span></button>
            </form>
        <?php else : ?>
            <a class="uw-button uw-button--small" href="<?php echo esc_url( uw_app_url() ); ?>">Get started <span aria-hidden="true">↗</span></a>
        <?php endif; ?>
    </nav>
</header>


