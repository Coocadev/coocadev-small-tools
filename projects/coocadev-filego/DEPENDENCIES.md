# Dependencies and build

Runtime: WordPress APIs, PHP Fileinfo, SPL and hash; MySQL/MariaDB InnoDB. No bundled third-party JavaScript, fonts, assets or PHP library. Source PHP/CSS is shipped directly; no minification or remote build service.

All project code is GPL-2.0-or-later. `plugin/LICENSE` contains the unmodified GPLv2 license text. WordPress supplies its own dependencies; no development vendor folder belongs in the ZIP.

Build allowlist: contents of `plugin/` into one `coocadev-filego/` root. Exclude tests, reports, credentials and project plans. Test scripts use synthetic local data only. `tests/document_source.py` was a one-time development helper; do not rerun it on already documented classes.

Local QA prerequisites: isolated WordPress site, PHP8.1+, Fileinfo, InnoDB, HTTP server, QA account secret in `qa/secrets/filego.json` outside public root. Integration reads that local secret in memory and removes its cookie jar in finally. It never prints credentials. Lifecycle intentionally drops only FileGo tables after opt-in and verifies media retention; do not run on production.
