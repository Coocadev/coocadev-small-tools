<?php
/** Real WordPress, HTTP/REST, SQL and concurrent process tests. Local isolated QA only. */
declare(strict_types=1);
$project=dirname(__DIR__);$root=dirname($project,2);$site=$argv[1]??$root.'/qa/sites/donemark';
$report=$argv[3]??$project.'/reports/0.1.0';@mkdir($report,0777,true);
define('WP_ADMIN',true);require $site.'/wp-load.php';
use Coocadev\DoneMark\Settings;
use Coocadev\DoneMark\States;
use Coocadev\DoneMark\Admin;
use Coocadev\DoneMark\Privacy;
$credentials=json_decode(file_get_contents($argv[2]??$root.'/qa/secrets/donemark.json'),true);
$base=$credentials['url'];$cookie=$report.'/http-test.cookies';$checks=[];$owner=get_user_by('login',$credentials['user']);
wp_set_current_user($owner->ID);
function verify($name,$ok,$detail=''){global $checks;$checks[]=['test'=>$name,'pass'=>(bool)$ok,'detail'=>$detail];echo($ok?'PASS ':'FAIL ').$name.($detail?': '.$detail:'')."\n";if(!$ok)throw new RuntimeException($name.' '.$detail);}
function request($path,$method='GET',$data=null,$nonce=null,$auth=true){global $base,$cookie;$ch=curl_init($base.$path);$headers=[];$options=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>25,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HEADERFUNCTION=>function($ch,$line)use(&$headers){$p=explode(':',$line,2);if(count($p)===2)$headers[strtolower(trim($p[0]))]=trim($p[1]);return strlen($line);}];if($auth){$options[CURLOPT_COOKIEFILE]=$cookie;$options[CURLOPT_COOKIEJAR]=$cookie;}$outheaders=[];if($nonce!==null)$outheaders[]='X-WP-Nonce: '.$nonce;if($data!==null){if(str_starts_with($path,'/?rest_route=')){$outheaders[]='Content-Type: application/json';$options[CURLOPT_POSTFIELDS]=json_encode($data);}else{$options[CURLOPT_POSTFIELDS]=http_build_query($data);}}$options[CURLOPT_HTTPHEADER]=$outheaders;curl_setopt_array($ch,$options);$body=curl_exec($ch);$status=curl_getinfo($ch,CURLINFO_RESPONSE_CODE);if($body===false)throw new RuntimeException(curl_error($ch));return['status'=>$status,'body'=>$body,'json'=>json_decode($body,true),'headers'=>$headers];}
function api($path,$method='GET',$data=null,$nonce=null,$auth=true){return request('/?rest_route=/coocadev-donemark/v1'.$path,$method,$data,$nonce,$auth);}
function error_is($r,$code){return is_wp_error($r)&&$r->get_error_code()===$code;}
function mark($post,$id){return array_column(States::view($post)['marks'],null,'id')[$id];}
function change($post,$id,$done){$view=States::view($post);$m=array_column($view['marks'],null,'id')[$id];return States::write($post,$id,$done,$m['version'],$view['content_hash']);}
function race($jobs){global $site,$report,$owner;$gate=$report.'/gate-'.wp_generate_uuid4();$children=[];foreach($jobs as$job){$pipes=[];$proc=proc_open([PHP_BINARY,__DIR__.'/worker.php',$site,(string)$owner->ID,json_encode($job),$gate],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$children[]=[$proc,$pipes];}usleep(200000);file_put_contents($gate,'go');$results=[];foreach($children as[$proc,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($proc);$results[]=json_decode($out,true)??['ok'=>false,'error'=>$out.$err.' exit'.$code];}unlink($gate);return $results;}
try{
 States::install();update_option('ccdm_settings',Settings::defaults(),false);
 $defs=[['id'=>'','label'=>'Reviewed text','type'=>'review','post_types'=>['post','page'],'archived'=>false],['id'=>'','label'=>'Shared newsletter','type'=>'distribution','post_types'=>['post'],'archived'=>false]];
 $settings=Settings::save($defs,1,false);verify('Create definitions with stable server UUIDs',!is_wp_error($settings)&&count($settings['definitions'])===2);
 [$review,$distribution]=array_column($settings['definitions'],'id');
 $renamed=$settings['definitions'];$renamed[0]['label']='Copy reviewed';$settings=Settings::save($renamed,2,false);verify('Rename preserves mark UUID',$settings['definitions'][0]['id']===$review);
 verify('Stale settings CAS rejects',error_is(Settings::save($renamed,2,false),'ccdm_conflict'));
 $invalid=$renamed;$invalid[0]['type']='distribution';verify('Immutable mark type enforced',error_is(Settings::save($invalid,3,false),'ccdm_type'));
 $invalid=$renamed;$invalid[0]['label']='<b></b>';verify('Markup-only empty label rejected',error_is(Settings::save($invalid,3,false),'ccdm_definition'));
 verify('Normal save cannot silently delete history',error_is(Settings::save([$renamed[0]],3,false),'ccdm_delete'));
 $post=wp_insert_post(['post_title'=>'QA DoneMark 日本語 : café 🚀','post_content'=>'<!-- wp:paragraph --><p>Контент : &amp; text</p><!-- /wp:paragraph -->','post_excerpt'=>'Excerpt 🔎','post_status'=>'publish','post_author'=>$owner->ID]);
 $view=States::view($post);verify('New post starts independently unchecked',count($view['marks'])===2&&!$view['marks'][0]['done']);
 $table=$wpdb->prefix.'ccdm_states';
 $sqlhash=$wpdb->get_var($wpdb->prepare("SELECT SHA2(CONCAT('1:',OCTET_LENGTH(post_title),':',post_title,':',OCTET_LENGTH(post_content),':',post_content,':',OCTET_LENGTH(post_excerpt),':',post_excerpt),256) FROM %i WHERE ID=%d",$wpdb->posts,$post));
 verify('MariaDB SQL and PHP fingerprints match Unicode bytes',$sqlhash===$view['content_hash']);
 verify('Review true transition',!is_wp_error(change($post,$review,true))&&mark($post,$review)['done']&&!mark($post,$review)['stale']);
 $before=mark($post,$review);States::write($post,$review,true,0,$view['content_hash']);verify('Idempotent retry preserves actor time version',mark($post,$review)===$before);
 verify('Distribution marks independently',!is_wp_error(change($post,$distribution,true))&&mark($post,$distribution)['done']);
 wp_update_post(['ID'=>$post,'post_content'=>'Saved revised body']);
 verify('Content edit makes review stale only',mark($post,$review)['stale']&&!mark($post,$distribution)['stale']&&mark($post,$distribution)['done']);
 verify('Review from stale content hash rejected',error_is(States::write($post,$review,true,$before['version'],$view['content_hash']),'ccdm_content_conflict'));
 verify('Re-review current content clears stale',!is_wp_error(change($post,$review,true))&&!mark($post,$review)['stale']);
 verify('Stale version cannot undo newer review',error_is(States::write($post,$review,false,1,States::view($post)['content_hash']),'ccdm_conflict'));
 $fresh=States::post($post);wp_update_post(['ID'=>$post,'post_content'=>$fresh['post_content'].' modified']);wp_update_post(['ID'=>$post,'post_content'=>$fresh['post_content']]);verify('Exact content restore matches reviewed fingerprint',!mark($post,$review)['stale']);
 $subscriber=wp_insert_user(['user_login'=>'dm_sub_'.strtolower(wp_generate_password(6,false,false)),'user_pass'=>wp_generate_password(24),'role'=>'subscriber']);wp_set_current_user($subscriber);
 verify('Subscriber cannot read or alter marks',error_is(States::view($post),'ccdm_forbidden')&&error_is(States::write($post,$review,false,2,$view['content_hash']),'ccdm_forbidden'));
 verify('Subscriber cannot alter definitions',error_is(Settings::save($renamed,3,false),'ccdm_forbidden'));
 $author=wp_insert_user(['user_login'=>'dm_author_'.strtolower(wp_generate_password(6,false,false)),'user_pass'=>wp_generate_password(24),'role'=>'author']);$own=wp_insert_post(['post_title'=>'Own author post','post_status'=>'draft','post_author'=>$author]);wp_set_current_user($author);
 verify('Author can edit own mark but cannot address another authors post',!is_wp_error(States::view($own))&&!is_wp_error(change($own,$distribution,true))&&error_is(States::view($post),'ccdm_forbidden')&&error_is(States::write($post,$distribution,false,1,$view['content_hash']),'ccdm_forbidden'));
 wp_set_current_user($owner->ID);
 request('/wp-login.php');$login=request('/wp-login.php','POST',['log'=>$credentials['user'],'pwd'=>$credentials['password'],'testcookie'=>'1','wp-submit'=>'Log In','redirect_to'=>$base.'/wp-admin/']);verify('Real HTTP admin login',$login['status']===302);
 $html=request('/wp-admin/edit.php')['body'];preg_match('/var ccdmAdmin = (\{[^;]+\});/',$html,$match);$client=json_decode($match[1]??'{}',true);$nonce=$client['nonce']??'';
 verify('Native list has private marks and scoped JS',str_contains($html,'ccdm-marks')&&str_contains($html,'coocadev-donemark/assets/admin.js')&&strlen($nonce)>5);
 verify('Public REST cannot read state',in_array(api('/posts/'.$post.'/marks','GET',null,null,false)['status'],[401,403],true));
 verify('Cookie REST without nonce denied',in_array(api('/posts/'.$post.'/marks')['status'],[401,403],true));
 $r=api('/posts/'.$post.'/marks','GET',null,$nonce);verify('Authorized REST has no-store and accurate state',$r['status']===200&&str_contains($r['headers']['cache-control']??'','no-store')&&$r['json']['post_id']===$post);
 $payload=['done'=>false,'version'=>mark($post,$review)['version'],'content_hash'=>States::view($post)['content_hash']];
 verify('Invalid REST nonce denied403',api('/posts/'.$post.'/marks/'.$review,'PUT',$payload,'bad')['status']===403);
 $bad=$payload;$bad['done']='yes';verify('REST string boolean rejected400',api('/posts/'.$post.'/marks/'.$review,'PUT',$bad,$nonce)['status']===400);
 $bad=$payload;$bad['actor_user_id']=$subscriber;verify('REST spoofed attribution rejected400',api('/posts/'.$post.'/marks/'.$review,'PUT',$bad,$nonce)['status']===400);
 verify('REST toggle persists exact state',api('/posts/'.$post.'/marks/'.$review,'PUT',$payload,$nonce)['status']===200&&!mark($post,$review)['done']);
 $bad=$payload;$bad['version']=0;$bad['done']=true;verify('REST stale version409',api('/posts/'.$post.'/marks/'.$review,'PUT',$bad,$nonce)['status']===409);
 $item=['post_id'=>$post,'mark_id'=>$review,'done'=>true,'version'=>mark($post,$review)['version'],'content_hash'=>States::view($post)['content_hash']];
 verify('Duplicate bulk pair rejected',api('/bulk','POST',['items'=>[$item,$item]],$nonce)['status']===400);
 $baditem=$item;$baditem['post_id']=99999999;$r=api('/bulk','POST',['items'=>[$item,$baditem]],$nonce);verify('Bulk per-object partial success',$r['status']===200&&$r['json']['results'][0]['success']&&!$r['json']['results'][1]['success']);
 $settingshtml=request('/wp-admin/options-general.php?page=ccdm-settings');verify('Settings page native forms and escaped labels',$settingshtml['status']===200&&str_contains($settingshtml['body'],'Save marks'));
 verify('Admin form missing nonce rejected',request('/wp-admin/admin-post.php','POST',['action'=>'ccdm_save','revision'=>'3'])['status']===403);
 verify('Admin GET mutations denied',request('/wp-admin/admin-post.php?action=ccdm_delete')['status']===403);
 $GLOBALS['pagenow']='edit.php';$query=new WP_Query();$query->set('post_type','post');$GLOBALS['wp_the_query']=$query;
 $_GET['ccdm_mark']=$review;$_GET['ccdm_state']='done';$where=Admin::where('1=1',$query);$ids=$wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE ".$where);verify('Native SQL done filter matches PHP',in_array((string)$post,$ids,true));
 wp_update_post(['ID'=>$post,'post_excerpt'=>'Changed excerpt']);$_GET['ccdm_state']='stale';$ids=$wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE ".Admin::where('1=1',$query));verify('Native SQL stale filter matches PHP',in_array((string)$post,$ids,true)&&mark($post,$review)['stale']);
 $wpdb->update($table,['reviewed_hash'=>null],['post_id'=>$post,'mark_id'=>$review]);$ids=$wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE ".Admin::where('1=1',$query));verify('NULL persisted review fingerprint is stale in SQL and PHP',in_array((string)$post,$ids,true)&&mark($post,$review)['stale']);
 $other=new WP_Query();verify('Secondary query untouched',Admin::where('original',$other)==='original');$GLOBALS['pagenow']='index.php';verify('Dashboard query untouched',Admin::where('original',$query)==='original');unset($_GET['ccdm_mark'],$_GET['ccdm_state']);
 $setting=Settings::read();$archive=$setting['definitions'];$archive[0]['archived']=true;$archived=Settings::save($archive,$setting['revision'],false);verify('Archive hides but retains saved rows',!is_wp_error($archived)&&count(States::view($post)['marks'])===1&&(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE post_id=%d AND mark_id=%s',$table,$post,$review))===1);
 verify('Archived mark write rejected',error_is(States::write($post,$review,true,1,$view['content_hash']),'ccdm_mark'));
 $archive[0]['archived']=false;Settings::save($archive,$archived['revision'],false);verify('Unarchive restores prior state',count(States::view($post)['marks'])===2);
 change($post,$review,true);$m=mark($post,$review);$hash=States::view($post)['content_hash'];$jobs=[['op'=>'write','post'=>$post,'mark'=>$review,'done'=>false,'version'=>$m['version'],'hash'=>$hash],['op'=>'write','post'=>$post,'mark'=>$review,'done'=>false,'version'=>$m['version'],'hash'=>$hash]];$r=race($jobs);verify('Concurrent identical retries have one state transition',mark($post,$review)['version']===$m['version']+1&&!mark($post,$review)['done'],json_encode($r));
 $setting=Settings::read();$m=mark($post,$review);$r=race([['op'=>'delete','mark'=>$review,'revision'=>$setting['revision']],['op'=>'write','post'=>$post,'mark'=>$review,'done'=>true,'version'=>$m['version'],'hash'=>$hash]]);verify('Delete versus write cannot leave orphan states',!in_array($review,array_column(Settings::read()['definitions'],'id'),true)&&(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE mark_id=%s',$table,$review))===0,json_encode($r));
 $v=mark($post,$distribution)['version'];$export=Privacy::export($owner->user_email);verify('Privacy exporter includes own current attribution',count($export['data'])>0);
 $erased=Privacy::erase($owner->user_email);verify('Privacy eraser keeps state increments CAS and removes actor',$erased['items_removed']&&mark($post,$distribution)['done']&&mark($post,$distribution)['version']===$v+1&&mark($post,$distribution)['actor']==='Former team member');
 verify('Repeated privacy erasure accurately reports no removal',!Privacy::erase($owner->user_email)['items_removed']);
 $frontend=request('/?p='.$post,'GET',null,null,false);verify('No DoneMark frontend assets',!str_contains($frontend['body'],'coocadev-donemark/assets/'));
 $trash=wp_insert_post(['post_title'=>'QA trash','post_status'=>'trash','post_author'=>$owner->ID]);verify('Trash cannot acquire a mark',error_is(States::write($trash,$distribution,true,0,$hash),'ccdm_forbidden'));
 $m=mark($post,$distribution);$r=race([['op'=>'postdelete','post'=>$post],['op'=>'write','post'=>$post,'mark'=>$distribution,'done'=>false,'version'=>$m['version'],'hash'=>States::view($post)['content_hash']]]);clean_post_cache($post);verify('Concurrent permanent post delete versus mark write leaves no orphan',get_post($post)===null&&(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE post_id=%d',$table,$post))===0,json_encode($r));
}catch(Throwable $e){$checks[]=['test'=>'exception','pass'=>false,'detail'=>$e->getMessage()];echo 'ERROR '.$e->getMessage()."\n";}
finally{if(file_exists($cookie))unlink($cookie);$failed=count(array_filter($checks,fn($c)=>!$c['pass']));file_put_contents($report.'/integration-results.json',json_encode(['php'=>PHP_VERSION,'wordpress'=>get_bloginfo('version'),'checks'=>$checks,'failed'=>$failed],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));echo count($checks).' checks; '.$failed." failed.\n";}
exit($failed?1:0);
