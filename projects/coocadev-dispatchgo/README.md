# Coocadev DispatchGo

DispatchGo explains a temporary dispatch pause before checkout and preserves the accepted terms with each WooCommerce order. Version **0.2.0** is a local release candidate; it is not a claim of WordPress.org approval.

## Requirements and setup

WordPress 6.9+, PHP 8.1+, WooCommerce 10.2.2+ and InnoDB are required. Install [plugin/](plugin/) as `coocadev-dispatchgo`, activate on an individual site with WooCommerce available, and configure the dispatch pause in **WooCommerce > Settings > Shipping > DispatchGo**. Review the start/resume dates and customer-facing notice before enabling it.

## Features and scope

- Native Classic and Blocks checkout notices with server-side validation before the payment boundary.
- Strict store-timezone date parsing, signed bounded previews and settings revision checks.
- A write-once order receipt and connection-owned lock keep retries consistent across legacy order storage and HPOS.
- Original order terms remain visible in supported order screens and native HTML/plain-text email output.
- WooCommerce personal-data export includes the original receipt in its authorized order context.

This is a dispatch notice, not a delivery, stock or fulfillment promise. It does not change totals, gateways or order statuses and does not send separate email. A database routing/proxy layer must preserve connection-owned advisory locks. Blocks integration uses WooCommerce's declared APIs and needs regression testing when WooCommerce changes. Network activation is unsupported.

## Retention and privacy

Deactivation and default uninstall retain settings and historical order receipts. Explicit uninstall removal deletes configuration only; it preserves WooCommerce receipt metadata and the immutable ledger so a reinstall can render historical terms. Permanent order deletion while the plugin is active cleans its ledger row. If historical orders are removed while DispatchGo is absent, the retained ledger must be included in that removal procedure. Receipts do not copy customer addresses, email, IP or payment details.

## Source, development and verification

Copyright 2026 Coocadev. **GPL-2.0-or-later**; see the full [license](plugin/LICENSE). All shipped features are available without registration, subscription or license key. Readable PHP/JS/CSS are the preferred source and require no frontend compilation. Runtime packages include only `plugin/`; WordPress and WooCommerce supply the declared native libraries.

The English [developer guide](DEVELOPER.md) covers checkout adapters, hooks, durable receipt ownership, lifecycle, examples and guarded local tests. The [0.2.0 release report](reports/0.2.0/RELEASE_REPORT.md) records current package checks, tested storage modes and remaining compatibility work. Tests create synthetic orders and use offline payment/local mail fixtures; never run them on production or with live gateways. Contributions follow the shared [contribution policy](../../CONTRIBUTING.md).
