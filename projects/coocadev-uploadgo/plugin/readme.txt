=== Coocadev UploadGo ===
Contributors: coocadev
Tags: media, uploads, file size, image dimensions, roles
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.2.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Set predictable upload limits for your editors. Combine global and role rules with server-side file checks and clear upload feedback.

== Description ==

UploadGo gives administrators one place to set maximum file bytes, image width,
height and pixel count, and optional file-family restrictions. When a user has
several roles, the strictest applicable limits win. Administrators follow the
same policy system as other uploaders.

The plugin starts disabled: activation alone adds no upload restrictions.
Open Settings > UploadGo, review the global and role policies, and save.
Blank numeric fields add no extra limit. An enabled empty file-family list
blocks every new upload for that policy and requires explicit confirmation.

* WordPress permissions, MIME restrictions and server limits remain in force.
* File sizes come from the local temporary file, not a submitted size field.
* Raster dimensions are checked before normal Core image processing where
  header inspection is supported. JPEG width/height account for EXIF rotation.
* Native upload errors remain intact; rejected files are not silently accepted.
* Settings saves use revision checks so an older browser tab cannot silently
  overwrite a newer policy.
* Local editor hints and client size limits help explain the effective policy.
  The server checks still apply without JavaScript.

= Supported integration scope =

The server validator is attached to WordPress wp_handle_upload and
wp_handle_sideload prefilters. It covers files passing through those native
paths under the current authenticated user. REST multipart/raw uploads use
the same checks. On WordPress 7.1, the client-side media finalize adapter
rechecks attachment-owned files against the current policy and derives file
metadata from the actual files rather than trusting client dimensions.

Classic and block-editor interfaces use native upload paths, but third-party
media widgets, XML-RPC/importer integrations, MIME plugins, offloaded media,
and multisite workflows need their own compatibility testing. A plugin that
writes files directly, SFTP and direct filesystem changes are outside scope.
No new public upload endpoint is added. Existing attachments are not resized,
deleted or reprocessed by UploadGo.

Background processes without a signed-in user use WordPress behavior by
default. The separate background option applies only the global UploadGo
policy when enabled; it can affect importers and scheduled jobs.

= File inspection requirements =

PHP Fileinfo is required when UploadGo policies are active. Without it, files
are rejected with an administrator-facing explanation rather than accepted
without verification. Supported image-limit checks use JPEG, PNG, GIF, WebP,
AVIF, BMP, TIFF and ICO headers when the installed PHP/WordPress environment
can identify them. An uninspectable image is rejected when dimension limits
are configured. HEIC/HEIF dimension limits are not advertised as supported:
convert to JPEG, PNG or WebP first. Available upload formats still depend on
WordPress, PHP and other site policies.

Image pixel limits are not an antivirus, complete decompression-bomb defense
or an animation-frame limit. File-family checks do not scan document contents.
SVG, HTML, PHP and JavaScript cannot be enabled through UploadGo.

= Privacy and storage =

No telemetry, external API, tracking cookie or Coocadev account is used.
All checks run locally. Optional diagnostics contain only a reason code,
upload channel and timestamp, with at most 100 events. The screen shows the
last 24 hours. Expired records are physically removed when the scheduled
WordPress cleanup next runs, on a subsequent recorded event, on deactivation,
or when an administrator clears them. An idle site or disabled WP-Cron can
delay scheduled removal. Filenames, file contents, IPs and user IDs are not
included in this diagnostic buffer.

Deactivation removes diagnostic events and their scheduled cleanup, while
preserving policies and all uploaded files. Deleting the plugin preserves
policies by default. The explicit removal setting deletes only UploadGo's
own settings and diagnostics. No media, roles or WordPress settings are removed.

= Configuration failure =

If a saved configuration is missing, malformed or has an unknown schema,
UploadGo keeps that stored value and falls back to WordPress restrictions.
Administrators see an error notice. Restore a known valid configuration before
relying on extra UploadGo limits. The plugin does not silently rewrite an
unknown schema or claim its extra restrictions remain active.

== Installation ==

1. Upload the coocadev-uploadgo directory to wp-content/plugins or install its ZIP.
2. Activate Coocadev UploadGo on the intended site.
3. Open Settings > UploadGo and review global and role limits.
4. Enable policies and save. Check the effective-policy previews for your roles.
5. Test real uploads with a non-administrator editor account before broad use.

Network activation is intentionally blocked. Activate per site only; a full
multisite compatibility claim is not made in this development release.

== Frequently Asked Questions ==

= Does this increase my hosting upload limit? =

No. UploadGo can only narrow the effective WordPress/server limit. PHP,
web-server and proxy settings may reject a request before WordPress runs.

= Does a role-specific policy override the global one? =

It only narrows it. Numerical limits use the smallest configured value and
file-family lists are intersected. Role order does not change the result.

= Can editors bypass the rules by disabling JavaScript? =

The supported server upload paths inspect the real temporary file regardless
of client JavaScript. Direct filesystem writes and code that bypasses WordPress
upload APIs are outside the plugin's control.

= Why can a generated image or companion video be rejected? =

Each uploaded derivative follows the currently applicable policy. There is no
hidden exemption for a client-supplied generated-file flag. Include every
needed format family and test the actual workflow. A file that was allowed
earlier may fail finalize if the administrator tightened policy meanwhile.

= Can I turn the plugin off if it interferes with an importer? =

Yes. Disable its policies or deactivate it. This restores ordinary WordPress
upload behavior and leaves existing media unchanged.

== Source and contributions ==

Copyright (c) 2026 Coocadev. All included plugin features are free software under
GNU GPL version 2 or later (SPDX: GPL-2.0-or-later). You may study, use, modify and
redistribute the code under that license. Preserve copyright/license notices and
provide corresponding source when the GPL requires it for redistributed changes.
The full GPLv2 license text is included in LICENSE, with the later-version choice
stated in source headers. No licence key or payment unlocks included local features.

The shipped PHP, JavaScript and CSS are complete readable sources; no compilation,
minification or remote code download is required. The development source project
contains an English DEVELOPER.md with the architecture, hooks, data lifecycle,
test entry points and contribution guidance. Keep patches focused and include
regression coverage for changed behavior. Never include credentials, customer
data, private fixtures or review emails in public source or installable packages.

== Changelog ==
= 0.2.0 =
* Open-source release: English architecture comments and developer guides; same runtime behavior as 0.1.0.


= 0.1.0 =
* Initial development release with global/role policy intersections, native
  server checks, revision-safe settings, media guidance and local diagnostics.

== Upgrade Notice ==

= 0.1.0 =
Initial development release. Validate your site's media workflows before use.
