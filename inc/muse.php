<?php
/**
 * Muse connector for UploadWriter.
 *
 * Base:   {site}/wp-json/uw/v1
 * Header: x-uw-muse-key: {key}
 * Key:    Per-user on Account → Keys & voice. Legacy site option / UW_MUSE_KEY
 *         still work as site-admin (user_id 0) context.
 *
 * A matching key scopes TubeWriter reads/writes to that user's channel, drafts,
 * and OAuth. Muse may create draft posts/pages. YouTube push needs confirm_push.
 *
 * Royal MCP (Bearer) remains the path for broader WP work. Do not use browser
 * Google / YouTube / wp-admin login for Muse.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const UW_MUSE_OPTION_KEY  = 'uw_muse_key'; // legacy plain (migrated away)
const UW_MUSE_OPTION_HASH = 'uw_muse_key_hash';
const UW_MUSE_OPTION_HINT = 'uw_muse_key_hint';
const UW_MUSE_USER_META   = 'tw_muse_key'; // legacy plain (migrated away)
const UW_MUSE_USER_HASH   = 'tw_muse_key_hash';
const UW_MUSE_USER_HINT   = 'tw_muse_key_hint';
const UW_MUSE_USER_ALLOWED = 'tw_muse_allowed';
const UW_MUSE_USER_PUSH   = 'tw_muse_allow_push';
const UW_MUSE_USER_RESTORE = 'tw_muse_allow_restore';
const UW_MUSE_USER_SYNC   = 'tw_muse_allow_sync';
const UW_MUSE_SITE_PUSH   = 'uw_muse_allow_push';
const UW_MUSE_HEADER      = 'x-uw-muse-key';
const UW_MUSE_META_EXT    = '_uw_muse_external_id';
const UW_MUSE_KEYS_MIGRATED = 'uw_muse_keys_hashed_v1';
const UW_MUSE_RATE_MAX    = 60;
const UW_MUSE_RATE_WINDOW = 60;
const UW_MUSE_LOG_MAX     = 50;

/** @var int|null Resolved account for this request. 0 = site/admin storage. */
$GLOBALS['uw_muse_user_id'] = null;

function uw_muse_base_url() {
	return home_url( '/wp-json/uw/v1' );
}

function uw_muse_hash_key( $key ) {
	return hash_hmac( 'sha256', (string) $key, wp_salt( 'auth' ) );
}

function uw_muse_new_key() {
	return 'uwm_' . wp_generate_password( 48, false, false );
}

function uw_muse_hint_from_key( $key ) {
	$key = (string) $key;
	$tail = strlen( $key ) >= 4 ? substr( $key, -4 ) : $key;
	return 'uwm_…' . $tail;
}

/**
 * Whether this account may use Muse (UI + per-user API keys).
 * Site/admin Muse key (user_id 0) is not gated by this helper.
 *
 * @param int $user_id WordPress user id.
 * @return bool
 */
function uw_muse_user_allowed( $user_id ) {
	$user_id = (int) $user_id;
	if ( $user_id <= 0 ) {
		return false;
	}
	if ( user_can( $user_id, 'manage_options' ) ) {
		return true;
	}
	return '1' === (string) get_user_meta( $user_id, UW_MUSE_USER_ALLOWED, true );
}

function uw_muse_set_user_allowed( $user_id, $allowed ) {
	$user_id = (int) $user_id;
	if ( $user_id <= 0 ) {
		return;
	}
	if ( $allowed ) {
		update_user_meta( $user_id, UW_MUSE_USER_ALLOWED, '1' );
	} else {
		delete_user_meta( $user_id, UW_MUSE_USER_ALLOWED );
	}
}

function uw_muse_user_has_key( $user_id ) {
	$user_id = (int) $user_id;
	if ( $user_id <= 0 ) {
		return false;
	}
	return '' !== (string) get_user_meta( $user_id, UW_MUSE_USER_HASH, true );
}

function uw_muse_user_hint( $user_id ) {
	$user_id = (int) $user_id;
	if ( $user_id <= 0 ) {
		return '';
	}
	$hint = (string) get_user_meta( $user_id, UW_MUSE_USER_HINT, true );
	return $hint ? $hint : '';
}

function uw_muse_set_user_key( $user_id, $key ) {
	$user_id = (int) $user_id;
	$key     = trim( (string) $key );
	if ( $user_id <= 0 ) {
		return false;
	}
	delete_user_meta( $user_id, UW_MUSE_USER_META );
	if ( '' === $key ) {
		delete_user_meta( $user_id, UW_MUSE_USER_HASH );
		delete_user_meta( $user_id, UW_MUSE_USER_HINT );
		return true;
	}
	if ( strlen( $key ) < 32 ) {
		return false;
	}
	update_user_meta( $user_id, UW_MUSE_USER_HASH, uw_muse_hash_key( $key ) );
	update_user_meta( $user_id, UW_MUSE_USER_HINT, uw_muse_hint_from_key( $key ) );
	return true;
}

/** Clear Muse key + toggles (account delete / purge). */
function uw_muse_purge_user( $user_id ) {
	$user_id = (int) $user_id;
	if ( $user_id <= 0 ) {
		return;
	}
	delete_user_meta( $user_id, UW_MUSE_USER_META );
	delete_user_meta( $user_id, UW_MUSE_USER_HASH );
	delete_user_meta( $user_id, UW_MUSE_USER_HINT );
	delete_user_meta( $user_id, UW_MUSE_USER_ALLOWED );
	delete_user_meta( $user_id, UW_MUSE_USER_PUSH );
	delete_user_meta( $user_id, UW_MUSE_USER_RESTORE );
	delete_user_meta( $user_id, UW_MUSE_USER_SYNC );
	delete_transient( 'uw_muse_key_once_' . $user_id );
	delete_transient( 'uw_muse_rate_' . $user_id );
}

function uw_muse_user_toggle( $user_id, $meta_key ) {
	return '1' === (string) get_user_meta( (int) $user_id, $meta_key, true );
}

function uw_muse_set_user_toggles( $user_id, array $toggles ) {
	$user_id = (int) $user_id;
	if ( $user_id <= 0 ) {
		return;
	}
	$map = array(
		'push'    => UW_MUSE_USER_PUSH,
		'restore' => UW_MUSE_USER_RESTORE,
		'sync'    => UW_MUSE_USER_SYNC,
	);
	foreach ( $map as $k => $meta ) {
		if ( ! array_key_exists( $k, $toggles ) ) {
			continue;
		}
		if ( ! empty( $toggles[ $k ] ) ) {
			update_user_meta( $user_id, $meta, '1' );
		} else {
			delete_user_meta( $user_id, $meta );
		}
	}
}

function uw_muse_site_push_allowed() {
	return '1' === (string) get_option( UW_MUSE_SITE_PUSH, '' );
}

/** Whether this Muse identity may push to YouTube. */
function uw_muse_can_push() {
	$uid = uw_muse_request_user_id();
	if ( null === $uid ) {
		return false;
	}
	if ( 0 === (int) $uid ) {
		return uw_muse_site_push_allowed();
	}
	return uw_muse_user_toggle( $uid, UW_MUSE_USER_PUSH );
}

function uw_muse_can_restore() {
	$uid = uw_muse_request_user_id();
	if ( null === $uid || 0 === (int) $uid ) {
		return false;
	}
	return uw_muse_user_toggle( $uid, UW_MUSE_USER_RESTORE );
}

function uw_muse_can_sync() {
	$uid = uw_muse_request_user_id();
	if ( null === $uid || 0 === (int) $uid ) {
		return false;
	}
	return uw_muse_user_toggle( $uid, UW_MUSE_USER_SYNC );
}

function uw_muse_daily_unit_cap() {
	$uid = uw_muse_request_user_id();
	if ( null === $uid || 0 === (int) $uid ) {
		return 0; // site key: no daily Muse cap
	}
	return (int) apply_filters( 'uw_muse_daily_youtube_units', 1000, $uid );
}

function uw_muse_quota_day() {
	return ( new DateTime( 'now', new DateTimeZone( 'America/Los_Angeles' ) ) )->format( 'Y-m-d' );
}

function uw_muse_quota_used() {
	if ( ! class_exists( 'TW_Store' ) ) {
		return 0;
	}
	$q = TW_Store::get( 'muse_quota', array() );
	return ( is_array( $q ) && ( $q['day'] ?? '' ) === uw_muse_quota_day() ) ? (int) $q['used'] : 0;
}

/**
 * @param int $units Estimated YouTube API units this Muse action will use.
 * @return true|WP_Error
 */
function uw_muse_quota_check( $units = 1 ) {
	$cap = uw_muse_daily_unit_cap();
	if ( $cap <= 0 ) {
		return true;
	}
	$used = uw_muse_quota_used();
	if ( ( $used + max( 0, (int) $units ) ) > $cap ) {
		return new WP_Error(
			'uw_muse_quota',
			sprintf( 'Muse daily YouTube unit cap reached (%d / %d). Try again tomorrow.', $used, $cap ),
			array( 'status' => 429 )
		);
	}
	return true;
}

function uw_muse_quota_add( $units ) {
	$units = max( 0, (int) $units );
	if ( $units <= 0 || ! class_exists( 'TW_Store' ) ) {
		return;
	}
	if ( uw_muse_daily_unit_cap() <= 0 ) {
		return;
	}
	$day  = uw_muse_quota_day();
	$q    = TW_Store::get( 'muse_quota', array() );
	$used = ( is_array( $q ) && ( $q['day'] ?? '' ) === $day ) ? (int) $q['used'] : 0;
	TW_Store::set( 'muse_quota', array( 'day' => $day, 'used' => $used + $units ) );
}

/**
 * Log a Muse write. No key values.
 *
 * @param string     $action Short label.
 * @param string     $video  Optional video id.
 * @param array|null $extra  Extra safe fields.
 */
function uw_muse_log( $action, $video = '', $extra = null ) {
	if ( ! class_exists( 'TW_Store' ) ) {
		return;
	}
	$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
	$ip  = $ip ? substr( hash( 'sha256', $ip . '|' . wp_salt( 'auth' ) ), 0, 12 ) : '';
	$log = TW_Store::get( 'muse_log', array() );
	$log = is_array( $log ) ? $log : array();
	$row = array(
		'time'   => time(),
		'action' => sanitize_key( (string) $action ),
		'via'    => 'muse',
		'video'  => preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $video ),
		'ip'     => $ip,
	);
	if ( is_array( $extra ) ) {
		foreach ( array( 'status', 'note', 'post_id' ) as $k ) {
			if ( isset( $extra[ $k ] ) ) {
				$row[ $k ] = is_scalar( $extra[ $k ] ) ? $extra[ $k ] : '';
			}
		}
	}
	array_unshift( $log, $row );
	TW_Store::set( 'muse_log', array_slice( $log, 0, UW_MUSE_LOG_MAX ) );
}

/** Last N Muse actions for the current TW_Store scope (account page). */
function uw_muse_recent_actions( $limit = 10 ) {
	if ( ! class_exists( 'TW_Store' ) ) {
		return array();
	}
	$log = TW_Store::get( 'muse_log', array() );
	$log = is_array( $log ) ? $log : array();
	return array_slice( $log, 0, max( 1, (int) $limit ) );
}

/**
 * @return true|WP_Error
 */
function uw_muse_rate_limit( $user_id ) {
	$key     = 'uw_muse_rate_' . (int) $user_id;
	$bucket  = get_transient( $key );
	$bucket  = is_array( $bucket ) ? $bucket : array( 't' => time(), 'n' => 0 );
	$now     = time();
	if ( ( $now - (int) $bucket['t'] ) >= UW_MUSE_RATE_WINDOW ) {
		$bucket = array( 't' => $now, 'n' => 0 );
	}
	if ( (int) $bucket['n'] >= UW_MUSE_RATE_MAX ) {
		return new WP_Error(
			'uw_muse_rate',
			'Too many Muse requests. Wait a minute and try again.',
			array( 'status' => 429 )
		);
	}
	$bucket['n'] = (int) $bucket['n'] + 1;
	set_transient( $key, $bucket, UW_MUSE_RATE_WINDOW );
	return true;
}

function uw_muse_site_has_key() {
	if ( defined( 'UW_MUSE_KEY' ) && UW_MUSE_KEY ) {
		return true;
	}
	return '' !== (string) get_option( UW_MUSE_OPTION_HASH, '' );
}

function uw_muse_site_hint() {
	return (string) get_option( UW_MUSE_OPTION_HINT, '' );
}

function uw_muse_set_site_key( $key ) {
	$key = trim( (string) $key );
	delete_option( UW_MUSE_OPTION_KEY );
	if ( '' === $key ) {
		delete_option( UW_MUSE_OPTION_HASH );
		delete_option( UW_MUSE_OPTION_HINT );
		return true;
	}
	if ( strlen( $key ) < 32 ) {
		return false;
	}
	update_option( UW_MUSE_OPTION_HASH, uw_muse_hash_key( $key ), false );
	update_option( UW_MUSE_OPTION_HINT, uw_muse_hint_from_key( $key ), false );
	return true;
}

/** Migrate plain Muse keys to hash + hint, then delete the plain values. */
function uw_muse_maybe_migrate_keys() {
	if ( get_option( UW_MUSE_KEYS_MIGRATED ) ) {
		return;
	}
	$users = get_users(
		array(
			'meta_key'     => UW_MUSE_USER_META,
			'meta_compare' => 'EXISTS',
			'fields'       => array( 'ID' ),
			'number'       => 500,
			'count_total'  => false,
		)
	);
	foreach ( $users as $user ) {
		$plain = (string) get_user_meta( $user->ID, UW_MUSE_USER_META, true );
		if ( '' === $plain ) {
			delete_user_meta( $user->ID, UW_MUSE_USER_META );
			continue;
		}
		if ( '' === (string) get_user_meta( $user->ID, UW_MUSE_USER_HASH, true ) ) {
			update_user_meta( $user->ID, UW_MUSE_USER_HASH, uw_muse_hash_key( $plain ) );
			update_user_meta( $user->ID, UW_MUSE_USER_HINT, uw_muse_hint_from_key( $plain ) );
		}
		delete_user_meta( $user->ID, UW_MUSE_USER_META );
	}

	$site_plain = (string) get_option( UW_MUSE_OPTION_KEY, '' );
	if ( '' !== $site_plain ) {
		if ( '' === (string) get_option( UW_MUSE_OPTION_HASH, '' ) ) {
			update_option( UW_MUSE_OPTION_HASH, uw_muse_hash_key( $site_plain ), false );
			update_option( UW_MUSE_OPTION_HINT, uw_muse_hint_from_key( $site_plain ), false );
		}
		delete_option( UW_MUSE_OPTION_KEY );
	}

	update_option( UW_MUSE_KEYS_MIGRATED, '1', false );
}

/**
 * Find which account owns this Muse key.
 *
 * @return int|null User id, 0 for site key, null if invalid.
 */
function uw_muse_resolve_user_id( $sent ) {
	$sent = is_string( $sent ) ? trim( $sent ) : '';
	if ( '' === $sent || strlen( $sent ) < 16 ) {
		return null;
	}

	$hash  = uw_muse_hash_key( $sent );
	$users = get_users(
		array(
			'meta_key'     => UW_MUSE_USER_HASH,
			'meta_value'   => $hash,
			'number'       => 1,
			'fields'       => array( 'ID' ),
			'count_total'  => false,
		)
	);
	if ( ! empty( $users[0] ) ) {
		return (int) $users[0]->ID;
	}

	if ( defined( 'UW_MUSE_KEY' ) && UW_MUSE_KEY && hash_equals( (string) UW_MUSE_KEY, $sent ) ) {
		return 0;
	}
	$site_hash = (string) get_option( UW_MUSE_OPTION_HASH, '' );
	if ( '' !== $site_hash && hash_equals( $site_hash, $hash ) ) {
		return 0;
	}
	return null;
}

function uw_muse_request_user_id() {
	return isset( $GLOBALS['uw_muse_user_id'] ) && null !== $GLOBALS['uw_muse_user_id']
		? (int) $GLOBALS['uw_muse_user_id']
		: null;
}

function uw_muse_apply_context( $user_id ) {
	$GLOBALS['uw_muse_user_id'] = (int) $user_id;
	if ( class_exists( 'TW_Context' ) ) {
		if ( $user_id > 0 ) {
			TW_Context::set_user( $user_id );
		} else {
			TW_Context::clear();
		}
	}
}

function uw_muse_clear_context() {
	$GLOBALS['uw_muse_user_id'] = null;
	if ( class_exists( 'TW_Context' ) ) {
		TW_Context::clear();
	}
}

/**
 * @param WP_REST_Request $request Request.
 * @return true|WP_Error
 */
function uw_muse_permission( WP_REST_Request $request ) {
	$sent = $request->get_header( UW_MUSE_HEADER );
	if ( ! $sent ) {
		$auth = (string) $request->get_header( 'authorization' );
		if ( preg_match( '/^Bearer\s+(.+)$/i', $auth, $m ) ) {
			$sent = trim( $m[1] );
		}
	}
	$sent    = is_string( $sent ) ? trim( $sent ) : '';
	$user_id = uw_muse_resolve_user_id( $sent );
	if ( null === $user_id ) {
		return new WP_Error( 'uw_muse_unauthorized', 'Missing or invalid Muse key.', array( 'status' => 401 ) );
	}
	if ( $user_id > 0 ) {
		if ( ! uw_muse_user_allowed( $user_id ) ) {
			return new WP_Error( 'uw_muse_unauthorized', 'Missing or invalid Muse key.', array( 'status' => 401 ) );
		}
		if ( ! user_can( $user_id, 'use_uploadwriter' ) && ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error( 'uw_muse_unauthorized', 'Missing or invalid Muse key.', array( 'status' => 401 ) );
		}
	}
	$rate = uw_muse_rate_limit( $user_id );
	if ( is_wp_error( $rate ) ) {
		return $rate;
	}
	uw_muse_apply_context( $user_id );
	return true;
}

/**
 * Site/admin Muse key only (legacy UploadWriter → Muse, or UW_MUSE_KEY).
 * Used for promotional draft posts/pages. Per-user account keys are refused.
 *
 * @param WP_REST_Request $request Request.
 * @return true|WP_Error
 */
function uw_muse_permission_site( WP_REST_Request $request ) {
	$ok = uw_muse_permission( $request );
	if ( is_wp_error( $ok ) ) {
		return $ok;
	}
	$user_id = uw_muse_request_user_id();
	if ( null === $user_id || (int) $user_id > 0 ) {
		return new WP_Error(
			'uw_muse_site_only',
			'This route needs the site/admin Muse key (UploadWriter → Muse). Per-user account keys can only access that user’s videos.',
			array( 'status' => 403 )
		);
	}
	return true;
}

function uw_muse_is_site_scope() {
	$user_id = uw_muse_request_user_id();
	return null !== $user_id && (int) $user_id === 0;
}

add_action(
	'rest_api_init',
	function () {
		add_filter(
			'rest_pre_serve_request',
			function ( $served ) {
				uw_muse_clear_context();
				return $served;
			},
			999
		);
	},
	1
);

add_action(
	'rest_api_init',
	function () {
		$auth      = 'uw_muse_permission';
		$auth_site = 'uw_muse_permission_site';

		register_rest_route(
			'uw/v1',
			'/muse/schema',
			array(
				'methods'             => 'GET',
				'callback'            => 'uw_muse_rest_schema',
				'permission_callback' => $auth,
			)
		);
		register_rest_route(
			'uw/v1',
			'/muse/status',
			array(
				'methods'             => 'GET',
				'callback'            => 'uw_muse_rest_status',
				'permission_callback' => $auth,
			)
		);
		register_rest_route(
			'uw/v1',
			'/muse/settings',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => 'uw_muse_rest_settings_get',
					'permission_callback' => $auth,
				),
				array(
					'methods'             => array( 'POST', 'PATCH' ),
					'callback'            => 'uw_muse_rest_settings_patch',
					'permission_callback' => $auth,
				),
			)
		);
		register_rest_route(
			'uw/v1',
			'/muse/youtube/status',
			array(
				'methods'             => 'GET',
				'callback'            => 'uw_muse_rest_youtube_status',
				'permission_callback' => $auth,
			)
		);
		register_rest_route(
			'uw/v1',
			'/muse/videos',
			array(
				'methods'             => 'GET',
				'callback'            => 'uw_muse_rest_videos_list',
				'permission_callback' => $auth,
			)
		);
		register_rest_route(
			'uw/v1',
			'/muse/videos/(?P<id>[A-Za-z0-9_-]+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => 'uw_muse_rest_videos_get',
					'permission_callback' => $auth,
				),
				array(
					'methods'             => array( 'POST', 'PATCH' ),
					'callback'            => 'uw_muse_rest_videos_update',
					'permission_callback' => $auth,
				),
			)
		);
		register_rest_route(
			'uw/v1',
			'/muse/videos/(?P<id>[A-Za-z0-9_-]+)/generate',
			array(
				'methods'             => 'POST',
				'callback'            => 'uw_muse_rest_videos_generate',
				'permission_callback' => $auth,
			)
		);
		register_rest_route(
			'uw/v1',
			'/muse/videos/(?P<id>[A-Za-z0-9_-]+)/push-to-youtube',
			array(
				'methods'             => 'POST',
				'callback'            => 'uw_muse_rest_videos_push',
				'permission_callback' => $auth,
			)
		);
		register_rest_route(
			'uw/v1',
			'/muse/videos/(?P<id>[A-Za-z0-9_-]+)/history',
			array(
				'methods'             => 'GET',
				'callback'            => 'uw_muse_rest_videos_history',
				'permission_callback' => $auth,
			)
		);
		register_rest_route(
			'uw/v1',
			'/muse/videos/(?P<id>[A-Za-z0-9_-]+)/restore',
			array(
				'methods'             => 'POST',
				'callback'            => 'uw_muse_rest_videos_restore',
				'permission_callback' => $auth,
			)
		);
		register_rest_route(
			'uw/v1',
			'/muse/schedule',
			array(
				'methods'             => 'GET',
				'callback'            => 'uw_muse_rest_schedule',
				'permission_callback' => $auth,
			)
		);
		register_rest_route(
			'uw/v1',
			'/muse/playlists',
			array(
				'methods'             => 'GET',
				'callback'            => 'uw_muse_rest_playlists',
				'permission_callback' => $auth,
			)
		);
		register_rest_route(
			'uw/v1',
			'/muse/sync',
			array(
				'methods'             => 'POST',
				'callback'            => 'uw_muse_rest_sync',
				'permission_callback' => $auth,
			)
		);
		register_rest_route(
			'uw/v1',
			'/muse/media',
			array(
				'methods'             => 'POST',
				'callback'            => 'uw_muse_rest_media',
				'permission_callback' => $auth_site,
			)
		);
		register_rest_route(
			'uw/v1',
			'/muse/pages',
			array(
				'methods'             => 'POST',
				'callback'            => 'uw_muse_rest_pages',
				'permission_callback' => $auth_site,
			)
		);
		register_rest_route(
			'uw/v1',
			'/muse/posts',
			array(
				'methods'             => 'POST',
				'callback'            => 'uw_muse_rest_posts',
				'permission_callback' => $auth_site,
			)
		);
	}
);

function uw_muse_rest_schema() {
	$base = uw_muse_base_url();
	$cap  = uw_muse_daily_unit_cap();
	return rest_ensure_response(
		array(
			'auth_header' => UW_MUSE_HEADER,
			'base'        => $base,
			'limits'      => array(
				'title_max'         => class_exists( 'TW_Validator' ) ? TW_Validator::TITLE_MAX : 100,
				'tags_hard_max'     => class_exists( 'TW_Validator' ) ? TW_Validator::TAGS_HARD_MAX : 8,
				'hashtags_max'      => class_exists( 'TW_Validator' ) ? TW_Validator::HASH_MAX : 3,
				'description_max'   => class_exists( 'TW_Validator' ) ? TW_Validator::DESC_MAX : 5000,
				'videos_per_page'   => 20,
				'rate_per_minute'   => UW_MUSE_RATE_MAX,
				'muse_youtube_cap'  => $cap,
				'muse_youtube_used' => uw_muse_quota_used(),
				'youtube_project_cap'  => 10000,
				'youtube_project_used' => class_exists( 'TW_YouTube' ) ? TW_YouTube::quota_used() : 0,
				'media_max_bytes'   => 5 * MB_IN_BYTES,
			),
			'youtube_quota' => array(
				'project_daily_cap' => 10000,
				'project_used'      => class_exists( 'TW_YouTube' ) ? TW_YouTube::quota_used() : 0,
				'resets'            => 'midnight America/Los_Angeles',
				'muse_cap'          => $cap,
				'muse_used'         => uw_muse_quota_used(),
				'costs_units'       => array(
					'pull_captions'     => 250,
					'push_or_restore'   => 50,
					'thumbnail_upload'  => 50,
					'quick_sync'        => 'about 8-20',
					'full_sync_per_50'  => 2,
					'videos_list_get'   => 1,
					'playlists_list'    => '1 per 50',
				),
				'free'              => array(
					'local_index_list_search_filters',
					'schedule_calendar',
					'get_saved_draft_and_transcript',
					'patch_draft_fields',
					'paste_transcript',
					'ai_generate_revise_gemini_or_chatgpt',
					'dry_run_push_or_restore',
					'schema_status_settings_reads',
				),
				'confirm_before_spend' => 'Before any costs_units action, report estimated units and wait for explicit human OK unless they already asked for that exact spend.',
			),
			'permissions'       => array(
				'push'    => uw_muse_can_push(),
				'restore' => uw_muse_can_restore(),
				'sync'    => uw_muse_can_sync(),
			),
			'video_tw_statuses' => array( '', 'started', 'draft', 'pushed' ),
			'scope'             => uw_muse_is_site_scope() ? 'site' : 'user',
			'endpoints'         => array(
				'schema'          => array( 'GET', $base . '/muse/schema', 'any', '' ),
				'status'          => array( 'GET', $base . '/muse/status', 'any', '' ),
				'settings'        => array( 'GET|POST|PATCH', $base . '/muse/settings', 'GET any; PATCH user', '' ),
				'youtube_status'  => array( 'GET', $base . '/muse/youtube/status', 'any', '' ),
				'videos'          => array( 'GET', $base . '/muse/videos', 'any', '' ),
				'video'           => array( 'GET|POST|PATCH', $base . '/muse/videos/{id}', 'any', '' ),
				'generate'        => array( 'POST', $base . '/muse/videos/{id}/generate', 'any', '' ),
				'push_to_youtube' => array( 'POST', $base . '/muse/videos/{id}/push-to-youtube', 'any', 'push' ),
				'history'         => array( 'GET', $base . '/muse/videos/{id}/history', 'any', '' ),
				'restore'         => array( 'POST', $base . '/muse/videos/{id}/restore', 'user', 'restore' ),
				'schedule'        => array( 'GET', $base . '/muse/schedule', 'any', '' ),
				'playlists'       => array( 'GET', $base . '/muse/playlists', 'any', '' ),
				'sync'            => array( 'POST', $base . '/muse/sync', 'user', 'sync' ),
				'media'           => array( 'POST', $base . '/muse/media', 'site', '' ),
				'pages'           => array( 'POST', $base . '/muse/pages', 'site', '' ),
				'posts'           => array( 'POST', $base . '/muse/posts', 'site', '' ),
			),
			'fields'            => array(
				'videos_list'  => array( 'status', 'visibility', 'search', 'page', 'per_page' ),
				'video_update' => array(
					'transcript',
					'notes',
					'seed_title',
					'seed_desc',
					'pkg',
					'opts',
					'thumb_description',
					'step',
				),
				'pkg'          => array( 'titles', 'title', 'body', 'chapters_heading', 'chapters', 'tags', 'hashtags' ),
				'generate'     => array( 'notes', 'transcript', 'seed_title', 'seed_desc' ),
				'push'         => array( 'confirm_push', 'dry_run', 'fingerprint' ),
				'restore'      => array( 'entry_id', 'confirm' ),
				'pages_posts'  => array( 'external_id', 'title', 'slug', 'content_html', 'update', 'categories', 'tags', 'featured_media' ),
				'settings'     => class_exists( 'TW_Settings' ) ? TW_Settings::IDENTITY_KEYS : array(),
			),
			'notes'             => array(
				'Always send ' . UW_MUSE_HEADER . ' and Content-Type: application/json.',
				'User scope (Account → Keys & voice → Muse): that user’s identity, toggles, videos, and OAuth. No posts/pages/media.',
				'Site scope (UploadWriter → Muse): promotional draft posts/pages/media, plus the site/admin video index. Not /tubewriter/account/.',
				'If GET /muse/status shows user_id 0 and login site, Muse has the site key. Swap to a per-user key for front-end account work.',
				'PATCH /muse/settings (identity) requires a user key. Site keys get 403 so they cannot write site options the front end ignores.',
				'Push / restore / sync need the matching toggle on the account (or site push setting for the site key). Off = dry run only. Sync/restore stay off for site keys.',
				'Rate limit: ' . UW_MUSE_RATE_MAX . ' requests per minute per key. User keys also have a Muse YouTube unit cap (default 1000/day). Site key cap 0 means unlimited / not tracked.',
				'YouTube project quota is 10,000 units/day (midnight Pacific). Pull captions = 250. Prefer pasted transcripts and local index reads. See youtube_quota on this schema.',
				'Before any YouTube spend (captions, real push, restore, sync, thumbnail, live video fetch), check youtube_quota, tell the human the cost, and wait for OK unless they already asked for that spend.',
				'Do not pull captions when transcript is already stored or captions_status is ok/none.',
				'Royal MCP is separate (Authorization: Bearer).',
				'POST /muse/pages and /muse/posts are site-key only, drafts only. Never publishes.',
				'Push requires confirm_push:true. Use dry_run:true first.',
				'Never sign in to Google, YouTube, or wp-admin in a browser as Muse.',
			),
		)
	);
}

function uw_muse_rest_status() {
	$counts  = class_exists( 'TW_Index' ) ? TW_Index::counts() : array();
	$user_id = uw_muse_request_user_id();
	$user    = ( $user_id && $user_id > 0 ) ? get_userdata( $user_id ) : null;
	$is_user = ( $user_id && $user_id > 0 );
	$tw      = array(
		'started' => 0,
		'draft'   => 0,
		'pushed'  => 0,
		'none'    => 0,
	);
	if ( class_exists( 'TW_Index' ) ) {
		foreach ( array( 'draft' => 'draft', 'pushed' => 'pushed', 'none' => 'none' ) as $key => $filter ) {
			$q          = TW_Index::query( array( 'tw' => $filter, 'per_page' => 1, 'paged' => 1 ) );
			$tw[ $key ] = (int) ( $q['total'] ?? 0 );
		}
		$started_q = TW_Index::query( array( 'tw' => 'draft', 'per_page' => 200, 'paged' => 1 ) );
		$started   = 0;
		foreach ( (array) ( $started_q['items'] ?? array() ) as $row ) {
			if ( 'started' === ( $row['tw_status'] ?? '' ) ) {
				$started++;
			}
		}
		$tw['started'] = $started;
		$tw['draft']   = max( 0, $tw['draft'] - $started );
	}

	$channel = class_exists( 'TW_Store' ) ? TW_Store::get( 'channel', array() ) : array();
	$cap     = uw_muse_daily_unit_cap();
	$note    = $is_user
		? 'User Muse key. Reads/writes this WordPress user’s front-end account (identity, toggles, videos, OAuth).'
		: 'Site Muse key (UploadWriter → Muse). This is site/admin storage, not /tubewriter/account/. Empty identity and video counts here are expected. Create a per-user key under Account → Keys & voice → Muse and put that key in Muse for Greg’s front-end account.';

	return rest_ensure_response(
		array(
			'app'               => defined( 'TW_BRAND' ) ? TW_BRAND : 'UploadWriter',
			'version'           => defined( 'TW_VERSION' ) ? TW_VERSION : '',
			'account'           => array(
				'user_id'  => (int) $user_id,
				'login'    => $user ? $user->user_login : ( 0 === (int) $user_id ? 'site' : '' ),
				'scope'    => $is_user ? 'user' : 'site',
				'storage'  => $is_user ? 'user_meta' : 'site_options',
				'key_from' => $is_user ? 'Account → Keys & voice → Muse' : 'wp-admin → UploadWriter → Muse (site key)',
			),
			'gemini_configured' => class_exists( 'TW_Store' ) && TW_Store::has( 'gemini_key' ),
			'openai_configured' => class_exists( 'TW_Store' ) && TW_Store::has( 'openai_key' ),
			'youtube_connected' => class_exists( 'TW_OAuth' ) && TW_OAuth::is_connected(),
			'identity_complete' => class_exists( 'TW_Settings' ) && TW_Settings::identity_complete(),
			'channel'           => array(
				'id'    => is_array( $channel ) ? (string) ( $channel['id'] ?? '' ) : '',
				'title' => is_array( $channel ) ? (string) ( $channel['title'] ?? '' ) : '',
			),
			'permissions'       => array(
				'push'    => uw_muse_can_push(),
				'restore' => uw_muse_can_restore(),
				'sync'    => uw_muse_can_sync(),
			),
			'muse_quota'        => array(
				'used' => uw_muse_quota_used(),
				'cap'  => $cap,
				'note' => $is_user
					? 'Daily Muse YouTube API unit budget for this user key (default 1000).'
					: 'Site keys have no Muse YouTube unit cap (cap 0 means unlimited / not tracked). Sync and restore stay off for site keys by design.',
			),
			'youtube_quota'     => array(
				'project_used' => class_exists( 'TW_YouTube' ) ? TW_YouTube::quota_used() : 0,
				'project_cap'  => 10000,
				'resets'       => 'midnight America/Los_Angeles',
				'note'         => 'Shared Google Cloud project units. Caption pulls cost 250. Confirm with the human before any spend.',
			),
			'video_counts'      => array(
				'indexed'   => (int) ( $counts['all'] ?? 0 ),
				'public'    => (int) ( $counts['public'] ?? 0 ),
				'draft_yt'  => (int) ( $counts['draft'] ?? 0 ),
				'scheduled' => (int) ( $counts['scheduled'] ?? 0 ),
				'tw'        => $tw,
			),
			'note'              => $note,
		)
	);
}

function uw_muse_rest_settings_get() {
	if ( ! class_exists( 'TW_Settings' ) ) {
		return new WP_Error( 'uw_muse_app', 'UploadWriter is not loaded.', array( 'status' => 503 ) );
	}
	$is_user = ! uw_muse_is_site_scope();
	$notes   = array(
		'Prompt/voice text is not returned here. Identity fields drive generation.',
		'No API keys, OAuth tokens, or Muse key in this response.',
	);
	if ( ! $is_user ) {
		$notes[] = 'Site Muse key: identity here is site/admin options, not the front-end account at /tubewriter/account/. Empty fields usually mean no site identity was saved. Use a per-user Muse key for that account.';
	} else {
		$notes[] = 'User Muse key: identity is this account’s user meta (same fields as Account → Keys & voice).';
	}
	return rest_ensure_response(
		array(
			'identity'          => TW_Settings::identity(),
			'identity_complete' => TW_Settings::identity_complete(),
			'scope'             => $is_user ? 'user' : 'site',
			'defaults'          => array(
				'language' => TW_Settings::get( 'default_language' ),
				'category' => TW_Settings::get( 'default_category' ),
				'time'     => TW_Settings::get( 'default_time' ),
			),
			'limits'            => array(
				'title_max'       => TW_Validator::TITLE_MAX,
				'tags_hard_max'   => TW_Validator::TAGS_HARD_MAX,
				'hashtags_max'    => TW_Validator::HASH_MAX,
				'description_max' => TW_Validator::DESC_MAX,
			),
			'notes'             => $notes,
		)
	);
}

function uw_muse_rest_settings_patch( WP_REST_Request $request ) {
	if ( ! class_exists( 'TW_Settings' ) ) {
		return new WP_Error( 'uw_muse_app', 'UploadWriter is not loaded.', array( 'status' => 503 ) );
	}
	if ( uw_muse_is_site_scope() ) {
		return new WP_Error(
			'uw_muse_user_only',
			'Identity PATCH needs a per-user Muse key (Account → Keys & voice → Muse). The site key would write site options that the front-end account ignores.',
			array( 'status' => 403 )
		);
	}
	$body    = $request->get_json_params();
	$body    = is_array( $body ) ? $body : array();
	$changed = array();
	foreach ( TW_Settings::IDENTITY_KEYS as $key ) {
		if ( ! array_key_exists( $key, $body ) ) {
			continue;
		}
		$val = sanitize_textarea_field( (string) $body[ $key ] );
		TW_Store::set( $key, $val );
		$changed[] = $key;
	}
	if ( $changed && TW_Settings::identity_complete() ) {
		TW_Store::set( 'identity_complete', '1' );
	}
	return rest_ensure_response(
		array(
			'changed'  => $changed,
			'identity' => TW_Settings::identity(),
			'scope'    => 'user',
		)
	);
}

function uw_muse_rest_youtube_status() {
	$channel = class_exists( 'TW_Store' ) ? TW_Store::get( 'channel', array() ) : array();
	$channel = is_array( $channel ) ? $channel : array();
	$cap     = uw_muse_daily_unit_cap();
	return rest_ensure_response(
		array(
			'connected'     => class_exists( 'TW_OAuth' ) && TW_OAuth::is_connected(),
			'needs_reauth'  => class_exists( 'TW_OAuth' ) && TW_OAuth::needs_reconnect(),
			'scope'         => defined( 'TW_SCOPE' ) ? TW_SCOPE : '',
			'refresh_token' => class_exists( 'TW_OAuth' ) && TW_OAuth::is_connected() ? 'yes' : 'no',
			'channel'       => array(
				'id'    => (string) ( $channel['id'] ?? '' ),
				'title' => (string) ( $channel['title'] ?? '' ),
			),
			'quota'         => array(
				'project_used' => class_exists( 'TW_YouTube' ) ? TW_YouTube::quota_used() : 0,
				'project_cap'  => 10000,
				'muse_used'    => uw_muse_quota_used(),
				'muse_cap'     => $cap,
				'resets'       => 'midnight America/Los_Angeles',
				'expensive'    => array(
					'pull_captions' => 250,
					'push_restore'  => 50,
					'thumb_upload'  => 50,
					'quick_sync'    => 'about 8-20',
				),
				'free'         => array( 'local_index', 'saved_draft', 'paste_transcript', 'ai_generate', 'dry_run' ),
				'note'         => 'Confirm with the human before any expensive action. Prefer paste over caption pulls.',
			),
		)
	);
}

function uw_muse_video_id( WP_REST_Request $request ) {
	return preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $request['id'] );
}

function uw_muse_get_video_row( $video_id ) {
	global $wpdb;
	if ( ! class_exists( 'TW_Index' ) || '' === $video_id ) {
		return null;
	}
	$row = $wpdb->get_row(
		$wpdb->prepare(
			'SELECT id, title, description, privacy, publish_at, published_at, views, duration, thumb, tw_status, captions_status
			FROM ' . TW_Index::table() . ' WHERE user_id = %d AND id = %s',
			TW_Index::uid(),
			$video_id
		),
		ARRAY_A
	); // phpcs:ignore
	return is_array( $row ) ? $row : null;
}

function uw_muse_get_state( $video_id ) {
	$state = TW_Store::get( 'state_' . $video_id, array() );
	return is_array( $state ) ? $state : array();
}

function uw_muse_save_state( $video_id, array $data ) {
	$state = array_merge( uw_muse_get_state( $video_id ), $data );
	TW_Store::set( 'state_' . $video_id, $state );
	TW_Index::set_tw_status(
		$video_id,
		! empty( $state['pushed'] ) ? 'pushed' : ( ! empty( $state['pkg'] ) ? 'draft' : 'started' )
	);
	return $state;
}

function uw_muse_normalize_pkg( $raw ) {
	if ( ! is_array( $raw ) ) {
		return array(
			'titles'           => array(),
			'title'            => '',
			'body'             => '',
			'chapters_heading' => '',
			'chapters'         => array(),
			'tags'             => array(),
			'hashtags'         => array(),
		);
	}
	$chapters = $raw['chapters'] ?? array();
	if ( is_string( $chapters ) ) {
		$chapters = TW_Validator::chapters_from_text( $chapters );
	}
	$tags = $raw['tags'] ?? array();
	if ( is_string( $tags ) ) {
		$tags = explode( ',', $tags );
	}
	$hashtags = $raw['hashtags'] ?? array();
	if ( is_string( $hashtags ) ) {
		$hashtags = preg_split( '/\s+/', trim( $hashtags ) );
	}
	return array(
		'titles'           => array_values( array_map( 'strval', (array) ( $raw['titles'] ?? array() ) ) ),
		'title'            => (string) ( $raw['title'] ?? '' ),
		'body'             => (string) ( $raw['body'] ?? '' ),
		'chapters_heading' => (string) ( $raw['chapters_heading'] ?? '' ),
		'chapters'         => array_values( (array) $chapters ),
		'tags'             => array_values( array_map( 'trim', array_map( 'strval', (array) $tags ) ) ),
		'hashtags'         => array_values( array_filter( array_map( 'strval', (array) $hashtags ) ) ),
	);
}

function uw_muse_default_opts( array $state = array() ) {
	$opts = isset( $state['opts'] ) && is_array( $state['opts'] ) ? $state['opts'] : array();
	$pl   = (array) ( $opts['playlist'] ?? array() );
	$sc   = (array) ( $opts['schedule'] ?? array() );
	return array(
		'language' => sanitize_text_field( $opts['language'] ?? TW_Settings::get( 'default_language' ) ),
		'category' => sanitize_text_field( $opts['category'] ?? TW_Settings::get( 'default_category' ) ),
		'playlist' => array(
			'mode'      => in_array( $pl['mode'] ?? '', array( 'none', 'existing', 'new' ), true ) ? $pl['mode'] : 'none',
			'id'        => sanitize_text_field( $pl['id'] ?? '' ),
			'title'     => sanitize_text_field( $pl['title'] ?? '' ),
			'rough'     => sanitize_text_field( $pl['rough'] ?? '' ),
			'new_title' => sanitize_text_field( $pl['new_title'] ?? '' ),
			'new_desc'  => sanitize_textarea_field( $pl['new_desc'] ?? '' ),
			'privacy'   => sanitize_text_field( $pl['privacy'] ?? 'public' ),
		),
		'schedule' => array(
			'mode' => in_array( $sc['mode'] ?? '', array( 'keep', 'set', 'now' ), true ) ? $sc['mode'] : 'keep',
			'date' => preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $sc['date'] ?? '' ) ) ? $sc['date'] : '',
			'time' => preg_match( '/^\d{2}:\d{2}$/', (string) ( $sc['time'] ?? '' ) ) ? $sc['time'] : '',
		),
	);
}

function uw_muse_video_payload( array $row, array $state = array() ) {
	$pkg = uw_muse_normalize_pkg( $state['pkg'] ?? array() );
	return array(
		'id'               => $row['id'],
		'title'            => $row['title'],
		'privacy'          => $row['privacy'],
		'publish_at'       => $row['publish_at'],
		'published_at'     => $row['published_at'],
		'views'            => (int) $row['views'],
		'duration'         => (int) $row['duration'],
		'thumb'            => $row['thumb'],
		'tw_status'        => $row['tw_status'],
		'captions_status'  => $row['captions_status'],
		'has_package'      => ! empty( $state['pkg'] ),
		'youtube_url'      => 'https://www.youtube.com/watch?v=' . $row['id'],
		'edit_url'         => admin_url( 'admin.php?page=tubewriter&video=' . rawurlencode( $row['id'] ) ),
		'transcript'       => (string) ( $state['transcript'] ?? '' ),
		'notes'            => (string) ( $state['notes'] ?? '' ),
		'seed_title'       => (string) ( $state['seed_title'] ?? '' ),
		'seed_desc'        => (string) ( $state['seed_desc'] ?? '' ),
		'thumb_description'=> (string) ( $state['thumb_description'] ?? '' ),
		'step'             => (int) ( $state['step'] ?? 0 ),
		'pkg'              => $pkg,
		'opts'             => uw_muse_default_opts( $state ),
		'pushed_at'        => ! empty( $state['pushed'] ) ? (int) $state['pushed'] : null,
		'saved_at'         => ! empty( $state['saved'] ) ? (int) $state['saved'] : null,
	);
}

function uw_muse_rest_videos_list( WP_REST_Request $request ) {
	if ( ! class_exists( 'TW_Index' ) ) {
		return new WP_Error( 'uw_muse_app', 'UploadWriter is not loaded.', array( 'status' => 503 ) );
	}
	$page     = max( 1, (int) $request->get_param( 'page' ) );
	$per_page = max( 1, min( 50, (int) ( $request->get_param( 'per_page' ) ?: 20 ) ) );
	$result   = TW_Index::query(
		array(
			'search'     => (string) ( $request->get_param( 'search' ) ?: '' ),
			'title'      => (string) ( $request->get_param( 'title' ) ?: '' ),
			'visibility' => (string) ( $request->get_param( 'visibility' ) ?: '' ),
			'tw'         => (string) ( $request->get_param( 'status' ) ?: $request->get_param( 'tw' ) ?: '' ),
			'orderby'    => 'date',
			'order'      => 'desc',
			'per_page'   => $per_page,
			'paged'      => $page,
		)
	);
	$items = array();
	foreach ( (array) $result['items'] as $row ) {
		$state   = uw_muse_get_state( $row['id'] );
		$items[] = array(
			'id'              => $row['id'],
			'title'           => $row['title'],
			'privacy'         => $row['privacy'],
			'tw_status'       => $row['tw_status'],
			'captions_status' => $row['captions_status'],
			'has_package'     => ! empty( $state['pkg'] ),
			'duration'        => (int) $row['duration'],
			'views'           => (int) $row['views'],
			'published_at'    => $row['published_at'],
			'publish_at'      => $row['publish_at'],
			'thumb'           => $row['thumb'],
			'edit_url'        => admin_url( 'admin.php?page=tubewriter&video=' . rawurlencode( $row['id'] ) ),
		);
	}
	return rest_ensure_response(
		array(
			'items'    => $items,
			'total'    => (int) $result['total'],
			'page'     => $page,
			'per_page' => (int) $result['per_page'],
		)
	);
}

function uw_muse_rest_videos_get( WP_REST_Request $request ) {
	$video_id = uw_muse_video_id( $request );
	$row      = uw_muse_get_video_row( $video_id );
	if ( ! $row ) {
		return new WP_Error( 'uw_muse_not_found', 'Video not in the local index.', array( 'status' => 404 ) );
	}
	return rest_ensure_response( uw_muse_video_payload( $row, uw_muse_get_state( $video_id ) ) );
}

function uw_muse_rest_videos_update( WP_REST_Request $request ) {
	$video_id = uw_muse_video_id( $request );
	$row      = uw_muse_get_video_row( $video_id );
	if ( ! $row ) {
		return new WP_Error( 'uw_muse_not_found', 'Video not in the local index.', array( 'status' => 404 ) );
	}
	$body    = $request->get_json_params();
	$body    = is_array( $body ) ? $body : array();
	$changed = array();
	$extra   = array( 'saved' => time() );

	foreach ( array( 'transcript', 'notes', 'seed_title', 'seed_desc', 'thumb_description' ) as $field ) {
		if ( ! array_key_exists( $field, $body ) ) {
			continue;
		}
		$val = (string) $body[ $field ];
		if ( 'transcript' === $field ) {
			$val = TW_Transcript::normalize( $val );
		} elseif ( in_array( $field, array( 'notes', 'seed_desc', 'thumb_description' ), true ) ) {
			$val = sanitize_textarea_field( $val );
		} else {
			$val = sanitize_text_field( $val );
		}
		$extra[ $field ] = $val;
		$changed[]       = $field;
	}
	if ( array_key_exists( 'pkg', $body ) ) {
		$extra['pkg'] = uw_muse_normalize_pkg( $body['pkg'] );
		$changed[]    = 'pkg';
	}
	if ( array_key_exists( 'opts', $body ) && is_array( $body['opts'] ) ) {
		$merged         = uw_muse_default_opts( array( 'opts' => array_merge( uw_muse_get_state( $video_id )['opts'] ?? array(), $body['opts'] ) ) );
		$extra['opts']  = $merged;
		$changed[]      = 'opts';
	}
	if ( array_key_exists( 'step', $body ) ) {
		$step          = max( 1, min( 7, absint( $body['step'] ) ) );
		$extra['step'] = $step;
		$changed[]     = 'step';
	}
	if ( array_key_exists( 'transcript', $extra ) ) {
		$status = '' !== trim( $extra['transcript'] ) ? 'ok' : (string) ( uw_muse_get_state( $video_id )['captions_status'] ?? '' );
		if ( '' !== trim( $extra['transcript'] ) ) {
			$extra['captions_status'] = 'ok';
			TW_Index::set_captions_status( $video_id, 'ok' );
		}
		unset( $status );
	}

	$state = uw_muse_save_state( $video_id, $extra );
	$row   = uw_muse_get_video_row( $video_id );
	if ( $changed ) {
		uw_muse_log( 'draft_update', $video_id, array( 'status' => 'ok' ) );
	}
	return rest_ensure_response(
		array(
			'id'      => $video_id,
			'changed' => $changed,
			'status'  => $row['tw_status'] ?? '',
			'video'   => uw_muse_video_payload( $row, $state ),
		)
	);
}

function uw_muse_rest_videos_generate( WP_REST_Request $request ) {
	$video_id = uw_muse_video_id( $request );
	$row      = uw_muse_get_video_row( $video_id );
	if ( ! $row ) {
		return new WP_Error( 'uw_muse_not_found', 'Video not in the local index.', array( 'status' => 404 ) );
	}
	if ( ! class_exists( 'TW_Settings' ) || ! TW_Settings::identity_complete() ) {
		return new WP_Error( 'uw_muse_identity', 'Channel identity is incomplete. Fill identity settings first.', array( 'status' => 400 ) );
	}

	$body  = $request->get_json_params();
	$body  = is_array( $body ) ? $body : array();
	$state = uw_muse_get_state( $video_id );

	$transcript = array_key_exists( 'transcript', $body ) ? (string) $body['transcript'] : (string) ( $state['transcript'] ?? '' );
	$notes      = array_key_exists( 'notes', $body ) ? (string) $body['notes'] : (string) ( $state['notes'] ?? '' );
	$seed_title = array_key_exists( 'seed_title', $body ) ? (string) $body['seed_title'] : (string) ( $state['seed_title'] ?? $row['title'] );
	$seed_desc  = array_key_exists( 'seed_desc', $body ) ? (string) $body['seed_desc'] : (string) ( $state['seed_desc'] ?? $row['description'] );
	$duration   = (int) $row['duration'];

	$transcript = TW_Transcript::normalize( $transcript );
	if ( '' === trim( $transcript ) && '' === trim( $notes ) && '' === trim( $seed_desc ) ) {
		return new WP_Error( 'uw_muse_input', 'Nothing to work from. Add a transcript, notes, or seed_desc.', array( 'status' => 400 ) );
	}

	$input = array(
		'video'               => $video_id,
		'transcript'          => $transcript,
		'notes'               => $notes,
		'duration'            => $duration,
		'duration_label'      => $duration ? TW_Transcript::fmt( $duration ) : '',
		'current_title'       => $seed_title,
		'current_description' => $seed_desc,
	);

	$provider = 'gemini';
	if ( class_exists( 'TW_Store' ) && TW_Store::has( 'gemini_key' ) ) {
		$provider = 'gemini';
	} elseif ( class_exists( 'TW_Store' ) && TW_Store::has( 'openai_key' ) ) {
		$provider = 'openai';
	}

	$pkg = TW_AI::generate( $input, $provider );
	if ( is_wp_error( $pkg ) ) {
		return $pkg;
	}
	$check = TW_Validator::check( $pkg, $duration );
	$check['pkg']['titles'] = array_map( array( 'TW_Validator', 'clean_text' ), $pkg['titles'] );

	$state = uw_muse_save_state(
		$video_id,
		array(
			'transcript'   => $transcript,
			'notes'        => $notes,
			'seed_title'   => $seed_title,
			'seed_desc'    => $seed_desc,
			'pkg'          => $check['pkg'],
			'ai_generated' => 1,
			'saved'        => time(),
		)
	);

	uw_muse_log( 'generate', $video_id, array( 'status' => 'ok' ) );

	return rest_ensure_response(
		array(
			'id'           => $video_id,
			'pushed'       => false,
			'published'    => false,
			'pkg'          => $check['pkg'],
			'errors'       => $check['errors'] ?? array(),
			'warnings'     => $check['warnings'] ?? array(),
			'description'  => $check['description'] ?? '',
			'tw_status'    => 'draft',
			'edit_url'     => admin_url( 'admin.php?page=tubewriter&video=' . rawurlencode( $video_id ) ),
			'ai_usage'     => TW_AI::last_usage(),
		)
	);
}

function uw_muse_rest_videos_push( WP_REST_Request $request ) {
	$video_id = uw_muse_video_id( $request );
	$row      = uw_muse_get_video_row( $video_id );
	if ( ! $row ) {
		return new WP_Error( 'uw_muse_not_found', 'Video not in the local index.', array( 'status' => 404 ) );
	}

	$body     = $request->get_json_params();
	$body     = is_array( $body ) ? $body : array();
	$dry_run  = ! empty( $body['dry_run'] );
	$confirm  = ! empty( $body['confirm_push'] );
	$can_push = uw_muse_can_push();
	$state    = uw_muse_get_state( $video_id );
	$pkg      = uw_muse_normalize_pkg( $state['pkg'] ?? array() );
	$opts     = uw_muse_default_opts( $state );
	$duration = (int) $row['duration'];

	if ( empty( $pkg['title'] ) && empty( $pkg['titles'] ) ) {
		return new WP_Error( 'uw_muse_no_pkg', 'No generated package on this draft. Generate or PATCH pkg first.', array( 'status' => 400 ) );
	}

	$video = TW_YouTube::get_video( $video_id );
	if ( is_wp_error( $video ) ) {
		return $video;
	}
	$plan = TW_Plan::build( $video, $pkg, $opts, $duration );

	if ( $dry_run || ! $confirm || ! $can_push ) {
		$note = 'Pass confirm_push:true and fingerprint from this dry_run to push. dry_run:true never calls YouTube write.';
		if ( ! $can_push ) {
			$note = 'Muse push is off for this key. Dry run only. Turn on "Let Muse push changes to YouTube" on the account (or the site Muse setting for the site key).';
		}
		return rest_ensure_response(
			array(
				'dry_run'     => true,
				'pushed'      => false,
				'id'          => $video_id,
				'fingerprint' => $plan['fingerprint'],
				'errors'      => $plan['errors'],
				'warnings'    => $plan['warnings'],
				'actions'     => $plan['actions'],
				'details'     => array(
					'title'       => $plan['details']['title'] ?? '',
					'tags'        => $plan['details']['tags'] ?? array(),
					'categoryId'  => $plan['details']['categoryId'] ?? '',
					'language'    => $plan['details']['defaultLanguage'] ?? '',
					'description' => mb_substr( (string) ( $plan['details']['description'] ?? '' ), 0, 400 ),
				),
				'schedule'    => $plan['schedule'],
				'push_allowed'=> $can_push,
				'note'        => $note,
			)
		);
	}

	$quota = uw_muse_quota_check( 50 );
	if ( is_wp_error( $quota ) ) {
		return $quota;
	}

	$fingerprint = (string) ( $body['fingerprint'] ?? '' );
	if ( '' === $fingerprint ) {
		return new WP_Error( 'uw_muse_fingerprint', 'fingerprint from dry_run is required for a real push.', array( 'status' => 400 ) );
	}

	$result = TW_Plan::publish( $video_id, $pkg, $opts, $duration, $fingerprint );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	uw_muse_quota_add( 50 );
	uw_muse_log( 'push', $video_id, array( 'status' => ! empty( $result['wrote'] ) ? 'ok' : 'partial' ) );

	uw_muse_save_state(
		$video_id,
		array(
			'pkg'    => $pkg,
			'opts'   => $opts,
			'pushed' => time(),
		)
	);
	TW_Index::refresh_one( $video_id );
	if ( ! empty( $result['wrote'] ) ) {
		$sched = (array) ( $result['schedule'] ?? array() );
		TW_Index::apply_visibility( $video_id, $sched['mode'] ?? 'keep', $sched['at'] ?? '' );
	}
	TW_Index::set_tw_status( $video_id, 'pushed' );

	return rest_ensure_response(
		array(
			'dry_run'     => false,
			'pushed'      => true,
			'id'          => $video_id,
			'youtube_url' => 'https://www.youtube.com/watch?v=' . $video_id,
			'result'      => $result,
			'history'     => TW_History::summary( $video_id ),
		)
	);
}

function uw_muse_rest_videos_history( WP_REST_Request $request ) {
	$video_id = uw_muse_video_id( $request );
	$row      = uw_muse_get_video_row( $video_id );
	if ( ! $row ) {
		return new WP_Error( 'uw_muse_not_found', 'Video not in the local index.', array( 'status' => 404 ) );
	}
	if ( ! class_exists( 'TW_History' ) ) {
		return new WP_Error( 'uw_muse_app', 'UploadWriter is not loaded.', array( 'status' => 503 ) );
	}
	return rest_ensure_response(
		array(
			'id'      => $video_id,
			'history' => TW_History::summary( $video_id ),
		)
	);
}

function uw_muse_rest_videos_restore( WP_REST_Request $request ) {
	$video_id = uw_muse_video_id( $request );
	$row      = uw_muse_get_video_row( $video_id );
	if ( ! $row ) {
		return new WP_Error( 'uw_muse_not_found', 'Video not in the local index.', array( 'status' => 404 ) );
	}
	$body    = $request->get_json_params();
	$body    = is_array( $body ) ? $body : array();
	$entry   = sanitize_text_field( (string) ( $body['entry_id'] ?? '' ) );
	$confirm = ! empty( $body['confirm'] );
	$can     = uw_muse_can_restore();

	if ( ! $can || ! $confirm ) {
		$hist = class_exists( 'TW_History' ) ? TW_History::summary( $video_id ) : array();
		$note = ! $can
			? 'Muse restore is off for this key. Dry run only. Turn on "Let Muse restore older versions" on the account.'
			: 'Pass confirm:true and entry_id to restore. Nothing was written.';
		return rest_ensure_response(
			array(
				'dry_run'       => true,
				'restored'      => false,
				'id'            => $video_id,
				'entry_id'      => $entry,
				'restore_allowed' => $can,
				'history'       => $hist,
				'note'          => $note,
			)
		);
	}
	if ( '' === $entry ) {
		return new WP_Error( 'uw_muse_entry', 'entry_id is required.', array( 'status' => 400 ) );
	}
	$quota = uw_muse_quota_check( 50 );
	if ( is_wp_error( $quota ) ) {
		return $quota;
	}
	$result = TW_Plan::restore( $video_id, $entry );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	uw_muse_quota_add( 50 );
	uw_muse_log( 'restore', $video_id, array( 'status' => ! empty( $result['ok'] ) ? 'ok' : 'partial' ) );
	TW_Index::refresh_one( $video_id );
	return rest_ensure_response(
		array(
			'dry_run'  => false,
			'restored' => true,
			'id'       => $video_id,
			'result'   => $result,
			'history'  => TW_History::summary( $video_id ),
		)
	);
}

function uw_muse_rest_schedule() {
	if ( ! class_exists( 'TW_Index' ) ) {
		return new WP_Error( 'uw_muse_app', 'UploadWriter is not loaded.', array( 'status' => 503 ) );
	}
	return rest_ensure_response(
		array(
			'events' => TW_Index::calendar_events(),
			'units'  => 0,
		)
	);
}

function uw_muse_rest_playlists() {
	if ( ! class_exists( 'TW_YouTube' ) ) {
		return new WP_Error( 'uw_muse_app', 'UploadWriter is not loaded.', array( 'status' => 503 ) );
	}
	$quota = uw_muse_quota_check( 1 );
	if ( is_wp_error( $quota ) ) {
		return $quota;
	}
	$list = TW_YouTube::playlists();
	if ( is_wp_error( $list ) ) {
		return $list;
	}
	uw_muse_quota_add( max( 1, (int) ceil( count( $list ) / 50 ) ) );
	return rest_ensure_response( array( 'items' => $list ) );
}

function uw_muse_rest_sync( WP_REST_Request $request ) {
	if ( ! class_exists( 'TW_Index' ) ) {
		return new WP_Error( 'uw_muse_app', 'UploadWriter is not loaded.', array( 'status' => 503 ) );
	}
	$can = uw_muse_can_sync();
	if ( ! $can ) {
		return rest_ensure_response(
			array(
				'dry_run'      => true,
				'synced'       => false,
				'sync_allowed' => false,
				'note'         => 'Muse sync is off for this key. Turn on "Let Muse pull captions and sync" on the account.',
			)
		);
	}
	// Quick sync costs roughly a handful of list units.
	$quota = uw_muse_quota_check( 20 );
	if ( is_wp_error( $quota ) ) {
		return $quota;
	}
	$result = TW_Index::sync_batch( 'quick' );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	uw_muse_quota_add( 20 );
	uw_muse_log( 'sync', '', array( 'status' => 'ok' ) );
	return rest_ensure_response(
		array(
			'dry_run' => false,
			'synced'  => true,
			'result'  => $result,
		)
	);
}

function uw_muse_rest_media( WP_REST_Request $request ) {
	$files = $request->get_file_params();
	$file  = $files['file'] ?? null;
	if ( ! is_array( $file ) || empty( $file['tmp_name'] ) ) {
		return new WP_Error( 'uw_muse_media', 'Send a multipart file field named file.', array( 'status' => 400 ) );
	}
	$max = 5 * MB_IN_BYTES;
	if ( ! empty( $file['size'] ) && (int) $file['size'] > $max ) {
		return new WP_Error( 'uw_muse_media_size', 'Image must be 5 MB or smaller.', array( 'status' => 400 ) );
	}
	$check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] ?? '' );
	$type  = (string) ( $check['type'] ?? '' );
	if ( ! $type || 0 !== strpos( $type, 'image/' ) ) {
		return new WP_Error( 'uw_muse_media_type', 'Only image uploads are allowed.', array( 'status' => 400 ) );
	}
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
	$att_id = media_handle_upload( 'file', 0 );
	if ( is_wp_error( $att_id ) ) {
		return $att_id;
	}
	uw_muse_log( 'media', '', array( 'status' => 'ok', 'post_id' => (int) $att_id ) );
	return rest_ensure_response(
		array(
			'id'  => (int) $att_id,
			'url' => wp_get_attachment_url( $att_id ),
		)
	);
}

function uw_muse_find_by_external( $external_id, $post_type = 'page' ) {
	$external_id = sanitize_text_field( (string) $external_id );
	if ( '' === $external_id ) {
		return 0;
	}
	$q = new WP_Query(
		array(
			'post_type'      => $post_type,
			'post_status'    => array( 'draft', 'pending', 'private' ),
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_key'       => UW_MUSE_META_EXT,
			'meta_value'     => $external_id,
			'no_found_rows'  => true,
		)
	);
	return ! empty( $q->posts[0] ) ? (int) $q->posts[0] : 0;
}

/**
 * @param array  $item      Payload.
 * @param string $post_type page|post.
 * @return array|WP_Error
 */
function uw_muse_save_content( array $item, $post_type = 'page' ) {
	$post_type   = in_array( $post_type, array( 'page', 'post' ), true ) ? $post_type : 'page';
	$external_id = sanitize_text_field( (string) ( $item['external_id'] ?? '' ) );
	if ( '' === $external_id ) {
		return new WP_Error( 'uw_muse_external_id', 'external_id is required.', array( 'status' => 400 ) );
	}

	$update   = ! empty( $item['update'] ) || ! empty( $item['upsert'] );
	$existing = uw_muse_find_by_external( $external_id, $post_type );
	$title    = isset( $item['title'] ) ? sanitize_text_field( (string) $item['title'] ) : '';
	$content  = isset( $item['content_html'] ) ? (string) $item['content_html'] : (string) ( $item['description_html'] ?? '' );
	$slug     = isset( $item['slug'] ) ? sanitize_title( (string) $item['slug'] ) : '';
	$excerpt  = isset( $item['excerpt'] ) ? sanitize_textarea_field( (string) $item['excerpt'] ) : null;

	if ( $existing && ! $update ) {
		return new WP_Error( 'uw_muse_duplicate', 'Draft exists. Pass update:true to rewrite it.', array( 'status' => 409 ) );
	}
	if ( ! $existing && '' === $title ) {
		return new WP_Error( 'uw_muse_title', 'title is required when creating.', array( 'status' => 400 ) );
	}

	$postarr = array(
		'post_type'   => $post_type,
		'post_status' => 'draft',
	);
	$author = uw_muse_request_user_id();
	if ( $author && $author > 0 ) {
		$postarr['post_author'] = $author;
	}
	if ( '' !== $title ) {
		$postarr['post_title'] = $title;
	}
	if ( array_key_exists( 'content_html', $item ) || array_key_exists( 'description_html', $item ) ) {
		$postarr['post_content'] = wp_kses_post( $content );
	}
	if ( '' !== $slug ) {
		$postarr['post_name'] = $slug;
	}
	if ( null !== $excerpt ) {
		$postarr['post_excerpt'] = $excerpt;
	}

	if ( $existing ) {
		$postarr['ID'] = $existing;
		$result        = wp_update_post( $postarr, true );
	} else {
		$result = wp_insert_post( $postarr, true );
	}
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$post_id = (int) $result;
	update_post_meta( $post_id, UW_MUSE_META_EXT, $external_id );

	if ( 'post' === $post_type ) {
		if ( array_key_exists( 'categories', $item ) ) {
			$cats = is_array( $item['categories'] ) ? $item['categories'] : array( $item['categories'] );
			$ids  = array_values( array_filter( array_map( 'absint', $cats ) ) );
			if ( $ids ) {
				wp_set_post_categories( $post_id, $ids, false );
			}
		}
		if ( array_key_exists( 'tags', $item ) ) {
			$tags = is_array( $item['tags'] ) ? $item['tags'] : preg_split( '/\s*,\s*/', (string) $item['tags'] );
			$tags = array_values( array_filter( array_map( 'sanitize_text_field', (array) $tags ) ) );
			wp_set_post_tags( $post_id, $tags, false );
		}
		if ( array_key_exists( 'featured_media', $item ) ) {
			$fid = absint( $item['featured_media'] );
			if ( $fid && wp_attachment_is_image( $fid ) ) {
				set_post_thumbnail( $post_id, $fid );
			} elseif ( ! $fid ) {
				delete_post_thumbnail( $post_id );
			}
		}
	}

	uw_muse_log( $post_type, '', array( 'status' => 'ok', 'post_id' => $post_id ) );

	return array(
		'id'          => $post_id,
		'external_id' => $external_id,
		'type'        => $post_type,
		'status'      => 'draft',
		'updated'     => (bool) $existing,
		'edit_url'    => get_edit_post_link( $post_id, 'raw' ),
	);
}

function uw_muse_rest_batch_content( WP_REST_Request $request, $post_type ) {
	$body          = $request->get_json_params();
	$body          = is_array( $body ) ? $body : array();
	$items         = isset( $body['items'] ) && is_array( $body['items'] ) ? $body['items'] : array( $body );
	$global_update = ! empty( $body['update'] ) || ! empty( $body['upsert'] );

	$created  = 0;
	$updated  = 0;
	$skipped  = 0;
	$ids      = array();
	$warnings = array();

	foreach ( $items as $i => $item ) {
		if ( ! is_array( $item ) ) {
			$skipped++;
			$warnings[] = "Item {$i}: not an object.";
			continue;
		}
		if ( $global_update ) {
			$item['update'] = true;
		}
		$result = uw_muse_save_content( $item, $post_type );
		if ( is_wp_error( $result ) ) {
			$skipped++;
			$warnings[] = "Item {$i}: " . $result->get_error_message();
			continue;
		}
		$ids[] = $result['id'];
		if ( $result['updated'] ) {
			$updated++;
		} else {
			$created++;
		}
	}

	return rest_ensure_response(
		array(
			'created'   => $created,
			'updated'   => $updated,
			'skipped'   => $skipped,
			'item_ids'  => $ids,
			'warnings'  => $warnings,
			'admin_url' => admin_url( 'edit.php?post_type=' . $post_type . '&post_status=draft' ),
		)
	);
}

function uw_muse_rest_pages( WP_REST_Request $request ) {
	return uw_muse_rest_batch_content( $request, 'page' );
}

function uw_muse_rest_posts( WP_REST_Request $request ) {
	return uw_muse_rest_batch_content( $request, 'post' );
}

add_action( 'init', 'uw_muse_maybe_migrate_keys', 5 );

add_action(
	'delete_user',
	function ( $user_id ) {
		uw_muse_purge_user( (int) $user_id );
	}
);

add_action(
	'admin_menu',
	function () {
		add_submenu_page( 'tubewriter', 'Muse', 'Muse', 'manage_options', 'uploadwriter-muse', 'uw_muse_settings_page' );
	},
	30
);

add_action(
	'admin_init',
	function () {
		if ( empty( $_POST['uw_muse_settings_nonce'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'uw_muse_settings', 'uw_muse_settings_nonce' );

		if ( ! empty( $_POST['uw_muse_clear_key'] ) ) {
			uw_muse_set_site_key( '' );
		} elseif ( ! empty( $_POST['uw_muse_regen_key'] ) ) {
			$new = uw_muse_new_key();
			uw_muse_set_site_key( $new );
			set_transient( 'uw_muse_key_once', $new, 60 );
		} else {
			$typed = isset( $_POST['uw_muse_key'] ) ? trim( wp_unslash( (string) $_POST['uw_muse_key'] ) ) : '';
			if ( '' !== $typed ) {
				if ( strlen( $typed ) < 32 ) {
					wp_safe_redirect( add_query_arg( array( 'page' => 'uploadwriter-muse', 'err' => 'short' ), admin_url( 'admin.php' ) ) );
					exit;
				}
				uw_muse_set_site_key( $typed );
			}
		}

		if ( ! empty( $_POST['uw_muse_allow_push'] ) ) {
			update_option( UW_MUSE_SITE_PUSH, '1', false );
		} else {
			delete_option( UW_MUSE_SITE_PUSH );
		}

		wp_safe_redirect( add_query_arg( array( 'page' => 'uploadwriter-muse', 'updated' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}
);

function uw_muse_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$from_cfg   = defined( 'UW_MUSE_KEY' ) && UW_MUSE_KEY;
	$has_site   = uw_muse_site_has_key();
	$hint       = uw_muse_site_hint();
	$base       = uw_muse_base_url();
	$site_push  = uw_muse_site_push_allowed();
	$once       = get_transient( 'uw_muse_key_once' );
	if ( $once ) {
		delete_transient( 'uw_muse_key_once' );
	}
	$account_url = class_exists( 'TW_Frontend' ) ? TW_Frontend::url( 'account' ) . '#tw-keys' : home_url( '/tubewriter/account/#tw-keys' );
	$with_keys   = get_users(
		array(
			'meta_key'     => UW_MUSE_USER_HASH,
			'meta_compare' => 'EXISTS',
			'fields'       => array( 'ID', 'user_login' ),
			'number'       => 50,
			'count_total'  => false,
		)
	);
	$routes = array(
		array( 'GET', '/muse/schema', 'any', '' ),
		array( 'GET', '/muse/status', 'any', '' ),
		array( 'GET|POST|PATCH', '/muse/settings', 'GET any; PATCH user', '' ),
		array( 'GET', '/muse/youtube/status', 'any', '' ),
		array( 'GET', '/muse/videos', 'any', '' ),
		array( 'GET|POST|PATCH', '/muse/videos/{id}', 'any', '' ),
		array( 'POST', '/muse/videos/{id}/generate', 'any', '' ),
		array( 'POST', '/muse/videos/{id}/push-to-youtube', 'any', 'push' ),
		array( 'GET', '/muse/videos/{id}/history', 'any', '' ),
		array( 'POST', '/muse/videos/{id}/restore', 'user', 'restore' ),
		array( 'GET', '/muse/schedule', 'any', '' ),
		array( 'GET', '/muse/playlists', 'any', '' ),
		array( 'POST', '/muse/sync', 'user', 'sync' ),
		array( 'POST', '/muse/media', 'site', '' ),
		array( 'POST', '/muse/pages', 'site', '' ),
		array( 'POST', '/muse/posts', 'site', '' ),
	);
	?>
	<div class="wrap">
		<h1>Muse</h1>
		<?php if ( ! empty( $_GET['updated'] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p>Saved.</p></div>
		<?php endif; ?>
		<?php if ( ! empty( $_GET['err'] ) && 'short' === $_GET['err'] ) : ?>
			<div class="notice notice-error is-dismissible"><p>Muse keys must be at least 32 characters.</p></div>
		<?php endif; ?>
		<?php if ( $once ) : ?>
			<div class="notice notice-warning"><p>Site Muse key (copy now, shown once): <code><?php echo esc_html( $once ); ?></code></p></div>
		<?php endif; ?>
		<p><strong>Per-user keys</strong> (Account → <a href="<?php echo esc_url( $account_url ); ?>">Keys &amp; voice</a>): that user’s videos only. No promotional posts/pages.</p>
		<p><strong>This site key</strong>: draft posts/pages for marketing, plus the site/admin video index. Pair with Royal MCP (Bearer) for broader WP access. That is a separate key in Royal MCP settings.</p>
		<p>Accounts with a Muse key: <strong><?php echo esc_html( (string) count( $with_keys ) ); ?></strong>
			<?php if ( $with_keys ) : ?>
				(<?php echo esc_html( implode( ', ', wp_list_pluck( $with_keys, 'user_login' ) ) ); ?>)
			<?php endif; ?>
		</p>
		<form method="post">
			<?php wp_nonce_field( 'uw_muse_settings', 'uw_muse_settings_nonce' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Site Muse key</th>
					<td>
						<p class="description">
							Status: <strong><?php echo $has_site ? 'yes' : 'no'; ?></strong>
							<?php if ( $hint ) : ?>
								<code><?php echo esc_html( $hint ); ?></code>
							<?php endif; ?>
							<?php if ( $from_cfg ) : ?>
								(set via <code>UW_MUSE_KEY</code> in wp-config; maps to site/admin storage).
							<?php else : ?>
								Stored as a hash only. Generate shows the full key once.
							<?php endif; ?>
						</p>
						<?php if ( ! $from_cfg ) : ?>
							<p>
								<input type="password" class="regular-text code" name="uw_muse_key" value="" autocomplete="new-password" placeholder="<?php echo $has_site ? esc_attr( $hint ? $hint : 'Saved. Leave blank to keep it.' ) : ''; ?>">
							</p>
							<p>
								<button type="submit" class="button" name="uw_muse_regen_key" value="1">Generate / rotate site key</button>
								<?php if ( $has_site ) : ?>
									<label style="margin-left:1em"><input type="checkbox" name="uw_muse_clear_key" value="1"> Clear site Muse key</label>
								<?php endif; ?>
							</p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row">Site push</th>
					<td>
						<label>
							<input type="checkbox" name="uw_muse_allow_push" value="1" <?php checked( $site_push ); ?>>
							Allow Muse to push to YouTube (site key)
						</label>
						<p class="description">Off by default. When off, push returns a dry run only.</p>
					</td>
				</tr>
			</table>
			<?php submit_button( 'Save' ); ?>
		</form>
		<h2>Endpoints</h2>
		<table class="widefat striped" style="max-width:56rem">
			<thead>
				<tr>
					<th>Method</th>
					<th>Path</th>
					<th>Scope</th>
					<th>Toggle</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $routes as $r ) : ?>
					<tr>
						<td><code><?php echo esc_html( $r[0] ); ?></code></td>
						<td><code><?php echo esc_html( $base . $r[1] ); ?></code></td>
						<td><?php echo esc_html( $r[2] ); ?></td>
						<td><?php echo esc_html( $r[3] ? $r[3] : 'none' ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description">Header: <code><?php echo esc_html( UW_MUSE_HEADER ); ?>: {key}</code>. Royal MCP is separate (Bearer). Rate limit <?php echo (int) UW_MUSE_RATE_MAX; ?>/min per key.</p>
	</div>
	<?php
}
