# Developer guide

The unminified PHP under `plugin/` is the complete preferred source form. Copy it to a plugin directory named `coocadev-hoursgo`; no compilation/vendor runtime is needed.

`coocadev-hoursgo.php` bootstraps `Coocadev\HoursGo\Plugin`. `src/class-plugin.php` contains pure schedule validation/status calculations, the authorized settings controller, escaped admin form and `[cchg_hours]`. `uninstall.php` removes `cchg_settings` only after opt-in. Prefix `cchg_`; literal text domain `coocadev-hoursgo`.

`local_time()` parses local wall-clock values with `wp_timezone()`. It rejects normalized invalid dates, spring-forward gaps and repeated fall-back minutes. `validate()` enforces seven daily pairs, count/duration limits and non-overlapping UTC exceptions. `status()` uses one server timestamp for day selection and open/closed calculation; end boundaries are exclusive. Stored exceptions remain absolute instants if the site timezone changes.

The POST controller authorizes `manage_options` and nonce before allowlisting grid keys. Every scalar cell passes lossless plain-text checks; the full resulting schema validates before one write. Exceptions are display messages rather than unverified structured opening periods. Time-sensitive output disables page caching via headers/constant, but external cache exclusion must be configured by the operator. No visitor state, JS or cron is required.

Run the synthetic `tests/integration.php` through `wp eval-file`, PHP8.1 lint, WPCS and Plugin Check. Test minimum/current WordPress, DST zones, exact start/end and invalid requests. Report actual results and remaining manual/mobile/RTL/accessibility/cache gates. Tests and local QA credentials must not be packaged.

Contributions: GPL-2.0-or-later, focused reproducer/regression test, English comments explaining behavior. Package only runtime allowlist with aligned slug/header/readme version and SHA256. Exact-ZIP verification precedes separately authorized publication.
