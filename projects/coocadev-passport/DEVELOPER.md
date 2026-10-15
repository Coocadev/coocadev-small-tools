# Passport developer guide

Copyright (c) 2026 Coocadev. SPDX-License-Identifier: GPL-2.0-or-later.
The runtime and this guide may be used, studied, modified and redistributed under
GNU GPL version 2 or later. Preserve notices and provide corresponding source as
the license requires. The included GPLv2 text and later-version notice apply;
there is no license key or mandatory shared runtime.

## Code map and flow

`plugin/coocadev-passport.php` registers activation and loads
`src/class-plugin.php`. Plugin owns native links/menu, data validation, option CAS,
POST handlers and private JSON export. `uninstall.php` implements explicit per-site
removal. There are no JavaScript/CSS build artifacts or frontend hooks.

The `plugin_action_links` filter adds Passport links after checking both
manage_options and activate_plugins. Admin page and POST actions repeat those
checks; seeing a link is not authorization. Actions are ccpp_save, ccpp_settings
and ccpp_export. Method/capabilities/nonce precede payload processing. Never expose
state() as a public API: this repository read intentionally has no request context.

## Data, consistency and lifecycle

One non-autoload option ccpp_data contains schema, revision, remove_data and cards
keyed by exact installed basename. Every card has purpose/usage/responsible/checklist
plain text with documented byte bounds. No author ID/email/IP is copied. Basenames
come from get_plugins(); do not include a user-supplied file path or trust a label.

CAS matches the exact old serialized value and expected revision, then deletes the
options cache. Concurrent cards share the revision: conflict is explicit, not a
silent merge. Unknown/corrupt stored data stops writes rather than dropping notes.
Removed-plugin cards are retained/read-only until that exact basename is installed.

Deactivation/default uninstall preserve data. Explicit removal drops only the own
option; network activation is rejected. The uninstall iterator respects each site's
preference. Free-text privacy requests require manual review because names cannot
be safely mapped to a WordPress account. JSON exports include that private text.

## Readable source and an example

No build process is needed. Install the plugin/ contents under
coocadev-passport. From trusted code after checking Plugin::allowed():

```php
$cards = \Coocadev\PluginPassport\Plugin::export();
if ( ! is_wp_error( $cards ) ) {
    // Keep this administrative export private; it can contain staff names.
}
```

These classes are internal implementation details, not a versioned external SDK.
Do not add a public route around this example. Use exact scalar validation and
contextual escaping instead of broad sanitizer coercion for future fields.

## Tests and contributions

tests/integration.php runs real WordPress domain/permission/HTTP assertions with
temporary synthetic users and restores state. Lifecycle workers use separate Core
uninstall processes. Read fixture assumptions and run only on disposable sites.
PHP syntax: php -l for all runtime files. WPCS/Plugin Check evidence and review
exceptions live in reports/0.2.0. Full ZIP/hash verification is a separate gate.
No earlier product's PASS is inherited by this plugin.

Contribute small patches with English explanations of non-obvious permission,
validation and concurrency choices plus behavioral tests. Public source packages
must exclude credentials, fixture cookies, databases, private email and customer
notes. Test multisite/cache/browser behavior before expanding compatibility claims.
