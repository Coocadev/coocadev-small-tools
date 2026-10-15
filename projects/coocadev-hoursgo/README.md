# Coocadev HoursGo

Version 0.2.0 local development candidate. Requires WordPress 6.9 and PHP 8.1. Complete readable runtime is in `plugin/`, GPL-2.0-or-later, without compilation or a required shared plugin.

Set the business timezone in WordPress, open Settings > HoursGo, enter seven daily opening/closing pairs and add `[cchg_hours]` to a page. Both fields blank means closed that day. Temporary exceptions have a start, end and plain-text message. The message replaces the normal schedule while active, then normal hours return automatically from the server timestamp without cron.

Supported scope: one location, one opening interval per day, up to 12 non-overlapping exceptions, at most 31 days each within the next year. Overnight and split-shift hours are outside this release. Missing/repeated daylight-saving minutes are rejected. Already-open browser tabs need refresh/navigation to recalculate. Weekly hours follow the current site timezone; stored exceptions retain their absolute UTC instants after a timezone change.

The shortcode sends no-cache signals. Exclude its page from external full-page/CDN caches: a cache that answers before WordPress cannot observe plugin headers. No visitor data, cookies, telemetry, external services, JavaScript or custom tables. One non-autoloaded option stores settings. Deactivation preserves them; uninstall deletes them only after explicit administrator opt-in. Single-site lifecycle is claimed; network-wide uninstall remains outside the tested matrix.

You may inspect, use, modify and redistribute the code under GPL version 2 or later; see `plugin/LICENSE`. See `DEVELOPER.md`, `DEPENDENCIES.md`, `PLAN.md`, and the exact release report. No production publication or directory submission occurred. Current naming, public product page and directory assets remain release gates.
