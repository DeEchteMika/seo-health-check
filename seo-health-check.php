<?php
/**
 * Plugin Name:       SEO Health Check
 * Plugin URI:        https://github.com/DeEchteMika/seo-health-check
 * Description:       Scans your posts and pages for common on-page SEO problems (titles, meta descriptions, alt texts, headings, thin content and broken internal links) and reports them in the dashboard.
 * Version:           0.1.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Mika Leonard
 * Author URI:        https://github.com/DeEchteMika
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       seo-health-check
 * Domain Path:       /languages
 *
 * @package SEO_Health_Check
 */

defined( 'ABSPATH' ) || exit;

define( 'SEOHC_VERSION', '0.1.1' );
define( 'SEOHC_DB_VERSION', '1' );
define( 'SEOHC_FILE', __FILE__ );
define( 'SEOHC_DIR', plugin_dir_path( __FILE__ ) );
define( 'SEOHC_URL', plugin_dir_url( __FILE__ ) );

require_once SEOHC_DIR . 'includes/class-seohc-issue-types.php';
require_once SEOHC_DIR . 'includes/class-seohc-installer.php';
require_once SEOHC_DIR . 'includes/class-seohc-settings.php';
require_once SEOHC_DIR . 'includes/class-seohc-repository.php';
require_once SEOHC_DIR . 'includes/class-seohc-seo-meta.php';
require_once SEOHC_DIR . 'includes/class-seohc-content-resolver.php';
require_once SEOHC_DIR . 'includes/class-seohc-link-checker.php';
require_once SEOHC_DIR . 'includes/class-seohc-scanner.php';
require_once SEOHC_DIR . 'includes/class-seohc-scan-queue.php';
require_once SEOHC_DIR . 'includes/class-seohc-csv-exporter.php';
require_once SEOHC_DIR . 'includes/class-seohc-launch-checks.php';
require_once SEOHC_DIR . 'includes/class-seohc-plugin.php';

register_activation_hook( __FILE__, array( 'SEOHC_Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'SEOHC_Installer', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'SEOHC_Plugin', 'instance' ) );
