# DoneMark developer guide

DoneMark records independent editorial marks in native WordPress lists. Review
marks compare a fingerprint of persisted text; distribution marks record a task
without publishing or sending anything. Keep these meanings distinct.

## License and source

Copyright (c) 2026 Coocadev. SPDX-License-Identifier: GPL-2.0-or-later.
The code and this guide are free software under GNU GPL version 2 or later.
Study, use, modify and redistribute them subject to that license, preserving
notices and supplying corresponding source when required. `plugin/LICENSE`
contains the full GPLv2 text; the later-version notice grants the later-version
choice. No shipped feature requires payment, registration or a license key.

PHP/JS/CSS are the complete unminified source. No compilation, npm build, remote
runtime or shared Coocadev plugin is required. Install `plugin/` contents under
`coocadev-donemark`; development tests/reports do not belong in the runtime ZIP.

## Code map and entry points

| File | Responsibility |
| --- | --- |
| `plugin/coocadev-donemark.php` | Activation, table/settings setup and module wiring |
| `plugin/src/class-settings.php` | Stable UUID definitions, validation, CAS, shared deletion/write lock |
| `plugin/src/class-states.php` | Persisted content hash, object access, state CAS and post cleanup |
| `plugin/src/class-rest.php` | Strict private single/bulk API and no-store headers |
| `plugin/src/class-admin.php` | Settings, native lists, Quick Edit, bounded bulk and scoped filters |
| `plugin/src/class-privacy.php` | Current actor attribution export/anonymization |
| `plugin/assets/admin.js` | List controls, saved-content Quick Edit ordering and per-item errors |
| `plugin/uninstall.php` | Per-site explicit delete-data cleanup |

The settings UI manages definitions under `manage_options`. Reading/changing
marks requires `edit_post` for the exact non-trashed object, not just a role name.
Admin POST actions `ccdm_save` / `ccdm_delete` repeat method/capability/nonce
checks. Native list integration is scoped via `current_screen`, `the_posts`,
`restrict_manage_posts` and `posts_where`; unrelated queries are not altered.
Core Quick Edit replaces rows through Ajax without necessarily firing
`current_screen`, so its nonce/capability-checked `wp_ajax_inline-save` adapter
restores column hooks before Core renders the new row.

## Private API and storage

Namespace `coocadev-donemark/v1` provides GET `/posts/{id}/marks`, PUT
`/posts/{id}/marks/{mark_id}` and POST `/bulk`. A change contains only `done`
(boolean), `version` (integer) and `content_hash` (64 hex characters). Bulk
requests contain 1-100 distinct post/mark items, fully shape-validated before
processing; results remain independent and include per-object failures.
Core cookie REST nonce checks and object capabilities are both required.
Private responses have no-store and vary by Cookie/Authorization.

`ccdm_settings` contains schema/revision, at most 12 mark definitions and the
uninstall choice. UUIDs remain stable when labels change; a mark's type is
immutable. Archiving hides a definition and preserves history; deletion is a
separate confirmed operation. `ccdm_schema` records the owned table version.
`{$wpdb->prefix}ccdm_states` has `(post_id,mark_id)` primary key, done flag,
reviewed hash/hash version, last actor, UTC time and row version.

Every state write and definition/post deletion shares the settings-row lock,
requiring InnoDB for options and the owned states table. Keep that common lock
and the optimistic row version together: removing either can leave orphaned rows
or silently overwrite another editor. Identical replay preserves attribution.
Fresh definition and persisted content reads deliberately avoid stale caches.

## Review semantics and a read example

Fingerprint version 1 hashes length-prefixed bytes of saved `post_title`,
`post_content` and `post_excerpt`. It does not include SEO settings, media,
taxonomies, dynamic output or an assessment of accuracy. A changed fingerprint
makes review stale; restoring exactly reviewed content matches it again.
Distribution marks remain done when content changes.

In trusted authorized server-side code, prefer the domain read instead of
reimplementing permission and fingerprint rules:

```php
$state = \Coocadev\DoneMark\States::view( $post_id );
if ( ! is_wp_error( $state ) ) {
    foreach ( $state['marks'] as $mark ) {
        echo esc_html( $mark['label'] );
    }
}
```

These classes are internal implementation details, not a promised public SDK.
Do not expose this example as an unauthenticated endpoint. Quick Edit first saves
Core content, then submits only changed marks with the new persisted fingerprint;
an untouched review must become stale after a text edit.

## Lifecycle, privacy and verification

Network activation is rejected; activate per site. Deactivation/default uninstall
preserve data. Explicit removal drops only DoneMark's own table/options, respecting
each site's setting; it never deletes posts or users. WordPress privacy export
returns current actor attribution. Erasure anonymizes the requested subject and
bumps state versions while keeping team states. Shared labels may need manual
review for personal information; no article copies are stored in the states table.

`tests/integration.php` covers definitions, independent states, CAS/fingerprints,
permissions/privacy and real REST; `worker.php` covers process concurrency;
`lifecycle.php` covers retention; `browser.cjs` exercises native list/Quick Edit
and bulk. Read fixture environment assumptions and use disposable sites only.
Run `php -l` for PHP and `node --check plugin/assets/admin.js` for syntax.
Historical exact-artifact results/open gates stay in `reports/0.1.0/`; the new
documentation-only report does not replace full release verification.

Contributions should be focused, use English comments for non-obvious concurrency
and privacy choices, and add regressions for changed behavior. Keep real editorial
data, fixture credentials, databases and private review emails out of public source.
