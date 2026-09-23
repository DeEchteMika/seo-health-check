<?php
/**
 * The issues overview table.
 *
 * @package SEO_Health_Check
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Lists every issue with its page, sortable and filterable by issue type.
 */
class SEOHC_Issues_List_Table extends WP_List_Table {

	/**
	 * Active filters.
	 *
	 * @var array
	 */
	private $filters;

	/**
	 * Constructor.
	 *
	 * @param array $filters Sanitized filters from SEOHC_Admin::current_filters().
	 */
	public function __construct( array $filters ) {
		parent::__construct(
			array(
				'singular' => 'issue',
				'plural'   => 'issues',
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
			'post_title' => __( 'Page', 'seo-health-check' ),
			'issue_type' => __( 'Issue', 'seo-health-check' ),
			'details'    => __( 'Details', 'seo-health-check' ),
			'severity'   => __( 'Severity', 'seo-health-check' ),
			'post_type'  => __( 'Type', 'seo-health-check' ),
			'created_at' => __( 'Scanned', 'seo-health-check' ),
		);
	}

	/**
	 * Sortable columns.
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array(
			'post_title' => array( 'post_title', false ),
			'issue_type' => array( 'issue_type', false ),
			'severity'   => array( 'severity', false ),
			'post_type'  => array( 'post_type', false ),
			'created_at' => array( 'created_at', true ),
		);
	}

	/**
	 * Loads the rows for the current page.
	 */
	public function prepare_items() {
		$per_page = $this->get_items_per_page( 'seohc_issues_per_page', 50 );
		$total    = SEOHC_Repository::count_issues( $this->filters );

		$this->items = SEOHC_Repository::get_issues(
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
	 * One view (tab) per issue type that has results.
	 *
	 * @return array
	 */
	protected function get_views() {
		$counts  = SEOHC_Repository::counts_by_type();
		$base    = remove_query_arg( array( 'issue_type', 'paged' ) );
		$current = $this->filters['issue_type'];

		$views = array(
			'all' => sprintf(
				'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
				esc_url( $base ),
				'' === $current ? ' class="current" aria-current="page"' : '',
				esc_html__( 'All', 'seo-health-check' ),
				esc_html( number_format_i18n( array_sum( $counts ) ) )
			),
		);

		foreach ( SEOHC_Issue_Types::all() as $type => $definition ) {
			if ( empty( $counts[ $type ] ) ) {
				continue;
			}
			$views[ $type ] = sprintf(
				'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
				esc_url( add_query_arg( 'issue_type', $type, $base ) ),
				$current === $type ? ' class="current" aria-current="page"' : '',
				esc_html( $definition['label'] ),
				esc_html( number_format_i18n( $counts[ $type ] ) )
			);
		}

		return $views;
	}

	/**
	 * Post type and severity dropdowns.
	 *
	 * @param string $which 'top' or 'bottom'.
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		?>
		<div class="alignleft actions">
			<label class="screen-reader-text" for="shc-filter-post-type"><?php esc_html_e( 'Filter by post type', 'seo-health-check' ); ?></label>
			<select name="seohc_post_type" id="shc-filter-post-type">
				<option value=""><?php esc_html_e( 'All post types', 'seo-health-check' ); ?></option>
				<?php foreach ( (array) SEOHC_Settings::get( 'post_types' ) as $type ) : ?>
					<?php $object = get_post_type_object( $type ); ?>
					<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $this->filters['post_type'], $type ); ?>>
						<?php echo esc_html( $object ? $object->labels->name : $type ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<label class="screen-reader-text" for="shc-filter-severity"><?php esc_html_e( 'Filter by severity', 'seo-health-check' ); ?></label>
			<select name="severity" id="shc-filter-severity">
				<option value=""><?php esc_html_e( 'All severities', 'seo-health-check' ); ?></option>
				<option value="error" <?php selected( $this->filters['severity'], 'error' ); ?>><?php esc_html_e( 'Errors', 'seo-health-check' ); ?></option>
				<option value="warning" <?php selected( $this->filters['severity'], 'warning' ); ?>><?php esc_html_e( 'Warnings', 'seo-health-check' ); ?></option>
			</select>

			<?php submit_button( __( 'Filter', 'seo-health-check' ), '', 'filter_action', false ); ?>
		</div>
		<?php
	}

	/**
	 * Page column with edit / view / rescan actions.
	 *
	 * @param object $item Row.
	 * @return string
	 */
	protected function column_post_title( $item ) {
		$edit_link = get_edit_post_link( $item->post_id );
		$title     = '' !== trim( $item->post_title ) ? $item->post_title : __( '(no title)', 'seo-health-check' );

		$actions = array();
		if ( $edit_link ) {
			$actions['edit'] = sprintf( '<a href="%s">%s</a>', esc_url( $edit_link ), esc_html__( 'Edit', 'seo-health-check' ) );
		}
		$actions['view']   = sprintf( '<a href="%s" target="_blank" rel="noopener">%s</a>', esc_url( get_permalink( $item->post_id ) ), esc_html__( 'View', 'seo-health-check' ) );
		$actions['rescan'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url(
				wp_nonce_url(
					add_query_arg(
						array(
							'action'  => 'seohc_rescan_post',
							'post_id' => (int) $item->post_id,
						),
						admin_url( 'admin-post.php' )
					),
					'seohc_rescan_post_' . (int) $item->post_id
				)
			),
			esc_html__( 'Rescan', 'seo-health-check' )
		);

		$title_html = $edit_link
			? sprintf( '<a class="row-title" href="%s">%s</a>', esc_url( $edit_link ), esc_html( $title ) )
			: esc_html( $title );

		return '<strong>' . $title_html . '</strong>' . $this->row_actions( $actions );
	}

	/**
	 * Issue type column.
	 *
	 * @param object $item Row.
	 * @return string
	 */
	protected function column_issue_type( $item ) {
		return esc_html( SEOHC_Issue_Types::label( $item->issue_type ) );
	}

	/**
	 * Severity column.
	 *
	 * @param object $item Row.
	 * @return string
	 */
	protected function column_severity( $item ) {
		$label = 'error' === $item->severity ? __( 'Error', 'seo-health-check' ) : __( 'Warning', 'seo-health-check' );
		return sprintf( '<span class="shc-badge shc-badge--%1$s">%2$s</span>', esc_attr( $item->severity ), esc_html( $label ) );
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
	protected function column_created_at( $item ) {
		return esc_html( get_date_from_gmt( $item->created_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) );
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
		if ( 0 === SEOHC_Repository::summary()['pages'] ) {
			esc_html_e( 'No scan results yet. Start a scan to check your site.', 'seo-health-check' );
			return;
		}
		esc_html_e( 'No issues found for these filters.', 'seo-health-check' );
	}
}
