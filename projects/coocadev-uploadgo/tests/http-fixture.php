<?php
require $argv[1] ?? __DIR__ . '/../../../qa/sites/uploadgo/wp-load.php';
if ( wp_get_environment_type() !== 'local' || ! str_contains( home_url(), '127.0.0.1' ) ) { exit(1); }
$credentials = array( 'url' => home_url() );
foreach ( array( 'admin' => 'administrator', 'editor' => 'editor', 'subscriber' => 'subscriber' ) as $name => $role ) {
	$login = 'ccug_http_' . $name;
	$user = get_user_by( 'login', $login );
	$password = wp_generate_password(32,false);
	$id = $user ? wp_update_user( array( 'ID'=>$user->ID,'user_pass'=>$password,'role'=>$role ) ) : wp_insert_user( array( 'user_login'=>$login,'user_pass'=>$password,'role'=>$role,'user_email'=>$login.'@example.test' ) );
	if ( is_wp_error($id) ) { throw new RuntimeException($id->get_error_message()); }
	$credentials[$name] = array('login'=>$login,'password'=>$password,'id'=>$id);
}
$credentials['application_password'] = WP_Application_Passwords::create_new_application_password( $credentials['editor']['id'], array( 'name'=>'UploadGo isolated HTTP QA' ) )[0];
$qa=dirname(__DIR__,3).'/qa';
file_put_contents($qa.'/secrets/uploadgo-http.json',wp_json_encode($credentials));
use Coocadev\UploadGo\PolicyRepository as Repo;
$s=Repo::defaults();
Repo::save($s,Repo::read()['config_revision']);
echo "Local UploadGo HTTP fixture prepared.\n";
