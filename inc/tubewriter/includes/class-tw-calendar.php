<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Local publishing workspace. Reads never call YouTube. All rows belong to the active account. */
class TW_Calendar {
	const DB_VERSION = '1';
	const COLORS = array( 'violet', 'blue', 'teal', 'amber', 'rose', 'slate' );

	public static function table() { global $wpdb; return $wpdb->prefix . 'tw_plans'; }
	public static function maybe_install() {
		if ( get_option( 'tw_plans_db_version' ) === self::DB_VERSION ) { return; }
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			date date NOT NULL,
			time varchar(5) NOT NULL DEFAULT '07:30',
			title text NOT NULL,
			game varchar(255) NOT NULL DEFAULT '',
			notes text NOT NULL,
			video_id varchar(32) NULL DEFAULT NULL,
			color varchar(12) NOT NULL DEFAULT 'violet',
			lane varchar(8) NOT NULL DEFAULT 'long',
			created datetime NOT NULL,
			updated datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY user_date (user_id,date),
			KEY user_video (user_id,video_id)
		) {$charset};" );
		update_option( 'tw_plans_db_version', self::DB_VERSION, false );
	}
	public static function rows() {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT id,title,description,privacy,publish_at,published_at,views,duration,thumb,tw_status,synced_at FROM ' . TW_Index::table() . ' WHERE user_id = %d ORDER BY published_at', TW_Context::user_id() ), ARRAY_A );
	}
	public static function plans() {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE user_id = %d ORDER BY date,time,id', TW_Context::user_id() ), ARRAY_A );
	}
	public static function local_datetime( $date, $time ) {
		$input = $date . ' ' . $time;
		$dt = DateTimeImmutable::createFromFormat( '!Y-m-d H:i', $input, TW_Settings::user_timezone() );
		if ( ! $dt || $dt->format( 'Y-m-d H:i' ) !== $input ) {
			return new WP_Error( 'tw_date', 'Pick a valid date and time. That local time may not exist when the clocks change.' );
		}
		return $dt;
	}
	public static function ready( array $row ) {
		$desc = trim( (string) ( $row['description'] ?? '' ) );
		$reasons = array();
		if ( ! preg_match( '/(?:^|\n)\s*(?:0:00|00:00|00:00:00)\s+\S/', $desc ) ) { $reasons[] = 'No chapters in the description'; }
		if ( mb_strlen( $desc ) < 200 || preg_match( '/(?:uploaded (?:from|via)|created with|default description|add (?:a |your )?description)/i', $desc ) ) { $reasons[] = 'Description is short or looks like a default'; }
		if ( 'pushed' !== ( $row['tw_status'] ?? '' ) ) { $reasons[] = 'Never pushed with UploadWriter'; }
		$at = empty( $row['publish_at'] ) ? 0 : strtotime( $row['publish_at'] . ' UTC' );
		return array( 'level' => $reasons ? ( $at > time() && $at <= time() + 48 * HOUR_IN_SECONDS ? 'red' : 'amber' ) : 'green', 'reasons' => $reasons );
	}
	public static function video_event( array $row ) {
		// Match TW_YouTube::is_scheduled: only a future private go-live stays "scheduled".
		// Past publish_at rows are assumed published until the next sync confirms privacy.
		$privacy    = (string) ( $row['privacy'] ?? '' );
		$publish_at = $row['publish_at'] ?? null;
		$sched_ts   = $publish_at ? strtotime( $publish_at . ' UTC' ) : 0;
		$scheduled  = 'private' === $privacy && $sched_ts && $sched_ts > time();
		$at         = ( $publish_at && 'private' === $privacy ) ? $publish_at : $row['published_at'];
		$ts         = $at ? strtotime( $at . ' UTC' ) : 0;
		return array(
			'id' => $row['id'], 'title' => $row['title'], 'type' => $scheduled ? 'scheduled' : 'published',
			'date' => $ts ? wp_date( 'Y-m-d', $ts, TW_Settings::user_timezone() ) : '',
			'time' => $ts ? wp_date( 'H:i', $ts, TW_Settings::user_timezone() ) : TW_Settings::get( 'default_time' ),
			'at' => $ts, 'lane' => (int) $row['duration'] <= 180 ? 'shorts' : 'long',
			'duration' => (int) $row['duration'], 'thumb' => $row['thumb'] ? TW_Index::thumb_url_with_version( $row['id'], $row['thumb'] ) : '',
			'views' => (int) ( $row['views'] ?? 0 ), 'syncedAt' => (int) ( $row['synced_at'] ?? 0 ),
			'ready' => self::ready( $row ),
		);
	}
	public static function workspace( $events_only = false ) {
		$events = array(); $drafts = array(); $videos = array(); $map = array();
		foreach ( self::rows() as $row ) {
			$map[ $row['id'] ] = $row;
			$event = self::video_event( $row );
			$videos[] = array( 'id' => $row['id'], 'title' => $row['title'] );
			if ( 'draft' === $row['privacy'] || ( 'private' === $row['privacy'] && empty( $row['publish_at'] ) ) ) {
				$event['type'] = 'draft'; $event['date'] = ''; $event['at'] = 0; $drafts[] = $event;
			} elseif ( ! empty( $row['publish_at'] ) || 'public' === $row['privacy'] ) { $events[] = $event; }
		}
		foreach ( self::plans() as $plan ) {
			// Linked uploads are represented exactly once and always follow the live indexed schedule.
			if ( ! empty( $plan['video_id'] ) && isset( $map[ $plan['video_id'] ] ) ) {
				$row = $map[ $plan['video_id'] ];
				if ( ! empty( $row['publish_at'] ) || 'public' === $row['privacy'] ) {
					foreach ( $events as &$event ) { if ( $event['id'] === $plan['video_id'] ) { $event['plan'] = $plan; } } unset( $event );
					continue;
				}
			}
			$dt = self::local_datetime( $plan['date'], $plan['time'] );
			$linked = ! empty( $plan['video_id'] ) && isset( $map[ $plan['video_id'] ] );
			$events[] = array( 'id' => 'plan-' . $plan['id'], 'title' => $linked ? $map[ $plan['video_id'] ]['title'] : $plan['title'], 'type' => 'planned', 'date' => $plan['date'], 'time' => $plan['time'], 'at' => is_wp_error( $dt ) ? 0 : $dt->getTimestamp(), 'lane' => $linked ? ( (int) $map[ $plan['video_id'] ]['duration'] <= 180 ? 'shorts' : 'long' ) : $plan['lane'], 'plan' => $plan, 'linked_status' => $linked ? $map[ $plan['video_id'] ]['privacy'] : '', 'ready' => $linked ? self::ready( $map[ $plan['video_id'] ] ) : null );
		}
		// Clash checks use UTC instants, including across midnight and daylight saving changes.
		foreach ( $events as $i => $a ) {
			if ( 'scheduled' !== $a['type'] ) { continue; }
			foreach ( $events as $j => $b ) {
				if ( $i !== $j && 'scheduled' === $b['type'] && $a['lane'] === $b['lane'] && abs( $a['at'] - $b['at'] ) <= HOUR_IN_SECONDS ) { $events[ $i ]['clash'] = true; break; }
			}
		}
		if ( $events_only ) { return array( 'events' => $events ); }
		return array( 'events' => $events, 'drafts' => $drafts, 'videos' => $videos, 'rhythms' => TW_Store::get( 'rhythms', array() ), 'best' => self::best_times(), 'now' => time(), 'today' => wp_date( 'Y-m-d', null, TW_Settings::user_timezone() ), 'defaultTime' => TW_Settings::get( 'default_time' ), 'tz' => TW_Settings::timezone_string() );
	}
	public static function save_plan( array $in ) {
		global $wpdb;
		$id = absint( $in['id'] ?? 0 );
		if ( $id && ! $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE user_id = %d AND id = %d', TW_Context::user_id(), $id ) ) ) { return new WP_Error( 'tw_plan', 'That plan was not found.' ); }
		$dt = self::local_datetime( (string) ( $in['date'] ?? '' ), (string) ( $in['time'] ?? '' ) );
		if ( is_wp_error( $dt ) ) { return $dt; }
		if ( $dt->getTimestamp() <= time() ) { return new WP_Error( 'tw_plan', 'Plan a video for a future time.' ); }
		$title = sanitize_text_field( $in['title'] ?? '' );
		if ( '' === $title ) { return new WP_Error( 'tw_plan', 'Give this plan a title.' ); }
		$video = sanitize_text_field( $in['video_id'] ?? '' );
		if ( $video && ! TW_Index::exists( $video ) ) { return new WP_Error( 'tw_plan', 'Choose an upload from your own library.' ); }
		if ( $video && $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE user_id = %d AND video_id = %s AND id <> %d', TW_Context::user_id(), $video, $id ) ) ) { return new WP_Error( 'tw_plan', 'That upload is already linked to a plan.' ); }
		$data = array( 'user_id' => TW_Context::user_id(), 'date' => $in['date'], 'time' => $in['time'], 'title' => mb_substr( $title, 0, 200 ), 'game' => sanitize_text_field( $in['game'] ?? '' ), 'notes' => sanitize_textarea_field( $in['notes'] ?? '' ), 'video_id' => $video ?: null, 'color' => in_array( $in['color'] ?? '', self::COLORS, true ) ? $in['color'] : 'violet', 'lane' => 'shorts' === ( $in['lane'] ?? '' ) ? 'shorts' : 'long', 'updated' => gmdate( 'Y-m-d H:i:s' ) );
		if ( $id ) { $ok = $wpdb->update( self::table(), $data, array( 'user_id' => TW_Context::user_id(), 'id' => $id ) ); }
		else { $data['created'] = $data['updated']; $ok = $wpdb->insert( self::table(), $data ); }
		return false === $ok ? new WP_Error( 'tw_plan', 'Could not save the plan. Please try again.' ) : true;
	}
	public static function remove_plan( $id ) {
		global $wpdb;
		return false !== $wpdb->delete( self::table(), array( 'user_id' => TW_Context::user_id(), 'id' => absint( $id ) ), array( '%d', '%d' ) );
	}
	public static function save_rhythms( array $in ) {
		$out = array();
		foreach ( array( 'long', 'shorts' ) as $lane ) {
			$r = (array) ( $in[ $lane ] ?? array() );
			$time = (string) ( $r['time'] ?? '07:30' );
			if ( ! preg_match( '/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $time ) ) { return new WP_Error( 'tw_time', 'Pick a valid rhythm time.' ); }
			$out[ $lane ] = array( 'days' => array_values( array_filter( array_unique( array_map( 'intval', (array) ( $r['days'] ?? array() ) ) ), function ( $d ) { return $d >= 0 && $d <= 6; } ) ), 'time' => $time );
		}
		TW_Store::set( 'rhythms', $out );
		return true;
	}
	public static function median( array $values ) {
		if ( ! $values ) { return 0; } sort( $values, SORT_NUMERIC ); $n = count( $values ); $mid = (int) floor( $n / 2 );
		return $n % 2 ? $values[ $mid ] : ( $values[ $mid - 1 ] + $values[ $mid ] ) / 2;
	}
	public static function heatmap( array $rows, $now, DateTimeZone $tz ) {
		$oldest = ( new DateTimeImmutable( '@' . $now ) )->modify( '-18 months' )->getTimestamp();
		$eligible = array();
		foreach ( $rows as $row ) {
			$ts = empty( $row['published_at'] ) ? 0 : strtotime( $row['published_at'] . ' UTC' );
			if ( 'public' === $row['privacy'] && $ts >= $oldest && $ts <= $now - 14 * DAY_IN_SECONDS ) { $row['ts'] = $ts; $eligible[] = $row; }
		}
		usort( $eligible, function ( $a, $b ) { return $a['ts'] <=> $b['ts']; } );
		$cells = array( 'long' => array_fill( 0, 84, array() ), 'shorts' => array_fill( 0, 84, array() ) );
		$start = 0; $end = 0; $n = count( $eligible );
		foreach ( $eligible as $row ) {
			while ( $start < $n && $eligible[ $start ]['ts'] < $row['ts'] - 60 * DAY_IN_SECONDS ) { ++$start; }
			while ( $end < $n && $eligible[ $end ]['ts'] <= $row['ts'] + 60 * DAY_IN_SECONDS ) { ++$end; }
			$cohort = array(); for ( $i = $start; $i < $end; ++$i ) { $cohort[] = (int) $eligible[ $i ]['views']; }
			$base = self::median( $cohort );
			if ( $base <= 0 ) { continue; }
			$d = ( new DateTimeImmutable( '@' . $row['ts'] ) )->setTimezone( $tz );
			$key = (int) $d->format( 'w' ) * 12 + (int) floor( (int) $d->format( 'G' ) / 2 );
			$cells[ (int) $row['duration'] <= 180 ? 'shorts' : 'long' ][ $key ][] = (int) $row['views'] / $base;
		}
		$out = array();
		foreach ( $cells as $lane => $grid ) {
			$out[ $lane ] = array();
			foreach ( $grid as $key => $scores ) { $out[ $lane ][] = array( 'day' => (int) floor( $key / 12 ), 'hour' => ( $key % 12 ) * 2, 'count' => count( $scores ), 'score' => count( $scores ) >= 3 ? round( self::median( $scores ), 2 ) : null ); }
		}
		return $out;
	}
	public static function best_times() {
		$full = (int) TW_Store::get( 'index_full', 0 );
		if ( ! $full ) { return array( 'synced' => '', 'cells' => null ); }
		$key = 'tw_best_' . TW_Context::user_id();
		$cached = get_transient( $key );
		if ( is_array( $cached ) && ( $cached['tz'] ?? '' ) === TW_Settings::timezone_string() && ( $cached['full'] ?? 0 ) === $full ) { return $cached; }
		$out = array( 'synced' => wp_date( 'M j, Y', $full, TW_Settings::user_timezone() ), 'full' => $full, 'tz' => TW_Settings::timezone_string(), 'cells' => self::heatmap( self::rows(), time(), TW_Settings::user_timezone() ) );
		set_transient( $key, $out, DAY_IN_SECONDS ); return $out;
	}

	/** A private capability URL. Only its HMAC and last four characters persist. */
	public static function feed_settings( $action, array $in ) {
		if ( 'off' === $action ) { TW_Store::delete( 'feed_hash' ); TW_Store::delete( 'feed_hint' ); return array( 'enabled' => false ); }
		TW_Store::set( 'feed_published', ! empty( $in['published'] ) ); TW_Store::set( 'feed_notes', ! empty( $in['notes'] ) );
		if ( 'create' === $action || 'rotate' === $action ) {
			$token = bin2hex( random_bytes( 32 ) );
			TW_Store::set( 'feed_hash', hash_hmac( 'sha256', $token, wp_salt( 'auth' ) ) );
			TW_Store::set( 'feed_hint', substr( $token, -4 ) );
			return array( 'enabled' => true, 'url' => home_url( '/tubewriter/feed/' . $token . '.ics' ) );
		}
		return array( 'enabled' => (bool) TW_Store::get( 'feed_hash' ) );
	}
	public static function ics_escape( $text ) { return str_replace( array( "\\", "\r", "\n", ',', ';' ), array( "\\\\", '', '\\n', '\\,', '\\;' ), (string) $text ); }
	public static function ics_fold( $line ) {
		$out = ''; $limit = 75;
		while ( strlen( $line ) > $limit ) { $part = mb_strcut( $line, 0, $limit, 'UTF-8' ); $out .= $part . "\r\n "; $line = substr( $line, strlen( $part ) ); $limit = 74; }
		return $out . $line;
	}
	public static function feed_content() {
		$today = new DateTimeImmutable( 'today', TW_Settings::user_timezone() );
		$from = $today->modify( '-30 days' )->getTimestamp(); $to = $today->modify( '+121 days' )->getTimestamp();
		$lines = array( 'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//UploadWriter//Publishing calendar//EN', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH', 'X-WR-CALNAME:UploadWriter' );
		// The data stays local and is scoped to the owner selected by the token.
		foreach ( self::workspace( true )['events'] as $event ) {
			if ( $event['at'] < $from || $event['at'] >= $to || ( 'published' === $event['type'] && ! TW_Store::get( 'feed_published' ) ) ) { continue; }
			$video = 'planned' === $event['type'] ? ( $event['plan']['video_id'] ?? '' ) : $event['id'];
			$uid = 'tw-' . TW_Context::user_id() . '-' . $event['id'] . '@uploadwriter';
			$lines[] = 'BEGIN:VEVENT'; $lines[] = 'UID:' . $uid;
			$lines[] = 'DTSTAMP:' . gmdate( 'Ymd\THis\Z' ); $lines[] = 'DTSTART:' . gmdate( 'Ymd\THis\Z', $event['at'] );
			$lines[] = 'DTEND:' . gmdate( 'Ymd\THis\Z', $event['at'] + 30 * MINUTE_IN_SECONDS );
			$lines[] = 'SUMMARY:' . self::ics_escape( '[' . ucfirst( $event['type'] ) . '] ' . $event['title'] );
			$lines[] = 'URL:' . self::ics_escape( TW_Frontend::url( $video ? 'account' : 'schedule' ) . ( $video ? '?video=' . rawurlencode( $video ) : '' ) );
			if ( 'planned' === $event['type'] && TW_Store::get( 'feed_notes' ) && ! empty( $event['plan']['notes'] ) ) { $lines[] = 'DESCRIPTION:' . self::ics_escape( $event['plan']['notes'] ); }
			$lines[] = 'END:VEVENT';
		}
		$lines[] = 'END:VCALENDAR'; return implode( "\r\n", array_map( array( __CLASS__, 'ics_fold' ), $lines ) ) . "\r\n";
	}
	public static function serve_feed() {
		$path = wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH );
		$prefix = wp_parse_url( home_url( '/tubewriter/feed/' ), PHP_URL_PATH );
		if ( 0 !== strpos( (string) $path, $prefix ) ) { return; }
		if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
		nocache_headers(); header( 'Cache-Control: private, no-store, max-age=0' ); header( 'X-Robots-Tag: noindex' ); header( 'X-Accel-Expires: 0' ); header( 'Surrogate-Control: no-store' );
		$token = substr( $path, strlen( $prefix ) );
		if ( ! preg_match( '/^([a-f0-9]{64})\.ics$/D', $token, $m ) ) { self::feed_404(); }
		global $wpdb;
		$hash = hash_hmac( 'sha256', $m[1], wp_salt( 'auth' ) );
		$uid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1", 'tw_feed_hash', $hash ) );
		if ( ! $uid || ! user_can( $uid, 'read' ) ) { self::feed_404(); }
		TW_Context::set_user( $uid );
		if ( ! hash_equals( (string) TW_Store::get( 'feed_hash' ), $hash ) ) { self::feed_404(); }
		// Serialize the counter so concurrent pollers cannot bypass the hourly limit.
		$key = 'tw_feed_' . $uid . '_' . substr( $hash, 0, 16 ); $lock = $key . '_lock';
		if ( ! add_option( $lock, time(), '', false ) ) {
			if ( (int) get_option( $lock ) < time() - 10 ) { delete_option( $lock ); }
			status_header( 429 ); header( 'Retry-After: 60' ); exit( 'Try again later.' );
		}
		$count = get_transient( $key );
		if ( ! is_array( $count ) || $count['until'] <= time() ) { $count = array( 'n' => 0, 'until' => time() + HOUR_IN_SECONDS ); }
		$count['n']++; set_transient( $key, $count, max( 1, $count['until'] - time() ) ); delete_option( $lock );
		if ( $count['n'] > 60 ) { status_header( 429 ); header( 'Retry-After: ' . max( 1, $count['until'] - time() ) ); exit( 'Too many requests.' ); }
		status_header( 200 ); header( 'Content-Type: text/calendar; charset=utf-8' );
		echo self::feed_content(); exit;
	}
	private static function feed_404() { status_header( 404 ); header( 'Content-Type: text/plain; charset=utf-8' ); exit( 'Not found.' ); }
}
