<?php
/**
 * Checks whether internal links point to something that exists.
 *
 * @package SEO_Health_Check
 */

defined( 'ABSPATH' ) || exit;

/**
 * Internal link checker.
 */
class SEOHC_Link_Checker {

	/**
	 * Results cache for the current request.
	 *
	 * @var array<string, string>
	 */
	private static $cache = array();

	/**
	 * Checks one link.
	 *
	 * @param string $href     Raw href attribute.
	 * @param string $base_url URL of the page containing the link (for relative links).
	 * @return string|null Reason the link is broken, or null when it is fine or not internal.
	 */
	public static function check( $href, $base_url ) {
		$url = self::normalize( $href, $base_url );
		if ( null === $url ) {
			return null;
		}

		if ( array_key_exists( $url, self::$cache ) ) {
			return '' === self::$cache[ $url ] ? null : self::$cache[ $url ];
		}

		$transient = 'seohc_link_' . md5( $url );
		$cached    = get_transient( $transient );
		if ( false === $cached ) {
			$cached = (string) self::resolve( $url );
			set_transient( $transient, $cached, HOUR_IN_SECONDS );
		}

		self::$cache[ $url ] = $cached;
		return '' === $cached ? null : $cached;
	}

	/**
	 * Turns an href into an absolute internal URL without fragment, or null when it should be skipped.
	 *
	 * @param string $href     Raw href.
	 * @param string $base_url Page URL.
	 * @return string|null
	 */
	private static function normalize( $href, $base_url ) {
		$href = trim( html_entity_decode( (string) $href, ENT_QUOTES ) );
		if ( '' === $href || '#' === $href[0] || preg_match( '#^(mailto|tel|javascript|data|sms|whatsapp):#i', $href ) ) {
			return null;
		}

		$url  = WP_Http::make_absolute_url( $href, $base_url ? $base_url : home_url( '/' ) );
		$url  = strtok( $url, '#' );
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! $host || self::bare_host( $host ) !== self::bare_host( wp_parse_url( home_url(), PHP_URL_HOST ) ) ) {
			return null;
		}

		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( preg_match( '#/(wp-admin|wp-login\.php|wp-json|xmlrpc\.php|feed)(/|$)#', $path ) ) {
			return null;
		}

		return $url;
	}

	/**
	 * Works out whether an internal URL resolves.
	 *
	 * @param string $url Absolute internal URL.
	 * @return string Empty when fine, otherwise the reason.
	 */
	private static function resolve( $url ) {
		// Files in the uploads folder: check the disk instead of making a request.
		$uploads = wp_get_upload_dir();
		if ( 0 === strpos( self::strip_scheme( $url ), self::strip_scheme( $uploads['baseurl'] ) ) ) {
			$relative = substr( self::strip_scheme( strtok( $url, '?' ) ), strlen( self::strip_scheme( $uploads['baseurl'] ) ) );
			return file_exists( $uploads['basedir'] . rawurldecode( $relative ) ) ? '' : __( 'File not found in the uploads folder.', 'seo-health-check' );
		}

		// Posts and pages: ask WordPress, which is much faster than an HTTP request.
		$post_id = url_to_postid( $url );
		if ( $post_id ) {
			$status = get_post_status( $post_id );
			if ( in_array( $status, array( 'publish', 'inherit' ), true ) ) {
				return '';
			}
			/* translators: %s: post status such as draft or trash. */
			return sprintf( __( 'Links to content with status "%s".', 'seo-health-check' ), $status );
		}

		// Everything else (archives, custom routes): request the URL.
		$args     = array(
			'timeout'     => 10,
			'redirection' => 5,
			'user-agent'  => 'SEO Health Check/' . SEOHC_VERSION . '; ' . home_url(),
			/** This filter is documented in wp-includes/class-wp-http-streams.php */
			'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter.
		);
		$response = wp_remote_head( $url, $args );
		if ( ! is_wp_error( $response ) && 405 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$response = wp_remote_get( $url, $args );
		}

		// Network errors are not reported: we cannot tell whether the link is broken.
		if ( is_wp_error( $response ) ) {
			return '';
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( in_array( $code, array( 404, 410 ), true ) ) {
			/* translators: %d: HTTP status code. */
			return sprintf( __( 'Returns HTTP %d.', 'seo-health-check' ), $code );
		}
		return '';
	}

	/**
	 * Host without a leading www.
	 *
	 * @param string $host Host.
	 * @return string
	 */
	private static function bare_host( $host ) {
		return preg_replace( '/^www\./i', '', strtolower( (string) $host ) );
	}

	/**
	 * URL without scheme and www, so http/https and www variants compare equal.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function strip_scheme( $url ) {
		return preg_replace( '#^(https?:)?//(www\.)?#i', '', (string) $url );
	}
}
