<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Layered abuse controls for YouTube write actions.
 *
 * Burst stops scripted spray. Soft pace asks for one extra confirm on a busy hour.
 * Day ceiling is the hard stop. Same-video cooldown stops double-submit loops.
 * High-risk publishes (go live now) weigh more toward soft/day windows.
 */
class TW_Rate_Limit {

	const BURST_MAX      = 5;
	const BURST_WINDOW   = 60;
	const SOFT_POINTS    = 25;
	const SOFT_WINDOW    = HOUR_IN_SECONDS;
	const DAY_POINTS     = 100;
	const COOLDOWN_SECS  = 20;
	const HIGH_RISK_WEIGHT = 2;

	/**
	 * @param string $action    publish|restore|thumb_upload
	 * @param string $video_id
	 * @param array  $opts {
	 *   @type bool $pace_ack   User confirmed soft-pace dialog.
	 *   @type bool $high_risk  schedule=now / go public.
	 * }
	 * @return true|WP_Error
	 */
	public static function check( $action, $video_id, array $opts = array() ) {
		$action   = sanitize_key( (string) $action );
		$video_id = (string) $video_id;
		$uid      = self::uid();
		$now      = time();
		$pace_ack = ! empty( $opts['pace_ack'] );
		$weight   = ! empty( $opts['high_risk'] ) ? self::HIGH_RISK_WEIGHT : 1;

		$burst = self::get_bucket( $uid, 'burst' );
		$burst = self::prune_events( $burst, $now - self::BURST_WINDOW );
		if ( count( $burst['events'] ) >= self::BURST_MAX ) {
			return new WP_Error(
				'tw_rate_burst',
				'Too many YouTube changes in the last minute. Wait a few seconds and try again.'
			);
		}

		if ( $video_id ) {
			$cool_key = 'cool_' . $action . '_' . md5( $video_id );
			$cool     = self::get_bucket( $uid, $cool_key );
			$last     = (int) ( $cool['last'] ?? 0 );
			if ( $last && ( $now - $last ) < self::COOLDOWN_SECS ) {
				$wait = self::COOLDOWN_SECS - ( $now - $last );
				return new WP_Error(
					'tw_rate_cooldown',
					sprintf( 'That video was just updated. Wait %d seconds before trying again.', max( 1, $wait ) )
				);
			}
		}

		$day = self::get_bucket( $uid, 'day_' . gmdate( 'Ymd', $now ) );
		$day_points = self::points_of( $day['videos'] ?? array() );
		$day_extra  = self::new_points( $day['videos'] ?? array(), $video_id, $weight );
		if ( ( $day_points + $day_extra ) > self::DAY_POINTS ) {
			return new WP_Error(
				'tw_rate_day',
				'Daily YouTube change limit reached for this account. Try again tomorrow, or space changes out.'
			);
		}

		// Soft pace: publish + restore only (thumb uploads stay on burst/day/cooldown).
		if ( in_array( $action, array( 'publish', 'restore' ), true ) ) {
			$soft = self::get_bucket( $uid, 'soft' );
			$soft = self::prune_videos( $soft, $now - self::SOFT_WINDOW );
			$soft_points = self::points_of( $soft['videos'] ?? array() );
			$soft_extra  = self::new_points( $soft['videos'] ?? array(), $video_id, $weight );
			if ( ( $soft_points + $soft_extra ) > self::SOFT_POINTS && ! $pace_ack ) {
				return new WP_Error(
					'tw_rate_pace',
					'You\'ve changed a lot of videos in the last hour. Continue with this one?',
					array( 'needs_confirm' => true )
				);
			}
		}

		return true;
	}

	/**
	 * Record a successful YouTube write. Call only after the API write succeeds.
	 *
	 * @param string $action
	 * @param string $video_id
	 * @param array  $opts Same shape as check().
	 */
	public static function hit( $action, $video_id, array $opts = array() ) {
		$action   = sanitize_key( (string) $action );
		$video_id = (string) $video_id;
		$uid      = self::uid();
		$now      = time();
		$weight   = ! empty( $opts['high_risk'] ) ? self::HIGH_RISK_WEIGHT : 1;

		$burst = self::get_bucket( $uid, 'burst' );
		$burst = self::prune_events( $burst, $now - self::BURST_WINDOW );
		$burst['events'][] = $now;
		self::set_bucket( $uid, 'burst', $burst, self::BURST_WINDOW + 30 );

		if ( $video_id ) {
			$cool_key = 'cool_' . $action . '_' . md5( $video_id );
			self::set_bucket( $uid, $cool_key, array( 'last' => $now ), self::COOLDOWN_SECS + 5 );
		}

		$day_key = 'day_' . gmdate( 'Ymd', $now );
		$day     = self::get_bucket( $uid, $day_key );
		$day['videos'] = self::merge_video( $day['videos'] ?? array(), $video_id, $weight, $now );
		self::set_bucket( $uid, $day_key, $day, DAY_IN_SECONDS + HOUR_IN_SECONDS );

		if ( in_array( $action, array( 'publish', 'restore' ), true ) ) {
			$soft = self::get_bucket( $uid, 'soft' );
			$soft = self::prune_videos( $soft, $now - self::SOFT_WINDOW );
			$soft['videos'] = self::merge_video( $soft['videos'] ?? array(), $video_id, $weight, $now );
			self::set_bucket( $uid, 'soft', $soft, self::SOFT_WINDOW + 60 );
		}
	}

	/** Scope key: front user id, or current admin user for site context. */
	private static function uid() {
		if ( TW_Context::is_user() ) {
			return (int) TW_Context::user_id();
		}
		return (int) get_current_user_id();
	}

	/**
	 * @return array{events?: int[], videos?: array<string, array{w:int,t:int}>, last?: int}
	 */
	private static function get_bucket( $uid, $name ) {
		$key  = self::key( $uid, $name );
		$data = get_transient( $key );
		return is_array( $data ) ? $data : array();
	}

	private static function set_bucket( $uid, $name, array $data, $ttl ) {
		set_transient( self::key( $uid, $name ), $data, max( 30, (int) $ttl ) );
	}

	private static function key( $uid, $name ) {
		return 'tw_rl_' . (int) $uid . '_' . preg_replace( '/[^a-z0-9_]/', '', (string) $name );
	}

	private static function prune_events( array $bucket, $cutoff ) {
		$events = array();
		foreach ( (array) ( $bucket['events'] ?? array() ) as $ts ) {
			if ( (int) $ts >= $cutoff ) {
				$events[] = (int) $ts;
			}
		}
		$bucket['events'] = $events;
		return $bucket;
	}

	private static function prune_videos( array $bucket, $cutoff ) {
		$videos = array();
		foreach ( (array) ( $bucket['videos'] ?? array() ) as $id => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			if ( (int) ( $row['t'] ?? 0 ) >= $cutoff ) {
				$videos[ (string) $id ] = array(
					'w' => max( 1, (int) ( $row['w'] ?? 1 ) ),
					't' => (int) $row['t'],
				);
			}
		}
		$bucket['videos'] = $videos;
		return $bucket;
	}

	/**
	 * @param array<string, array{w:int,t:int}> $videos
	 */
	private static function points_of( array $videos ) {
		$sum = 0;
		foreach ( $videos as $row ) {
			$sum += max( 1, (int) ( is_array( $row ) ? ( $row['w'] ?? 1 ) : 1 ) );
		}
		return $sum;
	}

	/**
	 * Extra points this write would add (0 if video already counted at >= weight).
	 *
	 * @param array<string, array{w:int,t:int}> $videos
	 */
	private static function new_points( array $videos, $video_id, $weight ) {
		if ( '' === (string) $video_id ) {
			return $weight;
		}
		$existing = (int) ( $videos[ $video_id ]['w'] ?? 0 );
		return max( 0, $weight - $existing );
	}

	/**
	 * @param array<string, array{w:int,t:int}> $videos
	 * @return array<string, array{w:int,t:int}>
	 */
	private static function merge_video( array $videos, $video_id, $weight, $now ) {
		if ( '' === (string) $video_id ) {
			return $videos;
		}
		$prev = $videos[ $video_id ] ?? array( 'w' => 0, 't' => 0 );
		$videos[ $video_id ] = array(
			'w' => max( (int) ( $prev['w'] ?? 0 ), (int) $weight ),
			't' => $now,
		);
		return $videos;
	}
}
