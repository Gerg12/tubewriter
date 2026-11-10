<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Option storage. Everything lives in wp_options on the user's own local site.
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
		'default_time',
		'default_language',
		'default_category',
	);

	const DEFAULTS = array(
		'default_time'         => '18:00',
		'default_language'     => 'en',
		'default_category'     => '20',
		'openai_image_quality' => 'high',
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
	 * Replace the stock "plain screenshot, little or no text" thumbnail prompts
	 * with the packaged Gregatron card. Leave a prompt alone once it has been edited
	 * away from those stock lines.
	 */
	public static function maybe_upgrade_thumb_prompts() {
		$brief = get_option( 'tw_thumb_prompt', false );
		if ( is_string( $brief ) && (
			false !== strpos( $brief, 'Prefer none if the image already sells the moment' )
			|| false !== strpos( $brief, 'A plain gameplay still, a cinematic painting' )
			|| false !== strpos( $brief, 'The plugin adds the avatar, the GG logo, the bottom-right game logo' )
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
		) ) {
			update_option( 'tw_thumb_render_prompt', self::default_thumb_render_prompt(), false );
		}
	}

	public static function default_thumb_prompt() {
		return include TW_DIR . 'includes/default-thumb-prompt.php';
	}

	public static function default_thumb_render_prompt() {
		return include TW_DIR . 'includes/default-thumb-render-prompt.php';
	}

	public static function get( $key ) {
		$value = get_option( 'tw_' . $key, '' );
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
		if ( 'prompt' === $key ) {
			if ( '' === trim( (string) $value ) ) {
				return self::default_prompt();
			}
			// The site connection is utf8, which cannot store the 4-byte stop-sign emoji.
			// Saving the prompt turns that character into a question mark. Put it back on read.
			return str_replace(
				'? THE CERTIFIED CHRONO SLOP BREAKDOWN ?',
				'🛑 THE CERTIFIED CHRONO SLOP BREAKDOWN 🛑',
				(string) $value
			);
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
		update_option( 'tw_' . $key, $value, false );
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

		$time = sanitize_text_field( wp_unslash( $post['tw_default_time'] ?? '' ) );
		self::set( 'default_time', preg_match( '/^\d{2}:\d{2}$/', $time ) ? $time : self::DEFAULTS['default_time'] );
		$lang = sanitize_text_field( wp_unslash( $post['tw_default_language'] ?? '' ) );
		self::set( 'default_language', isset( self::LANGUAGES[ $lang ] ) ? $lang : 'en' );
		$cat = sanitize_text_field( wp_unslash( $post['tw_default_category'] ?? '' ) );
		self::set( 'default_category', isset( self::CATEGORIES[ $cat ] ) ? $cat : '20' );

		// Prompt is plain text sent to the AI, never rendered as HTML. Keep emoji and quotes intact.
		$prompt = wp_unslash( $post['tw_prompt'] ?? '' );
		$prompt = wp_check_invalid_utf8( (string) $prompt );
		self::set( 'prompt', $prompt );

		$thumb_prompt = wp_unslash( $post['tw_thumb_prompt'] ?? '' );
		$thumb_prompt = wp_check_invalid_utf8( (string) $thumb_prompt );
		self::set( 'thumb_prompt', $thumb_prompt );

		$thumb_render = wp_unslash( $post['tw_thumb_render_prompt'] ?? '' );
		$thumb_render = wp_check_invalid_utf8( (string) $thumb_render );
		self::set( 'thumb_render_prompt', $thumb_render );
	}

	public static function is_configured() {
		return self::get( 'client_id' ) && self::get( 'client_secret' ) && self::get( 'gemini_key' );
	}

	/** Label for the active thumbnail image provider. */
	public static function image_provider_label() {
		$provider = self::get( 'image_provider' );
		return self::IMAGE_PROVIDERS[ $provider ] ?? $provider;
	}
}
