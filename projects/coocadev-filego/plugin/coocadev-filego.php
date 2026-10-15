<?php
/**
 * Plugin Name: Coocadev FileGo
 * Description: Keep one public PDF link while publishing new document versions and rolling back safely.
 * Version: 0.2.0
 * Requires at least: 6.9
 * Requires PHP: 8.1
 * Author: Coocadev
 * Author URI: https://cooca.dev/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: coocadev-filego
 *
 * Copyright (c) 2026 Coocadev.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package CoocadevFileGo
 */

namespace Coocadev\FileGo;

defined( 'ABSPATH' ) || exit;

const VERSION = '0.2.0';
const FILE    = __FILE__;

require_once __DIR__ . '/src/class-store.php';
require_once __DIR__ . '/src/class-documents.php';
require_once __DIR__ . '/src/class-admin.php';
require_once __DIR__ . '/src/class-privacy.php';
require_once __DIR__ . '/src/class-plugin.php';

register_activation_hook( __FILE__, array( Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Plugin::class, 'deactivate' ) );
add_action( 'plugins_loaded', array( Plugin::class, 'boot' ) );
