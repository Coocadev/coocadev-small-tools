# Usemap 0.2.0 — scanner regression release report

Date: 2026-10-15. Local candidate; no production publication or WordPress.org submission/approval. Parent release process records rebuilt final ZIP hashes and exact-installed-package smoke. This report covers the current readable runtime source in source-manifest.json.

## Actual matrix and checks

| Check | Observed result | Evidence |
| --- | --- | --- |
| Current WordPress7.1.3 / PHP8.5.5 | 31/31 behavioral checks | integration-7.1.3.json / regression-current.log |
| Minimum WordPress6.9 / PHP8.1.34 | 31/31 behavioral checks | integration-6.9.json / regression-minimum.log |
| PHP8.1 lint | All runtime PHP files pass | lint-php81.log |
| Node syntax | Local controller parsed successfully | node --check plugin/assets/scan.js |
| Real JS controller with synthetic batches | 3 checks pass | ui-incomplete.json / tests/ui-incomplete.cjs |
| WPCS3.13.6 | 0 errors, 3 reviewed warnings | wpcs.json |
| PHPCompatibilityWP8.1 | 0 errors,0 warnings | phpcompatibility.json |
| Plugin Check2.1.0 | 0 errors,3 reviewed warnings | plugin-check.log / plugin-check.json |

Actually run through WP-CLI on ordinary copied real plugin directories on the delegated reader-notes current/minimum sites. Existing local MariaDB service127.0.0.1:3317 was used without setup/reset. Synthetic posts/accounts/image files are removed in finally; test guards require WP_CLI, local environment and a localhost site before writes. Runtime itself scans without mutation. PHP8.5 raw logs preserve known startup/tool deprecations in WP-CLI vendor/react/promise and php-cli-tools/Colors.php; they are not silently suppressed or attributed to plugin runtime.

## Reproduced defects and verified fixes

1. wpdb get_results can return [] on SQL failure. Capture last_error immediately after the prepared query, before cache lookups. Actual injected invalid SELECT now produces REST503; it cannot produce a successful empty/complete report.
2. Content bytes are bounded before leaving SQL: CASE/OCTET_LENGTH returns NULL for a body larger than262144 bytes (256KiB). Such rows are not parsed or primed into full post caches. At most40 rows are visited per batch, plus one look-ahead row; the cursor advances across all visited rows, including skips. Responses expose visited/scanned/skipped and incomplete. Tests include exactly256KiB eligible content,41 oversized matching posts, an all-skipped nonterminal batch, terminal skip, cursor progress and absence of skipped post bodies in the Core post cache.
3. The JS controller accumulates skipped counts across requests and announces an incomplete terminal report instead of an empty/unused message. Executing the real controller against synthetic responses verifies cumulative41 skips and reset on a new scan.
4. Removed the adjacent duplicated database-error branch in Plugin::scan. Existing exact shortcode/block parsing, escaped-shortcode behavior and per-object permission checks remain covered.

## Warning review and limits

The three remaining WPCS/Plugin Check warnings refer to the one prepared bounded cursor SELECT: direct query, no persistent result cache, and the static analyzer counting array-unpacked replacements as one argument. The actual parameters are content-byte limit, trusted wpdb table, numeric cursor, one placeholder per allowed post type and limit41; behavioral tests prove successful execution. A generated placeholder list is the only SQL interpolation. The source documents that design rather than hiding an unreviewed SQL path. No report cache is introduced because data and permissions must remain current.

Covered security behavior includes anonymous/subscriber refusal, manage_options without edit_post cannot disclose other authors' content, malformed cursor/identifier rejection, no full content in REST, private no-store responses, no content mutation, multiple batches without duplicate/omitted matches, and explicit incomplete coverage. This is stored-content evidence, not a deletion/deactivation certificate. Reusable blocks are reported separately; arbitrary builders/options/widgets/files/runtime output are outside scope. Concurrent changes require a fresh scan. The256KiB body cap is not a universal bound on third-party hooks or unusually large metadata.

OPEN release gates: parent exact-ZIP install/hash/combined smoke, full browser interaction and physical Safari, keyboard/RTL/screen-reader, large real-site performance, actual third-party integrations, final name/product page/assets and directory review. Runtime is GPL-2.0-or-later, unminified and independent. Shared review lessons about accurate error reporting, strict token identity, bounded processing and honest incomplete reports are applied; full English source/developer documentation is retained.

## Coordinator final package gate

The aggregate 0.2.0 gate is now complete: this exact runtime ZIP was installed together with all fifteen tools; source/ZIP/installed hashes and syntax matched, final installed Plugin Check had no errors, and the all-15 bootstrap/debug plus joint runtime/browser checks passed. See [aggregate report](../../../../PACKAGE_REPORT.md) and [debug report](../../../../reports/0.2.0/DEBUG_REPORT.md). This closes the earlier root-packaging handoff only; product-specific open/manual gates above remain open.
