# Coocadev Client Nudge

Version 0.2.0 local development candidate. Requires WordPress 6.9 and PHP 8.1. Complete readable runtime source is in `plugin/`, licensed GPL-2.0-or-later; no compilation, registration or shared Coocadev Core is required.

Open Tools > Client Nudge. Administrators create a task with a client WordPress account ID, title, instructions and due date. Clients use the same Tools screen or a page containing `[cccn_tasks]` to mark assigned tasks complete. Each recipient explicitly enables weekly emails to their own account address and can stop them on that screen.

Reminders include unfinished tasks only, at most 50 per message. The hourly WP-Cron worker processes at most 50 opted-in users with a rotating cursor. It claims each user/UTC ISO week atomically before sending. `wp_mail` success means transport acceptance, not delivery. A crash after claiming can suppress that week rather than send twice. Traffic-driven cron may run late; an actual server cron is needed for reliable scheduling. This is a private material-request board, not a marketing or attachment delivery tool.

Private task posts and assignee/due/completed metadata remain per site. Email consent is stored in site-specific user metadata. Weekly claim options are non-autoloaded and purged in bounded batches after 8 days. WordPress personal-data export/erasure is integrated. Deactivation removes cron while preserving data. Uninstall preserves data unless the administrator selects permanent deletion first. Network activation is rejected; network-wide user cleanup where the plugin is not loaded is not promised.

Private board output sends no-cache signals; exclude board pages from external full-page/CDN caches that may answer before WordPress runs. No visitor tracking, remote runtime dependencies or external API calls. Reminder email uses the site's existing mail setup after opt-in.

You may inspect, use, modify and redistribute the distributed code under GPL version 2 or later. See `plugin/LICENSE`, `DEVELOPER.md`, `DEPENDENCIES.md`, `PLAN.md`, and `reports/0.2.0/RELEASE_REPORT.md`. No production publication or WordPress.org submission occurred. Current brand/slug clearance, product page and catalog assets remain release gates.
