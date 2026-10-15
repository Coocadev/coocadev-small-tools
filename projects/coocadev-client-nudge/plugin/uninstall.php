<?php
/**
 * Opt-in cleanup of this site's own data.
 *
 * @package CoocadevClientNudge
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
wp_clear_scheduled_hook( 'cccn_reminders' );
if ( ! get_option( 'cccn_delete_data', false ) ) {
	return; }
do {
	$cccn_ids = get_posts(
		array(
			'post_type'   => 'cccn_task',
			'post_status' => array_keys( get_post_stati() ),
			'numberposts' => 100,
			'fields'      => 'ids',
		)
	);
	foreach ( $cccn_ids as $cccn_id ) {
		wp_delete_post( $cccn_id, true );
	}
	$cccn_count = count( $cccn_ids );
} while ( 100 === $cccn_count );
delete_metadata( 'user', 0, 'cccn_optin_' . get_current_blog_id(), '', true );
global $wpdb;
$cccn_keys = $wpdb->get_col( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name LIKE %s OR option_name LIKE %s', $wpdb->options, $wpdb->esc_like( 'cccn_sent_' ) . '%', $wpdb->esc_like( 'cccn_lock_' ) . '%' ) );
foreach ( $cccn_keys as $cccn_key ) {
	delete_option( $cccn_key ); }
delete_option( 'cccn_delete_data' );
delete_option( 'cccn_cursor' );
