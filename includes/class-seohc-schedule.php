<?php
/**
 * Scanning on a schedule.
 *
 * @package SEO_Health_Check
 */

defined( 'ABSPATH' ) || exit;

/**
 * Works out when the next scan is due, books it, and books the one after it.
 */
class SEOHC_Schedule {

	const OPTION     = 'seohc_schedule';
	const OPTION_GRP = 'seohc_schedule_group';
	const STATE      = 'seohc_report_state';
	const HOOK       = 'seohc_scheduled_scan';
	const PAGE_SLUG  = 'seo-health-check-schedule';

	/**
	 * Weekday slugs in the order PHP numbers them, Sunday first.
	 */
	const DAYS = array( 'sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat' );

	/**
	 * Registers the hooks. Also outside the admin: the event fires on a front-end visit.
	 */
	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'update_option_' . self::OPTION, array( __CLASS__, 'reschedule' ) );

		// Books the event again if it ever goes missing, for instance after a database restore.
		add_action( 'init', array( __CLASS__, 'ensure_booked' ) );
	}

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'enabled' => 0,
			'days'    => array( 'fri' ),
			'time'    => '09:00',
			'email'   => '',
			'keep'    => 12,
		);
	}

	/**
	 * All settings, with the defaults filled in.
	 *
	 * @return array
	 */
	public static function get_all() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	/**
	 * One setting.
	 *
	 * @param string $key Setting name.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::get_all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Weekday slugs with their translated names, in the order the site starts its week.
	 *
	 * @return array<string, string>
	 */
	public static function weekdays() {
		global $wp_locale;

		$start = (int) get_option( 'start_of_week', 1 );
		$days  = array();

		for ( $offset = 0; $offset < 7; $offset++ ) {
			$index         = ( $start + $offset ) % 7;
			$slug          = self::DAYS[ $index ];
			$days[ $slug ] = $wp_locale ? $wp_locale->get_weekday( $index ) : $slug;
		}

		return $days;
	}

	/**
	 * The addresses the report goes to.
	 *
	 * @return string[]
	 */
	public static function recipients() {
		$raw = array_map( 'trim', explode( ',', (string) self::get( 'email' ) ) );

		return array_values( array_filter( $raw, 'is_email' ) );
	}

	/**
	 * When the next scan is due, as a Unix timestamp.
	 *
	 * Counted in the time zone of the site, so "Friday at 17:00" means what the person who set
	 * it means, whatever the server happens to be set to, and it keeps meaning 17:00 when the
	 * clocks change.
	 *
	 * @param DateTimeImmutable|null $from Moment to count from. Defaults to now; the parameter
	 *                                     exists so the calculation can be tested.
	 * @return int 0 when nothing is scheduled.
	 */
	public static function next_run( $from = null ) {
		$settings = self::get_all();
		$days     = (array) $settings['days'];

		if ( empty( $settings['enabled'] ) || empty( $days ) ) {
			return 0;
		}

		$parts  = explode( ':', (string) $settings['time'] );
		$hour   = isset( $parts[0] ) ? (int) $parts[0] : 9;
		$minute = isset( $parts[1] ) ? (int) $parts[1] : 0;

		$now = $from instanceof DateTimeImmutable ? $from : new DateTimeImmutable( 'now', wp_timezone() );

		// Today counts too, as long as the time has not passed yet.
		for ( $offset = 0; $offset <= 7; $offset++ ) {
			$candidate = $now->modify( sprintf( '+%d days', $offset ) )->setTime( $hour, $minute );

			if ( $candidate <= $now ) {
				continue;
			}

			if ( in_array( strtolower( $candidate->format( 'D' ) ), $days, true ) ) {
				return $candidate->getTimestamp();
			}
		}

		return 0;
	}

	/**
	 * Books the next scan, replacing whatever was booked before.
	 */
	public static function reschedule() {
		wp_clear_scheduled_hook( self::HOOK );

		$next = self::next_run();
		if ( $next > 0 ) {
			wp_schedule_single_event( $next, self::HOOK );
		}
	}

	/**
	 * Books the event when it is switched on but nothing is in the diary.
	 */
	public static function ensure_booked() {
		if ( ! self::get( 'enabled' ) ) {
			return;
		}

		if ( ! wp_next_scheduled( self::HOOK ) ) {
			self::reschedule();
		}
	}

	/**
	 * Starts the scheduled scan and books the next one.
	 *
	 * The report is not sent from here: the scan runs in the background and takes minutes on a
	 * large site, so mailing now would mail the numbers of the previous scan. The start time is
	 * remembered instead, and SEOHC_Mailer picks it up when this scan reports itself finished.
	 */
	public static function run() {
		if ( ! self::get( 'enabled' ) || ! self::get( 'days' ) ) {
			return;
		}

		if ( SEOHC_Scan_Queue::start() ) {
			$state = SEOHC_Scan_Queue::get_state();
			self::remember( array( 'pending' => (string) $state['started_at'] ) );
		}

		self::reschedule();
	}

	/**
	 * What the plugin remembers about the reports.
	 *
	 * @return array{pending: string, last_sent: string, last_error: string, last_file: string}
	 */
	public static function state() {
		$state = get_option( self::STATE, array() );

		return wp_parse_args(
			is_array( $state ) ? $state : array(),
			array(
				'pending'    => '',
				'last_sent'  => '',
				'last_error' => '',
				'last_file'  => '',
			)
		);
	}

	/**
	 * Stores part of that.
	 *
	 * @param array $changes Values to change.
	 */
	public static function remember( array $changes ) {
		update_option( self::STATE, array_merge( self::state(), $changes ), false );
	}

	/**
	 * Registers the settings, kept apart from the main settings so saving one screen cannot
	 * reset the other.
	 */
	public static function register() {
		register_setting(
			self::OPTION_GRP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);

		add_settings_section( 'seohc_schedule_when', __( 'When to scan', 'seo-health-check' ), '__return_false', self::PAGE_SLUG );
		add_settings_section( 'seohc_schedule_report', __( 'The report', 'seo-health-check' ), '__return_false', self::PAGE_SLUG );

		add_settings_field( 'enabled', __( 'Automatic scan', 'seo-health-check' ), array( __CLASS__, 'field_enabled' ), self::PAGE_SLUG, 'seohc_schedule_when' );
		add_settings_field( 'days', __( 'Days', 'seo-health-check' ), array( __CLASS__, 'field_days' ), self::PAGE_SLUG, 'seohc_schedule_when' );
		add_settings_field( 'time', __( 'Time', 'seo-health-check' ), array( __CLASS__, 'field_time' ), self::PAGE_SLUG, 'seohc_schedule_when' );
		add_settings_field( 'email', __( 'Send the report to', 'seo-health-check' ), array( __CLASS__, 'field_email' ), self::PAGE_SLUG, 'seohc_schedule_report' );
		add_settings_field( 'keep', __( 'Keep reports', 'seo-health-check' ), array( __CLASS__, 'field_keep' ), self::PAGE_SLUG, 'seohc_schedule_report' );
	}

	/**
	 * Cleans the submitted settings.
	 *
	 * @param mixed $input Raw input.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$defaults = self::defaults();
		$input    = is_array( $input ) ? $input : array();
		$clean    = array();

		$clean['enabled'] = empty( $input['enabled'] ) ? 0 : 1;

		$days          = isset( $input['days'] ) ? array_map( 'sanitize_key', (array) $input['days'] ) : array();
		$clean['days'] = array_values( array_intersect( $days, self::DAYS ) );

		$time          = isset( $input['time'] ) ? trim( (string) $input['time'] ) : '';
		$clean['time'] = preg_match( '/^([01]\d|2[0-3]):([0-5]\d)$/', $time ) ? $time : $defaults['time'];

		$addresses      = isset( $input['email'] ) ? explode( ',', sanitize_text_field( $input['email'] ) ) : array();
		$addresses      = array_filter( array_map( 'sanitize_email', array_map( 'trim', $addresses ) ), 'is_email' );
		$clean['email'] = implode( ', ', array_unique( $addresses ) );

		$clean['keep'] = min( max( isset( $input['keep'] ) ? absint( $input['keep'] ) : $defaults['keep'], 0 ), 52 );

		if ( $clean['enabled'] && empty( $clean['days'] ) ) {
			add_settings_error( self::OPTION, 'seohc_no_days', __( 'Choose at least one day, otherwise the scan never runs.', 'seo-health-check' ) );
			$clean['enabled'] = 0;
		}

		if ( $clean['enabled'] && '' === $clean['email'] ) {
			add_settings_error( self::OPTION, 'seohc_no_email', __( 'The scan will run, but without a valid email address no report is sent.', 'seo-health-check' ), 'warning' );
		}

		return $clean;
	}

	/**
	 * On/off checkbox.
	 */
	public static function field_enabled() {
		printf(
			'<label><input type="checkbox" name="%1$s[enabled]" value="1" %2$s> %3$s</label>',
			esc_attr( self::OPTION ),
			checked( (int) self::get( 'enabled' ), 1, false ),
			esc_html__( 'Scan the site automatically and send a report afterwards', 'seo-health-check' )
		);
	}

	/**
	 * Weekday checkboxes.
	 */
	public static function field_days() {
		$chosen = (array) self::get( 'days' );

		echo '<fieldset class="seohc-days">';
		foreach ( self::weekdays() as $slug => $label ) {
			printf(
				'<label><input type="checkbox" name="%1$s[days][]" value="%2$s" %3$s> %4$s</label>',
				esc_attr( self::OPTION ),
				esc_attr( $slug ),
				checked( in_array( $slug, $chosen, true ), true, false ),
				esc_html( $label )
			);
		}
		echo '</fieldset>';
	}

	/**
	 * Time field.
	 */
	public static function field_time() {
		printf(
			'<input type="time" name="%1$s[time]" value="%2$s"><p class="description">%3$s</p>',
			esc_attr( self::OPTION ),
			esc_attr( (string) self::get( 'time' ) ),
			esc_html(
				sprintf(
					/* translators: %s: the time zone of the site, for example Europe/Amsterdam. */
					__( 'In the time zone of the site (%s).', 'seo-health-check' ),
					wp_timezone_string()
				)
			)
		);
	}

	/**
	 * Recipients field.
	 */
	public static function field_email() {
		printf(
			'<input type="text" class="regular-text" name="%1$s[email]" value="%2$s" placeholder="%3$s"><p class="description">%4$s</p>',
			esc_attr( self::OPTION ),
			esc_attr( (string) self::get( 'email' ) ),
			esc_attr( get_option( 'admin_email' ) ),
			esc_html__( 'One or more addresses, separated by commas.', 'seo-health-check' )
		);
	}

	/**
	 * Retention field.
	 */
	public static function field_keep() {
		printf(
			'<input type="number" class="small-text" name="%1$s[keep]" value="%2$s" min="0" max="52"><p class="description">%3$s</p>',
			esc_attr( self::OPTION ),
			esc_attr( (string) self::get( 'keep' ) ),
			esc_html__( 'How many reports to keep on the site, so you can look back at them. Older ones are deleted. Use 0 to keep none; the report is then only emailed.', 'seo-health-check' )
		);
	}
}
