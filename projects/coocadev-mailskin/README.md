# Coocadev MailSkin

Version 0.2.0 local development candidate. Requires WordPress 6.9 and PHP 8.1. Full readable PHP source is in `plugin/`, GPL-2.0-or-later. No build, account, shared runtime or paid unlock is required.

Open Settings > MailSkin. Select Clean, Stripe or Card; set a brand, six-digit accent color, optional local image attachment ID and signature. Save to see a real sandboxed HTML preview. Preview never sends email. Enable branding explicitly when ready.

Only mail bodies change. Recipients, subject, headers and attachments retain their values. Explicit `Content-Type: text/html` messages receive a template; plain text receives a plain-text signature; multipart messages stay untouched. Existing complete HTML documents retain their own body/design and receive a brand header/signature rather than another nested HTML document. The three selectable skins apply to HTML fragments. A later content-type/transport filter remains that mailer's responsibility. No SMTP settings or delivery guarantee are added. Test important third-party transactional mail before enabling globally.

Use the `ccms_should_brand` filter to opt selected messages out. Deactivation stops branding immediately. Uninstall preserves settings unless deletion was explicitly selected. No mail log, recipient storage, cookies, statistics, cron or tracking pixel. A configured logo uses a normal site-media URL that the recipient's email application may request. Single-site lifecycle is tested; network-wide cleanup is not claimed.

You may use, inspect, modify and redistribute this code under GPL version 2 or later; see `plugin/LICENSE`, `DEVELOPER.md`, `DEPENDENCIES.md`, `PLAN.md`, and the release report. No production publication or directory submission occurred. Naming, actual product page and directory assets remain open release gates.
