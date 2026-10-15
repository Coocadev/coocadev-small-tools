<?php
/** Fault injection and stale option caches must never bypass the persisted mail claim. */
use Coocadev\StockDigest\Plugin;
if(!defined('WP_CLI')||!WP_CLI||'local'!==wp_get_environment_type()||!in_array(wp_parse_url(home_url(),PHP_URL_HOST),array('127.0.0.1','localhost','::1'),true)||!preg_match('~/qa/(?:min-)?sites/dispatchgo/?$~',str_replace('\\','/',ABSPATH))){throw new RuntimeException('Reserved local WP-CLI QA only.');}
$old=get_option('ccsd_settings');$old_run=get_option('ccsd_run');$pid=0;$mail=0;$checks=array();
$check=static function($pass,$name)use(&$checks){if(!$pass){throw new RuntimeException($name);}$checks[]=$name;};
$sink=static function()use(&$mail){++$mail;return true;};add_filter('pre_wp_mail',$sink,PHP_INT_MAX);
$finish=static function(){for($i=0;$i<101;++$i){$state=Plugin::run();if(is_wp_error($state)||'scanning'!==$state['status']){return $state;}}throw new RuntimeException('Unexpected unbounded test run');};
$enable=static function(){wp_cache_delete('ccsd_settings','options');wp_cache_delete('notoptions','options');update_option('ccsd_settings',array('enabled'=>true,'recipient'=>'durability@example.invalid'),false);delete_option('ccsd_run');};
try{
 $p=new WC_Product_Simple();$p->set_name('StockDigest durable synthetic fixture');$p->set_status('publish');$p->set_regular_price('1');$p->set_manage_stock(true);$p->set_stock_quantity(0);$pid=$p->save();$mail=0;$enable();
 $deny_claim=static function($next,$previous){return is_array($next)&&'claimed'===($next['status']??'')?$previous:$next;};add_filter('pre_update_option_ccsd_run',$deny_claim,10,2);
 $result=$finish();$check(is_wp_error($result)&&'ccsd_storage'===$result->get_error_code()&&0===$mail,'failed durable claim sends no email');
 $result=$finish();$check(is_wp_error($result)&&0===$mail,'claim write failure replay remains silent');remove_filter('pre_update_option_ccsd_run',$deny_claim,10);
 $enable();$deny_result=static function($next,$previous){return is_array($next)&&'sent'===($next['status']??'')?$previous:$next;};add_filter('pre_update_option_ccsd_run',$deny_result,10,2);
 $result=$finish();$check(is_wp_error($result)&&1===$mail&&'claimed'===get_option('ccsd_run')['status'],'result write failure retains durable pre-mail claim');remove_filter('pre_update_option_ccsd_run',$deny_result,10);
 $result=Plugin::run();$check(!is_wp_error($result)&&'claimed'===$result['status']&&1===$mail,'claimed result failure cannot resend');
 wp_cache_set('ccsd_run',array('day'=>current_time('Y-m-d'),'page'=>1,'rows'=>array(),'truncated'=>false,'status'=>'scanning'),'options');
 $result=Plugin::run();$check('claimed'===$result['status']&&1===$mail,'stale scanning cache cannot override authoritative completed claim');
 wp_cache_delete('ccsd_settings','options');update_option('ccsd_settings',array('enabled'=>false,'recipient'=>''),false);wp_cache_set('ccsd_settings',array('enabled'=>true,'recipient'=>'durability@example.invalid'),'options');
 $check(get_option('ccsd_settings')['enabled'],'fixture recreates stale init recipient cache');$result=Plugin::run();$check('disabled'===$result['status']&&1===$mail,'privacy-erased database recipient wins over stale init cache');
}finally{
 remove_filter('pre_update_option_ccsd_run',$deny_claim??'__return_null',10);remove_filter('pre_update_option_ccsd_run',$deny_result??'__return_null',10);if($pid){wp_delete_post($pid,true);}wp_cache_delete('ccsd_settings','options');wp_cache_delete('ccsd_run','options');wp_cache_delete('notoptions','options');update_option('ccsd_settings',$old,false);if(false===$old_run){delete_option('ccsd_run');}else{update_option('ccsd_run',$old_run,false);}Plugin::deactivate();Plugin::schedule();remove_filter('pre_wp_mail',$sink,PHP_INT_MAX);
}
echo wp_json_encode(array('plugin'=>'StockDigest','passed'=>count($checks),'tests'=>$checks),JSON_PRETTY_PRINT).PHP_EOL;
