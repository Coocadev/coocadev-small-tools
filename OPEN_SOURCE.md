# Small Tools open source policy

All fifteen Coocadev Small Tools use **GNU GPL version 2 or any later version**, SPDX identifier `GPL-2.0-or-later`. This matches WordPress's license choice. The owner approved this policy on 15 October 2026. It covers the original source code, bundled assets, tests and documentation we distribute; preserve any separately identified compatible third-party notices.

You may run these plugins for any purpose, study them, modify them and redistribute original or modified copies under the GPL. Redistribution must preserve the applicable copyright/license notices and provide the corresponding source as the GPL requires. Modifications are permitted for commercial use too. GPL does not require sending private, undistributed changes to Coocadev. The software comes without warranty to the extent permitted by law; see the complete license supplied in `LICENSE` and each plugin's `plugin/LICENSE`.

## Readable source and independent installation

Each `projects/<slug>/plugin/` is a complete independent WordPress plugin. PHP, JavaScript and CSS are readable and unminified. There is no mandatory Coocadev Core plugin, remote license check, locked local feature, paid activation or required Coocadev account. Runtime third-party dependencies, if any, are listed in each project's `DEPENDENCIES.md`; WooCommerce is an explicit dependency of the three commerce tools and DispatchGo.

The canonical source repository is https://github.com/zagulev/coocadev-small-tools. Developer documentation, tests and packaging tools accompany the runtime code. Each installable ZIP contains its corresponding readable runtime source and GPL license; it needs no transpilation or minification. Source-release archives additionally include development files. English comments explain boundaries, data flow and the reasons behind security/concurrency decisions.

## Contributions and releases

See `CONTRIBUTING.md` for setup, tests and patch expectations and `SECURITY.md` for reporting a vulnerability. Contributions are licensed under GPL-2.0-or-later; no copyright assignment is requested. Reuse does not imply endorsement or permission to represent a modified release as the original Coocadev release.

The initial public source release is a set of local release candidates. GitHub availability does not mean WordPress.org has approved or published the plugins. Exact test evidence and uncompleted release gates are recorded in `PACKAGE_REPORT.md` and the product reports. Emails and download approvals work only within their documented opt-in/merchant flows; no delivery guarantee is implied.

## What is deliberately excluded from the public source

Production credentials, Codex authentication, private review correspondence, customer data, database dumps, local test passwords, runtime fixtures with secrets, local caches, tool binaries and installed WordPress/WooCommerce copies do not belong in source releases. They are not needed to study, modify or build the plugins. Public source is staged from an explicit allowlist and reviewed before publishing.

## Primary licensing references

- WordPress license: https://wordpress.org/about/license/
- WordPress Plugin Guidelines, sections 1 and 4: https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/
- GNU GPL v2 text: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

References checked 15 October 2026. The complete license governs; this explanation is not a replacement for it.
