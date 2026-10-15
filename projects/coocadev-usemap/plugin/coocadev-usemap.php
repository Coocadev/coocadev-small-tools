<?php
/**
 * Plugin Name: Coocadev UseMap
 * Description: Locate stored uses of named blocks and shortcodes before changing a plugin.
 * Version: 0.2.0
 * Requires at least: 6.9
 * Requires PHP: 8.1
 * Author: Coocadev
 * Author URI: https://cooca.dev/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: coocadev-usemap
 *
 * @package CoocadevUseMap
 * @license GPL-2.0-or-later
 */

namespace Coocadev\UseMap;

defined( 'ABSPATH' ) || exit;
const VERSION = '0.2.0';
const FILE    = __FILE__;
require_once __DIR__ . '/src/class-scanner.php';
require_once __DIR__ . '/src/class-plugin.php';
Plugin::boot();
