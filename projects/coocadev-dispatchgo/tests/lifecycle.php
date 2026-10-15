<?php
/** Uninstall and dependency preservation against isolated fixtures. */
if ( empty( $argv[1] ) || ! preg_match( '~/qa/(?:min-)?sites/dispatchgo$~', str_replace( '\\', '/', $argv[1] ) ) ) { throw new RuntimeException( 'Isolated QA only.' ); }
require rtrim( $argv[1], '/\\' ) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
use Coocadev\DispatchGo\Repository;
use Coocadev\DispatchGo\Plugin;
$old = Repository::settings(); $order = wc_create_order();
add_filter( 'pre_wp_mail', '__return_true' );
try {
    $candidate = array( 'version' => 1, 'terms' => array( 'applicable' => false, 'resumes_local_date' => '', 'timezone' => '', 'message_plain' => '' ), 'campaign_revision' => 0, 'captured_at_utc' => gmdate( 'c' ), 'source' => 'classic', 'locale_at_checkout' => 'en_US' );
    Repository::freeze( $order, $candidate );
    $order->update_meta_data( '_synthetic_foreign_plugin_meta', 'untouched foreign fixture' ); $order->save_meta_data();
    deactivate_plugins( 'coocadev-dispatchgo/coocadev-dispatchgo.php', true );
    if ( is_plugin_active( 'coocadev-dispatchgo/coocadev-dispatchgo.php' ) || Repository::settings() !== $old || Repository::receipt( $order->get_id() ) !== $candidate ) { throw new RuntimeException( 'Deactivation changed data.' ); }
    echo "PASS real deactivation preserves settings and receipts\n";
    $activation = activate_plugin( 'coocadev-dispatchgo/coocadev-dispatchgo.php' );
    if ( is_wp_error( $activation ) || ! is_plugin_active( 'coocadev-dispatchgo/coocadev-dispatchgo.php' ) || Repository::receipt( $order->get_id() ) !== $candidate ) { throw new RuntimeException( 'Reactivation failed.' ); }
    echo "PASS real reactivation restores plugin without rewriting receipt\n";
    $keep = $old; $keep['uninstall_remove_settings'] = false; update_option( 'ccdg_settings', $keep );
    define( 'WP_UNINSTALL_PLUGIN', 'coocadev-dispatchgo/coocadev-dispatchgo.php' );
    include __DIR__ . '/../plugin/uninstall.php';
    if ( get_option( 'ccdg_settings' ) !== $keep || Repository::receipt( $order->get_id() ) !== $candidate ) { throw new RuntimeException( 'Keep policy failed.' ); }
    echo "PASS default uninstall retains settings and receipts\n";
    $remove = $keep; $remove['uninstall_remove_settings'] = true; update_option( 'ccdg_settings', $remove );
    include __DIR__ . '/../plugin/uninstall.php';
    if ( false !== get_option( 'ccdg_settings', false ) || Repository::receipt( $order->get_id() ) !== $candidate ) { throw new RuntimeException( 'Selective removal failed.' ); }
    $order->read_meta_data( true ); if ( $candidate !== $order->get_meta( '_ccdg_snapshot', true ) ) { throw new RuntimeException( 'Order history changed.' ); }
    echo "PASS opted-in uninstall removes only configuration, not order history\n";
    $reloaded = wc_get_order( $order->get_id() );
    if ( ! $reloaded || 'untouched foreign fixture' !== $reloaded->get_meta( '_synthetic_foreign_plugin_meta', true ) ) { throw new RuntimeException( 'Uninstall changed Woo order or foreign metadata.' ); }
    echo "PASS Woo order and foreign plugin metadata survive both uninstall modes\n";
    Repository::install(); if ( Repository::settings()['enabled'] || Repository::receipt( $order->get_id() ) !== $candidate ) { throw new RuntimeException( 'Reinstall failed.' ); }
    echo "PASS reinstall is inactive and preserves historical ledger\n";
} finally { update_option( 'ccdg_settings', $old ); if ( ! is_plugin_active( 'coocadev-dispatchgo/coocadev-dispatchgo.php' ) ) { activate_plugin( 'coocadev-dispatchgo/coocadev-dispatchgo.php' ); } $order->delete( true ); }
