=== Coocadev ReleaseGo ===
Contributors: coocadev
Tags: changelog, releases, products, version history
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.2.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Publish a lightweight product changelog with native WordPress release editing, version badges and a bounded shortcode.

== Description ==

Use the native Releases editor for title, formatted notes, author and revisions.
Add a version such as 1.2.3, select New / Fixed / Improved categories and assign a
Product. Put [coocadev_releases product="my-product" limit="10"] on an existing
page. The newest published release notes appear first. Omitting product shows
all products. Limit accepts 1 through 50; invalid attributes return no output.

Only published, non-password-protected entries with valid release metadata are
rendered. Draft, private and scheduled entries stay out even for logged-in admins.
There are no public single-release URLs, extra REST endpoints, frontend scripts,
remote services, license checks or analytics. Semantic HTML follows your theme.

The body allows normal WordPress post HTML after KSES filtering. Shortcodes in
release notes remain literal text and are not executed. PHP is never evaluated.
ReleaseGo is a changelog publisher, not a plugin updater or code deployment tool.

== Installation ==

1. Install and activate ReleaseGo on the intended site.
2. Add a Product under Releases > Products.
3. Add a Release, its version and one or more categories, then publish it.
4. Add [coocadev_releases product="my-product" limit="10"] to an existing page.
5. Settings > ReleaseGo explains the shortcode and controls uninstall retention.

WordPress post capabilities govern release editing/publishing; product term
management uses manage_categories. Metadata saves also require the specific post
edit capability and the release-details nonce. Network activation is unsupported.

== Frequently Asked Questions ==

= Why does a published entry not appear? =
Give it valid version details and a selected category, remove any post password,
and check its Product slug matches the shortcode. Native Quick Edit or an importer
without ReleaseGo details may publish the post while leaving the required metadata
empty; such entries remain hidden from the shortcode.

= What happens if release details are invalid? =
The existing version/categories remain unchanged. WordPress may still save the
title and body through its normal editor flow; the admin notice explains this.
Quick Edit/autosave without the details nonce preserves existing metadata.

= Is the list cached? =
ReleaseGo uses a bounded native WordPress query and adds no private-data cache.
Published notes are intentionally public. Your host/page cache may need a purge
after publishing or updating the page. Drafts are excluded at query and render time.

= What data is kept? =
ReleaseGo stores native WordPress posts, author IDs, dates/revisions, product terms,
version/categories and one uninstall preference. Core content/privacy procedures
apply. Avoid confidential or personal data in public release notes. No information
is sent to Coocadev.

= What happens on deletion? =
Deactivation and default uninstall preserve release history. Explicit removal in
Settings > ReleaseGo deletes only its release posts, associated metadata/revisions,
product taxonomy terms and settings. It does not delete normal posts or users.
Each site's removal preference is respected; full multisite QA remains a separate
release gate. Back up history before opting into deletion.

== Source and contributions ==

Copyright (c) 2026 Coocadev. SPDX-License-Identifier: GPL-2.0-or-later.
Use, study, modification and redistribution are permitted under GNU GPL version 2
or any later version. Preserve notices and provide corresponding source as the
license requires for redistributed derivatives. The full GPLv2 text is in LICENSE;
the later-version notice grants the later-version choice. All shipped features
are available without a license key, subscription or Coocadev account.

The shipped PHP is complete readable source; no build step, minification, remote
code or shared Coocadev runtime is required. The source project includes English
DEVELOPER.md, PLAN.md, DEPENDENCIES.md and local tests. Contributions should use
focused patches, explain the behavior in English and include relevant regression
checks. Keep credentials, private fixtures, customer data and emails out of public
code and release ZIPs.

== Changelog ==

= 0.2.0 =
* Initial local open-source candidate.
