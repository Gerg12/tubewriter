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

const UW_MUSE_OPTION_KEY = 'uw_muse_key';
const UW_MUSE_USER_META  = 'tw_muse_key';
const UW_MUSE_HEADER     = 'x-uw-muse-key';
const UW_MUSE_META_EXT   = '_uw_muse_external_id';

/** @var int|null Resolved account for this request. 0 = site/admin storage. */
$GLOBALS['uw_muse_user_id'] = null;

function uw_muse_base_url() {
	return home_url( '/wp-json/uw/v1' );
}

function uw_muse_user_key( $user_id ) {
	$user_id = (int) $user_id;
	if ( $user_id <= 0 ) {
		return '';
	}
	return (string) get_user_meta( $user_id, UW_MUSE_USER_META, true );
}

function uw_muse_user_has_key( $user_id ) {
	return '' !== uw_muse_user_key( $user_id );
}

function uw_muse_set_user_key( $user_id, $key ) {
	$user_id = (int) $user_id;
	$key     = trim( (string) $key );
	if ( $user_id <= 0 ) {
		return false;
	}
	if ( '' === $key ) {
		delete_user_meta( $user_id, UW_MUSE_USER_META );
		return true;
	}
	update_user_meta( $user_id, UW_MUSE_USER_META, $key );
	return true;
}

function uw_muse_new_key() {
	return wp_generate_password( 48, false, false );
}

/**
 * Find which account owns this Muse key.
 *
 * @return int|null User id, 0 for legacy site key, null if invalid.
 */
function uw_muse_resolve_user_id( $sent ) {
	$sent = is_string( $sent ) ? trim( $sent ) : '';
	if ( '' === $sent ) {
		return null;
	}

	$users = get_users(
		array(
			'meta_key'     => UW_MUSE_USER_META,
			'meta_compare' => 'EXISTS',
			'fields'       => array( 'ID' ),
			'number'       => 200,
			'count_total'  => false,
		)
	);
	foreach ( $users as $user ) {
		$stored = uw_muse_user_key( $user->ID );
		if ( '' !== $stored && hash_equals( $stored, $sent ) ) {
			return (int) $user->ID;
		}
	}

	if ( defined( 'UW_MUSE_KEY' ) && UW_MUSE_KEY && hash_equals( (string) UW_MUSE_KEY, $sent ) ) {
		return 0;
	}
	$site = (string) get_option( UW_MUSE_OPTION_KEY, '' );
	if ( '' !== $site && hash_equals( $site, $sent ) ) {
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
		$have_user_keys = (bool) get_users(
			array(
				'meta_key'     => UW_MUSE_USER_META,
				'meta_compare' => 'EXISTS',
				'number'       => 1,
				'fields'       => array( 'ID' ),
				'count_total'  => false,
			)
		);
		$have_any = $have_user_keys || ( defined( 'UW_MUSE_KEY' ) && UW_MUSE_KEY ) || '' !== (string) get_option( UW_MUSE_OPTION_KEY, '' );
		if ( ! $have_any ) {
			return new WP_Error( 'uw_muse_no_key', 'Muse key is not configured. Generate one on Account → Keys & voice.', array( 'status' => 503 ) );
		}
		return new WP_Error( 'uw_muse_unauthorized', 'Missing or invalid Muse key.', array( 'status' => 401 ) );
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
	return rest_ensure_response(
		array(
			'auth_header' => UW_MUSE_HEADER,
			'base'        => $base,
			'limits'      => array(
				'title_max'       => class_exists( 'TW_Validator' ) ? TW_Validator::TITLE_MAX : 100,
				'tags_hard_max'   => class_exists( 'TW_Validator' ) ? TW_Validator::TAGS_HARD_MAX : 8,
				'hashtags_max'    => class_exists( 'TW_Validator' ) ? TW_Validator::HASH_MAX : 3,
				'description_max' => class_exists( 'TW_Validator' ) ? TW_Validator::DESC_MAX : 5000,
				'videos_per_page' => 20,
			),
			'video_tw_statuses' => array( '', 'started', 'draft', 'pushed' ),
			'scope'             => uw_muse_is_site_scope() ? 'site' : 'user',
			'endpoints'         => array(
				'schema'          => array( 'GET', $base . '/muse/schema', 'any' ),
				'status'          => array( 'GET', $base . '/muse/status', 'any' ),
				'settings'        => array( 'GET|POST|PATCH', $base . '/muse/settings', 'any' ),
				'youtube_status'  => array( 'GET', $base . '/muse/youtube/status', 'any' ),
				'videos'          => array( 'GET', $base . '/muse/videos', 'any' ),
				'video'           => array( 'GET|POST|PATCH', $base . '/muse/videos/{id}', 'any' ),
				'generate'        => array( 'POST', $base . '/muse/videos/{id}/generate', 'any' ),
				'push_to_youtube' => array( 'POST', $base . '/muse/videos/{id}/push-to-youtube', 'any' ),
				'pages'           => array( 'POST', $base . '/muse/pages', 'site' ),
				'posts'           => array( 'POST', $base . '/muse/posts', 'site' ),
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
				'pages_posts'  => array( 'external_id', 'title', 'slug', 'content_html', 'update' ),
				'settings'     => class_exists( 'TW_Settings' ) ? TW_Settings::IDENTITY_KEYS : array(),
			),
			'notes'             => array(
				'Always send ' . UW_MUSE_HEADER . ' and Content-Type: application/json.',
				'User scope (Account → Keys & voice): that user’s videos, channel identity, generate, and push only. No posts/pages.',
				'Site scope (UploadWriter → Muse legacy key): promotional draft posts/pages, plus the site/admin video index.',
				'Royal MCP is separate (Authorization: Bearer). Use it for broader WP work with the site Muse connection; it is not granted by a per-user Muse key.',
				'Videos are YouTube ids from that account’s local index. Muse does not create YouTube uploads.',
				'POST /muse/pages and /muse/posts are site-key only, drafts only.',
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
	return rest_ensure_response(
		array(
			'app'               => defined( 'TW_BRAND' ) ? TW_BRAND : 'UploadWriter',
			'version'           => defined( 'TW_VERSION' ) ? TW_VERSION : '',
			'account'           => array(
				'user_id'  => (int) $user_id,
				'login'    => $user ? $user->user_login : ( 0 === (int) $user_id ? 'site' : '' ),
				'scope'    => ( $user_id && $user_id > 0 ) ? 'user' : 'site',
			),
			'gemini_configured' => class_exists( 'TW_Store' ) && TW_Store::has( 'gemini_key' ),
			'openai_configured' => class_exists( 'TW_Store' ) && TW_Store::has( 'openai_key' ),
			'youtube_connected' => class_exists( 'TW_OAuth' ) && TW_OAuth::is_connected(),
			'identity_complete' => class_exists( 'TW_Settings' ) && TW_Settings::identity_complete(),
			'channel'           => array(
				'id'    => is_array( $channel ) ? (string) ( $channel['id'] ?? '' ) : '',
				'title' => is_array( $channel ) ? (string) ( $channel['title'] ?? '' ) : '',
			),
			'video_counts'      => array(
				'indexed'   => (int) ( $counts['all'] ?? 0 ),
				'public'    => (int) ( $counts['public'] ?? 0 ),
				'draft_yt'  => (int) ( $counts['draft'] ?? 0 ),
				'scheduled' => (int) ( $counts['scheduled'] ?? 0 ),
				'tw'        => $tw,
			),
		)
	);
}

function uw_muse_rest_settings_get() {
	if ( ! class_exists( 'TW_Settings' ) ) {
		return new WP_Error( 'uw_muse_app', 'UploadWriter is not loaded.', array( 'status' => 503 ) );
	}
	return rest_ensure_response(
		array(
			'identity'          => TW_Settings::identity(),
			'identity_complete' => TW_Settings::identity_complete(),
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
			'notes'             => array(
				'Prompt/voice text is not returned here. Identity fields drive generation.',
				'No API keys, OAuth tokens, or Muse key in this response.',
			),
		)
	);
}

function uw_muse_rest_settings_patch( WP_REST_Request $request ) {
	if ( ! class_exists( 'TW_Settings' ) ) {
		return new WP_Error( 'uw_muse_app', 'UploadWriter is not loaded.', array( 'status' => 503 ) );
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
		)
	);
}

function uw_muse_rest_youtube_status() {
	$channel = class_exists( 'TW_Store' ) ? TW_Store::get( 'channel', array() ) : array();
	$channel = is_array( $channel ) ? $channel : array();
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

	if ( $dry_run || ! $confirm ) {
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
				'note'        => $confirm ? '' : 'Pass confirm_push:true and fingerprint from this dry_run to push. dry_run:true never calls YouTube write.',
			)
		);
	}

	$fingerprint = (string) ( $body['fingerprint'] ?? '' );
	if ( '' === $fingerprint ) {
		return new WP_Error( 'uw_muse_fingerprint', 'fingerprint from dry_run is required for a real push.', array( 'status' => 400 ) );
	}

	$result = TW_Plan::publish( $video_id, $pkg, $opts, $duration, $fingerprint );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

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
			'dry_run'         => false,
			'pushed'          => true,
			'id'              => $video_id,
			'youtube_url'     => 'https://www.youtube.com/watch?v=' . $video_id,
			'result'          => $result,
			'history'         => TW_History::summary( $video_id ),
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

		// Legacy site-scoped key only (admin index / user_id 0). Prefer per-user keys on Account.
		if ( ! empty( $_POST['uw_muse_clear_key'] ) ) {
			delete_option( UW_MUSE_OPTION_KEY );
		} elseif ( ! empty( $_POST['uw_muse_regen_key'] ) ) {
			$new = uw_muse_new_key();
			update_option( UW_MUSE_OPTION_KEY, $new, false );
			set_transient( 'uw_muse_key_once', $new, 60 );
		}

		wp_safe_redirect( add_query_arg( array( 'page' => 'uploadwriter-muse', 'updated' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}
);

function uw_muse_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$from_cfg = defined( 'UW_MUSE_KEY' ) && UW_MUSE_KEY;
	$site_key = (string) get_option( UW_MUSE_OPTION_KEY, '' );
	$has_site = $from_cfg || '' !== $site_key;
	$base     = uw_muse_base_url();
	$once     = get_transient( 'uw_muse_key_once' );
	if ( $once ) {
		delete_transient( 'uw_muse_key_once' );
	}
	$account_url = class_exists( 'TW_Frontend' ) ? TW_Frontend::url( 'account' ) . '#tw-keys' : home_url( '/tubewriter/account/#tw-keys' );
	$with_keys   = get_users(
		array(
			'meta_key'     => UW_MUSE_USER_META,
			'meta_compare' => 'EXISTS',
			'fields'       => array( 'ID', 'user_login' ),
			'number'       => 50,
			'count_total'  => false,
		)
	);
	?>
	<div class="wrap">
		<h1>Muse</h1>
		<?php if ( ! empty( $_GET['updated'] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p>Saved.</p></div>
		<?php endif; ?>
		<?php if ( $once ) : ?>
			<div class="notice notice-warning"><p>Legacy site Muse key (copy now, shown once): <code><?php echo esc_html( $once ); ?></code></p></div>
		<?php endif; ?>
		<p><strong>Per-user keys</strong> (Account → <a href="<?php echo esc_url( $account_url ); ?>">Keys &amp; voice</a>): that user’s videos only. No promotional posts/pages.</p>
		<p><strong>This site key</strong>: draft posts/pages for marketing, plus the site/admin video index. Pair with Royal MCP (Bearer) for broader WP access — that is a separate key in Royal MCP settings.</p>
		<p>Accounts with a Muse key: <strong><?php echo esc_html( (string) count( $with_keys ) ); ?></strong>
			<?php if ( $with_keys ) : ?>
				(<?php echo esc_html( implode( ', ', wp_list_pluck( $with_keys, 'user_login' ) ) ); ?>)
			<?php endif; ?>
		</p>
		<form method="post">
			<?php wp_nonce_field( 'uw_muse_settings', 'uw_muse_settings_nonce' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Legacy site key</th>
					<td>
						<p class="description">
							Status: <strong><?php echo $has_site ? 'yes' : 'no'; ?></strong>
							<?php if ( $from_cfg ) : ?>
								(set via <code>UW_MUSE_KEY</code> in wp-config; maps to site/admin storage).
							<?php else : ?>
								Optional fallback for the site/admin TubeWriter index (user_id 0). Prefer a per-user key instead.
							<?php endif; ?>
						</p>
						<?php if ( ! $from_cfg ) : ?>
							<p>
								<button type="submit" class="button" name="uw_muse_regen_key" value="1">Generate / rotate legacy site key</button>
								<?php if ( $has_site ) : ?>
									<label style="margin-left:1em"><input type="checkbox" name="uw_muse_clear_key" value="1"> Clear legacy site key</label>
								<?php endif; ?>
							</p>
						<?php endif; ?>
					</td>
				</tr>
			</table>
			<?php submit_button( 'Save' ); ?>
		</form>
		<h2>Endpoints</h2>
		<ul>
			<li><code><?php echo esc_html( $base ); ?>/muse/schema</code></li>
			<li><code><?php echo esc_html( $base ); ?>/muse/status</code></li>
			<li><code><?php echo esc_html( $base ); ?>/muse/settings</code></li>
			<li><code><?php echo esc_html( $base ); ?>/muse/videos</code></li>
			<li><code><?php echo esc_html( $base ); ?>/muse/videos/{id}</code></li>
			<li><code><?php echo esc_html( $base ); ?>/muse/videos/{id}/generate</code></li>
			<li><code><?php echo esc_html( $base ); ?>/muse/videos/{id}/push-to-youtube</code></li>
			<li><code><?php echo esc_html( $base ); ?>/muse/pages</code> — page drafts</li>
			<li><code><?php echo esc_html( $base ); ?>/muse/posts</code> — post drafts (news)</li>
		</ul>
		<p class="description">Header: <code><?php echo esc_html( UW_MUSE_HEADER ); ?>: {key}</code>. Royal MCP is separate (Bearer).</p>
	</div>
	<?php
}
