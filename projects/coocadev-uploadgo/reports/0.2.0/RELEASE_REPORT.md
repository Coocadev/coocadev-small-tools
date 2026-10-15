# UploadGo 0.2.0 release report

Date: 2026-10-15. Status: **local release candidate; final aggregate verification pending**. This report does not assert WordPress.org approval, production deployment, a fresh complete product regression matrix, or successful completion of the still-running all-fifteen system test.

## Scope and changes

Restrictive global/all-role upload limits, server-side local-file inspection, native upload/finalize adapters, revisioned policy settings and optional bounded local diagnostics.

Version 0.2.0 adds substantive English source comments/docblocks, explicit GPL/source/contribution guidance, an English developer guide and a public-safe project README, with the plugin/readme version bump. DispatchGo also advances its registered asset cache version. No runtime behavior change was intended by this documentation/version release. PHP, JavaScript and CSS remain readable source; no required Coocadev account, license key, paid feature gate, telemetry or mandatory shared runtime was added. Copyright 2026 Coocadev; GPL-2.0-or-later, full text in `plugin/LICENSE`.

## What was checked for this release

| Check | Observed result and boundary |
| --- | --- |
| Documentation phase | PHP token streams excluding comments/docblocks/whitespace matched the saved pre-comment baseline. PHP 8.1 lint and applicable Node syntax checks passed during that phase. This proof was recorded before the coordinator's 0.2.0 metadata/cache-version bump; it is not a substitute for final package syntax/hash checks. |
| README-only public cleanup | Original README preserved locally outside the public selection; before/after runtime SHA-256 maps were identical. |
| Metadata/source inspection | Current main header and readme declare 0.2.0, WordPress 6.9 / PHP 8.1 support floor and GPL version 2 or later. 11 expected readable runtime files are present. |
| Current installed Plugin Check | **0 errors / 7 warnings**, CLI exit 0, `local-combined`, 2026-10-15T12:21:12.3733947Z. Read from the actual current package-check output and its metadata, not inferred from the historical suite. |
| Final clean-install bytes, package-wide syntax and combined behavior | **PENDING in this report.** The coordinator is performing these checks; use the aggregate report below when finalized. |
| Actual Visual Studio Code / Xdebug session and fresh WP_DEBUG | **PENDING in this report.** No successful debugger/session-wide result is claimed here in advance. |

The actual Plugin Check output is retained locally at `reports/0.2.0/package-checks/coocadev-uploadgo.json` with a separate metadata file. Its extension does not make the mixed `FILE:`/JSON-array or plain-success CLI output a single valid JSON document. The public aggregate report owns the final exact-package disposition.

### Current warning disposition

Four DirectQuery and three NoCaching warnings concern prepared authoritative policy reads and exact-value compare-and-swap. WordPress update_option alone does not provide that conflict guarantee. Values remain typed/prepared, and direct cache bypass has a specific consistency purpose. Seven warnings remain reported; no blanket claim of warning-free analysis or directory acceptance is made.

## Historical verification is not a rerun

The separately recorded 0.1.0 work reported 47 integration assertions on each current/minimum stack, 25 real HTTP checks, seven Chromium UI checks, and 13 Core lifecycle checks on each stack. Current/minimum referred to WordPress 7.1.3 / PHP 8.5.5 and WordPress 6.9 / PHP 8.1.34. Those historical runs are not claimed as reruns of 0.2.0.

The 0.1.0 artifact/evidence and the dated comment-phase verification remain unchanged in the maintainer workspace. They establish prior coverage and the narrow nature of this change; they do not prove the final rebuilt 0.2.0 ZIP, every integration, or the combined fifteen-plugin system. Current source/installation/archive identity must be demonstrated by the coordinator's exact release checks.

## Supported limits and open checks

- Policy starts disabled. Administrator roles are not a bypass; the strictest applicable rules intersect with Core/server restrictions.
- PHP Fileinfo is required for active policy. Image limits are not antivirus, complete decompression-bomb protection or animation-frame limits.
- Direct filesystem/SFTP writes are outside scope. Third-party widgets/importers/XML-RPC, MIME integrations, HEIC/Imagick/offload, client-side derivatives and multisite need separate compatibility tests.
- Deactivation clears diagnostics/cleanup scheduling and retains policy/media; default uninstall retains settings. Opt-in removal deletes owned options, never media or roles.
- Corrupt stored schema remains available for repair with visible Core-only fallback; additional UploadGo restrictions are not promised during that state.
- Physical Safari/Firefox, screen readers, RTL/translations, persistent-cache behavior and large-scale performance remain open beyond the historical bounded checks.

The product/support URLs, directory screenshots/readme presentation, final name/slug availability and the remaining manual release checklist must be reviewed before a separate authorized directory submission. Another product's approval is not this product's approval.

## Release handoff

- [Developer guide](../../DEVELOPER.md): code map, hooks/data, permissions, concurrency/privacy decisions, examples and guarded disposable-local test entry points.
- [Aggregate package report](../../../../PACKAGE_REPORT.md): forthcoming/final authoritative all-fifteen build, clean installation, hashes, current scanner disposition and combined-test results. An old report must not stand in for this release.
- [Debug report](../../../../reports/0.2.0/DEBUG_REPORT.md): forthcoming/final actual VS Code/Xdebug and fresh WP_DEBUG evidence, including failures/open items if any.
- [Review-rule crosswalk](../../../../reports/0.2.0/WP_REVIEW_CROSSWALK.md): applicable reusable WordPress review lessons without private email evidence.

The links above may be completed by the coordinator after this product report freezes. Until their results are recorded, their checks remain pending here. Test scripts are source-only development material; local secrets, captured mail, databases and installed QA sites are excluded from runtime and public-source releases. No live email, payment, production mutation or publication was performed by this reporting task.

## Coordinator final package gate

The aggregate 0.2.0 gate is now complete: this exact runtime ZIP was installed together with all fifteen tools; source/ZIP/installed hashes and syntax matched, final installed Plugin Check had no errors, and the all-15 bootstrap/debug plus joint runtime/browser checks passed. See [aggregate report](../../../../PACKAGE_REPORT.md) and [debug report](../../../../reports/0.2.0/DEBUG_REPORT.md). This closes the earlier root-packaging handoff only; product-specific open/manual gates above remain open.
