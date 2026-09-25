<?php
/**
 * Tells WordPress when a newer release is on GitHub.
 *
 * @package SEO_Health_Check
 */

defined( 'ABSPATH' ) || exit;

/**
 * Update notices for a plugin that does not come from wordpress.org.
 *
 * WordPress keeps a list of plugins with an update waiting and draws the notice on the Plugins
 * screen from it. Plugins from wordpress.org get into that list on their own; this one has to
 * put itself there, by asking GitHub which release is the latest.
 */
class SEOHC_Updater {

	/**
	 * Where the answer from GitHub is kept, and for how long.
	 */
	const TRANSIENT = 'seohc_latest_release';
	const CACHE     = 12 * HOUR_IN_SECONDS;

	/**
	 * How long a failed lookup is remembered, so a GitHub outage is not asked about on
	 * every single page load.
	 */
	const CACHE_FAILURE = HOUR_IN_SECONDS;

	/**
	 * Registers the hooks.
	 */
	public static function init() {
		/**
		 * Filters whether the plugin looks for newer releases at all.
		 *
		 * Set to false on sites that should never reach out to GitHub.
		 *
		 * @param bool $enabled Default true.
		 */
		if ( ! apply_filters( 'seo_health_check_check_for_updates', true ) ) {
			return;
		}

		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'add_to_update_list' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'details' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'forget' ), 10, 2 );
	}

	/**
	 * Adds this plugin to the list WordPress draws its update notices from.
	 *
	 * @param mixed $transient The update list.
	 * @return mixed
	 */
	public static function add_to_update_list( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$latest = self::latest();
		if ( ! $latest ) {
			return $transient;
		}

		$file   = plugin_basename( SEOHC_FILE );
		$entry  = (object) array(
			'id'          => $latest['url'],
			'slug'        => dirname( $file ),
			'plugin'      => $file,
			'new_version' => $latest['version'],
			'url'         => $latest['url'],
			'package'     => $latest['package'],
			'tested'      => $latest['tested'],
			'icons'       => array(),
			'banners'     => array(),
		);
		$newer  = version_compare( $latest['version'], SEOHC_VERSION, '>' );
		$target = $newer ? 'response' : 'no_update';

		if ( ! isset( $transient->$target ) || ! is_array( $transient->$target ) ) {
			$transient->$target = array();
		}

		$transient->{$target}[ $file ] = $entry;

		return $transient;
	}

	/**
	 * Fills the "View version details" window.
	 *
	 * @param mixed  $result The result object or array.
	 * @param string $action The type of information being requested.
	 * @param object $args   Arguments of the request.
	 * @return mixed
	 */
	public static function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) ) {
			return $result;
		}

		if ( dirname( plugin_basename( SEOHC_FILE ) ) !== $args->slug ) {
			return $result;
		}

		$latest = self::latest();
		if ( ! $latest ) {
			return $result;
		}

		$header = get_file_data(
			SEOHC_FILE,
			array(
				'name'        => 'Plugin Name',
				'author'      => 'Author',
				'description' => 'Description',
				'requires'    => 'Requires at least',
				'php'         => 'Requires PHP',
			)
		);

		return (object) array(
			'name'          => $header['name'],
			'slug'          => $args->slug,
			'version'       => $latest['version'],
			'author'        => $header['author'],
			'homepage'      => $latest['url'],
			'download_link' => $latest['package'],
			'trunk'         => $latest['package'],
			'requires'      => $header['requires'],
			'requires_php'  => $header['php'],
			'tested'        => $latest['tested'],
			'last_updated'  => $latest['date'],
			'sections'      => array(
				'description' => wpautop( esc_html( $header['description'] ) ),
				'changelog'   => self::changelog( $latest['notes'] ),
			),
		);
	}

	/**
	 * Throws the cached answer away after an update, so the new version is not offered again.
	 *
	 * @param WP_Upgrader $upgrader Upgrader instance.
	 * @param array       $extra    What was updated.
	 */
	public static function forget( $upgrader, $extra ) {
		unset( $upgrader );

		if ( isset( $extra['type'] ) && 'plugin' === $extra['type'] ) {
			delete_site_transient( self::TRANSIENT );
		}
	}

	/**
	 * The latest release on GitHub, or null.
	 *
	 * A release without a `seo-health-check.zip` of its own is deliberately ignored. WordPress
	 * would then fall back on the source archive GitHub generates, and that unpacks into a
	 * folder with the version number in its name, which moves the plugin and switches it off.
	 *
	 * @return array|null version, package, url, notes, date and tested.
	 */
	private static function latest() {
		$cached = get_site_transient( self::TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		if ( 'none' === $cached ) {
			return null;
		}

		$repo = self::repository();
		if ( '' === $repo ) {
			return null;
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . $repo . '/releases/latest',
			array(
				'timeout'    => 10,
				'user-agent' => 'SEO Health Check/' . SEOHC_VERSION . '; ' . home_url(),
				'headers'    => array( 'Accept' => 'application/vnd.github+json' ),
			)
		);

		$body = is_wp_error( $response ) ? array() : json_decode( wp_remote_retrieve_body( $response ), true );
		$code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code || ! is_array( $body ) || empty( $body['tag_name'] ) ) {
			set_site_transient( self::TRANSIENT, 'none', self::CACHE_FAILURE );
			return null;
		}

		$package = '';
		foreach ( (array) ( isset( $body['assets'] ) ? $body['assets'] : array() ) as $asset ) {
			if ( isset( $asset['name'] ) && basename( SEOHC_DIR ) . '.zip' === $asset['name'] ) {
				$package = isset( $asset['browser_download_url'] ) ? $asset['browser_download_url'] : '';
				break;
			}
		}

		if ( '' === $package ) {
			set_site_transient( self::TRANSIENT, 'none', self::CACHE_FAILURE );
			return null;
		}

		$latest = array(
			'version' => ltrim( (string) $body['tag_name'], 'vV' ),
			'package' => $package,
			'url'     => isset( $body['html_url'] ) ? $body['html_url'] : '',
			'notes'   => isset( $body['body'] ) ? (string) $body['body'] : '',
			'date'    => isset( $body['published_at'] ) ? $body['published_at'] : '',
			'tested'  => get_file_data( SEOHC_FILE, array( 'tested' => 'Tested up to' ) )['tested'],
		);

		set_site_transient( self::TRANSIENT, $latest, self::CACHE );

		return $latest;
	}

	/**
	 * The owner/name of the repository, taken from the Plugin URI header rather than written
	 * out here, so moving the repository is a one line change in one place.
	 *
	 * @return string Empty when the header does not point at GitHub.
	 */
	private static function repository() {
		$uri = get_file_data( SEOHC_FILE, array( 'uri' => 'Plugin URI' ) )['uri'];

		if ( ! preg_match( '#github\.com/([^/\s]+)/([^/\s]+)#i', $uri, $match ) ) {
			return '';
		}

		return $match[1] . '/' . preg_replace( '/\.git$/', '', $match[2] );
	}

	/**
	 * Turns the release notes into something readable in the details window.
	 *
	 * @param string $notes Release notes as typed on GitHub.
	 * @return string
	 */
	private static function changelog( $notes ) {
		$notes = trim( $notes );
		if ( '' === $notes ) {
			return '<p>' . esc_html__( 'This release came without notes.', 'seo-health-check' ) . '</p>';
		}

		$items = array();
		foreach ( preg_split( '/\R/', $notes ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$items[] = '<li>' . esc_html( ltrim( $line, '*-• ' ) ) . '</li>';
		}

		return $items ? '<ul>' . implode( '', $items ) . '</ul>' : '<p>' . esc_html( $notes ) . '</p>';
	}
}
