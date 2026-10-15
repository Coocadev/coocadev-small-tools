"""Prepare readable English docs and PHP parameter descriptions for two local tools."""
from pathlib import Path
import re
import shutil

BASE = Path(__file__).resolve().parents[3]
SLUGS = ['coocadev-passport', 'coocadev-releasego']
PARAMS = {
    'network_wide': 'Whether network-wide activation was requested.',
    'basename': 'Exact installed plugin basename, never a filesystem path.',
    'code': 'Stable plugin-specific error suffix.',
    'message': 'Translated public-safe error text.',
    'status': 'Intended HTTP response status.',
    'input': 'Untrusted card fields to validate and sanitize.',
    'revision': 'Expected site data revision from the form.',
    'remove': 'Explicit uninstall-removal preference.',
    'next': 'Complete next state derived from the current revision.',
    'links': 'Existing native plugin action links.',
    'file': 'Installed plugin basename or an empty overview selection.',
    'nonce': 'Action-specific WordPress nonce identifier.',
    'value': 'Untrusted candidate value; no coercion is allowed.',
    'result': 'Confirmed stored state or a public-safe error.',
    'version': 'Exact candidate product version label.',
    'kinds': 'Candidate list of distinct release-category enums.',
    'post': 'Current persisted WordPress release post.',
    'id': 'Current site release post identifier.',
    'location': 'Core post-save redirect URL.',
    'attributes': 'Shortcode attributes before strict bounds validation.',
}

for slug in SLUGS:
    root = BASE / 'projects' / slug
    (root / 'reports/0.2.0').mkdir(parents=True, exist_ok=True)
    shutil.copyfile(BASE / 'projects/coocadev-filego/plugin/LICENSE', root / 'plugin/LICENSE')
    for path in (root / 'plugin').rglob('*.php'):
        text = path.read_text(encoding='utf-8')
        pattern = re.compile(r'(?P<indent>\t*)/\*\* (?P<summary>[^\n]+?) \*/\n(?P<signature>\t*(?:public|private) static function [a-z_]+\( (?P<params>[^\n]*?) \)(?P<returns>[^\n]*) \{)')
        def docs(match):
            params = re.findall(r'([a-zA-Z_\\|]+) \$(\w+)', match.group('params'))
            if not params:
                return match.group(0)
            indent = match.group('indent')
            lines = [indent + '/**', indent + ' * ' + match.group('summary'), indent + ' *']
            for kind, name in params:
                lines.append(indent + ' * @param ' + kind + ' $' + name + ' ' + PARAMS.get(name, 'Typed operation input.'))
            lines += [indent + ' */', match.group('signature')]
            return '\n'.join(lines)
        path.write_text(pattern.sub(docs, text), encoding='utf-8')

COMMON_SOURCE = '''== Source and contributions ==

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

'''

PASSPORT_README = '''=== Coocadev Passport ===
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

'''

RELEASE_README = '''=== Coocadev ReleaseGo ===
Contributors: coocadev
Tags: changelog, releases, products, version history
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.2.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Publish a lightweight product changelog with native WordPress release editing, version badges and a bounded shortcode.

== Description ==

Use the native Releases editor for title, formatted notes, author and revisions.
Add a version such as 1.2.3, select New / Fixed / Improved categories and assign a
Product. Put [coocadev_releases product="my-product" limit="10"] on an existing
page. The newest published release notes appear first. Omitting product shows
all products. Limit accepts 1 through 50; invalid attributes return no output.

Only published, non-password-protected entries with valid release metadata are
rendered. Draft, private and scheduled entries stay out even for logged-in admins.
There are no public single-release URLs, extra REST endpoints, frontend scripts,
remote services, license checks or analytics. Semantic HTML follows your theme.

The body allows normal WordPress post HTML after KSES filtering. Shortcodes in
release notes remain literal text and are not executed. PHP is never evaluated.
ReleaseGo is a changelog publisher, not a plugin updater or code deployment tool.

== Installation ==

1. Install and activate ReleaseGo on the intended site.
2. Add a Product under Releases > Products.
3. Add a Release, its version and one or more categories, then publish it.
4. Add [coocadev_releases product="my-product" limit="10"] to an existing page.
5. Settings > ReleaseGo explains the shortcode and controls uninstall retention.

WordPress post capabilities govern release editing/publishing; product term
management uses manage_categories. Metadata saves also require the specific post
edit capability and the release-details nonce. Network activation is unsupported.

== Frequently Asked Questions ==

= Why does a published entry not appear? =
Give it valid version details and a selected category, remove any post password,
and check its Product slug matches the shortcode. Native Quick Edit or an importer
without ReleaseGo details may publish the post while leaving the required metadata
empty; such entries remain hidden from the shortcode.

= What happens if release details are invalid? =
The existing version/categories remain unchanged. WordPress may still save the
title and body through its normal editor flow; the admin notice explains this.
Quick Edit/autosave without the details nonce preserves existing metadata.

= Is the list cached? =
ReleaseGo uses a bounded native WordPress query and adds no private-data cache.
Published notes are intentionally public. Your host/page cache may need a purge
after publishing or updating the page. Drafts are excluded at query and render time.

= What data is kept? =
ReleaseGo stores native WordPress posts, author IDs, dates/revisions, product terms,
version/categories and one uninstall preference. Core content/privacy procedures
apply. Avoid confidential or personal data in public release notes. No information
is sent to Coocadev.

= What happens on deletion? =
Deactivation and default uninstall preserve release history. Explicit removal in
Settings > ReleaseGo deletes only its release posts, associated metadata/revisions,
product taxonomy terms and settings. It does not delete normal posts or users.
Each site's removal preference is respected; full multisite QA remains a separate
release gate. Back up history before opting into deletion.

'''

for slug, readme in [(SLUGS[0], PASSPORT_README), (SLUGS[1], RELEASE_README)]:
    root = BASE / 'projects' / slug
    readme += COMMON_SOURCE + '== Changelog ==\n\n= 0.2.0 =\n* Initial local open-source candidate.\n'
    (root / 'plugin/readme.txt').write_text(readme, encoding='utf-8')
    (root / 'DEPENDENCIES.md').write_text('''# Runtime dependencies and licensing

Copyright (c) 2026 Coocadev. SPDX-License-Identifier: GPL-2.0-or-later.

* WordPress 6.9 or later; PHP 8.1 or later.
* Standard WordPress database/API support. No Composer/npm runtime dependencies.
* Complete readable PHP source, no compiled asset or mandatory shared plugin.
* No remote API, telemetry, external assets, updater or Coocadev account.
* Coocadev-authored runtime/docs: GNU GPL version 2 or later. Full GPLv2 text in
  plugin/LICENSE, later-version notice in source/readme. No third-party code copied
  into the runtime. WordPress supplies its own native functions and editor UI.

Development tests use an isolated WordPress and PHP CLI. The maintainer QA uses
Plugin Check, WPCS and synthetic HTTP fixtures; these tools are not shipped runtime.
Public source packages must exclude local fixture credentials, database dumps,
private evidence and customer data.
''', encoding='utf-8')

PASSPORT_PLAN = '''# Passport implementation plan

Status: local source candidate 0.2.0. Execution evidence belongs in reports/0.2.0;
this plan does not itself mark a test passed or imply directory approval.

The problem is a site handover where no one knows why each plugin exists, who
owns its operational use or what to test before updating it. The MVP adds a
private card to native plugin rows rather than managing plugin code or credentials.

## Flow and data

1. Enumerate exact installed basenames through get_plugins(), without including
   target source. Require manage_options and activate_plugins for all actions.
2. Record four bounded plain-text fields: purpose, usage, responsible, checklist.
   Keep all data in one versioned non-autoload site option with a revision.
3. Compare exact prior serialized option bytes before writing and invalidate cache.
4. Retain removed-plugin notes with explicit read-only status. Export includes
   installation state and card text, only through an authorized nonce-protected POST.
5. Preserve data by default; explicit per-site uninstall preference removes only
   the owned option. Never activate/delete/update other plugins or touch their files.

## Review crosswalk

R03/G01: complete free GPL-2.0-or-later readable source, no feature gate. R04/G02:
no assets, telemetry or external runtime. R05-R08/L01: method, both capabilities,
nonce, exact basename, strict text/revision/checkbox validation and late escaping.
R11-R15: one own option, trusted table identifier and prepared exact-value CAS;
no executable file writes or target include, no guessed Ajax path. R16-R18/L06:
exact build/version/source checks and clean ZIP install belong to release QA.
R01-R02 require final name/account/product-page checks before directory submission.
Personal free text requires manual privacy review; do not promise encryption.

## Verification gates

* Installed/unknown/traversal basename, scalar/array/UTF-8/size limits.
* Both capabilities independently, CSRF/method denial, JSON export/no-store.
* Two-tab revision conflict; complete prior state unchanged on malformed request.
* Removed-card retention, escaped output and no frontend output/assets.
* Actual default and opted-in Core uninstall in separate processes; foreign data safe.
* PHP8.1 syntax, WPCS/Plugin Check and fresh WP_DEBUG observations.
* Final ZIP/hash/install and all-product compatibility by the parent release task.
* Remaining manual Safari/Firefox/RTL/screen-reader, persistent object cache,
  multicore concurrency, multisite lifecycle and performance at 1,000 cards.
'''

RELEASE_PLAN = '''# ReleaseGo implementation plan

Status: local source candidate 0.2.0. Tests are recorded in reports/0.2.0, not
inferred from this plan. Directory presentation/naming/publication remain separate.

The MVP is a public version-history list backed by native WordPress editing. It
does not deploy code, update plugins or duplicate a commerce/licensing service.

## Architecture and flow

1. Register a private-admin release CPT and product taxonomy with standard post
   capabilities and explicit term-management capabilities; no public single URLs.
2. Native editor saves title/body/revisions. A per-post nonce and edit_post check
   protect strict version and New/Fixed/Improved metadata. Invalid details preserve
   prior metadata; a truthful notice does not claim Core content was rolled back.
3. Shortcode accepts a bounded product slug and 1-50 results. Query published
   posts only, then repeat type/status/password/version checks at render time.
4. Escape headings/date/badges, KSES-filter body, and never invoke note shortcodes,
   eval, remote code, telemetry or unconditional frontend resources.
5. Retain editorial history by default. Explicit per-site uninstall removes only
   release posts/meta/revisions, product terms and one own option via Core APIs.

## Review crosswalk

R03/G01: full GPL-2.0-or-later source and free local features. R04/R09/G02:
no external frontend assets, analytics or service. R05-R08/L01: native Core plus
specific nonce/object capabilities; strict version/category/retention validation;
late escaping/KSES and no raw execution. R11-R15: native WordPress storage/paths,
prefixed CPT/taxonomy/meta/shortcode, no custom SQL or executable file writing.
R16-R18/L06: exact environment/ZIP/hash verification remains release-scoped.
R01-R02: final unique name/account and real product page must be checked before
submission. Native content privacy applies; no encryption/compliance guarantee.

## Verification gates

* Author own/other release metadata, nonce/method denial and unchanged prior data.
* Enum/type/array/version bounds and valid normal editor save.
* Product filtering and deterministic date/ID ordering, result limit and malformed
  shortcode inputs. Draft/private/future/password/invalid-metadata exclusion.
* HTML/XSS escaping, body shortcode nonexecution and no frontend asset pollution.
* Default/opt-in Core uninstall in separate processes preserves unrelated posts/users.
* PHP8.1 syntax, WPCS, Plugin Check, fresh WP_DEBUG and actual native admin requests.
* Final exact ZIP/hash/install and compatibility with all tools by parent release QA.
* Manual browsers/RTL/screen-reader, cache invalidation, large history performance,
  full multisite lifecycle, custom-role/CPT and revision-editor workflows remain gates.
'''

PASSPORT_DEV = '''# Passport developer guide

Copyright (c) 2026 Coocadev. SPDX-License-Identifier: GPL-2.0-or-later.
The runtime and this guide may be used, studied, modified and redistributed under
GNU GPL version 2 or later. Preserve notices and provide corresponding source as
the license requires. The included GPLv2 text and later-version notice apply;
there is no license key or mandatory shared runtime.

## Code map and flow

`plugin/coocadev-passport.php` registers activation and loads
`src/class-plugin.php`. Plugin owns native links/menu, data validation, option CAS,
POST handlers and private JSON export. `uninstall.php` implements explicit per-site
removal. There are no JavaScript/CSS build artifacts or frontend hooks.

The `plugin_action_links` filter adds Passport links after checking both
manage_options and activate_plugins. Admin page and POST actions repeat those
checks; seeing a link is not authorization. Actions are ccpp_save, ccpp_settings
and ccpp_export. Method/capabilities/nonce precede payload processing. Never expose
state() as a public API: this repository read intentionally has no request context.

## Data, consistency and lifecycle

One non-autoload option ccpp_data contains schema, revision, remove_data and cards
keyed by exact installed basename. Every card has purpose/usage/responsible/checklist
plain text with documented byte bounds. No author ID/email/IP is copied. Basenames
come from get_plugins(); do not include a user-supplied file path or trust a label.

CAS matches the exact old serialized value and expected revision, then deletes the
options cache. Concurrent cards share the revision: conflict is explicit, not a
silent merge. Unknown/corrupt stored data stops writes rather than dropping notes.
Removed-plugin cards are retained/read-only until that exact basename is installed.

Deactivation/default uninstall preserve data. Explicit removal drops only the own
option; network activation is rejected. The uninstall iterator respects each site's
preference. Free-text privacy requests require manual review because names cannot
be safely mapped to a WordPress account. JSON exports include that private text.

## Readable source and an example

No build process is needed. Install the plugin/ contents under
coocadev-passport. From trusted code after checking Plugin::allowed():

```php
$cards = \\Coocadev\\PluginPassport\\Plugin::export();
if ( ! is_wp_error( $cards ) ) {
    // Keep this administrative export private; it can contain staff names.
}
```

These classes are internal implementation details, not a versioned external SDK.
Do not add a public route around this example. Use exact scalar validation and
contextual escaping instead of broad sanitizer coercion for future fields.

## Tests and contributions

tests/integration.php runs real WordPress domain/permission/HTTP assertions with
temporary synthetic users and restores state. Lifecycle workers use separate Core
uninstall processes. Read fixture assumptions and run only on disposable sites.
PHP syntax: php -l for all runtime files. WPCS/Plugin Check evidence and review
exceptions live in reports/0.2.0. Full ZIP/hash verification is a separate gate.
No earlier product's PASS is inherited by this plugin.

Contribute small patches with English explanations of non-obvious permission,
validation and concurrency choices plus behavioral tests. Public source packages
must exclude credentials, fixture cookies, databases, private email and customer
notes. Test multisite/cache/browser behavior before expanding compatibility claims.
'''

RELEASE_DEV = '''# ReleaseGo developer guide

Copyright (c) 2026 Coocadev. SPDX-License-Identifier: GPL-2.0-or-later.
Study, use, modification and redistribution follow GNU GPL version 2 or later.
Preserve notices and provide corresponding source for distributed derivatives as
the license requires. Full GPLv2 text and a later-version notice are included.

## Code map and hooks

The main PHP loads src/class-plugin.php and registers activation. Plugin registers
CPT ccrg_release and taxonomy ccrg_product on init, adds the release-details box,
handles save_post_ccrg_release, truthful editor redirect notices, retention admin
POST and the coocadev_releases shortcode. There is no public single-release route,
custom REST endpoint, asset build or frontend JavaScript/CSS dependency.

Native Core post permissions apply. Metadata repeats exact edit_post permission,
POST method and ccrg_meta_ID nonce checks. Autosave/revision/Quick Edit without the
details nonce preserves metadata. Terms use manage_categories for management and
edit_posts for assignment. Retention requires manage_options plus its nonce.

## Data and public rendering

Native posts/revisions retain author/date/title/body. Private metadata keys
_ccrg_version and _ccrg_kinds store a bounded version label and a distinct list of
new/fixed/improved. ccrg_remove_data is the explicit uninstall choice. Invalid
metadata preserves the pair; Core may still save body/title, which the notice says.
The plugin does not promise a combined transaction with the native editor save.

WP_Query is bounded to 1-50 published releases with optional indexed product term
filter and date/ID ordering. The renderer repeats type/status/password/metadata
checks after the query: even a modified query cannot leak a draft. Public titles,
badges and dates are escaped; body uses wpautop and wp_kses_post. It intentionally
does not run the_content filters or do_shortcode on note bodies, so notes cannot
invoke arbitrary shortcode behavior. PHP is never evaluated.

## Usage and source installation

```text
[coocadev_releases product="my-product" limit="10"]
```

Omit product for a combined list. Use exact lowercase ASCII slug and a decimal
limit from 1 through 50. Invalid attributes produce empty output. Only published,
unprotected entries with valid version/category details appear, even for admins.
Use semantic .ccrg-releases/.ccrg-release/.ccrg-badge hooks in your theme styles.

The plugin/ directory is complete readable runtime, installed under
coocadev-releasego. No compilation/minification, remote assets or shared plugin is
required. Native class methods are internal implementation details, not a stable
public API. Don't bypass object permissions by wrapping them in a public route.

## Lifecycle, privacy and verification

Per-site activation is supported; network activation is rejected. Deactivation
and default uninstall retain release history. Explicit opt-in uses native deletion
APIs for only this CPT and taxonomy, associated metadata/revisions and its option.
Each site has its own preference. A deletion failure stops cleanup instead of
looping or claiming success. Core editorial/privacy procedures handle authors and
content; published release notes are intentionally public, not encrypted storage.

tests/integration.php tests actual WordPress metadata/permissions/shortcode and
native HTTP boundaries with disposable synthetic fixtures. Lifecycle workers call
actual Core uninstall in separate processes. Run on isolated sites, never live
stores. PHP syntax: php -l. reports/0.2.0 records observed WPCS/Plugin Check,
WP_DEBUG and runtime results; exact final ZIP/hash/install remains release-scoped.

Contribute focused English-commented patches and regressions for changed rendering
or authorization. Keep credentials, cookie files, real content/database dumps and
private email out of public source and installable releases. Expanded multisite,
cache, performance, browser and accessibility claims require their own evidence.
'''

for slug, plan, developer in [(SLUGS[0], PASSPORT_PLAN, PASSPORT_DEV), (SLUGS[1], RELEASE_PLAN, RELEASE_DEV)]:
    root = BASE / 'projects' / slug
    (root / 'PLAN.md').write_text(plan, encoding='utf-8')
    (root / 'DEVELOPER.md').write_text(developer.replace('\\\\', '\\'), encoding='utf-8')
    (root / 'README.md').write_text('# ' + ('Passport' if slug == SLUGS[0] else 'ReleaseGo') + '\n\nLocal open-source candidate 0.2.0. Runtime: plugin/.\nRead DEVELOPER.md, PLAN.md, DEPENDENCIES.md and plugin/readme.txt.\nExecuted checks are release-scoped in reports/0.2.0; no publication is implied.\n', encoding='utf-8')
    for name in ['phpcs-initial.json', 'phpcs-postformat.json']:
        old = root / name
        if old.exists():
            old.rename(root / 'reports/0.2.0' / name)
print('Prepared docs and English parameter descriptions for both plugins.')
