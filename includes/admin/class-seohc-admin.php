<?php
/**
 * Admin screens and request handlers.
 *
 * @package SEO_Health_Check
 */

defined( 'ABSPATH' ) || exit;

/**
 * Menus, pages, form handlers and the progress endpoint.
 */
class SEOHC_Admin {

	const MENU_SLUG  = 'seo-health-check';
	const PAGES_SLUG = 'seo-health-check-pages';

	/**
	 * Hook suffixes of the plugin's admin pages.
	 *
	 * @var string[]
	 */
	private static $hooks = array();

	/**
	 * Registers admin hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_init', array( 'SEOHC_Settings', 'register' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );

		add_action( 'admin_post_seohc_start_scan', array( __CLASS__, 'handle_start_scan' ) );
		add_action( 'admin_post_seohc_cancel_scan', array( __CLASS__, 'handle_cancel_scan' ) );
		add_action( 'admin_post_seohc_rescan_post', array( __CLASS__, 'handle_rescan_post' ) );
		add_action( 'admin_post_seohc_export', array( __CLASS__, 'handle_export' ) );
		add_action( 'wp_ajax_seohc_scan_status', array( __CLASS__, 'ajax_scan_status' ) );
		SEOHC_Launch_Checks::init();
		SEOHC_Inline_Edit::init();

		add_filter( 'option_page_capability_' . SEOHC_Settings::OPTION_GRP, array( 'SEOHC_Plugin', 'capability' ) );
		add_filter( 'set_screen_option_seohc_issues_per_page', array( __CLASS__, 'save_per_page' ), 10, 3 );
		add_filter( 'set_screen_option_seohc_pages_per_page', array( __CLASS__, 'save_per_page' ), 10, 3 );
		add_filter( 'plugin_action_links_' . plugin_basename( SEOHC_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Adds the menu and sub pages.
	 */
	public static function register_menu() {
		$cap = SEOHC_Plugin::capability();

		$overview = add_menu_page(
			__( 'SEO Health Check', 'seo-health-check' ),
			__( 'SEO Health', 'seo-health-check' ),
			$cap,
			self::MENU_SLUG,
			array( __CLASS__, 'render_overview' ),
			'dashicons-heart',
			80
		);
		add_submenu_page( self::MENU_SLUG, __( 'SEO issues', 'seo-health-check' ), __( 'Issues', 'seo-health-check' ), $cap, self::MENU_SLUG, array( __CLASS__, 'render_overview' ) );
		$pages    = add_submenu_page( self::MENU_SLUG, __( 'Page scores', 'seo-health-check' ), __( 'Page scores', 'seo-health-check' ), $cap, self::PAGES_SLUG, array( __CLASS__, 'render_pages' ) );
		$launch   = add_submenu_page( self::MENU_SLUG, __( 'Launch checks', 'seo-health-check' ), __( 'Launch checks', 'seo-health-check' ), $cap, 'seo-health-check-launch', array( 'SEOHC_Launch_Checks', 'render_page' ) );
		$settings = add_submenu_page( self::MENU_SLUG, __( 'SEO Health Check settings', 'seo-health-check' ), __( 'Settings', 'seo-health-check' ), $cap, SEOHC_Settings::PAGE_SLUG, array( 'SEOHC_Settings', 'render_page' ) );

		self::$hooks = array( $overview, $pages, $launch, $settings );

		add_action( 'load-' . $overview, array( __CLASS__, 'add_screen_options' ) );
		add_action( 'load-' . $pages, array( __CLASS__, 'add_pages_screen_options' ) );
	}

	/**
	 * "Items per page" screen option for the issues overview.
	 */
	public static function add_screen_options() {
		add_screen_option(
			'per_page',
			array(
				'default' => 50,
				'option'  => 'seohc_issues_per_page',
			)
		);
	}

	/**
	 * "Items per page" screen option for the page scores overview.
	 */
	public static function add_pages_screen_options() {
		add_screen_option(
			'per_page',
			array(
				'default' => 50,
				'option'  => 'seohc_pages_per_page',
			)
		);
	}

	/**
	 * Saves the "items per page" screen option.
	 *
	 * @param mixed  $status Default false.
	 * @param string $option Option name.
	 * @param mixed  $value  Submitted value.
	 * @return int
	 */
	public static function save_per_page( $status, $option, $value ) {
		return min( max( absint( $value ), 1 ), 500 );
	}

	/**
	 * Settings link on the Plugins screen.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift(
			$links,
			sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=' . SEOHC_Settings::PAGE_SLUG ) ), esc_html__( 'Settings', 'seo-health-check' ) )
		);
		return $links;
	}

	/**
	 * Loads CSS/JS on the plugin's own pages only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue_assets( $hook ) {
		if ( ! in_array( $hook, self::$hooks, true ) ) {
			return;
		}

		wp_enqueue_style( 'shc-admin', SEOHC_URL . 'assets/admin.css', array(), SEOHC_VERSION );
		wp_enqueue_script( 'shc-admin', SEOHC_URL . 'assets/admin.js', array(), SEOHC_VERSION, true );
		wp_localize_script(
			'shc-admin',
			'shcAdmin',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( 'seohc_scan_status' ),
				'inlineNonce' => wp_create_nonce( SEOHC_Inline_Edit::NONCE ),
				'i18n'        => array(
					/* translators: 1: processed posts, 2: total posts. */
					'progress' => __( '%1$s of %2$s pages scanned', 'seo-health-check' ),
					'finished' => __( 'Scan finished. Reloading…', 'seo-health-check' ),
					'saving'   => __( 'Saving…', 'seo-health-check' ),
					'failed'   => __( 'Could not save. Try again.', 'seo-health-check' ),
					/* translators: 1: characters used, 2: recommended maximum. */
					'counter'  => __( '%1$d of %2$d characters', 'seo-health-check' ),
				),
			)
		);
	}

	/**
	 * Sanitized filters for the overview and export, read from the query string.
	 *
	 * @return array
	 */
	public static function current_filters() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters, no state change.
		$issue_type = isset( $_GET['issue_type'] ) ? sanitize_key( wp_unslash( $_GET['issue_type'] ) ) : '';
		$post_type  = isset( $_GET['seohc_post_type'] ) ? sanitize_key( wp_unslash( $_GET['seohc_post_type'] ) ) : '';
		$severity   = isset( $_GET['severity'] ) ? sanitize_key( wp_unslash( $_GET['severity'] ) ) : '';
		$search     = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$orderby    = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'post_title';
		$order      = isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'asc';
		$only_new   = ! empty( $_GET['seohc_new'] );
		// phpcs:enable

		return array(
			'issue_type' => SEOHC_Issue_Types::exists( $issue_type ) ? $issue_type : '',
			'post_type'  => in_array( $post_type, (array) SEOHC_Settings::get( 'post_types' ), true ) ? $post_type : '',
			'severity'   => in_array( $severity, array( 'error', 'warning' ), true ) ? $severity : '',
			'search'     => $search,
			'orderby'    => array_key_exists( $orderby, SEOHC_Repository::SORTABLE ) ? $orderby : 'post_title',
			'order'      => 'desc' === $order ? 'DESC' : 'ASC',
			'new_since'  => $only_new ? self::new_since() : '',
			'only_new'   => $only_new,
		);
	}

	/**
	 * Sanitized filters for the page scores overview.
	 *
	 * @return array
	 */
	public static function current_page_filters() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters, no state change.
		$band      = isset( $_GET['band'] ) ? sanitize_key( wp_unslash( $_GET['band'] ) ) : '';
		$post_type = isset( $_GET['seohc_post_type'] ) ? sanitize_key( wp_unslash( $_GET['seohc_post_type'] ) ) : '';
		$search    = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$orderby   = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'score';
		$order     = isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'asc';
		// phpcs:enable

		return array(
			'band'      => array_key_exists( $band, SEOHC_Repository::score_bands() ) ? $band : '',
			'post_type' => in_array( $post_type, (array) SEOHC_Settings::get( 'post_types' ), true ) ? $post_type : '',
			'search'    => $search,
			'orderby'   => array_key_exists( $orderby, SEOHC_Repository::SORTABLE_PAGES ) ? $orderby : 'score',
			'order'     => 'desc' === $order ? 'DESC' : 'ASC',
		);
	}

	/**
	 * GMT datetime the most recent scan started. Issues first seen after it count as new.
	 *
	 * @return string Empty when the site was never scanned.
	 */
	public static function new_since() {
		$state = SEOHC_Scan_Queue::get_state();
		return (string) $state['started_at'];
	}

	/**
	 * Renders a score as a coloured badge.
	 *
	 * @param int $score Score from 0 to 100.
	 * @return string
	 */
	public static function score_badge( $score ) {
		$score = max( 0, min( 100, (int) $score ) );

		return sprintf(
			'<span class="seohc-score seohc-score--%1$s">%2$s</span>',
			esc_attr( SEOHC_Repository::band_for_score( $score ) ),
			esc_html( number_format_i18n( $score ) )
		);
	}

	/**
	 * Renders the change since the previous scan.
	 *
	 * @param int  $current      Current value.
	 * @param int  $previous     Value at the previous scan.
	 * @param bool $less_is_good Whether a decrease is an improvement.
	 * @return string
	 */
	public static function delta_badge( $current, $previous, $less_is_good = true ) {
		$difference = (int) $current - (int) $previous;

		if ( 0 === $difference ) {
			return '<span class="seohc-delta seohc-delta--same">' . esc_html__( 'unchanged', 'seo-health-check' ) . '</span>';
		}

		$improved = $less_is_good ? $difference < 0 : $difference > 0;

		return sprintf(
			'<span class="seohc-delta seohc-delta--%1$s">%2$s%3$s</span>',
			esc_attr( $improved ? 'better' : 'worse' ),
			$difference > 0 ? '+' : '−',
			esc_html( number_format_i18n( abs( $difference ) ) )
		);
	}

	/**
	 * Dies unless the current user may use the plugin.
	 */
	private static function require_capability() {
		if ( ! current_user_can( SEOHC_Plugin::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'seo-health-check' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Redirects back to the overview with a notice.
	 *
	 * @param string $notice Notice key.
	 */
	private static function redirect_with_notice( $notice ) {
		wp_safe_redirect( add_query_arg( 'seohc_notice', $notice, admin_url( 'admin.php?page=' . self::MENU_SLUG ) ) );
		exit;
	}

	/**
	 * Handles "Start scan".
	 */
	public static function handle_start_scan() {
		self::require_capability();
		check_admin_referer( 'seohc_start_scan' );

		self::redirect_with_notice( SEOHC_Scan_Queue::start() ? 'started' : 'already_running' );
	}

	/**
	 * Handles "Cancel scan".
	 */
	public static function handle_cancel_scan() {
		self::require_capability();
		check_admin_referer( 'seohc_cancel_scan' );

		SEOHC_Scan_Queue::cancel();
		self::redirect_with_notice( 'cancelled' );
	}

	/**
	 * Handles the "Rescan" row action.
	 */
	public static function handle_rescan_post() {
		self::require_capability();
		$post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the nonce includes the ID and is verified on the next line.
		check_admin_referer( 'seohc_rescan_post_' . $post_id );

		SEOHC_Scan_Queue::scan_single( $post_id );
		self::redirect_with_notice( 'rescanned' );
	}

	/**
	 * Handles the CSV export.
	 */
	public static function handle_export() {
		self::require_capability();
		check_admin_referer( 'seohc_export' );

		SEOHC_CSV_Exporter::send( self::current_filters() );
	}

	/**
	 * Returns scan progress for the progress bar. Also nudges the queue when WP-Cron is not running.
	 */
	public static function ajax_scan_status() {
		check_ajax_referer( 'seohc_scan_status', 'nonce' );
		if ( ! current_user_can( SEOHC_Plugin::capability() ) ) {
			wp_send_json_error( null, 403 );
		}

		// Fallback for sites where WP-Cron is disabled or blocked: run a batch in this request.
		if ( SEOHC_Scan_Queue::needs_ajax_runner() ) {
			SEOHC_Scan_Queue::process_batch();
		}

		$state = SEOHC_Scan_Queue::get_state();
		wp_send_json_success(
			array(
				'status'    => $state['status'],
				'processed' => (int) $state['processed'],
				'total'     => (int) $state['total'],
			)
		);
	}

	/**
	 * Admin notice after an action.
	 */
	private static function render_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only selects a fixed message.
		$notice   = isset( $_GET['seohc_notice'] ) ? sanitize_key( wp_unslash( $_GET['seohc_notice'] ) ) : '';
		$messages = array(
			'started'         => array( 'success', __( 'The scan has started and runs in the background. You can leave this page.', 'seo-health-check' ) ),
			'already_running' => array( 'warning', __( 'A scan is already running.', 'seo-health-check' ) ),
			'cancelled'       => array( 'info', __( 'The scan was cancelled. Results scanned so far are kept.', 'seo-health-check' ) ),
			'rescanned'       => array( 'success', __( 'The page was rescanned.', 'seo-health-check' ) ),
		);
		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $messages[ $notice ][0] ),
			esc_html( $messages[ $notice ][1] )
		);
	}

	/**
	 * Renders the overview page.
	 */
	public static function render_overview() {
		self::require_capability();

		$filters  = self::current_filters();
		$state    = SEOHC_Scan_Queue::get_state();
		$summary  = SEOHC_Repository::summary();
		$previous = SEOHC_Repository::previous_run();
		$table    = new SEOHC_Issues_List_Table( $filters, self::new_since() );
		$table->prepare_items();

		$export_url = wp_nonce_url(
			add_query_arg(
				array_filter(
					array(
						'action'          => 'seohc_export',
						'issue_type'      => $filters['issue_type'],
						'seohc_post_type' => $filters['post_type'],
						'severity'        => $filters['severity'],
						's'               => $filters['search'],
						'seohc_new'       => $filters['only_new'] ? '1' : '',
					)
				),
				admin_url( 'admin-post.php' )
			),
			'seohc_export'
		);
		?>
		<div class="wrap shc-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'SEO Health Check', 'seo-health-check' ); ?></h1>
			<?php if ( $summary['issues'] > 0 ) : ?>
				<a href="<?php echo esc_url( $export_url ); ?>" class="page-title-action"><?php esc_html_e( 'Export CSV', 'seo-health-check' ); ?></a>
			<?php endif; ?>
			<hr class="wp-header-end">

			<?php self::render_notice(); ?>

			<?php self::render_cards( $summary, $previous ); ?>

			<?php self::render_scan_panel( $state ); ?>

			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>">
				<?php if ( $filters['issue_type'] ) : ?>
					<input type="hidden" name="issue_type" value="<?php echo esc_attr( $filters['issue_type'] ); ?>">
				<?php endif; ?>
				<?php
				$table->views();
				$table->search_box( __( 'Search pages', 'seo-health-check' ), 'shc-search' );
				$table->display();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Summary cards, with the change since the previous scan when there is one to compare with.
	 *
	 * @param array      $summary  Current totals.
	 * @param array|null $previous Snapshot of the scan before the most recent one.
	 */
	private static function render_cards( array $summary, $previous ) {
		?>
		<div class="shc-cards">
			<div class="shc-card">
				<span class="shc-card__value"><?php echo wp_kses_post( self::score_badge( $summary['score'] ) ); ?></span>
				<span class="shc-card__label">
					<?php esc_html_e( 'Average score', 'seo-health-check' ); ?>
					<?php if ( $previous ) : ?>
						<?php echo wp_kses_post( self::delta_badge( $summary['score'], $previous['score'], false ) ); ?>
					<?php endif; ?>
				</span>
			</div>
			<div class="shc-card">
				<span class="shc-card__value"><?php echo esc_html( number_format_i18n( $summary['issues'] ) ); ?></span>
				<span class="shc-card__label">
					<?php esc_html_e( 'Issues', 'seo-health-check' ); ?>
					<?php if ( $previous ) : ?>
						<?php echo wp_kses_post( self::delta_badge( $summary['issues'], $previous['issues'] ) ); ?>
					<?php endif; ?>
				</span>
			</div>
			<div class="shc-card">
				<span class="shc-card__value"><?php echo esc_html( number_format_i18n( $summary['pages_with_issues'] ) ); ?></span>
				<span class="shc-card__label">
					<?php esc_html_e( 'Pages with issues', 'seo-health-check' ); ?>
					<?php if ( $previous ) : ?>
						<?php echo wp_kses_post( self::delta_badge( $summary['pages_with_issues'], $previous['pages_with_issues'] ) ); ?>
					<?php endif; ?>
				</span>
			</div>
			<div class="shc-card">
				<span class="shc-card__value"><?php echo esc_html( number_format_i18n( $summary['pages'] ) ); ?></span>
				<span class="shc-card__label">
					<?php esc_html_e( 'Pages scanned', 'seo-health-check' ); ?>
					<span class="shc-delta shc-delta--same"><?php echo esc_html( SEOHC_SEO_Meta::provider_label() ); ?></span>
				</span>
			</div>
		</div>
		<?php if ( ! $previous ) : ?>
			<p class="description"><?php esc_html_e( 'After the next scan you will also see what changed since this one.', 'seo-health-check' ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Renders the page scores overview.
	 */
	public static function render_pages() {
		self::require_capability();

		$filters = self::current_page_filters();
		$summary = SEOHC_Repository::summary();
		$table   = new SEOHC_Pages_List_Table( $filters );
		$table->prepare_items();
		?>
		<div class="wrap shc-wrap">
			<h1><?php esc_html_e( 'Page scores', 'seo-health-check' ); ?></h1>
			<p>
				<?php esc_html_e( 'Every scanned page scores from 0 to 100. Each kind of problem costs points, errors twice as much as warnings, and repeats of the same problem a little extra.', 'seo-health-check' ); ?>
				<?php
				printf(
					/* translators: %s: average score. */
					esc_html__( 'The average for this site is %s.', 'seo-health-check' ),
					wp_kses_post( self::score_badge( $summary['score'] ) )
				);
				?>
			</p>

			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGES_SLUG ); ?>">
				<?php if ( $filters['band'] ) : ?>
					<input type="hidden" name="band" value="<?php echo esc_attr( $filters['band'] ); ?>">
				<?php endif; ?>
				<?php
				$table->views();
				$table->search_box( __( 'Search pages', 'seo-health-check' ), 'seohc-pages-search' );
				$table->display();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Scan status, progress bar and start/cancel buttons.
	 *
	 * @param array $state Scan state.
	 */
	private static function render_scan_panel( array $state ) {
		$running = 'running' === $state['status'];
		$percent = $state['total'] > 0 ? min( 100, (int) floor( $state['processed'] / $state['total'] * 100 ) ) : 0;
		?>
		<div class="shc-panel" id="shc-scan-panel" data-running="<?php echo $running ? '1' : '0'; ?>">
			<?php if ( $running ) : ?>
				<p><strong><?php esc_html_e( 'Scan in progress', 'seo-health-check' ); ?></strong></p>
				<div class="shc-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr( $percent ); ?>">
					<div class="shc-progress__bar" style="transform: scaleX(<?php echo esc_attr( $percent / 100 ); ?>)"></div>
				</div>
				<p class="shc-progress__text" aria-live="polite">
					<?php
					/* translators: 1: processed posts, 2: total posts. */
					echo esc_html( sprintf( __( '%1$s of %2$s pages scanned', 'seo-health-check' ), number_format_i18n( $state['processed'] ), number_format_i18n( $state['total'] ) ) );
					?>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="seohc_cancel_scan">
					<?php wp_nonce_field( 'seohc_cancel_scan' ); ?>
					<?php submit_button( __( 'Cancel scan', 'seo-health-check' ), 'secondary', 'submit', false ); ?>
				</form>
			<?php else : ?>
				<p>
					<?php
					if ( $state['finished_at'] ) {
						printf(
							/* translators: %s: date and time of the last scan. */
							esc_html__( 'Last scan: %s', 'seo-health-check' ),
							esc_html( get_date_from_gmt( $state['finished_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) )
						);
						if ( 'cancelled' === $state['status'] ) {
							echo ' ' . esc_html__( '(cancelled)', 'seo-health-check' );
						}
					} else {
						esc_html_e( 'This site has not been scanned yet.', 'seo-health-check' );
					}
					?>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="seohc_start_scan">
					<?php wp_nonce_field( 'seohc_start_scan' ); ?>
					<?php submit_button( __( 'Start full scan', 'seo-health-check' ), 'primary', 'submit', false ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}
}
