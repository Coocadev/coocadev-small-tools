<?php
/**
 * Explicit opt-in cleanup.
 *
 * Copyright (c) 2026 Coocadev.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package CoocadevDoneMark
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
/**
 * Remove only this site’s expressly opted-in DoneMark data.
 */
function ccdm_uninstall_site(): void {
	global $wpdb;
	$ccdm_settings = get_option( 'ccdm_settings', array() );
	if ( ! is_array( $ccdm_settings ) || empty( $ccdm_settings['remove_data_on_uninstall'] ) ) {
		return;
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Delete only this site's owned table after opt-in.
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'ccdm_states' ) );
	delete_option( 'ccdm_settings' );
	delete_option( 'ccdm_schema' );
}
if ( is_multisite() ) {
	$ccdm_offset = 0;
	do {
		$ccdm_sites = get_sites(
			array(
				'fields'  => 'ids',
				'number'  => 100,
				'offset'  => $ccdm_offset,
				'orderby' => 'id',
				'order'   => 'ASC',
			)
		);
		foreach ( $ccdm_sites as $ccdm_site ) {
			switch_to_blog( (int) $ccdm_site );
			ccdm_uninstall_site();
			restore_current_blog();
		}
		$ccdm_offset += 100;
		$ccdm_more    = count( $ccdm_sites ) === 100;
	} while ( $ccdm_more );
} else {
	ccdm_uninstall_site();
}
