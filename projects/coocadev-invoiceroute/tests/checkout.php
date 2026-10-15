<?php
/** Exercise actual classic or Store API checkout using a synthetic virtual item and offline COD. */
use Coocadev\InvoiceRoute\Plugin;
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() || ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( '127.0.0.1', 'localhost', '::1' ), true ) || ! preg_match( '~/qa/(?:min-)?sites/dispatchgo/?$~', str_replace( '\\', '/', ABSPATH ) ) || ! in_array( $args[0] ?? '', array( 'classic', 'blocks' ), true ) ) { throw new RuntimeException( 'Reserved local WP-CLI QA only.' ); }
if ( ! defined( 'DOING_AJAX' ) ) { define( 'DOING_AJAX', true ); }
class InvoiceRouteJsonEnd extends Error {}
add_filter( 'pre_wp_mail', '__return_true', PHP_INT_MAX );
add_filter( 'wp_die_ajax_handler', static function () { return static function () { throw new InvoiceRouteJsonEnd(); }; } );
// Only remove unrelated DispatchGo adapters in this PHP process; its saved settings/source are untouched.
remove_action( 'woocommerce_after_checkout_validation', array( 'Coocadev\DispatchGo\Plugin', 'classic_validate' ), 10 );
remove_action( 'woocommerce_checkout_order_created', array( 'Coocadev\DispatchGo\Plugin', 'classic_freeze' ), 1 );
remove_action( 'woocommerce_store_api_checkout_update_order_from_request', array( 'Coocadev\DispatchGo\Plugin', 'blocks_freeze' ), 1 );
$old = array(); foreach ( array( 'woocommerce_cod_settings', 'woocommerce_enable_guest_checkout', 'woocommerce_default_country' ) as $key ) { $old[$key] = get_option( $key ); }
update_option( 'woocommerce_cod_settings', array( 'enabled' => 'yes', 'title' => 'Offline synthetic QA', 'enable_for_virtual' => 'yes' ) );
update_option( 'woocommerce_enable_guest_checkout', 'yes' ); update_option( 'woocommerce_default_country', 'US:NY' );
wp_set_current_user( 0 ); wc_load_cart();
$product = new WC_Product_Simple(); $product->set_name( 'InvoiceRoute checkout synthetic item' ); $product->set_status( 'publish' ); $product->set_regular_price( '1' ); $product->set_virtual( true ); $pid = $product->save();
$orders = array(); $seen = false; $mode = $args[0];
add_action( 'woocommerce_checkout_order_created', static function ( $order ) use ( &$orders ) { $orders[] = $order->get_id(); }, 0 );
add_action( 'woocommerce_store_api_checkout_order_processed', static function ( $order ) use ( &$orders ) { $orders[] = $order->get_id(); }, 0 );
$verify = static function ( $order ) use ( &$seen ) { if ( 'checkout-accounts@example.invalid' !== Plugin::receipt( $order->get_id() ) ) { throw new RuntimeException( 'Receipt was not durable before payment.' ); } $seen = true; };
add_action( 'woocommerce_checkout_order_processed', static function ( $id, $data, $order ) use ( $verify ) { $verify( $order ); }, 20, 3 );
add_action( 'woocommerce_store_api_checkout_order_processed', $verify, 20 );
try {
    WC()->cart->empty_cart(); WC()->cart->add_to_cart( $pid ); WC()->customer->set_billing_country( 'US' ); WC()->customer->set_billing_state( 'NY' ); WC()->customer->set_billing_postcode( '10001' ); WC()->cart->calculate_totals();
    $address = array( 'first_name' => 'Synthetic', 'last_name' => 'Purchaser', 'company' => '', 'address_1' => '1 Test Avenue', 'address_2' => '', 'city' => 'New York', 'state' => 'NY', 'postcode' => '10001', 'country' => 'US', 'email' => 'checkout-buyer@example.invalid', 'phone' => '2025550100' );
    if ( 'classic' === $mode ) {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = array( 'woocommerce-process-checkout-nonce' => wp_create_nonce( 'woocommerce-process_checkout' ), 'payment_method' => 'cod', 'terms' => '1', 'terms-field' => '1', 'ccir_accounting_email' => 'checkout-accounts@example.invalid' );
        foreach ( $address as $key => $value ) { $_POST['billing_' . $key] = $value; }
        $_REQUEST = $_POST; wc_clear_notices();
        ob_start(); try { WC()->checkout()->process_checkout(); } catch ( InvoiceRouteJsonEnd $end ) {} finally { $body = ob_get_clean(); }
        $response = json_decode( $body, true );
        if ( 'success' !== ( $response['result'] ?? '' ) || ! $seen ) { throw new RuntimeException( 'Classic checkout failed: ' . strip_tags( $body ) ); }
    } else {
        $request = new WP_REST_Request( 'POST', '/wc/store/v1/checkout' ); $request->set_header( 'Nonce', wp_create_nonce( 'wc_store_api' ) );
        $request->set_param( 'billing_address', $address ); $request->set_param( 'shipping_address', $address ); $request->set_param( 'payment_method', 'cod' );
        $request->set_param( 'additional_fields', array( 'coocadev-invoiceroute/accounting-email' => 'not-an-email' ) );
        $invalid = rest_do_request( $request ); if ( $invalid->get_status() < 400 || $seen ) { throw new RuntimeException( 'Native Blocks validation failed to reject malformed email before payment.' ); }
        $request->set_param( 'additional_fields', array( 'coocadev-invoiceroute/accounting-email' => 'checkout-accounts@example.invalid' ) );
        $response = rest_do_request( $request ); if ( 200 !== $response->get_status() || ! $seen ) { throw new RuntimeException( 'Blocks checkout failed: ' . wp_json_encode( $response->get_data() ) ); }
        $orders[] = $response->get_data()['order_id'];
    }
    $order = wc_get_order( end( $orders ) );
    if ( ! $order || ! $order->has_status( 'processing' ) || 'checkout-buyer@example.invalid' !== $order->get_billing_email() || 'checkout-accounts@example.invalid' !== Plugin::receipt( $order->get_id() ) ) { throw new RuntimeException( 'Final native checkout order is inconsistent.' ); }
    echo wp_json_encode( array( 'plugin' => 'InvoiceRoute', 'checkout' => $mode, 'pass' => true, 'receipt_before_payment' => $seen, 'status' => $order->get_status(), 'wp' => get_bloginfo( 'version' ), 'woo' => WC_VERSION, 'php' => PHP_VERSION, 'hpos' => \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ), JSON_PRETTY_PRINT ) . PHP_EOL;
} finally {
    WC()->cart->empty_cart(); foreach ( array_unique( $orders ) as $id ) { $order = wc_get_order( $id ); if ( $order ) { $order->delete( true ); } }
    wp_delete_post( $pid, true ); foreach ( $old as $key => $value ) { if ( false === $value ) { delete_option( $key ); } else { update_option( $key, $value ); } }
}
