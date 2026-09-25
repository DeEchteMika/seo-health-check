=== SEO Health Check ===
Contributors: deechtemika
Tags: seo, audit, meta description, alt text, broken links
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Find missing meta descriptions, images without alt text, heading problems, thin content and broken internal links, right in your WordPress dashboard.

== Description ==

SEO Health Check scans every post and page on your site for the on-page SEO problems that are easy to miss and easy to fix. You get one overview in wp-admin with every problem, a direct link to the edit screen, and a CSV export for your client or team.

**What it checks, per page**

* **Title**: missing, too long, or used by more than one page.
* **Meta description**: missing, too long, or used by more than one page.
* **Images** without alt text, including the featured image.
* **Image files** bigger than the maximum you set (off until you fill one in).
* **H1 headings**: missing, or more than one.
* **Heading order**: a level that is skipped, such as an H2 followed by an H4.
* **Thin content**: fewer words than the minimum you set.
* **Broken internal links**: links to pages that do not exist (anymore), are in the trash, or are still a draft.
* **Pages nothing links to**: no other page and no menu points at them.

Titles and meta descriptions are read from **Yoast SEO** or **Rank Math** when one of them is active, including their templates and variables. Without an SEO plugin, the WordPress defaults are used.

**A score per page**

Every scanned page gets a score from 0 to 100 on its own screen, worst first, so you can see at a glance which pages need attention. Each kind of problem costs points, an error twice as much as a warning, and repeats of the same problem a little extra.

**See what changed**

Every issue in the overview says what the last scan did with it: **New** when that scan was the first to report it, **Unchanged** when it was already there, and **Fixed** when the scan found it gone. Solved issues stay in the list until the next scan instead of quietly disappearing, so you can see what your work actually did, and a status filter narrows the list to one of the three.

**The scan history**

A separate **Scans** screen keeps the last twenty finished scans: when each one ran and how long it took, the average score, the number of issues, how many appeared and how many were solved. Below it, the last scan is compared with the one before it per kind of problem, so you can see whether the alt texts are really getting better or only the titles. The page scores overview has a column showing what the last full scan changed for each page.

**Fix things without leaving the overview**

Alt texts, SEO titles and meta descriptions can be typed straight into the results list, with a character counter. The page is rescanned the moment you save, so the row updates itself. Problems that live inside the page content, such as headings and broken links, still link through to the edit screen.

**Scanning by itself**

Pick the days and the time, fill in one or more email addresses, and the plugin scans the site on its own. When the scan finishes it sends a report with the score, the totals, what appeared and what was solved, the most common issues and the results of the last few scans, with the full list attached as a CSV file.

A copy of each report is kept on the site so you can look back at it, shielded from visitors and downloadable from the settings screen only. You choose how many are kept.

**On your dashboard**

A widget on the wp-admin home screen shows the average score, the number of issues, what the last scan added and solved, and a link to each screen. No need to go looking for the results.

**Launch checks**

A separate page checks site-wide settings from a typical go-live checklist: search engine visibility, HTTPS, permalinks, favicon, a real 404 status, XML sitemap, WWW / HTTPS redirects, pending updates, inactive plugins, cookie consent, analytics code, links to your development domain and more.

**Built for large sites**

Scans run in the background in small batches (via Action Scheduler when available, otherwise WP-Cron), so the dashboard never freezes and there are no time-outs, also on sites with thousands of posts. You can leave the page while a scan runs.

**Works with page builders**

Page builders such as Oxygen, Breakdance and Elementor store their layout outside the normal post content. SEO Health Check notices when the post content is empty and scans the rendered page instead, ignoring the site header, footer and navigation.

**Other features**

* Sortable, filterable and searchable overview built on the standard WordPress list table.
* Filter by issue type, post type and severity, or by status: new, unchanged or solved.
* Export the (filtered) results as CSV, ready for Excel.
* Posts are rescanned automatically in the background when you save them.
* Scan on a schedule and receive the report by email.
* Removes all of its data when you delete the plugin.
* Translation ready, with a Dutch translation included.

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

= A page is reported as having no links, but it is in my footer. =

The scan reads the content of your pages and the items in your WordPress menus. It deliberately skips the site header, footer, sidebar and navigation, because otherwise every page would look linked from everywhere. A page that is only reachable from a widget or a footer built in a page builder is therefore reported.

Blog posts that are only listed on an archive page count as unlinked too. That is a real finding for SEO, but noisy on a blog. Switch the check off under **Settings > Pages without links**, or use the `seo_health_check_linked_post_ids` filter to add the posts you want treated as linked.

= Why does the image size check report nothing? =

It is off until you fill in a maximum under **Settings > Maximum image size**; 300 KB is a sensible start. Only images inside your own uploads folder can be measured, because their file is read from disk. Images served from a CDN or another site are skipped rather than downloaded.

= The automatic scan starts later than the time I set. =

WordPress runs planned jobs when someone visits the site, so on a quiet site nothing happens until the first visitor arrives after that moment. To make it exact, have your host call `wp-cron.php` from a real cron job and set `DISABLE_WP_CRON` to true.

= The report never arrives. =

Use **Send a test report** on the **Automatic scan** screen. If it fails, the reason is shown right below it. Most hosts cannot send mail through PHP at all; installing an SMTP plugin and pointing it at a real mailbox solves that. Also check the spam folder: a message from a site nobody knows yet often lands there.

= Where are the kept reports stored? =

In `wp-content/uploads/seo-health-check/`. They list every page of the site and what is wrong with it, so the folder gets an `.htaccess` that denies access and every file gets an unguessable name for servers that ignore `.htaccess`. Downloading goes through WordPress, which checks that you are allowed to. Deleting the plugin removes the folder.

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

= 0.5.0 =
* New: scan on a schedule. Choose the days and the time; it runs in the time zone of the site.
* New: a report by email after every scheduled scan, with the full list attached as CSV.
* New: reports are kept on the site, shielded from visitors, with a download link and a limit you set.
* New: a "Send a test report" button that shows why sending failed, instead of failing quietly.

= 0.4.0 =
* New check: heading levels that skip a step, for example an H2 followed by an H4.
* New check: image files larger than a maximum you set. Off until you fill one in.
* New check: pages that no other page and no menu links to.
* New: a dashboard widget with the main numbers on the wp-admin home screen.
* New: Dutch translation (nl_NL).
* Internal links between posts are stored in a third table, which is removed again when the plugin is deleted.

= 0.3.0 =
* New: every issue is marked New, Unchanged or Fixed, with a status filter in the overview.
* New: solved issues stay visible until the next scan instead of disappearing without a trace.
* New: a Scans screen with the last twenty scans, what each one changed, and a comparison per issue type.
* New: the page scores overview shows the change per page since the last full scan.
* The CSV export has a Status column. The database is upgraded automatically; existing results keep working.

= 0.2.0 =
* New: a score from 0 to 100 per page, on its own screen and in the issues list.
* New: every scan is compared with the previous one, and new issues are marked.
* New: edit alt texts, SEO titles and meta descriptions straight from the overview.
* The database is upgraded automatically; existing results keep working.

= 0.1.1 =
* Author name set to Mika Leonard.

= 0.1.0 =
* First version: per-page scan, overview, CSV export, launch checks and settings.
