<?php
/**
 * Plugin Name: Coocadev UploadGo
 * Description: Predictable upload limits, combined across a user's roles, with clear editor feedback.
 * Version: 0.2.0
 * Requires at least: 6.9
 * Requires PHP: 8.1
 * Author: Coocadev
 * Author URI: https://cooca.dev/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: coocadev-uploadgo
 *
 * Copyright (c) 2026 Coocadev.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package CoocadevUploadGo
 */

namespace Coocadev\UploadGo;

defined( 'ABSPATH' ) || exit;

const VERSION = '0.2.0';
const FILE    = __FILE__;

require_once __DIR__ . '/src/class-policyrepository.php';
require_once __DIR__ . '/src/class-policyresolver.php';
require_once __DIR__ . '/src/class-fileinspector.php';
require_once __DIR__ . '/src/class-uploadhooks.php';
require_once __DIR__ . '/src/class-adminpage.php';

register_activation_hook(
	__FILE__,
	static function ( $network_wide ) {
		if ( $network_wide ) {
			wp_die( esc_html__( 'Activate UploadGo separately on each site. Network activation is not supported.', 'coocadev-uploadgo' ) );
		}
		add_option( 'ccug_settings', PolicyRepository::defaults(), '', false );
	}
);

register_deactivation_hook(
	__FILE__,
	static function () {
		wp_clear_scheduled_hook( 'ccug_prune_diagnostics' );
		delete_option( 'ccug_diagnostics' );
	}
);

add_action(
	'plugins_loaded',
	static function () {
		UploadHooks::register();
		AdminPage::register();
	}
);
