# ReleaseGo 0.2.0 local verification

Date: 2026-10-15. Implemented local open-source candidate, not published to
production or submitted to WordPress.org. Runtime frozen under plugin/.

## Executed checks

| Check | Actual result |
| --- | --- |
| WordPress7.1.3 / PHP8.1.34 CLI integration and real HTTP | 33/33 PASS |
| WordPress6.9 / PHP8.1.34 integration and real HTTP | 33/33 PASS |
| Actual Core uninstall in separate processes, each WP stack | 6/6 PASS on each |
| PHP8.1.34 syntax, all three runtime PHP files | PASS |
| WPCS WordPress / PHP_CodeSniffer3.13.6 | 0 errors, 0 warnings |
| Plugin Check2.1.0, installed current source | 0 errors, 0 warnings |
| Fresh WP_DEBUG slices, integration and lifecycle, each stack | 0 bytes |
| Runtime source vs both installed QA copies | All5files SHA256 identical |

Raw per-case results are integration.json/log, lifecycle.json/log and minimum/.
VERIFICATION.json and source-hashes.json bind results to these exact runtime
inputs. Root release work owns final installable ZIP allowlist/hash and all15
compatibility; these source tests do not certify an unbuilt ZIP.

## Shared review crosswalk

R01/R02: distinctive branded name, contributors coocadev; final catalog-name,
account and live product-page verification remains before submission. R03/G01:
GPL-2.0-or-later, full GPLv2 text with later-version notice, complete readable
runtime with no paid local gate or compulsory registration. R04/R09/G02: no
external requests, telemetry, remote assets or bundled frontend framework.
R05-R08/L01: capabilities/nonces/methods, strict typed inputs, contextual output
escaping and malformed checkbox HTTP tests. R11-R15: own prefixed data,
WordPress paths/native APIs, no executable file writing or remote code.
R16-R18/L06: actual minimum/current WP, installed-source hashes and separate
Core uninstall processes. Final submission/SVN is outside this local task.

## Remaining release scope

Final source/ZIP publishing, public product/support URL, directory readme/assets,
name/account review, physical Safari/Firefox/RTL/screen-reader and zoom, full
multisite lifecycle, persistent object-cache/page-cache products, large-data
performance and wider third-party/theme integrations remain separate gates.
No compatibility approval, encryption or universal security guarantee is implied.

All active fixture data, temporary users, cookies, terms/posts and options were
restored/removed. Test runners restrict the site path before wp-load to the two
isolated FileGo QA sites and then require local/localhost; HTTP URL must equal
the local site's home URL. No emails/payments/production changes were performed.
The minimum HTTP server had a single-thread cron loopback deadlock; test-only
server-prepend.php disables cron/automatic updates only for that server process.
No shared setup, global PHP/Codex authentication or production config was changed.

## Product coverage and review findings

Tested published-only output (draft/private/future/password/invalid metadata
excluded), defensive post-query status check, product filtering, limit1, malformed
attributes, exact metadata enum/types, normal prerelease version, literal body
shortcodes and KSES. Author-own vs other-author and invalid-nonce/GET writes leave
metadata unchanged. Real native editor POST saves valid details; invalid badge
keeps both prior metadata fields and shows truthful failure notice. Both retention
policies use actual Core uninstall while foreign posts/options survive.

Real separate-process lifecycle testing caught a scalar option representation bug:
WordPress returned the persisted true boolean as string1. Uninstall now accepts
only canonical true/string1 and full lifecycle was repeated on both stacks.
This is shared lesson WP-ST02. Before-fix evidence is retained.

Initial fixtures mistakenly scheduled a future post without a future date, and
read an option cached before an external HTTP mutation. Corrected those fixture
assumptions, preserved initial logs and repeated the entire suite. These are not
claimed as application fixes. Final fresh WP_DEBUG slices are empty.

Plugin Check initially flagged suppress_filters=true in cleanup. Native cleanup
now permits filters and repeats the owned post-type check before every delete;
final Plugin Check is clean. The bounded native product tax_query and strict
metadata validation have narrow explained WPCS exceptions, not global exclusions.

## Coordinator final package gate

The aggregate 0.2.0 gate is now complete: this exact runtime ZIP was installed together with all fifteen tools; source/ZIP/installed hashes and syntax matched, final installed Plugin Check had no errors, and the all-15 bootstrap/debug plus joint runtime/browser checks passed. See [aggregate report](../../../../PACKAGE_REPORT.md) and [debug report](../../../../reports/0.2.0/DEBUG_REPORT.md). This closes the earlier root-packaging handoff only; product-specific open/manual gates above remain open.
