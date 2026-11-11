<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Options vs per-user meta. Secrets are encrypted when stored for a user.
 * Google OAuth client_id / client_secret always stay site-level so one Cloud
 * project can serve every account on the front end.
 */
class TW_Store {

	const SITE_ONLY = array( 'client_id', 'client_secret' );

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
			return ( false === $value || null === $value ) ? $default : $value;
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
		if ( in_array( $key, self::SITE_ONLY, true ) || ! TW_Context::is_user() ) {
			update_option( 'tw_' . $key, $value, false );
			return;
		}

		if ( 'tokens' === $key || in_array( $key, self::SECRETS, true ) ) {
			$value = TW_Crypto::encrypt( $value );
		}
		update_user_meta( TW_Context::user_id(), 'tw_' . $key, $value );
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
}
