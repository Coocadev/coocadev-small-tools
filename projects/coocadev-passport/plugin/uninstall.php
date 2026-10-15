<?php
/**
 * Remove only explicitly opted-in per-site Passport data.
 *
 * Copyright (c) 2026 Coocadev.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package CoocadevPluginPassport
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$ccpp_remove = static function (): void {
	$data = get_option( 'ccpp_data', array() );
	if ( is_array( $data ) && true === ( $data['remove_data'] ?? false ) ) {
		delete_option( 'ccpp_data' );
	}
};

if ( is_multisite() ) {
	$ccpp_offset = 0;
	do {
		$ccpp_sites = get_sites(
			array(
				'fields' => 'ids',
				'number' => 100,
				'offset' => $ccpp_offset,
			)
		);
		foreach ( $ccpp_sites as $ccpp_site ) {
			switch_to_blog( (int) $ccpp_site );
			$ccpp_remove();
			restore_current_blog();
		}
		$ccpp_offset += 100;
		$ccpp_count   = count( $ccpp_sites );
	} while ( 100 === $ccpp_count );
} else {
	$ccpp_remove();
}
