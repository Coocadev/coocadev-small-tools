=== Coocadev DoneMark ===
Contributors: coocadev
Tags: editorial, checklist, workflow, content review
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Private, independent editorial checkmarks in native WordPress lists. See when saved text no longer matches the reviewed version.

== Description ==

Create up to 12 named marks under Settings > DoneMark and select editable post types. Editors use the native Posts or Pages list, Quick Edit, and a bounded bulk action. Each mark stores its own state, last editor and UTC time.

Review marks compare the exact saved title, content and excerpt with the version reviewed. A content change shows Needs review; restoring identical content matches the earlier review again. Distribution marks, such as Shared in newsletter, remain done when text changes. This is an editorial aid, not a verification of content accuracy, SEO settings, media, taxonomies, dynamic block output or external publishing.

Only users who can edit a particular post can see or change its marks. Administrators manage definitions. Simultaneous edits use version checks. Deleting a mark requires separate confirmation; archiving hides it while preserving its states.

There are no frontend assets, external API requests, tracking or account requirements. Assets load only on the plugin settings screen and supported native post lists.

== Installation ==

1. Install the plugin and activate it for an individual site.
2. Open Settings > DoneMark and add a Review or Distribution mark.
3. Select the post types that use it and save.
4. Open Posts or Pages and use the DoneMark column, Quick Edit or selected-row bulk controls.

PHP 8.1+, WordPress 6.9+ and InnoDB for the WordPress options table and DoneMark state table are required. Network-wide activation is unsupported; activate separately per site.

== Frequently Asked Questions ==

= Does a checkmark publish or share an article? =
No. Marks record editorial work; they do not change post status or send content anywhere.

= What happens if two editors work together? =
Independent marks can be changed separately. A conflicting state or stale text review is rejected with a reload message. Retrying the same completed change does not overwrite attribution or time.

= Does Quick Edit review my unsaved title? =
Quick Edit saves WordPress content first. Only explicitly changed mark controls are then submitted against the saved content fingerprint. Failed or conflicting changes remain visible as errors.

= What data is stored? =
Site-local mark definitions, post IDs, current states, the last editor ID and UTC time, a state version, and for review marks a SHA-256 fingerprint of saved title, content and excerpt. No article copy or email address is stored in the state table. WordPress privacy export includes the requesting user's current attribution; erasure anonymizes attribution while keeping team states. Avoid personal data in custom labels.

= What happens on deactivation or deletion? =
Deactivation retains data. Deletion also retains data by default. Enabling Remove DoneMark definitions and states when this plugin is deleted removes only this site's DoneMark table and options on uninstall. Posts, users and other plugins are never deleted. On multisite, each site's own opt-in is respected.

== Source and contributions ==

Copyright (c) 2026 Coocadev. All included plugin features are free software under
GNU GPL version 2 or later (SPDX: GPL-2.0-or-later). You may study, use, modify and
redistribute the code under that license. Preserve copyright/license notices and
provide corresponding source when the GPL requires it for redistributed changes.
The full GPLv2 license text is included in LICENSE, with the later-version choice
stated in source headers. No licence key or payment unlocks included local features.

The shipped PHP, JavaScript and CSS are complete readable sources; no compilation,
minification or remote code download is required. The development source project
contains an English DEVELOPER.md with the architecture, hooks, data lifecycle,
test entry points and contribution guidance. Keep patches focused and include
regression coverage for changed behavior. Never include credentials, customer
data, private fixtures or review emails in public source or installable packages.

== Changelog ==
= 0.2.0 =
* Open-source release: English architecture comments and developer guides; same runtime behavior as 0.1.0.


= 0.1.0 =
* Initial local candidate with independent marks, content review fingerprints, native list controls, scoped permissions, conflict handling and privacy tools.
