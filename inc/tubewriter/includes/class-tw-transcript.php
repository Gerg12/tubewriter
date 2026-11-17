<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns SRT / VTT captions into compact "m:ss text" lines the AI can chapter from.
 */
class TW_Transcript {

	/**
	 * Normalize anything pasted or downloaded. SRT and VTT get converted, plain text passes through.
	 */
	public static function normalize( $text, $group_seconds = 15 ) {
		$text = str_replace( array( "\r\n", "\r" ), "\n", (string) $text );
		if ( preg_match( '/\d{1,2}:\d{2}:\d{2}[.,]\d{3}\s*-->/', $text ) ) {
			return self::from_cues( self::parse_cues( $text ), $group_seconds );
		}
		return trim( $text );
	}

	/** @return array<int, array{start: float, text: string}> */
	public static function parse_cues( $text ) {
		$cues   = array();
		$blocks = preg_split( "/\n\s*\n/", $text );
		foreach ( $blocks as $block ) {
			$lines = array_values( array_filter( array_map( 'trim', explode( "\n", $block ) ), 'strlen' ) );
			foreach ( $lines as $i => $line ) {
				if ( preg_match( '/^(\d{1,2}):(\d{2}):(\d{2})[.,](\d{3})\s*-->/', $line, $m ) ) {
					$start = $m[1] * 3600 + $m[2] * 60 + $m[3] + $m[4] / 1000;
					$body  = implode( ' ', array_slice( $lines, $i + 1 ) );
					$body  = trim( preg_replace( '/<[^>]+>/', '', $body ) ); // VTT inline tags
					$body  = html_entity_decode( $body, ENT_QUOTES, 'UTF-8' );
					if ( '' !== $body ) {
						$cues[] = array( 'start' => $start, 'text' => $body );
					}
					break;
				}
			}
		}
		return $cues;
	}

	/** Merge cues into one line per group window, de-duplicating the rolling text auto-captions repeat. */
	public static function from_cues( array $cues, $group_seconds = 15 ) {
		$out        = array();
		$group_t    = null;
		$group_text = array();
		$last       = '';
		foreach ( $cues as $cue ) {
			if ( $cue['text'] === $last ) {
				continue;
			}
			$last = $cue['text'];
			if ( null === $group_t || $cue['start'] - $group_t >= $group_seconds ) {
				if ( null !== $group_t ) {
					$out[] = self::fmt( $group_t ) . ' ' . implode( ' ', $group_text );
				}
				$group_t    = $cue['start'];
				$group_text = array();
			}
			$group_text[] = $cue['text'];
		}
		if ( null !== $group_t ) {
			$out[] = self::fmt( $group_t ) . ' ' . implode( ' ', $group_text );
		}
		return implode( "\n", $out );
	}

	public static function fmt( $seconds ) {
		$seconds = (int) floor( $seconds );
		$h       = intdiv( $seconds, 3600 );
		$m       = intdiv( $seconds % 3600, 60 );
		$s       = $seconds % 60;
		return $h > 0 ? sprintf( '%d:%02d:%02d', $h, $m, $s ) : sprintf( '%d:%02d', $m, $s );
	}

	/** ISO 8601 duration (PT21M58S) to seconds. */
	public static function duration_seconds( $iso ) {
		if ( ! preg_match( '/^P(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/', (string) $iso, $m ) ) {
			return 0;
		}
		return ( (int) ( $m[1] ?? 0 ) ) * 86400 + ( (int) ( $m[2] ?? 0 ) ) * 3600 + ( (int) ( $m[3] ?? 0 ) ) * 60 + (int) ( $m[4] ?? 0 );
	}
}
