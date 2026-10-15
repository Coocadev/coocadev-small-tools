# ReleaseGo implementation plan

Status: local source candidate 0.2.0. Tests are recorded in reports/0.2.0, not
inferred from this plan. Directory presentation/naming/publication remain separate.

The MVP is a public version-history list backed by native WordPress editing. It
does not deploy code, update plugins or duplicate a commerce/licensing service.

## Architecture and flow

1. Register a private-admin release CPT and product taxonomy with standard post
   capabilities and explicit term-management capabilities; no public single URLs.
2. Native editor saves title/body/revisions. A per-post nonce and edit_post check
   protect strict version and New/Fixed/Improved metadata. Invalid details preserve
   prior metadata; a truthful notice does not claim Core content was rolled back.
3. Shortcode accepts a bounded product slug and 1-50 results. Query published
   posts only, then repeat type/status/password/version checks at render time.
4. Escape headings/date/badges, KSES-filter body, and never invoke note shortcodes,
   eval, remote code, telemetry or unconditional frontend resources.
5. Retain editorial history by default. Explicit per-site uninstall removes only
   release posts/meta/revisions, product terms and one own option via Core APIs.

## Review crosswalk

R03/G01: full GPL-2.0-or-later source and free local features. R04/R09/G02:
no external frontend assets, analytics or service. R05-R08/L01: native Core plus
specific nonce/object capabilities; strict version/category/retention validation;
late escaping/KSES and no raw execution. R11-R15: native WordPress storage/paths,
prefixed CPT/taxonomy/meta/shortcode, no custom SQL or executable file writing.
R16-R18/L06: exact environment/ZIP/hash verification remains release-scoped.
R01-R02: final unique name/account and real product page must be checked before
submission. Native content privacy applies; no encryption/compliance guarantee.

## Verification gates

* Author own/other release metadata, nonce/method denial and unchanged prior data.
* Enum/type/array/version bounds and valid normal editor save.
* Product filtering and deterministic date/ID ordering, result limit and malformed
  shortcode inputs. Draft/private/future/password/invalid-metadata exclusion.
* HTML/XSS escaping, body shortcode nonexecution and no frontend asset pollution.
* Default/opt-in Core uninstall in separate processes preserves unrelated posts/users.
* PHP8.1 syntax, WPCS, Plugin Check, fresh WP_DEBUG and actual native admin requests.
* Final exact ZIP/hash/install and compatibility with all tools by parent release QA.
* Manual browsers/RTL/screen-reader, cache invalidation, large history performance,
  full multisite lifecycle, custom-role/CPT and revision-editor workflows remain gates.
