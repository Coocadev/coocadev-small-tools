# Developer guide: DownloadRescue

The runtime is plugin/coocadev-downloadrescue.php, plugin/src/class-plugin.php, plugin/uninstall.php, plugin/readme.txt and plugin/LICENSE. PHP requires 8.1+, WordPress 6.9+, WooCommerce 10.2+. Comments and UI use English. No transpilation, minification, Composer autoloader or build-time dependency is needed. Copy the plugin contents into a coocadev-downloadrescue directory or use the root allowlist packaging script.

## Code flow and boundaries

A signed-in customer viewing their own eligible paid order can request review of existing expired or exhausted download permissions. Requests are idempotent while pending and limited to once per 24 hours afterwards. The WooCommerce > DownloadRescue queue finds pending orders. A merchant explicitly approves or rejects on the order page. Approval renews only requested permissions still matching purchased current files, grants seven days and at least five downloads, and invokes the native completed/processing customer email once.

The owner ID is current_user_id and must exactly equal the nonzero order customer ID. Merchant operations require manage_woocommerce and edit_shop_order for that object, POST and a generation-bound nonce. Order status, refunds, file presence, purchased product, permission customer/email/order key and expiration are rechecked inside a per-order connection-owned lock. An action claim is persisted before permission CRUD and one native resend invocation. Native save forms are not nested or overridden: separate footer forms own approval controls. Private account routes use no-cache headers; do not page-cache WooCommerce My account.

## Data contract

WC_Order metadata _ccdr_request stores status, request time, random generation token, requested permission IDs and decision time. _ccdr_status is a queryable queue enum. Existing WC_Customer_Download objects remain in WooCommerce storage. No additional email/IP/cookie/analytics records are collected. Core export shows order/request status and time, excluding tokens, URLs and keys; erasure removes request metadata without changing permissions.

No activation schema or cron is required. Deactivation and uninstall retain request history and native permissions. Before uninstall, Core privacy erasure can remove requests for an address. Permanent order deletion follows native WC lifecycle and shares the request/decision mutex. Site-local operation only; network-wide activation is not supported.

## Testing

Run PHP lint on every runtime PHP file. Use the repository QA PHP_CodeSniffer WordPress standard via phpcs.xml.dist. On an isolated local QA WordPress/Woo installation, activate the plugin then run wp eval-file tests/integration.php. The test hard-fails outside the reserved local QA directory, creates synthetic fixtures, intercepts pre_wp_mail and cleans its own fixtures. Production data and real payments/mail are never test inputs. Reports are under reports/0.2.0.

Do not suppress a security finding broadly. Inline phpcs ignores explain exact prepared-query/strict-validation cases. No external requests to Coocadev or telemetry are present.

Durability note: native WC_Order save can swallow an exception and return an existing ID, so every request/decision state is verified through forced metadata readback. Permissions are also read back before a native resend. A crash or partial persistence after the durable granting claim requires merchant review through WooCommerce; no automatic replay occurs. Expiry uses native WooCommerce calendar-date semantics, valid through the selected date, rather than an exact seven-times-24-hour timer.
