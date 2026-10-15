# Passport implementation plan

Status: local source candidate 0.2.0. Execution evidence belongs in reports/0.2.0;
this plan does not itself mark a test passed or imply directory approval.

The problem is a site handover where no one knows why each plugin exists, who
owns its operational use or what to test before updating it. The MVP adds a
private card to native plugin rows rather than managing plugin code or credentials.

## Flow and data

1. Enumerate exact installed basenames through get_plugins(), without including
   target source. Require manage_options and activate_plugins for all actions.
2. Record four bounded plain-text fields: purpose, usage, responsible, checklist.
   Keep all data in one versioned non-autoload site option with a revision.
3. Compare exact prior serialized option bytes before writing and invalidate cache.
4. Retain removed-plugin notes with explicit read-only status. Export includes
   installation state and card text, only through an authorized nonce-protected POST.
5. Preserve data by default; explicit per-site uninstall preference removes only
   the owned option. Never activate/delete/update other plugins or touch their files.

## Review crosswalk

R03/G01: complete free GPL-2.0-or-later readable source, no feature gate. R04/G02:
no assets, telemetry or external runtime. R05-R08/L01: method, both capabilities,
nonce, exact basename, strict text/revision/checkbox validation and late escaping.
R11-R15: one own option, trusted table identifier and prepared exact-value CAS;
no executable file writes or target include, no guessed Ajax path. R16-R18/L06:
exact build/version/source checks and clean ZIP install belong to release QA.
R01-R02 require final name/account/product-page checks before directory submission.
Personal free text requires manual privacy review; do not promise encryption.

## Verification gates

* Installed/unknown/traversal basename, scalar/array/UTF-8/size limits.
* Both capabilities independently, CSRF/method denial, JSON export/no-store.
* Two-tab revision conflict; complete prior state unchanged on malformed request.
* Removed-card retention, escaped output and no frontend output/assets.
* Actual default and opted-in Core uninstall in separate processes; foreign data safe.
* PHP8.1 syntax, WPCS/Plugin Check and fresh WP_DEBUG observations.
* Final ZIP/hash/install and all-product compatibility by the parent release task.
* Remaining manual Safari/Firefox/RTL/screen-reader, persistent object cache,
  multicore concurrency, multisite lifecycle and performance at 1,000 cards.
