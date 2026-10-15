<?php
/** Two separate CLI invocations exercise Core uninstall in each retention mode. */
use Coocadev\ReaderNotes\Database;
use Coocadev\ReaderNotes\Plugin;
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || is_multisite() || 'ccst_min_reader_notes' !== DB_NAME ) {
	throw new RuntimeException( 'Unexpected lifecycle QA environment.' );
}
$mode = $args[0] ?? '';
if ( ! in_array( $mode, array( 'preserve', 'delete' ), true ) ) throw new RuntimeException( 'Use preserve or delete.' );
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
$checks = array();
$user = get_user_by( 'login', 'ccrn_lifecycle_reader' );
$id = $user ? $user->ID : wp_create_user( 'ccrn_lifecycle_reader', wp_generate_password( 24 ), 'ccrn-lifecycle@example.test' );
wp_set_current_user( $id );
$post = (int) get_option( 'ccrn_qa_lifecycle_post', 0 );
if ( ! get_post( $post ) ) {
	$post = wp_insert_post( array( 'post_title' => 'Lifecycle source survives uninstall', 'post_status' => 'publish' ) );
	update_option( 'ccrn_qa_lifecycle_post', $post );
}
$row = Database::by_post( $id, $post );
$note = Database::save( $id, $post, 'Local lifecycle note', $row ? (int) $row['version'] : 0, Database::state( $id )['epoch'] );
$checks[] = array( 'name' => 'Fixture note saved before uninstall', 'pass' => is_array( $note ) );
$settings = Plugin::settings();
$settings['delete_data'] = 'delete' === $mode;
update_option( 'ccrn_settings', $settings );
$name = get_option( 'blogname' );
$plugin = 'coocadev-reader-notes/coocadev-reader-notes.php';
deactivate_plugins( $plugin );
$checks[] = array( 'name' => 'Deactivation retains note', 'pass' => 1 === count( Database::listing( $id ) ) );
uninstall_plugin( $plugin );
if ( 'preserve' === $mode ) {
	$checks[] = array( 'name' => 'Default Core uninstall retains note tables and contents', 'pass' => Database::healthy() && 1 === count( Database::listing( $id ) ) );
} else {
	$checks[] = array( 'name' => 'Opted-in Core uninstall removes own tables and settings', 'pass' => ! Database::healthy() && false === get_option( 'ccrn_settings', false ) && false === get_option( 'ccrn_schema_version', false ) );
}
$checks[] = array( 'name' => 'Core uninstall preserves account, source and site title', 'pass' => (bool) get_userdata( $id ) && null !== get_post( $post ) && $name === get_option( 'blogname' ) );
activate_plugin( $plugin );
$checks[] = array( 'name' => 'Clean reactivation has expected retained or empty data', 'pass' => Database::healthy() && ( 'preserve' === $mode ? 1 : 0 ) === count( Database::listing( $id ) ) );
if ( 'delete' === $mode ) {
	wp_set_current_user( 0 );
	wp_delete_post( $post, true );
	wp_delete_user( $id );
	delete_option( 'ccrn_qa_lifecycle_post' );
}
$failed = count( array_filter( $checks, static fn( array $row ): bool => ! $row['pass'] ) );
echo wp_json_encode( array( 'wp' => get_bloginfo( 'version' ), 'php' => PHP_VERSION, 'mode' => $mode, 'total' => count( $checks ), 'failed' => $failed, 'checks' => $checks ), JSON_PRETTY_PRINT ) . PHP_EOL;
if ( $failed ) WP_CLI::halt( 1 );
