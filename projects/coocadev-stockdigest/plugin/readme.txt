=== Coocadev StockDigest ===
Contributors: coocadev
Tags: woocommerce, inventory, stock
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.1
Requires Plugins: woocommerce
Stable tag: 0.2.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Daily low-stock email with replenishment suppression for WooCommerce.

== Description ==

The merchant explicitly enables one recipient. Each daily run scans 100 products per invocation in ascending ID order, up to 10,000 products, and retains at most 500 report rows. Each product uses its native WooCommerce low-stock threshold. An ordered-until UTC date suppresses reminders, including the complete chosen day. The current day is persisted before one mail invocation; ambiguous failures never retry automatically.

There is no required Coocadev account, remote service, telemetry or license gate. All shipped PHP source is readable and GPL-2.0-or-later.

== Installation ==

1. Install WooCommerce 10.2 or later.
2. Install and activate Coocadev StockDigest on one site.
3. Open WooCommerce > StockDigest, enter one recipient and enable the digest. The first due run is scheduled one hour later and subsequent runs are daily. Mark replenishment dates beside products. Existing immediate WooCommerce low-stock email is unchanged.

== Frequently Asked Questions ==

= What are the limits? =

WP-Cron depends on traffic. The reminder list is paginated in 100-product pages, including variations; the normal WooCommerce inventory remains available. The digest covers at most 10,000 published products per daily run and 500 rows, visibly flagging truncation. Concurrent inventory edits during pagination may affect the current report. At-most-once mail invocation can lose a report if the process crashes after its claim; delivery is not guaranteed. No automatic resend button is provided.

= What data is stored? =

ccsd_settings stores the opted-in recipient and enabled flag. ccsd_run stores one current-day cursor/status and bounded temporary product rows, which are cleared at completion. Product _ccsd_ordered_until stores a merchant date. No customer/IP/analytics records are collected. Core privacy export matches the recipient; erasure disables mail and clears the address. Product reminder dates contain business inventory data.

= What happens on uninstall? =

Deactivation clears ccsd_daily and ccsd_continue and retains settings. Uninstall clears both cron hooks and the two options; product reminder dates remain. Product deletion naturally removes its own meta. Site-local activation only; network-wide activation is not supported.

= Is source code available? =

The package itself contains the complete readable runtime source. There is no generated or minified bundle. It is GPL-2.0-or-later; modification and redistribution follow the included license.

== Changelog ==

= 0.2.0 =
* Initial independently installable local candidate with English documentation and open source.


Durability note: mutex holders read the authoritative stored configuration and run state rather than an earlier per-request option cache. Each progress/claim/result write is checked against the database. A failed pre-mail claim sends nothing; a failed result write leaves the durable claim and never automatically resends. An already accepted email cannot be recalled after privacy erasure.
