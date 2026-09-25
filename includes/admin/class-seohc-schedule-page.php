<?php
/**
 * The screen for scanning on a schedule.
 *
 * @package SEO_Health_Check
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings, status and the kept reports.
 */
class SEOHC_Schedule_Page {

	/**
	 * Registers the form handlers.
	 */
	public static function init() {
		add_action( 'admin_post_seohc_test_report', array( __CLASS__, 'handle_test' ) );
		add_action( 'admin_post_seohc_download_report', array( __CLASS__, 'handle_download' ) );
	}

	/**
	 * Renders the screen.
	 */
	public static function render_page() {
		self::require_capability();

		$state = SEOHC_Schedule::state();
		$next  = SEOHC_Schedule::next_run();
		?>
		<div class="wrap shc-wrap">
			<h1><?php esc_html_e( 'Automatic scan', 'seo-health-check' ); ?></h1>
			<p class="seohc-intro">
				<?php esc_html_e( 'Let the plugin scan the site by itself and send the results by email afterwards, with the full list as a CSV file attached.', 'seo-health-check' ); ?>
			</p>

			<?php self::render_notice(); ?>
			<?php self::render_status( $next, $state ); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
				<?php
				settings_fields( SEOHC_Schedule::OPTION_GRP );
				do_settings_sections( SEOHC_Schedule::PAGE_SLUG );
				submit_button();
				?>
			</form>

			<?php self::render_test_button(); ?>
			<?php self::render_reports(); ?>
		</div>
		<?php
	}

	/**
	 * Box with what is planned and what happened last time.
	 *
	 * @param int   $next  Timestamp of the next scan, 0 when nothing is planned.
	 * @param array $state What the plugin remembers about the reports.
	 */
	private static function render_status( $next, array $state ) {
		?>
		<div class="shc-panel">
			<p>
				<?php if ( $next > 0 ) : ?>
					<strong>
						<?php
						printf(
							/* translators: %s: date and time of the next scan. */
							esc_html__( 'Next scan: %s', 'seo-health-check' ),
							esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next ) )
						);
						?>
					</strong>
				<?php else : ?>
					<strong><?php esc_html_e( 'No scan is planned.', 'seo-health-check' ); ?></strong>
				<?php endif; ?>
			</p>

			<?php if ( $next > 0 ) : ?>
				<p class="description">
					<?php esc_html_e( 'WordPress runs planned jobs when someone visits the site, so on a quiet site the scan can start later than the time you set. A real cron job on the server that calls wp-cron.php makes it exact.', 'seo-health-check' ); ?>
				</p>
			<?php endif; ?>

			<?php if ( '' !== $state['last_sent'] ) : ?>
				<p>
					<?php
					printf(
						/* translators: %s: date and time the last report was sent. */
						esc_html__( 'Last report sent: %s', 'seo-health-check' ),
						esc_html( get_date_from_gmt( $state['last_sent'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) )
					);
					?>
				</p>
			<?php endif; ?>

			<?php if ( '' !== $state['last_error'] ) : ?>
				<p class="seohc-mail-error">
					<strong><?php esc_html_e( 'The last attempt failed:', 'seo-health-check' ); ?></strong>
					<?php echo esc_html( $state['last_error'] ); ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * The "send a test" form.
	 */
	private static function render_test_button() {
		$recipients = SEOHC_Schedule::recipients();
		?>
		<h2><?php esc_html_e( 'Try it first', 'seo-health-check' ); ?></h2>
		<p>
			<?php esc_html_e( 'Sends the report of the most recent scan right now, so you can see whether mail from this site arrives at all. Many hosts need an SMTP plugin before WordPress can send anything.', 'seo-health-check' ); ?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="seohc_test_report">
			<?php wp_nonce_field( 'seohc_test_report' ); ?>
			<?php
			submit_button(
				empty( $recipients )
					? __( 'Fill in an address first', 'seo-health-check' )
					: __( 'Send a test report', 'seo-health-check' ),
				'secondary',
				'submit',
				false,
				empty( $recipients ) ? array( 'disabled' => 'disabled' ) : array()
			);
			?>
		</form>
		<?php
	}

	/**
	 * The list of kept reports.
	 */
	private static function render_reports() {
		$reports = SEOHC_Mailer::stored();
		?>
		<h2><?php esc_html_e( 'Kept reports', 'seo-health-check' ); ?></h2>

		<?php if ( empty( $reports ) ) : ?>
			<p><?php esc_html_e( 'No reports have been kept yet.', 'seo-health-check' ); ?></p>
			<?php return; ?>
		<?php endif; ?>

		<p class="description">
			<?php esc_html_e( 'These files name every page of the site and what is wrong with it, so they are shielded from visitors and can only be downloaded from here.', 'seo-health-check' ); ?>
		</p>
		<table class="wp-list-table widefat striped seohc-runs">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Report', 'seo-health-check' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Size', 'seo-health-check' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Download', 'seo-health-check' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $reports as $report ) : ?>
					<tr>
						<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $report['time'] ) ); ?></td>
						<td><?php echo esc_html( size_format( $report['size'] ) ); ?></td>
						<td>
							<a href="
							<?php
							echo esc_url(
								wp_nonce_url(
									add_query_arg(
										array(
											'action' => 'seohc_download_report',
											'file'   => rawurlencode( $report['name'] ),
										),
										admin_url( 'admin-post.php' )
									),
									'seohc_download_report'
								)
							);
							?>
							"><?php esc_html_e( 'Download CSV', 'seo-health-check' ); ?></a>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Handles "Send a test report".
	 */
	public static function handle_test() {
		self::require_capability();
		check_admin_referer( 'seohc_test_report' );

		$result = SEOHC_Mailer::send_report( true );

		self::redirect( is_wp_error( $result ) ? 'test_failed' : 'test_sent' );
	}

	/**
	 * Streams a kept report, so the file itself never needs a public URL.
	 */
	public static function handle_download() {
		self::require_capability();
		check_admin_referer( 'seohc_download_report' );

		$name = isset( $_GET['file'] ) ? sanitize_file_name( wp_unslash( $_GET['file'] ) ) : '';
		$path = SEOHC_Mailer::stored_path( $name );

		if ( null === $path ) {
			wp_die( esc_html__( 'That report no longer exists.', 'seo-health-check' ), '', array( 'response' => 404 ) );
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . basename( $path ) . '"' );
		header( 'Content-Length: ' . filesize( $path ) );

		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming the plugin's own report.
		exit;
	}

	/**
	 * Notice after a test.
	 */
	private static function render_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks which message to show.
		$notice = isset( $_GET['seohc_notice'] ) ? sanitize_key( wp_unslash( $_GET['seohc_notice'] ) ) : '';

		if ( 'test_sent' === $notice ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html__( 'The test report was handed to WordPress. Check the inbox, and the spam folder.', 'seo-health-check' )
			);
			return;
		}

		if ( 'test_failed' === $notice ) {
			printf(
				'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
				esc_html__( 'The test report could not be sent. The reason is shown below.', 'seo-health-check' )
			);
		}
	}

	/**
	 * Back to this screen with a message.
	 *
	 * @param string $notice Notice key.
	 */
	private static function redirect( $notice ) {
		wp_safe_redirect(
			add_query_arg(
				'seohc_notice',
				$notice,
				admin_url( 'admin.php?page=' . SEOHC_Schedule::PAGE_SLUG )
			)
		);
		exit;
	}

	/**
	 * Dies unless the current user may use the plugin.
	 */
	private static function require_capability() {
		if ( ! current_user_can( SEOHC_Plugin::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'seo-health-check' ), '', array( 'response' => 403 ) );
		}
	}
}
