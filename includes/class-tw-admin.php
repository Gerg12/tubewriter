<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TW_Admin {

	const CAP = 'manage_options';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( 'TW_Index', 'maybe_install' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );

		add_action( 'admin_post_tw_save_settings', array( $this, 'save_settings' ) );
		add_action( 'admin_post_tw_connect', array( $this, 'connect' ) );
		add_action( 'admin_post_tw_complete', array( $this, 'complete' ) );
		add_action( 'admin_post_tw_disconnect', array( $this, 'disconnect' ) );
		add_action( 'admin_post_tw_reset_prompt', array( $this, 'reset_prompt' ) );
		add_action( 'admin_post_tw_reset_thumb_prompt', array( $this, 'reset_thumb_prompt' ) );
		add_action( 'admin_post_tw_reset_thumb_render_prompt', array( $this, 'reset_thumb_render_prompt' ) );

		foreach ( array( 'captions', 'generate', 'revise', 'save', 'playlists', 'playlist_suggest', 'calendar', 'review', 'publish', 'restore', 'sync', 'thumb_describe', 'thumb_generate', 'thumb_upload', 'thumb_ref_save', 'thumb_refs', 'thumb_logos_save', 'thumb_logo_search' ) as $action ) {
			add_action( 'wp_ajax_tw_' . $action, array( $this, 'ajax_' . $action ) );
		}
	}

	public function menu() {
		add_menu_page( 'TubeWriter', 'TubeWriter', self::CAP, 'tubewriter', array( $this, 'page_videos' ), 'dashicons-video-alt3', 26 );
		add_submenu_page( 'tubewriter', 'Videos', 'Videos', self::CAP, 'tubewriter', array( $this, 'page_videos' ) );
		add_submenu_page( 'tubewriter', 'TubeWriter History', 'History', self::CAP, 'tubewriter-history', array( $this, 'page_history' ) );
		add_submenu_page( 'tubewriter', 'TubeWriter Settings', 'Settings', self::CAP, 'tubewriter-settings', array( $this, 'page_settings' ) );
	}

	public function assets( $hook ) {
		if ( false === strpos( (string) $hook, 'tubewriter' ) ) {
			return;
		}
		$script_deps = array();
		if ( isset( $_GET['video'] ) && '' !== (string) wp_unslash( $_GET['video'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			wp_enqueue_media();
			$script_deps[] = 'media-editor';
		}
		wp_enqueue_style( 'tubewriter', TW_URL . 'assets/admin.css', array(), TW_VERSION );
		wp_enqueue_script( 'tubewriter', TW_URL . 'assets/admin.js', $script_deps, TW_VERSION, true );
		wp_localize_script(
			'tubewriter',
			'TW',
			array(
				'ajax'               => admin_url( 'admin-ajax.php' ),
				'nonce'              => wp_create_nonce( 'tw_ajax' ),
				'imageProvider'      => TW_Settings::get( 'image_provider' ),
				'imageProviderLabel' => TW_Settings::image_provider_label(),
				'settingsUrl'        => admin_url( 'admin.php?page=tubewriter-settings' ),
			)
		);
	}

	/* ---------------------------------------------------------------- Settings */

	public function page_settings() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$connected  = TW_OAuth::is_connected();
		$configured = TW_Settings::get( 'client_id' ) && TW_Settings::get( 'client_secret' );
		$channel    = get_option( 'tw_channel' );
		$tz         = wp_timezone_string();
		?>
		<div class="wrap tw-wrap">
			<h1>TubeWriter Settings</h1>
			<?php $this->notice(); ?>

			<div class="tw-card">
				<h2>1. Connect YouTube</h2>
				<?php if ( $connected ) : ?>
					<p class="tw-ok">Connected<?php echo $channel ? ' to <strong>' . esc_html( $channel['title'] ) . '</strong>' : ''; ?>.</p>
					<?php if ( TW_OAuth::needs_reconnect() ) : ?>
						<div class="notice notice-error inline"><p>YouTube needs you to sign in again. Use the steps below.</p></div>
					<?php endif; ?>
					<details class="tw-reconnect" <?php echo TW_OAuth::needs_reconnect() ? 'open' : ''; ?>>
						<summary>Sign in again</summary>
						<p>Use this when Google asks you to approve TubeWriter again, or when a video says the YouTube connection expired.</p>
						<?php $this->reconnect_steps( 'Sign in again' ); ?>
					</details>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'tw_disconnect' ); ?>
						<input type="hidden" name="action" value="tw_disconnect">
						<button class="button">Disconnect</button>
					</form>
				<?php elseif ( ! $configured ) : ?>
					<p>Save your Google Client ID and Client Secret below first.</p>
				<?php else : ?>
					<?php $this->reconnect_steps( 'Connect YouTube' ); ?>
				<?php endif; ?>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'tw_save_settings' ); ?>
				<input type="hidden" name="action" value="tw_save_settings">

				<div class="tw-card">
					<h2>2. Credentials</h2>
					<p class="description">From your own Google Cloud project (OAuth client type <strong>Desktop app</strong>) and Google AI Studio. See README.md in the plugin folder for the 15 minute setup.</p>
					<table class="form-table">
						<tr>
							<th><label for="tw_client_id">Google Client ID</label></th>
							<td><input type="text" id="tw_client_id" name="tw_client_id" class="large-text code" value="<?php echo esc_attr( TW_Settings::get( 'client_id' ) ); ?>"></td>
						</tr>
						<tr>
							<th><label for="tw_client_secret">Google Client Secret</label></th>
							<td><input type="password" id="tw_client_secret" name="tw_client_secret" class="regular-text code" autocomplete="off" placeholder="<?php echo TW_Settings::get( 'client_secret' ) ? 'Saved. Leave blank to keep it.' : ''; ?>"></td>
						</tr>
						<tr>
							<th><label for="tw_gemini_key">Gemini API key</label></th>
							<td><input type="password" id="tw_gemini_key" name="tw_gemini_key" class="regular-text code" autocomplete="off" placeholder="<?php echo TW_Settings::get( 'gemini_key' ) ? 'Saved. Leave blank to keep it.' : ''; ?>"></td>
						</tr>
						<tr>
							<th><label for="tw_model">Gemini model</label></th>
							<td>
								<input type="text" id="tw_model" name="tw_model" class="regular-text code" value="<?php echo esc_attr( TW_Settings::get( 'model' ) ); ?>">
								<p class="description">Default <code><?php echo esc_html( TW_DEFAULT_MODEL ); ?></code>. Other options: <code>gemini-3.6-flash</code>, <code>gemini-3.8-flash</code>. If the model you type here is busy or does not answer, TubeWriter tries <code>gemini-3.5-flash-lite</code>, then <code>gemini-flash-lite-latest</code>. Used for metadata and thumbnail briefs.</p>
							</td>
						</tr>
						<tr>
							<th><label for="tw_openai_key">OpenAI API key</label></th>
							<td>
								<input type="password" id="tw_openai_key" name="tw_openai_key" class="regular-text code" autocomplete="off" placeholder="<?php echo TW_Settings::get( 'openai_key' ) ? 'Saved. Leave blank to keep it.' : ''; ?>">
								<p class="description">From <a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener">platform.openai.com</a>. Used for ChatGPT text in the editor and for OpenAI thumbnails.</p>
							</td>
						</tr>
						<tr>
							<th><label for="tw_openai_model">OpenAI text model</label></th>
							<td>
								<input type="text" id="tw_openai_model" name="tw_openai_model" class="regular-text code" value="<?php echo esc_attr( TW_Settings::get( 'openai_model' ) ); ?>">
								<p class="description">Used when you pick ChatGPT next to Generate. Default <code><?php echo esc_html( TW_DEFAULT_OPENAI_MODEL ); ?></code>. If ChatGPT says this model was not found, paste a current model name from <a href="https://platform.openai.com/docs/models" target="_blank" rel="noopener">platform.openai.com/docs/models</a>.</p>
							</td>
						</tr>
						<tr>
							<th><label for="tw_image_provider">Thumbnail image provider</label></th>
							<td>
								<select id="tw_image_provider" name="tw_image_provider">
									<?php foreach ( TW_Settings::IMAGE_PROVIDERS as $id => $label ) : ?>
										<option value="<?php echo esc_attr( $id ); ?>" <?php selected( TW_Settings::get( 'image_provider' ), $id ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
								<p class="description">Gemini still writes the thumbnail brief. This only chooses who creates the final image from your screenshots.</p>
							</td>
						</tr>
						<tr>
							<th><label for="tw_openai_image_model">OpenAI image model</label></th>
							<td>
								<input type="text" id="tw_openai_image_model" name="tw_openai_image_model" class="regular-text code" value="<?php echo esc_attr( TW_Settings::get( 'openai_image_model' ) ); ?>">
								<p class="description">Default <code><?php echo esc_html( TW_DEFAULT_OPENAI_IMAGE_MODEL ); ?></code> (tracks ChatGPT's current image model). Alternatives: <code>gpt-image-2.5-sunburst</code> (more precise edits), <code>gpt-image-2.5-flare</code> (faster).</p>
							</td>
						</tr>
						<tr>
							<th><label for="tw_openai_image_quality">OpenAI image quality</label></th>
							<td>
								<select id="tw_openai_image_quality" name="tw_openai_image_quality">
									<?php foreach ( TW_Settings::OPENAI_QUALITIES as $id => $label ) : ?>
										<option value="<?php echo esc_attr( $id ); ?>" <?php selected( TW_Settings::get( 'openai_image_quality' ), $id ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
								<p class="description">Same choice as the ChatGPT picker. Instant is the fast pass. High is the finished thumbnail.</p>
							</td>
						</tr>
						<tr>
							<th><label for="tw_image_model">Gemini image model</label></th>
							<td>
								<input type="text" id="tw_image_model" name="tw_image_model" class="regular-text code" value="<?php echo esc_attr( TW_Settings::get( 'image_model' ) ); ?>">
								<p class="description">Default <code><?php echo esc_html( TW_DEFAULT_IMAGE_MODEL ); ?></code>. Only used when the image provider is Gemini.</p>
							</td>
						</tr>
					</table>
				</div>

				<div class="tw-card">
					<h2>3. Publishing defaults</h2>
					<p class="description">Starting values in the editor. You can change them per video.</p>
					<table class="form-table">
						<tr>
							<th><label for="tw_default_time">Default publish time</label></th>
							<td><input type="time" id="tw_default_time" name="tw_default_time" value="<?php echo esc_attr( TW_Settings::get( 'default_time' ) ); ?>"> <span class="description"><?php echo esc_html( $tz ); ?></span></td>
						</tr>
						<tr>
							<th><label for="tw_default_language">Video language</label></th>
							<td><select id="tw_default_language" name="tw_default_language">
								<?php foreach ( TW_Settings::LANGUAGES as $code => $name ) : ?>
									<option value="<?php echo esc_attr( $code ); ?>" <?php selected( TW_Settings::get( 'default_language' ), $code ); ?>><?php echo esc_html( $name ); ?></option>
								<?php endforeach; ?>
							</select></td>
						</tr>
						<tr>
							<th><label for="tw_default_category">Category</label></th>
							<td><select id="tw_default_category" name="tw_default_category">
								<?php foreach ( TW_Settings::CATEGORIES as $id => $name ) : ?>
									<option value="<?php echo esc_attr( $id ); ?>" <?php selected( TW_Settings::get( 'default_category' ), (string) $id ); ?>><?php echo esc_html( $name ); ?></option>
								<?php endforeach; ?>
							</select></td>
						</tr>
					</table>
				</div>

				<div class="tw-card">
					<h2>4. Channel voice prompt</h2>
					<p class="description">This is what the AI follows for tone, the warning text, chapter style, tags and hashtags. The JSON output rules are added automatically, so you only describe the voice and content.</p>
					<textarea name="tw_prompt" rows="28" class="large-text code tw-prompt"><?php echo esc_textarea( TW_Settings::get( 'prompt' ) ); ?></textarea>
				</div>

				<div class="tw-card">
					<h2>5. Thumbnail brief prompt</h2>
					<p class="description">Gemini reads the title, description, transcript and screenshots, then writes a Gregatron thumbnail concept: hook text, picture-in-picture, avatar, GG logo and game logo. JSON rules are added automatically.</p>
					<textarea name="tw_thumb_prompt" rows="24" class="large-text code tw-prompt"><?php echo esc_textarea( TW_Settings::get( 'thumb_prompt' ) ); ?></textarea>
				</div>

				<div class="tw-card">
					<h2>6. Thumbnail render instructions</h2>
					<p class="description">Standing layout sent with every Create thumbnail, before the approved brief. This is the stencil headline, inset card, avatar, GG logo, game logo and safe zones. Per-video specifics stay in the brief on step 3.</p>
					<textarea name="tw_thumb_render_prompt" rows="22" class="large-text code tw-prompt"><?php echo esc_textarea( TW_Settings::get( 'thumb_render_prompt' ) ); ?></textarea>
				</div>

				<p class="description">Schedules use your WordPress timezone: <strong><?php echo esc_html( $tz ); ?></strong>. Change it under <a href="<?php echo esc_url( admin_url( 'options-general.php' ) ); ?>">Settings &gt; General</a>.</p>
				<?php submit_button( 'Save settings' ); ?>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Replace your prompt with the default GG prompt?');">
				<?php wp_nonce_field( 'tw_reset_prompt' ); ?>
				<input type="hidden" name="action" value="tw_reset_prompt">
				<button class="button-link tw-danger-link">Reset voice prompt to default</button>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Replace your thumbnail brief prompt with the default?');">
				<?php wp_nonce_field( 'tw_reset_thumb_prompt' ); ?>
				<input type="hidden" name="action" value="tw_reset_thumb_prompt">
				<button class="button-link tw-danger-link">Reset thumbnail brief prompt to default</button>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Replace your thumbnail render instructions with the default?');">
				<?php wp_nonce_field( 'tw_reset_thumb_render_prompt' ); ?>
				<input type="hidden" name="action" value="tw_reset_thumb_render_prompt">
				<button class="button-link tw-danger-link">Reset thumbnail render instructions to default</button>
			</form>
		</div>
		<?php
	}

	public function save_settings() {
		$this->guard( 'tw_save_settings' );
		TW_Settings::save_from_post( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification
		$this->back( 'settings', 'Settings saved.' );
	}

	public function reset_prompt() {
		$this->guard( 'tw_reset_prompt' );
		TW_Settings::set( 'prompt', TW_Settings::default_prompt() );
		$this->back( 'settings', 'Prompt reset to the default.' );
	}

	public function reset_thumb_prompt() {
		$this->guard( 'tw_reset_thumb_prompt' );
		TW_Settings::set( 'thumb_prompt', TW_Settings::default_thumb_prompt() );
		$this->back( 'settings', 'Thumbnail brief prompt reset to the default.' );
	}

	public function reset_thumb_render_prompt() {
		$this->guard( 'tw_reset_thumb_render_prompt' );
		TW_Settings::set( 'thumb_render_prompt', TW_Settings::default_thumb_render_prompt() );
		$this->back( 'settings', 'Thumbnail render instructions reset to the default.' );
	}

	public function connect() {
		$this->guard( 'tw_connect', 'GET' );
		wp_redirect( TW_OAuth::auth_url() ); // phpcs:ignore WordPress.Security.SafeRedirect
		exit;
	}

	public function complete() {
		$this->guard( 'tw_complete' );
		$result = TW_OAuth::complete( wp_unslash( $_POST['tw_pasted'] ?? '' ) ); // phpcs:ignore
		if ( is_wp_error( $result ) ) {
			$this->back( 'settings', $result->get_error_message(), 'error' );
		}
		delete_option( 'tw_channel' );
		$channel = TW_YouTube::channel();
		if ( is_wp_error( $channel ) ) {
			$this->back( 'settings', 'Connected, but: ' . $channel->get_error_message(), 'warning' );
		}
		$this->back( 'settings', 'Connected to ' . $channel['title'] . '.' );
	}

	public function disconnect() {
		$this->guard( 'tw_disconnect' );
		TW_OAuth::disconnect();
		$this->back( 'settings', 'Disconnected.' );
	}

	/* ---------------------------------------------------------------- Videos */

	public function page_videos() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$video_id = isset( $_GET['video'] ) ? sanitize_text_field( wp_unslash( $_GET['video'] ) ) : ''; // phpcs:ignore
		if ( $video_id ) {
			$this->page_editor( $video_id );
			return;
		}

		echo '<div class="wrap tw-wrap"><h1 class="wp-heading-inline">TubeWriter</h1>';
		$this->notice();
		if ( TW_OAuth::needs_reconnect() ) {
			echo '<div class="notice notice-error"><p>YouTube needs you to sign in again. Open <a href="' . esc_url( admin_url( 'admin.php?page=tubewriter-settings' ) ) . '">Settings</a> and use <strong>Sign in again</strong>.</p></div>';
		}

		if ( ! TW_Settings::is_configured() || ! TW_OAuth::is_connected() ) {
			echo '<div class="tw-card"><p>Finish setup first: add your credentials and connect YouTube in <a href="' . esc_url( admin_url( 'admin.php?page=tubewriter-settings' ) ) . '">Settings</a>.</p></div></div>';
			return;
		}

		$indexed      = TW_Index::count_all();
		$scheduled_n  = TW_Index::count_scheduled();
		$synced       = (int) get_option( 'tw_index_synced', 0 );
		$full         = (int) get_option( 'tw_index_full', 0 );
		$sched_note   = (string) get_option( 'tw_scheduled_sync_note', '' );
		$est          = max( 2, (int) ceil( max( $indexed, 50 ) / 50 ) * 2 ) + 16;
		?>
		<div class="tw-sync" id="tw-sync" data-empty="<?php echo $indexed ? '0' : '1'; ?>">
			<div class="tw-sync-readout">
				<span class="spinner tw-sync-spinner" id="tw-sync-spinner" aria-hidden="true"></span>
				<span class="tw-sync-status" id="tw-sync-status" role="status" aria-live="polite">
					<?php if ( $indexed ) : ?>
						<?php echo esc_html( number_format_i18n( $indexed ) ); ?> videos indexed.
						Last synced <?php echo esc_html( human_time_diff( $synced ) ); ?> ago<?php echo $full ? ', last full sync ' . esc_html( human_time_diff( $full ) ) . ' ago' : ''; ?>.
					<?php else : ?>
						Your video list is stored on this site so searching and filtering cost nothing. Build it once with Full sync.
					<?php endif; ?>
				</span>
				<div class="tw-sync-progress" id="tw-sync-progress" hidden>
					<div class="tw-sync-progress-bar" id="tw-sync-progress-bar" style="width:0%"></div>
				</div>
			</div>
			<button type="button" class="button" data-sync="quick">Quick sync (newest 100, drafts and scheduled, about 8 units)</button>
			<button type="button" class="button" data-sync="full">Full sync (about <?php echo esc_html( number_format_i18n( $est ) ); ?> units)</button>
			<button type="button" class="button button-primary" data-sync="drafts">Compare drafts (add missing only)</button>
			<span class="description">YouTube API today: about <?php echo esc_html( number_format_i18n( TW_YouTube::quota_used() ) ); ?> / 10,000 units</span>
			<?php if ( $sched_note ) : ?>
				<p class="description tw-sync-warn"><?php echo esc_html( $sched_note ); ?></p>
			<?php endif; ?>
		</div>
		<p class="description tw-sched-hint">YouTube's API has no Draft status (Studio drafts look like private). <strong>Compare drafts</strong> moves already-indexed private uploads into Draft, then searches for draft candidates and only adds ones you do not have yet. It does not re-download the whole channel.</p>
		<?php if ( $indexed && ! $scheduled_n ) : ?>
			<p class="description tw-sched-hint">No scheduled videos in the index yet. Run <strong>Quick sync</strong> or <strong>Full sync</strong>, then open the <strong>Scheduled</strong> tab below (orange).</p>
		<?php endif; ?>
		<?php
		$table = new TW_Videos_Table();
		$table->prepare_items();
		$table->views();
		echo '<form method="get" class="tw-list-form"><input type="hidden" name="page" value="tubewriter">';
		$table->search_box( 'Search title and description', 'tw-search' );
		$table->display();
		echo '</form></div>';
	}

	private function page_editor( $video_id ) {
		echo '<div class="wrap tw-wrap">';
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=tubewriter' ) ) . '">&larr; All videos</a></p>';

		$video = TW_YouTube::get_video( $video_id );
		if ( is_wp_error( $video ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $video->get_error_message() ) . '</p></div>';
			if ( in_array( $video->get_error_code(), array( 'tw_expired', 'tw_not_connected' ), true ) ) {
				echo '<div class="tw-card">';
				$this->reconnect_steps( 'Sign in again' );
				echo '</div>';
			}
			echo '</div>';
			return;
		}

		$state         = get_option( 'tw_state_' . $video_id, array() );
		$snap          = TW_YouTube::snapshot( $video );
		$secs          = TW_Transcript::duration_seconds( $video['contentDetails']['duration'] ?? '' );
		$remote_thumb  = $video['snippet']['thumbnails']['medium']['url'] ?? '';
		$thumb         = TW_Index::cache_thumbnail( $video_id, $remote_thumb );
		$studio        = 'https://studio.youtube.com/video/' . rawurlencode( $video_id ) . '/edit';
		$index_privacy = TW_Index::privacy_of( $video_id );
		$badge_privacy = ( 'draft' === $index_privacy ) ? 'draft' : $snap['privacyStatus'];
		$boot          = array(
			'videoId'   => $video_id,
			'duration'  => $secs,
			'current'   => $snap,
			'publishAtLocal' => $snap['publishAt'] ? array( 'date' => wp_date( 'Y-m-d', strtotime( $snap['publishAt'] ) ), 'time' => wp_date( 'H:i', strtotime( $snap['publishAt'] ) ) ) : null,
			'state'     => is_array( $state ) ? $state : array(),
			'studioUrl' => $studio,
			'defaults'  => array(
				'time'     => TW_Settings::get( 'default_time' ),
				'language' => TW_Settings::get( 'default_language' ),
				'category' => TW_Settings::get( 'default_category' ),
			),
			'logos'     => array(
				'game'    => $this->logo_boot( (int) ( is_array( $state ) ? ( $state['game_logo_id'] ?? 0 ) : 0 ), (string) ( is_array( $state ) ? ( $state['game_logo_pos'] ?? '' ) : '' ), 'upper-right' ),
				'channel' => $this->logo_boot( (int) get_option( 'tw_channel_logo_id', 0 ), (string) get_option( 'tw_channel_logo_pos', '' ), 'lower-left' ),
			),
			'today'     => wp_date( 'Y-m-d' ),
			'tz'        => wp_timezone_string(),
			'history'   => TW_History::summary( $video_id ),
		);
		$help = function ( $what, array $todo ) {
			echo '<div class="tw-help"><p><strong>What this step does:</strong> ' . esc_html( $what ) . '</p><p><strong>What to do:</strong></p><ol>';
			foreach ( $todo as $t ) {
				echo '<li>' . wp_kses( $t, array( 'strong' => array(), 'em' => array(), 'code' => array(), 'span' => array( 'class' => array() ) ) ) . '</li>';
			}
			echo '</ol></div>';
		};
		$select = function ( $id, array $options, $selected ) {
			echo '<select id="' . esc_attr( $id ) . '">';
			foreach ( $options as $value => $label ) {
				printf( '<option value="%s"%s>%s</option>', esc_attr( $value ), selected( (string) $selected, (string) $value, false ), esc_html( $label ) );
			}
			echo '</select>';
		};
		?>
		<div class="tw-editor" id="tw-editor" data-boot="<?php echo esc_attr( wp_json_encode( $boot ) ); ?>">
			<div class="tw-head">
				<?php if ( $thumb ) : ?>
					<a class="tw-head-thumb" href="<?php echo esc_url( $studio ); ?>" target="_blank" rel="noopener" title="Open in Studio">
						<img src="<?php echo esc_url( $thumb ); ?>" alt="">
					</a>
				<?php endif; ?>
				<div>
					<h1><?php echo esc_html( $snap['title'] ); ?></h1>
					<p>
						<span class="tw-badge tw-badge-<?php echo esc_attr( $badge_privacy ); ?>"><?php echo esc_html( $badge_privacy ); ?></span>
						<?php echo $secs ? esc_html( TW_Transcript::fmt( $secs ) ) : ''; ?>
						&middot; <a href="<?php echo esc_url( $studio ); ?>" target="_blank" rel="noopener">Open in Studio</a>
						&middot; <span class="tw-quota">YouTube API today: about <?php echo esc_html( number_format_i18n( TW_YouTube::quota_used() ) ); ?> / 10,000 units</span>
					</p>
					<p class="tw-safe">Nothing on YouTube changes until step 7, where you confirm twice. A backup is saved before every change, and TubeWriter never deletes anything. Thumbnails stay on your computer until you upload them in Studio.</p>
				</div>
			</div>

			<ol class="tw-stepper" id="tw-stepper">
				<li><button type="button" data-go="1">1. Source</button></li>
				<li><button type="button" data-go="2">2. Details</button></li>
				<li><button type="button" data-go="3">3. Thumbnail</button></li>
				<li><button type="button" data-go="4">4. Playlist</button></li>
				<li><button type="button" data-go="5">5. Schedule</button></li>
				<li><button type="button" data-go="6">6. Review</button></li>
				<li><button type="button" data-go="7">7. Publish</button></li>
			</ol>

			<div id="tw-msg" class="tw-msg" hidden></div>

			<!-- STEP 1 -->
			<section class="tw-card tw-step" data-step="1">
				<h2>Step 1: Source material</h2>
				<?php
				$help(
					'Gathers what the AI writes from: the transcript, a starting title and description, and your notes.',
					array(
						'Click <strong>Pull YouTube captions</strong>, or paste a transcript. Skip it if there are no captions.',
						'Check the <strong>starting title and description</strong>. They are filled in from YouTube. If there is no transcript, skim the video and write a short version yourself.',
						'Add <strong>notes</strong>: key moments with rough times (each becomes a chapter) and anything the AI must get right.',
						'Pick <strong>Gemini</strong> or <strong>ChatGPT</strong> next to <strong>Generate with AI</strong>, then generate. Or click <strong>Fill in by hand</strong>. The draft stays on this page, under your notes. Generate asks before it overwrites the current description. It does not save to YouTube, and you can undo. A busy model is not the YouTube sign-in.',
					)
				);
				?>
				<div class="tw-row">
					<label for="tw-transcript">Transcript</label>
					<span class="tw-pull-wrap">
						<span id="tw-pull-status" class="tw-pull-status" aria-live="polite"></span>
						<button type="button" class="button" id="tw-pull">Pull YouTube captions</button>
					</span>
				</div>
				<textarea id="tw-transcript" rows="12" class="large-text code" placeholder="Pull captions, or paste a transcript (plain text with timestamps, SRT or VTT all work)."></textarea>
				<p class="description">Pulling captions costs 250 of your 10,000 daily YouTube API units.</p>

				<div class="tw-guide" id="tw-guide">
					<strong>No transcript? Skim the video and fill in the boxes below.</strong>
					<p>The AI only knows what you tell it. A few honest lines beat a long vague paragraph.</p>
					<ol>
						<li><strong>Starting title:</strong> what the video is in a few words. <em>Gates of Hell, stole their 88 with a Churchill</em></li>
						<li><strong>Starting description:</strong> 2 or 3 sentences. The game and mode, who you played with, how it went and how it ended.</li>
						<li><strong>Notes:</strong> the best moments with rough times, one per line. Each one becomes a chapter.
							<br><code>2:30 Mike drives the supply truck into an open field</code>
							<br><code>11:15 we capture their 88 and turn it on them</code>
						</li>
						<li>Funniest fail or best play? Say so. That's what the description jokes are built from.</li>
					</ol>
				</div>

				<label for="tw-seed-title">Starting title</label>
				<input type="text" id="tw-seed-title" class="large-text" placeholder="Short idea, e.g. Stole their 88 with a Churchill">

				<label for="tw-seed-desc">Starting description</label>
				<textarea id="tw-seed-desc" rows="5" class="large-text" placeholder="A line or two about what happens. Any links in here get kept."></textarea>

				<label for="tw-notes">Notes for the AI</label>
				<textarea id="tw-notes" rows="5" class="large-text" placeholder="Key moments with rough times, one per line (2:30 truck dies). Anything the AI should know or get right. The AI trusts these over everything else."></textarea>

				<p class="tw-actions-left">
					<button type="button" class="button button-primary button-hero" id="tw-generate">Generate with AI</button>
					<label for="tw-text-provider" class="tw-provider-label">with</label>
					<select id="tw-text-provider" aria-label="AI model">
						<option value="gemini">Gemini (<?php echo esc_html( TW_Settings::get( 'model' ) ); ?>)</option>
						<option value="openai">ChatGPT (<?php echo esc_html( TW_Settings::get( 'openai_model' ) ); ?>)</option>
					</select>
					<button type="button" class="button button-hero" id="tw-manual">Fill in by hand</button>
				</p>
				<p id="tw-replace-warn" class="tw-draft-note" hidden>
					Careful: Generate will replace the current description. It will not save to YouTube, and you can undo.
					<button type="button" class="button button-primary" id="tw-replace-go">Replace the description</button>
					<button type="button" class="button" id="tw-replace-cancel">Keep what's there</button>
				</p>
				<p class="description">Switching models only changes who writes the draft. YouTube sign-in is separate. If the Gemini model in Settings is busy or does not answer, TubeWriter tries gemini-3.5-flash-lite, then gemini-flash-lite-latest. You can also pick ChatGPT. A video with no transcript can still generate from the starting title, description, and timestamp notes.</p>
			</section>

			<!-- STEP 2 (same page as the source, so the draft can be compared with what it came from) -->
			<section class="tw-card tw-step" data-step="2">
				<h2>Step 2: Details</h2>
				<p id="tw-draft-undo" class="tw-draft-note" hidden>Generate replaced the description in this draft. Nothing was saved to YouTube. <button type="button" class="button" id="tw-undo-generate">Undo</button></p>
				<?php
				$help(
					'The title, description, tags, category and language that will be published. They stay on this page with your starting title, description and notes so you can see where the draft came from. Editing here only changes the draft.',
					array(
						'Compare the draft with the <strong>starting title, description and notes</strong> above.',
						'Pick a <strong>title</strong> from the AI options, type your own, or keep what is on YouTube.',
						'Read the <strong>description</strong> and <strong>chapters</strong>. Fix anything that is wrong.',
						'Check <strong>tags</strong>, <strong>hashtags</strong>, <strong>category</strong> and <strong>language</strong>.',
						'Want a change from the AI? Type it in <strong>Tweak it</strong>. Then click <strong>Next</strong>.',
					)
				);
				?>
				<div class="tw-row">
					<label>Title</label>
					<p class="tw-count" data-for="tw-title" data-max="100"></p>
				</div>
				<div id="tw-titles" class="tw-titles"></div>
				<input type="text" id="tw-title" class="large-text">

				<div class="tw-row">
					<label for="tw-body">Description</label>
					<p class="tw-count" id="tw-desc-count"></p>
				</div>
				<textarea id="tw-body" rows="18" class="large-text"></textarea>
				<p class="description">YouTube allows 5,000 characters for the full description, including the chapter breakdown and hashtags. The first ~100 characters show in search, so lead with the game and what happens.</p>

				<label for="tw-chapters-heading">Chapters heading</label>
				<input type="text" id="tw-chapters-heading" class="large-text">

				<label for="tw-chapters">Chapters (one per line, first must be 0:00, leave empty for none)</label>
				<textarea id="tw-chapters" rows="14" class="large-text code"></textarea>

				<label for="tw-hashtags">Hashtags (3, added at the end of the description. Only 3 show above the title.)</label>
				<input type="text" id="tw-hashtags" class="large-text">
				<p class="tw-count" id="tw-hashtag-count"></p>

				<label for="tw-tags">Tags (comma separated)</label>
				<textarea id="tw-tags" rows="2" class="large-text"></textarea>
				<p class="tw-count" id="tw-tag-count"></p>

				<div class="tw-two">
					<div>
						<label for="tw-category">Category</label>
						<?php $select( 'tw-category', TW_Settings::CATEGORIES, $boot['defaults']['category'] ); ?>
					</div>
					<div>
						<label for="tw-language">Video and audio language</label>
						<?php $select( 'tw-language', TW_Settings::LANGUAGES, $boot['defaults']['language'] ); ?>
					</div>
				</div>
				<p class="description">Game title is not available through YouTube's API. Step 7 links you straight to it in Studio.</p>

				<details class="tw-preview">
					<summary>Full description preview</summary>
					<pre id="tw-preview"></pre>
				</details>
				<p class="description" id="tw-search-preview"></p>

				<div class="tw-tweak">
					<label for="tw-tweak">Tweak it with AI</label>
					<textarea id="tw-tweak" rows="2" class="large-text" placeholder="e.g. make title 3 meaner, or the supply truck fail was Mike, not me"></textarea>
					<button type="button" class="button" id="tw-revise">Apply change</button>
				</div>
			</section>

			<!-- STEP 3 -->
			<section class="tw-card tw-step" data-step="3" hidden>
				<h2>Step 3: Thumbnail</h2>
				<?php
				$help(
					'Optional. Paste up to two gameplay screenshots. Gemini writes a packaged thumbnail concept from the shots plus your title, description and transcript, then the image model builds the card. Upload to YouTube only when you click that button and confirm.',
					array(
						'Press <strong>Ctrl+V</strong> to paste a thumbnail. It is saved in the Media Library. Click a saved one below to look at it, then copy it.',
						'Click <strong>Write thumbnail description</strong>. The concept uses a top-left hook, your avatar and GG logo, and the game logo. The hook does not repeat the title.',
						'Edit the brief if you want, then click <strong>Create thumbnail</strong>. Standing layout rules live in Settings under Thumbnail render instructions.',
						'Click <strong>Upload to YouTube</strong> and confirm, or download and upload in Studio. Skip this step anytime with Next.',
					)
				);
				?>
				<p class="description" id="tw-thumb-provider">Image provider: <strong><?php echo esc_html( TW_Settings::image_provider_label() ); ?></strong>. Change it in Settings.</p>
				<div id="tw-thumb-zone" class="tw-thumb-zone" tabindex="0" role="button" aria-label="Paste screenshot area">
					<p class="tw-thumb-zone-title">Paste screenshots here</p>
					<p class="description">Press Ctrl+V while this box is focused, or click Add image to open the Media Library. Max two.</p>
					<div id="tw-thumb-shots" class="tw-thumb-shots"></div>
					<div class="tw-inline">
						<button type="button" class="button" id="tw-thumb-add">Add image</button>
						<button type="button" class="button" id="tw-thumb-clear" hidden>Clear images</button>
						<input type="file" id="tw-thumb-file" accept="image/png,image/jpeg,image/webp,image/gif" hidden>
					</div>
				</div>

				<div id="tw-thumb-refs" class="tw-thumb-refs">
					<p class="tw-thumb-zone-title">Saved references</p>
					<p class="description">Pasted thumbnails stay in the Media Library. Click one to look at it, then copy it.</p>
					<div id="tw-thumb-ref-list" class="tw-thumb-shots"></div>
				</div>

				<div id="tw-thumb-lightbox" class="tw-thumb-lightbox" hidden>
					<div class="tw-thumb-lightbox-card" role="dialog" aria-modal="true" aria-label="Saved thumbnail">
						<img id="tw-thumb-lightbox-img" alt="">
						<p class="tw-inline">
							<button type="button" class="button button-primary" id="tw-thumb-lightbox-copy">Copy image</button>
							<a class="button" id="tw-thumb-lightbox-edit" href="#" target="_blank" rel="noopener">Open in Media Library</a>
							<button type="button" class="button" id="tw-thumb-lightbox-close">Close</button>
						</p>
					</div>
				</div>

				<p class="tw-actions-left">
					<button type="button" class="button button-primary" id="tw-thumb-describe">Write thumbnail description</button>
				</p>

				<label for="tw-thumb-desc">Thumbnail description</label>
				<textarea id="tw-thumb-desc" rows="22" class="large-text" placeholder="The numbered 16:9 concept appears here. Edit any line, then create the thumbnail."></textarea>

				<div class="tw-two tw-logo-fields">
					<div>
						<label for="tw-game-logo-pos">Game logo</label>
						<select id="tw-game-logo-pos">
							<option value="upper-right">Top right</option>
							<option value="upper-left">Top left</option>
							<option value="lower-right">Bottom right</option>
							<option value="none">Don't place it</option>
						</select>
						<p class="description" id="tw-game-logo-note">Headline stays top left. With one screenshot the game logo sits top right, or top left if you pick that.</p>
						<p class="tw-inline">
							<input type="search" id="tw-game-logo-search" class="regular-text" placeholder="Search logos, e.g. Gates of Hell">
							<button type="button" class="button" id="tw-game-logo-find">Find</button>
						</p>
						<div id="tw-game-logo-suggestions" class="tw-logo-suggestions"></div>
						<p class="tw-inline">
							<button type="button" class="button" id="tw-game-logo-pick">Choose from Media Library</button>
							<button type="button" class="button-link" id="tw-game-logo-clear" hidden>Remove</button>
						</p>
						<img id="tw-game-logo-preview" class="tw-logo-preview" alt="" hidden>
					</div>
					<div>
						<label for="tw-channel-logo-pos">Channel logo</label>
						<select id="tw-channel-logo-pos">
							<option value="lower-left">Bottom left</option>
							<option value="lower-right">Bottom right</option>
							<option value="upper-left">Top left</option>
							<option value="upper-right">Top right</option>
							<option value="none">Don't place it</option>
						</select>
						<p class="tw-inline">
							<button type="button" class="button" id="tw-channel-logo-pick">Choose from Media Library</button>
							<button type="button" class="button-link" id="tw-channel-logo-clear" hidden>Remove</button>
						</p>
						<img id="tw-channel-logo-preview" class="tw-logo-preview" alt="" hidden>
					</div>
				</div>
				<p class="description">Headline is always top left. Channel logo defaults to the bottom left. Game logo defaults to the top right. A second screenshot becomes a picture-in-picture in the top right, and the game logo moves to the bottom right. The lower-left character comes from the screenshot for that video. Search for a real game logo PNG in the Media Library, or upload one and pick it.</p>

				<p class="tw-actions-left">
					<button type="button" class="button button-primary button-hero" id="tw-thumb-create">Create thumbnail</button>
				</p>

				<div id="tw-thumb-result" class="tw-thumb-result" hidden>
					<label>Generated thumbnail</label>
					<img id="tw-thumb-preview" alt="Generated thumbnail">
					<p class="tw-inline">
						<button type="button" class="button button-primary" id="tw-thumb-upload">Upload to YouTube</button>
						<a class="button" id="tw-thumb-download" download="thumbnail.png" href="#">Download PNG</a>
						<a class="button" href="<?php echo esc_url( $studio ); ?>" target="_blank" rel="noopener">Open in Studio</a>
					</p>
					<p class="description">Upload to YouTube costs about 50 API units and needs a verified channel for custom thumbnails. You can still download and upload in Studio instead.</p>
				</div>
			</section>

			<!-- STEP 4 -->
			<section class="tw-card tw-step" data-step="4" hidden>
				<h2>Step 4: Playlist</h2>
				<?php
				$help(
					'Adds the video to a playlist, or creates a new playlist for it. Nothing is created until you publish in step 7.',
					array(
						'Choose <strong>Don\'t add</strong>, <strong>Add to an existing playlist</strong>, or <strong>Create a new playlist</strong>.',
						'For a new playlist, type a rough name and click <strong>Polish for SEO</strong>. Edit the result if you want.',
						'TubeWriter never removes videos from playlists. If you pick the wrong one, remove it in Studio.',
					)
				);
				?>
				<p><label class="tw-radio"><input type="radio" name="tw-pl-mode" value="none" checked> Don't add to a playlist</label></p>
				<p><label class="tw-radio"><input type="radio" name="tw-pl-mode" value="existing"> Add to an existing playlist</label></p>
				<div class="tw-sub" id="tw-pl-existing" hidden>
					<select id="tw-pl-select"><option value="">Loading playlists...</option></select>
					<button type="button" class="button" id="tw-pl-refresh">Refresh list</button>
				</div>
				<p><label class="tw-radio"><input type="radio" name="tw-pl-mode" value="new"> Create a new playlist and add this video</label></p>
				<div class="tw-sub" id="tw-pl-new" hidden>
					<label for="tw-pl-rough">Rough name</label>
					<div class="tw-inline">
						<input type="text" id="tw-pl-rough" class="regular-text" placeholder="e.g. space marine 2 ops co-op">
						<button type="button" class="button" id="tw-pl-polish">Polish for SEO</button>
					</div>
					<label for="tw-pl-title">Playlist title</label>
					<input type="text" id="tw-pl-title" class="large-text">
					<label for="tw-pl-desc">Playlist description</label>
					<textarea id="tw-pl-desc" rows="3" class="large-text"></textarea>
					<label for="tw-pl-privacy">Playlist visibility</label>
					<select id="tw-pl-privacy"><option value="public">Public</option><option value="unlisted">Unlisted</option><option value="private">Private</option></select>
				</div>
			</section>

			<!-- STEP 5 -->
			<section class="tw-card tw-step" data-step="5" hidden>
				<h2>Step 5: Schedule</h2>
				<?php
				$help(
					'Picks when the video goes public. Only private videos can be scheduled.',
					array(
						'Choose <strong>Leave as is</strong> to keep the current schedule, or <strong>Schedule it</strong>.',
						'Click a day on the calendar. <span class="tw-key tw-key-sched">Orange</span> is already scheduled, <span class="tw-key tw-key-pub">green</span> is already published, empty days are open.',
						'Check the time. It starts at your default from Settings (' . esc_html( TW_Settings::get( 'default_time' ) ) . ', ' . esc_html( wp_timezone_string() ) . ').',
					)
				);
				?>
				<p id="tw-sched-current" class="tw-current-line"></p>
				<div id="tw-sched-locked" class="notice notice-info inline" hidden><p>This video is not private, so its schedule can't be changed here.</p></div>
				<div id="tw-sched-controls">
					<p><label class="tw-radio"><input type="radio" name="tw-sched-mode" value="keep" checked> Leave as is</label></p>
					<p><label class="tw-radio"><input type="radio" name="tw-sched-mode" value="set"> Schedule it</label></p>
					<div class="tw-sub" id="tw-sched-set" hidden>
						<div class="tw-inline">
							<label for="tw-sched-date">Date</label> <input type="date" id="tw-sched-date">
							<label for="tw-sched-time">Time</label> <input type="time" id="tw-sched-time">
							<span class="description"><?php echo esc_html( wp_timezone_string() ); ?></span>
						</div>
					</div>
				</div>
				<div class="tw-cal">
					<div class="tw-cal-head">
						<button type="button" class="button" id="tw-cal-prev">&larr;</button>
						<strong id="tw-cal-title"></strong>
						<button type="button" class="button" id="tw-cal-next">&rarr;</button>
						<button type="button" class="button-link" id="tw-cal-refresh">Refresh from YouTube</button>
					</div>
					<div class="tw-cal-grid" id="tw-cal-grid"></div>
				</div>
			</section>

			<!-- STEP 6 -->
			<section class="tw-card tw-step" data-step="6" hidden>
				<h2>Step 6: Review</h2>
				<?php
				$help(
					'Compares what is on YouTube right now with what TubeWriter will write. Nothing has changed yet.',
					array(
						'Read every <strong>highlighted</strong> row. Left is YouTube now, right is after publishing.',
						'Fix problems by going back to the step that owns them.',
						'If there are red errors, publishing is blocked until they are fixed.',
					)
				);
				?>
				<p><button type="button" class="button" id="tw-review-reload">Reload from YouTube</button></p>
				<div id="tw-review"></div>
			</section>

			<!-- STEP 7 -->
			<section class="tw-card tw-step" data-step="7" hidden>
				<h2>Step 7: Publish</h2>
				<?php
				$help(
					'Saves a backup of the current details, then makes exactly the changes listed below. Nothing else on the video is touched.',
					array(
						'Read the list of changes.',
						'Tick the box to confirm you reviewed them.',
						'Click <strong>Publish changes</strong>, then confirm once more in the pop-up.',
						'Afterwards, finish the manual steps in Studio (thumbnail, game title and end screen).',
					)
				);
				?>
				<div id="tw-confirm">
					<h3>These changes will be made</h3>
					<ul id="tw-actions" class="tw-action-list"></ul>
					<p class="tw-backup-note">Before anything changes, TubeWriter saves the current title, description, tags, category, language and schedule. You can restore them from <strong>Version history</strong> below.</p>
					<p><label><input type="checkbox" id="tw-ack"> I reviewed these changes in step 6 and want to make them.</label></p>
					<p><button type="button" class="button button-primary button-hero" id="tw-publish" disabled>Publish changes</button></p>
				</div>
				<div id="tw-results" hidden></div>

				<div class="tw-manual">
					<h3>Finish in Studio (not possible through the API)</h3>
					<ol>
						<li><strong>Game title:</strong> open <a href="<?php echo esc_url( $studio ); ?>" target="_blank" rel="noopener">the video in Studio</a>, Details, Show more, Game title.</li>
						<li><strong>End screen:</strong> in Studio, Video elements, End screen. Add <em>Subscribe</em>, <em>Video: Best for viewer</em>, and the playlist from step 4 (or one in the same genre).</li>
					</ol>
					<p class="description">Thumbnails can be uploaded from step 3. If you skipped that, you can still set one in Studio under Details.</p>
				</div>

				<div class="tw-history">
					<h3>Version history</h3>
					<p class="description">Every publish and restore saves the details from just before it. Restoring puts that version back and backs up the current one first, so a restore can be undone too.</p>
					<div id="tw-history-list"></div>
				</div>
			</section>

			<div class="tw-footer">
				<button type="button" class="button" id="tw-back">&larr; Back</button>
				<span class="tw-footer-mid">
					<button type="button" class="button" id="tw-save">Save draft</button>
					<span id="tw-saved" class="description"></span>
				</span>
				<button type="button" class="button button-primary" id="tw-next">Next &rarr;</button>
			</div>
		</div>
		</div>
		<?php
	}

	public function page_history() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		echo '<div class="wrap tw-wrap"><h1>TubeWriter History</h1>';
		echo '<p class="description">Every change TubeWriter made, newest first. Open a video to see its backups and restore an older version.</p>';
		$log = TW_History::log_entries();
		if ( ! $log ) {
			echo '<div class="tw-card"><p>No changes yet.</p></div></div>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>When</th><th>Video</th><th>Action</th><th>Result</th><th>What changed</th></tr></thead><tbody>';
		foreach ( $log as $e ) {
			printf(
				'<tr><td>%s</td><td><a href="%s">%s</a></td><td>%s</td><td><span class="tw-badge tw-badge-%s">%s</span></td><td><ul class="tw-mini">%s</ul></td></tr>',
				esc_html( wp_date( 'M j, Y g:ia', $e['time'] ) ),
				esc_url( admin_url( 'admin.php?page=tubewriter&video=' . rawurlencode( $e['video'] ) ) ),
				esc_html( $e['title'] ?: $e['video'] ),
				esc_html( ucfirst( $e['action'] ) ),
				esc_attr( 'done' === $e['status'] ? 'public' : 'private' ),
				esc_html( $e['status'] ),
				implode( '', array_map( function ( $c ) { return '<li>' . esc_html( $c ) . '</li>'; }, (array) $e['changes'] ) )
			);
		}
		echo '</tbody></table></div>';
	}

	/* ---------------------------------------------------------------- AJAX */

	public function ajax_captions() {
		$this->ajax_guard();
		$srt = TW_YouTube::captions_srt( $this->post( 'video' ) );
		if ( is_wp_error( $srt ) ) {
			wp_send_json_error( $srt->get_error_message() );
		}
		$text = TW_Transcript::normalize( $srt );
		$this->save_state( $this->post( 'video' ), array( 'transcript' => $text ) );
		wp_send_json_success( array( 'transcript' => $text ) );
	}

	public function ajax_generate() {
		$this->ajax_guard();
		$input = $this->input();
		if ( '' === trim( $input['transcript'] ) && '' === trim( $input['notes'] ) && '' === trim( $input['current_description'] ) ) {
			wp_send_json_error( 'Nothing to work from yet. Add a transcript, a starting description or some notes.' );
		}
		$pkg = TW_AI::generate( $input, $this->text_provider() );
		$this->respond_with_package( $input, $pkg );
	}

	public function ajax_revise() {
		$this->ajax_guard();
		$input       = $this->input();
		$instruction = $this->post( 'instruction', true );
		if ( '' === trim( $instruction ) ) {
			wp_send_json_error( 'Type what you want changed.' );
		}
		$current = $this->pkg_from_post();
		$pkg     = TW_AI::revise( $input, $current, $instruction, $this->text_provider() );
		$this->respond_with_package( $input, $pkg );
	}

	public function ajax_save() {
		$this->ajax_guard();
		$input = $this->input();
		$this->save_state(
			$input['video'],
			array(
				'transcript'        => $input['transcript'],
				'notes'             => $input['notes'],
				'seed_title'        => $input['current_title'],
				'seed_desc'         => $input['current_description'],
				'pkg'               => $this->pkg_from_post(),
				'opts'              => $this->opts_from_post(),
				'thumb_description' => $this->post( 'thumb_description', true ),
				'saved'             => time(),
			)
		);
		wp_send_json_success( array( 'saved' => wp_date( 'g:ia' ) ) );
	}

	public function ajax_thumb_logos_save() {
		$this->ajax_guard();
		$channel_id  = absint( $this->post( 'channel_logo_id' ) );
		$game_id     = absint( $this->post( 'game_logo_id' ) );
		$channel_pos = $this->logo_pos( $this->post( 'channel_logo_pos' ), 'lower-left' );
		$game_pos    = $this->logo_pos( $this->post( 'game_logo_pos' ), 'upper-right' );
		$game_name   = sanitize_text_field( $this->post( 'game_name' ) );
		update_option( 'tw_channel_logo_id', $channel_id, false );
		update_option( 'tw_channel_logo_pos', $channel_pos, false );
		if ( $game_id && '' !== $game_name ) {
			update_post_meta( $game_id, '_tw_game_logo', '1' );
			update_post_meta( $game_id, '_tw_game_logo_name', $game_name );
			$map = get_option( 'tw_game_logos', array() );
			if ( ! is_array( $map ) ) {
				$map = array();
			}
			$map[ $this->game_logo_key( $game_name ) ] = $game_id;
			update_option( 'tw_game_logos', $map, false );
		}
		$this->save_state(
			$this->post( 'video' ),
			array(
				'game_logo_id'  => $game_id,
				'game_logo_pos' => $game_pos,
			)
		);
		wp_send_json_success();
	}

	public function ajax_thumb_logo_search() {
		$this->ajax_guard();
		$query = sanitize_text_field( $this->post( 'q' ) );
		if ( '' === $query ) {
			$query = sanitize_text_field( $this->post( 'game_name' ) );
		}
		$items = $this->search_game_logos( $query );
		wp_send_json_success( array( 'items' => $items, 'q' => $query ) );
	}

	/** Remembered logo for the current draft title, if any. */
	private function remembered_game_logo_id() {
		$pkg   = $this->pkg_from_post();
		$title = trim( (string) ( $pkg['title'] ?? '' ) );
		if ( '' === $title ) {
			$title = $this->post( 'current_title' );
		}
		$cut = preg_split( '/[:|\-–—]/u', $title );
		$name = trim( (string) ( $cut[0] ?? $title ) );
		if ( '' === $name ) {
			return 0;
		}
		$map = get_option( 'tw_game_logos', array() );
		if ( ! is_array( $map ) ) {
			return 0;
		}
		$key = $this->game_logo_key( $name );
		return absint( $map[ $key ] ?? 0 );
	}

	/** @return string */
	private function game_logo_key( $name ) {
		$key = strtolower( trim( (string) $name ) );
		$key = preg_replace( '/[^a-z0-9]+/', '-', $key );
		return trim( (string) $key, '-' );
	}

	/**
	 * Find official logo PNGs already in the Media Library.
	 *
	 * @param string $query
	 * @return array<int, array{id: int, url: string, thumb: string, title: string}>
	 */
	private function search_game_logos( $query ) {
		$query = trim( (string) $query );
		$items = array();
		$seen  = array();

		$map = get_option( 'tw_game_logos', array() );
		if ( is_array( $map ) && '' !== $query ) {
			$key = $this->game_logo_key( $query );
			if ( ! empty( $map[ $key ] ) ) {
				$payload = $this->logo_search_payload( (int) $map[ $key ] );
				if ( $payload ) {
					$items[]           = $payload;
					$seen[ $payload['id'] ] = true;
				}
			}
		}

		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => 12,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'post_mime_type' => 'image',
		);
		if ( '' !== $query ) {
			$args['s'] = $query;
		} else {
			$args['meta_key']   = '_tw_game_logo';
			$args['meta_value'] = '1';
		}
		$found = new WP_Query( $args );
		foreach ( $found->posts as $post ) {
			if ( isset( $seen[ $post->ID ] ) ) {
				continue;
			}
			$payload = $this->logo_search_payload( $post->ID );
			if ( ! $payload ) {
				continue;
			}
			$items[] = $payload;
			$seen[ $post->ID ] = true;
		}

		if ( '' !== $query && count( $items ) < 4 ) {
			$extra = new WP_Query(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'posts_per_page' => 8,
					'orderby'        => 'date',
					'order'          => 'DESC',
					'post_mime_type' => 'image',
					'meta_key'       => '_tw_game_logo',
					'meta_value'     => '1',
				)
			);
			foreach ( $extra->posts as $post ) {
				if ( isset( $seen[ $post->ID ] ) ) {
					continue;
				}
				$name = (string) get_post_meta( $post->ID, '_tw_game_logo_name', true );
				if ( '' !== $name && false === stripos( $name, $query ) && false === stripos( $query, $name ) ) {
					continue;
				}
				$payload = $this->logo_search_payload( $post->ID );
				if ( ! $payload ) {
					continue;
				}
				$items[] = $payload;
				$seen[ $post->ID ] = true;
			}
		}

		return array_slice( $items, 0, 12 );
	}

	/**
	 * @param int $id
	 * @return array{id: int, url: string, thumb: string, title: string}|null
	 */
	private function logo_search_payload( $id ) {
		$id = absint( $id );
		if ( ! $id ) {
			return null;
		}
		$url = (string) wp_get_attachment_url( $id );
		if ( '' === $url ) {
			return null;
		}
		$thumb = (string) wp_get_attachment_image_url( $id, 'medium' );
		$title = get_the_title( $id );
		$name  = (string) get_post_meta( $id, '_tw_game_logo_name', true );
		if ( '' !== $name ) {
			$title = $name;
		}
		return array(
			'id'    => $id,
			'url'   => $url,
			'thumb' => '' !== $thumb ? $thumb : $url,
			'title' => $title,
		);
	}

	public function ajax_thumb_ref_save() {
		$this->ajax_guard();
		if ( empty( $_FILES['image']['tmp_name'] ) ) {
			wp_send_json_error( 'No image was pasted.' );
		}
		$allowed = array( 'image/png', 'image/jpeg', 'image/jpg', 'image/webp', 'image/gif' );
		$type    = isset( $_FILES['image']['type'] ) ? strtolower( (string) $_FILES['image']['type'] ) : '';
		if ( ! in_array( $type, $allowed, true ) ) {
			wp_send_json_error( 'Use a PNG, JPEG, WebP or GIF.' );
		}
		$name = isset( $_FILES['image']['name'] ) ? (string) $_FILES['image']['name'] : '';
		if ( '' === $name || 'blob' === $name || 'image.png' === $name ) {
			$ext = 'jpg';
			if ( false !== strpos( $type, 'png' ) ) {
				$ext = 'png';
			} elseif ( false !== strpos( $type, 'webp' ) ) {
				$ext = 'webp';
			} elseif ( false !== strpos( $type, 'gif' ) ) {
				$ext = 'gif';
			}
			$_FILES['image']['name'] = 'tw-ref-' . gmdate( 'Ymd-His' ) . '.' . $ext;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$id = media_handle_upload( 'image', 0 );
		if ( is_wp_error( $id ) ) {
			wp_send_json_error( $id->get_error_message() );
		}
		update_post_meta( $id, '_tw_thumb_ref', '1' );
		$video = sanitize_text_field( $this->post( 'video' ) );
		if ( '' !== $video ) {
			update_post_meta( $id, '_tw_thumb_ref_video', $video );
		}
		wp_update_post(
			array(
				'ID'         => $id,
				'post_title' => 'TubeWriter reference ' . wp_date( 'Y-m-d g:i a' ),
			)
		);
		wp_send_json_success( $this->thumb_ref_payload( $id ) );
	}

	public function ajax_thumb_refs() {
		$this->ajax_guard();
		$query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 24,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'meta_key'       => '_tw_thumb_ref',
				'meta_value'     => '1',
			)
		);
		$items = array();
		foreach ( $query->posts as $post ) {
			$items[] = $this->thumb_ref_payload( $post->ID );
		}
		wp_send_json_success( array( 'items' => $items ) );
	}

	/**
	 * @param int $id Attachment id.
	 * @return array{id: int, url: string, thumb: string, edit: string, title: string}
	 */
	private function thumb_ref_payload( $id ) {
		$id    = (int) $id;
		$full  = (string) wp_get_attachment_image_url( $id, 'full' );
		$thumb = (string) wp_get_attachment_image_url( $id, 'medium' );
		return array(
			'id'    => $id,
			'url'   => $full,
			'thumb' => '' !== $thumb ? $thumb : $full,
			'edit'  => (string) get_edit_post_link( $id, 'raw' ),
			'title' => get_the_title( $id ),
		);
	}

	public function ajax_thumb_describe() {
		$this->ajax_guard();
		$input  = $this->input();
		$images = $this->images_from_post();
		if ( is_wp_error( $images ) ) {
			wp_send_json_error( $images->get_error_message() );
		}
		$out = TW_AI::describe_thumbnail( $input, $images, $this->pkg_from_post() );
		if ( is_wp_error( $out ) ) {
			wp_send_json_error( $out->get_error_message() );
		}
		$this->save_state(
			$input['video'],
			array(
				'transcript'        => $input['transcript'],
				'notes'             => $input['notes'],
				'seed_title'        => $input['current_title'],
				'seed_desc'         => $input['current_description'],
				'pkg'               => $this->pkg_from_post(),
				'thumb_description' => $out['description'],
			)
		);
		wp_send_json_success( $out );
	}

	public function ajax_thumb_generate() {
		$this->ajax_guard();
		$input       = $this->input();
		$description = $this->post( 'thumb_description', true );
		$images      = $this->images_from_post();
		if ( is_wp_error( $images ) ) {
			wp_send_json_error( $images->get_error_message() );
		}
		$logos = $this->logos_from_post( is_array( $images ) ? count( $images ) : 1 );
		if ( is_wp_error( $logos ) ) {
			wp_send_json_error( $logos->get_error_message() );
		}
		$image = TW_AI::generate_thumbnail( $description, $images, $logos );
		if ( is_wp_error( $image ) ) {
			wp_send_json_error( $image->get_error_message() );
		}

		$saved = $this->store_thumbnail( $input['video'], $image['data'], $image['mime'] );
		if ( is_wp_error( $saved ) ) {
			wp_send_json_error( $saved->get_error_message() );
		}

		$this->save_state(
			$input['video'],
			array(
				'thumb_description' => $description,
				'thumb_url'         => $saved['url'],
				'thumb_file'        => $saved['file'],
			)
		);

		wp_send_json_success(
			array(
				'url'         => $saved['url'],
				'mime'        => $image['mime'],
				'description' => $description,
			)
		);
	}

	public function ajax_thumb_upload() {
		$this->ajax_guard();
		$video_id = $this->post( 'video' );
		if ( ! $video_id ) {
			wp_send_json_error( 'Missing video id.' );
		}

		$key   = 'tw_state_' . preg_replace( '/[^A-Za-z0-9_-]/', '', $video_id );
		$state = get_option( $key, array() );
		$file  = is_array( $state ) ? (string) ( $state['thumb_file'] ?? '' ) : '';
		if ( '' === $file || ! is_readable( $file ) ) {
			wp_send_json_error( 'Create a thumbnail first, then upload it.' );
		}

		$uploads = wp_get_upload_dir();
		$basedir = wp_normalize_path( (string) ( $uploads['basedir'] ?? '' ) );
		$norm    = wp_normalize_path( $file );
		if ( ! $basedir || 0 !== strpos( $norm, $basedir ) ) {
			wp_send_json_error( 'Thumbnail file is not in the uploads folder.' );
		}

		$bin = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $bin || '' === $bin ) {
			wp_send_json_error( 'Could not read the generated thumbnail file.' );
		}

		$mime = 'image/png';
		if ( preg_match( '/\.jpe?g$/i', $file ) ) {
			$mime = 'image/jpeg';
		} elseif ( preg_match( '/\.webp$/i', $file ) ) {
			wp_send_json_error( 'YouTube needs PNG or JPEG. Create the thumbnail again so it saves as PNG.' );
		}

		$result = TW_YouTube::set_thumbnail( $video_id, $bin, $mime );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		$thumb_url = $result['items'][0]['high']['url']
			?? $result['items'][0]['medium']['url']
			?? $result['items'][0]['default']['url']
			?? (string) ( $state['thumb_url'] ?? '' );

		$this->save_state(
			$video_id,
			array(
				'thumb_uploaded' => time(),
				'thumb_yt_url'   => $thumb_url,
			)
		);

		TW_Index::refresh_one( $video_id );

		wp_send_json_success(
			array(
				'ok'      => true,
				'message' => 'Thumbnail uploaded to YouTube.',
				'url'     => $thumb_url,
				'quota'   => TW_YouTube::quota_used(),
			)
		);
	}

	public function ajax_playlists() {
		$this->ajax_guard();
		$list = TW_YouTube::playlists();
		if ( is_wp_error( $list ) ) {
			wp_send_json_error( $list->get_error_message() );
		}
		wp_send_json_success( $list );
	}

	public function ajax_playlist_suggest() {
		$this->ajax_guard();
		$pkg   = $this->pkg_from_post();
		$check = TW_Validator::check( $pkg, 0 );
		$out   = TW_AI::playlist(
			$this->post( 'rough' ),
			array( 'title' => $check['pkg']['title'], 'description' => $check['description'] )
		);
		if ( is_wp_error( $out ) ) {
			wp_send_json_error( $out->get_error_message() );
		}
		wp_send_json_success( $out );
	}

	public function ajax_calendar() {
		$this->ajax_guard();
		if ( '1' === $this->post( 'force' ) ) {
			$sync = TW_Index::sync_batch( 'quick' );
			if ( is_wp_error( $sync ) ) {
				wp_send_json_error( $sync->get_error_message() );
			}
		}
		$out = array();
		foreach ( TW_YouTube::calendar_events() as $e ) {
			$ts    = strtotime( $e['at'] );
			$out[] = array(
				'id'    => $e['id'],
				'title' => $e['title'],
				'type'  => $e['type'],
				'date'  => wp_date( 'Y-m-d', $ts ),
				'time'  => wp_date( 'g:ia', $ts ),
			);
		}
		wp_send_json_success( $out );
	}

	public function ajax_sync() {
		$this->ajax_guard();
		$raw  = $this->post( 'mode' );
		$mode = 'full' === $raw ? 'full' : ( 'drafts' === $raw ? 'drafts' : 'quick' );
		$result = TW_Index::sync_batch( $mode, $this->post( 'token' ), $this->post( 'run' ) );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}
		$result['indexed'] = TW_Index::count_all();
		$result['quota']   = TW_YouTube::quota_used();
		wp_send_json_success( $result );
	}

	public function ajax_review() {
		$this->ajax_guard();
		$input = $this->input();
		$video = TW_YouTube::get_video( $input['video'] );
		if ( is_wp_error( $video ) ) {
			wp_send_json_error( $video->get_error_message() );
		}
		$plan = TW_Plan::build( $video, $this->pkg_from_post(), $this->opts_from_post(), $input['duration'] );
		unset( $plan['details'], $plan['current'] );
		wp_send_json_success( $plan );
	}

	public function ajax_publish() {
		$this->ajax_guard();
		$input  = $this->input();
		$result = TW_Plan::publish( $input['video'], $this->pkg_from_post(), $this->opts_from_post(), $input['duration'], $this->post( 'fingerprint' ) );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}
		$this->save_state(
			$input['video'],
			array(
				'transcript' => $input['transcript'],
				'notes'      => $input['notes'],
				'seed_title' => $input['current_title'],
				'seed_desc'  => $input['current_description'],
				'pkg'        => $this->pkg_from_post(),
				'opts'       => $this->opts_from_post(),
				'pushed'     => time(),
			)
		);
		TW_Index::refresh_one( $input['video'] );
		TW_Index::set_tw_status( $input['video'], 'pushed' );
		$result['history'] = TW_History::summary( $input['video'] );
		wp_send_json_success( $result );
	}

	public function ajax_restore() {
		$this->ajax_guard();
		$video  = $this->post( 'video' );
		$result = TW_Plan::restore( $video, $this->post( 'entry' ) );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}
		TW_Index::refresh_one( $video );
		$result['history'] = TW_History::summary( $video );
		wp_send_json_success( $result );
	}


	/* ---------------------------------------------------------------- Helpers */

	private function respond_with_package( array $input, $pkg ) {
		if ( is_wp_error( $pkg ) ) {
			wp_send_json_error( $pkg->get_error_message() );
		}
		$check = TW_Validator::check( $pkg, $input['duration'] );
		// Keep the AI's title options even though check() only returns the chosen one cleaned.
		$check['pkg']['titles'] = array_map( array( 'TW_Validator', 'clean_text' ), $pkg['titles'] );
		$this->save_state( $input['video'], array( 'transcript' => $input['transcript'], 'notes' => $input['notes'], 'seed_title' => $input['current_title'], 'seed_desc' => $input['current_description'], 'pkg' => $check['pkg'] ) );
		wp_send_json_success( $check );
	}

	private function input() {
		$transcript = $this->post( 'transcript', true );
		$duration   = (int) $this->post( 'duration' );
		return array(
			'video'          => $this->post( 'video' ),
			'transcript'     => TW_Transcript::normalize( $transcript ),
			'notes'          => $this->post( 'notes', true ),
			'duration'       => $duration,
			'duration_label' => $duration ? TW_Transcript::fmt( $duration ) : '',
			'current_title'       => $this->post( 'current_title' ),
			'current_description' => $this->post( 'current_description', true ),
		);
	}

	private function pkg_from_post() {
		$raw = json_decode( wp_unslash( $_POST['pkg'] ?? '' ), true ); // phpcs:ignore
		if ( ! is_array( $raw ) ) {
			return array( 'titles' => array(), 'title' => '', 'body' => '', 'chapters_heading' => '', 'chapters' => array(), 'tags' => array(), 'hashtags' => array() );
		}
		$chapters = $raw['chapters'] ?? array();
		if ( is_string( $chapters ) ) {
			$chapters = TW_Validator::chapters_from_text( $chapters );
		}
		$tags = $raw['tags'] ?? array();
		if ( is_string( $tags ) ) {
			$tags = explode( ',', $tags );
		}
		$hashtags = $raw['hashtags'] ?? array();
		if ( is_string( $hashtags ) ) {
			$hashtags = preg_split( '/\s+/', trim( $hashtags ) );
		}
		return array(
			'titles'           => array_values( array_map( 'strval', (array) ( $raw['titles'] ?? array() ) ) ),
			'title'            => (string) ( $raw['title'] ?? '' ),
			'body'             => (string) ( $raw['body'] ?? '' ),
			'chapters_heading' => (string) ( $raw['chapters_heading'] ?? '' ),
			'chapters'         => array_values( (array) $chapters ),
			'tags'             => array_values( array_map( 'trim', array_map( 'strval', (array) $tags ) ) ),
			'hashtags'         => array_values( array_filter( array_map( 'strval', (array) $hashtags ) ) ),
		);
	}

	/** Language, category, playlist and schedule choices from the editor. */
	private function opts_from_post() {
		$raw = json_decode( wp_unslash( $_POST['opts'] ?? '' ), true ); // phpcs:ignore
		$raw = is_array( $raw ) ? $raw : array();
		$pl  = (array) ( $raw['playlist'] ?? array() );
		$sc  = (array) ( $raw['schedule'] ?? array() );
		return array(
			'language' => sanitize_text_field( $raw['language'] ?? TW_Settings::get( 'default_language' ) ),
			'category' => sanitize_text_field( $raw['category'] ?? TW_Settings::get( 'default_category' ) ),
			'playlist' => array(
				'mode'      => in_array( $pl['mode'] ?? '', array( 'none', 'existing', 'new' ), true ) ? $pl['mode'] : 'none',
				'id'        => sanitize_text_field( $pl['id'] ?? '' ),
				'title'     => sanitize_text_field( $pl['title'] ?? '' ),
				'rough'     => sanitize_text_field( $pl['rough'] ?? '' ),
				'new_title' => sanitize_text_field( $pl['new_title'] ?? '' ),
				'new_desc'  => sanitize_textarea_field( $pl['new_desc'] ?? '' ),
				'privacy'   => sanitize_text_field( $pl['privacy'] ?? 'public' ),
			),
			'schedule' => array(
				'mode' => 'set' === ( $sc['mode'] ?? '' ) ? 'set' : 'keep',
				'date' => preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $sc['date'] ?? '' ) ) ? $sc['date'] : '',
				'time' => preg_match( '/^\d{2}:\d{2}$/', (string) ( $sc['time'] ?? '' ) ) ? $sc['time'] : '',
			),
		);
	}

	private function save_state( $video_id, array $data ) {
		if ( ! $video_id ) {
			return;
		}
		$key   = 'tw_state_' . preg_replace( '/[^A-Za-z0-9_-]/', '', $video_id );
		$state = get_option( $key, array() );
		$state = array_merge( is_array( $state ) ? $state : array(), $data );
		update_option( $key, $state, false );
		TW_Index::set_tw_status( $video_id, ! empty( $state['pushed'] ) ? 'pushed' : ( ! empty( $state['pkg'] ) ? 'draft' : 'started' ) );
	}

	private function post( $key, $multiline = false ) {
		$value = wp_unslash( $_POST[ $key ] ?? '' ); // phpcs:ignore
		return $multiline ? wp_check_invalid_utf8( (string) $value ) : sanitize_text_field( $value );
	}

	/**
	 * Decode up to two pasted screenshots from the editor.
	 *
	 * @return array|WP_Error
	 */
	/**
	 * @param int    $id
	 * @param string $pos
	 * @param string $default
	 * @return array{id: int, url: string, pos: string}
	 */
	private function logo_boot( $id, $pos, $default ) {
		$id  = absint( $id );
		$pos = $this->logo_pos( $pos, $default );
		$url = $id ? (string) wp_get_attachment_url( $id ) : '';
		if ( $id && '' === $url ) {
			$id = 0;
		}
		return array(
			'id'  => $id,
			'url' => $url,
			'pos' => $pos,
		);
	}

	/** @return string */
	private function logo_pos( $pos, $default = 'none' ) {
		$pos = sanitize_key( (string) $pos );
		$ok  = array( 'upper-left', 'upper-right', 'lower-left', 'lower-right', 'none' );
		return in_array( $pos, $ok, true ) ? $pos : $default;
	}

	/**
	 * Game and channel logo files chosen on the thumbnail step.
	 *
	 * @return array<int, array{mime: string, data: string, label: string, pos: string}>|WP_Error
	 */
	private function logos_from_post( $shot_count = 1 ) {
		$specs = array(
			array( 'game_logo_id', 'game_logo_pos', 'official game logo', 'upper-right' ),
			array( 'channel_logo_id', 'channel_logo_pos', 'GG channel logo', 'lower-left' ),
		);
		$out = array();
		foreach ( $specs as $spec ) {
			$id  = absint( $this->post( $spec[0] ) );
			$pos = $this->logo_pos( $this->post( $spec[1] ), $spec[3] );
			if ( 'official game logo' === $spec[2] && ! $id ) {
				$id = $this->remembered_game_logo_id();
			}
			if ( 'official game logo' === $spec[2] && (int) $shot_count > 1 && 'upper-right' === $pos ) {
				$pos = 'lower-right';
			}
			if ( ! $id || 'none' === $pos ) {
				continue;
			}
			$path = get_attached_file( $id );
			if ( ! $path || ! is_readable( $path ) ) {
				return new WP_Error( 'tw_ai', 'Could not read the ' . $spec[2] . ' from the Media Library. Choose it again.' );
			}
			$bin = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( false === $bin || '' === $bin ) {
				return new WP_Error( 'tw_ai', 'The ' . $spec[2] . ' file was empty.' );
			}
			$mime = (string) get_post_mime_type( $id );
			if ( '' === $mime ) {
				$mime = 'image/png';
			}
			$out[] = array(
				'mime'  => $mime,
				'data'  => base64_encode( $bin ),
				'label' => $spec[2],
				'pos'   => str_replace( '-', ' ', $pos ),
			);
		}
		return $out;
	}

	private function images_from_post() {
		$raw = json_decode( wp_unslash( $_POST['images'] ?? '' ), true ); // phpcs:ignore
		if ( ! is_array( $raw ) ) {
			return new WP_Error( 'tw_ai', 'Paste at least one screenshot first.' );
		}
		return $raw;
	}

	/**
	 * Save a generated thumbnail under uploads/tubewriter/.
	 *
	 * @param string $video_id
	 * @param string $base64
	 * @param string $mime
	 * @return array{url: string, file: string}|WP_Error
	 */
	private function store_thumbnail( $video_id, $base64, $mime ) {
		$bin = base64_decode( (string) $base64, true );
		if ( false === $bin || '' === $bin ) {
			return new WP_Error( 'tw_ai', 'Could not decode the generated image.' );
		}
		$ext = 'png';
		if ( false !== strpos( $mime, 'jpeg' ) || false !== strpos( $mime, 'jpg' ) ) {
			$ext = 'jpg';
		} elseif ( false !== strpos( $mime, 'webp' ) ) {
			$ext = 'webp';
		}
		$safe = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $video_id );
		$name = 'tw-thumb-' . ( $safe ?: 'video' ) . '-' . gmdate( 'Ymd-His' ) . '.' . $ext;

		$upload = wp_upload_bits( $name, null, $bin );
		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error( 'tw_ai', 'Could not save the thumbnail: ' . $upload['error'] );
		}
		return array(
			'url'  => $upload['url'],
			'file' => $upload['file'],
		);
	}

	/** Gemini or ChatGPT, from the editor dropdown. */
	private function text_provider() {
		return TW_AI::text_provider( $this->post( 'provider' ) );
	}

	/** Google sign-in steps. The paste box is how a Desktop OAuth client finishes. */
	private function reconnect_steps( $button_label ) {
		?>
		<ol class="tw-steps">
			<li>
				<a class="button button-primary" target="_blank" rel="noopener" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=tw_connect' ), 'tw_connect' ) ); ?>"><?php echo esc_html( $button_label ); ?></a>
				opens Google in a new tab. Pick the account that owns the channel and allow access.
				If Google says the app is not verified, click <strong>Advanced</strong>, then <strong>Go to TubeWriter (unsafe)</strong>.
			</li>
			<li>Google then opens a <strong>This site can't be reached</strong> page at 127.0.0.1. That is expected. Copy the entire address from the address bar.</li>
			<li>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tw-inline-form">
					<?php wp_nonce_field( 'tw_complete' ); ?>
					<input type="hidden" name="action" value="tw_complete">
					<input type="text" name="tw_pasted" class="large-text code" placeholder="http://127.0.0.1:8765/?state=...&amp;code=..." required>
					<button class="button button-primary">Finish connecting</button>
				</form>
			</li>
		</ol>
		<p class="description">This signs TubeWriter into YouTube. It does not fix a busy Gemini or ChatGPT model. A Google Cloud project left in <strong>Testing</strong> makes you do this about every 7 days. To stop that, open Google Auth Platform, go to Audience, and click <strong>Publish app</strong>.</p>
		<?php
	}

	private function ajax_guard() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( 'Not allowed.', 403 );
		}
		check_ajax_referer( 'tw_ajax', 'nonce' );
	}

	private function guard( $action, $method = 'POST' ) {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( $action ); // Works for both the GET connect link and POST forms.
	}

	private function back( $page, $message, $type = 'success' ) {
		set_transient( 'tw_notice_' . get_current_user_id(), array( 'message' => $message, 'type' => $type ), 60 );
		$slug = 'settings' === $page ? 'tubewriter-settings' : 'tubewriter';
		wp_safe_redirect( admin_url( 'admin.php?page=' . $slug ) );
		exit;
	}

	private function notice() {
		$key    = 'tw_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( $notice ) {
			delete_transient( $key );
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $notice['type'] ), esc_html( $notice['message'] ) );
		}
	}
}
