<?php
/**
 * Runs all checks against a single post.
 *
 * @package SEO_Health_Check
 */

defined( 'ABSPATH' ) || exit;

/**
 * Single-post scanner.
 */
class SEOHC_Scanner {

	/**
	 * Scans a post and stores the results.
	 *
	 * @param int $post_id Post ID.
	 * @return array|null The issues found, or null when the post should not be scanned.
	 */
	public static function scan_post( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || ! self::is_scannable( $post ) ) {
			SEOHC_Repository::delete_post( $post_id );
			return null;
		}

		$settings = SEOHC_Settings::get_all();
		$meta     = SEOHC_SEO_Meta::get( $post );
		$content  = SEOHC_Content_Resolver::resolve( $post );
		$dom      = SEOHC_Content_Resolver::load_dom( $content['html'] );
		$issues   = array();

		if ( '' !== $content['error'] ) {
			self::add( $issues, 'rendered_fetch_failed', $content['error'] . ' ' . __( 'The post content was scanned instead.', 'seo-health-check' ) );
		}

		self::check_title( $issues, $post, $meta['title'], (int) $settings['max_title_length'] );
		self::check_description( $issues, $meta, (int) $settings['max_description_length'] );

		$word_count = 0;
		if ( $dom ) {
			self::check_images( $issues, $dom );
			self::check_headings( $issues, $dom, $content['source'], (bool) $settings['theme_outputs_h1'] );
			self::check_links( $issues, $dom, get_permalink( $post ) );
			$word_count = self::count_words( $dom );
		} elseif ( 'content' === $content['source'] && ! $settings['theme_outputs_h1'] ) {
			self::add( $issues, 'h1_missing', __( 'The content is empty.', 'seo-health-check' ) );
		}

		if ( 'content' === $content['source'] ) {
			self::check_featured_image( $issues, $post );
		}

		$min_words = (int) $settings['min_words'];
		if ( $min_words > 0 && $word_count < $min_words ) {
			/* translators: 1: word count, 2: minimum word count. */
			self::add( $issues, 'thin_content', sprintf( __( '%1$d words (minimum %2$d).', 'seo-health-check' ), $word_count, $min_words ) );
		}

		/**
		 * Filters the issues found for a post before they are saved.
		 *
		 * @param array   $issues List of arrays with 'type' and 'details'.
		 * @param WP_Post $post   Post being scanned.
		 * @param string  $html   The scanned HTML.
		 */
		$issues = apply_filters( 'seo_health_check_post_issues', $issues, $post, $content['html'] );
		$issues = array_values(
			array_filter(
				(array) $issues,
				function ( $issue ) {
					return isset( $issue['type'], $issue['details'] ) && SEOHC_Issue_Types::exists( $issue['type'] );
				}
			)
		);

		SEOHC_Repository::save_post_result(
			$post->ID,
			array(
				'seo_title'        => $meta['title'],
				'meta_description' => $meta['custom_description'] ? $meta['description'] : '',
				'word_count'       => $word_count,
			),
			$issues
		);

		return $issues;
	}

	/**
	 * Whether a post falls within the scan settings.
	 *
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	public static function is_scannable( WP_Post $post ) {
		return 'publish' === $post->post_status
			&& in_array( $post->post_type, (array) SEOHC_Settings::get( 'post_types' ), true );
	}

	/**
	 * Title checks.
	 *
	 * @param array   $issues Issues (by reference).
	 * @param WP_Post $post   Post.
	 * @param string  $title  Resolved SEO title.
	 * @param int     $max    Maximum length.
	 */
	private static function check_title( array &$issues, WP_Post $post, $title, $max ) {
		if ( '' === $title || '' === trim( $post->post_title ) ) {
			self::add( $issues, 'title_missing', __( 'The post has no title.', 'seo-health-check' ) );
			return;
		}

		$length = mb_strlen( $title );
		if ( $length > $max ) {
			/* translators: 1: length, 2: maximum length, 3: the title. */
			self::add( $issues, 'title_too_long', sprintf( __( '%1$d characters (max %2$d): %3$s', 'seo-health-check' ), $length, $max, $title ) );
		}
	}

	/**
	 * Meta description checks.
	 *
	 * @param array $issues Issues (by reference).
	 * @param array $meta   Resolved SEO meta.
	 * @param int   $max    Maximum length.
	 */
	private static function check_description( array &$issues, array $meta, $max ) {
		if ( ! $meta['custom_description'] ) {
			$details = 'none' === SEOHC_SEO_Meta::provider()
				? __( 'No SEO plugin is active, so no meta description is output.', 'seo-health-check' )
				: __( 'No meta description was written; search engines will pick their own snippet.', 'seo-health-check' );
			self::add( $issues, 'description_missing', $details );
			return;
		}

		$length = mb_strlen( $meta['description'] );
		if ( $length > $max ) {
			/* translators: 1: length, 2: maximum length. */
			self::add( $issues, 'description_too_long', sprintf( __( '%1$d characters (max %2$d).', 'seo-health-check' ), $length, $max ) );
		}
	}

	/**
	 * Images without alt text.
	 *
	 * @param array       $issues Issues (by reference).
	 * @param DOMDocument $dom    Content.
	 */
	private static function check_images( array &$issues, DOMDocument $dom ) {
		foreach ( $dom->getElementsByTagName( 'img' ) as $img ) {
			if ( 'true' === $img->getAttribute( 'aria-hidden' ) || 'presentation' === $img->getAttribute( 'role' ) ) {
				continue;
			}
			if ( '' !== trim( $img->getAttribute( 'alt' ) ) ) {
				continue;
			}

			$src = $img->getAttribute( 'src' );
			if ( '' === $src ) {
				$src = $img->getAttribute( 'data-src' );
			}
			self::add( $issues, 'image_missing_alt', $src ? wp_basename( strtok( $src, '?' ) ) : __( '(image without source)', 'seo-health-check' ) );
		}
	}

	/**
	 * Featured image without alt text.
	 *
	 * @param array   $issues Issues (by reference).
	 * @param WP_Post $post   Post.
	 */
	private static function check_featured_image( array &$issues, WP_Post $post ) {
		$thumbnail_id = get_post_thumbnail_id( $post );
		if ( ! $thumbnail_id ) {
			return;
		}
		$alt = (string) get_post_meta( $thumbnail_id, '_wp_attachment_image_alt', true );
		if ( '' === trim( $alt ) ) {
			/* translators: %s: file name. */
			self::add( $issues, 'image_missing_alt', sprintf( __( 'Featured image: %s', 'seo-health-check' ), wp_basename( (string) get_attached_file( $thumbnail_id ) ) ) );
		}
	}

	/**
	 * H1 checks.
	 *
	 * @param array       $issues   Issues (by reference).
	 * @param DOMDocument $dom      Content.
	 * @param string      $source   'content' or 'rendered'.
	 * @param bool        $theme_h1 Whether the theme adds an H1 around the post content.
	 */
	private static function check_headings( array &$issues, DOMDocument $dom, $source, $theme_h1 ) {
		$count = $dom->getElementsByTagName( 'h1' )->length;
		if ( 'content' === $source && $theme_h1 ) {
			++$count;
		}

		if ( 0 === $count ) {
			self::add( $issues, 'h1_missing', __( 'The page has no H1 heading.', 'seo-health-check' ) );
		} elseif ( $count > 1 ) {
			$details = 'content' === $source && $theme_h1
				/* translators: %d: number of H1 headings. */
				? sprintf( __( '%d H1 headings, including the post title shown by the theme.', 'seo-health-check' ), $count )
				/* translators: %d: number of H1 headings. */
				: sprintf( __( '%d H1 headings.', 'seo-health-check' ), $count );
			self::add( $issues, 'h1_multiple', $details );
		}
	}

	/**
	 * Broken internal links.
	 *
	 * @param array       $issues   Issues (by reference).
	 * @param DOMDocument $dom      Content.
	 * @param string      $page_url URL of the page, for relative links.
	 */
	private static function check_links( array &$issues, DOMDocument $dom, $page_url ) {
		$seen = array();
		foreach ( $dom->getElementsByTagName( 'a' ) as $link ) {
			$href = trim( $link->getAttribute( 'href' ) );
			if ( '' === $href || isset( $seen[ $href ] ) ) {
				continue;
			}
			$seen[ $href ] = true;

			$reason = SEOHC_Link_Checker::check( $href, $page_url );
			if ( null !== $reason ) {
				self::add( $issues, 'broken_internal_link', $href . ' - ' . $reason );
			}
		}
	}

	/**
	 * Counts the words in the visible text.
	 *
	 * @param DOMDocument $dom Content.
	 * @return int
	 */
	private static function count_words( DOMDocument $dom ) {
		$text = $dom->textContent; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		return (int) preg_match_all( "/[\p{L}\p{N}][\p{L}\p{N}'’-]*/u", (string) $text );
	}

	/**
	 * Appends an issue.
	 *
	 * @param array  $issues  Issues (by reference).
	 * @param string $type    Issue type.
	 * @param string $details Details shown in the overview.
	 */
	private static function add( array &$issues, $type, $details ) {
		$issues[] = array(
			'type'    => $type,
			'details' => $details,
		);
	}
}
