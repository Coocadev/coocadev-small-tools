<?php
/** Real WP security and persistence boundaries; synthetic local data only. */
if ( empty( $argv[1] ) || ! preg_match( '~/qa/(?:min-)?sites/dispatchgo$~', str_replace( '\\', '/', $argv[1] ) ) ) { throw new RuntimeException( 'Isolated QA only.' ); }
define( 'WP_USE_THEMES', false );
require rtrim( $argv[1], '/\\' ) . '/wp-load.php';
use Coocadev\DispatchGo\Repository;
use Coocadev\DispatchGo\Plugin;
add_filter( 'pre_wp_mail', '__return_true' );
require_once ABSPATH . 'wp-admin/includes/user.php';
if ( ! class_exists( 'WC_Admin_Settings' ) ) { require WC_ABSPATH . 'includes/admin/class-wc-admin-settings.php'; }
class DispatchGoTestDie extends Error {}
add_filter( 'wp_die_handler', fn() => fn() => throw new DispatchGoTestDie( 'Denied' ) );
$count = 0;
function verify_boundary( $yes, $label ) { global $count; ++$count; if ( ! $yes ) { throw new RuntimeException( 'FAIL ' . $label ); } echo 'PASS ' . $label . PHP_EOL; }
function denied_boundary( $callback, $label ) { try { $callback(); } catch ( DispatchGoTestDie $error ) { verify_boundary( true, $label ); return; } verify_boundary( false, $label ); }
$old = Repository::settings(); $ids = array(); $uid = 0;
try {
    global $current_section; $current_section = 'ccdg'; $_SERVER['REQUEST_METHOD'] = 'POST';
    $uid = wp_insert_user( array( 'user_login' => 'ccdg_boundary_' . wp_generate_password( 8, false ), 'user_pass' => wp_generate_password(), 'user_email' => 'ccdg-boundary@example.invalid', 'role' => 'subscriber' ) );
    if ( is_wp_error( $uid ) ) { throw new RuntimeException( $uid->get_error_message() ); }
    wp_set_current_user( $uid );
    $_POST = array( 'ccdg_nonce' => wp_create_nonce( 'ccdg_save_settings' ) ); $_REQUEST = $_POST;
    denied_boundary( fn() => Plugin::save_settings(), 'subscriber cannot save even with nonce' );
    ( new WP_User( $uid ) )->set_role( 'editor' ); wp_set_current_user( 0 ); wp_set_current_user( $uid );
    denied_boundary( fn() => Plugin::save_settings(), 'editor cannot save' );
    wp_set_current_user( 1 ); $_POST = array(); $_REQUEST = $_POST;
    denied_boundary( fn() => Plugin::save_settings(), 'admin missing nonce denied' );
    $_POST['ccdg_nonce'] = 'wrong'; $_REQUEST = $_POST;
    denied_boundary( fn() => Plugin::save_settings(), 'admin invalid nonce denied' );
    verify_boundary( Repository::settings() === $old, 'unauthorized requests did not change settings' );
    $_POST = array( 'ccdg_nonce' => wp_create_nonce( 'ccdg_save_settings' ), 'ccdg_revision' => (string) $old['revision'], 'ccdg_enabled' => '1', 'ccdg_starts_local' => wp_date( 'Y-m-d\TH:i', time() - 3600 ), 'ccdg_resumes_local_date' => wp_date( 'Y-m-d', time() + 604800 ), 'ccdg_customer_message' => '<b>Boundary notice</b>', 'ccdg_show_cart' => '1' ); $_REQUEST = $_POST;
    Plugin::save_settings();
    $saved = Repository::settings();
    verify_boundary( $saved['revision'] === $old['revision'] + 1 && 'Boundary notice' === $saved['customer_message'], 'authorized save sanitizes and increments revision' );
    Plugin::save_settings(); verify_boundary( Repository::settings() === $saved, 'second admin tab cannot overwrite newer revision' );
    $_POST['ccdg_revision'] = (string) $saved['revision']; $_POST['ccdg_resumes_local_date'] = '2026-02-30'; $_REQUEST = $_POST;
    Plugin::save_settings(); verify_boundary( Repository::settings() === $saved, 'invalid date leaves settings intact' );
    wc_load_cart();
    $product = new WC_Product_Simple(); $product->set_regular_price( 1 ); $product->set_status( 'publish' ); $product->set_name( 'Boundary fixture' ); $pid = $product->save();
    WC()->cart->empty_cart(); WC()->cart->add_to_cart( $pid );
    update_option( 'woocommerce_ship_to_countries', '' ); update_option( 'woocommerce_shipping_cost_requires_address', 'no' );
    $zone = new WC_Shipping_Zone( 0 ); if ( ! $zone->get_shipping_methods() ) { $zone->add_shipping_method( 'flat_rate' ); }
    WC()->session->set( 'chosen_shipping_methods', array( 'flat_rate:1' ) );
    $order = wc_create_order(); $ids[] = $order->get_id();
    $request = new WP_REST_Request( 'POST', '/wc/store/v1/checkout' ); $request->set_param( 'extensions', array( 'ccdg' => array( 'token' => Plugin::preview()['token'] ) ) );
    $skip_ledger = static function( $sql ) { return str_starts_with( $sql, 'INSERT IGNORE INTO' ) && str_contains( $sql, 'ccdg_snapshots' ) ? 'SELECT 1' : $sql; };
    add_filter( 'query', $skip_ledger );
    try { Plugin::blocks_freeze( $order, $request ); verify_boundary( false, 'missing durable receipt blocks checkout' ); }
    catch ( \Automattic\WooCommerce\StoreApi\Exceptions\RouteException $e ) { verify_boundary( 409 === $e->getCode(), 'missing durable receipt blocks checkout' ); }
    finally { remove_filter( 'query', $skip_ledger ); }
    verify_boundary( null === Repository::receipt( $order->get_id() ) && '' === $order->get_meta( '_ccdg_snapshot', true ), 'failed durable write invents no receipt' );
    $skip_meta = static function( $sql ) { return str_starts_with( $sql, 'INSERT INTO' ) && str_contains( $sql, '_ccdg_snapshot' ) ? 'SELECT 1' : $sql; };
    add_filter( 'query', $skip_meta );
    try { Plugin::blocks_freeze( $order, $request ); verify_boundary( false, 'materialization failure blocks checkout' ); }
    catch ( \Automattic\WooCommerce\StoreApi\Exceptions\RouteException $e ) { verify_boundary( true, 'materialization failure blocks checkout' ); }
    finally { remove_filter( 'query', $skip_meta ); }
    $durable = Repository::receipt( $order->get_id() ); verify_boundary( is_array( $durable ), 'first receipt remains durable after metadata failure' );
    Plugin::blocks_freeze( $order, $request );
    verify_boundary( $order->get_meta( '_ccdg_snapshot', true ) === $durable, 'safe retry repairs metadata using same receipt' );
    foreach ( array( 'failed', 'cancelled', 'refunded', 'pending' ) as $status ) { $order->update_status( $status ); verify_boundary( $order->get_meta( '_ccdg_snapshot', true ) === $durable, 'status ' . $status . ' preserves receipt' ); }
    $inactive = wc_create_order(); $ids[] = $inactive->get_id(); WC()->cart->empty_cart(); $request->set_param( 'extensions', array() );
    add_filter( 'query', $skip_ledger ); try { Plugin::blocks_freeze( $inactive, $request ); } finally { remove_filter( 'query', $skip_ledger ); }
    verify_boundary( null === Repository::receipt( $inactive->get_id() ), 'inactive optional audit failure does not block checkout' );
    $prior = Repository::receipt( $order->get_id() ); Repository::install(); verify_boundary( Repository::receipt( $order->get_id() ) === $prior, 'reactivation preserves historical receipt' );
    echo "PASS total {$count}\n";
} finally {
    update_option( 'ccdg_settings', $old ); wp_set_current_user( 1 );
    foreach ( $ids as $id ) { $o = wc_get_order( $id ); if ( $o ) { $o->delete( true ); } }
    if ( isset( $pid ) ) { wp_delete_post( $pid, true ); }
    if ( WC()->cart ) { WC()->cart->empty_cart(); }
    if ( $uid && ! is_wp_error( $uid ) ) { wp_delete_user( $uid ); }
}
