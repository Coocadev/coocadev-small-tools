<?php
// Run only on the isolated local fixture: php integration.php [wp-load.php].
require $argv[1] ?? __DIR__ . '/../../../qa/sites/uploadgo/wp-load.php';
set_exception_handler( static function ( Throwable $error ): void { fwrite( STDERR, $error->getMessage() . "\n" ); exit( 1 ); } );
if ( 'local' !== wp_get_environment_type() || ! str_contains( home_url(), '127.0.0.1' ) ) { throw new RuntimeException( 'Local fixture required' ); }
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
use Coocadev\UploadGo\PolicyRepository as Repo;
use Coocadev\UploadGo\PolicyResolver as Resolver;
use Coocadev\UploadGo\FileInspector as Inspector;
use Coocadev\UploadGo\UploadHooks as Hooks;

$checks = array();
function verify( string $name, $condition ): void {
	global $checks;
	if ( ! $condition ) { throw new RuntimeException( 'FAIL: ' . $name ); }
	$checks[] = $name;
	echo "PASS $name\n";
}
function policy( array $values ): array { return array_merge( Repo::empty_policy(), $values ); }
function save_settings( array $settings ): array {
	$current = Repo::read();
	$settings['config_revision'] = $current['config_revision'];
	$result = Repo::save( $settings, $current['config_revision'] );
	if ( is_wp_error( $result ) ) { throw new RuntimeException( $result->get_error_message() ); }
	return $result;
}
$old = Repo::read();
$owner = wp_get_current_user();
$tmp = dirname( __DIR__, 3 ) . '/qa/results/uploadgo-fixtures';
wp_mkdir_p( $tmp );
$png = $tmp . '/valid.png';
$image = imagecreatetruecolor( 40, 30 ); imagepng( $image, $png ); unset( $image );
$jpeg = $tmp . '/oriented.jpg';
$image = imagecreatetruecolor( 40, 30 ); imagejpeg( $image, $jpeg ); unset( $image );
$jpeg_data = file_get_contents( $jpeg );
$exif = "Exif\0\0II" . pack( 'vVv', 42, 8, 1 ) . pack( 'vvVv', 0x0112, 3, 1, 6 ) . "\0\0" . pack( 'V', 0 );
file_put_contents( $jpeg, substr( $jpeg_data, 0, 2 ) . "\xFF\xE1" . pack( 'n', strlen( $exif ) + 2 ) . $exif . substr( $jpeg_data, 2 ) );
$pdf = $tmp . '/valid.pdf'; file_put_contents( $pdf, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n%%EOF\n" );
$renamed = $tmp . '/fake.pdf'; copy( $png, $renamed );
$html = $tmp . '/bad.pdf'; file_put_contents( $html, '<html><script>alert(1)</script></html>' );
$empty = $tmp . '/empty.png'; file_put_contents( $empty, '' );
$file = array( 'name' => 'valid.png', 'tmp_name' => $png, 'size' => 1, 'type' => 'application/pdf', 'error' => 0 );
$editor = wp_insert_user( array( 'user_login' => 'ccug_test_' . wp_generate_password( 8, false ), 'role' => 'editor', 'user_pass' => wp_generate_password( 32 ) ) );
if ( is_wp_error( $editor ) ) { throw new RuntimeException( $editor->get_error_message() ); }
$attachment = 0;
try {
	wp_set_current_user( $editor );
	save_settings( Repo::defaults() );
	verify( 'disabled defaults impose no policy', null === Resolver::current() );
	verify( 'empty uploads stay with core when disabled', Hooks::upload( array( 'error' => 0 ) ) === array( 'error' => 0 ) );
	$s = Repo::defaults(); $s['enabled'] = true;
	$s['global_policy'] = policy( array( 'max_bytes' => 20000, 'types_enabled' => true, 'types' => array( 'images', 'pdf' ) ) );
	$s['role_policies'] = array( 'editor' => policy( array( 'max_bytes' => 10000, 'max_width' => 40 ) ), 'author' => policy( array( 'max_bytes' => 12000, 'types_enabled' => true, 'types' => array( 'pdf' ) ) ) );
	$s = save_settings( $s );
	$a = Resolver::resolve( $s, array( 'editor', 'author' ), true );
	$b = Resolver::resolve( $s, array( 'author', 'editor' ), true );
	verify( 'role order independent restrictive intersection', $a === $b && 10000 === $a['max_bytes'] && array( 'pdf' ) === $a['types'] );
	verify( 'unknown role falls back to global', 20000 === Resolver::resolve( $s, array( 'new_role' ), true )['max_bytes'] );
	verify( 'background default is core-only', null === Resolver::resolve( $s, array(), false ) );
	$s['background_mode'] = true;
	verify( 'explicit background mode applies global only', 20000 === Resolver::resolve( $s, array( 'editor' ), false )['max_bytes'] );
	verify( 'admin has no implicit bypass', 20000 === Resolver::resolve( $s, array( 'administrator' ), true )['max_bytes'] );
	$stale = Repo::save( $s, $s['config_revision'] - 1 );
	verify( 'stale revision rejected', is_wp_error( $stale ) && 'ccug_conflict' === $stale->get_error_code() );
	$invalid = $s; $invalid['global_policy']['max_bytes'] = '100';
	verify( 'numeric strings rejected in repository', is_wp_error( Repo::validate( $invalid ) ) );
	$invalid = $s; $invalid['enabled'] = 1;
	verify( 'switches require real booleans', is_wp_error( Repo::validate( $invalid ) ) );
	$invalid = $s; $invalid['unknown'] = true;
	verify( 'unknown settings rejected', is_wp_error( Repo::validate( $invalid ) ) );
	$invalid = $s; $invalid['global_policy']['types'] = array( 'svg' );
	verify( 'unsafe/unknown file families rejected', is_wp_error( Repo::validate( $invalid ) ) );
	verify( 'valid PNG exact dimension boundary', true === Inspector::inspect( $file, policy( array( 'max_width' => 40, 'max_height' => 30, 'max_pixels' => 1200 ) ) ) );
	verify( 'EXIF rotation uses visually oriented sides without PHP EXIF', true === Inspector::inspect( array( 'name' => 'oriented.jpg', 'tmp_name' => $jpeg ), policy( array( 'max_width' => 30, 'max_height' => 40 ) ) ) );
	verify( 'rotated JPEG cannot bypass visual height limit', is_wp_error( Inspector::inspect( array( 'name' => 'oriented.jpg', 'tmp_name' => $jpeg ), policy( array( 'max_height' => 39 ) ) ) ) );
	verify( 'width one pixel above boundary rejected', is_wp_error( Inspector::inspect( $file, policy( array( 'max_width' => 39 ) ) ) ) );
	verify( 'height boundary enforced', is_wp_error( Inspector::inspect( $file, policy( array( 'max_height' => 29 ) ) ) ) );
	verify( 'pixel boundary enforced without multiplication overflow', is_wp_error( Inspector::inspect( $file, policy( array( 'max_pixels' => 1199 ) ) ) ) );
	$bytes = filesize( $png );
	verify( 'exact bytes accepted', true === Inspector::inspect( $file, policy( array( 'max_bytes' => $bytes ) ) ) );
	verify( 'client size/type cannot bypass actual bytes', is_wp_error( Inspector::inspect( $file, policy( array( 'max_bytes' => $bytes - 1 ) ) ) ) );
	verify( 'renamed PNG rejected as PDF', is_wp_error( Inspector::inspect( array( 'name' => 'fake.pdf', 'tmp_name' => $renamed ), Repo::empty_policy() ) ) );
	verify( 'HTML disguised as PDF rejected', is_wp_error( Inspector::inspect( array( 'name' => 'bad.pdf', 'tmp_name' => $html ), Repo::empty_policy() ) ) );
	verify( 'valid PDF accepted', true === Inspector::inspect( array( 'name' => 'valid.pdf', 'tmp_name' => $pdf ), Repo::empty_policy() ) );
	verify( 'empty file rejected', is_wp_error( Inspector::inspect( array( 'name' => 'empty.png', 'tmp_name' => $empty ), Repo::empty_policy() ) ) );
	verify( 'URL/stream temp path rejected', is_wp_error( Inspector::inspect( array( 'name' => 'x.png', 'tmp_name' => 'php://memory' ), Repo::empty_policy() ) ) );
	verify( 'array path rejected', is_wp_error( Inspector::inspect( array( 'name' => 'x.png', 'tmp_name' => array() ), Repo::empty_policy() ) ) );
	verify( 'enabled empty allowlist denies files', is_wp_error( Inspector::inspect( $file, policy( array( 'types_enabled' => true ) ) ) ) );
	verify( 'upstream error retained unchanged', 'earlier rejection' === Hooks::upload( array_merge( $file, array( 'error' => 'earlier rejection' ) ) )['error'] );
	verify( 'upload size filter never increases core maximum', 50 === Hooks::size( 50 ) );
	verify( 'MIME filter cannot introduce new formats', array( 'pdf' => 'application/pdf' ) === Hooks::mimes( array( 'pdf' => 'application/pdf', 'svg' => 'image/svg+xml' ) ) );
	$s['global_policy'] = policy( array( 'max_width' => 39 ) ); $s['role_policies'] = array(); $s['background_mode'] = false;
	save_settings( $s );
	verify( 'upload hook rejects oversized dimensions', is_string( Hooks::upload( $file )['error'] ) );
	verify( 'sideload hook enforces same policy', is_string( Hooks::sideload( $file )['error'] ) );
	wp_set_current_user( 0 );
	verify( 'no user uses explicitly disabled background policy', 0 === Hooks::sideload( $file )['error'] );
	wp_set_current_user( $editor );
	verify( 'changing current user refreshes policy', is_string( Hooks::sideload( $file )['error'] ) );
	$s['global_policy'] = policy( array( 'max_width' => 40 ) ); save_settings( $s );
	$copy = $tmp . '/sideload.png'; copy( $png, $copy );
	$attachment = media_handle_sideload( array( 'name' => 'sideload.png', 'tmp_name' => $copy, 'error' => 0, 'size' => filesize( $copy ) ), 0 );
	verify( 'real media_handle_sideload saves acceptable file', is_int( $attachment ) && file_exists( get_attached_file( $attachment ) ) );
	$s['global_policy'] = policy( array( 'max_width' => 39 ) ); save_settings( $s );
	$copy = $tmp . '/rejected.png'; copy( $png, $copy );
	verify( 'real media_handle_sideload rejects violating file', is_wp_error( media_handle_sideload( array( 'name' => 'rejected.png', 'tmp_name' => $copy, 'error' => 0, 'size' => filesize( $copy ) ), 0 ) ) );
	$request = new WP_REST_Request( 'POST', '/wp/v2/media/' . $attachment . '/finalize' );
	$request->set_param( 'sub_sizes', array() );
	verify( 'finalize reevaluates changed policy on real original file', is_wp_error( Hooks::finalize( null, $request, '', array() ) ) );
	$s['global_policy'] = policy( array( 'max_width' => 40 ) ); save_settings( $s );
	verify( 'finalize accepts compliant original', null === Hooks::finalize( null, $request, '', array() ) );
	$request->set_param( 'sub_sizes', array( array( 'file' => wp_basename( get_attached_file( $attachment ) ), 'image_size' => 'thumbnail', 'width' => 1, 'height' => 1, 'filesize' => 1, 'mime_type' => 'text/html' ) ) );
	verify( 'finalize normalizes client metadata to file facts', null === Hooks::finalize( null, $request, '', array() ) && 40 === $request->get_param( 'sub_sizes' )[0]['width'] && 30 === $request->get_param( 'sub_sizes' )[0]['height'] && 'image/png' === $request->get_param( 'sub_sizes' )[0]['mime_type'] && $request->get_param( 'sub_sizes' )[0]['filesize'] > 1 );
	$request->set_param( 'sub_sizes', array( array( 'file' => '../other.png' ) ) );
	verify( 'finalize rejects foreign/traversal metadata', is_wp_error( Hooks::finalize( null, $request, '', array() ) ) );
	$user = new WP_User( $editor ); $user->remove_cap( 'upload_files' ); $user->add_cap( 'upload_files', false );
	wp_set_current_user( 0 ); wp_set_current_user( $editor );
	$request->set_param( 'sub_sizes', array() );
	verify( 'finalize rejects revoked upload capability', is_wp_error( Hooks::finalize( null, $request, '', array() ) ) );
	$user->remove_cap( 'upload_files' );
	delete_option( 'ccug_diagnostics' ); Hooks::record( 'test', 'upload' );
	verify( 'diagnostics off does not write logs', false === get_option( 'ccug_diagnostics', false ) );
	$s['diagnostics_enabled'] = true; save_settings( $s );
	for ( $i = 0; $i < 110; $i++ ) { Hooks::record( 'ccug_size', 'sideload' ); }
	$logs = get_option( 'ccug_diagnostics' );
	verify( 'diagnostics bounded to 100 code/channel/time records', count( $logs ) === 100 && array_keys( $logs[0] ) === array( 'time', 'code', 'channel' ) );
	update_option( 'ccug_diagnostics', array( array( 'time' => time() - 90000, 'code' => 'expired', 'channel' => 'upload' ) ), false );
	Hooks::record( 'ccug_type', 'upload' );
	verify( 'old diagnostics pruned on next event', 1 === count( get_option( 'ccug_diagnostics' ) ) );
	update_option( 'ccug_diagnostics', array( array( 'time' => time() - 90000, 'code' => 'expired', 'channel' => 'upload' ) ), false );
	do_action( 'ccug_prune_diagnostics' );
	verify( 'scheduled cleanup physically removes expired logs', false === get_option( 'ccug_diagnostics', false ) );
	verify( 'options.php cannot bypass CAS handler', 'do_not_allow' === apply_filters( 'option_page_capability_ccug', 'manage_options' ) );
	do_action( 'wp_enqueue_scripts' );
	verify( 'no frontend assets enqueued', ! wp_script_is( 'ccug-media' ) && ! wp_style_is( 'ccug-admin' ) );
	echo wp_json_encode( array( 'passed' => count( $checks ), 'checks' => $checks ), JSON_PRETTY_PRINT ) . "\n";
} finally {
	save_settings( $old );
	if ( is_int( $attachment ) && $attachment > 0 ) { wp_delete_attachment( $attachment, true ); }
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $editor );
	wp_set_current_user( $owner->ID );
	delete_option( 'ccug_diagnostics' );
	wp_clear_scheduled_hook( 'ccug_prune_diagnostics' );
}
