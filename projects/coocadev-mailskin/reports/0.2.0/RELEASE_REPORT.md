# MailSkin 0.2.0 — local release evidence

Date: 2026-10-15. Local candidate, full GPL-2.0-or-later readable source with English comments/docs. No production change, submission or approval. Parent process records final ZIP/hash and verifies combined installed packages separately.

Final matrix: WordPress7.1.3/PHP8.5.5 and WordPress6.9/PHP8.1.34, local MariaDB QA3317, ordinary copied plugin directories on current/minimum reader-notes sites. No shared setup restart or source junction installation.

| Check | Observed result | Evidence |
| --- | --- | --- |
| Domain/current | 16/16 | integration-current-final.log |
| Domain/minimum | 16/16 | integration-minimum-final.log |
| Actual HTTP | 6 relevant assertions including shared unchanged settings | http-boundaries.json |
| Separate-process lifecycle | 3/3 | lifecycle.json |
| PHP8.1 syntax | All3 PHP files pass | lint-php81.log |
| WPCS3.13.6 | 0 errors,0 warnings | wpcs.json |
| PHPCompatibilityWP8.1 | 0 errors,0 warnings | phpcompatibility.json |
| Plugin Check2.1.0 | 0 errors,0 warnings | plugin-check.log / plugin-check.json |
| Source | No compilation/minification; complete preferred PHP and license | source-manifest.json / plugin/LICENSE |

Actually run: PHP8.1 lint, PHPCS WordPress and PHPCompatibilityWP8.1 reports, WP-CLI activate/eval-file/plugin check, shared localhost HTTP and separate-process lifecycle Python workers in Client Nudge/tests. Synthetic mail addresses use example.test and every domain-test send is intercepted by pre_wp_mail. No real mail was sent. Test entry points refuse nonlocal execution before writes. Current-site debug log changed by zero bytes across the observed test interval; see Client Nudge `wp-debug-delta.json`.

Covered: disabled by default; explicit settings; text/plain remains plain; exact preservation of recipients, subject, headers and attachments; plain/HTML idempotence; original fragment retained; complete document not nested; multipart bypass; all three fragment skins differ; malformed checkbox, CSS payload and unknown logo rejected; per-message filter opt-out; actual wp_mail hook intercepted before transport. HTTP proves settings render plus GET/nonce/type/capability rejection and unchanged settings. Separate-process uninstall proves preserve/delete behavior. No WPCS/PCP exceptions remain for this plugin.

The full HTML preview uses escaped sandboxed iframe srcdoc and does not send email. Complete HTML keeps its own surrounding design and receives brand/header/footer only; the selected three skins apply to fragments. Plain text receives a plain-text footer only. Existing SMTP/transport and later content-type filters retain responsibility. These scopes are documented in README/readme/PLAN. `PLAN.md` includes the applicable shared review-registry crosswalk.

OPEN: screenshots/visual acceptance, physical mail-client rendering, major third-party transactional-template compatibility, RTL/screen-reader, network-wide lifecycle, current name clearance, public product page/assets and exact final ZIP smoke. No SMTP delivery or universal template compatibility guarantee.

Tool limitation: PHP8.5 may print a startup deprecation from the bundled WP-CLI vendor/react/promise semicolon case statement before loading WordPress. Raw integration logs preserve it; normalized integration-*-final.json files retain the behavioral results. This tool warning is distinct from plugin-runtime failures and is not hidden.

## Coordinator final package gate

The aggregate 0.2.0 gate is now complete: this exact runtime ZIP was installed together with all fifteen tools; source/ZIP/installed hashes and syntax matched, final installed Plugin Check had no errors, and the all-15 bootstrap/debug plus joint runtime/browser checks passed. See [aggregate report](../../../../PACKAGE_REPORT.md) and [debug report](../../../../reports/0.2.0/DEBUG_REPORT.md). This closes the earlier root-packaging handoff only; product-specific open/manual gates above remain open.
