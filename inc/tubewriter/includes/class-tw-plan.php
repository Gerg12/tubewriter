<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Review, publish and undo.
 *
 * Safety model:
 * - Review compares the live video on YouTube with what TubeWriter will write, field by field.
 * - Publish refuses to run if the live video changed after Review (fingerprint check).
 * - A backup of the live details is saved BEFORE anything is written.
 * - TubeWriter never deletes videos, playlists or playlist entries. It only updates and adds.
 */
class TW_Plan {

	/**
	 * Build what will happen, without changing anything.
	 *
	 * @param array $video Live video resource.
	 * @param array $pkg   Package from the editor.
	 * @param array $opts  language, category, playlist{}, schedule{}.
	 * @return array{errors: string[], warnings: string[], rows: array, actions: string[], details: array, schedule: array, playlist: array, fingerprint: string, current: array}
	 */
	public static function build( array $video, array $pkg, array $opts, $duration ) {
		$current = TW_YouTube::snapshot( $video );
		$check   = TW_Validator::check( $pkg, $duration );
		$errors  = $check['errors'];
		$warn    = $check['warnings'];

		$lang = (string) ( $opts['language'] ?? TW_Settings::get( 'default_language' ) );
		if ( ! isset( TW_Settings::LANGUAGES[ $lang ] ) ) {
			$lang = 'en';
		}
		$cat = (string) ( $opts['category'] ?? TW_Settings::get( 'default_category' ) );
		if ( ! isset( TW_Settings::CATEGORIES[ $cat ] ) ) {
			$cat = $current['categoryId'] ?: '20';
		}

		$details = array(
			'title'                => $check['pkg']['title'],
			'description'          => $check['description'],
			'tags'                 => $check['pkg']['tags'],
			'categoryId'           => $cat,
			'defaultLanguage'      => $lang,
			'defaultAudioLanguage' => $lang,
		);

		// Go live: keep, schedule for later, or publish now.
		$schedule = array( 'mode' => 'keep', 'at' => '', 'label' => '' );
		$sched_in = (array) ( $opts['schedule'] ?? array() );
		$sched_mode = (string) ( $sched_in['mode'] ?? 'keep' );
		if ( 'now' === $sched_mode ) {
			if ( 'private' !== $current['privacyStatus'] ) {
				$errors[] = 'This video is ' . $current['privacyStatus'] . ', so it can\'t be published now from here. Set Go live to "Leave as is".';
			} else {
				$schedule['mode']  = 'now';
				$schedule['label'] = 'Public now';
			}
		} elseif ( 'set' === $sched_mode ) {
			if ( 'private' !== $current['privacyStatus'] ) {
				$errors[] = 'This video is ' . $current['privacyStatus'] . ', so it can\'t be scheduled. Set Go live to "Leave as is".';
			} else {
				try {
					$dt = new DateTime( trim( ( $sched_in['date'] ?? '' ) . ' ' . ( $sched_in['time'] ?? '' ) ), wp_timezone() );
					if ( $dt->getTimestamp() <= time() + 5 * MINUTE_IN_SECONDS ) {
						$errors[] = 'The schedule time is in the past or less than 5 minutes away. Pick a later time.';
					} else {
						$schedule['label'] = wp_date( 'D M j, Y g:ia T', $dt->getTimestamp() );
						$dt->setTimezone( new DateTimeZone( 'UTC' ) );
						$schedule['mode'] = 'set';
						$schedule['at']   = $dt->format( 'Y-m-d\TH:i:s\Z' );
					}
				} catch ( Exception $e ) {
					$errors[] = 'Pick a date and time for the schedule.';
				}
			}
		}

		// Playlist.
		$playlist = array( 'mode' => 'none' );
		$pl_in    = (array) ( $opts['playlist'] ?? array() );
		$pl_mode  = $pl_in['mode'] ?? 'none';
		if ( 'existing' === $pl_mode ) {
			if ( empty( $pl_in['id'] ) ) {
				$errors[] = 'Pick a playlist, or set Playlist to "Don\'t add to a playlist".';
			} else {
				$playlist = array( 'mode' => 'existing', 'id' => sanitize_text_field( $pl_in['id'] ), 'title' => sanitize_text_field( $pl_in['title'] ?? '' ) );
			}
		} elseif ( 'new' === $pl_mode ) {
			$pl_title = TW_Validator::clean_text( trim( (string) ( $pl_in['new_title'] ?? '' ) ) );
			if ( '' === $pl_title ) {
				$errors[] = 'Give the new playlist a title.';
			} elseif ( mb_strlen( $pl_title ) > 150 ) {
				$errors[] = 'Playlist titles max out at 150 characters.';
			} else {
				$playlist = array(
					'mode'        => 'new',
					'title'       => $pl_title,
					'description' => TW_Validator::clean_text( (string) ( $pl_in['new_desc'] ?? '' ) ),
					'privacy'     => in_array( $pl_in['privacy'] ?? '', array( 'public', 'unlisted', 'private' ), true ) ? $pl_in['privacy'] : 'public',
				);
			}
		}

		// Field-by-field comparison.
		$lang_name = function ( $code ) {
			return '' === $code ? '(not set)' : ( TW_Settings::LANGUAGES[ $code ] ?? $code );
		};
		$cat_name = function ( $id ) {
			return TW_Settings::CATEGORIES[ $id ] ?? ( 'Category ' . $id );
		};
		$current_sched = $current['publishAt'] ? wp_date( 'D M j, Y g:ia T', strtotime( $current['publishAt'] ) ) : ( 'private' === $current['privacyStatus'] ? 'Private, not scheduled' : ucfirst( $current['privacyStatus'] ) );
		$next_sched    = $current_sched;
		if ( 'now' === $schedule['mode'] ) {
			$next_sched = 'Public now';
		} elseif ( 'set' === $schedule['mode'] ) {
			$next_sched = 'Goes public ' . $schedule['label'];
		}

		$rows = array(
			self::row( 'title', 'Title', $current['title'], $details['title'] ),
			self::row( 'description', 'Description', $current['description'], $details['description'] ),
			self::row( 'tags', 'Tags', implode( ', ', $current['tags'] ), implode( ', ', $details['tags'] ) ),
			self::row( 'category', 'Category', $cat_name( $current['categoryId'] ), $cat_name( $cat ) ),
			self::row( 'language', 'Video language', $lang_name( $current['defaultLanguage'] ), $lang_name( $lang ) ),
			self::row( 'audio', 'Audio language', $lang_name( $current['defaultAudioLanguage'] ), $lang_name( $lang ) ),
			self::row( 'schedule', 'Go live', $current_sched, $next_sched ),
		);
		$pl_text = 'Not added to a playlist';
		if ( 'existing' === $playlist['mode'] ) {
			$pl_text = 'Add to "' . $playlist['title'] . '"';
		} elseif ( 'new' === $playlist['mode'] ) {
			$pl_text = 'Create ' . $playlist['privacy'] . ' playlist "' . $playlist['title'] . '" and add this video';
		}
		$rows[] = array(
			'key'     => 'playlist',
			'label'   => 'Playlist',
			'current' => '(no change)',
			'next'    => $pl_text,
			'changed' => 'none' !== $playlist['mode'],
		);

		// Plain English action list for the confirm step.
		$actions = array();
		foreach ( $rows as $r ) {
			if ( ! $r['changed'] ) {
				continue;
			}
			switch ( $r['key'] ) {
				case 'title':
					$actions[] = 'Replace the title with "' . $details['title'] . '"';
					break;
				case 'description':
					$actions[] = 'Replace the whole description (' . strlen( $details['description'] ) . ' bytes)';
					break;
				case 'tags':
					$actions[] = 'Replace the tags with ' . count( $details['tags'] ) . ' new tags';
					break;
				case 'category':
					$actions[] = 'Set category to ' . $cat_name( $cat );
					break;
				case 'language':
					$actions[] = 'Set video language to ' . $lang_name( $lang );
					break;
				case 'audio':
					$actions[] = 'Set audio language to ' . $lang_name( $lang );
					break;
				case 'schedule':
					$actions[] = 'now' === $schedule['mode']
						? 'Make it public now'
						: 'Schedule it to go public ' . $schedule['label'];
					break;
				case 'playlist':
					$actions[] = $pl_text;
					break;
			}
		}
		if ( ! $actions ) {
			$warn[] = 'Nothing would change. Everything already matches YouTube.';
		}

		return array(
			'errors'      => array_values( array_unique( $errors ) ),
			'warnings'    => array_values( array_unique( $warn ) ),
			'rows'        => $rows,
			'actions'     => $actions,
			'details'     => $details,
			'schedule'    => $schedule,
			'playlist'    => $playlist,
			'fingerprint' => TW_YouTube::fingerprint( $current ),
			'current'     => $current,
			'pkg'         => $check['pkg'],
		);
	}

	private static function row( $key, $label, $current, $next ) {
		return array(
			'key'     => $key,
			'label'   => $label,
			'current' => (string) $current,
			'next'    => (string) $next,
			'changed' => (string) $current !== (string) $next,
		);
	}

	/**
	 * Publish. Re-checks everything against the live video, backs it up, then writes.
	 *
	 * @return array{ok: bool, results: array, backup: string, message: string}|WP_Error
	 */
	public static function publish( $video_id, array $pkg, array $opts, $duration, $fingerprint ) {
		$video = TW_YouTube::get_video( $video_id );
		if ( is_wp_error( $video ) ) {
			return $video;
		}
		$plan = self::build( $video, $pkg, $opts, $duration );

		if ( ! hash_equals( $plan['fingerprint'], (string) $fingerprint ) ) {
			return new WP_Error( 'tw_stale', 'This video changed on YouTube after you reviewed it (maybe in Studio). Nothing was changed. Go back to Review to see the latest, then publish again.' );
		}
		if ( $plan['errors'] ) {
			return new WP_Error( 'tw_plan', implode( ' ', $plan['errors'] ) );
		}
		if ( ! $plan['actions'] ) {
			return new WP_Error( 'tw_plan', 'Nothing to change. Everything already matches YouTube.' );
		}

		// 1. Backup first. If this fails we stop before touching YouTube.
		$backup = TW_History::start( $video_id, $plan['details']['title'], 'publish', $plan['current'], $plan['actions'] );
		if ( ! TW_History::get( $video_id, $backup ) ) {
			return new WP_Error( 'tw_backup', 'Could not save a backup, so nothing was changed.' );
		}

		$results = array();
		$ok      = true;

		// 2. Video details and schedule.
		$write = TW_YouTube::write_video( $video, $plan['details'], $plan['schedule']['mode'], $plan['schedule']['at'] );
		if ( is_wp_error( $write ) ) {
			$results[] = array( 'ok' => false, 'text' => 'Video details NOT updated: ' . $write->get_error_message() );
			$ok        = false;
		} else {
			$results[] = array( 'ok' => true, 'text' => 'Title, description, tags, category and language updated' );
			if ( 'now' === $plan['schedule']['mode'] ) {
				$results[] = array( 'ok' => true, 'text' => 'Made public on YouTube' );
			} elseif ( 'set' === $plan['schedule']['mode'] ) {
				$results[] = array( 'ok' => true, 'text' => 'Scheduled to go public ' . $plan['schedule']['label'] );
			}
		}

		// 3. Playlist, only if the video update worked.
		if ( $ok && 'none' !== $plan['playlist']['mode'] ) {
			$pl_id    = $plan['playlist']['id'] ?? '';
			$pl_title = $plan['playlist']['title'];
			if ( 'new' === $plan['playlist']['mode'] ) {
				$created = TW_YouTube::create_playlist( $pl_title, $plan['playlist']['description'], $plan['playlist']['privacy'] );
				if ( is_wp_error( $created ) ) {
					$results[] = array( 'ok' => false, 'text' => 'Playlist NOT created: ' . $created->get_error_message() );
					$ok        = false;
					$pl_id     = '';
				} else {
					$pl_id     = $created['id'];
					$results[] = array( 'ok' => true, 'text' => 'Created playlist "' . $pl_title . '"' );
				}
			}
			if ( $pl_id ) {
				$added = TW_YouTube::add_to_playlist( $pl_id, $video_id );
				if ( is_wp_error( $added ) ) {
					$results[] = array( 'ok' => false, 'text' => 'Not added to playlist: ' . $added->get_error_message() );
					$ok        = false;
				} else {
					$results[] = array( 'ok' => true, 'text' => 'already' === $added ? 'Already in "' . $pl_title . '", left as is' : 'Added to "' . $pl_title . '"' );
				}
			}
		}

		TW_History::finish( $video_id, $backup, $results, $ok ? 'done' : 'partial' );

		return array(
			'ok'      => $ok,
			'results' => $results,
			'backup'  => $backup,
			'message' => $ok ? 'Published. A backup of the old details was saved first.' : 'Some steps failed. See below. A backup of the old details was saved first.',
		);
	}

	/**
	 * Put a backed-up version back. The current live details are backed up first too,
	 * so a restore can itself be undone. Playlists are left alone (TubeWriter never removes).
	 */
	public static function restore( $video_id, $entry_id ) {
		$entry = TW_History::get( $video_id, $entry_id );
		if ( ! $entry ) {
			return new WP_Error( 'tw_restore', 'That backup was not found.' );
		}
		$video = TW_YouTube::get_video( $video_id );
		if ( is_wp_error( $video ) ) {
			return $video;
		}
		$live   = TW_YouTube::snapshot( $video );
		$before = $entry['before'];

		$details = array(
			'title'                => $before['title'],
			'description'          => $before['description'],
			'tags'                 => $before['tags'],
			'categoryId'           => $before['categoryId'],
			'defaultLanguage'      => $before['defaultLanguage'] ?: null,
			'defaultAudioLanguage' => $before['defaultAudioLanguage'] ?: null,
		);

		$schedule = 'keep';
		$at       = '';
		$notes    = array();
		if ( 'private' === $live['privacyStatus'] && 'private' === $before['privacyStatus'] && $live['publishAt'] !== $before['publishAt'] ) {
			if ( '' === $before['publishAt'] ) {
				$schedule = 'clear';
			} elseif ( strtotime( $before['publishAt'] ) > time() + 5 * MINUTE_IN_SECONDS ) {
				$schedule = 'set';
				$at       = $before['publishAt'];
			} else {
				$notes[] = 'The old schedule time has already passed, so the current schedule was left alone.';
			}
		} elseif ( 'private' === $before['privacyStatus'] && in_array( $live['privacyStatus'], array( 'public', 'unlisted' ), true ) ) {
			// Undo a "Publish now". YouTube will not let a once-public video be scheduled again.
			$schedule = 'clear';
			if ( $before['publishAt'] ) {
				$notes[] = 'Made it private again. The old schedule was not put back, because YouTube will not schedule a video that has already been public.';
			}
		} elseif ( $live['privacyStatus'] !== $before['privacyStatus'] ) {
			$notes[] = 'The video is ' . $live['privacyStatus'] . ' now, so its visibility and schedule were left alone.';
		}

		$changes = array( 'Restore the title, description, tags, category and language from ' . wp_date( 'M j g:ia', $entry['time'] ) );
		if ( 'clear' === $schedule ) {
			if ( in_array( $live['privacyStatus'], array( 'public', 'unlisted' ), true ) ) {
				$changes[] = 'Make it private again (not scheduled)';
			} else {
				$changes[] = 'Remove the schedule (back to private, not scheduled)';
			}
		} elseif ( 'set' === $schedule ) {
			$changes[] = 'Put the old schedule back';
		}
		$backup = TW_History::start( $video_id, $before['title'], 'restore', $live, $changes );

		$write   = TW_YouTube::write_video( $video, $details, $schedule, $at );
		$results = array();
		if ( is_wp_error( $write ) ) {
			$results[] = array( 'ok' => false, 'text' => 'Restore failed: ' . $write->get_error_message() );
		} else {
			$results[] = array( 'ok' => true, 'text' => 'Restored the version from ' . wp_date( 'M j g:ia', $entry['time'] ) );
		}
		foreach ( $notes as $n ) {
			$results[] = array( 'ok' => true, 'text' => $n );
		}
		$results[] = array( 'ok' => true, 'text' => 'Playlists were not touched. Remove the video from a playlist in Studio if needed.' );
		TW_History::finish( $video_id, $backup, $results, is_wp_error( $write ) ? 'failed' : 'done' );

		return array( 'ok' => ! is_wp_error( $write ), 'results' => $results, 'backup' => $backup );
	}
}
