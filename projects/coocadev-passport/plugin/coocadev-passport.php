<?php
/**
 * Plugin Name: Coocadev Passport
 * Description: Keep private operational notes, an owner and an update checklist beside each installed plugin.
 * Version: 0.2.0
 * Requires at least: 6.9
 * Requires PHP: 8.1
 * Author: Coocadev
 * Author URI: https://cooca.dev/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: coocadev-passport
 *
 * Copyright (c) 2026 Coocadev.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package CoocadevPluginPassport
 */

namespace Coocadev\PluginPassport;

defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/src/class-plugin.php';
register_activation_hook( __FILE__, array( Plugin::class, 'activate' ) );
add_action( 'plugins_loaded', array( Plugin::class, 'boot' ) );
