<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Request-scoped user context. When set, TubeWriter reads and writes that user's
 * credentials, YouTube tokens, drafts and video index instead of the site options.
 * Admin keeps the empty context (site-wide storage).
 */
class TW_Context {

	/** @var int */
	private static $user_id = 0;

	public static function set_user( $user_id ) {
		self::$user_id = max( 0, (int) $user_id );
	}

	public static function clear() {
		self::$user_id = 0;
	}

	/** @return int 0 = site/admin storage */
	public static function user_id() {
		return self::$user_id;
	}

	public static function is_user() {
		return self::$user_id > 0;
	}
}
