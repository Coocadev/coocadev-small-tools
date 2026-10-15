<?php
/** Real WordPress/WooCommerce behavior, isolated fixtures and intercepted mail. */
use Coocadev\InvoiceRoute\Plugin;
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() || ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( '127.0.0.1', 'localhost', '::1' ), true ) || ! preg_match( '~/qa/(?:min-)?sites/dispatchgo/?$~', str_replace( '\\', '/', ABSPATH ) ) ) { throw new RuntimeException( 'Isolated local QA only.' ); }
global $checks; $checks = array(); $orders = array(); $mail = array();
function ccir_check( $pass, $name ) { global $checks; $checks[] = array( 'name' => $name, 'pass' => (bool) $pass ); if ( ! $pass ) { throw new RuntimeException( $name ); } }
$sink = static function ( $return, $args ) use ( &$mail ) { $mail[] = $args; return true; };
add_filter( 'pre_wp_mail', $sink, PHP_INT_MAX, 2 );
try {
    ccir_check( '' === Plugin::validate( '' ) && 'accounts@example.invalid' === Plugin::validate( 'accounts@example.invalid' ), 'optional blank and valid accounting address accepted' );
    foreach ( array( array( 'x' ), null, 'a@b.com,b@c.com', "a@b.com\r\nBcc: victim@example.invalid", '<script>x</script>', str_repeat( 'x', 255 ) ) as $bad ) { ccir_check( is_wp_error( Plugin::validate( $bad ) ), 'reject malformed accounting input ' . count( $checks ) ); }
    $order = wc_create_order(); $orders[] = $order->get_id(); $order->set_billing_email( 'buyer@example.invalid' ); $order->save();
    Plugin::classic_prepare( $order, array( 'ccir_accounting_email' => 'accounts@example.invalid' ) ); $order->save(); Plugin::classic_freeze( $order );
    ccir_check( 'accounts@example.invalid' === Plugin::receipt( $order->get_id() ), 'classic checkout freezes address after order ID exists' );
    ccir_check( 'accounts@example.invalid' === Plugin::freeze( $order, 'different@example.invalid' ), 'repeated checkout cannot replace immutable receipt' );
    $order->update_meta_data( '_ccir_accounting_email', 'tampered@example.invalid' ); $order->save();
    $emails = WC()->mailer()->get_emails();
    $mail = array(); $emails['WC_Email_Customer_Invoice']->trigger( $order->get_id(), $order );
    ccir_check( 1 === count( $mail ) && array( 'accounts@example.invalid' ) === (array) $mail[0]['to'], 'native manual invoice routes to authoritative receipt despite tampered order meta' );
    $mail = array(); $emails['WC_Email_Customer_Processing_Order']->trigger( $order->get_id(), $order );
    ccir_check( 1 === count( $mail ) && array( 'buyer@example.invalid' ) === (array) $mail[0]['to'], 'processing email remains addressed to purchaser' );
    $mail = array(); $emails['WC_Email_Customer_Completed_Order']->trigger( $order->get_id(), $order );
    ccir_check( 1 === count( $mail ) && array( 'buyer@example.invalid' ) === (array) $mail[0]['to'], 'completed email remains addressed to purchaser' );
    ccir_check( 'buyer@example.invalid' === $order->get_billing_email(), 'billing identity is never rewritten' );
    $blank = wc_create_order(); $orders[] = $blank->get_id(); $blank->set_billing_email( 'blank-buyer@example.invalid' ); $blank->save();
    ccir_check( '' === Plugin::freeze( $blank, '' ) && '' === Plugin::freeze( $blank, 'later@example.invalid' ), 'blank choice is also immutable' );
    $mail = array(); $emails['WC_Email_Customer_Invoice']->trigger( $blank->get_id(), $blank );
    ccir_check( 1 === count( $mail ) && array( 'blank-buyer@example.invalid' ) === (array) $mail[0]['to'], 'blank invoice keeps original purchaser recipient' );
    $blocks = wc_create_order( array( 'status' => 'checkout-draft' ) ); $orders[] = $blocks->get_id();
    $blocks->update_meta_data( '_wc_other/coocadev-invoiceroute/accounting-email', 'block-accounts@example.invalid' ); $blocks->save();
    ccir_check( null === Plugin::receipt( $blocks->get_id() ), 'editable Blocks draft is not frozen prematurely' );
    Plugin::block_freeze( $blocks );
    ccir_check( 'block-accounts@example.invalid' === Plugin::receipt( $blocks->get_id() ), 'final Blocks processing freezes native additional field' );
    $error = new WP_Error(); Plugin::classic_validate( array( 'ccir_accounting_email' => array( 'bad' ) ), $error );
    ccir_check( $error->has_errors(), 'classic validation rejects structured input before checkout' );
    ccir_check( is_wp_error( Plugin::block_validate( 'not-an-email' ) ), 'native Blocks validator returns field error' );
    ccir_check( 1 === count( Plugin::export( 'accounts@example.invalid' )['data'] ) && ! Plugin::export( 'stranger@example.invalid' )['data'], 'Core privacy export matches the accounting address only' );
    ccir_check( Plugin::erase( 'accounts@example.invalid' )['items_retained'], 'privacy eraser explicitly reports retained commercial receipt' );
    $id = $blocks->get_id(); $blocks->delete( true );
    ccir_check( null === Plugin::receipt( $id ), 'permanent order deletion cleans own receipt' );
    ccir_check( is_wp_error( Plugin::freeze( $blocks, 'orphan@example.invalid' ) ), 'deleted order cannot recreate an orphan receipt' );
} finally {
    foreach ( $orders as $id ) { $o = wc_get_order( $id ); if ( $o ) { $o->delete( true ); } }
    remove_filter( 'pre_wp_mail', $sink, PHP_INT_MAX );
}
echo wp_json_encode( array( 'plugin' => 'InvoiceRoute', 'version' => '0.2.0', 'environment' => array( 'wp' => get_bloginfo( 'version' ), 'woo' => WC_VERSION, 'php' => PHP_VERSION, 'hpos' => \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ), 'passed' => count( $checks ), 'tests' => $checks ), JSON_PRETTY_PRINT ) . PHP_EOL;
