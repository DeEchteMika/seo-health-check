<?php
/**
 * The per-page score overview.
 *
 * @package SEO_Health_Check
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Lists every scanned page with its score, sorted worst first.
 */
class SEOHC_Pages_List_Table extends WP_List_Table {

	/**
	 * Active filters.
	 *
	 * @var array
	 */
	private $filters;

	/**
	 * Constructor.
	 *
	 * @param array $filters Sanitized filters from SEOHC_Admin::current_page_filters().
	 */
	public function __construct( array $filters ) {
		parent::__construct(
			array(
				'singular' => 'page',
				'plural'   => 'pages',
				'ajax'     => false,
			)
		);
		$this->filters = $filters;
	}

	/**
	 * Column definitions.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'score'       => __( 'Score', 'seo-health-check' ),
			'post_title'  => __( 'Page', 'seo-health-check' ),
			'issue_count' => __( 'Issues', 'seo-health-check' ),
			'word_count'  => __( 'Words', 'seo-health-check' ),
			'post_type'   => __( 'Type', 'seo-health-check' ),
			'scanned_at'  => __( 'Scanned', 'seo-health-check' ),
		);
	}

	/**
	 * Sortable columns.
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array(
			'score'       => array( 'score', false ),
			'post_title'  => array( 'post_title', false ),
			'issue_count' => array( 'issue_count', true ),
			'word_count'  => array( 'word_count', true ),
			'post_type'   => array( 'post_type', false ),
			'scanned_at'  => array( 'scanned_at', true ),
		);
	}

	/**
	 * Loads the rows for the current page.
	 */
	public function prepare_items() {
		$per_page = $this->get_items_per_page( 'seohc_pages_per_page', 50 );
		$total    = SEOHC_Repository::count_pages( $this->filters );

		$this->items = SEOHC_Repository::get_pages(
			array_merge(
				$this->filters,
				array(
					'per_page' => $per_page,
					'page'     => $this->get_pagenum(),
				)
			)
		);

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'post_title' );

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total / $per_page ),
			)
		);
	}

	/**
	 * One view (tab) per score band.
	 *
	 * @return array
	 */
	protected function get_views() {
		$counts  = SEOHC_Repository::counts_by_band();
		$base    = remove_query_arg( array( 'band', 'paged' ) );
		$current = $this->filters['band'];

		$views = array(
			'all' => sprintf(
				'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
				esc_url( $base ),
				'' === $current ? ' class="current" aria-current="page"' : '',
				esc_html__( 'All', 'seo-health-check' ),
				esc_html( number_format_i18n( array_sum( $counts ) ) )
			),
		);

		foreach ( SEOHC_Repository::score_bands() as $slug => $band ) {
			$views[ $slug ] = sprintf(
				'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
				esc_url( add_query_arg( 'band', $slug, $base ) ),
				$current === $slug ? ' class="current" aria-current="page"' : '',
				esc_html( sprintf( '%1$s %2$d-%3$d', $band['label'], $band['min'], $band['max'] ) ),
				esc_html( number_format_i18n( isset( $counts[ $slug ] ) ? $counts[ $slug ] : 0 ) )
			);
		}

		return $views;
	}

	/**
	 * Post type dropdown.
	 *
	 * @param string $which 'top' or 'bottom'.
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		?>
		<div class="alignleft actions">
			<label class="screen-reader-text" for="seohc-pages-post-type"><?php esc_html_e( 'Filter by post type', 'seo-health-check' ); ?></label>
			<select name="seohc_post_type" id="seohc-pages-post-type">
				<option value=""><?php esc_html_e( 'All post types', 'seo-health-check' ); ?></option>
				<?php foreach ( (array) SEOHC_Settings::get( 'post_types' ) as $type ) : ?>
					<?php $object = get_post_type_object( $type ); ?>
					<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $this->filters['post_type'], $type ); ?>>
						<?php echo esc_html( $object ? $object->labels->name : $type ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<?php submit_button( __( 'Filter', 'seo-health-check' ), '', 'filter_action', false ); ?>
		</div>
		<?php
	}

	/**
	 * Score column.
	 *
	 * @param object $item Row.
	 * @return string
	 */
	protected function column_score( $item ) {
		return SEOHC_Admin::score_badge( (int) $item->score );
	}

	/**
	 * Page column with edit / view / issues actions.
	 *
	 * @param object $item Row.
	 * @return string
	 */
	protected function column_post_title( $item ) {
		$edit_link = get_edit_post_link( $item->post_id );
		$title     = '' !== trim( $item->post_title ) ? $item->post_title : __( '(no title)', 'seo-health-check' );

		$actions = array(
			'issues' => sprintf(
				'<a href="%s">%s</a>',
				esc_url(
					add_query_arg(
						array(
							'page' => SEOHC_Admin::MENU_SLUG,
							's'    => rawurlencode( $item->post_title ),
						),
						admin_url( 'admin.php' )
					)
				),
				esc_html__( 'Show issues', 'seo-health-check' )
			),
		);
		if ( $edit_link ) {
			$actions['edit'] = sprintf( '<a href="%s">%s</a>', esc_url( $edit_link ), esc_html__( 'Edit', 'seo-health-check' ) );
		}
		$actions['view'] = sprintf( '<a href="%s" target="_blank" rel="noopener">%s</a>', esc_url( get_permalink( $item->post_id ) ), esc_html__( 'View', 'seo-health-check' ) );

		$title_html = $edit_link
			? sprintf( '<a class="row-title" href="%s">%s</a>', esc_url( $edit_link ), esc_html( $title ) )
			: esc_html( $title );

		return '<strong>' . $title_html . '</strong>' . $this->row_actions( $actions );
	}

	/**
	 * Issue count column.
	 *
	 * @param object $item Row.
	 * @return string
	 */
	protected function column_issue_count( $item ) {
		return esc_html( number_format_i18n( (int) $item->issue_count ) );
	}

	/**
	 * Word count column.
	 *
	 * @param object $item Row.
	 * @return string
	 */
	protected function column_word_count( $item ) {
		return esc_html( number_format_i18n( (int) $item->word_count ) );
	}

	/**
	 * Post type column.
	 *
	 * @param object $item Row.
	 * @return string
	 */
	protected function column_post_type( $item ) {
		$object = get_post_type_object( $item->post_type );
		return esc_html( $object ? $object->labels->singular_name : $item->post_type );
	}

	/**
	 * Scan date column.
	 *
	 * @param object $item Row.
	 * @return string
	 */
	protected function column_scanned_at( $item ) {
		return esc_html( get_date_from_gmt( $item->scanned_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) );
	}

	/**
	 * Fallback column output.
	 *
	 * @param object $item        Row.
	 * @param string $column_name Column.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		return isset( $item->$column_name ) ? esc_html( $item->$column_name ) : '';
	}

	/**
	 * Message when there are no rows.
	 */
	public function no_items() {
		esc_html_e( 'No pages found. Start a scan first.', 'seo-health-check' );
	}
}
