<?php
/** Run with wp eval-file on the isolated dispatchgo QA installation only. */
use Coocadev\StockDigest\Plugin;
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() || ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( '127.0.0.1', 'localhost', '::1' ), true ) || ! preg_match( '~/qa/(?:min-)?sites/dispatchgo/?$~', str_replace( '\\', '/', ABSPATH ) ) ) { throw new RuntimeException( 'Isolated local QA only.' ); }
global $checks; $checks = array(); $ids = array(); $mail = array(); $fail_mail = false;
$old_settings = get_option( 'ccsd_settings' ); $old_run = get_option( 'ccsd_run' );
function ccsd_check( $pass, $name ) { global $checks; $checks[] = array( 'name' => $name, 'pass' => (bool) $pass ); if ( ! $pass ) { throw new RuntimeException( $name ); } }
$sink = static function ( $return, $args ) use ( &$mail, &$fail_mail ) { $mail[] = $args; return ! $fail_mail; };
add_filter( 'pre_wp_mail', $sink, PHP_INT_MAX, 2 );
try {
    update_option( 'ccsd_settings', array( 'enabled' => false, 'recipient' => 'merchant@example.invalid' ), false );
    ccsd_check( 'disabled' === Plugin::run()['status'] && ! $mail, 'disabled is silent and sends nothing' );
    foreach ( array( 'low' => 0, 'healthy' => 100, 'ordered' => 0 ) as $name => $quantity ) {
        $p = new WC_Product_Simple(); $p->set_name( 'StockDigest QA ' . $name ); $p->set_status( 'publish' ); $p->set_regular_price( '1' ); $p->set_manage_stock( true ); $p->set_stock_quantity( $quantity );
        if ( 'ordered' === $name ) { $p->update_meta_data( '_ccsd_ordered_until', time() + DAY_IN_SECONDS ); }
        $p->save(); $ids[] = $p->get_id();
        ccsd_check( ( 'low' === $name ) === Plugin::needs_stock( $p ), $name . ' stock selection obeys Woo threshold and suppression' );
    }
    $mail = array(); update_option( 'ccsd_settings', array( 'enabled' => true, 'recipient' => 'merchant@example.invalid' ), false ); delete_option( 'ccsd_run' );
    do { $state = Plugin::run(); } while ( 'scanning' === $state['status'] );
    ccsd_check( 'sent' === $state['status'] && 1 === count( $mail ), 'daily batch sends one digest' );
    ccsd_check( 'merchant@example.invalid' === $mail[0]['to'], 'only explicit configured recipient receives digest' );
    ccsd_check( str_contains( $mail[0]['message'], 'StockDigest QA low' ) && ! str_contains( $mail[0]['message'], 'StockDigest QA healthy' ) && ! str_contains( $mail[0]['message'], 'StockDigest QA ordered' ), 'digest excludes healthy and ordered products' );
    $state2 = Plugin::run(); ccsd_check( $state2 === $state && 1 === count( $mail ), 'same-day retry cannot resend' );
    delete_option( 'ccsd_run' ); $mail = array(); $fail_mail = true;
    do { $state = Plugin::run(); } while ( 'scanning' === $state['status'] );
    ccsd_check( 'mail_failed' === $state['status'], 'failed mail is explicitly recorded' );
    Plugin::run(); ccsd_check( 1 === count( $mail ), 'ambiguous mail failure is not retried automatically' );
    $fail_mail = false; $mail = array();
    for ( $i = 0; $i < 101; ++$i ) { $p = new WC_Product_Simple(); $p->set_name( 'StockDigest bounded QA ' . $i ); $p->set_status( 'publish' ); $p->set_regular_price( '1' ); $p->save(); $ids[] = $p->get_id(); }
    delete_option( 'ccsd_run' ); $state = Plugin::run();
    ccsd_check( 'scanning' === $state['status'] && 2 === $state['page'] && ! $mail, 'one invocation scans at most one 100-product page' );
    ccsd_check( false !== wp_next_scheduled( 'ccsd_continue' ), 'continuation is scheduled without immediate blocking loop' );
    do { $state = Plugin::run(); } while ( 'scanning' === $state['status'] );
    ccsd_check( 'sent' === $state['status'] && 1 === count( $mail ), 'paged run still sends exactly once' );
    ccsd_check( 1 === count( Plugin::export( 'merchant@example.invalid' )['data'] ) && ! Plugin::export( 'someone-else@example.invalid' )['data'], 'privacy exporter matches recipient only' );
    ccsd_check( Plugin::erase( 'merchant@example.invalid' )['items_removed'] && ! Plugin::settings()['enabled'] && '' === Plugin::settings()['recipient'], 'privacy erasure disables mail and removes matching recipient' );
    ccsd_check( false === wp_next_scheduled( 'ccsd_continue' ) && false === wp_next_scheduled( 'ccsd_daily' ), 'erasure removes scheduled mail work' );
} finally {
    remove_filter( 'pre_wp_mail', $sink, PHP_INT_MAX );
    foreach ( $ids as $id ) { wp_delete_post( $id, true ); }
    update_option( 'ccsd_settings', $old_settings, false );
    if ( false === $old_run ) { delete_option( 'ccsd_run' ); } else { update_option( 'ccsd_run', $old_run, false ); }
    Plugin::deactivate(); Plugin::schedule();
}
echo wp_json_encode( array( 'plugin' => 'StockDigest', 'version' => '0.2.0', 'environment' => array( 'wp' => get_bloginfo( 'version' ), 'woo' => WC_VERSION, 'php' => PHP_VERSION ), 'passed' => count( $checks ), 'tests' => $checks ), JSON_PRETTY_PRINT ) . PHP_EOL;
