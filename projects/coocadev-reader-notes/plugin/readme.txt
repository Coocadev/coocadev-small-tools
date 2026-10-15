=== Coocadev Reader Notes ===
Contributors: coocadev
Tags: notes, readers, private notes, education
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Private personal notes per article, with autosave, conflict protection, search, text export and WordPress privacy tools.

== Description ==

Coocadev Reader Notes lets signed-in readers keep one plain-text note for each
article. Notes belong to the reader, not the article author or editorial team.
It does not duplicate editorial collaboration notes.

Features:

* Reader Note and My Notes blocks, plus classic shortcodes.
* Debounced autosave, manual save and explicit conflict resolution.
* Own-note search, text export, individual deletion and delete all.
* WordPress personal-data exporter and eraser.
* Optional automatic placement after selected article types.
* An optional administrator-selected storage quota, with no quota by default.
  This setting is fully free to change or remove.
* No external services, telemetry, remote assets or licence checks.

This is an initial local development release. Directory submission is pending
the full compatibility, accessibility and packaging checks.

== Installation ==

1. Upload the coocadev-reader-notes directory to your WordPress plugins directory.
2. Activate Reader Notes on the individual site.
3. Insert a Reader Note block into an article, or use [ccrn_note].
4. Insert the My Notes block on an existing page, or use [ccrn_my_notes].
   Signed-in readers can also open Profile / Users > My notes.
5. Settings > Reader Notes controls allowed source types, optional automatic
   placement, optional storage quota and uninstall policy.

InnoDB is required for the plugin's two private tables. Writing is safely
disabled if transactional storage is unavailable. The plugin does not change
the storage engine of other WordPress tables.

Network activation is deliberately unsupported. Activate separately per site.

== Frequently Asked Questions ==

= Are notes encrypted or hidden from the server owner? =

No. They are private through the plugin interface/API: other readers, article
authors and editors cannot access someone else's notes. Site/database
administrators and backup operators can technically read stored data.
This is not an encrypted vault.

= Will it work with my membership or LMS plugin? =

Only standard WordPress post access is supported out of the box. A custom
membership system needs a tested deny-only ccrn_can_read_source filter.
The filter receives the explicit reader ID and a background-export flag.
It may deny access but cannot override a failed WordPress access check.
Do not use it for protected membership content before verifying the integration.

= What happens when access to an article is revoked? =

The reader can still read/export/delete their existing note in My Notes.
The closed source's title and URL are hidden, and edits linked to that source
are blocked. Permanently deleting a source leaves the note unlinked.

= What limits exist? =

A note accepts 10,000 Unicode characters and at most 40 KiB of UTF-8 text.
An optional site-owner quota limits creation only; editing/export/deletion
of existing notes remain available. There is no default note-count ceiling
and no paid unlock. These are storage and request-processing controls.

= What happens when I leave a tab or lose my connection? =

A failed save is never shown as Saved. Keep the tab open and retry or copy
your text. Drafts are kept only in memory, not localStorage, cookies or
IndexedDB. Hidden pages hide personal text until the session is rechecked.
Leaving a page clears its draft from the page snapshot; save before navigating.
The plugin cannot guarantee saving when a mobile browser closes the tab.

= How is list order determined? =

Newest-created notes first, using a stable note-ID cursor. Updating a note
does not move it between pages. Export reads bounded server-side batches;
a concurrent change while export runs is not a point-in-time database snapshot.

= How do I configure page caches? =

Public HTML contains a neutral shell only: no personal text, user ID or nonce.
Exclude coocadev-reader-notes/v1 REST routes and the ccrn_bootstrap admin-ajax
action from caching. Private responses use no-store. A proxy that ignores those
headers must be configured and tested before the feature is used.
No service worker or browser-persistent note cache is installed by this plugin.

= What data is stored and deleted? =

Per-site private tables store user ID, source ID, text, version and timestamps.
Notes are deleted on explicit deletion, confirmed WordPress privacy erasure,
and account/site-membership deletion while the plugin's lifecycle hooks are
loaded. A minimal epoch record
(user ID and counter, without note text) remains after erasure to reject delayed
old writes. After account removal it acts as a write-blocking tombstone.
Backups are under the site owner's control.

Deactivation keeps data. Uninstall keeps data by default. Enable "Delete all
plugin data when uninstalling" in Settings first to remove the plugin's two
tables and settings. In multisite each site's own preference is respected.
Reinstall to manage retained data; the plugin never deletes other plugins' data.

For per-site multisite activation, network-admin user removal runs in the
primary site's plugin context. Keep Reader Notes active on that primary site
when relying on its network user-removal cleanup, and verify the workflow.
An inactive plugin cannot receive those lifecycle events. Network activation
is intentionally unsupported; universal third-party/network cleanup is not
claimed.

= Can I use HTML in notes? =

Markup and shortcodes are treated as literal text. They are never rendered
as HTML or executed. Notes are not attachments or public comments.

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
* Initial implementation of private reader notes, conflict-safe storage,
  privacy tools, blocks, shortcodes and own-note management.

== Privacy ==

No note data is sent to Coocadev or any third party. No IP address, browser
fingerprint, analytics cookie or copied account email is stored by this plugin.
WordPress itself and the site's other plugins have their own privacy policies.

