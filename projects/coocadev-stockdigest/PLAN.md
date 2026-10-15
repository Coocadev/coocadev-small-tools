# Implementation plan and acceptance contract: StockDigest

Version: 0.2.0. Status: implemented local candidate; release evidence determines completed gates.

## 1. User outcome and MVP

The merchant explicitly enables one recipient. Each daily run scans 100 products per invocation in ascending ID order, up to 10,000 products, and retains at most 500 report rows. Each product uses its native WooCommerce low-stock threshold. An ordered-until UTC date suppresses reminders, including the complete chosen day. The current day is persisted before one mail invocation; ambiguous failures never retry automatically.

## 2. Merchant and customer flows

Open WooCommerce > StockDigest, enter one recipient and enable the digest. The first due run is scheduled one hour later and subsequent runs are daily. Mark replenishment dates beside products. Existing immediate WooCommerce low-stock email is unchanged.

## 3. Security and permissions

The run and settings/reminder writes share a database connection-owned GET_LOCK, with release in finally. Persisting the mail claim before wp_mail prevents retry races and avoids a lease expiring during delivery. All admin mutations require manage_woocommerce, POST and an action-specific nonce; product changes also require edit_post for that product. Strict scalar checkbox, email and exact date validation precedes mutation.

## 4. Data, privacy and lifecycle

ccsd_settings stores the opted-in recipient and enabled flag. ccsd_run stores one current-day cursor/status and bounded temporary product rows, which are cleared at completion. Product _ccsd_ordered_until stores a merchant date. No customer/IP/analytics records are collected. Core privacy export matches the recipient; erasure disables mail and clears the address. Product reminder dates contain business inventory data.

Deactivation clears ccsd_daily and ccsd_continue and retains settings. Uninstall clears both cron hooks and the two options; product reminder dates remain. Product deletion naturally removes its own meta. Site-local activation only; network-wide activation is not supported.

## 5. Performance and failure policy

WP-Cron depends on traffic. The reminder list is paginated in 100-product pages, including variations; the normal WooCommerce inventory remains available. The digest covers at most 10,000 published products per daily run and 500 rows, visibly flagging truncation. Concurrent inventory edits during pagination may affect the current report. At-most-once mail invocation can lose a report if the process crashes after its claim; delivery is not guaranteed. No automatic resend button is provided.

All queries are bounded. No remote runtime service or shared Coocadev plugin is required. No frontend asset bundle loads on unrelated pages.

## 6. Acceptance and negative scenarios

Enable/disable, low/healthy/ordered selection, once-per-day replay, failed-mail no-auto-retry, 100-row continuation, final deduplicated mail, recipient export/erasure and cron cleanup.

Run on WordPress current/minimum, PHP8.1/current where available and WooCommerce current/minimum. For order functionality, test HPOS without compatibility sync and legacy independently. Native classic and Blocks checkout must be exercised where this plugin changes checkout. Run Plugin Check, WPCS, PHP lint and WP_DEBUG review. Inspect same-ID concurrent requests and permanent deletion, malformed HTTP data, nonce/capability failures and plain/HTML emails.

## 7. Release gates still separate

Naming and slug checks; actual product page and directory assets; manual screen-reader, RTL, Safari/Firefox and mobile-admin acceptance; full third-party/cache/multisite matrix; exact final ZIP/hash install smoke; owner production/submission action. GPL-open readable source is required now and is not contingent on directory approval.

Durability note: mutex holders read the authoritative stored configuration and run state rather than an earlier per-request option cache. Each progress/claim/result write is checked against the database. A failed pre-mail claim sends nothing; a failed result write leaves the durable claim and never automatically resends. An already accepted email cannot be recalled after privacy erasure.
