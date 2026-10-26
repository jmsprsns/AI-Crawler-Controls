<?php
/**
 * Plugin Name:       AI Crawler Controls
 * Plugin URI:        https://www.contentpowered.com
 * Description:       Block or allow AI crawlers such as GPTBot, ClaudeBot and CCBot one by one, through robots.txt rules, opt-out headers and an optional 403, and see every visit they make in a crawl log.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.2
 * Author:            Content Powered
 * Author URI:        https://www.contentpowered.com/wordpress-plugins/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ai-crawler-controls
 * Update URI:        https://github.com/jmsprsns/AI-Crawler-Controls
 *
 * @package AI_Crawler_Controls
 */

defined( 'ABSPATH' ) || exit;

// Version and repo are read from the header above, so a release only needs
// "Version" bumped and the GitHub repo lives in one place ("Update URI").
// The Update URI header also stops WordPress.org offering a same-slug plugin
// as an "update" that would overwrite this one.
$ai_crawler_controls_headers = get_file_data(
	__FILE__,
	array(
		'version' => 'Version',
		'repo'    => 'Update URI',
	)
);

define( 'AI_CRAWLER_CONTROLS_VERSION', $ai_crawler_controls_headers['version'] );
define( 'AI_CRAWLER_CONTROLS_REPO', $ai_crawler_controls_headers['repo'] );
define( 'AI_CRAWLER_CONTROLS_FILE', __FILE__ );
define( 'AI_CRAWLER_CONTROLS_DIR', plugin_dir_path( __FILE__ ) );
define( 'AI_CRAWLER_CONTROLS_URL', plugin_dir_url( __FILE__ ) );

unset( $ai_crawler_controls_headers );

require_once AI_CRAWLER_CONTROLS_DIR . 'includes/class-ai-crawler-controls-icons.php';
require_once AI_CRAWLER_CONTROLS_DIR . 'includes/class-ai-crawler-controls-updater.php';
require_once AI_CRAWLER_CONTROLS_DIR . 'includes/class-ai-crawler-controls-menu.php';
require_once AI_CRAWLER_CONTROLS_DIR . 'includes/class-ai-crawler-controls-feedback.php';
require_once AI_CRAWLER_CONTROLS_DIR . 'includes/class-ai-crawler-controls-bots.php';
require_once AI_CRAWLER_CONTROLS_DIR . 'includes/class-ai-crawler-controls-db.php';
require_once AI_CRAWLER_CONTROLS_DIR . 'includes/class-ai-crawler-controls-guard.php';
require_once AI_CRAWLER_CONTROLS_DIR . 'includes/class-ai-crawler-controls-admin.php';
require_once AI_CRAWLER_CONTROLS_DIR . 'includes/class-ai-crawler-controls-plugin.php';

// Built right away, outside any hook, so WP-Cron background updates and
// remote management tools see new releases too (not only logged-in admins).
AI_Crawler_Controls_Updater::init();

/**
 * Boot the plugin once all other plugins have loaded.
 *
 * @return AI_Crawler_Controls_Plugin
 */
function ai_crawler_controls_plugin() {
	return AI_Crawler_Controls_Plugin::instance();
}
add_action( 'plugins_loaded', 'ai_crawler_controls_plugin' );

/**
 * Set up on activation: create the tables and schedule the daily prune.
 *
 * Nothing is blocked until an administrator chooses, so activating the
 * plugin doesn't change what crawlers see.
 *
 * @param bool $network_wide Whether the plugin is being network activated.
 * @return void
 */
function ai_crawler_controls_activate( $network_wide = false ) {
	// Network activation only runs this on the main site; the others set
	// themselves up on their next admin or cron load (see AI_Crawler_Controls_Plugin).
	unset( $network_wide );

	AI_Crawler_Controls_Plugin::setup();
}
register_activation_hook( AI_CRAWLER_CONTROLS_FILE, 'ai_crawler_controls_activate' );

/**
 * Clean up when the plugin is deactivated.
 *
 * Settings and the crawl log stay put (uninstall.php removes them). The
 * daily prune is unscheduled, and WP Rocket forgets the crawlers it was
 * sending past its cache.
 *
 * @return void
 */
function ai_crawler_controls_deactivate() {
	wp_clear_scheduled_hook( AI_Crawler_Controls_Plugin::PRUNE_HOOK );

	AI_Crawler_Controls_Guard::release_page_cache();
}
register_deactivation_hook( AI_CRAWLER_CONTROLS_FILE, 'ai_crawler_controls_deactivate' );
