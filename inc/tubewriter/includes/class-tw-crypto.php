<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Encrypt secrets at rest (API keys, OAuth tokens) using OpenSSL and WordPress salts.
 */
class TW_Crypto {

	const PREFIX = 'tw1:';

	private static function key() {
		$material = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ) . '|' . ( defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : '' );
		if ( '' === trim( str_replace( '|', '', $material ) ) ) {
			$material = 'tubewriter-fallback-key';
		}
		return hash( 'sha256', $material, true );
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
}
