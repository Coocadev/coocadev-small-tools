# DispatchGo developer guide

DispatchGo displays a store dispatch pause before checkout and freezes the terms
with each standard WooCommerce order. Its central invariant is that later emails
read the original durable receipt instead of today's configuration.

## License and source layout

Copyright (c) 2026 Coocadev. SPDX-License-Identifier: GPL-2.0-or-later.
The plugin and this guide are free software under GNU GPL version 2 or later:
study, use, modify and redistribute them subject to the license. Preserve notices
and offer corresponding source when required for distributions of modified code.
The full GPLv2 text is `plugin/LICENSE`; the later-version notice grants the
later-version choice. No shipped function requires a subscription or license key.

All PHP/JS/CSS sources are readable and shipped without compilation. No npm build
or vendor runtime is required. WordPress/WooCommerce provide the declared frontend
libraries. The runtime directory is `plugin/`, installed as `coocadev-dispatchgo`.
Tests, evidence and reports are development material, not runtime dependencies.

## Code map

| File | Responsibility |
| --- | --- |
| `plugin/coocadev-dispatchgo.php` | Woo version gate, activation and compatibility declarations |
| `plugin/src/class-schedule.php` | Strict local-time parsing, applicability, preview signatures |
| `plugin/src/class-repository.php` | Settings CAS, write-once receipt ledger and WC_Order materialization |
| `plugin/src/class-plugin.php` | Woo settings, both checkout adapters, order/email/privacy rendering |
| `plugin/assets/classic.js` | Refreshes classic notice and signed hidden token together |
| `plugin/assets/blocks.js` | Store API extension-token/UI adapter using Woo registered stores |
| `plugin/uninstall.php` | Optional configuration-only removal; retained order history |

`ARCHITECTURE.md` explains the persistence design in more detail. The classes are
internal implementation boundaries rather than a separately versioned public SDK.

## Checkout hooks and the payment boundary

Classic checkout validates at `woocommerce_after_checkout_validation` and freezes
at priority 1 on `woocommerce_checkout_order_created`. The validated receipt must
exist before Woo's ordinary processed/gateway step. Blocks uses namespaced Store
API cart extension data and `extensions.ccdg.token`; POST freezes through
`woocommerce_store_api_checkout_update_order_from_request`. GET/PATCH must not
freeze a draft. Invalid active terms stop checkout through Woo's own errors.

The server computes terms from its clock/store timezone. Tokens sign exact
normalized terms for a bounded lifetime; they never authorize a client date.
Refresh every minute is presentation support, not a security gate. Woo's nonce,
session and authorization still apply. Existing receipts are reused on retries.
Pending-order preview reuse requires Woo session context and matching cart hash.

Store settings require `manage_woocommerce` and both the Woo and plugin form
guards. The Shipping section uses Woo settings hooks, including
`woocommerce_update_options_shipping`. Order/email views use normal authorized
Woo contexts and `woocommerce_email_after_order_table`; there is no custom public
order-data endpoint. The plugin does not change payment totals, gateways or order
statuses and does not send separate mail.

## Persistence and concurrency

Site option `ccdg_settings` has schema/revision and normalized schedule. A CAS
write prevents one admin tab silently replacing another tab's changes.
`{$wpdb->prefix}ccdg_snapshots` has `PRIMARY KEY(order_id)` and one JSON receipt.
`INSERT IGNORE` chooses the first durable winner. A different candidate cannot
replace it. A connection-owned MySQL/MariaDB advisory lock serializes copying that
same payload through `WC_Order` CRUD to `_ccdg_snapshot`; there is no direct SQL
against Woo order/meta tables, which keeps the design storage-independent.

Do not replace the immutable gate with an expiring lock: a stale former owner
could otherwise overwrite an accepted notice. Active-notice storage or lock
failure must stop checkout before payment; inactive optional-audit failure need
not block an order. Retrying can repair missing Woo metadata from the ledger.
Exotic database routing/proxies must preserve connection-owned advisory locks.

Receipt fields contain public dispatch terms, capture UTC, source, revision and
locale, not copied customer address/email/IP/payment data. The time interval is
`[start,resume)` in UTC, calculated from validated local store time. DST gaps and
repeated wall-clock times are rejected. This is a dispatch notice, not a promise
about delivery, stock or third-party fulfillment.

## Lifecycle and local example

Network activation is rejected; activate individually. Deactivation preserves
settings and receipts. Opt-in uninstall removes configuration only, retaining
Woo metadata and the ledger for historical display after reinstall. Permanent
order deletion while active cleans its ledger row. A store removing historical
data while the plugin is absent must include retained ledger rows in that process.
Privacy export adds the receipt to Woo's existing scoped order export.

Example in trusted PHP code where `$order` is already authorized:

```php
$receipt = $order->get_meta( '_ccdg_snapshot', true );
if ( is_array( $receipt ) && 1 === ( $receipt['version'] ?? null ) ) {
    // For custom rendering, validate terms and escape for the destination context.
    $terms = $receipt['terms'] ?? array();
}
```

Do not create a public endpoint that accepts an arbitrary order ID around this
example. Normal Woo order authorization remains essential.

## Contributing and verification

`tests/domain.php` tests dates/signatures, `integration.php` tests adapters and
receipt behavior, `boundaries.php` tests permissions/failure recovery,
`concurrency.php` tests the durable gate, and `classic-checkout.php` /
`store-checkout.php` exercise actual checkout with offline COD fixtures.
`lifecycle.php` covers retention. Read `tests/run.ps1` environment assumptions;
these are isolated disposable test commands, not production administration.

Syntax checks are `php -l` for PHP and `node --check` for both runtime JS files.
Use Woo's own dependency versions; `ExperimentalOrderMeta` is experimental and
requires regression QA when Woo changes. Historical executed results and open
browser/gateway gates are in `QA-2026-10-14.md`. A documentation-only verification
report does not transfer those passes to a newly built artifact.

Keep patches narrow, comments in English, and include a regression for changed
checkout behavior. Never distribute real orders, credentials, mail evidence,
fixture secrets or payment data in public source/runtime packages.
