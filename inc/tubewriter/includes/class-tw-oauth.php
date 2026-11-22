<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Google OAuth (Web application client).
 * Redirect URI is always https://{site}/tubewriter/oauth/ via home_url().
 * Tokens are stored per WordPress user (or site options for admin) via TW_Store.
 */
class TW_OAuth {

	const AUTH_URL   = 'https://accounts.google.com/o/oauth2/v2/auth';
	const TOKEN_URL  = 'https://oauth2.googleapis.com/token';
	const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

	/** Exact redirect URI registered on the Google Web client (trailing slash). */
	public static function redirect_uri() {
		$uri = home_url( '/tubewriter/oauth/' );
		/**
		 * Filter the OAuth redirect URI. Must match the Google Cloud console exactly.
		 *
		 * @param string $uri Redirect URI sent to Google.
		 */
		return (string) apply_filters( 'tw_oauth_redirect_uri', $uri );
	}

	private static function state_key( $state ) {
		return 'tw_oauth_state_' . preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $state );
	}

	/** Build the Google sign-in URL; store PKCE verifier + intended user for 10 minutes. */
	public static function auth_url() {
		$verifier  = self::random_string( 64 );
		$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
		$state     = self::random_string( 32 );

		set_transient(
			self::state_key( $state ),
			array(
				'verifier' => $verifier,
				'user_id'  => TW_Context::user_id(),
			),
			10 * MINUTE_IN_SECONDS
		);

		return add_query_arg(
			array(
				'client_id'                => rawurlencode( TW_Settings::get( 'client_id' ) ),
				'redirect_uri'             => rawurlencode( self::redirect_uri() ),
				'response_type'            => 'code',
				'scope'                    => rawurlencode( TW_SCOPE ),
				'access_type'              => 'offline',
				'include_granted_scopes'   => 'true',
				'prompt'                   => 'consent',
				'code_challenge'           => $challenge,
				'code_challenge_method'    => 'S256',
				'state'                    => $state,
			),
			self::AUTH_URL
		);
	}

	/**
	 * Web callback: exchange ?code=&state= from Google redirect.
	 *
	 * @return true|WP_Error
	 */
	public static function complete_redirect( $code, $state ) {
		$code  = sanitize_text_field( (string) $code );
		$state = sanitize_text_field( (string) $state );
		if ( '' === $code ) {
			return new WP_Error( 'tw_oauth', 'Google did not return an authorization code.' );
		}
		if ( '' === $state ) {
			return new WP_Error( 'tw_oauth', 'Missing OAuth state. Click Connect YouTube again.' );
		}
		return self::exchange_code( $code, $state );
	}

	/**
	 * @return true|WP_Error
	 */
	private static function exchange_code( $code, $state ) {
		$pkce = get_transient( self::state_key( $state ) );
		if ( ! is_array( $pkce ) || empty( $pkce['verifier'] ) ) {
			delete_transient( self::state_key( $state ) );
			return new WP_Error( 'tw_oauth', 'The sign-in link expired. Click Connect YouTube again.' );
		}

		$intended = (int) ( $pkce['user_id'] ?? 0 );
		if ( $intended > 0 ) {
			if ( (int) get_current_user_id() !== $intended ) {
				delete_transient( self::state_key( $state ) );
				return new WP_Error( 'tw_oauth', 'That sign-in belongs to a different account. Log in and connect again.' );
			}
			TW_Context::set_user( $intended );
		} else {
			TW_Context::clear();
			if ( ! current_user_can( 'manage_options' ) ) {
				delete_transient( self::state_key( $state ) );
				return new WP_Error( 'tw_oauth', 'Site YouTube connect requires an administrator.' );
			}
		}

		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 30,
				'body'    => array(
					'code'          => $code,
					'client_id'     => TW_Settings::get( 'client_id' ),
					'client_secret' => TW_Settings::get( 'client_secret' ),
					'redirect_uri'  => self::redirect_uri(),
					'grant_type'    => 'authorization_code',
					'code_verifier' => $pkce['verifier'],
				),
			)
		);

		$data = self::decode( $response );
		delete_transient( self::state_key( $state ) );
		if ( is_wp_error( $data ) ) {
			if ( false !== strpos( $data->get_error_message(), 'invalid_grant' ) ) {
				self::mark_disconnected();
				return new WP_Error( 'tw_oauth', 'That sign-in code was already used or expired. Click Connect YouTube again.' );
			}
			return $data;
		}
		if ( empty( $data['refresh_token'] ) ) {
			return new WP_Error( 'tw_oauth', 'Google did not return a refresh token. Remove UploadWriter at myaccount.google.com/permissions and connect again.' );
		}

		self::store_tokens( $data );
		return true;
	}

	/** @return string|WP_Error A valid access token, refreshed if needed. */
	public static function access_token() {
		$tokens = TW_Store::get( 'tokens', array() );
		if ( ! is_array( $tokens ) || empty( $tokens['refresh_token'] ) ) {
			$msg = TW_Context::is_user()
				? 'YouTube is not connected. Open your account page and connect YouTube.'
				: 'YouTube is not connected. Go to UploadWriter > Settings and click Connect YouTube.';
			return new WP_Error( 'tw_not_connected', $msg );
		}

		if ( self::tokens_from_wrong_client( $tokens ) ) {
			TW_Store::set( 'oauth_needs_reconnect', 1 );
			return new WP_Error(
				'tw_expired',
				'Please reconnect YouTube. UploadWriter switched to a new Google OAuth client and needs your approval again.'
			);
		}

		if ( ! empty( $tokens['access_token'] ) && ( $tokens['expires_at'] ?? 0 ) > time() + 60 ) {
			return $tokens['access_token'];
		}

		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 30,
				'body'    => array(
					'client_id'     => TW_Settings::get( 'client_id' ),
					'client_secret' => TW_Settings::get( 'client_secret' ),
					'refresh_token' => $tokens['refresh_token'],
					'grant_type'    => 'refresh_token',
				),
			)
		);
		$data = self::decode( $response );
		if ( is_wp_error( $data ) ) {
			if ( false !== strpos( $data->get_error_message(), 'invalid_grant' ) ) {
				self::mark_disconnected();
				$msg = TW_Context::is_user()
					? 'Please reconnect YouTube. Your YouTube login expired. Connect again from your account page.'
					: 'Please reconnect YouTube. Your YouTube login expired. Sign in again from UploadWriter > Settings.';
				return new WP_Error( 'tw_expired', $msg );
			}
			return $data;
		}
		self::store_tokens( $data, $tokens['refresh_token'] );
		return $data['access_token'];
	}

	public static function is_connected() {
		$tokens = TW_Store::get( 'tokens', array() );
		return is_array( $tokens ) && ! empty( $tokens['refresh_token'] );
	}

	/**
	 * True when the user must approve Google again: invalid_grant, or tokens from
	 * a previous OAuth client ID (Desktop → Web switch).
	 */
	public static function needs_reconnect() {
		if ( TW_Store::get( 'oauth_needs_reconnect', 0 ) ) {
			return true;
		}
		$tokens = TW_Store::get( 'tokens', array() );
		if ( ! is_array( $tokens ) || empty( $tokens['refresh_token'] ) ) {
			return false;
		}
		return self::tokens_from_wrong_client( $tokens );
	}

	/** Tokens missing client_id (pre-Web client) or issued under a different client. */
	private static function tokens_from_wrong_client( array $tokens ) {
		$current = (string) TW_Settings::get( 'client_id' );
		if ( '' === $current ) {
			return false;
		}
		$stored = (string) ( $tokens['client_id'] ?? '' );
		if ( '' === $stored ) {
			return true;
		}
		return ! hash_equals( $current, $stored );
	}

	/**
	 * Revoke at Google, delete tokens, clear channel cache, stamp disconnect time
	 * so scheduled cleanup can purge remaining YouTube API cache within 30 days.
	 */
	public static function disconnect() {
		$tokens = TW_Store::get( 'tokens', array() );
		$token  = '';
		if ( is_array( $tokens ) ) {
			if ( ! empty( $tokens['refresh_token'] ) ) {
				$token = (string) $tokens['refresh_token'];
			} elseif ( ! empty( $tokens['access_token'] ) ) {
				$token = (string) $tokens['access_token'];
			}
		}
		if ( '' !== $token ) {
			wp_remote_post(
				self::REVOKE_URL,
				array(
					'timeout' => 15,
					'body'    => array( 'token' => $token ),
				)
			);
		}

		TW_Store::delete( 'tokens' );
		TW_Store::delete( 'channel' );
		TW_Store::delete( 'quota' );
		TW_Store::delete( 'oauth_needs_reconnect' );
		TW_Store::set( 'youtube_disconnected_at', time() );
		self::clear_youtube_cache_now();
	}

	/** Drop stored tokens after Google rejects them; prompt reconnect. */
	private static function mark_disconnected() {
		TW_Store::delete( 'tokens' );
		TW_Store::delete( 'channel' );
		TW_Store::set( 'oauth_needs_reconnect', 1 );
		TW_Store::set( 'youtube_disconnected_at', time() );
	}

	private static function store_tokens( array $data, $existing_refresh = '' ) {
		TW_Store::set(
			'tokens',
			array(
				'access_token'  => $data['access_token'],
				'refresh_token' => $data['refresh_token'] ?? $existing_refresh,
				'expires_at'    => time() + (int) ( $data['expires_in'] ?? 3600 ),
				'client_id'     => (string) TW_Settings::get( 'client_id' ),
			)
		);
		TW_Store::delete( 'oauth_needs_reconnect' );
		TW_Store::delete( 'youtube_disconnected_at' );
	}

	/** Immediate purge of local YouTube API cache for the active account. */
	public static function clear_youtube_cache_now() {
		TW_Store::delete( 'channel' );
		TW_Store::delete( 'quota' );
		TW_Store::delete( 'scheduled_sync_note' );
		if ( class_exists( 'TW_Index' ) ) {
			TW_Index::purge_user( TW_Context::user_id() );
		}
	}

	/**
	 * Cron: purge any leftover YouTube API cache for accounts disconnected
	 * 30+ days ago (safety net; disconnect already clears cache immediately).
	 */
	public static function cleanup_disconnected_cache() {
		$max_age = 30 * DAY_IN_SECONDS;
		$now     = time();

		TW_Context::clear();
		$site_at = (int) TW_Store::get( 'youtube_disconnected_at', 0 );
		if ( $site_at && ( $now - $site_at ) >= $max_age && ! self::is_connected() ) {
			self::clear_youtube_cache_now();
			TW_Store::delete( 'youtube_disconnected_at' );
		}

		$user_ids = get_users(
			array(
				'fields'       => 'ID',
				'meta_key'     => 'tw_youtube_disconnected_at', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_compare' => 'EXISTS',
				'number'       => 200,
			)
		);
		foreach ( (array) $user_ids as $uid ) {
			$uid = (int) $uid;
			$at  = (int) get_user_meta( $uid, 'tw_youtube_disconnected_at', true );
			if ( ! $at || ( $now - $at ) < $max_age ) {
				continue;
			}
			TW_Context::set_user( $uid );
			if ( self::is_connected() ) {
				TW_Store::delete( 'youtube_disconnected_at' );
				continue;
			}
			self::clear_youtube_cache_now();
			TW_Store::delete( 'youtube_disconnected_at' );
		}
		TW_Context::clear();
	}

	private static function decode( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$code = wp_remote_retrieve_response_code( $response );
		if ( $code >= 400 || ! is_array( $data ) ) {
			$err  = is_array( $data ) ? ( $data['error'] ?? 'unknown_error' ) : 'bad_response';
			$desc = is_array( $data ) ? ( $data['error_description'] ?? '' ) : '';
			return new WP_Error( 'tw_oauth', trim( 'Google sign-in failed: ' . $err . ' ' . $desc ) );
		}
		return $data;
	}

	private static function random_string( $length ) {
		$chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-._~';
		$out   = '';
		for ( $i = 0; $i < $length; $i++ ) {
			$out .= $chars[ random_int( 0, strlen( $chars ) - 1 ) ];
		}
		return $out;
	}
}
