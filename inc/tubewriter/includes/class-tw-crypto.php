<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Encrypt secrets at rest (API keys, OAuth tokens) using OpenSSL and WordPress salts.
 */
class TW_Crypto {

	const PREFIX           = 'tw1:';
	const FINGERPRINT_OPT  = 'tw_crypto_fp';
	const SECRETS_MIGRATED = 'tw_secrets_encrypted_v1';

	private static function key() {
		$material = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ) . '|' . ( defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : '' );
		if ( '' === trim( str_replace( '|', '', $material ) ) ) {
			$material = 'tubewriter-fallback-key';
		}
		return hash( 'sha256', $material, true );
	}

	/** Short fingerprint of the current key material. Never the key itself. */
	public static function fingerprint() {
		return substr( hash( 'sha256', self::key() ), 0, 16 );
	}

	/** True when a stored string already uses the tw1: envelope. */
	public static function is_encrypted( $stored ) {
		return is_string( $stored ) && 0 === strpos( $stored, self::PREFIX );
	}

	/**
	 * @param mixed $value String or array (JSON-encoded before encrypt).
	 * @return string
	 */
	public static function encrypt( $value ) {
		if ( is_array( $value ) ) {
			$plain = 'j:' . wp_json_encode( $value );
		} else {
			$plain = (string) $value;
		}
		if ( '' === $plain ) {
			return '';
		}
		$iv     = random_bytes( 16 );
		$cipher = openssl_encrypt( $plain, 'AES-256-CBC', self::key(), OPENSSL_RAW_DATA, $iv );
		if ( false === $cipher ) {
			return '';
		}
		$mac = hash_hmac( 'sha256', $iv . $cipher, self::key(), true );
		return self::PREFIX . base64_encode( $iv . $mac . $cipher );
	}

	/**
	 * @param mixed $stored
	 * @return mixed String, array, or original value when not encrypted.
	 */
	public static function decrypt( $stored ) {
		if ( ! is_string( $stored ) || 0 !== strpos( $stored, self::PREFIX ) ) {
			return $stored;
		}
		$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true );
		if ( false === $raw || strlen( $raw ) < 48 ) {
			return '';
		}
		$iv     = substr( $raw, 0, 16 );
		$mac    = substr( $raw, 16, 32 );
		$cipher = substr( $raw, 48 );
		$check  = hash_hmac( 'sha256', $iv . $cipher, self::key(), true );
		if ( ! hash_equals( $mac, $check ) ) {
			return '';
		}
		$plain = openssl_decrypt( $cipher, 'AES-256-CBC', self::key(), OPENSSL_RAW_DATA, $iv );
		if ( false === $plain ) {
			return '';
		}
		if ( 0 === strpos( $plain, 'j:' ) ) {
			$json = json_decode( substr( $plain, 2 ), true );
			return is_array( $json ) ? $json : array();
		}
		return $plain;
	}

	/**
	 * One-time: encrypt plain site-level secrets still sitting in wp_options.
	 * Decrypt still accepts plain values, so a partial run is safe.
	 */
	public static function maybe_migrate_site_secrets() {
		if ( get_option( self::SECRETS_MIGRATED ) ) {
			return;
		}
		$keys = array( 'client_secret', 'gemini_key', 'openai_key', 'tokens' );
		foreach ( $keys as $key ) {
			$raw = get_option( 'tw_' . $key, null );
			if ( null === $raw || false === $raw || '' === $raw ) {
				continue;
			}
			if ( self::is_encrypted( $raw ) ) {
				continue;
			}
			// tokens may already be an array if somehow unserialized that way.
			if ( 'tokens' === $key && is_array( $raw ) ) {
				update_option( 'tw_tokens', self::encrypt( $raw ), false );
				continue;
			}
			if ( ! is_string( $raw ) ) {
				continue;
			}
			$enc = self::encrypt( $raw );
			if ( '' !== $enc ) {
				update_option( 'tw_' . $key, $enc, false );
			}
		}
		update_option( self::SECRETS_MIGRATED, '1', false );
		self::ensure_fingerprint();
	}

	/** Store the current key fingerprint when missing. Never overwrite a mismatch. */
	public static function ensure_fingerprint() {
		$fp = self::fingerprint();
		$stored = (string) get_option( self::FINGERPRINT_OPT, '' );
		if ( '' === $stored ) {
			update_option( self::FINGERPRINT_OPT, $fp, false );
			return true;
		}
		return hash_equals( $stored, $fp );
	}

	/** True when stored fingerprint no longer matches current salts. */
	public static function fingerprint_mismatch() {
		$stored = (string) get_option( self::FINGERPRINT_OPT, '' );
		if ( '' === $stored ) {
			return false;
		}
		return ! hash_equals( $stored, self::fingerprint() );
	}

	/** Admin notice when WordPress salts changed under existing encrypted secrets. */
	public static function maybe_admin_notice() {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! self::fingerprint_mismatch() ) {
			return;
		}
		add_action(
			'admin_notices',
			function () {
				echo '<div class="notice notice-error"><p>';
				echo esc_html( 'WordPress security keys changed. Saved Google logins and API keys can\'t be read. Put the old keys back in wp-config.php, or ask users to reconnect.' );
				echo '</p></div>';
			}
		);
	}
}
