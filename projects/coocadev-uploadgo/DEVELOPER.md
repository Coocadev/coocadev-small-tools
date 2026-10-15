# UploadGo developer guide

UploadGo adds restrictive upload policy to supported native WordPress upload
paths. The server inspects the actual local file; the media UI explains the same
policy. Activation starts disabled so the administrator can review policy first.

## License and readable source

Copyright (c) 2026 Coocadev. SPDX-License-Identifier: GPL-2.0-or-later.
Use, study, modification and redistribution are permitted under GNU GPL version 2
or later. Preserve notices, keep redistributed derivatives GPL-compatible and
provide corresponding source as the license requires. `plugin/LICENSE` contains
the full GPLv2 text; the later-version notice grants the later-version choice.
There is no license-key requirement for any shipped feature.

The PHP and `assets/media.js`/CSS are complete, unminified source. No npm build,
remote code or mandatory Coocadev runtime is needed. Install the contents of
`plugin/` as `coocadev-uploadgo`; do not install tests or reports as plugin code.

## Code map and execution flow

| File | Responsibility |
| --- | --- |
| `plugin/coocadev-uploadgo.php` | Per-site activation, cleanup scheduling and boot |
| `plugin/src/class-policyrepository.php` | Versioned schema, validation and exact-value CAS |
| `plugin/src/class-policyresolver.php` | Global/all-role intersection and file-family mapping |
| `plugin/src/class-fileinspector.php` | Actual bytes, MIME/content checks and bounded image headers |
| `plugin/src/class-uploadhooks.php` | Native upload/sideload/finalize adapters and diagnostics |
| `plugin/src/class-adminpage.php` | Settings request guards, previews and local media guidance |
| `plugin/assets/media.js` | Explanatory hints and client-size feedback only |
| `plugin/uninstall.php` | Opt-in removal of this site's own settings/diagnostics |

On each supported upload, the resolver reads current settings and computes the
effective rule for the actual user. Inspector verifies local file facts and
returns true or a public-safe WP_Error. The hook preserves existing upstream
errors and converts policy rejection into the native uploader's error format.
It does not delete already stored media or create a new public upload route.

## Hooks, permissions and stored data

Server hooks are `wp_handle_upload_prefilter`, `wp_handle_sideload_prefilter`,
`upload_mimes`, `upload_size_limit` and `rest_dispatch_request` for the supported
WordPress media finalize route. Settings actions are `admin_post_ccug_save`,
`ccug_export`, `ccug_reset` and `ccug_clear`. Administrative requests repeat POST,
`manage_options` and action nonce checks; the JSON policy is typed/allowlisted.
Present checkboxes accept only the exact canonical form value; malformed arrays
must not silently disable policy. Core remains responsible for upload capability.

`ccug_settings` is a site-local versioned option with `config_revision`, enabled
flag, global/role policies, background/diagnostic/uninstall choices. Saving uses
compare-and-swap against the exact old serialized value, then invalidates its
options cache. Invalid stored schema remains unchanged for administrator repair;
the visible, documented fallback is ordinary Core restrictions.

Global and all matching roles intersect: smallest configured numeric bounds,
intersection of enabled families. Role order is irrelevant. Null numeric fields
mean no additional limit, and an enabled empty family list means deny all.
Administrator is not a bypass. No-user background work uses only global policy
when explicitly enabled. Never add MIME entries to Core's incoming allowlist.

Optional `ccug_diagnostics` stores at most 100 timestamp/reason/channel events,
without filenames, IDs, addresses or contents. `ccug_prune_diagnostics` schedules
expiry cleanup. Records older than 24 hours disappear from display; physical
cleanup waits for cron, another event, manual clear or deactivation.

## Trust boundaries and media finalize

Submitted byte counts, browser MIME and image dimensions are hints, not facts.
Inspect the actual server file and account for JPEG orientation. The bounded EXIF
reader works without PHP's Exif extension. A dimension-limited uninspectable image
is rejected. This is not antivirus or a complete decompression/animation defense.

The WordPress 7.1 finalize adapter rechecks current upload/edit capabilities,
attachment provenance, local uploads containment and current policy. It replaces
client filesize/MIME/dimensions with inspected facts before Core persistence.
Do not trust a derivative flag or exempt a generated companion file.

## Lifecycle, extension example and testing

Network activation is rejected; activate per site. Deactivation clears only
diagnostics/scheduled cleanup and retains policy/media. Uninstall retains policy
by default; explicit removal deletes only UploadGo's options, never media or roles.
Third-party widgets, direct filesystem writes, offload and importer/multisite
workflows need their own compatibility tests.

To inspect an effective policy from trusted server-side integration code:

```php
$policy = \Coocadev\UploadGo\PolicyResolver::current();
if ( null !== $policy ) {
    // Read only; upload enforcement still belongs to the native server hooks.
    $extra_byte_limit = $policy['max_bytes'];
}
```

These classes are implementation details, not a promised stable public SDK.
`tests/integration.php` covers intersection, file facts, finalize and CAS;
`tests/http.mjs` covers authenticated upload/settings boundaries;
`tests/browser.cjs` covers media/settings UI; `tests/lifecycle.php` covers cleanup.
Use isolated disposable WordPress data and read fixture requirements before use.
Run `php -l` on PHP and `node --check plugin/assets/media.js` for syntax checks.
Historical exact-artifact evidence remains in `reports/0.1.0/`; this documentation
pass has a separate dated report and does not certify a newly built release.

Contribute focused patches with English explanations of policy/security choices
and behavioral regressions. Keep credentials, private media, local fixture JSON,
databases and email evidence out of public source and runtime packages.
