<?php
/** Isolated multi-process digest worker. Use setup, worker, verify, then cleanup. */
use Coocadev\StockDigest\Plugin;
if(!defined('WP_CLI')||!WP_CLI||'local'!==wp_get_environment_type()||!in_array(wp_parse_url(home_url(),PHP_URL_HOST),array('127.0.0.1','localhost','::1'),true)||!preg_match('~/qa/(?:min-)?sites/dispatchgo/?$~',str_replace('\\','/',ABSPATH))){throw new RuntimeException('Reserved local WP-CLI QA only.');}
$dir=dirname(untrailingslashit(ABSPATH),3).'/projects/coocadev-stockdigest/reports/0.2.0/';$path=$dir.'concurrency-fixture.private.json';$mail_path=$dir.'concurrency-mail.log';$mode=$args[0]??'';
if('setup'===$mode){
 if(is_file($path)){throw new RuntimeException('Clean previous concurrency fixture first');}
 $state=array('settings'=>get_option('ccsd_settings'),'run'=>get_option('ccsd_run'));
 $p=new WC_Product_Simple();$p->set_name('StockDigest concurrency synthetic fixture');$p->set_status('publish');$p->set_regular_price('1');$p->set_manage_stock(true);$p->set_stock_quantity(0);$state['product']=$p->save();
 file_put_contents($path,wp_json_encode($state));file_put_contents($mail_path,'');update_option('ccsd_settings',array('enabled'=>true,'recipient'=>'concurrency@example.invalid'),false);delete_option('ccsd_run');echo "READY\n";
}elseif('worker'===$mode){
 if(!is_file($path)){throw new RuntimeException('No local concurrency fixture');}
 add_filter('pre_wp_mail',static function($return,$atts)use($mail_path){usleep(700000);file_put_contents($mail_path,"mail-invoked\n",FILE_APPEND|LOCK_EX);return true;},PHP_INT_MAX,2);
 $result=Plugin::run();echo wp_json_encode(array('status'=>is_wp_error($result)?$result->get_error_code():$result['status'])).PHP_EOL;
}elseif('verify'===$mode){
 $lines=file($mail_path,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);$run=get_option('ccsd_run');if(count($lines)!==1||'sent'!==($run['status']??'')){throw new RuntimeException('Concurrent workers did not produce exactly one claimed mail');}echo wp_json_encode(array('plugin'=>'StockDigest','workers'=>4,'mail_invocations'=>1,'pass'=>true)).PHP_EOL;
}elseif('cleanup'===$mode){
 if(is_file($path)){$old=json_decode(file_get_contents($path),true);$p=wc_get_product($old['product']);if($p&&'StockDigest concurrency synthetic fixture'===$p->get_name()){wp_delete_post($p->get_id(),true);}update_option('ccsd_settings',$old['settings'],false);if(false===$old['run']){delete_option('ccsd_run');}else{update_option('ccsd_run',$old['run'],false);}Plugin::deactivate();Plugin::schedule();unlink($path);}echo "CLEAN\n";
}else{throw new RuntimeException('Unknown local test mode');}
