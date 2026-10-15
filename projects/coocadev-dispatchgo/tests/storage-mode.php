<?php
/** Isolated QA storage selection. Does not synchronize production orders. */
if ( empty( $argv[1] ) || ! preg_match( '~/qa/(?:min-)?sites/dispatchgo$~', str_replace( '\\', '/', $argv[1] ) ) || ! in_array( $argv[2] ?? '', array( 'hpos', 'legacy' ), true ) ) { throw new RuntimeException( 'Isolated QA only.' ); }
require rtrim( $argv[1], '/\\' ) . '/wp-load.php';
$sync = wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class );
while ( $batch = $sync->get_next_batch_to_process( 50 ) ) { $sync->process_batch( $batch ); }
update_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'no' );
update_option( 'woocommerce_custom_orders_table_enabled', 'hpos' === $argv[2] ? 'yes' : 'no' );
echo 'Next request storage=' . $argv[2] . ', sync=off' . PHP_EOL;
