<?php
/**
 * Plugin Name: Coocadev DoneMark
 * Description: Team checkmarks that distinguish completed tasks from content reviews that need another look.
 * Version: 0.2.0
 * Requires at least: 6.9
 * Requires PHP: 8.1
 * Author: Coocadev
 * Author URI: https://cooca.dev/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: coocadev-donemark
 *
 * Copyright (c) 2026 Coocadev.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package CoocadevDoneMark
 */

namespace Coocadev\DoneMark;

defined( 'ABSPATH' ) || exit;
const VERSION = '0.2.0';
const FILE    = __FILE__;

require_once __DIR__ . '/src/class-settings.php';
require_once __DIR__ . '/src/class-states.php';
require_once __DIR__ . '/src/class-rest.php';
require_once __DIR__ . '/src/class-admin.php';
require_once __DIR__ . '/src/class-privacy.php';

register_activation_hook(
	__FILE__,
	static function ( $network_wide ) {
		if ( $network_wide ) {
			wp_die( esc_html__( 'Activate DoneMark separately on each site. Network activation is not supported.', 'coocadev-donemark' ) );
		}
		States::install();
		add_option( 'ccdm_settings', Settings::defaults(), '', false );
	}
);

add_action(
	'plugins_loaded',
	static function () {
		Rest::register();
		Admin::register();
		Privacy::register();
		add_action( 'deleted_post', array( States::class, 'delete_post' ) );
	}
);
