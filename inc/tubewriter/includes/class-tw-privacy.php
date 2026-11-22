<?php
/**
 * Customer data isolation: keep UploadWriter users out of each other's media
 * and out of shared wp-admin screens.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TW_Privacy {

	/** Meta keys that mark private app-created media. */
	const APP_META_KEYS = array( '_tw_thumb_ref', '_tw_thumb_lib', '_tw_game_logo' );

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'redirect_non_admins' ) );
		add_filter( 'ajax_query_attachments_args', array( __CLASS__, 'own_attachments_only' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'restrict_attachment_queries' ) );
		add_filter( 'rest_attachment_query', array( __CLASS__, 'rest_own_attachments' ), 10, 2 );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'rest_block_app_media_item' ), 10, 3 );
		add_action( 'init', array( __CLASS__, 'block_app_attachment_id_param' ), 0 );
		add_action( 'wp', array( __CLASS__, 'block_app_attachment_pages' ), 0 );
		add_action( 'template_redirect', array( __CLASS__, 'block_app_attachment_pages' ), 0 );
		add_filter( 'redirect_canonical', array( __CLASS__, 'block_app_attachment_canonical' ), 1, 2 );
		add_filter( 'wp_sitemaps_posts_query_args', array( __CLASS__, 'sitemap_exclude_app_media' ), 10, 2 );
		add_filter( 'wpseo_exclude_from_sitemap_by_post_ids', array( __CLASS__, 'yoast_exclude_app_media' ) );
		add_filter( 'wpseo_sitemap_exclude_post_type', array( __CLASS__, 'yoast_maybe_exclude_attachment_type' ), 10, 2 );
	}

	/** True when the current user is a site admin. */
	public static function is_admin_user() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Non-admins who hit wp-admin go to their UploadWriter account.
	 * admin-ajax.php and admin-post.php stay available for the front app.
	 */
	public static function redirect_non_admins() {
		if ( self::is_admin_user() || ! is_user_logged_in() ) {
			return;
		}
		if ( wp_doing_ajax() ) {
			return;
		}
		global $pagenow;
		$pagenow = (string) $pagenow;
		if ( in_array( $pagenow, array( 'admin-ajax.php', 'admin-post.php' ), true ) ) {
			return;
		}
		// Allow profile edits that WordPress itself needs after password reset, etc.
		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			return;
		}
		if ( ! class_exists( 'TW_Frontend' ) ) {
			return;
		}
		wp_safe_redirect( TW_Frontend::url( 'account' ) );
		exit;
	}

	/**
	 * Media Library modal: non-admins only see their own uploads.
	 *
	 * @param array $args Query args.
	 * @return array
	 */
	public static function own_attachments_only( $args ) {
		if ( self::is_admin_user() ) {
			return $args;
		}
		$uid = get_current_user_id();
		if ( $uid ) {
			$args['author'] = $uid;
		}
		return $args;
	}

	/**
	 * Main query / list table attachment queries for non-admins.
	 *
	 * @param WP_Query $query Query.
	 */
	public static function restrict_attachment_queries( $query ) {
		if ( self::is_admin_user() || ! $query instanceof WP_Query ) {
			return;
		}
		if ( is_admin() && ! wp_doing_ajax() ) {
			// Non-admins are redirected away from wp-admin screens anyway.
		}
		$post_type = $query->get( 'post_type' );
		$is_att    = ( 'attachment' === $post_type )
			|| ( is_array( $post_type ) && in_array( 'attachment', $post_type, true ) );
		if ( ! $is_att && ! $query->is_attachment() ) {
			return;
		}
		$uid = get_current_user_id();
		if ( $uid ) {
			$query->set( 'author', $uid );
		}
	}

	/**
	 * REST media collection: logged-in non-admins only see their own.
	 * Logged-out callers keep WordPress defaults (usually public media only).
	 *
	 * @param array           $args    Query args.
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	public static function rest_own_attachments( $args, $request ) {
		unset( $request );
		if ( self::is_admin_user() ) {
			return $args;
		}
		$uid = get_current_user_id();
		if ( $uid ) {
			$args['author'] = $uid;
		}
		// Hide app-private media from everyone who is not the owner or an admin.
		$args['meta_query'] = self::merge_meta_query(
			isset( $args['meta_query'] ) ? $args['meta_query'] : array(),
			self::exclude_app_media_meta_query( $uid )
		);
		return $args;
	}

	/**
	 * Block single media REST reads of app-private files for non-owners.
	 *
	 * @param mixed           $result  Early result.
	 * @param WP_REST_Server  $server  Server.
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public static function rest_block_app_media_item( $result, $server, $request ) {
		unset( $server );
		if ( self::is_admin_user() ) {
			return $result;
		}
		$route = (string) $request->get_route();
		if ( ! preg_match( '#^/wp/v2/media/(\d+)#', $route, $m ) ) {
			return $result;
		}
		$id = (int) $m[1];
		if ( ! $id || ! self::is_app_media( $id ) ) {
			return $result;
		}
		$uid = get_current_user_id();
		if ( $uid && (int) get_post_field( 'post_author', $id ) === $uid ) {
			return $result;
		}
		return new WP_Error( 'rest_forbidden', 'Not allowed.', array( 'status' => 404 ) );
	}

	/**
	 * Catch ?attachment_id=N before WordPress redirects it to the file URL.
	 */
	public static function block_app_attachment_id_param() {
		if ( empty( $_GET['attachment_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$id = absint( $_GET['attachment_id'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $id && self::should_hide_app_media( $id ) ) {
			self::send_attachment_404();
		}
	}

	/**
	 * Stop WordPress from redirecting a private app attachment to its file URL.
	 *
	 * @param string|false $redirect_url Canonical URL.
	 * @param string       $requested    Requested URL.
	 * @return string|false
	 */
	public static function block_app_attachment_canonical( $redirect_url, $requested ) {
		unset( $requested );
		$id = self::requested_attachment_id();
		if ( $id && self::should_hide_app_media( $id ) ) {
			return false;
		}
		return $redirect_url;
	}

	/** Emit a plain 404 for a blocked attachment page. */
	private static function send_attachment_404() {
		status_header( 404 );
		nocache_headers();
		global $wp_query;
		if ( $wp_query instanceof WP_Query ) {
			$wp_query->set_404();
		}
		$template = get_404_template();
		if ( $template ) {
			include $template;
		} else {
			echo 'Not found.';
		}
		exit;
	}

	/** Attachment pages for app media return 404 except for owner/admin. */
	public static function block_app_attachment_pages() {
		$id = self::requested_attachment_id();
		if ( ! $id || ! self::should_hide_app_media( $id ) ) {
			return;
		}
		self::send_attachment_404();
	}

	/**
	 * @return int Attachment id from the main query or attachment_id param.
	 */
	private static function requested_attachment_id() {
		if ( is_attachment() ) {
			return (int) get_queried_object_id();
		}
		$qid = get_query_var( 'attachment_id' );
		if ( $qid ) {
			return absint( $qid );
		}
		if ( ! empty( $_GET['attachment_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return absint( $_GET['attachment_id'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		return 0;
	}

	/**
	 * @param int $attachment_id Attachment id.
	 * @return bool True when this visitor must not see the attachment page.
	 */
	private static function should_hide_app_media( $attachment_id ) {
		if ( ! self::is_app_media( $attachment_id ) ) {
			return false;
		}
		if ( self::is_admin_user() ) {
			return false;
		}
		$uid = get_current_user_id();
		if ( $uid && (int) get_post_field( 'post_author', $attachment_id ) === $uid ) {
			return false;
		}
		return true;
	}

	/**
	 * Core sitemaps: skip app media.
	 *
	 * @param array  $args      Query args.
	 * @param string $post_type Post type.
	 * @return array
	 */
	public static function sitemap_exclude_app_media( $args, $post_type ) {
		if ( 'attachment' !== $post_type ) {
			return $args;
		}
		$args['meta_query'] = self::merge_meta_query(
			isset( $args['meta_query'] ) ? $args['meta_query'] : array(),
			self::exclude_app_media_meta_query( 0 )
		);
		return $args;
	}

	/**
	 * Yoast: exclude known app attachment ids from the sitemap.
	 *
	 * @param int[] $excluded Already excluded.
	 * @return int[]
	 */
	public static function yoast_exclude_app_media( $excluded ) {
		$excluded = is_array( $excluded ) ? $excluded : array();
		$ids      = self::app_media_ids( 500 );
		return array_values( array_unique( array_merge( $excluded, $ids ) ) );
	}

	/**
	 * Prefer excluding attachments entirely from Yoast when the site has no
	 * public need for them. Keep the type, filter by id instead (safer).
	 *
	 * @param bool   $exclude   Whether to exclude.
	 * @param string $post_type Post type.
	 * @return bool
	 */
	public static function yoast_maybe_exclude_attachment_type( $exclude, $post_type ) {
		unset( $post_type );
		return $exclude;
	}

	/**
	 * @param int $attachment_id Attachment id.
	 * @return bool
	 */
	public static function is_app_media( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		if ( ! $attachment_id ) {
			return false;
		}
		if ( '1' === (string) get_post_meta( $attachment_id, '_tw_thumb_ref', true ) ) {
			return true;
		}
		if ( '' !== (string) get_post_meta( $attachment_id, '_tw_thumb_lib', true ) ) {
			return true;
		}
		if ( '1' === (string) get_post_meta( $attachment_id, '_tw_game_logo', true ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Whether the current user may use this attachment.
	 *
	 * @param int $attachment_id Attachment id.
	 * @return bool
	 */
	public static function user_owns_attachment( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		if ( ! $attachment_id ) {
			return false;
		}
		if ( self::is_admin_user() ) {
			return true;
		}
		$uid = get_current_user_id();
		if ( ! $uid ) {
			return false;
		}
		return (int) get_post_field( 'post_author', $attachment_id ) === $uid;
	}

	/**
	 * Meta query that excludes app-private media for everyone except the owner.
	 * When $owner_id is 0, exclude all app media (public/sitemap).
	 *
	 * @param int $owner_id Current user id, or 0 to hide all app media.
	 * @return array
	 */
	private static function exclude_app_media_meta_query( $owner_id ) {
		$owner_id = (int) $owner_id;
		// For owners we still want their own app media in REST. Only strip others.
		// Easiest reliable approach for public/logged-out: exclude by meta existence.
		if ( $owner_id > 0 ) {
			// Logged-in non-admin already has author=uid, so their own app media
			// is fine. Extra exclude not required for them.
			return array();
		}
		return array(
			'relation' => 'AND',
			array(
				'relation' => 'AND',
				array(
					'key'     => '_tw_thumb_ref',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => '_tw_thumb_lib',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => '_tw_game_logo',
					'compare' => 'NOT EXISTS',
				),
			),
		);
	}

	/**
	 * @param array $existing Existing meta_query.
	 * @param array $extra    Extra clause.
	 * @return array
	 */
	private static function merge_meta_query( $existing, $extra ) {
		if ( empty( $extra ) ) {
			return $existing;
		}
		if ( empty( $existing ) ) {
			return $extra;
		}
		return array(
			'relation' => 'AND',
			$existing,
			$extra,
		);
	}

	/**
	 * @param int $limit Max ids.
	 * @return int[]
	 */
	private static function app_media_ids( $limit = 500 ) {
		$ids = get_posts(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'posts_per_page'         => $limit,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => array(
					'relation' => 'OR',
					array(
						'key'   => '_tw_thumb_ref',
						'value' => '1',
					),
					array(
						'key'     => '_tw_thumb_lib',
						'compare' => 'EXISTS',
					),
					array(
						'key'   => '_tw_game_logo',
						'value' => '1',
					),
				),
			)
		);
		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}
}
