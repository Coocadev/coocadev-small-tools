# Coocadev FileGo

FileGo keeps a stable public link to a PDF while its physical versions remain separate. Version **0.2.0** is a local release candidate; it is not a claim of WordPress.org approval.

## Requirements and setup

WordPress 6.9+, PHP 8.1+, local WordPress uploads and InnoDB are required. Install the contents of [plugin/](plugin/) as `coocadev-filego` and activate on an individual site. Open **Media > FileGo**, create a document alias, upload a trusted PDF, and publish its staged version. Link to `/filego/your-alias/`; plain-permalink installations use the equivalent query URL shown by the plugin.

## Features and scope

- Immutable PDF version files, staged publication, version notes, history and rollback.
- Revision checks and transactions for publication; a recoverable two-phase flow for physical deletion.
- Public GET/HEAD aliases use a no-store 302 redirect to the currently published version.
- Document management uses a dedicated capability; uploads and deletion also check the relevant WordPress capabilities.

Every uploaded PDF version is public, including staged and retired files. FileGo is not private document storage. Direct URLs or downloaded copies of older files cannot be revoked by changing the alias. Offloaded media and non-transactional storage are outside the supported scope. Network activation is unsupported.

## Retention and privacy

Deactivation and default uninstall retain FileGo data. An explicit uninstall choice removes only owned records, settings, capabilities and markers; WordPress attachments and physical PDF files remain. Privacy erasure removes uploader attribution and optional version notes while preserving the public document. Review backups and old public copies separately.

## Source, development and verification

Copyright 2026 Coocadev. **GPL-2.0-or-later**; see the full [license](plugin/LICENSE). All shipped features are available without a license key, paid unlock or mandatory Coocadev account. Readable PHP/CSS are the preferred source and require no build step. Runtime packages include only `plugin/`.

See the English [developer guide](DEVELOPER.md) for the code map, request flow, hooks, data model, security/concurrency decisions, examples and local test entry points. See [dependencies](DEPENDENCIES.md) and the [0.2.0 release report](reports/0.2.0/RELEASE_REPORT.md) for exact-release checks and remaining limitations. Test scripts use disposable local fixtures; never run mutation or uninstall tests against production. Contributions follow the shared [contribution policy](../../CONTRIBUTING.md).
