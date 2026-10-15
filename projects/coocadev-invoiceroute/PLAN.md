# Implementation plan and acceptance contract: InvoiceRoute

Version: 0.2.0. Status: implemented local candidate; release evidence determines completed gates.

## 1. User outcome and MVP

Capture an optional accounting address at classic checkout or native Checkout Blocks additional field. Freeze it after an order ID exists and before payment. A primary-key receipt table wins concurrent retries; blank is an immutable choice too. Only the WooCommerce customer_invoice recipient filter uses the receipt. Billing, payment, processing, completed and shipping recipients are otherwise unchanged.

## 2. Merchant and customer flows

Activate the plugin. Checkout gains an optional accounting email field. The saved receipt is visible, read-only, on the merchant order screen. Use the native Order actions > Send order details to customer to send the manual invoice. An authorized accounting address may receive order details and payment links.

## 3. Security and permissions

Strict validation rejects multi-recipient lists, CR/LF, arrays, null and oversized addresses instead of converting input into another recipient. Classic and Blocks validation execute within native checkout security. Only final Blocks processing freezes a draft. INSERT IGNORE with the order primary key makes retry captures immutable; routing reads the authoritative table, bypassing mutable order meta. Prepared SQL touches only this plugin table; WC_Order CRUD touches orders. Deletion and receipt creation use the same scoped connection-owned lock.

## 4. Data, privacy and lifecycle

The site-local ccir_receipts table stores order_id, email and UTC created_at. WC_Order meta _ccir_accounting_email is a readable mirror, not routing authority; temporary _ccir_checkout_input is removed after capture. Checkout Blocks manages its own native additional-field metadata. Core privacy export matches accounting email. Erasure explicitly reports receipt retention under the merchant commercial-order retention policy.

Permanent order deletion while active removes the receipt, with a shared lock spanning Core deletion. Trashing preserves it. Deactivation and uninstall preserve historical receipts and WooCommerce metadata; no cron exists. If the plugin is removed while records are retained, their eventual cleanup requires an explicit merchant retention decision. Site-local activation only; network-wide activation is not supported.

## 5. Performance and failure policy

This is invoice-recipient routing, not PDF generation or bookkeeping. No historical orders are rewritten. The accounting address cannot be edited after checkout in this release. It must be authorized to receive order details and any payment link. Other plugins filtering the same recipient may change the final result; shipping extension behavior needs its own compatibility test.

All queries are bounded. No remote runtime service or shared Coocadev plugin is required. No frontend asset bundle loads on unrelated pages.

## 6. Acceptance and negative scenarios

Scalar/address rejection; classic and Blocks hooks; no premature draft freeze; immutable blank/nonblank retry; tampered meta ignored; real manual invoice routing; processing/completed recipient isolation; billing identity unchanged; privacy export/retention; permanent-delete cleanup and no orphan recreation.

Run on WordPress current/minimum, PHP8.1/current where available and WooCommerce current/minimum. For order functionality, test HPOS without compatibility sync and legacy independently. Native classic and Blocks checkout must be exercised where this plugin changes checkout. Run Plugin Check, WPCS, PHP lint and WP_DEBUG review. Inspect same-ID concurrent requests and permanent deletion, malformed HTTP data, nonce/capability failures and plain/HTML emails.

## 7. Release gates still separate

Naming and slug checks; actual product page and directory assets; manual screen-reader, RTL, Safari/Firefox and mobile-admin acceptance; full third-party/cache/multisite matrix; exact final ZIP/hash install smoke; owner production/submission action. GPL-open readable source is required now and is not contingent on directory approval.
