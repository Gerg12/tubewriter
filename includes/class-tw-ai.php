<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gemini calls. The editable voice prompt goes first, then a fixed output contract
 * so the plugin always gets JSON it can parse. Thumbnail methods use vision and
 * a separate image model. They never write to YouTube.
 */
class TW_AI {

	const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

	/** @var array|null Last token usage from Gemini or OpenAI. */
	private static $last_usage = null;

	/** @return array|null */
	public static function last_usage() {
		return self::$last_usage;
	}

	private static function remember_usage( array $usage ) {
		self::$last_usage = $usage;
	}

	/** Tried in order when the chosen text model is busy or never answers. */
	const TEXT_FALLBACKS = array(
		'gemini-3.5-flash-lite',
		'gemini-flash-lite-latest',
	);

	const OUTPUT_CONTRACT = <<<'TXT'

=== OUTPUT FORMAT (required, do not ignore) ===
Respond with ONLY a JSON object, no markdown fences, using exactly these keys:
{
  "titles": ["5 title options"],
  "suggested_title": "the best of the five",
  "body": "the full opening walkthrough plus the complete GG Algorithm Warning, with a blank line between them. Write the whole video, not a short summary. Include the warning through the exact closing line 'The slop will continue until morale improves.' Do NOT put chapters or hashtags in here. The assembled description (body + chapters + hashtags) may use up to 5000 characters. Do not cut the walkthrough short to save space.",
  "chapters_heading": "the heading line that goes above the chapter list, or an empty string",
  "chapters": [{"time": "0:00", "label": "Chapter label"}],
  "tags": ["4 to 6 tags, max 8, no # symbols"],
  "hashtags": ["#Game", "#Topic", "#Brand"]
}
Rules: the first chapter time MUST be "0:00". Times use m:ss (or h:mm:ss past one hour). Chapters must come from real timestamps in the transcript, the starting description or the creator notes, and stay inside the video length. If none of those contain timestamps, return "chapters": [] and never invent times. No < or > characters anywhere. Never use an em dash or en dash (– or —); use a comma, period, colon, or hyphen instead.
The STARTING TITLE and STARTING DESCRIPTION are the creator's starting point. If the starting title is just a date or filename (like "2026 10 01 19 19 26"), ignore it. When there is no transcript, build the package from the starting title, starting description and notes, and stay close to what they say.
The STARTING DESCRIPTION is also context. Keep any links, social handles, sponsor or affiliate lines, credits and "watch next" lines from it in the new body (near the end of the body) unless the creator notes say to drop them. Use its facts about the match when there is no transcript.
TXT;

	const THUMB_DESCRIBE_CONTRACT = <<<'TXT'

=== OUTPUT FORMAT (required, do not ignore) ===
Respond with ONLY a JSON object, no markdown fences, using exactly these keys:
{
  "composition": "Picture-in-Picture composite. Image 1 is the full-bleed main background. Image 2 is the upper-right picture-in-picture.",
  "base_imagery": "Two or three sentences. Start with The main background features. Image 1 only. Never describe Image 2 here.",
  "inset": "No inset when only one screenshot is attached. When Image 2 is attached, a short phrase describing that second shot only. No border sentence.",
  "game_name": "Full official game name only, no word logo",
  "line_1": "2 to 4 word hook, not copied from the title",
  "line_2": "2 to 4 word stake"
}
No markdown. No < or > characters. Do not write the concept document. Do not return one paragraph.
TXT;

	const THUMB_DESCRIBE_SCHEMA = array(
		'type'       => 'OBJECT',
		'properties' => array(
			'composition'  => array( 'type' => 'STRING' ),
			'base_imagery' => array( 'type' => 'STRING' ),
			'inset'        => array( 'type' => 'STRING' ),
			'game_name'    => array( 'type' => 'STRING' ),
			'line_1'       => array( 'type' => 'STRING' ),
			'line_2'       => array( 'type' => 'STRING' ),
		),
		'required'   => array( 'composition', 'base_imagery', 'inset', 'game_name', 'line_1', 'line_2' ),
	);

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
	public static function generate( array $input, $provider = 'gemini' ) {
		return self::call( self::context( $input ), null, false, $provider );
	}

	/** Apply a tweak request to the current package. Returns a full new package. */
	public static function revise( array $input, array $current, $instruction, $provider = 'gemini' ) {
		$user = self::context( $input ) . "\n\n"
			. "CURRENT PACKAGE (JSON):\n" . wp_json_encode( $current, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) . "\n\n"
			. "CHANGE REQUEST FROM THE CREATOR:\n" . trim( $instruction ) . "\n\n"
			. 'Apply only that change. Keep everything else the same unless the change requires otherwise. Return the full package in the required JSON format.';

		return self::call( $user, null, false, $provider );
	}

	/** gemini or openai. Anything else stays on Gemini. */
	public static function text_provider( $provider ) {
		$provider = sanitize_key( (string) $provider );
		return in_array( $provider, array( 'gemini', 'openai' ), true ) ? $provider : 'gemini';
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
			. 'Title: under 60 characters, game name first, then what the playlist is (mode, series, theme). No clickbait, no em dashes, no en dashes, no < or >. '
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
	 * Write a thumbnail brief from screenshots plus title, description and transcript.
	 *
	 * @param array $input  Same shape as generate().
	 * @param array $images List of {mime: string, data: string} (base64, no data: prefix).
	 * @param array $pkg    Optional current draft package for title/body context.
	 * @return array{description: string}|WP_Error
	 */
	public static function describe_thumbnail( array $input, array $images, array $pkg = array() ) {
		$images = self::normalize_images( $images );
		if ( is_wp_error( $images ) ) {
			return $images;
		}
		if ( ! $images ) {
			return new WP_Error( 'tw_ai', 'Paste at least one screenshot first.' );
		}

		$title = trim( (string) ( $pkg['title'] ?? '' ) );
		$body  = trim( (string) ( $pkg['body'] ?? '' ) );
		$shot_count = count( $images );
		$shots      = $shot_count > 1
			? 'Two screenshots are attached, in order. Image 1 is always the full-bleed main background. Image 2 is always the picture-in-picture card in the upper right. Never swap them. Never set inset to "No inset". base_imagery describes Image 1 only. inset describes Image 2 only. Do not invent a new foreground character. Look at the lower left of Image 1. Keep a character there only if one is already drawn. If that corner is empty, say GG logo only. Exactly one game logo for the whole thumbnail.'
			: 'Only one screenshot is attached. It is the full-bleed main background. Set inset to "No inset". Do not invent a new foreground character. Look at the lower left. Keep a character there only if one is already drawn. If that corner is empty, say GG logo only. Exactly one game logo for the whole thumbnail.';
		$user  = "DRAFT TITLE: " . ( $title !== '' ? $title : ( $input['current_title'] ?: '(none)' ) ) . "\n\n"
			. "DRAFT DESCRIPTION BODY:\n" . ( $body !== '' ? mb_substr( $body, 0, 2500 ) : '(empty)' ) . "\n\n"
			. self::context( $input ) . "\n\n"
			. $shots . "\n\n"
			. 'Fill the six JSON fields for this video.';

		$system = TW_Settings::get( 'thumb_prompt' ) . "\n" . self::THUMB_DESCRIBE_CONTRACT;
		$parts  = array_merge( array( array( 'text' => $user ) ), self::image_parts( $images ) );
		$json   = self::request(
			TW_Settings::get( 'model' ),
			$system,
			$parts,
			array(
				'responseMimeType' => 'application/json',
				'responseSchema'   => self::THUMB_DESCRIBE_SCHEMA,
				'temperature'      => 0.7,
			),
			true
		);
		if ( is_wp_error( $json ) ) {
			return $json;
		}
		$desc = self::format_thumb_concept( $json, $shot_count );
		if ( is_wp_error( $desc ) ) {
			return $desc;
		}
		return array( 'description' => $desc );
	}

	/**
	 * Print the description box in the fixed Gregatron concept shape.
	 * The model only supplies the per-video facts.
	 *
	 * @param array $json       Decoded model object.
	 * @param int   $shot_count Attached screenshot count.
	 * @return string|WP_Error
	 */
	private static function format_thumb_concept( array $json, $shot_count = 1 ) {
		$composition = self::thumb_prose( $json, 'composition', '/^composition style:\s*/i' );
		$base        = self::thumb_prose( $json, 'base_imagery', '/^base imagery\s*\(image 1\):\s*/i' );
		$inset       = self::thumb_prose( $json, 'inset', '/^tactical inset\s*\(image 2\):\s*/i' );
		$game        = self::thumb_prose( $json, 'game_name', '/^(the\s+authentic\s+official\s+|authentic\s+official\s+)/i' );
		$line1       = self::thumb_line( $json, 'line_1' );
		$line2       = self::thumb_line( $json, 'line_2' );
		$shot_count  = (int) $shot_count;

		$game = preg_replace( '/\s+(transparent\s+png\s+)?logo$/i', '', $game );
		$game = trim( (string) $game, " ." );

		if ( '' === $composition || '' === $base || '' === $inset || '' === $game || '' === $line1 || '' === $line2 ) {
			return new WP_Error( 'tw_ai', 'The thumbnail concept came back incomplete. Try Write thumbnail description again.' );
		}

		if ( $shot_count > 1 && preg_match( '/^no inset\b/i', $inset ) ) {
			$inset = 'the second attached screenshot';
		}

		if ( preg_match( '/^no inset\b/i', $inset ) ) {
			$inset = 'No inset. The game logo can sit in the top right.';
		} else {
			if ( false === stripos( $inset, 'Positioned in the upper right' ) ) {
				$subject = $inset;
				if ( 0 === stripos( $subject, 'showcasing ' ) ) {
					$subject = substr( $subject, strlen( 'showcasing ' ) );
				}
				$inset = 'Positioned in the upper right as a small action card, showcasing ' . $subject;
			}
			if ( false === stripos( $inset, 'thin dark military border' ) ) {
				$inset = rtrim( $inset, " ." ) . '. Thin dark military border. Keep it inside the central 84 percent of the canvas, never flush to the top or right edge, and small enough that every letter of the headline stays fully on the canvas.';
			}
			if ( $shot_count > 1 && false === stripos( $inset, 'Image 2' ) ) {
				$inset = rtrim( $inset, " ." ) . '. This card is Image 2 only. Never use Image 2 as the main background.';
			}
		}

		$roles = $shot_count > 1
			? "Image 1 is the full-bleed main background. Image 2 is only the picture-in-picture card. Never swap them.\n\n"
			: "Only Image 1 is attached. It is the full-bleed main background. No picture-in-picture.\n\n";

		return "7. Custom 16:9 Thumbnail Concept\n"
			. "Canvas: 1920x1080. Exact 16:9 widescreen. No black bars. Gameplay may fill the frame. Headline, logos, any corner character, and any inset stay inside the central 84 percent (about 8 percent empty gameplay from every edge). Place them in inset boxes, not against the rim. Never crop a word. Flush edges are a failed thumbnail.\n"
			. "\n"
			. $roles
			. 'Composition Style: ' . $composition . "\n"
			. "\n"
			. 'Base Imagery (Image 1): ' . $base . "\n"
			. "\n"
			. "Main Subject:\n"
			. "\n"
			. "Polish Image 1 as the main background. The hero is whatever that screenshot already shows. Do not invent a new foreground character, Pixar redesign, fan-art face, or franchise look-alike that is not in the shot.\n"
			. "\n"
			. 'Tactical Inset (Image 2): ' . $inset . "\n"
			. "\n"
			. "Lower-Left Character:\n"
			. "\n"
			. "Look at the lower left of the screenshot. That corner changes per video. If someone is already drawn there, keep that exact face, outfit, colors, mascot, and pose. If the lower left is empty gameplay, leave it for the GG logo only. Do not invent a face, anime figure, cat, or mascot.\n"
			. "\n"
			. "The channel logo sits in the bottom-left content box: large orange GG, then GREGATRON GAMES in white block letters. About 8 percent in from the left and bottom. Smaller than the headline.\n"
			. "\n"
			. "Official Game Logo (exactly one):\n"
			. "\n"
			. 'Place exactly one authentic official ' . $game . " logo. Never two copies. Never a large logo plus a small duplicate. Top right when there is no inset. If Image 2 is a picture-in-picture in the top right, move this single logo to the bottom right, raised above the YouTube duration badge. If the creator set the game logo to top left, leave it there. Keep it inside the central 84 percent, smaller than the headline. If a logo file is attached, use that file once and do not paint another logo from memory.\n"
			. "\n"
			. "Headline Typography (Top-Left):\n"
			. "\n"
			. "Line 1 is larger than Line 2. Both lines sit in the upper-left headline box, about 8 percent in from the top and left. No letter is cut off.\n"
			. "\n"
			. 'Line 1: ' . $line1 . "\n"
			. "\n"
			. 'Line 2: ' . $line2 . "\n"
			. "\n"
			. 'Line 1 is white distressed heavy block lettering with a thick black shadow and a fiery orange stroke. Line 2 is amber-orange with the same heavy black shadow.';
	}

	/** One prose field, labels and extra whitespace removed. */
	private static function thumb_prose( array $json, $key, $label_pattern ) {
		$text = TW_Validator::clean_text( trim( (string) ( $json[ $key ] ?? '' ) ) );
		$text = preg_replace( '/\s+/', ' ', $text );
		$text = preg_replace( $label_pattern, '', (string) $text );
		return trim( (string) $text );
	}

	/** A headline line: words only, forced to caps. */
	private static function thumb_line( array $json, $key ) {
		$text = self::thumb_prose( $json, $key, '/^line\s*[12]\s*:\s*/i' );
		$text = trim( $text, " \t." );
		if ( function_exists( 'mb_strtoupper' ) ) {
			$text = mb_strtoupper( $text, 'UTF-8' );
		} else {
			$text = strtoupper( $text );
		}
		return trim( $text );
	}

	/**
	 * Create a 16:9 thumbnail image from the approved brief and source screenshots.
	 * Uses OpenAI or Gemini based on Settings > image provider.
	 *
	 * @param string $description Approved brief.
	 * @param array  $images      List of {mime, data}.
	 * @return array{mime: string, data: string}|WP_Error
	 */
	public static function generate_thumbnail( $description, array $images, array $logos = array() ) {
		$description = trim( (string) $description );
		if ( '' === $description ) {
			return new WP_Error( 'tw_ai', 'Approve or write a thumbnail description first.' );
		}
		$images = self::normalize_images( $images );
		if ( is_wp_error( $images ) ) {
			return $images;
		}
		if ( ! $images ) {
			return new WP_Error( 'tw_ai', 'Paste at least one screenshot first.' );
		}

		$prompt = self::render_prompt( $description, count( $images ), $logos );
		$send   = $images;
		foreach ( $logos as $logo ) {
			if ( empty( $logo['data'] ) || empty( $logo['mime'] ) ) {
				continue;
			}
			$send[] = array(
				'mime' => $logo['mime'],
				'data' => $logo['data'],
			);
		}
		$image  = ( 'openai' === TW_Settings::get( 'image_provider' ) )
			? self::openai_edit( $prompt, $send )
			: self::gemini_image( $prompt, $send );
		if ( is_wp_error( $image ) ) {
			return $image;
		}
		$fitted = self::fit_16x9( $image['data'] );
		if ( is_string( $fitted ) && '' !== $fitted ) {
			$backdrop = isset( $images[0]['data'] ) ? (string) $images[0]['data'] : '';
			$padded   = self::apply_edge_breathing( $fitted, $backdrop );
			$image['data'] = ( is_string( $padded ) && '' !== $padded ) ? $padded : $fitted;
			$image['mime'] = 'image/png';
		}
		return $image;
	}

	/**
	 * Guarantee edge breathing room after the model paints. Image models often
	 * ignore safe-zone prose and pin text or logos to the rim. Scale the finished
	 * card into the central ~88 percent and fill the border from Image 1 so
	 * gameplay still reaches the frame with no black bars.
	 *
	 * @param string $b64          Fitted 16:9 thumbnail, raw base64.
	 * @param string $backdrop_b64 First screenshot base64, or empty.
	 * @return string Base64 PNG, or the original string when GD cannot run.
	 */
	private static function apply_edge_breathing( $b64, $backdrop_b64 = '' ) {
		if ( ! function_exists( 'imagecreatefromstring' ) || ! function_exists( 'imagepng' ) ) {
			return $b64;
		}
		$bin = base64_decode( (string) $b64, true );
		if ( false === $bin || '' === $bin ) {
			return $b64;
		}
		$src = imagecreatefromstring( $bin );
		if ( ! $src ) {
			return $b64;
		}

		$out_w = 1920;
		$out_h = 1080;
		// ~6 percent empty rim on each side. Enough to read as padding, not a letterbox.
		$inset = 0.06;
		$card_w = (int) round( $out_w * ( 1 - ( 2 * $inset ) ) );
		$card_h = (int) round( $out_h * ( 1 - ( 2 * $inset ) ) );
		$card_x = (int) floor( ( $out_w - $card_w ) / 2 );
		$card_y = (int) floor( ( $out_h - $card_h ) / 2 );

		$dst = imagecreatetruecolor( $out_w, $out_h );
		if ( ! $dst ) {
			imagedestroy( $src );
			return $b64;
		}

		$backdrop = null;
		$back_bin = base64_decode( (string) $backdrop_b64, true );
		if ( false !== $back_bin && '' !== $back_bin ) {
			$backdrop = imagecreatefromstring( $back_bin );
		}
		if ( $backdrop ) {
			self::cover_copy( $backdrop, $dst, $out_w, $out_h );
			imagedestroy( $backdrop );
		} else {
			self::cover_copy( $src, $dst, $out_w, $out_h );
		}

		imagecopyresampled( $dst, $src, $card_x, $card_y, 0, 0, $card_w, $card_h, imagesx( $src ), imagesy( $src ) );
		imagedestroy( $src );

		ob_start();
		imagepng( $dst );
		$out = ob_get_clean();
		imagedestroy( $dst );
		if ( ! is_string( $out ) || '' === $out ) {
			return $b64;
		}
		return base64_encode( $out );
	}

	/**
	 * Scale-and-cover paste: fill the destination, cropping overflow from the center.
	 *
	 * @param resource|\GdImage $src
	 * @param resource|\GdImage $dst
	 * @param int               $out_w
	 * @param int               $out_h
	 */
	private static function cover_copy( $src, $dst, $out_w, $out_h ) {
		$sw = imagesx( $src );
		$sh = imagesy( $src );
		if ( $sw < 2 || $sh < 2 ) {
			return;
		}
		$scale  = max( $out_w / $sw, $out_h / $sh );
		$copy_w = (int) round( $out_w / $scale );
		$copy_h = (int) round( $out_h / $scale );
		$src_x  = (int) floor( ( $sw - $copy_w ) / 2 );
		$src_y  = (int) floor( ( $sh - $copy_h ) / 2 );
		imagecopyresampled( $dst, $src, 0, 0, max( 0, $src_x ), max( 0, $src_y ), $out_w, $out_h, $copy_w, $copy_h );
	}

	/**
	 * Force a generated thumbnail to 1920x1080. A near-16:9 frame is scaled.
	 * A 3:2 or square frame is center-cropped to 16:9 first.
	 *
	 * @param string $b64 Raw base64, no data: prefix.
	 * @return string Base64 PNG, or the original string when GD cannot run.
	 */
	private static function fit_16x9( $b64 ) {
		if ( ! function_exists( 'imagecreatefromstring' ) || ! function_exists( 'imagepng' ) ) {
			return $b64;
		}
		$bin = base64_decode( (string) $b64, true );
		if ( false === $bin || '' === $bin ) {
			return $b64;
		}
		$src = imagecreatefromstring( $bin );
		if ( ! $src ) {
			return $b64;
		}
		$w = imagesx( $src );
		$h = imagesy( $src );
		if ( $w < 2 || $h < 2 ) {
			imagedestroy( $src );
			return $b64;
		}

		$target = 16 / 9;
		$ratio  = $w / $h;
		$crop_x = 0;
		$crop_y = 0;
		$crop_w = $w;
		$crop_h = $h;
		if ( $ratio > $target * 1.01 ) {
			$crop_w = (int) round( $h * $target );
			$crop_x = (int) floor( ( $w - $crop_w ) / 2 );
		} elseif ( $ratio < $target * 0.99 ) {
			$crop_h = (int) round( $w / $target );
			$crop_y = (int) floor( ( $h - $crop_h ) / 2 );
		}

		$dst = imagecreatetruecolor( 1920, 1080 );
		if ( ! $dst ) {
			imagedestroy( $src );
			return $b64;
		}
		imagecopyresampled( $dst, $src, 0, 0, $crop_x, $crop_y, 1920, 1080, $crop_w, $crop_h );
		ob_start();
		imagepng( $dst );
		$out = ob_get_clean();
		imagedestroy( $src );
		imagedestroy( $dst );
		if ( ! is_string( $out ) || '' === $out ) {
			return $b64;
		}
		return base64_encode( $out );
	}

	/**
	 * Landscape size this OpenAI image model will accept.
	 * chatgpt-image-latest only allows three fixed sizes, and 1536x1024 is 3:2.
	 * gpt-image-2 and newer accept a real 16:9 frame. 1920x1080 itself is rejected
	 * because 1080 is not divisible by 16, so we ask for 2048x1152 and scale down.
	 *
	 * @param string $model
	 * @return string
	 */
	private static function openai_image_size( $model ) {
		$model = strtolower( (string) $model );
		$fixed = array( 'chatgpt-image-latest', 'gpt-image-1', 'gpt-image-1.5', 'dall-e-2', 'dall-e-3' );
		foreach ( $fixed as $name ) {
			if ( $model === $name ) {
				return '1536x1024';
			}
		}
		return '2048x1152';
	}

	/** Standing render instructions + the approved per-video brief. */
	private static function render_prompt( $description, $shot_count, array $logos = array() ) {
		$standing = trim( (string) TW_Settings::get( 'thumb_render_prompt' ) );
		$shots    = (int) $shot_count > 1
			? 'Two screenshots are attached, in order. Image 1 is always the full-bleed main background. Polish that exact shot. Do not invent a new foreground hero. Image 2 is always the picture-in-picture card in the top-right content box, inside the central 84 percent, never flush to the top or right edge. Never swap Image 1 and Image 2. Never paint Image 2 as the background. Never paint Image 1 as the inset. The headline stays in the upper-left content box. The channel logo stays in the lower-left content box. Exactly one game logo moves to the bottom-right content box so it does not cover the inset. Keep a lower-left character only if one is already drawn in Image 1. If that corner is empty, GG logo only.'
			: 'One screenshot is attached. It is the full-bleed main background. Polish that exact shot. Do not invent a new foreground hero. No picture-in-picture. The headline is in the upper-left content box. The channel logo is in the lower-left content box. Exactly one game logo is in the upper-right content box, or top left if the logo instruction says top left. Keep a lower-left character only if one is already drawn in the screenshot. If that corner is empty, GG logo only.';
		$size = self::openai_image_size( TW_Settings::get( 'openai_image_model' ) );
		$frame = ( '1536x1024' === $size )
			? 'Paint on a 1536x1024 canvas. The top and bottom are then trimmed and the picture is saved at 1920x1080, which is 16:9. Keep every graphic inside the central 80 percent of this taller canvas so the trim still leaves about 8 percent padding on the final 16:9 frame.'
			: 'Paint on a ' . $size . ' canvas. That is exact 16:9. It is saved at 1920x1080. Keep every graphic inside the central 84 percent. About 8 percent empty gameplay from every edge.';
		$logo_block = self::logo_block( (int) $shot_count, $logos );
		$edge_safe  = '[EDGE-SAFE] All headlines, logos, corner characters, inset cards, and wordmarks must fit within the central 84 percent of the canvas (about 8 percent padding from every edge). Background gameplay may bleed to the rim. Text and focal graphics may NOT touch or extend off any edge. If a graphic does not fit, scale it DOWN. Never crop a headline. Never let a letter, logo, helmet, or inset border kiss the frame.';
		return $standing . "\n\n"
			. $frame . " Keep every letter inside its content box. Do not clip a letter.\n\n"
			. $shots . "\n\n"
			. "APPROVED THUMBNAIL BRIEF:\n" . trim( (string) $description )
			. ( '' !== $logo_block ? "\n\n" . $logo_block : '' )
			. "\n\n" . $edge_safe;
	}

	/**
	 * Last instruction the image model sees. Attached logo files override the brief.
	 *
	 * @param int   $shot_count
	 * @param array $logos
	 * @return string
	 */
	private static function logo_block( $shot_count, array $logos ) {
		$lines = array();
		$n     = (int) $shot_count;
		$has_game = false;
		foreach ( $logos as $logo ) {
			if ( empty( $logo['data'] ) ) {
				continue;
			}
			$n++;
			$where = trim( (string) ( $logo['pos'] ?? '' ) );
			$label = trim( (string) ( $logo['label'] ?? 'logo' ) );
			$lines[] = 'Attached image ' . $n . ' is the exact ' . $label . ' file. Place that artwork unchanged once in the ' . $where . ' content box, inside the central 84 percent. Do not redraw it, recolor it, or typeset a new logo.';
			if ( false !== stripos( $label, 'game' ) ) {
				$has_game = true;
			}
		}
		if ( ! $lines ) {
			return '';
		}
		$extra = $has_game
			? "\nExactly one game logo in the whole image. Use the attached game logo file once. Do not paint a second game logo from memory."
			: '';
		return "LOGO FILES (these override the brief)\n" . implode( "\n", $lines )
			. "\nKeep each logo inside its content box, smaller than the headline, and clear of the other logo."
			. $extra;
	}

	/** Gemini native image generation with reference screenshots. */
	private static function gemini_image( $prompt, array $images ) {
		$parts = array_merge( array( array( 'text' => $prompt ) ), self::image_parts( $images ) );
		return self::request(
			TW_Settings::get( 'image_model' ),
			null,
			$parts,
			array(
				'responseModalities' => array( 'TEXT', 'IMAGE' ),
				'imageConfig'        => array( 'aspectRatio' => '16:9' ),
			),
			'image'
		);
	}

	/**
	 * OpenAI Images edits endpoint with reference screenshots (ChatGPT-style image).
	 *
	 * @return array{mime: string, data: string}|WP_Error
	 */
	private static function openai_edit( $prompt, array $images ) {
		$key = TW_Settings::get( 'openai_key' );
		if ( ! $key ) {
			return new WP_Error( 'tw_ai', 'Add your OpenAI API key in TubeWriter > Settings, or switch the image provider to Gemini.' );
		}

		$model   = TW_Settings::get( 'openai_image_model' );
		$quality = TW_Settings::get( 'openai_image_quality' );
		$boundary = 'tw' . wp_generate_password( 24, false, false );
		$body     = '';

		$fields = array(
			'model'         => $model,
			'prompt'        => $prompt,
			'size'          => self::openai_image_size( $model ),
			'quality'       => $quality,
			'output_format' => 'png',
		);
		foreach ( $fields as $name => $value ) {
			$body .= '--' . $boundary . "\r\n";
			$body .= 'Content-Disposition: form-data; name="' . $name . "\"\r\n\r\n";
			$body .= $value . "\r\n";
		}

		$i = 0;
		foreach ( $images as $img ) {
			$i++;
			$bin  = base64_decode( $img['data'], true );
			$mime = $img['mime'];
			$ext  = ( false !== strpos( $mime, 'jpeg' ) || false !== strpos( $mime, 'jpg' ) ) ? 'jpg' : ( false !== strpos( $mime, 'webp' ) ? 'webp' : 'png' );
			if ( false === $bin ) {
				return new WP_Error( 'tw_ai', 'Could not decode a pasted screenshot.' );
			}
			$body .= '--' . $boundary . "\r\n";
			$body .= 'Content-Disposition: form-data; name="image[]"; filename="shot' . $i . '.' . $ext . "\"\r\n";
			$body .= 'Content-Type: ' . $mime . "\r\n\r\n";
			$body .= $bin . "\r\n";
		}
		$body .= '--' . $boundary . "--\r\n";

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 180 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		$response = wp_remote_post(
			'https://api.openai.com/v1/images/edits',
			array(
				'timeout' => 150,
				'headers' => array(
					'Authorization' => 'Bearer ' . $key,
					'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
				),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'tw_ai', 'Could not reach OpenAI: ' . $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 400 ) {
			$msg = $data['error']['message'] ?? ( 'HTTP ' . $code );
			if ( 401 === $code || 403 === $code ) {
				$msg = 'OpenAI rejected the API key. Check it in Settings. ' . $msg;
			} elseif ( 429 === $code ) {
				$msg = 'OpenAI rate limit or quota hit. Wait and try again. ' . $msg;
			} elseif ( 404 === $code ) {
				$msg = 'OpenAI image model "' . $model . '" not found. Try chatgpt-image-latest or gpt-image-2.5-sunburst in Settings. ' . $msg;
			}
			return new WP_Error( 'tw_ai', wp_strip_all_tags( $msg ) );
		}

		$b64 = $data['data'][0]['b64_json'] ?? '';
		if ( '' === $b64 ) {
			$url = $data['data'][0]['url'] ?? '';
			if ( $url ) {
				$fetched = wp_remote_get( $url, array( 'timeout' => 60 ) );
				if ( ! is_wp_error( $fetched ) && 200 === wp_remote_retrieve_response_code( $fetched ) ) {
					$bin = wp_remote_retrieve_body( $fetched );
					if ( $bin ) {
						return array(
							'mime' => 'image/png',
							'data' => base64_encode( $bin ),
						);
					}
				}
			}
			return new WP_Error( 'tw_ai', 'OpenAI returned no image. Try again or switch quality/model in Settings.' );
		}

		return array(
			'mime' => 'image/png',
			'data' => $b64,
		);
	}

	/**
	 * ChatGPT text completion. Returns package text, decoded JSON when $raw, or WP_Error.
	 *
	 * @param string $model
	 * @param string $system
	 * @param string $user_text
	 * @param bool   $raw
	 * @return mixed|WP_Error
	 */
	private static function openai_chat( $model, $system, $user_text, $raw ) {
		self::$last_usage = null;
		$key = TW_Settings::get( 'openai_key' );
		if ( ! $key ) {
			$where = TW_Context::is_user() ? 'your account settings' : 'TubeWriter > Settings';
			return new WP_Error( 'tw_ai', 'ChatGPT needs an OpenAI API key. Add it in ' . $where . ', then pick ChatGPT again.' );
		}
		$model = trim( (string) $model );
		if ( '' === $model ) {
			$model = TW_DEFAULT_OPENAI_MODEL;
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 180 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		$response = wp_remote_post(
			'https://api.openai.com/v1/chat/completions',
			array(
				'timeout' => 150,
				'headers' => array(
					'Authorization' => 'Bearer ' . $key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'           => $model,
						'temperature'     => 0.9,
						'response_format' => array( 'type' => 'json_object' ),
						'messages'        => array(
							array( 'role' => 'system', 'content' => (string) $system ),
							array( 'role' => 'user', 'content' => (string) $user_text ),
						),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$err = $response->get_error_message();
			if ( false !== stripos( $err, 'timed out' ) || false !== stripos( $err, 'cURL error 28' ) ) {
				return new WP_Error( 'tw_ai', 'ChatGPT did not answer (the request timed out). Nothing was changed. Try Gemini, or try again in a minute.' );
			}
			return new WP_Error( 'tw_ai', 'Could not reach ChatGPT: ' . $err . ' Nothing was changed.' );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code >= 400 ) {
			$raw_msg = is_array( $data ) ? ( $data['error']['message'] ?? ( 'HTTP ' . $code ) ) : ( 'HTTP ' . $code );
			if ( 401 === $code || 403 === $code ) {
				$msg = 'ChatGPT rejected the API key. Check the OpenAI key in Settings. ' . $raw_msg;
			} elseif ( 404 === $code || false !== stripos( $raw_msg, 'does not exist' ) || false !== stripos( $raw_msg, 'not found' ) ) {
				$msg = 'ChatGPT model "' . $model . '" was not found. Change the OpenAI text model in Settings. ' . $raw_msg;
			} elseif ( 429 === $code ) {
				$msg = 'ChatGPT rate limit or quota hit. Nothing was changed. Wait a minute and try again. ' . $raw_msg;
			} else {
				$msg = 'ChatGPT: ' . $raw_msg;
			}
			return new WP_Error( 'tw_ai', wp_strip_all_tags( $msg ) );
		}

		if ( ! empty( $data['usage'] ) && is_array( $data['usage'] ) ) {
			self::remember_usage(
				array(
					'provider'     => 'openai',
					'model'        => $model,
					'prompt'       => (int) ( $data['usage']['prompt_tokens'] ?? 0 ),
					'completion'   => (int) ( $data['usage']['completion_tokens'] ?? 0 ),
					'total'        => (int) ( $data['usage']['total_tokens'] ?? 0 ),
					'reused_cache' => (int) ( $data['usage']['prompt_tokens_details']['cached_tokens'] ?? 0 ),
				)
			);
		}

		$text = (string) ( $data['choices'][0]['message']['content'] ?? '' );
		if ( '' === trim( $text ) ) {
			return new WP_Error( 'tw_ai', 'ChatGPT returned nothing. Nothing was changed. Try again.' );
		}
		if ( $raw ) {
			$json = json_decode( trim( $text ), true );
			return is_array( $json ) ? $json : new WP_Error( 'tw_ai', 'ChatGPT answer was not valid JSON. Try again.' );
		}
		return $text;
	}

	/**
	 * @param string      $user_text
	 * @param string|null $system  Override the channel voice prompt + contract.
	 * @param bool        $raw     Return decoded JSON as-is instead of a parsed package.
	 */
	private static function call( $user_text, $system = null, $raw = false, $provider = 'gemini' ) {
		if ( null === $system ) {
			$system = TW_Settings::get( 'prompt' ) . "\n" . self::OUTPUT_CONTRACT;
		}
		if ( 'openai' === self::text_provider( $provider ) ) {
			$result = self::openai_chat( TW_Settings::get( 'openai_model' ), $system, $user_text, $raw );
		} else {
			$result = self::request(
				TW_Settings::get( 'model' ),
				$system,
				array( array( 'text' => $user_text ) ),
				array(
					'responseMimeType' => 'application/json',
					'temperature'      => 0.9,
					'maxOutputTokens'  => 8192,
				),
				$raw ? true : false
			);
		}
		if ( is_wp_error( $result ) || $raw ) {
			return $result;
		}
		return self::parse_package( is_string( $result ) ? $result : wp_json_encode( $result ) );
	}

	/** True when Gemini is overloaded or asking the caller to retry shortly. */
	private static function demand_message( $msg ) {
		$msg = strtolower( (string) $msg );
		foreach ( array( 'high demand', 'unavailable', 'overloaded', 'resource exhausted', 'try again later' ) as $bit ) {
			if ( false !== strpos( $msg, $bit ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Low-level Gemini generateContent call.
	 *
	 * @param string      $model
	 * @param string|null $system
	 * @param array       $parts
	 * @param array       $gen_config
	 * @param bool|string $mode true = decoded JSON, false = package text for parse_package path via call(), 'image' = first image part, 'text' = raw text.
	 * @param bool        $allow_fallback When a text model is busy or times out, try TEXT_FALLBACKS.
	 * @return mixed|WP_Error
	 */
	private static function request( $model, $system, array $parts, array $gen_config, $mode = false, $allow_fallback = true ) {
		self::$last_usage = null;
		$key = TW_Settings::get( 'gemini_key' );
		if ( ! $key ) {
			$where = TW_Context::is_user() ? 'your account settings' : 'TubeWriter > Settings';
			return new WP_Error( 'tw_ai', 'Add your Gemini API key in ' . $where . '.' );
		}
		if ( ! $parts ) {
			return new WP_Error( 'tw_ai', 'Nothing to send to Gemini.' );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 180 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		$image     = ( 'image' === $mode );
		$timeout   = $image ? 150 : 45;
		$max_tries = $image ? 3 : 1;

		$body = array(
			'contents'         => array(
				array(
					'role'  => 'user',
					'parts' => $parts,
				),
			),
			'generationConfig' => $gen_config,
		);
		if ( null !== $system && '' !== trim( (string) $system ) ) {
			$body['systemInstruction'] = array( 'parts' => array( array( 'text' => $system ) ) );
		}

		$url     = sprintf( self::ENDPOINT, rawurlencode( $model ) );
		$payload = wp_json_encode( $body );
		$data    = null;
		$code    = 0;

		for ( $try = 1; $try <= $max_tries; $try++ ) {
			$response = wp_remote_post(
				$url,
				array(
					'timeout' => $timeout,
					'headers' => array(
						'Content-Type'   => 'application/json',
						'x-goog-api-key' => $key,
					),
					'body'    => $payload,
				)
			);

			if ( is_wp_error( $response ) ) {
				$err = $response->get_error_message();
				if ( false !== stripos( $err, 'timed out' ) || false !== stripos( $err, 'cURL error 28' ) ) {
					return self::failover(
						$model,
						$system,
						$parts,
						$gen_config,
						$mode,
						$allow_fallback,
						new WP_Error( 'tw_ai', 'Gemini did not answer (the request timed out). Nothing was changed. Pick ChatGPT next to Generate and try that.' )
					);
				}
				return self::failover(
					$model,
					$system,
					$parts,
					$gen_config,
					$mode,
					$allow_fallback,
					new WP_Error( 'tw_ai', 'Could not reach Gemini: ' . $err )
				);
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			$data = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( $code < 400 ) {
				break;
			}

			$raw   = $data['error']['message'] ?? ( 'HTTP ' . $code );
			$busy  = in_array( $code, array( 429, 500, 503 ), true ) || self::demand_message( $raw );
			if ( $busy && $try < $max_tries ) {
				sleep( 2 * $try );
				continue;
			}

			if ( $busy && ( self::demand_message( $raw ) || in_array( $code, array( 500, 503 ), true ) ) ) {
				$msg = 'This model is busy right now. Spikes in demand are usually temporary. Nothing was changed. Wait a minute and try again.';
			} elseif ( 429 === $code ) {
				$msg = 'Gemini rate limit hit (free tier). Nothing was changed. Wait a minute and try again. ' . $raw;
			} elseif ( 404 === $code ) {
				$msg = 'Gemini model "' . $model . '" not found. Check the model name in Settings. ' . $raw;
			} elseif ( 400 === $code || 403 === $code ) {
				$msg = 'Gemini rejected the request. Check your API key and model in Settings. ' . $raw;
			} else {
				$msg = $raw;
			}
			return self::failover(
				$model,
				$system,
				$parts,
				$gen_config,
				$mode,
				$allow_fallback && ! in_array( $code, array( 400, 403 ), true ),
				new WP_Error( 'tw_ai', wp_strip_all_tags( $msg ) )
			);
		}

		if ( ! empty( $data['usageMetadata'] ) && is_array( $data['usageMetadata'] ) ) {
			$um = $data['usageMetadata'];
			self::remember_usage(
				array(
					'provider'     => 'gemini',
					'model'        => $model,
					'prompt'       => (int) ( $um['promptTokenCount'] ?? 0 ),
					'completion'   => (int) ( $um['candidatesTokenCount'] ?? 0 ),
					'total'        => (int) ( $um['totalTokenCount'] ?? 0 ),
					'reused_cache' => (int) ( $um['cachedContentTokenCount'] ?? 0 ),
				)
			);
		}

		$parts_out = $data['candidates'][0]['content']['parts'] ?? array();
		if ( 'image' === $mode ) {
			foreach ( $parts_out as $part ) {
				$blob = $part['inlineData'] ?? $part['inline_data'] ?? null;
				if ( ! is_array( $blob ) || empty( $blob['data'] ) ) {
					continue;
				}
				$mime = (string) ( $blob['mimeType'] ?? $blob['mime_type'] ?? 'image/png' );
				return array(
					'mime' => $mime,
					'data' => (string) $blob['data'],
				);
			}
			$text = '';
			foreach ( $parts_out as $part ) {
				if ( empty( $part['thought'] ) ) {
					$text .= $part['text'] ?? '';
				}
			}
			$reason = $data['candidates'][0]['finishReason'] ?? ( $data['promptFeedback']['blockReason'] ?? 'unknown' );
			$extra  = trim( $text ) !== '' ? ' Gemini said: ' . wp_strip_all_tags( mb_substr( $text, 0, 300 ) ) : '';
			return new WP_Error( 'tw_ai', 'Gemini returned no image (reason: ' . $reason . ').' . $extra );
		}

		$text = '';
		foreach ( $parts_out as $part ) {
			if ( ! empty( $part['thought'] ) ) {
				continue;
			}
			$text .= $part['text'] ?? '';
		}
		$reason = (string) ( $data['candidates'][0]['finishReason'] ?? ( $data['promptFeedback']['blockReason'] ?? '' ) );
		if ( '' === trim( $text ) ) {
			$reason = $reason !== '' ? $reason : 'unknown';
			return new WP_Error( 'tw_ai', 'Gemini returned nothing (reason: ' . $reason . '). Try again.' );
		}
		if ( 'MAX_TOKENS' === $reason ) {
			return new WP_Error( 'tw_ai', 'Gemini ran out of output room and cut the answer short. Nothing was changed. Click Generate again.' );
		}

		if ( true === $mode ) {
			$json = json_decode( preg_replace( '/^```(?:json)?\s*|\s*```$/', '', trim( $text ) ), true );
			return is_array( $json ) ? $json : new WP_Error( 'tw_ai', 'The AI answer was not valid JSON. Try again.' );
		}

		return $text;
	}

	/**
	 * One retry on a model that is answering, when the chosen text model is busy or silent.
	 *
	 * @param string      $model
	 * @param string|null $system
	 * @param array       $parts
	 * @param array       $gen_config
	 * @param bool|string $mode
	 * @param bool        $allow
	 * @param WP_Error    $error
	 * @return mixed|WP_Error
	 */
	private static function failover( $model, $system, array $parts, array $gen_config, $mode, $allow, WP_Error $error ) {
		if ( ! $allow || 'image' === $mode ) {
			return $error;
		}
		$tried = array( $model . ': ' . $error->get_error_message() );
		foreach ( self::TEXT_FALLBACKS as $next_model ) {
			if ( $next_model === $model ) {
				continue;
			}
			$next = self::request( $next_model, $system, $parts, $gen_config, $mode, false );
			if ( ! is_wp_error( $next ) ) {
				return $next;
			}
			$tried[] = $next_model . ': ' . $next->get_error_message();
		}
		return new WP_Error( 'tw_ai', 'Gemini did not finish. ' . implode( ' ', $tried ) );
	}

	/**
	 * @param array $images
	 * @return array|WP_Error
	 */
	private static function normalize_images( array $images ) {
		$out   = array();
		$allow = array( 'image/png', 'image/jpeg', 'image/jpg', 'image/webp', 'image/gif' );
		foreach ( array_slice( $images, 0, 2 ) as $img ) {
			if ( ! is_array( $img ) ) {
				continue;
			}
			$mime = strtolower( (string) ( $img['mime'] ?? '' ) );
			$data = (string) ( $img['data'] ?? '' );
			if ( 0 === strpos( $data, 'data:' ) ) {
				if ( preg_match( '#^data:([^;]+);base64,(.+)$#s', $data, $m ) ) {
					$mime = strtolower( $m[1] );
					$data = $m[2];
				}
			}
			$data = preg_replace( '/\s+/', '', $data );
			if ( 'image/jpg' === $mime ) {
				$mime = 'image/jpeg';
			}
			if ( ! in_array( $mime, $allow, true ) || '' === $data ) {
				return new WP_Error( 'tw_ai', 'Screenshots must be PNG, JPEG, WebP or GIF.' );
			}
			// Rough size guard: ~5MB decoded.
			if ( strlen( $data ) > 7 * 1024 * 1024 ) {
				return new WP_Error( 'tw_ai', 'A screenshot is too large. Paste a smaller crop or lower resolution.' );
			}
			$out[] = array( 'mime' => $mime, 'data' => $data );
		}
		return $out;
	}

	private static function image_parts( array $images ) {
		$parts = array();
		foreach ( $images as $img ) {
			$parts[] = array(
				'inlineData' => array(
					'mimeType' => $img['mime'],
					'data'     => $img['data'],
				),
			);
		}
		return $parts;
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
