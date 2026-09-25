<?php
/**
 * The scan history screen.
 *
 * @package SEO_Health_Check
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lists the finished scans with their totals and what each one changed.
 */
class SEOHC_Scans_Page {

	/**
	 * Renders the screen.
	 */
	public static function render_page() {
		if ( ! current_user_can( SEOHC_Plugin::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'seo-health-check' ), '', array( 'response' => 403 ) );
		}

		// Newest first: the scan you just ran is the one you came here for.
		$runs = array_reverse( SEOHC_Repository::run_history() );
		?>
		<div class="wrap shc-wrap">
			<h1><?php esc_html_e( 'Scan history', 'seo-health-check' ); ?></h1>
			<p class="seohc-intro">
				<?php esc_html_e( 'What every finished scan found, newest first, so you can see whether the site is moving forwards. The last twenty scans are kept.', 'seo-health-check' ); ?>
			</p>

			<?php if ( empty( $runs ) ) : ?>
				<p>
					<?php
					printf(
						/* translators: %s: link to the issues overview. */
						esc_html__( 'No finished scan yet. Start a full scan on the %s screen and it will show up here.', 'seo-health-check' ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=' . SEOHC_Admin::MENU_SLUG ) ) . '">' . esc_html__( 'Issues', 'seo-health-check' ) . '</a>'
					);
					?>
				</p>
			<?php else : ?>
				<?php self::render_runs( $runs ); ?>
				<?php self::render_by_type( $runs ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Table with one row per finished scan.
	 *
	 * @param array[] $runs Snapshots, newest first.
	 */
	private static function render_runs( array $runs ) {
		?>
		<table class="wp-list-table widefat striped seohc-runs">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Finished', 'seo-health-check' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Took', 'seo-health-check' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Average score', 'seo-health-check' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Issues', 'seo-health-check' ); ?></th>
					<th scope="col"><?php esc_html_e( 'New', 'seo-health-check' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Fixed', 'seo-health-check' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Pages with issues', 'seo-health-check' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Pages scanned', 'seo-health-check' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $runs as $index => $run ) : ?>
					<?php $before = isset( $runs[ $index + 1 ] ) ? $runs[ $index + 1 ] : null; ?>
					<tr>
						<td>
							<strong><?php echo esc_html( self::datetime( $run['finished_at'] ) ); ?></strong>
							<?php if ( 0 === $index ) : ?>
								<span class="seohc-badge seohc-badge--new"><?php esc_html_e( 'Latest', 'seo-health-check' ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( self::duration( $run ) ); ?></td>
						<td>
							<?php echo wp_kses_post( SEOHC_Admin::score_badge( (int) $run['score'] ) ); ?>
							<?php if ( $before ) : ?>
								<?php echo wp_kses_post( SEOHC_Admin::delta_badge( $run['score'], $before['score'], false ) ); ?>
							<?php endif; ?>
						</td>
						<td>
							<?php echo esc_html( number_format_i18n( (int) $run['issues'] ) ); ?>
							<?php if ( $before ) : ?>
								<?php echo wp_kses_post( SEOHC_Admin::delta_badge( $run['issues'], $before['issues'] ) ); ?>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( self::count( $run, 'new' ) ); ?></td>
						<td><?php echo esc_html( self::count( $run, 'resolved' ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) $run['pages_with_issues'] ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) $run['pages'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Table comparing the last scan with the one before it, per issue type.
	 *
	 * @param array[] $runs Snapshots, newest first.
	 */
	private static function render_by_type( array $runs ) {
		if ( ! isset( $runs[1] ) ) {
			return;
		}

		$latest = isset( $runs[0]['by_type'] ) ? (array) $runs[0]['by_type'] : array();
		$before = isset( $runs[1]['by_type'] ) ? (array) $runs[1]['by_type'] : array();
		$types  = array_unique( array_merge( array_keys( $latest ), array_keys( $before ) ) );

		if ( empty( $types ) ) {
			return;
		}

		// Worst first: the kind of problem you have most of is the one to start on.
		usort(
			$types,
			static function ( $a, $b ) use ( $latest ) {
				$first  = isset( $latest[ $a ] ) ? (int) $latest[ $a ] : 0;
				$second = isset( $latest[ $b ] ) ? (int) $latest[ $b ] : 0;
				return $second <=> $first;
			}
		);
		?>
		<h2><?php esc_html_e( 'Per kind of problem', 'seo-health-check' ); ?></h2>
		<p class="seohc-intro">
			<?php
			printf(
				/* translators: 1: date of the previous scan, 2: date of the most recent scan. */
				esc_html__( 'The scan of %1$s compared with the one of %2$s.', 'seo-health-check' ),
				esc_html( self::datetime( $runs[1]['finished_at'] ) ),
				esc_html( self::datetime( $runs[0]['finished_at'] ) )
			);
			?>
		</p>
		<table class="wp-list-table widefat striped seohc-runs">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Issue', 'seo-health-check' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Now', 'seo-health-check' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Previous scan', 'seo-health-check' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Change', 'seo-health-check' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $types as $type ) : ?>
					<?php
					$now  = isset( $latest[ $type ] ) ? (int) $latest[ $type ] : 0;
					$then = isset( $before[ $type ] ) ? (int) $before[ $type ] : 0;
					$link = add_query_arg( 'issue_type', $type, admin_url( 'admin.php?page=' . SEOHC_Admin::MENU_SLUG ) );
					?>
					<tr>
						<td>
							<?php if ( $now > 0 ) : ?>
								<a href="<?php echo esc_url( $link ); ?>"><?php echo esc_html( SEOHC_Issue_Types::label( $type ) ); ?></a>
							<?php else : ?>
								<?php echo esc_html( SEOHC_Issue_Types::label( $type ) ); ?>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( number_format_i18n( $now ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $then ) ); ?></td>
						<td><?php echo wp_kses_post( SEOHC_Admin::delta_badge( $now, $then ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * A stored count, or a dash for scans from before the plugin kept it.
	 *
	 * @param array  $run Snapshot.
	 * @param string $key 'new' or 'resolved'.
	 * @return string
	 */
	private static function count( array $run, $key ) {
		if ( ! isset( $run[ $key ] ) ) {
			return '—';
		}
		return number_format_i18n( (int) $run[ $key ] );
	}

	/**
	 * How long a scan took, in words.
	 *
	 * @param array $run Snapshot.
	 * @return string
	 */
	private static function duration( array $run ) {
		if ( empty( $run['started_at'] ) || empty( $run['finished_at'] ) ) {
			return '—';
		}

		$from    = strtotime( $run['started_at'] );
		$to      = strtotime( $run['finished_at'] );
		$seconds = max( 0, $to - $from );

		if ( $seconds < MINUTE_IN_SECONDS ) {
			return sprintf(
				/* translators: %s: number of seconds. */
				_n( '%s second', '%s seconds', $seconds, 'seo-health-check' ),
				number_format_i18n( $seconds )
			);
		}

		return human_time_diff( $from, $to );
	}

	/**
	 * A GMT datetime in the site's own format and time zone.
	 *
	 * @param string $gmt GMT datetime.
	 * @return string
	 */
	private static function datetime( $gmt ) {
		if ( empty( $gmt ) ) {
			return '—';
		}
		return get_date_from_gmt( $gmt, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
	}
}
