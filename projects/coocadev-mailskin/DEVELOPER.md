# Developer guide

Runtime PHP in `plugin/` is the full preferred source form. Copy it into `coocadev-mailskin`; there is no compile step, vendor runtime or shared plugin. Namespace `Coocadev\MailSkin`, prefix `ccms_`, text domain `coocadev-mailskin`.

`coocadev-mailskin.php` bootstraps `src/class-plugin.php`. The class validates settings, renders the saved sandboxed `srcdoc` preview and filters `wp_mail` at priority20. `uninstall.php` removes only `ccms_settings` when explicitly requested. `tests/integration.php` uses synthetic messages and blocks actual transport with `pre_wp_mail`.

`mail()` preserves every argument except `message`. An explicit text/html header selects HTML branding; plain text receives no HTML; multipart is skipped. Full HTML bodies receive header/footer insertion without nesting another document. HTML fragments receive one of three fixed skins. Existing mail body HTML is trusted original mailer output, not accepted user-authored CSS. Only a validated six-digit hex color reaches dynamic CSS; brand/signature are escaped. The optional logo must be an image attachment editable by the administrator. Sender/from/content-type/SMTP filters are not replaced.

To skip selected messages, register `add_filter( 'ccms_should_brand', $callback, 10, 2 )` and return false based on the mail argument array. Do not use the filter for unrequested telemetry or transport. The preview is an escaped iframe with an empty sandbox and never calls `wp_mail`.

Save requires POST, `manage_options`, nonce and a complete explicit scalar schema. Malformed checkboxes/arrays/colors/logos are rejected before persistence. Activation leaves branding disabled. No email logs, recipient database, external HTTP APIs or tracking assets exist. Site-media images can be requested by recipient applications in the normal way.

Run `wp eval-file /absolute/path/to/tests/integration.php`, PHP8.1 lint, WPCS and Plugin Check on isolated current/minimum WordPress. Capture actual logs; test important third-party mail templates and real email clients separately. GPL-2.0-or-later contributions need focused reproducers/regression tests and English explanatory comments. Package runtime allowlist only, align all version/slug fields, and verify final SHA256/ZIP install before separately authorized publication.
