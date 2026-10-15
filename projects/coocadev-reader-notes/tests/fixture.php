<?php
// Synthetic local HTTP/UI test accounts. The generated credentials never enter runtime ZIPs.
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) exit(1);
$path = __DIR__ . '/.runtime-fixture.json';
if ( file_exists( $path ) ) { WP_CLI::success( 'Fixture already exists.' ); return; }
$pass = wp_generate_password( 28, false );
$a = wp_insert_user( array( 'user_login' => 'ccrn_http_reader', 'user_email' => 'ccrn-http-reader@example.test', 'user_pass' => $pass, 'role' => 'subscriber' ) );
$bpass = wp_generate_password( 28, false );
$b = wp_insert_user( array( 'user_login' => 'ccrn_http_admin', 'user_email' => 'ccrn-http-admin@example.test', 'user_pass' => $bpass, 'role' => 'administrator' ) );
$post = wp_insert_post( array( 'post_title' => 'Reader Notes QA article', 'post_status' => 'publish', 'post_content' => "Synthetic test article.\n\n[ccrn_note]\n\n[ccrn_note]\n\n[ccrn_my_notes]", 'post_type' => 'post' ) );
$page = wp_insert_post( array( 'post_title' => 'Reader Notes QA list', 'post_status' => 'publish', 'post_content' => '[ccrn_my_notes]', 'post_type' => 'page' ) );
file_put_contents( $path, wp_json_encode( array( 'a' => array( 'id' => $a, 'login' => 'ccrn_http_reader', 'password' => $pass ), 'b' => array( 'id' => $b, 'login' => 'ccrn_http_admin', 'password' => $bpass ), 'post' => $post, 'page' => $page, 'base' => home_url() ) ) );
WP_CLI::success( 'Synthetic HTTP fixture ready.' );

