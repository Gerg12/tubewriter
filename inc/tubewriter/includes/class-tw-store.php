<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Options vs per-user meta. Secrets and OAuth tokens are encrypted at rest.
 * Google OAuth client_id / client_secret always stay site-level so one Cloud
 * project can serve every account on the front end (or come from wp-config).
 */
class TW_Store {

	const SITE_ONLY = array( 'client_id', 'client_secret', 'thumb_ai_for_users', 'beta_access' );

	const SECRETS = array( 'gemini_key', 'openai_key', 'client_secret' );

	/**
	 * @param string $key Without the tw_ prefix.
	 * @param mixed  $default
	 * @return mixed
	 */
	public static function get( $key, $default = '' ) {
		$key = (string) $key;
		if ( in_array( $key, self::SITE_ONLY, true ) || ! TW_Context::is_user() ) {
			$value = get_option( 'tw_' . $key, $default );
			if ( false === $value || null === $value ) {
				return $default;
			}
			if ( 'tokens' === $key || in_array( $key, self::SECRETS, true ) ) {
				$value = TW_Crypto::decrypt( $value );
				if ( ( 'tokens' === $key && ! is_array( $value ) ) || ( 'tokens' !== $key && '' === $value ) ) {
					return $default;
				}
			}
			return $value;
		}

		$value = get_user_meta( TW_Context::user_id(), 'tw_' . $key, true );
		if ( '' === $value || false === $value || null === $value ) {
			return $default;
		}
		if ( 'tokens' === $key || in_array( $key, self::SECRETS, true ) ) {
			return TW_Crypto::decrypt( $value );
		}
		return $value;
	}

	/**
	 * @param string $key Without the tw_ prefix.
	 * @param mixed  $value
	 */
	public static function set( $key, $value ) {
		$key = (string) $key;
		$store = $value;
		if ( 'tokens' === $key || in_array( $key, self::SECRETS, true ) ) {
			$store = TW_Crypto::encrypt( $value );
		}

		if ( in_array( $key, self::SITE_ONLY, true ) || ! TW_Context::is_user() ) {
			update_option( 'tw_' . $key, $store, false );
			return;
		}

		update_user_meta( TW_Context::user_id(), 'tw_' . $key, $store );
	}

	/**
	 * @param string $key Without the tw_ prefix.
	 */
	public static function delete( $key ) {
		$key = (string) $key;
		if ( in_array( $key, self::SITE_ONLY, true ) || ! TW_Context::is_user() ) {
			delete_option( 'tw_' . $key );
			return;
		}
		delete_user_meta( TW_Context::user_id(), 'tw_' . $key );
	}

	/** True when a non-empty value is stored (after decrypt for secrets). */
	public static function has( $key ) {
		$v = self::get( $key, '' );
		if ( is_array( $v ) ) {
			return ! empty( $v );
		}
		return '' !== (string) $v;
	}

	/**
	 * Last 4 characters of a decrypted secret for display. Never the full value.
	 *
	 * @param string $key Without the tw_ prefix.
	 * @return string Empty when missing or too short.
	 */
	public static function secret_hint( $key ) {
		$v = self::get( $key, '' );
		if ( ! is_string( $v ) || strlen( $v ) < 4 ) {
			return '';
		}
		return substr( $v, -4 );
	}

	/**
	 * Placeholder text for secret inputs: "Saved ···abcd" or empty.
	 *
	 * @param string $key Without the tw_ prefix.
	 * @return string
	 */
	public static function secret_placeholder( $key ) {
		if ( ! self::has( $key ) ) {
			return '';
		}
		$hint = self::secret_hint( $key );
		return $hint ? ( 'Saved ···' . $hint ) : 'Saved. Leave blank to keep it.';
	}
}
