# Developing MediaMap

## Read the request path

1. `coocadev-mediamap.php` declares metadata/constants and loads the two namespaced classes.
2. `Plugin::boot()` registers a native Tools page, its page-only script and `GET /coocadev-mediamap/v1/scan`.
3. Cookie-authenticated REST requests use WordPress's `X-WP-Nonce` verification. `manage_options` is checked at the route, and `edit_post` is checked before returning each content result. Application passwords use Core REST authentication.
4. `Scanner::batch()` fetches 40 rows plus one look-ahead row using a prepared numeric ID cursor. A CASE/OCTET_LENGTH expression returns NULL instead of a body larger than256KiB. It captures wpdb last_error immediately; SQL failures produce503. Skipped rows advance next/visited/skipped but never prime full post caches. Eligible rows prime post/meta caches before capability checks. Full content never goes back to the browser.
5. `Scanner::reasons()` examines parsed Core data. It never renders a block or executes a shortcode. A depth limit bounds malformed nested block trees.
6. `assets/scan.js` appends results with `textContent`, keeps an ID set and advances the cursor only after a successful response. Abort/retry cannot skip a batch. Start-new increments a generation counter so stale responses cannot replace newer results.

## Trust and storage

Input identifiers are allowlisted and limited before scanning. SQL identifiers come from wpdb; each post type/cursor is a prepared value. The generated `%s` placeholder list is the only interpolated SQL fragment. These read-only database calls intentionally have no long-lived report cache: private content can change and the current user's capabilities must apply. REST data uses `Cache-Control: no-store, private`.

There are no custom options, tables, user metadata, scheduled jobs or writes to uninstall. Network activation does not create per-site state; every request reads only the current blog's `$wpdb->posts`. This does not claim a network-wide scan.

## Change safely

To support an additional Core block, add an explicit attribute mapping and regression fixture. Do not match arbitrary numeric attributes as attachment IDs. To cover a builder, write and document a separate adapter rather than claiming universal coverage. Preserve per-object permission checks and bounded batches.

## Example

From an authenticated administrator REST session, send `GET /wp-json/coocadev-mediamap/v1/scan?after=0` with the UI target parameters and `X-WP-Nonce`. Follow `next` until `done` is true (traversal ended), summing `visited` and `skipped` across batches. A nonzero cumulative skipped total means incomplete coverage; never present that as a clean empty/unused report. Use the WordPress-generated REST URL rather than hard-coding `/wp-json/` on subdirectory sites.

## Checks

Run PHP 8.1 lint, Node syntax checks and `wp eval-file tests/integration.php` against an isolated local WordPress installation. The fixture guards its target and cleans its synthetic content. Reports record exact runtime versions. Source comments explain security boundaries; please keep added comments and documentation in English.

No build step is needed; copy the readable runtime files unchanged. Licensed GPL-2.0-or-later.

Run `node tests/ui-incomplete.cjs` to execute the real controller against synthetic all-skipped batches and a new scan. Regression fixtures also verify actual SQL failure503, exact MediaMap IMG class tokens and oversized-body cache exclusion.
