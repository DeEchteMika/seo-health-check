<?php
/**
 * Reads the SEO title and meta description from Yoast SEO, Rank Math or WordPress core.
 *
 * @package SEO_Health_Check
 */

defined( 'ABSPATH' ) || exit;

/**
 * SEO meta resolver.
 */
class SEOHC_SEO_Meta {

	/**
	 * Detects the active SEO plugin.
	 *
	 * @return string 'yoast', 'rankmath' or 'none'.
	 */
	public static function provider() {
		if ( defined( 'WPSEO_VERSION' ) ) {
			return 'yoast';
		}
		if ( class_exists( 'RankMath' ) || defined( 'RANK_MATH_VERSION' ) ) {
			return 'rankmath';
		}
		return 'none';
	}

	/**
	 * Human readable provider name.
	 *
	 * @return string
	 */
	public static function provider_label() {
		switch ( self::provider() ) {
			case 'yoast':
				return 'Yoast SEO';
			case 'rankmath':
				return 'Rank Math';
			default:
				return __( 'none (WordPress defaults)', 'seo-health-check' );
		}
	}

	/**
	 * Resolves the title and description a search engine will see.
	 *
	 * @param WP_Post $post Post.
	 * @return array{title: string, description: string, custom_description: bool}
	 */
	public static function get( WP_Post $post ) {
		switch ( self::provider() ) {
			case 'yoast':
				$result = self::from_yoast( $post );
				break;
			case 'rankmath':
				$result = self::from_rank_math( $post );
				break;
			default:
				$result = array(
					'title'              => self::default_title( $post ),
					'description'        => '',
					'custom_description' => false,
				);
		}

		$result['title']       = self::clean( $result['title'] );
		$result['description'] = self::clean( $result['description'] );

		/**
		 * Filters the resolved SEO meta for a post.
		 *
		 * @param array   $result Title, description and custom_description flag.
		 * @param WP_Post $post   Post being scanned.
		 */
		return apply_filters( 'seo_health_check_seo_meta', $result, $post );
	}

	/**
	 * Yoast SEO values.
	 *
	 * @param WP_Post $post Post.
	 * @return array
	 */
	private static function from_yoast( WP_Post $post ) {
		$title       = (string) get_post_meta( $post->ID, '_yoast_wpseo_title', true );
		$description = (string) get_post_meta( $post->ID, '_yoast_wpseo_metadesc', true );
		$custom      = '' !== trim( $description );

		if ( '' === trim( $title ) && class_exists( 'WPSEO_Options' ) ) {
			$title = (string) WPSEO_Options::get( 'title-' . $post->post_type, '' );
		}
		if ( ! $custom && class_exists( 'WPSEO_Options' ) ) {
			$description = (string) WPSEO_Options::get( 'metadesc-' . $post->post_type, '' );
		}

		return array(
			'title'              => self::replace_yoast_vars( $title, $post, self::default_title( $post ) ),
			'description'        => self::replace_yoast_vars( $description, $post, '' ),
			'custom_description' => $custom,
		);
	}

	/**
	 * Rank Math values.
	 *
	 * @param WP_Post $post Post.
	 * @return array
	 */
	private static function from_rank_math( WP_Post $post ) {
		$title       = (string) get_post_meta( $post->ID, 'rank_math_title', true );
		$description = (string) get_post_meta( $post->ID, 'rank_math_description', true );
		$custom      = '' !== trim( $description );
		$has_helper  = class_exists( '\RankMath\Helper' ) && method_exists( '\RankMath\Helper', 'get_settings' );

		if ( '' === trim( $title ) && $has_helper ) {
			$title = (string) \RankMath\Helper::get_settings( 'titles.pt_' . $post->post_type . '_title' );
		}
		if ( ! $custom && $has_helper ) {
			$description = (string) \RankMath\Helper::get_settings( 'titles.pt_' . $post->post_type . '_description' );
		}

		return array(
			'title'              => self::replace_rank_math_vars( $title, $post, self::default_title( $post ) ),
			'description'        => self::replace_rank_math_vars( $description, $post, '' ),
			'custom_description' => $custom,
		);
	}

	/**
	 * Replaces Yoast variables such as %%title%%.
	 *
	 * @param string  $value    Template.
	 * @param WP_Post $post     Post.
	 * @param string  $fallback Used when the template is empty or cannot be resolved.
	 * @return string
	 */
	private static function replace_yoast_vars( $value, WP_Post $post, $fallback ) {
		if ( '' === trim( $value ) ) {
			return $fallback;
		}
		if ( function_exists( 'wpseo_replace_vars' ) ) {
			try {
				$replaced = (string) wpseo_replace_vars( $value, $post );
			} catch ( Throwable $e ) {
				$replaced = '';
			}
			return self::usable( $replaced ) ? $replaced : $fallback;
		}
		return self::usable( $value ) ? $value : $fallback;
	}

	/**
	 * Replaces Rank Math variables such as %title%.
	 *
	 * @param string  $value    Template.
	 * @param WP_Post $post     Post.
	 * @param string  $fallback Used when the template is empty or cannot be resolved.
	 * @return string
	 */
	private static function replace_rank_math_vars( $value, WP_Post $post, $fallback ) {
		if ( '' === trim( $value ) ) {
			return $fallback;
		}
		if ( class_exists( '\RankMath\Helper' ) && method_exists( '\RankMath\Helper', 'replace_vars' ) ) {
			try {
				$replaced = (string) \RankMath\Helper::replace_vars( $value, $post );
			} catch ( Throwable $e ) {
				$replaced = '';
			}
			return self::usable( $replaced ) ? $replaced : $fallback;
		}
		return self::usable( $value ) ? $value : $fallback;
	}

	/**
	 * Whether a resolved value can be stored as the real title or description.
	 *
	 * SEO plugins only resolve their own variables reliably on their own screens. Asked from a
	 * background scan or an Ajax request they sometimes hand back the raw template, which would
	 * be stored as the page title and then reported as a duplicate on every page. Anything that
	 * still contains %variable% or %%variable%% is therefore rejected in favour of the fallback.
	 *
	 * @param string $value Resolved value.
	 * @return bool
	 */
	private static function usable( $value ) {
		$value = trim( (string) $value );
		return '' !== $value && ! preg_match( '/%%?[a-z0-9_-]+%%?/i', $value );
	}

	/**
	 * The title WordPress itself would output: "Post title - Site name".
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	private static function default_title( WP_Post $post ) {
		$title = trim( wp_strip_all_tags( $post->post_title ) );
		if ( '' === $title ) {
			return '';
		}

		/** This filter is documented in wp-includes/general-template.php */
		$sep = apply_filters( 'document_title_separator', '-' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter.
		return $title . ' ' . $sep . ' ' . get_bloginfo( 'name', 'display' );
	}

	/**
	 * Strips tags, decodes entities and collapses whitespace.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private static function clean( $value ) {
		$value = html_entity_decode( wp_strip_all_tags( (string) $value ), ENT_QUOTES, get_bloginfo( 'charset' ) );
		return trim( preg_replace( '/\s+/u', ' ', $value ) );
	}
}
