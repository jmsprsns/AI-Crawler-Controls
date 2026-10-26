<?php
/**
 * Runs when the plugin is deleted from the Plugins screen: remove its data.
 *
 * The robots.txt rules and headers stop with the plugin; this removes its
 * tables, settings and scheduled event.
 *
 * @package AI_Crawler_Controls
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Remove the plugin's data from the current site.
 *
 * @return void
 */
function ai_crawler_controls_uninstall_site() {
	global $wpdb;

	foreach ( array( 'ai_crawler_controls_hits', 'ai_crawler_controls_daily' ) as $ai_crawler_controls_table ) {
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . $ai_crawler_controls_table ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Fixed table names.
	}

	foreach ( array( 'settings', 'blocked', 'custom', 'db_version' ) as $ai_crawler_controls_option ) {
		delete_option( 'ai_crawler_controls_' . $ai_crawler_controls_option );
	}

	delete_transient( 'ai_crawler_controls_robots_check' );
	wp_clear_scheduled_hook( 'ai_crawler_controls_prune' );
}

if ( is_multisite() ) {
	$ai_crawler_controls_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $ai_crawler_controls_sites as $ai_crawler_controls_site ) {
		switch_to_blog( $ai_crawler_controls_site );
		ai_crawler_controls_uninstall_site();
		restore_current_blog();
	}
} else {
	ai_crawler_controls_uninstall_site();
}

// Plugin Update Checker's cached update state.
delete_site_option( 'external_updates-ai-crawler-controls' );
