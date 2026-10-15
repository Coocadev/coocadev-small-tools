# Coocadev StockDigest 0.2.0

Daily low-stock email with replenishment suppression for WooCommerce.

## Usage

Open WooCommerce > StockDigest, enter one recipient and enable the digest. The first due run is scheduled one hour later and subsequent runs are daily. Mark replenishment dates beside products. Existing immediate WooCommerce low-stock email is unchanged.

## Scope

The merchant explicitly enables one recipient. Each daily run scans 100 products per invocation in ascending ID order, up to 10,000 products, and retains at most 500 report rows. Each product uses its native WooCommerce low-stock threshold. An ordered-until UTC date suppresses reminders, including the complete chosen day. The current day is persisted before one mail invocation; ambiguous failures never retry automatically.

## Privacy and lifecycle

ccsd_settings stores the opted-in recipient and enabled flag. ccsd_run stores one current-day cursor/status and bounded temporary product rows, which are cleared at completion. Product _ccsd_ordered_until stores a merchant date. No customer/IP/analytics records are collected. Core privacy export matches the recipient; erasure disables mail and clears the address. Product reminder dates contain business inventory data.

Deactivation clears ccsd_daily and ccsd_continue and retains settings. Uninstall clears both cron hooks and the two options; product reminder dates remain. Product deletion naturally removes its own meta. Site-local activation only; network-wide activation is not supported.

## Limitations

WP-Cron depends on traffic. The reminder list is paginated in 100-product pages, including variations; the normal WooCommerce inventory remains available. The digest covers at most 10,000 published products per daily run and 500 rows, visibly flagging truncation. Concurrent inventory edits during pagination may affect the current report. At-most-once mail invocation can lose a report if the process crashes after its claim; delivery is not guaranteed. No automatic resend button is provided.

## Open source

Copyright (c) 2026 Coocadev. All shipped source is readable and licensed under GPL-2.0-or-later, the same license family used by WordPress. You may inspect, modify and redistribute it under that license, without an account, license key, feature gate or shared Coocadev runtime. GPL does not require modified private copies to be published; distribution carries its source/license obligations. See plugin/LICENSE, DEVELOPER.md and DEPENDENCIES.md.

This is a local candidate; review reports, not this README, define observed tests. No WordPress.org submission or production publication is implied.

Durability note: mutex holders read the authoritative stored configuration and run state rather than an earlier per-request option cache. Each progress/claim/result write is checked against the database. A failed pre-mail claim sends nothing; a failed result write leaves the durable claim and never automatically resends. An already accepted email cannot be recalled after privacy erasure.
