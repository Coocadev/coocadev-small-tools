<?php
/**
 * Preserve by default; only remove this plugin's own per-site data when opted in.
 *
 * Copyright (c) 2026 Coocadev.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package CoocadevReaderNotes
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$ccrn_remove = static function (): void {
	global $wpdb;
	$ccrn_settings = get_option( 'ccrn_settings', array() );
	if ( empty( $ccrn_settings['delete_data'] ) ) {
		return;
	}
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'ccrn_notes' ) );
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'ccrn_user_state' ) );
	delete_option( 'ccrn_settings' );
	delete_option( 'ccrn_schema_version' );
};
if ( is_multisite() ) {
	$ccrn_offset = 0;
	do {
		$ccrn_sites = get_sites(
			array(
				'fields' => 'ids',
				'number' => 100,
				'offset' => $ccrn_offset,
			)
		);
		foreach ( $ccrn_sites as $ccrn_site ) {
			switch_to_blog( (int) $ccrn_site );
			$ccrn_remove();
			restore_current_blog();
		}
		$ccrn_offset += 100;
		$ccrn_count   = count( $ccrn_sites );
	} while ( 100 === $ccrn_count );
} else {
	$ccrn_remove();
}
