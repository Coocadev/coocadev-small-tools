# HoursGo 0.2.0 — local release evidence

Date: 2026-10-15. Implemented local candidate; no production changes, directory submission or approval. Parent release process owns final ZIP/hash/exact-ZIP combined smoke; this report covers readable runtime source and isolated tests.

Final matrix: WordPress7.1.3/PHP8.5.5 and WordPress6.9/PHP8.1.34 with local MariaDB QA3317. Normal copied real plugin directories on reader-notes current/minimum sites, no force-install/source junction. All files and evidence remain on D:.

| Gate | Observed result | Evidence |
| --- | --- | --- |
| Domain/current | 15/15 | integration-current-final.log |
| Domain/minimum | 15/15 | integration-minimum-final.log |
| Actual HTTP | 6 relevant assertions including shared unchanged settings | http-boundaries.json |
| Separate-process lifecycle | 3/3 | lifecycle.json |
| PHP8.1 syntax | All3 PHP files pass | lint-php81.log |
| WPCS3.13.6 | 0 errors,0 warnings | wpcs.json |
| PHPCompatibilityWP8.1 | 0 errors,0 warnings | phpcompatibility.json |
| Plugin Check2.1.0 | 0 errors,1 explained warning | plugin-check.log / plugin-check.json |
| Source/licensing | Full readable PHP, GPL-2.0-or-later, no build | source-manifest.json / plugin/LICENSE |

Commands: PHP8.1 `-l`; PHPCS `--standard=WordPress --report=json`; `--standard=PHPCompatibilityWP --runtime-set testVersion 8.1-`; WP-CLI `plugin activate`, `eval-file tests/integration.php`, `plugin check coocadev-hoursgo --format=json`; actual localhost HTTP and separate-process uninstall through shared local test workers in Client Nudge/tests. Test entry points refuse nonlocal sites before mutation; fixture accounts are ephemeral and credentials remain in memory. No emails requested by this product/tests.

Covered: valid weekly hours, exact open/close boundaries, local timezone, missing/repeated Bucharest DST minutes, invalid calendar date, exact exception start/end, automatic expiry without cron, overlap, malformed checkbox, inverted period, bounded count and cache signals. HTTP covers admin render, GET403, nonce403, malformed400, low-role403 and unchanged persisted settings. Lifecycle proves deactivation/preserve-default uninstall and explicit-delete uninstall in separate CLI processes. Current-site debug log grew zero bytes across the observed test interval (Client Nudge report `wp-debug-delta.json`); historical Oct14 CLI deprecation predates this work.

The sole PCP warning is `DONOTCACHEPAGE` reported as a non-prefixed global. It is the standard integration constant, deliberately not renamed; external caches still require explicit exclusion. Narrow structural-key input sniff exception inspects only bounded indexes, followed by scalar/domain validation. No unreviewed SQL or blanket suppression. `PLAN.md` maps shared registry R/G/L requirements and distinguishes applicability.

OPEN: real external/CDN cache configuration, physical mobile/Safari/RTL/screen-reader, larger performance tests, network-wide uninstall, current brand clearance, product page/assets, exact final ZIP smoke. A page already open in a browser does not self-refresh; overnight/split-shift schedules and multiple locations are unsupported and documented. No universal accessibility/security/compliance claim.

Tool limitation: PHP8.5 may print a startup deprecation from the bundled WP-CLI vendor/react/promise semicolon case statement before loading WordPress. Raw integration logs preserve it; normalized integration-*-final.json files retain the behavioral results. This tool warning is distinct from plugin-runtime failures and is not hidden.

## Coordinator final package gate

The aggregate 0.2.0 gate is now complete: this exact runtime ZIP was installed together with all fifteen tools; source/ZIP/installed hashes and syntax matched, final installed Plugin Check had no errors, and the all-15 bootstrap/debug plus joint runtime/browser checks passed. See [aggregate report](../../../../PACKAGE_REPORT.md) and [debug report](../../../../reports/0.2.0/DEBUG_REPORT.md). This closes the earlier root-packaging handoff only; product-specific open/manual gates above remain open.
