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
	 * GMT datetime the most recent scan started; issues first seen after it are new.
	 *
	 * @var string
	 */
	private $new_since;

	/**
	 * Constructor.
	 *
	 * @param array  $filters   Sanitized filters from SEOHC_Admin::current_filters().
	 * @param string $new_since GMT datetime the most recent scan started.
	 */
	public function __construct( array $filters, $new_since = '' ) {
		parent::__construct(
			array(
				'singular' => 'issue',
				'plural'   => 'issues',
				'ajax'     => false,
			)
		);
		$this->filters   = $filters;
		$this->new_since = (string) $new_since;
	}

	/**
	 * Column definitions.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'post_title' => __( 'Page', 'seo-health-check' ),
			'score'      => __( 'Score', 'seo-health-check' ),
			'issue_type' => __( 'Issue', 'seo-health-check' ),
			'details'    => __( 'Details', 'seo-health-check' ),
			'severity'   => __( 'Severity', 'seo-health-check' ),
			'post_type'  => __( 'Type', 'seo-health-check' ),
			'first_seen' => __( 'Since', 'seo-health-check' ),
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
			'first_seen' => array( 'first_seen', true ),
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

			<label class="seohc-filter-new">
				<input type="checkbox" name="seohc_new" value="1" <?php checked( ! empty( $this->filters['only_new'] ) ); ?>>
				<?php esc_html_e( 'Only new since the last scan', 'seo-health-check' ); ?>
			</label>

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
	protected function column_first_seen( $item ) {
		$date = esc_html( get_date_from_gmt( $item->first_seen, get_option( 'date_format' ) ) );

		if ( $this->is_new( $item ) ) {
			return '<span class="seohc-badge seohc-badge--new">' . esc_html__( 'New', 'seo-health-check' ) . '</span><br>' . $date;
		}
		return $date;
	}

	/**
	 * Whether the issue first appeared in the most recent scan.
	 *
	 * @param object $item Row.
	 * @return bool
	 */
	private function is_new( $item ) {
		return '' !== $this->new_since && $item->first_seen >= $this->new_since;
	}

	/**
	 * Score of the page the issue belongs to.
	 *
	 * @param object $item Row.
	 * @return string
	 */
	protected function column_score( $item ) {
		return SEOHC_Admin::score_badge( (int) $item->score );
	}

	/**
	 * Details column, with an inline editor for the fields that can be fixed here.
	 *
	 * @param object $item Row.
	 * @return string
	 */
	protected function column_details( $item ) {
		$html   = '<span class="seohc-details">' . esc_html( $item->details ) . '</span>';
		$editor = SEOHC_Inline_Edit::editor_for( $item );

		if ( ! $editor ) {
			return $html;
		}

		$id = 'seohc-inline-' . (int) $item->id;

		$html .= sprintf(
			'<div class="seohc-inline" data-issue="%1$d" data-max="%2$d">
				<button type="button" class="button-link seohc-inline__toggle" aria-expanded="false" aria-controls="%3$s">%4$s</button>
				<div class="seohc-inline__form" id="%3$s" hidden>
					<label class="screen-reader-text" for="%3$s-input">%5$s</label>
					<textarea id="%3$s-input" class="seohc-inline__input" rows="2">%6$s</textarea>
					<p class="seohc-inline__meta"><span class="seohc-inline__count"></span> %7$s</p>
					<button type="button" class="button button-primary button-small seohc-inline__save">%8$s</button>
					<button type="button" class="button button-small seohc-inline__cancel">%9$s</button>
					<span class="seohc-inline__status" role="status"></span>
				</div>
			</div>',
			(int) $item->id,
			(int) $editor['max_length'],
			esc_attr( $id ),
			/* translators: %s: field name, for example "Alt text". */
			esc_html( sprintf( __( 'Edit %s', 'seo-health-check' ), mb_strtolower( $editor['label'] ) ) ),
			esc_html( $editor['label'] ),
			esc_textarea( $editor['value'] ),
			esc_html( $editor['hint'] ),
			esc_html__( 'Save', 'seo-health-check' ),
			esc_html__( 'Cancel', 'seo-health-check' )
		);

		return $html;
	}

	/**
	 * Adds a class to rows whose issue is new, so they can be highlighted.
	 *
	 * @param object $item Row.
	 */
	public function single_row( $item ) {
		$classes = $this->is_new( $item ) ? ' class="seohc-row--new"' : '';
		echo '<tr' . $classes . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup.
		$this->single_row_columns( $item );
		echo '</tr>';
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
