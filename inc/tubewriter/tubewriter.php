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

define( 'TW_VERSION', '2.8.0' );
define( 'TW_DIR', trailingslashit( get_template_directory() ) . 'inc/tubewriter/' );
define( 'TW_URL', trailingslashit( get_template_directory_uri() ) . 'inc/tubewriter/' );
define( 'TW_BRAND', 'UploadWriter' );

// Desktop OAuth clients accept any loopback redirect. Nothing listens here on purpose:
// the browser shows a "can't connect" page and the user pastes that URL back into TubeWriter.
define( 'TW_REDIRECT_URI', 'http://127.0.0.1:8765/' );
define( 'TW_SCOPE', 'https://www.googleapis.com/auth/youtube.force-ssl' );
define( 'TW_DEFAULT_MODEL', 'gemini-3.8-flash' );
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
require_once TW_DIR . 'includes/class-tw-rate-limit.php';
require_once TW_DIR . 'includes/class-tw-admin.php';
require_once TW_DIR . 'includes/class-tw-frontend.php';

add_action( TW_Settings::CRON_CLEANUP_THUMBS, function () {
	require_once TW_DIR . 'includes/class-tw-admin.php';
	TW_Admin::cleanup_generated_thumbs();
} );

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
	return current_user_can( 'use_uploadwriter' ) || current_user_can( 'manage_options' );
}

/**
 * True when the current user may use AI thumbnail brief/create.
 * Site admins always can. Other UploadWriter users only when the site setting is on.
 */
function tw_user_can_thumbnails() {
	if ( current_user_can( 'manage_options' ) ) {
		return true;
	}
	if ( ! tw_user_can_app() ) {
		return false;
	}
	return TW_Settings::thumb_ai_for_users();
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
	TW_Index::maybe_install();
	tw_ensure_caps();

	new TW_Frontend();

	if ( get_option( 'tw_front_rewrite' ) !== TW_VERSION ) {
		update_option( 'tw_front_rewrite', TW_VERSION, false );
		update_option( 'tw_flush_rewrite', 1, false );
	}

	if ( is_admin() ) {
		require_once TW_DIR . 'includes/class-tw-videos-table.php';
		new TW_Admin();
	}
}

if ( did_action( 'plugins_loaded' ) ) {
	tw_bootstrap();
} else {
	add_action( 'plugins_loaded', 'tw_bootstrap' );
}
