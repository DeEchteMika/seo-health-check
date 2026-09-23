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
	 * Columns the overview may sort on, mapped to SQL expressions.
	 */
	const SORTABLE = array(
		'post_title' => 'p.post_title',
		'post_type'  => 'p.post_type',
		'issue_type' => 'i.issue_type',
		'severity'   => 'i.severity',
		'created_at' => 'i.created_at',
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

		self::delete_post( $post_id );

		foreach ( $issues as $issue ) {
			$wpdb->insert(
				self::issues_table(),
				array(
					'post_id'    => $post_id,
					'issue_type' => $issue['type'],
					'severity'   => SEOHC_Issue_Types::severity( $issue['type'] ),
					'details'    => $issue['details'],
					'created_at' => $now,
				),
				array( '%d', '%s', '%s', '%s', '%s' )
			);
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
				'scanned_at'       => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s' )
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

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$issues} WHERE issue_type IN (%s, %s)", 'title_duplicate', 'description_duplicate' ) );

		$checks = array(
			'title_duplicate'       => 'title_hash',
			'description_duplicate' => 'description_hash',
		);
		$now    = current_time( 'mysql', true );

		foreach ( $checks as $type => $column ) {
			$rows = $wpdb->get_results(
				"SELECT pg.post_id, dup.total FROM {$pages} pg
				INNER JOIN (
					SELECT {$column} AS hash, COUNT(*) AS total FROM {$pages}
					WHERE {$column} <> '' GROUP BY {$column} HAVING COUNT(*) > 1
				) dup ON dup.hash = pg.{$column}"
			);

			foreach ( $rows as $row ) {
				$wpdb->insert(
					$issues,
					array(
						'post_id'    => (int) $row->post_id,
						'issue_type' => $type,
						'severity'   => SEOHC_Issue_Types::severity( $type ),
						/* translators: %d: number of pages sharing the value. */
						'details'    => sprintf( __( 'Shared by %d pages.', 'seo-health-check' ), (int) $row->total ),
						'created_at' => $now,
					),
					array( '%d', '%s', '%s', '%s', '%s' )
				);
			}
		}

		// Keep issue_count in sync after adding duplicates.
		$wpdb->query( "UPDATE {$pages} SET issue_count = (SELECT COUNT(*) FROM {$issues} WHERE {$issues}.post_id = {$pages}.post_id)" );
	}

	/**
	 * Builds the WHERE clause for the overview filters.
	 *
	 * @param array $args Filters: issue_type, post_type, severity, search.
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

		return 'WHERE ' . implode( ' AND ', $clauses );
	}

	/**
	 * Fetches issues for the overview / export.
	 *
	 * @param array $args Filters plus orderby, order, per_page (0 = all) and page.
	 * @return object[] Rows with id, post_id, issue_type, severity, details, created_at, post_title, post_type, post_status.
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
		$limit   = '';

		if ( $args['per_page'] > 0 ) {
			$limit = $wpdb->prepare( 'LIMIT %d OFFSET %d', $args['per_page'], ( max( 1, (int) $args['page'] ) - 1 ) * $args['per_page'] );
		}

		return $wpdb->get_results( "SELECT i.*, p.post_title, p.post_type, p.post_status FROM {$issues} i INNER JOIN {$wpdb->posts} p ON p.ID = i.post_id {$where} ORDER BY {$orderby} {$order}, i.id ASC {$limit}" );
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
	 * Summary numbers for the dashboard.
	 *
	 * @return array{pages: int, pages_with_issues: int, issues: int}
	 */
	public static function summary() {
		global $wpdb;
		$pages = self::pages_table();

		$row = $wpdb->get_row( "SELECT COUNT(*) AS pages, SUM(issue_count > 0) AS pages_with_issues, SUM(issue_count) AS issues FROM {$pages}" );

		return array(
			'pages'             => $row ? (int) $row->pages : 0,
			'pages_with_issues' => $row ? (int) $row->pages_with_issues : 0,
			'issues'            => $row ? (int) $row->issues : 0,
		);
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
