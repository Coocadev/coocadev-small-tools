# Reader Notes developer guide

Reader Notes gives each authenticated reader private plain-text notes linked to
articles. Ownership, source access, concurrency and erasure are distinct concerns;
preserve all four when extending this implementation.

## License and readable source

Copyright (c) 2026 Coocadev. SPDX-License-Identifier: GPL-2.0-or-later.
You may study, use, modify and redistribute the plugin and this guide under GNU
GPL version 2 or later. Preserve notices and provide corresponding source as the
license requires for distributed derivatives. `plugin/LICENSE` contains GPLv2;
the later-version notice grants the later-version choice. Shipped features have
no license-key, registration or paid-unlock dependency.

The PHP/JS/CSS and block JSON are complete, readable source, with no minification,
build step or mandatory shared Coocadev runtime. Install the contents of `plugin/`
as `coocadev-reader-notes`. Keep tests, reports and private local fixtures outside
an installable package. Namespace classes are internal, not a stable public SDK.

## Code map and private request flow

| File | Responsibility |
| --- | --- |
| `plugin/coocadev-reader-notes.php` | Per-site activation and bootstrap |
| `plugin/src/class-database.php` | Private schema, owner lock, CAS, quota and epoch erasure |
| `plugin/src/class-access.php` | Site membership and explicit-reader source access |
| `plugin/src/class-rest.php` | Owner-only typed endpoints, session bootstrap and no-store |
| `plugin/src/class-plugin.php` | Neutral shells, dynamic blocks/shortcodes, settings/lifecycle/privacy |
| `plugin/assets/notes.js` | Session-aware in-memory UI, autosave queue and explicit conflicts |
| `plugin/assets/blocks.js` | Neutral editor placeholders; no serialized note data |
| `plugin/blocks/*/block.json` | Local dynamic block metadata |
| `plugin/uninstall.php` | Per-site explicit delete-data cleanup |

HTML rendering emits a neutral shell and local non-personal configuration.
`wp_ajax_ccrn_bootstrap` returns current session state and a REST nonce with
no-store. The browser then loads owned notes from REST. WordPress authenticates
the request before the permission callback; cookie REST authentication requires
the Core nonce. User/author/owner parameters are rejected, and the current user
ID is repeated in repository constraints. Same-origin browser checks do not
replace authentication or ownership.

## Routes, permissions and storage

Namespace: `coocadev-reader-notes/v1`. GET `/notes`, `/notes/{id}` and
`/posts/{post_id}/note` read owned data; PUT `/posts/{post_id}/note` saves it;
DELETE `/notes/{id}` deletes one note; POST `/export` and `/erase` perform bounded
owned operations. Site membership and `read` are required. All private successes
and errors receive no-store; permissive cross-origin disclosure is not enabled.
Exclude this namespace and the bootstrap Ajax action from any upstream cache.

Two per-site InnoDB tables, `ccrn_notes` and `ccrn_user_state`, store owner/source
IDs, plain text, version/UTC times and erasure state. `(user_id,post_id)` is unique.
Settings/schema options are `ccrn_settings` and `ccrn_schema_version`.
The optional administrator quota limits new-note creation; default is unlimited.
Each note is limited to 10,000 Unicode characters and 40 KiB UTF-8.

Every mutation locks the owner's state row before checking epoch and note
version. Erasure first commits an incremented epoch/operation barrier, then
deletes bounded batches. This rejects delayed old autosaves. Account deletion
retains only a write-blocking epoch tombstone without text; restoration advances
the barrier. Do not cache private text/version/epoch in a shared object cache.

The browser shares one queue for duplicate blocks of the same article, ignores
old-session responses and keeps drafts only in memory. Hidden/page lifecycle
events clear private DOM and require session recheck. Unconfirmed saves remain
errors/unsaved state; conflict replacement needs explicit reader action.

## Source access and integration example

Losing article access blocks edits and hides the source title/link while letting
the reader read/export/delete their own existing note. Removing a source detaches
it; it does not destroy the reader's text. Admin/database operators can access
stored data; this plugin is not encryption or a secret vault.

Membership integrations may further deny, but never override failed Core access:

```php
add_filter( 'ccrn_can_read_source', function ( $allowed, $post_id, $reader_id, $background ) {
    // Replace this illustrative restriction with your tested membership rule.
    // Use $reader_id, including during another administrator's privacy export.
    $restricted = (bool) get_post_meta( $post_id, '_example_restricted', true );
    return $allowed && ( ! $restricted || user_can( $reader_id, 'manage_options' ) );
}, 10, 4 );
```

Test both foreground and background export; do not rely on the current admin's
identity. Classic placement uses `[ccrn_note]` and `[ccrn_my_notes]`; dynamic blocks
and optional `the_content` placement use the same neutral shells.

## Lifecycle, tests and contributions

Network activation is rejected. Per-site activation checks InnoDB. Deactivation
retains data; default uninstall retains it. Explicit per-site delete-data opt-in
drops only the two owned tables/settings. WordPress privacy exporters/erasers and
user/site-membership removal hooks delete owned text while loaded. In multisite,
keep the plugin active on the primary site for network-admin removal callbacks;
an inactive per-site plugin cannot receive those events.

`tests/integration.php` exercises ownership, CAS, limits and privacy barriers;
`http.mjs` tests actual authenticated boundaries; `browser.cjs` tests queues and
UI; `multisite-lifecycle.php` and `single-lifecycle.php` cover lifecycle scope.
Run only against isolated disposable sites after reading fixture requirements.
Syntax checks are `php -l` for PHP and `node --check` for both JS sources.
Exact historical results/open browser/performance gates are in `reports/0.1.0/`;
comment-only documentation has separate dated evidence, not a new runtime pass.

Contribute focused English-commented patches with behavioral regressions for
ownership, erasure or session changes. Never include real notes, account data,
private fixture JSON, databases, credentials or review emails in public source.
