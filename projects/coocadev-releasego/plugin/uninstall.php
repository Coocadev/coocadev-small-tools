<?php
/**
 * Explicit cleanup of owned release posts, terms and one option only.
 *
 * Default uninstall retains editorial history. WordPress deletion APIs preserve
 * other plugins' lifecycle hooks; an unsuccessful deletion stops rather than
 * claiming all history was removed or looping forever.
 *
 * Copyright (c) 2026 Coocadev.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package CoocadevReleaseGo
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
require_once __DIR__ . '/src/class-plugin.php';
\Coocadev\ReleaseGo\Plugin::types();

$ccrg_remove = static function (): void {
	// WordPress returns a persisted scalar boolean as "1" in a fresh process.
	$choice = get_option( 'ccrg_remove_data', false );
	if ( true !== $choice && '1' !== $choice ) {
		return;
	}
	do {
		$ids = get_posts(
			array(
				'post_type'        => 'ccrg_release',
				'post_status'      => array_keys( get_post_stati() ),
				'numberposts'      => 100,
				'fields'           => 'ids',
				'suppress_filters' => false,
			)
		);
		foreach ( $ids as $id ) {
			if ( 'ccrg_release' !== get_post_type( $id ) || ! wp_delete_post( $id, true ) ) {
				wp_die( esc_html__( 'A ReleaseGo entry could not be deleted. Restore or review the retained history before retrying.', 'coocadev-releasego' ) );
			}
		}
		$count = count( $ids );
	} while ( 100 === $count );
	do {
		$terms = get_terms(
			array(
				'taxonomy'   => 'ccrg_product',
				'hide_empty' => false,
				'number'     => 100,
				'fields'     => 'ids',
			)
		);
		if ( is_wp_error( $terms ) ) {
			wp_die( esc_html__( 'ReleaseGo product terms could not be read for cleanup.', 'coocadev-releasego' ) );
		}
		foreach ( $terms as $term ) {
			$result = wp_delete_term( $term, 'ccrg_product' );
			if ( is_wp_error( $result ) || ! $result ) {
				wp_die( esc_html__( 'A ReleaseGo product term could not be deleted.', 'coocadev-releasego' ) );
			}
		}
		$count = count( $terms );
	} while ( 100 === $count );
	delete_option( 'ccrg_remove_data' );
};

if ( is_multisite() ) {
	$ccrg_offset = 0;
	do {
		$ccrg_sites = get_sites(
			array(
				'fields' => 'ids',
				'number' => 100,
				'offset' => $ccrg_offset,
			)
		);
		foreach ( $ccrg_sites as $ccrg_site ) {
			switch_to_blog( (int) $ccrg_site );
			$ccrg_remove();
			restore_current_blog();
		}
		$ccrg_offset += 100;
		$ccrg_count   = count( $ccrg_sites );
	} while ( 100 === $ccrg_count );
} else {
	$ccrg_remove();
}
