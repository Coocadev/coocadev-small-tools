from pathlib import Path
import re
base=Path(__file__).resolve().parents[1]/"plugin"
purposes={
"table":"Return an allowlisted per-site table identifier.",
"activate":"Install tables for a single site and reject unsupported network activation.",
"install":"Create the plugin's version-one schema using the site's charset.",
"healthy":"Verify both owned tables actually support InnoDB transactions.",
"state":"Read the owner's erasure epoch without creating any state.",
"error":"Create an owner-safe REST-compatible application error.",
"begin":"Begin a transaction and lock the owner's state before any mutation.",
"finish":"Commit a complete operation or roll back its failure.",
"barrier":"Reject stale drafts and any write during an active erasure.",
"content":"Validate bounded Unicode plain text and normalize line endings.",
"by_post":"Read the current owner's note bound to one source article.",
"one":"Read one note only when its owner matches the authenticated subject.",
"save":"Create or compare-and-swap a note under the owner's epoch lock.",
"delete":"Delete an owned note only at the expected version and epoch.",
"listing":"Return a bounded private page using a stable newest-created ID cursor.",
"erase":"Commit an epoch barrier, then erase an operation-bound batch safely.",
"member":"Require the read capability and membership of the current site.",
"post":"Check source access for an explicit reader, including privacy exports.",
"public_note":"Return owned note fields, hiding metadata of inaccessible sources.",
"same_origin":"Reject cross-origin browser requests without broadening CORS.",
"register":"Register private REST routes with shared authentication permissions.",
"permission":"Require an authenticated site member and reject client-selected owners.",
"headers":"Apply no-store headers to private successes and errors.",
"no_cache":"Prevent shared caching and CORS disclosure of personal responses.",
"bootstrap":"Deliver only session state and a REST nonce; never return note text.",
"body":"Accept a bounded JSON object containing only allowlisted fields.",
"integer":"Validate a real JSON integer without coercing arrays or strings.",
"query_int":"Validate a bounded non-negative cursor from a query string.",
"present":"Prime source objects in a batch and apply reader-specific visibility.",
"export":"Return one bounded page of owned notes for a client-side text export.",
"boot":"Connect plugin features to the relevant WordPress lifecycle hooks.",
"settings":"Read settings with an unlimited default owner quota.",
"init":"Register local assets, dynamic blocks and classic shortcodes.",
"assets":"Enqueue only the feature's assets with entirely non-personal configuration.",
"shell":"Return a cacheable neutral shell with no personal note or nonce.",
"note_block":"Bind a note shell to the source article in block context.",
"shortcode":"Bind a classic note shell to the current singular article.",
"list_block":"Return a cacheable shell for the reader's personal note list.",
"automatic":"Append an opted-in note shell only to supported singular content.",
"menu":"Register settings and personal notes screens with distinct capabilities.",
"admin_init":"Describe the plugin's real data handling to the privacy-policy editor.",
"notice":"Show storage diagnostics only on the plugin settings screen.",
"settings_page":"Render escaped administrator settings and an authenticated form.",
"save_settings":"Validate and atomically replace administrator settings by revision.",
"my_notes":"Render the authenticated personal-management page's neutral shell.",
"exporters":"Register the WordPress personal-data exporter.",
"privacy_export":"Export only the requested subject's notes and permitted source metadata.",
"erasers":"Register the WordPress personal-data eraser.",
"privacy_erase":"Continue bounded erasure while preserving the stale-write barrier.",
"detach":"Keep note text while removing a permanently deleted source link.",
"delete_user":"Erase account notes and keep a write-blocking epoch tombstone.",
"remove_member":"Erase notes in the site whose membership is being removed."
}
for file in (base/"src").glob("*.php"):
 text=file.read_text(encoding="utf-8")
 lines=text.splitlines(); out=[]
 for line in lines:
  match=re.match(r"^(\t)(?:public|private) static function (\w+)\((.*?)\): ([^ ]+) \{",line)
  if match:
   if out and out[-1].strip().endswith("*/"):
    pos=len(out)-1
    while pos>=0 and "/**" not in out[pos]: pos-=1
    if pos>=0 and all(s.startswith("\t") for s in out[pos:]): out=out[:pos]
   indent,name,params,result=match.groups()
   doc=[indent+"/**",indent+" * "+purposes.get(name,"Perform the documented plugin operation."),indent+" *"]
   for param in re.finditer(r"([?\\\w|]+)\s+\$(\w+)",params):
    typ,key=param.groups()
    if typ.startswith("?"): typ=typ[1:]+"|null"
    doc.append(indent+" * @param "+typ+" $"+key+" "+key.replace("_"," ").capitalize()+".")
   doc.append(indent+" * @return "+result+" Operation result.")
   doc.append(indent+" */")
   out.extend(doc)
  if line.startswith("final class"):
   out.extend(["/**"," * "+file.stem+" module for private reader notes."," */"])
  out.append(line)
 file.write_text("\n".join(out)+"\n",encoding="utf-8")

