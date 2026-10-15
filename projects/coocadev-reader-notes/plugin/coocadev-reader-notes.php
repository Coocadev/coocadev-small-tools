<?php
/**
 * Plugin Name: Coocadev Reader Notes
 * Description: Private reader notes per article, with autosave, conflict protection and personal export.
 * Version: 0.2.0
 * Requires at least: 6.9
 * Requires PHP: 8.1
 * Author: Coocadev
 * Author URI: https://cooca.dev/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: coocadev-reader-notes
 *
 * Copyright (c) 2026 Coocadev.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package CoocadevReaderNotes
 */

namespace Coocadev\ReaderNotes;

defined( 'ABSPATH' ) || exit;

const VERSION = '0.2.0';
const FILE    = __FILE__;
require_once __DIR__ . '/src/class-database.php';
require_once __DIR__ . '/src/class-access.php';
require_once __DIR__ . '/src/class-rest.php';
require_once __DIR__ . '/src/class-plugin.php';
register_activation_hook( __FILE__, array( Database::class, 'activate' ) );
add_action( 'plugins_loaded', array( Plugin::class, 'boot' ) );
