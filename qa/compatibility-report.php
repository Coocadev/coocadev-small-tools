<?php
/** Local read-only Woo feature declaration diagnostic. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() || 'http://127.0.0.1:8785' !== untrailingslashit( home_url() ) ) {
    throw new RuntimeException( 'Dedicated local combined QA only.' );
}
$features = wc_get_container()->get( \Automattic\WooCommerce\Internal\Features\FeaturesController::class );
$catalog = json_decode( file_get_contents( dirname( __DIR__ ) . '/catalog.json' ), true );
$results = array();
$results['woocommerce_aware_active'] = wc_get_container()->get( \Automattic\WooCommerce\Utilities\PluginUtil::class )->get_woocommerce_aware_plugins( true );
foreach ( $catalog['products'] as $product ) {
    $file = $product['slug'] . '/' . $product['slug'] . '.php';
    $results[ $product['slug'] ] = $features->get_compatible_features_for_plugin( $file, true );
}
WP_CLI::log( wp_json_encode( $results, JSON_PRETTY_PRINT ) );
