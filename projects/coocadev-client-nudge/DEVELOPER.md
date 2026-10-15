# Developer guide

The preferred source is the unminified PHP in `plugin/`. No build step or vendor runtime is needed. Copy that directory to a WordPress plugin directory named `coocadev-client-nudge`.

- `coocadev-client-nudge.php`: namespace/bootstrap/version and lifecycle registration.
- `src/class-plugin.php`: private task CRUD, ownership checks, recipient consent, bounded reminder worker, privacy handlers and escaped views.
- `uninstall.php`: protected opt-in removal of this site's data.
- `tests/integration.php`: synthetic behavioral tests; `pre_wp_mail` intercepts every test message.

Namespace `Coocadev\ClientNudge`; prefix `cccn_`; text domain `coocadev-client-nudge`. Task type `cccn_task`; private posts carry `cccn_assignee`, `cccn_due`, `cccn_done`. Consent metadata includes current blog ID because user metadata is network-shared. Each non-autoloaded weekly option is added atomically before mail. This favors avoiding duplicates over retrying ambiguous transport crashes. At most 50 users/50 tasks per user are processed, and expired-claim cleanup is capped at 100 keys per invocation.

Mutation boundary: POST + capability + nonce, then explicit scalar input and domain validation. The `posted()` helper verifies the nonce too and rejects arrays/markup without silently coercing checkbox values. No caller-supplied user ID controls recipient consent. Task completion checks actual assignee; public REST/search do not expose the post type. Do not place the authenticated task board in a shared full-page cache.

Relevant hooks: `cccn_reminders`, `admin_post_cccn_action`, privacy exporter/eraser registries, `deleted_user`. The shortcode is `[cccn_tasks]`. No frontend JavaScript/CSS or external HTTP API exists. Single-site/per-site activation only; no network-wide lifecycle guarantee.

Run `wp eval-file /absolute/path/to/tests/integration.php` on an isolated database. Then PHP 8.1 lint, `phpcs --standard=WordPress plugin/`, and `wp plugin check coocadev-client-nudge --format=json`. Preserve actual logs with the release. Test minimum/current WordPress and final ZIP. Never force-install over a source junction or put test mail/users/logs/secrets in a public package.

Contribute focused patches with reproducers and relevant regression tests under GPL-2.0-or-later. Keep English comments explaining trust boundaries, failure semantics and unusual choices. Build from an explicit runtime allowlist, record SHA256, and repeat exact-ZIP install smoke before a separately authorized publication.
