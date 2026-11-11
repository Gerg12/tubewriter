<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin YouTube Data API v3 client. Quota per call is noted next to each method.
 */
class TW_YouTube {

	const API = 'https://www.googleapis.com/youtube/v3/';

	/** Uploads playlist id for the connected channel (channels.list, 1 unit, cached). */
	public static function channel() {
		$cached = TW_Store::get( 'channel', array() );
		if ( is_array( $cached ) && ! empty( $cached['uploads'] ) ) {
			return $cached;
		}
		$data = self::request( 'GET', 'channels', array( 'part' => 'snippet,contentDetails', 'mine' => 'true' ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( empty( $data['items'][0] ) ) {
			return new WP_Error( 'tw_yt', 'This Google account has no YouTube channel.' );
		}
		$item    = $data['items'][0];
		$channel = array(
			'id'      => $item['id'],
			'title'   => $item['snippet']['title'] ?? '',
			'uploads' => $item['contentDetails']['relatedPlaylists']['uploads'] ?? '',
		);
		TW_Store::set( 'channel', $channel );
		return $channel;
	}

	/**
	 * videos.list for up to 50 ids per call, 1 unit per call.
	 *
	 * @return array<int, array>|WP_Error
	 */
	public static function videos_by_ids( array $ids ) {
		$ids = array_values( array_filter( array_map( 'strval', $ids ) ) );
		if ( ! $ids ) {
			return array();
		}
		$out = array();
		foreach ( array_chunk( $ids, 50 ) as $chunk ) {
			$data = self::request( 'GET', 'videos', array( 'part' => 'snippet,status,statistics,contentDetails', 'id' => implode( ',', $chunk ), 'maxResults' => 50 ) );
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			foreach ( $data['items'] ?? array() as $video ) {
				$out[] = $video;
			}
		}
		return $out;
	}

	/** videos.list, 1 unit. */
	public static function get_video( $video_id ) {
		$data = self::request( 'GET', 'videos', array( 'part' => 'snippet,status,statistics,contentDetails', 'id' => $video_id ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( empty( $data['items'][0] ) ) {
			return new WP_Error( 'tw_yt', 'Video not found on your channel.' );
		}
		return $data['items'][0];
	}

	/**
	 * Fetch the best caption track as SRT. captions.list 50 units + captions.download 200 units.
	 *
	 * @return string|WP_Error SRT text.
	 */
	public static function captions_srt( $video_id ) {
		$list = self::request( 'GET', 'captions', array( 'part' => 'snippet', 'videoId' => $video_id ) );
		if ( is_wp_error( $list ) ) {
			return $list;
		}
		$tracks = $list['items'] ?? array();
		if ( ! $tracks ) {
			return new WP_Error( 'tw_no_captions', 'No captions yet. YouTube makes auto-captions a while after upload finishes. Try again later, or paste a transcript.' );
		}

		// Prefer a creator-uploaded English track, then English auto-captions, then anything.
		usort(
			$tracks,
			function ( $a, $b ) {
				return self::track_score( $b ) <=> self::track_score( $a );
			}
		);
		$track = $tracks[0];

		$srt = self::request( 'GET', 'captions/' . rawurlencode( $track['id'] ), array( 'tfmt' => 'srt' ), null, false );
		if ( is_wp_error( $srt ) ) {
			return $srt;
		}
		if ( '' === trim( $srt ) ) {
			return new WP_Error( 'tw_no_captions', 'The caption track came back empty. Paste a transcript instead.' );
		}
		return $srt;
	}

	private static function track_score( $track ) {
		$s     = $track['snippet'] ?? array();
		$score = 0;
		if ( 0 === strpos( strtolower( $s['language'] ?? '' ), 'en' ) ) {
			$score += 2;
		}
		if ( 'asr' !== strtolower( $s['trackKind'] ?? '' ) ) {
			$score += 1;
		}
		if ( 'serving' !== strtolower( $s['status'] ?? 'serving' ) ) {
			$score -= 10;
		}
		return $score;
	}

	/** The fields TubeWriter can change and back up, read from a live video. */
	public static function snapshot( array $video ) {
		$sn = $video['snippet'] ?? array();
		$st = $video['status'] ?? array();
		return array(
			'title'                => (string) ( $sn['title'] ?? '' ),
			'description'          => (string) ( $sn['description'] ?? '' ),
			'tags'                 => array_values( (array) ( $sn['tags'] ?? array() ) ),
			'categoryId'           => (string) ( $sn['categoryId'] ?? '20' ),
			'defaultLanguage'      => (string) ( $sn['defaultLanguage'] ?? '' ),
			'defaultAudioLanguage' => (string) ( $sn['defaultAudioLanguage'] ?? '' ),
			'privacyStatus'        => (string) ( $st['privacyStatus'] ?? '' ),
			'publishAt'            => (string) ( $st['publishAt'] ?? '' ),
		);
	}

	/** Short hash of a snapshot, used to detect changes made on YouTube between Review and Publish. */
	public static function fingerprint( array $snapshot ) {
		return substr( md5( wp_json_encode( $snapshot ) ), 0, 12 );
	}

	/**
	 * Write details and optionally the schedule. videos.update, 50 units.
	 *
	 * videos.update wipes any writable field left out of a part you send, so every writable
	 * field is sent back: ours where we change it, the live value everywhere else.
	 *
	 * @param array  $video    Live video resource (snippet, status) fetched just before.
	 * @param array  $details  title, description, tags, categoryId, defaultLanguage, defaultAudioLanguage.
	 * @param string $schedule 'keep', 'set' (with $publish_at) or 'clear' (private, unscheduled).
	 */
	public static function write_video( array $video, array $details, $schedule = 'keep', $publish_at = '' ) {
		$current = $video['snippet'] ?? array();
		$snippet = array(
			'title'       => $details['title'],
			'description' => $details['description'],
			'tags'        => array_values( $details['tags'] ),
			'categoryId'  => (string) ( $details['categoryId'] ?: ( $current['categoryId'] ?? '20' ) ),
		);
		foreach ( array( 'defaultLanguage', 'defaultAudioLanguage' ) as $lang ) {
			$value = $details[ $lang ] ?? ( $current[ $lang ] ?? '' );
			if ( '' !== (string) $value ) {
				$snippet[ $lang ] = $value;
			}
		}

		$body  = array( 'id' => $video['id'], 'snippet' => $snippet );
		$parts = array( 'snippet' );

		if ( 'keep' !== $schedule ) {
			$status = $video['status'] ?? array();
			if ( 'private' !== ( $status['privacyStatus'] ?? '' ) ) {
				return new WP_Error( 'tw_schedule', 'Only private videos can be scheduled. This one is ' . ( $status['privacyStatus'] ?? 'unknown' ) . '.' );
			}
			$new_status = array( 'privacyStatus' => 'private' );
			if ( 'set' === $schedule ) {
				$new_status['publishAt'] = $publish_at;
			}
			foreach ( array( 'embeddable', 'license', 'publicStatsViewable', 'selfDeclaredMadeForKids', 'containsSyntheticMedia' ) as $key ) {
				if ( array_key_exists( $key, $status ) ) {
					$new_status[ $key ] = $status[ $key ];
				}
			}
			$body['status'] = $new_status;
			$parts[]        = 'status';
		}

		$result = self::request( 'PUT', 'videos', array( 'part' => implode( ',', $parts ) ), $body );
		return $result;
	}

	/** The channel's playlists (playlists.list, 1 unit per 50). */
	public static function playlists() {
		$out   = array();
		$token = '';
		for ( $page = 0; $page < 4; $page++ ) {
			$args = array( 'part' => 'snippet,status,contentDetails', 'mine' => 'true', 'maxResults' => 50 );
			if ( $token ) {
				$args['pageToken'] = $token;
			}
			$data = self::request( 'GET', 'playlists', $args );
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			foreach ( $data['items'] ?? array() as $pl ) {
				$out[] = array(
					'id'      => $pl['id'],
					'title'   => $pl['snippet']['title'] ?? '',
					'privacy' => $pl['status']['privacyStatus'] ?? '',
					'count'   => (int) ( $pl['contentDetails']['itemCount'] ?? 0 ),
				);
			}
			$token = $data['nextPageToken'] ?? '';
			if ( ! $token ) {
				break;
			}
		}
		usort(
			$out,
			function ( $a, $b ) {
				return strcasecmp( $a['title'], $b['title'] );
			}
		);
		return $out;
	}

	/** playlists.insert, 50 units. */
	public static function create_playlist( $title, $description, $privacy = 'public' ) {
		$data = self::request(
			'POST',
			'playlists',
			array( 'part' => 'snippet,status' ),
			array(
				'snippet' => array( 'title' => $title, 'description' => $description ),
				'status'  => array( 'privacyStatus' => in_array( $privacy, array( 'public', 'unlisted', 'private' ), true ) ? $privacy : 'public' ),
			)
		);
		return is_wp_error( $data ) ? $data : array( 'id' => $data['id'], 'title' => $data['snippet']['title'] ?? $title );
	}

	/** Is the video already in the playlist? playlistItems.list, 1 unit. */
	public static function in_playlist( $playlist_id, $video_id ) {
		$data = self::request( 'GET', 'playlistItems', array( 'part' => 'id', 'playlistId' => $playlist_id, 'videoId' => $video_id ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		return ! empty( $data['items'] );
	}

	/** playlistItems.insert, 50 units. Skips silently if it's already there. */
	public static function add_to_playlist( $playlist_id, $video_id ) {
		$already = self::in_playlist( $playlist_id, $video_id );
		if ( is_wp_error( $already ) ) {
			return $already;
		}
		if ( $already ) {
			return 'already';
		}
		$data = self::request(
			'POST',
			'playlistItems',
			array( 'part' => 'snippet' ),
			array( 'snippet' => array( 'playlistId' => $playlist_id, 'resourceId' => array( 'kind' => 'youtube#video', 'videoId' => $video_id ) ) )
		);
		return is_wp_error( $data ) ? $data : 'added';
	}

	/**
	 * One page (50) of uploads with everything the index needs. 2 units.
	 *
	 * The uploads playlist leaves out scheduled videos and Studio drafts, so this
	 * page alone is not the whole channel. See playlist_excluded_videos().
	 *
	 * @return array{videos: array, next: string, total: int}|WP_Error
	 */
	public static function uploads_page( $token = '' ) {
		$channel = self::channel();
		if ( is_wp_error( $channel ) ) {
			return $channel;
		}
		$args = array( 'part' => 'contentDetails', 'playlistId' => $channel['uploads'], 'maxResults' => 50 );
		if ( $token ) {
			$args['pageToken'] = $token;
		}
		$list = self::request( 'GET', 'playlistItems', $args );
		if ( is_wp_error( $list ) ) {
			return $list;
		}
		$ids  = array();
		$when = array();
		foreach ( $list['items'] ?? array() as $item ) {
			$id = (string) ( $item['contentDetails']['videoId'] ?? '' );
			if ( '' === $id ) {
				continue;
			}
			$ids[]       = $id;
			$when[ $id ] = (string) ( $item['contentDetails']['videoPublishedAt'] ?? '' );
		}
		$videos = array();
		if ( $ids ) {
			$data = self::request( 'GET', 'videos', array( 'part' => 'snippet,status,statistics,contentDetails', 'id' => implode( ',', $ids ), 'maxResults' => 50 ) );
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			foreach ( $data['items'] ?? array() as $video ) {
				$videos[] = self::apply_schedule_hint( $video, $when[ $video['id'] ] ?? '' );
			}
		}
		return array(
			'videos' => $videos,
			'next'   => $list['nextPageToken'] ?? '',
			'total'  => (int) ( $list['pageInfo']['totalResults'] ?? count( $videos ) ),
		);
	}

	/**
	 * Scheduled videos and Studio drafts that the uploads playlist leaves out.
	 *
	 * The Data API has no "draft" privacyStatus. Studio drafts still come back as
	 * private, but YouTube often omits them from the uploads playlist the same way
	 * it omits future scheduled videos. search.list with forMine finds them.
	 * Each search call costs 1 unit in Google's separate search bucket (100 calls
	 * a day), then videos.list is 1 unit per 50 on the main quota.
	 *
	 * Looks at videos created in the last 120 days, newest first, up to $pages
	 * pages of 50. Older scheduled or draft uploads are not returned.
	 *
	 * @param int $pages How many search pages to read.
	 * @return array{scheduled: array, drafts: array, error: string, complete: bool}
	 */
	public static function playlist_excluded_videos( $pages = 2 ) {
		$pages     = max( 1, min( 12, (int) $pages ) );
		$scheduled = array();
		$drafts    = array();
		$token     = '';
		$error     = '';
		$complete  = true;

		for ( $page = 0; $page < $pages; $page++ ) {
			$args = array(
				'part'       => 'snippet',
				'forMine'    => 'true',
				'type'       => 'video',
				'order'      => 'date',
				'maxResults' => 50,
			);
			if ( $token ) {
				$args['pageToken'] = $token;
			}
			$data = self::request( 'GET', 'search', $args );
			if ( is_wp_error( $data ) ) {
				$error    = $data->get_error_message();
				$complete = false;
				break;
			}
			$ids = array();
			foreach ( $data['items'] ?? array() as $item ) {
				$id = (string) ( $item['id']['videoId'] ?? '' );
				if ( '' !== $id ) {
					$ids[] = $id;
				}
			}
			if ( $ids ) {
				$videos = self::request( 'GET', 'videos', array( 'part' => 'snippet,status,statistics,contentDetails', 'id' => implode( ',', $ids ), 'maxResults' => 50 ) );
				if ( is_wp_error( $videos ) ) {
					$error    = $videos->get_error_message();
					$complete = false;
					break;
				}
				foreach ( $videos['items'] ?? array() as $video ) {
					if ( self::is_scheduled( $video ) ) {
						$scheduled[ $video['id'] ] = $video;
					} elseif ( self::is_private_unscheduled( $video ) ) {
						// Caller only indexes these when the uploads playlist missed them.
						$drafts[ $video['id'] ] = $video;
					}
				}
			}
			$token = $data['nextPageToken'] ?? '';
			if ( ! $token ) {
				break;
			}
			if ( $page === $pages - 1 ) {
				$complete = false;
			}
		}

		return array(
			'scheduled' => array_values( $scheduled ),
			'drafts'    => array_values( $drafts ),
			'error'     => $error,
			'complete'  => $complete,
		);
	}

	/** @deprecated Use playlist_excluded_videos(). */
	public static function scheduled_videos( $pages = 2 ) {
		$found = self::playlist_excluded_videos( $pages );
		return array(
			'videos'   => $found['scheduled'],
			'error'    => $found['error'],
			'complete' => $found['complete'],
		);
	}

	/** Private, with a publish time still in the future. */
	public static function is_scheduled( array $video ) {
		$status = $video['status'] ?? array();
		if ( 'private' !== ( $status['privacyStatus'] ?? '' ) ) {
			return false;
		}
		$at = strtotime( (string) ( $status['publishAt'] ?? '' ) );
		return $at && $at > time();
	}

	/** Private with no future go-live date (includes Studio drafts and finished private uploads). */
	public static function is_private_unscheduled( array $video ) {
		$status = $video['status'] ?? array();
		if ( 'private' !== ( $status['privacyStatus'] ?? '' ) ) {
			return false;
		}
		return ! self::is_scheduled( $video );
	}

	/**
	 * If videos.list omitted publishAt, a future playlist publish time on a
	 * private video is the schedule. Upload times in the past are ignored.
	 */
	private static function apply_schedule_hint( array $video, $published_at ) {
		$status = $video['status'] ?? array();
		if ( ! empty( $status['publishAt'] ) || 'private' !== ( $status['privacyStatus'] ?? '' ) ) {
			return $video;
		}
		$at = strtotime( (string) $published_at );
		if ( $at && $at > time() ) {
			$video['status']['publishAt'] = gmdate( 'Y-m-d\TH:i:s\Z', $at );
		}
		return $video;
	}

	/** Calendar events come from the local index, so the calendar costs no API units. */
	public static function calendar_events() {
		return TW_Index::calendar_events();
	}

	/**
	 * Upload a custom thumbnail for a video. thumbnails.set, about 50 units.
	 * Channel must be verified for custom thumbnails. PNG or JPEG only.
	 *
	 * @param string $video_id
	 * @param string $binary Raw image bytes.
	 * @param string $mime   image/png or image/jpeg.
	 * @return array|WP_Error YouTube thumbnailSetResponse.
	 */
	public static function set_thumbnail( $video_id, $binary, $mime = 'image/png' ) {
		$video_id = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $video_id );
		if ( ! $video_id ) {
			return new WP_Error( 'tw_yt', 'Missing video id.' );
		}
		if ( '' === (string) $binary ) {
			return new WP_Error( 'tw_yt', 'Thumbnail file is empty.' );
		}
		$mime = strtolower( (string) $mime );
		if ( 'image/jpg' === $mime ) {
			$mime = 'image/jpeg';
		}
		if ( ! in_array( $mime, array( 'image/png', 'image/jpeg' ), true ) ) {
			return new WP_Error( 'tw_yt', 'YouTube only accepts PNG or JPEG thumbnails.' );
		}

		$token = TW_OAuth::access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$url = add_query_arg(
			array(
				'videoId'    => $video_id,
				'uploadType' => 'media',
			),
			'https://www.googleapis.com/upload/youtube/v3/thumbnails/set'
		);

		$response = wp_remote_request(
			$url,
			array(
				'method'  => 'POST',
				'timeout' => 60,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => $mime,
				),
				'body'    => $binary,
			)
		);
		self::track_quota( 'POST', 'thumbnails/set' );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'tw_yt', 'Could not reach YouTube: ' . $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( $code >= 400 ) {
			$reason  = $data['error']['errors'][0]['reason'] ?? '';
			$message = $data['error']['message'] ?? ( 'HTTP ' . $code );
			if ( 'quotaExceeded' === $reason ) {
				$message = 'Daily YouTube API quota used up. It resets at midnight Pacific time.';
			} elseif ( 'forbidden' === $reason || 403 === (int) $code ) {
				$message = 'YouTube refused the thumbnail. The channel may need phone verification for custom thumbnails, or this account cannot edit that video.';
			} elseif ( 'invalidImage' === $reason ) {
				$message = 'YouTube rejected the image. Try a PNG under 2 MB at 1280x720.';
			} elseif ( 'uploadRateLimitExceeded' === $reason ) {
				$message = 'Too many thumbnail uploads recently. Wait a bit and try again.';
			} elseif ( 'videoNotFound' === $reason ) {
				$message = 'Video not found on your channel.';
			}
			return new WP_Error( 'tw_yt', 'YouTube: ' . wp_strip_all_tags( $message ), array( 'reason' => $reason, 'status' => $code ) );
		}

		return is_array( $data ) ? $data : array( 'ok' => true );
	}

	/** Rough count of API units used today (Pacific day, like Google's reset). */
	public static function quota_used() {
		$q = TW_Store::get( 'quota', array() );
		return ( is_array( $q ) && ( $q['day'] ?? '' ) === self::quota_day() ) ? (int) $q['used'] : 0;
	}

	private static function quota_day() {
		return ( new DateTime( 'now', new DateTimeZone( 'America/Los_Angeles' ) ) )->format( 'Y-m-d' );
	}

	private static function track_quota( $method, $path ) {
		if ( 'GET' === $method && 'captions' === $path ) {
			$cost = 50;
		} elseif ( 0 === strpos( $path, 'captions/' ) ) {
			$cost = 200;
		} elseif ( 'GET' !== $method ) {
			$cost = 50;
		} else {
			$cost = 1;
		}
		$day  = self::quota_day();
		$q    = TW_Store::get( 'quota', array() );
		$used = ( is_array( $q ) && ( $q['day'] ?? '' ) === $day ) ? (int) $q['used'] : 0;
		TW_Store::set( 'quota', array( 'day' => $day, 'used' => $used + $cost ) );
	}

	/**
	 * @return array|string|WP_Error Decoded JSON, raw body when $json is false, or an error.
	 */
	private static function request( $method, $path, array $query = array(), $body = null, $json = true ) {
		$token = TW_OAuth::access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$url  = add_query_arg( array_map( 'rawurlencode', $query ), self::API . $path );
		$args = array(
			'method'  => $method,
			'timeout' => 45,
			'headers' => array( 'Authorization' => 'Bearer ' . $token ),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json; charset=utf-8';
			$args['body']                    = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );
		self::track_quota( $method, $path );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );

		if ( $code >= 400 ) {
			$data    = json_decode( $raw, true );
			$reason  = $data['error']['errors'][0]['reason'] ?? '';
			$message = $data['error']['message'] ?? ( 'HTTP ' . $code );
			if ( 'quotaExceeded' === $reason ) {
				$message = 'Daily YouTube API quota used up. It resets at midnight Pacific time.';
			} elseif ( 'forbidden' === $reason && 0 === strpos( $path, 'captions/' ) ) {
				$message = 'YouTube would not hand over this caption track. Paste a transcript instead.';
			}
			return new WP_Error( 'tw_yt', 'YouTube: ' . wp_strip_all_tags( $message ), array( 'reason' => $reason, 'status' => $code ) );
		}

		if ( ! $json ) {
			return $raw;
		}
		$data = json_decode( $raw, true );
		return is_array( $data ) ? $data : new WP_Error( 'tw_yt', 'YouTube sent back something unreadable.' );
	}
}
