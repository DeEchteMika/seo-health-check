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
	 * instead of by the per-page scanner: flag_duplicates() owns the first two,
	 * flag_orphans() the last one.
	 */
	const DUPLICATE_TYPES  = array( 'title_duplicate', 'description_duplicate' );
	const ORPHAN_TYPE      = 'no_incoming_links';
	const CROSS_PAGE_TYPES = array( 'title_duplicate', 'description_duplicate', 'no_incoming_links' );

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
		'change'      => '( pg.score - pg.previous_score )',
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
	 * Internal links table name.
	 *
	 * @return string
	 */
	public static function links_table() {
		global $wpdb;
		return $wpdb->prefix . 'seohc_links';
	}

	/**
	 * Prepared value list for an IN clause, built from an issue type list.
	 *
	 * @param string[] $types Issue types.
	 * @return string
	 */
	private static function type_list( array $types ) {
		global $wpdb;

		$placeholders = implode( ', ', array_fill( 0, count( $types ), '%s' ) );

		return $wpdb->prepare( "({$placeholders})", $types ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- a value list, not a whole query.
	}

	/**
	 * Replaces all stored results for one post.
	 *
	 * Issues that are found again keep the date they were first seen, issues that have
	 * disappeared are marked resolved instead of deleted, and issues that were already
	 * resolved by an earlier scan are dropped. "Fixed" therefore always means "fixed since
	 * the previous scan of this page".
	 *
	 * @param int   $post_id Post ID.
	 * @param array $page    Page summary: seo_title, meta_description, word_count.
	 * @param array $issues  List of arrays with 'type' and 'details'.
	 */
	public static function save_post_result( $post_id, array $page, array $issues ) {
		global $wpdb;

		$post_id = (int) $post_id;
		$now     = current_time( 'mysql', true );
		$table   = self::issues_table();

		// Duplicates and orphans are decided across all pages once the scan is done, so
		// flag_duplicates() and flag_orphans() own those rows and this method leaves them alone.
		$own   = 'issue_type NOT IN ' . self::type_list( self::CROSS_PAGE_TYPES );
		$since = self::scan_started_at();

		// Fixes belong to one scan. Everything this page had repaired before the current scan
		// began has been reported, so it goes; fixes made since then add up, which is what
		// makes correcting several fields on one page from the overview readable.
		if ( '' !== $since ) {
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$table} WHERE post_id = %d AND resolved_at < %s AND {$own}",
					$post_id,
					$since
				)
			);
		}

		$open = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, issue_type, object_id, details FROM {$table} WHERE post_id = %d AND resolved_at IS NULL AND {$own}",
				$post_id
			)
		);

		$matches = self::match_issues( $open, $issues );
		$counts  = array();

		foreach ( $issues as $index => $issue ) {
			$type     = $issue['type'];
			$severity = SEOHC_Issue_Types::severity( $type );

			if ( isset( $matches[ $index ] ) ) {
				// The same problem as in the previous scan: keep the row and the date it was
				// first seen, and only refresh the wording and the scan date.
				$wpdb->update(
					$table,
					array(
						'severity'   => $severity,
						'details'    => $issue['details'],
						'created_at' => $now,
					),
					array( 'id' => $matches[ $index ] ),
					array( '%s', '%s', '%s' ),
					array( '%d' )
				);
			} else {
				$wpdb->insert(
					$table,
					array(
						'post_id'    => $post_id,
						'issue_type' => $type,
						'severity'   => $severity,
						'object_id'  => isset( $issue['object_id'] ) ? (int) $issue['object_id'] : 0,
						'details'    => $issue['details'],
						'created_at' => $now,
						'first_seen' => $now,
					),
					array( '%d', '%s', '%s', '%d', '%s', '%s', '%s' )
				);
			}

			if ( ! isset( $counts[ $type ] ) ) {
				$counts[ $type ] = array(
					'severity' => $severity,
					'count'    => 0,
				);
			}
			++$counts[ $type ]['count'];
		}

		$gone = array();
		foreach ( $open as $row ) {
			if ( ! in_array( (int) $row->id, $matches, true ) ) {
				$gone[] = (int) $row->id;
			}
		}

		self::resolve_issues( $gone, $now );
		self::save_page_row( $post_id, $page, count( $issues ), self::score_from_counts( $counts ), $now );
	}

	/**
	 * Stores which posts a page links to, so pages nothing links to can be found after a scan.
	 *
	 * Links a page makes to itself are dropped: they say nothing about whether anyone else
	 * can find the page.
	 *
	 * @param int   $post_id Post ID.
	 * @param int[] $targets IDs of the posts the page links to.
	 */
	public static function save_post_links( $post_id, array $targets ) {
		global $wpdb;

		$post_id = (int) $post_id;
		$links   = self::links_table();

		$wpdb->delete( $links, array( 'from_post_id' => $post_id ), array( '%d' ) );

		foreach ( array_unique( array_map( 'intval', $targets ) ) as $target ) {
			if ( $target <= 0 || $target === $post_id ) {
				continue;
			}

			$wpdb->insert(
				$links,
				array(
					'from_post_id' => $post_id,
					'to_post_id'   => $target,
				),
				array( '%d', '%d' )
			);
		}
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
		$wpdb->delete( self::links_table(), array( 'from_post_id' => $post_id ), array( '%d' ) );
		$wpdb->delete( self::links_table(), array( 'to_post_id' => $post_id ), array( '%d' ) );
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

		$links = self::links_table();

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$pages} WHERE scanned_at < %s", $since ) );
		$wpdb->query( "DELETE FROM {$issues} WHERE post_id NOT IN (SELECT post_id FROM {$pages})" );
		$wpdb->query( "DELETE FROM {$links} WHERE from_post_id NOT IN (SELECT post_id FROM {$pages})" );
	}

	/**
	 * Adds duplicate title / description issues for every post that shares a value with another post.
	 */
	public static function flag_duplicates() {
		global $wpdb;
		$issues = self::issues_table();
		$pages  = self::pages_table();
		$now    = current_time( 'mysql', true );
		$types  = array(
			'title_duplicate'       => 'title_hash',
			'description_duplicate' => 'description_hash',
		);

		// A fix belongs to one scan, so duplicates repaired before this scan started are dropped.
		$mine  = 'issue_type IN ' . self::type_list( self::DUPLICATE_TYPES );
		$since = self::scan_started_at();

		if ( '' !== $since ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$issues} WHERE resolved_at < %s AND {$mine}", $since ) );
		}

		// Duplicates are recalculated from scratch every time, so remember which rows are
		// already open: those keep their id and the date they were first reported.
		$open = array();
		$rows = $wpdb->get_results( "SELECT id, post_id, issue_type FROM {$issues} WHERE resolved_at IS NULL AND {$mine}" );
		foreach ( $rows as $row ) {
			$open[ $row->issue_type ][ (int) $row->post_id ] = (int) $row->id;
		}

		$affected = array();
		$kept     = array();

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
				/* translators: %d: number of pages sharing the value. */
				$details = sprintf( __( 'Shared by %d pages.', 'seo-health-check' ), (int) $row->total );

				if ( isset( $open[ $type ][ $post_id ] ) ) {
					$kept[] = $open[ $type ][ $post_id ];

					$wpdb->update(
						$issues,
						array(
							'details'    => $details,
							'created_at' => $now,
						),
						array( 'id' => $open[ $type ][ $post_id ] ),
						array( '%s', '%s' ),
						array( '%d' )
					);
					continue;
				}

				$wpdb->insert(
					$issues,
					array(
						'post_id'    => $post_id,
						'issue_type' => $type,
						'severity'   => SEOHC_Issue_Types::severity( $type ),
						'details'    => $details,
						'created_at' => $now,
						'first_seen' => $now,
					),
					array( '%d', '%s', '%s', '%s', '%s', '%s' )
				);
			}
		}

		// Pages that had a duplicate before but not now are fixed, and their totals change too.
		$gone = array();
		foreach ( $open as $ids ) {
			foreach ( $ids as $post_id => $id ) {
				$affected[] = (int) $post_id;

				if ( ! in_array( $id, $kept, true ) ) {
					$gone[] = $id;
				}
			}
		}

		self::resolve_issues( $gone, $now );
		self::refresh_issue_counts();
		self::refresh_scores( $affected );
	}

	/**
	 * Flags every scanned page that nothing links to.
	 *
	 * Only run at the end of a full scan: the link table is complete only then. After a single
	 * rescan it holds one page's links, which would make the whole site look like orphans.
	 *
	 * Links are counted from the scanned content of other pages plus the WordPress menus. The
	 * site header, footer and sidebar are deliberately left out of a scan, so without the menus
	 * every page in the main navigation would be reported.
	 */
	public static function flag_orphans() {
		global $wpdb;

		$issues = self::issues_table();
		$pages  = self::pages_table();
		$links  = self::links_table();
		$now    = current_time( 'mysql', true );
		$type   = self::ORPHAN_TYPE;

		$open = array();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, post_id FROM {$issues} WHERE issue_type = %s AND resolved_at IS NULL", $type ) );
		foreach ( $rows as $row ) {
			$open[ (int) $row->post_id ] = (int) $row->id;
		}

		// Switched off: drop what the check reported earlier instead of calling it all solved.
		if ( ! SEOHC_Settings::get( 'check_orphans' ) ) {
			if ( ! empty( $open ) ) {
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$issues} WHERE issue_type = %s", $type ) );
				self::refresh_issue_counts();
				self::refresh_scores( array_keys( $open ) );
			}
			return;
		}

		$since = self::scan_started_at();
		if ( '' !== $since ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$issues} WHERE issue_type = %s AND resolved_at < %s", $type, $since ) );
		}

		$exempt = self::linked_without_a_link();
		$filter = empty( $exempt ) ? '' : ' AND pg.post_id NOT IN (' . implode( ', ', $exempt ) . ')';

		$orphans = array_map(
			'intval',
			(array) $wpdb->get_col( "SELECT pg.post_id FROM {$pages} pg WHERE pg.post_id NOT IN (SELECT to_post_id FROM {$links}){$filter}" )
		);

		$kept     = array();
		$affected = array_keys( $open );

		foreach ( $orphans as $post_id ) {
			$affected[] = $post_id;

			if ( isset( $open[ $post_id ] ) ) {
				$kept[] = $open[ $post_id ];
				continue;
			}

			$wpdb->insert(
				$issues,
				array(
					'post_id'    => $post_id,
					'issue_type' => $type,
					'severity'   => SEOHC_Issue_Types::severity( $type ),
					'details'    => __( 'No other page links to this one, and it is not in a menu.', 'seo-health-check' ),
					'created_at' => $now,
					'first_seen' => $now,
				),
				array( '%d', '%s', '%s', '%s', '%s', '%s' )
			);
		}

		self::resolve_issues( array_diff( array_values( $open ), $kept ), $now );
		self::refresh_issue_counts();
		self::refresh_scores( $affected );
	}

	/**
	 * Posts that count as linked even though a scan cannot see the link: everything in a
	 * WordPress menu, plus the front page and the page that holds the blog.
	 *
	 * @return int[]
	 */
	private static function linked_without_a_link() {
		$ids = array( (int) get_option( 'page_on_front' ), (int) get_option( 'page_for_posts' ) );

		foreach ( (array) wp_get_nav_menus() as $menu ) {
			foreach ( (array) wp_get_nav_menu_items( $menu->term_id ) as $item ) {
				if ( 'post_type' === $item->type ) {
					$ids[] = (int) $item->object_id;
				} elseif ( 'custom' === $item->type ) {
					$ids[] = (int) url_to_postid( $item->url );
				}
			}
		}

		/**
		 * Filters the posts treated as linked without the scan finding a link to them.
		 *
		 * Useful for links a scan cannot see, such as those in a widget or a built footer.
		 *
		 * @param int[] $ids Post IDs.
		 */
		$ids = (array) apply_filters( 'seo_health_check_linked_post_ids', $ids );

		return array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
	}

	/**
	 * Recounts the open issues per page.
	 */
	private static function refresh_issue_counts() {
		global $wpdb;

		$issues = self::issues_table();
		$pages  = self::pages_table();

		$wpdb->query( "UPDATE {$pages} SET issue_count = (SELECT COUNT(*) FROM {$issues} WHERE {$issues}.post_id = {$pages}.post_id AND {$issues}.resolved_at IS NULL)" );
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
					"SELECT post_id, issue_type, severity, COUNT(*) AS total FROM {$issues} WHERE post_id IN ({$placeholders}) AND resolved_at IS NULL GROUP BY post_id, issue_type, severity", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- the placeholders are generated from the ID list above.
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
	 * Matches the issues found now against the ones already stored for a page.
	 *
	 * Issues are grouped by type and object, so every image keeps its own row. Within a group
	 * the rows whose details are identical are paired first and the rest in the order they were
	 * found: a page that grew from 90 to 120 words stays the same thin-content issue, and of
	 * three broken links the one that was repaired is the one that ends up resolved.
	 *
	 * @param object[] $open   Rows currently open for the page.
	 * @param array[]  $issues Issues found by the scanner.
	 * @return array<int, int> Index in $issues => id of the row it continues.
	 */
	private static function match_issues( array $open, array $issues ) {
		$groups = array();
		foreach ( $open as $row ) {
			$groups[ $row->issue_type . '|' . (int) $row->object_id ][] = $row;
		}

		$matches = array();
		$changed = array();

		// Identical rows first, so the ones whose text really changed are left for the pass below.
		foreach ( $issues as $index => $issue ) {
			$key = $issue['type'] . '|' . ( isset( $issue['object_id'] ) ? (int) $issue['object_id'] : 0 );

			if ( empty( $groups[ $key ] ) ) {
				continue;
			}

			$found = false;
			foreach ( $groups[ $key ] as $position => $row ) {
				if ( $row->details === $issue['details'] ) {
					$matches[ $index ] = (int) $row->id;
					unset( $groups[ $key ][ $position ] );
					$found = true;
					break;
				}
			}

			if ( ! $found ) {
				$changed[ $index ] = $key;
			}
		}

		foreach ( $changed as $index => $key ) {
			if ( empty( $groups[ $key ] ) ) {
				continue;
			}

			$row               = array_shift( $groups[ $key ] );
			$matches[ $index ] = (int) $row->id;
		}

		return $matches;
	}

	/**
	 * Marks issues as fixed instead of deleting them, so the overview can show what changed.
	 *
	 * @param int[]  $ids         Issue IDs.
	 * @param string $resolved_at GMT datetime the issues disappeared.
	 */
	private static function resolve_issues( array $ids, $resolved_at ) {
		global $wpdb;

		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		if ( empty( $ids ) ) {
			return;
		}

		$table = self::issues_table();

		foreach ( array_chunk( $ids, 200 ) as $chunk ) {
			$placeholders = implode( ', ', array_fill( 0, count( $chunk ), '%d' ) );

			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET resolved_at = %s WHERE id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- the placeholders are generated from the ID list above.
					array_merge( array( $resolved_at ), $chunk )
				)
			);
		}
	}

	/**
	 * Writes the page row, keeping the score the page had when the current scan started.
	 *
	 * @param int    $post_id     Post ID.
	 * @param array  $page        Page summary: seo_title, meta_description, word_count.
	 * @param int    $issue_count Number of issues found.
	 * @param int    $score       Score from 0 to 100.
	 * @param string $now         GMT datetime of the scan.
	 */
	private static function save_page_row( $post_id, array $page, $issue_count, $score, $now ) {
		global $wpdb;

		$pages    = self::pages_table();
		$previous = $wpdb->get_var( $wpdb->prepare( "SELECT previous_score FROM {$pages} WHERE post_id = %d", $post_id ) );

		$wpdb->delete( $pages, array( 'post_id' => $post_id ), array( '%d' ) );

		$wpdb->insert(
			$pages,
			array(
				'post_id'          => $post_id,
				'seo_title'        => $page['seo_title'],
				'meta_description' => $page['meta_description'],
				'title_hash'       => self::hash( $page['seo_title'] ),
				'description_hash' => self::hash( $page['meta_description'] ),
				'word_count'       => $page['word_count'],
				'issue_count'      => $issue_count,
				'score'            => $score,
				'previous_score'   => null === $previous ? null : (int) $previous,
				'scanned_at'       => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s' )
		);
	}

	/**
	 * GMT datetime the most recent full scan started.
	 *
	 * Issues first seen after it are new, issues resolved after it were fixed by this scan.
	 * Single rescans do not move this date, so fixing one field at a time keeps adding to the
	 * same list instead of replacing it.
	 *
	 * @return string Empty when the site has never been scanned.
	 */
	public static function scan_started_at() {
		$state = SEOHC_Scan_Queue::get_state();
		return isset( $state['started_at'] ) ? (string) $state['started_at'] : '';
	}

	/**
	 * Copies the score of every page to previous_score.
	 *
	 * Called when a full scan starts, so the page overview can show what that scan changed.
	 * Rescans of a single page leave it alone: those would compare a page with itself a
	 * moment earlier, while the totals on the dashboard still point at the last full scan.
	 */
	public static function snapshot_scores() {
		global $wpdb;
		$pages = self::pages_table();

		$wpdb->query( "UPDATE {$pages} SET previous_score = score" );
	}

	/**
	 * Builds the WHERE clause for the issues overview filters.
	 *
	 * @param array $args Filters: issue_type, post_type, severity, search, status, new_since.
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

		$status    = isset( $args['status'] ) ? $args['status'] : '';
		$new_since = isset( $args['new_since'] ) ? $args['new_since'] : '';

		if ( 'resolved' === $status ) {
			$clauses[] = 'i.resolved_at IS NOT NULL';
		} else {
			// Everything else counts the problems a page still has; fixed ones are asked for.
			$clauses[] = 'i.resolved_at IS NULL';

			if ( '' !== $new_since && 'new' === $status ) {
				$clauses[] = $wpdb->prepare( 'i.first_seen >= %s', $new_since );
			} elseif ( '' !== $new_since && 'unchanged' === $status ) {
				$clauses[] = $wpdb->prepare( 'i.first_seen < %s', $new_since );
			}
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
	 * @param array $args Optional status and new_since, so the tabs count what the status
	 *                    filter shows. Without them only the open issues are counted.
	 * @return array<string, int>
	 */
	public static function counts_by_type( array $args = array() ) {
		global $wpdb;
		$issues = self::issues_table();
		$where  = self::where(
			array(
				'status'    => isset( $args['status'] ) ? $args['status'] : '',
				'new_since' => isset( $args['new_since'] ) ? $args['new_since'] : '',
			)
		);

		$rows = $wpdb->get_results( "SELECT i.issue_type, COUNT(*) AS total FROM {$issues} i INNER JOIN {$wpdb->posts} p ON p.ID = i.post_id {$where} GROUP BY i.issue_type" );

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
			self::change_counts( $started_at ),
			array(
				'started_at'  => $started_at,
				'finished_at' => $finished_at,
				'by_type'     => self::counts_by_type(),
			)
		);

		update_option( self::HISTORY_KEY, array_slice( $history, -self::HISTORY_LIMIT ), false );
	}

	/**
	 * How many issues this scan added and how many it found fixed.
	 *
	 * @param string $since GMT datetime the scan started.
	 * @return array{new: int, resolved: int}
	 */
	public static function change_counts( $since ) {
		global $wpdb;

		$since = (string) $since;
		if ( '' === $since ) {
			return array(
				'new'      => 0,
				'resolved' => 0,
			);
		}

		$issues = self::issues_table();

		return array(
			'new'      => (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$issues} i INNER JOIN {$wpdb->posts} p ON p.ID = i.post_id WHERE i.resolved_at IS NULL AND i.first_seen >= %s",
					$since
				)
			),
			'resolved' => (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$issues} i INNER JOIN {$wpdb->posts} p ON p.ID = i.post_id WHERE i.resolved_at >= %s",
					$since
				)
			),
		);
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
