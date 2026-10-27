<?php
/**
 * Plugin Name:       Replace Broken Links With AI
 * Plugin URI:        https://www.contentpowered.com
 * Description:       Finds links in your posts that return 404 or 410, waits until they're truly dead, then uses AI and a Google search to swap each one for the best live page on the same topic.
 * Version:           1.0.1
 * Requires at least: 5.8
 * Requires PHP:      7.2
 * Author:            Content Powered
 * Author URI:        https://www.contentpowered.com/wordpress-plugins/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       replace-broken-links-ai
 * Update URI:        https://github.com/jmsprsns/replace-broken-links-ai
 *
 * @package Replace_Broken_Links_AI
 */

defined( 'ABSPATH' ) || exit;

// Version and repo are read from the header above, so a release only needs
// "Version" bumped and the GitHub repo lives in one place ("Update URI").
// The Update URI header also stops WordPress.org offering a same-slug plugin
// as an "update" that would overwrite this one.
$replace_broken_links_ai_headers = get_file_data(
	__FILE__,
	array(
		'version' => 'Version',
		'repo'    => 'Update URI',
	)
);

define( 'REPLACE_BROKEN_LINKS_AI_VERSION', $replace_broken_links_ai_headers['version'] );
define( 'REPLACE_BROKEN_LINKS_AI_REPO', $replace_broken_links_ai_headers['repo'] );
define( 'REPLACE_BROKEN_LINKS_AI_FILE', __FILE__ );
define( 'REPLACE_BROKEN_LINKS_AI_DIR', plugin_dir_path( __FILE__ ) );
define( 'REPLACE_BROKEN_LINKS_AI_URL', plugin_dir_url( __FILE__ ) );

unset( $replace_broken_links_ai_headers );

require_once REPLACE_BROKEN_LINKS_AI_DIR . 'includes/class-replace-broken-links-ai-icons.php';
require_once REPLACE_BROKEN_LINKS_AI_DIR . 'includes/class-replace-broken-links-ai-updater.php';
require_once REPLACE_BROKEN_LINKS_AI_DIR . 'includes/class-replace-broken-links-ai-menu.php';
require_once REPLACE_BROKEN_LINKS_AI_DIR . 'includes/class-replace-broken-links-ai-feedback.php';
require_once REPLACE_BROKEN_LINKS_AI_DIR . 'includes/class-replace-broken-links-ai-crypto.php';
require_once REPLACE_BROKEN_LINKS_AI_DIR . 'includes/class-replace-broken-links-ai-db.php';
require_once REPLACE_BROKEN_LINKS_AI_DIR . 'includes/class-replace-broken-links-ai-checker.php';
require_once REPLACE_BROKEN_LINKS_AI_DIR . 'includes/class-replace-broken-links-ai-content.php';
require_once REPLACE_BROKEN_LINKS_AI_DIR . 'includes/class-replace-broken-links-ai-ai.php';
require_once REPLACE_BROKEN_LINKS_AI_DIR . 'includes/class-replace-broken-links-ai-serper.php';
require_once REPLACE_BROKEN_LINKS_AI_DIR . 'includes/class-replace-broken-links-ai-wayback.php';
require_once REPLACE_BROKEN_LINKS_AI_DIR . 'includes/class-replace-broken-links-ai-prompts.php';
require_once REPLACE_BROKEN_LINKS_AI_DIR . 'includes/class-replace-broken-links-ai-usage.php';
require_once REPLACE_BROKEN_LINKS_AI_DIR . 'includes/class-replace-broken-links-ai-finder.php';
require_once REPLACE_BROKEN_LINKS_AI_DIR . 'includes/class-replace-broken-links-ai-scanner.php';
require_once REPLACE_BROKEN_LINKS_AI_DIR . 'includes/class-replace-broken-links-ai-admin.php';
require_once REPLACE_BROKEN_LINKS_AI_DIR . 'includes/class-replace-broken-links-ai-plugin.php';

// Built right away, outside any hook, so WP-Cron background updates and
// remote management tools see new releases too (not only logged-in admins).
Replace_Broken_Links_AI_Updater::init();

/**
 * Boot the plugin once all other plugins have loaded.
 *
 * @return Replace_Broken_Links_AI_Plugin
 */
function replace_broken_links_ai_plugin() {
	return Replace_Broken_Links_AI_Plugin::instance();
}
add_action( 'plugins_loaded', 'replace_broken_links_ai_plugin' );

/**
 * Set up on activation: create the tables, schedule the daily check and,
 * the first time only, start a full scan in the background. The scan only
 * checks links; nothing is sent to an AI or a search API until both are
 * connected.
 *
 * @param bool $network_wide Whether the plugin is being network activated.
 * @return void
 */
function replace_broken_links_ai_activate( $network_wide = false ) {
	// Network activation only runs this on the main site; the others set
	// themselves up on their next load (see Replace_Broken_Links_AI_Plugin).
	unset( $network_wide );

	Replace_Broken_Links_AI_DB::install();
	Replace_Broken_Links_AI_Scanner::schedule_daily();

	$state = Replace_Broken_Links_AI_Scanner::get_state();
	if ( empty( $state['finished'] ) && 'running' !== $state['status'] ) {
		Replace_Broken_Links_AI_Scanner::start();
	}
}
register_activation_hook( REPLACE_BROKEN_LINKS_AI_FILE, 'replace_broken_links_ai_activate' );

/**
 * Clean up when the plugin is deactivated.
 *
 * Settings, keys, the change log and the review queue stay put
 * (uninstall.php removes them). Only the scheduled events are cleared.
 *
 * @return void
 */
function replace_broken_links_ai_deactivate() {
	Replace_Broken_Links_AI_Scanner::unschedule();
}
register_deactivation_hook( REPLACE_BROKEN_LINKS_AI_FILE, 'replace_broken_links_ai_deactivate' );
