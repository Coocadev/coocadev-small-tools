<?php
/**
 * Plugin Name: Coocadev MailSkin
 * Description: Opt-in WordPress email branding with three HTML skins and plain-text-safe signatures.
 * Version: 0.2.0
 * Requires at least: 6.9
 * Requires PHP: 8.1
 * Author: Coocadev
 * Author URI: https://cooca.dev/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: coocadev-mailskin
 *
 * @package CoocadevMailSkin
 */

namespace Coocadev\MailSkin;

defined( 'ABSPATH' ) || exit;
const VERSION = '0.2.0';
require_once __DIR__ . '/src/class-plugin.php';
add_action( 'init', array( Plugin::class, 'boot' ) );
