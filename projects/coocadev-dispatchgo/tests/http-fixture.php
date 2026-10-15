<?php
/** Disposable local HTTP account and settings snapshot; never package in runtime. */
if ( empty( $argv[1] ) || ! preg_match( '~/qa/sites/dispatchgo$~', str_replace( '\\', '/', $argv[1] ) ) ) { throw new RuntimeException( 'Isolated DispatchGo QA only.' ); }
require rtrim( $argv[1], '/\\' ) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
add_filter( 'pre_wp_mail', '__return_true' );
$private = __DIR__ . '/../evidence/http-private-fixture.json';
if ( 'verify' === ( $argv[2] ?? '' ) ) {
    $data = json_decode( file_get_contents( $private ), true );
    if ( \Coocadev\DispatchGo\Repository::settings() !== $data['settings'] ) { throw new RuntimeException( 'Malformed HTTP requests changed persisted settings.' ); }
    echo "PASS persisted settings are identical after malformed HTTP requests\n"; exit;
}
if ( 'cleanup' === ( $argv[2] ?? '' ) ) {
    $data = json_decode( file_get_contents( $private ), true );
    update_option( 'ccdg_settings', $data['settings'] );
    wp_delete_user( $data['id'] ); unlink( $private );
    echo "HTTP fixture removed; original configuration restored.\n"; exit;
}
$password = wp_generate_password( 32, true, true );
$login = 'ccdg_http_' . strtolower( wp_generate_password( 8, false ) );
$id = wp_insert_user( array( 'user_login' => $login, 'user_pass' => $password, 'user_email' => $login . '@example.invalid', 'role' => 'shop_manager' ) );
if ( is_wp_error( $id ) ) { throw new RuntimeException( $id->get_error_message() ); }
file_put_contents( $private, wp_json_encode( array( 'url' => 'http://127.0.0.1:8782', 'login' => $login, 'password' => $password, 'id' => $id, 'settings' => \Coocadev\DispatchGo\Repository::settings() ) ) );
echo "Disposable HTTP fixture created (credentials not printed).\n";
