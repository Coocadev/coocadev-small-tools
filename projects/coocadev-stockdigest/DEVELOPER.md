# Developer guide: StockDigest

The runtime is plugin/coocadev-stockdigest.php, plugin/src/class-plugin.php, plugin/uninstall.php, plugin/readme.txt and plugin/LICENSE. PHP requires 8.1+, WordPress 6.9+, WooCommerce 10.2+. Comments and UI use English. No transpilation, minification, Composer autoloader or build-time dependency is needed. Copy the plugin contents into a coocadev-stockdigest directory or use the root allowlist packaging script.

## Code flow and boundaries

The merchant explicitly enables one recipient. Each daily run scans 100 products per invocation in ascending ID order, up to 10,000 products, and retains at most 500 report rows. Each product uses its native WooCommerce low-stock threshold. An ordered-until UTC date suppresses reminders, including the complete chosen day. The current day is persisted before one mail invocation; ambiguous failures never retry automatically.

The run and settings/reminder writes share a database connection-owned GET_LOCK, with release in finally. Persisting the mail claim before wp_mail prevents retry races and avoids a lease expiring during delivery. All admin mutations require manage_woocommerce, POST and an action-specific nonce; product changes also require edit_post for that product. Strict scalar checkbox, email and exact date validation precedes mutation.

## Data contract

ccsd_settings stores the opted-in recipient and enabled flag. ccsd_run stores one current-day cursor/status and bounded temporary product rows, which are cleared at completion. Product _ccsd_ordered_until stores a merchant date. No customer/IP/analytics records are collected. Core privacy export matches the recipient; erasure disables mail and clears the address. Product reminder dates contain business inventory data.

Deactivation clears ccsd_daily and ccsd_continue and retains settings. Uninstall clears both cron hooks and the two options; product reminder dates remain. Product deletion naturally removes its own meta. Site-local activation only; network-wide activation is not supported.

## Testing

Run PHP lint on every runtime PHP file. Use the repository QA PHP_CodeSniffer WordPress standard via phpcs.xml.dist. On an isolated local QA WordPress/Woo installation, activate the plugin then run wp eval-file tests/integration.php. The test hard-fails outside the reserved local QA directory, creates synthetic fixtures, intercepts pre_wp_mail and cleans its own fixtures. Production data and real payments/mail are never test inputs. Reports are under reports/0.2.0.

Do not suppress a security finding broadly. Inline phpcs ignores explain exact prepared-query/strict-validation cases. No external requests to Coocadev or telemetry are present.

Durability note: mutex holders read the authoritative stored configuration and run state rather than an earlier per-request option cache. Each progress/claim/result write is checked against the database. A failed pre-mail claim sends nothing; a failed result write leaves the durable claim and never automatically resends. An already accepted email cannot be recalled after privacy erasure.
