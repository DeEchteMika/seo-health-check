<?php
/**
 * Plugin settings, registered through the Settings API.
 *
 * @package SEO_Health_Check
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings registration, sanitization and rendering.
 */
class SEOHC_Settings {

	const OPTION     = 'seohc_settings';
	const PAGE_SLUG  = 'seo-health-check-settings';
	const OPTION_GRP = 'seohc_settings_group';

	/**
	 * Default values for every setting.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'post_types'             => array( 'post', 'page' ),
			'min_words'              => 300,
			'max_title_length'       => 60,
			'max_description_length' => 160,
			'content_source'         => 'auto',
			'theme_outputs_h1'       => 1,
			'batch_size'             => 20,
			'rescan_on_save'         => 1,
			'footer_credit'          => '',
			'dev_domains'            => '',
		);
	}

	/**
	 * Current settings merged with defaults.
	 *
	 * @return array
	 */
	public static function get_all() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	/**
	 * A single setting.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::get_all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Post types that can be selected: every public type except attachments.
	 *
	 * @return WP_Post_Type[]
	 */
	public static function available_post_types() {
		$types = get_post_types( array( 'public' => true ), 'objects' );
		unset( $types['attachment'] );
		return $types;
	}

	/**
	 * Registers the setting, sections and fields.
	 */
	public static function register() {
		register_setting(
			self::OPTION_GRP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);

		add_settings_section(
			'seohc_scope',
			__( 'What to scan', 'seo-health-check' ),
			'__return_false',
			self::PAGE_SLUG
		);

		add_settings_section(
			'seohc_rules',
			__( 'Rules', 'seo-health-check' ),
			'__return_false',
			self::PAGE_SLUG
		);

		add_settings_section(
			'seohc_performance',
			__( 'Performance', 'seo-health-check' ),
			'__return_false',
			self::PAGE_SLUG
		);

		add_settings_field( 'post_types', __( 'Post types', 'seo-health-check' ), array( __CLASS__, 'field_post_types' ), self::PAGE_SLUG, 'seohc_scope' );
		add_settings_field( 'content_source', __( 'Content source', 'seo-health-check' ), array( __CLASS__, 'field_content_source' ), self::PAGE_SLUG, 'seohc_scope' );
		add_settings_field( 'theme_outputs_h1', __( 'Theme H1', 'seo-health-check' ), array( __CLASS__, 'field_theme_outputs_h1' ), self::PAGE_SLUG, 'seohc_scope' );

		add_settings_field(
			'min_words',
			__( 'Minimum word count', 'seo-health-check' ),
			array( __CLASS__, 'field_number' ),
			self::PAGE_SLUG,
			'seohc_rules',
			array(
				'key'         => 'min_words',
				'min'         => 0,
				'max'         => 10000,
				'label_for'   => 'seohc_min_words',
				'description' => __( 'Content with fewer words is reported as thin content. Use 0 to disable this check.', 'seo-health-check' ),
			)
		);
		add_settings_field(
			'max_title_length',
			__( 'Maximum title length', 'seo-health-check' ),
			array( __CLASS__, 'field_number' ),
			self::PAGE_SLUG,
			'seohc_rules',
			array(
				'key'         => 'max_title_length',
				'min'         => 10,
				'max'         => 200,
				'label_for'   => 'seohc_max_title_length',
				'description' => __( 'In characters. Google usually shows around 60.', 'seo-health-check' ),
			)
		);
		add_settings_field(
			'max_description_length',
			__( 'Maximum meta description length', 'seo-health-check' ),
			array( __CLASS__, 'field_number' ),
			self::PAGE_SLUG,
			'seohc_rules',
			array(
				'key'         => 'max_description_length',
				'min'         => 50,
				'max'         => 500,
				'label_for'   => 'seohc_max_description_length',
				'description' => __( 'In characters. Google usually shows around 155-160.', 'seo-health-check' ),
			)
		);

		add_settings_field(
			'batch_size',
			__( 'Batch size', 'seo-health-check' ),
			array( __CLASS__, 'field_number' ),
			self::PAGE_SLUG,
			'seohc_performance',
			array(
				'key'         => 'batch_size',
				'min'         => 1,
				'max'         => 200,
				'label_for'   => 'seohc_batch_size',
				'description' => __( 'Number of posts scanned per background step. Lower this on slow hosting.', 'seo-health-check' ),
			)
		);
		add_settings_field( 'rescan_on_save', __( 'Rescan on save', 'seo-health-check' ), array( __CLASS__, 'field_rescan_on_save' ), self::PAGE_SLUG, 'seohc_performance' );

		add_settings_section(
			'seohc_launch',
			__( 'Launch checks', 'seo-health-check' ),
			'__return_false',
			self::PAGE_SLUG
		);
		add_settings_field(
			'footer_credit',
			__( 'Footer credit text', 'seo-health-check' ),
			array( __CLASS__, 'field_text' ),
			self::PAGE_SLUG,
			'seohc_launch',
			array(
				'key'         => 'footer_credit',
				'label_for'   => 'seohc_footer_credit',
				'description' => __( 'Text that must appear on the homepage, for example "Website by Agency". Leave empty to skip this check.', 'seo-health-check' ),
			)
		);
		add_settings_field(
			'dev_domains',
			__( 'Development domains', 'seo-health-check' ),
			array( __CLASS__, 'field_text' ),
			self::PAGE_SLUG,
			'seohc_launch',
			array(
				'key'         => 'dev_domains',
				'label_for'   => 'seohc_dev_domains',
				'description' => __( 'Comma separated, for example "staging.example.com, example.test". After launch, content must not link to these domains anymore.', 'seo-health-check' ),
			)
		);
	}

	/**
	 * Sanitizes the submitted settings.
	 *
	 * @param mixed $input Raw input.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$defaults = self::defaults();
		$input    = is_array( $input ) ? $input : array();
		$clean    = array();

		$allowed_types       = array_keys( self::available_post_types() );
		$post_types          = isset( $input['post_types'] ) ? array_map( 'sanitize_key', (array) $input['post_types'] ) : array();
		$clean['post_types'] = array_values( array_intersect( $post_types, $allowed_types ) );
		if ( empty( $clean['post_types'] ) ) {
			add_settings_error( self::OPTION, 'seohc_no_post_types', __( 'Select at least one post type. The defaults were restored.', 'seo-health-check' ) );
			$clean['post_types'] = $defaults['post_types'];
		}

		$ranges = array(
			'min_words'              => array( 0, 10000 ),
			'max_title_length'       => array( 10, 200 ),
			'max_description_length' => array( 50, 500 ),
			'batch_size'             => array( 1, 200 ),
		);
		foreach ( $ranges as $key => $range ) {
			$value         = isset( $input[ $key ] ) ? absint( $input[ $key ] ) : $defaults[ $key ];
			$clean[ $key ] = min( max( $value, $range[0] ), $range[1] );
		}

		$source                  = isset( $input['content_source'] ) ? sanitize_key( $input['content_source'] ) : '';
		$clean['content_source'] = array_key_exists( $source, self::content_sources() ) ? $source : $defaults['content_source'];

		$clean['theme_outputs_h1'] = empty( $input['theme_outputs_h1'] ) ? 0 : 1;
		$clean['rescan_on_save']   = empty( $input['rescan_on_save'] ) ? 0 : 1;

		$clean['footer_credit'] = isset( $input['footer_credit'] ) ? sanitize_text_field( $input['footer_credit'] ) : '';

		$domains = isset( $input['dev_domains'] ) ? explode( ',', sanitize_text_field( $input['dev_domains'] ) ) : array();
		$domains = array_filter(
			array_map(
				function ( $domain ) {
					$domain = strtolower( trim( $domain ) );
					$host   = wp_parse_url( ( false === strpos( $domain, '//' ) ? 'http://' : '' ) . $domain, PHP_URL_HOST );
					return $host ? preg_replace( '/[^a-z0-9.\-]/', '', $host ) : '';
				},
				$domains
			)
		);

		$clean['dev_domains'] = implode( ', ', array_unique( $domains ) );

		return $clean;
	}

	/**
	 * Content source options.
	 *
	 * @return array<string, string>
	 */
	public static function content_sources() {
		return array(
			'auto'     => __( 'Automatic: use the rendered page when the post content is empty (page builders)', 'seo-health-check' ),
			'content'  => __( 'Post content only (fastest)', 'seo-health-check' ),
			'rendered' => __( 'Always use the rendered page (most accurate, slowest)', 'seo-health-check' ),
		);
	}

	/**
	 * Post type checkboxes.
	 */
	public static function field_post_types() {
		$selected = (array) self::get( 'post_types' );
		echo '<fieldset>';
		foreach ( self::available_post_types() as $type ) {
			printf(
				'<label><input type="checkbox" name="%1$s[post_types][]" value="%2$s" %3$s> %4$s</label><br>',
				esc_attr( self::OPTION ),
				esc_attr( $type->name ),
				checked( in_array( $type->name, $selected, true ), true, false ),
				esc_html( $type->labels->name )
			);
		}
		echo '</fieldset>';
	}

	/**
	 * Content source radios.
	 */
	public static function field_content_source() {
		$current = self::get( 'content_source' );
		echo '<fieldset>';
		foreach ( self::content_sources() as $value => $label ) {
			printf(
				'<label><input type="radio" name="%1$s[content_source]" value="%2$s" %3$s> %4$s</label><br>',
				esc_attr( self::OPTION ),
				esc_attr( $value ),
				checked( $current, $value, false ),
				esc_html( $label )
			);
		}
		echo '<p class="description">' . esc_html__( 'Page builders such as Oxygen, Breakdance and Elementor store their layout outside the normal post content. The rendered page is fetched with an HTTP request; the site header, footer and navigation are ignored.', 'seo-health-check' ) . '</p>';
		echo '</fieldset>';
	}

	/**
	 * Theme H1 checkbox.
	 */
	public static function field_theme_outputs_h1() {
		printf(
			'<label><input type="checkbox" name="%1$s[theme_outputs_h1]" value="1" %2$s> %3$s</label><p class="description">%4$s</p>',
			esc_attr( self::OPTION ),
			checked( (int) self::get( 'theme_outputs_h1' ), 1, false ),
			esc_html__( 'My theme shows the post title as an H1 above the content', 'seo-health-check' ),
			esc_html__( 'Only used when the post content is scanned. When checked, the content itself should not contain an H1.', 'seo-health-check' )
		);
	}

	/**
	 * Rescan on save checkbox.
	 */
	public static function field_rescan_on_save() {
		printf(
			'<label><input type="checkbox" name="%1$s[rescan_on_save]" value="1" %2$s> %3$s</label>',
			esc_attr( self::OPTION ),
			checked( (int) self::get( 'rescan_on_save' ), 1, false ),
			esc_html__( 'Rescan a post in the background when it is saved', 'seo-health-check' )
		);
	}

	/**
	 * Generic text field.
	 *
	 * @param array $args Field arguments.
	 */
	public static function field_text( $args ) {
		printf(
			'<input type="text" class="regular-text" id="%1$s" name="%2$s[%3$s]" value="%4$s">',
			esc_attr( $args['label_for'] ),
			esc_attr( self::OPTION ),
			esc_attr( $args['key'] ),
			esc_attr( self::get( $args['key'] ) )
		);
		if ( ! empty( $args['description'] ) ) {
			echo '<p class="description">' . esc_html( $args['description'] ) . '</p>';
		}
	}

	/**
	 * Generic number field.
	 *
	 * @param array $args Field arguments.
	 */
	public static function field_number( $args ) {
		printf(
			'<input type="number" class="small-text" id="%1$s" name="%2$s[%3$s]" value="%4$s" min="%5$d" max="%6$d">',
			esc_attr( $args['label_for'] ),
			esc_attr( self::OPTION ),
			esc_attr( $args['key'] ),
			esc_attr( self::get( $args['key'] ) ),
			(int) $args['min'],
			(int) $args['max']
		);
		if ( ! empty( $args['description'] ) ) {
			echo '<p class="description">' . esc_html( $args['description'] ) . '</p>';
		}
	}

	/**
	 * Renders the settings page.
	 */
	public static function render_page() {
		if ( ! current_user_can( SEOHC_Plugin::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'seo-health-check' ) );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'SEO Health Check settings', 'seo-health-check' ); ?></h1>
			<?php settings_errors(); ?>
			<form action="options.php" method="post">
				<?php
				settings_fields( self::OPTION_GRP );
				do_settings_sections( self::PAGE_SLUG );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}
}
