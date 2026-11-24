<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TW_Admin {

	const CAP = 'manage_options';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( 'TW_Index', 'maybe_install' ) );

		add_action( 'admin_post_tw_save_settings', array( $this, 'save_settings' ) );
		add_action( 'show_user_profile', array( $this, 'user_thumb_ai_field' ) );
		add_action( 'edit_user_profile', array( $this, 'user_thumb_ai_field' ) );
		add_action( 'personal_options_update', array( $this, 'save_user_thumb_ai_field' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_user_thumb_ai_field' ) );

		foreach ( array( 'captions', 'generate', 'revise', 'save', 'playlists', 'playlist_suggest', 'calendar', 'calendar_workspace', 'review', 'publish', 'restore', 'refresh', 'sync', 'privacy', 'thumb_describe', 'thumb_generate', 'thumb_upload', 'thumb_set', 'thumb_clear', 'thumb_ref_save', 'thumb_refs', 'thumb_ref_delete', 'thumb_library', 'thumb_library_delete', 'thumb_logos_save', 'thumb_logo_search' ) as $action ) {
			add_action( 'wp_ajax_tw_' . $action, array( $this, 'ajax_' . $action ) );
		}
	}

	public function menu() {
		// wp-admin: app keys / platform gates only. Editor, videos, identity live on /tubewriter/.
		add_menu_page( 'UploadWriter', 'UploadWriter', self::CAP, 'tubewriter', array( $this, 'page_settings' ), 'dashicons-video-alt3', 26 );
		add_submenu_page( 'tubewriter', 'UploadWriter Settings', 'Settings', self::CAP, 'tubewriter', array( $this, 'page_settings' ) );
		// Hidden alias so old Settings bookmarks still open.
		add_submenu_page( null, 'UploadWriter Settings', 'Settings', self::CAP, 'tubewriter-settings', array( $this, 'page_settings' ) );
	}

	/* ---------------------------------------------------------------- Settings */

	public function page_settings() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		// Old admin editor deep links → front-end app.
		$video_id = isset( $_GET['video'] ) ? sanitize_text_field( wp_unslash( $_GET['video'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $video_id && class_exists( 'TW_Frontend' ) ) {
			wp_safe_redirect( TW_Frontend::url( 'account' ) . '?video=' . rawurlencode( $video_id ) );
			exit;
		}

		$account_url = class_exists( 'TW_Frontend' ) ? TW_Frontend::url( 'account' ) : home_url( '/tubewriter/account/' );
		$tz          = TW_Settings::timezone_string();
		$site_tz     = (string) get_option( 'tw_timezone', '' );
		if ( ! TW_Settings::is_valid_timezone( $site_tz ) ) {
			$site_tz = wp_timezone_string();
		}
		?>
		<div class="wrap tw-wrap">
			<h1>UploadWriter Settings</h1>
			<?php $this->notice(); ?>

			<div class="notice notice-info inline">
				<p>
					Videos, channel identity, and YouTube connect live on the front-end app:
					<a href="<?php echo esc_url( $account_url ); ?>"><?php echo esc_html( $account_url ); ?></a>.
					This screen is for site app keys and access gates only.
					Phase 1 quota, beta, and waitlist numbers live under
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=tubewriter-launch' ) ); ?>">Launch Benchmarks</a>.
				</p>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'tw_save_settings' ); ?>
				<input type="hidden" name="action" value="tw_save_settings">

				<div class="tw-card">
					<h2>1. App credentials</h2>
					<p class="description">Google Cloud <strong>Web application</strong> OAuth client for every UploadWriter account. Authorized redirect URI must be exactly <code><?php echo esc_html( home_url( '/tubewriter/oauth/' ) ); ?></code> (trailing slash). Prefer <code>wp-config.php</code> constants over the database.</p>
					<?php if ( TW_Settings::google_credentials_from_config() ) : ?>
						<div class="notice notice-success inline"><p>Google Client ID and Secret are loaded from <code>wp-config.php</code> constants. The fields below are ignored while those constants are set.</p></div>
					<?php endif; ?>
					<table class="form-table">
						<tr>
							<th><label for="tw_client_id">Google Client ID</label></th>
							<td>
								<input type="text" id="tw_client_id" name="tw_client_id" class="large-text code" value="<?php echo esc_attr( TW_Settings::google_credentials_from_config() ? '' : TW_Store::get( 'client_id', '' ) ); ?>" <?php disabled( TW_Settings::google_credentials_from_config() ); ?> placeholder="<?php echo TW_Settings::google_credentials_from_config() ? 'Set via UPLOADWRITER_GOOGLE_CLIENT_ID' : ''; ?>">
							</td>
						</tr>
						<tr>
							<th><label for="tw_client_secret">Google Client Secret</label></th>
							<td><input type="password" id="tw_client_secret" name="tw_client_secret" class="regular-text code" autocomplete="off" value="" <?php disabled( TW_Settings::google_credentials_from_config() ); ?> placeholder="<?php echo esc_attr( TW_Settings::google_credentials_from_config() ? 'Set via UPLOADWRITER_GOOGLE_CLIENT_SECRET' : TW_Store::secret_placeholder( 'client_secret' ) ); ?>"></td>
						</tr>
						<tr>
							<th><label for="tw_gemini_key">Default Gemini API key</label></th>
							<td>
								<input type="password" id="tw_gemini_key" name="tw_gemini_key" class="regular-text code" autocomplete="off" value="" placeholder="<?php echo esc_attr( TW_Store::secret_placeholder( 'gemini_key' ) ); ?>">
								<p class="description">Site fallback when a user has neither their own key nor an assigned key on Users → Edit. Front-end users can still override under Account → Keys &amp; voice.</p>
							</td>
						</tr>
						<tr>
							<th><label for="tw_model">Gemini model</label></th>
							<td>
								<select id="tw_model" name="tw_model">
									<?php TW_Settings::render_model_options( 'gemini', TW_Settings::get( 'model' ) ); ?>
								</select>
							</td>
						</tr>
						<tr>
							<th><label for="tw_openai_key">Default OpenAI API key</label></th>
							<td>
								<input type="password" id="tw_openai_key" name="tw_openai_key" class="regular-text code" autocomplete="off" value="" placeholder="<?php echo esc_attr( TW_Store::secret_placeholder( 'openai_key' ) ); ?>">
								<p class="description">Site fallback when a user has neither their own key nor an assigned key on Users → Edit. From <a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener">platform.openai.com</a>.</p>
							</td>
						</tr>
						<tr>
							<th><label for="tw_openai_model">OpenAI text model</label></th>
							<td>
								<select id="tw_openai_model" name="tw_openai_model">
									<?php TW_Settings::render_model_options( 'openai', TW_Settings::get( 'openai_model' ) ); ?>
								</select>
							</td>
						</tr>
						<tr>
							<th>Beta access</th>
							<td>
								<label for="tw_beta_access">
									<input type="checkbox" id="tw_beta_access" name="tw_beta_access" value="1" <?php checked( TW_Settings::beta_access() ); ?>>
									Require approval before new signups can use UploadWriter
								</label>
								<p class="description">When on, new accounts stay locked until you approve them under Users → Edit. Site admins are never blocked.</p>
							</td>
						</tr>
						<tr>
							<th>AI thumbnail access</th>
							<td>
								<label for="tw_thumb_ai_for_users">
									<input type="checkbox" id="tw_thumb_ai_for_users" name="tw_thumb_ai_for_users" value="1" <?php checked( TW_Settings::thumb_ai_for_users() ); ?>>
									Let all UploadWriter users use Write thumbnail description and Create thumbnail
								</label>
								<p class="description">Off by default. Site admins always see it. Or enable per user under Users → Edit.</p>
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
							</td>
						</tr>
						<tr>
							<th><label for="tw_openai_image_model">OpenAI image model</label></th>
							<td>
								<input type="text" id="tw_openai_image_model" name="tw_openai_image_model" class="regular-text code" value="<?php echo esc_attr( TW_Settings::get( 'openai_image_model' ) ); ?>">
								<p class="description">Default <code><?php echo esc_html( TW_DEFAULT_OPENAI_IMAGE_MODEL ); ?></code>.</p>
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
							</td>
						</tr>
						<tr>
							<th><label for="tw_image_model">Gemini image model</label></th>
							<td>
								<input type="text" id="tw_image_model" name="tw_image_model" class="regular-text code" value="<?php echo esc_attr( TW_Settings::get( 'image_model' ) ); ?>">
								<p class="description">Default <code><?php echo esc_html( TW_DEFAULT_IMAGE_MODEL ); ?></code>.</p>
							</td>
						</tr>
					</table>
				</div>

				<div class="tw-card">
					<h2>2. Publishing defaults</h2>
					<p class="description">Site fallbacks when a front-end account has not set its own timezone or default time. Each account should set timezone under Keys &amp; voice. Ultimate fallback is WordPress <a href="<?php echo esc_url( admin_url( 'options-general.php' ) ); ?>">Settings &gt; General</a> (<code><?php echo esc_html( wp_timezone_string() ); ?></code>).</p>
					<table class="form-table">
						<tr>
							<th><label for="tw_timezone">Default timezone</label></th>
							<td>
								<select id="tw_timezone" name="tw_timezone">
									<?php
									if ( ! function_exists( 'wp_timezone_choice' ) ) {
										require_once ABSPATH . 'wp-admin/includes/template.php';
									}
									echo wp_timezone_choice( $site_tz ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
									?>
								</select>
								<p class="description">Used only until the account picks its own. Current resolved timezone for this request: <strong><?php echo esc_html( $tz ); ?></strong>.</p>
							</td>
						</tr>
						<tr>
							<th><label for="tw_default_time">Default publish time</label></th>
							<td><input type="time" id="tw_default_time" name="tw_default_time" value="<?php echo esc_attr( TW_Settings::get( 'default_time' ) ); ?>"> <span class="description">in the account timezone</span></td>
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

				<?php submit_button( 'Save settings' ); ?>
			</form>
		</div>
		<?php
	}

	public function save_settings() {
		$this->guard( 'tw_save_settings' );
		TW_Settings::save_from_post( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification
		$this->back( 'settings', 'Settings saved.' );
	}

	/** Checkboxes on Users → Profile / Edit for beta approval, Muse, and AI thumbnail access. */
	public function user_thumb_ai_field( $user ) {
		if ( ! current_user_can( self::CAP ) || ! ( $user instanceof WP_User ) ) {
			return;
		}
		$is_admin     = user_can( $user, 'manage_options' );
		$can_app      = user_can( $user, 'use_uploadwriter' ) || $is_admin;
		$granted      = tw_user_has_thumb_ai( $user->ID );
		$sitewide     = TW_Settings::thumb_ai_for_users();
		$beta_on      = TW_Settings::beta_access();
		$pending      = tw_user_is_beta_pending( $user->ID );
		$muse_allowed = function_exists( 'uw_muse_user_allowed' ) && uw_muse_user_allowed( $user->ID );
		?>
		<h2>UploadWriter</h2>
		<table class="form-table" role="presentation">
			<?php if ( $beta_on || $pending ) : ?>
			<tr>
				<th><label for="tw_beta_approved">Beta access</label></th>
				<td>
					<?php if ( $is_admin ) : ?>
						<p class="description">Site admins always have access.</p>
					<?php elseif ( ! $can_app ) : ?>
						<p class="description">This account cannot use UploadWriter yet. Give them the UploadWriter role first.</p>
					<?php else : ?>
						<label for="tw_beta_approved">
							<input type="checkbox" id="tw_beta_approved" name="tw_beta_approved" value="1" <?php checked( ! $pending ); ?>>
							Allow this user to use UploadWriter
						</label>
						<?php if ( $pending ) : ?>
							<p class="description">Waiting for approval. Uncheck later to revoke access again while beta mode is on.</p>
						<?php elseif ( $beta_on ) : ?>
							<p class="description">Approved. Uncheck to lock them out again while beta mode is on.</p>
						<?php else : ?>
							<p class="description">Beta mode is off site-wide, but this account still has a pending flag. Check this box to clear it.</p>
						<?php endif; ?>
					<?php endif; ?>
				</td>
			</tr>
			<?php endif; ?>
			<tr>
				<th><label for="tw_muse_allowed">Muse access</label></th>
				<td>
					<?php if ( $is_admin ) : ?>
						<p class="description">Site admins always have Muse access.</p>
					<?php elseif ( ! $can_app ) : ?>
						<p class="description">This account cannot use UploadWriter yet. Give them the UploadWriter role first.</p>
					<?php else : ?>
						<label for="tw_muse_allowed">
							<input type="checkbox" id="tw_muse_allowed" name="tw_muse_allowed" value="1" <?php checked( $muse_allowed ); ?>>
							Allow this user to connect Muse
						</label>
						<p class="description">Off by default. When off, the Account Muse section stays hidden and any existing Muse key is refused. The stored key hash is kept so turning this back on restores the key.</p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th><label for="tw_thumb_ai">AI thumbnails</label></th>
				<td>
					<?php if ( $is_admin ) : ?>
						<p class="description">Site admins always have Write thumbnail description and Create thumbnail.</p>
					<?php elseif ( ! $can_app ) : ?>
						<p class="description">This account cannot use UploadWriter yet. Give them the UploadWriter role (or the <code>use_uploadwriter</code> capability) first.</p>
					<?php elseif ( $sitewide ) : ?>
						<p class="description">Already allowed by the site-wide setting in UploadWriter → Settings. Uncheck that setting if you only want selected users.</p>
						<label for="tw_thumb_ai">
							<input type="checkbox" id="tw_thumb_ai" name="tw_thumb_ai" value="1" <?php checked( $granted ); ?>>
							Also keep a personal grant (still works if the site-wide switch is turned off later)
						</label>
					<?php else : ?>
						<label for="tw_thumb_ai">
							<input type="checkbox" id="tw_thumb_ai" name="tw_thumb_ai" value="1" <?php checked( $granted ); ?>>
							Allow this user to use Write thumbnail description and Create thumbnail
						</label>
						<p class="description">Leave the site-wide switch off in UploadWriter → Settings and enable people here one at a time.</p>
					<?php endif; ?>
				</td>
			</tr>
			<?php
			$uid              = (int) $user->ID;
			$own_gemini       = (bool) TW_Store::get_for_user( $uid, 'gemini_key', '' );
			$own_openai       = (bool) TW_Store::get_for_user( $uid, 'openai_key', '' );
			$assigned_gemini  = TW_Store::secret_placeholder_for_user( $uid, 'assigned_gemini_key' );
			$assigned_openai  = TW_Store::secret_placeholder_for_user( $uid, 'assigned_openai_key' );
			$assigned_provider = (string) TW_Store::get_for_user( $uid, 'assigned_text_provider', '' );
			$assigned_model    = (string) TW_Store::get_for_user( $uid, 'assigned_model', '' );
			$assigned_oai_model = (string) TW_Store::get_for_user( $uid, 'assigned_openai_model', '' );
			?>
			<tr>
				<th><label for="tw_assigned_gemini_key">Gemini API key</label></th>
				<td>
					<?php if ( $is_admin ) : ?>
						<p class="description">Site admins use the default keys in UploadWriter → Settings.</p>
					<?php elseif ( ! $can_app ) : ?>
						<p class="description">Give this account the UploadWriter role before assigning keys.</p>
					<?php else : ?>
						<input type="password" id="tw_assigned_gemini_key" name="tw_assigned_gemini_key" class="regular-text code" autocomplete="off" value="" placeholder="<?php echo esc_attr( $assigned_gemini ? $assigned_gemini : 'Paste Gemini key' ); ?>">
						<?php if ( $assigned_gemini ) : ?>
							<label for="tw_clear_assigned_gemini_key" style="display:inline-block;margin-top:6px;">
								<input type="checkbox" id="tw_clear_assigned_gemini_key" name="tw_clear_assigned_gemini_key" value="1">
								Clear assigned Gemini key
							</label>
						<?php endif; ?>
						<?php if ( $own_gemini ) : ?>
							<p class="description">This user saved their own Gemini key under Account → Keys &amp; voice. That key overrides whatever you assign here.</p>
						<?php else : ?>
							<p class="description">Used when this user has not saved their own Gemini key. Leave blank to keep the current value.</p>
						<?php endif; ?>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th><label for="tw_assigned_openai_key">ChatGPT / OpenAI API key</label></th>
				<td>
					<?php if ( $is_admin ) : ?>
						<p class="description">Site admins use the default keys in UploadWriter → Settings.</p>
					<?php elseif ( ! $can_app ) : ?>
						<p class="description">Give this account the UploadWriter role before assigning keys.</p>
					<?php else : ?>
						<input type="password" id="tw_assigned_openai_key" name="tw_assigned_openai_key" class="regular-text code" autocomplete="off" value="" placeholder="<?php echo esc_attr( $assigned_openai ? $assigned_openai : 'Paste OpenAI key' ); ?>">
						<?php if ( $assigned_openai ) : ?>
							<label for="tw_clear_assigned_openai_key" style="display:inline-block;margin-top:6px;">
								<input type="checkbox" id="tw_clear_assigned_openai_key" name="tw_clear_assigned_openai_key" value="1">
								Clear assigned OpenAI key
							</label>
						<?php endif; ?>
						<?php if ( $own_openai ) : ?>
							<p class="description">This user saved their own OpenAI key under Account → Keys &amp; voice. That key overrides whatever you assign here.</p>
						<?php else : ?>
							<p class="description">Used when this user has not saved their own OpenAI key. Leave blank to keep the current value.</p>
						<?php endif; ?>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th><label for="tw_assigned_text_provider">Preferred model</label></th>
				<td>
					<?php if ( $is_admin ) : ?>
						<p class="description">Site admins pick the model in the editor; site defaults live in UploadWriter → Settings.</p>
					<?php elseif ( ! $can_app ) : ?>
						<p class="description">Give this account the UploadWriter role first.</p>
					<?php else : ?>
						<select id="tw_assigned_text_provider" name="tw_assigned_text_provider">
							<option value="">Site default (ChatGPT)</option>
							<?php foreach ( TW_Settings::TEXT_PROVIDERS as $id => $label ) : ?>
								<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $assigned_provider, $id ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description" style="margin-top:10px;">
							<label for="tw_assigned_openai_model">ChatGPT model</label><br>
							<select id="tw_assigned_openai_model" name="tw_assigned_openai_model">
								<?php
								TW_Settings::render_model_options(
									'openai',
									$assigned_oai_model,
									'Site default (' . TW_Settings::get_site_model_label( 'openai' ) . ')'
								);
								?>
							</select>
						</p>
						<p class="description" style="margin-top:10px;">
							<label for="tw_assigned_model">Gemini model</label><br>
							<select id="tw_assigned_model" name="tw_assigned_model">
								<?php
								TW_Settings::render_model_options(
									'gemini',
									$assigned_model,
									'Site default (' . TW_Settings::get_site_model_label( 'gemini' ) . ')'
								);
								?>
							</select>
						</p>
						<p class="description">Default for the Generate picker. The user can still switch in the editor. “Site default” inherits UploadWriter → Settings.</p>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<?php
	}

	/** Save beta approval, Muse access, AI thumbnail grant, and assigned AI keys/models from the profile form. */
	public function save_user_thumb_ai_field( $user_id ) {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$user_id = (int) $user_id;
		if ( ! $user_id || user_can( $user_id, 'manage_options' ) ) {
			return;
		}
		if ( ! user_can( $user_id, 'use_uploadwriter' ) ) {
			tw_user_set_thumb_ai( $user_id, false );
			tw_user_set_beta_pending( $user_id, false );
			if ( function_exists( 'uw_muse_set_user_allowed' ) ) {
				uw_muse_set_user_allowed( $user_id, false );
			}
			return;
		}
		tw_user_set_thumb_ai( $user_id, ! empty( $_POST['tw_thumb_ai'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( function_exists( 'uw_muse_set_user_allowed' ) ) {
			uw_muse_set_user_allowed( $user_id, ! empty( $_POST['tw_muse_allowed'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}

		$beta_on = TW_Settings::beta_access();
		$pending = tw_user_is_beta_pending( $user_id );
		if ( $beta_on || $pending ) {
			// Checked = approved (not pending). Unchecked = pending / revoked.
			$approved = ! empty( $_POST['tw_beta_approved'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			tw_user_set_beta_pending( $user_id, ! $approved );
		}

		TW_Settings::save_assigned_ai_from_post( $user_id, $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	}

	public function page_editor( $video_id, $back_url = '' ) {
		$back_url = $back_url ? $back_url : ( class_exists( 'TW_Frontend' ) ? TW_Frontend::url( 'account' ) . '#tw-videos' : home_url( '/tubewriter/account/#tw-videos' ) );
		echo '<div class="wrap tw-wrap">';
		echo '<p><a href="' . esc_url( $back_url ) . '">&larr; All videos</a></p>';

		$video = TW_YouTube::get_video( $video_id );
		if ( is_wp_error( $video ) ) {
			$msg = $video->get_error_message();
			if ( 'tw_not_found' === $video->get_error_code() && TW_Index::exists( $video_id ) ) {
				TW_Index::delete_local( $video_id );
				$msg .= ' Removed it from your local video list.';
			}
			echo '<div class="notice notice-error"><p>' . esc_html( $msg ) . '</p></div>';
			if ( in_array( $video->get_error_code(), array( 'tw_expired', 'tw_not_connected' ), true ) ) {
				echo '<div class="tw-card">';
				if ( TW_Context::is_user() ) {
					$connect = wp_nonce_url( admin_url( 'admin-post.php?action=tw_front_connect' ), 'tw_front_connect' );
					echo '<p><a class="button button-primary" href="' . esc_url( $connect ) . '">Connect YouTube again</a></p>';
				} else {
					$this->reconnect_steps( 'Sign in again' );
				}
				echo '</div>';
			}
			echo '</div>';
			return;
		}

		$state         = TW_Store::get( 'state_' . $video_id, array() );
		$snap          = TW_YouTube::snapshot( $video );
		$secs          = TW_Transcript::duration_seconds( $video['contentDetails']['duration'] ?? '' );
		$remote_thumb  = $video['snippet']['thumbnails']['medium']['url'] ?? '';
		$thumb         = TW_Index::cache_thumbnail( $video_id, $remote_thumb );
		$studio        = 'https://studio.youtube.com/video/' . rawurlencode( $video_id ) . '/edit';
		$index_privacy = TW_Index::privacy_of( $video_id );
		$badge_privacy = ( 'draft' === $index_privacy ) ? 'draft' : $snap['privacyStatus'];
		$sched_tz      = TW_Settings::timezone();
		$boot          = array(
			'videoId'   => $video_id,
			'duration'  => $secs,
			'current'   => $snap,
			'publishAtLocal' => $snap['publishAt'] ? array( 'date' => wp_date( 'Y-m-d', strtotime( $snap['publishAt'] ), $sched_tz ), 'time' => wp_date( 'H:i', strtotime( $snap['publishAt'] ), $sched_tz ) ) : null,
			'state'     => is_array( $state ) ? $state : array(),
			'studioUrl' => $studio,
			'backUrl'   => $back_url,
			'defaults'  => array(
				'time'     => TW_Settings::get( 'default_time' ),
				'language' => TW_Settings::get( 'default_language' ),
				'category' => TW_Settings::get( 'default_category' ),
				'timezone' => TW_Settings::timezone_string(),
			),
			'logos'     => array(
				'game'    => $this->logo_boot( (int) ( is_array( $state ) ? ( $state['game_logo_id'] ?? 0 ) : 0 ), (string) ( is_array( $state ) ? ( $state['game_logo_pos'] ?? '' ) : '' ), 'upper-right' ),
				'channel' => $this->logo_boot( (int) TW_Store::get( 'channel_logo_id', 0 ), (string) TW_Store::get( 'channel_logo_pos', '' ), 'lower-left' ),
			),
			'today'     => wp_date( 'Y-m-d', null, $sched_tz ),
			'tz'        => TW_Settings::timezone_string(),
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
						<img id="tw-head-thumb-img" src="<?php echo esc_url( TW_Index::thumb_url_with_version( $video_id, $thumb ) ); ?>" alt="">
					</a>
				<?php endif; ?>
				<div>
					<h1 id="tw-head-title"><?php echo esc_html( $snap['title'] ); ?></h1>
					<p id="tw-head-meta">
						<span id="tw-head-badge" class="tw-badge tw-badge-<?php echo esc_attr( $badge_privacy ); ?>"><?php echo esc_html( $badge_privacy ); ?></span>
						<span id="tw-head-duration"><?php echo $secs ? esc_html( TW_Transcript::fmt( $secs ) ) : ''; ?></span>
						&middot; <a href="<?php echo esc_url( $studio ); ?>" target="_blank" rel="noopener">Open in Studio</a>
						&middot; <button type="button" class="button-link" id="tw-refresh" title="About 1 API unit">Refresh from YouTube</button>
						<?php if ( current_user_can( self::CAP ) ) : ?>
							&middot; <span class="tw-quota">YouTube API today: about <?php echo esc_html( number_format_i18n( TW_YouTube::quota_used() ) ); ?> / 10,000 units</span>
						<?php endif; ?>
					</p>
					<p class="tw-safe">Nothing on YouTube changes until step 7, where you confirm twice. A backup is saved before every change, and UploadWriter never deletes anything. Thumbnails stay on your computer until you upload them in Studio.</p>
				</div>
			</div>

			<ol class="tw-stepper" id="tw-stepper" aria-label="Steps">
				<li><button type="button" data-go="1">1. Source</button></li>
				<li><button type="button" data-go="2">2. Details</button></li>
				<li><button type="button" data-go="3">3. Thumbnail</button></li>
				<li><button type="button" data-go="4">4. Playlist</button></li>
				<li><button type="button" data-go="5">5. Go live</button></li>
				<li><button type="button" data-go="6">6. Review</button></li>
				<li><button type="button" data-go="7">7. Publish</button></li>
			</ol>

			<div id="tw-msg" class="tw-msg" hidden></div>

			<!-- STEP 1 -->
			<section class="tw-card tw-step" data-step="1">
				<h2>Step 1: Source material</h2>
				<?php
				$help(
					'Gathers what the AI writes from: the transcript, a starting title and description, and your notes. These boxes are not the listing that goes on YouTube.',
					array(
						'Click <strong>Pull YouTube captions</strong>, or paste a transcript. Skip it if there are no captions.',
						'Check the <strong>starting title and description</strong>. They are filled in from YouTube as ideas for the AI, not the finished listing. If you changed them in Studio after opening this page, click <strong>Refresh from YouTube</strong> in the header (about 1 unit).',
						'Add <strong>notes</strong>: key moments with rough times (each becomes a chapter) and anything the AI must get right.',
						'Pick <strong>Gemini</strong> or <strong>ChatGPT</strong> next to <strong>Generate with AI</strong>, then generate. Or click <strong>Fill in by hand</strong>. The real draft appears in step 2 below. Generate asks before it overwrites that draft. It does not save to YouTube, and you can undo. A busy model is not the YouTube sign-in.',
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
				<p class="description">Pulling captions costs 250 of your 10,000 daily YouTube API units. UploadWriter remembers when a pull already ran, including pulls that found nothing, so old videos are not charged again by accident.</p>

				<div class="tw-guide" id="tw-guide">
					<strong>No transcript? Skim the video and fill in the boxes below.</strong>
					<p>Those boxes are raw material for the AI, not the finished YouTube listing. A few honest lines beat a long vague paragraph.</p>
					<ol>
						<li><strong>Starting title:</strong> what the video is in a few words. <em>Gates of Hell, stole their 88 with a Churchill</em></li>
						<li><strong>Starting description:</strong> 2 or 3 sentences of facts for the AI to rewrite. The game and mode, who you played with, how it went and how it ended. Do not polish the final description here.</li>
						<li><strong>Notes:</strong> the best moments with rough times, one per line. Each one becomes a chapter.
							<br><code>2:30 Mike drives the supply truck into an open field</code>
							<br><code>11:15 we capture their 88 and turn it on them</code>
						</li>
						<li>Funniest fail or best play? Say so. That's what the description jokes are built from.</li>
					</ol>
				</div>

				<div class="tw-seed">
					<h3>What the AI writes from</h3>
					<p class="description">Not the published title or description. Generate rewrites these into the draft in step 2. Edit the finished listing there.</p>

					<label for="tw-seed-title">Starting title</label>
					<input type="text" id="tw-seed-title" class="large-text" placeholder="Short idea, e.g. Stole their 88 with a Churchill">
					<p class="description">Idea for the AI. Filled from YouTube when available. The title that gets published is chosen in step 2.</p>

					<label for="tw-seed-desc">Starting description</label>
					<textarea id="tw-seed-desc" rows="5" class="large-text" placeholder="A line or two about what happens. Any links in here get kept."></textarea>
					<p class="description">Idea for the AI, not where the finished description goes. Generate turns this into the draft in step 2. Links and credits in this box are kept.</p>

					<label for="tw-notes">Notes for the AI</label>
					<textarea id="tw-notes" rows="5" class="large-text" placeholder="Key moments with rough times, one per line (2:30 truck dies). Anything the AI should know or get right. The AI trusts these over everything else."></textarea>
					<p class="description">Extra facts and timed moments the AI must get right. Still source material, not the published listing.</p>
				</div>

				<p class="tw-actions-left">
					<button type="button" class="button button-primary button-hero" id="tw-generate">Generate with AI</button>
					<label for="tw-text-provider" class="tw-provider-label">with</label>
					<select id="tw-text-provider" aria-label="AI model">
						<option value="openai">ChatGPT (<?php echo esc_html( TW_Settings::get( 'openai_model' ) ); ?>)</option>
						<option value="gemini">Gemini (<?php echo esc_html( TW_Settings::get( 'model' ) ); ?>)</option>
					</select>
					<button type="button" class="button button-hero" id="tw-manual">Fill in by hand</button>
				</p>
				<p id="tw-replace-warn" class="tw-draft-note" hidden role="status" aria-live="polite">
					<span>Careful: Generate will replace the current description. It will not save to YouTube, and you can undo.</span>
					<span class="tw-draft-note-actions">
						<button type="button" class="button button-primary" id="tw-replace-go">Replace the description</button>
						<button type="button" class="button" id="tw-replace-cancel">Keep what's there</button>
					</span>
				</p>
				<p class="description">This picker also writes the thumbnail brief in step 3. YouTube sign-in is separate. Prefer ChatGPT while Gemini is overloaded. If you leave Gemini selected and it is busy, UploadWriter still finishes with ChatGPT when an OpenAI key is saved. A video with no transcript can still generate from the starting title, description, and timestamp notes.</p>
			</section>

			<!-- STEP 2 (same page as the source, so the draft can be compared with what it came from) -->
			<section class="tw-card tw-step" data-step="2">
				<h2>Step 2: Details</h2>
				<p id="tw-draft-undo" class="tw-draft-note" hidden>
					<span>Generate replaced the description in this draft. Nothing was saved to YouTube.</span>
					<span class="tw-draft-note-actions">
						<button type="button" class="button" id="tw-undo-generate">Undo</button>
					</span>
				</p>
				<?php
				$help(
					'This is the draft that gets published: title, description, tags, category and language. The starting title, description and notes above are only what the AI wrote from. Editing here only changes the draft.',
					array(
						'Compare this draft with the <strong>starting title, description and notes</strong> above (those stay as source material).',
						'Pick a <strong>title</strong> from the AI options, type your own, or keep what is on YouTube.',
						'Read the <strong>description</strong> and <strong>chapters</strong>. This description box is the one that goes on YouTube. Fix anything that is wrong.',
						'Check <strong>tags</strong>, <strong>hashtags</strong>, <strong>category</strong> and <strong>language</strong>.',
						'Want a change from the AI? Type it in <strong>Tweak it</strong>. Then click <strong>Next</strong>.',
					)
				);
				?>
				<p class="description">The published title (100 characters max, separate from the description). The starting title above was only an idea for the AI.</p>
				<div id="tw-titles" class="tw-titles"></div>
				<div class="tw-row">
					<label for="tw-title">Title</label>
					<p class="tw-count" id="tw-title-count"></p>
				</div>
				<input type="text" id="tw-title" class="large-text" maxlength="100" aria-describedby="tw-title-count">

				<div class="tw-row">
					<label for="tw-body">Description</label>
					<p class="tw-count" id="tw-desc-count"></p>
				</div>
				<p class="description">The published description (5,000 characters for body + chapters + hashtags). Not the starting description above.</p>
				<textarea id="tw-body" rows="18" class="large-text"></textarea>
				<p class="description">The first ~100 characters of the description show in search, so lead with the game and what happens.</p>

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
				<p class="tw-current-line" id="tw-privacy-current">Visibility on YouTube: <strong id="tw-privacy-label"></strong></p>

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
				$can_thumbs = tw_user_can_thumbnails();
				$help(
					$can_thumbs
						? 'Optional. Paste inspiration screenshots when you have them. The same Gemini or ChatGPT picker from step 1 writes a packaged thumbnail concept from your title, description, transcript/timestamps and pasted shots, then that same family builds the card. Or pick an existing image from the Media Library. Upload to YouTube only when you click that button and confirm.'
						: 'Optional. Choose a finished thumbnail from the Media Library, or upload one to YouTube when ready. AI thumbnail brief and create are hidden until a site admin turns them on in UploadWriter → Settings.',
					$can_thumbs
						? array(
							'Paste inspiration screenshots: <strong>Ctrl+V</strong> or Add image into the paste box (not only Saved references). First image is the background photo. Second is the picture-in-picture photo. Drag shots to swap roles. Max two.',
							'Click <strong>Write thumbnail description</strong>. Uses the Gemini or ChatGPT model selected next to Generate, with title, description, transcript/timestamps, and your pasted shots.',
							'Edit the brief if you want, then click <strong>Create thumbnail</strong>. ChatGPT selected → OpenAI image model. Gemini selected → Gemini image model. Safe margins stay ~150px in from every edge.',
							'Corner logos are optional PNG badges. The finished 16:9 card is created or chosen in its own section below.',
							'Generated cards are saved to the <strong>Media Library</strong>. Reuse them with <strong>Choose finished thumbnail</strong>. Free accounts keep a few videos worth; admins are unlimited. Oldest drop off when you go over.',
							'Click <strong>Upload to YouTube</strong> and confirm to put it on the video. Skip this step anytime with Next.',
						)
						: array(
							'Use <strong>Choose finished thumbnail</strong> to pick a PNG or JPEG from the Media Library.',
							'Upload to YouTube only when you click that button and confirm.',
							'Skip this step anytime with Next.',
						)
				);
				?>
				<?php if ( $can_thumbs ) : ?>
				<p class="description" id="tw-thumb-provider">Brief + image follow the Gemini / ChatGPT picker next to Generate. Settings image provider is the fallback. Active OpenAI image model: <strong><?php echo esc_html( TW_Settings::get( 'openai_image_model' ) ); ?></strong>.</p>
				<?php endif; ?>
				<?php if ( $can_thumbs ) : ?>
				<div id="tw-thumb-zone" class="tw-thumb-zone" tabindex="0" role="button" aria-label="Paste screenshot area">
					<p class="tw-thumb-zone-title">Paste inspiration screenshots here</p>
					<p class="description">Press Ctrl+V while this box is focused, or click Add image. First image = background photo. Second = picture-in-picture photo. Drag either shot onto the other to swap roles. Max two. These must appear here (not only under Saved references) to be sent to the model. Skip only if you want a text-only concept.</p>
					<div id="tw-thumb-shots" class="tw-thumb-shots"></div>
					<div class="tw-inline">
						<button type="button" class="button" id="tw-thumb-add">Add image</button>
						<button type="button" class="button" id="tw-thumb-clear" hidden>Clear images</button>
						<input type="file" id="tw-thumb-file" accept="image/png,image/jpeg,image/webp,image/gif" hidden>
					</div>
				</div>

				<div id="tw-thumb-refs" class="tw-thumb-refs">
					<p class="tw-thumb-zone-title">Saved references</p>
					<p class="description">Pasted thumbnails for this video stay in the Media Library. Drag one into the paste box above to use it as Image 1 or 2, or Look / Copy.</p>
					<div id="tw-thumb-ref-list" class="tw-thumb-shots"></div>
				</div>

				<div id="tw-thumb-lightbox" class="tw-thumb-lightbox" hidden>
					<div class="tw-thumb-lightbox-card" role="dialog" aria-modal="true" aria-label="Saved thumbnail">
						<img id="tw-thumb-lightbox-img" alt="">
						<p class="tw-inline">
							<button type="button" class="button button-primary" id="tw-thumb-lightbox-copy">Copy image</button>
							<a class="button" id="tw-thumb-lightbox-edit" href="#" target="_blank" rel="noopener">Open in Media Library</a>
							<button type="button" class="button" id="tw-thumb-lightbox-delete">Delete</button>
							<button type="button" class="button" id="tw-thumb-lightbox-close">Close</button>
						</p>
					</div>
				</div>

				<p class="tw-actions-left">
					<button type="button" class="button button-primary" id="tw-thumb-describe">Write thumbnail description</button>
				</p>

				<label for="tw-thumb-desc">Thumbnail description</label>
				<textarea id="tw-thumb-desc" rows="22" class="large-text" placeholder="The numbered 16:9 concept appears here. Edit any line, then create the thumbnail."></textarea>
				<?php endif; ?>

				<?php if ( $can_thumbs ) : ?>
				<div class="tw-thumb-block tw-thumb-logos">
					<p class="tw-thumb-zone-title">1. Corner logos</p>
					<p class="description">Optional. Transparent PNG badges for the corners only. Do not put a finished 16:9 thumbnail here.</p>
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
							<button type="button" class="button" id="tw-game-logo-pick">Choose logo PNG</button>
						</p>
						<div id="tw-game-logo-selected" class="tw-logo-selected" hidden>
							<p class="tw-logo-selected-kicker">Selected game logo</p>
							<p class="description">Corner badge only. Use a transparent game logo PNG, not a finished thumbnail.</p>
							<img id="tw-game-logo-preview" class="tw-logo-preview" alt="" hidden>
							<p class="tw-inline">
								<button type="button" class="button" id="tw-game-logo-clear" hidden>Remove logo</button>
							</p>
						</div>
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
							<button type="button" class="button" id="tw-channel-logo-pick">Choose logo PNG</button>
						</p>
						<div id="tw-channel-logo-selected" class="tw-logo-selected" hidden>
							<p class="tw-logo-selected-kicker">Selected channel logo</p>
							<p class="description">Corner badge only. Not the finished thumbnail.</p>
							<img id="tw-channel-logo-preview" class="tw-logo-preview" alt="" hidden>
							<p class="tw-inline">
								<button type="button" class="button" id="tw-channel-logo-clear" hidden>Remove logo</button>
							</p>
						</div>
					</div>
					</div>
					<p class="description">Headline stays top left. Channel logo defaults to the bottom left. Game logo defaults to the top right. With two screenshots, the first is always the main background and the second is always the picture-in-picture in the top right, so the game logo moves to the bottom right.</p>
				</div>
				<?php endif; ?>

				<div class="tw-thumb-block tw-thumb-build">
					<p class="tw-thumb-zone-title"><?php echo esc_html( $can_thumbs ? '2. Finished thumbnail' : 'Finished thumbnail' ); ?></p>
					<p class="description"><?php echo esc_html( $can_thumbs ? 'Create the full 16:9 YouTube card from the brief above, or pick one with Choose finished thumbnail. That opens the Media Library — previously generated thumbnails live there.' : 'Pick a finished 16:9 PNG or JPEG from the Media Library.' ); ?></p>
					<p class="tw-actions-left tw-inline">
						<?php if ( $can_thumbs ) : ?>
						<button type="button" class="button button-primary button-hero" id="tw-thumb-create">Create thumbnail</button>
						<?php endif; ?>
						<button type="button" class="button button-hero" id="tw-thumb-pick">Choose finished thumbnail</button>
					</p>

					<div id="tw-thumb-result" class="tw-thumb-result" hidden>
						<p class="tw-thumb-result-kicker">Selected 16:9 card</p>
						<label for="tw-thumb-preview">YouTube thumbnail</label>
						<p class="description">This is the full thumbnail, separate from the game and channel logos above.</p>
						<img id="tw-thumb-preview" alt="Finished thumbnail">
						<p id="tw-thumb-status" class="description tw-thumb-status" hidden></p>
						<p class="tw-inline">
							<button type="button" class="button button-primary" id="tw-thumb-upload">Upload to YouTube</button>
							<a class="button" id="tw-thumb-download" download="thumbnail.png" href="#">Download PNG</a>
							<a class="button" href="<?php echo esc_url( $studio ); ?>" target="_blank" rel="noopener">Open in Studio</a>
							<button type="button" class="button" id="tw-thumb-remove">Remove thumbnail</button>
						</p>
						<p class="description">Upload to YouTube costs about 50 API units and needs a verified channel for custom thumbnails. Download or Open in Studio if you will upload it yourself instead. Generated cards stay in the Media Library — open Choose finished thumbnail to reuse one (free accounts keep a few videos worth; admins are unlimited).</p>
					</div>
				</div>
			</section>

			<!-- STEP 4 -->
			<section class="tw-card tw-step" data-step="4" hidden>
				<h2>Step 4: Playlist</h2>
				<?php
				$help(
					'Optionally add the video to a playlist, or create a new one. Nothing is created until you publish in step 7.',
					array(
						'Choose <strong>Leave playlists as they are</strong> if it is already in the right playlist (or you will handle that in Studio).',
						'Or <strong>Add to an existing playlist</strong> / <strong>Create a new playlist</strong>. For a new one, type a rough name and click <strong>Polish for SEO</strong>.',
						'UploadWriter never removes videos from playlists. If you pick the wrong one, remove it in Studio.',
					)
				);
				?>
				<p><label class="tw-radio"><input type="radio" name="tw-pl-mode" value="none" checked> Leave playlists as they are</label></p>
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
				<h2>Step 5: Go live</h2>
				<?php
				$help(
					'Picks when the video goes public. Step 7 still needs your confirm before anything changes on YouTube.',
					array(
						'Choose <strong>Publish now</strong> to make it public as soon as you confirm in step 7.',
						'Choose <strong>Schedule it</strong> to pick a future date and time, or <strong>Leave as is</strong> to keep the current visibility (or schedule) and only update the details.',
						'Private and Unlisted videos can be scheduled. Scheduling sets the video to private until the chosen time, then it goes public.',
						'Visibility below saves to YouTube as soon as you apply it (about 50 API units). Default is Keep current.',
						'Click a day on the calendar. <span class="tw-key tw-key-sched">Scheduled</span> and <span class="tw-key tw-key-pub">Published</span> labels show existing uploads, empty days are open.',
						'Scheduled times start at your default (' . esc_html( TW_Settings::get( 'default_time' ) ) . ', ' . esc_html( TW_Settings::timezone_string() ) . '). Change timezone under Keys &amp; voice.',
					)
				);
				?>
				<div class="tw-privacy-box">
					<label for="tw-privacy">Visibility on YouTube</label>
					<div class="tw-inline">
						<select id="tw-privacy">
							<option value="">Keep current</option>
							<option value="private">Private</option>
							<option value="unlisted">Unlisted</option>
							<option value="public">Public</option>
						</select>
						<button type="button" class="button" id="tw-privacy-apply">Apply visibility</button>
					</div>
					<p class="description" id="tw-privacy-hint" hidden>Unlisted is not searchable and does not show on the channel page, but anyone with the link can watch it. Useful so a signed-out browser can read the transcript panel without a 250-unit caption pull.</p>
					<p class="description">Saves immediately to YouTube when you click Apply (not part of step 7). Opening the editor does not change visibility.</p>
				</div>
				<div class="tw-sched-layout">
					<div class="tw-sched-side">
						<p id="tw-sched-current" class="tw-current-line"></p>
						<div id="tw-sched-locked" class="notice notice-info inline" hidden><p>This video is public, so it can't be scheduled or published-now from here. Set visibility to Private or Unlisted first, or leave Go live as is and only update the details.</p></div>
						<div id="tw-sched-controls">
							<p><label class="tw-radio"><input type="radio" name="tw-sched-mode" value="now"> <span class="tw-choice-copy">Publish now<span class="description">Goes public when you confirm in step 7.</span></span></label></p>
							<p><label class="tw-radio"><input type="radio" name="tw-sched-mode" value="set"> <span class="tw-choice-copy">Schedule it<span class="description">Saves the listing when you confirm in step 7, but stays private until the chosen time — not live on publish.</span></span></label></p>
							<div class="tw-sub" id="tw-sched-set" hidden>
								<div class="tw-inline">
									<label for="tw-sched-date">Date</label> <input type="date" id="tw-sched-date">
									<label for="tw-sched-time">Time</label> <input type="time" id="tw-sched-time">
									<span class="description"><?php echo esc_html( TW_Settings::timezone_string() ); ?></span>
								</div>
							</div>
							<p><label class="tw-radio"><input type="radio" name="tw-sched-mode" value="keep" checked> <span class="tw-choice-copy">Leave as is<span class="description">Keeps the current visibility or schedule. Only the details change.</span></span></label></p>
						</div>
					</div>
					<div class="tw-cal uw-calendar tw-editor-calendar">
						<div class="tw-cal-head">
							<button type="button" class="button" id="tw-cal-prev" aria-label="Previous month">&larr;</button>
							<strong id="tw-cal-title"></strong>
							<button type="button" class="button" id="tw-cal-next" aria-label="Next month">&rarr;</button>
							<div class="tw-cal-views" id="tw-cal-views" role="group" aria-label="Calendar view" hidden>
								<button type="button" data-view="upcoming" aria-pressed="true">Upcoming</button>
								<button type="button" data-view="month" aria-pressed="false">Month</button>
								<button type="button" data-view="week" aria-pressed="false">Week</button>
							</div>
							<button type="button" class="button-link" id="tw-cal-next-open">Next available</button>
							<button type="button" class="button-link" id="tw-cal-refresh">Refresh from YouTube</button>
						</div>
						<p class="tw-editor-calendar-hint">Select a future date to schedule this video. On small screens, scroll across to see the full week.</p>
						<div class="tw-calendar-scroll" role="region" aria-label="Publishing calendar, scroll horizontally on small screens" tabindex="0"><div class="tw-cal-grid" id="tw-cal-grid" role="group" aria-label="Choose a publishing date"></div></div>
					</div>
				</div>
			</section>

			<!-- STEP 6 -->
			<section class="tw-card tw-step" data-step="6" hidden>
				<h2>Step 6: Review</h2>
				<?php
				$help(
					'Compares what is on YouTube right now with what UploadWriter will write. Nothing has changed yet.',
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
						'Read the list of changes and the go-live note below. Publishing the listing is separate from going public.',
						'If the video is scheduled, confirming saves the details and keeps (or sets) the schedule — it does <strong>not</strong> go live until that time.',
						'Tick the box to confirm you reviewed them.',
						'Click the publish button, then confirm once more in the pop-up.',
						'When it finishes, use <strong>Done — back to videos</strong> to return to your library. You can still open Studio for game title and end screen.',
					)
				);
				?>
				<div id="tw-confirm">
					<div id="tw-publish-outcome" class="tw-publish-outcome" hidden></div>
					<h3>These changes will be made</h3>
					<ul id="tw-actions" class="tw-action-list"></ul>
					<p class="tw-backup-note">Before anything changes, UploadWriter saves the current title, description, tags, category, language and schedule. You can restore them from <strong>Version history</strong> below.</p>
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
				<ol class="tw-stepper tw-stepper--footer" id="tw-stepper-footer" aria-label="Steps">
					<li><button type="button" data-go="1">1. Source</button></li>
					<li><button type="button" data-go="2">2. Details</button></li>
					<li><button type="button" data-go="3">3. Thumbnail</button></li>
					<li><button type="button" data-go="4">4. Playlist</button></li>
					<li><button type="button" data-go="5">5. Go live</button></li>
					<li><button type="button" data-go="6">6. Review</button></li>
					<li><button type="button" data-go="7">7. Publish</button></li>
				</ol>
				<div class="tw-footer-bar">
					<button type="button" class="button" id="tw-back">&larr; Back</button>
					<span class="tw-footer-mid">
						<button type="button" class="button" id="tw-save">Save draft</button>
						<span id="tw-saved" class="description"></span>
					</span>
					<span class="tw-footer-end">
						<span class="tw-footer-action" id="tw-publish-footer-wrap" hidden>
							<button type="button" class="button button-primary" id="tw-publish-footer">Publish changes</button>
						</span>
						<button type="button" class="button button-primary" id="tw-next">Next &rarr;</button>
					</span>
				</div>
			</div>
		</div>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------- AJAX */

	public function ajax_captions() {
		$this->ajax_guard();
		$video_id = $this->post( 'video' );
		$srt      = TW_YouTube::captions_srt( $video_id );
		if ( is_wp_error( $srt ) ) {
			// No track / empty track still spent list (and maybe download) units. Remember so old videos are not re-pulled by accident.
			if ( 'tw_no_captions' === $srt->get_error_code() ) {
				$this->mark_captions( $video_id, 'none', '' );
				wp_send_json_success(
					array(
						'transcript'      => '',
						'captions_status' => 'none',
						'message'         => $srt->get_error_message(),
					)
				);
			}
			wp_send_json_error( $srt->get_error_message() );
		}
		$text   = TW_Transcript::normalize( $srt );
		$status = '' !== trim( $text ) ? 'ok' : 'none';
		$this->mark_captions( $video_id, $status, $text );
		wp_send_json_success(
			array(
				'transcript'      => $text,
				'captions_status' => $status,
				'message'         => 'none' === $status ? 'The caption track came back empty. Paste a transcript instead.' : '',
			)
		);
	}

	/** Persist a captions pull result on the draft and on the videos index. */
	private function mark_captions( $video_id, $status, $transcript ) {
		$status = in_array( $status, array( 'ok', 'none' ), true ) ? $status : '';
		$this->save_state(
			$video_id,
			array(
				'transcript'      => (string) $transcript,
				'captions_status' => $status,
			)
		);
		TW_Index::set_captions_status( $video_id, $status );
	}

	public function ajax_generate() {
		$this->ajax_guard();
		$this->require_identity();
		$input = $this->input();
		if ( '' === trim( $input['transcript'] ) && '' === trim( $input['notes'] ) && '' === trim( $input['current_description'] ) ) {
			wp_send_json_error( 'Nothing to work from yet. Add a transcript, a starting description or some notes.' );
		}
		$pkg = TW_AI::generate( $input, $this->text_provider() );
		$this->respond_with_package( $input, $pkg, 'generate' );
	}

	public function ajax_revise() {
		$this->ajax_guard();
		$this->require_identity();
		$input       = $this->input();
		$instruction = $this->post( 'instruction', true );
		if ( '' === trim( $instruction ) ) {
			wp_send_json_error( 'Type what you want changed.' );
		}
		$current = $this->pkg_from_post();
		$pkg     = TW_AI::revise( $input, $current, $instruction, $this->text_provider() );
		$this->respond_with_package( $input, $pkg, 'revise' );
	}

	public function ajax_save() {
		$this->ajax_guard();
		$input = $this->input();
		$step  = absint( $this->post( 'step' ) );
		if ( $step < 1 || $step > 7 ) {
			$step = 1;
		}
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
				'step'              => $step,
				'saved'             => time(),
			)
		);
		wp_send_json_success( array( 'saved' => wp_date( 'g:ia' ) ) );
	}

	public function ajax_thumb_logos_save() {
		$this->ajax_guard();
		$channel_id  = absint( $this->post( 'channel_logo_id' ) );
		$game_id     = absint( $this->post( 'game_logo_id' ) );
		$forget_id   = absint( $this->post( 'forget_logo_id' ) );
		$channel_pos = $this->logo_pos( $this->post( 'channel_logo_pos' ), 'lower-left' );
		$game_pos    = $this->logo_pos( $this->post( 'game_logo_pos' ), 'upper-right' );
		$game_name   = sanitize_text_field( $this->post( 'game_name' ) );
		if ( $channel_id && ! $this->user_owns_attachment( $channel_id ) ) {
			wp_send_json_error( 'That channel logo is not yours.', 403 );
		}
		if ( $game_id && ! $this->user_owns_attachment( $game_id ) ) {
			wp_send_json_error( 'That game logo is not yours.', 403 );
		}
		if ( $forget_id && ! $this->user_owns_attachment( $forget_id ) ) {
			wp_send_json_error( 'That logo is not yours.', 403 );
		}
		TW_Store::set( 'channel_logo_id', $channel_id );
		TW_Store::set( 'channel_logo_pos', $channel_pos );
		$map = TW_Store::get( 'game_logos', array() );
		if ( ! is_array( $map ) ) {
			$map = array();
		}
		if ( $game_id && '' !== $game_name ) {
			update_post_meta( $game_id, '_tw_game_logo', '1' );
			update_post_meta( $game_id, '_tw_game_logo_name', $game_name );
			$map[ $this->game_logo_key( $game_name ) ] = $game_id;
			TW_Store::set( 'game_logos', $map );
		} elseif ( ! $game_id ) {
			if ( '' !== $game_name ) {
				unset( $map[ $this->game_logo_key( $game_name ) ] );
			}
			if ( $forget_id ) {
				foreach ( $map as $key => $id ) {
					if ( (int) $id === $forget_id ) {
						unset( $map[ $key ] );
					}
				}
				delete_post_meta( $forget_id, '_tw_game_logo' );
				delete_post_meta( $forget_id, '_tw_game_logo_name' );
			}
			TW_Store::set( 'game_logos', $map );
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
		$map = TW_Store::get( 'game_logos', array() );
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

		$map = TW_Store::get( 'game_logos', array() );
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
		$uid = get_current_user_id();
		if ( $uid && ! current_user_can( 'manage_options' ) ) {
			$args['author'] = $uid;
		}
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
			$extra_args = array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 8,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'post_mime_type' => 'image',
				'meta_key'       => '_tw_game_logo',
				'meta_value'     => '1',
			);
			if ( $uid && ! current_user_can( 'manage_options' ) ) {
				$extra_args['author'] = $uid;
			}
			$extra = new WP_Query( $extra_args );
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
		if ( ! $this->user_owns_attachment( $id ) ) {
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

		$video = sanitize_text_field( $this->post( 'video' ) );
		$hash  = md5_file( (string) $_FILES['image']['tmp_name'] );
		if ( $hash && '' !== $video ) {
			$existing = $this->find_thumb_ref_by_hash( $video, $hash );
			if ( $existing ) {
				wp_send_json_success( $this->thumb_ref_payload( $existing ) );
			}
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$id = media_handle_upload( 'image', 0 );
		if ( is_wp_error( $id ) ) {
			wp_send_json_error( $id->get_error_message() );
		}
		update_post_meta( $id, '_tw_thumb_ref', '1' );
		if ( '' !== $video ) {
			update_post_meta( $id, '_tw_thumb_ref_video', $video );
		}
		if ( $hash ) {
			update_post_meta( $id, '_tw_thumb_ref_hash', $hash );
		}
		wp_update_post(
			array(
				'ID'         => $id,
				'post_title' => 'UploadWriter reference ' . wp_date( 'Y-m-d g:i a' ),
			)
		);
		wp_send_json_success( $this->thumb_ref_payload( $id ) );
	}

	/**
	 * @param string $video Video id.
	 * @param string $hash  md5 of file bytes.
	 * @return int Attachment id or 0.
	 */
	private function find_thumb_ref_by_hash( $video, $hash ) {
		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'orderby'        => 'date',
			'order'          => 'DESC',
			'meta_query'     => array(
				array(
					'key'   => '_tw_thumb_ref',
					'value' => '1',
				),
				array(
					'key'   => '_tw_thumb_ref_video',
					'value' => $video,
				),
				array(
					'key'   => '_tw_thumb_ref_hash',
					'value' => $hash,
				),
			),
		);
		$uid = get_current_user_id();
		if ( $uid ) {
			$args['author'] = $uid;
		}
		$query = new WP_Query( $args );
		if ( empty( $query->posts[0] ) ) {
			return 0;
		}
		return (int) $query->posts[0];
	}

	public function ajax_thumb_refs() {
		$this->ajax_guard();
		$video = sanitize_text_field( $this->post( 'video' ) );
		if ( '' === $video ) {
			wp_send_json_success( array( 'items' => array() ) );
		}
		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => 24,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'meta_query'     => array(
				array(
					'key'   => '_tw_thumb_ref',
					'value' => '1',
				),
				array(
					'key'   => '_tw_thumb_ref_video',
					'value' => $video,
				),
			),
		);
		$uid = get_current_user_id();
		if ( $uid ) {
			$args['author'] = $uid;
		}
		$query = new WP_Query( $args );
		$items = array();
		foreach ( $query->posts as $post ) {
			$items[] = $this->thumb_ref_payload( $post->ID );
		}
		wp_send_json_success( array( 'items' => $items ) );
	}

	public function ajax_thumb_ref_delete() {
		$this->ajax_guard();
		$id = absint( $this->post( 'id' ) );
		if ( ! $id || 'attachment' !== get_post_type( $id ) ) {
			wp_send_json_error( 'That saved reference was not found.' );
		}
		if ( '1' !== (string) get_post_meta( $id, '_tw_thumb_ref', true ) ) {
			wp_send_json_error( 'That file is not a UploadWriter reference.' );
		}
		$uid = get_current_user_id();
		if ( $uid && (int) get_post_field( 'post_author', $id ) !== $uid ) {
			wp_send_json_error( 'Not allowed.', 403 );
		}
		$video = sanitize_text_field( $this->post( 'video' ) );
		if ( '' !== $video ) {
			$tagged = (string) get_post_meta( $id, '_tw_thumb_ref_video', true );
			if ( '' !== $tagged && $tagged !== $video ) {
				wp_send_json_error( 'That reference belongs to another video.' );
			}
		}
		$deleted = wp_delete_attachment( $id, true );
		if ( ! $deleted ) {
			wp_send_json_error( 'Could not delete that reference.' );
		}
		wp_send_json_success( array( 'id' => $id ) );
	}

	/** List the current user's AI-generated thumbnail library + usage. */
	public function ajax_thumb_library() {
		$this->ajax_guard();
		wp_send_json_success( TW_Thumb_Library::list_for_user() );
	}

	/** Delete one item from the generated thumbnail library. */
	public function ajax_thumb_library_delete() {
		$this->ajax_guard();
		$id  = absint( $this->post( 'id' ) );
		$uid = get_current_user_id();
		$out = TW_Thumb_Library::delete_owned( $id, $uid );
		if ( is_wp_error( $out ) ) {
			$status = (int) ( $out->get_error_data()['status'] ?? 400 );
			wp_send_json_error( $out->get_error_message(), $status ? $status : 400 );
		}
		wp_send_json_success( TW_Thumb_Library::list_for_user( $uid ) );
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
		$this->require_thumbnails();
		$this->require_identity();
		$input  = $this->input();
		$images = $this->images_from_post();
		if ( is_wp_error( $images ) ) {
			wp_send_json_error( $images->get_error_message() );
		}
		$out = TW_AI::describe_thumbnail( $input, $images, $this->pkg_from_post(), $this->text_provider() );
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
				'thumb_described'   => 1,
			)
		);
		$out['thumb_described'] = 1;
		$usage = TW_AI::last_usage();
		if ( $usage ) {
			$out['ai_usage'] = $usage;
		}
		$out['quota'] = TW_YouTube::quota_used();
		wp_send_json_success( $out );
	}

	public function ajax_thumb_generate() {
		$this->ajax_guard();
		$this->require_thumbnails();
		$this->require_identity();
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
		$image = TW_AI::generate_thumbnail( $description, $images, $logos, $this->text_provider() );
		if ( is_wp_error( $image ) ) {
			wp_send_json_error( $image->get_error_message() );
		}

		$saved = $this->store_thumbnail( $input['video'], $image['data'], $image['mime'] );
		if ( is_wp_error( $saved ) ) {
			wp_send_json_error( $saved->get_error_message() );
		}

		$registered = TW_Thumb_Library::register_generated(
			$saved['file'],
			$saved['url'],
			$input['video'],
			$image['mime']
		);
		if ( is_wp_error( $registered ) ) {
			self::delete_generated_thumb_file( $saved['file'] );
			wp_send_json_error( $registered->get_error_message() );
		}

		$this->save_state(
			$input['video'],
			array(
				'thumb_description'   => $description,
				'thumb_url'           => $registered['url'],
				'thumb_file'          => $registered['file'],
				'thumb_attachment_id' => (int) $registered['id'],
				'thumb_generated'     => 1,
				'thumb_uploaded'      => 0,
				'thumb_yt_url'        => '',
			)
		);

		$payload = array(
			'url'             => $registered['url'],
			'id'              => (int) $registered['id'],
			'mime'            => $image['mime'],
			'description'     => $description,
			'thumb_generated' => 1,
			'quota'           => TW_YouTube::quota_used(),
			'library'         => TW_Thumb_Library::list_for_user(),
		);
		$usage = TW_AI::last_usage();
		if ( $usage ) {
			$payload['ai_usage'] = $usage;
		}
		if ( class_exists( 'TW_Launch' ) ) {
			$quality = strtolower( (string) $this->post( 'image_quality' ) );
			$detail  = ( false !== strpos( $quality, 'high' ) || 'hd' === $quality ) ? 'high' : 'standard';
			TW_Launch::record_thumbnail_credit( $detail );
		}
		wp_send_json_success( $payload );
	}

	public function ajax_thumb_upload() {
		$this->ajax_guard();
		$video_id = $this->post( 'video' );
		$owned    = $this->assert_writable_video( $video_id );
		if ( is_wp_error( $owned ) ) {
			wp_send_json_error( $owned->get_error_message() );
		}

		$rl_opts = array( 'pace_ack' => $this->pace_acked() );
		$rl      = TW_Rate_Limit::check( 'thumb_upload', $video_id, $rl_opts );
		if ( is_wp_error( $rl ) ) {
			$this->send_rate_error( $rl );
		}

		$key   = 'state_' . preg_replace( '/[^A-Za-z0-9_-]/', '', $video_id );
		$state = TW_Store::get( $key, array() );
		if ( ! is_array( $state ) ) {
			$state = array();
		}

		$resolved = $this->resolve_draft_thumb_file( $state );
		if ( is_wp_error( $resolved ) ) {
			wp_send_json_error( $resolved->get_error_message() );
		}

		$file = $resolved['file'];
		if ( (string) ( $state['thumb_file'] ?? '' ) !== $file ) {
			$this->save_state(
				$video_id,
				array(
					'thumb_file' => $file,
					'thumb_url'  => $resolved['url'] ?: (string) ( $state['thumb_url'] ?? '' ),
				)
			);
		}

		$bin = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $bin || '' === $bin ) {
			wp_send_json_error( 'Could not read the thumbnail file. Choose it again from the Media Library.' );
		}

		$mime = 'image/png';
		if ( preg_match( '/\.jpe?g$/i', $file ) ) {
			$mime = 'image/jpeg';
		} elseif ( preg_match( '/\.webp$/i', $file ) ) {
			wp_send_json_error( 'YouTube needs PNG or JPEG. Choose a PNG or JPEG, or create the thumbnail again.' );
		}

		$result = TW_YouTube::set_thumbnail( $video_id, $bin, $mime );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}
		TW_Rate_Limit::hit( 'thumb_upload', $video_id, $rl_opts );

		$sizes = $result['items'][0] ?? array();
		// Editor preview can use high. List cards cache the smaller medium/default.
		$thumb_url = $sizes['high']['url']
			?? $sizes['medium']['url']
			?? $sizes['default']['url']
			?? (string) ( $resolved['url'] ?: ( $state['thumb_url'] ?? '' ) );
		$list_url  = $sizes['medium']['url']
			?? $sizes['default']['url']
			?? $sizes['high']['url']
			?? $thumb_url;

		$this->save_state(
			$video_id,
			array(
				'thumb_uploaded' => time(),
				'thumb_yt_url'   => $thumb_url,
			)
		);

		// Seed the list cache from the file we just uploaded first. videos.list can
		// lag behind thumbnails.set and would otherwise pull the old CDN image.
		TW_Index::replace_thumbnail( $video_id, $list_url, $file );
		TW_Index::refresh_one( $video_id, false );

		wp_send_json_success(
			array(
				'ok'       => true,
				'message'  => 'Thumbnail uploaded to YouTube.',
				'url'      => $thumb_url,
				'list_url' => TW_Index::local_thumb_url( $video_id ) ?: $list_url,
				'uploaded' => time(),
				'quota'    => TW_YouTube::quota_used(),
			)
		);
	}

	/**
	 * Set the draft thumbnail from a Media Library attachment (PNG or JPEG).
	 */
	public function ajax_thumb_set() {
		$this->ajax_guard();
		$video_id = $this->post( 'video' );
		$att_id   = absint( $this->post( 'attachment_id' ) );
		if ( ! $video_id ) {
			wp_send_json_error( 'Missing video id.' );
		}
		if ( ! $att_id || 'attachment' !== get_post_type( $att_id ) ) {
			wp_send_json_error( 'Pick an image from the Media Library.' );
		}
		if ( ! $this->user_owns_attachment( $att_id ) ) {
			wp_send_json_error( 'That image is not yours.', 403 );
		}
		if ( 0 !== strpos( (string) get_post_mime_type( $att_id ), 'image/' ) ) {
			wp_send_json_error( 'That file is not an image.' );
		}

		$file = $this->safe_uploads_file( (string) get_attached_file( $att_id ) );
		$url  = (string) wp_get_attachment_url( $att_id );
		if ( '' === $file || ! is_readable( $file ) || '' === $url ) {
			wp_send_json_error( 'Could not read that image from the Media Library.' );
		}

		$mime = (string) get_post_mime_type( $att_id );
		if ( false !== strpos( $mime, 'webp' ) || preg_match( '/\.webp$/i', $file ) ) {
			wp_send_json_error( 'YouTube needs PNG or JPEG. Convert this image, or pick a different one.' );
		}
		if ( ! preg_match( '/\.(png|jpe?g)$/i', $file ) && false === strpos( $mime, 'png' ) && false === strpos( $mime, 'jpeg' ) && false === strpos( $mime, 'jpg' ) ) {
			wp_send_json_error( 'Use a PNG or JPEG for the YouTube thumbnail.' );
		}

		$uploads = wp_get_upload_dir();
		$basedir = wp_normalize_path( (string) ( $uploads['basedir'] ?? '' ) );
		if ( ! $basedir || 0 !== strpos( $file, $basedir ) ) {
			wp_send_json_error( 'That image is not in the uploads folder.' );
		}

		$key       = 'state_' . preg_replace( '/[^A-Za-z0-9_-]/', '', $video_id );
		$prev      = TW_Store::get( $key, array() );
		$prev_file = is_array( $prev ) ? (string) ( $prev['thumb_file'] ?? '' ) : '';
		// Keep prior AI generations in the library; only prune loose orphan files.
		if ( $prev_file && wp_normalize_path( $prev_file ) !== $file && ! TW_Thumb_Library::file_is_library( $prev_file ) ) {
			self::delete_generated_thumb_file( $prev_file );
		}

		$from_library = ( 'generated' === (string) get_post_meta( $att_id, TW_Thumb_Library::META, true ) );

		$this->save_state(
			$video_id,
			array(
				'thumb_url'           => $url,
				'thumb_file'          => $file,
				'thumb_attachment_id' => $att_id,
				'thumb_generated'     => $from_library ? 1 : 0,
				'thumb_uploaded'      => 0,
				'thumb_yt_url'        => '',
			)
		);

		wp_send_json_success(
			array(
				'url' => $url,
				'id'  => $att_id,
			)
		);
	}

	/** Clear the draft thumbnail without deleting the Media Library file. */
	public function ajax_thumb_clear() {
		$this->ajax_guard();
		$video_id = $this->post( 'video' );
		if ( ! $video_id ) {
			wp_send_json_error( 'Missing video id.' );
		}

		$this->save_state(
			$video_id,
			array(
				'thumb_url'           => '',
				'thumb_file'          => '',
				'thumb_attachment_id' => 0,
				'thumb_generated'     => 0,
				'thumb_uploaded'      => 0,
				'thumb_yt_url'        => '',
			)
		);

		wp_send_json_success( array( 'ok' => true ) );
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
		$this->require_identity();
		$pkg   = $this->pkg_from_post();
		$check = TW_Validator::check( $pkg, 0 );
		$out   = TW_AI::playlist(
			$this->post( 'rough' ),
			array( 'title' => $check['pkg']['title'], 'description' => $check['description'] )
		);
		if ( is_wp_error( $out ) ) {
			wp_send_json_error( $out->get_error_message() );
		}
		$this->save_state(
			$this->post( 'video' ),
			array(
				'playlist_polished' => 1,
			)
		);
		$out['playlist_polished'] = 1;
		wp_send_json_success( $out );
	}

	/** All workspace operations share the standard nonce, login, capability and account guard. */
	public function ajax_calendar_workspace() {
		$this->ajax_guard();
		$op = $this->post( 'op' );
		$in = json_decode( wp_unslash( $_POST['data'] ?? '{}' ), true );
		$in = is_array( $in ) ? $in : array();
		$result = true;
		if ( 'plan_save' === $op ) { $result = TW_Calendar::save_plan( $in ); }
		elseif ( 'plan_remove' === $op ) { $result = TW_Calendar::remove_plan( $in['id'] ?? 0 ); }
		elseif ( 'rhythms' === $op ) { $result = TW_Calendar::save_rhythms( $in ); }
		elseif ( 'timezone' === $op ) {
			if ( ! TW_Store::has_own( 'timezone' ) && TW_Settings::is_valid_timezone( $in['timezone'] ?? '' ) ) { TW_Store::set( 'timezone', $in['timezone'] ); }
			wp_send_json_success( true );
		}
		elseif ( 'feed' === $op ) {
			if ( ! in_array( $in['action'] ?? '', array( 'create', 'rotate', 'off', 'save' ), true ) ) { wp_send_json_error( 'Choose a feed action.' ); }
			wp_send_json_success( TW_Calendar::feed_settings( $in['action'], $in ) );
		}
		elseif ( in_array( $op, array( 'schedule_review', 'schedule_confirm' ), true ) ) {
			$id = sanitize_text_field( $in['video'] ?? '' );
			// Require a local owned row even when the account has an empty library.
			if ( ! TW_Index::exists( $id ) ) { wp_send_json_error( 'That video is not in your library.' ); }
			$live = $this->assert_writable_video( $id );
			if ( is_wp_error( $live ) ) { wp_send_json_error( $live->get_error_message() ); }
			$date = sanitize_text_field( $in['date'] ?? '' ); $time = sanitize_text_field( $in['time'] ?? '' );
			$plan = TW_Plan::build_schedule( $live, $date, $time );
			if ( $plan['errors'] ) { wp_send_json_error( implode( ' ', $plan['errors'] ) ); }
			$key = 'tw_cal_review_' . TW_Context::user_id() . '_' . $id;
			if ( 'schedule_review' === $op ) {
				$token = $this->issue_publish_token( $id, $plan['fingerprint'] );
				set_transient( $key, array( 'date' => $date, 'time' => $time, 'fingerprint' => $plan['fingerprint'] ), 15 * MINUTE_IN_SECONDS );
				wp_send_json_success( array( 'fingerprint' => $plan['fingerprint'], 'publish_token' => $token, 'label' => $plan['schedule']['label'], 'title' => $plan['current']['title'] ) );
			}
			if ( empty( $in['confirmed'] ) ) { wp_send_json_error( 'Confirm the schedule change first.' ); }
			$review = get_transient( $key );
			if ( ! is_array( $review ) || $review['date'] !== $date || $review['time'] !== $time || ! hash_equals( $review['fingerprint'], (string) ( $in['fingerprint'] ?? '' ) ) ) { wp_send_json_error( 'The date or time changed after review. Review again.' ); }
			$rl_opts = array( 'pace_ack' => ! empty( $in['pace_ack'] ), 'high_risk' => false );
			$rl = TW_Rate_Limit::check( 'publish', $id, $rl_opts );
			if ( is_wp_error( $rl ) ) { $this->send_rate_error( $rl ); }
			$valid = $this->consume_publish_token( $id, (string) ( $in['publish_token'] ?? '' ), (string) ( $in['fingerprint'] ?? '' ) );
			if ( is_wp_error( $valid ) ) { wp_send_json_error( $valid->get_error_message() ); }
			delete_transient( $key );
			$result = TW_Plan::publish( $id, array(), array( 'schedule' => array( 'date' => $date, 'time' => $time ) ), 0, $in['fingerprint'], true );
			if ( is_wp_error( $result ) ) { wp_send_json_error( $result->get_error_message() ); }
			if ( ! empty( $result['wrote'] ) ) { TW_Rate_Limit::hit( 'publish', $id, $rl_opts ); }
			if ( empty( $result['wrote'] ) ) { wp_send_json_error( $result['message'] ); }
			TW_Index::refresh_one( $id ); TW_Index::apply_visibility( $id, 'set', $result['schedule']['at'] );
			// A schedule change must not mark an unpushed metadata draft as pushed.
			wp_send_json_success( array( 'backup' => $result['backup'], 'video' => $id ) );
		}
		elseif ( 'load' !== $op ) { wp_send_json_error( 'Unknown calendar action.' ); }
		if ( is_wp_error( $result ) ) { wp_send_json_error( $result->get_error_message() ); }
		if ( false === $result ) { wp_send_json_error( 'Could not save your changes.' ); }
		wp_send_json_success( TW_Calendar::workspace() );
	}

	public function ajax_calendar() {
		$this->ajax_guard();
		if ( '1' === $this->post( 'force' ) ) {
			$sync = TW_Index::sync_batch( 'quick' );
			if ( is_wp_error( $sync ) ) {
				wp_send_json_error( $sync->get_error_message() );
			}
		}
		$tz  = TW_Settings::timezone();
		$out = array();
		foreach ( TW_YouTube::calendar_events() as $e ) {
			$ts    = strtotime( $e['at'] );
			$thumb = (string) ( $e['thumb'] ?? '' );
			$out[] = array(
				'id'    => $e['id'],
				'title' => $e['title'],
				'type'  => $e['type'],
				'date'  => wp_date( 'Y-m-d', $ts, $tz ),
				'time'  => wp_date( 'g:ia', $ts, $tz ),
				'thumb' => $thumb ? TW_Index::thumb_url_with_version( $e['id'], $thumb ) : '',
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

	/**
	 * Set YouTube privacy immediately (private|unlisted|public). ~50 units when it changes.
	 * Uses the same helper as Muse PATCH privacy (immediate mode for the signed-in user).
	 */
	public function ajax_privacy() {
		$this->ajax_guard();
		$video_id = $this->post( 'video' );
		$privacy  = $this->post( 'privacy' );
		$owned    = $this->assert_writable_video( $video_id );
		if ( is_wp_error( $owned ) ) {
			wp_send_json_error( $owned->get_error_message() );
		}
		if ( ! function_exists( 'uw_muse_apply_privacy' ) ) {
			wp_send_json_error( 'Privacy helper is not loaded.' );
		}
		$result = uw_muse_apply_privacy( $video_id, $privacy, false, true, '', true );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}
		$index_privacy = TW_Index::privacy_of( $video_id );
		$badge         = $index_privacy ? $index_privacy : ( $result['privacyStatus'] ?? $privacy );
		wp_send_json_success(
			array(
				'privacy'       => $badge,
				'privacyStatus' => $result['privacyStatus'] ?? $privacy,
				'written'       => ! empty( $result['written'] ),
				'units_used'    => (int) ( $result['units_used'] ?? 0 ),
				'quota'         => TW_YouTube::quota_used(),
				'message'       => ! empty( $result['written'] )
					? 'Visibility set to ' . $privacy . ' on YouTube.'
					: 'Already ' . $privacy . ' on YouTube.',
			)
		);
	}

	/**
	 * Re-fetch one video from YouTube and update the local index (about 1 unit).
	 * Does not write to YouTube. Used by the editor Refresh from YouTube control.
	 */
	public function ajax_refresh() {
		$this->ajax_guard();
		$video_id = $this->post( 'video' );
		if ( ! $video_id ) {
			wp_send_json_error( 'Missing video id.' );
		}

		$video = TW_YouTube::get_video( $video_id );
		if ( is_wp_error( $video ) ) {
			wp_send_json_error( $video->get_error_message() );
		}

		TW_Index::refresh_one( $video_id, true );

		$snap          = TW_YouTube::snapshot( $video );
		$secs          = TW_Transcript::duration_seconds( $video['contentDetails']['duration'] ?? '' );
		$index_privacy = TW_Index::privacy_of( $video_id );
		$badge_privacy = ( 'draft' === $index_privacy ) ? 'draft' : $snap['privacyStatus'];
		$thumb         = TW_Index::local_thumb_url( $video_id );
		$thumb         = $thumb ? TW_Index::thumb_url_with_version( $video_id, $thumb ) : '';
		$publish_at    = $snap['publishAt'];

		wp_send_json_success(
			array(
				'current'        => $snap,
				'privacy'        => $badge_privacy,
				'duration'       => $secs,
				'duration_label' => $secs ? TW_Transcript::fmt( $secs ) : '',
				'thumb'          => $thumb,
				'publishAtLocal' => $publish_at
					? array(
						'date' => wp_date( 'Y-m-d', strtotime( $publish_at ), TW_Settings::timezone() ),
						'time' => wp_date( 'H:i', strtotime( $publish_at ), TW_Settings::timezone() ),
					)
					: null,
				'quota'          => TW_YouTube::quota_used(),
			)
		);
	}

	public function ajax_review() {
		$this->ajax_guard();
		$input = $this->input();
		$video = $this->assert_writable_video( $input['video'] );
		if ( is_wp_error( $video ) ) {
			wp_send_json_error( $video->get_error_message() );
		}
		$plan = TW_Plan::build( $video, $this->pkg_from_post(), $this->opts_from_post(), $input['duration'] );
		$plan['publish_token'] = $this->issue_publish_token( $input['video'], $plan['fingerprint'] );
		unset( $plan['details'], $plan['current'] );
		wp_send_json_success( $plan );
	}

	public function ajax_publish() {
		$this->ajax_guard();
		$input = $this->input();
		$owned = $this->assert_writable_video( $input['video'] );
		if ( is_wp_error( $owned ) ) {
			wp_send_json_error( $owned->get_error_message() );
		}

		$opts    = $this->opts_from_post();
		$rl_opts = array(
			'pace_ack'  => $this->pace_acked(),
			'high_risk' => ( 'now' === ( $opts['schedule']['mode'] ?? '' ) ),
		);
		$rl = TW_Rate_Limit::check( 'publish', $input['video'], $rl_opts );
		if ( is_wp_error( $rl ) ) {
			$this->send_rate_error( $rl );
		}

		$token_ok = $this->consume_publish_token( $input['video'], $this->post( 'publish_token' ), $this->post( 'fingerprint' ) );
		if ( is_wp_error( $token_ok ) ) {
			wp_send_json_error( $token_ok->get_error_message() );
		}

		$result = TW_Plan::publish( $input['video'], $this->pkg_from_post(), $opts, $input['duration'], $this->post( 'fingerprint' ) );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}
		TW_Rate_Limit::hit( 'publish', $input['video'], $rl_opts );
		$this->save_state(
			$input['video'],
			array(
				'transcript' => $input['transcript'],
				'notes'      => $input['notes'],
				'seed_title' => $input['current_title'],
				'seed_desc'  => $input['current_description'],
				'pkg'        => $this->pkg_from_post(),
				'opts'       => $opts,
				'pushed'     => time(),
			)
		);
		// videos.list can lag videos.update for privacyStatus (same as thumbs).
		// Refresh metadata, then seed visibility from what we just wrote.
		TW_Index::refresh_one( $input['video'] );
		if ( ! empty( $result['wrote'] ) ) {
			$sched = (array) ( $result['schedule'] ?? array() );
			$mode  = $sched['mode'] ?? 'keep';
			if ( 'keep' === $mode ) {
				$opt_privacy = (string) ( $opts['privacy'] ?? '' );
				if ( in_array( $opt_privacy, array( 'private', 'unlisted', 'public' ), true ) ) {
					$mode = $opt_privacy;
				}
			}
			TW_Index::apply_visibility( $input['video'], $mode, $sched['at'] ?? '' );
		}
		TW_Index::set_tw_status( $input['video'], 'pushed' );
		$result['history'] = TW_History::summary( $input['video'] );
		wp_send_json_success( $result );
	}

	public function ajax_restore() {
		$this->ajax_guard();
		$video = $this->post( 'video' );
		$owned = $this->assert_writable_video( $video );
		if ( is_wp_error( $owned ) ) {
			wp_send_json_error( $owned->get_error_message() );
		}

		$rl_opts = array( 'pace_ack' => $this->pace_acked() );
		$rl      = TW_Rate_Limit::check( 'restore', $video, $rl_opts );
		if ( is_wp_error( $rl ) ) {
			$this->send_rate_error( $rl );
		}

		$result = TW_Plan::restore( $video, $this->post( 'entry' ) );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}
		TW_Rate_Limit::hit( 'restore', $video, $rl_opts );
		TW_Index::refresh_one( $video );
		if ( ! empty( $result['wrote'] ) ) {
			$sched = (array) ( $result['schedule'] ?? array() );
			TW_Index::apply_visibility( $video, $sched['mode'] ?? 'keep', $sched['at'] ?? '' );
		}
		$result['history'] = TW_History::summary( $video );
		wp_send_json_success( $result );
	}


	/* ---------------------------------------------------------------- Helpers */

	private function respond_with_package( array $input, $pkg, $kind = 'generate' ) {
		if ( is_wp_error( $pkg ) ) {
			wp_send_json_error( $pkg->get_error_message() );
		}
		$check = TW_Validator::check( $pkg, $input['duration'] );
		// Keep the AI's title options even though check() only returns the chosen one cleaned.
		$check['pkg']['titles'] = array_map( array( 'TW_Validator', 'clean_text' ), $pkg['titles'] );
		$extra                  = array(
			'transcript'   => $input['transcript'],
			'notes'        => $input['notes'],
			'seed_title'   => $input['current_title'],
			'seed_desc'    => $input['current_description'],
			'pkg'          => $check['pkg'],
			'ai_generated' => 1,
		);
		if ( 'revise' === $kind ) {
			$extra['ai_revised'] = 1;
		}
		$this->save_state( $input['video'], $extra );
		$check['ai_generated'] = 1;
		if ( 'revise' === $kind ) {
			$check['ai_revised'] = 1;
		}
		$usage = TW_AI::last_usage();
		if ( $usage ) {
			$check['ai_usage'] = $usage;
		}
		$check['quota'] = TW_YouTube::quota_used();
		if ( class_exists( 'TW_Launch' ) ) {
			TW_Launch::record_writing_credit();
		}
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
				'mode' => in_array( $sc['mode'] ?? '', array( 'keep', 'set', 'now' ), true ) ? $sc['mode'] : 'keep',
				'date' => preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $sc['date'] ?? '' ) ) ? $sc['date'] : '',
				'time' => preg_match( '/^\d{2}:\d{2}$/', (string) ( $sc['time'] ?? '' ) ) ? $sc['time'] : '',
			),
		);
	}

	private function save_state( $video_id, array $data ) {
		if ( ! $video_id ) {
			return;
		}
		$key   = 'state_' . preg_replace( '/[^A-Za-z0-9_-]/', '', $video_id );
		$state = TW_Store::get( $key, array() );
		$state = array_merge( is_array( $state ) ? $state : array(), $data );
		TW_Store::set( $key, $state );
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
			array( 'channel_logo_id', 'channel_logo_pos', 'Channel logo', 'lower-left' ),
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
		$posted = wp_unslash( $_POST['images'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( '' === $posted || null === $posted ) {
			return array();
		}
		$raw = json_decode( $posted, true );
		if ( ! is_array( $raw ) ) {
			return new WP_Error( 'tw_ai', 'Could not read the pasted screenshots. Paste them again into the inspiration box, then retry.' );
		}
		return $raw;
	}

	/**
	 * Save a generated thumbnail under uploads/tubewriter/generated/.
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

		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return new WP_Error( 'tw_ai', 'Could not save the thumbnail: ' . $uploads['error'] );
		}
		$dir = trailingslashit( $uploads['basedir'] ) . 'tubewriter/generated';
		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'tw_ai', 'Could not create the thumbnail folder.' );
		}
		$file = trailingslashit( $dir ) . $name;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === file_put_contents( $file, $bin ) ) {
			return new WP_Error( 'tw_ai', 'Could not save the thumbnail file.' );
		}

		$url = trailingslashit( $uploads['baseurl'] ) . 'tubewriter/generated/' . $name;
		$scheme = wp_parse_url( home_url(), PHP_URL_SCHEME );
		return array(
			'url'  => set_url_scheme( $url, $scheme ? $scheme : 'https' ),
			'file' => $file,
		);
	}

	/**
	 * Find a readable draft thumbnail under uploads.
	 * Tries thumb_file, then Media Library attachment id, then a local thumb_url.
	 *
	 * @param array $state
	 * @return array{file: string, url: string}|WP_Error
	 */
	private function resolve_draft_thumb_file( array $state ) {
		$uploads = wp_get_upload_dir();
		$basedir = wp_normalize_path( (string) ( $uploads['basedir'] ?? '' ) );
		$baseurl = untrailingslashit( (string) ( $uploads['baseurl'] ?? '' ) );
		if ( ! $basedir || ! $baseurl ) {
			return new WP_Error( 'tw_thumb', 'Uploads folder is not available.' );
		}

		$candidates = array();
		$stored     = (string) ( $state['thumb_file'] ?? '' );
		if ( '' !== $stored ) {
			$candidates[] = $stored;
		}

		$att_id = absint( $state['thumb_attachment_id'] ?? 0 );
		if ( $att_id ) {
			if ( ! $this->user_owns_attachment( $att_id ) ) {
				return new WP_Error( 'tw_thumb', 'That thumbnail is not yours.' );
			}
			$attached = (string) get_attached_file( $att_id );
			if ( '' !== $attached ) {
				$candidates[] = $attached;
			}
		}

		$url = (string) ( $state['thumb_url'] ?? '' );
		if ( $url ) {
			$from_url = $this->uploads_url_to_file( $url, $basedir, $baseurl );
			if ( $from_url ) {
				$candidates[] = $from_url;
			}
		}

		$seen = array();
		foreach ( $candidates as $file ) {
			$file = $this->safe_uploads_file( $file, $basedir );
			if ( '' === $file || isset( $seen[ $file ] ) ) {
				continue;
			}
			$seen[ $file ] = true;
			if ( ! is_readable( $file ) ) {
				continue;
			}
			$out_url = $url;
			if ( '' === $out_url ) {
				$rel     = ltrim( substr( $file, strlen( $basedir ) ), '/\\' );
				$out_url = trailingslashit( $baseurl ) . str_replace( '\\', '/', $rel );
			}
			return array(
				'file' => $file,
				'url'  => $out_url,
			);
		}

		if ( $url || $stored || $att_id ) {
			return new WP_Error(
				'tw_thumb',
				'The staged thumbnail file is missing. Click Choose from Media Library and pick it again, or create a new thumbnail.'
			);
		}
		return new WP_Error( 'tw_thumb', 'Create or choose a thumbnail first, then upload it.' );
	}

	/**
	 * Map a local uploads URL to an absolute file path inside uploads.
	 *
	 * @param string $url
	 * @param string $basedir Normalized uploads basedir.
	 * @param string $baseurl Uploads baseurl without trailing slash.
	 * @return string Empty when the URL is not under uploads or escapes it.
	 */
	private function uploads_url_to_file( $url, $basedir, $baseurl ) {
		$url = (string) $url;
		if ( '' === $url || false !== strpos( $url, '..' ) ) {
			return '';
		}
		$variants = array_unique(
			array(
				untrailingslashit( $baseurl ),
				untrailingslashit( set_url_scheme( $baseurl, 'https' ) ),
				untrailingslashit( set_url_scheme( $baseurl, 'http' ) ),
			)
		);
		$path = wp_parse_url( $url, PHP_URL_PATH );
		$raw  = '';
		foreach ( $variants as $root ) {
			$root_path = wp_parse_url( $root, PHP_URL_PATH );
			if ( $root_path && $path && 0 === strpos( $path, $root_path ) ) {
				$rel = ltrim( substr( $path, strlen( $root_path ) ), '/' );
				if ( false !== strpos( $rel, '..' ) ) {
					return '';
				}
				$raw = trailingslashit( $basedir ) . $rel;
				break;
			}
			if ( 0 === strpos( $url, $root . '/' ) ) {
				$rel = substr( $url, strlen( $root ) + 1 );
				$rel = (string) strtok( $rel, '?' );
				if ( false !== strpos( $rel, '..' ) ) {
					return '';
				}
				$raw = trailingslashit( $basedir ) . $rel;
				break;
			}
		}
		return $this->safe_uploads_file( $raw, $basedir );
	}

	/**
	 * Resolve a path and require it to stay inside the uploads basedir.
	 *
	 * @param string      $file    Candidate path.
	 * @param string|null $basedir Optional basedir.
	 * @return string Empty when unsafe or missing.
	 */
	private function safe_uploads_file( $file, $basedir = null ) {
		$file = (string) $file;
		if ( '' === $file || false !== strpos( $file, '..' ) ) {
			return '';
		}
		if ( null === $basedir ) {
			$uploads = wp_get_upload_dir();
			$basedir = wp_normalize_path( (string) ( $uploads['basedir'] ?? '' ) );
		}
		$basedir = wp_normalize_path( (string) $basedir );
		if ( '' === $basedir ) {
			return '';
		}
		$real_base = realpath( $basedir );
		if ( false === $real_base ) {
			return '';
		}
		$real_base = wp_normalize_path( $real_base );
		$normalized = wp_normalize_path( $file );
		// File may not exist yet. Check parent when needed.
		$real = realpath( $normalized );
		if ( false === $real ) {
			$parent = realpath( dirname( $normalized ) );
			if ( false === $parent ) {
				return '';
			}
			$real = wp_normalize_path( $parent ) . '/' . wp_basename( $normalized );
		} else {
			$real = wp_normalize_path( $real );
		}
		$prefix = trailingslashit( $real_base );
		if ( 0 !== strpos( $real, $prefix ) && $real !== $real_base ) {
			return '';
		}
		return $real;
	}

	/**
	 * Admins may use any attachment. Everyone else only their own.
	 *
	 * @param int $attachment_id Attachment id.
	 * @return bool
	 */
	private function user_owns_attachment( $attachment_id ) {
		if ( class_exists( 'TW_Privacy' ) ) {
			return TW_Privacy::user_owns_attachment( $attachment_id );
		}
		$attachment_id = (int) $attachment_id;
		if ( ! $attachment_id ) {
			return false;
		}
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		$uid = get_current_user_id();
		return $uid && (int) get_post_field( 'post_author', $attachment_id ) === $uid;
	}

	/**
	 * Delete a UploadWriter-generated thumb file. Never touches Media Library attachments.
	 *
	 * @param string $file Absolute path.
	 */
	public static function delete_generated_thumb_file( $file ) {
		$file = wp_normalize_path( (string) $file );
		if ( '' === $file || ! is_file( $file ) ) {
			return;
		}
		$uploads = wp_get_upload_dir();
		$base    = wp_normalize_path( trailingslashit( (string) ( $uploads['basedir'] ?? '' ) ) . 'tubewriter/generated' );
		if ( ! $base || 0 !== strpos( $file, $base ) ) {
			return;
		}
		if ( ! preg_match( '#/tw-thumb-[^/]+\.(png|jpe?g|webp)$#i', $file ) ) {
			return;
		}
		wp_delete_file( $file );
	}

	/**
	 * Daily cron: remove orphan generated thumbs older than 7 days.
	 */
	public static function cleanup_generated_thumbs() {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return;
		}
		$dir = trailingslashit( $uploads['basedir'] ) . 'tubewriter/generated';
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$keep    = self::referenced_thumb_files();
		$library = TW_Thumb_Library::library_files();
		$cutoff  = time() - ( 7 * DAY_IN_SECONDS );
		$files   = glob( $dir . '/tw-thumb-*.*' );
		if ( ! is_array( $files ) ) {
			return;
		}

		foreach ( $files as $file ) {
			if ( ! is_file( $file ) ) {
				continue;
			}
			$norm = wp_normalize_path( $file );
			if ( isset( $keep[ $norm ] ) || isset( $library[ $norm ] ) ) {
				continue;
			}
			$mtime = @filemtime( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( ! $mtime || $mtime > $cutoff ) {
				continue;
			}
			self::delete_generated_thumb_file( $file );
		}
	}

	/**
	 * Absolute thumb_file paths still referenced by any draft state.
	 *
	 * @return array<string,true>
	 */
	private static function referenced_thumb_files() {
		global $wpdb;
		$keep = array();
		$vals = $wpdb->get_col( "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE 'tw_state\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$meta = $wpdb->get_col( "SELECT meta_value FROM {$wpdb->usermeta} WHERE meta_key LIKE 'tw_state\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( ! is_array( $vals ) ) {
			$vals = array();
		}
		if ( ! is_array( $meta ) ) {
			$meta = array();
		}
		foreach ( array_merge( $vals, $meta ) as $raw ) {
			$state = maybe_unserialize( $raw );
			if ( ! is_array( $state ) ) {
				continue;
			}
			$file = wp_normalize_path( (string) ( $state['thumb_file'] ?? '' ) );
			if ( '' !== $file ) {
				$keep[ $file ] = true;
			}
		}
		return $keep;
	}

	/** Gemini or ChatGPT, from the editor dropdown. */
	private function text_provider() {
		return TW_AI::text_provider( $this->post( 'provider' ) );
	}

	/** AI thumbnail brief/create: admins always; other users only when the site setting is on. */
	private function require_thumbnails() {
		if ( ! tw_user_can_thumbnails() ) {
			wp_send_json_error( 'AI thumbnail tools are turned off for this account. Ask a site admin to enable them in UploadWriter Settings.', 403 );
		}
	}

	private function ajax_guard() {
		check_ajax_referer( 'tw_ajax', 'nonce' );
		// Editor AJAX is front-end only (TW.front → tw_front=1). Site-scoped admin AJAX is retired.
		if ( empty( $_POST['tw_front'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			wp_send_json_error( 'Use the UploadWriter app at /tubewriter/account/.', 403 );
		}
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( 'Please log in again.', 403 );
		}
		if ( ! tw_user_can_app() ) {
			wp_send_json_error( 'Not allowed.', 403 );
		}
		TW_Context::set_user( get_current_user_id() );
	}

	/**
	 * Ensure the video belongs to the connected channel (and local index when populated).
	 *
	 * @param string     $video_id
	 * @param array|null $video Optional already-fetched videos.list item.
	 * @return array|WP_Error
	 */
	private function assert_writable_video( $video_id, $video = null ) {
		$video_id = (string) $video_id;
		if ( '' === $video_id ) {
			return new WP_Error( 'tw_video', 'Missing video id.' );
		}
		if ( null === $video ) {
			$video = TW_YouTube::get_video( $video_id );
			if ( is_wp_error( $video ) ) {
				return $video;
			}
		}

		$channel    = TW_Store::get( 'channel', array() );
		$channel_id = is_array( $channel ) ? (string) ( $channel['id'] ?? '' ) : '';
		$video_ch   = (string) ( $video['snippet']['channelId'] ?? '' );
		if ( $channel_id && $video_ch && $channel_id !== $video_ch ) {
			return new WP_Error( 'tw_video', 'That video is not on the connected YouTube channel.' );
		}
		if ( TW_Index::count_all() > 0 && ! TW_Index::exists( $video_id ) ) {
			return new WP_Error( 'tw_video', 'That video is not in your UploadWriter library. Sync your channel, then open it from Videos.' );
		}
		return $video;
	}

	private function publish_token_key( $video_id ) {
		$uid = TW_Context::is_user() ? TW_Context::user_id() : get_current_user_id();
		return 'tw_pub_' . (int) $uid . '_' . preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $video_id );
	}

	private function issue_publish_token( $video_id, $fingerprint ) {
		$token = wp_generate_password( 32, false, false );
		set_transient(
			$this->publish_token_key( $video_id ),
			array(
				'token'       => $token,
				'fingerprint' => (string) $fingerprint,
			),
			15 * MINUTE_IN_SECONDS
		);
		return $token;
	}

	/**
	 * One-time Review→Publish token. Consumed whether or not publish later succeeds.
	 *
	 * @return true|WP_Error
	 */
	private function consume_publish_token( $video_id, $token, $fingerprint ) {
		$key  = $this->publish_token_key( $video_id );
		$data = get_transient( $key );
		delete_transient( $key );
		if ( ! is_array( $data ) || empty( $data['token'] ) ) {
			return new WP_Error( 'tw_publish_token', 'Review this video again, then publish. The confirmation expired.' );
		}
		if ( ! hash_equals( (string) $data['token'], (string) $token ) ) {
			return new WP_Error( 'tw_publish_token', 'Review this video again, then publish. The confirmation was invalid.' );
		}
		if ( ! hash_equals( (string) ( $data['fingerprint'] ?? '' ), (string) $fingerprint ) ) {
			return new WP_Error( 'tw_publish_token', 'This video changed after review. Run Review again, then publish.' );
		}
		return true;
	}

	private function send_rate_error( WP_Error $err ) {
		$data = $err->get_error_data();
		if ( is_array( $data ) && ! empty( $data['needs_confirm'] ) ) {
			wp_send_json_error(
				array(
					'code'           => $err->get_error_code(),
					'message'        => $err->get_error_message(),
					'needs_confirm'  => true,
				)
			);
		}
		wp_send_json_error( $err->get_error_message() );
	}

	private function pace_acked() {
		return '1' === $this->post( 'pace_ack' );
	}

	/** Block AI calls until Channel identity is saved on the front-end account. */
	private function require_identity() {
		if ( TW_Settings::identity_complete() ) {
			return;
		}
		$url = class_exists( 'TW_Frontend' )
			? TW_Frontend::url( 'account' ) . '#tw-voice'
			: home_url( '/tubewriter/account/#tw-voice' );
		wp_send_json_error( 'Set up your channel identity first: ' . $url );
	}

	private function guard( $action, $method = 'POST' ) {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( $action );
	}

	private function back( $page, $message, $type = 'success' ) {
		set_transient( 'tw_notice_' . get_current_user_id(), array( 'message' => $message, 'type' => $type ), 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=tubewriter' ) );
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
