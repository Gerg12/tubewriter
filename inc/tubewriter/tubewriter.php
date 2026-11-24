<?php
/**
 * UploadWriter app bootstrap (lives in the theme; was a standalone plugin).
 *
 * Public product name: UploadWriter. Working title / code: TubeWriter / tubewriter.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Old plugin still active: skip so classes are not declared twice.
if ( defined( 'TW_VERSION' ) ) {
	return;
}

define( 'TW_VERSION', '2.9.0' );
define( 'TW_DIR', trailingslashit( get_template_directory() ) . 'inc/tubewriter/' );
define( 'TW_URL', trailingslashit( get_template_directory_uri() ) . 'inc/tubewriter/' );
define( 'TW_BRAND', 'UploadWriter' );

define( 'TW_SCOPE', 'https://www.googleapis.com/auth/youtube.force-ssl' );
define( 'TW_DEFAULT_MODEL', 'gemini-3.6-flash' );
define( 'TW_DEFAULT_IMAGE_MODEL', 'gemini-2.5-flash-image' );
define( 'TW_DEFAULT_OPENAI_IMAGE_MODEL', 'gpt-image-2.5-sunburst' );
define( 'TW_DEFAULT_OPENAI_MODEL', 'gpt-4.1' );
define( 'TW_DEFAULT_IMAGE_PROVIDER', 'openai' );

require_once TW_DIR . 'includes/class-tw-context.php';
require_once TW_DIR . 'includes/class-tw-crypto.php';
require_once TW_DIR . 'includes/class-tw-store.php';
require_once TW_DIR . 'includes/class-tw-settings.php';
require_once TW_DIR . 'includes/class-tw-oauth.php';
require_once TW_DIR . 'includes/class-tw-youtube.php';
require_once TW_DIR . 'includes/class-tw-transcript.php';
require_once TW_DIR . 'includes/class-tw-validator.php';
require_once TW_DIR . 'includes/class-tw-ai.php';
require_once TW_DIR . 'includes/class-tw-history.php';
require_once TW_DIR . 'includes/class-tw-plan.php';
require_once TW_DIR . 'includes/class-tw-index.php';
require_once TW_DIR . 'includes/class-tw-calendar.php';
add_action( 'init', array( 'TW_Calendar', 'maybe_install' ) );
add_action( 'template_redirect', array( 'TW_Calendar', 'serve_feed' ), -100 );
require_once TW_DIR . 'includes/class-tw-rate-limit.php';
require_once TW_DIR . 'includes/class-tw-thumb-library.php';
require_once TW_DIR . 'includes/class-tw-privacy.php';
require_once TW_DIR . 'includes/class-tw-admin.php';
require_once TW_DIR . 'includes/class-tw-frontend.php';

add_action( TW_Settings::CRON_CLEANUP_THUMBS, function () {
	require_once TW_DIR . 'includes/class-tw-admin.php';
	TW_Admin::cleanup_generated_thumbs();
} );

add_action( TW_Settings::CRON_CLEANUP_YT, array( 'TW_OAuth', 'cleanup_disconnected_cache' ) );

/**
 * One-time / theme-switch setup (replaces plugin activation hooks).
 */
function tw_theme_activate() {
	TW_Settings::activate();
	TW_Index::maybe_install();
	tw_ensure_caps();
	TW_Frontend::register_rewrites();
	flush_rewrite_rules();
	update_option( 'tw_front_rewrite', TW_VERSION, false );
}

/** Ensure the UploadWriter role and use_uploadwriter capability exist. */
function tw_ensure_caps() {
	if ( ! get_role( 'tubewriter' ) ) {
		add_role(
			'tubewriter',
			'UploadWriter',
			array(
				'read'             => true,
				'upload_files'     => true,
				'use_uploadwriter' => true,
			)
		);
		return;
	}
	$role = get_role( 'tubewriter' );
	if ( $role && ! $role->has_cap( 'use_uploadwriter' ) ) {
		$role->add_cap( 'use_uploadwriter' );
	}
}

/** True when the current user may use the front UploadWriter app. */
function tw_user_can_app() {
	if ( ! current_user_can( 'use_uploadwriter' ) && ! current_user_can( 'manage_options' ) ) {
		return false;
	}
	if ( current_user_can( 'manage_options' ) ) {
		return true;
	}
	if ( TW_Settings::beta_access() && tw_user_is_beta_pending( get_current_user_id() ) ) {
		return false;
	}
	return true;
}

/** True when a user is waiting for beta approval (only set on new signups while beta mode is on). */
function tw_user_is_beta_pending( $user_id ) {
	return '1' === (string) get_user_meta( (int) $user_id, 'tw_beta_pending', true );
}

/** Mark a user as waiting for beta approval. */
function tw_user_set_beta_pending( $user_id, $pending ) {
	$user_id = (int) $user_id;
	if ( ! $user_id ) {
		return;
	}
	if ( $pending ) {
		update_user_meta( $user_id, 'tw_beta_pending', '1' );
	} else {
		delete_user_meta( $user_id, 'tw_beta_pending' );
	}
}

/**
 * True when a user may use AI thumbnail brief/create.
 * Site admins always can. Others need the site-wide setting, or a per-user grant.
 *
 * @param int|null $user_id User id, or null for the current user.
 */
function tw_user_can_thumbnails( $user_id = null ) {
	$user_id = $user_id ? (int) $user_id : get_current_user_id();
	if ( ! $user_id ) {
		return false;
	}
	if ( user_can( $user_id, 'manage_options' ) ) {
		return true;
	}
	if ( ! user_can( $user_id, 'use_uploadwriter' ) ) {
		return false;
	}
	if ( TW_Settings::thumb_ai_for_users() ) {
		return true;
	}
	return tw_user_has_thumb_ai( $user_id );
}

/** Per-user grant for AI thumbnails (user meta tw_thumb_ai = 1). */
function tw_user_has_thumb_ai( $user_id ) {
	return '1' === (string) get_user_meta( (int) $user_id, 'tw_thumb_ai', true );
}

/** Set or clear the per-user AI thumbnail grant. */
function tw_user_set_thumb_ai( $user_id, $enabled ) {
	$user_id = (int) $user_id;
	if ( ! $user_id ) {
		return;
	}
	if ( $enabled ) {
		update_user_meta( $user_id, 'tw_thumb_ai', '1' );
	} else {
		delete_user_meta( $user_id, 'tw_thumb_ai' );
	}
}
add_action( 'after_switch_theme', 'tw_theme_activate' );

add_action( 'switch_theme', array( 'TW_Settings', 'deactivate' ) );

/**
 * Boot the app. Safe to call after plugins_loaded (theme functions.php timing).
 */
function tw_bootstrap() {
	TW_Settings::maybe_upgrade_prompt();
	TW_Settings::maybe_upgrade_thumb_prompts();
	TW_Settings::maybe_upgrade_gemini_model();
	TW_Settings::maybe_upgrade_openai_image_model();
	TW_Settings::schedule_thumb_cleanup();
	TW_Settings::schedule_youtube_cache_cleanup();
	TW_Index::maybe_install();
	tw_ensure_caps();

	new TW_Frontend();
	TW_Privacy::init();
	TW_Crypto::maybe_migrate_site_secrets();
	TW_Crypto::ensure_fingerprint();
	TW_Crypto::maybe_admin_notice();

	if ( get_option( 'tw_front_rewrite' ) !== TW_VERSION ) {
		update_option( 'tw_front_rewrite', TW_VERSION, false );
		update_option( 'tw_flush_rewrite', 1, false );
	}

	if ( is_admin() ) {
		new TW_Admin();
	}
}

if ( did_action( 'plugins_loaded' ) ) {
	tw_bootstrap();
} else {
	add_action( 'plugins_loaded', 'tw_bootstrap' );
}
