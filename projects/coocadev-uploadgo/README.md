# Coocadev UploadGo

UploadGo adds predictable, restrictive upload policy to native WordPress media paths. Version **0.2.0** is a local release candidate; it is not a claim of WordPress.org approval.

## Requirements and setup

WordPress 6.9+, PHP 8.1+ and PHP Fileinfo are required when policies are enabled. Install [plugin/](plugin/) as `coocadev-uploadgo` and activate on an individual site. Activation starts with policy disabled. Open **Settings > UploadGo**, review global and role policies, then explicitly enable the rules you need.

## Features and scope

- Maximum file bytes and raster image width, height and pixel count.
- Optional file-family restrictions intersect with WordPress MIME and server policy; they never broaden it.
- Users with multiple roles receive the strictest applicable limits, including administrators.
- Actual temporary-file facts drive server checks; local editor hints explain the effective policy.
- Revision checks prevent older settings tabs silently replacing newer policy.
- Optional bounded local diagnostics omit filenames, account identifiers and file contents.

Blank numeric limits add no restriction. An enabled empty file-family list denies all uploads for that rule and requires explicit confirmation. Existing media is not resized, deleted or reprocessed. Direct filesystem/SFTP writes, third-party upload widgets, importers, MIME plugins and offloaded/multisite workflows require separate compatibility checks. Image header checks are not antivirus, complete decompression-bomb protection or an animation-frame limit. Network activation is unsupported.

## Retention and privacy

Deactivation clears diagnostics and their scheduled cleanup but retains policy and media. Default uninstall retains policy. Explicit data removal deletes only UploadGo's own options; it never deletes media or modifies roles. Background jobs without a signed-in user follow Core policy unless the administrator explicitly opts into global background restrictions. No external service, telemetry or remote asset is required.

## Source, development and verification

Copyright 2026 Coocadev. **GPL-2.0-or-later**; see the full [license](plugin/LICENSE). Shipped features have no license-key or paid-unlock requirement. Readable PHP/JS/CSS are the preferred source; no compilation or shared Coocadev plugin is needed. Runtime packages include only `plugin/`.

The English [developer guide](DEVELOPER.md) describes policy resolution, hooks, file inspection, typed settings, concurrency, examples and guarded local test entry points. The [0.2.0 release report](reports/0.2.0/RELEASE_REPORT.md) records exact-release checks and open compatibility work. Never run fixture, upload or uninstall tests against production. Contributions follow the shared [contribution policy](../../CONTRIBUTING.md).
