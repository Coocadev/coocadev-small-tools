<?php
/**
 * Plugin Name: Coocadev Client Nudge
 * Description: Private client material tasks and explicitly opted-in weekly reminders.
 * Version: 0.2.0
 * Requires at least: 6.9
 * Requires PHP: 8.1
 * Author: Coocadev
 * Author URI: https://cooca.dev/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: coocadev-client-nudge
 *
 * @package CoocadevClientNudge
 */

namespace Coocadev\ClientNudge;

defined( 'ABSPATH' ) || exit;
const VERSION = '0.2.0';
require_once __DIR__ . '/src/class-plugin.php';
register_activation_hook( __FILE__, array( Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Plugin::class, 'deactivate' ) );
add_action( 'init', array( Plugin::class, 'boot' ) );
