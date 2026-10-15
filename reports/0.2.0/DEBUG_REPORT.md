# VS Code / Xdebug and WordPress debug — 0.2.0

Date: 15 October 2026. Scope: exact installed archives, all fifteen active on the isolated local combined site. This is Visual Studio Code PHP Debug, not a claim that the separate Visual Studio IDE or every interactive UI branch was tested.

## Real debugger session

The actual xdebug.php-debug1.40.2 adapter was driven through Debug Adapter Protocol with PHP8.1.34 and Xdebug3.4.5. A verified executable breakpoint was set in each installed plugin bootstrap; each stopped, evaluated the safe ABSPATH/PHP expression as true, executed one step and resumed. Result: **15/15 breakpoint hits, 15/15 single steps, successful all-15 version/activation/bootstrap probe**. Session finished: 2026-10-15T13:25:14.208Z.

The runnable configuration is in `.vscode/launch.json`, with build/verify/debug tasks and a workspace file. The automated reproduction is `node qa/debug-adapter-test.cjs`, after installing the documented local toolchain and exact ZIPs. It uses localhost and subprocess-only Xdebug settings. No authentication files, project API keys or global environment settings are changed or evaluated. Tool binaries are not distributed with the plugins.

Raw local evidence: vscode-xdebug.json. This verifies debugger transport and startup, not complete business behavior; individual product suites and browser tests supply separate coverage. The harness also validates actual step reasons/expression values and terminates its debuggee through DAP on failure.

## Subsequent WP_DEBUG runtime check

After the final debugger session, the existing debug.log byte offset was recorded. WP_DEBUG and WP_DEBUG_LOG were enabled; display remained disabled. The original-five combined HTTP fixture passed30/30 with all fifteen active, MediaMap33/33 and UseMap31/31 regressions passed, and the real Chromium UI run passed 39 assertions across fourteen native admin screens plus scan/nonce/keyboard/pause paths. Synthetic content was cleaned afterward.

Fresh log delta: **0 bytes / 0 nonempty lines**. Result: no new PHP/Core/product diagnostics in this final measured interval. Historical debug.log was not truncated. Earlier WP-CLI phar PHP8.5 vendor deprecations and intentional failure fixtures remain historical evidence; they are not represented as product errors or silently removed from an active run.

The first combined attempt failed because the HTTP server was stopped; the server was restarted and the complete fixture repeated. The browser harness's first dashboard-load wait timed out; corrected DOM-ready synchronization was then verified by the complete successful run. A mobile screenshot revealed StockDigest's missing HPOS declaration: the declaration and targeted regression were added, its archive was rebuilt/reinstalled/rechecked, and this debugger/runtime/browser sequence repeated after that final runtime change. Exact-package lint/identity/Plugin Check results are in [PACKAGE_REPORT](../../PACKAGE_REPORT.md), and the [review crosswalk](WP_REVIEW_CROSSWALK.md) lists remaining manual acceptance gates.
