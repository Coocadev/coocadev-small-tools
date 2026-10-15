# Coocadev UseMap

Locate stored uses of named blocks and shortcodes before changing a plugin.

Local release candidate 0.2.0. Install `plugin/` as `coocadev-usemap/` or use the separately built installable ZIP. Author: Coocadev; developer site: https://cooca.dev/.

## Usage

Open **Tools → UseMap**, choose the target and start the scan. Requests are sequential and bounded to 40 stored content records. You can pause/resume or start a fresh scan. Refreshing clears the report. The plugin never executes a shortcode or changes/deletes content.

## Limits

Stored Core blocks and classic content only; UI post types, reusable blocks and database templates are covered when the user can edit them. Reusable blocks are listed separately, not expanded into all referencing pages. Page builders, widget/options storage, source files and runtime output are outside scope. No matches is not proof of safe deletion/deactivation. Concurrent content edits require restarting the scan.

## Open source

GPL-2.0-or-later, matching WordPress's license choice. Runtime source is unminified; no build process, external service or shared Coocadev runtime is required. See [DEVELOPER.md](DEVELOPER.md), `plugin/LICENSE` and the source-distribution contribution guide.

## Verification

See `reports/0.2.0/RELEASE_REPORT.md` for observed results and open release gates. This is not a WordPress.org approval.

## Content budget and incomplete reports

Each request visits at most40 records. Each post body is limited to256KiB before it leaves the database: larger bodies become SQL NULL, are skipped before parsing and before full post-cache priming, and still advance the numeric cursor. Responses expose visited, scanned, skipped and a per-batch incomplete flag. The UI totals skipped records across the full scan and prominently marks the terminal report incomplete whenever any record was skipped; it never labels such a report empty/unused. Database errors return503, not an empty successful scan. Review skipped oversized content separately. This limit bounds stored body parsing, not every third-party filter or metadata allocation.
