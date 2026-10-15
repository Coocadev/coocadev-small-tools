"""Stage only audited source for publication; never copy a maintainer workspace.

Copyright 2026 Coocadev. SPDX-License-Identifier: GPL-2.0-or-later.
Run after tests and package verification. An existing staging tree is refused:
choose a fresh target, or review/remove it separately within your workspace.
"""
import argparse
import hashlib
import json
import re
import shutil
import zipfile
from pathlib import Path

parser = argparse.ArgumentParser()
parser.add_argument('--destination', required=True)
args = parser.parse_args()
root = Path(__file__).resolve().parents[1]
destination = Path(args.destination).resolve()
if destination.exists():
    raise SystemExit('Refusing to replace an existing staging directory.')
if destination == root or root in destination.parents:
    raise SystemExit('Public source must be staged outside the maintainer source tree.')
catalog = json.loads((root / 'catalog.json').read_text(encoding='utf-8'))
approved_tests = set(json.loads((root / 'qa/public-test-files.json').read_text(encoding='utf-8'))['files'])
files = []
top = ['README.md', 'LICENSE', 'OPEN_SOURCE.md', 'CONTRIBUTING.md', 'SECURITY.md', 'catalog.json', 'PACKAGE_REPORT.md']
for name in top:
    files.append(root / name)
for product in catalog['products']:
    project = root / 'projects' / product['slug']
    runtime = project / 'plugin'
    for path in runtime.rglob('*'):
        if not path.is_file():
            continue
        relative = path.relative_to(runtime).as_posix()
        pattern = re.escape(product['slug']) + r'\.php|uninstall\.php|readme\.txt|LICENSE|(src|assets|blocks)/[a-zA-Z0-9_./-]+\.(php|js|css|json)'
        if not re.fullmatch(pattern, relative):
            raise SystemExit('Unexpected runtime file: ' + str(path))
        files.append(path)
    for name in ('README.md', 'DEVELOPER.md', 'DEPENDENCIES.md', 'PLAN.md', 'ARCHITECTURE.md'):
        path = project / name
        if path.exists():
            files.append(path)
    # Test source paths are individually reviewed and explicitly listed. A new
    # credentials.py/private.php cannot become public merely by its extension.
    prefix = 'projects/' + product['slug'] + '/tests/'
    for relative in sorted(approved_tests):
        if relative.startswith(prefix):
            if Path(relative).is_absolute() or '..' in Path(relative).parts or '\\' in relative:
                raise SystemExit('Invalid approved test path.')
            path = root / relative
            if not path.is_file() or path.suffix not in ('.php', '.js', '.cjs', '.mjs', '.py', '.ps1'):
                raise SystemExit('Missing or unsupported approved test: ' + relative)
            files.append(path)
    report = project / 'reports' / catalog['version'] / 'RELEASE_REPORT.md'
    if report.exists():
        files.append(report)
for name in ('README.md', 'public-test-files.json', 'build-packages.ps1', 'verify-packages.ps1', 'scanners-test.php', 'combined-smoke.php', 'debug-probe.php', 'debug-adapter-test.cjs', 'browser-all-tools.cjs', 'browser-fixtures-020.php', 'compatibility-report.php', 'local-mail-sink.php', 'stage-public-source.py'):
    path = root / 'qa' / name
    if path.exists():
        files.append(path)
# Original maintainer research/plans refer to private review correspondence.
# Public runtime readmes and English developer guides define the release scope.
# This is a public engineering audit, not the private review-email registry.
for name in ('WP_REVIEW_CROSSWALK.md', 'DEBUG_REPORT.md', 'PUBLIC_SOURCE_REVIEW.md'):
    path = root / 'reports' / catalog['version'] / name
    if path.exists():
        files.append(path)
for path in (root / '.vscode').glob('*.json'):
    files.append(path)
workspace = root / 'Coocadev Small Tools.code-workspace'
if workspace.exists():
    files.append(workspace)

forbidden = re.compile(r'(^|/)(?:secrets|private-mail|databases|dbdata|node_modules|vendor|downloads|runtime|\.git)(/|$)|(?:auth\.json|wp-config\.php|\.env(?:\.|$)|\.runtime-fixture\.json|cookies?\.)', re.I)
secret_patterns = [
    re.compile(r'-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----'),
    re.compile(r'\bgh[pousr]_[A-Za-z0-9]{25,}\b'),
    re.compile(r'\bgithub_pat_[A-Za-z0-9_]{30,}\b'),
    re.compile(r'\bsk-(?:proj-)?[A-Za-z0-9_-]{30,}\b'),
    re.compile(r'\bya29\.[A-Za-z0-9_-]{25,}\b'),
]
manifest = []
for path in sorted(set(files)):
    relative = path.relative_to(root).as_posix()
    if forbidden.search(relative) or not path.resolve().is_relative_to(root):
        raise SystemExit('Forbidden source path: ' + relative)
    for ancestor in (path, *path.parents):
        if ancestor == root:
            break
        if ancestor.is_symlink() or ancestor.is_junction():
            raise SystemExit('Reparse path in source: ' + relative)
    data = path.read_bytes()
    text = data.decode('utf-8-sig')
    if any(pattern.search(text) for pattern in secret_patterns):
        raise SystemExit('Potential secret in ' + relative + '; inspect locally without printing it.')
    manifest.append({'path': relative, 'bytes': len(data), 'sha256': hashlib.sha256(data).hexdigest()})
destination.mkdir(parents=True)
for record in manifest:
    target = destination / record['path']
    target.parent.mkdir(parents=True, exist_ok=True)
    shutil.copyfile(root / record['path'], target)
(destination / '.gitignore').write_text('''# Local generated/test material never belongs in this repository.
.env
.env.*
!.env.example
**/.runtime-fixture.json
**/*private-fixture.json
**/*credentials*.json
**/*cookies*
**/*.log
**/node_modules/
**/vendor/
qa/sites/
qa/min-sites/
qa/secrets/
qa/min-secrets/
qa/runtime/
qa/downloads/
qa/results/
artifacts/
''', encoding='utf-8')
# Preserve the audited bytes regardless of a contributor's global autocrlf setting.
(destination / '.gitattributes').write_text('* -text\n', encoding='utf-8')
(destination / 'SOURCE_MANIFEST.json').write_text(json.dumps({'version': catalog['version'], 'license': catalog['license'], 'files': manifest}, indent=2) + '\n', encoding='utf-8')
archive = root / 'artifacts' / catalog['version'] / ('coocadev-small-tools-source-' + catalog['version'] + '.zip')
archive.parent.mkdir(parents=True, exist_ok=True)
with zipfile.ZipFile(archive, 'w', zipfile.ZIP_DEFLATED) as out:
    for path in sorted(destination.rglob('*')):
        if path.is_file():
            info = zipfile.ZipInfo('coocadev-small-tools-' + catalog['version'] + '/' + path.relative_to(destination).as_posix(), (2026, 10, 15, 0, 0, 0))
            info.compress_type = zipfile.ZIP_DEFLATED
            out.writestr(info, path.read_bytes())
print(json.dumps({'destination': str(destination), 'files': len(manifest) + 3, 'archive': str(archive), 'bytes': archive.stat().st_size, 'sha256': hashlib.sha256(archive.read_bytes()).hexdigest()}, indent=2))
