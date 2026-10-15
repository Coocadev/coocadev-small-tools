<?php
/** Invoke with wp --skip-plugins=woocommerce eval-file on isolated QA. */
if ( ! defined( 'ABSPATH' ) || ! preg_match( '~/qa/(?:min-)?sites/dispatchgo/$~', str_replace( '\\', '/', ABSPATH ) ) ) { throw new RuntimeException( 'Isolated QA only.' ); }
if ( defined( 'WC_VERSION' ) || has_action( 'woocommerce_review_order_before_submit', array( \Coocadev\DispatchGo\Plugin::class, 'classic_preview' ) ) ) { throw new RuntimeException( 'Woo unexpectedly active or checkout hooks registered.' ); }
if ( ! class_exists( \Coocadev\DispatchGo\Plugin::class ) ) { throw new RuntimeException( 'DispatchGo bootstrap not loaded.' ); }
echo "PASS Woo absent: bootstrap loads without fatal, checkout integration remains inactive\n";
