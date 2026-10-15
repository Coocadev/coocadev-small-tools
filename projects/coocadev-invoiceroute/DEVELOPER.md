# Developer guide: InvoiceRoute

The runtime is plugin/coocadev-invoiceroute.php, plugin/src/class-plugin.php, plugin/uninstall.php, plugin/readme.txt and plugin/LICENSE. PHP requires 8.1+, WordPress 6.9+, WooCommerce 10.2+. Comments and UI use English. No transpilation, minification, Composer autoloader or build-time dependency is needed. Copy the plugin contents into a coocadev-invoiceroute directory or use the root allowlist packaging script.

## Code flow and boundaries

Capture an optional accounting address at classic checkout or native Checkout Blocks additional field. Freeze it after an order ID exists and before payment. A primary-key receipt table wins concurrent retries; blank is an immutable choice too. Only the WooCommerce customer_invoice recipient filter uses the receipt. Billing, payment, processing, completed and shipping recipients are otherwise unchanged.

Strict validation rejects multi-recipient lists, CR/LF, arrays, null and oversized addresses instead of converting input into another recipient. Classic and Blocks validation execute within native checkout security. Only final Blocks processing freezes a draft. INSERT IGNORE with the order primary key makes retry captures immutable; routing reads the authoritative table, bypassing mutable order meta. Prepared SQL touches only this plugin table; WC_Order CRUD touches orders. Deletion and receipt creation use the same scoped connection-owned lock.

## Data contract

The site-local ccir_receipts table stores order_id, email and UTC created_at. WC_Order meta _ccir_accounting_email is a readable mirror, not routing authority; temporary _ccir_checkout_input is removed after capture. Checkout Blocks manages its own native additional-field metadata. Core privacy export matches accounting email. Erasure explicitly reports receipt retention under the merchant commercial-order retention policy.

Permanent order deletion while active removes the receipt, with a shared lock spanning Core deletion. Trashing preserves it. Deactivation and uninstall preserve historical receipts and WooCommerce metadata; no cron exists. If the plugin is removed while records are retained, their eventual cleanup requires an explicit merchant retention decision. Site-local activation only; network-wide activation is not supported.

## Testing

Run PHP lint on every runtime PHP file. Use the repository QA PHP_CodeSniffer WordPress standard via phpcs.xml.dist. On an isolated local QA WordPress/Woo installation, activate the plugin then run wp eval-file tests/integration.php. The test hard-fails outside the reserved local QA directory, creates synthetic fixtures, intercepts pre_wp_mail and cleans its own fixtures. Production data and real payments/mail are never test inputs. Reports are under reports/0.2.0.

Do not suppress a security finding broadly. Inline phpcs ignores explain exact prepared-query/strict-validation cases. No external requests to Coocadev or telemetry are present.
