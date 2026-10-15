"""Separate-process activation/deactivation/preserve/delete checks on local QA only."""
from pathlib import Path
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
tests = []


def command(*arguments):
    result = subprocess.run([str(php), str(wp), "--path=" + str(site), *arguments], capture_output=True, text=True, check=True)
    return result.stdout.strip()


def check(plugin, name, result):
    tests.append({"plugin": plugin, "test": name, "passed": bool(result)})
    if not result:
        raise RuntimeError(name)


if command("eval", "echo defined('WP_CLI')&&WP_CLI&&'local'===wp_get_environment_type()&&in_array(wp_parse_url(home_url(),PHP_URL_HOST),array('127.0.0.1','localhost','::1'),true)?'local-qa':'refused';") != "local-qa":
    raise RuntimeError("Synthetic lifecycle tests require a local localhost WordPress installation")

for slug, option in [("coocadev-client-nudge", "cccn_delete_data"), ("coocadev-hoursgo", "cchg_settings"), ("coocadev-mailskin", "ccms_settings")]:
    command("plugin", "activate", slug)
    if slug == "coocadev-client-nudge":
        task = command("eval", "$id=wp_insert_post(array('post_type'=>'cccn_task','post_status'=>'private','post_title'=>'Lifecycle fixture'));update_option('cccn_delete_data',false,false);echo $id;")
    else:
        command("eval", "update_option('" + option + "',array('delete_data'=>false,'qa_marker'=>'preserve'),false);")
    command("plugin", "deactivate", slug)
    if slug == "coocadev-client-nudge":
        check(slug, "Deactivation clears reminder schedule", command("eval", "echo wp_next_scheduled('cccn_reminders')?'bad':'ok';") == "ok")
    check(slug, "Deactivation preserves data", command("eval", "echo " + ("get_post(" + task + ")" if slug == "coocadev-client-nudge" else "get_option('" + option + "',null)") + "?'ok':'bad';") == "ok")
    command("plugin", "deactivate", slug)
    command("plugin", "uninstall", slug, "--skip-delete")
    check(slug, "Default uninstall preserves data in separate process", command("eval", "echo " + ("get_post(" + task + ")" if slug == "coocadev-client-nudge" else "get_option('" + option + "',null)") + "?'ok':'bad';") == "ok")
    command("plugin", "activate", slug)
    if slug == "coocadev-client-nudge":
        check(slug, "Reactivation reschedules future worker", command("eval", "echo wp_next_scheduled('cccn_reminders')?'ok':'bad';") == "ok")
        command("eval", "update_option('cccn_delete_data',true,false);")
    else:
        command("eval", "update_option('" + option + "',array('delete_data'=>true,'qa_marker'=>'delete'),false);")
    command("plugin", "deactivate", slug)
    command("plugin", "uninstall", slug, "--skip-delete")
    check(slug, "Opted-in uninstall deletes own data in separate process", command("eval", "echo " + ("get_post(" + task + ")" if slug == "coocadev-client-nudge" else "get_option('" + option + "',null)") + "?'bad':'ok';") == "ok")
    command("plugin", "activate", slug)

for slug in ("coocadev-client-nudge", "coocadev-hoursgo", "coocadev-mailskin"):
    (root / "projects" / slug / "reports/0.2.0/lifecycle.json").write_text(json.dumps({"site": str(site), "tests": [item for item in tests if item["plugin"] == slug]}, indent=2))
print(json.dumps({"tests": tests}, indent=2))
