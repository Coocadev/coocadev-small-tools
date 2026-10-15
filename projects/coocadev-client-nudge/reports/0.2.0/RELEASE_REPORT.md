# Client Nudge 0.2.0 — local release evidence

Date: 2026-10-15. Status: implemented local candidate; no production change or WordPress.org submission. Final ZIP build, SHA256 and exact-ZIP combined smoke are handled by the parent release process; this report does not invent a ZIP hash or directory approval.

Final matrix: WordPress7.1.3/PHP8.5.5 and WordPress6.9/PHP8.1.34, existing local MariaDB QA service on127.0.0.1:3317. Sources under `plugin/`; normal copied real plugin directories on `qa/sites/reader-notes` and `qa/min-sites/reader-notes`; no source junctions used. Developer source remains on D:.

| Check | Observed result | Evidence |
| --- | --- | --- |
| Domain/current | 23/23 assertions | integration-current-final.log |
| Domain/minimum | 23/23 assertions | integration-minimum-final.log |
| Actual HTTP | 7 plugin-relevant assertions including shared unchanged-state check | http-boundaries.json |
| Concurrent weekly claim | 8 separate WP-CLI workers; exactly1 mail accepted | concurrency.json |
| Separate-process lifecycle | 5/5 preserve/delete/schedule assertions | lifecycle.json |
| PHP8.1 syntax | All3 runtime PHP files pass | lint-php81.log |
| WPCS | 0 errors,9 reviewed warnings | wpcs.json |
| PHPCompatibilityWP8.1 | 0 errors,0 warnings | phpcompatibility.json |
| Plugin Check2.1.0 | 0 errors,10 reviewed warnings | plugin-check.log / plugin-check.json |
| WP_DEBUG observation | No new bytes/errors in the observed current-site run; historical WP-CLI Colors.php deprecation from Oct14 is not a new plugin error | wp-debug-delta.json |
| Runtime source | Full preferred PHP source, no minification/build, GPL2-or-later license | source-manifest.json / plugin/LICENSE |

Commands actually used: portable PHP8.1 `-l`; PHPCS3.13.6 `--standard=WordPress --report=json`; `--standard=PHPCompatibilityWP --runtime-set testVersion 8.1-`; WP-CLI `plugin activate`, `eval-file tests/integration.php`, `plugin check coocadev-client-nudge --format=json`; local Python `tests/http-boundaries.py`, `tests/concurrency.py`, `tests/lifecycle.py`. HTTP used localhost8783 and synthetic ephemeral administrator/subscriber accounts. No test passwords are printed or stored. Every test entry point refuses nonlocal/non-WP-CLI/remote-site execution before writes. All test mail is intercepted by `pre_wp_mail`, never delivered.

Behavior covered: private administrator create; invalid date; client create denial; recipient-only consent; cross-owner completion denial; Core editor post access denied; private board/cache exclusion; no consent=no reminder; pending-only contents; same-week deduplication; next-week eligibility; opt-out; privacy export/erase including trash; anonymous denial. HTTP verifies admin render, GET403, invalid nonce403, malformed array400, subscriber create denial and persisted settings unchanged. Lifecycle uses separate uninstall processes, never repeated include_once. A first test-run harness failed because WP-CLI refuses uninstalling an active plugin; the corrected test explicitly deactivates before uninstall, then passed. This was not a product failure.

Reviewed warnings: three native meta-query performance warnings remain because the 50-row query is explicitly scoped by assignee/consent; large-site performance remains OPEN rather than claimed solved. Six direct/no-cache SQL warnings refer to prepared option-name enumeration for bounded privacy/expiry cleanup and uninstall; WordPress has no equivalent option-prefix iterator. Option deletion still uses `delete_option()` for cache invalidation. Plugin Check adds one warning for standard `DONOTCACHEPAGE`; renaming that integration constant would break cache exclusion. Narrow scalar-input/pagination sniff exceptions explain immediate lossless validation or read-only semantics; no blanket suppressions. Parent release review must retain or revise these explanations, not hide warnings.

Review crosswalk is in `PLAN.md`: applicable R01–R18, G01–G06 and local lessons are explicitly mapped. GDPR/privacy compliance is not promised universally. Network activation is rejected; universal network cleanup, physical Safari/RTL/screen-reader, real-inbox delivery, high-volume performance, external cache behavior and final product/naming/assets remain OPEN. Source code and developer documentation are English. Public release must exclude internal QA reports/fixtures/private mail and use the final verified runtime ZIP.

Tool limitation: PHP8.5 may print a startup deprecation from the bundled WP-CLI vendor/react/promise semicolon case statement before loading WordPress. Raw integration logs preserve it; normalized integration-*-final.json files retain the behavioral results. This tool warning is distinct from plugin-runtime failures and is not hidden.

## Coordinator final package gate

The aggregate 0.2.0 gate is now complete: this exact runtime ZIP was installed together with all fifteen tools; source/ZIP/installed hashes and syntax matched, final installed Plugin Check had no errors, and the all-15 bootstrap/debug plus joint runtime/browser checks passed. See [aggregate report](../../../../PACKAGE_REPORT.md) and [debug report](../../../../reports/0.2.0/DEBUG_REPORT.md). This closes the earlier root-packaging handoff only; product-specific open/manual gates above remain open.
