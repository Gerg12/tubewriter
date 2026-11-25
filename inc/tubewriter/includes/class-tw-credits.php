<?php
/**
 * Plan allowances, writing/thumbnail credits, per-user daily YouTube units,
 * project quota, and next-day push queue. Phase 1: BYOK only, no Stripe.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TW_Credits {

	const META_PLAN              = 'tw_plan';
	const META_MODE              = 'tw_ai_mode';
	const META_WRITING_LEFT      = 'tw_writing_credits_left';
	const META_WRITING_ALLOW     = 'tw_writing_credits_allowance';
	const META_THUMB_LEFT        = 'tw_thumbnail_credits_left';
	const META_THUMB_ALLOW       = 'tw_thumbnail_credits_allowance';
	const META_THUMB_PACK        = 'tw_thumbnail_credits_pack';
	const META_PERIOD            = 'tw_credits_period';
	const META_BILLING_DAY       = 'tw_billing_day';
	const META_DAILY_UNITS       = 'tw_daily_units';
	const META_LAST_ACTIVE       = 'tw_last_active';
	const META_TEST_ACCOUNT     = 'tw_test_account';
	const META_UNLIMITED         = 'tw_unlimited';
	const OPTION_PROJECT_QUOTA   = 'tw_project_quota';
	const OPTION_QUEUE_DB        = 'tw_push_queue_db_version';
	const QUEUE_DB_VERSION       = '1';
	const CRON_QUEUE             = 'tw_credits_process_queue';
	const PROJECT_DAILY_CAP      = 10000;
	const BETA_CAP               = 25;
	const MUSE_DAILY_CAP         = 1000;

	const PLANS = array(
		'free'    => array(
			'name'                => 'Free',
			'writing'             => 3,
			'writing_period'      => 'once',
			'thumbnail'           => 0,
			'daily_units'         => 100,
			'full_videos_per_day' => 1,
		),
		'creator' => array(
			'name'                => 'Creator',
			'writing'             => 25,
			'writing_period'      => 'month',
			'thumbnail'           => 0,
			'daily_units'         => 100,
			'full_videos_per_day' => 1,
		),
		'studio'  => array(
			'name'                => 'Studio',
			'writing'             => 100,
			'writing_period'      => 'month',
			'thumbnail'           => 10,
			'daily_units'         => 200,
			'full_videos_per_day' => 2,
		),
	);

	const UNIT_COSTS = array(
		'push'           => 50,
		'restore'        => 50,
		'privacy'        => 50,
		'thumb_upload'   => 50,
		'full_video'     => 100,
		'captions'       => 250,
		'quick_sync'     => 20,
		'full_sync_page' => 2,
		'refresh'        => 1,
	);

	public static function boot() {
		add_action( 'init', array( __CLASS__, 'maybe_install_queue' ) );
		add_action( 'init', array( __CLASS__, 'maybe_clear_admin_daily_caps' ), 20 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_schedule_queue' ) );
		add_action( self::CRON_QUEUE, array( __CLASS__, 'process_queue' ) );
		add_action( 'admin_post_tw_waitlist_invite', array( __CLASS__, 'handle_waitlist_invite' ) );
	}

	/**
	 * Site admins, or users with the Unlimited whitelist flag, skip plan YouTube
	 * unit caps and writing/thumbnail credit meters. Shared project 10k still applies.
	 */
	public static function is_unlimited( $user_id = null ) {
		$user_id = self::uid( $user_id );
		if ( $user_id < 1 ) {
			return false;
		}
		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}
		return self::user_has_unlimited( $user_id );
	}

	public static function user_has_unlimited( $user_id ) {
		return '1' === (string) get_user_meta( (int) $user_id, self::META_UNLIMITED, true );
	}

	/**
	 * Grant or revoke the unlimited whitelist. Clears today's plan daily meter when granting.
	 */
	public static function set_user_unlimited( $user_id, $enabled ) {
		$user_id = (int) $user_id;
		if ( $user_id < 1 ) {
			return;
		}
		if ( $enabled ) {
			update_user_meta( $user_id, self::META_UNLIMITED, '1' );
			delete_user_meta( $user_id, self::META_DAILY_UNITS );
		} else {
			delete_user_meta( $user_id, self::META_UNLIMITED );
		}
	}

	/** One-time: wipe inflated per-user daily counters for admins after the unlimited fix. */
	public static function maybe_clear_admin_daily_caps() {
		if ( get_option( 'tw_credits_admin_unlimited_v1' ) === '1' ) {
			return;
		}
		$admins = get_users(
			array(
				'role'   => 'administrator',
				'fields' => array( 'ID' ),
				'number' => 100,
			)
		);
		foreach ( $admins as $admin ) {
			delete_user_meta( (int) $admin->ID, self::META_DAILY_UNITS );
		}
		update_option( 'tw_credits_admin_unlimited_v1', '1', false );
	}

	public static function pacific_day() {
		return ( new DateTime( 'now', new DateTimeZone( 'America/Los_Angeles' ) ) )->format( 'Y-m-d' );
	}

	public static function plan_id( $user_id = null ) {
		$user_id = self::uid( $user_id );
		$plan    = (string) get_user_meta( $user_id, self::META_PLAN, true );
		if ( ! isset( self::PLANS[ $plan ] ) ) {
			$plan = 'free';
		}
		return $plan;
	}

	public static function plan( $user_id = null ) {
		return self::PLANS[ self::plan_id( $user_id ) ];
	}

	public static function set_plan( $user_id, $plan, $reset_balances = false ) {
		$user_id = (int) $user_id;
		$plan    = isset( self::PLANS[ $plan ] ) ? $plan : 'free';
		update_user_meta( $user_id, self::META_PLAN, $plan );
		$def = self::PLANS[ $plan ];
		$has_writing = '' !== (string) get_user_meta( $user_id, self::META_WRITING_LEFT, true );
		if ( $reset_balances || ! $has_writing ) {
			update_user_meta( $user_id, self::META_WRITING_LEFT, (int) $def['writing'] );
			update_user_meta( $user_id, self::META_WRITING_ALLOW, (int) $def['writing'] );
			update_user_meta( $user_id, self::META_THUMB_LEFT, (int) $def['thumbnail'] );
			update_user_meta( $user_id, self::META_THUMB_ALLOW, (int) $def['thumbnail'] );
			if ( 'once' === $def['writing_period'] ) {
				update_user_meta( $user_id, self::META_PERIOD, 'once' );
			} else {
				update_user_meta( $user_id, self::META_PERIOD, gmdate( 'Y-m' ) );
			}
		} else {
			update_user_meta( $user_id, self::META_WRITING_ALLOW, (int) $def['writing'] );
			update_user_meta( $user_id, self::META_THUMB_ALLOW, (int) $def['thumbnail'] );
		}
		if ( ! get_user_meta( $user_id, self::META_BILLING_DAY, true ) ) {
			update_user_meta( $user_id, self::META_BILLING_DAY, (int) gmdate( 'j' ) );
		}
		if ( ! get_user_meta( $user_id, self::META_MODE, true ) ) {
			update_user_meta( $user_id, self::META_MODE, 'byok' );
		}
	}

	public static function ensure( $user_id = null ) {
		$user_id = self::uid( $user_id );
		if ( ! $user_id ) {
			return;
		}
		if ( ! get_user_meta( $user_id, self::META_PLAN, true ) ) {
			self::set_plan( $user_id, 'free', true );
			return;
		}
		$plan = self::plan( $user_id );
		if ( 'once' === $plan['writing_period'] ) {
			return;
		}
		$period = (string) get_user_meta( $user_id, self::META_PERIOD, true );
		$now    = self::current_period_key( $user_id );
		if ( $period === $now ) {
			return;
		}
		update_user_meta( $user_id, self::META_WRITING_LEFT, (int) $plan['writing'] );
		update_user_meta( $user_id, self::META_WRITING_ALLOW, (int) $plan['writing'] );
		$pack = (int) get_user_meta( $user_id, self::META_THUMB_PACK, true );
		update_user_meta( $user_id, self::META_THUMB_LEFT, (int) $plan['thumbnail'] + max( 0, $pack ) );
		update_user_meta( $user_id, self::META_THUMB_ALLOW, (int) $plan['thumbnail'] );
		update_user_meta( $user_id, self::META_PERIOD, $now );
	}

	public static function current_period_key( $user_id = null ) {
		$user_id = self::uid( $user_id );
		$day     = max( 1, min( 28, (int) get_user_meta( $user_id, self::META_BILLING_DAY, true ) ?: 1 ) );
		$now     = new DateTime( 'now', new DateTimeZone( 'UTC' ) );
		$d       = (int) $now->format( 'j' );
		if ( $d < $day ) {
			$now->modify( '-1 month' );
		}
		return $now->format( 'Y-m' ) . '-d' . $day;
	}

	public static function renews_label( $user_id = null ) {
		$user_id = self::uid( $user_id );
		$plan    = self::plan( $user_id );
		if ( 'once' === $plan['writing_period'] ) {
			return '';
		}
		$day  = max( 1, min( 28, (int) get_user_meta( $user_id, self::META_BILLING_DAY, true ) ?: 1 ) );
		$now  = new DateTime( 'now', new DateTimeZone( 'UTC' ) );
		$next = clone $now;
		$next->setDate( (int) $now->format( 'Y' ), (int) $now->format( 'n' ), $day );
		$next->setTime( 0, 0, 0 );
		if ( $next <= $now ) {
			$next->modify( '+1 month' );
		}
		return $next->format( 'M j' );
	}

	public static function mode( $user_id = null ) {
		$user_id = self::uid( $user_id );
		$mode    = (string) get_user_meta( $user_id, self::META_MODE, true );
		return 'hosted' === $mode ? 'hosted' : 'byok';
	}

	public static function set_mode( $user_id, $mode ) {
		update_user_meta( (int) $user_id, self::META_MODE, 'byok' );
	}

	public static function has_byok_key( $user_id = null ) {
		$user_id = self::uid( $user_id );
		if ( ! $user_id ) {
			return false;
		}
		$prev = TW_Context::user_id();
		TW_Context::set_user( $user_id );
		$ok = (bool) TW_Settings::get( 'gemini_key' ) || (bool) TW_Settings::get( 'openai_key' );
		TW_Context::set_user( $prev );
		return $ok;
	}

	public static function assert_can_generate( $user_id = null ) {
		$user_id = self::uid( $user_id );
		if ( 'hosted' === self::mode( $user_id ) ) {
			return new WP_Error( 'tw_mode', 'Hosted AI is planned, not available yet. Add your own Gemini or OpenAI key under Account, Keys and voice.' );
		}
		if ( ! self::has_byok_key( $user_id ) ) {
			return new WP_Error( 'tw_byok', 'Add a Gemini or OpenAI API key under Account, Keys and voice before generating.' );
		}
		return true;
	}

	public static function writing_left( $user_id = null ) {
		self::ensure( $user_id );
		return max( 0, (int) get_user_meta( self::uid( $user_id ), self::META_WRITING_LEFT, true ) );
	}

	public static function writing_allowance( $user_id = null ) {
		self::ensure( $user_id );
		$allow = (int) get_user_meta( self::uid( $user_id ), self::META_WRITING_ALLOW, true );
		return $allow > 0 ? $allow : (int) self::plan( $user_id )['writing'];
	}

	public static function thumb_left( $user_id = null ) {
		self::ensure( $user_id );
		return max( 0, (int) get_user_meta( self::uid( $user_id ), self::META_THUMB_LEFT, true ) );
	}

	public static function thumb_allowance( $user_id = null ) {
		self::ensure( $user_id );
		return max( 0, (int) get_user_meta( self::uid( $user_id ), self::META_THUMB_ALLOW, true ) );
	}

	public static function thumb_cost( $detail = 'standard' ) {
		return ( 'high' === $detail ) ? 3 : 1;
	}

	public static function quality_detail( $quality = '' ) {
		$q = strtolower( (string) $quality );
		if ( '' === $q ) {
			$q = strtolower( (string) TW_Settings::get( 'openai_image_quality' ) );
		}
		if ( false !== strpos( $q, 'high' ) || 'hd' === $q ) {
			return 'high';
		}
		return 'standard';
	}

	public static function assert_writing_credit( $user_id = null ) {
		$user_id = self::uid( $user_id );
		if ( self::is_unlimited( $user_id ) ) {
			return true;
		}
		$gate = self::assert_can_generate( $user_id );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}
		$left = self::writing_left( $user_id );
		if ( $left < 1 ) {
			$renew = self::renews_label( $user_id );
			$msg   = $renew
				? sprintf( 'Out of writing credits, upgrade, buy a top-up, or wait until %s.', $renew )
				: 'Out of writing credits, upgrade, buy a top-up, or wait for a new allowance.';
			return new WP_Error( 'tw_credits', $msg );
		}
		return true;
	}

	public static function assert_thumb_credit( $detail = 'standard', $user_id = null ) {
		$user_id = self::uid( $user_id );
		if ( self::is_unlimited( $user_id ) ) {
			return true;
		}
		$gate = self::assert_can_generate( $user_id );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}
		$allow = self::thumb_allowance( $user_id );
		$left  = self::thumb_left( $user_id );
		$cost  = self::thumb_cost( $detail );
		if ( $allow < 1 && $left < 1 ) {
			return new WP_Error( 'tw_credits', 'No thumbnail credits on this plan' );
		}
		if ( $left < $cost ) {
			$renew = self::renews_label( $user_id );
			$msg   = $renew
				? sprintf( 'Out of thumbnail credits, upgrade, buy a top-up, or wait until %s.', $renew )
				: 'Out of thumbnail credits on this plan.';
			return new WP_Error( 'tw_credits', $msg );
		}
		return true;
	}

	public static function spend_writing( $user_id = null ) {
		$user_id = self::uid( $user_id );
		if ( self::is_unlimited( $user_id ) ) {
			return true;
		}
		$left = self::writing_left( $user_id );
		if ( $left < 1 ) {
			return false;
		}
		update_user_meta( $user_id, self::META_WRITING_LEFT, $left - 1 );
		update_user_meta( $user_id, self::META_LAST_ACTIVE, time() );
		if ( class_exists( 'TW_Launch' ) ) {
			TW_Launch::record_writing_credit();
		}
		return true;
	}

	public static function spend_thumb( $detail = 'standard', $user_id = null ) {
		$user_id = self::uid( $user_id );
		if ( self::is_unlimited( $user_id ) ) {
			return true;
		}
		$cost = self::thumb_cost( $detail );
		$left = self::thumb_left( $user_id );
		if ( $left < $cost ) {
			return false;
		}
		update_user_meta( $user_id, self::META_THUMB_LEFT, $left - $cost );
		$allow = self::thumb_allowance( $user_id );
		$pack  = (int) get_user_meta( $user_id, self::META_THUMB_PACK, true );
		$after_monthly = max( 0, $left - $allow );
		if ( $pack > 0 && $after_monthly > 0 ) {
			$from_pack = min( $cost, $after_monthly );
			update_user_meta( $user_id, self::META_THUMB_PACK, max( 0, $pack - $from_pack ) );
		}
		update_user_meta( $user_id, self::META_LAST_ACTIVE, time() );
		if ( class_exists( 'TW_Launch' ) ) {
			TW_Launch::record_thumbnail_credit( $detail );
		}
		return true;
	}

	public static function daily_cap( $user_id = null ) {
		if ( self::is_unlimited( $user_id ) ) {
			return 0; // 0 = unlimited (not a Free 0-cap).
		}
		$plan_cap = (int) self::plan( $user_id )['daily_units'];
		return min( $plan_cap, self::MUSE_DAILY_CAP );
	}

	public static function daily_used( $user_id = null ) {
		$user_id = self::uid( $user_id );
		$raw     = get_user_meta( $user_id, self::META_DAILY_UNITS, true );
		$raw     = is_array( $raw ) ? $raw : array();
		if ( ( $raw['day'] ?? '' ) !== self::pacific_day() ) {
			return 0;
		}
		return (int) ( $raw['used'] ?? 0 );
	}

	public static function daily_remaining( $user_id = null ) {
		if ( self::is_unlimited( $user_id ) ) {
			return PHP_INT_MAX;
		}
		return max( 0, self::daily_cap( $user_id ) - self::daily_used( $user_id ) );
	}

	public static function add_daily_units( $units, $user_id = null ) {
		$units = max( 0, (int) $units );
		if ( $units < 1 ) {
			return;
		}
		$user_id = self::uid( $user_id );
		if ( self::is_unlimited( $user_id ) ) {
			return;
		}
		$day     = self::pacific_day();
		$raw     = get_user_meta( $user_id, self::META_DAILY_UNITS, true );
		$raw     = is_array( $raw ) ? $raw : array();
		$used    = ( ( $raw['day'] ?? '' ) === $day ) ? (int) ( $raw['used'] ?? 0 ) : 0;
		update_user_meta(
			$user_id,
			self::META_DAILY_UNITS,
			array(
				'day'  => $day,
				'used' => $used + $units,
			)
		);
		update_user_meta( $user_id, self::META_LAST_ACTIVE, time() );
	}

	public static function set_daily_used( $used, $user_id = null ) {
		$user_id = self::uid( $user_id );
		update_user_meta(
			$user_id,
			self::META_DAILY_UNITS,
			array(
				'day'  => self::pacific_day(),
				'used' => max( 0, (int) $used ),
			)
		);
	}

	public static function project_used() {
		$q = get_option( self::OPTION_PROJECT_QUOTA, array() );
		$q = is_array( $q ) ? $q : array();
		if ( ( $q['day'] ?? '' ) !== self::pacific_day() ) {
			return 0;
		}
		return (int) ( $q['used'] ?? 0 );
	}

	public static function project_remaining() {
		return max( 0, self::PROJECT_DAILY_CAP - self::project_used() );
	}

	public static function add_project_units( $units ) {
		$units = max( 0, (int) $units );
		if ( $units < 1 ) {
			return;
		}
		$day  = self::pacific_day();
		$q    = get_option( self::OPTION_PROJECT_QUOTA, array() );
		$q    = is_array( $q ) ? $q : array();
		$used = ( ( $q['day'] ?? '' ) === $day ) ? (int) ( $q['used'] ?? 0 ) : 0;
		update_option(
			self::OPTION_PROJECT_QUOTA,
			array(
				'day'  => $day,
				'used' => $used + $units,
			),
			false
		);
	}

	public static function set_project_used( $used ) {
		update_option(
			self::OPTION_PROJECT_QUOTA,
			array(
				'day'  => self::pacific_day(),
				'used' => max( 0, (int) $used ),
			),
			false
		);
	}

	public static function assert_units( $units, $user_id = null, $queueable = false ) {
		$units   = max( 0, (int) $units );
		$user_id = self::uid( $user_id );
		if ( $units < 1 ) {
			return true;
		}

		// Site admins skip plan daily caps. Shared project 10k still applies.
		if ( ! self::is_unlimited( $user_id ) ) {
			$daily_left = self::daily_remaining( $user_id );
			if ( $units > $daily_left ) {
				return new WP_Error(
					'tw_daily_limit',
					'Daily limit reached, 0 units left today, try tomorrow.',
					array(
						'status'       => 429,
						'remaining'    => $daily_left,
						'needed'       => $units,
						'credit_spent' => false,
					)
				);
			}
		}

		$project_left = self::project_remaining();
		if ( $units > $project_left ) {
			if ( $queueable ) {
				return new WP_Error(
					'tw_quota_queue',
					'YouTube quota full today, your push is queued for tomorrow.',
					array(
						'status'       => 429,
						'queue'        => true,
						'credit_spent' => false,
					)
				);
			}
			return new WP_Error(
				'tw_project_quota',
				'Daily YouTube API quota used up. It resets at midnight Pacific time.',
				array(
					'status'       => 429,
					'credit_spent' => false,
				)
			);
		}

		return true;
	}

	public static function record_units( $units, $user_id = null ) {
		$units = max( 0, (int) $units );
		if ( $units < 1 ) {
			return;
		}
		// Plan daily meter for non-admins only. Project counter always.
		self::add_daily_units( $units, $user_id );
		self::add_project_units( $units );
	}

	public static function queue_table() {
		global $wpdb;
		return $wpdb->prefix . 'tw_push_queue';
	}

	public static function maybe_install_queue() {
		if ( get_option( self::OPTION_QUEUE_DB ) === self::QUEUE_DB_VERSION ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::queue_table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				video_id varchar(32) NOT NULL,
				user_id bigint(20) unsigned NOT NULL,
				payload longtext NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'queued',
				units_estimate int(10) unsigned NOT NULL DEFAULT 50,
				units_used int(10) unsigned NOT NULL DEFAULT 0,
				credit_spent tinyint(1) NOT NULL DEFAULT 0,
				fail_reason text NULL,
				requested_at datetime NOT NULL,
				queued_at datetime NOT NULL,
				ran_at datetime NULL,
				PRIMARY KEY  (id),
				KEY status_queued (status, queued_at),
				KEY user_id (user_id),
				KEY video_id (video_id)
			) {$charset};"
		);
		update_option( self::OPTION_QUEUE_DB, self::QUEUE_DB_VERSION, false );
	}

	public static function maybe_schedule_queue() {
		if ( ! wp_next_scheduled( self::CRON_QUEUE ) ) {
			$tz   = new DateTimeZone( 'America/Los_Angeles' );
			$now  = new DateTime( 'now', $tz );
			$next = clone $now;
			$next->setTime( 0, 15, 0 );
			if ( $next <= $now ) {
				$next->modify( '+1 day' );
			}
			wp_schedule_event( $next->getTimestamp(), 'daily', self::CRON_QUEUE );
		}
	}

	public static function enqueue_push( $video_id, array $payload, $user_id = null, $units = 50 ) {
		global $wpdb;
		self::maybe_install_queue();
		$user_id  = self::uid( $user_id );
		$video_id = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $video_id );
		$now      = current_time( 'mysql', true );

		$existing = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id FROM ' . self::queue_table() . " WHERE user_id = %d AND video_id = %s AND status = 'queued' LIMIT 1",
				$user_id,
				$video_id
			),
			ARRAY_A
		);
		if ( $existing ) {
			$wpdb->update(
				self::queue_table(),
				array(
					'payload'        => wp_json_encode( $payload ),
					'units_estimate' => max( 1, (int) $units ),
					'requested_at'   => $now,
				),
				array( 'id' => (int) $existing['id'] ),
				array( '%s', '%d', '%s' ),
				array( '%d' )
			);
			$id = (int) $existing['id'];
		} else {
			$ok = $wpdb->insert(
				self::queue_table(),
				array(
					'video_id'       => $video_id,
					'user_id'        => $user_id,
					'payload'        => wp_json_encode( $payload ),
					'status'         => 'queued',
					'units_estimate' => max( 1, (int) $units ),
					'units_used'     => 0,
					'credit_spent'   => 0,
					'fail_reason'    => null,
					'requested_at'   => $now,
					'queued_at'      => $now,
					'ran_at'         => null,
				),
				array( '%s', '%d', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s' )
			);
			if ( false === $ok ) {
				return new WP_Error( 'tw_queue', 'Could not queue that push.' );
			}
			$id = (int) $wpdb->insert_id;
		}

		if ( class_exists( 'TW_Launch' ) ) {
			TW_Launch::bump_day( 'pushes_queued_next_day' );
		}

		$position = self::queue_position( $id );
		$message  = sprintf(
			'YouTube quota full today, your push is queued for tomorrow, position %d in the queue, credit not spent.',
			$position
		);
		return array(
			'id'           => $id,
			'position'     => $position,
			'message'      => $message,
			'queued'       => true,
			'credit_spent' => false,
		);
	}

	public static function queue_position( $id ) {
		global $wpdb;
		self::maybe_install_queue();
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT queued_at FROM ' . self::queue_table() . ' WHERE id = %d', (int) $id ), ARRAY_A );
		if ( ! $row ) {
			return 0;
		}
		return 1 + (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM " . self::queue_table() . " WHERE status = 'queued' AND (queued_at < %s OR (queued_at = %s AND id < %d))",
				$row['queued_at'],
				$row['queued_at'],
				(int) $id
			)
		);
	}

	public static function queue_count() {
		global $wpdb;
		self::maybe_install_queue();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . self::queue_table() . " WHERE status = 'queued'" ); // phpcs:ignore
	}

	public static function queue_entries( $limit = 100 ) {
		global $wpdb;
		self::maybe_install_queue();
		$limit = max( 1, min( 500, (int) $limit ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::queue_table() . ' ORDER BY FIELD(status, "queued", "running", "done", "failed"), queued_at ASC, id ASC LIMIT %d',
				$limit
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	public static function process_queue( $max = 50 ) {
		global $wpdb;
		self::maybe_install_queue();
		$max  = max( 1, min( 200, (int) $max ) );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM " . self::queue_table() . " WHERE status = 'queued' ORDER BY queued_at ASC, id ASC LIMIT %d",
				$max
			),
			ARRAY_A
		);
		if ( ! $rows ) {
			return 0;
		}

		$ran = 0;
		foreach ( $rows as $row ) {
			$units = max( 1, (int) $row['units_estimate'] );
			$check = self::assert_units( $units, (int) $row['user_id'], false );
			if ( is_wp_error( $check ) ) {
				if ( 'tw_project_quota' === $check->get_error_code() ) {
					break;
				}
				continue;
			}

			$wpdb->update(
				self::queue_table(),
				array( 'status' => 'running' ),
				array( 'id' => (int) $row['id'] ),
				array( '%s' ),
				array( '%d' )
			);

			$result = self::run_queued_push( $row );
			$ran_at = current_time( 'mysql', true );
			if ( is_wp_error( $result ) ) {
				$wpdb->update(
					self::queue_table(),
					array(
						'status'       => 'failed',
						'fail_reason'  => $result->get_error_message(),
						'credit_spent' => 0,
						'ran_at'       => $ran_at,
					),
					array( 'id' => (int) $row['id'] ),
					array( '%s', '%s', '%d', '%s' ),
					array( '%d' )
				);
				if ( class_exists( 'TW_Launch' ) ) {
					TW_Launch::record_push( 'error', $result->get_error_message() );
				}
				continue;
			}

			// Units are recorded by TW_YouTube::track_quota during the write.
			$wpdb->update(
				self::queue_table(),
				array(
					'status'       => 'done',
					'units_used'   => $units,
					'credit_spent' => 0,
					'ran_at'       => $ran_at,
					'fail_reason'  => null,
				),
				array( 'id' => (int) $row['id'] ),
				array( '%s', '%d', '%d', '%s', '%s' ),
				array( '%d' )
			);
			if ( class_exists( 'TW_Launch' ) ) {
				TW_Launch::record_push( 'ok' );
			}
			++$ran;
		}
		return $ran;
	}

	private static function run_queued_push( array $row ) {
		$payload = json_decode( (string) $row['payload'], true );
		if ( ! is_array( $payload ) ) {
			return new WP_Error( 'tw_queue', 'Queued payload was unreadable.' );
		}
		$prev = TW_Context::user_id();
		TW_Context::set_user( (int) $row['user_id'] );
		$pkg         = is_array( $payload['pkg'] ?? null ) ? $payload['pkg'] : array();
		$opts        = is_array( $payload['opts'] ?? null ) ? $payload['opts'] : array();
		$duration    = (int) ( $payload['duration'] ?? 0 );
		$fingerprint = (string) ( $payload['fingerprint'] ?? '' );
		$result      = TW_Plan::publish( (string) $row['video_id'], $pkg, $opts, $duration, $fingerprint );
		TW_Context::set_user( $prev );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		TW_Index::refresh_one( (string) $row['video_id'] );
		if ( ! empty( $result['wrote'] ) ) {
			$sched = (array) ( $result['schedule'] ?? array() );
			$mode  = $sched['mode'] ?? 'keep';
			if ( 'keep' === $mode ) {
				$opt_privacy = (string) ( $opts['privacy'] ?? '' );
				if ( in_array( $opt_privacy, array( 'private', 'unlisted', 'public' ), true ) ) {
					$mode = $opt_privacy;
				}
			}
			TW_Index::apply_visibility( (string) $row['video_id'], $mode, $sched['at'] ?? '' );
		}
		TW_Index::set_tw_status( (string) $row['video_id'], 'pushed' );
		return true;
	}

	public static function snapshot( $user_id = null ) {
		$user_id = self::uid( $user_id );
		self::ensure( $user_id );
		$unlimited = self::is_unlimited( $user_id );
		$plan      = self::plan( $user_id );
		$renew     = self::renews_label( $user_id );
		$w_left    = self::writing_left( $user_id );
		$w_allow   = self::writing_allowance( $user_id );
		$t_left    = self::thumb_left( $user_id );
		$t_allow   = self::thumb_allowance( $user_id );
		$daily_c   = self::daily_cap( $user_id );
		$daily_u   = self::daily_used( $user_id );
		$daily_r   = $unlimited ? null : max( 0, $daily_c - $daily_u );

		if ( $unlimited ) {
			$writing_line = 'Unlimited writing credits';
			$thumb_line   = 'Unlimited thumbnail credits';
			$daily_line   = 'Unlimited YouTube units today';
		} else {
			$writing_line = sprintf( '%d of %d writing credits left', $w_left, $w_allow );
			if ( $renew ) {
				$writing_line .= ', renews ' . $renew;
			}
			$thumb_line = ( $t_allow < 1 && $t_left < 1 )
				? '0 thumbnail credits'
				: sprintf( '%d of %d thumbnail credits left', $t_left, max( $t_allow, $t_left ) );
			$daily_line = sprintf( '%d of %d YouTube units left today', $daily_r, $daily_c );
		}

		return array(
			'plan'                  => self::plan_id( $user_id ),
			'plan_name'             => $unlimited
				? ( user_can( $user_id, 'manage_options' ) ? 'Admin' : 'Unlimited' )
				: $plan['name'],
			'mode'                  => self::mode( $user_id ),
			'mode_label'            => 'byok' === self::mode( $user_id ) ? 'BYOK' : 'Hosted',
			'hosted_available'      => false,
			'unlimited'             => $unlimited ? 1 : 0,
			'writing_left'          => $unlimited ? null : $w_left,
			'writing_allowance'     => $unlimited ? null : $w_allow,
			'writing_line'          => $writing_line,
			'thumbnail_left'        => $unlimited ? null : $t_left,
			'thumbnail_allowance'   => $unlimited ? null : $t_allow,
			'thumbnail_line'        => $thumb_line,
			'renews'                => $renew,
			'daily_units_cap'       => $unlimited ? null : $daily_c,
			'daily_units_used'      => $daily_u,
			'daily_units_remaining' => $daily_r,
			'daily_units_line'      => $daily_line,
			'project_used'          => self::project_used(),
			'project_remaining'     => self::project_remaining(),
			'project_cap'           => self::PROJECT_DAILY_CAP,
			'writing_credit_cost'   => 1,
			'thumb_standard_cost'   => 1,
			'thumb_high_cost'       => 3,
			'has_byok_key'          => self::has_byok_key( $user_id ) ? 1 : 0,
			'queue_count'           => self::queue_count(),
		);
	}

	public static function beta_active_count() {
		if ( class_exists( 'TW_Launch' ) ) {
			$users = TW_Launch::beta_users();
			$n     = 0;
			foreach ( $users as $user ) {
				if ( '1' === (string) get_user_meta( (int) $user->ID, self::META_TEST_ACCOUNT, true ) ) {
					continue;
				}
				++$n;
			}
			return $n;
		}
		return 0;
	}

	public static function beta_slots_open() {
		return self::beta_active_count() < self::BETA_CAP;
	}

	public static function invite_waitlist_entry( $entry_id ) {
		if ( ! function_exists( 'uw_waitlist_entries' ) ) {
			return new WP_Error( 'tw_invite', 'Waitlist is not available.' );
		}
		$entries = uw_waitlist_entries( 2000 );
		$entry   = null;
		foreach ( $entries as $row ) {
			if ( (int) $row['id'] === (int) $entry_id ) {
				$entry = $row;
				break;
			}
		}
		if ( ! $entry ) {
			return new WP_Error( 'tw_invite', 'Waitlist entry not found.' );
		}
		if ( ! self::beta_slots_open() ) {
			return new WP_Error( 'tw_invite', 'Beta is full (25 of 25). Free a seat before inviting.' );
		}

		$email = sanitize_email( (string) $entry['email'] );
		$user  = get_user_by( 'email', $email );
		if ( ! $user ) {
			$login = sanitize_user( current( explode( '@', $email ) ), true );
			if ( ! $login || username_exists( $login ) ) {
				$login = 'uw' . wp_generate_password( 8, false, false );
			}
			$pass    = wp_generate_password( 20, true, true );
			$user_id = wp_create_user( $login, $pass, $email );
			if ( is_wp_error( $user_id ) ) {
				return $user_id;
			}
			$user = new WP_User( $user_id );
			$user->set_role( 'tubewriter' );
			self::set_plan( $user_id, self::invite_plan_for_entry( $entry ), true );
		} else {
			$user_id = (int) $user->ID;
			if ( ! user_can( $user_id, 'use_uploadwriter' ) && ! user_can( $user_id, 'manage_options' ) ) {
				$user->set_role( 'tubewriter' );
			}
			if ( ! get_user_meta( $user_id, self::META_PLAN, true ) ) {
				self::set_plan( $user_id, self::invite_plan_for_entry( $entry ), true );
			}
		}

		tw_user_set_beta_pending( $user_id, false );
		if ( function_exists( 'uw_waitlist_set_invited' ) ) {
			uw_waitlist_set_invited( (int) $entry_id, true );
		}

		$login_url = home_url( '/account/login/' );
		if ( class_exists( 'TW_Frontend' ) ) {
			$login_url = TW_Frontend::url( 'login' );
		}
		$subject = 'Your UploadWriter beta invite';
		$body    = "You are invited to UploadWriter beta.\n\n"
			. "Log in here:\n" . $login_url . "\n\n"
			. "Phase 1 is BYOK only. Add your Gemini or OpenAI key under Account, Keys and voice.\n";
		wp_mail( $email, $subject, $body );

		return array(
			'ok'      => true,
			'user_id' => $user_id,
			'message' => 'Invite sent. Beta seat granted.',
		);
	}

	public static function invite_plan_for_entry( array $entry ) {
		$videos = (string) ( $entry['videos_per_month'] ?? '' );
		if ( in_array( $videos, array( '13-30', '31-plus' ), true ) ) {
			return 'studio';
		}
		if ( '5-12' === $videos ) {
			return 'creator';
		}
		return 'free';
	}

	public static function handle_waitlist_invite() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden.', 'uploadwriter' ) );
		}
		check_admin_referer( 'tw_waitlist_invite' );
		$id     = isset( $_POST['entry_id'] ) ? (int) $_POST['entry_id'] : 0;
		$result = self::invite_waitlist_entry( $id );
		$args   = array( 'page' => 'tubewriter-launch', 'waitlist' => '1' );
		if ( is_wp_error( $result ) ) {
			$args['invite_error'] = rawurlencode( $result->get_error_message() );
		} else {
			$args['invited'] = 1;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) . '#waitlist' );
		exit;
	}

	private static function uid( $user_id = null ) {
		if ( null === $user_id || '' === $user_id ) {
			$ctx = TW_Context::user_id();
			if ( $ctx ) {
				return (int) $ctx;
			}
			return (int) get_current_user_id();
		}
		return (int) $user_id;
	}
}
