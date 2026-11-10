<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enforces YouTube's rules before anything gets pushed:
 * chapters start at 0:00, 3+ chapters, 10s apart, title <= 100 chars,
 * description <= 5000 bytes, tags <= 500 chars and max 8 (aim 4 to 6),
 * hashtags max 3, no angle brackets. Extra tag and description checks are warnings.
 */
class TW_Validator {

	const TITLE_MAX     = 100;
	const DESC_MAX      = 5000;
	const TAGS_MAX      = 500;
	const TAGS_SOFT_MAX = 6;
	const TAGS_HARD_MAX = 8;
	const HASH_MAX      = 3;

	public static function parse_time( $str ) {
		$str = trim( (string) $str );
		if ( ! preg_match( '/^(?:(\d+):)?(\d{1,2}):(\d{2})$/', $str, $m ) ) {
			return null;
		}
		$h = '' === $m[1] ? 0 : (int) $m[1];
		return $h * 3600 + (int) $m[2] * 60 + (int) $m[3];
	}

	public static function fmt_time( $seconds, $long ) {
		$h = intdiv( $seconds, 3600 );
		$m = intdiv( $seconds % 3600, 60 );
		$s = $seconds % 60;
		return $long ? sprintf( '%d:%02d:%02d', $h, $m, $s ) : sprintf( '%d:%02d', $m, $s );
	}

	/** "0:00 Label" lines to chapter arrays. Lines that don't start with a timestamp are ignored. */
	public static function chapters_from_text( $text ) {
		$out = array();
		foreach ( preg_split( '/\R/', (string) $text ) as $line ) {
			$line = trim( $line );
			if ( preg_match( '/^((?:\d+:)?\d{1,2}:\d{2})\s*[-–:]?\s*(.+)$/u', $line, $m ) ) {
				$out[] = array( 'time' => $m[1], 'label' => trim( $m[2] ) );
			}
		}
		return $out;
	}

	public static function chapters_to_text( array $chapters ) {
		$lines = array();
		foreach ( $chapters as $c ) {
			$lines[] = $c['time'] . ' ' . $c['label'];
		}
		return implode( "\n", $lines );
	}

	/**
	 * Repair chapters so YouTube accepts them.
	 *
	 * @return array{chapters: array, notes: string[]}
	 */
	public static function fix_chapters( array $chapters, $duration = 0 ) {
		$notes = array();
		$long  = $duration >= 3600;
		$items = array();

		foreach ( $chapters as $c ) {
			$t     = self::parse_time( $c['time'] ?? '' );
			$label = trim( (string) ( $c['label'] ?? '' ) );
			if ( null === $t || '' === $label ) {
				$notes[] = 'Dropped a chapter with a bad timestamp or empty label.';
				continue;
			}
			if ( $duration && $t >= $duration ) {
				$notes[] = 'Dropped "' . $label . '" because it starts after the video ends.';
				continue;
			}
			$items[] = array( 't' => $t, 'label' => self::clean_text( $label ) );
			if ( $t > 3600 ) {
				$long = true;
			}
		}

		usort(
			$items,
			function ( $a, $b ) {
				return $a['t'] <=> $b['t'];
			}
		);

		if ( $items ) {
			if ( $items[0]['t'] > 0 && $items[0]['t'] < 10 ) {
				$items[0]['t'] = 0;
				$notes[]       = 'Moved the first chapter to 0:00 (YouTube requires it).';
			} elseif ( $items[0]['t'] >= 10 ) {
				array_unshift( $items, array( 't' => 0, 'label' => 'Intro' ) );
				$notes[] = 'Added "0:00 Intro" (YouTube ignores chapters without a 0:00 start).';
			}
		}

		$kept = array();
		foreach ( $items as $item ) {
			if ( $kept && $item['t'] - end( $kept )['t'] < 10 ) {
				$notes[] = 'Dropped "' . $item['label'] . '" because it was under 10 seconds after the previous chapter.';
				continue;
			}
			$kept[] = $item;
		}

		if ( $duration && $kept && $duration - end( $kept )['t'] < 10 && count( $kept ) > 1 ) {
			$last    = array_pop( $kept );
			$notes[] = 'Dropped "' . $last['label'] . '" because the last chapter must run at least 10 seconds.';
		}

		$out = array();
		foreach ( $kept as $k ) {
			$out[] = array( 'time' => self::fmt_time( $k['t'], $long ), 'label' => $k['label'] );
		}
		return array( 'chapters' => $out, 'notes' => array_values( array_unique( $notes ) ) );
	}

	/** YouTube rejects < and >. Typographic dashes are swapped to a plain hyphen. */
	public static function clean_text( $text ) {
		$text = str_replace( array( '<', '>' ), array( '‹', '›' ), (string) $text );
		// En dash, em dash, figure dash, horizontal bar. Never leave these in titles or copy.
		$text = preg_replace( '/\s*[–—‒―]\s*/u', ' - ', $text );
		return is_string( $text ) ? $text : '';
	}

	/**
	 * Clean tags: drop generics and near-duplicates, cap at 8, keep under 500 characters.
	 *
	 * @return array{tags: string[], removed_generic: string[], removed_duplicate: string[], trimmed: string[]}
	 */
	public static function clean_tags( array $tags ) {
		$generic = array(
			'gaming',
			'games',
			'game',
			'video',
			'videos',
			'youtube',
			'funny',
			'fun',
			'epic',
			'lol',
			'pc gaming',
			'gameplay',
		);
		$out               = array();
		$norms             = array();
		$removed_generic   = array();
		$removed_duplicate = array();
		$trimmed           = array();
		$len               = 0;

		foreach ( $tags as $tag ) {
			$tag = trim( self::clean_text( str_replace( array( ',', '#' ), '', (string) $tag ) ) );
			if ( '' === $tag ) {
				continue;
			}
			$lower = mb_strtolower( $tag );
			if ( in_array( $lower, $generic, true ) ) {
				$removed_generic[] = $tag;
				continue;
			}
			// Near-duplicates that only differ by spaces or case (spacemarine2 vs Space Marine 2).
			$norm = preg_replace( '/\s+/u', '', $lower );
			if ( isset( $norms[ $norm ] ) ) {
				$removed_duplicate[] = $tag;
				continue;
			}
			if ( count( $out ) >= self::TAGS_HARD_MAX ) {
				$trimmed[] = $tag;
				continue;
			}
			// YouTube counts quotes around tags that contain spaces, plus the commas between tags.
			$cost = mb_strlen( $tag ) + ( false !== strpos( $tag, ' ' ) ? 2 : 0 ) + ( $out ? 1 : 0 );
			if ( $len + $cost > self::TAGS_MAX ) {
				$trimmed[] = $tag;
				continue;
			}
			$out[]            = $tag;
			$norms[ $norm ]   = true;
			$len             += $cost;
		}

		return array(
			'tags'              => $out,
			'removed_generic'   => $removed_generic,
			'removed_duplicate' => $removed_duplicate,
			'trimmed'           => $trimmed,
		);
	}

	/**
	 * Clean hashtags: unique, keep order, cap at 3.
	 *
	 * @return array{hashtags: string[], trimmed: string[]}
	 */
	public static function clean_hashtags( array $tags ) {
		$out = array();
		foreach ( $tags as $tag ) {
			$tag = preg_replace( '/[^\p{L}\p{N}_]/u', '', (string) $tag );
			if ( '' !== $tag ) {
				$out[] = '#' . $tag;
			}
		}
		$unique  = array_values( array_unique( $out ) );
		$kept    = array_slice( $unique, 0, self::HASH_MAX );
		$trimmed = array_slice( $unique, self::HASH_MAX );
		return array(
			'hashtags' => $kept,
			'trimmed'  => $trimmed,
		);
	}

	public static function assemble_description( array $pkg ) {
		$parts = array();
		if ( '' !== trim( $pkg['body'] ?? '' ) ) {
			$parts[] = trim( $pkg['body'] );
		}
		if ( ! empty( $pkg['chapters'] ) ) {
			$block = '';
			if ( '' !== trim( $pkg['chapters_heading'] ?? '' ) ) {
				$block .= trim( $pkg['chapters_heading'] ) . "\n\n";
			}
			$block  .= self::chapters_to_text( $pkg['chapters'] );
			$parts[] = $block;
		}
		if ( ! empty( $pkg['hashtags'] ) ) {
			$parts[] = implode( ' ', $pkg['hashtags'] );
		}
		return self::clean_text( implode( "\n\n", $parts ) );
	}

	/**
	 * Full check before push. Errors block the push, warnings don't.
	 *
	 * @return array{pkg: array, errors: string[], warnings: string[]}
	 */
	public static function check( array $pkg, $duration = 0 ) {
		$errors   = array();
		$warnings = array();

		$fixed           = self::fix_chapters( $pkg['chapters'] ?? array(), $duration );
		$pkg['chapters'] = $fixed['chapters'];
		$warnings        = array_merge( $warnings, $fixed['notes'] );

		$tag_clean             = self::clean_tags( $pkg['tags'] ?? array() );
		$pkg['tags']           = $tag_clean['tags'];
		$hash_clean            = self::clean_hashtags( $pkg['hashtags'] ?? array() );
		$pkg['hashtags']       = $hash_clean['hashtags'];
		$pkg['title']            = trim( self::clean_text( $pkg['title'] ?? '' ) );
		$pkg['body']             = self::clean_text( trim( (string) ( $pkg['body'] ?? '' ) ) );
		$pkg['chapters_heading'] = self::clean_text( trim( (string) ( $pkg['chapters_heading'] ?? '' ) ) );

		if ( $tag_clean['removed_generic'] ) {
			$warnings[] = 'Dropped generic tags: ' . implode( ', ', $tag_clean['removed_generic'] ) . '.';
		}
		if ( $tag_clean['removed_duplicate'] ) {
			$warnings[] = 'Dropped near-duplicate tags: ' . implode( ', ', $tag_clean['removed_duplicate'] ) . '.';
		}
		if ( $tag_clean['trimmed'] ) {
			$warnings[] = 'Kept the first ' . self::TAGS_HARD_MAX . ' tags and dropped: ' . implode( ', ', $tag_clean['trimmed'] ) . '.';
		}
		if ( count( $pkg['tags'] ) > self::TAGS_SOFT_MAX ) {
			$warnings[] = 'You have ' . count( $pkg['tags'] ) . ' tags. Aim for 4 to 6.';
		}
		if ( $hash_clean['trimmed'] ) {
			$warnings[] = 'Only ' . self::HASH_MAX . ' hashtags show above the title. Dropped: ' . implode( ' ', $hash_clean['trimmed'] ) . '.';
		}

		$body = trim( (string) ( $pkg['body'] ?? '' ) );
		if ( '' !== $body ) {
			$start = ltrim( $body );
			if ( preg_match( '/^⚠️\s*THE GG ALGORITHM WARNING/u', $start ) || 0 === stripos( $start, 'THE GG ALGORITHM WARNING' ) ) {
				$warnings[] = 'Description opens with the warning block. Lead with the game and what happens.';
			}
			if ( preg_match( '/^(https?:\/\/|www\.)/i', $start ) ) {
				$warnings[] = 'Description opens with a link. Lead with the game and what happens.';
			}
			if ( false !== stripos( $body, 'THE GG ALGORITHM WARNING' )
				&& false === stripos( $body, 'The slop will continue until morale improves' ) ) {
				$warnings[] = 'The GG Algorithm Warning looks truncated. It must end with "The slop will continue until morale improves." Generate again or paste the rest.';
			}
			foreach ( preg_split( '/\R/', $body ) as $line ) {
				if ( substr_count( $line, ',' ) >= 8 ) {
					$warnings[] = 'A description line has 8 or more commas. That looks like a keyword list.';
					break;
				}
			}
			foreach ( preg_split( '/\R/', $body ) as $line ) {
				if ( preg_match( '/^\s*(Tags|Keywords)\s*:/i', $line ) ) {
					$warnings[] = 'Description has a Tags: or Keywords: line. That is against YouTube spam rules.';
					break;
				}
			}
		}

		if ( $pkg['chapters'] && count( $pkg['chapters'] ) < 3 ) {
			$warnings[] = 'Fewer than 3 chapters, so YouTube will not show chapters for this video.';
		}
		if ( '' === $pkg['title'] ) {
			$errors[] = 'Title is empty.';
		} elseif ( mb_strlen( $pkg['title'] ) > self::TITLE_MAX ) {
			$errors[] = 'Title is ' . mb_strlen( $pkg['title'] ) . ' characters. YouTube allows 100.';
		}

		$description = self::assemble_description( $pkg );
		$desc_bytes  = strlen( $description );
		if ( $desc_bytes > self::DESC_MAX ) {
			$errors[] = 'Description is ' . $desc_bytes . ' bytes. YouTube allows 5000 (emoji count as 4).';
		} elseif ( $desc_bytes > 4500 ) {
			$warnings[] = 'Description is ' . $desc_bytes . ' bytes, close to YouTube\'s 5,000 byte limit.';
		}

		return array( 'pkg' => $pkg, 'description' => $description, 'errors' => $errors, 'warnings' => $warnings );
	}
}
