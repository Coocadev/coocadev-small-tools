<?php
if ( empty( $argv[1] ) || ! str_contains( str_replace( '\\', '/', $argv[1] ), '/qa/sites/dispatchgo' ) ) { throw new RuntimeException( 'Isolated QA only.' ); }
require rtrim( $argv[1], '/\\' ) . '/wp-load.php';
use Coocadev\DispatchGo\Repository;
$s = Repository::defaults(); $s['enabled'] = true; $s['starts_local'] = gmdate( 'Y-m-d\TH:i', time() - 3600 ); $s['starts_at_utc'] = time() - 3600; $s['resumes_at_utc'] = time() + 604800; $s['resumes_local_date'] = gmdate( 'Y-m-d', time() + 604800 ); $s['customer_message'] = 'Our dispatch team is on a short break. Thank you for your patience.'; update_option( 'ccdg_settings', $s );
$product = new WC_Product_Simple(); $product->set_name( 'DispatchGo synthetic demonstration product' ); $product->set_status( 'publish' ); $product->set_regular_price( 1 ); $id = $product->save();
echo 'Product=' . $id . PHP_EOL;
echo 'Checkout=' . wc_get_checkout_url() . PHP_EOL;
$classic = wp_insert_post( array( 'post_title' => 'Classic QA checkout', 'post_content' => '[woocommerce_checkout]', 'post_type' => 'page', 'post_status' => 'publish' ) );
echo 'Classic=' . get_permalink( $classic ) . PHP_EOL;
