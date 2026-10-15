# Implementation plan and acceptance contract: DownloadRescue

Version: 0.2.0. Status: implemented local candidate; release evidence determines completed gates.

## 1. User outcome and MVP

A signed-in customer viewing their own eligible paid order can request review of existing expired or exhausted download permissions. Requests are idempotent while pending and limited to once per 24 hours afterwards. The WooCommerce > DownloadRescue queue finds pending orders. A merchant explicitly approves or rejects on the order page. Approval renews only requested permissions still matching purchased current files, grants seven days and at least five downloads, and invokes the native completed/processing customer email once.

## 2. Merchant and customer flows

Activate, then the purchaser opens My account > Orders > View. Expired or exhausted eligible downloads show Request download renewal. Merchants review WooCommerce > DownloadRescue, open an order and use Approve and resend or Reject request. Approval is a business decision and is never automatic.

## 3. Security and permissions

The owner ID is current_user_id and must exactly equal the nonzero order customer ID. Merchant operations require manage_woocommerce and edit_shop_order for that object, POST and a generation-bound nonce. Order status, refunds, file presence, purchased product, permission customer/email/order key and expiration are rechecked inside a per-order connection-owned lock. An action claim is persisted before permission CRUD and one native resend invocation. Native save forms are not nested or overridden: separate footer forms own approval controls. Private account routes use no-cache headers; do not page-cache WooCommerce My account.

## 4. Data, privacy and lifecycle

WC_Order metadata _ccdr_request stores status, request time, random generation token, requested permission IDs and decision time. _ccdr_status is a queryable queue enum. Existing WC_Customer_Download objects remain in WooCommerce storage. No additional email/IP/cookie/analytics records are collected. Core export shows order/request status and time, excluding tokens, URLs and keys; erasure removes request metadata without changing permissions.

No activation schema or cron is required. Deactivation and uninstall retain request history and native permissions. Before uninstall, Core privacy erasure can remove requests for an address. Permanent order deletion follows native WC lifecycle and shares the request/decision mutex. Site-local operation only; network-wide activation is not supported.

## 5. Performance and failure policy

Guests must contact the store; no weak order-key/email lookup is exposed. Any refund, cancelled/failed/unpaid order, missing file/product, identity mismatch or more than 100 permissions uses native WooCommerce support. No new entitlement, arbitrary file/URL, file fetch, replacement billing identity or automatic grant is created. A process crash after the granting claim requires native merchant review; replay never duplicates grant/email. Native mail enablement and delivery remain controlled by the store.

All queries are bounded. No remote runtime service or shared Coocadev plugin is required. No frontend asset bundle loads on unrelated pages.

## 6. Acceptance and negative scenarios

Guest/foreign-owner/self-approval rejection; pending request deduplication; unchanged permissions until approval; pending queue; generation replay; seven-day/five-download WC CRUD; native purchaser mail once; 24-hour repeat rate limit; cancelled/refunded order rejection; privacy metadata erase without entitlement mutation; merchant reject with no mail/grant.

Run on WordPress current/minimum, PHP8.1/current where available and WooCommerce current/minimum. For order functionality, test HPOS without compatibility sync and legacy independently. Native classic and Blocks checkout must be exercised where this plugin changes checkout. Run Plugin Check, WPCS, PHP lint and WP_DEBUG review. Inspect same-ID concurrent requests and permanent deletion, malformed HTTP data, nonce/capability failures and plain/HTML emails.

## 7. Release gates still separate

Naming and slug checks; actual product page and directory assets; manual screen-reader, RTL, Safari/Firefox and mobile-admin acceptance; full third-party/cache/multisite matrix; exact final ZIP/hash install smoke; owner production/submission action. GPL-open readable source is required now and is not contingent on directory approval.

Durability note: native WC_Order save can swallow an exception and return an existing ID, so every request/decision state is verified through forced metadata readback. Permissions are also read back before a native resend. A crash or partial persistence after the durable granting claim requires merchant review through WooCommerce; no automatic replay occurs. Expiry uses native WooCommerce calendar-date semantics, valid through the selected date, rather than an exact seven-times-24-hour timer.
