<?php
/** Run with wp eval-file on an isolated local installation; all outbound mail is intercepted. */
use Coocadev\ClientNudge\Plugin;
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() || ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( '127.0.0.1', 'localhost', '::1' ), true ) ) { throw new RuntimeException( 'Synthetic integration tests require local WP-CLI and a localhost site.' ); }
$cccn_results = array();
$cccn_assert = static function ( string $name, bool $condition ) use ( &$cccn_results ): void { $cccn_results[] = array( 'test' => $name, 'passed' => $condition ); if ( ! $condition ) { throw new RuntimeException( $name ); } };
$cccn_admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0]->ID;
$cccn_a = wp_create_user( 'cccn_a_' . wp_generate_password( 8, false ), wp_generate_password(), 'cccn-a-' . uniqid() . '@example.test' );
$cccn_b = wp_create_user( 'cccn_b_' . wp_generate_password( 8, false ), wp_generate_password(), 'cccn-b-' . uniqid() . '@example.test' );
$cccn_mail = array();
$cccn_sink = static function ( $return, $attributes ) use ( &$cccn_mail ) { $cccn_mail[] = $attributes; return true; };
add_filter( 'pre_wp_mail', $cccn_sink, 10, 2 );
$cccn_tasks = array();
try {
 wp_set_current_user( $cccn_admin );
 $cccn_tasks[] = Plugin::create( 'Send team photographs', 'Plain client instructions', $cccn_a, '2026-11-01' );
 $cccn_tasks[] = Plugin::create( 'Already completed', 'Do not email this', $cccn_a, '2026-11-02' );
 $cccn_tasks[] = Plugin::create( 'Other client private task', 'Never show to A', $cccn_b, '2026-11-02' );
 $cccn_assert( 'Admin creates private tasks', ! is_wp_error( $cccn_tasks[0] ) && 'private' === get_post_status( $cccn_tasks[0] ) );
 $cccn_assert( 'Invalid calendar date rejected', is_wp_error( Plugin::create( 'Bad date', '', $cccn_a, '2026-02-30' ) ) );
 wp_set_current_user( $cccn_a );
 $cccn_assert( 'Client cannot create tasks', is_wp_error( Plugin::create( 'Forbidden', '', $cccn_a, '2026-11-01' ) ) );
 $cccn_assert( 'Client cannot consent for another account', ! Plugin::opt_in( $cccn_b, true ) );
 $cccn_assert( 'Client cannot complete another client task', ! Plugin::complete( $cccn_tasks[2] ) );
 wp_update_user( array( 'ID' => $cccn_b, 'role' => 'editor' ) ); wp_set_current_user( $cccn_b );
 $cccn_assert( 'Editor native post access cannot bypass task privacy', ! current_user_can( 'read_post', $cccn_tasks[0] ) ); wp_set_current_user( $cccn_a );
 $cccn_assert( 'Client completes own task idempotently', Plugin::complete( $cccn_tasks[1] ) && Plugin::complete( $cccn_tasks[1] ) );
 $cccn_assert( 'Own board excludes other client', ! str_contains( Plugin::board(), 'Other client private task' ) );
 $cccn_assert( 'Private board disables page cache', defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE );
 $cccn_assert( 'No reminder without consent', 0 === Plugin::reminders( strtotime( '2026-10-15 UTC' ) ) && ! $cccn_mail );
 $cccn_assert( 'Client explicitly opts in', Plugin::opt_in( $cccn_a, true ) );
 $cccn_assert( 'Consent is site scoped', str_ends_with( Plugin::consent_key(), '_' . get_current_blog_id() ) );
 $cccn_assert( 'One reminder accepted', 1 === Plugin::reminders( strtotime( '2026-10-15 UTC' ) ) );
 $cccn_assert( 'Reminder only contains pending tasks', str_contains( $cccn_mail[0]['message'], 'Send team photographs' ) && ! str_contains( $cccn_mail[0]['message'], 'Already completed' ) && ! str_contains( $cccn_mail[0]['message'], 'Other client' ) );
 $cccn_assert( 'Repeated worker deduplicated in same week', 0 === Plugin::reminders( strtotime( '2026-10-16 UTC' ) ) );
 $cccn_assert( 'Next week may send', 1 === Plugin::reminders( strtotime( '2026-10-22 UTC' ) ) );
 Plugin::opt_in( $cccn_a, false );
 $cccn_assert( 'Opt-out stops future reminder', 0 === Plugin::reminders( strtotime( '2026-10-29 UTC' ) ) );
 $cccn_export = Plugin::export( get_userdata( $cccn_a )->user_email );
 $cccn_assert( 'Privacy export contains only assigned tasks and consent', 3 === count( $cccn_export['data'] ) && ! str_contains( wp_json_encode( $cccn_export ), 'Other client' ) && str_contains( wp_json_encode( $cccn_export ), 'Completed' ) && str_contains( wp_json_encode( $cccn_export ), 'Stored task status' ) );
 $cccn_trash = wp_insert_post( array( 'post_type' => Plugin::TYPE, 'post_status' => 'trash', 'post_title' => 'Trashed privacy fixture', 'meta_input' => array( 'cccn_assignee' => $cccn_a ) ) ); $cccn_tasks[] = $cccn_trash;
 $cccn_assert( 'Trashed task cannot be completed by the client', ! Plugin::complete( $cccn_trash ) );
 $cccn_erased = Plugin::erase( get_userdata( $cccn_a )->user_email );
 $cccn_assert( 'Privacy erasure removes own tasks and keeps other client', $cccn_erased['items_removed'] && ! Plugin::tasks( $cccn_a ) && 1 === count( Plugin::tasks( $cccn_b ) ) );
 $cccn_assert( 'Privacy erasure includes trashed tasks', null === get_post( $cccn_trash ) );
 wp_set_current_user( 0 );
 $cccn_assert( 'Logged-out board has no private content', ! str_contains( Plugin::board(), 'Send team photographs' ) );
 $cccn_assert( 'Logged-out completion rejected', ! Plugin::complete( $cccn_tasks[2] ) );
 echo wp_json_encode( array( 'version' => '0.2.0', 'wp' => get_bloginfo( 'version' ), 'php' => PHP_VERSION, 'tests' => $cccn_results ), JSON_PRETTY_PRINT ) . "\n";
} finally {
 wp_set_current_user( $cccn_admin ); foreach ( $cccn_tasks as $cccn_task ) { if ( is_int( $cccn_task ) ) { wp_delete_post( $cccn_task, true ); } }
 require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( $cccn_a ); wp_delete_user( $cccn_b ); remove_filter( 'pre_wp_mail', $cccn_sink, 10 );
}
