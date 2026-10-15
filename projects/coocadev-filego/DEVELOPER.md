# FileGo developer guide

FileGo maintains one public PDF alias while each physical version has its own
immutable filename. This guide covers the readable source in `plugin/`, the
consistency rules, and the places to change when contributing.

## License and source

Copyright (c) 2026 Coocadev. SPDX-License-Identifier: GPL-2.0-or-later.
The plugin code and this guide may be studied, used, modified and redistributed
under GNU GPL version 2 or any later version. Preserve notices and provide
corresponding source when distributing modified copies under the GPL. The full
GPLv2 text is in `plugin/LICENSE`; the file-level later-version notice grants the
later-version choice. There is no license key or paid unlock for shipped features.

PHP, CSS and documentation are the source; there is no compilation, minification
or external runtime bundle. An installable directory is the contents of `plugin/`
under the slug `coocadev-filego`. Developer fixtures and reports stay outside it.

## Code map and request flow

| File | Responsibility |
| --- | --- |
| `plugin/coocadev-filego.php` | Namespace, readable requires, lifecycle registration |
| `plugin/src/class-plugin.php` | Hook wiring, pretty/plain aliases and GET/HEAD redirect |
| `plugin/src/class-store.php` | Site-prefixed schema, bounded repository reads, InnoDB check |
| `plugin/src/class-documents.php` | PDF validation, immutable upload, publication, deletion recovery |
| `plugin/src/class-admin.php` | Native Media screen, typed POST adapters, action nonces, diagnostics |
| `plugin/src/class-privacy.php` | WordPress privacy authorship export/anonymization |
| `plugin/uninstall.php` | Opt-in removal of FileGo records while preserving media |

An administrative POST checks its method, capability and action nonce before
calling Documents. The service checks management authority again, validates the
site's attachment/path graph, and performs the intended write. The public alias
reads the current pointer, verifies local ownership, and emits a no-store 302.
It never reads the whole PDF body to serve a download.

## Hooks, permissions and data

`plugins_loaded` boots handlers. `init` registers `^filego/([a-z0-9-]+)/?$`,
`query_vars` admits `ccfg_download`, and `template_redirect` resolves the alias.
Admin changes use `admin_post_ccfg_*`; `pre_delete_attachment` and `pre_trash_post`
protect managed media from accidental removal outside the controlled flow.

Only `ccfg_manage_documents` grants document management; activation grants it to
administrators. Uploads additionally require `upload_files`, and deletion checks
`delete_post` on the exact attachment. Nonces never replace those capabilities.
All uploaded PDFs, including staged/retired versions, are public.

`{$wpdb->prefix}ccfg_documents` stores title, unique slug, current version pointer,
revision/status and UTC times. `ccfg_versions` stores attachment ID, immutable
relative path, bytes/SHA-256, state/delete token, note and uploader attribution.
Attachment markers are `_ccfg_owner` and `_ccfg_version_id`. Options are
`ccfg_schema_version`, `ccfg_remove_data` and `ccfg_warning_mb`; bounded temporary
same-site diagnostics use `ccfg_check_*` transients.

## Consistency and deletion

Publication checks SHA-256, then locks document before version and compares the
submitted revision. Keep that order in every new transactional operation.
Do not persistently cache the public pointer or replace a version in place.

File deletion cannot share a database transaction. The code commits
`delete_pending` first, then authorizes one exact in-process attachment/token
delete. A pending version is never publishable. Retry/recovery distinguishes an
already removed attachment from a file that may still need administrator repair.
Do not replace this state machine with a direct `unlink` or broad deletion bypass.

## Lifecycle and privacy

Activation is per-site; network activation is rejected. Deactivation removes the
alias rewrite and keeps documents/media. Default uninstall retains FileGo data.
The explicit removal option drops only its records/settings/capabilities/markers;
physical PDFs and WordPress media records remain. Privacy erasure removes author
references and optional notes, preserving public documents. Old direct URLs and
downloaded copies cannot be revoked by clearing the alias.

## Local development and an example

On an isolated WordPress, activate the plugin, create slug `menu`, upload a trusted
PDF and publish it. Link to the logical alias, never build an uploads path:

```php
$url = \Coocadev\FileGo\Plugin::url( 'menu' );
echo '<a href="' . esc_url( $url ) . '">Download menu</a>';
```

The namespace classes are implementation details rather than a versioned public
SDK. Changes to HTTP handling must retain the same permissions/nonces as the
native screen. A preview of a public PDF must not be described as private storage.

`tests/integration.php`, `tests/lifecycle.php`, `tests/worker.php` and
`tests/browser.cjs` exercise the domain, concurrency, lifecycle and native UI.
Read their local environment assumptions before running them: they use disposable
data and are not production maintenance commands. Syntax checks are `php -l`
for each PHP file. Historical exact-release evidence is in `reports/0.1.0/`.
Comment-only documentation verification has its own dated report; it does not
relabel old runtime passes as tests of a rebuilt ZIP.

Contributions should include a small focused patch, an explanation of the user
effect and a regression for changed behavior. Keep readable English comments for
non-obvious privacy, ownership and transaction choices. Never include credentials,
private PDFs, customer data or review emails. Product/source publication and the
remaining browser/cache/multisite release gates are tracked separately.
