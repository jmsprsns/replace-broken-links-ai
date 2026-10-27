<?php
/**
 * Runs when the plugin is deleted from the Plugins screen: remove its data.
 *
 * Posts keep the links the plugin replaced; only the plugin's own tables,
 * settings (including the encrypted API keys) and scheduled events go.
 *
 * @package Replace_Broken_Links_AI
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Remove the plugin's data from the current site.
 *
 * @return void
 */
function replace_broken_links_ai_uninstall_site() {
	global $wpdb;

	foreach ( array( 'rblai_urls', 'rblai_links', 'rblai_uses' ) as $replace_broken_links_ai_table ) {
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . $replace_broken_links_ai_table ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Fixed table names.
	}

	foreach ( array( 'settings', 'db_version', 'scan', 'queue', 'lock', 'lookup_lock', 'paused', 'usage', 'models_openai', 'models_anthropic' ) as $replace_broken_links_ai_option ) {
		delete_option( 'replace_broken_links_ai_' . $replace_broken_links_ai_option );
	}

	wp_clear_scheduled_hook( 'replace_broken_links_ai_batch' );
	wp_clear_scheduled_hook( 'replace_broken_links_ai_daily' );
}

if ( is_multisite() ) {
	$replace_broken_links_ai_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $replace_broken_links_ai_sites as $replace_broken_links_ai_site ) {
		switch_to_blog( $replace_broken_links_ai_site );
		replace_broken_links_ai_uninstall_site();
		restore_current_blog();
	}
} else {
	replace_broken_links_ai_uninstall_site();
}

// Plugin Update Checker's cached update state.
delete_site_option( 'external_updates-replace-broken-links-ai' );
