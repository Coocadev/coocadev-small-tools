<?php
/**
 * Optional cleanup; physical files and media records are always preserved.
 *
 * Copyright (c) 2026 Coocadev.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package CoocadevFileGo
 */

namespace Coocadev\FileGo;

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Remove explicitly opted-in FileGo records while retaining all media files.
 */
function ccfg_uninstall_site(): void {
	global $wpdb;
	if ( ! get_option( 'ccfg_remove_data', false ) ) {
		return;
	}
	$versions = $wpdb->prefix . 'ccfg_versions';
	$exists   = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $versions ) ) );
	if ( $exists ) {
		$after = 0;
		do {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id,attachment_id FROM %i WHERE id > %d ORDER BY id LIMIT 100', $versions, $after ) );
			foreach ( $rows as $row ) {
				if ( get_post_meta( (int) $row->attachment_id, '_ccfg_owner', true ) === 'coocadev-filego:' . get_current_blog_id() && (int) get_post_meta( (int) $row->attachment_id, '_ccfg_version_id', true ) === (int) $row->id ) {
					delete_post_meta( (int) $row->attachment_id, '_ccfg_owner' );
					delete_post_meta( (int) $row->attachment_id, '_ccfg_version_id' );
				}
				$after = (int) $row->id;
			}
			$count = count( $rows );
		} while ( 100 === $count );
	}
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'ccfg_documents' ) );
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $versions ) );
	foreach ( array( 'ccfg_remove_data', 'ccfg_warning_mb', 'ccfg_schema_version' ) as $option ) {
		delete_option( $option );
	}
	foreach ( wp_roles()->role_objects as $role ) {
		$role->remove_cap( 'ccfg_manage_documents' );
	}
	// Only our short-lived diagnostics; no user content or unrelated options.
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s', $wpdb->options, $wpdb->esc_like( '_transient_ccfg_check_' ) . '%', $wpdb->esc_like( '_transient_timeout_ccfg_check_' ) . '%' ) );
}

if ( is_multisite() ) {
	$ccfg_offset = 0;
	do {
		$ccfg_sites = get_sites(
			array(
				'fields' => 'ids',
				'number' => 100,
				'offset' => $ccfg_offset,
			)
		);
		foreach ( $ccfg_sites as $ccfg_site_id ) {
			switch_to_blog( (int) $ccfg_site_id );
			ccfg_uninstall_site();
			restore_current_blog();
		}
		$ccfg_offset += 100;
		$ccfg_count   = count( $ccfg_sites );
	} while ( 100 === $ccfg_count );
} else {
	ccfg_uninstall_site();
}
