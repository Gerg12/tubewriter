<header class="uw-header" id="masthead">
    <a class="uw-logo" href="<?php echo esc_url( home_url( '/' ) ); ?>" aria-label="UploadWriter.com home"><?php uw_brand(); ?></a>
    <button class="uw-menu-toggle" type="button" aria-expanded="false" aria-controls="uw-navigation">Menu <span aria-hidden="true">☰</span></button>
    <nav id="uw-navigation" class="uw-navigation" aria-label="Main navigation">
        <a href="<?php echo esc_url( home_url( '/#features' ) ); ?>">The toolkit</a>
        <a href="<?php echo esc_url( uw_story_url() ); ?>"<?php echo is_page( 'how-it-works' ) ? ' aria-current="page"' : ''; ?>>How it works</a>
        <a href="<?php echo esc_url( home_url( '/pricing/' ) ); ?>"<?php echo is_page( 'pricing' ) ? ' aria-current="page"' : ''; ?>>Pricing</a>
        <a href="<?php echo esc_url( class_exists( 'TW_Frontend' ) ? TW_Frontend::url( 'account' ) : wp_login_url() ); ?>">Your videos</a>
        <?php if ( ! is_user_logged_in() ) : ?><a class="uw-login" href="<?php echo esc_url( class_exists( 'TW_Frontend' ) ? TW_Frontend::url( 'login' ) : wp_login_url() ); ?>">Log in</a><?php endif; ?>
        <?php if ( is_user_logged_in() && class_exists( 'TW_Frontend' ) ) : $uw_user = wp_get_current_user(); ?>
            <?php if ( class_exists( 'TW_Credits' ) && function_exists( 'tw_user_can_app' ) && tw_user_can_app() ) :
				$uw_credits      = TW_Credits::snapshot( (int) $uw_user->ID );
				$uw_credit_title = implode( ' · ', array( $uw_credits['writing_line'], $uw_credits['thumbnail_line'], $uw_credits['daily_units_line'] ) );
				if ( ! empty( $uw_credits['unlimited'] ) ) {
					$uw_credit_label = ( 'Admin' === ( $uw_credits['plan_name'] ?? '' ) ) ? 'Admin · unlimited' : 'Unlimited';
				} else {
					$uw_credit_label = sprintf(
						'%d writing · %d thumbs · %d YT today',
						(int) $uw_credits['writing_left'],
						(int) $uw_credits['thumbnail_left'],
						(int) $uw_credits['daily_units_remaining']
					);
				}
				?>
				<span class="uw-header-credits" title="<?php echo esc_attr( $uw_credit_title ); ?>"><?php echo esc_html( $uw_credit_label ); ?></span>
			<?php endif; ?>
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


