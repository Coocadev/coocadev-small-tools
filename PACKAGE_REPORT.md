# Small Tools 0.2.0 package verification

Date: 15 October 2026. Fifteen independent GPL-2.0-or-later plugins, with readable English-commented source and developer guides. This is an engineering prerelease for review and acceptance, not a WordPress.org approval or production deployment. Original 0.1.0 artifacts/evidence remain preserved separately.

## Exact installable archives

| Plugin ZIP | Files | Bytes | PHP / JS syntax checks | Plugin Check errors / warnings |
| --- | ---: | ---: | ---: | ---: |
| [FileGo](https://github.com/zagulev/coocadev-small-tools/releases/download/v0.2.0/coocadev-filego-0.2.0.zip) | 10 | 32023 | 7 / 0 | 0 / 96 |
| [UploadGo](https://github.com/zagulev/coocadev-small-tools/releases/download/v0.2.0/coocadev-uploadgo-0.2.0.zip) | 11 | 29418 | 7 / 1 | 0 / 7 |
| [DispatchGo](https://github.com/zagulev/coocadev-small-tools/releases/download/v0.2.0/coocadev-dispatchgo-0.2.0.zip) | 10 | 26373 | 5 / 2 | 0 / 0 |
| [Reader Notes](https://github.com/zagulev/coocadev-small-tools/releases/download/v0.2.0/coocadev-reader-notes-0.2.0.zip) | 13 | 35973 | 6 / 2 | 0 / 52 |
| [DoneMark](https://github.com/zagulev/coocadev-small-tools/releases/download/v0.2.0/coocadev-donemark-0.2.0.zip) | 11 | 30668 | 7 / 1 | 0 / 12 |
| [Client Nudge](https://github.com/zagulev/coocadev-small-tools/releases/download/v0.2.0/coocadev-client-nudge-0.2.0.zip) | 5 | 16678 | 3 / 0 | 0 / 10 |
| [StockDigest](https://github.com/zagulev/coocadev-small-tools/releases/download/v0.2.0/coocadev-stockdigest-0.2.0.zip) | 5 | 16165 | 3 / 0 | 0 / 0 |
| [InvoiceRoute](https://github.com/zagulev/coocadev-small-tools/releases/download/v0.2.0/coocadev-invoiceroute-0.2.0.zip) | 5 | 14883 | 3 / 0 | 0 / 0 |
| [DownloadRescue](https://github.com/zagulev/coocadev-small-tools/releases/download/v0.2.0/coocadev-downloadrescue-0.2.0.zip) | 5 | 17416 | 3 / 0 | 0 / 0 |
| [HoursGo](https://github.com/zagulev/coocadev-small-tools/releases/download/v0.2.0/coocadev-hoursgo-0.2.0.zip) | 5 | 13638 | 3 / 0 | 0 / 1 |
| [MediaMap](https://github.com/zagulev/coocadev-small-tools/releases/download/v0.2.0/coocadev-mediamap-0.2.0.zip) | 7 | 17437 | 4 / 1 | 0 / 3 |
| [UseMap](https://github.com/zagulev/coocadev-small-tools/releases/download/v0.2.0/coocadev-usemap-0.2.0.zip) | 7 | 16877 | 4 / 1 | 0 / 3 |
| [Passport](https://github.com/zagulev/coocadev-small-tools/releases/download/v0.2.0/coocadev-passport-0.2.0.zip) | 5 | 16477 | 3 / 0 | 0 / 0 |
| [ReleaseGo](https://github.com/zagulev/coocadev-small-tools/releases/download/v0.2.0/coocadev-releasego-0.2.0.zip) | 5 | 16264 | 3 / 0 | 0 / 0 |
| [MailSkin](https://github.com/zagulev/coocadev-small-tools/releases/download/v0.2.0/coocadev-mailskin-0.2.0.zip) | 5 | 13275 | 3 / 0 | 0 / 0 |

All 109 runtime files were compared byte-for-byte between source, ZIP entries and real installed directories. Archive hashes, entry lengths/counts and installed file counts passed. PHP8.1 syntax and Node syntax passed for every applicable file. [Verified package manifest](https://github.com/zagulev/coocadev-small-tools/releases/download/v0.2.0/verified-packages.json) contains each SHA-256; [SHA256SUMS](https://github.com/zagulev/coocadev-small-tools/releases/download/v0.2.0/SHA256SUMS.txt) covers release assets. The general source ZIP is development source, not a combined WordPress installer.

Warnings are disclosed: 184 total, principally reviewed prepared SQL, atomic/schema/lifecycle operations and the intentional DONOTCACHEPAGE interoperability constant. Product review reports and inline explanations record their disposition. Zero scanner errors does not mean absence of all future defects.

## Verification actually executed

- Each final archive was installed through WP-CLI into a real directory on the isolated combined WordPress7.1.3 / WooCommerce11.2.1 installation. All fifteen were active together during final Plugin Check2.1.0 and subsequent runtime/browser checks.
- Actual VS Code PHP Debug1.40.2 and Xdebug3.4.5 session: fifteen verified entry breakpoints, fifteen safe expression inspections and fifteen actual single steps, then successful bootstrap. This checks the debugger and plugin startup; domain behavior is covered separately. See [debug report](reports/0.2.0/DEBUG_REPORT.md).
- After debugging, WP_DEBUG=true, WP_DEBUG_LOG=true and WP_DEBUG_DISPLAY=false remained enabled. Fresh combined runtime/browser delta: 0 bytes, no new diagnostic lines. Historical logs were not erased.
- Original-five interoperability fixture with all fifteen active: 30/30 PASS, including real multipart PDF upload/replacement denial, unchanged published bytes, private REST/no-store, editorial state and immutable Woo receipt isolation.
- Final scanner regressions on the combined installation: MediaMap33/33 and UseMap31/31 PASS, including real DB failure, bounded large-body skipping, exact image references, object permissions and explicit incomplete results. Separate current/minimum and JS fixtures are described in each report.
- Actual Chromium browser: 39 assertions PASS, fourteen native admin screens, image/shortcode/block scan controls, mobile-width focus, keyboard start, pause/resume without duplicate results and real cookie REST missing/bad nonce and malformed-input denial. InvoiceRoute native classic/Blocks checkout is tested in its separate commerce matrix. This is not a physical Safari or screen-reader test.
- Individual current/minimum suites, lifecycle, selected multi-process races, mail/DB failure injection and WooCommerce HPOS sync-off/legacy matrix are recorded in [product reports](projects/). Do not infer unexecuted plan cases from the aggregate result.

No real payments or customer messages were used: local mail was intercepted, orders/users/content were synthetic, and fixtures were cleaned. An initial combined attempt found the local HTTP server stopped; after restarting it, the complete 30 checks passed. The browser harness initially waited for full dashboard load and timed out; its navigation synchronization was corrected and the final complete run passed. These infrastructure/harness failures remain local evidence and are not counted as successful tests.

Visual inspection of the mobile screenshot also caught WooCommerce's shared feature-compatibility notice. StockDigest had passed HPOS behavior but omitted the feature declaration. Its tested product-only scope is now declared before Woo initialization, its targeted current/minimum checks were repeated, and the final browser run explicitly rejects that notice. The notice was not hidden by a filter.

## Review lessons and release limits

[WP review crosswalk](reports/0.2.0/WP_REVIEW_CROSSWALK.md) applies the shared historical categories without exposing private correspondence. Confirmed new lessons include immutable blank SQL values, persisted option types on uninstall, trashed-task erasure, localized error messages, honest incomplete scans, confirmed durable mail/permission claims and repeatable dbDelta schemas.

The original five changed only comments/docs/version metadata for this release; their old full functional suites are historical coverage, supplemented by current installed-package checks and the new combined/browser/debug runs. New ten implementations have individual current reports. Not every planned scenario is declared PASS.

Before WordPress.org submission: finalize product pages, naming/trademark/slug clearance, directory assets and manual presentation. Physical Safari/Firefox/mobile, RTL/screen readers, broader third-party/cache integrations, large data sets and compatibility with separate BriefGo/SEO Suite remain open where stated. Network-wide activation and unsupported builders/storage are not promised. Source publication and these ZIPs do not alter live sites, payments or directory submissions.

Source audit: [public source review](reports/0.2.0/PUBLIC_SOURCE_REVIEW.md). Only audited source/docs/tests are staged; installed WordPress/WooCommerce, tool binaries, local passwords, databases, customer data, Codex authentication and private email evidence are excluded.
