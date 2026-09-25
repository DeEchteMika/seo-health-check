<?php
/**
 * The widget on the wp-admin home screen.
 *
 * @package SEO_Health_Check
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shows the main numbers of the last scan on the dashboard.
 */
class SEOHC_Dashboard_Widget {

	/**
	 * Registers the widget, for users who may see the results.
	 */
	public static function register() {
		if ( ! current_user_can( SEOHC_Plugin::capability() ) ) {
			return;
		}

		wp_add_dashboard_widget(
			'seohc_dashboard',
			__( 'SEO Health Check', 'seo-health-check' ),
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Renders the widget.
	 */
	public static function render() {
		$summary = SEOHC_Repository::summary();
		$state   = SEOHC_Scan_Queue::get_state();

		if ( 0 === $summary['pages'] ) {
			self::render_empty();
			return;
		}

		$previous = SEOHC_Repository::previous_run();
		?>
		<div class="seohc-widget">
			<p class="seohc-widget__score">
				<?php echo wp_kses_post( SEOHC_Admin::score_badge( $summary['score'] ) ); ?>
				<span class="seohc-widget__label">
					<?php esc_html_e( 'Average score', 'seo-health-check' ); ?>
					<?php if ( $previous ) : ?>
						<?php echo wp_kses_post( SEOHC_Admin::delta_badge( $summary['score'], $previous['score'], false ) ); ?>
					<?php endif; ?>
				</span>
			</p>

			<p class="seohc-widget__totals">
				<?php
				printf(
					/* translators: %s: number of issues. */
					esc_html( _n( '%s issue', '%s issues', $summary['issues'], 'seo-health-check' ) ),
					'<strong>' . esc_html( number_format_i18n( $summary['issues'] ) ) . '</strong>'
				);
				echo ' &middot; ';
				printf(
					/* translators: 1: number of pages with issues, 2: number of pages scanned. */
					esc_html__( '%1$s of %2$s pages need attention', 'seo-health-check' ),
					'<strong>' . esc_html( number_format_i18n( $summary['pages_with_issues'] ) ) . '</strong>',
					esc_html( number_format_i18n( $summary['pages'] ) )
				);
				?>
			</p>

			<?php self::render_changes( $previous ); ?>
			<?php self::render_status( $state ); ?>

			<p class="seohc-widget__actions">
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=' . SEOHC_Admin::MENU_SLUG ) ); ?>">
					<?php esc_html_e( 'View issues', 'seo-health-check' ); ?>
				</a>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . SEOHC_Admin::PAGES_SLUG ) ); ?>">
					<?php esc_html_e( 'Page scores', 'seo-health-check' ); ?>
				</a>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . SEOHC_Admin::SCANS_SLUG ) ); ?>">
					<?php esc_html_e( 'Scans', 'seo-health-check' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * What the last scan added and solved, when there is a scan to compare with.
	 *
	 * @param array|null $previous Snapshot of the scan before the most recent one.
	 */
	private static function render_changes( $previous ) {
		if ( ! $previous ) {
			return;
		}

		$changes = SEOHC_Repository::change_counts( SEOHC_Admin::new_since() );
		$base    = admin_url( 'admin.php?page=' . SEOHC_Admin::MENU_SLUG );
		?>
		<p class="seohc-widget__changes">
			<a href="<?php echo esc_url( add_query_arg( 'seohc_status', 'new', $base ) ); ?>">
				<span class="seohc-badge seohc-badge--new"><?php esc_html_e( 'New', 'seo-health-check' ); ?></span>
				<?php echo esc_html( number_format_i18n( $changes['new'] ) ); ?>
			</a>
			<a href="<?php echo esc_url( add_query_arg( 'seohc_status', 'resolved', $base ) ); ?>">
				<span class="seohc-badge seohc-badge--resolved"><?php esc_html_e( 'Fixed', 'seo-health-check' ); ?></span>
				<?php echo esc_html( number_format_i18n( $changes['resolved'] ) ); ?>
			</a>
			<span class="seohc-widget__note"><?php esc_html_e( 'since the previous scan', 'seo-health-check' ); ?></span>
		</p>
		<?php
	}

	/**
	 * Line with the scan state.
	 *
	 * @param array $state Scan state.
	 */
	private static function render_status( array $state ) {
		if ( 'running' === $state['status'] ) {
			?>
			<p class="seohc-widget__note">
				<strong>
					<?php
					/* translators: 1: processed posts, 2: total posts. */
					echo esc_html( sprintf( __( 'Scan in progress: %1$s of %2$s pages.', 'seo-health-check' ), number_format_i18n( $state['processed'] ), number_format_i18n( $state['total'] ) ) );
					?>
				</strong>
			</p>
			<?php
			return;
		}

		if ( empty( $state['finished_at'] ) ) {
			return;
		}
		?>
		<p class="seohc-widget__note">
			<?php
			printf(
				/* translators: %s: date and time of the last scan. */
				esc_html__( 'Last scan: %s', 'seo-health-check' ),
				esc_html( get_date_from_gmt( $state['finished_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) )
			);
			?>
		</p>
		<?php
	}

	/**
	 * Message for a site that has not been scanned yet.
	 */
	private static function render_empty() {
		?>
		<div class="seohc-widget">
			<p><?php esc_html_e( 'This site has not been scanned yet.', 'seo-health-check' ); ?></p>
			<p class="seohc-widget__actions">
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=' . SEOHC_Admin::MENU_SLUG ) ); ?>">
					<?php esc_html_e( 'Start a scan', 'seo-health-check' ); ?>
				</a>
			</p>
		</div>
		<?php
	}
}
