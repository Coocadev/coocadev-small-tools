# DispatchGo 0.1.0 — implementation decisions

## Scope

One store-timezone dispatch pause; it is not a delivery-date or inventory promise. Runtime has no external requests, analytics, separate mail delivery, payment override, cron dependency or account requirement. The checkout integration uses Woo's own session and authorization. Dates never come from a browser clock.

`Schedule` strictly parses local wall times, rejects nonexistent and repeated DST times, computes UTC boundaries `[start,resume)`, and signs the normalized public preview terms for 30 minutes. A changed date, message or applicability invalidates the previous token. Cart/checkout full-page caching must remain excluded by the host. Classic refresh and Store API cart extensions issue fresh previews; a timestamp alone is not trusted as an accepted date.

## Before-payment integration

- Classic: `woocommerce_after_checkout_validation` verifies Woo nonce and signed preview. `woocommerce_checkout_order_created` at priority 1 persists the validated terms. Woo then fires `woocommerce_checkout_order_processed`, followed by its gateway processing. Actual guest COD tests check that the snapshot already exists at the processed hook.
- Blocks: namespaced Store API cart data and `extensions.ccdg.token`. `woocommerce_store_api_checkout_update_order_from_request` freezes only for POST; GET/PATCH do not freeze a draft. Invalid or missing active preview returns RouteException 409 before `woocommerce_store_api_checkout_order_processed` and gateway work. Real Store API POST tests verify both stale rejection and successful COD processing.
- Existing pending/failed/draft receipts are reused on retry. The cart preview only reads a pending order from Woo's own session and requires matching cart hash. Foreign order IDs are not accepted in a custom route; no such route exists.
- Existing order/email display reads versioned Woo metadata, not current settings. Missing, malformed or unsupported snapshots never produce a guessed date; the order editor gets a diagnostic for damaged data.

## Durable concurrency gate — refinement from the initial plan

The original planning document said no custom table was required. The prototype established that Woo metadata alone has no unique order/key constraint in both storage implementations. To prevent concurrent writers producing different snapshots or duplicate metadata, this implementation adds a tiny site-scoped ledger `${prefix}ccdg_snapshots` with `PRIMARY KEY(order_id)` and one JSON payload. No direct SQL reads/writes Woo's order or metadata tables.

1. `INSERT IGNORE` selects the first durable candidate.
2. Read that immutable winner without an object cache. A conflicting candidate is rejected.
3. Obtain a connection-owned MySQL advisory lock solely while materializing the same payload via WC_Order CRUD. Re-read metadata, save if needed, and verify persistence before continuing.
4. Release in `finally`. Connection death also releases it. The mutex has no expiring owner or TTL takeover. Even if a connection disappears, all materializers must use the one immutable ledger payload; there is no stale-owner update operation on the ledger.

The lock waits at most three seconds. Storage/lock/materialization failure while an active notice applies stops checkout before its normal payment boundary with an instruction to review/retry. An unavailable optional inactive audit does not block a checkout that has no dispatch notice. A subsequent retry repairs missing Woo metadata using the existing durable receipt. Eight simultaneous PHP workers produced one ledger winner and exactly one Woo metadata row in the isolated HPOS site.

Target database must provide standard MySQL/MariaDB `GET_LOCK`/`RELEASE_LOCK`; otherwise active-notice checkout fails closed. This is documented because exotic proxies or read/write routing may not preserve connection-level locks.

## Data and lifecycle

One `ccdg_settings` option with schema/revision. Admin writes use a prepared compare-and-swap SQL update, then invalidate the WordPress option cache, preventing lost updates from two tabs. Capability `manage_woocommerce` and a nonce are required.

Order payload contains only public dispatch terms, revision, capture UTC, checkout source and locale; no extra email, address, IP, cart or payment data. Woo order metadata is stored through CRUD, and the ledger is keyed to that order. Configuration removal on uninstall is opt-in. Historical receipts remain, and reinstall restores display. Permanent order deletion while active removes its ledger row. After uninstall, a store conducting final retention cleanup must explicitly remove retained ledger rows too. Network activation is rejected; per-site activation is supported.

PHP and JS sources are unbundled. Both registered Woo frontend dependencies are declared. Runtime ZIP excludes tests, QA fixtures, reports, synthetic screenshots and private evidence. GPL-2.0-or-later plugin; full GPLv2 text included.

## API evidence

Compared official WooCommerce 10.2.2 source (copies in `evidence/wc-*.php`) and installed official 11.2.1 source. Confirmed classic checkout hook order, Store API POST sequence, shipping settings hooks, scoped privacy exporter and checkout-store `setExtensionData` action.

- https://developer.woocommerce.com/docs/apis/store-api/extending-store-api/extend-store-api-add-data/
- https://developer.woocommerce.com/docs/block-development/reference/data-store/checkout
- https://developer.woocommerce.com/docs/block-development/extensible-blocks/cart-and-checkout-blocks/available-slot-fills/
- https://github.com/woocommerce/woocommerce/tree/10.2.2/plugins/woocommerce

`ExperimentalOrderMeta` remains an experimental Woo slot, so future Woo releases need regression tests. HPOS and Cart/Checkout Blocks compatibility declarations were added only after local runtime verification.
