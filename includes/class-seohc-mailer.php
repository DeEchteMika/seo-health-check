<?php
/**
 * Building, storing and sending the scan report.
 *
 * @package SEO_Health_Check
 */

defined( 'ABSPATH' ) || exit;

/**
 * Turns a finished scan into a report, keeps a copy and mails it.
 */
class SEOHC_Mailer {

	/**
	 * Folder inside wp-content/uploads where the kept reports live.
	 */
	const FOLDER = 'seo-health-check';

	/**
	 * Registers the hooks.
	 */
	public static function init() {
		add_action( 'seo_health_check_scan_finished', array( __CLASS__, 'on_scan_finished' ) );
	}

	/**
	 * Sends the report when the scan that just finished is the one the schedule started.
	 *
	 * A scan you start yourself does not mail anyone: you are already looking at the screen.
	 *
	 * @param array $state Final scan state.
	 */
	public static function on_scan_finished( $state ) {
		$pending = SEOHC_Schedule::state()['pending'];

		if ( '' === $pending || ! isset( $state['started_at'] ) || $pending !== (string) $state['started_at'] ) {
			return;
		}

		SEOHC_Schedule::remember( array( 'pending' => '' ) );
		self::send_report();
	}

	/**
	 * Builds the report, keeps a copy and mails it.
	 *
	 * @param bool $test Whether this is a test from the settings screen.
	 * @return true|WP_Error
	 */
	public static function send_report( $test = false ) {
		$recipients = SEOHC_Schedule::recipients();

		if ( empty( $recipients ) ) {
			$error = new WP_Error( 'seohc_no_recipients', __( 'No valid email address is set, so there was nobody to send the report to.', 'seo-health-check' ) );
			SEOHC_Schedule::remember( array( 'last_error' => $error->get_error_message() ) );
			return $error;
		}

		$summary = SEOHC_Repository::summary();
		$csv     = SEOHC_CSV_Exporter::build( self::filters() );
		$keep    = (int) SEOHC_Schedule::get( 'keep' );

		$stored = $keep > 0 ? self::store( $csv, $keep ) : '';

		// Nothing is kept, but the mail still needs a file on disk to attach.
		$attachment = '' !== $stored ? $stored : self::temporary( $csv );

		$message = '';
		$capture = static function ( $wp_error ) use ( &$message ) {
			$message = $wp_error->get_error_message();
		};

		add_action( 'wp_mail_failed', $capture );
		$sent = wp_mail(
			$recipients,
			self::subject( $test ),
			self::body( $summary, $test ),
			array( 'Content-Type: text/html; charset=UTF-8' ),
			$attachment ? array( $attachment ) : array()
		);
		remove_action( 'wp_mail_failed', $capture );

		if ( '' === $stored && $attachment ) {
			wp_delete_file( $attachment );
		}

		if ( ! $sent ) {
			$error = new WP_Error(
				'seohc_mail_failed',
				'' !== $message
					? $message
					: __( 'WordPress could not send the mail and did not say why. Most hosts need an SMTP plugin for this.', 'seo-health-check' )
			);
			SEOHC_Schedule::remember( array( 'last_error' => $error->get_error_message() ) );
			return $error;
		}

		SEOHC_Schedule::remember(
			array(
				'last_sent'  => current_time( 'mysql', true ),
				'last_error' => '',
				'last_file'  => '' !== $stored ? basename( $stored ) : '',
			)
		);

		return true;
	}

	/**
	 * Filters for the attached CSV: everything the site currently has open.
	 *
	 * @return array
	 */
	private static function filters() {
		return array(
			'issue_type' => '',
			'post_type'  => '',
			'severity'   => '',
			'search'     => '',
			'status'     => '',
			'new_since'  => SEOHC_Repository::scan_started_at(),
		);
	}

	/**
	 * Subject line.
	 *
	 * @param bool $test Whether this is a test.
	 * @return string
	 */
	private static function subject( $test ) {
		$subject = sprintf(
			/* translators: 1: site name, 2: date. */
			__( 'SEO report for %1$s, %2$s', 'seo-health-check' ),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			date_i18n( get_option( 'date_format' ) )
		);

		return $test ? sprintf(
			/* translators: %s: the normal subject line. */
			__( '[Test] %s', 'seo-health-check' ),
			$subject
		) : $subject;
	}

	/**
	 * The mail itself.
	 *
	 * @param array $summary Current totals.
	 * @param bool  $test    Whether this is a test.
	 * @return string
	 */
	private static function body( array $summary, $test ) {
		$previous = SEOHC_Repository::previous_run();
		$changes  = SEOHC_Repository::change_counts( SEOHC_Repository::scan_started_at() );
		$issues   = admin_url( 'admin.php?page=' . SEOHC_Admin::MENU_SLUG );

		$html = '<div style="font-family:-apple-system,Segoe UI,Roboto,sans-serif;font-size:15px;color:#1d2327;line-height:1.5">';

		if ( $test ) {
			$html .= '<p style="padding:10px 12px;background:#fcf9e8;border-left:4px solid #dba617">'
				. esc_html__( 'This is a test message from the SEO Health Check settings. The numbers are the ones from the most recent scan.', 'seo-health-check' )
				. '</p>';
		}

		$html .= '<p>' . sprintf(
			/* translators: %s: site name. */
			esc_html__( 'The results of the latest scan of %s.', 'seo-health-check' ),
			'<strong>' . esc_html( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ) . '</strong>'
		) . '</p>';

		$rows = array(
			array( __( 'Average score', 'seo-health-check' ), number_format_i18n( $summary['score'] ), $previous ? self::difference( $summary['score'], $previous['score'], false ) : '' ),
			array( __( 'Issues', 'seo-health-check' ), number_format_i18n( $summary['issues'] ), $previous ? self::difference( $summary['issues'], $previous['issues'] ) : '' ),
			array( __( 'Pages with issues', 'seo-health-check' ), number_format_i18n( $summary['pages_with_issues'] ), '' ),
			array( __( 'Pages scanned', 'seo-health-check' ), number_format_i18n( $summary['pages'] ), '' ),
		);

		$html .= '<table style="border-collapse:collapse;margin:16px 0">';
		foreach ( $rows as $row ) {
			$html .= '<tr>'
				. '<td style="padding:4px 16px 4px 0;color:#50575e">' . esc_html( $row[0] ) . '</td>'
				. '<td style="padding:4px 8px 4px 0;font-weight:600;text-align:right">' . esc_html( $row[1] ) . '</td>'
				. '<td style="padding:4px 0;color:#50575e">' . esc_html( $row[2] ) . '</td>'
				. '</tr>';
		}
		$html .= '</table>';

		if ( $previous ) {
			$html .= '<p>' . sprintf(
				/* translators: 1: number of new issues, 2: number of solved issues. */
				esc_html__( 'Since the previous scan: %1$s new, %2$s solved.', 'seo-health-check' ),
				'<strong>' . esc_html( number_format_i18n( $changes['new'] ) ) . '</strong>',
				'<strong>' . esc_html( number_format_i18n( $changes['resolved'] ) ) . '</strong>'
			) . '</p>';
		}

		$html .= self::type_list();
		$html .= self::trend();

		$html .= '<p style="margin-top:20px">'
			. esc_html__( 'The full list is attached as a CSV file, ready for Excel.', 'seo-health-check' )
			. ' <a href="' . esc_url( $issues ) . '">' . esc_html__( 'Open the overview in WordPress', 'seo-health-check' ) . '</a>'
			. '</p>';

		return $html . '</div>';
	}

	/**
	 * The five kinds of problem the site has most of.
	 *
	 * @return string
	 */
	private static function type_list() {
		$counts = SEOHC_Repository::counts_by_type();
		if ( empty( $counts ) ) {
			return '<p>' . esc_html__( 'No issues were found at all. Nothing to do.', 'seo-health-check' ) . '</p>';
		}

		arsort( $counts );
		$counts = array_slice( $counts, 0, 5, true );

		$html = '<p style="margin-bottom:4px"><strong>' . esc_html__( 'Most common issues', 'seo-health-check' ) . '</strong></p><ul style="margin:0 0 16px;padding-left:20px">';
		foreach ( $counts as $type => $total ) {
			$html .= '<li>' . esc_html( SEOHC_Issue_Types::label( $type ) ) . ' &mdash; ' . esc_html( number_format_i18n( $total ) ) . '</li>';
		}

		return $html . '</ul>';
	}

	/**
	 * The totals of the last few scans, so the reader sees the direction.
	 *
	 * Only the numbers of an older scan are kept, never its list of issues, so this is as far
	 * back as a report can look.
	 *
	 * @return string
	 */
	private static function trend() {
		$runs = array_slice( SEOHC_Repository::run_history(), -5 );
		if ( count( $runs ) < 2 ) {
			return '';
		}

		$html = '<p style="margin-bottom:4px"><strong>' . esc_html__( 'Recent scans', 'seo-health-check' ) . '</strong></p><ul style="margin:0;padding-left:20px">';

		foreach ( array_reverse( $runs ) as $run ) {
			$html .= '<li>' . esc_html(
				sprintf(
					/* translators: 1: date of the scan, 2: number of issues, 3: average score. */
					__( '%1$s: %2$s issues, score %3$s', 'seo-health-check' ),
					get_date_from_gmt( $run['finished_at'], get_option( 'date_format' ) ),
					number_format_i18n( (int) $run['issues'] ),
					number_format_i18n( (int) $run['score'] )
				)
			) . '</li>';
		}

		return $html . '</ul>';
	}

	/**
	 * The change against the previous scan, in words.
	 *
	 * @param int  $current      Current value.
	 * @param int  $previous     Value at the previous scan.
	 * @param bool $less_is_good Whether a decrease is an improvement.
	 * @return string
	 */
	private static function difference( $current, $previous, $less_is_good = true ) {
		$difference = (int) $current - (int) $previous;

		if ( 0 === $difference ) {
			return __( '(unchanged)', 'seo-health-check' );
		}

		$better = $less_is_good ? $difference < 0 : $difference > 0;

		return sprintf(
			$better
				/* translators: %s: how much the number improved. */
				? __( '(%s better)', 'seo-health-check' )
				/* translators: %s: how much the number got worse. */
				: __( '(%s worse)', 'seo-health-check' ),
			number_format_i18n( abs( $difference ) )
		);
	}

	/**
	 * The folder the kept reports live in, created and shielded on first use.
	 *
	 * @return string Empty when it could not be created.
	 */
	public static function folder() {
		$uploads = wp_get_upload_dir();
		$folder  = trailingslashit( $uploads['basedir'] ) . self::FOLDER;

		if ( ! wp_mkdir_p( $folder ) ) {
			return '';
		}

		// Reports name every page of the site and its weak spots, so they are not for visitors.
		// The .htaccess covers Apache; the random part of every file name covers the rest.
		$guard = $folder . '/.htaccess';
		if ( ! file_exists( $guard ) ) {
			$rules = "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
				. "<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n";
			file_put_contents( $guard, $rules ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a protection file in the plugin's own folder.
		}

		$index = $folder . '/index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a protection file in the plugin's own folder.
		}

		return $folder;
	}

	/**
	 * Writes the report into that folder and throws away the ones beyond the limit.
	 *
	 * @param string $csv  File contents.
	 * @param int    $keep How many reports to keep.
	 * @return string Path to the file, empty when it could not be written.
	 */
	private static function store( $csv, $keep ) {
		$folder = self::folder();
		if ( '' === $folder ) {
			return '';
		}

		$name = sprintf(
			'seo-health-check-%s-%s.csv',
			current_time( 'Y-m-d-Hi' ),
			wp_generate_password( 12, false, false )
		);
		$path = $folder . '/' . $name;

		if ( false === file_put_contents( $path, $csv ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing the report the user asked to keep.
			return '';
		}

		foreach ( array_slice( self::stored(), $keep ) as $old ) {
			wp_delete_file( $old['path'] );
		}

		return $path;
	}

	/**
	 * A throwaway copy for when nothing is kept but the mail still needs an attachment.
	 *
	 * @param string $csv File contents.
	 * @return string Path, empty on failure.
	 */
	private static function temporary( $csv ) {
		$path = wp_tempnam( 'seo-health-check.csv' );

		if ( ! $path || false === file_put_contents( $path, $csv ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a temporary attachment, removed right after sending.
			return '';
		}

		return $path;
	}

	/**
	 * The kept reports, newest first.
	 *
	 * @return array<int, array{name: string, path: string, size: int, time: int}>
	 */
	public static function stored() {
		$uploads = wp_get_upload_dir();
		$folder  = trailingslashit( $uploads['basedir'] ) . self::FOLDER;
		$files   = glob( $folder . '/seo-health-check-*.csv' );
		$reports = array();

		foreach ( (array) $files as $path ) {
			$reports[] = array(
				'name' => basename( $path ),
				'path' => $path,
				'size' => (int) filesize( $path ),
				'time' => (int) filemtime( $path ),
			);
		}

		usort(
			$reports,
			static function ( $a, $b ) {
				return $b['time'] <=> $a['time'];
			}
		);

		return $reports;
	}

	/**
	 * Resolves a file name to a kept report, or null when it is not one.
	 *
	 * The name is reduced to its last part and matched against the pattern, so a crafted name
	 * cannot walk out of the folder.
	 *
	 * @param string $name File name.
	 * @return string|null
	 */
	public static function stored_path( $name ) {
		$name = basename( (string) $name );

		if ( ! preg_match( '/^seo-health-check-[0-9-]+-[A-Za-z0-9]+\.csv$/', $name ) ) {
			return null;
		}

		$uploads = wp_get_upload_dir();
		$path    = trailingslashit( $uploads['basedir'] ) . self::FOLDER . '/' . $name;

		return file_exists( $path ) ? $path : null;
	}

	/**
	 * Removes the whole folder. Used when the plugin is deleted.
	 */
	public static function delete_all() {
		$uploads = wp_get_upload_dir();
		$folder  = trailingslashit( $uploads['basedir'] ) . self::FOLDER;

		foreach ( (array) glob( $folder . '/*' ) as $file ) {
			wp_delete_file( $file );
		}

		if ( is_dir( $folder ) ) {
			rmdir( $folder ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- removing the plugin's own folder on uninstall.
		}
	}
}
