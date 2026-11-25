<?php
/**
 * Phase 1 launch benchmarks: daily quota snapshots, push counters, beta / waitlist gates.
 * Admin only. No public leaderboard. Does not enable checkout or hosted billing.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TW_Launch {

	const CRON_SNAPSHOT     = 'tw_launch_daily_snapshot';
	const OPTION_HISTORY    = 'tw_launch_quota_history';
	const OPTION_DAY_STATS  = 'tw_launch_day_stats';
	const OPTION_COUNTERS   = 'tw_launch_counters';
	const OPTION_GATES      = 'tw_launch_gate_flags';
	const PROJECT_DAILY_CAP = 10000;
	const BETA_CAP          = 25;
	const HISTORY_DAYS      = 14;

	public static function boot() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( self::CRON_SNAPSHOT, array( __CLASS__, 'run_daily_snapshot' ) );
		add_action( 'admin_post_tw_launch_save_gates', array( __CLASS__, 'save_gates' ) );
		add_action( 'admin_post_tw_launch_toggle_invite', array( __CLASS__, 'toggle_invite' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_schedule' ) );
	}

	public static function maybe_schedule() {
		if ( ! wp_next_scheduled( self::CRON_SNAPSHOT ) ) {
			// Aim for shortly after midnight Pacific.
			$tz  = new DateTimeZone( 'America/Los_Angeles' );
			$now = new DateTime( 'now', $tz );
			$next = clone $now;
			$next->setTime( 0, 10, 0 );
			if ( $next <= $now ) {
				$next->modify( '+1 day' );
			}
			wp_schedule_event( $next->getTimestamp(), 'daily', self::CRON_SNAPSHOT );
		}
	}

	public static function menu() {
		add_submenu_page(
			'tubewriter',
			'Launch Benchmarks',
			'Launch Benchmarks',
			'manage_options',
			'tubewriter-launch',
			array( __CLASS__, 'page' )
		);
	}

	/** Pacific calendar day, same as YouTube quota reset. */
	public static function pacific_day() {
		return ( new DateTime( 'now', new DateTimeZone( 'America/Los_Angeles' ) ) )->format( 'Y-m-d' );
	}

	/**
	 * @return array{day:string,pushes_succeeded:int,pushes_failed_quota:int,pushes_queued_next_day:int,writing_credits_used:int,thumbnail_credits_standard:int,thumbnail_credits_high:int}
	 */
	public static function day_stats( $day = '' ) {
		$day  = $day ? (string) $day : self::pacific_day();
		$all  = get_option( self::OPTION_DAY_STATS, array() );
		$all  = is_array( $all ) ? $all : array();
		$row  = isset( $all[ $day ] ) && is_array( $all[ $day ] ) ? $all[ $day ] : array();
		return array(
			'day'                         => $day,
			'pushes_succeeded'            => (int) ( $row['pushes_succeeded'] ?? 0 ),
			'pushes_failed_quota'         => (int) ( $row['pushes_failed_quota'] ?? 0 ),
			'pushes_queued_next_day'      => (int) ( $row['pushes_queued_next_day'] ?? 0 ),
			'writing_credits_used'        => (int) ( $row['writing_credits_used'] ?? 0 ),
			'thumbnail_credits_standard'  => (int) ( $row['thumbnail_credits_standard'] ?? 0 ),
			'thumbnail_credits_high'      => (int) ( $row['thumbnail_credits_high'] ?? 0 ),
		);
	}

	/**
	 * @param string $field
	 * @param int    $delta
	 */
	public static function bump_day( $field, $delta = 1, $day = '' ) {
		$day   = $day ? (string) $day : self::pacific_day();
		$all   = get_option( self::OPTION_DAY_STATS, array() );
		$all   = is_array( $all ) ? $all : array();
		$row   = isset( $all[ $day ] ) && is_array( $all[ $day ] ) ? $all[ $day ] : array();
		$allowed = array(
			'pushes_succeeded',
			'pushes_failed_quota',
			'pushes_queued_next_day',
			'writing_credits_used',
			'thumbnail_credits_standard',
			'thumbnail_credits_high',
		);
		if ( ! in_array( $field, $allowed, true ) ) {
			return;
		}
		$row[ $field ] = (int) ( $row[ $field ] ?? 0 ) + (int) $delta;
		$all[ $day ]   = $row;
		// Keep ~60 days of day stats.
		if ( count( $all ) > 60 ) {
			ksort( $all );
			$all = array_slice( $all, -60, null, true );
		}
		update_option( self::OPTION_DAY_STATS, $all, false );
	}

	/**
	 * Record a push outcome without changing the publish path itself.
	 *
	 * @param string $status ok|error|…
	 * @param string $reason Optional YouTube error reason.
	 */
	public static function record_push( $status, $reason = '' ) {
		$status = (string) $status;
		$reason = (string) $reason;
		if ( 'ok' === $status || 'success' === $status || 'done' === $status ) {
			self::bump_day( 'pushes_succeeded' );
			return;
		}
		if ( 'quotaExceeded' === $reason || false !== stripos( $reason, 'quota' ) ) {
			self::bump_day( 'pushes_failed_quota' );
		}
	}

	public static function record_writing_credit() {
		self::bump_day( 'writing_credits_used' );
	}

	/**
	 * @param string $detail standard|high
	 */
	public static function record_thumbnail_credit( $detail = 'standard' ) {
		if ( 'high' === $detail ) {
			self::bump_day( 'thumbnail_credits_high' );
		} else {
			self::bump_day( 'thumbnail_credits_standard' );
		}
	}

	/**
	 * Snapshot today's project quota into the 14-day history.
	 * Safe to call on every admin view so numbers stay fresh.
	 */
	public static function sync_today_quota() {
		$used = class_exists( 'TW_YouTube' ) ? (int) TW_YouTube::quota_used() : 0;
		$day  = self::pacific_day();
		$cap  = self::PROJECT_DAILY_CAP;
		$pct  = $cap > 0 ? round( ( $used / $cap ) * 100, 1 ) : 0;
		$stats = self::day_stats( $day );
		$attempts = $stats['pushes_succeeded'] + $stats['pushes_failed_quota'];
		$rate = $attempts > 0 ? round( ( $stats['pushes_succeeded'] / $attempts ) * 100, 1 ) : null;

		$history = get_option( self::OPTION_HISTORY, array() );
		$history = is_array( $history ) ? $history : array();
		$history[ $day ] = array(
			'day'                    => $day,
			'project_used'           => $used,
			'project_used_percent'   => $pct,
			'pushes_succeeded'       => $stats['pushes_succeeded'],
			'pushes_failed_quota'    => $stats['pushes_failed_quota'],
			'pushes_queued_next_day' => $stats['pushes_queued_next_day'],
			'push_success_rate'      => $rate,
			'median_queue_wait_hours'=> null,
			'snapshotted_at'         => time(),
		);
		ksort( $history );
		if ( count( $history ) > self::HISTORY_DAYS ) {
			$history = array_slice( $history, -self::HISTORY_DAYS, null, true );
		}
		update_option( self::OPTION_HISTORY, $history, false );
		return $history[ $day ];
	}

	public static function run_daily_snapshot() {
		self::sync_today_quota();
	}

	/**
	 * @return array<int, array>
	 */
	public static function quota_history() {
		self::sync_today_quota();
		$history = get_option( self::OPTION_HISTORY, array() );
		$history = is_array( $history ) ? $history : array();
		ksort( $history );
		return array_values( $history );
	}

	/**
	 * Count UploadWriter role users who are not pending beta approval.
	 */
	public static function beta_users() {
		$q = new WP_User_Query(
			array(
				'role'   => 'tubewriter',
				'fields' => array( 'ID', 'user_registered' ),
				'number' => 500,
			)
		);
		$users = $q->get_results();
		$real  = array();
		foreach ( $users as $user ) {
			if ( user_can( $user->ID, 'manage_options' ) ) {
				continue;
			}
			if ( function_exists( 'tw_user_is_beta_pending' ) && tw_user_is_beta_pending( $user->ID ) ) {
				continue;
			}
			$real[] = $user;
		}
		return $real;
	}

	/**
	 * Active = has a draft state or a push in the window.
	 *
	 * @param int $days
	 * @return int
	 */
	public static function beta_active( $days ) {
		$cutoff = time() - ( $days * DAY_IN_SECONDS );
		$count  = 0;
		foreach ( self::beta_users() as $user ) {
			$uid = (int) $user->ID;
			$last = (int) get_user_meta( $uid, 'tw_last_active', true );
			if ( $last >= $cutoff ) {
				++$count;
				continue;
			}
			// Fallback: any video index touch or history for this user.
			$tokens = get_user_meta( $uid, 'tw_tokens', true );
			if ( $tokens && (int) strtotime( $user->user_registered ) >= $cutoff ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Aggregate video activity from the local index and history log.
	 *
	 * @return array{drafted:int,pushed:int,scheduled:int}
	 */
	public static function video_activity() {
		global $wpdb;
		$drafted = 0;
		$pushed  = 0;
		$scheduled = 0;
		if ( class_exists( 'TW_Index' ) ) {
			$table = TW_Index::table();
			$drafted = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE tw_status IN ('draft','edited','ready')" ); // phpcs:ignore
			$pushed  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE tw_status = 'pushed'" ); // phpcs:ignore
			$scheduled = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE publish_at IS NOT NULL AND publish_at > UTC_TIMESTAMP()" ); // phpcs:ignore
		}
		return array(
			'drafted'   => $drafted,
			'pushed'    => $pushed,
			'scheduled' => $scheduled,
		);
	}

	/**
	 * Sum credit counters over the last N Pacific days.
	 *
	 * @return array{writing:int,thumb_standard:int,thumb_high:int}
	 */
	public static function credit_totals( $days = 30 ) {
		$all = get_option( self::OPTION_DAY_STATS, array() );
		$all = is_array( $all ) ? $all : array();
		ksort( $all );
		$slice = array_slice( $all, -$days, null, true );
		$out = array( 'writing' => 0, 'thumb_standard' => 0, 'thumb_high' => 0 );
		foreach ( $slice as $row ) {
			$out['writing']        += (int) ( $row['writing_credits_used'] ?? 0 );
			$out['thumb_standard'] += (int) ( $row['thumbnail_credits_standard'] ?? 0 );
			$out['thumb_high']     += (int) ( $row['thumbnail_credits_high'] ?? 0 );
		}
		return $out;
	}

	/**
	 * @return array{days_over_70:int,days_over_85:int}
	 */
	public static function quota_trigger_counts() {
		$history = self::quota_history();
		$over70  = 0;
		$over85  = 0;
		foreach ( $history as $row ) {
			$used = (int) ( $row['project_used'] ?? 0 );
			if ( $used >= 7000 ) {
				++$over70;
			}
			if ( $used >= 8500 ) {
				++$over85;
			}
		}
		return array(
			'days_over_70' => $over70,
			'days_over_85' => $over85,
		);
	}

	/**
	 * Median hours between queued_at and ran_at for completed queue rows.
	 *
	 * @return float|null
	 */
	public static function median_queue_wait_hours() {
		if ( ! class_exists( 'TW_Credits' ) ) {
			return null;
		}
		$rows = TW_Credits::queue_entries( 200 );
		$waits = array();
		foreach ( $rows as $row ) {
			if ( 'done' !== ( $row['status'] ?? '' ) || empty( $row['queued_at'] ) || empty( $row['ran_at'] ) ) {
				continue;
			}
			$q = strtotime( $row['queued_at'] . ' UTC' );
			$r = strtotime( $row['ran_at'] . ' UTC' );
			if ( ! $q || ! $r || $r < $q ) {
				continue;
			}
			$waits[] = ( $r - $q ) / HOUR_IN_SECONDS;
		}
		if ( ! $waits ) {
			return null;
		}
		sort( $waits, SORT_NUMERIC );
		$n = count( $waits );
		$mid = (int) floor( $n / 2 );
		if ( 0 === $n % 2 ) {
			return round( ( $waits[ $mid - 1 ] + $waits[ $mid ] ) / 2, 1 );
		}
		return round( $waits[ $mid ], 1 );
	}

	/**
	 * Push success rate across the stored 14-day window.
	 *
	 * @return float|null
	 */
	public static function push_success_rate() {
		$history = self::quota_history();
		$ok = 0;
		$fail = 0;
		foreach ( $history as $row ) {
			$ok   += (int) ( $row['pushes_succeeded'] ?? 0 );
			$fail += (int) ( $row['pushes_failed_quota'] ?? 0 );
		}
		$total = $ok + $fail;
		if ( $total < 1 ) {
			return null;
		}
		return round( ( $ok / $total ) * 100, 1 );
	}

	/**
	 * Weekly push count from day stats (last 7 Pacific days).
	 */
	public static function pushes_last_7_days() {
		$all = get_option( self::OPTION_DAY_STATS, array() );
		$all = is_array( $all ) ? $all : array();
		$sum = 0;
		for ( $i = 0; $i < 7; $i++ ) {
			$day = ( new DateTime( 'now', new DateTimeZone( 'America/Los_Angeles' ) ) )
				->modify( '-' . $i . ' day' )
				->format( 'Y-m-d' );
			$sum += (int) ( $all[ $day ]['pushes_succeeded'] ?? 0 );
		}
		return $sum;
	}

	/**
	 * Manual gate flags Greg can tick (OAuth, policy, demo, abuse-free).
	 *
	 * @return array<string,bool>
	 */
	public static function gate_flags() {
		$flags = get_option( self::OPTION_GATES, array() );
		$flags = is_array( $flags ) ? $flags : array();
		return array(
			'oauth_verified'     => ! empty( $flags['oauth_verified'] ),
			'privacy_terms_live' => ! empty( $flags['privacy_terms_live'] ),
			'domain_verified'    => ! empty( $flags['domain_verified'] ),
			'demo_video_ready'   => ! empty( $flags['demo_video_ready'] ),
			'no_abuse'           => ! empty( $flags['no_abuse'] ),
		);
	}

	public static function save_gates() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden.', 'uploadwriter' ) );
		}
		check_admin_referer( 'tw_launch_save_gates' );
		$keys = array( 'oauth_verified', 'privacy_terms_live', 'domain_verified', 'demo_video_ready', 'no_abuse' );
		$out  = array();
		foreach ( $keys as $key ) {
			$out[ $key ] = ! empty( $_POST[ $key ] );
		}
		update_option( self::OPTION_GATES, $out, false );
		wp_safe_redirect( admin_url( 'admin.php?page=tubewriter-launch&saved=1' ) );
		exit;
	}

	public static function toggle_invite() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden.', 'uploadwriter' ) );
		}
		check_admin_referer( 'tw_launch_toggle_invite' );
		$id = isset( $_POST['entry_id'] ) ? (int) $_POST['entry_id'] : 0;
		$on = ! empty( $_POST['invited'] );
		if ( $id && function_exists( 'uw_waitlist_set_invited' ) ) {
			uw_waitlist_set_invited( $id, $on );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=tubewriter-launch&waitlist=1#waitlist' ) );
		exit;
	}

	/**
	 * Phase 1 exit gate checklist using live numbers + manual flags.
	 *
	 * @return array<int, array{label:string,met:bool,detail:string}>
	 */
	public static function exit_gate() {
		$beta_count = class_exists( 'TW_Credits' ) ? TW_Credits::beta_active_count() : count( self::beta_users() );
		$pushes_week = self::pushes_last_7_days();
		$rate = self::push_success_rate();
		$flags = self::gate_flags();
		$history = self::quota_history();
		$weeks = count( $history ) >= 14;

		return array(
			array(
				'label'  => 'At least 20 real beta users (not test accounts)',
				'met'    => $beta_count >= 20,
				'detail' => $beta_count . ' active beta users (cap ' . self::BETA_CAP . ')',
			),
			array(
				'label'  => 'At least 2 weeks of consistent use, at least 10 pushes a week',
				'met'    => $weeks && $pushes_week >= 10,
				'detail' => $pushes_week . ' pushes in the last 7 days' . ( $weeks ? '' : ', need 14 days of history' ),
			),
			array(
				'label'  => 'Push success rate at least 95 percent',
				'met'    => null !== $rate && $rate >= 95,
				'detail' => null === $rate ? 'No push attempts recorded yet' : $rate . '%',
			),
			array(
				'label'  => 'No abuse, no policy violations, no spam pushes',
				'met'    => ! empty( $flags['no_abuse'] ),
				'detail' => ! empty( $flags['no_abuse'] ) ? 'Marked clean' : 'Mark when logs look clean',
			),
			array(
				'label'  => 'OAuth verification approved',
				'met'    => ! empty( $flags['oauth_verified'] ),
				'detail' => ! empty( $flags['oauth_verified'] ) ? 'Marked approved' : 'Pending',
			),
			array(
				'label'  => 'Privacy policy and terms live, domain verified, demo video ready',
				'met'    => ! empty( $flags['privacy_terms_live'] ) && ! empty( $flags['domain_verified'] ) && ! empty( $flags['demo_video_ready'] ),
				'detail' => 'Privacy/terms: ' . ( ! empty( $flags['privacy_terms_live'] ) ? 'yes' : 'no' )
					. ', domain: ' . ( ! empty( $flags['domain_verified'] ) ? 'yes' : 'no' )
					. ', demo: ' . ( ! empty( $flags['demo_video_ready'] ) ? 'yes' : 'no' ),
			),
		);
	}

	/**
	 * Whether it is a smart time to apply for a YouTube quota extension.
	 *
	 * @return array{ready:bool,reasons:string[]}
	 */
	public static function quota_extension_ready() {
		$flags   = self::gate_flags();
		$triggers = self::quota_trigger_counts();
		$waitlist = function_exists( 'uw_waitlist_count' ) ? uw_waitlist_count() : 0;
		$beta     = count( self::beta_users() );
		$reasons  = array();
		$checks   = array();

		$checks['oauth'] = ! empty( $flags['oauth_verified'] );
		if ( ! $checks['oauth'] ) {
			$reasons[] = 'OAuth verification not marked approved yet.';
		}

		$checks['users'] = $beta >= 20 && count( self::quota_history() ) >= 14;
		if ( ! $checks['users'] ) {
			$reasons[] = 'Need at least 20 real users and 2 weeks of use.';
		}

		$checks['usage'] = ( $triggers['days_over_70'] >= 7 ) || ( $waitlist >= 100 );
		if ( ! $checks['usage'] ) {
			$reasons[] = 'Need 7 of the last 14 days at 7,000+ units, or a waitlist of 100+, or both.';
		}

		$checks['clean'] = ! empty( $flags['no_abuse'] );
		if ( ! $checks['clean'] ) {
			$reasons[] = 'Mark logs clean (no abuse) before applying.';
		}

		return array(
			'ready'   => ! in_array( false, $checks, true ),
			'reasons' => $reasons,
		);
	}

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$today     = self::sync_today_quota();
		$history   = self::quota_history();
		$triggers  = self::quota_trigger_counts();
		$beta      = self::beta_users();
		$activity  = self::video_activity();
		$credits   = self::credit_totals( 30 );
		$gates     = self::exit_gate();
		$flags     = self::gate_flags();
		$waitlist  = function_exists( 'uw_waitlist_entries' ) ? uw_waitlist_entries() : array();
		$wait_total = function_exists( 'uw_waitlist_count' ) ? uw_waitlist_count() : 0;
		$ext       = self::quota_extension_ready();
		$rate      = self::push_success_rate();
		$active_7  = self::beta_active( 7 );
		$active_30 = self::beta_active( 30 );
		?>
		<div class="wrap tw-wrap">
			<h1>Launch Benchmarks</h1>
			<p>Phase 1 Beta, BYOK only. Shared Google Cloud project <code>tubewriter-514118</code>. Daily cap <?php echo esc_html( number_format_i18n( self::PROJECT_DAILY_CAP ) ); ?> YouTube Data API units, resets midnight Pacific. Revenue is $0 in Phase 1.</p>
			<?php if ( ! empty( $_GET['saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p>Gate flags saved.</p></div>
			<?php endif; ?>

			<div class="tw-card" style="margin-top:16px;">
				<h2>Today (Pacific)</h2>
				<table class="widefat striped">
					<tbody>
						<tr><th>project_used</th><td><?php echo esc_html( number_format_i18n( (int) $today['project_used'] ) ); ?> / <?php echo esc_html( number_format_i18n( self::PROJECT_DAILY_CAP ) ); ?></td></tr>
						<tr><th>project_used_percent</th><td><?php echo esc_html( (string) $today['project_used_percent'] ); ?>%</td></tr>
						<tr><th>pushes_succeeded</th><td><?php echo esc_html( number_format_i18n( (int) $today['pushes_succeeded'] ) ); ?></td></tr>
						<tr><th>pushes_failed_quota</th><td><?php echo esc_html( number_format_i18n( (int) $today['pushes_failed_quota'] ) ); ?></td></tr>
						<tr><th>pushes_queued_next_day</th><td><?php echo esc_html( number_format_i18n( (int) $today['pushes_queued_next_day'] ) ); ?> <span class="description">(live queue: <?php echo esc_html( (string) ( class_exists( 'TW_Credits' ) ? TW_Credits::queue_count() : 0 ) ); ?> waiting)</span></td></tr>
						<tr><th>push_success_rate (14d)</th><td><?php echo null === $rate ? 'n/a' : esc_html( $rate . '%' ); ?> <span class="description">(target ≥ 95%)</span></td></tr>
						<tr><th>median_queue_wait</th><td><?php
						$median = self::median_queue_wait_hours();
						echo null === $median ? 'n/a' : esc_html( $median . ' hours' );
						?> <span class="description">(target under 24 hours)</span></td></tr>
						<tr><th>days_over_70_percent (14d)</th><td><?php echo esc_html( (string) $triggers['days_over_70'] ); ?> <span class="description">(trigger: 7+ days at 7,000+)</span></td></tr>
						<tr><th>days_over_85_percent (14d)</th><td><?php echo esc_html( (string) $triggers['days_over_85'] ); ?> <span class="description">(trigger: 3+ days at 8,500+, pause new signups that week)</span></td></tr>
					</tbody>
				</table>
			</div>

			<div class="tw-card" style="margin-top:16px;">
				<h2>14 day quota history</h2>
				<table class="widefat striped">
					<thead>
						<tr>
							<th>Day (Pacific)</th>
							<th>Used</th>
							<th>%</th>
							<th>Pushes ok</th>
							<th>Failed quota</th>
							<th>Queued</th>
							<th>Success %</th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $history ) ) : ?>
							<tr><td colspan="7">No snapshots yet. Today’s row is created when you open this page.</td></tr>
						<?php else : ?>
							<?php foreach ( array_reverse( $history ) as $row ) : ?>
								<tr>
									<td><?php echo esc_html( (string) $row['day'] ); ?></td>
									<td><?php echo esc_html( number_format_i18n( (int) $row['project_used'] ) ); ?></td>
									<td><?php echo esc_html( (string) $row['project_used_percent'] ); ?>%</td>
									<td><?php echo esc_html( number_format_i18n( (int) $row['pushes_succeeded'] ) ); ?></td>
									<td><?php echo esc_html( number_format_i18n( (int) $row['pushes_failed_quota'] ) ); ?></td>
									<td><?php echo esc_html( number_format_i18n( (int) $row['pushes_queued_next_day'] ) ); ?></td>
									<td><?php echo null === $row['push_success_rate'] ? 'n/a' : esc_html( $row['push_success_rate'] . '%' ); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>

			<div class="tw-card" style="margin-top:16px;">
				<h2>Beta users (Phase 1)</h2>
				<table class="widefat striped">
					<tbody>
						<tr><th>signups_total (approved beta)</th><td><?php echo esc_html( (string) ( class_exists( 'TW_Credits' ) ? TW_Credits::beta_active_count() : count( $beta ) ) ); ?> / <?php echo esc_html( (string) self::BETA_CAP ); ?> <span class="description">Beta users: X of 25</span></td></tr>
						<tr><th>waitlist_total</th><td><?php echo esc_html( number_format_i18n( $wait_total ) ); ?></td></tr>
						<tr><th>beta_active_7d</th><td><?php echo esc_html( (string) $active_7 ); ?></td></tr>
						<tr><th>beta_active_30d</th><td><?php echo esc_html( (string) $active_30 ); ?></td></tr>
						<tr><th>videos_drafted</th><td><?php echo esc_html( number_format_i18n( $activity['drafted'] ) ); ?></td></tr>
						<tr><th>videos_pushed</th><td><?php echo esc_html( number_format_i18n( $activity['pushed'] ) ); ?></td></tr>
						<tr><th>videos_scheduled</th><td><?php echo esc_html( number_format_i18n( $activity['scheduled'] ) ); ?></td></tr>
						<tr><th>writing_credits_used (30d)</th><td><?php echo esc_html( number_format_i18n( $credits['writing'] ) ); ?></td></tr>
						<tr><th>thumbnail_credits_used (30d)</th><td>standard <?php echo esc_html( number_format_i18n( $credits['thumb_standard'] ) ); ?>, high-detail <?php echo esc_html( number_format_i18n( $credits['thumb_high'] ) ); ?></td></tr>
						<tr><th>retention_7d / activation</th><td>Tracked manually in Phase 1 until cohort tooling lands. Targets: retention ≥ 60%, activation ≥ 80%.</td></tr>
					</tbody>
				</table>
			</div>

			<?php if ( ! empty( $_GET['invited'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p>Invite sent and beta seat granted.</p></div>
			<?php endif; ?>
			<?php if ( ! empty( $_GET['invite_error'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-error is-dismissible"><p><?php echo esc_html( rawurldecode( (string) wp_unslash( $_GET['invite_error'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?></p></div>
			<?php endif; ?>

			<div class="tw-card" style="margin-top:16px;" id="queue">
				<h2>Push queue</h2>
				<p>Queued when the shared project hits 10,000 units. Oldest first after midnight Pacific. Credits and units are not spent until a row runs.</p>
				<table class="widefat striped">
					<thead>
						<tr>
							<th>ID</th>
							<th>Video</th>
							<th>User</th>
							<th>Status</th>
							<th>Queued (UTC)</th>
							<th>Ran (UTC)</th>
							<th>Units</th>
							<th>Credit spent</th>
							<th>Fail reason</th>
						</tr>
					</thead>
					<tbody>
						<?php
						$queue_rows = class_exists( 'TW_Credits' ) ? TW_Credits::queue_entries( 50 ) : array();
						if ( empty( $queue_rows ) ) :
							?>
							<tr><td colspan="9">No queued pushes.</td></tr>
						<?php else : ?>
							<?php foreach ( $queue_rows as $qrow ) : ?>
								<tr>
									<td><?php echo esc_html( (string) $qrow['id'] ); ?></td>
									<td><code><?php echo esc_html( (string) $qrow['video_id'] ); ?></code></td>
									<td><?php echo esc_html( (string) $qrow['user_id'] ); ?></td>
									<td><?php echo esc_html( (string) $qrow['status'] ); ?></td>
									<td><?php echo esc_html( (string) $qrow['queued_at'] ); ?></td>
									<td><?php echo esc_html( (string) ( $qrow['ran_at'] ?? '' ) ); ?></td>
									<td><?php echo esc_html( (string) ( (int) $qrow['units_used'] ?: (int) $qrow['units_estimate'] ) ); ?></td>
									<td><?php echo ! empty( $qrow['credit_spent'] ) ? 'Yes' : 'No'; ?></td>
									<td><?php echo esc_html( (string) ( $qrow['fail_reason'] ?? '' ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>

			<div class="tw-card" style="margin-top:16px;" id="waitlist">
				<h2>Waitlist (invite Free and Creator first, Studio last)</h2>
				<p>Beta users: <?php echo esc_html( (string) ( class_exists( 'TW_Credits' ) ? TW_Credits::beta_active_count() : count( $beta ) ) ); ?> of <?php echo esc_html( (string) self::BETA_CAP ); ?>. Waitlist: <?php echo esc_html( number_format_i18n( $wait_total ) ); ?>. Invite creates or unlocks a workspace account and emails them.</p>
				<table class="widefat striped">
					<thead>
						<tr>
							<th>#</th>
							<th>Email</th>
							<th>Channel</th>
							<th>Videos / month</th>
							<th>Has key</th>
							<th>Suggested plan</th>
							<th>Invited</th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $waitlist ) ) : ?>
							<tr><td colspan="8">No waitlist entries yet.</td></tr>
						<?php else : ?>
							<?php
							// Sort: Free/Creator interest first, Studio-heavy (13+) last.
							usort(
								$waitlist,
								static function ( $a, $b ) {
									$rank = static function ( $row ) {
										$v = (string) ( $row['videos_per_month'] ?? '' );
										if ( in_array( $v, array( '13-30', '31-plus' ), true ) ) {
											return 2;
										}
										if ( '5-12' === $v ) {
											return 1;
										}
										return 0;
									};
									$d = $rank( $a ) - $rank( $b );
									if ( 0 !== $d ) {
										return $d;
									}
									return (int) $a['position'] - (int) $b['position'];
								}
							);
							foreach ( $waitlist as $entry ) :
								$pos = (int) $entry['position'];
								$cohort = $pos <= self::BETA_CAP;
								$suggest = class_exists( 'TW_Credits' ) ? TW_Credits::invite_plan_for_entry( $entry ) : 'free';
								?>
								<tr>
									<td><?php echo esc_html( (string) $pos ); ?><?php echo $cohort ? ' <span class="description">(beta)</span>' : ''; ?></td>
									<td><?php echo esc_html( (string) $entry['email'] ); ?></td>
									<td><a href="<?php echo esc_url( (string) $entry['channel_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( (string) $entry['channel_url'] ); ?></a></td>
									<td><?php echo esc_html( (string) $entry['videos_per_month'] ); ?></td>
									<td><?php echo esc_html( (string) $entry['has_key'] ); ?></td>
									<td><?php echo esc_html( $suggest ); ?></td>
									<td><?php echo ! empty( $entry['invited'] ) ? 'Yes' : 'No'; ?></td>
									<td>
										<?php if ( empty( $entry['invited'] ) ) : ?>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
											<?php wp_nonce_field( 'tw_waitlist_invite' ); ?>
											<input type="hidden" name="action" value="tw_waitlist_invite">
											<input type="hidden" name="entry_id" value="<?php echo esc_attr( (string) $entry['id'] ); ?>">
											<button type="submit" class="button button-primary button-small">Invite</button>
										</form>
										<?php endif; ?>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
											<?php wp_nonce_field( 'tw_launch_toggle_invite' ); ?>
											<input type="hidden" name="action" value="tw_launch_toggle_invite">
											<input type="hidden" name="entry_id" value="<?php echo esc_attr( (string) $entry['id'] ); ?>">
											<input type="hidden" name="invited" value="<?php echo empty( $entry['invited'] ) ? '1' : '0'; ?>">
											<button type="submit" class="button button-small"><?php echo empty( $entry['invited'] ) ? 'Mark invited' : 'Clear invite'; ?></button>
										</form>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>

			<div class="tw-card" style="margin-top:16px;">
				<h2>Phase 1 exit gate</h2>
				<p>All items must be true before moving to Phase 2 Soft Launch (50 users max).</p>
				<table class="widefat striped">
					<thead><tr><th>Gate</th><th>Status</th><th>Detail</th></tr></thead>
					<tbody>
						<?php foreach ( $gates as $gate ) : ?>
							<tr>
								<td><?php echo esc_html( $gate['label'] ); ?></td>
								<td><?php echo $gate['met'] ? '<strong style="color:#007017;">Met</strong>' : '<strong style="color:#b32d2e;">Not yet</strong>'; ?></td>
								<td><?php echo esc_html( $gate['detail'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:16px;">
					<?php wp_nonce_field( 'tw_launch_save_gates' ); ?>
					<input type="hidden" name="action" value="tw_launch_save_gates">
					<h3>Manual flags</h3>
					<label style="display:block;margin:6px 0;"><input type="checkbox" name="oauth_verified" value="1" <?php checked( $flags['oauth_verified'] ); ?>> OAuth verification approved</label>
					<label style="display:block;margin:6px 0;"><input type="checkbox" name="privacy_terms_live" value="1" <?php checked( $flags['privacy_terms_live'] ); ?>> Privacy policy and terms live</label>
					<label style="display:block;margin:6px 0;"><input type="checkbox" name="domain_verified" value="1" <?php checked( $flags['domain_verified'] ); ?>> Domain verified</label>
					<label style="display:block;margin:6px 0;"><input type="checkbox" name="demo_video_ready" value="1" <?php checked( $flags['demo_video_ready'] ); ?>> Demo video ready</label>
					<label style="display:block;margin:6px 0;"><input type="checkbox" name="no_abuse" value="1" <?php checked( $flags['no_abuse'] ); ?>> No abuse / policy violations / spam pushes</label>
					<?php submit_button( 'Save gate flags' ); ?>
				</form>
			</div>

			<div class="tw-card" style="margin-top:16px;">
				<h2>Quota extension trigger</h2>
				<?php if ( $ext['ready'] ) : ?>
					<p><strong style="color:#007017;">Ready to apply.</strong> Request 50,000 units/day for project <code>tubewriter-514118</code>. Best window: data in Dec 2026, apply early Jan 2027.</p>
				<?php else : ?>
					<p><strong>Not yet.</strong> Do not apply on day 1. Do not wait until users fail daily.</p>
					<ul>
						<?php foreach ( $ext['reasons'] as $reason ) : ?>
							<li><?php echo esc_html( $reason ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
				<p class="description">If denied, stay in Phase 2 at 50 users max and reapply in 30 days. Do not open a second Google Cloud project to work around the limit.</p>
			</div>

			<div class="tw-card" style="margin-top:16px;">
				<h2>Phase 2 revenue (planning only)</h2>
				<p>Phase 1 revenue is $0. Do not fake revenue here. Soft launch target mix at 50 users, half hosted / half BYOK, about $304.55 MRR (range $200 to $400). Stripe and support metrics start when checkout opens.</p>
			</div>
		</div>
		<?php
	}
}
