<?php
/**
 * Explicitly opted-in cleanup of this site's options only.
 *
 * Copyright (c) 2026 Coocadev.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package CoocadevUploadGo
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
wp_clear_scheduled_hook( 'ccug_prune_diagnostics' );

$ccug_settings = get_option( 'ccug_settings', array() );
if ( is_array( $ccug_settings ) && ! empty( $ccug_settings['remove_data_on_uninstall'] ) ) {
	delete_option( 'ccug_settings' );
	delete_option( 'ccug_diagnostics' );
}
