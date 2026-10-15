=== Coocadev FileGo ===
Contributors: coocadev
Tags: pdf, documents, versions, downloads
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.2.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Keep one public PDF link while publishing new versions and rolling back safely.

== Description ==

FileGo gives public PDFs a permanent link. Upload a new menu, price list or guide, review it, then publish it. Every version has a different physical filename; the permanent link redirects to the current version with no-store headers.

* Native Media > FileGo screen, searchable documents and version history.
* Staged uploads, explicit publication, rollback and conflict detection.
* Public GET/HEAD links with temporary 302 redirects.
* Protected media deletion with explicit confirmation in FileGo.
* No external services, analytics, accounts or paid feature locks.

This is a local 0.2.0 candidate. Tested compatibility is recorded separately until release checks are complete.

== Installation ==

1. Install the plugin directory and activate FileGo for this site.
2. Open Media > FileGo and create a document with a unique permanent-link slug.
3. Upload a PDF, then choose Publish / roll back to this version.
4. Copy the permanent link and use it in your pages or QR codes.

PHP Fileinfo and InnoDB are required. Maximum PDF size is the smaller of 20 MiB and your WordPress/PHP upload limit. FileGo does not raise server limits. Only administrators receive its management capability on activation; a role-management tool can deliberately grant ccfg_manage_documents to trusted users. Uploads also require upload_files and deletion checks the specific attachment capability.

== Frequently Asked Questions ==

= Are these private documents? =

No. Every uploaded PDF and version URL is public, including staged and retired versions. Unpublishing stops only the permanent link. It cannot revoke downloaded copies or old direct links. Upload trusted public documents only; FileGo is not antivirus, DRM or protected storage.

= How does caching work? =

Publish the permanent link, not the physical attachment URL. FileGo returns a temporary 302 redirect with no-store. Each version has its own filename and can be cached independently. Exclude /filego/ and the ccfg_download query from any proxy or page cache that serves responses before WordPress. The administrator diagnostic checks the current same-site response, not every CDN node. Configure immutable caching of version files in your hosting panel if wanted; FileGo does not edit server configuration.

= Does it work with plain permalinks? =

Yes. The fallback is ?ccfg_download=your-slug. Pretty permalinks use /filego/your-slug/. WordPress in a subdirectory is supported by WordPress URL/path APIs. A pre-existing /filego/ page route should be renamed before enabling pretty aliases.

= Can I use cloud media/offload or replace files outside FileGo? =

This release requires the original file in this site's local uploads directory and a same-host attachment URL. Offload is not supported. Do not edit or replace version files via FTP or another plugin. Publication checks the recorded SHA-256; a mismatch is rejected. Public downloads check local ownership and size without hashing the whole file on each request.

= What happens when deleting a version? =

Only non-current versions can be removed. The confirmation warns that their direct links stop working, even if used elsewhere. Interrupted deletion stays delete_pending and is never publishable. Retry it in FileGo. A storage failure after WordPress removed the attachment record may need administrator repair; preserve a site backup before deleting valuable files.

= What data is stored? =

Document title and slug, version notes, file hash and size, uploading user ID and timestamps. WordPress media records also belong to WordPress's normal media lifecycle. FileGo's privacy exporter exports authorship; erasure clears FileGo author IDs and version notes without deleting public documents or media records. There are no visitor tracking records or analytics cookies. The optional diagnostic makes a HEAD request to this site only.

= What happens on deactivation or uninstall? =

Deactivation stops the permanent links and preserves all documents and PDFs. Uninstall preserves FileGo records by default. Enable Remove FileGo records on uninstall first to remove its tables, settings, capabilities and attachment markers; physical PDFs and media records are still kept. Reinstall/activate before choosing this option if data was previously retained. Network-wide activation is not supported; activate separately per site.

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
* Initial local candidate: public PDF versions, permanent links, rollback, safe deletion and privacy tools.

== Upgrade Notice ==

= 0.1.0 =
Initial local candidate. Back up document tables and media before updates. No production migration is performed by this package.
