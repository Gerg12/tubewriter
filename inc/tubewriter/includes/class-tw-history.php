<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Backups and the change log. Before TubeWriter changes a video it stores the video's
 * current details here, so any push can be undone from the editor.
 */
class TW_History {

	const PER_VIDEO = 25;
	const LOG_MAX   = 300;

	private static function key( $video_id ) {
		return 'history_' . preg_replace( '/[^A-Za-z0-9_-]/', '', $video_id );
	}

	/** @return array<int, array> Newest first. */
	public static function for_video( $video_id ) {
		$list = TW_Store::get( self::key( $video_id ), array() );
		return is_array( $list ) ? $list : array();
	}

	public static function get( $video_id, $entry_id ) {
		foreach ( self::for_video( $video_id ) as $entry ) {
			if ( $entry['id'] === $entry_id ) {
				return $entry;
			}
		}
		return null;
	}

	/**
	 * Save a backup before anything is written. Returns the entry id.
	 *
	 * @param string $action  'publish' or 'restore'.
	 * @param array  $before  TW_YouTube::snapshot() of the live video.
	 * @param array  $changes Plain English list of what is about to happen.
	 */
	public static function start( $video_id, $title, $action, array $before, array $changes ) {
		$entry = array(
			'id'      => wp_generate_password( 10, false ),
			'time'    => time(),
			'action'  => $action,
			'title'   => $title,
			'before'  => $before,
			'changes' => $changes,
			'results' => array(),
			'status'  => 'started',
		);
		$list = self::for_video( $video_id );
		array_unshift( $list, $entry );
		TW_Store::set( self::key( $video_id ), array_slice( $list, 0, self::PER_VIDEO ) );
		return $entry['id'];
	}

	public static function finish( $video_id, $entry_id, array $results, $status ) {
		$list = self::for_video( $video_id );
		foreach ( $list as &$entry ) {
			if ( $entry['id'] === $entry_id ) {
				$entry['results'] = $results;
				$entry['status']  = $status;
				self::log( $video_id, $entry );
				break;
			}
		}
		unset( $entry );
		TW_Store::set( self::key( $video_id ), $list );
	}

	private static function log( $video_id, array $entry ) {
		$log = TW_Store::get( 'log', array() );
		$log = is_array( $log ) ? $log : array();
		array_unshift(
			$log,
			array(
				'time'    => $entry['time'],
				'video'   => $video_id,
				'title'   => $entry['title'],
				'action'  => $entry['action'],
				'status'  => $entry['status'],
				'changes' => $entry['changes'],
			)
		);
		TW_Store::set( 'log', array_slice( $log, 0, self::LOG_MAX ) );
	}

	public static function log_entries() {
		$log = TW_Store::get( 'log', array() );
		return is_array( $log ) ? $log : array();
	}

	/** Trimmed list for the editor's version history panel. */
	public static function summary( $video_id ) {
		$out = array();
		foreach ( self::for_video( $video_id ) as $e ) {
			$out[] = array(
				'id'      => $e['id'],
				'when'    => wp_date( 'M j, Y g:ia', $e['time'] ),
				'action'  => $e['action'],
				'status'  => $e['status'],
				'title'   => $e['before']['title'] ?? '',
				'changes' => $e['changes'],
			);
		}
		return $out;
	}
}
