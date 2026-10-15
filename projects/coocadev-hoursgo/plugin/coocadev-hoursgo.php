<?php
/**
 * Plugin Name: Coocadev HoursGo
 * Description: Weekly opening hours with temporary, automatically expiring exceptions.
 * Version: 0.2.0
 * Requires at least: 6.9
 * Requires PHP: 8.1
 * Author: Coocadev
 * Author URI: https://cooca.dev/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: coocadev-hoursgo
 *
 * @package CoocadevHoursGo
 */

namespace Coocadev\HoursGo;

defined( 'ABSPATH' ) || exit;
const VERSION = '0.2.0';
require_once __DIR__ . '/src/class-plugin.php';
add_action( 'init', array( Plugin::class, 'boot' ) );
