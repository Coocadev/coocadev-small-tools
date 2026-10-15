# UseMap implementation plan and acceptance scope

Original idea #12, approved for implementation 2026-10-15. Locate stored uses of named blocks and shortcodes before changing a plugin.

The distinct small scope is a bounded, pauseable scan with explicit evidence and limits. The original demand signal and nearest analog are recorded in `../../evidence/original-15-ideas-2026-10-14.md`; this is not trademark clearance. No deletion or deactivation automation is included.

## Deliverables

Independent GPL-2.0-or-later plugin; English translatable UI; exact-match parsing; per-result edit permissions; native Tools entry; no external dependencies or persisted reports; source and developer docs; installable ZIP and exact-package tests.

## Acceptance checks

- Administrator can find nested Core block/classic references across more than one cursor batch.
- Plain string near-matches do not become matching IDs/names.
- Subscriber and unauthenticated REST requests are refused; private content is never sent to an unprivileged user.
- Array/negative/exponential cursors and malformed/oversized target lists are rejected.
- Full post content is absent from REST output and private responses are no-store.
- Pause/retry/new-scan does not skip or append stale results; no browser external requests.
- Scan writes no content/options; deactivation/uninstall retains all WordPress records.
- PHP 8.1/current lint, WordPress minimum/current runtime and Plugin Check run on the exact package. UI keyboard, RTL, Safari and screen-reader checks remain separate release gates until observed.

## Data and lifecycle

Read current-blog posts and media only. Browser DOM holds results until refresh. No schema, migrations, cron, telemetry or personal data collection. Normal Core authentication, manage_options and edit_post are required. Uninstall needs no data cleanup.

## Content budget and incomplete reports

Each request visits at most40 records. Each post body is limited to256KiB before it leaves the database: larger bodies become SQL NULL, are skipped before parsing and before full post-cache priming, and still advance the numeric cursor. Responses expose visited, scanned, skipped and a per-batch incomplete flag. The UI totals skipped records across the full scan and prominently marks the terminal report incomplete whenever any record was skipped; it never labels such a report empty/unused. Database errors return503, not an empty successful scan. Review skipped oversized content separately. This limit bounds stored body parsing, not every third-party filter or metadata allocation.
