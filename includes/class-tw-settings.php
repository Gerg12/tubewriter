<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Option storage. Everything lives in wp_options on the user's own local site.
 */
class TW_Settings {

	const KEYS = array( 'client_id', 'client_secret', 'gemini_key', 'model', 'prompt' );

	public static function activate() {
		if ( false === get_option( 'tw_prompt', false ) ) {
			add_option( 'tw_prompt', self::default_prompt(), '', false );
		}
		if ( false === get_option( 'tw_model', false ) ) {
			add_option( 'tw_model', TW_DEFAULT_MODEL, '', false );
		}
	}

	public static function default_prompt() {
		return include TW_DIR . 'includes/default-prompt.php';
	}

	public static function get( $key ) {
		$value = get_option( 'tw_' . $key, '' );
		if ( 'model' === $key && '' === $value ) {
			return TW_DEFAULT_MODEL;
		}
		if ( 'prompt' === $key && '' === trim( (string) $value ) ) {
			return self::default_prompt();
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

		$model = sanitize_text_field( wp_unslash( $post['tw_model'] ?? '' ) );
		self::set( 'model', '' === $model ? TW_DEFAULT_MODEL : $model );

		// Prompt is plain text sent to the AI, never rendered as HTML. Keep emoji and quotes intact.
		$prompt = wp_unslash( $post['tw_prompt'] ?? '' );
		$prompt = wp_check_invalid_utf8( (string) $prompt );
		self::set( 'prompt', $prompt );
	}

	public static function is_configured() {
		return self::get( 'client_id' ) && self::get( 'client_secret' ) && self::get( 'gemini_key' );
	}
}
