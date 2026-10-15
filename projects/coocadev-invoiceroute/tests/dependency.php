<?php
/** Run with --skip-plugins=woocommerce to prove no order adapter becomes active. */
if(!defined('WP_CLI')||!WP_CLI||'local'!==wp_get_environment_type()||!in_array(wp_parse_url(home_url(),PHP_URL_HOST),array('127.0.0.1','localhost','::1'),true)||!preg_match('~/qa/(?:min-)?sites/dispatchgo/?$~',str_replace('\\','/',ABSPATH))){throw new RuntimeException('Reserved local WP-CLI QA only.');}
if(defined('WC_VERSION')||has_action('woocommerce_checkout_order_created',array('Coocadev\InvoiceRoute\Plugin','classic_freeze'))){throw new RuntimeException('Dependency guard failed');}
echo "PASS InvoiceRoute stays inactive with WooCommerce absent\n";
