<?php
/** Preserve requests on uninstall; never manipulate download entitlement implicitly. */
use Coocadev\DownloadRescue\Plugin;
if(!defined('WP_CLI')||!WP_CLI||'local'!==wp_get_environment_type()||!in_array(wp_parse_url(home_url(),PHP_URL_HOST),array('127.0.0.1','localhost','::1'),true)||!preg_match('~/qa/(?:min-)?sites/dispatchgo/?$~',str_replace('\\','/',ABSPATH))){throw new RuntimeException('Reserved local WP-CLI QA only.');}
require_once ABSPATH.'wp-admin/includes/plugin.php';$file='coocadev-downloadrescue/coocadev-downloadrescue.php';$order=wc_create_order();$id=$order->get_id();$state=array('status'=>'pending','at'=>time(),'token'=>wp_generate_uuid4(),'permission_ids'=>array());$order->update_meta_data('_ccdr_request',$state);$order->update_meta_data('_ccdr_status','pending');$order->save();$checks=array();
try{
 deactivate_plugins($file);if($state!==wc_get_order($id)->get_meta('_ccdr_request',true)){throw new RuntimeException('Deactivation lost request');}$checks[]='deactivate preserves request';
 uninstall_plugin($file);if($state!==wc_get_order($id)->get_meta('_ccdr_request',true)){throw new RuntimeException('Uninstall lost request');}$checks[]='native uninstall preserves request';
 activate_plugins($file);if($state!==wc_get_order($id)->get_meta('_ccdr_request',true)){throw new RuntimeException('Reactivation changed request');}$checks[]='reactivation preserves request';
 $order->delete(true);if(wc_get_order($id)){throw new RuntimeException('Order not deleted');}$checks[]='native permanent deletion completes safely';
}finally{activate_plugins($file);$fresh=wc_get_order($id);if($fresh){$fresh->delete(true);}}
echo wp_json_encode(array('plugin'=>'DownloadRescue','passed'=>count($checks),'tests'=>$checks),JSON_PRETTY_PRINT).PHP_EOL;
