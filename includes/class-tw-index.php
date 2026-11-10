<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Local index of the channel's uploads in a custom table, so the Videos page can
 * search, filter, sort and paginate without calling YouTube.
 *
 * Sync cost: 2 API units per 50 videos (playlistItems.list + videos.list).
 * - Quick sync: newest 100 uploads (about 4 units), then a short excluded-video check.
 * - Full sync: every upload, run in batches from the browser, removes local rows
 *   for videos that no longer exist on YouTube. It never deletes anything on YouTube.
 * - Scheduled videos and Studio drafts are often not in the uploads playlist. When
 *   a sync finishes, search.list (forMine) finds private videos missing from that
 *   playlist. Future publishAt becomes Scheduled. Other private misses become Draft
 *   (YouTube's API still reports them as private). Same run id, before local cleanup.
 */
class TW_Index {

	const DB_VERSION   = '2';
	const BATCH_PAGES  = 4;  // 4 x 50 = 200 videos per AJAX request.
	const QUICK_PAGES  = 2;

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'tw_videos';
	}

	public static function maybe_install() {
		if ( get_option( 'tw_db_version' ) === self::DB_VERSION ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
				id varchar(32) NOT NULL,
				title text NOT NULL,
				description longtext NOT NULL,
				privacy varchar(20) NOT NULL DEFAULT '',
				publish_at datetime NULL DEFAULT NULL,
				published_at datetime NULL DEFAULT NULL,
				views bigint(20) unsigned NOT NULL DEFAULT 0,
				duration int(10) unsigned NOT NULL DEFAULT 0,
				thumb varchar(255) NOT NULL DEFAULT '',
				tw_status varchar(20) NOT NULL DEFAULT '',
				captions_status varchar(10) NOT NULL DEFAULT '',
				sync_run varchar(16) NOT NULL DEFAULT '',
				synced_at int(10) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY privacy (privacy),
				KEY published_at (published_at),
				KEY views (views)
			) {$charset};"
		);
		update_option( 'tw_db_version', self::DB_VERSION, false );
	}

	/**
	 * Insert or update a row from a YouTube video resource (snippet, status, statistics, contentDetails).
	 *
	 * @param array $opts {
	 *   @type bool $from_uploads True when the row came from the uploads playlist.
	 *   @type bool $as_draft     Store private unscheduled videos as Studio drafts.
	 * }
	 */
	public static function upsert( array $v, $run = '', array $opts = array() ) {
		global $wpdb;
		$sn       = $v['snippet'] ?? array();
		$st       = $v['status'] ?? array();
		$privacy  = (string) ( $st['privacyStatus'] ?? '' );
		$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT id, privacy, sync_run FROM ' . self::table() . ' WHERE id = %s', $v['id'] ), ARRAY_A ); // phpcs:ignore

		// API has no draft status. Studio drafts come back as private. Treat every
		// private video without a future go-live date as a draft, including ones
		// that still appear in the uploads playlist. Scheduled stays private+publishAt.
		if ( ( 'private' === $privacy || ! empty( $opts['as_draft'] ) ) && ! TW_YouTube::is_scheduled( $v ) ) {
			if ( 'private' === $privacy || 'private' === ( $st['privacyStatus'] ?? '' ) ) {
				$privacy = 'draft';
			}
		}

		// Private/scheduled thumbs are signed i9.ytimg URLs that expire. Download
		// while the API URL is fresh so the Videos list never hotlinks YouTube.
		$remote_thumb = self::remote_thumb_from_snippet( $sn );
		$thumb        = self::cache_thumbnail( $v['id'], $remote_thumb, ! self::has_local_thumb( $v['id'] ) );
		$row          = array(
			'id'           => $v['id'],
			'title'        => (string) ( $sn['title'] ?? '' ),
			'description'  => (string) ( $sn['description'] ?? '' ),
			'privacy'      => $privacy,
			'publish_at'   => ! empty( $st['publishAt'] ) ? gmdate( 'Y-m-d H:i:s', strtotime( $st['publishAt'] ) ) : null,
			'published_at' => ! empty( $sn['publishedAt'] ) ? gmdate( 'Y-m-d H:i:s', strtotime( $sn['publishedAt'] ) ) : null,
			'duration'     => TW_Transcript::duration_seconds( $v['contentDetails']['duration'] ?? '' ),
			'thumb'        => $thumb,
			'synced_at'    => time(),
		);
		if ( isset( $v['statistics']['viewCount'] ) ) {
			$row['views'] = (int) $v['statistics']['viewCount'];
		}
		if ( $run ) {
			$row['sync_run'] = $run;
		}
		if ( $existing ) {
			$wpdb->update( self::table(), $row, array( 'id' => $v['id'] ) );
		} else {
			$row['tw_status']        = self::tw_status_from_state( $v['id'] );
			$row['captions_status'] = self::captions_status_from_state( $v['id'] );
			$wpdb->insert( self::table(), $row );
		}
	}

	/** Refresh one row after TubeWriter changed the video (1 unit). */
	public static function refresh_one( $video_id ) {
		$video = TW_YouTube::get_video( $video_id );
		if ( ! is_wp_error( $video ) ) {
			self::upsert( $video );
		}
	}

	public static function set_tw_status( $video_id, $status ) {
		global $wpdb;
		$wpdb->update( self::table(), array( 'tw_status' => $status ), array( 'id' => $video_id ) );
	}

	/** ok = captions text saved, none = pull tried and came back empty. */
	public static function set_captions_status( $video_id, $status ) {
		global $wpdb;
		$status = in_array( $status, array( 'ok', 'none' ), true ) ? $status : '';
		$wpdb->update( self::table(), array( 'captions_status' => $status ), array( 'id' => $video_id ) );
	}

	private static function tw_status_from_state( $video_id ) {
		$state = get_option( 'tw_state_' . $video_id );
		if ( ! is_array( $state ) ) {
			return '';
		}
		if ( ! empty( $state['pushed'] ) ) {
			return 'pushed';
		}
		return ! empty( $state['pkg'] ) ? 'draft' : 'started';
	}

	private static function captions_status_from_state( $video_id ) {
		$state = get_option( 'tw_state_' . $video_id );
		if ( ! is_array( $state ) ) {
			return '';
		}
		$status = (string) ( $state['captions_status'] ?? '' );
		return in_array( $status, array( 'ok', 'none' ), true ) ? $status : '';
	}

	/**
	 * Sync one batch. Pass mode 'quick', 'full', or 'drafts'.
	 * - quick/full: walk uploads, then find scheduled + drafts via search.
	 * - drafts: compare only. Promote local private-unscheduled rows to draft, then
	 *   search for draft candidates and add ones the index does not already have.
	 *
	 * @return array{done: bool, token: string, run: string, synced: int, total: int}|WP_Error
	 */
	public static function sync_batch( $mode, $token = '', $run = '' ) {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore
		}
		if ( 'drafts' === $mode ) {
			return self::reconcile_drafts();
		}

		$run    = $run ? preg_replace( '/[^A-Za-z0-9]/', '', $run ) : wp_generate_password( 8, false );
		$pages  = 'quick' === $mode ? self::QUICK_PAGES : self::BATCH_PAGES;
		$synced = 0;
		$total  = 0;

		for ( $i = 0; $i < $pages; $i++ ) {
			$page = TW_YouTube::uploads_page( $token );
			if ( is_wp_error( $page ) ) {
				return $page;
			}
			foreach ( $page['videos'] as $v ) {
				self::upsert( $v, $run, array( 'from_uploads' => true ) );
				$synced++;
			}
			$total = $page['total'];
			$token = $page['next'];
			if ( ! $token ) {
				break;
			}
		}

		$done      = ( 'quick' === $mode ) || ! $token;
		$scheduled = 0;
		$drafts    = 0;
		$note      = '';
		if ( $done ) {
			// Same run id, and before the local delete, or a full sync would drop them.
			$search_pages = 'quick' === $mode ? 3 : 11;
			$found        = TW_YouTube::playlist_excluded_videos( $search_pages );
			foreach ( $found['scheduled'] as $video ) {
				self::upsert( $video, $run );
				$scheduled++;
			}
			foreach ( $found['drafts'] as $video ) {
				self::upsert( $video, $run, array( 'as_draft' => true ) );
				$drafts++;
			}
			$refreshed = self::refresh_private_schedules( $run );
			if ( $refreshed['scheduled'] > $scheduled ) {
				$scheduled = $refreshed['scheduled'];
			}
			if ( $found['error'] ) {
				$note = 'Draft/scheduled search failed: ' . $found['error'];
			} elseif ( 'full' === $mode && ! $found['complete'] ) {
				$note = 'Draft/scheduled search read about ' . ( $search_pages * 50 ) . ' newest uploads. If something is still missing, use Compare drafts.';
			}
			if ( $refreshed['error'] ) {
				$note = trim( $note . ' Refreshing private videos failed: ' . $refreshed['error'] );
			}
			update_option( 'tw_scheduled_sync_note', $note, false );
			if ( 'full' === $mode ) {
				global $wpdb;
				// Local cleanup only. An unfinished excluded-video check must not drop
				// draft or scheduled rows the scan did not reach.
				$sql = 'DELETE FROM ' . self::table() . ' WHERE sync_run <> %s';
				if ( ! $found['complete'] ) {
					$sql .= " AND privacy <> 'draft'";
					$sql .= " AND NOT (privacy = 'private' AND publish_at IS NOT NULL AND publish_at > UTC_TIMESTAMP())";
				}
				$wpdb->query( $wpdb->prepare( $sql, $run ) ); // phpcs:ignore
				if ( $found['complete'] ) {
					update_option( 'tw_index_full', time(), false );
				}
			}
			update_option( 'tw_index_synced', time(), false );
			delete_transient( 'tw_calendar' );
		}

		return array(
			'done'            => $done,
			'token'           => $done ? '' : $token,
			'run'             => $run,
			'synced'          => $synced,
			'total'           => $total,
			'scheduled'       => $scheduled,
			'drafts'          => $drafts,
			'scheduled_note'  => $note,
			'scheduled_count' => self::count_scheduled(),
			'draft_count'     => self::count_drafts(),
		);
	}

	/**
	 * Compare draft candidates from search to the local index and only add missing ones.
	 * Also promotes already-indexed private (unscheduled) rows to draft, since the API
	 * cannot tell Studio Draft from Private and those usually belong under Draft.
	 *
	 * About 12 to 24 search units in Google's search bucket, plus 1 videos.list per 50.
	 *
	 * @return array|WP_Error
	 */
	public static function reconcile_drafts() {
		$had      = self::count_drafts();
		$promoted = self::promote_private_to_draft();

		// Deep search: up to 12 pages (600 newest owned videos).
		$found     = TW_YouTube::playlist_excluded_videos( 12 );
		$checked   = count( $found['drafts'] ) + count( $found['scheduled'] );
		$already   = 0;
		$added     = 0;
		$scheduled = 0;

		foreach ( $found['scheduled'] as $video ) {
			self::upsert( $video );
			$scheduled++;
		}
		foreach ( $found['drafts'] as $video ) {
			$id = (string) $video['id'];
			if ( self::has_draft( $id ) ) {
				$already++;
				continue;
			}
			self::upsert( $video, '', array( 'as_draft' => true ) );
			$added++;
		}

		$note = '';
		if ( $found['error'] ) {
			$note = 'Draft compare failed: ' . $found['error'];
		} elseif ( ! $found['complete'] ) {
			$note = 'Compare read about 600 newest owned videos. If Studio still shows more drafts, run it again later or use Full sync.';
		}
		update_option( 'tw_scheduled_sync_note', $note, false );
		update_option( 'tw_index_synced', time(), false );
		delete_transient( 'tw_calendar' );

		$draft_count = self::count_drafts();
		return array(
			'done'              => true,
			'token'             => '',
			'run'               => '',
			'synced'            => $added,
			'total'             => $checked,
			'scheduled'         => $scheduled,
			'drafts'            => $added,
			'scheduled_note'    => $note,
			'scheduled_count'   => self::count_scheduled(),
			'draft_count'       => $draft_count,
			'draft_had'         => $had,
			'draft_promoted'    => $promoted,
			'draft_candidates'  => count( $found['drafts'] ),
			'draft_already'     => $already,
			'draft_added'       => $added,
			'draft_complete'    => $found['complete'],
			'compare'           => true,
		);
	}

	/** Move private-unscheduled index rows to draft (0 API units). */
	public static function promote_private_to_draft() {
		global $wpdb;
		$t = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query(
			"UPDATE {$t} SET privacy = 'draft'
			WHERE privacy = 'private'
			AND (publish_at IS NULL OR publish_at <= UTC_TIMESTAMP())"
		);
	}

	public static function has_draft( $video_id ) {
		global $wpdb;
		$privacy = $wpdb->get_var( $wpdb->prepare( 'SELECT privacy FROM ' . self::table() . ' WHERE id = %s', $video_id ) ); // phpcs:ignore
		return 'draft' === $privacy;
	}

	/** Re-fetch publishAt for indexed private and draft videos. */
	private static function refresh_private_schedules( $run ) {
		global $wpdb;
		$ids = $wpdb->get_col( "SELECT id FROM " . self::table() . " WHERE privacy IN ('private','draft')" ); // phpcs:ignore
		$err = '';
		if ( $ids ) {
			$videos = TW_YouTube::videos_by_ids( $ids );
			if ( is_wp_error( $videos ) ) {
				$err = $videos->get_error_message();
			} else {
				foreach ( $videos as $video ) {
					self::upsert( $video, $run );
				}
			}
		}
		return array(
			'scheduled' => self::count_scheduled(),
			'error'     => $err,
		);
	}

	public static function count_scheduled() {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . self::table() . " WHERE privacy = 'private' AND publish_at IS NOT NULL AND publish_at > UTC_TIMESTAMP()" ); // phpcs:ignore
	}

	public static function count_drafts() {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . self::table() . " WHERE privacy = 'draft'" ); // phpcs:ignore
	}

	public static function count_all() {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() ); // phpcs:ignore
	}

	/** Indexed visibility for a video id, or empty string. */
	public static function privacy_of( $video_id ) {
		global $wpdb;
		$privacy = $wpdb->get_var( $wpdb->prepare( 'SELECT privacy FROM ' . self::table() . ' WHERE id = %s', $video_id ) ); // phpcs:ignore
		return is_string( $privacy ) ? $privacy : '';
	}

	/** Counts for the visibility tabs. */
	public static function counts() {
		global $wpdb;
		$t   = self::table();
		$out = array(
			'all'       => self::count_all(),
			'public'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE privacy = 'public'" ), // phpcs:ignore
			'unlisted'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE privacy = 'unlisted'" ), // phpcs:ignore
			'private'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE privacy = 'private' AND (publish_at IS NULL OR publish_at <= UTC_TIMESTAMP())" ), // phpcs:ignore
			'scheduled' => self::count_scheduled(),
			'draft'     => self::count_drafts(),
		);
		return $out;
	}

	/**
	 * @param array $q search, title, visibility, views_min, views_max, tw, orderby, order, per_page, paged
	 * @return array{items: array, total: int}
	 */
	public static function query( array $q ) {
		global $wpdb;
		$t     = self::table();
		$where = array( '1=1' );
		$args  = array();

		if ( '' !== ( $q['search'] ?? '' ) ) {
			$like    = '%' . $wpdb->esc_like( $q['search'] ) . '%';
			$where[] = '(title LIKE %s OR description LIKE %s OR id = %s)';
			array_push( $args, $like, $like, $q['search'] );
		}
		if ( '' !== ( $q['title'] ?? '' ) ) {
			$where[] = 'title LIKE %s';
			$args[]  = '%' . $wpdb->esc_like( $q['title'] ) . '%';
		}
		switch ( $q['visibility'] ?? '' ) {
			case 'public':
			case 'unlisted':
			case 'draft':
				$where[] = 'privacy = %s';
				$args[]  = $q['visibility'];
				break;
			case 'private':
				$where[] = "privacy = 'private' AND (publish_at IS NULL OR publish_at <= UTC_TIMESTAMP())";
				break;
			case 'scheduled':
				$where[] = "privacy = 'private' AND publish_at IS NOT NULL AND publish_at > UTC_TIMESTAMP()";
				break;
		}
		if ( '' !== ( $q['views_min'] ?? '' ) ) {
			$where[] = 'views >= %d';
			$args[]  = (int) $q['views_min'];
		}
		if ( '' !== ( $q['views_max'] ?? '' ) ) {
			$where[] = 'views <= %d';
			$args[]  = (int) $q['views_max'];
		}
		switch ( $q['tw'] ?? '' ) {
			case 'none':
				$where[] = "tw_status = ''";
				break;
			case 'draft':
				$where[] = "tw_status IN ('started','draft')";
				break;
			case 'pushed':
				$where[] = "tw_status = 'pushed'";
				break;
		}

		$sortable = array( 'date' => 'COALESCE(publish_at, published_at)', 'title' => 'title', 'views' => 'views', 'length' => 'duration' );
		$orderby  = $sortable[ $q['orderby'] ?? 'date' ] ?? $sortable['date'];
		$order    = 'asc' === strtolower( $q['order'] ?? '' ) ? 'ASC' : 'DESC';
		$per      = max( 10, min( 200, (int) ( $q['per_page'] ?? 50 ) ) );
		$paged    = max( 1, (int) ( $q['paged'] ?? 1 ) );

		$sql_where = implode( ' AND ', $where );
		$count_sql = "SELECT COUNT(*) FROM {$t} WHERE {$sql_where}";
		$total     = (int) $wpdb->get_var( $args ? $wpdb->prepare( $count_sql, $args ) : $count_sql ); // phpcs:ignore

		$list_sql = "SELECT id, title, LEFT(description, 220) AS excerpt, privacy, publish_at, published_at, views, duration, thumb, tw_status, captions_status
			FROM {$t} WHERE {$sql_where} ORDER BY {$orderby} {$order}, id ASC LIMIT %d OFFSET %d";
		$items    = $wpdb->get_results( $wpdb->prepare( $list_sql, array_merge( $args, array( $per, ( $paged - 1 ) * $per ) ) ), ARRAY_A ); // phpcs:ignore
		$items    = self::ensure_local_thumbs( is_array( $items ) ? $items : array() );

		return array( 'items' => $items, 'total' => $total, 'per_page' => $per );
	}

	/** Best thumbnail URL from a YouTube snippet. */
	public static function remote_thumb_from_snippet( array $sn ) {
		$thumbs = $sn['thumbnails'] ?? array();
		foreach ( array( 'medium', 'high', 'default', 'standard', 'maxres' ) as $key ) {
			if ( ! empty( $thumbs[ $key ]['url'] ) ) {
				return (string) $thumbs[ $key ]['url'];
			}
		}
		return '';
	}

	/** True when the stored thumb still points at YouTube's CDN. */
	public static function thumb_is_remote( $url ) {
		$url = (string) $url;
		if ( '' === $url ) {
			return false;
		}
		return (bool) preg_match( '#https?://([^/]+\.)?(ytimg\.com|youtube\.com|ggpht\.com)/#i', $url );
	}

	/**
	 * Local path/URL for a cached YouTube thumb under uploads/tubewriter/thumbs/.
	 *
	 * @return array{dir:string,file:string,url:string}|null
	 */
	public static function local_thumb_paths( $video_id ) {
		$safe = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $video_id );
		if ( ! $safe ) {
			return null;
		}
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return null;
		}
		$dir    = trailingslashit( $uploads['basedir'] ) . 'tubewriter/thumbs';
		$scheme = wp_parse_url( home_url(), PHP_URL_SCHEME );
		$url    = trailingslashit( $uploads['baseurl'] ) . 'tubewriter/thumbs/' . $safe . '.jpg';
		return array(
			'dir'  => $dir,
			'file' => $dir . '/' . $safe . '.jpg',
			'url'  => set_url_scheme( $url, $scheme ? $scheme : 'https' ),
		);
	}

	/** Whether a usable local thumb file already exists. */
	public static function has_local_thumb( $video_id ) {
		$paths = self::local_thumb_paths( $video_id );
		return $paths && file_exists( $paths['file'] ) && filesize( $paths['file'] ) > 200;
	}

	/**
	 * Download a YouTube thumbnail and serve it from this site.
	 * Returns the local URL when possible, otherwise the remote URL (or empty).
	 *
	 * @param string $video_id
	 * @param string $remote_url
	 * @param bool   $force Re-download even when a local file already exists.
	 */
	public static function cache_thumbnail( $video_id, $remote_url, $force = false ) {
		$remote_url = esc_url_raw( (string) $remote_url );
		$paths      = self::local_thumb_paths( $video_id );
		if ( ! $paths ) {
			return $remote_url;
		}

		if ( ! $force && self::has_local_thumb( $video_id ) ) {
			return $paths['url'];
		}

		if ( '' === $remote_url ) {
			return self::has_local_thumb( $video_id ) ? $paths['url'] : '';
		}

		if ( ! self::thumb_is_remote( $remote_url ) ) {
			return self::has_local_thumb( $video_id ) ? $paths['url'] : $remote_url;
		}

		if ( ! wp_mkdir_p( $paths['dir'] ) ) {
			return self::has_local_thumb( $video_id ) ? $paths['url'] : $remote_url;
		}

		$body = self::fetch_image_bytes( $remote_url );
		if ( null === $body ) {
			return self::has_local_thumb( $video_id ) ? $paths['url'] : $remote_url;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === file_put_contents( $paths['file'], $body ) ) {
			return $remote_url;
		}
		return $paths['url'];
	}

	/**
	 * @return string|null Raw image bytes, or null on failure.
	 */
	private static function fetch_image_bytes( $url ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 15,
				'redirection' => 3,
				'user-agent'  => 'TubeWriter/' . TW_VERSION . '; ' . home_url( '/' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return null;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		$type = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );
		if ( $code < 200 || $code >= 300 || strlen( $body ) < 200 ) {
			return null;
		}
		if ( $type && false === strpos( $type, 'image' ) ) {
			return null;
		}
		return $body;
	}

	/**
	 * Backfill local thumbs for the current page. Expired private/scheduled URLs
	 * are refreshed with videos.list (1 unit per 50) then downloaded.
	 *
	 * @param array $items
	 * @return array
	 */
	private static function ensure_local_thumbs( array $items ) {
		global $wpdb;
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore
		}

		$need_refresh = array();
		foreach ( $items as &$item ) {
			$id    = (string) ( $item['id'] ?? '' );
			$thumb = (string) ( $item['thumb'] ?? '' );
			if ( ! $id ) {
				continue;
			}
			$paths = self::local_thumb_paths( $id );
			if ( self::has_local_thumb( $id ) ) {
				if ( $paths && $thumb !== $paths['url'] ) {
					$wpdb->update( self::table(), array( 'thumb' => $paths['url'] ), array( 'id' => $id ) );
					$item['thumb'] = $paths['url'];
				}
				continue;
			}
			if ( self::thumb_is_remote( $thumb ) ) {
				$local = self::cache_thumbnail( $id, $thumb );
				if ( self::has_local_thumb( $id ) ) {
					$wpdb->update( self::table(), array( 'thumb' => $local ), array( 'id' => $id ) );
					$item['thumb'] = $local;
					continue;
				}
			}
			$need_refresh[] = $id;
		}
		unset( $item );

		if ( ! $need_refresh ) {
			return $items;
		}

		$videos = TW_YouTube::videos_by_ids( $need_refresh );
		if ( is_wp_error( $videos ) ) {
			return $items;
		}

		$fresh = array();
		foreach ( $videos as $video ) {
			$vid = (string) ( $video['id'] ?? '' );
			$url = self::remote_thumb_from_snippet( $video['snippet'] ?? array() );
			if ( $vid && $url ) {
				$fresh[ $vid ] = $url;
			}
		}

		foreach ( $items as &$item ) {
			$id = (string) ( $item['id'] ?? '' );
			if ( ! $id || self::has_local_thumb( $id ) || empty( $fresh[ $id ] ) ) {
				continue;
			}
			$local = self::cache_thumbnail( $id, $fresh[ $id ], true );
			if ( self::has_local_thumb( $id ) ) {
				$wpdb->update( self::table(), array( 'thumb' => $local ), array( 'id' => $id ) );
				$item['thumb'] = $local;
			} else {
				// Keep the fresh signed URL briefly if download failed this pass.
				$wpdb->update( self::table(), array( 'thumb' => $fresh[ $id ] ), array( 'id' => $id ) );
				$item['thumb'] = $fresh[ $id ];
			}
		}
		unset( $item );

		return $items;
	}

	/** Local cached thumb URL for one video, if the file exists. */
	public static function local_thumb_url( $video_id ) {
		$paths = self::local_thumb_paths( $video_id );
		if ( ! $paths || ! self::has_local_thumb( $video_id ) ) {
			return '';
		}
		return $paths['url'];
	}

	/** Scheduled and published uploads for the editor calendar, from the index (no API calls). */
	public static function calendar_events() {
		global $wpdb;
		$t    = self::table();
		$rows = $wpdb->get_results( // phpcs:ignore
			"SELECT id, title, privacy, publish_at, published_at FROM {$t}
			WHERE (privacy = 'private' AND publish_at IS NOT NULL) OR (privacy IN ('public','unlisted') AND published_at IS NOT NULL)",
			ARRAY_A
		);
		$out = array();
		foreach ( $rows as $r ) {
			$sched = 'private' === $r['privacy'];
			$out[] = array(
				'id'    => $r['id'],
				'title' => $r['title'],
				'type'  => $sched ? 'scheduled' : 'published',
				'at'    => str_replace( ' ', 'T', $sched ? $r['publish_at'] : $r['published_at'] ) . 'Z',
			);
		}
		return $out;
	}
}
