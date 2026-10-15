<?php
/** Local-only behavior tests for the two independent content scanners. GPL-2.0-or-later. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() || ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( 'localhost', '127.0.0.1' ), true ) ) {
    throw new RuntimeException( 'Run this fixture only on an isolated local WordPress site.' );
}
$product = defined( 'CCST_SCAN_PRODUCT' ) ? CCST_SCAN_PRODUCT : '';
if ( ! in_array( $product, array( 'mediamap', 'usemap' ), true ) ) { throw new RuntimeException( 'Unknown scanner fixture.' ); }
$scanner = 'mediamap' === $product ? \Coocadev\MediaMap\Scanner::class : \Coocadev\UseMap\Scanner::class;
$plugin = 'mediamap' === $product ? \Coocadev\MediaMap\Plugin::class : \Coocadev\UseMap\Plugin::class;
$domain = 'coocadev-' . $product;
if ( ! class_exists( $scanner ) ) { throw new RuntimeException( 'Activate the selected scanner first.' ); }
require_once ABSPATH . 'wp-admin/includes/user.php';
$checks = array(); $posts = array(); $users = array(); $image = 0;
$verify = static function ( string $name, bool $pass ) use ( &$checks ): void {
    $checks[] = array( 'name' => $name, 'pass' => $pass );
    WP_CLI::log( ( $pass ? 'PASS ' : 'FAIL ' ) . $name );
};
$insert = static function ( array $data ) use ( &$posts ): int {
    $id = wp_insert_post( $data, true );
    if ( is_wp_error( $id ) ) { throw new RuntimeException( $id->get_error_message() ); }
    $posts[] = $id;
    return $id;
};
try {
    $admin = wp_insert_user( array( 'user_login' => 'scan_admin_' . wp_generate_password( 8, false, false ), 'user_pass' => wp_generate_password( 24 ), 'user_email' => wp_generate_uuid4() . '@example.test', 'role' => 'administrator' ) );
    $subscriber = wp_insert_user( array( 'user_login' => 'scan_sub_' . wp_generate_password( 8, false, false ), 'user_pass' => wp_generate_password( 24 ), 'user_email' => wp_generate_uuid4() . '@example.test', 'role' => 'subscriber' ) );
    $manager = wp_insert_user( array( 'user_login' => 'scan_manager_' . wp_generate_password( 8, false, false ), 'user_pass' => wp_generate_password( 24 ), 'user_email' => wp_generate_uuid4() . '@example.test', 'role' => 'subscriber' ) );
    foreach ( array( $admin, $subscriber, $manager ) as $id ) { if ( is_wp_error( $id ) ) { throw new RuntimeException( $id->get_error_message() ); } $users[] = $id; }
    ( new WP_User( $manager ) )->add_cap( 'manage_options' );
    wp_set_current_user( $admin );
    rest_get_server();
    $start = $insert( array( 'post_title' => 'Scanner fixture boundary', 'post_status' => 'draft', 'post_author' => $admin ) );
    $targets = array();
    if ( 'mediamap' === $product ) {
        $png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aT2kAAAAASUVORK5CYII=' );
        $upload = wp_upload_bits( 'scan-' . wp_generate_uuid4() . '.png', null, $png );
        if ( $upload['error'] ) { throw new RuntimeException( $upload['error'] ); }
        $image = wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'post_title' => 'Scanner image', 'post_status' => 'inherit', 'post_author' => $admin ), $upload['file'] );
        update_attached_file( $image, $upload['file'] );
        $urls = $scanner::image_urls( $image );
        $verify( 'Original and root-relative image URLs resolved', count( $urls ) >= 2 );
        $targets[] = $insert( array( 'post_title' => 'Nested image', 'post_content' => '<!-- wp:group --><div><!-- wp:image {"id":' . $image . '} /--></div><!-- /wp:group -->', 'post_status' => 'publish', 'post_author' => $admin ) );
        $targets[] = $insert( array( 'post_title' => 'Classic image', 'post_content' => '<img class="wp-image-' . $image . '" src="' . esc_url( $upload['url'] ) . '?v=2">', 'post_status' => 'draft', 'post_author' => $admin ) );
        $targets[] = $insert( array( 'post_title' => 'Featured image', 'post_content' => 'No image in body', 'post_status' => 'private', 'post_author' => $admin ) );
        set_post_thumbnail( end( $targets ), $image );
        $targets[] = $insert( array( 'post_type' => 'wp_block', 'post_title' => 'Reusable image', 'post_content' => '<!-- wp:gallery {"ids":[' . $image . ']} /-->', 'post_status' => 'publish', 'post_author' => $admin ) );
        $near = $insert( array( 'post_title' => 'Near match', 'post_content' => '<img class="wp-image-' . $image . '7" src="/not-the-image.png">', 'post_status' => 'publish', 'post_author' => $admin ) );
        $request_data = array( 'image_id' => $image );
        $verify( 'Unrelated numeric suffix is not a match', ! $scanner::reasons( get_post( $near ), $image, $urls ) );
        foreach ( array( '<p>Plain mention wp-image-' . $image . '</p>', '<img class="wp-image-' . $image . 'abc">', '<img class="prefix-wp-image-' . $image . '">', '<div class="wp-image-' . $image . '"></div>' ) as $false_class ) {
            $verify( 'Only exact image-element class tokens match: ' . $false_class, ! $scanner::reasons( (object) array( 'ID' => 0, 'post_content' => $false_class ), $image, array() ) );
        }
        $verify( 'Exact IMG class token matches without an image URL', (bool) $scanner::reasons( (object) array( 'ID' => 0, 'post_content' => '<img class="alignwide wp-image-' . $image . ' size-full">' ), $image, array() ) );
    } else {
        $request_data = array( 'blocks' => 'fixture/card', 'shortcodes' => 'fixture_tag' );
        $targets[] = $insert( array( 'post_title' => 'Nested custom block', 'post_content' => '<!-- wp:group --><div><!-- wp:fixture/card /--></div><!-- /wp:group -->', 'post_status' => 'publish', 'post_author' => $admin ) );
        $targets[] = $insert( array( 'post_title' => 'Inactive shortcode', 'post_content' => '[fixture_tag color="blue"]Text[/fixture_tag]', 'post_status' => 'draft', 'post_author' => $admin ) );
        $targets[] = $insert( array( 'post_title' => 'Private shortcode', 'post_content' => '[fixture_tag]', 'post_status' => 'private', 'post_author' => $admin ) );
        $targets[] = $insert( array( 'post_type' => 'wp_block', 'post_title' => 'Reusable custom block', 'post_content' => '<!-- wp:fixture/card /-->', 'post_status' => 'publish', 'post_author' => $admin ) );
        $near = $insert( array( 'post_title' => 'Escaped / near shortcode', 'post_content' => '[[fixture_tag]] [fixture_tag_more] <!-- wp:fixture/cards /-->', 'post_status' => 'publish', 'post_author' => $admin ) );
        $verify( 'Unregistered shortcode syntax is found without executing code', in_array( '[fixture_tag]', $scanner::reasons( '[fixture_tag]', array(), array( 'fixture_tag' ) ), true ) );
        $verify( 'Escaped and prefix-only identifiers are not matches', ! $scanner::reasons( get_post_field( 'post_content', $near ), array( 'fixture/card' ), array( 'fixture_tag' ) ) );
        $verify( 'Array target list refused', is_wp_error( $scanner::identifiers( array( 'fixture/card' ), true ) ) );
        $verify( 'Oversized target list refused', is_wp_error( $scanner::identifiers( implode( ' ', array_fill( 0, 31, 'fixture/card' ) ), true ) ) );
        $verify( 'Malformed block identifier refused', is_wp_error( $scanner::identifiers( 'bad<script>/name', true ) ) );
    }
    for ( $i = 0; $i < 43; $i++ ) { $insert( array( 'post_title' => 'Batch filler ' . $i, 'post_content' => 'Plain content', 'post_status' => 'publish', 'post_author' => $admin ) ); }
    $trash = $insert( array( 'post_title' => 'Trashed target', 'post_content' => 'mediamap' === $product ? '<!-- wp:image {"id":' . $image . '} /-->' : '[fixture_tag]', 'post_status' => 'trash', 'post_author' => $admin ) );
    $cursor = $start; $found = array(); $calls = 0; $done = false; $first = null;
    do {
        $request = new WP_REST_Request( 'GET', '/' . $domain . '/v1/scan' );
        $request->set_query_params( array_merge( $request_data, array( 'after' => $cursor ) ) );
        $response = rest_do_request( $request );
        $data = $response->get_data();
        if ( ! $first ) { $first = $response; }
        if ( 200 !== $response->get_status() ) { throw new RuntimeException( wp_json_encode( $data ) ); }
        foreach ( $data['matches'] as $match ) {
            $verify( 'Result exposes no full content: ' . $match['id'], ! array_key_exists( 'post_content', $match ) && ! array_key_exists( 'content', $match ) );
            $found[] = $match['id'];
        }
        $verify( 'Batch is bounded and cursor advances', $data['scanned'] <= 40 && ( $data['done'] || $data['next'] > $cursor ) );
        $cursor = $data['next']; $done = $data['done']; $calls++;
    } while ( ! $done && $calls < 10 );
    sort( $found ); $expected = $targets; sort( $expected );
    $verify( 'All expected references across multiple batches, no false positives', $done && $calls >= 2 && $found === $expected );
    $verify( 'No duplicate result IDs across batches', count( $found ) === count( array_unique( $found ) ) );
    $verify( 'Private REST result is no-store', false !== strpos( $first->get_headers()['Cache-Control'], 'no-store' ) );
    $verify( 'Content not mutated by scanning', get_post_field( 'post_content', $near ) === ( 'mediamap' === $product ? '<img class="wp-image-' . $image . '7" src="/not-the-image.png">' : '[[fixture_tag]] [fixture_tag_more] <!-- wp:fixture/cards /-->' ) );
    foreach ( array( -1, '1e2', array( 1 ), true, '01', '9999999999999999' ) as $bad ) { $verify( 'Malformed cursor refused: ' . wp_json_encode( $bad ), is_wp_error( $scanner::cursor( $bad ) ) ); }
    foreach ( array( $subscriber, 0 ) as $user ) {
        wp_set_current_user( $user );
        $response = rest_do_request( $request );
        $verify( 'Unprivileged REST read denied: ' . $user, in_array( $response->get_status(), array( 401, 403 ), true ) );
    }
    wp_set_current_user( $manager );
    $response = rest_do_request( $request );
    $verify( 'Settings capability alone cannot disclose other authors content', 'mediamap' === $product ? 400 === $response->get_status() : ( 200 === $response->get_status() && ! $response->get_data()['matches'] ) );
    wp_set_current_user( $admin );
    $request->set_query_params( array_merge( $request_data, array( 'after' => array( 1 ) ) ) );
    $verify( 'Malformed cursor refused through REST boundary', 400 === rest_do_request( $request )->get_status() );

    // Oversized bodies must stay in the database and cannot become false "unused" reports.
    $target_syntax = 'mediamap' === $product ? '<!-- wp:image {"id":' . $image . '} /-->' : '[fixture_tag]';
    $limit_boundary = $insert( array( 'post_title' => 'Byte limit boundary', 'post_content' => $target_syntax . str_repeat( 'x', $scanner::MAX_CONTENT_BYTES - strlen( $target_syntax ) ), 'post_status' => 'publish', 'post_author' => $admin ) );
    $big_targets = array();
    for ( $i = 0; $i < 41; ++$i ) {
        $big_targets[] = $insert( array( 'post_title' => 'Oversized target ' . $i, 'post_content' => $target_syntax . str_repeat( 'x', $scanner::MAX_CONTENT_BYTES + 1 ), 'post_status' => 'publish', 'post_author' => $admin ) );
    }
    foreach ( $big_targets as $big_id ) { wp_cache_delete( $big_id, 'posts' ); }
    $boundary_batch = $scanner::batch( $limit_boundary - 1 );
    $verify( 'Exactly256KiB content remains eligible at the byte boundary', 1 === count( $boundary_batch['posts'] ) && $boundary_batch['posts'][0]->ID == $limit_boundary && $scanner::MAX_CONTENT_BYTES === strlen( $boundary_batch['posts'][0]->post_content ) );
    $request->set_query_params( array_merge( $request_data, array( 'after' => $limit_boundary ) ) );
    $large_response = rest_do_request( $request ); $large = $large_response->get_data();
    $verify( 'All-skipped nonterminal batch advances over 40 visited rows', 200 === $large_response->get_status() && 40 === $large['visited'] && 40 === $large['skipped'] && 0 === $large['scanned'] && ! $large['done'] && $large['next'] === $big_targets[39] );
    $verify( 'Skipped matching records explicitly make the response incomplete', true === $large['incomplete'] && ! $large['matches'] );
    $verify( 'Skipped large bodies are never primed into the full post cache', false === wp_cache_get( $big_targets[0], 'posts' ) && false === wp_cache_get( $big_targets[39], 'posts' ) );
    $request->set_query_params( array_merge( $request_data, array( 'after' => $large['next'] ) ) );
    $last_large = rest_do_request( $request )->get_data();
    $verify( 'Terminal all-skipped batch advances without claiming completeness', $last_large['done'] && 1 === $last_large['visited'] && 1 === $last_large['skipped'] && true === $last_large['incomplete'] && $last_large['next'] === $big_targets[40] );

    // Force an actual SELECT failure; wpdb get_results returns [] here, not null.
    global $wpdb;
    $old_suppress = $wpdb->suppress_errors( true );
    $break_scan = static function ( $query ) use ( $wpdb ) { return str_contains( $query, 'OCTET_LENGTH(post_content)' ) ? 'SELECT ccst_missing_regression_column FROM ' . $wpdb->posts : $query; };
    add_filter( 'query', $break_scan );
    try {
        $request->set_query_params( array_merge( $request_data, array( 'after' => $start ) ) );
        $db_failure = rest_do_request( $request );
        $verify( 'Real database failure returns HTTP503 instead of false empty success', 503 === $db_failure->get_status() );
    } finally { remove_filter( 'query', $break_scan ); $wpdb->suppress_errors( $old_suppress ); }
} finally {
    wp_set_current_user( 0 );
    foreach ( array_reverse( $posts ) as $post ) { wp_delete_post( $post, true ); }
    if ( $image ) { wp_delete_attachment( $image, true ); }
    foreach ( $users as $user ) { wp_delete_user( $user ); }
}
$report = dirname( __DIR__ ) . '/projects/' . $domain . '/reports/0.2.0';
wp_mkdir_p( $report );
$result = array( 'product' => $domain, 'version' => '0.2.0', 'date_utc' => gmdate( 'c' ), 'wp' => get_bloginfo( 'version' ), 'php' => PHP_VERSION, 'checks' => $checks, 'passed' => count( array_filter( $checks, static function ( $check ) { return $check['pass']; } ) ), 'total' => count( $checks ) );
file_put_contents( $report . '/integration-' . get_bloginfo( 'version' ) . '.json', wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
if ( $result['passed'] !== $result['total'] ) { WP_CLI::error( 'Some scanner checks failed.' ); }
WP_CLI::success( $result['passed'] . '/' . $result['total'] . ' behavior checks passed.' );
