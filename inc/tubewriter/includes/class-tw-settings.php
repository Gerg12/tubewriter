<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Option storage. Everything lives in wp_options on the user's own local site.
 * Front-end accounts store identity and prompts in user meta via TW_Store.
 */
class TW_Settings {

	const KEYS = array(
		'client_id',
		'client_secret',
		'gemini_key',
		'openai_key',
		'openai_model',
		'model',
		'image_provider',
		'image_model',
		'openai_image_model',
		'openai_image_quality',
		'prompt',
		'thumb_prompt',
		'thumb_render_prompt',
		'thumb_ai_for_users',
		'default_time',
		'default_language',
		'default_category',
		'channel_name',
		'channel_short',
		'creator_name',
		'brand_tag',
		'brand_hashtag',
		'warning_title',
		'warning_close',
		'chapters_heading',
		'channel_logo_brief',
		'identity_complete',
	);

	const DEFAULTS = array(
		'default_time'         => '07:30',
		'default_language'     => 'en',
		'default_category'     => '20',
		'openai_image_quality' => 'high',
		'thumb_ai_for_users'   => '',
	);

	/** Example values shown in onboarding (Gregatron). Not auto-saved for new users. */
	const IDENTITY_EXAMPLES = array(
		'channel_name'       => 'Gregatron Games',
		'channel_short'      => 'GG',
		'creator_name'       => 'Greg',
		'brand_tag'          => 'Gregatron Games',
		'brand_hashtag'      => '#GregatronGames',
		'warning_title'      => 'THE GG ALGORITHM WARNING',
		'warning_close'      => 'The slop will continue until morale improves.',
		'chapters_heading'   => '🛑 THE CERTIFIED CHRONO SLOP BREAKDOWN 🛑',
		'channel_logo_brief' => 'Stacked mark: large orange GG with a heavy black outline, and GREGATRON GAMES in smaller white block letters underneath.',
	);

	const IDENTITY_REQUIRED = array(
		'channel_name',
		'creator_name',
		'brand_hashtag',
		'warning_title',
		'warning_close',
		'chapters_heading',
		'channel_logo_brief',
	);

	const IDENTITY_KEYS = array(
		'channel_name',
		'channel_short',
		'creator_name',
		'brand_tag',
		'brand_hashtag',
		'warning_title',
		'warning_close',
		'chapters_heading',
		'channel_logo_brief',
	);

	const IMAGE_PROVIDERS = array(
		'openai' => 'OpenAI (ChatGPT image)',
		'gemini' => 'Gemini',
	);

	const OPENAI_QUALITIES = array(
		'low'    => 'Instant',
		'medium' => 'Medium',
		'high'   => 'High',
		'auto'   => 'Auto',
	);

	/** Video language choices (YouTube language codes). */
	const LANGUAGES = array(
		'en'    => 'English',
		'en-US' => 'English (United States)',
		'en-GB' => 'English (United Kingdom)',
		'es'    => 'Spanish',
		'fr'    => 'French',
		'de'    => 'German',
		'pt-BR' => 'Portuguese (Brazil)',
		'ja'    => 'Japanese',
		'ko'    => 'Korean',
	);

	/** Common YouTube category ids (US region). */
	const CATEGORIES = array(
		'20' => 'Gaming',
		'24' => 'Entertainment',
		'23' => 'Comedy',
		'22' => 'People & Blogs',
		'28' => 'Science & Technology',
		'27' => 'Education',
	);

	const CRON_CLEANUP_THUMBS = 'tw_cleanup_generated_thumbs';

	public static function activate() {
		if ( false === get_option( 'tw_prompt', false ) ) {
			add_option( 'tw_prompt', self::default_prompt(), '', false );
		}
		if ( false === get_option( 'tw_thumb_prompt', false ) ) {
			add_option( 'tw_thumb_prompt', self::default_thumb_prompt(), '', false );
		}
		if ( false === get_option( 'tw_thumb_render_prompt', false ) ) {
			add_option( 'tw_thumb_render_prompt', self::default_thumb_render_prompt(), '', false );
		}
		if ( false === get_option( 'tw_model', false ) ) {
			add_option( 'tw_model', TW_DEFAULT_MODEL, '', false );
		}
		if ( false === get_option( 'tw_image_model', false ) ) {
			add_option( 'tw_image_model', TW_DEFAULT_IMAGE_MODEL, '', false );
		}
		if ( false === get_option( 'tw_openai_image_model', false ) ) {
			add_option( 'tw_openai_image_model', TW_DEFAULT_OPENAI_IMAGE_MODEL, '', false );
		}
		if ( false === get_option( 'tw_openai_model', false ) ) {
			add_option( 'tw_openai_model', TW_DEFAULT_OPENAI_MODEL, '', false );
		}
		if ( false === get_option( 'tw_image_provider', false ) ) {
			add_option( 'tw_image_provider', TW_DEFAULT_IMAGE_PROVIDER, '', false );
		}
		self::schedule_thumb_cleanup();
	}

	/** Schedule daily orphan generated-thumbnail cleanup. */
	public static function schedule_thumb_cleanup() {
		if ( ! wp_next_scheduled( self::CRON_CLEANUP_THUMBS ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_CLEANUP_THUMBS );
		}
	}

	/** Clear scheduled cleanup on deactivate. */
	public static function deactivate() {
		$ts = wp_next_scheduled( self::CRON_CLEANUP_THUMBS );
		while ( $ts ) {
			wp_unschedule_event( $ts, self::CRON_CLEANUP_THUMBS );
			$ts = wp_next_scheduled( self::CRON_CLEANUP_THUMBS );
		}
	}

	public static function default_prompt() {
		return include TW_DIR . 'includes/default-prompt.php';
	}

	/**
	 * The saved prompt overrides the file. If it still has the old short-description
	 * cap, replace it with the current default so Generate writes the full 5,000-character package.
	 */
	public static function maybe_upgrade_prompt() {
		$saved = get_option( 'tw_prompt', false );
		if ( ! is_string( $saved ) || '' === trim( $saved ) ) {
			return;
		}
		if ( false === strpos( $saved, 'under 1,500 characters' ) && false === strpos( $saved, 'under 1,200 characters' ) ) {
			return;
		}
		update_option( 'tw_prompt', self::default_prompt(), false );
	}

	/**
	 * Move sites still on chronically overloaded lite text models onto gemini-3.8-flash.
	 * Runs once per flag. Leaves non-lite custom models alone.
	 */
	public static function maybe_upgrade_gemini_model() {
		if ( get_option( 'tw_gemini_model_v3', false ) ) {
			return;
		}
		$current = strtolower( trim( (string) get_option( 'tw_model', '' ) ) );
		$lite    = (
			'' === $current
			|| false !== strpos( $current, 'lite' )
			|| in_array( $current, array( 'gemini-3.5-flash-lite', 'gemini-flash-lite-latest', 'gemini-2.5-flash-lite' ), true )
		);
		if ( $lite ) {
			update_option( 'tw_model', TW_DEFAULT_MODEL, false );
		}
		update_option( 'tw_gemini_model_v3', 1, false );
	}

	/**
	 * Replace the stock "plain screenshot, little or no text" thumbnail prompts
	 * with the packaged card template. Leave a prompt alone once it has been edited
	 * away from those stock lines.
	 */
	public static function maybe_upgrade_thumb_prompts() {
		$brief = get_option( 'tw_thumb_prompt', false );
		if ( is_string( $brief ) && (
			false !== strpos( $brief, 'Prefer none if the image already sells the moment' )
			|| false !== strpos( $brief, 'A plain gameplay still, a cinematic painting' )
			|| false !== strpos( $brief, 'The plugin adds the avatar, the GG logo, the bottom-right game logo' )
			|| false !== strpos( $brief, 'Lower left: the bald brown commander already in the screenshot' )
			|| false !== strpos( $brief, 'anime commander in the olive uniform with the cat' )
			|| false !== strpos( $brief, 'Do not invent a different person. Do not default to a generic bald commander' )
			|| false !== strpos( $brief, 'safe zone at least 140px from every edge' )
			|| false !== strpos( $brief, 'safe zone at least 160px from every edge' )
			|| false !== strpos( $brief, 'A picture-in-picture inset only when a second screenshot shows a different moment' )
			|| false !== strpos( $brief, 'The target layout is a polished gameplay card, not a poster' )
			|| false !== strpos( $brief, 'The target layout is a polished ChatGPT-style gameplay card' )
			|| false !== strpos( $brief, 'Headline, logos, and any inset stay at least 100px from every edge' )
			|| (
				false !== strpos( $brief, 'You look at up to two gameplay screenshots plus the draft title, description and transcript.' )
				&& false === strpos( $brief, 'channel logo only, UNLESS' )
			)
			|| (
				false !== strpos( $brief, 'Describe only what the screenshots show.' )
				&& false === strpos( $brief, 'Prefer attached screenshots when present' )
			)
			|| (
				false !== strpos( $brief, 'Exact 16:9. Image 1 full-bleed to the rim when attached.' )
				&& false === strpos( $brief, 'SAFE ZONE' )
			)
		) ) {
			update_option( 'tw_thumb_prompt', self::default_thumb_prompt(), false );
		}
		$render = get_option( 'tw_thumb_render_prompt', false );
		if ( is_string( $render ) && (
			false !== strpos( $render, 'No fake borders, no watermark, no channel logo' )
			|| false !== strpos( $render, 'If the brief says no inset' )
			|| false !== strpos( $render, 'This corner is the inset, never the game logo' )
			|| false !== strpos( $render, 'Never square. Never portrait. Never 3:2.' )
			|| false !== strpos( $render, 'The working canvas is taller than 16:9' )
			|| false !== strpos( $render, 'The recurring avatar is a bald brown-skinned man' )
			|| false !== strpos( $render, 'Leave at least 100px of image to the left of the text' )
			|| false !== strpos( $render, 'Do not swap in a generic bald commander when the screenshot already has someone else' )
			|| false !== strpos( $render, 'Military stencil, chipped, not a thin font' )
			|| false !== strpos( $render, 'at least 140px inward from every edge' )
			|| false !== strpos( $render, 'Prefer 180px from the top and bottom' )
			|| false !== strpos( $render, 'at least 160px inward from every edge' )
			|| false !== strpos( $render, 'Edge padding is the top priority after the gameplay shot' )
			|| false !== strpos( $render, 'Add a picture-in-picture card only when the brief describes one' )
			|| false !== strpos( $render, 'thin dark border, sits fully inside the central 84 percent' )
			|| false !== strpos( $render, 'thin light border and a soft drop shadow' )
			|| false !== strpos( $render, 'about 8 percent empty gameplay between each graphic and the frame' )
			|| false !== strpos( $render, 'Controlled cinematic S-curve, selective clarity on the hero subject' )
			|| false !== strpos( $render, 'REAL GAMEPLAY + cinematic polish + clear story + bold typography' )
			|| (
				false !== strpos( $render, 'You polish one {{channel_name}} YouTube thumbnail from the supplied gameplay screenshot. The screenshot stays the foundation. Do not replace it with a new illustration.' )
				&& false === strpos( $render, 'When no screenshot is attached' )
			)
			|| false !== strpos( $render, 'WHEN SCREENSHOTS ARE ATTACHED (ENHANCE ONLY)' )
			|| false !== strpos( $render, 'Controlled cinematic S-curve' )
			|| false !== strpos( $render, 'Corner logos are pasted later by the app. Do not paint channel or game logos.' )
			|| false !== strpos( $render, 'Every overlay sits at least 120px in from the edges' )
			|| (
				false === strpos( $render, 'LOGOS (normal case — no logo PNG attached)' )
				&& (
					false !== strpos( $render, 'LAYERS (this is the whole job)' )
					|| false !== strpos( $render, 'TARGET LOOK' )
					|| false !== strpos( $render, 'LOWER-LEFT BRANDING' )
					|| false !== strpos( $render, 'You are the thumbnail art director' )
				)
			)
			|| (
				false !== strpos( $render, 'You are the thumbnail art director' )
				&& false === strpos( $render, 'SAFE ZONE' )
			)
		) ) {
			update_option( 'tw_thumb_render_prompt', self::default_thumb_render_prompt(), false );
		}
	}

	/**
	 * Move sites off deprecated chatgpt-image-latest (3:2 only) onto a true 16:9 model.
	 * Runs once. Leaves a custom non-default model alone.
	 */
	public static function maybe_upgrade_openai_image_model() {
		if ( get_option( 'tw_openai_image_model_v2', false ) ) {
			return;
		}
		$current = strtolower( trim( (string) get_option( 'tw_openai_image_model', '' ) ) );
		$legacy  = (
			'' === $current
			|| 'chatgpt-image-latest' === $current
			|| 'gpt-image-1' === $current
			|| 'gpt-image-1.5' === $current
		);
		if ( $legacy ) {
			update_option( 'tw_openai_image_model', TW_DEFAULT_OPENAI_IMAGE_MODEL, false );
		}
		update_option( 'tw_openai_image_model_v2', 1, false );
	}

	public static function default_thumb_prompt() {
		return include TW_DIR . 'includes/default-thumb-prompt.php';
	}

	public static function default_thumb_render_prompt() {
		return include TW_DIR . 'includes/default-thumb-render-prompt.php';
	}

	/** Saved identity map (empty strings until the user finishes onboarding). */
	public static function identity() {
		$out = array();
		foreach ( self::IDENTITY_KEYS as $key ) {
			$out[ $key ] = self::normalize_identity_value( $key, (string) TW_Store::get( $key, '' ) );
		}
		if ( '' === $out['channel_short'] && '' !== $out['channel_name'] ) {
			$out['channel_short'] = self::guess_short( $out['channel_name'] );
		}
		if ( '' === $out['brand_tag'] && '' !== $out['channel_name'] ) {
			$out['brand_tag'] = $out['channel_name'];
		}
		return $out;
	}

	/** True when required identity fields were saved. */
	public static function identity_complete() {
		if ( '1' === (string) TW_Store::get( 'identity_complete', '' ) ) {
			return true;
		}
		// Treat a fully filled identity as complete even if the flag was never set.
		$id = self::identity();
		foreach ( self::IDENTITY_REQUIRED as $key ) {
			if ( '' === trim( (string) ( $id[ $key ] ?? '' ) ) ) {
				return false;
			}
		}
		return true;
	}

	/** Example hint for a field (Gregatron sample for onboarding UI). */
	public static function identity_example( $key ) {
		return (string) ( self::IDENTITY_EXAMPLES[ $key ] ?? '' );
	}

	/**
	 * Replace {{channel_name}} style tokens with the saved identity.
	 *
	 * @param string $text
	 * @return string
	 */
	public static function expand_prompt( $text ) {
		$text = (string) $text;
		$id   = self::identity();
		$map  = array(
			'{{channel_name}}'       => $id['channel_name'],
			'{{channel_short}}'      => $id['channel_short'],
			'{{creator_name}}'       => $id['creator_name'],
			'{{brand_tag}}'          => $id['brand_tag'],
			'{{brand_hashtag}}'      => $id['brand_hashtag'],
			'{{warning_title}}'      => $id['warning_title'],
			'{{warning_close}}'      => $id['warning_close'],
			'{{chapters_heading}}'   => $id['chapters_heading'],
			'{{channel_logo_brief}}' => $id['channel_logo_brief'],
		);
		return strtr( $text, $map );
	}

	/** Voice / thumb prompt with identity tokens expanded for the model. */
	public static function prompt_for_ai( $key ) {
		return self::expand_prompt( self::get( $key ) );
	}

	/**
	 * Save identity (+ optional prompts) from a POST array.
	 * Returns true when required fields are present and identity_complete is set.
	 *
	 * @param array $post
	 * @param bool  $include_prompts Also save the three prompt textareas when present.
	 * @return bool
	 */
	public static function save_identity_from_post( array $post, $include_prompts = true ) {
		$channel_name = sanitize_text_field( wp_unslash( $post['tw_channel_name'] ?? '' ) );
		$channel_short = sanitize_text_field( wp_unslash( $post['tw_channel_short'] ?? '' ) );
		$creator_name = sanitize_text_field( wp_unslash( $post['tw_creator_name'] ?? '' ) );
		$brand_tag = sanitize_text_field( wp_unslash( $post['tw_brand_tag'] ?? '' ) );
		$brand_hashtag = sanitize_text_field( wp_unslash( $post['tw_brand_hashtag'] ?? '' ) );
		$warning_title = sanitize_text_field( wp_unslash( $post['tw_warning_title'] ?? '' ) );
		$warning_close = sanitize_text_field( wp_unslash( $post['tw_warning_close'] ?? '' ) );
		$chapters_heading = wp_check_invalid_utf8( (string) wp_unslash( $post['tw_chapters_heading'] ?? '' ) );
		$chapters_heading = sanitize_text_field( $chapters_heading );
		$logo_brief = wp_check_invalid_utf8( (string) wp_unslash( $post['tw_channel_logo_brief'] ?? '' ) );
		$logo_brief = sanitize_textarea_field( $logo_brief );

		if ( '' !== $brand_hashtag && '#' !== substr( $brand_hashtag, 0, 1 ) ) {
			$brand_hashtag = '#' . ltrim( $brand_hashtag, '#' );
		}
		if ( '' === $channel_short && '' !== $channel_name ) {
			$channel_short = self::guess_short( $channel_name );
		}
		if ( '' === $brand_tag && '' !== $channel_name ) {
			$brand_tag = $channel_name;
		}

		self::set( 'channel_name', $channel_name );
		self::set( 'channel_short', $channel_short );
		self::set( 'creator_name', $creator_name );
		self::set( 'brand_tag', $brand_tag );
		self::set( 'brand_hashtag', $brand_hashtag );
		self::set( 'warning_title', $warning_title );
		self::set( 'warning_close', $warning_close );
		self::set( 'chapters_heading', $chapters_heading );
		self::set( 'channel_logo_brief', $logo_brief );

		if ( $include_prompts ) {
			if ( array_key_exists( 'tw_prompt', $post ) ) {
				$prompt = wp_check_invalid_utf8( (string) wp_unslash( $post['tw_prompt'] ) );
				self::set( 'prompt', $prompt );
			}
			if ( array_key_exists( 'tw_thumb_prompt', $post ) ) {
				$thumb = wp_check_invalid_utf8( (string) wp_unslash( $post['tw_thumb_prompt'] ) );
				self::set( 'thumb_prompt', $thumb );
			}
			if ( array_key_exists( 'tw_thumb_render_prompt', $post ) ) {
				$render = wp_check_invalid_utf8( (string) wp_unslash( $post['tw_thumb_render_prompt'] ) );
				self::set( 'thumb_render_prompt', $render );
			}
		}

		$complete = '' !== $channel_name
			&& '' !== $creator_name
			&& '' !== $brand_hashtag
			&& '' !== $warning_title
			&& '' !== $warning_close
			&& '' !== $chapters_heading
			&& '' !== $logo_brief;

		self::set( 'identity_complete', $complete ? '1' : '' );
		return $complete;
	}

	private static function guess_short( $channel_name ) {
		$words = preg_split( '/\s+/', trim( (string) $channel_name ) );
		if ( ! $words ) {
			return '';
		}
		if ( count( $words ) === 1 ) {
			return strtoupper( substr( $words[0], 0, 2 ) );
		}
		$short = '';
		foreach ( array_slice( $words, 0, 3 ) as $word ) {
			$short .= strtoupper( substr( $word, 0, 1 ) );
		}
		return $short;
	}

	private static function normalize_identity_value( $key, $value ) {
		$value = (string) $value;
		if ( 'chapters_heading' === $key ) {
			// utf8 (not utf8mb4) stores the stop-sign emoji as ?.
			$value = str_replace(
				'? THE CERTIFIED CHRONO SLOP BREAKDOWN ?',
				'🛑 THE CERTIFIED CHRONO SLOP BREAKDOWN 🛑',
				$value
			);
		}
		return $value;
	}

	public static function get( $key ) {
		$value = TW_Store::get( $key, '' );
		if ( 'model' === $key && '' === $value ) {
			return TW_DEFAULT_MODEL;
		}
		if ( 'image_model' === $key && '' === $value ) {
			return TW_DEFAULT_IMAGE_MODEL;
		}
		if ( 'openai_image_model' === $key && '' === $value ) {
			return TW_DEFAULT_OPENAI_IMAGE_MODEL;
		}
		if ( 'openai_model' === $key && '' === $value ) {
			return TW_DEFAULT_OPENAI_MODEL;
		}
		if ( 'image_provider' === $key ) {
			$value = (string) $value;
			return isset( self::IMAGE_PROVIDERS[ $value ] ) ? $value : TW_DEFAULT_IMAGE_PROVIDER;
		}
		if ( in_array( $key, self::IDENTITY_KEYS, true ) ) {
			return self::normalize_identity_value( $key, (string) $value );
		}
		if ( 'prompt' === $key ) {
			if ( '' === trim( (string) $value ) ) {
				return self::default_prompt();
			}
			// The site connection is utf8, which cannot store the 4-byte stop-sign emoji.
			$id = self::identity();
			$heading = $id['chapters_heading'] !== '' ? $id['chapters_heading'] : self::IDENTITY_EXAMPLES['chapters_heading'];
			$broken  = preg_replace( '/^🛑|🛑$/u', '?', $heading );
			$value   = str_replace( (string) $broken, $heading, (string) $value );
			$value   = str_replace(
				'? THE CERTIFIED CHRONO SLOP BREAKDOWN ?',
				'🛑 THE CERTIFIED CHRONO SLOP BREAKDOWN 🛑',
				$value
			);
			return $value;
		}
		if ( 'thumb_prompt' === $key && '' === trim( (string) $value ) ) {
			return self::default_thumb_prompt();
		}
		if ( 'thumb_render_prompt' === $key && '' === trim( (string) $value ) ) {
			return self::default_thumb_render_prompt();
		}
		if ( isset( self::DEFAULTS[ $key ] ) && '' === (string) $value ) {
			return self::DEFAULTS[ $key ];
		}
		return $value;
	}

	public static function set( $key, $value ) {
		TW_Store::set( $key, $value );
	}

	/** Front-end user is ready when they have at least one AI key. */
	public static function user_has_ai_key() {
		return TW_Store::has( 'gemini_key' ) || TW_Store::has( 'openai_key' );
	}

	public static function save_from_post( array $post ) {
		self::set( 'client_id', sanitize_text_field( wp_unslash( $post['tw_client_id'] ?? '' ) ) );

		// Keep stored secrets when the field is submitted blank (we never echo them back into the form).
		$secret = sanitize_text_field( wp_unslash( $post['tw_client_secret'] ?? '' ) );
		if ( '' !== $secret ) {
			self::set( 'client_secret', $secret );
		}
		$gemini = sanitize_text_field( wp_unslash( $post['tw_gemini_key'] ?? '' ) );
		if ( '' !== $gemini ) {
			self::set( 'gemini_key', $gemini );
		}
		$openai = sanitize_text_field( wp_unslash( $post['tw_openai_key'] ?? '' ) );
		if ( '' !== $openai ) {
			self::set( 'openai_key', $openai );
		}
		$openai_model = sanitize_text_field( wp_unslash( $post['tw_openai_model'] ?? '' ) );
		self::set( 'openai_model', '' === $openai_model ? TW_DEFAULT_OPENAI_MODEL : $openai_model );

		$model = sanitize_text_field( wp_unslash( $post['tw_model'] ?? '' ) );
		self::set( 'model', '' === $model ? TW_DEFAULT_MODEL : $model );

		$provider = sanitize_text_field( wp_unslash( $post['tw_image_provider'] ?? '' ) );
		self::set( 'image_provider', isset( self::IMAGE_PROVIDERS[ $provider ] ) ? $provider : TW_DEFAULT_IMAGE_PROVIDER );

		$image_model = sanitize_text_field( wp_unslash( $post['tw_image_model'] ?? '' ) );
		self::set( 'image_model', '' === $image_model ? TW_DEFAULT_IMAGE_MODEL : $image_model );

		$openai_image_model = sanitize_text_field( wp_unslash( $post['tw_openai_image_model'] ?? '' ) );
		self::set( 'openai_image_model', '' === $openai_image_model ? TW_DEFAULT_OPENAI_IMAGE_MODEL : $openai_image_model );

		$quality = sanitize_text_field( wp_unslash( $post['tw_openai_image_quality'] ?? '' ) );
		self::set( 'openai_image_quality', isset( self::OPENAI_QUALITIES[ $quality ] ) ? $quality : self::DEFAULTS['openai_image_quality'] );

		// Site-only gate: off by default so AI thumbnails stay admin-only until ready.
		self::set( 'thumb_ai_for_users', empty( $post['tw_thumb_ai_for_users'] ) ? '' : '1' );

		$time = sanitize_text_field( wp_unslash( $post['tw_default_time'] ?? '' ) );
		self::set( 'default_time', preg_match( '/^\d{2}:\d{2}$/', $time ) ? $time : self::DEFAULTS['default_time'] );
		$lang = sanitize_text_field( wp_unslash( $post['tw_default_language'] ?? '' ) );
		self::set( 'default_language', isset( self::LANGUAGES[ $lang ] ) ? $lang : 'en' );
		$cat = sanitize_text_field( wp_unslash( $post['tw_default_category'] ?? '' ) );
		self::set( 'default_category', isset( self::CATEGORIES[ $cat ] ) ? $cat : '20' );

		self::save_identity_from_post( $post, true );
	}

	public static function is_configured() {
		if ( TW_Context::is_user() ) {
			return self::get( 'client_id' ) && self::get( 'client_secret' ) && self::user_has_ai_key() && self::identity_complete();
		}
		return self::get( 'client_id' ) && self::get( 'client_secret' ) && self::get( 'gemini_key' ) && self::identity_complete();
	}

	/** Label for the active thumbnail image provider. */
	public static function image_provider_label() {
		$provider = self::get( 'image_provider' );
		return self::IMAGE_PROVIDERS[ $provider ] ?? $provider;
	}

	/** True when non-admin UploadWriter users may use AI thumbnail brief/create. */
	public static function thumb_ai_for_users() {
		return '1' === (string) self::get( 'thumb_ai_for_users' );
	}
}
