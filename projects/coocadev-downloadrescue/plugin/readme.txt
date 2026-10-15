=== Coocadev DownloadRescue ===
Contributors: coocadev
Tags: woocommerce, downloads, customer-support
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.1
Requires Plugins: woocommerce
Stable tag: 0.2.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Customer download-renewal requests with explicit WooCommerce merchant approval.

== Description ==

A signed-in customer viewing their own eligible paid order can request review of existing expired or exhausted download permissions. Requests are idempotent while pending and limited to once per 24 hours afterwards. The WooCommerce > DownloadRescue queue finds pending orders. A merchant explicitly approves or rejects on the order page. Approval renews only requested permissions still matching purchased current files, grants seven days and at least five downloads, and invokes the native completed/processing customer email once.

There is no required Coocadev account, remote service, telemetry or license gate. All shipped PHP source is readable and GPL-2.0-or-later.

== Installation ==

1. Install WooCommerce 10.2 or later.
2. Install and activate Coocadev DownloadRescue on one site.
3. Activate, then the purchaser opens My account > Orders > View. Expired or exhausted eligible downloads show Request download renewal. Merchants review WooCommerce > DownloadRescue, open an order and use Approve and resend or Reject request. Approval is a business decision and is never automatic.

== Frequently Asked Questions ==

= What are the limits? =

Guests must contact the store; no weak order-key/email lookup is exposed. Any refund, cancelled/failed/unpaid order, missing file/product, identity mismatch or more than 100 permissions uses native WooCommerce support. No new entitlement, arbitrary file/URL, file fetch, replacement billing identity or automatic grant is created. A process crash after the granting claim requires native merchant review; replay never duplicates grant/email. Native mail enablement and delivery remain controlled by the store.

= What data is stored? =

WC_Order metadata _ccdr_request stores status, request time, random generation token, requested permission IDs and decision time. _ccdr_status is a queryable queue enum. Existing WC_Customer_Download objects remain in WooCommerce storage. No additional email/IP/cookie/analytics records are collected. Core export shows order/request status and time, excluding tokens, URLs and keys; erasure removes request metadata without changing permissions.

= What happens on uninstall? =

No activation schema or cron is required. Deactivation and uninstall retain request history and native permissions. Before uninstall, Core privacy erasure can remove requests for an address. Permanent order deletion follows native WC lifecycle and shares the request/decision mutex. Site-local operation only; network-wide activation is not supported.

= Is source code available? =

The package itself contains the complete readable runtime source. There is no generated or minified bundle. It is GPL-2.0-or-later; modification and redistribution follow the included license.

== Changelog ==

= 0.2.0 =
* Initial independently installable local candidate with English documentation and open source.


Durability note: native WC_Order save can swallow an exception and return an existing ID, so every request/decision state is verified through forced metadata readback. Permissions are also read back before a native resend. A crash or partial persistence after the durable granting claim requires merchant review through WooCommerce; no automatic replay occurs. Expiry uses native WooCommerce calendar-date semantics, valid through the selected date, rather than an exact seven-times-24-hour timer.
