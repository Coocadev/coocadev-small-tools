<?php
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
