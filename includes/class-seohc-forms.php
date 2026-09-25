<?php
/**
 * Reads the forms on the site, whichever plugin made them.
 *
 * @package SEO_Health_Check
 */

defined( 'ABSPATH' ) || exit;

/**
 * Contact Form 7, WPForms and Gravity Forms, flattened into one shape.
 *
 * Each of the three keeps its forms somewhere else: posts with meta, posts with JSON in the
 * content, and custom tables behind an API. The launch checks should not have to know that, so
 * everything comes out of here as the same handful of fields.
 */
class SEOHC_Forms {

	/**
	 * Placeholders the form plugins use for the WordPress administrator address.
	 */
	const ADMIN_TAGS = array( '[_site_admin_email]', '{admin_email}', '{wp_admin_email}' );

	/**
	 * Forms found during this request.
	 *
	 * @var array[]|null
	 */
	private static $cache = null;

	/**
	 * Every form on the site.
	 *
	 * @return array[] Each with plugin, id, title, edit_url, recipients and confirmation.
	 */
	public static function all() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$forms = array_merge( self::contact_form_7(), self::wpforms(), self::gravity_forms() );

		/**
		 * Filters the forms the launch checks look at.
		 *
		 * Add your own form plugin here: give every form a plugin, id, title, edit_url,
		 * recipients (array of strings) and confirmation ('message', 'page', 'redirect' or
		 * 'unknown').
		 *
		 * @param array[] $forms Forms found so far.
		 */
		self::$cache = (array) apply_filters( 'seo_health_check_forms', $forms );

		return self::$cache;
	}

	/**
	 * Whether any supported form plugin is active.
	 *
	 * @return bool
	 */
	public static function has_plugin() {
		return post_type_exists( 'wpcf7_contact_form' ) || post_type_exists( 'wpforms' ) || class_exists( 'GFAPI' );
	}

	/**
	 * Whether a recipient is still the WordPress administrator placeholder.
	 *
	 * @param string $recipient Recipient as the form stores it.
	 * @return bool
	 */
	public static function is_admin_placeholder( $recipient ) {
		foreach ( self::ADMIN_TAGS as $tag ) {
			if ( false !== stripos( $recipient, $tag ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The domain of a recipient, lowercase, or an empty string when it is not an address.
	 *
	 * @param string $recipient Recipient as the form stores it.
	 * @return string
	 */
	public static function domain_of( $recipient ) {
		if ( ! preg_match( '/[^\s<>,;]+@([A-Za-z0-9.\-]+\.[A-Za-z]{2,})/', $recipient, $match ) ) {
			return '';
		}
		return strtolower( $match[1] );
	}

	/**
	 * Contact Form 7: posts with the mail settings in meta.
	 *
	 * @return array[]
	 */
	private static function contact_form_7() {
		if ( ! post_type_exists( 'wpcf7_contact_form' ) ) {
			return array();
		}

		$forms = array();

		foreach ( self::posts_of( 'wpcf7_contact_form' ) as $post ) {
			$mail      = get_post_meta( $post->ID, '_mail', true );
			$recipient = is_array( $mail ) && isset( $mail['recipient'] ) ? (string) $mail['recipient'] : '';

			// A redirect is not a setting in Contact Form 7; it is a line of JavaScript in the
			// additional settings, so that is the only place it can be seen from here.
			$extra        = (string) get_post_meta( $post->ID, '_additional_settings', true );
			$confirmation = preg_match( '/on_sent_ok|location\s*[=.]|wpcf7_redirect/i', $extra ) ? 'redirect' : 'message';

			$forms[] = array(
				'plugin'       => 'Contact Form 7',
				'id'           => (int) $post->ID,
				'title'        => $post->post_title,
				'edit_url'     => admin_url( 'admin.php?page=wpcf7&post=' . (int) $post->ID . '&action=edit' ),
				'recipients'   => self::split_recipients( $recipient ),
				'confirmation' => $confirmation,
			);
		}

		return $forms;
	}

	/**
	 * WPForms: posts with the whole form as JSON in the content.
	 *
	 * @return array[]
	 */
	private static function wpforms() {
		if ( ! post_type_exists( 'wpforms' ) ) {
			return array();
		}

		$forms = array();

		foreach ( self::posts_of( 'wpforms' ) as $post ) {
			$data     = json_decode( $post->post_content, true );
			$settings = isset( $data['settings'] ) && is_array( $data['settings'] ) ? $data['settings'] : array();

			$recipients = array();
			if ( isset( $settings['notifications'] ) && is_array( $settings['notifications'] ) ) {
				foreach ( $settings['notifications'] as $notification ) {
					if ( isset( $notification['email'] ) ) {
						$recipients = array_merge( $recipients, self::split_recipients( (string) $notification['email'] ) );
					}
				}
			}

			$confirmation = 'unknown';
			if ( isset( $settings['confirmations'] ) && is_array( $settings['confirmations'] ) ) {
				foreach ( $settings['confirmations'] as $item ) {
					if ( isset( $item['type'] ) ) {
						$confirmation = 'message' === $item['type'] ? 'message' : 'page';
						break;
					}
				}
			}

			$forms[] = array(
				'plugin'       => 'WPForms',
				'id'           => (int) $post->ID,
				'title'        => $post->post_title,
				'edit_url'     => admin_url( 'admin.php?page=wpforms-builder&form_id=' . (int) $post->ID ),
				'recipients'   => array_values( array_unique( $recipients ) ),
				'confirmation' => $confirmation,
			);
		}

		return $forms;
	}

	/**
	 * Gravity Forms: custom tables, reachable through its own API.
	 *
	 * @return array[]
	 */
	private static function gravity_forms() {
		if ( ! class_exists( 'GFAPI' ) || ! method_exists( 'GFAPI', 'get_forms' ) ) {
			return array();
		}

		$forms = array();

		foreach ( (array) GFAPI::get_forms() as $form ) {
			$recipients = array();
			if ( isset( $form['notifications'] ) && is_array( $form['notifications'] ) ) {
				foreach ( $form['notifications'] as $notification ) {
					// Routing and field-based recipients depend on what the visitor fills in,
					// so there is no fixed address to judge.
					if ( isset( $notification['toType'] ) && 'email' !== $notification['toType'] ) {
						continue;
					}
					if ( isset( $notification['to'] ) ) {
						$recipients = array_merge( $recipients, self::split_recipients( (string) $notification['to'] ) );
					}
				}
			}

			$confirmation = 'unknown';
			if ( isset( $form['confirmations'] ) && is_array( $form['confirmations'] ) ) {
				foreach ( $form['confirmations'] as $item ) {
					if ( isset( $item['type'] ) ) {
						$confirmation = 'message' === $item['type'] ? 'message' : 'page';
						break;
					}
				}
			}

			$forms[] = array(
				'plugin'       => 'Gravity Forms',
				'id'           => (int) $form['id'],
				'title'        => isset( $form['title'] ) ? $form['title'] : '',
				'edit_url'     => admin_url( 'admin.php?page=gf_edit_forms&id=' . (int) $form['id'] ),
				'recipients'   => array_values( array_unique( $recipients ) ),
				'confirmation' => $confirmation,
			);
		}

		return $forms;
	}

	/**
	 * Forms of one post type.
	 *
	 * Capped at a hundred: that is well past what any site has, and it keeps the check from
	 * loading a whole table if a plugin ever reuses this post type for something else.
	 *
	 * @param string $post_type Post type.
	 * @return WP_Post[]
	 */
	private static function posts_of( $post_type ) {
		return (array) get_posts(
			array(
				'post_type'        => $post_type,
				'post_status'      => array( 'publish', 'draft' ),
				'posts_per_page'   => 100,
				'suppress_filters' => false,
			)
		);
	}

	/**
	 * Splits a recipient field into separate entries.
	 *
	 * @param string $value Recipient field.
	 * @return string[]
	 */
	private static function split_recipients( $value ) {
		$parts = preg_split( '/[,;]+/', (string) $value );
		$parts = array_filter( array_map( 'trim', (array) $parts ) );

		return array_values( $parts );
	}
}
