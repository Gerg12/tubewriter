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

	const DB_VERSION   = '3';
	const BATCH_PAGES  = 4;  // 4 x 50 = 200 videos per AJAX request.
	const QUICK_PAGES  = 2;

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'tw_videos';
	}

	/** Active account scope. 0 = site admin index. */
	public static function uid() {
		return TW_Context::user_id();
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
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
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
				PRIMARY KEY  (user_id,id),
				KEY privacy (privacy),
				KEY published_at (published_at),
				KEY views (views)
			) {$charset};"
		);

		// Upgrade from v2 (id-only PK) to per-user composite key.
		$cols = $wpdb->get_col( "DESCRIBE {$table}", 0 ); // phpcs:ignore
		if ( is_array( $cols ) && ! in_array( 'user_id', $cols, true ) ) {
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN user_id bigint(20) unsigned NOT NULL DEFAULT 0 FIRST" ); // phpcs:ignore
			$wpdb->query( "ALTER TABLE {$table} DROP PRIMARY KEY, ADD PRIMARY KEY (user_id, id)" ); // phpcs:ignore
		}

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
		$uid      = self::uid();
		$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT id, privacy, sync_run FROM ' . self::table() . ' WHERE user_id = %d AND id = %s', $uid, $v['id'] ), ARRAY_A ); // phpcs:ignore

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
		// Only force-redownload when asked (or missing). Forcing every sync row
		// re-fetches hundreds of ytimg files and makes Quick sync look stuck.
		$remote_thumb = self::remote_thumb_from_snippet( $sn );
		$force_thumb  = ! empty( $opts['force_thumb'] ) || ! self::has_local_thumb( $v['id'] );
		$thumb        = self::cache_thumbnail( $v['id'], $remote_thumb, $force_thumb );
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
			$wpdb->update( self::table(), $row, array( 'user_id' => $uid, 'id' => $v['id'] ) );
		} else {
			$row['user_id']         = $uid;
			$row['tw_status']       = self::tw_status_from_state( $v['id'] );
			$row['captions_status'] = self::captions_status_from_state( $v['id'] );
			$wpdb->insert( self::table(), $row );
		}
	}

	/**
	 * Refresh one row after TubeWriter changed the video (1 unit).
	 *
	 * @param string $video_id
	 * @param bool   $force_thumb Re-download the list thumb even when a local file exists.
	 */
	public static function refresh_one( $video_id, $force_thumb = true ) {
		$video = TW_YouTube::get_video( $video_id );
		if ( ! is_wp_error( $video ) ) {
			self::upsert( $video, '', array( 'force_thumb' => (bool) $force_thumb ) );
		}
	}

	/**
	 * Force-update the Videos-list thumb cache from a YouTube URL (or a local file).
	 * Prefer a medium/default size for the card. Returns the local URL when possible.
	 *
	 * @param string $video_id
	 * @param string $remote_url Fresh YouTube CDN URL (medium is ideal).
	 * @param string $local_fallback Absolute path to a staged PNG/JPEG if the CDN fetch fails.
	 */
	public static function replace_thumbnail( $video_id, $remote_url, $local_fallback = '' ) {
		global $wpdb;
		$video_id   = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $video_id );
		$remote_url = esc_url_raw( (string) $remote_url );
		if ( ! $video_id ) {
			return '';
		}

		$thumb = '';
		// Prefer the staged file we just uploaded (CDN can lag after thumbnails.set).
		// Always overwrite the list cache so an older AI thumb cannot stick.
		if ( $local_fallback && is_readable( (string) $local_fallback ) ) {
			$thumb = self::cache_thumbnail_from_file( $video_id, $local_fallback );
		}
		if ( ! $thumb && $remote_url ) {
			$thumb = self::cache_thumbnail( $video_id, $remote_url, true );
		}
		if ( ! $thumb ) {
			$thumb = self::local_thumb_url( $video_id );
		}
		if ( $thumb ) {
			$wpdb->update( self::table(), array( 'thumb' => $thumb ), array( 'user_id' => self::uid(), 'id' => $video_id ) );
		}
		return $thumb;
	}

	/**
	 * Write uploads/tubewriter/thumbs/{id}.jpg from a local PNG/JPEG (list-card size).
	 *
	 * @param string $video_id
	 * @param string $source Absolute path.
	 */
	public static function cache_thumbnail_from_file( $video_id, $source ) {
		$paths = self::local_thumb_paths( $video_id );
		$source = (string) $source;
		if ( ! $paths || ! $source || ! file_exists( $source ) ) {
			return '';
		}
		if ( ! wp_mkdir_p( $paths['dir'] ) ) {
			return '';
		}

		$editor = wp_get_image_editor( $source );
		if ( is_wp_error( $editor ) ) {
			return '';
		}
		$size = $editor->get_size();
		$w    = (int) ( $size['width'] ?? 0 );
		$h    = (int) ( $size['height'] ?? 0 );
		if ( $w > 320 ) {
			$editor->resize( 320, 180, true );
		}
		$saved = $editor->save( $paths['file'], 'image/jpeg' );
		if ( is_wp_error( $saved ) || ! self::has_local_thumb( $video_id ) ) {
			return '';
		}
		return $paths['url'];
	}

	public static function set_tw_status( $video_id, $status ) {
		global $wpdb;
		$wpdb->update( self::table(), array( 'tw_status' => $status ), array( 'user_id' => self::uid(), 'id' => $video_id ) );
	}

	/** ok = captions text saved, none = pull tried and came back empty. */
	public static function set_captions_status( $video_id, $status ) {
		global $wpdb;
		$status = in_array( $status, array( 'ok', 'none' ), true ) ? $status : '';
		$wpdb->update( self::table(), array( 'captions_status' => $status ), array( 'user_id' => self::uid(), 'id' => $video_id ) );
	}

	private static function tw_status_from_state( $video_id ) {
		$state = TW_Store::get( 'state_' . $video_id, array() );
		if ( ! is_array( $state ) ) {
			return '';
		}
		if ( ! empty( $state['pushed'] ) ) {
			return 'pushed';
		}
		return ! empty( $state['pkg'] ) ? 'draft' : 'started';
	}

	private static function captions_status_from_state( $video_id ) {
		$state = TW_Store::get( 'state_' . $video_id, array() );
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
		// Quick sync: refresh list thumbs for the newest handful only, so a Studio
		// or Media Library replace shows up without re-downloading the whole channel.
		$force_thumbs_left = ( 'quick' === $mode && '' === (string) $token ) ? 12 : 0;

		for ( $i = 0; $i < $pages; $i++ ) {
			$page = TW_YouTube::uploads_page( $token );
			if ( is_wp_error( $page ) ) {
				return $page;
			}
			foreach ( $page['videos'] as $v ) {
				$force_one = $force_thumbs_left > 0;
				if ( $force_one ) {
					$force_thumbs_left--;
				}
				self::upsert(
					$v,
					$run,
					array(
						'from_uploads' => true,
						'force_thumb'  => $force_one,
					)
				);
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
			TW_Store::set( 'scheduled_sync_note', $note );
			if ( 'full' === $mode ) {
				global $wpdb;
				// Local cleanup only. An unfinished excluded-video check must not drop
				// draft or scheduled rows the scan did not reach.
				$sql = 'DELETE FROM ' . self::table() . ' WHERE user_id = %d AND sync_run <> %s';
				if ( ! $found['complete'] ) {
					$sql .= " AND privacy <> 'draft'";
					$sql .= " AND NOT (privacy = 'private' AND publish_at IS NOT NULL AND publish_at > UTC_TIMESTAMP())";
				}
				$wpdb->query( $wpdb->prepare( $sql, self::uid(), $run ) ); // phpcs:ignore
				if ( $found['complete'] ) {
					TW_Store::set( 'index_full', time() );
				}
			}
			TW_Store::set( 'index_synced', time() );
			delete_transient( 'tw_calendar_' . self::uid() );
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
		TW_Store::set( 'scheduled_sync_note', $note );
		TW_Store::set( 'index_synced', time() );
		delete_transient( 'tw_calendar_' . self::uid() );

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
		$t   = self::table();
		$uid = self::uid();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$t} SET privacy = 'draft'
				WHERE user_id = %d AND privacy = 'private'
				AND (publish_at IS NULL OR publish_at <= UTC_TIMESTAMP())",
				$uid
			)
		);
	}

	public static function has_draft( $video_id ) {
		global $wpdb;
		$privacy = $wpdb->get_var( $wpdb->prepare( 'SELECT privacy FROM ' . self::table() . ' WHERE user_id = %d AND id = %s', self::uid(), $video_id ) ); // phpcs:ignore
		return 'draft' === $privacy;
	}

	/** Re-fetch publishAt for indexed private and draft videos. */
	private static function refresh_private_schedules( $run ) {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM " . self::table() . " WHERE user_id = %d AND privacy IN ('private','draft')", self::uid() ) ); // phpcs:ignore
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
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM " . self::table() . " WHERE user_id = %d AND privacy = 'private' AND publish_at IS NOT NULL AND publish_at > UTC_TIMESTAMP()", self::uid() ) ); // phpcs:ignore
	}

	public static function count_drafts() {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM " . self::table() . " WHERE user_id = %d AND privacy = 'draft'", self::uid() ) ); // phpcs:ignore
	}

	public static function count_all() {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE user_id = %d', self::uid() ) ); // phpcs:ignore
	}

	/** Indexed visibility for a video id, or empty string. */
	public static function privacy_of( $video_id ) {
		global $wpdb;
		$privacy = $wpdb->get_var( $wpdb->prepare( 'SELECT privacy FROM ' . self::table() . ' WHERE user_id = %d AND id = %s', self::uid(), $video_id ) ); // phpcs:ignore
		return is_string( $privacy ) ? $privacy : '';
	}

	/** Counts for the visibility tabs. */
	public static function counts() {
		global $wpdb;
		$t   = self::table();
		$uid = self::uid();
		$out = array(
			'all'       => self::count_all(),
			'public'    => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE user_id = %d AND privacy = 'public'", $uid ) ), // phpcs:ignore
			'unlisted'  => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE user_id = %d AND privacy = 'unlisted'", $uid ) ), // phpcs:ignore
			'private'   => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE user_id = %d AND privacy = 'private' AND (publish_at IS NULL OR publish_at <= UTC_TIMESTAMP())", $uid ) ), // phpcs:ignore
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
		$where = array( 'user_id = %d' );
		$args  = array( self::uid() );

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
				'timeout'     => 8,
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
					$wpdb->update( self::table(), array( 'thumb' => $paths['url'] ), array( 'user_id' => self::uid(), 'id' => $id ) );
					$item['thumb'] = $paths['url'];
				}
				continue;
			}
			if ( self::thumb_is_remote( $thumb ) ) {
				$local = self::cache_thumbnail( $id, $thumb );
				if ( self::has_local_thumb( $id ) ) {
					$wpdb->update( self::table(), array( 'thumb' => $local ), array( 'user_id' => self::uid(), 'id' => $id ) );
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
				$wpdb->update( self::table(), array( 'thumb' => $local ), array( 'user_id' => self::uid(), 'id' => $id ) );
				$item['thumb'] = $local;
			} else {
				// Keep the fresh signed URL briefly if download failed this pass.
				$wpdb->update( self::table(), array( 'thumb' => $fresh[ $id ] ), array( 'user_id' => self::uid(), 'id' => $id ) );
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

	/**
	 * Bust browser cache when uploads/tubewriter/thumbs/{id}.jpg is replaced
	 * after a custom thumbnail upload (same path, new bytes).
	 */
	public static function thumb_url_with_version( $video_id, $url ) {
		$url = (string) $url;
		if ( '' === $url ) {
			return '';
		}
		$paths = self::local_thumb_paths( $video_id );
		if ( ! $paths || ! self::has_local_thumb( $video_id ) ) {
			return $url;
		}
		if ( false === strpos( $url, '/tubewriter/thumbs/' ) ) {
			return $url;
		}
		return add_query_arg( 'v', (string) filemtime( $paths['file'] ), $paths['url'] );
	}

	/** Scheduled and published uploads for the editor / Schedule calendars, from the index (no API calls). */
	public static function calendar_events() {
		global $wpdb;
		$t    = self::table();
		$rows = $wpdb->get_results( // phpcs:ignore
			$wpdb->prepare(
				"SELECT id, title, privacy, publish_at, published_at, thumb FROM {$t}
				WHERE user_id = %d AND ((privacy = 'private' AND publish_at IS NOT NULL) OR (privacy IN ('public','unlisted') AND published_at IS NOT NULL))",
				self::uid()
			),
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
				'thumb' => (string) ( $r['thumb'] ?? '' ),
			);
		}
		return $out;
	}
}
