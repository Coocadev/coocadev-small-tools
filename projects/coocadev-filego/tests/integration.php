<?php
/** Real WordPress + MariaDB + HTTP multipart integration; synthetic fixtures only. */
declare(strict_types=1);

$project = dirname(__DIR__);
$root = dirname($project, 2);
$site = $argv[1] ?? $root . '/qa/sites/filego';
$run = $argv[3] ?? $project . '/reports/0.1.0';
@mkdir($run, 0777, true);
define('WP_USE_THEMES', false);
require $site . '/wp-load.php';
use Coocadev\FileGo\Documents;
use Coocadev\FileGo\Store;
use Coocadev\FileGo\Plugin;
use Coocadev\FileGo\Privacy;

$credentials = json_decode(file_get_contents($argv[2] ?? $root . '/qa/secrets/filego.json'), true);
$base = $credentials['url'];
$cookie = $run . '/http-test.cookies';
$checks = [];
$owner = get_user_by('login', $credentials['user']);
wp_set_current_user($owner->ID);
function verify(string $name, bool $value, string $detail=''): void {
    global $checks;
    $checks[]=['test'=>$name,'pass'=>$value,'detail'=>$detail];
    echo ($value ? 'PASS ' : 'FAIL ') . $name . ($detail ? ': '.$detail : '') . "\n";
    if (!$value) throw new RuntimeException($name . ' failed: ' . $detail);
}
function request(string $path, string $method='GET', array $fields=[], bool $authenticated=true): array {
    global $base,$cookie;
    $ch=curl_init(str_starts_with($path,'http')?$path:$base.$path);
    $headers=[];
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>20,CURLOPT_HEADERFUNCTION=>function($ch,$line)use(&$headers){$part=explode(':',$line,2);if(count($part)===2)$headers[strtolower(trim($part[0]))]=trim($part[1]);return strlen($line);},CURLOPT_CUSTOMREQUEST=>$method]);
    if ($authenticated) curl_setopt_array($ch,[CURLOPT_COOKIEFILE=>$cookie,CURLOPT_COOKIEJAR=>$cookie]);
    if($method==='HEAD')curl_setopt($ch,CURLOPT_NOBODY,true);
    if($fields)curl_setopt($ch,CURLOPT_POSTFIELDS,$fields);
    $body=curl_exec($ch);$status=curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$error=curl_error($ch);unset($ch);
    if($body===false)throw new RuntimeException($error);
    return ['status'=>$status,'headers'=>$headers,'body'=>$body];
}
function fields(string $body,string $action,int $version=0): array {
    $dom=new DOMDocument();@$dom->loadHTML($body);
    foreach($dom->getElementsByTagName('form') as $form){
        $data=[];foreach($form->getElementsByTagName('input') as $input){if($input->getAttribute('type')==='hidden')$data[$input->getAttribute('name')]=$input->getAttribute('value');}
        if(($data['action']??'')===$action && (!$version || (int)($data['version']??0)===$version))return $data;
    }
    throw new RuntimeException('Form missing: '.$action.' version '.$version);
}
function document_page(int $id): string{return request('/wp-admin/upload.php?page=ccfg-documents&document='.$id)['body'];}
function latest_version(int $id): object{return Store::versions($id)[0];}
function race(array $jobs): array {
    global $site,$run,$owner;
    $gate=$run.'/gate-'.wp_generate_uuid4();$children=[];
    foreach($jobs as $job){$pipes=[];$process=proc_open(array_merge([PHP_BINARY,__DIR__.'/worker.php',$site],array_map('strval',$job),[$gate,(string)$owner->ID]),[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$children[]=[$process,$pipes];}
    usleep(250000);file_put_contents($gate,'go');$results=[];
    foreach($children as [$process,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($process);$results[]=json_decode($out,true)??['ok'=>false,'error'=>$out.' '.$err.' exit'.$code];}
    unlink($gate);return $results;
}
function pdf_fixture(string $path,string $label): void {
    $content="BT /F1 12 Tf 50 750 Td (".$label.") Tj ET";
    $objects=["<< /Type /Catalog /Pages 2 0 R >>","<< /Type /Pages /Kids [3 0 R] /Count 1 >>","<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>","<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>","<< /Length ".strlen($content)." >>\nstream\n$content\nendstream"];
    $pdf="%PDF-1.4\n";$offsets=[0];foreach($objects as $i=>$object){$offsets[]=strlen($pdf);$pdf.=($i+1)." 0 obj\n$object\nendobj\n";}$xref=strlen($pdf);$pdf.="xref\n0 6\n0000000000 65535 f \n";foreach(array_slice($offsets,1)as$offset)$pdf.=sprintf('%010d 00000 n ',$offset)."\n";$pdf.="trailer\n<< /Root 1 0 R /Size 6 >>\nstartxref\n$xref\n%%EOF\n";file_put_contents($path,$pdf);
}
try {
    verify('InnoDB schema and admin capability',Store::transactional()&&current_user_can('ccfg_manage_documents'));
    verify('Repeat activation idempotent',Store::install()&&Store::transactional());
    request('/wp-login.php');
    $login=request('/wp-login.php','POST',['log'=>$credentials['user'],'pwd'=>$credentials['password'],'wp-submit'=>'Log In','redirect_to'=>$base.'/wp-admin/','testcookie'=>'1']);
    verify('Real HTTP administrator login',$login['status']===302);
    $slug='qa-'.strtolower(wp_generate_password(8,false,false));
    $page=request('/wp-admin/upload.php?page=ccfg-documents');
    verify('Native UI loads',$page['status']===200&&str_contains($page['body'],'Create a document'));
    $create=fields($page['body'],'ccfg_create');$create['title']='QA <script>alert(1)</script> menu';$create['slug']=$slug;
    $response=request('/wp-admin/admin-post.php','POST',$create);
    verify('Create document over CSRF-protected HTTP',$response['status']===302);
    parse_str(parse_url($response['headers']['location'],PHP_URL_QUERY),$query);$id=(int)$query['document'];
    verify('Unpublished alias returns404',request('/?ccfg_download='.$slug,'GET',[],false)['status']===404);
    $noNonce=$create;unset($noNonce['_wpnonce']);$noNonce['slug']=$slug.'-bad';
    verify('Missing nonce rejected',request('/wp-admin/admin-post.php','POST',$noNonce)['status']===400);
    $noNonce['_wpnonce']='wrong';verify('Invalid nonce rejected403',request('/wp-admin/admin-post.php','POST',$noNonce)['status']===403);
    verify('GET mutation rejected405',request('/wp-admin/admin-post.php?action=ccfg_create')['status']===405);
    $arrayInput=$create;$arrayInput['title']='unused';$arrayInput['slug[]']='bad';unset($arrayInput['slug']);
    verify('Array instead scalar rejected',request('/wp-admin/admin-post.php','POST',$arrayInput)['status']===400);
    $pdf1=$run.'/fixture-a.pdf';$pdf2=$run.'/fixture-b.pdf';pdf_fixture($pdf1,'First menu');pdf_fixture($pdf2,'Second menu');
    verify('PDF content inspection',Documents::validate_pdf($pdf1)===true);
    $form=fields(document_page($id),'ccfg_upload');$form['note']='First <script>alert(2)</script> release';$form['ccfg_pdf']=new CURLFile($pdf1,'application/pdf','../../menu.php.pdf');
    $uploadResponse=request('/wp-admin/admin-post.php','POST',$form);verify('Real multipart PDF upload',$uploadResponse['status']===302,(string)$uploadResponse['status'].' '.substr(trim(strip_tags($uploadResponse['body'])), -450));
    $v1=latest_version($id);$path1=get_attached_file((int)$v1->attachment_id,true);
    verify('Staged unique server filename',$v1->state==='staged'&&preg_match('/^ccfg-[a-f0-9-]+\.pdf$/',basename($path1))===1&&Store::document($id)->current_version_id===null);
    verify('Direct staged media is public',request(wp_get_attachment_url((int)$v1->attachment_id),'GET',[],false)['status']===200);
    verify('HTML payload escaped/sanitized',!str_contains(document_page($id),'<script>alert('));
    $bad=$run.'/malicious.pdf';file_put_contents($bad,'<?php echo "owned"; ?>');
    $form=fields(document_page($id),'ccfg_upload');$form['note']='';$form['ccfg_pdf']=new CURLFile($bad,'application/pdf','bad.pdf');
    verify('Renamed PHP PDF rejected',request('/wp-admin/admin-post.php','POST',$form)['status']===400);
    verify('Failed upload leaves history unchanged',count(Store::versions($id))===1);
    $publish=fields(document_page($id),'ccfg_publish',(int)$v1->id);
    verify('Publish first version',request('/wp-admin/admin-post.php','POST',$publish)['status']===302);
    $alias=request('/?ccfg_download='.$slug,'GET',[],false);$head=request('/?ccfg_download='.$slug,'HEAD',[],false);
    verify('Alias302 to exact file',$alias['status']===302&&$alias['headers']['location']===wp_get_attachment_url((int)$v1->attachment_id));
    verify('GET and HEAD no-store parity',$head['status']===302&&$head['body']===''&&$head['headers']['location']===$alias['headers']['location']&&str_contains($head['headers']['cache-control'],'no-store'));
    verify('Public POST alias405',request('/?ccfg_download='.$slug,'POST',[],false)['status']===405);
    verify('Duplicate publish stale revision409',request('/wp-admin/admin-post.php','POST',$publish)['status']===409);
    verify('Media permanent deletion blocked',wp_delete_attachment((int)$v1->attachment_id,true)===false&&is_file($path1));
    verify('Media trash blocked',wp_trash_post((int)$v1->attachment_id)===false&&get_post((int)$v1->attachment_id)->post_status==='inherit');
    $form=fields(document_page($id),'ccfg_upload');$form['note']='Second release';$form['ccfg_pdf']=new CURLFile($pdf2,'application/pdf','menu.pdf');
    verify('Upload second staged version',request('/wp-admin/admin-post.php','POST',$form)['status']===302);$v2=latest_version($id);
    verify('Old alias unchanged before publication',Plugin::target($slug)===wp_get_attachment_url((int)$v1->attachment_id));
    verify('Second immutable URL differs',wp_get_attachment_url((int)$v2->attachment_id)!==wp_get_attachment_url((int)$v1->attachment_id));
    $publish=fields(document_page($id),'ccfg_publish',(int)$v2->id);
    verify('Publish second version',request('/wp-admin/admin-post.php','POST',$publish)['status']===302);
    verify('Alias follows second version immediately',Plugin::target($slug)===wp_get_attachment_url((int)$v2->attachment_id));
    $other=Documents::create('Other document',$slug.'-other');
    verify('Cross-document version rejected',is_wp_error(Documents::publish($other,(int)$v1->id,0)));
    $revision=(int)Store::document($id)->revision;
    verify('Current version deletion rejected',is_wp_error(Documents::delete_version($id,(int)$v2->id,$revision)));
    $beforeHash=hash_file('sha256',$path1);$original=file_get_contents($path1);file_put_contents($path1,str_replace('First menu','Worst menu',$original));clearstatcache();
    verify('Same-size changed PDF rollback rejected',is_wp_error(Documents::publish($id,(int)$v1->id,$revision)));
    file_put_contents($path1,$original);clearstatcache();
    verify('Rollback to intact historical PDF',Documents::publish($id,(int)$v1->id,$revision)===true&&Plugin::target($slug)===wp_get_attachment_url((int)$v1->attachment_id));
    $revision=(int)Store::document($id)->revision;
    verify('Stale deletion rejected',is_wp_error(Documents::delete_version($id,(int)$v2->id,$revision-1)));
    $v2path=get_attached_file((int)$v2->attachment_id,true);
    verify('Two-phase delete removes only old version',Documents::delete_version($id,(int)$v2->id,$revision)===true&&!is_file($v2path)&&is_file($path1)&&Store::version((int)$v2->id)->state==='deleted');
    $revision=(int)Store::document($id)->revision;
    verify('Deleted version never publishable',is_wp_error(Documents::publish($id,(int)$v2->id,$revision)));
    $subscriber=wp_create_user('ccfgsub'.wp_rand(100000,999999),wp_generate_password(),'sub'.wp_rand(100000,999999).'@example.test');$sub=new WP_User($subscriber);$sub->set_role('subscriber');wp_set_current_user($subscriber);
    verify('Subscriber cannot create with service API',is_wp_error(Documents::create('denied',$slug.'-denied')));
    verify('Subscriber cannot publish existing version',is_wp_error(Documents::publish($id,(int)$v1->id,$revision)));
    wp_set_current_user($owner->ID);
    verify('Privacy export contains own version',count(Privacy::export($owner->user_email)['data'])>=1);
    $before=Store::document($id);$erased=Privacy::erase($owner->user_email);$after=Store::document($id);
    verify('Privacy erases authorship without breaking publication',$erased['items_removed']&&(int)Store::version((int)$v1->id)->created_by===0&&$after->current_version_id===$before->current_version_id);
    verify('Unpublish stops alias',Documents::unpublish($id,(int)$after->revision)===true&&is_wp_error(Plugin::target($slug)));
    verify('Unpublish leaves physical public PDF',is_file($path1)&&request(wp_get_attachment_url((int)$v1->attachment_id),'GET',[],false)['status']===200);
    verify('Republish retained version',Documents::publish($id,(int)$v1->id,(int)Store::document($id)->revision)===true);
    $outside=$run.'/outside.pdf';pdf_fixture($outside,'Outside uploads');verify('Path outside uploads rejected',is_wp_error(Documents::local_path($outside)));
    $marker=get_post_meta((int)$v1->attachment_id,'_ccfg_owner',true);update_post_meta((int)$v1->attachment_id,'_ccfg_owner','coocadev-filego:999');
    verify('Foreign-site marker rejected',is_wp_error(Documents::ownership(Store::version((int)$v1->id))));update_post_meta((int)$v1->attachment_id,'_ccfg_owner',$marker);
    $offload=static fn($url,$attachment)=>$attachment===(int)$v1->attachment_id?'https://example.net/file.pdf':$url;add_filter('wp_get_attachment_url',$offload,10,2);
    verify('Offload redirect rejected',is_wp_error(Plugin::target($slug)));remove_filter('wp_get_attachment_url',$offload,10);
    verify('No FileGo frontend assets',!str_contains(request('/')['body'],'ccfg-admin'));
    $form=fields(document_page($id),'ccfg_upload');$form['note']='Concurrent candidate';$form['ccfg_pdf']=new CURLFile($pdf2,'application/pdf','candidate.pdf');
    verify('Upload concurrent candidate',request('/wp-admin/admin-post.php','POST',$form)['status']===302);$v3=latest_version($id);$rev=(int)Store::document($id)->revision;
    $results=race([['publish',$id,(int)$v3->id,$rev],['publish',$id,(int)$v3->id,$rev]]);
    verify('Two concurrent writers: exactly one publication',count(array_filter($results,fn($r)=>$r['ok']))===1,json_encode($results));
    $rev=(int)Store::document($id)->revision;$results=race([['publish',$id,(int)$v1->id,$rev],['delete',$id,(int)$v1->id,$rev]]);
    $docAfter=Store::document($id);$v1After=Store::version((int)$v1->id);
    verify('Concurrent rollback versus delete: one safe winner',count(array_filter($results,fn($r)=>$r['ok']))===1&&!is_wp_error(Plugin::target($slug))&&(!($v1After->state==='deleted')||(int)$docAfter->current_version_id!==(int)$v1->id),json_encode($results));
    $form=fields(document_page($id),'ccfg_upload');$form['note']='Interrupted deletion';$form['ccfg_pdf']=new CURLFile($pdf2,'application/pdf','pending.pdf');
    verify('Upload deletion-retry fixture',request('/wp-admin/admin-post.php','POST',$form)['status']===302);$pending=latest_version($id);
    $block=static fn($result,$post)=>$post->ID===(int)$pending->attachment_id?false:$result;add_filter('pre_delete_attachment',$block,99,2);
    $deletion=Documents::delete_version($id,(int)$pending->id,(int)Store::document($id)->revision);remove_filter('pre_delete_attachment',$block,99);
    verify('Failure persists delete_pending',is_wp_error($deletion)&&Store::version((int)$pending->id)->state==='delete_pending');
    verify('Pending deletion cannot be rolled back',is_wp_error(Documents::publish($id,(int)$pending->id,(int)Store::document($id)->revision)));
    verify('Explicit pending deletion retry succeeds',Documents::delete_version($id,(int)$pending->id,(int)Store::document($id)->revision)===true);
    $form=fields(document_page($id),'ccfg_upload');$form['note']='Post-delete crash';$form['ccfg_pdf']=new CURLFile($pdf2,'application/pdf','crash.pdf');request('/wp-admin/admin-post.php','POST',$form);$crash=latest_version($id);
    $crashPath=get_attached_file((int)$crash->attachment_id,true);$wpdb->update(Store::table('versions'),['state'=>'delete_pending','delete_token'=>'synthetic-crash'],['id'=>(int)$crash->id]);remove_filter('pre_delete_attachment',[Documents::class,'protect_attachment'],10);wp_delete_attachment((int)$crash->attachment_id,true);add_filter('pre_delete_attachment',[Documents::class,'protect_attachment'],10,3);
    verify('Crash recovery finalizes only absent attachment and file',!is_file($crashPath)&&Documents::delete_version($id,(int)$crash->id,(int)Store::document($id)->revision)===true&&Store::version((int)$crash->id)->state==='deleted');
    update_option('ccfg_test_document',$id,false);
} catch(Throwable $e){$checks[]=['test'=>'exception','pass'=>false,'detail'=>$e->getMessage()];echo 'ERROR '.$e->getMessage()."\n";}
finally{if(is_file($cookie))unlink($cookie);file_put_contents($run.'/integration-results.json',json_encode(['wp'=>$GLOBALS['wp_version'],'php'=>PHP_VERSION,'db'=>$GLOBALS['wpdb']->db_version(),'checks'=>$checks],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));}
$fail=array_filter($checks,static fn($check)=>!$check['pass']);echo count($checks).' checks; '.count($fail)." failed.\n";exit($fail?1:0);
