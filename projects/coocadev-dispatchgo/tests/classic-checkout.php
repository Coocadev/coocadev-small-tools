<?php
/** Actual classic Woo checkout flow, using only offline COD and synthetic data. */
if ( empty( $argv[1] ) || ! preg_match( '~/qa/(?:min-)?sites/dispatchgo$~', str_replace( '\\', '/', $argv[1] ) ) ) { throw new RuntimeException( 'Isolated QA only.' ); }
define( 'DOING_AJAX', true ); define( 'WP_USE_THEMES', false );
require rtrim( $argv[1], '/\\' ) . '/wp-load.php';
use Coocadev\DispatchGo\Repository;
use Coocadev\DispatchGo\Plugin;
class DispatchGoJsonDone extends Error {}
add_filter( 'pre_wp_mail', '__return_true' );
add_filter( 'wp_die_ajax_handler', fn() => fn() => throw new DispatchGoJsonDone() );
wp_set_current_user( 0 ); wc_load_cart();
update_option( 'woocommerce_enable_guest_checkout', 'yes' );
update_option( 'woocommerce_cod_settings', array( 'enabled' => 'yes', 'enable_for_virtual' => 'yes' ) );
update_option( 'woocommerce_default_country', 'US:NY' ); update_option( 'woocommerce_ship_to_countries', '' ); update_option( 'woocommerce_shipping_cost_requires_address', 'no' );
$zone = new WC_Shipping_Zone( 0 ); if ( ! $zone->get_shipping_methods() ) { $zone->add_shipping_method( 'flat_rate' ); } delete_transient( 'wc_shipping_method_count' );
$product = new WC_Product_Simple(); $product->set_name( 'Classic QA fixture' ); $product->set_status( 'publish' ); $product->set_regular_price( 1 ); $pid = $product->save();
$old = Repository::settings(); $orders = array(); $processed = false;
$settings = Repository::defaults(); $settings['enabled'] = true; $settings['starts_at_utc'] = time() - 3600; $settings['resumes_at_utc'] = time() + 604800; $settings['resumes_local_date'] = wp_date( 'Y-m-d', time() + 604800 ); $settings['customer_message'] = 'Classic original'; update_option( 'ccdg_settings', $settings );
WC()->cart->empty_cart(); WC()->cart->add_to_cart( $pid );
WC()->customer->set_shipping_country( 'US' ); WC()->customer->set_shipping_state( 'NY' ); WC()->customer->set_shipping_postcode( '10001' ); WC()->cart->calculate_totals();
add_action( 'woocommerce_checkout_order_created', function( $order ) use ( &$orders ) { $orders[] = $order->get_id(); }, 0 );
add_action( 'woocommerce_checkout_order_processed', function( $id, $posted, $order ) use ( &$processed ) { if ( 'Classic revised' !== ( $order->get_meta( '_ccdg_snapshot', true )['terms']['message_plain'] ?? '' ) ) { throw new RuntimeException( 'Missing snapshot before payment.' ); } $processed = true; }, 5, 3 );
function classic_post( $token ) {
    wc_clear_notices();
    $_POST = array( 'woocommerce-process-checkout-nonce' => wp_create_nonce( 'woocommerce-process_checkout' ), 'billing_first_name' => 'Synthetic', 'billing_last_name' => 'Guest', 'billing_country' => 'US', 'billing_address_1' => '1 Test Avenue', 'billing_city' => 'New York', 'billing_state' => 'NY', 'billing_postcode' => '10001', 'billing_phone' => '2025550100', 'billing_email' => 'ccdg-classic@example.invalid', 'payment_method' => 'cod', 'shipping_method' => WC()->session->get( 'chosen_shipping_methods' ), 'terms' => '1', 'terms-field' => '1', 'ccdg_preview_token' => $token );
    $_REQUEST = $_POST;
    ob_start(); try { WC()->checkout()->process_checkout(); } catch ( DispatchGoJsonDone $done ) {} finally { $output = ob_get_clean(); }
    $json = json_decode( $output, true ); if ( ! is_array( $json ) ) { throw new RuntimeException( 'Unexpected checkout response: ' . substr( strip_tags( $output ), 0, 200 ) ); }
    return $json;
}
try {
    $token = Plugin::preview()['token']; $settings['customer_message'] = 'Classic revised'; update_option( 'ccdg_settings', $settings );
    $stale = classic_post( $token );
    if ( 'failure' !== ( $stale['result'] ?? '' ) || $processed || $orders ) { throw new RuntimeException( 'Stale terms reached order/payment.' ); }
    echo "PASS real classic stale preview stops before order/payment\n";
    $fresh = classic_post( Plugin::preview()['token'] );
    if ( 'success' !== ( $fresh['result'] ?? '' ) || ! $processed || ! $orders ) { throw new RuntimeException( 'Fresh classic checkout failed: ' . strip_tags( $fresh['messages'] ?? '' ) ); }
    $order = wc_get_order( end( $orders ) );
    if ( ! $order->has_status( 'processing' ) || ! Repository::receipt( $order->get_id() ) ) { throw new RuntimeException( 'Expected offline processing order with receipt.' ); }
    echo "PASS real classic guest checkout freezes before offline COD, then processing\n";
} finally {
    update_option( 'ccdg_settings', $old ); WC()->cart->empty_cart();
    foreach ( $orders as $id ) { $order = wc_get_order( $id ); if ( $order ) { $order->delete( true ); } }
    wp_delete_post( $pid, true );
}
