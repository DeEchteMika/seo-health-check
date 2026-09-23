<?php
/**
 * Plugin bootstrap.
 *
 * @package SEO_Health_Check
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wires all components together.
 */
class SEOHC_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var SEOHC_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Returns the single instance, creating it on first use.
	 *
	 * @return SEOHC_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Registers hooks.
	 */
	private function __construct() {
		SEOHC_Installer::maybe_upgrade();
		SEOHC_Scan_Queue::init();

		add_action( 'init', array( $this, 'load_textdomain' ) );

		if ( is_admin() ) {
			require_once SEOHC_DIR . 'includes/admin/class-seohc-issues-list-table.php';
			require_once SEOHC_DIR . 'includes/admin/class-seohc-admin.php';
			SEOHC_Admin::init();
		}
	}

	/**
	 * Loads bundled translations.
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'seo-health-check', false, dirname( plugin_basename( SEOHC_FILE ) ) . '/languages' );
	}

	/**
	 * Capability required to use the plugin.
	 *
	 * @return string
	 */
	public static function capability() {
		/**
		 * Filters the capability needed to view results, run scans and change settings.
		 *
		 * @param string $capability Default 'manage_options'.
		 */
		return apply_filters( 'seo_health_check_capability', 'manage_options' );
	}
}
