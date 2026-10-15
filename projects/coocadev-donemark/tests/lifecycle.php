<?php
/** Destructive only to isolated DoneMark QA tables; never run against production. */
$p=dirname(__DIR__);$root=dirname($p,2);$site=$argv[1]??$root.'/qa/sites/donemark';$report=$argv[2]??$p.'/reports/0.1.0';require $site.'/wp-load.php';require_once ABSPATH.'wp-admin/includes/plugin.php';
use Coocadev\DoneMark\Settings;
use Coocadev\DoneMark\States;
use Coocadev\DoneMark\Privacy;
$owner=get_user_by('login','qa_owner');wp_set_current_user($owner->ID);$table=$wpdb->prefix.'ccdm_states';$checks=[];
function verify($name,$pass){global$checks;$checks[]=['test'=>$name,'pass'=>(bool)$pass];echo($pass?'PASS ':'FAIL ').$name."\n";if(!$pass)throw new RuntimeException($name);}
function uninstall_child(){global$site;$pipes=[];$proc=proc_open([PHP_BINARY,__DIR__.'/uninstall-worker.php',$site],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($proc)!==0)throw new RuntimeException($out.$err);}
try{
 States::install();update_option('ccdm_settings',Settings::defaults(),false);$settings=Settings::save([['id'=>'','label'=>'Lifecycle mark','type'=>'distribution','post_types'=>['post'],'archived'=>false]],1,false);$mark=$settings['definitions'][0]['id'];$post=wp_insert_post(['post_title'=>'DoneMark retained lifecycle post','post_status'=>'publish','post_author'=>$owner->ID]);States::write($post,$mark,true,0,States::view($post)['content_hash']);
 deactivate_plugins('coocadev-donemark/coocadev-donemark.php');verify('Deactivation retains definitions and state',count(Settings::read()['definitions'])===1&&(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE post_id=%d',$table,$post))===1);
 activate_plugin('coocadev-donemark/coocadev-donemark.php');verify('Reactivation retains existing mark and data',Settings::read()['definitions'][0]['id']===$mark);
 uninstall_child();verify('Default uninstall retains table and definitions',count(Settings::read()['definitions'])===1&&$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table)))===$table);
 $wpdb->query($wpdb->prepare('ALTER TABLE %i ENGINE=MyISAM',$table));$r=States::write($post,$mark,false,1,States::view($post)['content_hash']);verify('Nontransactional storage fails closed',is_wp_error($r)&&$r->get_error_code()==='ccdm_storage');$wpdb->query($wpdb->prepare('ALTER TABLE %i ENGINE=InnoDB',$table));verify('Restored transactional storage usable',!is_wp_error(States::write($post,$mark,false,1,States::view($post)['content_hash'])));
 $other=wp_insert_user(['user_login'=>'dm_privacy_'.strtolower(wp_generate_password(6,false,false)),'user_pass'=>wp_generate_password(24),'role'=>'editor']);$ids=[];
 for($i=0;$i<201;$i++){$id=wp_insert_post(['post_title'=>'DoneMark privacy fixture '.$i,'post_status'=>'draft','post_author'=>$owner->ID]);$ids[]=$id;$wpdb->insert($table,['post_id'=>$id,'mark_id'=>$mark,'is_done'=>1,'reviewed_hash'=>'','hash_version'=>1,'actor_user_id'=>$owner->ID,'changed_at_gmt'=>current_time('mysql',true),'version'=>1]);}
 $wpdb->update($table,['actor_user_id'=>$other],['post_id'=>$ids[0],'mark_id'=>$mark]);
 $first=Privacy::erase($owner->user_email);$second=Privacy::erase($owner->user_email);$third=Privacy::erase($owner->user_email);verify('Privacy erasure batches100 until complete',!$first['done']&&!$second['done']&&$third['done']);
 verify('Privacy erasure preserves another editors latest attribution',(int)$wpdb->get_var($wpdb->prepare('SELECT actor_user_id FROM %i WHERE post_id=%d AND mark_id=%s',$table,$ids[0],$mark))===$other);
 verify('Privacy erasure preserves team states and invalidates old CAS',(int)$wpdb->get_var($wpdb->prepare('SELECT version FROM %i WHERE post_id=%d AND mark_id=%s',$table,$ids[1],$mark))===2&&(int)$wpdb->get_var($wpdb->prepare('SELECT is_done FROM %i WHERE post_id=%d AND mark_id=%s',$table,$ids[1],$mark))===1);
 $settings=Settings::read();Settings::save($settings['definitions'],$settings['revision'],true);uninstall_child();verify('Opt-in uninstall removes owned table',$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table)))===null);wp_cache_delete('ccdm_settings','options');wp_cache_delete('ccdm_schema','options');verify('Opt-in uninstall removes only own options',get_option('ccdm_settings','missing')==='missing'&&get_option('ccdm_schema','missing')==='missing');verify('Uninstall preserves posts and users',get_post($post)!==null&&get_userdata($other)!==false);
 States::install();add_option('ccdm_settings',Settings::defaults(),'','no');verify('Reinstall after cleanup works',Settings::read()['revision']===1);
 foreach($ids as$id)wp_delete_post($id,true);
}catch(Throwable$e){$checks[]=['test'=>'exception','pass'=>false,'detail'=>$e->getMessage()];echo'ERROR '.$e->getMessage()."\n";}
finally{$failed=count(array_filter($checks,fn($c)=>!$c['pass']));file_put_contents($report.'/lifecycle-results.json',json_encode(['php'=>PHP_VERSION,'wordpress'=>get_bloginfo('version'),'checks'=>$checks,'failed'=>$failed],JSON_PRETTY_PRINT));echo count($checks).' checks; '.$failed." failed.\n";}
exit($failed?1:0);
