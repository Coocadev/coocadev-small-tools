<?php
/**
 * Plugin Name: Coocadev DispatchGo for WooCommerce
 * Description: Show a dispatch pause at checkout and keep the original notice with each order.
 * Version: 0.2.0
 * Requires at least: 6.9
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * WC requires at least: 10.2.2
 * WC tested up to: 11.2
 * Author: Coocadev
 * Author URI: https://cooca.dev/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: coocadev-dispatchgo
 *
 * Copyright (c) 2026 Coocadev.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package CoocadevDispatchGo
 */

namespace Coocadev\DispatchGo;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/src/class-schedule.php';
require_once __DIR__ . '/src/class-repository.php';
require_once __DIR__ . '/src/class-plugin.php';

register_activation_hook( __FILE__, array( Repository::class, 'install' ) );
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);
add_action(
	'plugins_loaded',
	static function () {
		if ( ! defined( 'WC_VERSION' ) || version_compare( WC_VERSION, '10.2.2', '<' ) ) {
			add_action(
				'admin_notices',
				static function () {
					if ( current_user_can( 'activate_plugins' ) ) {
						echo '<div class="notice notice-warning"><p>' . esc_html__( 'DispatchGo needs WooCommerce 10.2.2 or later. Its checkout features are currently inactive.', 'coocadev-dispatchgo' ) . '</p></div>';
					}
				}
			);
			return;
		}
		Plugin::boot( __FILE__ );
	},
	20
);
