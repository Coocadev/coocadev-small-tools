<?php
/**
 * Execute via wp eval-file on the isolated Reader Notes QA site only.
 * Creates and removes synthetic users/posts. Never run against production.
 */
use Coocadev\ReaderNotes\Access;
use Coocadev\ReaderNotes\Database;
use Coocadev\ReaderNotes\Plugin;
use Coocadev\ReaderNotes\Rest;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}
global $wpdb;
$GLOBALS['ccrn_checks'] = array();
function ccrn_test( bool $condition, string $name ): void {
	$GLOBALS['ccrn_checks'][] = array( 'name' => $name, 'pass' => $condition );
	if ( ! $condition ) {
		WP_CLI::warning( 'FAIL: ' . $name );
	}
}
function ccrn_call( string $method, string $route, ?array $body = null ): WP_REST_Response {
	$request = new WP_REST_Request( $method, '/coocadev-reader-notes/v1/' . $route );
	if ( null !== $body ) {
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );
	}
	$response = rest_do_request( $request );
	// WP_REST_Server::serve_request applies this filter after dispatch on HTTP.
	return apply_filters( 'rest_post_dispatch', $response, rest_get_server(), $request );
}

Database::activate();
$a = wp_insert_user( array( 'user_login' => 'ccrn-test-a-' . time(), 'user_email' => 'ccrn-a-' . time() . '@example.test', 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
$b = wp_insert_user( array( 'user_login' => 'ccrn-test-b-' . time(), 'user_email' => 'ccrn-b-' . time() . '@example.test', 'user_pass' => wp_generate_password(), 'role' => 'administrator' ) );
$post = wp_insert_post( array( 'post_title' => 'Reader Notes synthetic public source', 'post_content' => 'Public fixture', 'post_status' => 'publish', 'post_type' => 'post', 'post_author' => $b ) );
$private = wp_insert_post( array( 'post_title' => 'SECRET SOURCE TITLE', 'post_content' => 'Secret fixture', 'post_status' => 'private', 'post_type' => 'post', 'post_author' => $b ) );
$settings_before = get_option( 'ccrn_settings', null );
try {
	wp_set_current_user( $a );
	ccrn_test( Database::healthy(), 'RN-T01 InnoDB tables installed' );
	ccrn_test( Database::state( $a )['epoch'] === 1, 'Fresh epoch defaults to one without bootstrap writes' );
	$sample = "Alice's note\n<script>alert('x')</script> [shortcode] 😀";
	$note = Database::save( $a, $post, $sample, 0, 1 );
	ccrn_test( is_array( $note ) && $note['content'] === $sample, 'RN-T17 Unicode and markup stored as literal text' );
	$id = (int) $note['id'];
	ccrn_test( null === Database::one( $b, $id ), 'RN-T03 repository owner isolation even for admin' );
	$duplicate = Database::save( $a, $post, 'Duplicate attempt', 0, 1 );
	ccrn_test( is_wp_error( $duplicate ) && 'ccrn_conflict' === $duplicate->get_error_code(), 'RN-T12 duplicate create rejected' );
	$updated = Database::save( $a, $post, 'Updated', 1, 1 );
	ccrn_test( is_array( $updated ) && 2 === (int) $updated['version'], 'RN-T13 version increments after save' );
	$stale = Database::save( $a, $post, 'Stale', 1, 1 );
	ccrn_test( is_wp_error( $stale ) && 'Updated' === Database::one( $a, $id )['content'], 'RN-T13 stale update preserves saved text' );
	$stale_delete = Database::delete( $a, $id, 1, 1 );
	ccrn_test( is_wp_error( $stale_delete ) && null !== Database::one( $a, $id ), 'RN-T13 stale delete rejected' );
	ccrn_test( is_wp_error( Database::content( str_repeat( 'a', 10001 ) ) ), 'RN-T18 character limit enforced' );
	ccrn_test( is_wp_error( Database::content( "\xFF" ) ), 'RN-T18 invalid UTF-8 rejected' );
	ccrn_test( is_wp_error( Database::save( $a, $private, 'Cannot access', 0, 1 ) ), 'RN-T09 private source denies subscriber' );

	wp_set_current_user( $b );
	$res = ccrn_call( 'GET', 'notes/' . $id );
	ccrn_test( 404 === $res->get_status(), 'RN-T04 REST admin cannot read another owner note' );
	$res = ccrn_call( 'DELETE', 'notes/' . $id, array( 'version' => 2, 'epoch' => 1 ) );
	ccrn_test( 404 === $res->get_status() && null !== Database::one( $a, $id ), 'RN-T03 REST admin cannot delete another owner note' );
	$res = ccrn_call( 'POST', 'export', array( 'id' => $id ) );
	ccrn_test( 404 === $res->get_status(), 'RN-T03 REST export does not leak another owner' );

	wp_set_current_user( 0 );
	ccrn_test( 401 === ccrn_call( 'GET', 'notes' )->get_status(), 'RN-T02 REST guest denied' );
	wp_set_current_user( $a );
	$res = ccrn_call( 'PUT', 'posts/' . $post . '/note', array( 'content' => 'Owner injection', 'version' => 2, 'epoch' => 1, 'user_id' => $b ) );
	ccrn_test( 400 === $res->get_status(), 'RN-T05 owner parameter rejected' );
	$res = ccrn_call( 'PUT', 'posts/' . $post . '/note', array( 'content' => array( 'bad' ), 'version' => 2, 'epoch' => 1 ) );
	ccrn_test( 400 === $res->get_status(), 'RN-T18 array text rejected by REST' );
	$res = ccrn_call( 'GET', 'notes' );
	ccrn_test( str_contains( $res->get_headers()['Cache-Control'] ?? '', 'no-store' ), 'RN-T08 private REST cache header' );
	$res = ccrn_call( 'GET', 'notes/99999999' );
	ccrn_test( str_contains( $res->get_headers()['Cache-Control'] ?? '', 'no-store' ), 'RN-T08 private errors are no-store' );
	$request = new WP_REST_Request( 'GET', '/coocadev-reader-notes/v1/notes' );
	$request->set_query_params( array( 'search' => "' OR 1=1 --" ) );
	ccrn_test( count( rest_do_request( $request )->get_data()['notes'] ) === 0, 'RN-T17 SQL search payload remains literal' );
	$_SERVER['HTTP_ORIGIN'] = 'https://attacker.example';
	ccrn_test( 403 === ccrn_call( 'GET', 'notes' )->get_status(), 'RN-T07 cross-origin requests rejected' );
	unset( $_SERVER['HTTP_ORIGIN'] );

	update_option( 'ccrn_settings', array_merge( Plugin::settings(), array( 'quota' => 1 ) ), false );
	$post2 = wp_insert_post( array( 'post_title' => 'Second source', 'post_status' => 'publish', 'post_type' => 'post' ) );
	$quota = Database::save( $a, $post2, 'Blocked by owner policy', 0, 1 );
	ccrn_test( is_wp_error( $quota ) && 'ccrn_quota' === $quota->get_error_code(), 'RN-T37 configured quota enforced' );
	ccrn_test( is_array( Database::save( $a, $post, 'Edit still works', 2, 1 ) ), 'RN-T39 quota does not block existing edits' );
	update_option( 'ccrn_settings', array_merge( Plugin::settings(), array( 'quota' => null ) ), false );
	ccrn_test( is_array( Database::save( $a, $post2, 'Now allowed', 0, 1 ) ), 'RN-T40 removing quota allows new notes' );

	wp_update_post( array( 'ID' => $post, 'post_status' => 'private' ) );
	$present = Access::public_note( Database::one( $a, $id ), $a );
	ccrn_test( null === $present['source'] && null === $present['post_id'] && 'Edit still works' === $present['content'], 'RN-T10 revoked source metadata hidden but own text retained' );
	wp_set_current_user( $b );
	$export = Plugin::privacy_export( get_userdata( $a )->user_email );
	ccrn_test( ! str_contains( wp_json_encode( $export ), get_permalink( $post ) ), 'RN-T32 privacy export uses subject rights, not admin rights' );
	wp_set_current_user( $a );
	wp_delete_post( $post, true );
	ccrn_test( null === Database::one( $a, $id )['post_id'], 'RN-T11 source deletion detaches without erasing note' );

	$erase = Database::erase( $a, 1 );
	ccrn_test( is_array( $erase ) && $erase['done'] && 2 === $erase['epoch'], 'RN-T21 delete-all increments epoch and completes' );
	$late = Database::save( $a, $post2, 'Delayed old autosave', 0, 1 );
	ccrn_test( is_wp_error( $late ) && 'ccrn_epoch' === $late->get_error_code(), 'RN-T35 delayed autosave cannot resurrect erased text' );
	ccrn_test( is_array( Database::save( $a, $post2, 'Fresh after erase', 0, 2 ) ), 'Fresh epoch permits intentional new note' );
	ccrn_test( Database::listing( $b ) === array(), 'Erasure and exports did not add admin data' );
	$result = Plugin::privacy_erase( get_userdata( $a )->user_email, 1 );
	ccrn_test( true === $result['done'] && true === $result['items_removed'], 'RN-T21 Core privacy eraser deletes reader notes' );
	ccrn_test( Database::state( $a )['epoch'] === 3, 'Privacy eraser preserves monotonic epoch' );
	ccrn_test( Plugin::privacy_erase( get_userdata( $a )->user_email, 2 )['done'], 'RN-T36 eraser follow-up is idempotent' );
	$seed_time = current_time( 'mysql', true );
	for ( $batch = 0; $batch < 10; $batch++ ) {
		$values = array();
		for ( $index = 0; $index < 500; $index++ ) {
			$values[] = $wpdb->prepare( '(%d,NULL,%s,1,%s,%s)', $a, 'Synthetic detached note ' . ( $batch * 500 + $index ), $seed_time, $seed_time );
		}
		$wpdb->query( $wpdb->prepare( 'INSERT INTO %i (user_id,post_id,content,version,created_at_gmt,updated_at_gmt) VALUES ', Database::table() ) . implode( ',', $values ) );
	}
	$above_five_thousand = Database::save( $a, $post2, 'No hidden free limit', 0, 3 );
	ccrn_test( is_array( $above_five_thousand ), 'RN-T40 actual 5001st note succeeds with null quota' );
	ccrn_test( 20 === count( Database::listing( $a ) ), 'RN-T19 large list is bounded to 20 notes' );
	$batch_erase = Database::erase( $a, 3 );
	ccrn_test( ! $batch_erase['done'] && 100 === $batch_erase['removed'] && is_string( $batch_erase['operation'] ), 'RN-T35 erasure keeps operation barrier between bounded batches' );
	$wrong_operation = Database::erase( $a, $batch_erase['epoch'], wp_generate_uuid4() );
	ccrn_test( is_wp_error( $wrong_operation ) && Database::state( $a )['erasing'], 'RN-T36 different operation cannot clear active barrier' );
	$erasing_write = Database::save( $a, $post2, 'Must not reappear', 0, $batch_erase['epoch'] );
	ccrn_test( is_wp_error( $erasing_write ) && 'ccrn_epoch' === $erasing_write->get_error_code(), 'RN-T35 even new epoch cannot write during active erasure' );
	while ( ! $batch_erase['done'] ) {
		$batch_erase = Database::erase( $a, $batch_erase['epoch'], $batch_erase['operation'] );
	}
	ccrn_test( array() === Database::listing( $a ) && ! Database::state( $a )['erasing'], 'RN-T36 batch continuation fully erases and releases only its barrier' );
	$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ENGINE=MyISAM', Database::table() ) );
	try {
		$unsupported = Database::save( $a, $post2, 'Unsafe engine', 0, $batch_erase['epoch'] );
		ccrn_test( is_wp_error( $unsupported ) && 'ccrn_storage' === $unsupported->get_error_code(), 'RN-T38 nontransactional engine disables writes' );
	} finally {
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ENGINE=InnoDB', Database::table() ) );
	}

	$html = do_shortcode( '[ccrn_my_notes]' );
	ccrn_test( ! str_contains( $html, $sample ) && ! str_contains( $html, 'nonce' ), 'RN-T08 shortcode is a neutral shell' );
	ccrn_test( WP_Block_Type_Registry::get_instance()->is_registered( 'coocadev-reader-notes/note' ) && WP_Block_Type_Registry::get_instance()->is_registered( 'coocadev-reader-notes/list' ), 'RN-T26 both Gutenberg blocks registered' );
	$before_account = Database::state( $a )['epoch'];
	Database::save( $a, $post2, 'Member removal text', 0, $before_account );
	ccrn_test( true === Database::account( $a ) && array() === Database::listing( $a ) && Database::state( $a )['erasing'], 'RN-T23 account cleanup atomically erases text and keeps tombstone' );
	ccrn_test( is_wp_error( Database::save( $a, $post2, 'Delayed member autosave', 0, $before_account ) ), 'RN-T35 account tombstone blocks delayed autosave' );
	ccrn_test( true === Database::account( $a, true ) && ! Database::state( $a )['erasing'] && Database::state( $a )['epoch'] === $before_account + 2, 'RN-T23 restored membership receives fresh epoch' );
	Database::save( $b, $post2, 'Account deletion text', 0, Database::state( $b )['epoch'] );
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $b );
	ccrn_test( array() === Database::listing( $b ) && Database::state( $b )['erasing'] && ! get_userdata( $b ), 'RN-T23 actual WordPress account deletion clears private text first' );
	wp_delete_post( $post2, true );
} finally {
	if ( null === $settings_before ) delete_option( 'ccrn_settings' ); else update_option( 'ccrn_settings', $settings_before, false );
	wp_set_current_user( 0 );
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $a );
	wp_delete_user( $b );
	wp_delete_post( $private, true );
	$wpdb->delete( Database::table( 'state' ), array( 'user_id' => $a ), array( '%d' ) );
	$wpdb->delete( Database::table( 'state' ), array( 'user_id' => $b ), array( '%d' ) );
}
$checks = $GLOBALS['ccrn_checks'];
$failed = count( array_filter( $checks, static fn( array $row ): bool => ! $row['pass'] ) );
echo wp_json_encode( array( 'wp' => get_bloginfo( 'version' ), 'php' => PHP_VERSION, 'checks' => $checks, 'total' => count( $checks ), 'failed' => $failed ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) . PHP_EOL;
if ( $failed ) WP_CLI::halt( 1 );

