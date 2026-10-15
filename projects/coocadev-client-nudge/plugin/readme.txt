=== Coocadev Client Nudge ===
Contributors: coocadev
Tags: client tasks, reminders, workflow
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.2.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Private client tasks and optional weekly reminder emails for unfinished material requests.

== Description ==
Create private tasks with a client account, due date and instructions. Clients mark their own tasks done. Each recipient can explicitly enable or stop weekly reminder emails. No emails are sent on activation and no client is opted in by the administrator.

The hourly WP-Cron worker handles at most 50 recipients and 50 pending tasks per recipient per run. One atomic claim per user and UTC ISO week prevents repeat reminders. Transport acceptance is not a delivery guarantee; a crash after claiming can suppress that week rather than risk duplication. Traffic-driven cron can run late. No external APIs, telemetry, cookies, paid unlocks or shared plugin dependency.

Private task posts, per-site consent and weekly claim options are stored in WordPress. Claims older than eight days are purged in bounded batches. Standard WordPress privacy export and erasure are integrated. The task-board page must bypass external full-page/CDN caches even though the plugin also sends no-cache signals.

== Installation ==
1. Install and activate on the desired site. Network activation is not supported.
2. Open Tools > Client Nudge and create a task with a valid client account ID and due date.
3. Clients use that Tools screen or a page containing [cccn_tasks]. Each client controls their own email consent.

== Frequently Asked Questions ==
= Will this configure SMTP? =
No. It uses your existing wp_mail transport after recipient opt-in. Sending acceptance does not prove inbox delivery.

= What happens on uninstall? =
Data is preserved by default. The administrator can explicitly choose permanent deletion of this site's tasks, consent and claims before uninstalling. Deactivation only unschedules reminders. Account deletion cleans tasks on the currently loaded site; network-wide cleanup where the plugin is inactive is outside this release.

= Is the source open? =
Yes. All readable PHP source is included under GPL-2.0-or-later. See LICENSE. No compilation or registration is required.

== Changelog ==
= 0.2.0 =
* Initial local candidate with private tasks, consent-controlled reminders and privacy tools.
