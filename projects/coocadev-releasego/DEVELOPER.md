# ReleaseGo developer guide

Copyright (c) 2026 Coocadev. SPDX-License-Identifier: GPL-2.0-or-later.
Study, use, modification and redistribution follow GNU GPL version 2 or later.
Preserve notices and provide corresponding source for distributed derivatives as
the license requires. Full GPLv2 text and a later-version notice are included.

## Code map and hooks

The main PHP loads src/class-plugin.php and registers activation. Plugin registers
CPT ccrg_release and taxonomy ccrg_product on init, adds the release-details box,
handles save_post_ccrg_release, truthful editor redirect notices, retention admin
POST and the coocadev_releases shortcode. There is no public single-release route,
custom REST endpoint, asset build or frontend JavaScript/CSS dependency.

Native Core post permissions apply. Metadata repeats exact edit_post permission,
POST method and ccrg_meta_ID nonce checks. Autosave/revision/Quick Edit without the
details nonce preserves metadata. Terms use manage_categories for management and
edit_posts for assignment. Retention requires manage_options plus its nonce.

## Data and public rendering

Native posts/revisions retain author/date/title/body. Private metadata keys
_ccrg_version and _ccrg_kinds store a bounded version label and a distinct list of
new/fixed/improved. ccrg_remove_data is the explicit uninstall choice. Invalid
metadata preserves the pair; Core may still save body/title, which the notice says.
The plugin does not promise a combined transaction with the native editor save.

WP_Query is bounded to 1-50 published releases with optional indexed product term
filter and date/ID ordering. The renderer repeats type/status/password/metadata
checks after the query: even a modified query cannot leak a draft. Public titles,
badges and dates are escaped; body uses wpautop and wp_kses_post. It intentionally
does not run the_content filters or do_shortcode on note bodies, so notes cannot
invoke arbitrary shortcode behavior. PHP is never evaluated.

## Usage and source installation

```text
[coocadev_releases product="my-product" limit="10"]
```

Omit product for a combined list. Use exact lowercase ASCII slug and a decimal
limit from 1 through 50. Invalid attributes produce empty output. Only published,
unprotected entries with valid version/category details appear, even for admins.
Use semantic .ccrg-releases/.ccrg-release/.ccrg-badge hooks in your theme styles.

The plugin/ directory is complete readable runtime, installed under
coocadev-releasego. No compilation/minification, remote assets or shared plugin is
required. Native class methods are internal implementation details, not a stable
public API. Don't bypass object permissions by wrapping them in a public route.

## Lifecycle, privacy and verification

Per-site activation is supported; network activation is rejected. Deactivation
and default uninstall retain release history. Explicit opt-in uses native deletion
APIs for only this CPT and taxonomy, associated metadata/revisions and its option.
Each site has its own preference. A deletion failure stops cleanup instead of
looping or claiming success. Core editorial/privacy procedures handle authors and
content; published release notes are intentionally public, not encrypted storage.

tests/integration.php tests actual WordPress metadata/permissions/shortcode and
native HTTP boundaries with disposable synthetic fixtures. Lifecycle workers call
actual Core uninstall in separate processes. Run on isolated sites, never live
stores. PHP syntax: php -l. reports/0.2.0 records observed WPCS/Plugin Check,
WP_DEBUG and runtime results; exact final ZIP/hash/install remains release-scoped.

Contribute focused English-commented patches and regressions for changed rendering
or authorization. Keep credentials, cookie files, real content/database dumps and
private email out of public source and installable releases. Expanded multisite,
cache, performance, browser and accessibility claims require their own evidence.
