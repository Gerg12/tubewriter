<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Google OAuth for a Desktop client with PKCE and a paste-the-URL step.
 */
class TW_OAuth {

	const AUTH_URL  = 'https://accounts.google.com/o/oauth2/v2/auth';
	const TOKEN_URL = 'https://oauth2.googleapis.com/token';
	const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

	/** Build the Google sign-in URL and remember the PKCE verifier + state for 30 minutes. */
	public static function auth_url() {
		$verifier  = self::random_string( 64 );
		$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
		$state     = self::random_string( 24 );

		set_transient( 'tw_oauth_pkce', array( 'verifier' => $verifier, 'state' => $state ), 30 * MINUTE_IN_SECONDS );

		return add_query_arg(
			array(
				'client_id'             => rawurlencode( TW_Settings::get( 'client_id' ) ),
				'redirect_uri'          => rawurlencode( TW_REDIRECT_URI ),
				'response_type'         => 'code',
				'scope'                 => rawurlencode( TW_SCOPE ),
				'access_type'           => 'offline',
				'prompt'                => 'consent',
				'code_challenge'        => $challenge,
				'code_challenge_method' => 'S256',
				'state'                 => $state,
			),
			self::AUTH_URL
		);
	}

	/**
	 * Accepts the full pasted URL (or just the code), exchanges it for tokens and stores them.
	 *
	 * @return true|WP_Error
	 */
	public static function complete( $pasted ) {
		$pasted = trim( (string) $pasted );
		$code   = '';
		$state  = '';

		if ( false !== strpos( $pasted, 'code=' ) ) {
			$query = wp_parse_url( $pasted, PHP_URL_QUERY );
			if ( ! $query && 0 === strpos( $pasted, 'code=' ) ) {
				$query = $pasted;
			}
			parse_str( (string) $query, $params );
			if ( ! empty( $params['error'] ) ) {
				return new WP_Error( 'tw_oauth', 'Google returned an error: ' . sanitize_text_field( $params['error'] ) );
			}
			$code  = $params['code'] ?? '';
			$state = $params['state'] ?? '';
		} elseif ( false !== strpos( $pasted, 'error=' ) ) {
			return new WP_Error( 'tw_oauth', 'Google returned an error. Try Connect again.' );
		} else {
			$code = $pasted;
		}

		if ( '' === $code ) {
			return new WP_Error( 'tw_oauth', 'Could not find a code in what you pasted. Copy the whole address bar from the "can\'t connect" page.' );
		}

		$pkce = get_transient( 'tw_oauth_pkce' );
		if ( ! $pkce ) {
			return new WP_Error( 'tw_oauth', 'The sign-in link expired. Click Connect YouTube again.' );
		}
		if ( $state && ! hash_equals( $pkce['state'], $state ) ) {
			return new WP_Error( 'tw_oauth', 'That URL is from a different sign-in attempt. Click Connect YouTube again.' );
		}

		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 30,
				'body'    => array(
					'code'          => $code,
					'client_id'     => TW_Settings::get( 'client_id' ),
					'client_secret' => TW_Settings::get( 'client_secret' ),
					'redirect_uri'  => TW_REDIRECT_URI,
					'grant_type'    => 'authorization_code',
					'code_verifier' => $pkce['verifier'],
				),
			)
		);

		$data = self::decode( $response );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( empty( $data['refresh_token'] ) ) {
			return new WP_Error( 'tw_oauth', 'Google did not return a refresh token. Remove TubeWriter at myaccount.google.com/permissions and connect again.' );
		}

		delete_transient( 'tw_oauth_pkce' );
		self::store_tokens( $data );
		return true;
	}

	/** @return string|WP_Error A valid access token, refreshed if needed. */
	public static function access_token() {
		$tokens = get_option( 'tw_tokens', array() );
		if ( empty( $tokens['refresh_token'] ) ) {
			return new WP_Error( 'tw_not_connected', 'YouTube is not connected. Go to TubeWriter > Settings and click Connect YouTube.' );
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
				update_option( 'tw_oauth_needs_reconnect', 1, false );
				return new WP_Error(
					'tw_expired',
					'Your YouTube connection expired. Google is asking you to approve TubeWriter again. Sign in again from TubeWriter > Settings. A project left in Testing mode does this about every 7 days.'
				);
			}
			return $data;
		}
		self::store_tokens( $data, $tokens['refresh_token'] );
		return $data['access_token'];
	}

	public static function is_connected() {
		$tokens = get_option( 'tw_tokens', array() );
		return ! empty( $tokens['refresh_token'] );
	}

	/** True after Google rejects the saved login (usually the 7-day Testing-mode cutoff). */
	public static function needs_reconnect() {
		return (bool) get_option( 'tw_oauth_needs_reconnect' );
	}

	public static function disconnect() {
		$tokens = get_option( 'tw_tokens', array() );
		if ( ! empty( $tokens['refresh_token'] ) ) {
			wp_remote_post( self::REVOKE_URL, array( 'timeout' => 15, 'body' => array( 'token' => $tokens['refresh_token'] ) ) );
		}
		delete_option( 'tw_tokens' );
		delete_option( 'tw_channel' );
		delete_option( 'tw_oauth_needs_reconnect' );
	}

	private static function store_tokens( array $data, $existing_refresh = '' ) {
		update_option(
			'tw_tokens',
			array(
				'access_token'  => $data['access_token'],
				'refresh_token' => $data['refresh_token'] ?? $existing_refresh,
				'expires_at'    => time() + (int) ( $data['expires_in'] ?? 3600 ),
			),
			false
		);
		delete_option( 'tw_oauth_needs_reconnect' );
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
