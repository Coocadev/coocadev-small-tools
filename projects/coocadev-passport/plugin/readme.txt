=== Coocadev Passport ===
Contributors: coocadev
Tags: plugins, documentation, maintenance, administration
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.2.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Private operational cards beside installed plugins: purpose, usage, responsible team and an update checklist.

== Description ==

Add a Passport link to native Plugins rows. Keep four plain-text notes for each
installed plugin and export them as JSON when handing the site to another trusted
administrator. Settings > Passport lists installed plugins and retained
cards for removed plugins. Removed-plugin cards are read-only until the same exact
basename is installed again. An update does not remove its card.

Both manage_options and activate_plugins are required to read, edit or export.
Concurrent saves use a site revision check; reload after a conflict. Retention
changes also use that revision. This tool does not activate, deactivate, update,
read source files or change settings of another plugin.

Cards are operational notes, not a secure vault. Do not put passwords, API keys or
customer data in them. Administrators, database operators and backups can access
the text. No frontend assets, public API, telemetry or external service is added.
There is a technical limit of 1,000 retained cards per site and bounded text fields
(purpose 1,000 bytes, usage/checklist 4,000 bytes each, responsible 200 bytes).

== Installation ==

1. Install and activate on the intended individual site.
2. Open Plugins and select Passport beside an installed plugin.
3. Explain its purpose, use, responsible team and update checklist, then save.
4. Use Settings > Passport for the overview and private JSON export.

Network activation is unsupported. Per-site installation keeps each site's notes
separate; a complete multisite lifecycle matrix is not claimed in this candidate.

== Frequently Asked Questions ==

= Will uninstalling another plugin delete its card? =
No. Notes remain clearly marked as retained for a removed plugin. They are not
treated as instructions to include or execute a removed plugin file.

= Can editors or visitors read cards? =
Only users with both administrative capabilities above can use the interface or
export. There is no public route. This is interface access control, not encryption.

= What happens to personal information in notes? =
Responsible-person names or other free text may contain personal information you
enter. Review/export/remove that text manually when handling a privacy request;
the plugin cannot infer an account owner from free text. No account email, IP or
telemetry is copied automatically. JSON exports contain the entered card text.

= What happens on deactivation or deletion? =
Deactivation and default uninstall retain cards. Explicitly enable removal in
Settings > Passport to delete only its own cards/settings on uninstall.
Other plugins, files and settings remain. Back up important notes before enabling
that destructive preference. Reinstall to manage retained data.

= What if the stored schema is damaged? =
Saving/export stops with an explanation rather than replacing the stored value
with an empty default. Restore a known valid site backup before relying on cards.

== Source and contributions ==

Copyright (c) 2026 Coocadev. SPDX-License-Identifier: GPL-2.0-or-later.
Use, study, modification and redistribution are permitted under GNU GPL version 2
or any later version. Preserve notices and provide corresponding source as the
license requires for redistributed derivatives. The full GPLv2 text is in LICENSE;
the later-version notice grants the later-version choice. All shipped features
are available without a license key, subscription or Coocadev account.

The shipped PHP is complete readable source; no build step, minification, remote
code or shared Coocadev runtime is required. The source project includes English
DEVELOPER.md, PLAN.md, DEPENDENCIES.md and local tests. Contributions should use
focused patches, explain the behavior in English and include relevant regression
checks. Keep credentials, private fixtures, customer data and emails out of public
code and release ZIPs.

== Changelog ==

= 0.2.0 =
* Initial local open-source candidate.
