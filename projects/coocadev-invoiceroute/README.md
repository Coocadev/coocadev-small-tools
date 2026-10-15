# Coocadev InvoiceRoute 0.2.0

Route manual WooCommerce order-detail invoices to a captured accounting email.

## Usage

Activate the plugin. Checkout gains an optional accounting email field. The saved receipt is visible, read-only, on the merchant order screen. Use the native Order actions > Send order details to customer to send the manual invoice. An authorized accounting address may receive order details and payment links.

## Scope

Capture an optional accounting address at classic checkout or native Checkout Blocks additional field. Freeze it after an order ID exists and before payment. A primary-key receipt table wins concurrent retries; blank is an immutable choice too. Only the WooCommerce customer_invoice recipient filter uses the receipt. Billing, payment, processing, completed and shipping recipients are otherwise unchanged.

## Privacy and lifecycle

The site-local ccir_receipts table stores order_id, email and UTC created_at. WC_Order meta _ccir_accounting_email is a readable mirror, not routing authority; temporary _ccir_checkout_input is removed after capture. Checkout Blocks manages its own native additional-field metadata. Core privacy export matches accounting email. Erasure explicitly reports receipt retention under the merchant commercial-order retention policy.

Permanent order deletion while active removes the receipt, with a shared lock spanning Core deletion. Trashing preserves it. Deactivation and uninstall preserve historical receipts and WooCommerce metadata; no cron exists. If the plugin is removed while records are retained, their eventual cleanup requires an explicit merchant retention decision. Site-local activation only; network-wide activation is not supported.

## Limitations

This is invoice-recipient routing, not PDF generation or bookkeeping. No historical orders are rewritten. The accounting address cannot be edited after checkout in this release. It must be authorized to receive order details and any payment link. Other plugins filtering the same recipient may change the final result; shipping extension behavior needs its own compatibility test.

## Open source

Copyright (c) 2026 Coocadev. All shipped source is readable and licensed under GPL-2.0-or-later, the same license family used by WordPress. You may inspect, modify and redistribute it under that license, without an account, license key, feature gate or shared Coocadev runtime. GPL does not require modified private copies to be published; distribution carries its source/license obligations. See plugin/LICENSE, DEVELOPER.md and DEPENDENCIES.md.

This is a local candidate; review reports, not this README, define observed tests. No WordPress.org submission or production publication is implied.
