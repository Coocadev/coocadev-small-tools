<?php
/** Run with --skip-plugins=woocommerce to prove no account action becomes active. */
if(!defined('WP_CLI')||!WP_CLI||'local'!==wp_get_environment_type()||!in_array(wp_parse_url(home_url(),PHP_URL_HOST),array('127.0.0.1','localhost','::1'),true)||!preg_match('~/qa/(?:min-)?sites/dispatchgo/?$~',str_replace('\\','/',ABSPATH))){throw new RuntimeException('Reserved local WP-CLI QA only.');}
if(defined('WC_VERSION')||has_action('admin_post_ccdr_request',array('Coocadev\DownloadRescue\Plugin','handle_request'))){throw new RuntimeException('Dependency guard failed');}
echo "PASS DownloadRescue stays inactive with WooCommerce absent\n";
