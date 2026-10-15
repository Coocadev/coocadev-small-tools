=== Coocadev MediaMap ===
Contributors: coocadev
Tags: content, administration, audit
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Find selected image references in stored Core blocks, content and featured images.

== Description ==
Find pages that reference a selected Media Library image. A clean report is not a safe-to-delete certificate.

Open Tools > MediaMap, enter the target and start a scan. It reads at most 40 content records per request. Pause and Continue work without writing a server-side job. Only administrators with manage_options can scan, and each result must be editable by the current user.

Scans stored Core content, pages, UI post types, reusable blocks and database templates. Excludes trash, revisions, auto-drafts and attachments as content containers. Does not inspect arbitrary page builders, widgets, theme files, options, dynamic output or external sites. Reusable blocks are separate entries, not expanded into parent pages. A report with no matches is not evidence that deletion or plugin deactivation is safe.

No content is changed, deleted or deactivated. No persistent report, personal data store, cron, cookie, telemetry or external request is added. Results live only in the admin page and disappear on refresh. Scans are not an atomic snapshot: restart after editing previously scanned content.

Posts larger than256KiB are skipped before parsing and full post-cache loading. Skipped counts remain visible and mark the report incomplete, even when no matches were found. The cursor still advances across skipped rows. Review oversized content separately; no unused conclusion is justified. Actual database failures stop with an error instead of reporting a clean empty scan.

== Installation ==
1. Upload and activate the ZIP through Plugins > Add New.
2. Open Tools > MediaMap.
3. Start a scan and review the linked content before making your own changes.

== Frequently Asked Questions ==
= Does an empty report mean an item is unused? =
No. The scanner covers the documented stored content only.

= Is it open source? =
Yes. All runtime PHP and JavaScript are readable and included under GPL-2.0-or-later. You may study, change and redistribute them under that license. No build or paid unlock is required. The source distribution includes tests and developer documentation.

= What happens on uninstall? =
Only plugin files are removed. The plugin has no persisted settings or reports, and WordPress content is left intact.

== Changelog ==
= 0.2.0 =
* Initial local release candidate with a bounded, read-only content scanner.
