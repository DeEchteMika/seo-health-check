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
			'max_image_kb'           => 0,
			'check_orphans'          => 1,
			'content_source'         => 'auto',
			'theme_outputs_h1'       => 1,
			'batch_size'             => 20,
			'rescan_on_save'         => 1,
			'footer_credit'          => '',
			'dev_domains'            => '',
			'dkim_selector'          => '',
			'analytics_id'           => '',
			'agency_domains'         => '',
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
			'max_image_kb',
			__( 'Maximum image size', 'seo-health-check' ),
			array( __CLASS__, 'field_number' ),
			self::PAGE_SLUG,
			'seohc_rules',
			array(
				'key'         => 'max_image_kb',
				'min'         => 0,
				'max'         => 20000,
				'label_for'   => 'seohc_max_image_kb',
				'description' => __( 'In KB. Images in your uploads folder above this size are reported, 300 is a sensible start. Use 0 to disable this check, which is how it starts out: on a site with many photos it can report a lot at once.', 'seo-health-check' ),
			)
		);
		add_settings_field( 'check_orphans', __( 'Pages without links', 'seo-health-check' ), array( __CLASS__, 'field_check_orphans' ), self::PAGE_SLUG, 'seohc_rules' );

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
			'agency_domains',
			__( 'Your own email domains', 'seo-health-check' ),
			array( __CLASS__, 'field_text' ),
			self::PAGE_SLUG,
			'seohc_launch',
			array(
				'key'         => 'agency_domains',
				'label_for'   => 'seohc_agency_domains',
				'description' => __( 'Comma separated, for example "youragency.com". Forms that still mail to one of these have not been handed over to the client yet. Leave empty to skip that part of the check.', 'seo-health-check' ),
			)
		);
		add_settings_field(
			'analytics_id',
			__( 'Measurement code', 'seo-health-check' ),
			array( __CLASS__, 'field_text' ),
			self::PAGE_SLUG,
			'seohc_launch',
			array(
				'key'         => 'analytics_id',
				'label_for'   => 'seohc_analytics_id',
				'description' => __( 'The Google Analytics or Tag Manager code the site should use, for example G-ABC123DEF4. Fill in the one from the old site and the check confirms it really was carried over. Leave empty to only check that some code is present.', 'seo-health-check' ),
			)
		);
		add_settings_field(
			'dkim_selector',
			__( 'DKIM selector', 'seo-health-check' ),
			array( __CLASS__, 'field_text' ),
			self::PAGE_SLUG,
			'seohc_launch',
			array(
				'key'         => 'dkim_selector',
				'label_for'   => 'seohc_dkim_selector',
				'description' => __( 'The selector your mail server signs with, for example "default" or "google". Your mail provider knows it. Without it DKIM cannot be looked up, because the record hides behind a name only they know.', 'seo-health-check' ),
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
			'max_image_kb'           => array( 0, 20000 ),
			'batch_size'             => array( 1, 200 ),
		);
		foreach ( $ranges as $key => $range ) {
			$value         = isset( $input[ $key ] ) ? absint( $input[ $key ] ) : $defaults[ $key ];
			$clean[ $key ] = min( max( $value, $range[0] ), $range[1] );
		}

		$source                  = isset( $input['content_source'] ) ? sanitize_key( $input['content_source'] ) : '';
		$clean['content_source'] = array_key_exists( $source, self::content_sources() ) ? $source : $defaults['content_source'];

		$clean['check_orphans']    = empty( $input['check_orphans'] ) ? 0 : 1;
		$clean['theme_outputs_h1'] = empty( $input['theme_outputs_h1'] ) ? 0 : 1;
		$clean['rescan_on_save']   = empty( $input['rescan_on_save'] ) ? 0 : 1;

		$clean['footer_credit'] = isset( $input['footer_credit'] ) ? sanitize_text_field( $input['footer_credit'] ) : '';

		$domains = isset( $input['dev_domains'] ) ? explode( ',', sanitize_text_field( $input['dev_domains'] ) ) : array();
		$domains = array_filter( array_map( array( __CLASS__, 'clean_domain' ), $domains ) );

		$clean['dev_domains'] = implode( ', ', array_unique( $domains ) );

		$agency                  = isset( $input['agency_domains'] ) ? explode( ',', sanitize_text_field( $input['agency_domains'] ) ) : array();
		$agency                  = array_filter( array_map( array( __CLASS__, 'clean_domain' ), $agency ) );
		$clean['agency_domains'] = implode( ', ', array_unique( $agency ) );

		// A DKIM selector is a host label, a measurement code is letters, digits and dashes.
		$selector               = isset( $input['dkim_selector'] ) ? sanitize_text_field( $input['dkim_selector'] ) : '';
		$clean['dkim_selector'] = trim( preg_replace( '/[^A-Za-z0-9._-]/', '', $selector ), '.' );
		$analytics              = isset( $input['analytics_id'] ) ? sanitize_text_field( $input['analytics_id'] ) : '';
		$clean['analytics_id']  = strtoupper( preg_replace( '/[^A-Za-z0-9-]/', '', $analytics ) );

		return $clean;
	}

	/**
	 * Orphan page checkbox.
	 */
	public static function field_check_orphans() {
		printf(
			'<label><input type="checkbox" name="%1$s[check_orphans]" value="1" %2$s> %3$s</label><p class="description">%4$s</p>',
			esc_attr( self::OPTION ),
			checked( (int) self::get( 'check_orphans' ), 1, false ),
			esc_html__( 'Report pages that nothing links to', 'seo-health-check' ),
			esc_html__( 'Counts links from the content of other pages and from your menus. Links in a sidebar, a footer or a widget are not scanned, so a page only reachable from there is reported as well. Blog posts that are only listed on an archive page count as unlinked too.', 'seo-health-check' )
		);
	}

	/**
	 * Reduces a domain the user typed to a bare hostname.
	 *
	 * @param string $domain Whatever was typed.
	 * @return string Empty when it is not a hostname.
	 */
	public static function clean_domain( $domain ) {
		$domain = strtolower( trim( (string) $domain ) );
		if ( '' === $domain ) {
			return '';
		}

		$host = wp_parse_url( ( false === strpos( $domain, '//' ) ? 'http://' : '' ) . $domain, PHP_URL_HOST );

		return $host ? preg_replace( '/[^a-z0-9.\-]/', '', $host ) : '';
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

			<?php self::render_about(); ?>
		</div>
		<?php
	}

	/**
	 * Which version is running, and what it found to work with.
	 *
	 * WordPress only shows the version on the Plugins screen, which is not where you are when
	 * you are working in the plugin. Since 0.10.0 the Plugins screen does announce a new
	 * release, so the link below is for reading what changed rather than for finding out.
	 */
	private static function render_about() {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$data = get_plugin_data( SEOHC_FILE, false, false );
		$rows = array(
			array( __( 'Version', 'seo-health-check' ), SEOHC_VERSION ),
			array( __( 'Database version', 'seo-health-check' ), SEOHC_DB_VERSION ),
			array( __( 'Titles and descriptions from', 'seo-health-check' ), SEOHC_SEO_Meta::provider_label() ),
			array(
				__( 'Background work through', 'seo-health-check' ),
				SEOHC_Scan_Queue::use_action_scheduler()
					? __( 'Action Scheduler', 'seo-health-check' )
					: __( 'WP-Cron', 'seo-health-check' ),
			),
		);
		?>
		<h2><?php esc_html_e( 'About this plugin', 'seo-health-check' ); ?></h2>
		<table class="seohc-about">
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html( $row[0] ); ?></th>
						<td><?php echo esc_html( $row[1] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( ! empty( $data['PluginURI'] ) ) : ?>
			<p class="description">
				<a href="<?php echo esc_url( $data['PluginURI'] ); ?>" target="_blank" rel="noopener">
					<?php esc_html_e( 'The project page, where you can read what changed per version', 'seo-health-check' ); ?>
				</a>
			</p>
		<?php endif; ?>
		<?php
	}
}
