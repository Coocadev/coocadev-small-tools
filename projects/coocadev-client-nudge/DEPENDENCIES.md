# Dependencies and licensing

Coocadev Client Nudge 0.2.0 runtime is original readable PHP, GPL-2.0-or-later (full `plugin/LICENSE`). WordPress6.9+ and PHP8.1+ are separately installed hosts, not bundled dependencies. No runtime libraries, fonts, images, remote APIs, minified bundles, build generators or required shared plugins. WP-CLI, Plugin Check and WPCS are development-only and excluded from install ZIPs. Mail uses the site's existing `wp_mail` transport after explicit recipient opt-in; no delivery provider is bundled.
