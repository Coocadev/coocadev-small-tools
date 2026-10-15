# Coocadev Small Tools

Fifteen independent, focused WordPress plugins. All are open source under **GPL-2.0-or-later**, the same license choice as WordPress. PHP, JavaScript and CSS are readable and included; no build, shared Coocadev Core, account, telemetry or paid unlock is needed for the supplied functionality.

**Release 0.2.0 is a set of local release candidates.** See [PACKAGE_REPORT.md](PACKAGE_REPORT.md) and product reports for actual checks and remaining release gates. Public source availability does not mean WordPress.org approval. These plugins are not being automatically deployed to a live site.

## Products

| Plugin | Focus | For developers |
| --- | --- | --- |
| [FileGo](projects/coocadev-filego/README.md) | Permanent public PDF links with version history and rollback | [Developer guide](projects/coocadev-filego/DEVELOPER.md) |
| [UploadGo](projects/coocadev-uploadgo/README.md) | Role-aware upload limits that only tighten Core/server policy | [Developer guide](projects/coocadev-uploadgo/DEVELOPER.md) |
| [DispatchGo](projects/coocadev-dispatchgo/README.md) | Shipping-resumption notices saved with WooCommerce orders | [Developer guide](projects/coocadev-dispatchgo/DEVELOPER.md) |
| [Reader Notes](projects/coocadev-reader-notes/README.md) | Private per-post reader notes and personal export | [Developer guide](projects/coocadev-reader-notes/DEVELOPER.md) |
| [DoneMark](projects/coocadev-donemark/README.md) | Editorial checkmarks that detect stale content reviews | [Developer guide](projects/coocadev-donemark/DEVELOPER.md) |
| [Client Nudge](projects/coocadev-client-nudge/README.md) | Assigned material requests with opted-in weekly incomplete-task reminders | [Developer guide](projects/coocadev-client-nudge/DEVELOPER.md) |
| [StockDigest](projects/coocadev-stockdigest/README.md) | Low-stock digest with supplier-order snoozing | [Developer guide](projects/coocadev-stockdigest/DEVELOPER.md) |
| [InvoiceRoute](projects/coocadev-invoiceroute/README.md) | Order-specific accounting email for customer invoices | [Developer guide](projects/coocadev-invoiceroute/DEVELOPER.md) |
| [DownloadRescue](projects/coocadev-downloadrescue/README.md) | Owner requests and merchant-approved extension of existing download access | [Developer guide](projects/coocadev-downloadrescue/DEVELOPER.md) |
| [HoursGo](projects/coocadev-hoursgo/README.md) | Business hours with expiring temporary overrides | [Developer guide](projects/coocadev-hoursgo/DEVELOPER.md) |
| [MediaMap](projects/coocadev-mediamap/README.md) | Bounded image-reference scan of stored Core content | [Developer guide](projects/coocadev-mediamap/DEVELOPER.md) |
| [UseMap](projects/coocadev-usemap/README.md) | Bounded stored block and shortcode usage scan | [Developer guide](projects/coocadev-usemap/DEVELOPER.md) |
| [Passport](projects/coocadev-passport/README.md) | Private plugin purpose, ownership and update-check notes with export | [Developer guide](projects/coocadev-passport/DEVELOPER.md) |
| [ReleaseGo](projects/coocadev-releasego/README.md) | Published product version history with new, fixed and improved changes | [Developer guide](projects/coocadev-releasego/DEVELOPER.md) |
| [MailSkin](projects/coocadev-mailskin/README.md) | Previewable local branding for WordPress system emails | [Developer guide](projects/coocadev-mailskin/DEVELOPER.md) |

DispatchGo, StockDigest, InvoiceRoute and DownloadRescue require WooCommerce. Each plugin has its own namespace, data policy, permission checks, tests and uninstall behavior. Read the product README before installation: support is deliberately bounded, not a claim of compatibility with every theme, builder or extension.

## Install one tool

Download its individual ZIP from the repository Releases page, then use WordPress **Plugins → Add New → Upload Plugin**. Install only the tools you need. The general source ZIP contains development material and is not a multi-plugin installer.

To install from source, copy `projects/<slug>/plugin/` to `wp-content/plugins/<slug>/` and activate the named plugin. No transpilation, minification or Composer runtime install is required. Use an isolated local site for the test fixtures.

## Study, change and contribute

Start with the product's [developer guide](projects/) and [CONTRIBUTING.md](CONTRIBUTING.md). English comments explain request flow, storage, capability/nonce boundaries and the reasons behind concurrency or privacy decisions. Development tests and packaging tools are included in this repository.

You may use, modify and redistribute the code under GPL-2.0-or-later, including for commercial use. Preserve license/copyright notices and the GPL's source/redistribution terms. See [OPEN_SOURCE.md](OPEN_SOURCE.md) and [LICENSE](LICENSE). No copyright assignment is required for contributions.

## Build and verify

`qa/build-packages.ps1` builds runtime-only ZIPs from `catalog.json` and records every file's SHA-256. `qa/verify-packages.ps1` checks the archive against source and a real clean installation, then checks PHP/JS syntax and recorded installed-package Plugin Check results. See [qa/README.md](qa/README.md).

## Support and security

General issues belong in the repository. Report suspected security vulnerabilities privately using [SECURITY.md](SECURITY.md). Product site: https://coocadev.com/. Developer studio: https://cooca.dev/. The two domains serve separate purposes.

This repository contains audited source, not production keys, customer data, private review correspondence, local passwords or installed test servers. Emails use the site's existing WordPress/WooCommerce mail transport; SMTP configuration and guaranteed delivery are outside these microplugins' scope.