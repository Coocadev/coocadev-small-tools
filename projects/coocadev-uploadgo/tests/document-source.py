from pathlib import Path
import re

base = Path(__file__).resolve().parents[1] / 'plugin'
purpose = {
'AdminPage': {
 'register':'Connect native settings, guarded handlers and editor guidance.',
 'guard':'Authenticate a POST and capture only validated allowlisted controls.',
 'revision':'Return the revision captured by the verified request guard.',
 'checked':'Read a checkbox token already validated by the request guard.',
 'form_policy':'Validate a canonical form policy without silently dropping malformed fields.',
 'save':'Validate the complete policy form and perform a revision-aware save.',
 'finish':'Render a safe settings error or redirect after a confirmed operation.',
 'reset':'Reset only UploadGo settings after explicit confirmation and revision checking.',
 'clear':'Clear only local diagnostics after administrator and nonce checks.',
 'export':'Download a JSON policy after administrator and nonce checks.',
 'enqueue':'Load local assets only on the relevant settings or media screens.',
 'plupload':'Narrow the client uploader size limit without raising Core limits.',
 'hint_text':'Describe the current reader-visible effective upload limits.',
 'hint':'Render escaped upload guidance on the native upload screen.',
 'policy_fields':'Render escaped controls for one global or role-specific policy.',
 'render':'Render the administrator settings form, effective previews and diagnostics.',
},
'FileInspector': {'inspect':'Validate real upload bytes, type and bounded image dimensions against the effective policy.'},
'PolicyRepository': {
 'empty_policy':'Return neutral limits that never broaden WordPress permissions.',
 'defaults':'Return the disabled first-install configuration.',
 'read':'Read the authoritative stored policy, falling back explicitly to Core-only behavior if invalid.',
 'validate':'Validate the versioned configuration and every role policy.',
 'validate_policy':'Validate positive numeric bounds and explicit file-family selections.',
 'save':'Replace only the exact previous stored revision to prevent lost settings updates.',
},
'PolicyResolver': {
 'families':'Map explicit file families to their allowed MIME types.',
 'resolve':'Intersect global and authenticated role policies using the strictest limits.',
 'current':'Resolve policy for the actual current site and authenticated user.',
 'allows_mime':'Apply permanent executable-type exclusions and configured family intersections.',
},
'UploadHooks': {
 'register':'Attach the same server policy to the supported Core upload paths.',
 'finalize':'Reinspect attachment-owned files and normalize their metadata before Core finalize.',
 'upload':'Apply policy to a native multipart upload without replacing upstream errors.',
 'sideload':'Apply policy to the native sideload path using the actual current user.',
 'check':'Preserve upstream failures and report a policy violation without deleting files.',
 'mimes':'Intersect the incoming Core MIME allowlist with effective policy.',
 'size':'Only lower the incoming Core upload size limit.',
 'record':'Append a bounded local reason-code event when diagnostics are opted in.',
 'prune_diagnostics':'Physically remove expired diagnostics when scheduled cleanup runs.',
 'deactivate':'Clear diagnostic state and its scheduled cleanup without changing uploaded media.',
}}
untyped = {'response':'mixed','request':'\\WP_REST_Request','route':'string','handler':'array','size':'int','input':'mixed','result':'mixed','network_wide':'bool'}
results = {'inspect':'bool|\\WP_Error','form_policy':'array|\\WP_Error','validate':'array|\\WP_Error','validate_policy':'array|\\WP_Error','save':'array|\\WP_Error','finalize':'mixed','size':'int'}
for file in (base/'src').glob('*.php'):
 text=file.read_text(encoding='utf-8')
 klass=re.search(r'final class (\w+)',text).group(1)
 lines=text.splitlines(); out=[]
 for line in lines:
  if line.startswith('final class') and not (out and out[-1].strip()=='*/'):
   out += ['/**',' * '+{'AdminPage':'Administrator controls and effective-policy guidance.','PolicyResolver':'Stateless policy intersection for the current site and user.','PolicyRepository':'Versioned settings validation and optimistic-concurrency storage.','FileInspector':'Conservative local file inspection before native Core processing.','UploadHooks':'Policy adapters for authenticated Core uploads and optional background work.'}[klass],' */']
  match=re.match(r'^(\t)(?:public|private) static function (\w+)\((.*?)\)(?:: ([^ ]+))? \{',line)
  if match and match[2] in purpose[klass]:
   if out and out[-1].strip().endswith('*/'):
    pos=len(out)-1
    while pos>=0 and '/**' not in out[pos]: pos-=1
    if pos>=0 and all(s.startswith('\t') for s in out[pos:]): out=out[:pos]
   indent,name,params,result=match.groups()
   doc=[indent+'/**',indent+' * '+purpose[klass][name],indent+' *']
   for part in params.split(','):
    param=re.search(r'(?:(\??[\\\w|]+)\s+)?\$(\w+)',part)
    if param:
     typ,key=param.groups();typ=typ or untyped.get(key,'mixed')
     if typ.startswith('?'):typ=typ[1:]+'|null'
     doc.append(indent+' * @param '+typ+' $'+key+' '+key.replace('_',' ').capitalize()+'.')
   doc += [indent+' * @return '+(result or results.get(name,'void'))+' Operation result.',indent+' */']
   out+=doc
  if 'phpcs:ignore WordPress.DB.DirectDatabaseQuery' in line:
   continue
  out.append(line)
 file.write_text('\n'.join(out)+'\n',encoding='utf-8')
