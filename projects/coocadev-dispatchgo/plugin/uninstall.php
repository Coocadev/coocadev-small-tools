<?php
/**
 * Preserve historical order receipts; optionally remove configuration.
 *
 * Copyright (c) 2026 Coocadev.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package CoocadevDispatchGo
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
$coocadev_dispatchgo_settings = get_option( 'ccdg_settings', array() );
if ( is_array( $coocadev_dispatchgo_settings ) && ! empty( $coocadev_dispatchgo_settings['uninstall_remove_settings'] ) ) {
	delete_option( 'ccdg_settings' );
}
// ccdg_snapshots and WC order metadata intentionally remain as historical receipts.
