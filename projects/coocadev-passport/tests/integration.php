<?php
/**
 * Isolated WordPress domain and real HTTP checks; synthetic fixtures only.
 * Copyright (c) 2026 Coocadev. SPDX-License-Identifier: GPL-2.0-or-later.
 */
declare(strict_types=1);
$project = dirname(__DIR__);
$root = dirname($project, 2);
$site = $argv[1] ?? $root . '/qa/sites/filego';
$allowedSites=array_filter([realpath($root.'/qa/sites/filego'),realpath($root.'/qa/min-sites/filego')]);
if(!is_string($site)||!in_array(realpath($site),$allowedSites,true))throw new RuntimeException('Only isolated FileGo QA sites are allowed');
$report = $argv[3] ?? $project . '/reports/0.2.0';
@mkdir($report, 0777, true);
$debug = $site . '/wp-content/debug.log';
$debugOffset = is_file($debug) ? filesize($debug) : 0;
define('WP_USE_THEMES', false);
require $site . '/wp-load.php';
if(wp_get_environment_type()!=='local'||!in_array(wp_parse_url(home_url(),PHP_URL_HOST),['127.0.0.1','localhost','::1'],true))throw new RuntimeException('Localhost QA only');
require_once ABSPATH . 'wp-admin/includes/user.php';
use Coocadev\PluginPassport\Plugin;
add_filter('pre_wp_mail', static fn() => false);
$credentials = json_decode(file_get_contents($argv[2] ?? $root . '/qa/secrets/filego.json'), true);
$base = $credentials['url'];
if(rtrim($base,'/')!==rtrim(home_url(),'/'))throw new RuntimeException('HTTP fixture URL must match the isolated localhost site');
$cookie = $report . '/private-http.cookies';
$checks = [];
$before = get_option(Plugin::OPTION);
$target = 'coocadev-passport/coocadev-passport.php';
$fields = ['purpose'=>'QA operational card','usage'=>'Native plugin screen','responsible'=>'QA team','checklist'=>"Back up\nTest login\nCheck forms"];
$owner = get_user_by('login', $credentials['user']);
$sub = 0;
function ccpp_verify(string $name, bool $pass): void { global $checks; $checks[]=['test'=>$name,'pass'=>$pass]; echo ($pass?'PASS ':'FAIL ') . $name . "\n"; if(!$pass)throw new RuntimeException($name); }
function ccpp_http(string $path,string $method='GET',array $fields=[],bool $auth=true): array {
    global $base,$cookie;
    $curl=curl_init($base.$path);$headers=[];
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_TIMEOUT=>20,CURLOPT_HEADERFUNCTION=>static function($handle,$line)use(&$headers){$p=explode(':',$line,2);if(count($p)===2)$headers[strtolower(trim($p[0]))]=trim($p[1]);return strlen($line);}]);
    if($auth)curl_setopt_array($curl,[CURLOPT_COOKIEJAR=>$cookie,CURLOPT_COOKIEFILE=>$cookie]);
    if($fields)curl_setopt($curl,CURLOPT_POSTFIELDS,http_build_query($fields));
    $body=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);if($body===false)throw new RuntimeException(curl_error($curl));unset($curl);
    return ['status'=>$status,'headers'=>$headers,'body'=>$body];
}
function ccpp_form(string $html,string $action): array {
    $dom=new DOMDocument();@$dom->loadHTML($html);
    foreach($dom->getElementsByTagName('form')as$form){$fields=[];foreach($form->getElementsByTagName('input')as$input){if($input->getAttribute('type')==='hidden')$fields[$input->getAttribute('name')]=$input->getAttribute('value');}if(($fields['action']??'')===$action)return$fields;}
    throw new RuntimeException('Missing form '.$action);
}
try {
    wp_set_current_user($owner->ID);
    ccpp_verify('Activated real installed plugin has schema1',is_array(Plugin::state())&&Plugin::state()['schema']===1);
    ccpp_verify('Exact installed target accepted',Plugin::target($target));
    ccpp_verify('Traversal, URL, array and unknown basename denied',!Plugin::target('../wp-config.php')&&!Plugin::target('https://example.test/a.php')&&!Plugin::target([])&&!Plugin::target('missing/plugin.php'));
    $state=Plugin::state();$saved=Plugin::save($target,$fields,$state['revision']);
    ccpp_verify('Own operational card saved and revision advanced',!is_wp_error($saved)&&$saved['revision']===$state['revision']+1&&$saved['cards'][$target]===$fields);
    $stale=Plugin::save($target,array_merge($fields,['purpose'=>'stale']),$state['revision']);
    ccpp_verify('Stale writer conflicts without clobbering card',is_wp_error($stale)&&$stale->get_error_data()['status']===409&&Plugin::state()===$saved);
    foreach([array_merge($fields,['purpose'=>[]]),array_merge($fields,['responsible'=>str_repeat('x',201)]),array_merge($fields,['usage'=>"bad\xff"]),array_merge($fields,['unknown'=>'x'])]as$bad){ccpp_verify('Malformed card leaves complete state unchanged',is_wp_error(Plugin::save($target,$bad,$saved['revision']))&&Plugin::state()===$saved);}
    $sub=wp_insert_user(['user_login'=>'ccpp_qa_'.wp_generate_password(8,false,false),'user_pass'=>wp_generate_password(28,true,true),'role'=>'subscriber','user_email'=>'passport-qa@example.test']);
    if(is_wp_error($sub))throw new RuntimeException($sub->get_error_message());
    wp_set_current_user((int)$sub);
    ccpp_verify('Subscriber cannot save or export',is_wp_error(Plugin::save($target,$fields,$saved['revision']))&&is_wp_error(Plugin::export()));
    wp_set_current_user($owner->ID);
    $user=new WP_User($owner->ID);$user->add_cap('activate_plugins',false);wp_set_current_user(0);wp_set_current_user($owner->ID);
    ccpp_verify('manage_options alone cannot export',!Plugin::allowed()&&is_wp_error(Plugin::export()));
    $user->remove_cap('activate_plugins');wp_set_current_user(0);wp_set_current_user($owner->ID);
    $user->add_cap('manage_options',false);wp_set_current_user(0);wp_set_current_user($owner->ID);
    ccpp_verify('activate_plugins alone cannot export',!Plugin::allowed()&&is_wp_error(Plugin::export()));
    $user->remove_cap('manage_options');wp_set_current_user(0);wp_set_current_user($owner->ID);
    $withRemoved=Plugin::state();$withRemoved['cards']['removed-qa/plugin.php']=$fields;update_option(Plugin::OPTION,$withRemoved,false);
    $export=Plugin::export();$removed=array_values(array_filter($export['cards'],static fn($card)=>$card['plugin']==='removed-qa/plugin.php'));
    ccpp_verify('Removed-plugin card is retained and explicitly not installed',count($removed)===1&&!$removed[0]['installed']&&!Plugin::target('removed-qa/plugin.php'));
    ccpp_http('/wp-login.php');$login=ccpp_http('/wp-login.php','POST',['log'=>$credentials['user'],'pwd'=>$credentials['password'],'wp-submit'=>'Log In','testcookie'=>'1','redirect_to'=>$base.'/wp-admin/']);
    ccpp_verify('Real HTTP administrator login',$login['status']===302);
    $page=ccpp_http('/wp-admin/options-general.php?page=ccpp-cards&plugin='.rawurlencode($target));
    ccpp_verify('Native private card and removed-card overview render',$page['status']===200&&str_contains($page['body'],'QA operational card')&&str_contains($page['body'],'removed-qa/plugin.php'));
    $post=array_merge(ccpp_form($page['body'],'ccpp_save'),$fields);$unchanged=Plugin::state();
    ccpp_verify('GET cannot mutate',ccpp_http('/wp-admin/admin-post.php?action=ccpp_save')['status']===403&&Plugin::state()===$unchanged);
    $missing=$post;unset($missing['_wpnonce']);ccpp_verify('Missing nonce rejected',ccpp_http('/wp-admin/admin-post.php','POST',$missing)['status']===403&&Plugin::state()===$unchanged);
    $bad=$post;$bad['_wpnonce']='bad';ccpp_verify('Invalid nonce rejected',ccpp_http('/wp-admin/admin-post.php','POST',$bad)['status']===403&&Plugin::state()===$unchanged);
    $bad=$post;$bad['responsible']=['array'];ccpp_verify('Actual HTTP array payload rejects without state change',ccpp_http('/wp-admin/admin-post.php','POST',$bad)['status']===400&&Plugin::state()===$unchanged);
    $post['purpose']='HTTP updated operational purpose';$http=ccpp_http('/wp-admin/admin-post.php','POST',$post);
    ccpp_verify('Authorized native POST saves and redirects',$http['status']===302&&Plugin::state()['cards'][$target]['purpose']===$post['purpose']);
    ccpp_verify('Repeated stale HTTP form receives409',ccpp_http('/wp-admin/admin-post.php','POST',$post)['status']===409);
    $page=ccpp_http('/wp-admin/options-general.php?page=ccpp-cards');$form=ccpp_form($page['body'],'ccpp_settings');$snapshot=Plugin::state();$bad=$form;$bad['remove_data']=['1'];
    ccpp_verify('Malformed retention checkbox is400 and preserves all data',ccpp_http('/wp-admin/admin-post.php','POST',$bad)['status']===400&&Plugin::state()===$snapshot);
    $form['remove_data']='1';ccpp_verify('Explicit retention opt-in persists',ccpp_http('/wp-admin/admin-post.php','POST',$form)['status']===302&&Plugin::state()['remove_data']===true);
    $exportRequest=ccpp_form($page['body'],'ccpp_export');$download=ccpp_http('/wp-admin/admin-post.php','POST',$exportRequest);
    ccpp_verify('Authorized export is JSON attachment with no-store',$download['status']===200&&str_contains($download['headers']['cache-control']??'','no-store')&&str_contains($download['headers']['content-disposition']??'','attachment')&&json_decode($download['body'],true)['format']==='coocadev-passport');
    $anon=ccpp_http('/wp-admin/admin-post.php','POST',$exportRequest,false);
    ccpp_verify('Anonymous request cannot read private card export',!str_contains($anon['body'],'HTTP updated operational purpose'));
    $front=ccpp_http('/', 'GET', [], false);ccpp_verify('Frontend contains no card data or assets',!str_contains($front['body'],'HTTP updated operational purpose')&&!str_contains($front['body'],'ccpp-cards'));
    update_option(Plugin::OPTION,['schema'=>999,'cards'=>[]],false);$corrupt=get_option(Plugin::OPTION);
    ccpp_verify('Corrupt schema fails without resetting stored content',is_wp_error(Plugin::state())&&is_wp_error(Plugin::save($target,$fields,0))&&get_option(Plugin::OPTION)===$corrupt);
    echo 'PASS total '.count($checks)."\n";
} finally {
    update_option(Plugin::OPTION,$before,false);
    if($sub&&!is_wp_error($sub))wp_delete_user((int)$sub);
    $user=new WP_User($owner->ID);$user->remove_cap('activate_plugins');$user->remove_cap('manage_options');
    wp_set_current_user(0);if(is_file($cookie))unlink($cookie);
    clearstatcache(true,$debug);$fresh=is_file($debug)?substr(file_get_contents($debug),$debugOffset):'';
    file_put_contents($report.'/wp-debug-fresh.log',$fresh);
    file_put_contents($report.'/integration.json',json_encode(['date_utc'=>gmdate('c'),'wp'=>get_bloginfo('version'),'php'=>PHP_VERSION,'checks'=>$checks,'fresh_debug_bytes'=>strlen($fresh)],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
}
