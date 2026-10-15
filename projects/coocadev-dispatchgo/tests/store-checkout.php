<?php
/** Exercise real Store API route and an offline gateway, never a real payment. */
if ( empty( $argv[1] ) || ! preg_match( '~/qa/(?:min-)?sites/dispatchgo$~', str_replace( '\\', '/', $argv[1] ) ) ) { throw new RuntimeException( 'Isolated QA only.' ); }
define( 'WP_USE_THEMES', false );
require rtrim( $argv[1], '/\\' ) . '/wp-load.php';
add_filter( 'pre_wp_mail', '__return_true' );
use Coocadev\DispatchGo\Repository;
use Coocadev\DispatchGo\Plugin;
wp_set_current_user( 1 ); wc_load_cart();
update_option( 'woocommerce_cod_settings', array( 'enabled' => 'yes', 'title' => 'Offline QA only', 'enable_for_virtual' => 'yes' ) );
update_option( 'woocommerce_default_country', 'US:NY' );
update_option( 'woocommerce_ship_to_countries', '' );
update_option( 'woocommerce_shipping_cost_requires_address', 'no' );
$zone = new WC_Shipping_Zone( 0 ); if ( ! $zone->get_shipping_methods() ) { $zone->add_shipping_method( 'flat_rate' ); }
$product = new WC_Product_Simple(); $product->set_name( 'DispatchGo Store API fixture' ); $product->set_status( 'publish' ); $product->set_regular_price( 1 ); $product_id = $product->save();
$old_settings = Repository::settings();
$s = Repository::defaults(); $s['enabled'] = true; $s['starts_at_utc'] = time() - 3600; $s['resumes_at_utc'] = time() + 604800; $s['resumes_local_date'] = gmdate( 'Y-m-d', time() + 604800 ); $s['customer_message'] = 'Store API original notice'; update_option( 'ccdg_settings', $s );
WC()->cart->empty_cart(); WC()->cart->add_to_cart( $product_id );
$address = array( 'first_name' => 'Synthetic', 'last_name' => 'Customer', 'company' => '', 'address_1' => '1 Test Avenue', 'address_2' => '', 'city' => 'New York', 'state' => 'NY', 'postcode' => '10001', 'country' => 'US', 'email' => 'dispatchgo@example.invalid', 'phone' => '2025550100' );
WC()->customer->set_billing_country( 'US' ); WC()->customer->set_billing_state( 'NY' ); WC()->customer->set_billing_postcode( '10001' );
WC()->customer->set_shipping_country( 'US' ); WC()->customer->set_shipping_state( 'NY' ); WC()->customer->set_shipping_postcode( '10001' );
WC()->cart->calculate_totals();
$payment_seen = false;
$orders = array();
add_action( 'woocommerce_store_api_checkout_order_processed', function( $order ) use ( &$payment_seen, &$orders ) { $orders[] = $order->get_id(); $receipt = $order->get_meta( '_ccdg_snapshot', true ); if ( ! is_array( $receipt ) || 'Store API revised notice' !== $receipt['terms']['message_plain'] ) { throw new RuntimeException( 'Snapshot missing before payment.' ); } $payment_seen = true; } );
function send_checkout( $address, $token ) {
    $request = new WP_REST_Request( 'POST', '/wc/store/v1/checkout' );
    $request->set_header( 'Nonce', wp_create_nonce( 'wc_store_api' ) );
    $request->set_param( 'billing_address', $address ); $request->set_param( 'shipping_address', $address );
    $request->set_param( 'payment_method', 'cod' ); $request->set_param( 'extensions', array( 'ccdg' => array( 'token' => $token ) ) );
    return rest_do_request( $request );
}
try {
    $token = Plugin::preview()['token'];
    $s['customer_message'] = 'Store API revised notice'; update_option( 'ccdg_settings', $s );
    $first = send_checkout( $address, $token );
    echo 'Stale checkout response: ' . $first->get_status() . ' ' . ( $first->get_data()['code'] ?? '' ) . PHP_EOL;
    if ( $payment_seen || 409 !== $first->get_status() ) { throw new RuntimeException( 'Expected stale preview rejection before payment.' ); }
    echo "PASS stale preview is rejected by real Store API before gateway\n";
    $second = send_checkout( $address, Plugin::preview()['token'] );
    echo 'Fresh checkout response: ' . $second->get_status() . ' ' . ( $second->get_data()['status'] ?? '' ) . PHP_EOL;
    if ( 200 !== $second->get_status() || ! $payment_seen ) { throw new RuntimeException( 'Real checkout not successful.' ); }
    $order_id = $second->get_data()['order_id']; $orders[] = $order_id;
    $order = wc_get_order( $order_id );
    if ( ! $order->has_status( 'processing' ) || ! Repository::receipt( $order_id ) ) { throw new RuntimeException( 'Expected offline processing order with receipt.' ); }
    echo "PASS real Blocks checkout saved snapshot before offline payment and reached processing\n";
} finally {
    update_option( 'ccdg_settings', $old_settings ); WC()->cart->empty_cart();
    foreach ( array_unique( $orders ) as $id ) { $order = wc_get_order( $id ); if ( $order ) { $order->delete( true ); } }
    wp_delete_post( $product_id, true );
}
