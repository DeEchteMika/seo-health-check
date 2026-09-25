# SEO Health Check

A WordPress plugin that scans posts and pages for common on-page SEO problems and shows them in the dashboard:

- missing, too long or duplicate **titles** and **meta descriptions** (read from **Yoast SEO** or **Rank Math**)
- **images without alt text**
- **missing or multiple H1** headings
- **thin content** (below a word count you choose)
- **broken internal links**

A separate **Launch checks** page checks site-wide go-live settings such as search engine visibility, HTTPS, sitemap, redirects and pending updates.

Scans run in the background in batches (Action Scheduler or WP-Cron), so large sites scan without time-outs.

For the full description, installation steps and FAQ, see [readme.txt](readme.txt).

## Installation

1. Download `seo-health-check.zip` from the [latest release](../../releases/latest).
2. In WordPress go to **Plugins > Add New Plugin > Upload Plugin**, choose the zip and click **Install Now**.
3. Activate the plugin and go to **SEO Health > Issues** to start a scan.

Requirements: WordPress 6.0+ and PHP 7.4+.

## Development

```bash
composer install      # installs PHP_CodeSniffer with the WordPress Coding Standards
composer lint         # checks the code
composer lint:fix     # fixes what can be fixed automatically
```

Build an installable zip (development files are excluded through `.gitattributes`):

```bash
git archive --format=zip --prefix=seo-health-check/ -o seo-health-check.zip HEAD
```

### Structure

| Path | Purpose |
| --- | --- |
| `seo-health-check.php` | Plugin header and bootstrap |
| `uninstall.php` | Removes all plugin data on delete |
| `includes/class-seohc-scanner.php` | Runs all checks for one post |
| `includes/class-seohc-scan-queue.php` | Background batches and scan state |
| `includes/class-seohc-repository.php` | All database access (two custom tables) |
| `includes/class-seohc-seo-meta.php` | Reads titles and descriptions from Yoast / Rank Math |
| `includes/class-seohc-content-resolver.php` | Chooses post content or the rendered page (page builders) |
| `includes/class-seohc-link-checker.php` | Checks internal links |
| `includes/class-seohc-launch-checks.php` | Site-wide go-live checks |
| `includes/class-seohc-settings.php` | Settings API |
| `includes/admin/` | Admin pages and the `WP_List_Table` overview |

### Hooks for developers

| Hook | Type | Use |
| --- | --- | --- |
| `seo_health_check_capability` | filter | Capability needed to use the plugin (default `manage_options`) |
| `seo_health_check_issue_types` | filter | Register extra issue types |
| `seo_health_check_post_issues` | filter | Add or remove issues for a post |
| `seo_health_check_post_html` | filter | Change the HTML that is scanned |
| `seo_health_check_seo_meta` | filter | Change the resolved title / description |
| `seo_health_check_required_plugins` | filter | Plugins the launch check expects |
| `seo_health_check_scan_finished` | action | Runs when a full scan is done |

## License

GPL-2.0-or-later
