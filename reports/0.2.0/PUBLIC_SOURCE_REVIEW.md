# Public source review — 0.2.0

Date: 2026-10-15. Scope: the public-source staging rules, the fifteen runtime source trees, English contributor/developer documentation, and the actual selected development sources. This was a local read-only release audit except for the five README rewrites explicitly assigned below. No public repository, production site, runtime file or shared staging script was changed by this reviewer. No staging directory or source ZIP was created by this audit.

## Observed result

**PASS for the currently selected source privacy/license preflight. Final publication remains pending the parent's final package reports, freeze and manifest verification.** This is not WordPress.org approval, a guarantee that arbitrary future source is secret-free, or a new functional test of every plugin.

The latest exact selection/validation portion of `qa/stage-public-source.py` was evaluated without its copy/archive operations. It accepted 268 files / 1,500,225 bytes: 109 runtime files, 76 explicitly approved test-source files, seven available current product release reports, and 76 documentation/packaging/debugging files. Counts are a point-in-time snapshot while other agents finish reports; rerun the complete staging validation after the final freeze.

Reviewed staging script SHA-256: `a02f47542f2a5a827050e6b6cec271aaef8e26d0c7ffbf197d048d2b92dc86bb`.

Reviewed `qa/public-test-files.json` SHA-256: `bd7a07e23e8a19add6e0f16cc8aa897fca27f0fad9dc8a74e7b9adb578d8238e`.

## Publication boundary and sensitive material

- Runtime selection accepts the expected bootstrap, uninstall, readme, license and readable PHP/JS/CSS/block JSON paths. All fifteen have their expected bootstrap, readme and full license; no runtime build or mandatory shared Coocadev plugin is needed.
- The original recursive test-extension selection was identified as unsafe: a future credential-bearing source file could be selected solely by extension. The parent replaced it with 76 individually reviewed source paths in `qa/public-test-files.json`. The actual list has no unmatched entries.
- The original leaf-only symlink check was identified as insufficient for Windows directory junctions. The parent added resolved-root containment and symlink/junction checks for every ancestor. No currently selected file or ancestor is a reparse path.
- Actual selected paths contain no installed QA site, secret directory, private-mail/evidence directory, database/dump, `.runtime-fixture.json`, credential/cookie artifact, tool binary, vendor tree or `.git` directory. Fixed QA source entry points are selected explicitly; their required local secret/config files are not copied.
- Configured private-key, GitHub/OpenAI/Google-token scans pass. A supplementary assignment/credential-DSN/address/long-encoded-literal review found no embedded actual credential or customer database. Fixture email addresses are synthetic; the two long encoded literals are a one-pixel PNG used by local media tests. Test code that generates synthetic credentials or reads an excluded local secret file is source, not a captured credential artifact.
- VS Code launch/tasks/extensions/workspace JSON was inspected: local PHP/Xdebug commands, localhost binding and source mappings only, with no authentication material. The source includes development scripts, not downloaded PHP/WordPress/WooCommerce/debugger binaries.
- The new StockDigest and DownloadRescue `tests/durability.php` files were included in the reviewed test list. They use guarded disposable local WooCommerce fixtures and intercept mail; no production order or email content is embedded.

The source scanner is defense in depth, not proof against every possible secret format. New source paths or content still need review. Freeze the tree before staging and compare staged/archive bytes with `SOURCE_MANIFEST.json` before any publication.

## GPL, preferred source and English documentation

All fifteen main headers explicitly grant `GPL-2.0-or-later`, require PHP 8.1 / WordPress 6.9 and declare version 0.2.0. Every readme has the same GPL version-or-later grant and stable tag; `GPLv2 or later` wording is equivalent to the SPDX spelling here. Each plugin contains the complete GPLv2 text. Three historical license copies differ in FSF contact/example-appendix formatting, with the operative terms unchanged; they were preserved rather than replaced with a short notice.

All fifteen have an English `DEVELOPER.md`. Runtime PHP/JS/CSS/JSON contains no Cyrillic text, and is readable preferred source rather than minified or opaque-only output. Targeted license/activation/subscription/premium/remote-request checks found no hidden local-feature key gate, remote Coocadev activation dependency or required account. WooCommerce is the explicitly documented dependency of DispatchGo and the three commerce tools.

`OPEN_SOURCE.md`, `CONTRIBUTING.md` and `SECURITY.md` correctly explain use/modification/redistribution, contribution licensing, independent installation, local-only destructive QA, private vulnerability reporting and release-candidate status. The parent changed the premature publication claim to a canonical repository address. No statement of WordPress.org approval was found. Private review correspondence and the D: review registry are not part of the source allowlist; the original maintainer plans were removed from public staging.

## Assigned documentation correction

The five original project `README.md` files were rewritten in English for 0.2.0: FileGo, UploadGo, DispatchGo, Reader Notes and DoneMark. They now describe setup, features, precise supported scope, retention/privacy, GPL and readable source, with links to `DEVELOPER.md`, the current product report and the shared contribution policy. Private D: registry links, omitted compliance links and old test-pass claims were removed.

Each original README was first preserved byte-for-byte as that project's `reports/0.1.0/README-before-open-source.md`, outside the public-source selection. Per-project runtime SHA-256 maps were identical before and after these five README-only operations. No old PASS was presented as verification of 0.2.0.

## Final handoff items

At this snapshot, the parent was still assembling the remaining eight current product reports and the current aggregate `PACKAGE_REPORT.md`; complete those before publication so every public report link resolves and old 0.1.0 aggregate claims do not stand in for 0.2.0 checks. Two scanner reports initially had Windows-1252 em dashes and made strict UTF-8 staging fail; the parent corrected them and the repeated exact read-only validation now passes.

No further source-privacy/license blocker was found in the reviewed selection. The parent owns the final changed-file review, fresh complete selection/hash check, source/archive creation and any subsequent authorized publication.
