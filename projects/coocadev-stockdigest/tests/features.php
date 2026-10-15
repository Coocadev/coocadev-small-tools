<?php
/** Verify declarations registered during normal WooCommerce bootstrap. */
use Automattic\WooCommerce\Internal\Features\FeaturesController;
use Coocadev\StockDigest\Plugin;
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() || ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( '127.0.0.1', 'localhost', '::1' ), true ) || ! preg_match( '~/qa/(?:min-)?sites/dispatchgo/?$~', str_replace( '\\', '/', ABSPATH ) ) ) { throw new RuntimeException( 'Isolated local QA only.' ); }
$checks = array();
$check = static function ( $pass, $name ) use ( &$checks ) { $checks[] = array( 'name' => $name, 'pass' => (bool) $pass ); if ( ! $pass ) { throw new RuntimeException( $name ); } };
$check( did_action( 'before_woocommerce_init' ) > 0 && false !== has_action( 'before_woocommerce_init', array( Plugin::class, 'compatibility' ) ), 'declarations use normal before_woocommerce_init bootstrap' );
$controller = wc_get_container()->get( FeaturesController::class );
$features = $controller->get_compatible_features_for_plugin( 'coocadev-stockdigest/coocadev-stockdigest.php', false, false );
foreach ( array( 'custom_order_tables', 'cart_checkout_blocks' ) as $feature ) {
    $check( in_array( $feature, $features['compatible'], true ) && ! in_array( $feature, $features['uncertain'], true ) && ! in_array( $feature, $features['incompatible'], true ), $feature . ' is explicitly compatible, not uncertain' );
}
echo wp_json_encode( array( 'plugin' => 'StockDigest', 'version' => '0.2.0', 'environment' => array( 'wp' => get_bloginfo( 'version' ), 'woo' => WC_VERSION, 'php' => PHP_VERSION ), 'passed' => count( $checks ), 'tests' => $checks, 'declarations' => $features ), JSON_PRETTY_PRINT ) . PHP_EOL;
