<?php
/**
 * Plugin Name: TubeWriter
 * Description: Pulls a YouTube video's transcript, writes the title, description, chapters, tags and hashtags with Gemini, and pushes them back to YouTube. Bring your own Google and Gemini credentials.
 * Version: 2.5.2
 * Author: Gregory S Johnson LLC
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * Text Domain: tubewriter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TW_VERSION', '2.5.2' );
define( 'TW_DIR', plugin_dir_path( __FILE__ ) );
define( 'TW_URL', plugin_dir_url( __FILE__ ) );

// Desktop OAuth clients accept any loopback redirect. Nothing listens here on purpose:
// the browser shows a "can't connect" page and the user pastes that URL back into TubeWriter.
define( 'TW_REDIRECT_URI', 'http://127.0.0.1:8765/' );
define( 'TW_SCOPE', 'https://www.googleapis.com/auth/youtube.force-ssl' );
define( 'TW_DEFAULT_MODEL', 'gemini-3.5-flash-lite' );
define( 'TW_DEFAULT_IMAGE_MODEL', 'gemini-2.5-flash-image' );
define( 'TW_DEFAULT_OPENAI_IMAGE_MODEL', 'chatgpt-image-latest' );
define( 'TW_DEFAULT_OPENAI_MODEL', 'gpt-4.1' );
define( 'TW_DEFAULT_IMAGE_PROVIDER', 'openai' );

require_once TW_DIR . 'includes/class-tw-settings.php';
require_once TW_DIR . 'includes/class-tw-oauth.php';
require_once TW_DIR . 'includes/class-tw-youtube.php';
require_once TW_DIR . 'includes/class-tw-transcript.php';
require_once TW_DIR . 'includes/class-tw-validator.php';
require_once TW_DIR . 'includes/class-tw-ai.php';
require_once TW_DIR . 'includes/class-tw-history.php';
require_once TW_DIR . 'includes/class-tw-plan.php';
require_once TW_DIR . 'includes/class-tw-index.php';
require_once TW_DIR . 'includes/class-tw-admin.php';

register_activation_hook( __FILE__, array( 'TW_Settings', 'activate' ) );
register_activation_hook( __FILE__, array( 'TW_Index', 'maybe_install' ) );

add_action( 'plugins_loaded', function () {
	TW_Settings::maybe_upgrade_prompt();
	TW_Settings::maybe_upgrade_thumb_prompts();
	if ( is_admin() ) {
		require_once TW_DIR . 'includes/class-tw-videos-table.php';
		new TW_Admin();
	}
} );
