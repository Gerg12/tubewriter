<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gemini calls. The editable voice prompt goes first, then a fixed output contract
 * so the plugin always gets JSON it can parse.
 */
class TW_AI {

	const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

	const OUTPUT_CONTRACT = <<<'TXT'

=== OUTPUT FORMAT (required, do not ignore) ===
Respond with ONLY a JSON object, no markdown fences, using exactly these keys:
{
  "titles": ["5 title options"],
  "suggested_title": "the best of the five",
  "body": "every part of the description that comes BEFORE the chapter list, with blank lines between paragraphs. Do NOT put chapters or hashtags in here.",
  "chapters_heading": "the heading line that goes above the chapter list, or an empty string",
  "chapters": [{"time": "0:00", "label": "Chapter label"}],
  "tags": ["tags as the instructions above say, no # symbols"],
  "hashtags": ["#Five", "#Hashtags"]
}
Rules: the first chapter time MUST be "0:00". Times use m:ss (or h:mm:ss past one hour). Chapters must come from real timestamps in the transcript, the starting description or the creator notes, and stay inside the video length. If none of those contain timestamps, return "chapters": [] and never invent times. No < or > characters anywhere.
The STARTING TITLE and STARTING DESCRIPTION are the creator's starting point. If the starting title is just a date or filename (like "2026 10 01 19 19 26"), ignore it. When there is no transcript, build the package from the starting title, starting description and notes, and stay close to what they say.
The STARTING DESCRIPTION is also context. Keep any links, social handles, sponsor or affiliate lines, credits and "watch next" lines from it in the new body (near the end of the body) unless the creator notes say to drop them. Use its facts about the match when there is no transcript.
TXT;

	/** Shared context block: what's on YouTube now, the notes, and the transcript. */
	private static function context( array $input ) {
		$transcript = trim( $input['transcript'] );
		return "VIDEO LENGTH: " . ( $input['duration_label'] ?: 'unknown' ) . "\n\n"
			. "STARTING TITLE: " . ( $input['current_title'] ?: '(none)' ) . "\n\n"
			. "STARTING DESCRIPTION:\n" . ( trim( $input['current_description'] ) ?: '(empty)' ) . "\n\n"
			. "CREATOR NOTES (trust these over everything else):\n" . ( trim( $input['notes'] ) ?: '(none)' ) . "\n\n"
			. "TRANSCRIPT WITH TIMESTAMPS:\n" . ( '' !== $transcript ? $transcript : '(no transcript for this video)' );
	}

	/** First draft from transcript, notes and what's already on YouTube. */
	public static function generate( array $input ) {
		return self::call( self::context( $input ) );
	}

	/** Apply a tweak request to the current package. Returns a full new package. */
	public static function revise( array $input, array $current, $instruction ) {
		$user = self::context( $input ) . "\n\n"
			. "CURRENT PACKAGE (JSON):\n" . wp_json_encode( $current, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) . "\n\n"
			. "CHANGE REQUEST FROM THE CREATOR:\n" . trim( $instruction ) . "\n\n"
			. 'Apply only that change. Keep everything else the same unless the change requires otherwise. Return the full package in the required JSON format.';

		return self::call( $user );
	}

	/**
	 * Suggest an SEO-friendly playlist title and description.
	 *
	 * @return array{title: string, description: string}|WP_Error
	 */
	public static function playlist( $rough_name, array $input ) {
		$channel = get_option( 'tw_channel' );
		$system  = 'You write YouTube playlist titles and descriptions that rank in YouTube search for the channel "'
			. ( $channel['title'] ?? 'this channel' ) . '". '
			. 'Title: under 60 characters, game name first, then what the playlist is (mode, series, theme). No clickbait, no em dashes, no < or >. '
			. 'Description: 2 to 3 natural sentences, under 600 characters, that say what viewers get and naturally include the game name and the main search terms. '
			. 'Respond with ONLY JSON: {"title": "...", "description": "..."}';
		$user    = "ROUGH PLAYLIST NAME FROM THE CREATOR: " . ( trim( $rough_name ) ?: '(none, infer from the video)' ) . "\n\n"
			. "VIDEO THIS PLAYLIST STARTS WITH:\n"
			. 'Title: ' . ( $input['title'] ?? '' ) . "\n"
			. 'Description: ' . mb_substr( (string) ( $input['description'] ?? '' ), 0, 1500 );

		$json = self::call( $user, $system, true );
		if ( is_wp_error( $json ) ) {
			return $json;
		}
		return array(
			'title'       => TW_Validator::clean_text( mb_substr( (string) ( $json['title'] ?? '' ), 0, 150 ) ),
			'description' => TW_Validator::clean_text( mb_substr( (string) ( $json['description'] ?? '' ), 0, 5000 ) ),
		);
	}

	/**
	 * @param string      $user_text
	 * @param string|null $system  Override the channel voice prompt + contract.
	 * @param bool        $raw     Return decoded JSON as-is instead of a parsed package.
	 */
	private static function call( $user_text, $system = null, $raw = false ) {
		$key = TW_Settings::get( 'gemini_key' );
		if ( ! $key ) {
			return new WP_Error( 'tw_ai', 'Add your Gemini API key in TubeWriter > Settings.' );
		}
		$model  = TW_Settings::get( 'model' );
		if ( null === $system ) {
			$system = TW_Settings::get( 'prompt' ) . "\n" . self::OUTPUT_CONTRACT;
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 180 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		$response = wp_remote_post(
			sprintf( self::ENDPOINT, rawurlencode( $model ) ),
			array(
				'timeout' => 150,
				'headers' => array(
					'Content-Type'   => 'application/json',
					'x-goog-api-key' => $key,
				),
				'body'    => wp_json_encode(
					array(
						'systemInstruction' => array( 'parts' => array( array( 'text' => $system ) ) ),
						'contents'          => array(
							array(
								'role'  => 'user',
								'parts' => array( array( 'text' => $user_text ) ),
							),
						),
						'generationConfig'  => array(
							'responseMimeType' => 'application/json',
							'temperature'      => 0.9,
						),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'tw_ai', 'Could not reach Gemini: ' . $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 400 ) {
			$msg = $data['error']['message'] ?? ( 'HTTP ' . $code );
			if ( 429 === $code ) {
				$msg = 'Gemini rate limit hit (free tier). Wait a minute and try again. ' . $msg;
			} elseif ( 404 === $code ) {
				$msg = 'Gemini model "' . $model . '" not found. Check the model name in Settings. ' . $msg;
			} elseif ( 400 === $code || 403 === $code ) {
				$msg = 'Gemini rejected the request. Check your API key in Settings. ' . $msg;
			}
			return new WP_Error( 'tw_ai', wp_strip_all_tags( $msg ) );
		}

		$text = '';
		foreach ( $data['candidates'][0]['content']['parts'] ?? array() as $part ) {
			if ( ! empty( $part['thought'] ) ) {
				continue;
			}
			$text .= $part['text'] ?? '';
		}
		if ( '' === trim( $text ) ) {
			$reason = $data['candidates'][0]['finishReason'] ?? ( $data['promptFeedback']['blockReason'] ?? 'unknown' );
			return new WP_Error( 'tw_ai', 'Gemini returned nothing (reason: ' . $reason . '). Try again.' );
		}

		if ( $raw ) {
			$json = json_decode( preg_replace( '/^```(?:json)?\s*|\s*```$/', '', trim( $text ) ), true );
			return is_array( $json ) ? $json : new WP_Error( 'tw_ai', 'The AI answer was not valid JSON. Try again.' );
		}
		return self::parse_package( $text );
	}

	/** Parse and normalize the model's JSON into the package shape the UI uses. */
	public static function parse_package( $text ) {
		$text = trim( $text );
		$text = preg_replace( '/^```(?:json)?\s*|\s*```$/', '', $text );
		$json = json_decode( $text, true );
		if ( ! is_array( $json ) ) {
			$start = strpos( $text, '{' );
			$end   = strrpos( $text, '}' );
			if ( false !== $start && false !== $end ) {
				$json = json_decode( substr( $text, $start, $end - $start + 1 ), true );
			}
		}
		if ( ! is_array( $json ) ) {
			return new WP_Error( 'tw_ai', 'The AI answer was not valid JSON. Click Generate again.' );
		}

		$titles = array_values( array_filter( array_map( 'strval', (array) ( $json['titles'] ?? array() ) ) ) );
		$chosen = (string) ( $json['suggested_title'] ?? ( $titles[0] ?? '' ) );
		if ( $chosen && ! in_array( $chosen, $titles, true ) ) {
			array_unshift( $titles, $chosen );
		}

		$chapters = array();
		foreach ( (array) ( $json['chapters'] ?? array() ) as $c ) {
			if ( is_array( $c ) ) {
				$chapters[] = array( 'time' => (string) ( $c['time'] ?? '' ), 'label' => (string) ( $c['label'] ?? '' ) );
			} elseif ( is_string( $c ) ) {
				$chapters = array_merge( $chapters, TW_Validator::chapters_from_text( $c ) );
			}
		}

		$tags = $json['tags'] ?? array();
		if ( is_string( $tags ) ) {
			$tags = explode( ',', $tags );
		}
		$hashtags = $json['hashtags'] ?? array();
		if ( is_string( $hashtags ) ) {
			$hashtags = preg_split( '/\s+/', $hashtags );
		}

		return array(
			'titles'           => $titles,
			'title'            => $chosen,
			'body'             => (string) ( $json['body'] ?? '' ),
			'chapters_heading' => (string) ( $json['chapters_heading'] ?? '' ),
			'chapters'         => $chapters,
			'tags'             => array_values( array_map( 'strval', (array) $tags ) ),
			'hashtags'         => array_values( array_map( 'strval', (array) $hashtags ) ),
		);
	}
}
