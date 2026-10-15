<?php
/**
 * Plugin Name: Coocadev ReleaseGo
 * Description: Publish a small product changelog with native WordPress editing, version badges and a release shortcode.
 * Version: 0.2.0
 * Requires at least: 6.9
 * Requires PHP: 8.1
 * Author: Coocadev
 * Author URI: https://cooca.dev/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: coocadev-releasego
 *
 * Copyright (c) 2026 Coocadev.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package CoocadevReleaseGo
 */

namespace Coocadev\ReleaseGo;

defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/src/class-plugin.php';
register_activation_hook( __FILE__, array( Plugin::class, 'activate' ) );
add_action( 'plugins_loaded', array( Plugin::class, 'boot' ) );
