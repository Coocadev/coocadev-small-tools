<?php
/** Destructive only to FileGo's isolated QA tables; media and WordPress data must survive. */
$project=dirname(__DIR__);$root=dirname($project,2);$site=$argv[1]??$root.'/qa/sites/filego';
define('WP_USE_THEMES',false);require $site.'/wp-load.php';
use Coocadev\FileGo\Documents;use Coocadev\FileGo\Store;use Coocadev\FileGo\Plugin;
$checks=[];function check_life($name,$condition){global$checks;$checks[]=['test'=>$name,'pass'=>(bool)$condition];echo($condition?'PASS ':'FAIL ').$name."\n";if(!$condition)throw new RuntimeException($name);}
$user=get_user_by('login','qa_owner');wp_set_current_user($user->ID);
try{
 $id=(int)get_option('ccfg_test_document');$doc=Store::document($id);$current=Store::version((int)$doc->current_version_id);$file=get_attached_file((int)$current->attachment_id,true);$sha=hash_file('sha256',$file);
 check_life('Title update preserves permanent slug',Documents::update_title($id,(int)$doc->revision,'Updated QA title')===true&&Store::document($id)->slug===$doc->slug);
 check_life('Stale title change rejected',is_wp_error(Documents::update_title($id,(int)$doc->revision,'Losing edit')));
 $wpdb->query($wpdb->prepare('ALTER TABLE %i ENGINE=MyISAM',Store::table('versions')));
 check_life('Nontransactional tables block mutations',is_wp_error(Documents::create('Blocked','nontransactional-'.wp_rand())));
 $wpdb->query($wpdb->prepare('ALTER TABLE %i ENGINE=InnoDB',Store::table('versions')));
 check_life('InnoDB restored',Store::transactional());
 Plugin::deactivate();check_life('Deactivation retains document and PDF',Store::document($id)!==null&&is_file($file)&&hash_file('sha256',$file)===$sha);
 Plugin::activate(false);check_life('Reactivation retains current pointer',(int)Store::document($id)->current_version_id===(int)$current->id);
 update_option('ccfg_remove_data',false);define('WP_UNINSTALL_PLUGIN','coocadev-filego/coocadev-filego.php');require $site.'/wp-content/plugins/coocadev-filego/uninstall.php';
 check_life('Default uninstall retains document',Store::document($id)!==null&&is_file($file));
 update_option('ccfg_remove_data',true);Coocadev\FileGo\ccfg_uninstall_site();
 check_life('Opt-in uninstall removes own tables',!$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like(Store::table('documents'))))&&!$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like(Store::table('versions')))));
 check_life('Uninstall preserves physical PDF and attachment',is_file($file)&&hash_file('sha256',$file)===$sha&&get_post((int)$current->attachment_id)!==null);
 check_life('Uninstall removes owned markers',''===get_post_meta((int)$current->attachment_id,'_ccfg_owner',true)&&''===get_post_meta((int)$current->attachment_id,'_ccfg_version_id',true));
 check_life('Uninstall removes management capability',!get_role('administrator')->has_cap('ccfg_manage_documents'));
 check_life('Reinstall works after opted-in cleanup',Store::install()&&get_role('administrator')->has_cap('ccfg_manage_documents'));
}catch(Throwable$e){$checks[]=['test'=>'exception: '.$e->getMessage(),'pass'=>false];echo $e->getMessage()."\n";}finally{if(!Store::transactional())$wpdb->query($wpdb->prepare('ALTER TABLE %i ENGINE=InnoDB',Store::table('versions')));file_put_contents($project.'/reports/0.1.0/lifecycle-results.json',json_encode($checks,JSON_PRETTY_PRINT));}
exit(count(array_filter($checks,fn($c)=>!$c['pass']))?1:0);
