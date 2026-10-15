# Coocadev Reader Notes

Reader Notes lets signed-in readers keep their own private plain-text notes per article. Version **0.2.0** is a local release candidate; it is not a claim of WordPress.org approval.

## Requirements and setup

WordPress 6.9+, PHP 8.1+ and InnoDB are required. Install [plugin/](plugin/) as `coocadev-reader-notes` and activate on an individual site. Insert a **Reader Note** block into an article and a **My Notes** block on a page, or use `[ccrn_note]` and `[ccrn_my_notes]`. Configure supported source types, optional automatic placement, storage quota and retention under **Settings > Reader Notes**. Signed-in readers can also open their native profile notes page.

## Features and scope

- Owner-only note reads, edits, search, text export, individual deletion and delete-all.
- Debounced autosave with manual save, explicit tab-conflict handling and bounded requests.
- Native dynamic blocks and shortcodes render neutral shells before the authenticated private API fills them.
- WordPress personal-data export and erasure, with a concurrency barrier against late stale autosaves.
- An optional administrator-selected creation quota; no quota is imposed by default.

Each note accepts at most 10,000 Unicode characters and 40 KiB of UTF-8. Notes are private through the plugin's interface/API, not encrypted from site/database administrators or backup operators. Membership/LMS access requires a tested deny-only integration. Losing source access hides its title/link and blocks edits while retaining the reader's ability to read/export/delete their existing note. Drafts live only in page memory; a failed save is not a saved note.

## Retention and privacy

Deactivation and default uninstall retain notes and settings. Explicit per-site data removal deletes only the two owned tables and settings. Active privacy/account/membership-removal hooks remove the relevant reader text. Network activation is unsupported; on multisite, network-admin removal callbacks require the plugin to be loaded on the primary site. Exclude the private REST namespace and session bootstrap Ajax action from shared caches.

## Source, development and verification

Copyright 2026 Coocadev. **GPL-2.0-or-later**; see the full [license](plugin/LICENSE). All shipped features are available without a license key, paid unlock or Coocadev account. PHP/JS/CSS and block JSON are the complete preferred source; no compilation or shared Coocadev runtime is required. Runtime packages include only `plugin/`.

The English [developer guide](DEVELOPER.md) explains ownership, hooks/routes, schema, autosave queues, erasure barriers, integration examples and local test entry points. The [0.2.0 release report](reports/0.2.0/RELEASE_REPORT.md) records exact-release checks and remaining browser/integration limits. Fixtures create or erase disposable local notes; never run them on production. Contributions follow the shared [contribution policy](../../CONTRIBUTING.md).
