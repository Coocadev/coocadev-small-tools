<?php
/** Real WC permission CRUD and owner/merchant boundaries; never fetch file content. */
use Coocadev\DownloadRescue\Plugin;
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() || ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( '127.0.0.1', 'localhost', '::1' ), true ) || ! preg_match( '~/qa/(?:min-)?sites/dispatchgo/?$~', str_replace( '\\', '/', ABSPATH ) ) ) { throw new RuntimeException( 'Isolated local QA only.' ); }
global $checks; $checks = array(); $orders = array(); $users = array(); $pid = 0; $mail = array(); $fixture_file = ''; $fixture_dir = ''; $approved_id = 0;
function ccdr_check( $pass, $name ) { global $checks; $checks[] = array( 'name' => $name, 'pass' => (bool) $pass ); if ( ! $pass ) { throw new RuntimeException( $name ); } }
$sink = static function ( $return, $args ) use ( &$mail ) { $mail[] = $args; return true; };
add_filter( 'pre_wp_mail', $sink, PHP_INT_MAX, 2 );
try {
    foreach ( array( 'buyer', 'stranger' ) as $role ) { $id = wp_insert_user( array( 'user_login' => 'ccdr_' . $role . '_' . wp_generate_password( 8, false ), 'user_email' => 'ccdr-' . $role . '-' . wp_generate_password( 8, false ) . '@example.invalid', 'user_pass' => wp_generate_password( 30 ), 'role' => 'customer' ) ); if ( is_wp_error( $id ) ) { throw new RuntimeException( 'Fixture user failed' ); } $users[$role] = $id; }
    $product = new WC_Product_Simple(); $product->set_name( 'DownloadRescue synthetic download' ); $product->set_status( 'publish' ); $product->set_regular_price( '1' ); $product->set_virtual( true ); $product->set_downloadable( true ); $product->set_download_expiry( 1 ); $product->set_download_limit( 1 );
    wp_set_current_user( 1 ); $upload = wp_upload_dir(); $directory_name = 'ccdr-qa-' . wp_generate_password( 12, false ); $fixture_dir = $upload['basedir'] . '/' . $directory_name; wp_mkdir_p( $fixture_dir ); $fixture_file = $fixture_dir . '/fixture.zip'; file_put_contents( $fixture_file, "PK\x05\x06" . str_repeat( "\x00", 18 ) );
    $directory_url = trailingslashit( $upload['baseurl'] ) . $directory_name . '/'; $register = wc_get_container()->get( \Automattic\WooCommerce\Internal\ProductDownloads\ApprovedDirectories\Register::class ); $approved_id = $register->add_approved_directory( $directory_url, true );
    $file = new WC_Product_Download(); $file->set_id( 'ccdr-test-file' ); $file->set_name( 'Synthetic archive' ); $file->set_file( $directory_url . 'fixture.zip' ); $product->set_downloads( array( $file ) ); $pid = $product->save();
    $order = wc_create_order( array( 'customer_id' => $users['buyer'] ) ); $orders[] = $order->get_id(); $order->set_billing_email( get_userdata( $users['buyer'] )->user_email ); $order->add_product( $product, 1 ); $order->set_status( 'completed' ); $order->save();
    $store = WC_Data_Store::load( 'customer-download' ); $permissions = $store->get_downloads( array( 'order_id' => $order->get_id() ) );
    if ( ! $permissions ) { wc_downloadable_product_permissions( $order->get_id(), true ); $permissions = $store->get_downloads( array( 'order_id' => $order->get_id() ) ); }
    ccdr_check( 1 === count( $permissions ), 'native WooCommerce creates purchased permission' );
    $permission = $permissions[0]; $permission->set_access_expires( strtotime( 'midnight', time() ) ); $permission->set_downloads_remaining( 1 ); $permission->save();
    ccdr_check( ! Plugin::expired_permissions( $order ), 'native WooCommerce expiry date remains valid throughout its calendar day' );
    $permission->set_access_expires( time() - DAY_IN_SECONDS ); $permission->set_downloads_remaining( 0 ); $permission->save();
    wp_set_current_user( 0 ); ccdr_check( is_wp_error( Plugin::request( $order->get_id() ) ), 'logged-out visitor cannot request access' );
    wp_set_current_user( $users['stranger'] ); ccdr_check( is_wp_error( Plugin::request( $order->get_id() ) ), 'another customer cannot request against foreign order' );
    wp_set_current_user( $users['buyer'] ); $mail = array(); $request = Plugin::request( $order->get_id() );
    ccdr_check( is_array( $request ) && 'pending' === $request['status'], 'authenticated owner can request expired download review' );
    ccdr_check( ! $mail && 1 === count( Plugin::expired_permissions( wc_get_order( $order->get_id() ) ) ), 'request does not grant permission or send email' );
    ccdr_check( $request === Plugin::request( $order->get_id() ), 'duplicate request returns same generation' );
    ccdr_check( is_wp_error( Plugin::decide( $order->get_id(), $request['token'], true ) ), 'purchaser cannot self-approve' );
    wp_set_current_user( 1 );
    ccdr_check( in_array( $order->get_id(), array_map( static function( $o ) { return $o->get_id(); }, Plugin::pending_orders() ), true ), 'merchant queue finds pending request via WC query' );
    ccdr_check( is_wp_error( Plugin::decide( $order->get_id(), 'stale-token', true ) ), 'wrong generation cannot approve' );
    $mail = array(); $result = Plugin::decide( $order->get_id(), $request['token'], true );
    ccdr_check( is_array( $result ) && 'approved' === $result['status'], 'merchant explicitly approves pending generation' );
    $fresh = new WC_Customer_Download( $permission->get_id() );
    ccdr_check( $fresh->get_access_expires()->getTimestamp() > time() + 6 * DAY_IN_SECONDS && 5 === (int) $fresh->get_downloads_remaining(), 'existing native permission renewed seven days and five downloads' );
    ccdr_check( 1 === count( $mail ) && array( $order->get_billing_email() ) === (array) $mail[0]['to'], 'approval resends native purchaser order email exactly once' );
    ccdr_check( is_wp_error( Plugin::decide( $order->get_id(), $request['token'], true ) ) && 1 === count( $mail ), 'approval replay cannot renew or resend twice' );
    wp_set_current_user( $users['buyer'] ); ccdr_check( is_wp_error( Plugin::request( $order->get_id() ) ), 'repeat request is limited to one per 24 hours' );
    ccdr_check( 1 === count( Plugin::export( $order->get_billing_email() )['data'] ) && ! Plugin::export( 'stranger@example.invalid' )['data'], 'private request export matches order billing identity' );
    $before = $fresh->get_data(); $erased = Plugin::erase( $order->get_billing_email() ); $after = new WC_Customer_Download( $permission->get_id() );
    ccdr_check( $erased['items_removed'] && ! wc_get_order( $order->get_id() )->meta_exists( '_ccdr_request' ), 'privacy erases request metadata' );
    ccdr_check( $before == $after->get_data(), 'erasure leaves native entitlement unchanged' );
    $fresh->set_access_expires( time() - DAY_IN_SECONDS ); $fresh->save(); $order = wc_get_order( $order->get_id() ); $order->set_status( 'cancelled' ); $order->save();
    ccdr_check( is_wp_error( Plugin::request( $order->get_id() ) ), 'cancelled order cannot request renewal' );
    $order->set_status( 'completed' ); $order->save();
    $request = Plugin::request( $order->get_id() ); wp_set_current_user( 1 ); $mail = array(); $rejected = Plugin::decide( $order->get_id(), $request['token'], false );
    ccdr_check( is_array( $rejected ) && 'rejected' === $rejected['status'] && ! $mail && 1 === count( Plugin::expired_permissions( $order ) ), 'merchant rejection changes no entitlement and sends nothing' );
    ccdr_check( ! Plugin::pending_orders(), 'decided requests leave pending queue' );
    $refund = wc_create_refund( array( 'order_id' => $order->get_id(), 'amount' => 0, 'reason' => 'Synthetic review-only refund', 'refund_payment' => false, 'restock_items' => false ) );
    if ( is_wp_error( $refund ) ) { throw new RuntimeException( 'Refund fixture failed: ' . $refund->get_error_code() ); }
    $refunded = wc_get_order( $order->get_id() );
    ccdr_check( ! Plugin::eligible_order( $refunded ) && ! Plugin::expired_permissions( $refunded ), 'any existing partial or full refund requires native merchant review' );
    wp_set_current_user( $users['buyer'] ); $denied = Plugin::request( $order->get_id() );
    ccdr_check( is_wp_error( $denied ) && 'ccdr_status' === $denied->get_error_code(), 'refund gate precedes request or rate-limit mutation' );
} finally {
    wp_set_current_user( 1 ); foreach ( $orders as $id ) { $o = wc_get_order( $id ); if ( $o ) { $o->delete( true ); } }
    if ( $pid ) { wp_delete_post( $pid, true ); }
    if ( $fixture_file && is_file( $fixture_file ) ) { unlink( $fixture_file ); }
    if ( $fixture_dir && is_dir( $fixture_dir ) ) { rmdir( $fixture_dir ); }
    if ( $approved_id ) { $register->delete_by_id( $approved_id ); }
    require_once ABSPATH . 'wp-admin/includes/user.php'; foreach ( $users as $id ) { wp_delete_user( $id ); }
    remove_filter( 'pre_wp_mail', $sink, PHP_INT_MAX );
}
echo wp_json_encode( array( 'plugin' => 'DownloadRescue', 'version' => '0.2.0', 'environment' => array( 'wp' => get_bloginfo( 'version' ), 'woo' => WC_VERSION, 'php' => PHP_VERSION, 'hpos' => \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ), 'passed' => count( $checks ), 'tests' => $checks ), JSON_PRETTY_PRINT ) . PHP_EOL;
