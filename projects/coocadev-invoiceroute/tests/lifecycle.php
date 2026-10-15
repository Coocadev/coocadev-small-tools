<?php
/** Native deactivate/uninstall/reinstall preserve the commercial receipt policy. */
use Coocadev\InvoiceRoute\Plugin;
if(!defined('WP_CLI')||!WP_CLI||'local'!==wp_get_environment_type()||!in_array(wp_parse_url(home_url(),PHP_URL_HOST),array('127.0.0.1','localhost','::1'),true)||!preg_match('~/qa/(?:min-)?sites/dispatchgo/?$~',str_replace('\\','/',ABSPATH))){throw new RuntimeException('Reserved local WP-CLI QA only.');}
require_once ABSPATH.'wp-admin/includes/plugin.php';$file='coocadev-invoiceroute/coocadev-invoiceroute.php';$order=wc_create_order();$id=$order->get_id();$checks=array();
try{
 if('lifecycle@example.invalid'!==Plugin::freeze($order,'lifecycle@example.invalid')){throw new RuntimeException('Receipt fixture failed');}$checks[]='fresh durable receipt';
 deactivate_plugins($file);if('lifecycle@example.invalid'!==Plugin::receipt($id)){throw new RuntimeException('Deactivation lost receipt');}$checks[]='deactivate preserves commercial receipt';
 uninstall_plugin($file);if('lifecycle@example.invalid'!==Plugin::receipt($id)){throw new RuntimeException('Uninstall lost receipt');}$checks[]='native uninstall retains historical receipt';
 activate_plugins($file);if('lifecycle@example.invalid'!==Plugin::freeze($order,'changed@example.invalid')){throw new RuntimeException('Reactivation replaced receipt');}$checks[]='reactivation preserves immutable winner';
 $order->delete(true);if(null!==Plugin::receipt($id)){throw new RuntimeException('Permanent-delete cleanup failed');}$checks[]='permanent delete removes receipt';
}finally{activate_plugins($file);$fresh=wc_get_order($id);if($fresh){$fresh->delete(true);}}
echo wp_json_encode(array('plugin'=>'InvoiceRoute','passed'=>count($checks),'tests'=>$checks),JSON_PRETTY_PRINT).PHP_EOL;
