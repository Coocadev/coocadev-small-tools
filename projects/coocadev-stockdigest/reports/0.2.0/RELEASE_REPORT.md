# Coocadev StockDigest — release candidate 0.2.0

Date: 2026-10-15. Status: implemented and locally verified candidate. Production and WordPress.org are unchanged. Exact final ZIP/install/hash verification belongs to the root packaging report; this is source/runtime QA, not a directory approval.

## Implemented behavior

Daily 100-product pages, 10,000-product ceiling, 500-row limit, once-per-day durable mail claim and ordered-until suppression. Progress/claim/result persistence uses authoritative DB readback inside a database/site mutex. Failed claim sends nothing; failed result retains the claim. Privacy erasure wins over a stale init option cache. A deleted product is rechecked after waiting for the mutex.

## Observed environments

| Profile | WordPress | WooCommerce | PHP | Order storage |
| --- | --- | --- | --- | --- |
| Current functional | 7.1.3 | 11.2.1 | 8.5.5 | HPOS, compatibility sync off; legacy separately |
| Minimum functional | 6.9 | 10.2.2 | 8.1.34 | HPOS, compatibility sync off; legacy separately |
| Static, lifecycle, dependency and concurrency harness | current/minimum as applicable | same installed versions | 8.1.34 | native site-local WC APIs |

Database: MariaDB 10.11.14 on the reserved localhost QA service. WP-CLI 2.12.0, PHP_CodeSniffer 3.13.6, Plugin Check 2.1.0. Source manifest: source-manifest.json. No network mail or real payment was used; pre_wp_mail was intercepted, addresses are synthetic, and checkout used offline COD.

## Actual results

| Gate | Status | Evidence / limits |
| --- | --- | --- |
| PHP8.1 syntax | PASS | php81-lint.txt; all 3 runtime PHP files |
| WPCS | PASS | phpcs.json, 0 errors / 0 warnings |
| PHPCompatibilityWP 8.1–8.5 | PASS | phpcompatibility.json, 0 errors / 0 warnings |
| Plugin Check | PASS | plugin-check-sites.json and plugin-check-min-sites.json, no findings after readme Tested up to:7.1 correction |
| Runtime and negative boundaries | PASS | 16 functional assertions per integration profile; 19 real admin-post callback/nonce/method/type assertions per profile; 7 durability/fault-injection assertions per stack; 6 native lifecycle assertions per stack |
| Current/minimum and storage matrix | PASS | integration-*-hpos.json / integration-*-legacy.json |
| WooCommerce feature declarations | PASS | features-*-hpos.json / features-*-legacy.json: 3 assertions per profile confirm normal before_woocommerce_init registration and explicit HPOS/checkout-blocks compatibility |
| Dependency absent | PASS | dependency-sites.txt / dependency-min-sites.txt; runtime adapters stay inactive with WooCommerce skipped |
| Native uninstall and reactivation | PASS | lifecycle-*-hpos.json; one native uninstall per process, documented retention preserved |
| Concurrent workers | PASS | concurrency.json + four worker outputs; same database and site mutex, no duplicated side effect |
| Fresh WP_DEBUG | PASS | wp-debug-delta-sites.log and wp-debug-delta-min-sites.log contain no non-whitespace lines after the successful final matrix |
| Manual accessibility / browser matrix | OPEN | Native labels/buttons are present; screen reader, RTL, Safari/Firefox and mobile acceptance are not claimed |
| Naming, product pages and directory assets | OPEN | Owner/catalog preparation remains; no fake Plugin URI is added |
| Exact packaged ZIP and all-15 coexistence | PENDING ROOT | Root builds allowlisted ZIP and compares installed bytes with this source manifest |
| External caches / third-party extensions / multisite network | OPEN or unsupported | No universal compatibility claim. Network-wide activation is explicitly rejected; per-site activation only |

This plugin uses product CRUD only; it does not read or write orders or modify checkout. Its inventory behavior passed on both current/minimum stacks with HPOS and legacy order storage. A final all-plugin browser check exposed WooCommerce's generic compatibility notice: StockDigest's Woo-aware header had no feature declarations, so custom_order_tables was uncertain and treated as incompatible by that feature's default. The release now explicitly declares custom_order_tables and cart_checkout_blocks compatibility during before_woocommerce_init. Native FeaturesController regression checks confirm both declarations on all four profiles; no claim is made for unrelated experimental WooCommerce features. StockDigest functional/negative, durability/lifecycle, dependency, PCP and static checks were repeated after this change. wp-debug-feature-delta-sites.log and wp-debug-feature-delta-min-sites.log contain no non-whitespace output for that repeat.

## Fixed findings and honest failure policy

Peer review found unchecked mail-claim persistence and a stale init-option race with recipient erasure. Both are fixed and covered by 7 real option-write-failure/stale-cache checks on current and minimum. A product deletion between precheck and lock acquisition now returns a controlled error; the admin-post callback test simulates that timing. NUL-containing dates are rejected before DateTime parsing.

Database advisory lock names include DB_NAME, site table prefix and (for orders) order ID, to avoid serializing unrelated WordPress databases with identical prefixes. The final multi-process test repeats after that namespace change.

The current global PHP8.5 executable exposes WP-CLI phar vendor deprecations before WordPress loads (react/promise/functions.php:369; CLI color formatting in a prior tooling run). Raw functional output is retained in *.raw.log; only those exact known phar/vendor bootstrap lines are separated when decoding JSON. Plugin Check, lifecycle and option configuration run with PHP8.1, avoiding tool noise. No PHP warning from plugin runtime is filtered. Earlier deliberate faults and fixed schema errors remain historical evidence; the final WP_DEBUG delta is measured from a fresh byte offset, without deleting old debug.log.

## Security review and release limits

See REVIEW_CROSSWALK.md and PLAN.md. At-most-once means a persisted claim prevents a second mail invocation; it does not guarantee external delivery or recall an already accepted message. Interrupted claimed work requires merchant/native review where documented. Tests and private fixtures are excluded from runtime ZIPs. Functional test scripts require WP_CLI, WP_ENVIRONMENT_TYPE=local, localhost home URL and the reserved QA path before writes. Temporary concurrency fixtures were removed.

All runtime source and comments are English, readable and GPL-2.0-or-later. No Coocadev runtime service, license gate, analytics, remote updater or bundled/minified vendor code exists. Public Git publication and final packaging are coordinated by the parent task; no public URL is invented here.

## Reproduction

Use tests/run-woocommerce-matrix.ps1 under the StockDigest project to run all three on reserved current/minimum QA sites. Individual commands use PHP wp-cli.phar --path=<reserved-site> eval-file tests/integration.php (and features.php, http-boundaries.php, lifecycle.php or checkout.php classic/blocks where applicable). Fault injection uses durability.php; DownloadRescue requires its guarded concurrency setup first and cleanup afterwards. Multi-process tests use tests/concurrency.php setup, four worker invocations, verify and cleanup. All working evidence remains on D:.

## Coordinator final package gate

The aggregate 0.2.0 gate is now complete: this exact runtime ZIP was installed together with all fifteen tools; source/ZIP/installed hashes and syntax matched, final installed Plugin Check had no errors, and the all-15 bootstrap/debug plus joint runtime/browser checks passed. See [aggregate report](../../../../PACKAGE_REPORT.md) and [debug report](../../../../reports/0.2.0/DEBUG_REPORT.md). This closes the earlier root-packaging handoff only; product-specific open/manual gates above remain open.
