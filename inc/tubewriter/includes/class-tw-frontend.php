<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Public UploadWriter app (route prefix /tubewriter/): home, login, register, account, schedule.
 */
class TW_Frontend {

	const QUERY = 'tw_app';

	public function __construct() {
		add_action( 'init', array( $this, 'rewrite' ) );
		add_action( 'init', array( $this, 'maybe_flush_rewrites' ), 99 );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'template_redirect', array( $this, 'route' ) );
		add_action( 'admin_post_nopriv_tw_front_login', array( $this, 'handle_login' ) );
		add_action( 'admin_post_tw_front_login', array( $this, 'handle_login' ) );
		add_action( 'admin_post_nopriv_tw_front_register', array( $this, 'handle_register' ) );
		add_action( 'admin_post_tw_front_register', array( $this, 'handle_register' ) );
		add_action( 'admin_post_tw_front_logout', array( $this, 'handle_logout' ) );
		add_action( 'admin_post_tw_front_save_keys', array( $this, 'handle_save_keys' ) );
		add_action( 'admin_post_tw_front_save_identity', array( $this, 'handle_save_identity' ) );
		add_action( 'admin_post_tw_front_reset_prompt', array( $this, 'handle_reset_prompt' ) );
		add_action( 'admin_post_tw_front_reset_thumb_prompt', array( $this, 'handle_reset_thumb_prompt' ) );
		add_action( 'admin_post_tw_front_reset_thumb_render_prompt', array( $this, 'handle_reset_thumb_render_prompt' ) );
		add_action( 'admin_post_tw_front_connect', array( $this, 'handle_connect' ) );
		add_action( 'admin_post_tw_front_disconnect', array( $this, 'handle_disconnect' ) );
		add_filter( 'show_admin_bar', array( $this, 'maybe_hide_admin_bar' ) );
	}

	public static function activate() {
		add_role(
			'tubewriter',
			'UploadWriter',
			array(
				'read'         => true,
				'upload_files' => true,
			)
		);
		self::register_rewrites();
		flush_rewrite_rules();
	}

	public static function register_rewrites() {
		add_rewrite_rule( '^tubewriter/?$', 'index.php?' . self::QUERY . '=home', 'top' );
		add_rewrite_rule( '^tubewriter/login/?$', 'index.php?' . self::QUERY . '=login', 'top' );
		add_rewrite_rule( '^tubewriter/register/?$', 'index.php?' . self::QUERY . '=register', 'top' );
		add_rewrite_rule( '^tubewriter/account/?$', 'index.php?' . self::QUERY . '=account', 'top' );
		add_rewrite_rule( '^tubewriter/schedule/?$', 'index.php?' . self::QUERY . '=schedule', 'top' );
		add_rewrite_rule( '^tubewriter/oauth/?$', 'index.php?' . self::QUERY . '=oauth', 'top' );
	}

	public function rewrite() {
		self::register_rewrites();
	}

	public function maybe_flush_rewrites() {
		if ( ! get_option( 'tw_flush_rewrite' ) ) {
			return;
		}
		self::register_rewrites();
		flush_rewrite_rules( false );
		delete_option( 'tw_flush_rewrite' );
	}

	public function query_vars( $vars ) {
		$vars[] = self::QUERY;
		return $vars;
	}

	public function maybe_hide_admin_bar( $show ) {
		if ( get_query_var( self::QUERY ) ) {
			return false;
		}
		return $show;
	}

	public static function url( $path = '' ) {
		$path = ltrim( (string) $path, '/' );
		return home_url( '/tubewriter/' . ( $path ? $path . '/' : '' ) );
	}

	public function route() {
		$view = get_query_var( self::QUERY );
		if ( ! $view ) {
			return;
		}

		if ( 'oauth' === $view ) {
			$this->oauth_callback();
			return;
		}

		if ( in_array( $view, array( 'account', 'schedule' ), true ) && ! is_user_logged_in() ) {
			wp_safe_redirect( self::url( 'login' ) );
			exit;
		}

		if ( in_array( $view, array( 'account', 'schedule' ), true ) && is_user_logged_in() && ! tw_user_can_app() ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}

		if ( in_array( $view, array( 'login', 'register', 'home' ), true ) && is_user_logged_in() && 'home' !== $view ) {
			if ( tw_user_can_app() ) {
				wp_safe_redirect( self::url( 'account' ) );
			} else {
				wp_safe_redirect( home_url( '/' ) );
			}
			exit;
		}

		if ( 'home' === $view && is_user_logged_in() ) {
			wp_safe_redirect( tw_user_can_app() ? self::url( 'account' ) : home_url( '/' ) );
			exit;
		}

		if ( 'home' === $view ) { wp_safe_redirect( home_url( '/' ) ); exit; }

		nocache_headers();
		status_header( 200 );
		$this->enqueue();
		$this->render( $view );
		exit;
	}

	private function enqueue() {
		if ( is_user_logged_in() ) {
			TW_Context::set_user( get_current_user_id() );
		}

		$deps = array( 'tubewriter-front' );
		$video = isset( $_GET['video'] ) && '' !== (string) wp_unslash( $_GET['video'] ); // phpcs:ignore
		if ( $video ) {
			wp_enqueue_media();
			$deps[] = 'media-editor';
		}

		wp_enqueue_style( 'tubewriter-front', TW_URL . 'assets/front.css', array(), filemtime( TW_DIR . 'assets/front.css' ) );
		wp_enqueue_style( 'tubewriter-admin', TW_URL . 'assets/admin.css', array( 'tubewriter-front' ), filemtime( TW_DIR . 'assets/admin.css' ) );
		wp_enqueue_script( 'tubewriter-front', TW_URL . 'assets/front.js', array(), filemtime( TW_DIR . 'assets/front.js' ), true );
		// Localize onto front.js so TW exists before that file runs (Schedule calendar, etc.).
		wp_localize_script(
			'tubewriter-front',
			'TW',
			array(
				'ajax'               => admin_url( 'admin-ajax.php' ),
				'nonce'              => wp_create_nonce( 'tw_ajax' ),
				'front'              => 1,
				'imageProvider'      => is_user_logged_in() ? TW_Settings::get( 'image_provider' ) : TW_DEFAULT_IMAGE_PROVIDER,
				'imageProviderLabel' => is_user_logged_in() ? TW_Settings::image_provider_label() : '',
				'hasOpenAI'          => ( is_user_logged_in() && TW_Settings::get( 'openai_key' ) ) ? 1 : 0,
				'canThumbnails'      => ( is_user_logged_in() && tw_user_can_thumbnails() ) ? 1 : 0,
				'thumbLibrary'       => ( is_user_logged_in() ) ? TW_Thumb_Library::usage() : array( 'used' => 0, 'limit' => TW_Thumb_Library::FREE_LIMIT, 'unlimited' => 0, 'remaining' => TW_Thumb_Library::FREE_LIMIT ),
				'settingsUrl'        => self::url( 'account' ) . ( TW_Settings::identity_complete() ? '#tw-keys' : '#tw-voice' ),
				'accountUrl'         => self::url( 'account' ),
				'scheduleUrl'        => self::url( 'schedule' ),
				'showQuota'          => current_user_can( 'manage_options' ) ? 1 : 0,
			)
		);
		wp_enqueue_script( 'tubewriter', TW_URL . 'assets/admin.js', $deps, filemtime( TW_DIR . 'assets/admin.js' ), true );
	}

	private function render( $view ) {
		$notice = $this->pull_notice();
		echo '<!DOCTYPE html><html ' . get_language_attributes() . '><head><meta charset="' . esc_attr( get_bloginfo( 'charset' ) ) . '">';
		echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
		echo '<title>' . esc_html( TW_BRAND ) . '</title>';
		wp_head();
		// The library gets its own layout scope so marketing, editor and schedule stay independent.
		$library_class = ( 'account' === $view && empty( $_GET['video'] ) ) ? ' tw-app--videos' : '';
		echo '</head><body class="tw-app uw-site tw-app--' . esc_attr( $view ) . esc_attr( $library_class ) . '">';
		wp_body_open();
		echo '<a class="skip-link screen-reader-text" href="#primary">Skip to content</a>';
		if ( function_exists( 'uw_brand' ) ) { get_template_part( 'template-parts/uploadwriter-header' ); }
		echo '<div class="tw-app-bg" aria-hidden="true"></div>';
		echo '<div id="tw-toasts" class="tw-toasts" aria-live="polite"></div>';

		if ( $notice ) {
			printf(
				'<div class="tw-flash tw-flash--%s" role="status">%s</div>',
				esc_attr( $notice['type'] ),
				esc_html( $notice['message'] )
			);
		}

		if ( 'login' === $view ) {
			$this->view_login();
		} elseif ( 'register' === $view ) {
			$this->view_register();
		} elseif ( 'account' === $view ) {
			$this->view_account();
		} elseif ( 'schedule' === $view ) {
			$this->view_schedule();
		} else {
			$this->view_home();
		}

		if ( in_array( $view, array( 'login', 'register' ), true ) ) { echo '<a class="uw-app-home-link" href="' . esc_url( home_url( '/' ) ) . '">&larr; Back to UploadWriter.com</a>'; }
		echo '<footer class="uw-footer uw-footer--app">';
		echo '<div class="uw-footer-links">';
		echo '<a href="' . esc_url( get_privacy_policy_url() ? get_privacy_policy_url() : home_url( '/privacy-policy/' ) ) . '">Privacy Policy</a>';
		echo '<a href="' . esc_url( home_url( '/terms-of-use/' ) ) . '">Terms of Use</a>';
		echo '<a href="mailto:hello@uploadwriter.com">Contact</a>';
		echo '<span>&copy; ' . esc_html( wp_date( 'Y' ) ) . ' Gregory S Johnson LLC</span>';
		echo '</div></footer>';
		wp_footer();
		echo '</body></html>';
	}

	private function view_home() {
		wp_safe_redirect( home_url( '/' ) );
		exit;
	}

	private function view_login() {
		?>
		<main id="primary" tabindex="-1" class="tw-shell tw-shell--auth">
			<section class="tw-auth-card tw-auth-center">
				<p class="tw-brand"><?php echo esc_html( TW_BRAND ); ?></p>
				<span class="tw-brand-mark" aria-hidden="true"></span>
				<h1>Log in</h1>
				<p class="tw-lead">Back to your channel workspace.</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tw-form tw-form--auth">
					<?php wp_nonce_field( 'tw_front_login' ); ?>
					<input type="hidden" name="action" value="tw_front_login">
					<label for="tw-login-user">Email or username</label>
					<input type="text" id="tw-login-user" name="log" required autocomplete="username" placeholder="Email or username">
					<label for="tw-login-pass">Password</label>
					<input type="password" id="tw-login-pass" name="pwd" required autocomplete="current-password" placeholder="Your password">
					<button type="submit" class="tw-btn tw-btn--primary tw-btn--block tw-btn--pill">Log in</button>
				</form>
				<p class="tw-fine">No account yet? <a href="<?php echo esc_url( self::url( 'register' ) ); ?>">Create one</a></p>
			</section>
		</main>
		<?php
	}

	private function view_register() {
		?>
		<main id="primary" tabindex="-1" class="tw-shell tw-shell--auth">
			<section class="tw-auth-card tw-auth-center">
				<p class="tw-brand"><?php echo esc_html( TW_BRAND ); ?></p>
				<span class="tw-brand-mark" aria-hidden="true"></span>
				<h1>Create account</h1>
				<p class="tw-lead">Connect AI and YouTube, draft metadata, and publish only after review. Your keys stay encrypted on this site.</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tw-form tw-form--auth">
					<?php wp_nonce_field( 'tw_front_register' ); ?>
					<input type="hidden" name="action" value="tw_front_register">
					<label for="tw-reg-user">Username</label>
					<input type="text" id="tw-reg-user" name="user_login" required autocomplete="username" placeholder="Enter a username">
					<label for="tw-reg-email">Email</label>
					<input type="email" id="tw-reg-email" name="user_email" required autocomplete="email" placeholder="Enter your email">
					<label for="tw-reg-pass">Password (8+ chars)</label>
					<input type="password" id="tw-reg-pass" name="user_pass" required minlength="8" autocomplete="new-password" placeholder="Create a password">
					<button type="submit" class="tw-btn tw-btn--primary tw-btn--block tw-btn--pill">Create account</button>
				</form>
				<p class="tw-fine">Already have an account? <a href="<?php echo esc_url( self::url( 'login' ) ); ?>">Log in</a></p>
			</section>
		</main>
		<?php
	}

	private function view_account() {
		TW_Context::set_user( get_current_user_id() );
		TW_Index::maybe_install();
		$video   = isset( $_GET['video'] ) ? sanitize_text_field( wp_unslash( $_GET['video'] ) ) : ''; // phpcs:ignore
		$channel = TW_Store::get( 'channel', array() );
		$this->render_topbar( $video ? 'editor' : 'videos' );
		?>
		<main id="primary" tabindex="-1" class="tw-shell tw-shell--account">
			<?php
			if ( $video ) {
				$this->render_editor( $video );
			} else {
				$this->render_workspace( $channel );
			}
			?>
		</main>
		<?php
	}

	private function view_schedule() {
		TW_Context::set_user( get_current_user_id() );
		TW_Index::maybe_install();
		$this->render_topbar( 'schedule' );
		$connected = TW_OAuth::is_connected();
		$ready     = TW_Settings::user_has_ai_key() && $connected && TW_Settings::identity_complete();
		$indexed   = TW_Index::count_all();
		$scheduled = TW_Index::count_scheduled();
		$boot      = array(
			'today'     => wp_date( 'Y-m-d' ),
			'tz'        => wp_timezone_string(),
			'editBase'  => self::url( 'account' ),
			'scheduled' => $scheduled,
		);
		?>
		<main id="primary" tabindex="-1" class="tw-shell tw-shell--account tw-shell--schedule">
			<section class="tw-panel tw-workspace-head">
				<div class="tw-panel-head">
					<h1>Schedule</h1>
					<p>Browse scheduled and published uploads by day. Click a day to review its posts above the calendar, then open one to edit.</p>
				</div>
			</section>

			<?php if ( ! $ready ) : ?>
				<section class="tw-panel">
					<div class="tw-card-ui">
						<p>Finish setup on <a href="<?php echo esc_url( self::url( 'account' ) ); ?>#tw-keys">Keys &amp; voice</a> (channel identity, AI key, YouTube), then sync your videos before using the schedule calendar.</p>
					</div>
				</section>
			<?php elseif ( ! $indexed ) : ?>
				<section class="tw-panel">
					<div class="tw-card-ui">
						<p>No videos in the local index yet. Go to <a href="<?php echo esc_url( self::url( 'account' ) ); ?>#tw-videos">Videos</a> and run Full sync or Quick sync first.</p>
					</div>
				</section>
			<?php else : ?>
				<section class="tw-panel" id="tw-schedule" data-boot="<?php echo esc_attr( wp_json_encode( $boot ) ); ?>">
                    <div class="tw-help tw-schedule-intro">
                        <p><strong>Your publishing calendar, in focus.</strong> Select a date to see its videos and open one to edit.</p>
                        <div class="tw-schedule-legend" aria-label="Video status legend">
                            <span class="tw-schedule-legend-item tw-schedule-legend-scheduled">Scheduled</span>
                            <span class="tw-schedule-legend-item tw-schedule-legend-published">Published</span>
                            <span class="tw-schedule-timezone">Times in <?php echo esc_html( wp_timezone_string() ); ?></span>
                        </div>
                    </div>
					<aside class="tw-sched-day" id="tw-sched-day" aria-live="polite">
						<h2 id="tw-sched-day-heading">Pick a day</h2>
						<p class="tw-muted" id="tw-sched-day-empty">Click a day on the calendar to see its posts.</p>
						<div class="tw-sched-day-list" id="tw-sched-day-list" hidden></div>
					</aside>

					<div class="tw-cal tw-sched-cal uw-calendar">
						<div class="tw-cal-head">
							<button type="button" class="tw-btn tw-btn--small" id="tw-sched-prev" aria-label="Previous month">&larr;</button>
							<strong id="tw-sched-title"></strong>
							<button type="button" class="tw-btn tw-btn--small" id="tw-sched-next" aria-label="Next month">&rarr;</button>
							<button type="button" class="tw-btn tw-btn--ghost tw-btn--small" id="tw-sched-refresh">Refresh from YouTube</button>
						</div>
						<p class="tw-schedule-mobile-hint">Dates show video counts. Select a day for full titles and thumbnails above.</p>
						<div class="tw-cal-grid" id="tw-sched-grid" role="group" aria-label="Publishing calendar">
							<p class="tw-muted">Loading schedule…</p>
						</div>
						<?php if ( current_user_can( 'manage_options' ) ) : ?>
							<p class="tw-muted tw-quota">YouTube API today: about <?php echo esc_html( number_format_i18n( TW_YouTube::quota_used() ) ); ?> / 10,000 units</p>
						<?php endif; ?>
					</div>
				</section>
			<?php endif; ?>
		</main>
		<?php
	}

	/**
	 * Shared app chrome for logged-in views.
	 *
	 * @param string $active videos|schedule|editor|keys.
	 */
	private function render_topbar( $active = 'videos' ) {
        // Keep plugin navigation available when another theme is active.
        if ( ! function_exists( 'uw_brand' ) ) {
            echo '<a class="tw-brand tw-brand--link" href="' . esc_url( home_url( '/' ) ) . '">' . esc_html( TW_BRAND ) . '</a>';
        }
        ?>
        <div class="uw-workspace-nav">
            <nav class="uw-workspace-tabs" aria-label="Workspace navigation">
                <a data-uw-workspace-tab="videos" class="<?php echo in_array( $active, array( 'videos', 'editor' ), true ) ? 'is-active' : ''; ?>" <?php echo in_array( $active, array( 'videos', 'editor' ), true ) ? 'aria-current="page"' : ''; ?> href="<?php echo esc_url( self::url( 'account' ) ); ?>#tw-videos"><span aria-hidden="true">▤</span> Videos</a>
                <a data-uw-workspace-tab="schedule" class="<?php echo 'schedule' === $active ? 'is-active' : ''; ?>" <?php echo 'schedule' === $active ? 'aria-current="page"' : ''; ?> href="<?php echo esc_url( self::url( 'schedule' ) ); ?>"><span aria-hidden="true">▦</span> Schedule</a>
                <a data-uw-workspace-tab="keys" href="<?php echo esc_url( self::url( 'account' ) ); ?>#tw-keys" data-tw-open-keys><span aria-hidden="true">⚙</span> Keys &amp; voice</a>
            </nav>
            <span class="uw-workspace-label">YOUR CHANNEL, IN FOCUS <span aria-hidden="true">✦</span></span>
        </div>
        <?php
    }
	private function render_workspace( $channel ) {
		$has_gemini = TW_Store::has( 'gemini_key' );
		$has_openai = TW_Store::has( 'openai_key' );
		$connected  = TW_OAuth::is_connected();
		$site_ok    = TW_Settings::get( 'client_id' ) && TW_Settings::get( 'client_secret' );
		$voice_ok   = TW_Settings::identity_complete();
		$ready      = TW_Settings::user_has_ai_key() && $connected && $voice_ok;
		$setup_open = ! $ready;
		$channel_label = ( is_array( $channel ) && ! empty( $channel['title'] ) ) ? (string) $channel['title'] : '';
		$id = TW_Settings::identity();
		?>
		<section class="tw-panel tw-workspace-head">
			<div class="tw-panel-head">
				<p class="uw-workspace-eyebrow">YOUR CREATIVE CONTROL ROOM</p><h1>Your video workspace.</h1>
				<?php if ( $ready ) : ?>
					<p>A clearer view of your channel. Find a video, polish the details, and make your next move.</p>
				<?php elseif ( ! $voice_ok ) : ?>
					<p>First, tell UploadWriter who this channel is. Then save an AI key and connect YouTube.</p>
				<?php else : ?>
					<p>Save an AI key, connect YouTube, then sync your videos and compare AI drafts in one place.</p>
				<?php endif; ?>
			</div>
		</section>

        <div class="uw-workspace-stats" aria-label="Channel overview">
            <div class="uw-stat"><span>VIDEOS IN YOUR LIBRARY</span><strong><?php echo esc_html( number_format_i18n( TW_Index::count_all() ) ); ?></strong></div>
            <div class="uw-stat"><span>ON THE SCHEDULE</span><strong><?php echo esc_html( number_format_i18n( TW_Index::count_scheduled() ) ); ?></strong></div>
            <div class="uw-stat"><span>CHANNEL CONNECTION</span><strong><?php echo $connected ? 'Connected' : 'Let’s get set up'; ?></strong></div>
        </div>
		<details class="tw-setup tw-panel" id="tw-keys"<?php echo $setup_open ? ' open' : ''; ?>>
			<summary class="tw-setup-summary">
				<span class="tw-setup-title"><?php echo $ready ? 'Keys &amp; voice' : 'Set up your workspace'; ?></span>
				<span class="tw-setup-meta">
					<?php if ( $ready ) : ?>
						<?php echo $channel_label ? esc_html( $channel_label ) . ' · ' : ''; ?>Connected. Open to change voice, keys, or reconnect.
					<?php elseif ( ! $voice_ok ) : ?>
						Channel identity required before Generate will run.
					<?php else : ?>
						Save an AI key and connect YouTube to start.
					<?php endif; ?>
				</span>
			</summary>

			<?php $this->render_identity_form( $id, $voice_ok ); ?>

			<div class="tw-grid-2">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tw-card-ui tw-form">
					<?php wp_nonce_field( 'tw_front_save_keys' ); ?>
					<input type="hidden" name="action" value="tw_front_save_keys">
					<h2>AI keys</h2>
					<p class="tw-muted">Keys are encrypted at rest. Leave a field blank to keep the saved value.</p>
					<label for="tw_gemini_key">Gemini API key <?php echo $has_gemini ? '<span class="tw-chip tw-chip--ok">Saved</span>' : ''; ?></label>
					<div class="tw-secret">
						<input type="password" id="tw_gemini_key" name="tw_gemini_key" autocomplete="off" placeholder="<?php echo $has_gemini ? 'Saved. Leave blank to keep it.' : 'Paste Gemini key'; ?>">
						<button type="button" class="tw-secret-toggle" data-toggle-secret="tw_gemini_key" data-secret-name="Gemini API key" aria-label="Show Gemini API key">
							<span class="tw-icon tw-icon--eye" aria-hidden="true"></span>
						</button>
					</div>
					<label for="tw_openai_key">OpenAI / ChatGPT API key <?php echo $has_openai ? '<span class="tw-chip tw-chip--ok">Saved</span>' : ''; ?></label>
					<div class="tw-secret">
						<input type="password" id="tw_openai_key" name="tw_openai_key" autocomplete="off" placeholder="<?php echo $has_openai ? 'Saved. Leave blank to keep it.' : 'Paste OpenAI key'; ?>">
						<button type="button" class="tw-secret-toggle" data-toggle-secret="tw_openai_key" data-secret-name="OpenAI API key" aria-label="Show OpenAI API key">
							<span class="tw-icon tw-icon--eye" aria-hidden="true"></span>
						</button>
					</div>
					<label for="tw_image_provider">Thumbnail image provider (fallback)</label>
					<select id="tw_image_provider" name="tw_image_provider">
						<?php foreach ( TW_Settings::IMAGE_PROVIDERS as $id_key => $label ) : ?>
							<option value="<?php echo esc_attr( $id_key ); ?>" <?php selected( TW_Settings::get( 'image_provider' ), $id_key ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="tw-muted">AI thumbnail create is off for regular users until a site admin enables it site-wide (UploadWriter → Settings) or on that user’s profile (Users → Edit → AI thumbnails). Admins always have it. The Gemini / ChatGPT picker next to Generate chooses the image family; this setting is the fallback.</p>
					<button type="submit" class="tw-btn tw-btn--primary">Save keys</button>
				</form>

				<div class="tw-card-ui">
					<h2>YouTube</h2>
					<?php if ( ! $site_ok ) : ?>
						<p class="tw-warn">This site still needs a Google OAuth client in the WordPress admin UploadWriter settings. Ask the site owner to finish that once.</p>
					<?php elseif ( $connected ) : ?>
						<p class="tw-ok-line"><span class="tw-ok-check" aria-hidden="true"></span> Connected<?php echo $channel_label ? ': <strong>' . esc_html( $channel_label ) . '</strong>' : ''; ?></p>
						<?php if ( TW_OAuth::needs_reconnect() ) : ?>
							<p class="tw-warn">Please reconnect YouTube. <?php echo esc_html( TW_BRAND ); ?> needs you to approve access again (one time after the Google OAuth client update).</p>
						<?php endif; ?>
						<div class="tw-cta-row">
							<a class="tw-btn" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=tw_front_connect' ), 'tw_front_connect' ) ); ?>">Reconnect</a>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tw-inline" onsubmit="return confirm('Disconnect YouTube from this account?');">
								<?php wp_nonce_field( 'tw_front_disconnect' ); ?>
								<input type="hidden" name="action" value="tw_front_disconnect">
								<button type="submit" class="tw-btn tw-btn--danger">Disconnect</button>
							</form>
						</div>
						<p class="tw-connect-legal">By connecting, you agree to the <a href="https://www.youtube.com/t/terms" target="_blank" rel="noopener noreferrer">YouTube Terms of Service</a> and <a href="https://policies.google.com/privacy" target="_blank" rel="noopener noreferrer">Google Privacy Policy</a>.</p>
					<?php else : ?>
						<?php if ( TW_OAuth::needs_reconnect() ) : ?>
							<p class="tw-warn">Please reconnect YouTube. Your previous connection is no longer valid.</p>
						<?php else : ?>
							<p class="tw-muted">Connect the Google account that owns your channel. <?php echo esc_html( TW_BRAND ); ?> only asks for YouTube manage access.</p>
						<?php endif; ?>
						<a class="tw-btn tw-btn--primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=tw_front_connect' ), 'tw_front_connect' ) ); ?>">Connect YouTube</a>
						<p class="tw-connect-legal">By connecting, you agree to the <a href="https://www.youtube.com/t/terms" target="_blank" rel="noopener noreferrer">YouTube Terms of Service</a> and <a href="https://policies.google.com/privacy" target="_blank" rel="noopener noreferrer">Google Privacy Policy</a>.</p>
					<?php endif; ?>
					<?php if ( current_user_can( 'manage_options' ) ) : ?>
						<p class="tw-quota-line">YouTube API today: about <?php echo esc_html( number_format_i18n( TW_YouTube::quota_used() ) ); ?> / 10,000 units</p>
					<?php endif; ?>
				</div>
			</div>
		</details>

		<section class="tw-panel" id="tw-videos">
			<div class="tw-panel-head">
				<h2>Your videos</h2>
				<p>The list lives on this site so search and filters cost zero YouTube units.</p>
			</div>
			<?php
			if ( ! $ready ) {
				echo '<div class="tw-card-ui">';
				if ( ! $voice_ok ) {
					echo '<h3>Set up your channel voice first.</h3><p>Fill in Channel identity &amp; voice above, then add an AI key and connect YouTube.</p>';
				} else {
					echo '<h3>Your videos will feel right at home here.</h3><p>Add your AI key and connect YouTube above. Then sync your channel to start shaping your next upload.</p>';
				}
				echo '</div></section>';
				return;
			}
			$this->render_video_list();
			?>
		</section>
		<?php
	}

	/**
	 * Onboarding + dial-in form for channel identity and the three prompts.
	 *
	 * @param array $id       Current identity values.
	 * @param bool  $voice_ok Whether required identity is already saved.
	 */
	private function render_identity_form( array $id, $voice_ok ) {
		$fields = array(
			'channel_name'       => array( 'Channel name', 'text' ),
			'channel_short'      => array( 'Short name / initials', 'text' ),
			'creator_name'       => array( 'Creator first name (first-person voice)', 'text' ),
			'brand_tag'          => array( 'Brand tag (last YouTube tag)', 'text' ),
			'brand_hashtag'      => array( 'Brand hashtag', 'text' ),
			'warning_title'      => array( 'Description warning title', 'text' ),
			'warning_close'      => array( 'Warning closing line (exact)', 'text' ),
			'chapters_heading'   => array( 'Chapters heading', 'text' ),
			'channel_logo_brief' => array( 'Channel logo description (for thumbnails)', 'textarea' ),
		);
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tw-card-ui tw-form tw-identity" id="tw-voice">
			<?php wp_nonce_field( 'tw_front_save_identity' ); ?>
			<input type="hidden" name="action" value="tw_front_save_identity">
			<h2>Channel identity &amp; voice <?php echo $voice_ok ? '<span class="tw-chip tw-chip--ok">Saved</span>' : '<span class="tw-chip">Required</span>'; ?></h2>
			<?php if ( ! $voice_ok ) : ?>
				<p class="tw-warn">Tell UploadWriter who this channel is before Generate can run. Example values below are from Gregatron Games so you can see the shape; replace them with your channel.</p>
			<?php else : ?>
				<p class="tw-muted">These fields drive titles, tags, the description warning, chapters heading, and thumbnail channel-logo language. <code>{{channel_name}}</code> and other tokens in the prompts below pull from here.</p>
			<?php endif; ?>

			<div class="tw-identity-grid">
				<?php foreach ( $fields as $key => $meta ) : ?>
					<?php
					list( $label, $type ) = $meta;
					$example = TW_Settings::identity_example( $key );
					$input_id = 'tw_' . $key;
					$value = (string) ( $id[ $key ] ?? '' );
					?>
					<div class="tw-identity-field<?php echo 'textarea' === $type ? ' tw-identity-field--wide' : ''; ?>">
						<label for="<?php echo esc_attr( $input_id ); ?>"><?php echo esc_html( $label ); ?></label>
						<?php if ( 'textarea' === $type ) : ?>
							<textarea id="<?php echo esc_attr( $input_id ); ?>" name="<?php echo esc_attr( $input_id ); ?>" rows="3" placeholder="<?php echo esc_attr( $example ); ?>"><?php echo esc_textarea( $value ); ?></textarea>
						<?php else : ?>
							<input type="text" id="<?php echo esc_attr( $input_id ); ?>" name="<?php echo esc_attr( $input_id ); ?>" value="<?php echo esc_attr( $value ); ?>" placeholder="<?php echo esc_attr( $example ); ?>">
						<?php endif; ?>
						<?php if ( '' !== $example ) : ?>
							<p class="tw-field-example">Example: <?php echo esc_html( $example ); ?></p>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>

			<details class="tw-prompt-dial"<?php echo $voice_ok ? '' : ' open'; ?>>
				<summary>Dial in prompts (advanced)</summary>
				<p class="tw-muted">Same control as the old Settings prompts. Tokens like <code>{{channel_name}}</code>, <code>{{warning_title}}</code>, and <code>{{channel_logo_brief}}</code> expand from the identity fields above when Generate runs.</p>

				<label for="tw_prompt">Channel voice prompt</label>
				<textarea id="tw_prompt" name="tw_prompt" rows="16" class="tw-prompt-box code"><?php echo esc_textarea( TW_Settings::get( 'prompt' ) ); ?></textarea>

				<label for="tw_thumb_prompt">Thumbnail brief prompt</label>
				<textarea id="tw_thumb_prompt" name="tw_thumb_prompt" rows="12" class="tw-prompt-box code"><?php echo esc_textarea( TW_Settings::get( 'thumb_prompt' ) ); ?></textarea>

				<label for="tw_thumb_render_prompt">Thumbnail render instructions</label>
				<textarea id="tw_thumb_render_prompt" name="tw_thumb_render_prompt" rows="12" class="tw-prompt-box code"><?php echo esc_textarea( TW_Settings::get( 'thumb_render_prompt' ) ); ?></textarea>
			</details>

			<button type="submit" class="tw-btn tw-btn--primary"><?php echo $voice_ok ? 'Save channel voice' : 'Save and continue'; ?></button>
		</form>
		<?php if ( $voice_ok ) : ?>
			<div class="tw-identity-resets">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tw-inline" onsubmit="return confirm('Replace your voice prompt with the packaged template?');">
					<?php wp_nonce_field( 'tw_front_reset_prompt' ); ?>
					<input type="hidden" name="action" value="tw_front_reset_prompt">
					<button type="submit" class="tw-btn tw-btn--link">Reset voice prompt</button>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tw-inline" onsubmit="return confirm('Replace your thumbnail brief prompt with the packaged template?');">
					<?php wp_nonce_field( 'tw_front_reset_thumb_prompt' ); ?>
					<input type="hidden" name="action" value="tw_front_reset_thumb_prompt">
					<button type="submit" class="tw-btn tw-btn--link">Reset thumbnail brief</button>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tw-inline" onsubmit="return confirm('Replace your thumbnail render instructions with the packaged template?');">
					<?php wp_nonce_field( 'tw_front_reset_thumb_render_prompt' ); ?>
					<input type="hidden" name="action" value="tw_front_reset_thumb_render_prompt">
					<button type="submit" class="tw-btn tw-btn--link">Reset thumbnail render</button>
				</form>
			</div>
		<?php endif; ?>
		<?php
	}

	private function render_video_list() {
		$indexed    = TW_Index::count_all();
		$synced     = (int) TW_Store::get( 'index_synced', 0 );
		$full       = (int) TW_Store::get( 'index_full', 0 );
		$sched_note = (string) TW_Store::get( 'scheduled_sync_note', '' );
		$est        = max( 2, (int) ceil( max( $indexed, 50 ) / 50 ) * 2 ) + 16;
		$visibility = isset( $_GET['visibility'] ) ? sanitize_text_field( wp_unslash( $_GET['visibility'] ) ) : ''; // phpcs:ignore
		$search     = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore
		$paged      = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 ); // phpcs:ignore
		$sortables  = array( 'title' => 'Title', 'date' => 'Date', 'views' => 'Views', 'length' => 'Length' );
		$orderby    = isset( $_GET['orderby'] ) ? sanitize_text_field( wp_unslash( $_GET['orderby'] ) ) : 'date'; // phpcs:ignore
		if ( ! isset( $sortables[ $orderby ] ) ) {
			$orderby = 'date';
		}
		$order = isset( $_GET['order'] ) ? strtolower( sanitize_text_field( wp_unslash( $_GET['order'] ) ) ) : ''; // phpcs:ignore
		if ( ! in_array( $order, array( 'asc', 'desc' ), true ) ) {
			$order = ( 'title' === $orderby ) ? 'asc' : 'desc';
		}
		$result = TW_Index::query(
			array(
				'search'     => $search,
				'visibility' => $visibility,
				'orderby'    => $orderby,
				'order'      => $order,
				'per_page'   => 48,
				'paged'      => $paged,
			)
		);
		$counts = TW_Index::counts();
		$total  = (int) $result['total'];
		$pages  = (int) ceil( $total / max( 1, $result['per_page'] ) );
		$paged  = min( $paged, max( 1, $pages ) );
		$list   = array(
			'visibility' => $visibility,
			'search'     => $search,
			'orderby'    => $orderby,
			'order'      => $order,
		);
		$pager  = array_merge(
			$list,
			array(
				'total' => $total,
				'pages' => $pages,
				'paged' => $paged,
			)
		);
		?>
		<div class="tw-sync tw-card-ui" id="tw-sync" data-empty="<?php echo $indexed ? '0' : '1'; ?>">
			<div class="tw-sync-readout">
				<span class="spinner tw-sync-spinner" id="tw-sync-spinner" aria-hidden="true"></span>
				<span class="tw-sync-status" id="tw-sync-status" role="status" aria-live="polite">
					<?php if ( $indexed ) : ?>
						<?php echo esc_html( number_format_i18n( $indexed ) ); ?> videos indexed.
						Last synced <?php echo esc_html( human_time_diff( $synced ) ); ?> ago<?php echo $full ? ', last full sync ' . esc_html( human_time_diff( $full ) ) . ' ago' : ''; ?>.
					<?php else : ?>
						Build your local list once with Full sync.
					<?php endif; ?>
				</span>
				<div class="tw-sync-progress" id="tw-sync-progress" hidden>
					<div class="tw-sync-progress-bar" id="tw-sync-progress-bar" style="width:0%"></div>
				</div>
			</div>
			<div class="tw-cta-row">
				<button type="button" class="tw-btn" data-sync="quick">Quick sync</button>
				<button type="button" class="tw-btn" data-sync="full">Full sync (~<?php echo esc_html( number_format_i18n( $est ) ); ?> units)</button>
				<button type="button" class="tw-btn tw-btn--primary" data-sync="drafts">Compare drafts</button>
			</div>
			<?php if ( current_user_can( 'manage_options' ) ) : ?>
				<span class="tw-muted tw-quota">YouTube API today: about <?php echo esc_html( number_format_i18n( TW_YouTube::quota_used() ) ); ?> / 10,000 units</span>
			<?php endif; ?>
			<?php if ( $sched_note ) : ?>
				<p class="tw-warn"><?php echo esc_html( $sched_note ); ?></p>
			<?php endif; ?>
		</div>

		<form method="get" action="<?php echo esc_url( self::url( 'account' ) ); ?>" class="tw-filters">
			<?php if ( $visibility ) : ?>
				<input type="hidden" name="visibility" value="<?php echo esc_attr( $visibility ); ?>">
			<?php endif; ?>
			<input type="hidden" name="orderby" value="<?php echo esc_attr( $orderby ); ?>">
			<input type="hidden" name="order" value="<?php echo esc_attr( $order ); ?>">
			<label class="tw-search">
				<span class="tw-icon tw-icon--search" aria-hidden="true"></span>
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Search title or description" aria-label="Search title or description">
			</label>
			<button class="tw-btn tw-btn--primary" type="submit">Search</button>
		</form>

		<nav class="tw-tabs" aria-label="Filter videos by visibility">
			<?php
			$tabs = array( '' => 'All', 'public' => 'Public', 'unlisted' => 'Unlisted', 'private' => 'Private', 'scheduled' => 'Scheduled', 'draft' => 'Draft' );
			foreach ( $tabs as $key => $label ) {
				$count = $counts[ '' === $key ? 'all' : $key ] ?? 0;
				$url   = $this->list_url(
					array(
						'visibility' => $key,
						's'          => $search,
						'orderby'    => $orderby,
						'order'      => $order,
					)
				);
				$cls = ( (string) $visibility === (string) $key ) ? ' is-active' : '';
				printf(
					'<a class="tw-tab%s" href="%s"%s>%s <span>(%s)</span></a>',
					esc_attr( $cls ),
					esc_url( $url ),
					$cls ? ' aria-current="page"' : '',
					esc_html( $label ),
					esc_html( number_format_i18n( $count ) )
				);
			}
			?>
		</nav>

		<div class="tw-sortbar" aria-label="Sort videos">
			<span class="tw-sortbar-label">Sort</span>
			<?php foreach ( $sortables as $key => $label ) : ?>
				<?php
				$active = ( $orderby === $key );
				$next   = $active ? ( 'asc' === $order ? 'desc' : 'asc' ) : ( ( 'title' === $key ) ? 'asc' : 'desc' );
				$url    = $this->list_url(
					array(
						'visibility' => $visibility,
						's'          => $search,
						'orderby'    => $key,
						'order'      => $next,
					)
				);
				$arrow = '';
				if ( $active ) {
					$arrow = 'asc' === $order ? ' ↑' : ' ↓';
				}
				printf(
					'<a class="tw-sort%s" href="%s">%s%s</a>',
					$active ? ' is-active' : '',
					esc_url( $url ),
					esc_html( $label ),
					esc_html( $arrow )
				);
				?>
			<?php endforeach; ?>
		</div>

		<?php $this->render_pager( $pager, 'top' ); ?>

		<div class="tw-video-grid">
			<?php if ( empty( $result['items'] ) ) : ?>
				<p class="tw-muted">No videos in this view yet.</p>
			<?php else : ?>
				<?php foreach ( $result['items'] as $item ) : ?>
					<?php
					$edit = add_query_arg( 'video', $item['id'], self::url( 'account' ) );
					$priv = $item['privacy'];
					$meta = number_format_i18n( (int) $item['views'] ) . ' views';
					if ( 'private' === $priv && ! empty( $item['publish_at'] ) && strtotime( $item['publish_at'] . ' UTC' ) > time() ) {
						$priv = 'scheduled';
						$meta .= ' · Publishes ' . wp_date( 'M j, Y \a\t g:i A', strtotime( $item['publish_at'] . ' UTC' ) );
					}
					$badge = ucfirst( $priv );
					?>
					<a class="tw-video-card" href="<?php echo esc_url( $edit ); ?>">
						<span class="tw-video-thumb">
							<?php if ( ! empty( $item['thumb'] ) ) : ?>
								<img src="<?php echo esc_url( TW_Index::thumb_url_with_version( $item['id'], $item['thumb'] ) ); ?>" alt="">
							<?php endif; ?>
							<?php if ( ! empty( $item['duration'] ) ) : ?>
								<span class="tw-video-dur"><?php echo esc_html( TW_Transcript::fmt( (int) $item['duration'] ) ); ?></span>
							<?php endif; ?>
						</span>
						<span class="tw-video-body">
							<span class="tw-badge tw-badge-<?php echo esc_attr( $priv ); ?>"><?php echo esc_html( $badge ); ?></span>
							<span class="tw-video-title"><?php echo esc_html( $item['title'] ); ?></span>
							<span class="tw-muted tw-video-meta"><?php echo esc_html( $meta ); ?></span>
							<span class="tw-btn tw-btn--small tw-btn--primary tw-btn--card"><span class="tw-icon tw-icon--edit" aria-hidden="true"></span> Edit</span>
						</span>
					</a>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
		<?php
		$this->render_pager( $pager, 'bottom' );
	}

	/**
	 * Account list URL with visibility / search / sort / page.
	 *
	 * @param array $args visibility, s, orderby, order, paged.
	 */
	private function list_url( $args ) {
		$q = array();
		if ( ! empty( $args['visibility'] ) ) {
			$q['visibility'] = $args['visibility'];
		}
		if ( ! empty( $args['s'] ) ) {
			$q['s'] = $args['s'];
		}
		$orderby = $args['orderby'] ?? 'date';
		$order   = $args['order'] ?? 'desc';
		if ( 'date' !== $orderby || 'desc' !== $order ) {
			$q['orderby'] = $orderby;
			$q['order']   = $order;
		}
		if ( ! empty( $args['paged'] ) && (int) $args['paged'] > 1 ) {
			$q['paged'] = (int) $args['paged'];
		}
		// No hash here. Same #fragment + new query is treated as same-document in some
		// browsers and the Draft/Public tabs never reload. front.js scrolls to #tw-videos.
		return add_query_arg( $q, self::url( 'account' ) );
	}

	/**
	 * Admin-style pager: first / prev / page box / next / last.
	 *
	 * @param array  $pager total, pages, paged, visibility, search, orderby, order.
	 * @param string $which top|bottom for unique input ids.
	 */
	private function render_pager( $pager, $which ) {
		$pages = (int) $pager['pages'];
		if ( $pages < 2 ) {
			return;
		}
		$total      = (int) $pager['total'];
		$paged      = (int) $pager['paged'];
		$visibility = (string) ( $pager['visibility'] ?? '' );
		$search     = (string) ( $pager['search'] ?? '' );
		$orderby    = (string) ( $pager['orderby'] ?? 'date' );
		$order      = (string) ( $pager['order'] ?? 'desc' );
		$input_id   = 'tw-paged-' . $which;
		$page_url   = function ( $n ) use ( $visibility, $search, $orderby, $order ) {
			return $this->list_url(
				array(
					'visibility' => $visibility,
					's'          => $search,
					'orderby'    => $orderby,
					'order'      => $order,
					'paged'      => $n,
				)
			);
		};
		?>
		<nav class="tw-pager-front tw-pager-front--<?php echo esc_attr( $which ); ?>" aria-label="<?php echo 'top' === $which ? 'Pagination top' : 'Pagination bottom'; ?>">
			<span class="tw-pager-num"><?php echo esc_html( sprintf( _n( '%s video', '%s videos', $total ), number_format_i18n( $total ) ) ); ?></span>
			<span class="tw-pager-links">
				<?php if ( 1 === $paged ) : ?>
					<span class="tw-page tw-page--nav is-disabled" aria-hidden="true">&laquo;</span>
					<span class="tw-page tw-page--nav is-disabled" aria-hidden="true">&lsaquo;</span>
				<?php else : ?>
					<a class="tw-page tw-page--nav" href="<?php echo esc_url( $page_url( 1 ) ); ?>" aria-label="First page">&laquo;</a>
					<a class="tw-page tw-page--nav" href="<?php echo esc_url( $page_url( $paged - 1 ) ); ?>" aria-label="Previous page">&lsaquo;</a>
				<?php endif; ?>

				<form method="get" action="<?php echo esc_url( self::url( 'account' ) ); ?>" class="tw-pager-goto">
					<?php if ( $visibility ) : ?>
						<input type="hidden" name="visibility" value="<?php echo esc_attr( $visibility ); ?>">
					<?php endif; ?>
					<?php if ( $search ) : ?>
						<input type="hidden" name="s" value="<?php echo esc_attr( $search ); ?>">
					<?php endif; ?>
					<?php if ( 'date' !== $orderby || 'desc' !== $order ) : ?>
						<input type="hidden" name="orderby" value="<?php echo esc_attr( $orderby ); ?>">
						<input type="hidden" name="order" value="<?php echo esc_attr( $order ); ?>">
					<?php endif; ?>
					<label class="screen-reader-text" for="<?php echo esc_attr( $input_id ); ?>">Current page</label>
					<input
						id="<?php echo esc_attr( $input_id ); ?>"
						class="tw-pager-input"
						type="number"
						name="paged"
						value="<?php echo (int) $paged; ?>"
						min="1"
						max="<?php echo (int) $pages; ?>"
						size="<?php echo (int) strlen( (string) $pages ); ?>"
					>
					<span class="tw-pager-of">of <?php echo esc_html( number_format_i18n( $pages ) ); ?></span>
				</form>

				<?php if ( $paged >= $pages ) : ?>
					<span class="tw-page tw-page--nav is-disabled" aria-hidden="true">&rsaquo;</span>
					<span class="tw-page tw-page--nav is-disabled" aria-hidden="true">&raquo;</span>
				<?php else : ?>
					<a class="tw-page tw-page--nav" href="<?php echo esc_url( $page_url( $paged + 1 ) ); ?>" aria-label="Next page">&rsaquo;</a>
					<a class="tw-page tw-page--nav" href="<?php echo esc_url( $page_url( $pages ) ); ?>" aria-label="Last page">&raquo;</a>
				<?php endif; ?>
			</span>
		</nav>
		<?php
	}

	private function render_editor( $video_id ) {
		if ( ! class_exists( 'TW_Admin' ) ) {
			require_once TW_DIR . 'includes/class-tw-admin.php';
		}
		// Avoid registering admin menus twice when Admin was already constructed for AJAX bootstrap.
		$admin = new TW_Admin();
		echo '<div class="tw-editor-shell">';
		$admin->page_editor( $video_id, self::url( 'account' ) . '#tw-videos' );
		echo '</div>';
	}

	public function handle_login() {
		check_admin_referer( 'tw_front_login' );
		$creds = array(
			'user_login'    => sanitize_text_field( wp_unslash( $_POST['log'] ?? '' ) ),
			'user_password' => (string) wp_unslash( $_POST['pwd'] ?? '' ), // phpcs:ignore
			'remember'      => true,
		);
		$user = wp_signon( $creds, is_ssl() );
		if ( is_wp_error( $user ) ) {
			$this->flash( 'Could not log in. Check your email/username and password.', 'error' );
			wp_safe_redirect( self::url( 'login' ) );
			exit;
		}
		if ( ! user_can( $user, 'use_uploadwriter' ) && ! user_can( $user, 'manage_options' ) ) {
			wp_logout();
			$this->flash( 'That account cannot use UploadWriter.', 'error' );
			wp_safe_redirect( self::url( 'login' ) );
			exit;
		}
		wp_safe_redirect( self::url( 'account' ) );
		exit;
	}

	public function handle_register() {
		check_admin_referer( 'tw_front_register' );
		$login = sanitize_user( wp_unslash( $_POST['user_login'] ?? '' ), true );
		$email = sanitize_email( wp_unslash( $_POST['user_email'] ?? '' ) );
		$pass  = (string) wp_unslash( $_POST['user_pass'] ?? '' ); // phpcs:ignore

		if ( ! $login || ! is_email( $email ) || strlen( $pass ) < 8 ) {
			$this->flash( 'Use a valid username, email, and a password of at least 8 characters.', 'error' );
			wp_safe_redirect( self::url( 'register' ) );
			exit;
		}
		if ( username_exists( $login ) || email_exists( $email ) ) {
			$this->flash( 'That username or email is already taken.', 'error' );
			wp_safe_redirect( self::url( 'register' ) );
			exit;
		}

		$user_id = wp_create_user( $login, $pass, $email );
		if ( is_wp_error( $user_id ) ) {
			$this->flash( $user_id->get_error_message(), 'error' );
			wp_safe_redirect( self::url( 'register' ) );
			exit;
		}

		$user = new WP_User( $user_id );
		$user->set_role( 'tubewriter' );
		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, true, is_ssl() );
		$this->flash( 'Welcome. Add your AI key and connect YouTube to get started.', 'success' );
		wp_safe_redirect( self::url( 'account' ) );
		exit;
	}

	public function handle_logout() {
		check_admin_referer( 'tw_front_logout' );
		wp_logout();
		wp_safe_redirect( self::url( 'login' ) );
		exit;
	}

	public function handle_save_keys() {
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( self::url( 'login' ) );
			exit;
		}
		check_admin_referer( 'tw_front_save_keys' );
		TW_Context::set_user( get_current_user_id() );

		$gemini = sanitize_text_field( wp_unslash( $_POST['tw_gemini_key'] ?? '' ) );
		if ( '' !== $gemini ) {
			TW_Settings::set( 'gemini_key', $gemini );
		}
		$openai = sanitize_text_field( wp_unslash( $_POST['tw_openai_key'] ?? '' ) );
		if ( '' !== $openai ) {
			TW_Settings::set( 'openai_key', $openai );
		}
		$provider = sanitize_text_field( wp_unslash( $_POST['tw_image_provider'] ?? '' ) );
		TW_Settings::set( 'image_provider', isset( TW_Settings::IMAGE_PROVIDERS[ $provider ] ) ? $provider : TW_DEFAULT_IMAGE_PROVIDER );

		$this->flash( 'Keys saved.', 'success' );
		wp_safe_redirect( self::url( 'account' ) . '#tw-keys' );
		exit;
	}

	public function handle_save_identity() {
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( self::url( 'login' ) );
			exit;
		}
		check_admin_referer( 'tw_front_save_identity' );
		TW_Context::set_user( get_current_user_id() );

		$ok = TW_Settings::save_identity_from_post( $_POST, true ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( $ok ) {
			$this->flash( 'Channel voice saved. Generate will use these identity fields and prompts.', 'success' );
		} else {
			$this->flash( 'Saved what you entered, but required identity fields are still missing.', 'error' );
		}
		wp_safe_redirect( self::url( 'account' ) . '#tw-voice' );
		exit;
	}

	public function handle_reset_prompt() {
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( self::url( 'login' ) );
			exit;
		}
		check_admin_referer( 'tw_front_reset_prompt' );
		TW_Context::set_user( get_current_user_id() );
		TW_Settings::set( 'prompt', TW_Settings::default_prompt() );
		$this->flash( 'Voice prompt reset to the packaged template.', 'success' );
		wp_safe_redirect( self::url( 'account' ) . '#tw-voice' );
		exit;
	}

	public function handle_reset_thumb_prompt() {
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( self::url( 'login' ) );
			exit;
		}
		check_admin_referer( 'tw_front_reset_thumb_prompt' );
		TW_Context::set_user( get_current_user_id() );
		TW_Settings::set( 'thumb_prompt', TW_Settings::default_thumb_prompt() );
		$this->flash( 'Thumbnail brief prompt reset to the packaged template.', 'success' );
		wp_safe_redirect( self::url( 'account' ) . '#tw-voice' );
		exit;
	}

	public function handle_reset_thumb_render_prompt() {
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( self::url( 'login' ) );
			exit;
		}
		check_admin_referer( 'tw_front_reset_thumb_render_prompt' );
		TW_Context::set_user( get_current_user_id() );
		TW_Settings::set( 'thumb_render_prompt', TW_Settings::default_thumb_render_prompt() );
		$this->flash( 'Thumbnail render instructions reset to the packaged template.', 'success' );
		wp_safe_redirect( self::url( 'account' ) . '#tw-voice' );
		exit;
	}

	public function handle_connect() {
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( self::url( 'login' ) );
			exit;
		}
		check_admin_referer( 'tw_front_connect' );
		TW_Context::set_user( get_current_user_id() );
		if ( ! TW_Settings::get( 'client_id' ) || ! TW_Settings::get( 'client_secret' ) ) {
			$this->flash( 'Site Google OAuth is not configured yet.', 'error' );
			wp_safe_redirect( self::url( 'account' ) );
			exit;
		}
		// Google is off-site. wp_safe_redirect would bounce to wp-admin.
		wp_redirect( TW_OAuth::auth_url() ); // phpcs:ignore WordPress.Security.SafeRedirect
		exit;
	}

	public function handle_disconnect() {
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( self::url( 'login' ) );
			exit;
		}
		check_admin_referer( 'tw_front_disconnect' );
		TW_Context::set_user( get_current_user_id() );
		TW_OAuth::disconnect();
		$this->flash( 'YouTube disconnected.', 'success' );
		wp_safe_redirect( self::url( 'account' ) );
		exit;
	}

	private function oauth_callback() {
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( self::url( 'login' ) );
			exit;
		}
		$code  = isset( $_GET['code'] ) ? wp_unslash( $_GET['code'] ) : ''; // phpcs:ignore
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : ''; // phpcs:ignore
		$error = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : ''; // phpcs:ignore

		$done_url = self::url( 'account' );
		if ( $error ) {
			$this->flash( 'Google returned an error: ' . $error, 'error' );
			wp_safe_redirect( $done_url );
			exit;
		}

		$result = TW_OAuth::complete_redirect( $code, $state );
		// complete_redirect sets TW_Context from the verified state (user or site/admin).
		$is_admin_connect = ! TW_Context::is_user() && current_user_can( 'manage_options' );
		if ( $is_admin_connect ) {
			$done_url = admin_url( 'admin.php?page=tubewriter-settings' );
		}

		if ( is_wp_error( $result ) ) {
			if ( $is_admin_connect ) {
				set_transient( 'tw_notice_' . get_current_user_id(), array( 'message' => $result->get_error_message(), 'type' => 'error' ), 60 );
			} else {
				$this->flash( $result->get_error_message(), 'error' );
				$done_url = self::url( 'account' );
			}
			wp_safe_redirect( $done_url );
			exit;
		}

		$channel = TW_YouTube::channel();
		if ( is_wp_error( $channel ) ) {
			$msg  = 'Connected, but could not load the channel: ' . $channel->get_error_message();
			$type = 'error';
		} else {
			$msg  = 'YouTube connected' . ( ! empty( $channel['title'] ) ? ': ' . $channel['title'] : '' ) . '.';
			$type = 'success';
		}

		if ( $is_admin_connect ) {
			set_transient( 'tw_notice_' . get_current_user_id(), array( 'message' => $msg, 'type' => $type ), 60 );
		} else {
			$this->flash( $msg, $type );
			$done_url = self::url( 'account' );
		}
		wp_safe_redirect( $done_url );
		exit;
	}

	private function flash( $message, $type = 'success' ) {
		set_transient( 'tw_front_notice_' . get_current_user_id(), array( 'message' => $message, 'type' => $type ), 60 );
	}

	private function pull_notice() {
		$key    = 'tw_front_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( $notice ) {
			delete_transient( $key );
			return is_array( $notice ) ? $notice : null;
		}
		return null;
	}
}

