<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TW_Admin {

	const CAP = 'manage_options';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );

		add_action( 'admin_post_tw_save_settings', array( $this, 'save_settings' ) );
		add_action( 'admin_post_tw_connect', array( $this, 'connect' ) );
		add_action( 'admin_post_tw_complete', array( $this, 'complete' ) );
		add_action( 'admin_post_tw_disconnect', array( $this, 'disconnect' ) );
		add_action( 'admin_post_tw_reset_prompt', array( $this, 'reset_prompt' ) );

		foreach ( array( 'captions', 'generate', 'revise', 'check', 'push', 'save' ) as $action ) {
			add_action( 'wp_ajax_tw_' . $action, array( $this, 'ajax_' . $action ) );
		}
	}

	public function menu() {
		add_menu_page( 'TubeWriter', 'TubeWriter', self::CAP, 'tubewriter', array( $this, 'page_videos' ), 'dashicons-video-alt3', 26 );
		add_submenu_page( 'tubewriter', 'Videos', 'Videos', self::CAP, 'tubewriter', array( $this, 'page_videos' ) );
		add_submenu_page( 'tubewriter', 'TubeWriter Settings', 'Settings', self::CAP, 'tubewriter-settings', array( $this, 'page_settings' ) );
	}

	public function assets( $hook ) {
		if ( false === strpos( (string) $hook, 'tubewriter' ) ) {
			return;
		}
		wp_enqueue_style( 'tubewriter', TW_URL . 'assets/admin.css', array(), TW_VERSION );
		wp_enqueue_script( 'tubewriter', TW_URL . 'assets/admin.js', array(), TW_VERSION, true );
		wp_localize_script(
			'tubewriter',
			'TW',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'tw_ajax' ),
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
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'tw_disconnect' ); ?>
						<input type="hidden" name="action" value="tw_disconnect">
						<button class="button">Disconnect</button>
					</form>
				<?php elseif ( ! $configured ) : ?>
					<p>Save your Google Client ID and Client Secret below first.</p>
				<?php else : ?>
					<ol class="tw-steps">
						<li>
							<a class="button button-primary" target="_blank" rel="noopener" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=tw_connect' ), 'tw_connect' ) ); ?>">Connect YouTube</a>
							opens Google sign-in in a new tab. Pick the account that owns your channel and allow access.
						</li>
						<li>Google then sends you to a <strong>"This site can't be reached"</strong> page at 127.0.0.1. That is expected. Copy the whole address from the address bar.</li>
						<li>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tw-inline-form">
								<?php wp_nonce_field( 'tw_complete' ); ?>
								<input type="hidden" name="action" value="tw_complete">
								<input type="text" name="tw_pasted" class="large-text code" placeholder="http://127.0.0.1:8765/?state=...&code=..." required>
								<button class="button button-primary">Finish connecting</button>
							</form>
						</li>
					</ol>
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
								<p class="description">Default <code><?php echo esc_html( TW_DEFAULT_MODEL ); ?></code> (free tier). Other free options: <code>gemini-3.6-flash</code>, <code>gemini-2.5-flash</code>.</p>
							</td>
						</tr>
					</table>
				</div>

				<div class="tw-card">
					<h2>3. Channel voice prompt</h2>
					<p class="description">This is what the AI follows for tone, the warning text, chapter style, tags and hashtags. The JSON output rules are added automatically, so you only describe the voice and content.</p>
					<textarea name="tw_prompt" rows="28" class="large-text code tw-prompt"><?php echo esc_textarea( TW_Settings::get( 'prompt' ) ); ?></textarea>
				</div>

				<p class="description">Schedules use your WordPress timezone: <strong><?php echo esc_html( $tz ); ?></strong>. Change it under <a href="<?php echo esc_url( admin_url( 'options-general.php' ) ); ?>">Settings &gt; General</a>.</p>
				<?php submit_button( 'Save settings' ); ?>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Replace your prompt with the default GG prompt?');">
				<?php wp_nonce_field( 'tw_reset_prompt' ); ?>
				<input type="hidden" name="action" value="tw_reset_prompt">
				<button class="button-link tw-danger-link">Reset prompt to default</button>
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

		echo '<div class="wrap tw-wrap"><h1>TubeWriter</h1>';
		$this->notice();

		if ( ! TW_Settings::is_configured() || ! TW_OAuth::is_connected() ) {
			echo '<div class="tw-card"><p>Finish setup first: add your credentials and connect YouTube in <a href="' . esc_url( admin_url( 'admin.php?page=tubewriter-settings' ) ) . '">Settings</a>.</p></div></div>';
			return;
		}

		$token = isset( $_GET['pt'] ) ? sanitize_text_field( wp_unslash( $_GET['pt'] ) ) : ''; // phpcs:ignore
		$data  = TW_YouTube::list_videos( $token );
		if ( is_wp_error( $data ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $data->get_error_message() ) . '</p></div></div>';
			return;
		}

		echo '<table class="widefat striped tw-videos"><thead><tr><th></th><th>Title</th><th>Status</th><th>Length</th><th>TubeWriter</th><th></th></tr></thead><tbody>';
		foreach ( $data['videos'] as $v ) {
			$thumb    = $v['snippet']['thumbnails']['default']['url'] ?? '';
			$status   = $v['status']['privacyStatus'] ?? '';
			$upload   = $v['status']['uploadStatus'] ?? '';
			$sched    = $v['status']['publishAt'] ?? '';
			$secs     = TW_Transcript::duration_seconds( $v['contentDetails']['duration'] ?? '' );
			$state    = get_option( 'tw_state_' . $v['id'] );
			$tw_label = '';
			if ( is_array( $state ) ) {
				$tw_label = ! empty( $state['pushed'] ) ? 'Pushed ' . esc_html( wp_date( 'M j', $state['pushed'] ) ) : ( ! empty( $state['pkg'] ) ? 'Draft ready' : 'Started' );
			}
			$badge = $status;
			if ( $sched ) {
				$badge = 'scheduled ' . wp_date( 'M j g:ia', strtotime( $sched ) );
			}
			if ( 'processed' !== $upload && 'uploaded' !== $upload && $upload ) {
				$badge .= ' (' . $upload . ')';
			}
			$link = admin_url( 'admin.php?page=tubewriter&video=' . rawurlencode( $v['id'] ) );
			printf(
				'<tr><td>%s</td><td><a href="%s"><strong>%s</strong></a></td><td><span class="tw-badge tw-badge-%s">%s</span></td><td>%s</td><td>%s</td><td><a class="button button-primary" href="%s">Write</a></td></tr>',
				$thumb ? '<img src="' . esc_url( $thumb ) . '" width="96" alt="">' : '',
				esc_url( $link ),
				esc_html( $v['snippet']['title'] ?? $v['id'] ),
				esc_attr( $status ),
				esc_html( $badge ),
				esc_html( $secs ? TW_Transcript::fmt( $secs ) : '' ),
				esc_html( $tw_label ),
				esc_url( $link )
			);
		}
		echo '</tbody></table><p class="tw-pager">';
		if ( $data['prev'] ) {
			echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=tubewriter&pt=' . rawurlencode( $data['prev'] ) ) ) . '">&larr; Newer</a> ';
		}
		if ( $data['next'] ) {
			echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=tubewriter&pt=' . rawurlencode( $data['next'] ) ) ) . '">Older &rarr;</a>';
		}
		echo '</p></div>';
	}

	private function page_editor( $video_id ) {
		echo '<div class="wrap tw-wrap">';
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=tubewriter' ) ) . '">&larr; All videos</a></p>';

		$video = TW_YouTube::get_video( $video_id );
		if ( is_wp_error( $video ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $video->get_error_message() ) . '</p></div></div>';
			return;
		}

		$state    = get_option( 'tw_state_' . $video_id, array() );
		$secs     = TW_Transcript::duration_seconds( $video['contentDetails']['duration'] ?? '' );
		$status   = $video['status']['privacyStatus'] ?? '';
		$sched    = $video['status']['publishAt'] ?? '';
		$thumb    = $video['snippet']['thumbnails']['medium']['url'] ?? '';
		$boot     = array(
			'videoId'      => $video_id,
			'duration'     => $secs,
			'currentTitle' => $video['snippet']['title'] ?? '',
			'currentDesc'  => $video['snippet']['description'] ?? '',
			'privacy'      => $status,
			'publishAt'    => $sched ? wp_date( 'Y-m-d\TH:i', strtotime( $sched ) ) : '',
			'state'        => is_array( $state ) ? $state : array(),
			'studioUrl'    => 'https://studio.youtube.com/video/' . rawurlencode( $video_id ) . '/edit',
		);
		?>
		<div class="tw-editor" id="tw-editor" data-boot="<?php echo esc_attr( wp_json_encode( $boot ) ); ?>">
			<div class="tw-head">
				<?php if ( $thumb ) : ?><img src="<?php echo esc_url( $thumb ); ?>" alt=""><?php endif; ?>
				<div>
					<h1><?php echo esc_html( $boot['currentTitle'] ); ?></h1>
					<p>
						<span class="tw-badge tw-badge-<?php echo esc_attr( $status ); ?>"><?php echo esc_html( $status ); ?></span>
						<?php echo $secs ? esc_html( TW_Transcript::fmt( $secs ) ) : ''; ?>
						&middot; <a href="<?php echo esc_url( $boot['studioUrl'] ); ?>" target="_blank" rel="noopener">Open in Studio</a>
					</p>
				</div>
			</div>

			<div id="tw-msg" class="tw-msg" hidden></div>

			<div class="tw-cols">
				<section class="tw-card">
					<h2>Input</h2>
					<div class="tw-row">
						<label for="tw-transcript">Transcript</label>
						<button type="button" class="button" id="tw-pull">Pull YouTube captions</button>
					</div>
					<textarea id="tw-transcript" rows="16" class="large-text code" placeholder="Pull captions, or paste a transcript (plain text with timestamps, SRT or VTT all work)."></textarea>
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
					<p class="description">Filled in from YouTube. Overwrite them with a quick idea when there's no transcript. Nothing changes on YouTube until you push.</p>

					<label for="tw-notes">Notes for the AI</label>
					<textarea id="tw-notes" rows="5" class="large-text" placeholder="Key moments with rough times, one per line (2:30 truck dies). Anything the AI should know or get right. The AI trusts these over everything else."></textarea>

					<p><button type="button" class="button button-primary button-hero" id="tw-generate">Generate</button></p>
				</section>

				<section class="tw-card" id="tw-output" hidden>
					<h2>Output</h2>

					<label>Title</label>
					<div id="tw-titles" class="tw-titles"></div>
					<input type="text" id="tw-title" class="large-text">
					<p class="tw-count" data-for="tw-title" data-max="100"></p>

					<label for="tw-body">Description</label>
					<textarea id="tw-body" rows="10" class="large-text"></textarea>

					<label for="tw-chapters-heading">Chapters heading</label>
					<input type="text" id="tw-chapters-heading" class="large-text">

					<label for="tw-chapters">Chapters (one per line, first must be 0:00)</label>
					<textarea id="tw-chapters" rows="10" class="large-text code"></textarea>

					<label for="tw-tags">Tags (comma separated)</label>
					<textarea id="tw-tags" rows="3" class="large-text"></textarea>
					<p class="tw-count" data-for="tw-tags" data-max="500"></p>

					<label for="tw-hashtags">Hashtags (space separated)</label>
					<input type="text" id="tw-hashtags" class="large-text">

					<details class="tw-preview">
						<summary>Full description preview</summary>
						<pre id="tw-preview"></pre>
						<p class="tw-count" id="tw-desc-count"></p>
					</details>

					<div class="tw-tweak">
						<label for="tw-tweak">Tweak it</label>
						<textarea id="tw-tweak" rows="2" class="large-text" placeholder="e.g. make title 3 meaner, or the supply truck fail was Mike, not me"></textarea>
						<button type="button" class="button" id="tw-revise">Apply change</button>
					</div>

					<div class="tw-schedule">
						<label><input type="checkbox" id="tw-schedule-on"> Schedule publish</label>
						<input type="datetime-local" id="tw-schedule-at">
						<span class="description"><?php echo esc_html( wp_timezone_string() ); ?> time. Private, never-published videos only.</span>
					</div>

					<p class="tw-actions">
						<button type="button" class="button" id="tw-save">Save draft</button>
						<button type="button" class="button button-primary button-hero" id="tw-push">Push to YouTube</button>
					</p>
				</section>
			</div>
		</div>
		</div>
		<?php
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
		$pkg = TW_AI::generate( $input );
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
		$pkg     = TW_AI::revise( $input, $current, $instruction );
		$this->respond_with_package( $input, $pkg );
	}

	public function ajax_check() {
		$this->ajax_guard();
		$check = TW_Validator::check( $this->pkg_from_post(), (int) $this->post( 'duration' ) );
		wp_send_json_success( $check );
	}

	public function ajax_save() {
		$this->ajax_guard();
		$input = $this->input();
		$this->save_state( $input['video'], array( 'transcript' => $input['transcript'], 'notes' => $input['notes'], 'seed_title' => $input['current_title'], 'seed_desc' => $input['current_description'], 'pkg' => $this->pkg_from_post() ) );
		wp_send_json_success( 'Draft saved.' );
	}

	public function ajax_push() {
		$this->ajax_guard();
		$input = $this->input();
		$check = TW_Validator::check( $this->pkg_from_post(), $input['duration'] );
		if ( $check['errors'] ) {
			wp_send_json_error( implode( ' ', $check['errors'] ) );
		}

		$publish_at = null;
		$when       = $this->post( 'publish_at' );
		if ( $when ) {
			try {
				$dt = new DateTime( $when, wp_timezone() );
			} catch ( Exception $e ) {
				wp_send_json_error( 'That schedule date is not valid.' );
			}
			if ( $dt->getTimestamp() <= time() + 60 ) {
				wp_send_json_error( 'The schedule time has to be in the future.' );
			}
			$dt->setTimezone( new DateTimeZone( 'UTC' ) );
			$publish_at = $dt->format( 'Y-m-d\TH:i:s\Z' );
		}

		$pkg    = $check['pkg'];
		$result = TW_YouTube::update_video(
			$input['video'],
			array(
				'title'       => $pkg['title'],
				'description' => $check['description'],
				'tags'        => $pkg['tags'],
			),
			$publish_at
		);
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		$this->save_state(
			$input['video'],
			array( 'transcript' => $input['transcript'], 'notes' => $input['notes'], 'seed_title' => $input['current_title'], 'seed_desc' => $input['current_description'], 'pkg' => $pkg, 'pushed' => time() )
		);
		wp_send_json_success(
			array(
				'message'  => $publish_at ? 'Pushed and scheduled.' : 'Pushed to YouTube.',
				'pkg'      => $pkg,
				'warnings' => $check['warnings'],
			)
		);
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

	private function save_state( $video_id, array $data ) {
		if ( ! $video_id ) {
			return;
		}
		$key   = 'tw_state_' . preg_replace( '/[^A-Za-z0-9_-]/', '', $video_id );
		$state = get_option( $key, array() );
		$state = array_merge( is_array( $state ) ? $state : array(), $data );
		update_option( $key, $state, false );
	}

	private function post( $key, $multiline = false ) {
		$value = wp_unslash( $_POST[ $key ] ?? '' ); // phpcs:ignore
		return $multiline ? wp_check_invalid_utf8( (string) $value ) : sanitize_text_field( $value );
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
