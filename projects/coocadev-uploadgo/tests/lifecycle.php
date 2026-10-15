<?php
// Local, disposable QA only. Never package this file.
require $argv[1] ?? dirname(__DIR__, 3) . '/qa/sites/uploadgo/wp-load.php';
if (wp_get_environment_type() !== 'local' || !str_contains(home_url(), '127.0.0.1')) { throw new RuntimeException('Local QA only.'); }
require_once ABSPATH . 'wp-admin/includes/plugin.php';
use Coocadev\UploadGo\PolicyRepository as Repo;
set_exception_handler(static function(Throwable $error): void { fwrite(STDERR, $error->getMessage() . "\n"); exit(1); });
if (($argv[2] ?? '') === '--uninstall') { uninstall_plugin('coocadev-uploadgo/coocadev-uploadgo.php'); exit(0); }
$checks = [];
function ccug_test_check(string $name, bool $pass): void { global $checks; if (!$pass) { throw new RuntimeException($name); } $checks[] = $name; }
function ccug_test_uninstall(): void {
    $process = proc_open([PHP_BINARY, __FILE__, ABSPATH . 'wp-load.php', '--uninstall'], [1=>['pipe','w'],2=>['pipe','w']], $pipes);
    $out = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process) !== 0 || $error !== '' || $out !== '') { throw new RuntimeException('Core uninstall subprocess failed: ' . $out . $error); }
    wp_cache_delete('ccug_settings','options'); wp_cache_delete('ccug_diagnostics','options'); wp_cache_delete('notoptions','options'); wp_cache_delete('alloptions','options');
}
$plugin = 'coocadev-uploadgo/coocadev-uploadgo.php';
$old = get_option('ccug_settings', false);
$was_active = is_plugin_active($plugin);
$attachment = 0;
try {
    $fixture = dirname(__DIR__, 3) . '/qa/results/uploadgo-fixtures/valid.png';
    $upload = wp_upload_bits('uploadgo-lifecycle.png', null, file_get_contents($fixture));
    ccug_test_check('test creates real media fixture', empty($upload['error']));
    $attachment = wp_insert_attachment(['post_mime_type'=>'image/png', 'post_title'=>'UploadGo lifecycle fixture', 'post_status'=>'inherit'], $upload['file']);
    update_option('ccug_qa_neighbor', 'preserve-me', false);
    $policy = Repo::defaults(); $policy['enabled'] = true; $policy['global']['max_bytes'] = 123456;
    update_option('ccug_settings', $policy, false);
    update_option('ccug_diagnostics', [['time'=>time(),'code'=>'test','channel'=>'upload']], false);
    wp_schedule_single_event(time()+90000,'ccug_prune_diagnostics');
    deactivate_plugins($plugin);
    ccug_test_check('deactivation preserves upload policies', get_option('ccug_settings') === $policy);
    ccug_test_check('deactivation clears optional diagnostics', get_option('ccug_diagnostics', false) === false);
    ccug_test_check('deactivation removes scheduled cleanup', wp_next_scheduled('ccug_prune_diagnostics') === false);
    ccug_test_check('reactivation succeeds', !is_wp_error(activate_plugin($plugin)));
    ccug_test_check('reactivation preserves existing policy', get_option('ccug_settings') === $policy);
    deactivate_plugins($plugin);
    ccug_test_uninstall();
    ccug_test_check('core uninstall keeps policy by default', get_option('ccug_settings') === $policy);
    activate_plugin($plugin);
    $policy['remove_data_on_uninstall'] = true; update_option('ccug_settings',$policy,false);
    update_option('ccug_diagnostics', [['time'=>time(),'code'=>'test','channel'=>'upload']], false);
    deactivate_plugins($plugin);
    ccug_test_uninstall();
    ccug_test_check('opted-in core uninstall removes own policy', get_option('ccug_settings',false) === false);
    ccug_test_check('opted-in uninstall removes own diagnostics', get_option('ccug_diagnostics',false) === false);
    ccug_test_check('uninstall leaves unrelated settings intact', get_option('ccug_qa_neighbor') === 'preserve-me');
    ccug_test_check('uninstall preserves WordPress media and file', get_post($attachment) !== null && is_file($upload['file']));
    ccug_test_check('fresh reactivation succeeds', !is_wp_error(activate_plugin($plugin)));
    ccug_test_check('fresh reactivation restores disabled defaults', get_option('ccug_settings') === Repo::defaults());
    echo wp_json_encode(['passed'=>count($checks),'checks'=>$checks],JSON_PRETTY_PRINT) . "\n";
} finally {
    if ($old === false) { delete_option('ccug_settings'); } else { update_option('ccug_settings',$old,false); }
    delete_option('ccug_qa_neighbor'); delete_option('ccug_diagnostics'); wp_clear_scheduled_hook('ccug_prune_diagnostics');
    if ($attachment) { wp_delete_attachment($attachment,true); }
    if ($was_active) { activate_plugin($plugin); } else { deactivate_plugins($plugin); }
}
