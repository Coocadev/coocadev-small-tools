<?php
/** One native uninstall per isolated PHP process, preserving documented product data. */
use Coocadev\StockDigest\Plugin;
if ( ! defined('WP_CLI') || ! WP_CLI || 'local' !== wp_get_environment_type() || ! in_array(wp_parse_url(home_url(),PHP_URL_HOST),array('127.0.0.1','localhost','::1'),true) || ! preg_match('~/qa/(?:min-)?sites/dispatchgo/?$~',str_replace('\\','/',ABSPATH)) ){throw new RuntimeException('Reserved local WP-CLI QA only.');}
require_once ABSPATH.'wp-admin/includes/plugin.php';$old=get_option('ccsd_settings');$run=get_option('ccsd_run');$file='coocadev-stockdigest/coocadev-stockdigest.php';$pid=0;$checks=array();
try{
 $p=new WC_Product_Simple();$p->set_name('StockDigest lifecycle fixture');$p->update_meta_data('_ccsd_ordered_until',time()+DAY_IN_SECONDS);$pid=$p->save();
 update_option('ccsd_settings',array('enabled'=>true,'recipient'=>'lifecycle@example.invalid'),false);Plugin::schedule();
 if(!wp_next_scheduled('ccsd_daily')){throw new RuntimeException('Enabled cron not scheduled');}$checks[]='activation/scheduling';
 deactivate_plugins($file);if(wp_next_scheduled('ccsd_daily')||wp_next_scheduled('ccsd_continue')){throw new RuntimeException('Cron survives deactivate');}$checks[]='deactivation cancels work';
 if(!get_option('ccsd_settings')['enabled']){throw new RuntimeException('Deactivate deleted settings');}$checks[]='deactivation retains settings';
 uninstall_plugin($file);if(false!==get_option('ccsd_settings')||false!==get_option('ccsd_run')){throw new RuntimeException('Uninstall did not clear configuration');}$checks[]='native uninstall removes own options';
 if(!wc_get_product($pid)->get_meta('_ccsd_ordered_until',true)){throw new RuntimeException('Uninstall destroyed merchant reminder');}$checks[]='uninstall preserves product reminder';
 activate_plugins($file);if(Plugin::settings()['enabled']||Plugin::settings()['recipient']){throw new RuntimeException('Reinstall enabled unsolicited mail');}$checks[]='reinstall defaults to no mail';
}finally{if($pid){wp_delete_post($pid,true);}activate_plugins($file);update_option('ccsd_settings',$old,false);if(false===$run){delete_option('ccsd_run');}else{update_option('ccsd_run',$run,false);}Plugin::deactivate();Plugin::schedule();}
echo wp_json_encode(array('plugin'=>'StockDigest','passed'=>count($checks),'tests'=>$checks),JSON_PRETTY_PRINT).PHP_EOL;
