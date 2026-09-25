<?php
/**
 * Site-wide checks from a typical go-live checklist.
 *
 * @package SEO_Health_Check
 */

defined( 'ABSPATH' ) || exit;

/**
 * Launch checklist: site settings that are checked once for the whole site instead of per page.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
 */
class SEOHC_Launch_Checks {

	const RESULTS_KEY = 'seohc_launch_results';

	const PASS    = 'pass';
	const WARNING = 'warning';
	const FAIL    = 'fail';
	const INFO    = 'info';

	/**
	 * Homepage HTML, fetched once per run.
	 *
	 * @var string|WP_Error|null
	 */
	private static $homepage = null;

	/**
	 * Registers hooks.
	 */
	public static function init() {
		add_action( 'admin_post_seohc_run_launch_checks', array( __CLASS__, 'handle_run' ) );
	}

	/**
	 * Plugins every site should have, keyed by plugin folder.
	 *
	 * @return array<string, string>
	 */
	public static function required_plugins() {
		/**
		 * Filters the plugins the launch check expects to be active.
		 *
		 * @param array $plugins Plugin folder => name.
		 */
		return apply_filters(
			'seo_health_check_required_plugins',
			array(
				'wp-mail-smtp'                        => 'WP Mail SMTP',
				'duplicate-post'                      => 'Yoast Duplicate Post',
				'stops-core-theme-and-plugin-updates' => 'Easy Updates Manager',
				'redirection'                         => 'Redirection',
			)
		);
	}

	/**
	 * Known cookie consent plugins, keyed by plugin folder.
	 *
	 * @return string[]
	 */
	public static function cookie_plugins() {
		return apply_filters(
			'seo_health_check_cookie_plugins',
			array( 'cookie-notice', 'complianz-gdpr', 'complianz-gdpr-premium', 'cookie-law-info', 'cookiebot', 'real-cookie-banner', 'gdpr-cookie-compliance', 'borlabs-cookie', 'cookieyes' )
		);
	}

	/**
	 * Checklist phases.
	 *
	 * @return array<string, string>
	 */
	public static function phases() {
		return array(
			'before' => __( 'Before launch', 'seo-health-check' ),
			'launch' => __( 'At launch', 'seo-health-check' ),
			'after'  => __( 'After launch', 'seo-health-check' ),
		);
	}

	/**
	 * Runs every check.
	 *
	 * @return array List of results with id, phase, label, status and message.
	 */
	public static function run() {
		self::$homepage = null;

		$checks = array(
			array( 'before', 'homepage_title', __( 'No "Home" in the homepage title', 'seo-health-check' ) ),
			array( 'before', 'permalinks', __( 'URL structure', 'seo-health-check' ) ),
			array( 'before', 'favicon', __( 'Favicon', 'seo-health-check' ) ),
			array( 'before', 'not_found', __( '404 page', 'seo-health-check' ) ),
			array( 'before', 'homepage_image', __( 'Featured image on the homepage', 'seo-health-check' ) ),
			array( 'before', 'footer_credit', __( 'Footer credit', 'seo-health-check' ) ),
			array( 'before', 'required_plugins', __( 'Standard plugins installed', 'seo-health-check' ) ),
			array( 'before', 'updates', __( 'WordPress, plugins and themes up to date', 'seo-health-check' ) ),
			array( 'before', 'menu_unpublished', __( 'Menus point at published pages', 'seo-health-check' ) ),
			array( 'before', 'mail_delivery', __( 'Mail delivery set up', 'seo-health-check' ) ),
			array( 'before', 'form_recipients', __( 'Forms mail to the client', 'seo-health-check' ) ),
			array( 'before', 'form_confirmations', __( 'Thank you pages for forms', 'seo-health-check' ) ),
			array( 'before', 'form_spam', __( 'Spam protection on forms', 'seo-health-check' ) ),
			array( 'launch', 'https', __( 'HTTPS', 'seo-health-check' ) ),
			array( 'launch', 'dev_links', __( 'No links to the development site', 'seo-health-check' ) ),
			array( 'launch', 'analytics', __( 'Google Analytics / Tag Manager', 'seo-health-check' ) ),
			array( 'launch', 'cookie_notice', __( 'Cookie notice', 'seo-health-check' ) ),
			array( 'after', 'indexing', __( 'Search engines may index the site', 'seo-health-check' ) ),
			array( 'after', 'sitemap', __( 'XML sitemap', 'seo-health-check' ) ),
			array( 'after', 'redirects', __( 'WWW / non-WWW and HTTP / HTTPS redirects', 'seo-health-check' ) ),
			array( 'after', 'inactive_plugins', __( 'No inactive plugins', 'seo-health-check' ) ),
			array( 'after', 'search_console', __( 'Search Console verification', 'seo-health-check' ) ),
			array( 'after', 'mail_records', __( 'SPF and DMARC', 'seo-health-check' ) ),
			array( 'after', 'default_admin', __( 'Default "admin" login', 'seo-health-check' ) ),
		);

		$results = array();
		foreach ( $checks as $check ) {
			list( $phase, $id, $label ) = $check;
			try {
				list( $status, $message ) = call_user_func( array( __CLASS__, 'check_' . $id ) );
			} catch ( Throwable $e ) {
				$status  = self::WARNING;
				$message = __( 'This check could not be completed.', 'seo-health-check' );
			}
			$results[] = compact( 'id', 'phase', 'label', 'status', 'message' );
		}

		/**
		 * Filters the launch check results.
		 *
		 * @param array $results Results.
		 */
		return apply_filters( 'seo_health_check_launch_results', $results );
	}

	/**
	 * Handles the "Run checks" button.
	 */
	public static function handle_run() {
		if ( ! current_user_can( SEOHC_Plugin::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'seo-health-check' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'seohc_run_launch_checks' );

		update_option(
			self::RESULTS_KEY,
			array(
				'time'    => time(),
				'results' => self::run(),
			),
			false
		);

		wp_safe_redirect( admin_url( 'admin.php?page=seo-health-check-launch' ) );
		exit;
	}

	/**
	 * Renders the launch checks page.
	 */
	public static function render_page() {
		if ( ! current_user_can( SEOHC_Plugin::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'seo-health-check' ) );
		}

		$stored  = get_option( self::RESULTS_KEY, array() );
		$results = isset( $stored['results'] ) ? (array) $stored['results'] : array();
		$labels  = array(
			self::PASS    => __( 'OK', 'seo-health-check' ),
			self::WARNING => __( 'Check', 'seo-health-check' ),
			self::FAIL    => __( 'Action needed', 'seo-health-check' ),
			self::INFO    => __( 'Info', 'seo-health-check' ),
		);
		?>
		<div class="wrap shc-wrap">
			<h1><?php esc_html_e( 'Launch checks', 'seo-health-check' ); ?></h1>
			<p><?php esc_html_e( 'Site-wide settings from a typical go-live checklist. Manual tasks such as testing forms or informing the client are not included.', 'seo-health-check' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="shc-panel">
				<input type="hidden" name="action" value="seohc_run_launch_checks">
				<?php wp_nonce_field( 'seohc_run_launch_checks' ); ?>
				<?php if ( ! empty( $stored['time'] ) ) : ?>
					<p>
						<?php
						/* translators: %s: date and time. */
						echo esc_html( sprintf( __( 'Last run: %s', 'seo-health-check' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $stored['time'] ) ) );
						?>
					</p>
				<?php endif; ?>
				<?php submit_button( $results ? __( 'Run checks again', 'seo-health-check' ) : __( 'Run checks', 'seo-health-check' ), 'primary', 'submit', false ); ?>
				<p class="description"><?php esc_html_e( 'This makes a few requests to your own site and can take up to half a minute.', 'seo-health-check' ); ?></p>
			</form>

			<?php foreach ( self::phases() as $phase => $phase_label ) : ?>
				<?php
				$rows = array_filter(
					$results,
					function ( $result ) use ( $phase ) {
						return $result['phase'] === $phase;
					}
				);
				if ( ! $rows ) {
					continue;
				}
				?>
				<h2><?php echo esc_html( $phase_label ); ?></h2>
				<table class="widefat striped shc-launch-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Check', 'seo-health-check' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Status', 'seo-health-check' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Details', 'seo-health-check' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $row ) : ?>
							<tr>
								<td><strong><?php echo esc_html( $row['label'] ); ?></strong></td>
								<td><span class="shc-badge shc-badge--<?php echo esc_attr( $row['status'] ); ?>"><?php echo esc_html( isset( $labels[ $row['status'] ] ) ? $labels[ $row['status'] ] : $row['status'] ); ?></span></td>
								<td><?php echo esc_html( $row['message'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/*
	 * ------------------------------------------------------------------
	 * Individual checks. Each returns array( status, message ).
	 * ------------------------------------------------------------------
	 */

	/**
	 * The homepage SEO title should not contain the word "Home".
	 *
	 * @return array
	 */
	private static function check_homepage_title() {
		$front_id = (int) get_option( 'page_on_front' );
		if ( 'page' !== get_option( 'show_on_front' ) || ! $front_id || ! get_post( $front_id ) ) {
			return array( self::INFO, __( 'The homepage shows the latest posts; check its title in your SEO plugin.', 'seo-health-check' ) );
		}

		$title = SEOHC_SEO_Meta::get( get_post( $front_id ) )['title'];
		if ( preg_match( '/\bhome\b/i', $title ) ) {
			/* translators: %s: the homepage title. */
			return array( self::FAIL, sprintf( __( 'The title is "%s". Replace "Home" with a descriptive title.', 'seo-health-check' ), $title ) );
		}
		/* translators: %s: the homepage title. */
		return array( self::PASS, sprintf( __( 'The title is "%s".', 'seo-health-check' ), $title ) );
	}

	/**
	 * Permalinks should not be the plain ?p=123 structure.
	 *
	 * @return array
	 */
	private static function check_permalinks() {
		$structure = (string) get_option( 'permalink_structure' );
		if ( '' === $structure ) {
			return array( self::FAIL, __( 'Plain permalinks (?p=123) are used. Choose "Post name" under Settings > Permalinks.', 'seo-health-check' ) );
		}
		/* translators: %s: permalink structure. */
		return array( self::PASS, sprintf( __( 'Permalink structure: %s', 'seo-health-check' ), $structure ) );
	}

	/**
	 * A site icon is set in WordPress or in the theme.
	 *
	 * @return array
	 */
	private static function check_favicon() {
		if ( has_site_icon() ) {
			return array( self::PASS, __( 'A site icon is set in WordPress.', 'seo-health-check' ) );
		}
		$html = self::homepage_html();
		if ( ! is_wp_error( $html ) && preg_match( '/<link[^>]+rel=["\'](?:shortcut )?icon["\']/i', $html ) ) {
			return array( self::PASS, __( 'The theme outputs a favicon.', 'seo-health-check' ) );
		}
		return array( self::FAIL, __( 'No favicon found. Set one under Appearance > Customize > Site Identity.', 'seo-health-check' ) );
	}

	/**
	 * Unknown URLs must return a real 404 status.
	 *
	 * @return array
	 */
	private static function check_not_found() {
		$response = wp_remote_get(
			home_url( '/seohc-check-' . strtolower( wp_generate_password( 12, false ) ) . '/' ),
			array_merge( self::request_args(), array( 'redirection' => 0 ) )
		);
		if ( is_wp_error( $response ) ) {
			return array( self::WARNING, $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 404 === $code ) {
			return array( self::PASS, __( 'Unknown URLs return status 404. Check the page design yourself.', 'seo-health-check' ) );
		}
		if ( $code >= 300 && $code < 400 ) {
			/* translators: 1: HTTP status code, 2: redirect target. */
			return array( self::WARNING, sprintf( __( 'Unknown URLs redirect (%1$d) to %2$s instead of showing a 404 page.', 'seo-health-check' ), $code, wp_remote_retrieve_header( $response, 'location' ) ) );
		}
		/* translators: %d: HTTP status code. */
		return array( self::FAIL, sprintf( __( 'Unknown URLs return status %d instead of 404, so search engines may index error pages.', 'seo-health-check' ), $code ) );
	}

	/**
	 * The homepage has a featured image (used when shared on social media).
	 *
	 * @return array
	 */
	private static function check_homepage_image() {
		$front_id = (int) get_option( 'page_on_front' );
		if ( 'page' !== get_option( 'show_on_front' ) || ! $front_id ) {
			return array( self::INFO, __( 'The homepage is not a static page.', 'seo-health-check' ) );
		}
		if ( has_post_thumbnail( $front_id ) ) {
			return array( self::PASS, __( 'The homepage has a featured image.', 'seo-health-check' ) );
		}
		return array( self::FAIL, __( 'The homepage has no featured image, so social media shares show no image.', 'seo-health-check' ) );
	}

	/**
	 * The configured credit text appears on the homepage.
	 *
	 * @return array
	 */
	private static function check_footer_credit() {
		$credit = trim( (string) SEOHC_Settings::get( 'footer_credit' ) );
		if ( '' === $credit ) {
			return array( self::INFO, __( 'No credit text configured in the settings.', 'seo-health-check' ) );
		}
		$html = self::homepage_html();
		if ( is_wp_error( $html ) ) {
			return array( self::WARNING, $html->get_error_message() );
		}
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' );
		if ( false !== mb_stripos( $text, $credit ) ) {
			/* translators: %s: credit text. */
			return array( self::PASS, sprintf( __( '"%s" was found on the homepage.', 'seo-health-check' ), $credit ) );
		}
		/* translators: %s: credit text. */
		return array( self::FAIL, sprintf( __( '"%s" was not found on the homepage.', 'seo-health-check' ), $credit ) );
	}

	/**
	 * The standard plugins are active.
	 *
	 * @return array
	 */
	private static function check_required_plugins() {
		$active  = self::active_plugin_folders();
		$missing = array();
		foreach ( self::required_plugins() as $folder => $name ) {
			if ( ! in_array( $folder, $active, true ) ) {
				$missing[] = $name;
			}
		}
		if ( $missing ) {
			/* translators: %s: comma separated plugin names. */
			return array( self::FAIL, sprintf( __( 'Not active: %s. Also check that they are configured.', 'seo-health-check' ), implode( ', ', $missing ) ) );
		}
		return array( self::PASS, __( 'All standard plugins are active. Check that they are configured.', 'seo-health-check' ) );
	}

	/**
	 * Core, plugins and themes have no pending updates.
	 *
	 * @return array
	 */
	private static function check_updates() {
		require_once ABSPATH . 'wp-admin/includes/update.php';
		wp_update_plugins();
		wp_update_themes();

		$counts = wp_get_update_data()['counts'];
		$total  = (int) $counts['wordpress'] + (int) $counts['plugins'] + (int) $counts['themes'];
		if ( 0 === $total ) {
			return array( self::PASS, __( 'Everything is up to date.', 'seo-health-check' ) );
		}
		return array(
			self::FAIL,
			/* translators: 1: core updates, 2: plugin updates, 3: theme updates. */
			sprintf( __( 'Updates available: WordPress %1$d, plugins %2$d, themes %3$d.', 'seo-health-check' ), $counts['wordpress'], $counts['plugins'], $counts['themes'] ),
		);
	}

	/**
	 * The site runs on HTTPS.
	 *
	 * @return array
	 */
	private static function check_https() {
		if ( 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME ) && 'https' === wp_parse_url( site_url(), PHP_URL_SCHEME ) ) {
			return array( self::PASS, __( 'The site address uses HTTPS.', 'seo-health-check' ) );
		}
		return array( self::FAIL, __( 'The site address does not use HTTPS. Change it under Settings > General after installing a certificate.', 'seo-health-check' ) );
	}

	/**
	 * Content no longer links to the development domains.
	 *
	 * @return array
	 */
	private static function check_dev_links() {
		global $wpdb;

		$domains = array_filter( array_map( 'trim', explode( ',', (string) SEOHC_Settings::get( 'dev_domains' ) ) ) );
		if ( ! $domains ) {
			return array( self::INFO, __( 'No development domains configured in the settings.', 'seo-health-check' ) );
		}

		$home_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$found     = array();
		foreach ( $domains as $domain ) {
			if ( $home_host === $domain || str_ends_with( $home_host, '.' . $domain ) ) {
				/* translators: %s: domain. */
				return array( self::INFO, sprintf( __( 'This site itself runs on %s, so this check only makes sense on the live site.', 'seo-health-check' ), $domain ) );
			}

			$like  = '%' . $wpdb->esc_like( $domain ) . '%';
			$posts = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status IN ('publish', 'private', 'draft') AND post_type <> 'revision' AND post_content LIKE %s", $like ) );
			$meta  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_value LIKE %s", $like ) );
			if ( $posts + $meta > 0 ) {
				/* translators: 1: domain, 2: number of posts, 3: number of meta fields. */
				$found[] = sprintf( __( '%1$s (%2$d posts, %3$d fields)', 'seo-health-check' ), $domain, $posts, $meta );
			}
		}

		if ( $found ) {
			/* translators: %s: list of domains with counts. */
			return array( self::FAIL, sprintf( __( 'Content still refers to: %s. Use a search-replace plugin to update the URLs.', 'seo-health-check' ), implode( '; ', $found ) ) );
		}
		return array( self::PASS, __( 'No references to the development domains found.', 'seo-health-check' ) );
	}

	/**
	 * Google Analytics or Tag Manager code is present on the homepage.
	 *
	 * @return array
	 */
	private static function check_analytics() {
		$html = self::homepage_html();
		if ( is_wp_error( $html ) ) {
			return array( self::WARNING, $html->get_error_message() );
		}

		$expected = (string) SEOHC_Settings::get( 'analytics_id' );
		$found    = preg_match( '#G-[A-Z0-9]{6,}|GTM-[A-Z0-9]{4,}|UA-\d{4,}-\d+#', $html, $match ) ? $match[0] : '';

		// With a code filled in, "there is some code" is not good enough: carrying the wrong
		// one over from the old site looks fine and quietly measures nothing.
		if ( '' !== $expected ) {
			if ( false !== stripos( $html, $expected ) ) {
				/* translators: %s: measurement code such as G-ABC123DEF4. */
				return array( self::PASS, sprintf( __( 'The measurement code %s is on the homepage.', 'seo-health-check' ), $expected ) );
			}

			if ( '' !== $found ) {
				return array(
					self::FAIL,
					sprintf(
						/* translators: 1: expected measurement code, 2: the code that was found. */
						__( 'The homepage uses %2$s, not %1$s. The wrong measurement code was carried over.', 'seo-health-check' ),
						$expected,
						$found
					),
				);
			}

			/* translators: %s: measurement code. */
			return array( self::FAIL, sprintf( __( 'The measurement code %s was not found on the homepage.', 'seo-health-check' ), $expected ) );
		}

		if ( '' !== $found || preg_match( '#googletagmanager\.com/(gtag/js|gtm\.js)#', $html ) ) {
			return array( self::PASS, __( 'Google Analytics or Tag Manager code was found. Fill in the measurement code under Settings to check that it is the right one.', 'seo-health-check' ) );
		}

		return array( self::INFO, __( 'No Google Analytics or Tag Manager code found. Only needed when the client uses it.', 'seo-health-check' ) );
	}

	/**
	 * A cookie consent plugin is active.
	 *
	 * @return array
	 */
	private static function check_cookie_notice() {
		$active = array_intersect( self::cookie_plugins(), self::active_plugin_folders() );
		if ( $active ) {
			/* translators: %s: plugin folder names. */
			return array( self::PASS, sprintf( __( 'Cookie plugin active: %s', 'seo-health-check' ), implode( ', ', $active ) ) );
		}
		return array( self::WARNING, __( 'No known cookie consent plugin is active. Check whether the site uses another solution.', 'seo-health-check' ) );
	}

	/**
	 * "Discourage search engines" is off.
	 *
	 * @return array
	 */
	private static function check_indexing() {
		if ( '0' === (string) get_option( 'blog_public' ) ) {
			return array( self::FAIL, __( '"Discourage search engines from indexing this site" is on (Settings > Reading). Correct on a staging site, but turn it off after launch.', 'seo-health-check' ) );
		}
		return array( self::PASS, __( 'Search engines are allowed to index the site.', 'seo-health-check' ) );
	}

	/**
	 * The XML sitemap responds.
	 *
	 * @return array
	 */
	private static function check_sitemap() {
		$path     = 'none' === SEOHC_SEO_Meta::provider() ? '/wp-sitemap.xml' : '/sitemap_index.xml';
		$url      = home_url( $path );
		$response = wp_remote_get( $url, self::request_args() );
		if ( is_wp_error( $response ) ) {
			return array( self::WARNING, $response->get_error_message() );
		}
		if ( 200 === (int) wp_remote_retrieve_response_code( $response ) && false !== stripos( wp_remote_retrieve_body( $response ), '<sitemap' ) ) {
			/* translators: %s: sitemap URL. */
			return array( self::PASS, sprintf( __( 'Sitemap found at %s. Submit it in Google Search Console.', 'seo-health-check' ), $url ) );
		}
		/* translators: %s: sitemap URL. */
		return array( self::FAIL, sprintf( __( 'No sitemap found at %s. Enable it in your SEO plugin.', 'seo-health-check' ), $url ) );
	}

	/**
	 * All address variants end up at the site address.
	 *
	 * @return array
	 */
	private static function check_redirects() {
		$canonical = trailingslashit( home_url() );
		$host      = (string) wp_parse_url( $canonical, PHP_URL_HOST );
		$port      = wp_parse_url( $canonical, PHP_URL_PORT );
		$port      = $port ? ':' . $port : '';
		$bare      = preg_replace( '/^www\./i', '', $host );
		$hosts     = array( $bare );

		// Only test www. for main domains (example.com), not for subdomains such as staging.example.com.
		if ( 0 === stripos( $host, 'www.' ) || 1 === substr_count( $bare, '.' ) ) {
			$hosts[] = 'www.' . $bare;
		}

		$problems = array();
		foreach ( $hosts as $variant_host ) {
			foreach ( array( 'http', 'https' ) as $scheme ) {
				$variant = $scheme . '://' . $variant_host . $port . '/';
				if ( $variant === $canonical ) {
					continue;
				}
				$response = wp_remote_get( $variant, array_merge( self::request_args(), array( 'redirection' => 5 ) ) );
				if ( is_wp_error( $response ) ) {
					/* translators: %s: URL. */
					$problems[] = sprintf( __( '%s is not reachable', 'seo-health-check' ), $variant );
					continue;
				}
				$final = self::final_url( $response, $variant );
				if ( untrailingslashit( $final ) !== untrailingslashit( $canonical ) ) {
					/* translators: 1: URL, 2: final URL. */
					$problems[] = sprintf( __( '%1$s ends at %2$s', 'seo-health-check' ), $variant, $final );
				}
			}
		}

		if ( $problems ) {
			return array( self::FAIL, implode( '; ', $problems ) . '.' );
		}
		/* translators: %s: site address. */
		return array( self::PASS, sprintf( __( 'All variants redirect to %s.', 'seo-health-check' ), $canonical ) );
	}

	/**
	 * No deactivated plugins are left behind.
	 *
	 * @return array
	 */
	private static function check_inactive_plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$inactive = array();
		foreach ( get_plugins() as $file => $data ) {
			if ( ! is_plugin_active( $file ) ) {
				$inactive[] = $data['Name'];
			}
		}
		if ( $inactive ) {
			/* translators: %s: plugin names. */
			return array( self::WARNING, sprintf( __( 'Inactive plugins: %s. Remove the ones you do not need.', 'seo-health-check' ), implode( ', ', $inactive ) ) );
		}
		return array( self::PASS, __( 'There are no inactive plugins.', 'seo-health-check' ) );
	}

	/**
	 * Menu items that point at something unpublished, and pages still waiting for content.
	 *
	 * Covers two lines of the checklist at once: pages that will be filled in later belong on
	 * private and out of the menu, and after launch someone has to remember they are there.
	 *
	 * @return array
	 */
	private static function check_menu_unpublished() {
		$broken = array();

		foreach ( (array) wp_get_nav_menus() as $menu ) {
			foreach ( (array) wp_get_nav_menu_items( $menu->term_id ) as $item ) {
				if ( 'post_type' !== $item->type ) {
					continue;
				}

				$status = get_post_status( $item->object_id );
				if ( ! $status || 'publish' === $status ) {
					continue;
				}

				$object   = get_post_status_object( $status );
				$broken[] = sprintf(
					/* translators: 1: page title, 2: menu name, 3: status such as draft or private. */
					__( '%1$s (in "%2$s", %3$s)', 'seo-health-check' ),
					$item->title,
					$menu->name,
					$object ? $object->label : $status
				);
			}
		}

		if ( $broken ) {
			return array(
				self::FAIL,
				sprintf(
					/* translators: %s: list of menu items. */
					__( 'These menu items do not lead to a published page, so visitors end up on an error page: %s.', 'seo-health-check' ),
					implode( '; ', $broken )
				),
			);
		}

		$waiting = get_posts(
			array(
				'post_type'      => (array) SEOHC_Settings::get( 'post_types' ),
				'post_status'    => array( 'private', 'draft', 'pending' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		if ( $waiting ) {
			return array(
				self::INFO,
				sprintf(
					/* translators: %d: number of pages. */
					_n(
						'%d page is not published yet and is correctly kept out of the menus. Remember that it still needs its text.',
						'%d pages are not published yet and are correctly kept out of the menus. Remember that they still need their text.',
						count( $waiting ),
						'seo-health-check'
					),
					count( $waiting )
				),
			);
		}

		return array( self::PASS, __( 'Every menu item leads to a published page.', 'seo-health-check' ) );
	}

	/**
	 * The Google Search Console verification tag.
	 *
	 * Only the meta tag can be seen from here. Verifying through DNS or an uploaded HTML file
	 * works just as well and leaves no trace on the page, so a missing tag is not a failure.
	 *
	 * @return array
	 */
	private static function check_search_console() {
		$html = self::homepage_html();
		if ( is_wp_error( $html ) ) {
			return array( self::WARNING, $html->get_error_message() );
		}

		if ( preg_match( '#<meta[^>]+name=[\'"]google-site-verification[\'"]#i', $html ) ) {
			return array( self::PASS, __( 'The verification tag for Google Search Console is on the homepage. Check in Search Console itself that the site is really added.', 'seo-health-check' ) );
		}

		return array( self::INFO, __( 'No verification tag for Google Search Console found. If you verified the site through DNS or an HTML file, this check cannot see that.', 'seo-health-check' ) );
	}

	/**
	 * SPF and DMARC records in DNS.
	 *
	 * Without them, mail from this domain lands in the spam folder, which includes the report
	 * this plugin sends after a scheduled scan. DKIM needs the selector the mail server uses,
	 * which cannot be worked out from here, so that one stays a manual check.
	 *
	 * @return array
	 */
	private static function check_mail_records() {
		if ( ! function_exists( 'dns_get_record' ) ) {
			return array( self::INFO, __( 'This server does not allow DNS lookups, so the mail records cannot be checked from here.', 'seo-health-check' ) );
		}

		$host = preg_replace( '/^www\./i', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( '' === $host ) {
			return array( self::WARNING, __( 'The domain of the site could not be determined.', 'seo-health-check' ) );
		}

		$records = array(
			'SPF'   => self::has_txt_record( $host, 'v=spf1' ),
			'DMARC' => self::has_txt_record( '_dmarc.' . $host, 'v=DMARC1' ),
		);

		// DKIM lives behind a name only the mail provider knows, so it is checked only once
		// that selector has been filled in.
		$selector = (string) SEOHC_Settings::get( 'dkim_selector' );
		if ( '' !== $selector ) {
			$records['DKIM'] = self::has_txt_record( $selector . '._domainkey.' . $host, 'v=DKIM1' );
		}

		$missing = array_keys(
			array_filter(
				$records,
				static function ( $present ) {
					return ! $present;
				}
			)
		);

		$note = '' === $selector
			? ' ' . __( 'DKIM is not checked: fill in the selector of your mail server under Settings.', 'seo-health-check' )
			: '';

		if ( empty( $missing ) ) {
			return array(
				self::PASS,
				sprintf(
					/* translators: 1: record names such as "SPF, DMARC", 2: domain name. */
					__( '%1$s are set for %2$s.', 'seo-health-check' ),
					implode( ', ', array_keys( $records ) ),
					$host
				) . $note,
			);
		}

		return array(
			self::WARNING,
			sprintf(
				/* translators: 1: missing record names, 2: domain name. */
				__( 'No %1$s record found for %2$s. Mail from this domain, including the scan report, is likely to end up in the spam folder.', 'seo-health-check' ),
				implode( ' and no ', $missing ),
				$host
			) . $note,
		);
	}

	/**
	 * Whether a hostname has a TXT record starting with the given marker.
	 *
	 * @param string $host   Hostname to look up.
	 * @param string $marker Start of the record, for example v=spf1.
	 * @return bool
	 */
	private static function has_txt_record( $host, $marker ) {
		// A lookup for a name that does not exist raises a warning; the empty result is the answer.
		$records = @dns_get_record( $host, DNS_TXT ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a missing record is a normal outcome here, not an error to report.

		foreach ( (array) $records as $record ) {
			if ( isset( $record['txt'] ) && 0 === stripos( (string) $record['txt'], $marker ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Plugins that keep spam out of forms, keyed by plugin folder.
	 *
	 * @return array<string, string>
	 */
	public static function antispam_plugins() {
		return apply_filters(
			'seo_health_check_antispam_plugins',
			array(
				'akismet'                      => 'Akismet',
				'antispam-bee'                 => 'Antispam Bee',
				'cleantalk-spam-protect'       => 'CleanTalk',
				'google-captcha'               => 'reCaptcha by BestWebSoft',
				'advanced-nocaptcha-recaptcha' => 'Advanced noCaptcha & Invisible Captcha',
				'simple-cloudflare-turnstile'  => 'Simple Cloudflare Turnstile',
				'hcaptcha-for-forms-and-more'  => 'hCaptcha',
				'wp-armour'                    => 'WP Armour Honeypot',
				'contact-form-7-honeypot'      => 'Honeypot for Contact Form 7',
			)
		);
	}

	/**
	 * The email domains of whoever built the site, from the settings.
	 *
	 * @return string[]
	 */
	private static function agency_domains() {
		$raw = array_map( 'trim', explode( ',', (string) SEOHC_Settings::get( 'agency_domains' ) ) );

		return array_values( array_filter( array_map( 'strtolower', $raw ) ) );
	}

	/**
	 * Where a form check should stop before it starts.
	 *
	 * @return array|null A result when there is nothing to check, null when there is.
	 */
	private static function forms_or_reason() {
		if ( ! SEOHC_Forms::has_plugin() ) {
			return array( self::INFO, __( 'No Contact Form 7, WPForms or Gravity Forms found, so there is nothing to check here.', 'seo-health-check' ) );
		}

		if ( ! SEOHC_Forms::all() ) {
			return array( self::WARNING, __( 'A form plugin is active but no forms were made yet.', 'seo-health-check' ) );
		}

		return null;
	}

	/**
	 * Whether the forms still mail to us instead of to the client.
	 *
	 * @return array
	 */
	private static function check_form_recipients() {
		$stop = self::forms_or_reason();
		if ( $stop ) {
			return $stop;
		}

		$forms    = SEOHC_Forms::all();
		$agency   = self::agency_domains();
		$problems = array();

		foreach ( $forms as $form ) {
			$title = '' !== trim( $form['title'] ) ? $form['title'] : '#' . $form['id'];

			if ( empty( $form['recipients'] ) ) {
				/* translators: %s: form name. */
				$problems[] = sprintf( __( '%s has no recipient at all', 'seo-health-check' ), $title );
				continue;
			}

			foreach ( $form['recipients'] as $recipient ) {
				if ( SEOHC_Forms::is_admin_placeholder( $recipient ) ) {
					/* translators: %s: form name. */
					$problems[] = sprintf( __( '%s still mails to the WordPress administrator', 'seo-health-check' ), $title );
					continue;
				}

				$domain = SEOHC_Forms::domain_of( $recipient );
				if ( '' !== $domain && in_array( $domain, $agency, true ) ) {
					$problems[] = sprintf(
						/* translators: 1: form name, 2: email address. */
						__( '%1$s mails to %2$s', 'seo-health-check' ),
						$title,
						$recipient
					);
				}
			}
		}

		if ( $problems ) {
			return array(
				self::FAIL,
				sprintf(
					/* translators: %s: list of forms and what is wrong with them. */
					__( 'Check the recipients: %s.', 'seo-health-check' ),
					implode( '; ', array_unique( $problems ) )
				),
			);
		}

		$note = empty( $agency )
			? ' ' . __( 'Fill in your own email domains under Settings to also catch forms that still mail to you.', 'seo-health-check' )
			: '';

		return array(
			self::PASS,
			sprintf(
				/* translators: %d: number of forms. */
				_n( '%d form mails to an address of its own.', '%d forms mail to an address of their own.', count( $forms ), 'seo-health-check' ),
				count( $forms )
			) . $note,
		);
	}

	/**
	 * Which forms send the visitor to a page, and which only show a message.
	 *
	 * The checklist says "if needed", so this reports rather than judges: a message is a fine
	 * answer for a small form, it just cannot be measured as a conversion.
	 *
	 * @return array
	 */
	private static function check_form_confirmations() {
		$stop = self::forms_or_reason();
		if ( $stop ) {
			return $stop;
		}

		$message = array();
		$unknown = array();

		foreach ( SEOHC_Forms::all() as $form ) {
			$title = '' !== trim( $form['title'] ) ? $form['title'] : '#' . $form['id'];

			if ( 'message' === $form['confirmation'] ) {
				$message[] = $title;
			} elseif ( 'unknown' === $form['confirmation'] ) {
				$unknown[] = $title;
			}
		}

		if ( ! $message && ! $unknown ) {
			return array( self::PASS, __( 'Every form sends the visitor to a page of its own afterwards.', 'seo-health-check' ) );
		}

		$parts = array();
		if ( $message ) {
			$parts[] = sprintf(
				/* translators: %s: list of form names. */
				__( 'showing a message instead of a thank you page: %s', 'seo-health-check' ),
				implode( ', ', $message )
			);
		}
		if ( $unknown ) {
			$parts[] = sprintf(
				/* translators: %s: list of form names. */
				__( 'could not be read: %s', 'seo-health-check' ),
				implode( ', ', $unknown )
			);
		}

		return array(
			self::INFO,
			sprintf(
				/* translators: %s: what is the matter with which forms. */
				__( 'Forms %s. A thank you page is not always needed, but without one the submission cannot be counted as a conversion.', 'seo-health-check' ),
				implode( '; ', $parts )
			),
		);
	}

	/**
	 * Whether anything is standing between the forms and the spam bots.
	 *
	 * @return array
	 */
	private static function check_form_spam() {
		$stop = self::forms_or_reason();
		if ( $stop ) {
			return $stop;
		}

		$active = self::active_plugin_folders();
		$found  = array();

		foreach ( self::antispam_plugins() as $folder => $name ) {
			if ( in_array( $folder, $active, true ) ) {
				$found[] = $name;
			}
		}

		$found = array_merge( $found, self::builtin_captchas() );

		if ( $found ) {
			return array(
				self::PASS,
				sprintf(
					/* translators: %s: names of the plugins or settings found. */
					__( 'Spam protection found: %s. Check that it is switched on for every form, because this check only looks site-wide.', 'seo-health-check' ),
					implode( ', ', array_unique( $found ) )
				),
			);
		}

		return array( self::WARNING, __( 'No spam protection found. Without a captcha or a honeypot the spam bots will find the forms within weeks.', 'seo-health-check' ) );
	}

	/**
	 * Captchas the form plugins bring themselves, once their keys are filled in.
	 *
	 * @return string[]
	 */
	private static function builtin_captchas() {
		$found = array();

		$cf7 = get_option( 'wpcf7', array() );
		if ( is_array( $cf7 ) && ! empty( $cf7['recaptcha'] ) ) {
			$found[] = __( 'reCAPTCHA in Contact Form 7', 'seo-health-check' );
		}

		$wpforms = get_option( 'wpforms_settings', array() );
		if ( is_array( $wpforms ) ) {
			foreach ( array( 'recaptcha-site-key', 'hcaptcha-site-key', 'turnstile-site-key' ) as $key ) {
				if ( ! empty( $wpforms[ $key ] ) ) {
					$found[] = __( 'Captcha in WPForms', 'seo-health-check' );
					break;
				}
			}
		}

		return $found;
	}

	/**
	 * Other plugins that can take over sending mail, keyed by plugin folder.
	 *
	 * @return array<string, string>
	 */
	public static function smtp_plugins() {
		return apply_filters(
			'seo_health_check_smtp_plugins',
			array(
				'easy-wp-smtp' => 'Easy WP SMTP',
				'post-smtp'    => 'Post SMTP',
				'fluent-smtp'  => 'FluentSMTP',
				'smtp-mailer'  => 'SMTP Mailer',
				'gmail-smtp'   => 'Gmail SMTP',
			)
		);
	}

	/**
	 * Whether mail actually leaves the site.
	 *
	 * Having WP Mail SMTP installed says nothing: out of the box it is set to the same PHP
	 * mail function it is meant to replace, and that is exactly the state a site is left in
	 * when someone activates the plugin and moves on.
	 *
	 * @return array
	 */
	private static function check_mail_delivery() {
		$active = self::active_plugin_folders();

		if ( ! in_array( 'wp-mail-smtp', $active, true ) ) {
			foreach ( self::smtp_plugins() as $folder => $name ) {
				if ( in_array( $folder, $active, true ) ) {
					return array(
						self::INFO,
						sprintf(
							/* translators: %s: plugin name. */
							__( '%s takes care of the mail. This check can only look inside WP Mail SMTP, so check its settings yourself.', 'seo-health-check' ),
							$name
						),
					);
				}
			}

			return array( self::FAIL, __( 'No mail plugin is active, so WordPress hands mail to the PHP mail function. On most hosting that mail never arrives.', 'seo-health-check' ) );
		}

		$settings = get_option( 'wp_mail_smtp', array() );
		$mail     = isset( $settings['mail'] ) && is_array( $settings['mail'] ) ? $settings['mail'] : array();
		$mailer   = isset( $mail['mailer'] ) ? (string) $mail['mailer'] : '';
		$from     = isset( $mail['from_email'] ) ? (string) $mail['from_email'] : '';

		if ( '' === $mailer || 'mail' === $mailer ) {
			return array( self::FAIL, __( 'WP Mail SMTP is active but still set to the PHP mail function, which is what it starts on. Choose a real mailer and fill in its details.', 'seo-health-check' ) );
		}

		if ( '' === $from || ! is_email( $from ) ) {
			return array(
				self::WARNING,
				sprintf(
					/* translators: %s: name of the mailer, for example smtp. */
					__( 'WP Mail SMTP sends through %s, but no sender address is filled in.', 'seo-health-check' ),
					$mailer
				),
			);
		}

		return array(
			self::PASS,
			sprintf(
				/* translators: 1: name of the mailer, 2: sender address. */
				__( 'WP Mail SMTP sends through %1$s, from %2$s.', 'seo-health-check' ),
				$mailer,
				$from
			),
		);
	}

	/**
	 * The default "admin" login.
	 *
	 * @return array
	 */
	private static function check_default_admin() {
		$user = get_user_by( 'login', 'admin' );

		if ( ! $user ) {
			return array( self::PASS, __( 'There is no user called "admin".', 'seo-health-check' ) );
		}

		if ( user_can( $user, 'manage_options' ) ) {
			return array( self::FAIL, __( 'An administrator called "admin" still exists. That is the first name login bots try. Make a new administrator under a different name, hand the content over to it and remove this one.', 'seo-health-check' ) );
		}

		return array( self::WARNING, __( 'A user called "admin" still exists, without administrator rights. Bots try that name anyway, so give it another one.', 'seo-health-check' ) );
	}

	/*
	 * ------------------------------------------------------------------
	 * Helpers.
	 * ------------------------------------------------------------------
	 */

	/**
	 * Folders of all active plugins.
	 *
	 * @return string[]
	 */
	private static function active_plugin_folders() {
		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		return array_map(
			function ( $file ) {
				return dirname( $file );
			},
			$active
		);
	}

	/**
	 * Homepage HTML (cached for one run).
	 *
	 * @return string|WP_Error
	 */
	private static function homepage_html() {
		if ( null === self::$homepage ) {
			$response = wp_remote_get( home_url( '/' ), self::request_args() );
			if ( is_wp_error( $response ) ) {
				self::$homepage = $response;
			} elseif ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				/* translators: %d: HTTP status code. */
				self::$homepage = new WP_Error( 'seohc_http_status', sprintf( __( 'The homepage returned HTTP status %d.', 'seo-health-check' ), wp_remote_retrieve_response_code( $response ) ) );
			} else {
				self::$homepage = wp_remote_retrieve_body( $response );
			}
		}
		return self::$homepage;
	}

	/**
	 * URL a response ended at after redirects.
	 *
	 * @param array  $response Response from wp_remote_get().
	 * @param string $fallback URL that was requested.
	 * @return string
	 */
	private static function final_url( $response, $fallback ) {
		if ( isset( $response['http_response'] ) && $response['http_response'] instanceof WP_HTTP_Requests_Response ) {
			$object = $response['http_response']->get_response_object();
			if ( ! empty( $object->url ) ) {
				return $object->url;
			}
		}
		return $fallback;
	}

	/**
	 * Common request arguments.
	 *
	 * @return array
	 */
	private static function request_args() {
		return array(
			'timeout'    => 10,
			'user-agent' => 'SEO Health Check/' . SEOHC_VERSION . '; ' . home_url(),
			/** This filter is documented in wp-includes/class-wp-http-streams.php */
			'sslverify'  => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter.
		);
	}
}
