"""Create isolated, separate-process Core uninstall fixtures for two owned tools."""
from pathlib import Path
base=Path(__file__).resolve().parents[3]
worker=r'''<?php
/** Local QA worker only. Copyright (c) 2026 Coocadev. SPDX-License-Identifier: GPL-2.0-or-later. */
declare(strict_types=1);
$project=dirname(__DIR__);$root=dirname($project,2);$site=$argv[1]??'';
$allowed=array_filter([realpath($root.'/qa/sites/filego'),realpath($root.'/qa/min-sites/filego')]);
if(!is_string($site)||!in_array(realpath($site),$allowed,true))throw new RuntimeException('Only the two isolated FileGo QA sites are allowed');
define('WP_USE_THEMES',false);
require $site.'/wp-load.php';
require_once ABSPATH.'wp-admin/includes/plugin.php';
if(wp_get_environment_type()!=='local'||!in_array(wp_parse_url(home_url(),PHP_URL_HOST),['127.0.0.1','localhost','::1'],true))throw new RuntimeException('Localhost QA only');
add_filter('pre_wp_mail',static fn()=>false);
$slug=basename(dirname(__DIR__));$file=$slug.'/'.$slug.'.php';
$admins=get_users(['role'=>'administrator','number'=>1]);wp_set_current_user($admins[0]->ID);
deactivate_plugins($file,true);
uninstall_plugin($file);
$error=activate_plugin($file,'',false,false);
if(is_wp_error($error))throw new RuntimeException($error->get_error_message());
echo json_encode(['core_uninstall_and_reactivate'=>true]);
'''
test=r'''<?php
/** Real Core uninstall, separate processes. Copyright (c) 2026 Coocadev. SPDX-License-Identifier: GPL-2.0-or-later. */
declare(strict_types=1);
$project=dirname(__DIR__);$root=dirname($project,2);$site=$argv[1]??$root.'/qa/sites/filego';$report=$argv[2]??$project.'/reports/0.2.0';@mkdir($report,0777,true);
$allowed=array_filter([realpath($root.'/qa/sites/filego'),realpath($root.'/qa/min-sites/filego')]);
if(!is_string($site)||!in_array(realpath($site),$allowed,true))throw new RuntimeException('Only the two isolated FileGo QA sites are allowed');
$debug=$site.'/wp-content/debug.log';$offset=is_file($debug)?filesize($debug):0;
define('WP_USE_THEMES',false);require $site.'/wp-load.php';
if(wp_get_environment_type()!=='local'||!in_array(wp_parse_url(home_url(),PHP_URL_HOST),['127.0.0.1','localhost','::1'],true))throw new RuntimeException('Localhost QA only');
add_filter('pre_wp_mail',static fn()=>false);
$slug=basename($project);$passport=$slug==='coocadev-passport';$option=$passport?'ccpp_data':'ccrg_remove_data';$prior=get_option($option);$checks=[];$release=0;$term=0;
$admins=get_users(['role'=>'administrator','number'=>1]);wp_set_current_user($admins[0]->ID);
$foreign=wp_insert_post(['post_type'=>'post','post_status'=>'draft','post_title'=>'Foreign lifecycle fixture']);
$foreignOption='ccqa_'.wp_generate_password(10,false,false);add_option($foreignOption,'retain-me','',false);
function ccst_lifecycle_check(string$name,bool$ok):void{global$checks;$checks[]=['test'=>$name,'pass'=>$ok];echo($ok?'PASS ':'FAIL ').$name."\n";if(!$ok)throw new RuntimeException($name);}
function ccst_lifecycle_worker():void{global$site,$project;$p=proc_open([PHP_BINARY,$project.'/tests/uninstall-worker.php',$site],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($p);if($code!==0||!(json_decode($out,true)['core_uninstall_and_reactivate']??false))throw new RuntimeException('Worker failed: '.$out.$err);}
try{
 if($passport){$seed=['schema'=>1,'revision'=>5,'remove_data'=>false,'cards'=>['retained-fixture/plugin.php'=>['purpose'=>'Retained QA','usage'=>'QA','responsible'=>'QA team','checklist'=>'Check']]];update_option($option,$seed,false);}else{$release=wp_insert_post(['post_type'=>'ccrg_release','post_status'=>'publish','post_title'=>'Lifecycle release']);update_post_meta($release,'_ccrg_version','1.0.0');update_post_meta($release,'_ccrg_kinds',['new']);$result=wp_insert_term('Lifecycle '.wp_generate_password(6,false,false),'ccrg_product');if(is_wp_error($result))throw new RuntimeException('Term fixture');$term=$result['term_id'];wp_set_object_terms($release,[(int)$term],'ccrg_product');update_option($option,false,false);}
 ccst_lifecycle_worker();wp_cache_delete($option,'options');if($release)clean_post_cache($release);
 ccst_lifecycle_check('Actual default Core uninstall and reinstall preserves owned data',$passport?get_option($option)===$seed:(bool)get_post($release));
 ccst_lifecycle_check('Default uninstall preserves foreign post and option',(bool)get_post($foreign)&&get_option($foreignOption)==='retain-me');
 if($passport){$seed['remove_data']=true;update_option($option,$seed,false);}else{update_option($option,true,false);}
 ccst_lifecycle_worker();wp_cache_delete($option,'options');if($release)clean_post_cache($release);
 ccst_lifecycle_check('Actual opted-in uninstall removes only owned data',$passport?get_option($option)['cards']===[]:get_post($release)===null);
 if(!$passport){clean_term_cache((int)$term,'ccrg_product');ccst_lifecycle_check('Opted-in cleanup removes owned product term',get_term((int)$term,'ccrg_product')===null);}
 ccst_lifecycle_check('Opted-in cleanup preserves foreign post and option',(bool)get_post($foreign)&&get_option($foreignOption)==='retain-me');
 ccst_lifecycle_check('Plugin reactivation restores safe default retention',$passport?get_option($option)['remove_data']===false:!get_option($option));
 echo 'PASS total '.count($checks)."\n";
}finally{if($release&&get_post($release))wp_delete_post($release,true);if($term&&get_term((int)$term,'ccrg_product'))wp_delete_term((int)$term,'ccrg_product');wp_delete_post($foreign,true);delete_option($foreignOption);update_option($option,$prior,false);clearstatcache(true,$debug);$fresh=is_file($debug)?substr(file_get_contents($debug),$offset):'';file_put_contents($report.'/lifecycle-wp-debug-fresh.log',$fresh);file_put_contents($report.'/lifecycle.json',json_encode(['date_utc'=>gmdate('c'),'wp'=>get_bloginfo('version'),'php'=>PHP_VERSION,'checks'=>$checks,'fresh_debug_bytes'=>strlen($fresh)],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));}
'''
for slug in ('coocadev-passport','coocadev-releasego'):
 p=base/'projects'/slug/'tests'
 (p/'uninstall-worker.php').write_text(worker,encoding='utf-8')
 (p/'lifecycle.php').write_text(test,encoding='utf-8')
