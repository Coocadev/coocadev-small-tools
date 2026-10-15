<?php
/** Concurrent real DB client. Never shipped in plugin ZIP. */
define('WP_USE_THEMES',false);
require $argv[1].'/wp-load.php';
wp_set_current_user((int)$argv[7]);
$gate=$argv[6];
$deadline=microtime(true)+10;
while(!is_file($gate)&&microtime(true)<$deadline){usleep(10000);clearstatcache(true,$gate);}
if(!is_file($gate)){echo json_encode(['ok'=>false,'error'=>'gate timeout']);exit(1);}
$result=$argv[2]==='publish'?Coocadev\FileGo\Documents::publish((int)$argv[3],(int)$argv[4],(int)$argv[5]):Coocadev\FileGo\Documents::delete_version((int)$argv[3],(int)$argv[4],(int)$argv[5]);
echo json_encode(['ok'=>!is_wp_error($result),'error'=>is_wp_error($result)?$result->get_error_code():null]);
