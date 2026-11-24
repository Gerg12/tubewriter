<?php
/**
 * Pricing waitlist: store beta interest, no checkout, no confirmation email with dates.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const UW_WAITLIST_DB_VERSION = '1';
const UW_WAITLIST_OPTION     = 'uw_waitlist_db_version';
const UW_WAITLIST_BETA_CAP   = 25;

/**
 * @return string
 */
function uw_waitlist_table() {
	global $wpdb;
	return $wpdb->prefix . 'uw_waitlist';
}

function uw_waitlist_maybe_install() {
	if ( get_option( UW_WAITLIST_OPTION ) === UW_WAITLIST_DB_VERSION ) {
		return;
	}
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$table   = uw_waitlist_table();
	$charset = $wpdb->get_charset_collate();
	dbDelta(
		"CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			email varchar(190) NOT NULL,
			channel_url text NOT NULL,
			videos_per_month varchar(20) NOT NULL DEFAULT '',
			has_key varchar(20) NOT NULL DEFAULT '',
			position int(10) unsigned NOT NULL DEFAULT 0,
			invited tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY email (email),
			KEY position (position),
			KEY invited (invited)
		) {$charset};"
	);
	update_option( UW_WAITLIST_OPTION, UW_WAITLIST_DB_VERSION, false );
}
add_action( 'init', 'uw_waitlist_maybe_install' );

/**
 * @return array{type:string,message:string}|null
 */
function uw_waitlist_flash() {
	if ( empty( $_GET['uw_waitlist'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return null;
	}
	$code = sanitize_key( wp_unslash( $_GET['uw_waitlist'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( 'ok' === $code ) {
		$pos   = isset( $_GET['pos'] ) ? max( 1, (int) $_GET['pos'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$total = isset( $_GET['total'] ) ? max( $pos, (int) $_GET['total'] ) : $pos; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return array(
			'type'    => 'success',
			'message' => sprintf( 'You are on the list, position %d of %d.', $pos, $total ),
		);
	}
	if ( 'exists' === $code ) {
		$pos   = isset( $_GET['pos'] ) ? max( 1, (int) $_GET['pos'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$total = isset( $_GET['total'] ) ? max( $pos, (int) $_GET['total'] ) : $pos; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return array(
			'type'    => 'info',
			'message' => sprintf( 'You are already on the list, position %d of %d.', $pos, $total ),
		);
	}
	return array(
		'type'    => 'error',
		'message' => 'Could not save that request. Check the fields and try again.',
	);
}

/**
 * @return int
 */
function uw_waitlist_count() {
	global $wpdb;
	uw_waitlist_maybe_install();
	return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . uw_waitlist_table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
}

/**
 * @return array<int, array>
 */
function uw_waitlist_entries( $limit = 500 ) {
	global $wpdb;
	uw_waitlist_maybe_install();
	$limit = max( 1, min( 2000, (int) $limit ) );
	$rows  = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT * FROM ' . uw_waitlist_table() . ' ORDER BY position ASC, id ASC LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$limit
		),
		ARRAY_A
	);
	return is_array( $rows ) ? $rows : array();
}

/**
 * @param array $data
 * @return array{ok:bool,position:int,total:int,exists?:bool}|WP_Error
 */
function uw_waitlist_add( array $data ) {
	global $wpdb;
	uw_waitlist_maybe_install();

	$email   = sanitize_email( (string) ( $data['email'] ?? '' ) );
	$channel = esc_url_raw( (string) ( $data['channel_url'] ?? '' ) );
	$videos  = sanitize_key( (string) ( $data['videos_per_month'] ?? '' ) );
	$has_key = sanitize_key( (string) ( $data['has_key'] ?? '' ) );

	$allowed_videos = array( '1-4', '5-12', '13-30', '31-plus' );
	$allowed_keys   = array( 'yes', 'no', 'not_yet' );

	if ( ! is_email( $email ) ) {
		return new WP_Error( 'uw_waitlist', 'Enter a valid email.' );
	}
	if ( '' === $channel || ! preg_match( '#^https?://#i', $channel ) ) {
		return new WP_Error( 'uw_waitlist', 'Enter a valid YouTube channel URL.' );
	}
	if ( ! in_array( $videos, $allowed_videos, true ) ) {
		return new WP_Error( 'uw_waitlist', 'Pick how many videos you publish per month.' );
	}
	if ( ! in_array( $has_key, $allowed_keys, true ) ) {
		return new WP_Error( 'uw_waitlist', 'Tell us whether you have an AI key.' );
	}

	$table  = uw_waitlist_table();
	$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT id, position FROM ' . $table . ' WHERE email = %s', $email ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$total  = uw_waitlist_count();

	if ( $existing ) {
		return array(
			'ok'       => true,
			'exists'   => true,
			'position' => (int) $existing['position'],
			'total'    => max( 1, $total ),
		);
	}

	$position = $total + 1;
	$inserted = $wpdb->insert(
		$table,
		array(
			'email'            => $email,
			'channel_url'      => $channel,
			'videos_per_month' => $videos,
			'has_key'          => $has_key,
			'position'         => $position,
			'invited'          => 0,
			'created_at'       => current_time( 'mysql', true ),
		),
		array( '%s', '%s', '%s', '%s', '%d', '%d', '%s' )
	);

	if ( false === $inserted ) {
		return new WP_Error( 'uw_waitlist', 'Could not save that request.' );
	}

	return array(
		'ok'       => true,
		'exists'   => false,
		'position' => $position,
		'total'    => $position,
	);
}

/**
 * Mark the first N waitlist rows as invited for the beta cohort display.
 *
 * @param int $id
 * @param bool $invited
 */
function uw_waitlist_set_invited( $id, $invited ) {
	global $wpdb;
	$wpdb->update(
		uw_waitlist_table(),
		array( 'invited' => $invited ? 1 : 0 ),
		array( 'id' => (int) $id ),
		array( '%d' ),
		array( '%d' )
	);
}

function uw_waitlist_handle_join() {
	$base = home_url( '/pricing/' );
	$go   = static function ( $args ) use ( $base ) {
		wp_safe_redirect( add_query_arg( $args, $base ) . '#waitlist' );
		exit;
	};
	if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'uw_waitlist_join' ) ) {
		$go( array( 'uw_waitlist' => 'error' ) );
	}

	$result = uw_waitlist_add(
		array(
			'email'            => isset( $_POST['email'] ) ? wp_unslash( $_POST['email'] ) : '',
			'channel_url'      => isset( $_POST['channel_url'] ) ? wp_unslash( $_POST['channel_url'] ) : '',
			'videos_per_month' => isset( $_POST['videos_per_month'] ) ? wp_unslash( $_POST['videos_per_month'] ) : '',
			'has_key'          => isset( $_POST['has_key'] ) ? wp_unslash( $_POST['has_key'] ) : '',
		)
	);

	if ( is_wp_error( $result ) ) {
		$go( array( 'uw_waitlist' => 'error' ) );
	}

	$code = ! empty( $result['exists'] ) ? 'exists' : 'ok';
	$go(
		array(
			'uw_waitlist' => $code,
			'pos'         => (int) $result['position'],
			'total'       => (int) $result['total'],
		)
	);
}
add_action( 'admin_post_nopriv_uw_waitlist_join', 'uw_waitlist_handle_join' );
add_action( 'admin_post_uw_waitlist_join', 'uw_waitlist_handle_join' );
