# SEO Health Check

A WordPress plugin that scans posts and pages for common on-page SEO problems and shows them in the dashboard:

- missing, too long or duplicate **titles** and **meta descriptions** (read from **Yoast SEO** or **Rank Math**)
- **images without alt text**, and **image files** over a size you choose
- **missing or multiple H1** headings, and heading levels that **skip a step**
- **thin content** (below a word count you choose)
- **broken internal links**, and pages that **nothing links to**

Every page also gets a **score from 0 to 100**, and **alt texts, SEO titles and meta descriptions can be edited straight from the overview**.

Every scan is compared with the one before it. Each issue is marked **New**, **Unchanged** or **Fixed**, solved issues stay in the list until the next scan, and a **Scans** page keeps the last twenty scans with what each one added and solved.

The plugin can **scan on a schedule** (pick the days and the time) and **email the report** afterwards, with the full list attached as a CSV file and a copy kept on the site.

A **dashboard widget** puts the main numbers on the wp-admin home screen, and the plugin ships with a **Dutch translation**.

A separate **Launch checks** page checks site-wide go-live settings such as search engine visibility, HTTPS, sitemap, redirects and pending updates.

Scans run in the background in batches (Action Scheduler or WP-Cron), so large sites scan without time-outs.

For the full description, installation steps and FAQ, see [readme.txt](readme.txt).

## Installation

1. Download `seo-health-check.zip` from the [latest release](../../releases/latest).
2. In WordPress go to **Plugins > Add New Plugin > Upload Plugin**, choose the zip and click **Install Now**.
3. Activate the plugin and go to **SEO Health > Issues** to start a scan.

Requirements: WordPress 6.0+ and PHP 7.4+.

Once installed, the plugin checks this repository for newer releases once a day and reports them on the **Plugins** screen, so updating works from there like any other plugin.

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
| `includes/class-seohc-repository.php` | All database access (three custom tables) |
| `includes/class-seohc-seo-meta.php` | Reads titles and descriptions from Yoast / Rank Math |
| `includes/class-seohc-content-resolver.php` | Chooses post content or the rendered page (page builders) |
| `includes/class-seohc-link-checker.php` | Checks internal links |
| `includes/class-seohc-launch-checks.php` | Site-wide go-live checks |
| `includes/class-seohc-forms.php` | Reads Contact Form 7, WPForms and Gravity Forms |
| `includes/class-seohc-updater.php` | Update notices, from the GitHub releases |
| `includes/class-seohc-schedule.php` | When the next automatic scan is due |
| `includes/class-seohc-mailer.php` | Builds, keeps and sends the report |
| `includes/class-seohc-settings.php` | Settings API |
| `includes/admin/class-seohc-issues-list-table.php` | The issues overview (`WP_List_Table`) |
| `includes/admin/class-seohc-pages-list-table.php` | The page scores overview (`WP_List_Table`) |
| `includes/admin/class-seohc-scans-page.php` | The scan history screen |
| `includes/admin/class-seohc-dashboard-widget.php` | The widget on the wp-admin home screen |
| `includes/admin/class-seohc-schedule-page.php` | The automatic scan screen |
| `includes/admin/class-seohc-inline-edit.php` | Saving alt texts, titles and descriptions from the overview |
| `includes/admin/class-seohc-admin.php` | Menus, screens, form handlers and the progress endpoint |
| `languages/` | Translations (`nl_NL` included) |

### Hooks for developers

| Hook | Type | Use |
| --- | --- | --- |
| `seo_health_check_capability` | filter | Capability needed to use the plugin (default `manage_options`) |
| `seo_health_check_issue_types` | filter | Register extra issue types |
| `seo_health_check_post_issues` | filter | Add or remove issues for a post |
| `seo_health_check_post_html` | filter | Change the HTML that is scanned |
| `seo_health_check_seo_meta` | filter | Change the resolved title / description |
| `seo_health_check_required_plugins` | filter | Plugins the launch check expects |
| `seo_health_check_page_score` | filter | Change how a page's score is calculated |
| `seo_health_check_inline_fields` | filter | Which issue types can be fixed from the overview |
| `seo_health_check_scan_finished` | action | Runs when a full scan is done |

## License

GPL-2.0-or-later
