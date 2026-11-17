<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Google OAuth. Admin always uses the Desktop paste-the-URL flow (loopback).
 * Front end uses paste-the-URL on local/staging, and a real https redirect on
 * a live site. Tokens are stored via TW_Store so each account keeps its own login.
 */
class TW_OAuth {

	const AUTH_URL   = 'https://accounts.google.com/o/oauth2/v2/auth';
	const TOKEN_URL  = 'https://oauth2.googleapis.com/token';
	const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

	/**
	 * True when this site should use the Desktop loopback paste flow for front Connect.
	 * Detects WP_ENVIRONMENT_TYPE local/development, hosts like *.staging / *.local,
	 * localhost, or a non-https home URL (Google rejects http web redirects on custom hosts).
	 * Override with the tw_oauth_use_paste_flow filter.
	 */
	public static function use_paste_flow() {
		$use = self::detect_local_site();
		/**
		 * Filter whether front-end OAuth uses paste-the-URL (loopback) instead of a web redirect.
		 *
		 * @param bool $use True for paste flow.
		 */
		return (bool) apply_filters( 'tw_oauth_use_paste_flow', $use );
	}

	/** Heuristic for LocalWP / dev hosts. */
	public static function detect_local_site() {
		$env = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';
		if ( in_array( $env, array( 'local', 'development' ), true ) ) {
			return true;
		}

		$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( in_array( $host, array( 'localhost', '127.0.0.1' ), true ) ) {
			return true;
		}
		if ( $host && preg_match( '/\.(local|localhost|test|staging)$/', $host ) ) {
			return true;
		}

		// Google only allows http redirects for loopback. Any plain-http custom host must paste.
		$scheme = wp_parse_url( home_url(), PHP_URL_SCHEME );
		if ( 'https' !== $scheme ) {
			return true;
		}

		return false;
	}

	/**
	 * Redirect URI for the active context.
	 * Admin: always loopback. Front: loopback on local, /tubewriter/oauth/ on live https.
	 * Override with tw_oauth_redirect_uri.
	 */
	public static function redirect_uri() {
		if ( ! TW_Context::is_user() ) {
			$uri = TW_REDIRECT_URI;
			$ctx = 'admin';
		} elseif ( self::use_paste_flow() ) {
			$uri = TW_REDIRECT_URI;
			$ctx = 'front_paste';
		} else {
			$uri = home_url( '/tubewriter/oauth/' );
			$ctx = 'front_web';
		}

		/**
		 * Filter the OAuth redirect URI.
		 *
		 * @param string $uri Redirect URI sent to Google.
		 * @param string $ctx admin|front_paste|front_web
		 */
		return (string) apply_filters( 'tw_oauth_redirect_uri', $uri, $ctx );
	}

	private static function pkce_key() {
		return TW_Context::is_user() ? 'tw_oauth_pkce_' . TW_Context::user_id() : 'tw_oauth_pkce';
	}

	/** Build the Google sign-in URL and remember the PKCE verifier + state for 10 minutes. */
	public static function auth_url() {
		$verifier  = self::random_string( 64 );
		$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
		$state     = self::random_string( 24 );

		set_transient(
			self::pkce_key(),
			array(
				'verifier' => $verifier,
				'state'    => $state,
				'user_id'  => TW_Context::user_id(),
			),
			10 * MINUTE_IN_SECONDS
		);

		return add_query_arg(
			array(
				'client_id'             => rawurlencode( TW_Settings::get( 'client_id' ) ),
				'redirect_uri'          => rawurlencode( self::redirect_uri() ),
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

		if ( false !== strpos( $pasted, 'code=' ) || false !== strpos( $pasted, 'error=' ) ) {
			$query = wp_parse_url( $pasted, PHP_URL_QUERY );
			if ( ! $query && ( 0 === strpos( $pasted, 'code=' ) || 0 === strpos( $pasted, 'error=' ) ) ) {
				$query = $pasted;
			}
			parse_str( (string) $query, $params );
			if ( ! empty( $params['error'] ) ) {
				self::burn_pkce();
				$err = sanitize_text_field( $params['error'] );
				if ( 'access_denied' === $err ) {
					return new WP_Error( 'tw_oauth', 'Google access was denied. Click Connect YouTube and allow access.' );
				}
				return new WP_Error( 'tw_oauth', 'Google returned an error: ' . $err );
			}
			$code  = isset( $params['code'] ) ? (string) $params['code'] : '';
			$state = isset( $params['state'] ) ? (string) $params['state'] : '';
		} else {
			$code = $pasted;
		}

		if ( '' === $code ) {
			return new WP_Error( 'tw_oauth', 'Could not find a code in what you pasted. Copy the whole address bar from the "can\'t connect" page.' );
		}

		return self::exchange_code( $code, $state );
	}

	/**
	 * Front-end web callback: exchange ?code=&state= from Google redirect.
	 *
	 * @return true|WP_Error
	 */
	public static function complete_redirect( $code, $state ) {
		$code  = sanitize_text_field( (string) $code );
		$state = sanitize_text_field( (string) $state );
		if ( '' === $code ) {
			self::burn_pkce();
			return new WP_Error( 'tw_oauth', 'Google did not return an authorization code.' );
		}
		return self::exchange_code( $code, $state );
	}

	/**
	 * @return true|WP_Error
	 */
	private static function exchange_code( $code, $state ) {
		$pkce = get_transient( self::pkce_key() );
		if ( ! $pkce || empty( $pkce['state'] ) || empty( $pkce['verifier'] ) ) {
			self::burn_pkce();
			return new WP_Error( 'tw_oauth', 'The sign-in link expired. Click Connect YouTube again.' );
		}

		// Pasted URL with state must match. Bare code is allowed if this attempt is still live.
		if ( '' !== (string) $state && ! hash_equals( $pkce['state'], (string) $state ) ) {
			self::burn_pkce();
			return new WP_Error( 'tw_oauth', 'That URL is from a different sign-in attempt. Click Connect YouTube again.' );
		}
		if ( TW_Context::is_user() && ! empty( $pkce['user_id'] ) && (int) $pkce['user_id'] !== TW_Context::user_id() ) {
			self::burn_pkce();
			return new WP_Error( 'tw_oauth', 'That sign-in belongs to a different account. Log in and connect again.' );
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
		self::burn_pkce();
		if ( is_wp_error( $data ) ) {
			if ( false !== strpos( $data->get_error_message(), 'invalid_grant' ) ) {
				self::clear_tokens();
				return new WP_Error( 'tw_oauth', 'That sign-in code was already used or expired. Click Connect YouTube again.' );
			}
			return $data;
		}
		if ( empty( $data['refresh_token'] ) ) {
			return new WP_Error( 'tw_oauth', 'Google did not return a refresh token. Remove TubeWriter at myaccount.google.com/permissions and connect again.' );
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
				: 'YouTube is not connected. Go to TubeWriter > Settings and click Connect YouTube.';
			return new WP_Error( 'tw_not_connected', $msg );
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
				self::clear_tokens();
				$msg = TW_Context::is_user()
					? 'Please reconnect. Your YouTube login expired. Connect YouTube again from your account page.'
					: 'Please reconnect. Your YouTube login expired. Sign in again from TubeWriter > Settings. A project left in Testing mode does this about every 7 days.';
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

	/** True after Google rejects the saved login (usually the 7-day Testing-mode cutoff). */
	public static function needs_reconnect() {
		return (bool) TW_Store::get( 'oauth_needs_reconnect', 0 );
	}

	public static function disconnect() {
		$tokens = TW_Store::get( 'tokens', array() );
		if ( is_array( $tokens ) && ! empty( $tokens['refresh_token'] ) ) {
			wp_remote_post( self::REVOKE_URL, array( 'timeout' => 15, 'body' => array( 'token' => $tokens['refresh_token'] ) ) );
		}
		TW_Store::delete( 'tokens' );
		TW_Store::delete( 'channel' );
		TW_Store::delete( 'oauth_needs_reconnect' );
		self::burn_pkce();
	}

	/** Drop stored tokens after Google rejects them. */
	private static function clear_tokens() {
		TW_Store::delete( 'tokens' );
		TW_Store::delete( 'channel' );
		TW_Store::set( 'oauth_needs_reconnect', 1 );
	}

	private static function store_tokens( array $data, $existing_refresh = '' ) {
		TW_Store::set(
			'tokens',
			array(
				'access_token'  => $data['access_token'],
				'refresh_token' => $data['refresh_token'] ?? $existing_refresh,
				'expires_at'    => time() + (int) ( $data['expires_in'] ?? 3600 ),
			)
		);
		TW_Store::delete( 'oauth_needs_reconnect' );
	}

	private static function burn_pkce() {
		delete_transient( self::pkce_key() );
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
