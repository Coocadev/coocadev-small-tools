<?php
/**
 * Plugin Name: Coocadev StockDigest
 * Description: A scheduled, bounded low-stock digest with merchant-controlled replenishment reminders for WooCommerce.
 * Version: 0.2.0
 * Requires at least: 6.9
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * WC requires at least: 10.2
 * WC tested up to: 11.2
 * Author: Coocadev
 * Author URI: https://cooca.dev/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: coocadev-stockdigest
 *
 * @package CoocadevStockDigest
 */

namespace Coocadev\StockDigest;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/src/class-plugin.php';
register_activation_hook( __FILE__, array( Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Plugin::class, 'deactivate' ) );
add_action( 'before_woocommerce_init', array( Plugin::class, 'compatibility' ) );
add_action( 'plugins_loaded', array( Plugin::class, 'boot' ) );
