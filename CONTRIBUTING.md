# Contributing to Coocadev Small Tools

The fifteen tools are independent WordPress plugins, licensed GPL-2.0-or-later. Contributions use that same license. Keep code comments, identifiers, user-facing strings and developer documentation in English. Local planning documents may be in Russian.

## Find the code

`catalog.json` is the product index. For each product, `projects/<slug>/plugin/` contains the installable runtime, `DEVELOPER.md` explains its classes/hooks/data flow, the runtime readme and `PLAN.md` (where present) state the scope, and `tests/` contains development-only checks. Original internal research and private review correspondence are not part of the public source. Do not put reports, tests or fixtures inside `plugin/`.

Start with one product's developer guide. Follow the request from the WordPress hook through authorization, validation and storage to output. Preserve the explicit namespace/prefix in `catalog.json`. No tool may require a shared proprietary runtime.

## Local environment

Use an isolated local WordPress installation with `WP_ENVIRONMENT_TYPE=local`, a local database, PHP 8.1 or later and WP-CLI. The current observed test versions are recorded in the reports; do not invent a new `Tested up to`. Install WooCommerce for DispatchGo, StockDigest, InvoiceRoute and DownloadRescue. Do not copy production data or keys into fixtures.

Copy a runtime directory to `wp-content/plugins/<slug>/` or build and install its ZIP. Activate only on your local site. Existing maintainers' QA sites may use filesystem junctions; never run an updater with `--force` over a junction into working source. When testing exact releases, install into a fresh real directory.

Install development tools separately: WordPress Plugin Check, PHPCS with WordPress Coding Standards, and PHPCompatibilityWP. Tool binaries and WordPress itself are not included in the source release. `qa/README.md` and each developer guide describe the test entry points. Some original fixtures require the named dedicated maintainer database; inspect their guards and adapt only for an equally isolated local target. Fixtures must never be pointed at production.

## Changes and tests

1. Explain the user-visible problem and a bounded scenario that demonstrates it.
2. Add a behavioral regression test where the change affects data, permissions or a nontrivial invariant. Avoid tests that merely search for the implementation text.
3. Preserve capability plus object checks, nonce protection for cookie-authenticated writes, strict input types/bounds, late escaping, prepared SQL and private-response cache controls.
4. Run PHP lint on changed PHP, Node `--check` on changed JS, relevant behavior/lifecycle tests, WPCS and Plugin Check. Test both the declared minimum and current environment before changing support metadata.
5. Check errors as well as the successful path: other users' IDs, arrays instead of strings, missing/invalid nonces, stale revisions, retries and concurrent writes where relevant.
6. Update the English developer guide/readme when changing hooks, schemas, supported flows or retention. Do not mark a test PASS unless it was actually observed on the exact code/package.

Do not add telemetry, remote fonts, external runtime assets, mandatory registration or local feature locks. Requests to add a service need a separate data-flow specification and terms/privacy disclosure. Network tests use a local mail sink; tests must not email real people.

## Building

On PowerShell, run `./qa/build-packages.ps1`. It reads the catalog and packages only runtime files into one-root ZIPs in `artifacts/0.2.0/`; manifests record every file's SHA-256. There is no compiled/minified runtime build step. To package a subset, use `-Slugs @('coocadev-mediamap')`.

Install those ZIPs in a clean local site, run applicable checks, then use `qa/verify-packages.ps1` with the actual installed path. Source releases use the separate explicit allowlist in `qa/stage-public-source.py`. Generated archives, private correspondence, QA databases and credentials must never be committed.

## Pull requests and security

Keep pull requests focused. Include behavior before/after, actual test commands/results and remaining limits. Do not send passwords, cookies, keys, customer data or private WordPress review emails. Report security issues using `SECURITY.md` instead of opening a public exploit issue.
