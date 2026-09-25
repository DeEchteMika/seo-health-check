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
		$links_table     = SEOHC_Repository::links_table();

		// One row per issue found. Rows stay behind with a resolved_at date once the problem is
		// gone, so the overview can show what the last scan fixed. Indexed on the columns the
		// overview filters and sorts on.
		dbDelta(
			"CREATE TABLE {$issues_table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				post_id bigint(20) unsigned NOT NULL,
				issue_type varchar(40) NOT NULL,
				severity varchar(10) NOT NULL,
				object_id bigint(20) unsigned NOT NULL DEFAULT 0,
				details text NOT NULL,
				created_at datetime NOT NULL,
				first_seen datetime NOT NULL,
				resolved_at datetime DEFAULT NULL,
				PRIMARY KEY  (id),
				KEY post_id (post_id),
				KEY issue_type (issue_type),
				KEY first_seen (first_seen),
				KEY resolved_at (resolved_at)
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
				score tinyint(3) unsigned NOT NULL DEFAULT 100,
				previous_score tinyint(3) unsigned DEFAULT NULL,
				scanned_at datetime NOT NULL,
				PRIMARY KEY  (post_id),
				KEY title_hash (title_hash),
				KEY description_hash (description_hash),
				KEY score (score)
			) {$charset_collate};"
		);

		// One row per internal link between two posts, used to find pages nothing links to.
		dbDelta(
			"CREATE TABLE {$links_table} (
				from_post_id bigint(20) unsigned NOT NULL,
				to_post_id bigint(20) unsigned NOT NULL,
				PRIMARY KEY  (from_post_id,to_post_id),
				KEY to_post_id (to_post_id)
			) {$charset_collate};"
		);

		add_option( SEOHC_Settings::OPTION, SEOHC_Settings::defaults() );

		// Results stored before the score and first-seen columns existed carry defaults; recalculate them.
		if ( get_option( 'seohc_db_version' ) && get_option( 'seohc_db_version' ) !== SEOHC_DB_VERSION ) {
			self::backfill();
		}

		update_option( 'seohc_db_version', SEOHC_DB_VERSION );
	}

	/**
	 * Fills the columns added by an upgrade for results that were scanned with an older version.
	 */
	private static function backfill() {
		global $wpdb;

		$issues = SEOHC_Repository::issues_table();
		$pages  = SEOHC_Repository::pages_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own tables.
		$wpdb->query( "UPDATE {$issues} SET first_seen = created_at WHERE first_seen = '0000-00-00 00:00:00' OR first_seen IS NULL" );

		$post_ids = $wpdb->get_col( "SELECT post_id FROM {$pages}" );
		// phpcs:enable

		SEOHC_Repository::refresh_scores( array_map( 'intval', $post_ids ) );
	}
}
