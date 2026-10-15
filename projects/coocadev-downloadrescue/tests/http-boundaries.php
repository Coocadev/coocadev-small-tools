<?php
/** Real callbacks must reject invalid method, nonce, capability and structured data. */
use Coocadev\DownloadRescue\Plugin;
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() || ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( '127.0.0.1', 'localhost', '::1' ), true ) || ! preg_match( '~/qa/(?:min-)?sites/dispatchgo/?$~', str_replace( '\\', '/', ABSPATH ) ) ) { throw new RuntimeException( 'Isolated local QA only.' ); }
class DownloadRescueHttpStop extends RuntimeException { public $status; public function __construct($status){$this->status=$status;parent::__construct('HTTP stop');} }
add_filter('wp_die_handler',static function(){return static function($message,$title,$args){throw new DownloadRescueHttpStop((int)($args['response']??500));};},PHP_INT_MAX);
global $checks; $checks=array();
function ccdr_http($fn,$post,$status,$name,$user=1,$method='POST'){global $checks;wp_set_current_user($user);$_POST=$post;$_REQUEST=$post;$_SERVER['REQUEST_METHOD']=$method;try{$fn();throw new RuntimeException('No HTTP boundary');}catch(DownloadRescueHttpStop $e){if($e->status!==$status){throw new RuntimeException($name.': expected '.$status.', got '.$e->status);}$checks[]=array('name'=>$name,'status'=>$status,'pass'=>true);}}
wp_set_current_user(1);$nonce=wp_create_nonce('ccdr_decide_99999999');
ccdr_http(array(Plugin::class,'handle_decision'),array(),403,'logged-out approval rejected',0);
ccdr_http(array(Plugin::class,'handle_decision'),array(),403,'GET approval rejected',1,'GET');
ccdr_http(array(Plugin::class,'handle_decision'),array('ccdr_order_id'=>'99999999'),403,'missing merchant nonce rejected');
ccdr_http(array(Plugin::class,'handle_decision'),array('ccdr_order_id'=>'99999999','ccdr_nonce'=>'bad'),403,'invalid merchant nonce rejected');
foreach(array(array('approve'),'yes','0',' approve') as $decision){ccdr_http(array(Plugin::class,'handle_decision'),array('ccdr_order_id'=>'99999999','ccdr_nonce'=>$nonce,'ccdr_token'=>'test','ccdr_decision'=>$decision),400,'malformed decision rejected '.count($checks));}
ccdr_http(array(Plugin::class,'handle_decision'),array('ccdr_order_id'=>'99999999','ccdr_nonce'=>$nonce,'ccdr_token'=>array('test'),'ccdr_decision'=>'approve'),400,'token array rejected');
ccdr_http(array(Plugin::class,'handle_request'),array(),403,'logged-out request rejected',0);
ccdr_http(array(Plugin::class,'handle_request'),array(),403,'GET request rejected',1,'GET');
ccdr_http(array(Plugin::class,'handle_request'),array('order_id'=>'99999999'),403,'missing request nonce rejected');
ccdr_http(array(Plugin::class,'handle_request'),array('order_id'=>'99999999','_wpnonce'=>wp_create_nonce('ccdr_request_99999999')),409,'unknown or foreign order reveals no data');
echo wp_json_encode(array('plugin'=>'DownloadRescue','passed'=>count($checks),'tests'=>$checks),JSON_PRETTY_PRINT).PHP_EOL;
