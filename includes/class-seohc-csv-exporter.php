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
	 * Sends the CSV file and exits.
	 *
	 * @param array $filters Filters: issue_type, post_type, severity, search.
	 */
	public static function send( array $filters ) {
		$rows     = SEOHC_Repository::get_issues(
			array_merge(
				$filters,
				array(
					'per_page' => 0,
					'orderby'  => 'post_title',
					'order'    => 'ASC',
				)
			)
		);
		$filename = 'seo-health-check-' . gmdate( 'Y-m-d' ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming to output, not the file system.

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
						$row->created_at,
					)
				)
			);
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
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
