# Reader Notes 0.2.0 release report

Date: 2026-10-15. Status: **local release candidate; final aggregate verification pending**. This report does not assert WordPress.org approval, production deployment, a fresh complete product regression matrix, or successful completion of the still-running all-fifteen system test.

## Scope and changes

Owner-only per-article plain-text notes, neutral block/shortcode shells, authenticated private REST, autosave/conflicts, text export, and erasure barriers against late writes.

Version 0.2.0 adds substantive English source comments/docblocks, explicit GPL/source/contribution guidance, an English developer guide and a public-safe project README, with the plugin/readme version bump. DispatchGo also advances its registered asset cache version. No runtime behavior change was intended by this documentation/version release. PHP, JavaScript and CSS remain readable source; no required Coocadev account, license key, paid feature gate, telemetry or mandatory shared runtime was added. Copyright 2026 Coocadev; GPL-2.0-or-later, full text in `plugin/LICENSE`.

## What was checked for this release

| Check | Observed result and boundary |
| --- | --- |
| Documentation phase | PHP token streams excluding comments/docblocks/whitespace matched the saved pre-comment baseline. PHP 8.1 lint and applicable Node syntax checks passed during that phase. This proof was recorded before the coordinator's 0.2.0 metadata/cache-version bump; it is not a substitute for final package syntax/hash checks. |
| README-only public cleanup | Original README preserved locally outside the public selection; before/after runtime SHA-256 maps were identical. |
| Metadata/source inspection | Current main header and readme declare 0.2.0, WordPress 6.9 / PHP 8.1 support floor and GPL version 2 or later. 13 expected readable runtime files are present. |
| Current installed Plugin Check | **0 errors / 52 warnings**, CLI exit 0, `local-combined`, 2026-10-15T12:21:21.2124870Z. Read from the actual current package-check output and its metadata, not inferred from the historical suite. |
| Final clean-install bytes, package-wide syntax and combined behavior | **PENDING in this report.** The coordinator is performing these checks; use the aggregate report below when finalized. |
| Actual Visual Studio Code / Xdebug session and fresh WP_DEBUG | **PENDING in this report.** No successful debugger/session-wide result is claimed here in advance. |

The actual Plugin Check output is retained locally at `reports/0.2.0/package-checks/coocadev-reader-notes.json` with a separate metadata file. Its extension does not make the mixed `FILE:`/JSON-array or plain-success CLI output a single valid JSON document. The public aggregate report owns the final exact-package disposition.

### Current warning disposition

26 DirectQuery and 24 NoCaching findings are prepared own-table reads/writes, owner locks, CAS/epoch barriers and privacy operations. Shared caching of note text, versions or erasure epochs would be inappropriate. Two SchemaChange findings are the explicit per-site opted-in uninstall of the two owned tables. All 52 warnings remain disclosed; scanner output is not a directory approval.

## Historical verification is not a rerun

The separately recorded 0.1.0 work reported 47 backend assertions on each WordPress/PHP stack, 20 real cookie HTTP checks, 11 Chromium UI checks, 22 dedicated multisite lifecycle checks, and two five-check preserve/delete Core uninstall runs on the minimum stack. The backend also exercised a 5,000-note fixture. These are historical coverage references, not a new full 0.2.0 execution.

The 0.1.0 artifact/evidence and the dated comment-phase verification remain unchanged in the maintainer workspace. They establish prior coverage and the narrow nature of this change; they do not prove the final rebuilt 0.2.0 ZIP, every integration, or the combined fifteen-plugin system. Current source/installation/archive identity must be demonstrated by the coordinator's exact release checks.

## Supported limits and open checks

- Notes are private through the interface/API, not encrypted against site/database administrators or backup operators. Free-form notes can contain personal data.
- Notes are limited to 10,000 Unicode characters / 40 KiB each. The default count quota is unlimited; administrator-selected creation quotas are free settings rather than license gates.
- Membership/LMS systems need a tested deny-only source-access integration. Exports are bounded, not transactionally frozen snapshots.
- Drafts exist only in page memory. Navigation/mobile tab closure cannot guarantee a last save; unconfirmed saves are not represented as saved.
- Default uninstall retains data. Opt-in removal deletes only owned tables/options. Network activation is rejected; network-admin removal callbacks require the plugin loaded on the primary site.
- Physical iPhone/Safari and Firefox, real cross-account BFCache, screen readers/RTL/zoom, persistent-cache compatibility and large production workload behavior remain separate checks.

The product/support URLs, directory screenshots/readme presentation, final name/slug availability and the remaining manual release checklist must be reviewed before a separate authorized directory submission. Another product's approval is not this product's approval.

## Release handoff

- [Developer guide](../../DEVELOPER.md): code map, hooks/data, permissions, concurrency/privacy decisions, examples and guarded disposable-local test entry points.
- [Aggregate package report](../../../../PACKAGE_REPORT.md): forthcoming/final authoritative all-fifteen build, clean installation, hashes, current scanner disposition and combined-test results. An old report must not stand in for this release.
- [Debug report](../../../../reports/0.2.0/DEBUG_REPORT.md): forthcoming/final actual VS Code/Xdebug and fresh WP_DEBUG evidence, including failures/open items if any.
- [Review-rule crosswalk](../../../../reports/0.2.0/WP_REVIEW_CROSSWALK.md): applicable reusable WordPress review lessons without private email evidence.

The links above may be completed by the coordinator after this product report freezes. Until their results are recorded, their checks remain pending here. Test scripts are source-only development material; local secrets, captured mail, databases and installed QA sites are excluded from runtime and public-source releases. No live email, payment, production mutation or publication was performed by this reporting task.

## Coordinator final package gate

The aggregate 0.2.0 gate is now complete: this exact runtime ZIP was installed together with all fifteen tools; source/ZIP/installed hashes and syntax matched, final installed Plugin Check had no errors, and the all-15 bootstrap/debug plus joint runtime/browser checks passed. See [aggregate report](../../../../PACKAGE_REPORT.md) and [debug report](../../../../reports/0.2.0/DEBUG_REPORT.md). This closes the earlier root-packaging handoff only; product-specific open/manual gates above remain open.
