<?php
/**
 * Plugin Name: Coocadev MediaMap
 * Description: Find selected image references in stored Core blocks, content and featured images.
 * Version: 0.2.0
 * Requires at least: 6.9
 * Requires PHP: 8.1
 * Author: Coocadev
 * Author URI: https://cooca.dev/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: coocadev-mediamap
 *
 * @package CoocadevMediaMap
 * @license GPL-2.0-or-later
 */

namespace Coocadev\MediaMap;

defined( 'ABSPATH' ) || exit;
const VERSION = '0.2.0';
const FILE    = __FILE__;
require_once __DIR__ . '/src/class-scanner.php';
require_once __DIR__ . '/src/class-plugin.php';
Plugin::boot();
