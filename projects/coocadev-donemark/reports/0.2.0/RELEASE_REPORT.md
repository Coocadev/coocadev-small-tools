# DoneMark 0.2.0 release report

Date: 2026-10-15. Status: **local release candidate; final aggregate verification pending**. This report does not assert WordPress.org approval, production deployment, a fresh complete product regression matrix, or successful completion of the still-running all-fifteen system test.

## Scope and changes

Independent editorial review/distribution marks in native lists, saved-text fingerprints, Quick Edit and bounded bulk actions with per-object permissions and concurrency control.

Version 0.2.0 adds substantive English source comments/docblocks, explicit GPL/source/contribution guidance, an English developer guide and a public-safe project README, with the plugin/readme version bump. DispatchGo also advances its registered asset cache version. No runtime behavior change was intended by this documentation/version release. PHP, JavaScript and CSS remain readable source; no required Coocadev account, license key, paid feature gate, telemetry or mandatory shared runtime was added. Copyright 2026 Coocadev; GPL-2.0-or-later, full text in `plugin/LICENSE`.

## What was checked for this release

| Check | Observed result and boundary |
| --- | --- |
| Documentation phase | PHP token streams excluding comments/docblocks/whitespace matched the saved pre-comment baseline. PHP 8.1 lint and applicable Node syntax checks passed during that phase. This proof was recorded before the coordinator's 0.2.0 metadata/cache-version bump; it is not a substitute for final package syntax/hash checks. |
| README-only public cleanup | Original README preserved locally outside the public selection; before/after runtime SHA-256 maps were identical. |
| Metadata/source inspection | Current main header and readme declare 0.2.0, WordPress 6.9 / PHP 8.1 support floor and GPL version 2 or later. 11 expected readable runtime files are present. |
| Current installed Plugin Check | **0 errors / 12 warnings**, CLI exit 0, `local-combined`, 2026-10-15T12:21:26.1815942Z. Read from the actual current package-check output and its metadata, not inferred from the historical suite. |
| Final clean-install bytes, package-wide syntax and combined behavior | **PENDING in this report.** The coordinator is performing these checks; use the aggregate report below when finalized. |
| Actual Visual Studio Code / Xdebug session and fresh WP_DEBUG | **PENDING in this report.** No successful debugger/session-wide result is claimed here in advance. |

The actual Plugin Check output is retained locally at `reports/0.2.0/package-checks/coocadev-donemark.json` with a separate metadata file. Its extension does not make the mixed `FILE:`/JSON-array or plain-success CLI output a single valid JSON document. The public aggregate report owns the final exact-package disposition.

### Current warning disposition

Six DirectQuery and six NoCaching warnings concern prepared authoritative own-table state/settings operations and consistency/privacy boundaries. Shared definition/state locking and exact revision checks are deliberate. The current Plugin Check count is 12 warnings, not the historical complete WPCS count of 13; these are different tool runs and must not be conflated.

## Historical verification is not a rerun

The separately recorded 0.1.0 work reported 50 integration assertions on each current/minimum WordPress/PHP stack, 12 current-stack lifecycle checks, and nine Chromium UI checks on each stack. It covered Quick Edit ordering, stale review, Pages bulk controls and concurrent definition/post deletion. This documentation pass did not repeat that complete matrix for 0.2.0.

The 0.1.0 artifact/evidence and the dated comment-phase verification remain unchanged in the maintainer workspace. They establish prior coverage and the narrow nature of this change; they do not prove the final rebuilt 0.2.0 ZIP, every integration, or the combined fifteen-plugin system. Current source/installation/archive identity must be demonstrated by the coordinator's exact release checks.

## Supported limits and open checks

- A review mark compares persisted title, content and excerpt; it is not factual/SEO validation and does not cover media, taxonomies or dynamic output.
- Distribution marks do not publish, distribute or send anything. At most 12 stable definitions are supported.
- InnoDB is required for the common options/state locking model. Network activation is unsupported; full network lifecycle and third-party editor/CPT compatibility remain separate checks.
- Deactivation/default uninstall retain data. Explicit cleanup deletes only owned table/options, never posts/users. Privacy erasure anonymizes actor attribution while retaining shared team states.
- Shared labels need separate personal-data review. Persistent-cache, large workload, physical Safari/Firefox, screen-reader/RTL and broader translation checks remain open beyond the historical bounded tests.

The product/support URLs, directory screenshots/readme presentation, final name/slug availability and the remaining manual release checklist must be reviewed before a separate authorized directory submission. Another product's approval is not this product's approval.

## Release handoff

- [Developer guide](../../DEVELOPER.md): code map, hooks/data, permissions, concurrency/privacy decisions, examples and guarded disposable-local test entry points.
- [Aggregate package report](../../../../PACKAGE_REPORT.md): forthcoming/final authoritative all-fifteen build, clean installation, hashes, current scanner disposition and combined-test results. An old report must not stand in for this release.
- [Debug report](../../../../reports/0.2.0/DEBUG_REPORT.md): forthcoming/final actual VS Code/Xdebug and fresh WP_DEBUG evidence, including failures/open items if any.
- [Review-rule crosswalk](../../../../reports/0.2.0/WP_REVIEW_CROSSWALK.md): applicable reusable WordPress review lessons without private email evidence.

The links above may be completed by the coordinator after this product report freezes. Until their results are recorded, their checks remain pending here. Test scripts are source-only development material; local secrets, captured mail, databases and installed QA sites are excluded from runtime and public-source releases. No live email, payment, production mutation or publication was performed by this reporting task.

## Coordinator final package gate

The aggregate 0.2.0 gate is now complete: this exact runtime ZIP was installed together with all fifteen tools; source/ZIP/installed hashes and syntax matched, final installed Plugin Check had no errors, and the all-15 bootstrap/debug plus joint runtime/browser checks passed. See [aggregate report](../../../../PACKAGE_REPORT.md) and [debug report](../../../../reports/0.2.0/DEBUG_REPORT.md). This closes the earlier root-packaging handoff only; product-specific open/manual gates above remain open.
