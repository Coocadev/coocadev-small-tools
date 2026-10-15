<?php
/** Use the concurrency fixture; emulate WC_Order's swallowed save exception before each side-effect boundary. */
use Coocadev\DownloadRescue\Plugin;
if(!defined('WP_CLI')||!WP_CLI||'local'!==wp_get_environment_type()||!in_array(wp_parse_url(home_url(),PHP_URL_HOST),array('127.0.0.1','localhost','::1'),true)||!preg_match('~/qa/(?:min-)?sites/dispatchgo/?$~',str_replace('\\','/',ABSPATH))){throw new RuntimeException('Reserved local WP-CLI QA only.');}
$path=dirname(untrailingslashit(ABSPATH),3).'/projects/coocadev-downloadrescue/reports/0.2.0/concurrency-fixture.private.json';if(!is_file($path)){throw new RuntimeException('Create the guarded concurrency fixture first');}$fixture=json_decode(file_get_contents($path),true);$order=wc_get_order($fixture['order']);if(!$order||'ccdr-qa-concurrency'!==$order->get_created_via()){throw new RuntimeException('Not our synthetic fixture');}
wp_set_current_user(1);$mail=0;$checks=array();$sink=static function()use(&$mail){++$mail;return true;};add_filter('pre_wp_mail',$sink,PHP_INT_MAX);
$check=static function($pass,$name)use(&$checks){if(!$pass){throw new RuntimeException($name);}$checks[]=$name;};
$read=static function()use($fixture){$order=wc_get_order($fixture['order']);$order->read_meta_data(true);return $order->get_meta('_ccdr_request',true);};
$store=WC_Data_Store::load('customer-download');$original=$store->get_downloads(array('order_id'=>$order->get_id()))[0]->get_data();
$fail_state='granting';$seen_failures=array();$failure=static function($candidate)use($fixture,&$fail_state,&$seen_failures){if($candidate instanceof WC_Order&&$candidate->get_id()===$fixture['order']&&$fail_state===($candidate->get_meta('_ccdr_request',true)['status']??'')){$seen_failures[]=$fail_state;throw new RuntimeException('Intentional local durable-save failure');}};
add_action('woocommerce_before_order_object_save',$failure,PHP_INT_MAX);
try{
 $result=Plugin::decide($fixture['order'],$fixture['token'],true);$check(is_wp_error($result)&&'ccdr_storage'===$result->get_error_code(),'swallowed grant-claim save failure becomes explicit storage error');
 $fresh=$store->get_downloads(array('order_id'=>$order->get_id()))[0]->get_data();$check($original==$fresh&&0===$mail&&'pending'===$read()['status'],'failed claim cannot grant permissions or send mail');
 $result=Plugin::decide($fixture['order'],$fixture['token'],true);$check(is_wp_error($result)&&0===$mail,'replay under failed claim remains silent');
 $fail_state='approved';$result=Plugin::decide($fixture['order'],$fixture['token'],true);$check(is_wp_error($result)&&'ccdr_storage'===$result->get_error_code()&&0===$mail,'failed approved-state save cannot invoke native email');
 $check(in_array('approved',$seen_failures,true),'failure injection reaches the actual final approved-state save');
 $check('granting'===$read()['status'],'failed final approval leaves durable granting claim for native merchant review');
 remove_action('woocommerce_before_order_object_save',$failure,PHP_INT_MAX);$result=Plugin::decide($fixture['order'],$fixture['token'],true);$check(is_wp_error($result)&&'ccdr_stale'===$result->get_error_code()&&0===$mail,'durable intermediate claim prevents replay after approval save failure');
}finally{remove_action('woocommerce_before_order_object_save',$failure,PHP_INT_MAX);remove_filter('pre_wp_mail',$sink,PHP_INT_MAX);}
echo wp_json_encode(array('plugin'=>'DownloadRescue','passed'=>count($checks),'tests'=>$checks),JSON_PRETTY_PRINT).PHP_EOL;
