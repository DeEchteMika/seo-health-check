=== SEO Health Check ===
Contributors: pdk
Tags: seo, audit, meta description, alt text, broken links
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Find missing meta descriptions, images without alt text, heading problems, thin content and broken internal links, right in your WordPress dashboard.

== Description ==

SEO Health Check scans every post and page on your site for the on-page SEO problems that are easy to miss and easy to fix. You get one overview in wp-admin with every problem, a direct link to the edit screen, and a CSV export for your client or team.

**What it checks, per page**

* **Title**: missing, too long, or used by more than one page.
* **Meta description**: missing, too long, or used by more than one page.
* **Images** without alt text, including the featured image.
* **H1 headings**: missing, or more than one.
* **Thin content**: fewer words than the minimum you set.
* **Broken internal links**: links to pages that do not exist (anymore), are in the trash, or are still a draft.

Titles and meta descriptions are read from **Yoast SEO** or **Rank Math** when one of them is active, including their templates and variables. Without an SEO plugin, the WordPress defaults are used.

**Launch checks**

A separate page checks site-wide settings from a typical go-live checklist: search engine visibility, HTTPS, permalinks, favicon, a real 404 status, XML sitemap, WWW / HTTPS redirects, pending updates, inactive plugins, cookie consent, analytics code, links to your development domain and more.

**Built for large sites**

Scans run in the background in small batches (via Action Scheduler when available, otherwise WP-Cron), so the dashboard never freezes and there are no time-outs, also on sites with thousands of posts. You can leave the page while a scan runs.

**Works with page builders**

Page builders such as Oxygen, Breakdance and Elementor store their layout outside the normal post content. SEO Health Check notices when the post content is empty and scans the rendered page instead, ignoring the site header, footer and navigation.

**Other features**

* Sortable, filterable and searchable overview built on the standard WordPress list table.
* Filter by issue type, post type and severity.
* Export the (filtered) results as CSV, ready for Excel.
* Posts are rescanned automatically in the background when you save them.
* Removes all of its data when you delete the plugin.
* Translation ready.

== Installation ==

1. Download the plugin zip file.
2. In your WordPress dashboard, go to **Plugins > Add New Plugin** and click **Upload Plugin**.
3. Choose the zip file, click **Install Now** and then **Activate**.
4. Go to **SEO Health > Settings** and check the post types and rules. The defaults work for most sites.
5. Go to **SEO Health > Issues** and click **Start full scan**.

The scan runs in the background. You can watch the progress bar or leave the page and come back later.

To install manually, unzip the file and upload the `seo-health-check` folder to `/wp-content/plugins/` over FTP, then activate the plugin on the **Plugins** screen.

== Frequently Asked Questions ==

= Do I need Yoast SEO or Rank Math? =

No. Without an SEO plugin, the plugin checks the default WordPress title. Every page will then be reported as missing a meta description, because WordPress itself does not output one.

= How long does a scan take? =

Usually a few seconds per 20 pages. A site with 500 posts is typically done in a few minutes. Checking links and rendered pages makes HTTP requests, so slow hosting takes longer. You can lower the batch size under **Settings** if your server struggles.

= The scan does not move forward. What now? =

The scan relies on WP-Cron, which runs when someone visits the site. Keep the **Issues** page open: while it is open, it also pushes the scan forward itself. If your host has disabled WP-Cron (`DISABLE_WP_CRON`), make sure a real cron job calls `wp-cron.php`.

= Why does a page report "Multiple H1s" while I only added one? =

Most themes already show the post title as an H1 above the content. With the **Theme H1** setting enabled (the default), an H1 inside the content counts as a second one. Disable the setting if your theme does not output the title as an H1.

= My page builder pages show thin content or no H1. =

Check that **Content source** is set to **Automatic** or **Always use the rendered page**. The plugin then scans the page as visitors see it. The site must be reachable from its own server for this to work.

= Which links count as internal? =

Links to the same domain as the site (with or without www). External links, email links, phone links and anchors on the same page are skipped. Links to the admin area, feeds and the REST API are ignored.

= Does the plugin change my content? =

No. It only reads your content and stores its findings in two tables of its own.

= Who can see the results? =

Administrators (users with the `manage_options` capability). Developers can change this with the `seo_health_check_capability` filter.

= What happens to my data when I remove the plugin? =

Deactivating stops all background work but keeps the results. Deleting the plugin from the **Plugins** screen removes its tables, settings and cached data.

= Can I add my own checks? =

Yes. Use the `seo_health_check_issue_types` filter to register a new issue type and `seo_health_check_post_issues` to add issues for a post.

== Screenshots ==

1. The issues overview with summary, filters and direct edit links.
2. A scan running in the background.
3. The launch checks page.
4. The settings page.

== Changelog ==

= 0.1.0 =
* First version: per-page scan, overview, CSV export, launch checks and settings.
