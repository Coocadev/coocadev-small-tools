<?php
/** Run only on the disposable local Reader Notes multisite database. */
use Coocadev\ReaderNotes\Access;
use Coocadev\ReaderNotes\Database;
use Coocadev\ReaderNotes\Plugin;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! is_multisite() || 'ccst_reader_notes_multisite' !== DB_NAME ) {
	throw new RuntimeException( 'Unexpected environment.' );
}
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/ms.php';
global $wpdb;
$GLOBALS['ccrn_multi_checks'] = array();
function ccrn_multi_check( bool $pass, string $name ): void {
	$GLOBALS['ccrn_multi_checks'][] = compact( 'name', 'pass' );
}
function ccrn_restore_main(): void {
	while ( ms_is_switched() ) restore_current_blog();
}
add_filter( 'wp_die_handler', static fn() => static function ( $message ) { throw new RuntimeException( wp_strip_all_tags( $message ) ); }, PHP_INT_MAX );
wp_set_current_user( 1 );
$plugin = 'coocadev-reader-notes/coocadev-reader-notes.php';
$suffix = strtolower( wp_generate_password( 8, false ) );
$network = get_network();
$two = (int) wpmu_create_blog( $network->domain, '/notes-' . $suffix . '/', 'Notes second site', 1 );
$empty = (int) wpmu_create_blog( $network->domain, '/empty-' . $suffix . '/', 'No Notes plugin', 1 );
switch_to_blog( $two );
$activated = activate_plugin( $plugin );
ccrn_multi_check( null === $activated && Database::healthy(), 'Individual activation creates per-site InnoDB tables' );
$two_table = Database::table();
restore_current_blog();
$main_table = Database::table();
ccrn_multi_check( $two_table !== $main_table, 'Per-site table names are isolated' );

$blocked = false;
try { Database::activate( true ); } catch ( RuntimeException $e ) { $blocked = str_contains( $e->getMessage(), 'Network activation is not supported' ); }
ccrn_multi_check( $blocked, 'Network activation is explicitly rejected' );

$reader = wp_create_user( 'ccrn_multi_' . $suffix, wp_generate_password( 24 ), $suffix . '@example.test' );
add_user_to_blog( 1, $reader, 'subscriber' );
add_user_to_blog( $two, $reader, 'subscriber' );
add_user_to_blog( $empty, $reader, 'subscriber' );
$removed_empty = false;
try { $removed_empty = true === remove_user_from_blog( $reader, $empty ); } catch ( RuntimeException $e ) { /* A failure is recorded, not swallowed as success. */ }
ccrn_restore_main();
ccrn_multi_check( $removed_empty, 'Removing membership from a site without Reader Notes does not touch absent tables' );

wp_set_current_user( $reader );
$main_post = wp_insert_post( array( 'post_title' => 'Main notes source', 'post_status' => 'publish' ) );
$main_note = Database::save( $reader, $main_post, 'Main private content', 0, Database::state( $reader )['epoch'] );
ccrn_multi_check( is_array( $main_note ), 'Member saves a private note on main site' );
switch_to_blog( $two );
$two_post = wp_insert_post( array( 'post_title' => 'Second notes source', 'post_status' => 'publish' ) );
$two_note = Database::save( $reader, $two_post, 'Second site private content', 0, Database::state( $reader )['epoch'] );
ccrn_multi_check( is_array( $two_note ) && 'Second site private content' === Database::listing( $reader )[0]['content'], 'Second site reads only its own note' );
$old_epoch = Database::state( $reader )['epoch'];
restore_current_blog();
ccrn_multi_check( 'Main private content' === Database::listing( $reader )[0]['content'], 'Switching back restores main-site storage scope' );

wp_set_current_user( 1 );
$removed_two = remove_user_from_blog( $reader, $two );
switch_to_blog( $two );
ccrn_multi_check( true === $removed_two && array() === Database::listing( $reader ) && Database::state( $reader )['erasing'], 'Member removal erases target-site text and keeps epoch tombstone' );
ccrn_multi_check( ! Access::member( $reader ), 'Removed member cannot access target-site notes' );
restore_current_blog();
ccrn_multi_check( 1 === count( Database::listing( $reader ) ), 'Member removal leaves notes on another site intact' );
add_user_to_blog( $two, $reader, 'subscriber' );
switch_to_blog( $two );
$restored = Database::state( $reader );
ccrn_multi_check( ! $restored['erasing'] && $restored['epoch'] > $old_epoch, 'Re-added member receives a fresh unlocked epoch' );
wp_set_current_user( $reader );
ccrn_multi_check( is_wp_error( Database::save( $reader, $two_post, 'Stale draft', 0, $old_epoch ) ), 'Pre-removal stale draft cannot return after re-addition' );
ccrn_multi_check( is_array( Database::save( $reader, $two_post, 'Fresh second note', 0, $restored['epoch'] ) ), 'Re-added member can intentionally create a new note' );
restore_current_blog();
wp_set_current_user( 1 );
$network_deleted = false;
try { $network_deleted = wpmu_delete_user( $reader ); } catch ( RuntimeException $e ) { /* Recorded below. */ }
ccrn_restore_main();
ccrn_multi_check( $network_deleted && array() === Database::listing( $reader ), 'Actual network account deletion erases main-site notes' );
switch_to_blog( $two );
ccrn_multi_check( array() === Database::listing( $reader ), 'Actual network account deletion erases second-site notes' );
restore_current_blog();

$keeper = wp_create_user( 'ccrn_keeper_' . $suffix, wp_generate_password( 24 ), 'keeper-' . $suffix . '@example.test' );
add_user_to_blog( 1, $keeper, 'subscriber' );
add_user_to_blog( $two, $keeper, 'subscriber' );
wp_set_current_user( $keeper );
$main_keep_post = wp_insert_post( array( 'post_title' => 'Retain main source', 'post_status' => 'publish' ) );
Database::save( $keeper, $main_keep_post, 'Retained by default', 0, Database::state( $keeper )['epoch'] );
switch_to_blog( $two );
$two_keep_post = wp_insert_post( array( 'post_title' => 'Retain second source', 'post_status' => 'publish' ) );
Database::save( $keeper, $two_keep_post, 'Explicit cleanup data', 0, Database::state( $keeper )['epoch'] );
$options = Plugin::settings(); $options['delete_data'] = true; update_option( 'ccrn_settings', $options );
deactivate_plugins( $plugin );
ccrn_multi_check( 1 === count( Database::listing( $keeper ) ), 'Deactivation preserves private note data' );
restore_current_blog();
wp_set_current_user( 1 );
$main_settings = Plugin::settings(); $main_settings['delete_data'] = false; update_option( 'ccrn_settings', $main_settings );
$main_blog_name = get_option( 'blogname' );
deactivate_plugins( $plugin );
uninstall_plugin( $plugin );
ccrn_multi_check( Database::healthy() && 'Retained by default' === Database::listing( $keeper )[0]['content'], 'Uninstall preserves data on a site without destructive opt-in' );
ccrn_multi_check( get_post( $main_keep_post ) && $main_blog_name === get_option( 'blogname' ), 'Uninstall preserves unrelated main-site posts and settings' );
switch_to_blog( $two );
ccrn_multi_check( ! Database::healthy() && false === get_option( 'ccrn_settings', false ) && false === get_option( 'ccrn_schema_version', false ), 'Uninstall removes only opted-in site tables and owned options' );
ccrn_multi_check( null !== get_post( $two_keep_post ), 'Opted-in uninstall leaves source posts intact' );
restore_current_blog();

activate_plugin( $plugin );
ccrn_multi_check( 'Retained by default' === Database::listing( $keeper )[0]['content'], 'Reactivation preserves retained notes' );
switch_to_blog( $two );
activate_plugin( $plugin );
ccrn_multi_check( Database::healthy() && array() === Database::listing( $keeper ), 'Reactivation recreates an empty opted-in cleaned site' );
restore_current_blog();
$checks = $GLOBALS['ccrn_multi_checks'];
$failed = count( array_filter( $checks, static fn( array $check ): bool => ! $check['pass'] ) );
echo wp_json_encode( array( 'wp' => get_bloginfo( 'version' ), 'php' => PHP_VERSION, 'total' => count( $checks ), 'failed' => $failed, 'checks' => $checks ), JSON_PRETTY_PRINT ) . PHP_EOL;
if ( $failed ) WP_CLI::halt( 1 );
