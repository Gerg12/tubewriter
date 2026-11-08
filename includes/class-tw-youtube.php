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
		$cached = get_option( 'tw_channel' );
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
		update_option( 'tw_channel', $channel, false );
		return $channel;
	}

	/** Latest uploads with status (playlistItems.list 1 unit + videos.list 1 unit). */
	public static function list_videos( $page_token = '' ) {
		$channel = self::channel();
		if ( is_wp_error( $channel ) ) {
			return $channel;
		}
		$args = array( 'part' => 'contentDetails', 'playlistId' => $channel['uploads'], 'maxResults' => 25 );
		if ( $page_token ) {
			$args['pageToken'] = $page_token;
		}
		$list = self::request( 'GET', 'playlistItems', $args );
		if ( is_wp_error( $list ) ) {
			return $list;
		}
		$ids = array();
		foreach ( $list['items'] ?? array() as $item ) {
			$ids[] = $item['contentDetails']['videoId'];
		}
		$videos = array();
		if ( $ids ) {
			$data = self::request( 'GET', 'videos', array( 'part' => 'snippet,status,contentDetails', 'id' => implode( ',', $ids ) ) );
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			$by_id = array();
			foreach ( $data['items'] ?? array() as $v ) {
				$by_id[ $v['id'] ] = $v;
			}
			foreach ( $ids as $id ) {
				if ( isset( $by_id[ $id ] ) ) {
					$videos[] = $by_id[ $id ];
				}
			}
		}
		return array(
			'videos'    => $videos,
			'next'      => $list['nextPageToken'] ?? '',
			'prev'      => $list['prevPageToken'] ?? '',
		);
	}

	/** videos.list, 1 unit. */
	public static function get_video( $video_id ) {
		$data = self::request( 'GET', 'videos', array( 'part' => 'snippet,status,contentDetails', 'id' => $video_id ) );
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

	/**
	 * Push title, description, tags and an optional schedule. videos.update, 50 units.
	 *
	 * videos.update wipes any writable field left out of a part you send, so we fetch the
	 * live video first and send back every writable field with only ours changed.
	 *
	 * @param string      $video_id
	 * @param array       $fields   title, description, tags (array)
	 * @param string|null $publish_at ISO 8601 UTC or null to leave the schedule alone.
	 */
	public static function update_video( $video_id, array $fields, $publish_at = null ) {
		$video = self::get_video( $video_id );
		if ( is_wp_error( $video ) ) {
			return $video;
		}
		$current = $video['snippet'];

		$snippet = array(
			'title'       => $fields['title'],
			'description' => $fields['description'],
			'tags'        => array_values( $fields['tags'] ),
			'categoryId'  => $current['categoryId'] ?? '20',
		);
		if ( ! empty( $current['defaultLanguage'] ) ) {
			$snippet['defaultLanguage'] = $current['defaultLanguage'];
		}

		$body  = array( 'id' => $video_id, 'snippet' => $snippet );
		$parts = array( 'snippet' );

		if ( $publish_at ) {
			$status = $video['status'] ?? array();
			if ( 'private' !== ( $status['privacyStatus'] ?? '' ) ) {
				return new WP_Error( 'tw_schedule', 'Only private videos can be scheduled. This one is ' . ( $status['privacyStatus'] ?? 'unknown' ) . '.' );
			}
			$new_status = array(
				'privacyStatus' => 'private',
				'publishAt'     => $publish_at,
			);
			foreach ( array( 'embeddable', 'license', 'publicStatsViewable', 'selfDeclaredMadeForKids', 'containsSyntheticMedia' ) as $key ) {
				if ( array_key_exists( $key, $status ) ) {
					$new_status[ $key ] = $status[ $key ];
				}
			}
			$body['status'] = $new_status;
			$parts[]        = 'status';
		}

		return self::request( 'PUT', 'videos', array( 'part' => implode( ',', $parts ) ), $body );
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
