# Small Tools QA and packaging

The source release includes the test scripts, not the maintainers' WordPress installs, database files, passwords or downloaded binaries. Use an isolated local site, `WP_ENVIRONMENT_TYPE=local`, PHP 8.1+ and WP-CLI. Download WordPress, WooCommerce, Plugin Check and PHPCS/WPCS from their official sources. Review every fixture's local-target guard before execution.

## Entry points

- `build-packages.ps1`: deterministic runtime-only ZIPs and per-file SHA-256 manifests for the catalog products.
- `verify-packages.ps1`: ZIP/source/clean-install hashes, PHP/JS syntax and exact installed-plugin checks.
- `scanners-test.php`: behavior fixture shared by the MediaMap and UseMap test wrappers. It creates synthetic local users/media/content and cleans them in `finally`.
- `combined-smoke.php`: interoperability fixture for the original five; it intentionally accepts only the dedicated local combined QA database/URL. It tests real HTTP and multipart flows.
- Product `tests/` files: domain, HTTP, browser and lifecycle checks with their own safety guards. Use each project's `DEVELOPER.md` for commands and prerequisites. Browser tests require a separately installed browser driver.
- `stage-public-source.py`: explicit public-source allowlist. Do not publish the maintainer workspace wholesale.
- `compatibility-report.php`: read-only WooCommerce feature declarations for the dedicated combined site; browser checks also reject its active-feature warning.

## Debugger and browser prerequisites

The maintainer's optional Windows layout uses PHP8.1.34 at `qa/runtime/php81/php.exe`, the matching NTS x64 Xdebug3.4.5 DLL at `qa/runtime/php81/ext/php_xdebug-3.4.5.dll`, WP-CLI at `qa/downloads/wp-cli.phar` and the official xdebug.php-debug1.40.2 extension under `qa/runtime/vscode-extensions/`. These downloaded tools are intentionally excluded. Adapt `.vscode/launch.json` to your own matching PHP/Xdebug builds, isolated local site and paths; do not load an arbitrary DLL into a different PHP build.

`debug-adapter-test.cjs` drives the actual extension adapter, checks each entry breakpoint/evaluation/step and completes the local version probe. Install the official extension first. It does not substitute for domain tests. `browser-all-tools.cjs` requires Playwright and Chrome (or CHROME_PATH), plus excluded credentials at `qa/secrets/combined.json` with URL/user/password for the dedicated localhost8785 fixture. Prepare `browser-fixtures-020.php`, run the browser test, and always invoke its `cleanup` argument in a shell `finally` block. No contributor should copy production credentials into these fixtures.

## Typical commands

```powershell
php -l projects/coocadev-mediamap/plugin/src/class-scanner.php
node --check projects/coocadev-mediamap/plugin/assets/scan.js
php wp-cli.phar --path=/your/local/wordpress eval-file projects/coocadev-mediamap/tests/integration.php
php wp-cli.phar --path=/your/local/wordpress plugin check coocadev-mediamap --format=json
./qa/build-packages.ps1 -Slugs @('coocadev-mediamap')
```

Install and verify the built ZIP after source checks. Tests that mutate or uninstall plugin data belong on throwaway fixtures, not a production or developer-content database. Intercept outgoing email locally using `pre_wp_mail` or a local capture service. Optional email sending in product settings must remain off until an authorized administrator chooses it.

Reports distinguish PASS, FAIL, PARTIAL, OPEN and N/A. Lint alone is not functional verification. A clean Plugin Check is not WordPress.org approval. Physical Safari/Firefox/RTL/screen-reader and third-party compatibility checks remain open until performed and recorded.
