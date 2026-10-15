# Runtime dependencies and licensing

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
