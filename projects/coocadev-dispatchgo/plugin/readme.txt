=== Coocadev DispatchGo for WooCommerce ===
Contributors: coocadev
Tags: woocommerce, checkout, shipping, dispatch
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.2.0
Requires Plugins: woocommerce
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Show a dispatch pause in cart and checkout, and preserve the original notice with each WooCommerce order.

== Description ==

Configure one dispatch pause under WooCommerce > Settings > Shipping > DispatchGo. Customers see when dispatch resumes before ordering. The notice is retained in order details and existing WooCommerce emails, including resends after your schedule changes.

* A store-timezone schedule with strict date and daylight-saving validation.
* Cart and checkout notice, for physical items and mixed carts.
* Classic checkout and WooCommerce Cart/Checkout Blocks adapters.
* Signed, expiring previews: changed terms require another review before payment.
* An immutable receipt for each standard checkout, including when no pause applied.
* WooCommerce CRUD for order metadata, with HPOS and legacy storage support.
* HTML and plain-text email output and WooCommerce personal-data export integration.

All included features are available without a subscription, licence key, or Coocadev account. The plugin makes no outbound service requests and includes no analytics or third-party frontend assets.

This development release requires WooCommerce 10.2.2 or later. Release verification results are maintained separately; this package does not imply WordPress.org approval.

Locally exercised on WordPress 6.9 / PHP 8.1.34 / WooCommerce 10.2.2 and WordPress 7.1.3 / PHP 8.5.5 / WooCommerce 11.2.1, including classic checkout, Store API checkout and immutable order receipts. Third-party checkouts and gateways need their own sandbox verification.

== Installation ==

1. Install and activate WooCommerce 10.2.2 or later.
2. Upload and activate this plugin on an individual site.
3. Open WooCommerce > Settings > Shipping > DispatchGo.
4. Set the start, resume date, optional explanation and enable the notice.
5. Test your actual checkout and payment method in a sandbox before use.

Exclude cart, checkout and Store API responses from full-page caching, as WooCommerce normally requires. A cached page must not bypass live checkout validation.

== Frequently Asked Questions ==

= Does the date guarantee delivery? =
No. It describes when the store resumes dispatch. It does not guarantee stock availability, individual dispatch dates or carrier delivery.

= Which orders get a receipt? =
New orders submitted through standard WooCommerce classic checkout or the Store API. Merely viewing a Blocks checkout does not freeze a receipt. Manual orders, imports and other custom order-creation systems do not receive a retrospective promise.

= What happens when the schedule changes during checkout? =
The server compares the signed preview with current terms. If they differ or the active notice lacks a valid preview, checkout asks the customer to review it again before payment. The plugin never accepts a browser-supplied dispatch date as truth.

= What about virtual products and pickup? =
Virtual-only carts receive no shipping pause. Standard local_pickup-only selections receive no pause; mixed shipping packages still do. Third-party pickup methods and special fulfilment/subscription/preorder systems require independent compatibility testing.

= What is saved? =
One configuration option, a compact immutable receipt ledger keyed by order ID, and the same receipt in WooCommerce order metadata. Receipts contain the displayed store date, timezone, plain explanation, revision, capture time, source and locale. No additional customer address, email, IP or payment data is collected.

= What if saving the receipt fails? =
When a dispatch notice applies, checkout stops before its normal payment step and asks for a retry. If no notice applies, an unavailable optional audit does not block checkout. No date is invented for an order without a valid receipt. Standard MySQL/MariaDB connection-owned advisory locks are required; unusual database proxies should be tested before enabling a pause.

= What happens on deactivation or deletion? =
Deactivation preserves everything. Deletion removes configuration only if selected in settings; historical order metadata and the receipt ledger are retained. Reinstalling restores their display. While active, permanent order deletion removes its ledger row; anonymization follows WooCommerce order-retention policy and retains the non-identifying dispatch notice. With the plugin absent, an administrator performing a final historical-data cleanup must also remove the corresponding retained ledger rows. Do not manually delete receipts for live pending orders.

= Is network activation supported? =
No. Activate separately per site; no network-wide order sharing or cleanup is implemented.

= Are scripts built or minified? =
No. assets/blocks.js and assets/classic.js are the complete readable sources. They use WordPress/WooCommerce registered libraries. No build process or third-party runtime bundle is required.

== Source and contributions ==

Copyright (c) 2026 Coocadev. All included plugin features are free software under
GNU GPL version 2 or later (SPDX: GPL-2.0-or-later). You may study, use, modify and
redistribute the code under that license. Preserve copyright/license notices and
provide corresponding source when the GPL requires it for redistributed changes.
The full GPLv2 license text is included in LICENSE, with the later-version choice
stated in source headers. No licence key or payment unlocks included local features.

The shipped PHP, JavaScript and CSS are complete readable sources; no compilation,
minification or remote code download is required. The development source project
contains an English DEVELOPER.md with the architecture, hooks, data lifecycle,
test entry points and contribution guidance. Keep patches focused and include
regression coverage for changed behavior. Never include credentials, customer
data, private fixtures or review emails in public source or installable packages.

== Changelog ==
= 0.2.0 =
* Open-source release: English architecture comments and developer guides; same runtime behavior as 0.1.0.


= 0.1.0 =
* Initial local development release.
