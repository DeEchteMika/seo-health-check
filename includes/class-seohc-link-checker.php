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

	const GENERATION_KEY = 'seohc_link_cache_generation';

	/**
	 * Results cache for the current request.
	 *
	 * @var array<string, array{reason: string, post_id: int}>
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
		$result = self::inspect( $href, $base_url );
		return $result['reason'];
	}

	/**
	 * Checks one link and works out which post it points at.
	 *
	 * The post ID is what tells the scan which pages link to which, so pages nothing links to
	 * can be found once the whole site has been scanned.
	 *
	 * @param string $href     Raw href attribute.
	 * @param string $base_url URL of the page containing the link (for relative links).
	 * @return array{reason: string|null, post_id: int} Reason is null when the link is fine or
	 *                                                  not internal; post_id is 0 when the link
	 *                                                  does not point at a post or page.
	 */
	public static function inspect( $href, $base_url ) {
		$none = array(
			'reason'  => null,
			'post_id' => 0,
		);

		$url = self::normalize( $href, $base_url );
		if ( null === $url ) {
			return $none;
		}

		if ( ! array_key_exists( $url, self::$cache ) ) {
			// The generation changes with every full scan, so each scan starts with an empty cache.
			$transient = 'seohc_link_' . md5( (int) get_option( self::GENERATION_KEY, 0 ) . '|' . $url );
			$cached    = get_transient( $transient );

			if ( ! is_array( $cached ) ) {
				$post_id = (int) url_to_postid( $url );
				$cached  = array(
					'reason'  => (string) self::resolve( $url, $post_id ),
					'post_id' => $post_id,
				);
				set_transient( $transient, $cached, HOUR_IN_SECONDS );
			}

			self::$cache[ $url ] = $cached;
		}

		$entry = self::$cache[ $url ];

		return array(
			'reason'  => '' === $entry['reason'] ? null : $entry['reason'],
			'post_id' => (int) $entry['post_id'],
		);
	}

	/**
	 * Invalidates all cached link results. Old entries expire on their own.
	 */
	public static function reset_cache() {
		update_option( self::GENERATION_KEY, (int) get_option( self::GENERATION_KEY, 0 ) + 1, false );
		self::$cache = array();
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
	 * @param string $url     Absolute internal URL.
	 * @param int    $post_id Post the URL points at, 0 when it points at none. Worked out by
	 *                        the caller, which needs it anyway.
	 * @return string Empty when fine, otherwise the reason.
	 */
	private static function resolve( $url, $post_id = 0 ) {
		// Files in the uploads folder: check the disk instead of making a request.
		$uploads = wp_get_upload_dir();
		if ( 0 === strpos( self::strip_scheme( $url ), self::strip_scheme( $uploads['baseurl'] ) ) ) {
			$relative = substr( self::strip_scheme( strtok( $url, '?' ) ), strlen( self::strip_scheme( $uploads['baseurl'] ) ) );
			return file_exists( $uploads['basedir'] . rawurldecode( $relative ) ) ? '' : __( 'File not found in the uploads folder.', 'seo-health-check' );
		}

		// Posts and pages: ask WordPress, which is much faster than an HTTP request.
		if ( $post_id ) {
			$status = get_post_status( $post_id );
			if ( in_array( $status, array( 'publish', 'inherit' ), true ) ) {
				return '';
			}
			/* translators: %s: post status such as draft or trash. */
			return sprintf( __( 'Links to content with status "%s".', 'seo-health-check' ), $status );
		}

		// Everything else: match the URL against the rewrite rules, like WordPress does for a visitor.
		$verdict = self::resolve_with_rewrite_rules( $url );
		if ( 'ok' === $verdict ) {
			return '';
		}

		// Unknown or apparently broken: confirm over HTTP, which also follows redirects set up in
		// plugins such as Redirection. When the request itself fails, the rewrite verdict stands.
		$code = self::http_status( $url );
		if ( null === $code ) {
			return 'broken' === $verdict ? __( 'No page matches this URL.', 'seo-health-check' ) : '';
		}
		if ( in_array( $code, array( 404, 410 ), true ) ) {
			/* translators: %d: HTTP status code. */
			return sprintf( __( 'Returns HTTP %d.', 'seo-health-check' ), $code );
		}
		return '';
	}

	/**
	 * Resolves an internal URL through the rewrite rules without an HTTP request.
	 *
	 * Based on the matching in WP::parse_request() and url_to_postid(), but without firing
	 * request hooks, so plugins cannot redirect or exit during a scan.
	 *
	 * @param string $url Absolute internal URL.
	 * @return string 'ok', 'broken' or 'unknown'.
	 */
	private static function resolve_with_rewrite_rules( $url ) {
		global $wp_rewrite;

		$path      = rawurldecode( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		$home_path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		if ( '' !== $home_path && 0 === strpos( $path, $home_path ) ) {
			$path = substr( $path, strlen( $home_path ) );
		}
		$path = trim( $path, '/' );

		// The homepage, or a query string URL (plain permalinks) that url_to_postid() did not resolve.
		if ( '' === $path ) {
			return wp_parse_url( $url, PHP_URL_QUERY ) ? 'unknown' : 'ok';
		}

		// Real files next to WordPress (robots.txt, PDFs in a custom folder) are served by the web server.
		if ( preg_match( '/\.[a-z0-9]{2,5}$/i', $path ) && file_exists( ABSPATH . $path ) ) {
			return 'ok';
		}

		$rules = $wp_rewrite->wp_rewrite_rules();
		if ( empty( $rules ) ) {
			return 'unknown';
		}

		foreach ( (array) $rules as $match => $query ) {
			if ( ! preg_match( "#^{$match}#", $path, $matches ) && ! preg_match( "#^{$match}#", urldecode( $path ), $matches ) ) {
				continue;
			}

			// Verbose page rules match every path; WordPress skips them when the page does not exist.
			if ( $wp_rewrite->use_verbose_page_rules && preg_match( '/pagename=\$matches\[([0-9]+)\]/', $query, $varmatch ) ) {
				if ( ! get_page_by_path( $matches[ $varmatch[1] ] ) ) {
					continue;
				}
			}

			$query = preg_replace( '!^.+\?!', '', $query );
			parse_str( WP_MatchesMapRegex::apply( $query, $matches ), $vars );

			return self::verdict_for_query( $vars );
		}

		// No rule matches: WordPress would show its 404 page.
		return 'broken';
	}

	/**
	 * Runs the query a matched rewrite rule produces and decides whether it finds something.
	 *
	 * @param array $vars Query vars from the rewrite rule.
	 * @return string 'ok', 'broken' or 'unknown'.
	 */
	private static function verdict_for_query( array $vars ) {
		$public = array_flip( $GLOBALS['wp']->public_query_vars );
		$vars   = array_intersect_key( $vars, $public );
		if ( empty( $vars ) ) {
			return 'unknown';
		}

		$query = new WP_Query();
		$query->query(
			array_merge(
				$vars,
				array(
					'posts_per_page'         => 1,
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			)
		);

		if ( $query->is_singular() ) {
			return $query->have_posts() ? 'ok' : 'broken';
		}
		if ( $query->is_category() || $query->is_tag() || $query->is_tax() || $query->is_author() ) {
			return $query->get_queried_object() ? 'ok' : 'broken';
		}
		if ( $query->is_404() ) {
			return 'broken';
		}
		return 'ok';
	}

	/**
	 * HTTP status of a URL after redirects.
	 *
	 * @param string $url URL.
	 * @return int|null Null when the request failed.
	 */
	private static function http_status( $url ) {
		/**
		 * Filters whether suspected broken links are confirmed with an HTTP request.
		 *
		 * @param bool $enabled Default true.
		 */
		if ( ! apply_filters( 'seo_health_check_link_http_check', true ) ) {
			return null;
		}

		$args     = array(
			'timeout'     => 5,
			'redirection' => 5,
			'user-agent'  => 'SEO Health Check/' . SEOHC_VERSION . '; ' . home_url(),
			/** This filter is documented in wp-includes/class-wp-http-streams.php */
			'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter.
		);
		$response = wp_remote_head( $url, $args );
		if ( ! is_wp_error( $response ) && 405 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$response = wp_remote_get( $url, $args );
		}

		return is_wp_error( $response ) ? null : (int) wp_remote_retrieve_response_code( $response );
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
