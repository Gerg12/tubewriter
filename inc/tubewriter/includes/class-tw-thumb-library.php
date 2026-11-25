<?php
/**
 * Persistent library of AI-generated thumbnails.
 *
 * Free / UploadWriter users keep a small set (a few videos worth).
 * Site admins are unlimited.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TW_Thumb_Library {

	/** Meta flag on Media Library attachments. */
	const META = '_tw_thumb_lib';

	/** Video id the thumb was generated for. */
	const META_VIDEO = '_tw_thumb_lib_video';

	/**
	 * Free-tier cap: roughly a few videos × a couple of generations each.
	 * Admins bypass via limit_for_user().
	 */
	const FREE_LIMIT = 12;

	/**
	 * Max library slots for a user. Admins: unlimited.
	 *
	 * @param int|null $user_id
	 * @return int
	 */
	public static function limit_for_user( $user_id = null ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		if ( ! $user_id ) {
			return 0;
		}
		if ( user_can( $user_id, 'manage_options' ) ) {
			return PHP_INT_MAX;
		}
		/**
		 * Filter the generated-thumbnail library size for non-admins.
		 *
		 * @param int $limit   Default FREE_LIMIT.
		 * @param int $user_id
		 */
		return (int) apply_filters( 'tw_thumb_library_limit', self::FREE_LIMIT, $user_id );
	}

	/**
	 * @param int|null $user_id
	 * @return bool
	 */
	public static function is_unlimited( $user_id = null ) {
		return self::limit_for_user( $user_id ) >= PHP_INT_MAX;
	}

	/**
	 * @param int|null $user_id
	 * @return int
	 */
	public static function count_for_user( $user_id = null ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		if ( ! $user_id ) {
			return 0;
		}
		$query = new WP_Query(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'author'                 => $user_id,
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => array(
					array(
						'key'   => self::META,
						'value' => 'generated',
					),
				),
			)
		);
		return (int) $query->found_posts;
	}

	/**
	 * Usage payload for the editor UI.
	 *
	 * @param int|null $user_id
	 * @return array{used: int, limit: int|null, unlimited: bool, remaining: int|null}
	 */
	public static function usage( $user_id = null ) {
		$user_id    = $user_id ? (int) $user_id : get_current_user_id();
		$used       = self::count_for_user( $user_id );
		$unlimited  = self::is_unlimited( $user_id );
		$limit      = $unlimited ? null : self::limit_for_user( $user_id );
		$remaining  = $unlimited ? null : max( 0, (int) $limit - $used );
		return array(
			'used'      => $used,
			'limit'     => $limit,
			'unlimited' => $unlimited ? 1 : 0,
			'remaining' => $remaining,
		);
	}

	/**
	 * Absolute paths that belong to library attachments.
	 *
	 * @return array<string,true>
	 */
	public static function library_files() {
		$keep  = array();
		$query = new WP_Query(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'posts_per_page'         => 500,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => array(
					array(
						'key'   => self::META,
						'value' => 'generated',
					),
				),
			)
		);
		foreach ( $query->posts as $id ) {
			$file = wp_normalize_path( (string) get_attached_file( (int) $id ) );
			if ( '' !== $file ) {
				$keep[ $file ] = true;
			}
		}
		return $keep;
	}

	/**
	 * @param string $file Absolute path.
	 * @return bool
	 */
	public static function file_is_library( $file ) {
		$id = self::attachment_id_for_file( $file );
		if ( ! $id ) {
			return false;
		}
		return 'generated' === (string) get_post_meta( $id, self::META, true );
	}

	/**
	 * Absolute path → attachment id when that file is already attached.
	 *
	 * @param string $file Absolute path.
	 * @return int
	 */
	public static function attachment_id_for_file( $file ) {
		$file = wp_normalize_path( (string) $file );
		if ( '' === $file ) {
			return 0;
		}
		$uploads = wp_get_upload_dir();
		$basedir = wp_normalize_path( trailingslashit( (string) ( $uploads['basedir'] ?? '' ) ) );
		if ( ! $basedir || 0 !== strpos( $file, $basedir ) ) {
			return 0;
		}
		$rel = ltrim( substr( $file, strlen( $basedir ) ), '/' );
		if ( '' === $rel ) {
			return 0;
		}
		global $wpdb;
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1",
				$rel
			)
		);
	}

	/**
	 * WordPress resized / scaled sibling filename (e.g. foo-300x169.png, foo-scaled.jpg).
	 * These must never become their own Media Library attachments.
	 *
	 * @param string $file Absolute path or basename.
	 * @return bool
	 */
	public static function is_wp_resized_filename( $file ) {
		$name = basename( (string) $file );
		if ( '' === $name ) {
			return false;
		}
		return (bool) preg_match( '/-(?:\d+x\d+|scaled)\.(jpe?g|png|gif|webp)$/i', $name );
	}

	/**
	 * Keep model output as one file: no intermediate sizes, no -scaled rewrite.
	 *
	 * @param array $sizes
	 * @return array
	 */
	public static function skip_intermediate_sizes( $sizes ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return array();
	}

	/**
	 * @return false
	 */
	public static function disable_big_image_threshold() {
		return false;
	}

	/**
	 * Register a generated file as a Media Library attachment in the user's library.
	 *
	 * @param string $file     Absolute path already written.
	 * @param string $url      Public URL.
	 * @param string $video_id YouTube video id.
	 * @param string $mime     image/png|jpeg|webp.
	 * @return array{id: int, url: string, file: string}|WP_Error
	 */
	public static function register_generated( $file, $url, $video_id, $mime ) {
		$file = wp_normalize_path( (string) $file );
		if ( '' === $file || ! is_readable( $file ) ) {
			return new WP_Error( 'tw_thumb_lib', 'Generated thumbnail file is missing.' );
		}
		if ( self::is_wp_resized_filename( $file ) ) {
			return new WP_Error( 'tw_thumb_lib', 'Refusing to register a WordPress resized copy as a thumbnail.' );
		}

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return new WP_Error( 'tw_thumb_lib', 'You must be logged in to keep thumbnails in your library.' );
		}

		$existing = self::attachment_id_for_file( $file );
		if ( $existing ) {
			if ( 'generated' !== (string) get_post_meta( $existing, self::META, true ) ) {
				update_post_meta( $existing, self::META, 'generated' );
			}
			$safe_video = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $video_id );
			if ( '' !== $safe_video && '' === (string) get_post_meta( $existing, self::META_VIDEO, true ) ) {
				update_post_meta( $existing, self::META_VIDEO, $safe_video );
			}
			self::strip_attachment_sizes( $existing );
			$public = (string) wp_get_attachment_url( $existing );
			return array(
				'id'   => $existing,
				'url'  => '' !== $public ? $public : (string) $url,
				'file' => $file,
			);
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$mime = (string) $mime;
		if ( '' === $mime ) {
			$check = wp_check_filetype( basename( $file ), null );
			$mime  = (string) ( $check['type'] ?? 'image/png' );
		}

		$mtime = @filemtime( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$title = 'UploadWriter thumbnail';
		if ( $mtime ) {
			$title .= ' ' . wp_date( 'Y-m-d g:i a', $mtime );
		} else {
			$title .= ' ' . wp_date( 'Y-m-d g:i a' );
		}

		$attachment = array(
			'post_mime_type' => $mime,
			'post_title'     => $title,
			'post_content'   => '',
			'post_status'    => 'inherit',
			'post_author'    => $user_id,
		);

		$attach_id = wp_insert_attachment( $attachment, $file, 0, true );
		if ( is_wp_error( $attach_id ) ) {
			return $attach_id;
		}

		// One original only — never spawn -150x150 / -medium / -scaled siblings.
		add_filter( 'intermediate_image_sizes_advanced', array( __CLASS__, 'skip_intermediate_sizes' ), 999 );
		add_filter( 'big_image_size_threshold', array( __CLASS__, 'disable_big_image_threshold' ), 999 );
		$meta = wp_generate_attachment_metadata( $attach_id, $file );
		remove_filter( 'intermediate_image_sizes_advanced', array( __CLASS__, 'skip_intermediate_sizes' ), 999 );
		remove_filter( 'big_image_size_threshold', array( __CLASS__, 'disable_big_image_threshold' ), 999 );

		if ( is_array( $meta ) ) {
			unset( $meta['sizes'] );
			wp_update_attachment_metadata( $attach_id, $meta );
		}

		update_post_meta( $attach_id, self::META, 'generated' );
		$safe_video = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $video_id );
		if ( '' !== $safe_video ) {
			update_post_meta( $attach_id, self::META_VIDEO, $safe_video );
		}

		self::enforce_limit( $user_id, array( (int) $attach_id ) );

		$public = (string) wp_get_attachment_url( $attach_id );
		return array(
			'id'   => (int) $attach_id,
			'url'  => '' !== $public ? $public : (string) $url,
			'file' => $file,
		);
	}

	/**
	 * Drop intermediate size metadata and delete leftover -WxH / -scaled files on disk.
	 *
	 * @param int $attach_id
	 */
	public static function strip_attachment_sizes( $attach_id ) {
		$attach_id = (int) $attach_id;
		if ( ! $attach_id ) {
			return;
		}
		$file = wp_normalize_path( (string) get_attached_file( $attach_id ) );
		$meta = wp_get_attachment_metadata( $attach_id );
		if ( ! is_array( $meta ) ) {
			$meta = array();
		}

		$sizes = isset( $meta['sizes'] ) && is_array( $meta['sizes'] ) ? $meta['sizes'] : array();
		$dir   = $file ? trailingslashit( dirname( $file ) ) : '';
		foreach ( $sizes as $size ) {
			$name = isset( $size['file'] ) ? (string) $size['file'] : '';
			if ( '' === $name || ! $dir ) {
				continue;
			}
			$path = $dir . $name;
			if ( is_file( $path ) && self::is_wp_resized_filename( $path ) ) {
				wp_delete_file( $path );
			}
		}

		if ( $file && is_file( $file ) ) {
			$base = pathinfo( $file, PATHINFO_FILENAME );
			$ext  = pathinfo( $file, PATHINFO_EXTENSION );
			if ( $base && $ext ) {
				$siblings = glob( $dir . $base . '-*.' . $ext );
				if ( is_array( $siblings ) ) {
					foreach ( $siblings as $sibling ) {
						$sibling = wp_normalize_path( $sibling );
						if ( self::is_wp_resized_filename( $sibling ) && is_file( $sibling ) ) {
							wp_delete_file( $sibling );
						}
					}
				}
			}
		}

		if ( isset( $meta['sizes'] ) ) {
			unset( $meta['sizes'] );
			wp_update_attachment_metadata( $attach_id, $meta );
		}
	}

	/**
	 * Delete library attachments that are actually WordPress resized copies
	 * (registered by an older backfill that globbed -300x169 siblings).
	 *
	 * @param int|null $user_id Limit to one author when set; null = all generated.
	 * @return int How many impostor attachments were removed.
	 */
	public static function purge_resized_impostors( $user_id = null ) {
		$user_id = null === $user_id ? 0 : (int) $user_id;
		$args    = array(
			'post_type'              => 'attachment',
			'post_status'            => 'inherit',
			'posts_per_page'         => 500,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'meta_query'             => array(
				array(
					'key'   => self::META,
					'value' => 'generated',
				),
			),
		);
		if ( $user_id > 0 ) {
			$args['author'] = $user_id;
		}

		$query   = new WP_Query( $args );
		$removed = 0;
		foreach ( $query->posts as $id ) {
			$id   = (int) $id;
			$file = (string) get_attached_file( $id );
			if ( self::is_wp_resized_filename( $file ) ) {
				wp_delete_attachment( $id, true );
				++$removed;
				continue;
			}
			self::strip_attachment_sizes( $id );
		}
		return $removed;
	}

	/**
	 * Pull older loose files from uploads/tubewriter/generated into the Media Library
	 * so "Choose finished thumbnail" can see generations made before the library existed.
	 *
	 * @param int|null $user_id
	 * @return int How many attachments were created or tagged.
	 */
	public static function backfill_loose_generated( $user_id = null ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		if ( ! $user_id ) {
			return 0;
		}

		// Kill -300x169 / -scaled impostors that an older backfill registered as library items.
		self::purge_resized_impostors( $user_id );

		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return 0;
		}
		$dir = trailingslashit( $uploads['basedir'] ) . 'tubewriter/generated';
		if ( ! is_dir( $dir ) ) {
			return 0;
		}

		$candidates = array();

		// Drafts owned by this user (front app) or site drafts when acting as admin in wp-admin.
		$states = self::user_draft_states( $user_id );
		foreach ( $states as $video_id => $state ) {
			$file = wp_normalize_path( (string) ( $state['thumb_file'] ?? '' ) );
			if ( '' === $file || ! is_readable( $file ) || self::is_wp_resized_filename( $file ) ) {
				continue;
			}
			if ( false === strpos( $file, '/tubewriter/generated/' ) && false === strpos( $file, '\\tubewriter\\generated\\' ) ) {
				continue;
			}
			$candidates[ $file ] = $video_id;
		}

		// Also import loose files still on disk (older gens before Media Library registration).
		// Skip WordPress -WxH / -scaled siblings — they are not separate thumbnails.
		$files = glob( $dir . '/tw-thumb-*.*' );
		if ( is_array( $files ) ) {
			$my_videos = array_fill_keys( array_keys( $states ), true );
			$is_admin  = user_can( $user_id, 'manage_options' );
			foreach ( $files as $file ) {
				$file = wp_normalize_path( $file );
				if ( ! is_file( $file ) || isset( $candidates[ $file ] ) || self::is_wp_resized_filename( $file ) ) {
					continue;
				}
				$video = '';
				if ( preg_match( '#/tw-thumb-([A-Za-z0-9_-]+)-\d{8}-\d{6}\.#', $file, $m ) ) {
					$video = $m[1];
				}
				// Admins: everything. Others: only files for videos they have a draft for.
				if ( ! $is_admin && ( '' === $video || ! isset( $my_videos[ $video ] ) ) ) {
					continue;
				}
				$candidates[ $file ] = $video;
			}
		}

		$added = 0;
		foreach ( $candidates as $file => $video_id ) {
			$existing = self::attachment_id_for_file( $file );
			if ( $existing ) {
				if ( 'generated' !== (string) get_post_meta( $existing, self::META, true ) ) {
					update_post_meta( $existing, self::META, 'generated' );
					++$added;
				}
				self::sync_state_attachment( $user_id, (string) $video_id, $existing, $file );
				continue;
			}

			$ext  = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
			$mime = 'image/png';
			if ( 'jpg' === $ext || 'jpeg' === $ext ) {
				$mime = 'image/jpeg';
			} elseif ( 'webp' === $ext ) {
				$mime = 'image/webp';
			}
			$url = '';
			$rel = ltrim( str_replace( wp_normalize_path( trailingslashit( $uploads['basedir'] ) ), '', $file ), '/' );
			if ( $rel ) {
				$url = trailingslashit( $uploads['baseurl'] ) . str_replace( '\\', '/', $rel );
			}

			$registered = self::register_generated( $file, $url, (string) $video_id, $mime );
			if ( is_wp_error( $registered ) ) {
				continue;
			}
			self::sync_state_attachment( $user_id, (string) $video_id, (int) $registered['id'], $file );
			++$added;
		}

		return $added;
	}

	/**
	 * Draft states for a user: usermeta always; site options when they are an admin.
	 *
	 * @param int $user_id
	 * @return array<string,array>
	 */
	private static function user_draft_states( $user_id ) {
		global $wpdb;
		$user_id = (int) $user_id;
		$out     = array();

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT meta_key, meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key LIKE %s",
				$user_id,
				$wpdb->esc_like( 'tw_state_' ) . '%'
			),
			ARRAY_A
		);
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$key = (string) ( $row['meta_key'] ?? '' );
				if ( ! preg_match( '/^tw_state_(.+)$/', $key, $m ) ) {
					continue;
				}
				$state = maybe_unserialize( $row['meta_value'] ?? '' );
				if ( is_array( $state ) ) {
					$out[ $m[1] ] = $state;
				}
			}
		}

		if ( user_can( $user_id, 'manage_options' ) ) {
			$opts = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'tw_state\\_%'",
				ARRAY_A
			);
			if ( is_array( $opts ) ) {
				foreach ( $opts as $row ) {
					$key = (string) ( $row['option_name'] ?? '' );
					if ( ! preg_match( '/^tw_state_(.+)$/', $key, $m ) ) {
						continue;
					}
					$state = maybe_unserialize( $row['option_value'] ?? '' );
					if ( is_array( $state ) && ! isset( $out[ $m[1] ] ) ) {
						$out[ $m[1] ] = $state;
					}
				}
			}
		}

		return $out;
	}

	/**
	 * Point a draft at the Media Library attachment when it was only a loose file before.
	 *
	 * @param int    $user_id
	 * @param string $video_id
	 * @param int    $att_id
	 * @param string $file
	 */
	private static function sync_state_attachment( $user_id, $video_id, $att_id, $file ) {
		$video_id = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $video_id );
		$att_id   = (int) $att_id;
		if ( '' === $video_id || ! $att_id ) {
			return;
		}
		$key = 'tw_state_' . $video_id;
		if ( user_can( $user_id, 'manage_options' ) && ! TW_Context::is_user() ) {
			$state = get_option( $key, array() );
			if ( ! is_array( $state ) ) {
				return;
			}
			$stored = wp_normalize_path( (string) ( $state['thumb_file'] ?? '' ) );
			if ( $stored && $stored !== wp_normalize_path( $file ) ) {
				return;
			}
			if ( absint( $state['thumb_attachment_id'] ?? 0 ) === $att_id ) {
				return;
			}
			$state['thumb_attachment_id'] = $att_id;
			$state['thumb_file']          = wp_normalize_path( $file );
			$url = (string) wp_get_attachment_url( $att_id );
			if ( $url ) {
				$state['thumb_url'] = $url;
			}
			update_option( $key, $state, false );
			return;
		}

		$state = get_user_meta( $user_id, $key, true );
		if ( ! is_array( $state ) ) {
			return;
		}
		$stored = wp_normalize_path( (string) ( $state['thumb_file'] ?? '' ) );
		if ( $stored && $stored !== wp_normalize_path( $file ) ) {
			return;
		}
		if ( absint( $state['thumb_attachment_id'] ?? 0 ) === $att_id ) {
			return;
		}
		$state['thumb_attachment_id'] = $att_id;
		$state['thumb_file']          = wp_normalize_path( $file );
		$url = (string) wp_get_attachment_url( $att_id );
		if ( $url ) {
			$state['thumb_url'] = $url;
		}
		update_user_meta( $user_id, $key, $state );
	}

	/**
	 * Drop oldest library items when over the free-tier cap.
	 * Never deletes $protect_ids (e.g. the thumb just created / currently staged).
	 *
	 * @param int   $user_id
	 * @param int[] $protect_ids
	 */
	public static function enforce_limit( $user_id, array $protect_ids = array() ) {
		$user_id = (int) $user_id;
		$limit   = self::limit_for_user( $user_id );
		if ( $limit >= PHP_INT_MAX || $limit < 1 ) {
			return;
		}

		$protect = array();
		foreach ( $protect_ids as $id ) {
			$id = (int) $id;
			if ( $id ) {
				$protect[ $id ] = true;
			}
		}

		$ids = self::ids_for_user( $user_id, 'ASC' );
		$over = count( $ids ) - $limit;
		if ( $over <= 0 ) {
			return;
		}

		foreach ( $ids as $id ) {
			if ( $over <= 0 ) {
				break;
			}
			if ( isset( $protect[ $id ] ) ) {
				continue;
			}
			wp_delete_attachment( $id, true );
			--$over;
		}
	}

	/**
	 * @param int    $user_id
	 * @param string $order ASC = oldest first.
	 * @param int    $per_page
	 * @return int[]
	 */
	public static function ids_for_user( $user_id, $order = 'DESC', $per_page = 100 ) {
		$user_id = (int) $user_id;
		if ( ! $user_id ) {
			return array();
		}
		$query = new WP_Query(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'author'                 => $user_id,
				'posts_per_page'         => max( 1, (int) $per_page ),
				'orderby'                => 'date',
				'order'                  => ( 'ASC' === strtoupper( $order ) ) ? 'ASC' : 'DESC',
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => array(
					array(
						'key'   => self::META,
						'value' => 'generated',
					),
				),
			)
		);
		$ids = array();
		foreach ( $query->posts as $id ) {
			$ids[] = (int) $id;
		}
		return $ids;
	}

	/**
	 * @param int $id Attachment id.
	 * @return array{id: int, url: string, thumb: string, edit: string, title: string, video: string, created: string}|null
	 */
	public static function payload( $id ) {
		$id = (int) $id;
		if ( ! $id || 'attachment' !== get_post_type( $id ) ) {
			return null;
		}
		if ( 'generated' !== (string) get_post_meta( $id, self::META, true ) ) {
			return null;
		}
		$full = (string) wp_get_attachment_image_url( $id, 'full' );
		$url  = '' !== $full ? $full : (string) wp_get_attachment_url( $id );
		return array(
			'id'      => $id,
			'url'     => $url,
			'thumb'   => $url,
			'edit'    => (string) get_edit_post_link( $id, 'raw' ),
			'title'   => get_the_title( $id ),
			'video'   => (string) get_post_meta( $id, self::META_VIDEO, true ),
			'created' => get_the_date( 'Y-m-d H:i', $id ),
		);
	}

	/**
	 * @param int|null $user_id
	 * @return array{items: array, usage: array, imported: int}
	 */
	public static function list_for_user( $user_id = null ) {
		$user_id  = $user_id ? (int) $user_id : get_current_user_id();
		$imported = self::backfill_loose_generated( $user_id );
		$items    = array();
		foreach ( self::ids_for_user( $user_id, 'DESC', 48 ) as $id ) {
			$row = self::payload( $id );
			if ( $row ) {
				$items[] = $row;
			}
		}
		return array(
			'items'    => $items,
			'usage'    => self::usage( $user_id ),
			'imported' => $imported,
		);
	}

	/**
	 * Delete one library item owned by the user.
	 *
	 * @param int $id
	 * @param int $user_id
	 * @return true|WP_Error
	 */
	public static function delete_owned( $id, $user_id ) {
		$id      = (int) $id;
		$user_id = (int) $user_id;
		if ( ! $id || 'attachment' !== get_post_type( $id ) ) {
			return new WP_Error( 'tw_thumb_lib', 'That thumbnail was not found.' );
		}
		if ( 'generated' !== (string) get_post_meta( $id, self::META, true ) ) {
			return new WP_Error( 'tw_thumb_lib', 'That file is not in your generated thumbnail library.' );
		}
		$is_admin = user_can( $user_id, 'manage_options' );
		$author   = (int) get_post_field( 'post_author', $id );
		if ( ! $is_admin && $author !== $user_id ) {
			return new WP_Error( 'tw_thumb_lib', 'Not allowed.', array( 'status' => 403 ) );
		}
		$deleted = wp_delete_attachment( $id, true );
		if ( ! $deleted ) {
			return new WP_Error( 'tw_thumb_lib', 'Could not delete that thumbnail.' );
		}
		return true;
	}
}
