<?php
/**
 * Removes all plugin data when the plugin is deleted from the Plugins screen.
 *
 * @package SEO_Health_Check
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Deletes the tables, options, transients and scheduled events of the current site.
 */
function seohc_uninstall_site() {
	global $wpdb;

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- removing the plugin's own tables.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}seohc_issues" );
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}seohc_pages" );
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}seohc_links" );

	// Link check cache.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_seohc_link_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_seohc_link_' ) . '%'
		)
	);
	// phpcs:enable

	// Kept reports name every page of the site, so they go too.
	$seohc_uploads = wp_get_upload_dir();
	$seohc_folder  = trailingslashit( $seohc_uploads['basedir'] ) . 'seo-health-check';
	foreach ( (array) glob( $seohc_folder . '/*' ) as $seohc_file ) {
		wp_delete_file( $seohc_file );
	}
	if ( is_dir( $seohc_folder ) ) {
		rmdir( $seohc_folder ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- the plugin's own folder.
	}

	foreach ( array( 'seohc_settings', 'seohc_db_version', 'seohc_scan_state', 'seohc_scan_lock', 'seohc_launch_results', 'seohc_link_cache_generation', 'seohc_scan_history', 'seohc_schedule', 'seohc_report_state' ) as $option ) {
		delete_option( $option );
	}

	delete_site_transient( 'seohc_latest_release' );

	wp_unschedule_hook( 'seohc_scheduled_scan' );
	wp_unschedule_hook( 'seohc_process_batch' );
	wp_unschedule_hook( 'seohc_scan_single_post' );
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'seohc_process_batch' );
	}

	delete_metadata( 'user', 0, 'seohc_issues_per_page', '', true );
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids' ) ) as $seohc_site_id ) {
		switch_to_blog( $seohc_site_id );
		seohc_uninstall_site();
		restore_current_blog();
	}
} else {
	seohc_uninstall_site();
}
