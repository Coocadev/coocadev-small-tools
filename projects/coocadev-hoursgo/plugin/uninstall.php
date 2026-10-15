<?php
/**
 * Preserve schedules unless the administrator opted into deletion.
 *
 * @package CoocadevHoursGo
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
$cchg_settings = get_option( 'cchg_settings', array() );
if ( ! empty( $cchg_settings['delete_data'] ) ) {
	delete_option( 'cchg_settings' ); }
