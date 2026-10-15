<?php
/**
 * Preserve branding unless the administrator opted into deletion.
 *
 * @package CoocadevMailSkin
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
$ccms_settings = get_option( 'ccms_settings', array() );
if ( ! empty( $ccms_settings['delete_data'] ) ) {
	delete_option( 'ccms_settings' ); }
