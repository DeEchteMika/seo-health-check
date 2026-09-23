<?php
/**
 * Produces the HTML that should be scanned for a post.
 *
 * @package SEO_Health_Check
 */

defined( 'ABSPATH' ) || exit;

/**
 * Chooses between the stored post content and the rendered front-end page.
 */
class SEOHC_Content_Resolver {

	/**
	 * Elements stripped from a rendered page: site chrome that repeats on every page.
	 */
	const CHROME_TAGS = array( 'header', 'footer', 'nav', 'aside', 'script', 'style', 'noscript', 'template', 'svg', 'form' );

	/**
	 * Resolves the HTML for a post.
	 *
	 * @param WP_Post $post Post.
	 * @return array{html: string, source: string, error: string}
	 *               source is 'content' or 'rendered'; error is set when the rendered page could not be fetched.
	 */
	public static function resolve( WP_Post $post ) {
		$mode   = SEOHC_Settings::get( 'content_source' );
		$public = 'publish' === $post->post_status && is_post_type_viewable( $post->post_type );

		$use_rendered = false;
		if ( $public && 'rendered' === $mode ) {
			$use_rendered = true;
		} elseif ( $public && 'auto' === $mode ) {
			$use_rendered = '' === trim( wp_strip_all_tags( $post->post_content ) );
		}

		$result = array(
			'html'   => '',
			'source' => 'content',
			'error'  => '',
		);

		if ( $use_rendered ) {
			$fetched = self::fetch_rendered( $post );
			if ( is_wp_error( $fetched ) ) {
				$result['error'] = $fetched->get_error_message();
			} else {
				$result['html']   = $fetched;
				$result['source'] = 'rendered';
			}
		}

		if ( 'content' === $result['source'] ) {
			$result['html'] = self::render_post_content( $post );
		}

		/**
		 * Filters the HTML that is scanned for a post.
		 *
		 * @param array   $result html, source and error.
		 * @param WP_Post $post   Post being scanned.
		 */
		return apply_filters( 'seo_health_check_post_html', $result, $post );
	}

	/**
	 * Renders blocks and shortcodes in the stored post content.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	private static function render_post_content( WP_Post $post ) {
		// Some shortcodes rely on the global post.
		$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $post );

		try {
			$html = do_shortcode( do_blocks( $post->post_content ) );
		} catch ( Throwable $e ) {
			$html = $post->post_content;
		}

		wp_reset_postdata();
		return (string) $html;
	}

	/**
	 * Fetches the public page and returns its main content without header, footer and navigation.
	 *
	 * @param WP_Post $post Post.
	 * @return string|WP_Error
	 */
	private static function fetch_rendered( WP_Post $post ) {
		$url = get_permalink( $post );
		if ( ! $url ) {
			return new WP_Error( 'seohc_no_permalink', __( 'The post has no public URL.', 'seo-health-check' ) );
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 20,
				'user-agent' => 'SEO Health Check/' . SEOHC_VERSION . '; ' . home_url(),
				/** This filter is documented in wp-includes/class-wp-http-streams.php */
				'sslverify'  => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter.
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			/* translators: %d: HTTP status code. */
			return new WP_Error( 'seohc_http_status', sprintf( __( 'The page returned HTTP status %d.', 'seo-health-check' ), $code ) );
		}

		return self::strip_site_chrome( wp_remote_retrieve_body( $response ) );
	}

	/**
	 * Removes header, footer, navigation and non-content elements from a full HTML document.
	 *
	 * @param string $html Full HTML document.
	 * @return string Inner HTML of the body.
	 */
	public static function strip_site_chrome( $html ) {
		$dom = self::load_dom( $html );
		if ( ! $dom ) {
			return $html;
		}

		$xpath  = new DOMXPath( $dom );
		$query  = '//' . implode( ' | //', self::CHROME_TAGS );
		$query .= ' | //*[@role="banner"] | //*[@role="contentinfo"] | //*[@role="navigation"]';

		$nodes = iterator_to_array( $xpath->query( $query ) );
		foreach ( $nodes as $node ) {
			if ( $node->parentNode ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$node->parentNode->removeChild( $node ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			}
		}

		$body = $dom->getElementsByTagName( 'body' )->item( 0 );
		if ( ! $body ) {
			return '';
		}

		$out = '';
		foreach ( $body->childNodes as $child ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$out .= $dom->saveHTML( $child );
		}
		return $out;
	}

	/**
	 * Parses HTML into a DOMDocument without emitting warnings for sloppy markup.
	 *
	 * @param string $html HTML fragment or document.
	 * @return DOMDocument|null
	 */
	public static function load_dom( $html ) {
		if ( '' === trim( (string) $html ) ) {
			return null;
		}

		$dom      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		// The XML declaration forces UTF-8 parsing.
		$loaded = $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return $loaded ? $dom : null;
	}
}
