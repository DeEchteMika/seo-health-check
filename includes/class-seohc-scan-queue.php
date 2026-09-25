<?php
/**
 * Background processing: scans the site in small batches.
 *
 * @package SEO_Health_Check
 */

defined( 'ABSPATH' ) || exit;

/**
 * Batch queue on top of Action Scheduler (when available) or WP-Cron.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
 */
class SEOHC_Scan_Queue {

	const BATCH_HOOK  = 'seohc_process_batch';
	const SINGLE_HOOK = 'seohc_scan_single_post';
	const STATE_KEY   = 'seohc_scan_state';
	const LOCK_KEY    = 'seohc_scan_lock';
	const AS_GROUP    = 'seo-health-check';

	/**
	 * Seconds a lock stays valid; protects against a crashed batch blocking the queue forever.
	 */
	const LOCK_TTL = 120;

	/**
	 * Registers the queue hooks.
	 */
	public static function init() {
		add_action( self::BATCH_HOOK, array( __CLASS__, 'process_batch' ) );
		add_action( self::SINGLE_HOOK, array( __CLASS__, 'scan_single' ) );
		add_action( 'save_post', array( __CLASS__, 'on_save_post' ), 20, 2 );
		add_action( 'deleted_post', array( 'SEOHC_Repository', 'delete_post' ) );
	}

	/**
	 * Current scan state.
	 *
	 * @return array
	 */
	public static function get_state() {
		$state = get_option( self::STATE_KEY, array() );
		return wp_parse_args(
			is_array( $state ) ? $state : array(),
			array(
				'status'        => 'idle',
				'started_at'    => '',
				'finished_at'   => '',
				'last_activity' => 0,
				'total'         => 0,
				'processed'     => 0,
				'last_id'       => 0,
				'post_types'    => array(),
				'ajax_driven'   => false,
			)
		);
	}

	/**
	 * Saves the scan state.
	 *
	 * @param array $state State.
	 */
	private static function set_state( array $state ) {
		update_option( self::STATE_KEY, $state, false );
	}

	/**
	 * Scan status read from the database, bypassing the per-request option cache.
	 *
	 * @return string
	 */
	private static function fresh_status() {
		wp_cache_delete( self::STATE_KEY, 'options' );
		return self::get_state()['status'];
	}

	/**
	 * Whether a full scan is running.
	 *
	 * @return bool
	 */
	public static function is_running() {
		return 'running' === self::get_state()['status'];
	}

	/**
	 * Starts a full scan.
	 *
	 * @return bool False when a scan is already running.
	 */
	public static function start() {
		if ( self::is_running() ) {
			return false;
		}

		$post_types = (array) SEOHC_Settings::get( 'post_types' );
		SEOHC_Link_Checker::reset_cache();

		// Freeze the scores this scan starts from, so the page overview can show per page what
		// changed. Single rescans leave this alone: they would compare a page with itself.
		SEOHC_Repository::snapshot_scores();

		self::set_state(
			array(
				'status'        => 'running',
				'started_at'    => current_time( 'mysql', true ),
				'finished_at'   => '',
				'last_activity' => time(),
				'total'         => self::count_posts( $post_types ),
				'processed'     => 0,
				'last_id'       => 0,
				'post_types'    => $post_types,
				'ajax_driven'   => false,
			)
		);

		self::schedule_next();
		return true;
	}

	/**
	 * Cancels a running scan. Results scanned so far are kept.
	 */
	public static function cancel() {
		self::unschedule_all();
		delete_option( self::LOCK_KEY );

		$state                = self::get_state();
		$state['status']      = 'cancelled';
		$state['finished_at'] = current_time( 'mysql', true );
		self::set_state( $state );
	}

	/**
	 * Processes one batch. Called by WP-Cron / Action Scheduler, or by the admin progress poll as a fallback.
	 */
	public static function process_batch() {
		$state = self::get_state();
		if ( 'running' !== $state['status'] || ! self::acquire_lock() ) {
			return;
		}

		$started    = microtime( true );
		$budget     = self::time_budget();
		$batch_size = (int) SEOHC_Settings::get( 'batch_size' );
		$ids        = self::next_ids( $state['post_types'], (int) $state['last_id'], $batch_size );

		foreach ( $ids as $post_id ) {
			SEOHC_Scanner::scan_post( $post_id );

			// Stop when the scan was cancelled from another request in the meantime.
			if ( 'running' !== self::fresh_status() ) {
				delete_option( self::LOCK_KEY );
				return;
			}

			$state['last_id']       = $post_id;
			$state['processed']     = (int) $state['processed'] + 1;
			$state['last_activity'] = time();
			self::set_state( $state );

			if ( microtime( true ) - $started > $budget ) {
				break;
			}
		}

		$done = count( $ids ) < $batch_size && (int) end( $ids ) === (int) $state['last_id'];
		if ( empty( $ids ) || $done ) {
			self::finish( $state );
		} else {
			self::schedule_next();
		}

		delete_option( self::LOCK_KEY );
	}

	/**
	 * Finalizes a full scan: removes stale results and flags duplicates.
	 *
	 * @param array $state State.
	 */
	private static function finish( array $state ) {
		SEOHC_Repository::delete_stale( $state['started_at'] );
		SEOHC_Repository::flag_duplicates();

		// Only here: the link table is complete once every page has been scanned.
		SEOHC_Repository::flag_orphans();

		$state['status']        = 'done';
		$state['finished_at']   = current_time( 'mysql', true );
		$state['processed']     = max( (int) $state['processed'], (int) $state['total'] );
		$state['last_activity'] = time();
		self::set_state( $state );

		// Snapshot the result so the next scan can be compared with this one.
		SEOHC_Repository::record_run( $state['started_at'], $state['finished_at'] );

		/**
		 * Fires when a full scan has finished.
		 *
		 * @param array $state Final scan state.
		 */
		do_action( 'seo_health_check_scan_finished', $state );
	}

	/**
	 * Rescans one post (after it was saved).
	 *
	 * @param int $post_id Post ID.
	 */
	public static function scan_single( $post_id ) {
		SEOHC_Scanner::scan_post( (int) $post_id );
		SEOHC_Repository::flag_duplicates();
	}

	/**
	 * Queues a rescan when a post is saved.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 */
	public static function on_save_post( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ! SEOHC_Settings::get( 'rescan_on_save' ) ) {
			return;
		}
		if ( ! in_array( $post->post_type, (array) SEOHC_Settings::get( 'post_types' ), true ) ) {
			return;
		}

		$args = array( (int) $post_id );
		if ( ! wp_next_scheduled( self::SINGLE_HOOK, $args ) ) {
			wp_schedule_single_event( time(), self::SINGLE_HOOK, $args );
		}
	}

	/**
	 * Whether the admin progress poll should run the next batch itself.
	 *
	 * That happens when the queue made no progress for a while (WP-Cron disabled or loopback
	 * requests blocked). From then on the poll keeps driving the scan, so it does not wait again.
	 *
	 * @return bool
	 */
	public static function needs_ajax_runner() {
		$state = self::get_state();
		if ( 'running' !== $state['status'] ) {
			return false;
		}
		if ( ! empty( $state['ajax_driven'] ) ) {
			return true;
		}
		if ( time() - (int) $state['last_activity'] > 15 ) {
			$state['ajax_driven'] = true;
			self::set_state( $state );
			return true;
		}
		return false;
	}

	/**
	 * Schedules the next batch.
	 */
	private static function schedule_next() {
		if ( self::use_action_scheduler() ) {
			as_enqueue_async_action( self::BATCH_HOOK, array(), self::AS_GROUP );
			return;
		}

		if ( ! wp_next_scheduled( self::BATCH_HOOK ) ) {
			wp_schedule_single_event( time(), self::BATCH_HOOK );
		}
		spawn_cron();
	}

	/**
	 * Removes all queued batches.
	 */
	public static function unschedule_all() {
		wp_clear_scheduled_hook( self::BATCH_HOOK );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::BATCH_HOOK, array(), self::AS_GROUP );
		}
	}

	/**
	 * Action Scheduler ships with WooCommerce and others and is more reliable than WP-Cron.
	 *
	 * @return bool
	 */
	public static function use_action_scheduler() {
		/**
		 * Filters whether Action Scheduler is used when it is available.
		 *
		 * @param bool $use Default true when Action Scheduler is loaded.
		 */
		return apply_filters( 'seo_health_check_use_action_scheduler', function_exists( 'as_enqueue_async_action' ) );
	}

	/**
	 * Takes the batch lock. Uses add_option(), which fails when the row exists, so only one batch runs at a time.
	 *
	 * @return bool
	 */
	private static function acquire_lock() {
		if ( add_option( self::LOCK_KEY, time(), '', false ) ) {
			return true;
		}

		$locked_at = (int) get_option( self::LOCK_KEY );
		if ( time() - $locked_at > self::LOCK_TTL ) {
			delete_option( self::LOCK_KEY );
			return add_option( self::LOCK_KEY, time(), '', false );
		}
		return false;
	}

	/**
	 * Seconds one batch may run: well below common PHP and proxy time-outs.
	 *
	 * @return float
	 */
	private static function time_budget() {
		$max = (int) ini_get( 'max_execution_time' );
		$max = $max > 0 ? $max : 30;

		/**
		 * Filters the number of seconds a single batch may run.
		 *
		 * @param float $seconds Time budget.
		 */
		return (float) apply_filters( 'seo_health_check_batch_time_budget', min( 20, $max * 0.5 ) );
	}

	/**
	 * Next post IDs to scan, using the last ID as a cursor (stable when posts are added during a scan).
	 *
	 * @param string[] $post_types Post types.
	 * @param int      $after_id   Last scanned ID.
	 * @param int      $limit      Batch size.
	 * @return int[]
	 */
	private static function next_ids( array $post_types, $after_id, $limit ) {
		global $wpdb;
		if ( empty( $post_types ) ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );
		$params       = array_merge( $post_types, array( $after_id, $limit ) );

		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders are generated above.
		$sql = $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ({$placeholders}) AND post_status = 'publish' AND ID > %d ORDER BY ID ASC LIMIT %d", $params );

		return array_map( 'intval', $wpdb->get_col( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
	}

	/**
	 * Number of posts a full scan will process.
	 *
	 * @param string[] $post_types Post types.
	 * @return int
	 */
	private static function count_posts( array $post_types ) {
		$total = 0;
		foreach ( $post_types as $type ) {
			$counts = wp_count_posts( $type );
			$total += isset( $counts->publish ) ? (int) $counts->publish : 0;
		}
		return $total;
	}
}
