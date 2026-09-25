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

		$word_count   = 0;
		$link_targets = array();
		if ( $dom ) {
			self::check_images( $issues, $dom, (int) $settings['max_image_kb'] );
			self::check_headings( $issues, $dom, $content['source'], (bool) $settings['theme_outputs_h1'] );
			self::check_heading_order( $issues, $dom, $content['source'], (bool) $settings['theme_outputs_h1'] );
			$link_targets = self::check_links( $issues, $dom, get_permalink( $post ) );
			$word_count   = self::count_words( $dom );
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

		SEOHC_Repository::save_post_links( $post->ID, $link_targets );

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
	 * @param int         $max_kb Maximum image size in KB, 0 to skip the size check.
	 */
	private static function check_images( array &$issues, DOMDocument $dom, $max_kb ) {
		foreach ( $dom->getElementsByTagName( 'img' ) as $img ) {
			$src = $img->getAttribute( 'src' );
			if ( '' === $src ) {
				$src = $img->getAttribute( 'data-src' );
			}

			$decorative = 'true' === $img->getAttribute( 'aria-hidden' ) || 'presentation' === $img->getAttribute( 'role' );

			if ( ! $decorative && '' === trim( $img->getAttribute( 'alt' ) ) ) {
				self::add(
					$issues,
					'image_missing_alt',
					$src ? wp_basename( strtok( $src, '?' ) ) : __( '(image without source)', 'seo-health-check' ),
					self::attachment_id_from_url( $src )
				);
			}

			// A decorative image costs the visitor the same bandwidth, so size is checked for all.
			if ( $max_kb > 0 ) {
				self::check_image_size( $issues, $src, $max_kb );
			}
		}
	}

	/**
	 * Reports an image whose file is bigger than the maximum.
	 *
	 * Only files in the uploads folder can be measured from disk; images served from a CDN or
	 * another site are skipped rather than fetched, which would make every scan far slower.
	 *
	 * @param array  $issues Issues (by reference).
	 * @param string $src    Image URL.
	 * @param int    $max_kb Maximum size in KB.
	 */
	private static function check_image_size( array &$issues, $src, $max_kb ) {
		$file = self::local_file_for_url( $src );
		if ( null === $file ) {
			return;
		}

		$bytes = (int) filesize( $file );
		$max   = $max_kb * KB_IN_BYTES;
		if ( $bytes <= $max ) {
			return;
		}

		self::add(
			$issues,
			'image_too_large',
			sprintf(
				/* translators: 1: file name, 2: size of the file, 3: maximum size. */
				__( '%1$s is %2$s (maximum %3$s).', 'seo-health-check' ),
				wp_basename( $file ),
				size_format( $bytes ),
				size_format( $max )
			),
			self::attachment_id_from_url( $src )
		);
	}

	/**
	 * Turns an image URL into a path in the uploads folder, or null when it is not there.
	 *
	 * @param string $src Image URL, absolute or relative.
	 * @return string|null
	 */
	private static function local_file_for_url( $src ) {
		$src = trim( (string) $src );
		if ( '' === $src || 0 === strpos( $src, 'data:' ) ) {
			return null;
		}

		$src     = strtok( WP_Http::make_absolute_url( $src, home_url( '/' ) ), '?' );
		$uploads = wp_get_upload_dir();
		$base    = preg_replace( '#^https?://#', '', $uploads['baseurl'] );
		$url     = preg_replace( '#^https?://#', '', $src );

		if ( 0 !== strpos( $url, $base ) ) {
			return null;
		}

		$file = $uploads['basedir'] . rawurldecode( substr( $url, strlen( $base ) ) );

		return file_exists( $file ) ? $file : null;
	}

	/**
	 * Featured image without alt text. /**
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
			self::add(
				$issues,
				'image_missing_alt',
				/* translators: %s: file name. */
				sprintf( __( 'Featured image: %s', 'seo-health-check' ), wp_basename( (string) get_attached_file( $thumbnail_id ) ) ),
				$thumbnail_id
			);
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
	 * Heading levels that skip a step, for example an H2 followed by an H4.
	 *
	 * Only the first jump is reported, with a count of the rest: one badly structured page
	 * should not fill the overview with a dozen rows that all need the same fix.
	 *
	 * @param array       $issues   Issues (by reference).
	 * @param DOMDocument $dom      Content.
	 * @param string      $source   'content' or 'rendered'.
	 * @param bool        $theme_h1 Whether the theme adds an H1 around the post content.
	 */
	private static function check_heading_order( array &$issues, DOMDocument $dom, $source, $theme_h1 ) {
		$xpath = new DOMXPath( $dom );
		$nodes = $xpath->query( '//*[self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::h6]' );

		if ( ! $nodes || 0 === $nodes->length ) {
			return;
		}

		// When the theme prints the post title as H1, the content starts one level below it.
		$previous = ( 'content' === $source && $theme_h1 ) ? 1 : 0;
		$first    = '';
		$extra    = 0;

		foreach ( $nodes as $node ) {
			$level = (int) substr( $node->nodeName, 1 ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM property.

			if ( $previous > 0 && $level > $previous + 1 ) {
				if ( '' === $first ) {
					$first = sprintf(
						/* translators: 1: heading level before the jump, 2: heading level after it. */
						__( 'An H%1$d is followed by an H%2$d.', 'seo-health-check' ),
						$previous,
						$level
					);
				} else {
					++$extra;
				}
			}

			$previous = $level;
		}

		if ( '' === $first ) {
			return;
		}

		if ( $extra > 0 ) {
			$first .= ' ' . sprintf(
				/* translators: %d: number of further heading jumps on the same page. */
				_n( '%d more jump further down.', '%d more jumps further down.', $extra, 'seo-health-check' ),
				$extra
			);
		}

		self::add( $issues, 'heading_skip', $first );
	}

	/**
	 * Broken internal links, and which posts the page links to.
	 *
	 * @param array       $issues   Issues (by reference).
	 * @param DOMDocument $dom      Content.
	 * @param string      $page_url URL of the page, for relative links.
	 * @return int[] IDs of the posts this page links to.
	 */
	private static function check_links( array &$issues, DOMDocument $dom, $page_url ) {
		$seen    = array();
		$targets = array();

		foreach ( $dom->getElementsByTagName( 'a' ) as $link ) {
			$href = trim( $link->getAttribute( 'href' ) );
			if ( '' === $href || isset( $seen[ $href ] ) ) {
				continue;
			}
			$seen[ $href ] = true;

			$result = SEOHC_Link_Checker::inspect( $href, $page_url );

			if ( null !== $result['reason'] ) {
				self::add( $issues, 'broken_internal_link', $href . ' - ' . $result['reason'] );
			}

			if ( $result['post_id'] > 0 ) {
				$targets[] = $result['post_id'];
			}
		}

		return array_values( array_unique( $targets ) );
	}

	/**
	 * Counts the words in the visible text.    /**
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
	 * Finds the media library item an image URL belongs to, so its alt text can be edited inline.
	 *
	 * Content images usually point at a resized file (image-1024x490.jpg), which is not stored as
	 * its own attachment, so the size suffix is stripped before looking the URL up.
	 *
	 * @param string $src Image URL.
	 * @return int Attachment ID, or 0 when the image is not in the media library.
	 */
	private static function attachment_id_from_url( $src ) {
		$src = strtok( (string) $src, '?' );
		if ( '' === $src ) {
			return 0;
		}

		$attachment_id = attachment_url_to_postid( $src );
		if ( $attachment_id ) {
			return $attachment_id;
		}

		$full = preg_replace( '/-\d+x\d+(\.[a-zA-Z0-9]+)$/', '$1', $src );
		return $full !== $src ? attachment_url_to_postid( $full ) : 0;
	}

	/**
	 * Appends an issue.
	 *
	 * @param array  $issues    Issues (by reference).
	 * @param string $type      Issue type.
	 * @param string $details   Details shown in the overview.
	 * @param int    $object_id Attachment the issue is about, when there is one. Enables inline editing.
	 */
	private static function add( array &$issues, $type, $details, $object_id = 0 ) {
		$issues[] = array(
			'type'      => $type,
			'details'   => $details,
			'object_id' => (int) $object_id,
		);
	}
}
