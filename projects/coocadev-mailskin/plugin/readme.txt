=== Coocadev MailSkin ===
Contributors: coocadev
Tags: email templates, email branding, notifications
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.2.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Optional email branding with three HTML skins, plain-text signatures and a preview that never sends mail.

== Description ==
Choose Clean, Stripe or Card, a brand name, accent color, signature and optional local media logo. Branding is disabled until explicitly enabled. Saved settings have a real sandboxed HTML preview without sending a test message.

Only the message body changes. Recipients, subjects, headers and attachments remain unchanged. Explicit text/html messages receive HTML branding; plain text stays plain text with a signature. Multipart messages are untouched. Complete HTML documents retain their own layout and receive header/footer insertion; selectable fragment skins apply to HTML fragments. Test third-party transactional templates before enabling globally. Developers can opt selected messages out with ccms_should_brand.

MailSkin does not configure SMTP or guarantee delivery. No mail logs, recipient database, cookies, tracking pixels, telemetry or remote runtime dependencies. A configured logo is a site-media image the recipient's mail application may request.

== Installation ==
1. Activate and open Settings > MailSkin.
2. Save a skin, brand, six-digit accent color, optional editable image attachment ID and signature.
3. Inspect the saved preview, then explicitly enable branding when ready.

== Frequently Asked Questions ==
= Does preview send an email? =
No. The saved HTML is rendered inside a sandboxed iframe in the settings page.

= What happens on removal? =
Deactivation stops branding immediately. Uninstall preserves settings unless deletion was explicitly selected. No emails or accounts are stored. Network-wide cleanup is not claimed.

= Is the code open? =
Yes. Readable PHP is included under GPL-2.0-or-later. See LICENSE; no compilation or paid unlock is required.

== Changelog ==
= 0.2.0 =
* Initial local candidate with HTML skins, plain-text signatures and no-send preview.
