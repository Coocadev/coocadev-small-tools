# DoneMark dependencies

Runtime: no bundled third-party PHP/JS libraries. Native WordPress APIs and WordPress-provided `jquery`, `inline-edit-post`, `wp-i18n` handles are used only on supported administration screens. Local CSS/JS is human-readable; no minifier/build step is required. Runtime license is GPLv2 or later, with the GPLv2 text in plugin/LICENSE.

Development-only tools outside plugin/: PHP CLI, WP-CLI, MariaDB, Plugin Check, WPCS/PHPCompatibilityWP, Node/Playwright and the installed Chrome executable in an isolated headless profile. Tests use synthetic site-local users, posts and secrets. None of these tools, QA accounts, reports or secret files belongs in the runtime archive.
