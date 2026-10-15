<?php
/** Local-only, sequential interoperability smoke test of the five installed release ZIPs. */
use Coocadev\ReaderNotes\Database as Notes;
use Coocadev\FileGo\Documents as Documents;
use Coocadev\FileGo\Store as Files;
use Coocadev\UploadGo\PolicyRepository as Policy;
use Coocadev\DoneMark\Settings as Marks;
use Coocadev\DoneMark\States as States;
use Coocadev\DispatchGo\Repository as Dispatch;
use Coocadev\DispatchGo\Schedule as Schedule;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'ccst_combined' !== DB_NAME || '127.0.0.1:3317' !== DB_HOST || 'http://127.0.0.1:8785' !== untrailingslashit( home_url() ) ) {
    throw new RuntimeException( 'This fixture is restricted to the dedicated local combined QA database and URL.' );
}
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
global $wpdb;
$GLOBALS['ccst_combined_checks'] = array();

function ccst_combined_check( string $name, bool $pass, array $detail = array() ): void {
    $GLOBALS['ccst_combined_checks'][] = array( 'name' => $name, 'pass' => $pass, 'detail' => $detail );
    WP_CLI::log( ( $pass ? 'PASS ' : 'FAIL ' ) . $name . ( $detail ? ' ' . wp_json_encode( $detail ) : '' ) );
}
function ccst_combined_require( $result, string $operation ) {
    if ( is_wp_error( $result ) ) {
        throw new RuntimeException( $operation . ': ' . $result->get_error_code() . ' ' . $result->get_error_message() );
    }
    return $result;
}
function ccst_combined_auth( int $id ): array {
    $expires = time() + HOUR_IN_SECONDS;
    $token = WP_Session_Tokens::get_instance( $id )->create( $expires );
    $logged = wp_generate_auth_cookie( $id, $expires, 'logged_in', $token );
    $auth = wp_generate_auth_cookie( $id, $expires, 'auth', $token );
    wp_set_current_user( $id );
    $_COOKIE[ LOGGED_IN_COOKIE ] = $logged;
    return array( 'id' => $id, 'logged' => $logged, 'cookie' => LOGGED_IN_COOKIE . '=' . $logged . '; ' . AUTH_COOKIE . '=' . $auth );
}
function ccst_combined_nonce( array $auth, string $action ): string {
    wp_set_current_user( $auth['id'] );
    $_COOKIE[ LOGGED_IN_COOKIE ] = $auth['logged'];
    return wp_create_nonce( $action );
}
function ccst_combined_http( string $url, ?array $auth = null, ?array $fields = null, bool $rest = false ): array {
    if ( '127.0.0.1' !== wp_parse_url( $url, PHP_URL_HOST ) || 8785 !== wp_parse_url( $url, PHP_URL_PORT ) ) {
        throw new RuntimeException( 'Only the dedicated local QA server may receive fixture requests.' );
    }
    $headers = array();
    $curl = curl_init( $url );
    $request_headers = array( 'Origin: http://127.0.0.1:8785' );
    if ( $auth && $rest ) {
        $request_headers[] = 'X-WP-Nonce: ' . ccst_combined_nonce( $auth, 'wp_rest' );
    }
    curl_setopt_array( $curl, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $request_headers,
        CURLOPT_HEADERFUNCTION => static function ( $handle, string $line ) use ( &$headers ): int {
            $parts = explode( ':', $line, 2 );
            if ( count( $parts ) === 2 && in_array( strtolower( trim( $parts[0] ) ), array( 'cache-control', 'content-type', 'location' ), true ) ) {
                $headers[ strtolower( trim( $parts[0] ) ) ] = trim( $parts[1] );
            }
            return strlen( $line );
        },
    ) );
    if ( $auth ) { curl_setopt( $curl, CURLOPT_COOKIE, $auth['cookie'] ); }
    if ( null !== $fields ) { curl_setopt( $curl, CURLOPT_POSTFIELDS, $fields ); }
    $body = curl_exec( $curl );
    $code = (int) curl_getinfo( $curl, CURLINFO_RESPONSE_CODE );
    $error = curl_error( $curl );
    curl_close( $curl );
    if ( false === $body ) { throw new RuntimeException( 'Local HTTP failed: ' . $error ); }
    return array( 'status' => $code, 'body' => $body, 'headers' => $headers, 'json' => json_decode( $body, true ) );
}
function ccst_combined_rest_url( string $route ): string {
    return add_query_arg( 'rest_route', $route, home_url( '/' ) );
}
function ccst_combined_pdf(): string {
    $content = 'BT /F1 12 Tf 50 750 Td (Combined FileGo and UploadGo fixture) Tj ET';
    $objects = array(
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        '<< /Length ' . strlen( $content ) . " >>\nstream\n" . $content . "\nendstream",
    );
    $pdf = "%PDF-1.4\n";
    $offsets = array();
    foreach ( $objects as $i => $object ) {
        $offsets[] = strlen( $pdf );
        $pdf .= ( $i + 1 ) . " 0 obj\n" . $object . "\nendobj\n";
    }
    $xref = strlen( $pdf );
    $pdf .= "xref\n0 6\n0000000000 65535 f \n";
    foreach ( $offsets as $offset ) { $pdf .= sprintf( '%010d 00000 n ', $offset ) . "\n"; }
    return $pdf . "trailer\n<< /Root 1 0 R /Size 6 >>\nstartxref\n" . $xref . "\n%%EOF\n";
}

$slugs = array( 'coocadev-reader-notes', 'coocadev-filego', 'coocadev-uploadgo', 'coocadev-donemark', 'coocadev-dispatchgo' );
foreach ( $slugs as $slug ) {
    $active = is_plugin_active( $slug . '/' . $slug . '.php' );
    ccst_combined_check( 'Installed release is jointly active: ' . $slug, $active );
    if ( ! $active ) { WP_CLI::error( 'Activate every release ZIP before running this fixture in a fresh CLI process.' ); }
}
if ( ! class_exists( 'WC_Order' ) ) { WP_CLI::error( 'WooCommerce must already be active.' ); }
ccst_combined_check( 'WooCommerce is active with all five plugins', true, array( 'wordpress' => get_bloginfo( 'version' ), 'woocommerce' => WC_VERSION, 'php' => PHP_VERSION ) );
$saved_options = array();
foreach ( array( 'ccug_settings', 'ccdm_settings', 'ccdg_settings' ) as $key ) {
    $saved_options[ $key ] = get_option( $key, null );
}
$run = 'ccst-' . strtolower( wp_generate_password( 8, false ) );
$users = array();
$posts = array();
$document_id = 0;
$order = null;
$pdf_path = __DIR__ . '/results/' . $run . '.pdf';
$failure = null;
try {
    $admin_id = ccst_combined_require( wp_insert_user( array( 'user_login' => $run . '_admin', 'user_pass' => wp_generate_password( 40, true ), 'user_email' => $run . '_admin@example.test', 'role' => 'administrator' ) ), 'Create synthetic admin' );
    $users[] = $admin_id;
    $reader_id = ccst_combined_require( wp_insert_user( array( 'user_login' => $run . '_reader', 'user_pass' => wp_generate_password( 40, true ), 'user_email' => $run . '_reader@example.test', 'role' => 'subscriber' ) ), 'Create synthetic reader' );
    $users[] = $reader_id;
    $admin = ccst_combined_auth( $admin_id );
    $reader = ccst_combined_auth( $reader_id );
    wp_set_current_user( $admin_id );
    $post_id = ccst_combined_require( wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_author' => $admin_id, 'post_title' => $run . ' shared article', 'post_content' => '<p>Public article.</p>[ccrn_note]' ), true ), 'Create shared article' );
    $posts[] = $post_id;
    $plain_id = ccst_combined_require( wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_author' => $admin_id, 'post_title' => $run . ' plain page', 'post_content' => '<p>Public page without plugin widgets.</p>' ), true ), 'Create plain page' );
    $posts[] = $plain_id;
    $private = 'Private note sentinel ' . $run;
    $note = ccst_combined_require( Notes::save( $reader_id, $post_id, $private, 0, (int) Notes::state( $reader_id )['epoch'] ), 'Seed owned note' );
    ccst_combined_check( 'Reader Notes stores the reader-owned note', $note['content'] === $private && (int) $note['user_id'] === $reader_id );

    $settings = Marks::read();
    $definitions = $settings['definitions'];
    $definitions[] = array( 'id' => '', 'label' => $run . ' review', 'type' => 'review', 'post_types' => array( 'post' ), 'archived' => false );
    $settings = ccst_combined_require( Marks::save( $definitions, (int) $settings['revision'], (bool) $settings['remove_data_on_uninstall'] ), 'Create independent mark definition' );
    $definition = end( $settings['definitions'] );
    $view = ccst_combined_require( States::view( $post_id ), 'Read editor marks' );
    $changed = ccst_combined_require( States::write( $post_id, $definition['id'], true, 0, $view['content_hash'] ), 'Mark shared article reviewed' );
    $mark = array_values( array_filter( $changed['marks'], static fn( array $item ): bool => $item['id'] === $definition['id'] ) )[0];
    ccst_combined_check( 'DoneMark state coexists on the same article', true === $mark['done'] && false === $mark['stale'] && 1 === $mark['version'] );
    ccst_combined_check( 'DoneMark mutation leaves the private note unchanged', Notes::by_post( $reader_id, $post_id ) === $note );

    $page = ccst_combined_http( add_query_arg( 'p', $post_id, home_url( '/' ) ) );
    ccst_combined_check( 'Public article renders Reader Notes shell and local assets', 200 === $page['status'] && str_contains( $page['body'], 'ccrn-shell' ) && str_contains( $page['body'], '/coocadev-reader-notes/assets/notes.js' ) );
    ccst_combined_check( 'Public article does not contain private note or DoneMark data', ! str_contains( $page['body'], $private ) && ! str_contains( $page['body'], $definition['id'] ) && ! str_contains( $page['body'], $definition['label'] ) );
    $signed_page = ccst_combined_http( add_query_arg( 'p', $post_id, home_url( '/' ) ), $reader );
    ccst_combined_check( 'Authenticated HTML also keeps note content out of the page response', 200 === $signed_page['status'] && str_contains( $signed_page['body'], 'ccrn-shell' ) && ! str_contains( $signed_page['body'], $private ) );
    $plain = ccst_combined_http( add_query_arg( 'page_id', $plain_id, home_url( '/' ) ) );
    $unexpected = array_values( array_filter( $slugs, static fn( string $slug ): bool => (bool) preg_match( '~(?:src|href)=["\'][^"\']*/' . preg_quote( $slug, '~' ) . '/assets/~', $plain['body'] ) ) );
    ccst_combined_check( 'Plain public page loads no assets from any of the five plugins', 200 === $plain['status'] && ! $unexpected, array( 'unexpected' => $unexpected ) );
    $foreign_assets = array_values( array_filter( array_diff( $slugs, array( 'coocadev-reader-notes' ) ), static fn( string $slug ): bool => (bool) preg_match( '~(?:src|href)=["\'][^"\']*/' . preg_quote( $slug, '~' ) . '/assets/~', $page['body'] ) ) );
    ccst_combined_check( 'Reader Notes page does not enqueue other plugins admin/checkout assets', ! $foreign_assets, array( 'unexpected' => $foreign_assets ) );

    foreach ( array( '/coocadev-reader-notes/v1/notes', '/coocadev-donemark/v1/posts/' . $post_id . '/marks' ) as $route ) {
        $guest = ccst_combined_http( ccst_combined_rest_url( $route ), null, null, true );
        ccst_combined_check( 'Private REST denies guest and is no-store: ' . $route, in_array( $guest['status'], array( 401, 403 ), true ) && str_contains( strtolower( $guest['headers']['cache-control'] ?? '' ), 'no-store' ) && ! str_contains( $guest['body'], $private ), array( 'status' => $guest['status'] ) );
    }
    $own = ccst_combined_http( ccst_combined_rest_url( '/coocadev-reader-notes/v1/notes' ), $reader, null, true );
    ccst_combined_check( 'Reader cookie/REST nonce retrieves only its own private note', 200 === $own['status'] && count( $own['json']['notes'] ?? array() ) === 1 && str_contains( $own['body'], $private ) && str_contains( strtolower( $own['headers']['cache-control'] ?? '' ), 'no-store' ), array( 'status' => $own['status'] ) );
    $forbidden = ccst_combined_http( ccst_combined_rest_url( '/coocadev-donemark/v1/posts/' . $post_id . '/marks' ), $reader, null, true );
    ccst_combined_check( 'Reader identity does not acquire DoneMark editor permissions', 403 === $forbidden['status'], array( 'status' => $forbidden['status'] ) );
    $marks = ccst_combined_http( ccst_combined_rest_url( '/coocadev-donemark/v1/posts/' . $post_id . '/marks' ), $admin, null, true );
    ccst_combined_check( 'Editor REST route remains independently functional and private', 200 === $marks['status'] && str_contains( $marks['body'], $definition['id'] ) && str_contains( strtolower( $marks['headers']['cache-control'] ?? '' ), 'no-store' ), array( 'status' => $marks['status'] ) );
    $other = ccst_combined_http( ccst_combined_rest_url( '/coocadev-reader-notes/v1/notes/' . $note['id'] ), $admin, null, true );
    ccst_combined_check( 'Editor/admin identity cannot read another reader note via private REST', 404 === $other['status'] && ! str_contains( $other['body'], $private ), array( 'status' => $other['status'] ) );

    wp_set_current_user( $admin_id );
    $policy = Policy::read();
    $policy['enabled'] = true;
    $policy['global_policy'] = Policy::empty_policy();
    $policy['global_policy']['types_enabled'] = true;
    $policy['global_policy']['types'] = array( 'pdf' );
    $policy['global_policy']['max_bytes'] = 1024 * 1024;
    $policy['role_policies'] = array();
    $policy = ccst_combined_require( Policy::save( $policy, $policy['config_revision'] ), 'Enable PDF policy' );
    $document_id = ccst_combined_require( Documents::create( $run . ' public PDF', $run . '-pdf' ), 'Create logical FileGo document' );
    if ( false === file_put_contents( $pdf_path, ccst_combined_pdf() ) ) { throw new RuntimeException( 'Cannot write local PDF fixture.' ); }
    $fields = array( 'action' => 'ccfg_upload', 'document' => (string) $document_id, '_wpnonce' => ccst_combined_nonce( $admin, 'ccfg_upload_' . $document_id ), 'note' => 'Synthetic combined compatibility test', 'ccfg_pdf' => new CURLFile( $pdf_path, 'application/pdf', 'combined-fixture.pdf' ) );
    $upload = ccst_combined_http( admin_url( 'admin-post.php' ), $admin, $fields );
    $versions = Files::versions( $document_id, 1 );
    ccst_combined_check( 'Real multipart FileGo PDF upload succeeds with UploadGo allowing PDF', 302 === $upload['status'] && 1 === count( $versions ) && str_contains( $upload['headers']['location'] ?? '', 'ccfg_saved=1' ), array( 'status' => $upload['status'], 'versions' => count( $versions ) ) );
    if ( 1 !== count( $versions ) ) { throw new RuntimeException( 'No staged version after allowed PDF upload.' ); }
    $version = $versions[0];
    $document = Files::document( $document_id );
    ccst_combined_require( Documents::publish( $document_id, (int) $version->id, (int) $document->revision ), 'Publish staged PDF' );
    $published = Files::document( $document_id );
    $target = ccst_combined_require( Coocadev\FileGo\Plugin::target( $run . '-pdf' ), 'Resolve published target' );
    ccst_combined_check( 'Published FileGo pointer resolves its approved real PDF', (int) $published->current_version_id === (int) $version->id && 'application/pdf' === get_post_mime_type( (int) $version->attachment_id ) && is_file( get_attached_file( (int) $version->attachment_id ) ) );
    $policy['global_policy']['types'] = array( 'images' );
    ccst_combined_require( Policy::save( $policy, $policy['config_revision'] ), 'Disallow new PDF uploads' );
    $denied = ccst_combined_http( admin_url( 'admin-post.php' ), $admin, $fields );
    $after = Files::document( $document_id );
    ccst_combined_check( 'UploadGo PDF restriction is enforced through real FileGo admin-post upload', in_array( $denied['status'], array( 400, 403 ), true ), array( 'status' => $denied['status'] ) );
    ccst_combined_check( 'Rejected replacement leaves published pointer, revision and version count unchanged', $after->current_version_id === $published->current_version_id && $after->revision === $published->revision && count( Files::versions( $document_id, 1 ) ) === 1 );
    ccst_combined_check( 'New-upload restriction does not revoke an existing public FileGo document', Coocadev\FileGo\Plugin::target( $run . '-pdf' ) === $target );
    $download = ccst_combined_http( $target );
    ccst_combined_check( 'Existing public PDF remains downloadable with its original bytes', 200 === $download['status'] && hash( 'sha256', ccst_combined_pdf() ) === hash( 'sha256', $download['body'] ), array( 'status' => $download['status'] ) );

    $schedule = Dispatch::defaults();
    $schedule['enabled'] = true;
    $schedule['starts_at_utc'] = time() - 60;
    $schedule['resumes_at_utc'] = time() + DAY_IN_SECONDS;
    $schedule['resumes_local_date'] = gmdate( 'Y-m-d', time() + DAY_IN_SECONDS );
    $schedule['saved_timezone'] = 'UTC';
    $schedule['customer_message'] = 'Combined QA dispatch notice';
    $terms = Schedule::terms( $schedule, true, array( 'flat_rate:1' ), time() );
    $order = ccst_combined_require( wc_create_order( array( 'status' => 'pending', 'customer_id' => $reader_id, 'created_via' => 'ccst_combined_qa' ) ), 'Create synthetic unpaid order' );
    $candidate = array( 'version' => 1, 'terms' => $terms, 'campaign_revision' => 0, 'captured_at_utc' => gmdate( 'Y-m-d\TH:i:s\Z' ), 'source' => 'combined-fixture', 'locale_at_checkout' => determine_locale() );
    $receipt = Dispatch::freeze( $order, $candidate );
    ccst_combined_check( 'DispatchGo freezes its own order receipt while all other plugins are active', true === $terms['applicable'] && $receipt === Dispatch::receipt( $order->get_id() ) && $receipt === $order->get_meta( '_ccdg_snapshot', true ) );
    ccst_combined_check( 'Commerce and upload mutations leave reader note and editorial state unchanged', Notes::by_post( $reader_id, $post_id ) === $note && States::view( $post_id ) === $changed );
    $order_id = $order->get_id();
    $order->delete( true );
    $order = null;
    ccst_combined_check( 'Woo permanent order deletion clears only its DispatchGo receipt', null === Dispatch::receipt( $order_id ) && Notes::by_post( $reader_id, $post_id ) === $note );
} catch ( Throwable $error ) {
    $failure = $error->getMessage();
    ccst_combined_check( 'Fixture reached completion without an unexpected exception', false, array( 'error' => $failure ) );
} finally {
    if ( isset( $admin_id ) && is_int( $admin_id ) ) { wp_set_current_user( $admin_id ); }
    if ( $order instanceof WC_Order ) { $order->delete( true ); }
    if ( $document_id ) {
        $document = Files::document( $document_id );
        if ( $document && $document->current_version_id ) { Documents::unpublish( $document_id, (int) $document->revision ); }
        foreach ( Files::versions( $document_id, 1 ) as $version ) {
            $document = Files::document( $document_id );
            Documents::delete_version( $document_id, (int) $version->id, (int) $document->revision );
        }
        // Remove only this fixture's now-unpublished logical row after the public deletion service.
        $wpdb->delete( Files::table( 'documents' ), array( 'id' => $document_id ), array( '%d' ) );
    }
    foreach ( $posts as $id ) { wp_delete_post( $id, true ); }
    foreach ( array_reverse( $users ) as $id ) { wp_delete_user( $id ); }
    foreach ( $saved_options as $key => $value ) {
        if ( null === $value ) { delete_option( $key ); } else { update_option( $key, $value ); }
        wp_cache_delete( $key, 'options' );
    }
    if ( is_file( $pdf_path ) ) { unlink( $pdf_path ); }
    ccst_combined_check( 'Fixture restores all three saved settings records', get_option( 'ccug_settings', null ) === $saved_options['ccug_settings'] && get_option( 'ccdm_settings', null ) === $saved_options['ccdm_settings'] && get_option( 'ccdg_settings', null ) === $saved_options['ccdg_settings'] );
}
$checks = $GLOBALS['ccst_combined_checks'];
$failed = array_values( array_filter( $checks, static fn( array $check ): bool => ! $check['pass'] ) );
$report = array( 'generated_at_utc' => gmdate( 'c' ), 'environment' => array( 'wordpress' => get_bloginfo( 'version' ), 'woocommerce' => WC_VERSION, 'php' => PHP_VERSION, 'database' => DB_NAME, 'url' => home_url() ), 'total' => count( $checks ), 'failed' => count( $failed ), 'checks' => $checks );
file_put_contents( __DIR__ . '/results/combined-smoke.json', wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
WP_CLI::log( wp_json_encode( array( 'total' => count( $checks ), 'failed' => count( $failed ) ) ) );
if ( $failed ) { WP_CLI::halt( 1 ); }
