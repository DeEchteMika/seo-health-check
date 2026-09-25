<?php
/**
 * All database access for scan results.
 *
 * @package SEO_Health_Check
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the issues and pages tables.
 *
 * Direct queries are intentional here: these are the plugin's own tables, so
 * there is no core API for them. Every value goes through $wpdb->prepare();
 * the only interpolated parts are internal table names, whitelisted ORDER BY
 * columns and WHERE/LIMIT fragments that were prepared in where() / get_issues().
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 */
class SEOHC_Repository {

	/**
	 * Option holding the snapshot of each finished scan, used to compare runs.
	 */
	const HISTORY_KEY = 'seohc_scan_history';

	/**
	 * Number of finished scans kept in the history.
	 */
	const HISTORY_LIMIT = 20;

	/**
	 * Score deducted for the first issue of a type: errors weigh twice a warning.
	 */
	const PENALTY_ERROR   = 20;
	const PENALTY_WARNING = 10;

	/**
	 * Extra deduction per repeated issue of the same type, and its maximum.
	 */
	const PENALTY_REPEAT     = 2;
	const PENALTY_REPEAT_MAX = 10;

	/**
	 * Issue types that compare pages with each other, so they are decided after the whole scan
	 * by flag_duplicates() instead of by the per-page scanner.
	 */
	const CROSS_PAGE_TYPES = array( 'title_duplicate', 'description_duplicate' );

	/**
	 * Columns the issues overview may sort on, mapped to SQL expressions.
	 */
	const SORTABLE = array(
		'post_title' => 'p.post_title',
		'post_type'  => 'p.post_type',
		'issue_type' => 'i.issue_type',
		'severity'   => 'i.severity',
		'created_at' => 'i.created_at',
		'first_seen' => 'i.first_seen',
	);

	/**
	 * Columns the pages overview may sort on, mapped to SQL expressions.
	 */
	const SORTABLE_PAGES = array(
		'post_title'  => 'p.post_title',
		'post_type'   => 'p.post_type',
		'score'       => 'pg.score',
		'issue_count' => 'pg.issue_count',
		'word_count'  => 'pg.word_count',
		'scanned_at'  => 'pg.scanned_at',
	);

	/**
	 * Issues table name.
	 *
	 * @return string
	 */
	public static function issues_table() {
		global $wpdb;
		return $wpdb->prefix . 'seohc_issues';
	}

	/**
	 * Pages table name.
	 *
	 * @return string
	 */
	public static function pages_table() {
		global $wpdb;
		return $wpdb->prefix . 'seohc_pages';
	}

	/**
	 * Replaces all stored results for one post.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $page    Page summary: seo_title, meta_description, word_count.
	 * @param array $issues  List of arrays with 'type' and 'details'.
	 */
	public static function save_post_result( $post_id, array $page, array $issues ) {
		global $wpdb;

		$now = current_time( 'mysql', true );

		// Keep the date an issue was first reported, so the overview can flag what is new.
		$first_seen = self::first_seen_map( array( $post_id ) );
		$first_seen = isset( $first_seen[ $post_id ] ) ? $first_seen[ $post_id ] : array();

		// Duplicate issues are decided across all pages once the scan is done, so they stay put
		// here; deleting and recreating them every scan would reset the date they were first seen.
		self::delete_post_issues( $post_id, self::CROSS_PAGE_TYPES );

		$counts = array();
		foreach ( $issues as $issue ) {
			$type     = $issue['type'];
			$severity = SEOHC_Issue_Types::severity( $type );

			$wpdb->insert(
				self::issues_table(),
				array(
					'post_id'    => $post_id,
					'issue_type' => $type,
					'severity'   => $severity,
					'object_id'  => isset( $issue['object_id'] ) ? (int) $issue['object_id'] : 0,
					'details'    => $issue['details'],
					'created_at' => $now,
					'first_seen' => isset( $first_seen[ $type ] ) ? $first_seen[ $type ] : $now,
				),
				array( '%d', '%s', '%s', '%d', '%s', '%s', '%s' )
			);

			if ( ! isset( $counts[ $type ] ) ) {
				$counts[ $type ] = array(
					'severity' => $severity,
					'count'    => 0,
				);
			}
			++$counts[ $type ]['count'];
		}

		$wpdb->insert(
			self::pages_table(),
			array(
				'post_id'          => $post_id,
				'seo_title'        => $page['seo_title'],
				'meta_description' => $page['meta_description'],
				'title_hash'       => self::hash( $page['seo_title'] ),
				'description_hash' => self::hash( $page['meta_description'] ),
				'word_count'       => $page['word_count'],
				'issue_count'      => count( $issues ),
				'score'            => self::score_from_counts( $counts ),
				'scanned_at'       => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s' )
		);
	}

	/**
	 * Removes all results for one post.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function delete_post( $post_id ) {
		global $wpdb;
		$wpdb->delete( self::issues_table(), array( 'post_id' => $post_id ), array( '%d' ) );
		$wpdb->delete( self::pages_table(), array( 'post_id' => $post_id ), array( '%d' ) );
	}

	/**
	 * Removes a post's issues and its page row, optionally sparing some issue types.
	 *
	 * @param int      $post_id Post ID.
	 * @param string[] $keep    Issue types to leave in place.
	 */
	private static function delete_post_issues( $post_id, array $keep = array() ) {
		global $wpdb;

		$issues = self::issues_table();
		$params = array_merge( array( $post_id ), $keep );

		if ( empty( $keep ) ) {
			$wpdb->delete( $issues, array( 'post_id' => $post_id ), array( '%d' ) );
		} else {
			$placeholders = implode( ', ', array_fill( 0, count( $keep ), '%s' ) );

			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$issues} WHERE post_id = %d AND issue_type NOT IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- the placeholders are generated from the type list above.
					$params
				)
			);
		}

		$wpdb->delete( self::pages_table(), array( 'post_id' => $post_id ), array( '%d' ) );
	}

	/**
	 * Removes results for posts that were not touched by the scan that started at $since
	 * (deleted posts, unpublished posts or post types that are no longer scanned).
	 *
	 * @param string $since GMT datetime the scan started.
	 */
	public static function delete_stale( $since ) {
		global $wpdb;
		$issues = self::issues_table();
		$pages  = self::pages_table();

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$pages} WHERE scanned_at < %s", $since ) );
		$wpdb->query( "DELETE FROM {$issues} WHERE post_id NOT IN (SELECT post_id FROM {$pages})" );
	}

	/**
	 * Adds duplicate title / description issues for every post that shares a value with another post.
	 */
	public static function flag_duplicates() {
		global $wpdb;
		$issues = self::issues_table();
		$pages  = self::pages_table();
		$types  = array(
			'title_duplicate'       => 'title_hash',
			'description_duplicate' => 'description_hash',
		);

		// Duplicates are recalculated from scratch every time, so remember when each was first reported.
		$previous = array();
		$rows     = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, issue_type, MIN(first_seen) AS first_seen FROM {$issues} WHERE issue_type IN (%s, %s) GROUP BY post_id, issue_type",
				self::CROSS_PAGE_TYPES[0],
				self::CROSS_PAGE_TYPES[1]
			)
		);
		foreach ( $rows as $row ) {
			$previous[ (int) $row->post_id ][ $row->issue_type ] = $row->first_seen;
		}

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$issues} WHERE issue_type IN (%s, %s)", self::CROSS_PAGE_TYPES[0], self::CROSS_PAGE_TYPES[1] ) );

		$now      = current_time( 'mysql', true );
		$affected = array();

		foreach ( $types as $type => $column ) {
			$duplicates = $wpdb->get_results(
				"SELECT pg.post_id, dup.total FROM {$pages} pg
				INNER JOIN (
					SELECT {$column} AS hash, COUNT(*) AS total FROM {$pages}
					WHERE {$column} <> '' GROUP BY {$column} HAVING COUNT(*) > 1
				) dup ON dup.hash = pg.{$column}"
			);

			foreach ( $duplicates as $row ) {
				$post_id    = (int) $row->post_id;
				$affected[] = $post_id;

				$wpdb->insert(
					$issues,
					array(
						'post_id'    => $post_id,
						'issue_type' => $type,
						'severity'   => SEOHC_Issue_Types::severity( $type ),
						/* translators: %d: number of pages sharing the value. */
						'details'    => sprintf( __( 'Shared by %d pages.', 'seo-health-check' ), (int) $row->total ),
						'created_at' => $now,
						'first_seen' => isset( $previous[ $post_id ][ $type ] ) ? $previous[ $post_id ][ $type ] : $now,
					),
					array( '%d', '%s', '%s', '%s', '%s', '%s' )
				);
			}
		}

		// Pages that had a duplicate before but not now also need their totals refreshed.
		$affected = array_unique( array_merge( $affected, array_keys( $previous ) ) );

		$wpdb->query( "UPDATE {$pages} SET issue_count = (SELECT COUNT(*) FROM {$issues} WHERE {$issues}.post_id = {$pages}.post_id)" );
		self::refresh_scores( $affected );
	}

	/**
	 * Recalculates the score of the given posts from the issues currently stored for them.
	 *
	 * @param int[] $post_ids Post IDs.
	 */
	public static function refresh_scores( array $post_ids ) {
		global $wpdb;

		$post_ids = array_values( array_unique( array_map( 'intval', $post_ids ) ) );
		if ( empty( $post_ids ) ) {
			return;
		}

		$issues = self::issues_table();
		$pages  = self::pages_table();

		foreach ( array_chunk( $post_ids, 200 ) as $chunk ) {
			$placeholders = implode( ', ', array_fill( 0, count( $chunk ), '%d' ) );

			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT post_id, issue_type, severity, COUNT(*) AS total FROM {$issues} WHERE post_id IN ({$placeholders}) GROUP BY post_id, issue_type, severity", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- the placeholders are generated from the ID list above.
					$chunk
				)
			);

			$counts = array();
			foreach ( $rows as $row ) {
				$counts[ (int) $row->post_id ][ $row->issue_type ] = array(
					'severity' => $row->severity,
					'count'    => (int) $row->total,
				);
			}

			// One UPDATE per chunk instead of one per page: a full scan can touch hundreds of pages.
			$cases = '';
			$args  = array();
			foreach ( $chunk as $post_id ) {
				$cases .= ' WHEN %d THEN %d';
				$args[] = $post_id;
				$args[] = self::score_from_counts( isset( $counts[ $post_id ] ) ? $counts[ $post_id ] : array() );
			}

			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$pages} SET score = CASE post_id{$cases} END WHERE post_id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- the placeholders are generated from the ID list above.
					array_merge( $args, $chunk )
				)
			);
		}
	}

	/**
	 * Turns the issues of one page into a score from 0 (worst) to 100 (no issues found).
	 *
	 * Each distinct issue type costs points once, errors twice as much as warnings, plus a
	 * small capped deduction per repeat. Ten images without alt text therefore hurt, but not
	 * as much as ten different problems.
	 *
	 * @param array $counts Issue types keyed by slug, each with 'severity' and 'count'.
	 * @return int
	 */
	private static function score_from_counts( array $counts ) {
		$deduction = 0;

		foreach ( $counts as $entry ) {
			$base       = SEOHC_Issue_Types::SEVERITY_ERROR === $entry['severity'] ? self::PENALTY_ERROR : self::PENALTY_WARNING;
			$repeats    = min( ( max( 1, (int) $entry['count'] ) - 1 ) * self::PENALTY_REPEAT, self::PENALTY_REPEAT_MAX );
			$deduction += $base + $repeats;
		}

		/**
		 * Filters the score calculated for a page.
		 *
		 * @param int   $score  Score from 0 to 100.
		 * @param array $counts Issue types with their severity and count.
		 */
		return (int) apply_filters( 'seo_health_check_page_score', max( 0, 100 - $deduction ), $counts );
	}

	/**
	 * First-seen dates of the issues currently stored for the given posts.
	 *
	 * @param int[] $post_ids Post IDs.
	 * @return array<int, array<string, string>> Post ID => issue type => GMT datetime.
	 */
	private static function first_seen_map( array $post_ids ) {
		global $wpdb;

		$post_ids = array_map( 'intval', $post_ids );
		if ( empty( $post_ids ) ) {
			return array();
		}

		$issues       = self::issues_table();
		$placeholders = implode( ', ', array_fill( 0, count( $post_ids ), '%d' ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, issue_type, MIN(first_seen) AS first_seen FROM {$issues} WHERE post_id IN ({$placeholders}) GROUP BY post_id, issue_type", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- the placeholders are generated from the ID list above.
				$post_ids
			)
		);

		$map = array();
		foreach ( $rows as $row ) {
			$map[ (int) $row->post_id ][ $row->issue_type ] = $row->first_seen;
		}
		return $map;
	}

	/**
	 * Builds the WHERE clause for the issues overview filters.
	 *
	 * @param array $args Filters: issue_type, post_type, severity, search, new_since.
	 * @return string Prepared SQL fragment starting with WHERE.
	 */
	private static function where( array $args ) {
		global $wpdb;

		$clauses = array( '1=1' );
		if ( ! empty( $args['issue_type'] ) ) {
			$clauses[] = $wpdb->prepare( 'i.issue_type = %s', $args['issue_type'] );
		}
		if ( ! empty( $args['post_type'] ) ) {
			$clauses[] = $wpdb->prepare( 'p.post_type = %s', $args['post_type'] );
		}
		if ( ! empty( $args['severity'] ) ) {
			$clauses[] = $wpdb->prepare( 'i.severity = %s', $args['severity'] );
		}
		if ( ! empty( $args['search'] ) ) {
			$clauses[] = $wpdb->prepare( 'p.post_title LIKE %s', '%' . $wpdb->esc_like( $args['search'] ) . '%' );
		}
		if ( ! empty( $args['new_since'] ) ) {
			$clauses[] = $wpdb->prepare( 'i.first_seen >= %s', $args['new_since'] );
		}

		return 'WHERE ' . implode( ' AND ', $clauses );
	}

	/**
	 * Fetches issues for the overview / export.
	 *
	 * @param array $args Filters plus orderby, order, per_page (0 = all) and page.
	 * @return object[] Rows with id, post_id, issue_type, severity, details, created_at, first_seen,
	 *                  post_title, post_type, post_status and score.
	 */
	public static function get_issues( array $args ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'orderby'  => 'post_title',
				'order'    => 'ASC',
				'per_page' => 50,
				'page'     => 1,
			)
		);

		$orderby = isset( self::SORTABLE[ $args['orderby'] ] ) ? self::SORTABLE[ $args['orderby'] ] : self::SORTABLE['post_title'];
		$order   = 'DESC' === strtoupper( $args['order'] ) ? 'DESC' : 'ASC';
		$where   = self::where( $args );
		$issues  = self::issues_table();
		$pages   = self::pages_table();
		$limit   = '';

		if ( $args['per_page'] > 0 ) {
			$limit = $wpdb->prepare( 'LIMIT %d OFFSET %d', $args['per_page'], ( max( 1, (int) $args['page'] ) - 1 ) * $args['per_page'] );
		}

		return $wpdb->get_results(
			"SELECT i.*, p.post_title, p.post_type, p.post_status, pg.score
			FROM {$issues} i
			INNER JOIN {$wpdb->posts} p ON p.ID = i.post_id
			LEFT JOIN {$pages} pg ON pg.post_id = i.post_id
			{$where} ORDER BY {$orderby} {$order}, i.id ASC {$limit}"
		);
	}

	/**
	 * Counts issues matching the filters.
	 *
	 * @param array $args Filters.
	 * @return int
	 */
	public static function count_issues( array $args ) {
		global $wpdb;
		$where  = self::where( $args );
		$issues = self::issues_table();

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$issues} i INNER JOIN {$wpdb->posts} p ON p.ID = i.post_id {$where}" );
	}

	/**
	 * Issue totals per type.
	 *
	 * @return array<string, int>
	 */
	public static function counts_by_type() {
		global $wpdb;
		$issues = self::issues_table();

		$rows = $wpdb->get_results( "SELECT i.issue_type, COUNT(*) AS total FROM {$issues} i INNER JOIN {$wpdb->posts} p ON p.ID = i.post_id GROUP BY i.issue_type" );

		$counts = array();
		foreach ( $rows as $row ) {
			$counts[ $row->issue_type ] = (int) $row->total;
		}
		return $counts;
	}

	/**
	 * Builds the WHERE clause for the pages overview filters.
	 *
	 * @param array $args Filters: post_type, search, band.
	 * @return string Prepared SQL fragment starting with WHERE.
	 */
	private static function where_pages( array $args ) {
		global $wpdb;

		$clauses = array( '1=1' );
		if ( ! empty( $args['post_type'] ) ) {
			$clauses[] = $wpdb->prepare( 'p.post_type = %s', $args['post_type'] );
		}
		if ( ! empty( $args['search'] ) ) {
			$clauses[] = $wpdb->prepare( 'p.post_title LIKE %s', '%' . $wpdb->esc_like( $args['search'] ) . '%' );
		}

		$bands = self::score_bands();
		if ( ! empty( $args['band'] ) && isset( $bands[ $args['band'] ] ) ) {
			$clauses[] = $wpdb->prepare( 'pg.score BETWEEN %d AND %d', $bands[ $args['band'] ]['min'], $bands[ $args['band'] ]['max'] );
		}

		return 'WHERE ' . implode( ' AND ', $clauses );
	}

	/**
	 * Score bands used for the coloured badge and the filter tabs.
	 *
	 * @return array<string, array{label: string, min: int, max: int}>
	 */
	public static function score_bands() {
		return array(
			'good' => array(
				'label' => __( 'Good', 'seo-health-check' ),
				'min'   => 80,
				'max'   => 100,
			),
			'fair' => array(
				'label' => __( 'Needs work', 'seo-health-check' ),
				'min'   => 50,
				'max'   => 79,
			),
			'poor' => array(
				'label' => __( 'Poor', 'seo-health-check' ),
				'min'   => 0,
				'max'   => 49,
			),
		);
	}

	/**
	 * Band slug for a score.
	 *
	 * @param int $score Score from 0 to 100.
	 * @return string
	 */
	public static function band_for_score( $score ) {
		foreach ( self::score_bands() as $slug => $band ) {
			if ( $score >= $band['min'] && $score <= $band['max'] ) {
				return $slug;
			}
		}
		return 'poor';
	}

	/**
	 * Fetches scanned pages with their score.
	 *
	 * @param array $args Filters plus orderby, order, per_page (0 = all) and page.
	 * @return object[]
	 */
	public static function get_pages( array $args ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'orderby'  => 'score',
				'order'    => 'ASC',
				'per_page' => 50,
				'page'     => 1,
			)
		);

		$orderby = isset( self::SORTABLE_PAGES[ $args['orderby'] ] ) ? self::SORTABLE_PAGES[ $args['orderby'] ] : self::SORTABLE_PAGES['score'];
		$order   = 'DESC' === strtoupper( $args['order'] ) ? 'DESC' : 'ASC';
		$where   = self::where_pages( $args );
		$pages   = self::pages_table();
		$limit   = '';

		if ( $args['per_page'] > 0 ) {
			$limit = $wpdb->prepare( 'LIMIT %d OFFSET %d', $args['per_page'], ( max( 1, (int) $args['page'] ) - 1 ) * $args['per_page'] );
		}

		return $wpdb->get_results(
			"SELECT pg.*, p.post_title, p.post_type, p.post_status
			FROM {$pages} pg
			INNER JOIN {$wpdb->posts} p ON p.ID = pg.post_id
			{$where} ORDER BY {$orderby} {$order}, p.post_title ASC {$limit}"
		);
	}

	/**
	 * Counts pages matching the filters.
	 *
	 * @param array $args Filters.
	 * @return int
	 */
	public static function count_pages( array $args ) {
		global $wpdb;
		$where = self::where_pages( $args );
		$pages = self::pages_table();

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$pages} pg INNER JOIN {$wpdb->posts} p ON p.ID = pg.post_id {$where}" );
	}

	/**
	 * Page totals per score band.
	 *
	 * @return array<string, int>
	 */
	public static function counts_by_band() {
		global $wpdb;
		$pages  = self::pages_table();
		$counts = array();

		foreach ( self::score_bands() as $slug => $band ) {
			$counts[ $slug ] = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$pages} WHERE score BETWEEN %d AND %d", $band['min'], $band['max'] )
			);
		}
		return $counts;
	}

	/**
	 * Summary numbers for the dashboard.
	 *
	 * @return array{pages: int, pages_with_issues: int, issues: int, score: int}
	 */
	public static function summary() {
		global $wpdb;
		$pages = self::pages_table();

		$row = $wpdb->get_row( "SELECT COUNT(*) AS pages, SUM(issue_count > 0) AS pages_with_issues, SUM(issue_count) AS issues, AVG(score) AS score FROM {$pages}" );

		return array(
			'pages'             => $row ? (int) $row->pages : 0,
			'pages_with_issues' => $row ? (int) $row->pages_with_issues : 0,
			'issues'            => $row ? (int) $row->issues : 0,
			'score'             => $row && null !== $row->score ? (int) round( $row->score ) : 0,
		);
	}

	/**
	 * Stores a snapshot of a finished scan so the next one can be compared with it.
	 *
	 * @param string $started_at  GMT datetime the scan started.
	 * @param string $finished_at GMT datetime the scan finished.
	 */
	public static function record_run( $started_at, $finished_at ) {
		$history   = self::run_history();
		$history[] = array_merge(
			self::summary(),
			array(
				'started_at'  => $started_at,
				'finished_at' => $finished_at,
				'by_type'     => self::counts_by_type(),
			)
		);

		update_option( self::HISTORY_KEY, array_slice( $history, -self::HISTORY_LIMIT ), false );
	}

	/**
	 * Snapshots of the finished scans, oldest first.
	 *
	 * @return array[]
	 */
	public static function run_history() {
		$history = get_option( self::HISTORY_KEY, array() );
		return is_array( $history ) ? $history : array();
	}

	/**
	 * Snapshot of the scan before the most recent one, or null when there is none.
	 *
	 * @return array|null
	 */
	public static function previous_run() {
		$history = self::run_history();
		return count( $history ) >= 2 ? $history[ count( $history ) - 2 ] : null;
	}

	/**
	 * Hash used to group equal titles/descriptions (case and whitespace insensitive).
	 *
	 * @param string $value Value to hash.
	 * @return string Empty string for empty values.
	 */
	private static function hash( $value ) {
		$value = trim( preg_replace( '/\s+/u', ' ', (string) $value ) );
		return '' === $value ? '' : md5( mb_strtolower( $value ) );
	}
}
