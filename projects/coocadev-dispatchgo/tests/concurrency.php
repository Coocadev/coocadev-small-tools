<?php
if ( empty( $argv[1] ) || ! str_contains( str_replace( '\\', '/', $argv[1] ), '/qa/sites/dispatchgo' ) ) { throw new RuntimeException( 'Isolated QA only.' ); }
require rtrim( $argv[1], '/\\' ) . '/wp-load.php';
use Coocadev\DispatchGo\Repository;
add_filter( 'pre_wp_mail', '__return_true' );
$mode = $argv[2] ?? '';
if ( 'create' === $mode ) { $order = wc_create_order(); echo $order->get_id(); exit; }
$id = (int) ( $argv[3] ?? 0 );
$order = wc_get_order( $id );
if ( ! $order ) { throw new RuntimeException( 'Missing QA order.' ); }
if ( 'worker' === $mode ) {
    $candidate = array( 'version' => 1, 'terms' => array( 'applicable' => true, 'resumes_local_date' => '2026-11-01', 'timezone' => 'UTC', 'message_plain' => 'Concurrent receipt test' ), 'campaign_revision' => 1, 'captured_at_utc' => gmdate( 'c' ), 'source' => 'blocks', 'locale_at_checkout' => 'en_US' );
    Repository::freeze( $order, $candidate );
    echo "PASS concurrent freeze\n"; exit;
}
if ( 'verify' === $mode ) {
    $receipt = Repository::receipt( $id );
    $rows = array_filter( $order->get_meta_data(), fn( $meta ) => '_ccdg_snapshot' === $meta->key );
    if ( ! $receipt || count( $rows ) !== 1 || $order->get_meta( '_ccdg_snapshot', true ) !== $receipt ) { throw new RuntimeException( 'Concurrency invariant broken.' ); }
    echo "PASS one immutable ledger winner and one matching Woo meta row\n";
    $order->delete( true );
}
