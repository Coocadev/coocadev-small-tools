# Coocadev DoneMark

DoneMark adds independent editorial checkmarks to native WordPress post lists and detects when reviewed text has changed. Version **0.2.0** is a local release candidate; it is not a claim of WordPress.org approval.

## Requirements and setup

WordPress 6.9+, PHP 8.1+ and InnoDB for the options/owned state tables are required. Install [plugin/](plugin/) as `coocadev-donemark` and activate on an individual site. Open **Settings > DoneMark** to define up to 12 marks and supported post types. Authorized editors can then use list columns, Quick Edit, filters and bounded bulk actions.

## Features and scope

- Stable mark identifiers and independent review/distribution states per post.
- Review marks compare a fingerprint of persisted title, content and excerpt; changed text becomes stale until reviewed again.
- Distribution marks record a team task and remain done when article text changes.
- Per-object capabilities, revision checks and a common settings/state lock protect concurrent changes and definition deletion.
- Native list and Quick Edit controls report per-item errors without silently discarding conflicts.

A mark does not assess factual accuracy or publish/send/distribute anything. Fingerprints do not include SEO metadata, media, taxonomies or dynamic output. Restoring exactly the previously reviewed text matches its fingerprint again. Archiving a definition hides it while preserving history; deletion is a separate operation. Network activation is unsupported.

## Retention and privacy

Deactivation and default uninstall retain definitions and states. Explicit per-site data removal deletes only DoneMark's table/options, never posts or users. WordPress privacy export includes actor attribution; erasure anonymizes that actor and advances state versions while preserving shared team state. Review free-form shared labels for personal information separately. Private REST responses use no-store headers.

## Source, development and verification

Copyright 2026 Coocadev. **GPL-2.0-or-later**; see the full [license](plugin/LICENSE). Shipped features do not require payment, registration or a license key. Readable PHP/JS/CSS are the complete preferred source; no build step or mandatory Coocadev runtime is needed. Runtime packages include only `plugin/`.

See the English [developer guide](DEVELOPER.md) for the code map, hooks/private API, data model, fingerprints, locking, examples and guarded local test entry points; see [dependencies](DEPENDENCIES.md) for runtime assumptions. The [0.2.0 release report](reports/0.2.0/RELEASE_REPORT.md) records exact-release checks and open compatibility work. Lifecycle fixtures intentionally remove disposable plugin data; never run them on production. Contributions follow the shared [contribution policy](../../CONTRIBUTING.md).
