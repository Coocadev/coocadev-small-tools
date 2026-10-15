<?php
/** Synthetic local browser fixtures. Copyright 2026 Coocadev; GPL-2.0-or-later. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() || 'http://127.0.0.1:8785' !== untrailingslashit( home_url() ) ) {
    throw new RuntimeException( 'Dedicated local combined QA only.' );
}
$file = __DIR__ . '/secrets/browser-020.json';
if ( ! empty( $args[0] ) && 'cleanup' === $args[0] ) {
    $data = json_decode( file_get_contents( $file ), true );
    foreach ( $data['posts'] as $id ) {
        if ( $data['marker'] === get_post_meta( $id, '_ccst_ui_fixture', true ) ) {
            'attachment' === get_post_type( $id ) ? wp_delete_attachment( $id, true ) : wp_delete_post( $id, true );
        }
    }
    WP_CLI::success( 'Synthetic browser content cleaned.' );
    return;
}
wp_set_current_user( get_user_by( 'login', 'qa_owner' )->ID );
$marker = wp_generate_uuid4();
$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aT2kAAAAASUVORK5CYII=' );
$upload = wp_upload_bits( 'browser-' . $marker . '.png', null, $png );
if ( $upload['error'] ) { throw new RuntimeException( $upload['error'] ); }
$image = wp_insert_attachment( array( 'post_title' => 'Small Tools UI fixture', 'post_mime_type' => 'image/png', 'post_status' => 'inherit', 'post_author' => get_current_user_id() ), $upload['file'] );
$posts = array( $image );
$image_post = wp_insert_post( array( 'post_title' => 'UI scanner image fixture', 'post_content' => '<!-- wp:image {"id":' . $image . '} /-->', 'post_status' => 'publish', 'post_author' => get_current_user_id() ) );
$use_post = wp_insert_post( array( 'post_title' => 'UI scanner shortcode fixture', 'post_content' => '[ccst_ui_tag] <!-- wp:ccst/ui-card /-->', 'post_status' => 'publish', 'post_author' => get_current_user_id() ) );
$posts[] = $image_post; $posts[] = $use_post;
for ( $i = 0; $i < 43; $i++ ) { $posts[] = wp_insert_post( array( 'post_title' => 'UI scanner filler ' . $i, 'post_status' => 'publish', 'post_author' => get_current_user_id() ) ); }
foreach ( $posts as $id ) { update_post_meta( $id, '_ccst_ui_fixture', $marker ); }
file_put_contents( $file, wp_json_encode( array( 'marker' => $marker, 'image' => $image, 'imagePost' => $image_post, 'usePost' => $use_post, 'posts' => $posts ) ) );
WP_CLI::success( 'Synthetic browser fixtures prepared without sending email.' );
