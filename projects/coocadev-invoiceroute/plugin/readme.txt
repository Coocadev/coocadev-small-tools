=== Coocadev InvoiceRoute ===
Contributors: coocadev
Tags: woocommerce, invoice, email
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.1
Requires Plugins: woocommerce
Stable tag: 0.2.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Route manual WooCommerce order-detail invoices to a captured accounting email.

== Description ==

Capture an optional accounting address at classic checkout or native Checkout Blocks additional field. Freeze it after an order ID exists and before payment. A primary-key receipt table wins concurrent retries; blank is an immutable choice too. Only the WooCommerce customer_invoice recipient filter uses the receipt. Billing, payment, processing, completed and shipping recipients are otherwise unchanged.

There is no required Coocadev account, remote service, telemetry or license gate. All shipped PHP source is readable and GPL-2.0-or-later.

== Installation ==

1. Install WooCommerce 10.2 or later.
2. Install and activate Coocadev InvoiceRoute on one site.
3. Activate the plugin. Checkout gains an optional accounting email field. The saved receipt is visible, read-only, on the merchant order screen. Use the native Order actions > Send order details to customer to send the manual invoice. An authorized accounting address may receive order details and payment links.

== Frequently Asked Questions ==

= What are the limits? =

This is invoice-recipient routing, not PDF generation or bookkeeping. No historical orders are rewritten. The accounting address cannot be edited after checkout in this release. It must be authorized to receive order details and any payment link. Other plugins filtering the same recipient may change the final result; shipping extension behavior needs its own compatibility test.

= What data is stored? =

The site-local ccir_receipts table stores order_id, email and UTC created_at. WC_Order meta _ccir_accounting_email is a readable mirror, not routing authority; temporary _ccir_checkout_input is removed after capture. Checkout Blocks manages its own native additional-field metadata. Core privacy export matches accounting email. Erasure explicitly reports receipt retention under the merchant commercial-order retention policy.

= What happens on uninstall? =

Permanent order deletion while active removes the receipt, with a shared lock spanning Core deletion. Trashing preserves it. Deactivation and uninstall preserve historical receipts and WooCommerce metadata; no cron exists. If the plugin is removed while records are retained, their eventual cleanup requires an explicit merchant retention decision. Site-local activation only; network-wide activation is not supported.

= Is source code available? =

The package itself contains the complete readable runtime source. There is no generated or minified bundle. It is GPL-2.0-or-later; modification and redistribution follow the included license.

== Changelog ==

= 0.2.0 =
* Initial independently installable local candidate with English documentation and open source.

