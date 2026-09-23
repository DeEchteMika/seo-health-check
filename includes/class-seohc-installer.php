<?php
/**
 * Creates and upgrades the plugin's database tables.
 *
 * @package SEO_Health_Check
 */

defined( 'ABSPATH' ) || exit;

/**
 * Activation, deactivation and schema management.
 */
class SEOHC_Installer {

	/**
	 * Runs on plugin activation.
	 *
	 * @param bool $network_wide Whether the plugin is being network activated.
	 */
	public static function activate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			foreach ( get_sites( array( 'fields' => 'ids' ) ) as $site_id ) {
				switch_to_blog( $site_id );
				self::install();
				restore_current_blog();
			}
			return;
		}

		self::install();
	}

	/**
	 * Runs on plugin deactivation: stops all scheduled work. Data is kept.
	 */
	public static function deactivate() {
		SEOHC_Scan_Queue::unschedule_all();
		wp_unschedule_hook( SEOHC_Scan_Queue::SINGLE_HOOK );
		delete_option( SEOHC_Scan_Queue::LOCK_KEY );

		$state = SEOHC_Scan_Queue::get_state();
		if ( 'running' === $state['status'] ) {
			$state['status'] = 'cancelled';
			update_option( SEOHC_Scan_Queue::STATE_KEY, $state, false );
		}
	}

	/**
	 * Upgrades the schema when the stored DB version is outdated.
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'seohc_db_version' ) !== SEOHC_DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Creates the tables and default options.
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$issues_table    = SEOHC_Repository::issues_table();
		$pages_table     = SEOHC_Repository::pages_table();

		// One row per issue found. Indexed on the columns the overview filters and sorts on.
		dbDelta(
			"CREATE TABLE {$issues_table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				post_id bigint(20) unsigned NOT NULL,
				issue_type varchar(40) NOT NULL,
				severity varchar(10) NOT NULL,
				details text NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY post_id (post_id),
				KEY issue_type (issue_type)
			) {$charset_collate};"
		);

		// One row per scanned post: the values needed for cross-post checks (duplicates) and statistics.
		dbDelta(
			"CREATE TABLE {$pages_table} (
				post_id bigint(20) unsigned NOT NULL,
				seo_title text NOT NULL,
				meta_description text NOT NULL,
				title_hash char(32) NOT NULL DEFAULT '',
				description_hash char(32) NOT NULL DEFAULT '',
				word_count int(10) unsigned NOT NULL DEFAULT 0,
				issue_count int(10) unsigned NOT NULL DEFAULT 0,
				scanned_at datetime NOT NULL,
				PRIMARY KEY  (post_id),
				KEY title_hash (title_hash),
				KEY description_hash (description_hash)
			) {$charset_collate};"
		);

		add_option( SEOHC_Settings::OPTION, SEOHC_Settings::defaults() );
		update_option( 'seohc_db_version', SEOHC_DB_VERSION );
	}
}
