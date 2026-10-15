"""Exercise actual local HTTP boundaries for the three independent plugins.

No credentials are printed or stored. Fresh synthetic QA accounts are generated
in memory and removed afterward. Outbound email is never requested.
"""
from pathlib import Path
import argparse
import http.cookiejar
import json
import re
import subprocess
import urllib.error
import urllib.parse
import urllib.request

parser = argparse.ArgumentParser()
parser.add_argument("--root", required=True)
args = parser.parse_args()
root = Path(args.root)
base = "http://127.0.0.1:8783/"
site = root / "qa/sites/reader-notes"
php = root / "qa/runtime/php81/php.exe"
wp = root / "qa/downloads/wp-cli.phar"
results = []


def cli(code):
    guard = "if(!defined('WP_CLI')||!WP_CLI||'local'!==wp_get_environment_type()||!in_array(wp_parse_url(home_url(),PHP_URL_HOST),array('127.0.0.1','localhost','::1'),true)){throw new RuntimeException('Local QA only');}"
    result = subprocess.run([str(php), str(wp), "--path=" + str(site), "eval", guard + code], capture_output=True, text=True, check=True)
    return result.stdout.strip()


def session(user, password):
    opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    opener.open(base + "wp-login.php").read()
    data = urllib.parse.urlencode({"log": user, "pwd": password, "wp-submit": "Log In", "redirect_to": base + "wp-admin/", "testcookie": "1"}).encode()
    opener.open(base + "wp-login.php", data).read()
    return opener


def request(opener, path, fields=None):
    data = None if fields is None else urllib.parse.urlencode(fields).encode()
    try:
        response = opener.open(base + path, data)
        return response.status, response.read().decode()
    except urllib.error.HTTPError as error:
        return error.code, error.read().decode()


def check(product, name, value):
    results.append({"plugin": product, "test": name, "passed": bool(value)})
    if not value:
        raise RuntimeError(name)


admin_fixture = json.loads(cli("$u='cccn_admin_'.wp_generate_password(8,false);$p=wp_generate_password();$id=wp_create_user($u,$p,$u.'@example.test');wp_update_user(array('ID'=>$id,'role'=>'administrator'));echo wp_json_encode(array('id'=>$id,'user'=>$u,'password'=>$p));"))
admin = session(admin_fixture["user"], admin_fixture["password"])
fixture = json.loads(cli("$u='cccn_http_'.wp_generate_password(8,false);$p=wp_generate_password();$id=wp_create_user($u,$p,$u.'@example.test');echo wp_json_encode(array('id'=>$id,'user'=>$u,'password'=>$p));"))
client = session(fixture["user"], fixture["password"])
old = cli("echo wp_json_encode(array('ccms_settings'=>get_option('ccms_settings',null),'cchg_settings'=>get_option('cchg_settings',null),'cccn_delete_data'=>get_option('cccn_delete_data',null)));")
try:
    for product, page, action, operation, malformed in [
        ("coocadev-client-nudge", "tools.php?page=cccn-tasks", "cccn_action", "consent", [("enabled[]", "1")]),
        ("coocadev-hoursgo", "options-general.php?page=cchg-hours", "cchg_save", None, [("week[]", "bad")]),
        ("coocadev-mailskin", "options-general.php?page=ccms-skin", "ccms_save", None, [("enabled[]", "1")]),
    ]:
        status, html = request(admin, "wp-admin/" + page)
        check(product, "Administrator settings renders over HTTP", status == 200)
        nonce = re.search(r'name="_wpnonce" value="([^"]+)"', html).group(1)
        fields = [("action", action), ("_wpnonce", nonce)]
        if operation:
            fields.append(("operation", operation))
        status, _ = request(admin, "wp-admin/admin-post.php?action=" + action)
        check(product, "GET mutation denied", status == 403)
        status, _ = request(admin, "wp-admin/admin-post.php", [("action", action), ("_wpnonce", "wrong")])
        check(product, "Invalid nonce denied", status == 403)
        status, _ = request(admin, "wp-admin/admin-post.php", fields + malformed)
        check(product, "Malformed array payload rejected", status == 400)
        if product != "coocadev-client-nudge":
            status, _ = request(client, "wp-admin/admin-post.php", fields)
            check(product, "Subscriber settings mutation denied", status == 403)
        else:
            status, board = request(client, "wp-admin/" + page)
            client_nonce = re.search(r'name="_wpnonce" value="([^"]+)"', board).group(1)
            status, _ = request(client, "wp-admin/admin-post.php", [("action", action), ("_wpnonce", client_nonce), ("operation", "create"), ("title", "Forbidden"), ("detail", "Never persisted"), ("assignee", str(fixture["id"])), ("due", "2026-11-01")])
            check(product, "Subscriber cannot create client task", status == 400)
            check(product, "Client consent form survives HTML allowlist", "enabled" in board and "cccn_action" in board)
    check("all-three", "Invalid HTTP mutations leave persisted settings unchanged", old == cli("echo wp_json_encode(array('ccms_settings'=>get_option('ccms_settings',null),'cchg_settings'=>get_option('cchg_settings',null),'cccn_delete_data'=>get_option('cccn_delete_data',null)));"))
    output = {"base": base, "tests": results}
    for product in ("coocadev-client-nudge", "coocadev-hoursgo", "coocadev-mailskin"):
        (root / "projects" / product / "reports/0.2.0/http-boundaries.json").write_text(json.dumps({"base": base, "tests": [item for item in results if item["plugin"] in (product, "all-three")]}, indent=2))
    print(json.dumps(output, indent=2))
finally:
    cli("require_once ABSPATH.'wp-admin/includes/user.php';wp_delete_user(" + str(fixture["id"]) + ");wp_delete_user(" + str(admin_fixture["id"]) + ");")
