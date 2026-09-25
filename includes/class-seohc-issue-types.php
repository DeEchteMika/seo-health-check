<?php
/**
 * Registry of every issue type the scanner can report.
 *
 * @package SEO_Health_Check
 */

defined( 'ABSPATH' ) || exit;

/**
 * Issue type definitions (label + severity).
 */
class SEOHC_Issue_Types {

	const SEVERITY_ERROR   = 'error';
	const SEVERITY_WARNING = 'warning';

	/**
	 * All issue types, keyed by slug.
	 *
	 * @return array<string, array{label: string, severity: string}>
	 */
	public static function all() {
		$types = array(
			'title_missing'         => array(
				'label'    => __( 'Missing title', 'seo-health-check' ),
				'severity' => self::SEVERITY_ERROR,
			),
			'title_too_long'        => array(
				'label'    => __( 'Title too long', 'seo-health-check' ),
				'severity' => self::SEVERITY_WARNING,
			),
			'title_duplicate'       => array(
				'label'    => __( 'Duplicate title', 'seo-health-check' ),
				'severity' => self::SEVERITY_WARNING,
			),
			'description_missing'   => array(
				'label'    => __( 'Missing meta description', 'seo-health-check' ),
				'severity' => self::SEVERITY_WARNING,
			),
			'description_too_long'  => array(
				'label'    => __( 'Meta description too long', 'seo-health-check' ),
				'severity' => self::SEVERITY_WARNING,
			),
			'description_duplicate' => array(
				'label'    => __( 'Duplicate meta description', 'seo-health-check' ),
				'severity' => self::SEVERITY_WARNING,
			),
			'image_missing_alt'     => array(
				'label'    => __( 'Image without alt text', 'seo-health-check' ),
				'severity' => self::SEVERITY_WARNING,
			),
			'image_too_large'       => array(
				'label'    => __( 'Image file too large', 'seo-health-check' ),
				'severity' => self::SEVERITY_WARNING,
			),
			'h1_missing'            => array(
				'label'    => __( 'Missing H1', 'seo-health-check' ),
				'severity' => self::SEVERITY_ERROR,
			),
			'h1_multiple'           => array(
				'label'    => __( 'Multiple H1s', 'seo-health-check' ),
				'severity' => self::SEVERITY_WARNING,
			),
			'heading_skip'          => array(
				'label'    => __( 'Heading level skipped', 'seo-health-check' ),
				'severity' => self::SEVERITY_WARNING,
			),
			'thin_content'          => array(
				'label'    => __( 'Thin content', 'seo-health-check' ),
				'severity' => self::SEVERITY_WARNING,
			),
			'broken_internal_link'  => array(
				'label'    => __( 'Broken internal link', 'seo-health-check' ),
				'severity' => self::SEVERITY_ERROR,
			),
			'no_incoming_links'     => array(
				'label'    => __( 'No links to this page', 'seo-health-check' ),
				'severity' => self::SEVERITY_WARNING,
			),
			'rendered_fetch_failed' => array(
				'label'    => __( 'Page could not be loaded', 'seo-health-check' ),
				'severity' => self::SEVERITY_WARNING,
			),
		);

		/**
		 * Filters the list of issue types.
		 *
		 * @param array $types Issue types keyed by slug.
		 */
		return apply_filters( 'seo_health_check_issue_types', $types );
	}

	/**
	 * Human readable label for an issue type.
	 *
	 * @param string $type Issue type slug.
	 * @return string
	 */
	public static function label( $type ) {
		$types = self::all();
		return isset( $types[ $type ] ) ? $types[ $type ]['label'] : $type;
	}

	/**
	 * Severity for an issue type.
	 *
	 * @param string $type Issue type slug.
	 * @return string
	 */
	public static function severity( $type ) {
		$types = self::all();
		return isset( $types[ $type ] ) ? $types[ $type ]['severity'] : self::SEVERITY_WARNING;
	}

	/**
	 * Whether the slug is a known issue type.
	 *
	 * @param string $type Issue type slug.
	 * @return bool
	 */
	public static function exists( $type ) {
		return array_key_exists( $type, self::all() );
	}
}
