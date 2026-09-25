<?php
/**
 * Editing alt texts, SEO titles and meta descriptions straight from the overview.
 *
 * @package SEO_Health_Check
 */

defined( 'ABSPATH' ) || exit;

/**
 * Describes which issues can be fixed in place and saves the new values.
 *
 * Only fields that are stored on their own are editable here. An H1, a thin text or a broken
 * link lives inside the page content itself, so those still send the user to the edit screen.
 */
class SEOHC_Inline_Edit {

	const NONCE = 'seohc_inline_edit';

	const FIELD_ALT         = 'alt';
	const FIELD_TITLE       = 'seo_title';
	const FIELD_DESCRIPTION = 'meta_description';

	/**
	 * Registers the save endpoint.
	 */
	public static function init() {
		add_action( 'wp_ajax_seohc_inline_save', array( __CLASS__, 'handle_save' ) );
	}

	/**
	 * Which field an issue type can fix in place.
	 *
	 * @return array<string, string> Issue type => field.
	 */
	public static function fields_by_issue_type() {
		$map = array(
			'image_missing_alt'     => self::FIELD_ALT,
			'description_missing'   => self::FIELD_DESCRIPTION,
			'description_too_long'  => self::FIELD_DESCRIPTION,
			'description_duplicate' => self::FIELD_DESCRIPTION,
			'title_too_long'        => self::FIELD_TITLE,
			'title_duplicate'       => self::FIELD_TITLE,
		);

		/**
		 * Filters which issue types can be fixed from the overview.
		 *
		 * @param array $map Issue type => field slug.
		 */
		return apply_filters( 'seo_health_check_inline_fields', $map );
	}

	/**
	 * Describes the inline editor for one issue row, or null when it cannot be edited here.
	 *
	 * @param object $issue Issue row from the repository.
	 * @return array|null Keys: field, value, max_length, label, hint.
	 */
	public static function editor_for( $issue ) {
		$fields = self::fields_by_issue_type();
		$field  = isset( $fields[ $issue->issue_type ] ) ? $fields[ $issue->issue_type ] : '';

		if ( '' === $field ) {
			return null;
		}

		if ( self::FIELD_ALT === $field ) {
			$attachment_id = (int) $issue->object_id;
			if ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) ) {
				return null;
			}
			if ( ! current_user_can( 'edit_post', $attachment_id ) ) {
				return null;
			}

			return array(
				'field'      => $field,
				'value'      => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
				'max_length' => 125,
				'label'      => __( 'Alt text', 'seo-health-check' ),
				'hint'       => __( 'Describe what the image shows. Leave empty only for decoration.', 'seo-health-check' ),
			);
		}

		if ( 'none' === SEOHC_SEO_Meta::provider() ) {
			return null;
		}
		if ( ! current_user_can( 'edit_post', (int) $issue->post_id ) ) {
			return null;
		}

		if ( self::FIELD_TITLE === $field ) {
			return array(
				'field'      => $field,
				'value'      => self::stored_value( (int) $issue->post_id, $field ),
				'max_length' => (int) SEOHC_Settings::get( 'max_title_length' ),
				'label'      => __( 'SEO title', 'seo-health-check' ),
				/* translators: %s: name of the active SEO plugin. */
				'hint'       => sprintf( __( 'Saved in %s. Leave empty to use its default.', 'seo-health-check' ), SEOHC_SEO_Meta::provider_label() ),
			);
		}

		return array(
			'field'      => $field,
			'value'      => self::stored_value( (int) $issue->post_id, $field ),
			'max_length' => (int) SEOHC_Settings::get( 'max_description_length' ),
			'label'      => __( 'Meta description', 'seo-health-check' ),
			/* translators: %s: name of the active SEO plugin. */
			'hint'       => sprintf( __( 'Saved in %s. Summarise the page in one sentence.', 'seo-health-check' ), SEOHC_SEO_Meta::provider_label() ),
		);
	}

	/**
	 * Meta key the active SEO plugin stores a field in.
	 *
	 * @param string $field Field slug.
	 * @return string Empty when no SEO plugin is active.
	 */
	private static function meta_key( $field ) {
		$keys = array(
			'yoast'    => array(
				self::FIELD_TITLE       => '_yoast_wpseo_title',
				self::FIELD_DESCRIPTION => '_yoast_wpseo_metadesc',
			),
			'rankmath' => array(
				self::FIELD_TITLE       => 'rank_math_title',
				self::FIELD_DESCRIPTION => 'rank_math_description',
			),
		);

		$provider = SEOHC_SEO_Meta::provider();
		return isset( $keys[ $provider ][ $field ] ) ? $keys[ $provider ][ $field ] : '';
	}

	/**
	 * Currently stored value of a field, without the SEO plugin's template fallback.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $field   Field slug.
	 * @return string
	 */
	private static function stored_value( $post_id, $field ) {
		$key = self::meta_key( $field );
		return '' === $key ? '' : (string) get_post_meta( $post_id, $key, true );
	}

	/**
	 * Saves a value submitted from the overview and rescans the post.
	 */
	public static function handle_save() {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( SEOHC_Plugin::capability() ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'seo-health-check' ) ), 403 );
		}

		$issue_id = isset( $_POST['issue_id'] ) ? absint( $_POST['issue_id'] ) : 0;
		$value    = isset( $_POST['value'] ) ? sanitize_text_field( wp_unslash( $_POST['value'] ) ) : '';
		$issue    = self::get_issue( $issue_id );

		if ( ! $issue ) {
			wp_send_json_error( array( 'message' => __( 'This result no longer exists. Refresh the page.', 'seo-health-check' ) ), 404 );
		}

		$editor = self::editor_for( $issue );
		if ( ! $editor ) {
			wp_send_json_error( array( 'message' => __( 'This issue cannot be fixed from this screen.', 'seo-health-check' ) ), 400 );
		}

		$saved = self::FIELD_ALT === $editor['field']
			? self::save_alt( (int) $issue->object_id, $value, (int) $issue->post_id )
			: self::save_meta( (int) $issue->post_id, $editor['field'], $value );

		if ( is_wp_error( $saved ) ) {
			wp_send_json_error( array( 'message' => $saved->get_error_message() ), 400 );
		}

		// Rescan so the overview reflects the change straight away.
		SEOHC_Scanner::scan_post( (int) $issue->post_id );
		SEOHC_Repository::flag_duplicates();

		$remaining = self::count_remaining( (int) $issue->post_id, $issue->issue_type );

		wp_send_json_success(
			array(
				'value'     => $value,
				'resolved'  => 0 === $remaining,
				'remaining' => $remaining,
				'score'     => self::score_for_post( (int) $issue->post_id ),
				'message'   => 0 === $remaining
					? __( 'Fixed.', 'seo-health-check' )
					: __( 'Saved, but the issue is still reported.', 'seo-health-check' ),
			)
		);
	}

	/**
	 * Stores an alt text on a media library item, and on the image inside the page.
	 *
	 * WordPress copies the alt text into the page when an image is inserted, so an image that is
	 * already embedded keeps its own copy. Updating only the media library would leave the page
	 * unchanged, so the alt attribute of that image is updated as well.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $value         New alt text.
	 * @param int    $post_id       Post the image was found on.
	 * @return true|WP_Error
	 */
	private static function save_alt( $attachment_id, $value, $post_id ) {
		if ( ! current_user_can( 'edit_post', $attachment_id ) ) {
			return new WP_Error( 'seohc_forbidden', __( 'You may not edit this image.', 'seo-health-check' ) );
		}

		update_post_meta( $attachment_id, '_wp_attachment_image_alt', $value );

		if ( current_user_can( 'edit_post', $post_id ) ) {
			self::update_alt_in_content( $post_id, $attachment_id, $value );
		}

		return true;
	}

	/**
	 * Sets the alt attribute of every img tag in the post content that shows the given attachment.
	 *
	 * Only the matched img tags are rewritten; the rest of the content is left exactly as it was.
	 *
	 * @param int    $post_id       Post ID.
	 * @param int    $attachment_id Attachment ID.
	 * @param string $value         New alt text.
	 */
	private static function update_alt_in_content( $post_id, $attachment_id, $value ) {
		$post = get_post( $post_id );
		if ( ! $post || false === strpos( $post->post_content, '<img' ) ) {
			return;
		}

		$files = self::attachment_file_names( $attachment_id );
		if ( empty( $files ) ) {
			return;
		}

		$updated = preg_replace_callback(
			'/<img\b[^>]*>/i',
			static function ( $matches ) use ( $files, $value ) {
				$tag = $matches[0];

				if ( ! preg_match( '/\ssrc=["\']([^"\']+)["\']/i', $tag, $src ) ) {
					return $tag;
				}
				if ( ! in_array( wp_basename( strtok( $src[1], '?' ) ), $files, true ) ) {
					return $tag;
				}

				$attribute = ' alt="' . esc_attr( $value ) . '"';

				if ( preg_match( '/\salt=["\'][^"\']*["\']/i', $tag ) ) {
					return preg_replace( '/\salt=["\'][^"\']*["\']/i', $attribute, $tag, 1 );
				}
				return preg_replace( '/<img\b/i', '<img' . $attribute, $tag, 1 );
			},
			$post->post_content
		);

		if ( null !== $updated && $updated !== $post->post_content ) {
			wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => $updated,
				)
			);
		}
	}

	/**
	 * File names of an attachment and of every size WordPress generated for it.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string[]
	 */
	private static function attachment_file_names( $attachment_id ) {
		$files = array();

		$original = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		if ( '' !== $original ) {
			$files[] = wp_basename( $original );
		}

		$meta = wp_get_attachment_metadata( $attachment_id );
		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $size ) {
				if ( ! empty( $size['file'] ) ) {
					$files[] = $size['file'];
				}
			}
		}

		return array_values( array_unique( $files ) );
	}

	/**
	 * Stores an SEO title or meta description through the active SEO plugin.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $field   Field slug.
	 * @param string $value   New value.
	 * @return true|WP_Error
	 */
	private static function save_meta( $post_id, $field, $value ) {
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'seohc_forbidden', __( 'You may not edit this page.', 'seo-health-check' ) );
		}

		$key = self::meta_key( $field );
		if ( '' === $key ) {
			return new WP_Error( 'seohc_no_seo_plugin', __( 'Activate Yoast SEO or Rank Math to store this field.', 'seo-health-check' ) );
		}

		if ( '' === $value ) {
			delete_post_meta( $post_id, $key );
		} else {
			update_post_meta( $post_id, $key, $value );
		}

		return true;
	}

	/**
	 * Loads one issue row.
	 *
	 * @param int $issue_id Issue ID.
	 * @return object|null
	 */
	private static function get_issue( $issue_id ) {
		global $wpdb;
		$table = SEOHC_Repository::issues_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table.
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $issue_id ) );
	}

	/**
	 * How many issues of this type the post still has after the rescan.
	 *
	 * @param int    $post_id    Post ID.
	 * @param string $issue_type Issue type.
	 * @return int
	 */
	private static function count_remaining( $post_id, $issue_type ) {
		global $wpdb;
		$table = SEOHC_Repository::issues_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE post_id = %d AND issue_type = %s", $post_id, $issue_type ) );
	}

	/**
	 * Current score of a post.
	 *
	 * @param int $post_id Post ID.
	 * @return int
	 */
	private static function score_for_post( $post_id ) {
		global $wpdb;
		$table = SEOHC_Repository::pages_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT score FROM {$table} WHERE post_id = %d", $post_id ) );
	}
}
