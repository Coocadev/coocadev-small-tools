<?php
/** Real separate WordPress process for concurrency regression tests. */
require $argv[1] . '/wp-load.php';
wp_set_current_user((int)$argv[2]);
$job=json_decode($argv[3],true);$deadline=microtime(true)+15;
while(!file_exists($argv[4])&&microtime(true)<$deadline){usleep(10000);}
if($job['op']==='delete')$r=Coocadev\DoneMark\Settings::delete($job['mark'],$job['revision']);
elseif($job['op']==='postdelete')$r=wp_delete_post($job['post'],true);
else $r=Coocadev\DoneMark\States::write($job['post'],$job['mark'],$job['done'],$job['version'],$job['hash']);
echo json_encode(['ok'=>!is_wp_error($r),'error'=>is_wp_error($r)?$r->get_error_code():null]);
