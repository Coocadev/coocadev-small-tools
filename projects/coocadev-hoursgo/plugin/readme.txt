=== Coocadev HoursGo ===
Contributors: coocadev
Tags: opening hours, business hours, temporary hours
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.2.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Weekly opening hours with temporary exceptions that expire automatically in the site timezone.

== Description ==
Set normal weekly hours and temporary messages such as Closed today or Back on Monday. The public shortcode returns to the normal schedule automatically after an exception ends. One server timestamp determines the displayed day and open/closed state; no cron or browser timer is needed.

Scope: one location, one opening period per day, up to 12 non-overlapping exceptions lasting at most 31 days each. Overnight and split-shift hours are not supported. Nonexistent/repeated daylight-saving minutes are rejected. Existing exceptions remain absolute UTC instants if the site timezone changes. Already-open tabs update after refresh/navigation.

No visitor information, cookies, external APIs, telemetry or frontend assets. Time-sensitive shortcode pages send no-cache headers but must also bypass external full-page/CDN caching that can answer before WordPress runs.

== Installation ==
1. Activate and set the business timezone in WordPress settings.
2. Open Settings > HoursGo. Blank opening and closing fields mean closed that day.
3. Save the weekly schedule and exceptions; add [cchg_hours] to a page.

== Frequently Asked Questions ==
= Does an exception automatically expire? =
Yes, on each server render the end boundary is exclusive and normal hours resume without a scheduled task.

= What happens on uninstall? =
Settings are preserved unless the administrator explicitly selects deletion. No visitor data is stored. Network-wide uninstall is not claimed.

= Can I inspect and modify the source? =
Yes. Readable PHP is included under GPL-2.0-or-later; see LICENSE. No build is needed.

== Changelog ==
= 0.2.0 =
* Initial local candidate with weekly schedules and time-bounded exceptions.
