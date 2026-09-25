<?php
/**
 * CSV export of scan results.
 *
 * @package SEO_Health_Check
 */

defined( 'ABSPATH' ) || exit;

/**
 * Streams the (filtered) issues as a CSV download.
 */
class SEOHC_CSV_Exporter {

	/**
	 * Sends the CSV file as a download and exits.
	 *
	 * @param array $filters Filters: issue_type, post_type, severity, search, status.
	 */
	public static function send( array $filters ) {
		$filename = 'seo-health-check-' . gmdate( 'Y-m-d' ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		// Straight to the browser, so a site with tens of thousands of issues never has to hold
		// the whole file in memory at once.
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming to output, not the file system.
		self::write( $out, $filters );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		exit;
	}

	/**
	 * Builds the CSV and returns it, for attaching to a mail or writing to a file.
	 *
	 * @param array $filters Filters: issue_type, post_type, severity, search, status.
	 * @return string
	 */
	public static function build( array $filters ) {
		$out = fopen( 'php://temp', 'w+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- a scratch stream, not the file system.

		self::write( $out, $filters );
		rewind( $out );
		$csv = (string) stream_get_contents( $out );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return $csv;
	}

	/**
	 * Writes the whole file to an open stream.
	 *
	 * @param resource $out     Stream to write to.
	 * @param array    $filters Filters: issue_type, post_type, severity, search, status.
	 */
	private static function write( $out, array $filters ) {
		$rows      = SEOHC_Repository::get_issues(
			array_merge(
				$filters,
				array(
					'per_page' => 0,
					'orderby'  => 'post_title',
					'order'    => 'ASC',
				)
			)
		);
		$new_since = isset( $filters['new_since'] ) ? (string) $filters['new_since'] : '';

		// UTF-8 BOM so Excel opens accented characters correctly.
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

		fputcsv(
			$out,
			array(
				__( 'Post ID', 'seo-health-check' ),
				__( 'Title', 'seo-health-check' ),
				__( 'Post type', 'seo-health-check' ),
				__( 'URL', 'seo-health-check' ),
				__( 'Edit link', 'seo-health-check' ),
				__( 'Issue', 'seo-health-check' ),
				__( 'Severity', 'seo-health-check' ),
				__( 'Details', 'seo-health-check' ),
				__( 'Status', 'seo-health-check' ),
				__( 'Scanned at (UTC)', 'seo-health-check' ),
			)
		);

		foreach ( $rows as $row ) {
			fputcsv(
				$out,
				array_map(
					array( __CLASS__, 'safe_cell' ),
					array(
						$row->post_id,
						$row->post_title,
						$row->post_type,
						get_permalink( $row->post_id ),
						admin_url( 'post.php?post=' . (int) $row->post_id . '&action=edit' ),
						SEOHC_Issue_Types::label( $row->issue_type ),
						$row->severity,
						$row->details,
						self::status_label( $row, $new_since ),
						$row->created_at,
					)
				)
			);
		}
	}

	/**
	 * What the last scan did with this issue, in words, for the Status column.
	 *
	 * @param object $row       Issue row.
	 * @param string $new_since GMT datetime the most recent scan started.
	 * @return string
	 */
	private static function status_label( $row, $new_since ) {
		if ( ! empty( $row->resolved_at ) ) {
			return __( 'Fixed', 'seo-health-check' );
		}

		if ( '' !== $new_since && $row->first_seen >= $new_since ) {
			return __( 'New', 'seo-health-check' );
		}

		return __( 'Unchanged', 'seo-health-check' );
	}

	/**
	 * Prevents CSV formula injection: spreadsheet apps execute cells starting with = + - @.
	 *
	 * @param mixed $value Cell value.
	 * @return string
	 */
	public static function safe_cell( $value ) {
		$value = (string) $value;
		if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			$value = "'" . $value;
		}
		return $value;
	}
}
