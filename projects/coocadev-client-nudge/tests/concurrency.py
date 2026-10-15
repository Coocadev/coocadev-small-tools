"""Confirm eight local WP-CLI workers accept only one weekly reminder.

Every child intercepts wp_mail before transport. Never run against production.
"""
from pathlib import Path
from concurrent.futures import ThreadPoolExecutor
import argparse
import json
import subprocess

parser = argparse.ArgumentParser()
parser.add_argument("--root", required=True)
args = parser.parse_args()
root = Path(args.root)
site = root / "qa/sites/reader-notes"
php = root / "qa/runtime/php81/php.exe"
wp = root / "qa/downloads/wp-cli.phar"
guard = "if(!defined('WP_CLI')||!WP_CLI||'local'!==wp_get_environment_type()||!in_array(wp_parse_url(home_url(),PHP_URL_HOST),array('127.0.0.1','localhost','::1'),true)){throw new RuntimeException('Local QA only');}"


def cli(code):
    result = subprocess.run([str(php), str(wp), "--path=" + str(site), "eval", guard + code], capture_output=True, text=True, check=True)
    return result.stdout.strip()


fixture = json.loads(cli("$u='cccn_race_'.wp_generate_password(8,false);$id=wp_create_user($u,wp_generate_password(),$u.'@example.test');update_user_meta($id,Coocadev\\ClientNudge\\Plugin::consent_key(),'1');$post=wp_insert_post(array('post_type'=>'cccn_task','post_status'=>'private','post_title'=>'Concurrency fixture','meta_input'=>array('cccn_assignee'=>$id,'cccn_due'=>'2026-11-01','cccn_done'=>'0')));echo wp_json_encode(array('user'=>$id,'post'=>$post));"))
try:
    code = "add_filter('pre_wp_mail','__return_true');echo Coocadev\\ClientNudge\\Plugin::reminders();"
    with ThreadPoolExecutor(max_workers=8) as executor:
        accepted = [int(value) for value in executor.map(lambda _: cli(code), range(8))]
    report = {"workers": 8, "accepted_per_worker": accepted, "total_accepted": sum(accepted), "passed": sum(accepted) == 1}
    (root / "projects/coocadev-client-nudge/reports/0.2.0/concurrency.json").write_text(json.dumps(report, indent=2))
    print(json.dumps(report, indent=2))
    if not report["passed"]:
        raise RuntimeError("Expected exactly one atomic weekly acceptance")
finally:
    cli("wp_delete_post(" + str(fixture["post"]) + ",true);require_once ABSPATH.'wp-admin/includes/user.php';wp_delete_user(" + str(fixture["user"]) + ");")
