# Coocadev DownloadRescue 0.2.0

Customer download-renewal requests with explicit WooCommerce merchant approval.

## Usage

Activate, then the purchaser opens My account > Orders > View. Expired or exhausted eligible downloads show Request download renewal. Merchants review WooCommerce > DownloadRescue, open an order and use Approve and resend or Reject request. Approval is a business decision and is never automatic.

## Scope

A signed-in customer viewing their own eligible paid order can request review of existing expired or exhausted download permissions. Requests are idempotent while pending and limited to once per 24 hours afterwards. The WooCommerce > DownloadRescue queue finds pending orders. A merchant explicitly approves or rejects on the order page. Approval renews only requested permissions still matching purchased current files, grants seven days and at least five downloads, and invokes the native completed/processing customer email once.

## Privacy and lifecycle

WC_Order metadata _ccdr_request stores status, request time, random generation token, requested permission IDs and decision time. _ccdr_status is a queryable queue enum. Existing WC_Customer_Download objects remain in WooCommerce storage. No additional email/IP/cookie/analytics records are collected. Core export shows order/request status and time, excluding tokens, URLs and keys; erasure removes request metadata without changing permissions.

No activation schema or cron is required. Deactivation and uninstall retain request history and native permissions. Before uninstall, Core privacy erasure can remove requests for an address. Permanent order deletion follows native WC lifecycle and shares the request/decision mutex. Site-local operation only; network-wide activation is not supported.

## Limitations

Guests must contact the store; no weak order-key/email lookup is exposed. Any refund, cancelled/failed/unpaid order, missing file/product, identity mismatch or more than 100 permissions uses native WooCommerce support. No new entitlement, arbitrary file/URL, file fetch, replacement billing identity or automatic grant is created. A process crash after the granting claim requires native merchant review; replay never duplicates grant/email. Native mail enablement and delivery remain controlled by the store.

## Open source

Copyright (c) 2026 Coocadev. All shipped source is readable and licensed under GPL-2.0-or-later, the same license family used by WordPress. You may inspect, modify and redistribute it under that license, without an account, license key, feature gate or shared Coocadev runtime. GPL does not require modified private copies to be published; distribution carries its source/license obligations. See plugin/LICENSE, DEVELOPER.md and DEPENDENCIES.md.

This is a local candidate; review reports, not this README, define observed tests. No WordPress.org submission or production publication is implied.

Durability note: native WC_Order save can swallow an exception and return an existing ID, so every request/decision state is verified through forced metadata readback. Permissions are also read back before a native resend. A crash or partial persistence after the durable granting claim requires merchant review through WooCommerce; no automatic replay occurs. Expiry uses native WooCommerce calendar-date semantics, valid through the selected date, rather than an exact seven-times-24-hour timer.
