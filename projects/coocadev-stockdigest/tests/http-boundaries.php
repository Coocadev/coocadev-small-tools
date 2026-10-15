<?php
/** Exercise the real admin-post callbacks and their nonce/capability/input boundaries. */
use Coocadev\StockDigest\Plugin;
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() || ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( '127.0.0.1', 'localhost', '::1' ), true ) || ! preg_match( '~/qa/(?:min-)?sites/dispatchgo/?$~', str_replace( '\\', '/', ABSPATH ) ) ) { throw new RuntimeException( 'Isolated local QA only.' ); }
class StockDigestHttpStop extends RuntimeException { public $status; public function __construct( $status ) { $this->status = $status; parent::__construct( 'HTTP stop' ); } }
// The expected successful redirect is intercepted by this harness, not the CLI warning helper.
remove_filter( 'wp_redirect', 'WP_CLI\Utils\wp_redirect_handler' );
add_filter( 'wp_die_handler', static function () { return static function ( $message, $title, $args ) { throw new StockDigestHttpStop( (int) ( $args['response'] ?? 500 ) ); }; }, PHP_INT_MAX );
add_filter( 'wp_redirect', static function ( $url ) { throw new StockDigestHttpStop( 302 ); }, PHP_INT_MAX );
global $checks; $checks = array(); $original = get_option( 'ccsd_settings' ); $pid = 0;
function ccsd_http( $fn, $post, $status, $name, $user = 1, $method = 'POST' ) { global $checks; wp_set_current_user( $user ); $_POST = $post; $_REQUEST = $post; $_SERVER['REQUEST_METHOD'] = $method; try { $fn(); throw new RuntimeException( 'No HTTP boundary' ); } catch ( StockDigestHttpStop $e ) { if ( $e->status !== $status ) { throw new RuntimeException( $name . ': expected ' . $status . ', got ' . $e->status ); } $checks[] = array( 'name' => $name, 'status' => $status, 'pass' => true ); } }
try {
    wp_set_current_user( 1 ); $nonce = wp_create_nonce( 'ccsd_save' ); update_option( 'ccsd_settings', array( 'enabled' => true, 'recipient' => 'before@example.invalid' ), false ); $before = get_option( 'ccsd_settings' );
    foreach ( array( array('1'), 'on', '0', ' 1', false ) as $checkbox ) { ccsd_http( array(Plugin::class,'save'), array( '_wpnonce' => $nonce, 'enabled' => $checkbox, 'recipient' => 'new@example.invalid' ), 400, 'malformed checkbox rejected ' . count($checks) ); if ( $before !== get_option( 'ccsd_settings' ) ) { throw new RuntimeException( 'Malformed checkbox changed settings' ); } }
    ccsd_http( array(Plugin::class,'save'), array( '_wpnonce' => $nonce, 'enabled' => '1', 'recipient' => array('a@b.test') ), 400, 'email array rejected' );
    ccsd_http( array(Plugin::class,'save'), array( '_wpnonce' => $nonce, 'enabled' => '1', 'recipient' => "a@b.test\r\nBcc:x@y.test" ), 400, 'header injection rejected' );
    ccsd_http( array(Plugin::class,'save'), array( 'enabled' => '1', 'recipient' => 'x@example.invalid' ), 403, 'missing nonce rejected' );
    ccsd_http( array(Plugin::class,'save'), array( '_wpnonce' => 'invalid', 'enabled' => '1', 'recipient' => 'x@example.invalid' ), 403, 'invalid nonce rejected' );
    ccsd_http( array(Plugin::class,'save'), array( '_wpnonce' => $nonce ), 403, 'logged-out user rejected', 0 );
    ccsd_http( array(Plugin::class,'save'), array( '_wpnonce' => $nonce ), 403, 'GET mutation rejected', 1, 'GET' );
    ccsd_http( array(Plugin::class,'save'), array( '_wpnonce' => $nonce, 'enabled' => '1', 'recipient' => 'valid@example.invalid' ), 302, 'valid opted-in settings saved' );
    if ( array( 'enabled' => true, 'recipient' => 'valid@example.invalid' ) !== get_option( 'ccsd_settings' ) ) { throw new RuntimeException( 'Valid save did not persist' ); }
    $p = new WC_Product_Simple(); $p->set_name('StockDigest HTTP fixture'); $pid = $p->save(); $nonce = wp_create_nonce( 'ccsd_ordered_' . $pid );
    foreach ( array( '2026-02-31', 'tomorrow', '2099-01-01', "2026-10-15\0", array('date') ) as $date ) { ccsd_http( array(Plugin::class,'ordered'), array('_wpnonce'=>$nonce,'product_id'=>(string)$pid,'until'=>$date),400,'invalid ordered date rejected '.count($checks) ); }
    ccsd_http( array(Plugin::class,'ordered'), array('_wpnonce'=>$nonce,'product_id'=>(string)$pid,'until'=>gmdate('Y-m-d')),302,'valid product suppression saved' );
    if ( (int) wc_get_product($pid)->get_meta('_ccsd_ordered_until',true) <= time() ) { throw new RuntimeException('Suppression was not saved'); }
    $deleted=false;$delete_during_lock=static function($query)use(&$deleted,$pid){if(!$deleted&&str_contains($query,'GET_LOCK(')&&str_contains($query,'ccsd_')){$deleted=true;wp_delete_post($pid,true);}return $query;};add_filter('query',$delete_during_lock);
    try{ccsd_http(array(Plugin::class,'ordered'),array('_wpnonce'=>$nonce,'product_id'=>(string)$pid,'until'=>gmdate('Y-m-d')),409,'product deleted while waiting for lock fails safely');}finally{remove_filter('query',$delete_during_lock);}
    if(!$deleted){throw new RuntimeException('Deletion race fixture did not execute');}
} finally { wp_set_current_user(1); if($pid){wp_delete_post($pid,true);} update_option('ccsd_settings',$original,false); Plugin::deactivate(); Plugin::schedule(); }
echo wp_json_encode(array('plugin'=>'StockDigest','passed'=>count($checks),'tests'=>$checks),JSON_PRETTY_PRINT).PHP_EOL;
