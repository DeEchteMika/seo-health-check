=== SEO Health Check ===
Contributors: deechtemika
Tags: seo, audit, meta description, alt text, broken links
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.2.0
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

**A score per page**

Every scanned page gets a score from 0 to 100 on its own screen, worst first, so you can see at a glance which pages need attention. Each kind of problem costs points, an error twice as much as a warning, and repeats of the same problem a little extra.

**See what changed**

Every finished scan is compared with the one before it. The overview shows whether the number of issues went up or down, and issues that appeared in the latest scan are marked "New" so you can filter on them.

**Fix things without leaving the overview**

Alt texts, SEO titles and meta descriptions can be typed straight into the results list, with a character counter. The page is rescanned the moment you save, so the row updates itself. Problems that live inside the page content, such as headings and broken links, still link through to the edit screen.

**Launch checks**

A separate page checks site-wide settings from a typical go-live checklist: search engine visibility, HTTPS, permalinks, favicon, a real 404 status, XML sitemap, WWW / HTTPS redirects, pending updates, inactive plugins, cookie consent, analytics code, links to your development domain and more.

**Built for large sites**

Scans run in the background in small batches (via Action Scheduler when available, otherwise WP-Cron), so the dashboard never freezes and there are no time-outs, also on sites with thousands of posts. You can leave the page while a scan runs.

**Works with page builders**

Page builders such as Oxygen, Breakdance and Elementor store their layout outside the normal post content. SEO Health Check notices when the post content is empty and scans the rendered page instead, ignoring the site header, footer and navigation.

**Other features**

* Sortable, filterable and searchable overview built on the standard WordPress list table.
* Filter by issue type, post type and severity, or show only what is new.
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

Scanning never changes anything: it reads your content and stores its findings in two tables of its own.

The one exception is when you fix something yourself from the overview. Saving a meta description or SEO title writes that single field in Yoast SEO or Rank Math. Saving an alt text stores it on the image in the media library and, when that image is embedded in the page, sets the alt attribute of that one image tag. Nothing else in the page is touched.

= Who can see the results? =

Administrators (users with the `manage_options` capability). Developers can change this with the `seo_health_check_capability` filter.

= What happens to my data when I remove the plugin? =

Deactivating stops all background work but keeps the results. Deleting the plugin from the **Plugins** screen removes its tables, settings and cached data.

= How is the score calculated? =

A page starts at 100. Every distinct issue type costs 20 points for an error and 10 for a warning, plus 2 points per repeat of the same type, capped at 10 extra per type. Ten images without alt text therefore hurt, but less than ten different problems. Developers can change the result with the `seo_health_check_page_score` filter.

= Which fields can I edit from the overview? =

Alt text, SEO title and meta description, because each is stored on its own. Titles and descriptions need Yoast SEO or Rank Math, since the plugin writes the value into their field. Missing H1s, thin content and broken links live inside the page content, so those link to the edit screen instead.

= What counts as a "new" issue? =

Anything first reported by the most recent scan. The plugin remembers when it first saw each issue, so an issue that has been there for months stays unmarked even though it is found again every scan.

= Can I add my own checks? =

Yes. Use the `seo_health_check_issue_types` filter to register a new issue type and `seo_health_check_post_issues` to add issues for a post.

== Screenshots ==

1. The issues overview with scores, what changed since the previous scan, and inline fixing.
2. The page scores screen, worst pages first.
3. A scan running in the background.
4. The launch checks page.
5. The settings page.

== Changelog ==

= 0.2.0 =
* New: a score from 0 to 100 per page, on its own screen and in the issues list.
* New: every scan is compared with the previous one, and new issues are marked.
* New: edit alt texts, SEO titles and meta descriptions straight from the overview.
* The database is upgraded automatically; existing results keep working.

= 0.1.1 =
* Author name set to Mika Leonard.

= 0.1.0 =
* First version: per-page scan, overview, CSV export, launch checks and settings.
