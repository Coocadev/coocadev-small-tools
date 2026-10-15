import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'../../..');
const credentials=JSON.parse(fs.readFileSync(path.join(root,'qa/secrets/uploadgo-http.json')));
const base=credentials.url;
const results=[];
function check(name,condition){if(!condition)throw new Error(name); results.push(name); console.log('PASS '+name);}
class Session{
 constructor(){this.jar=new Map();}
 async send(url,init={}){
  if(url.startsWith('/wp-json/'))url='/?rest_route=/'+url.slice('/wp-json/'.length).replace('?','&');
  const address=new URL(url,base);
  if(init.headers?.Authorization)address.hostname='localhost';
  const response=await fetch(address,{...init,redirect:'manual',headers:{...init.headers,Cookie:[...this.jar].map(([k,v])=>k+'='+v).join('; ')}});
  for(const line of response.headers.getSetCookie()){const [pair]=line.split(';');const i=pair.indexOf('=');this.jar.set(pair.slice(0,i),pair.slice(i+1));}
  return response;
 }
 async login(user){
  await this.send('/wp-login.php');
  const response=await this.send('/wp-login.php',{method:'POST',body:new URLSearchParams({log:user.login,pwd:user.password,'wp-submit':'Log In',redirect_to:base+'/wp-admin/',testcookie:'1'})});
  check('cookie login '+user.login,response.status===302);
 }
}
function hidden(html,name){
 const tag=[...html.matchAll(/<input\b[^>]+>/g)].map(m=>m[0]).find(t=>t.includes('name="'+name+'"'));
 return tag?.match(/value="([^"]*)"/)?.[1];
}
function policyForm(html,{width='',revision=null,types=false,empty=false}={}){
 const form=new URLSearchParams({action:'ccug_save',_wpnonce:hidden(html,'_wpnonce'),revision:revision??hidden(html,'revision'),enabled:'1'});
 const keys=[...new Set([...html.matchAll(/name="policy\[([^\]]+)\]/g)].map(m=>m[1]))];
 for(const key of keys){
  for(const field of ['max_bytes','max_width','max_height','max_pixels'])form.set('policy['+key+']['+field+']',key==='global'&&field==='max_width'?width:'');
 }
 if(types){form.set('policy[global][types_enabled]','1');if(!empty)form.set('policy[global][types][]','images');}
 return form;
}
const admin=new Session(),editor=new Session(),subscriber=new Session(),app=new Session();
await admin.login(credentials.admin); await editor.login(credentials.editor); await subscriber.login(credentials.subscriber);
const settingsUrl='/wp-admin/options-general.php?page=ccug-settings';
let settings=await (await admin.send(settingsUrl)).text();
check('settings renders native policy form',settings.includes('Maximum width (px)')&&settings.includes('Save policies'));
const staleRevision=hidden(settings,'revision');
let form=policyForm(settings,{width:'39'});
for(const field of ['enabled','policy[global][types_enabled]']){
 const malformed=new URLSearchParams(form);malformed.delete(field);malformed.append(field+'[]','1');
 check('malformed checkbox rejected '+field,(await admin.send('/wp-admin/admin-post.php',{method:'POST',body:malformed})).status===400);
 const after=await (await admin.send(settingsUrl)).text();
 check('malformed checkbox preserves policy revision '+field,hidden(after,'revision')===staleRevision);
}
const missing=new URLSearchParams(form);missing.delete('_wpnonce');
check('settings rejects missing nonce',(await admin.send('/wp-admin/admin-post.php',{method:'POST',body:missing})).status===403);
check('settings rejects low privilege with real admin nonce',(await subscriber.send('/wp-admin/admin-post.php',{method:'POST',body:form})).status===403);
check('settings rejects GET mutation',(await admin.send('/wp-admin/admin-post.php?action=ccug_save')).status===403);
check('policy saves with proper nonce',(await admin.send('/wp-admin/admin-post.php',{method:'POST',body:form})).status===302);
check('stale policy form conflict',(await admin.send('/wp-admin/admin-post.php',{method:'POST',body:form})).status===409);
const mediaHtml=await (await editor.send('/wp-admin/media-new.php')).text();
const restNonce=mediaHtml.match(/"nonce"\s*:\s*"([^"]+)"/)?.[1];
check('editor media hint present',mediaHtml.includes('UploadGo:')&&mediaHtml.includes('ccug-media'));
const png=fs.readFileSync(path.join(root,'qa/results/uploadgo-fixtures/valid.png'));
const authorization='Basic '+Buffer.from(credentials.editor.login+':'+credentials.application_password).toString('base64');
async function rawUpload(headers={}){
 return (headers.Authorization?app:editor).send('/wp-json/wp/v2/media',{method:'POST',headers:{'Content-Type':'image/png','Content-Disposition':'attachment; filename="uploadgo-http.png"',...headers},body:png});
}
let response=await rawUpload({Authorization:authorization});
let body=await response.json();
if(!(response.status>=400&&body.message?.includes('40')))console.log('Upload response',response.status,body);
check('REST raw Application Password denied by dimensions',response.status>=400&&body.message?.includes('40'));
const multipart=new FormData();multipart.append('file',new Blob([png],{type:'image/png'}),'uploadgo-http.png');
response=await app.send('/wp-json/wp/v2/media',{method:'POST',headers:{Authorization:authorization},body:multipart});
body=await response.json();
check('REST multipart denied by same dimensions',response.status>=400&&body.message?.includes('40'));
settings=await (await admin.send(settingsUrl)).text();form=policyForm(settings,{width:'40'});
check('relax policy through settings',(await admin.send('/wp-admin/admin-post.php',{method:'POST',body:form})).status===302);
response=await rawUpload({Authorization:authorization});body=await response.json();
check('REST raw succeeds at exact boundary',response.status===201&&Number.isInteger(body.id));
const attachment=body.id;
settings=await (await admin.send(settingsUrl)).text();form=policyForm(settings,{width:'39'});
await admin.send('/wp-admin/admin-post.php',{method:'POST',body:form});
response=await app.send('/wp-json/wp/v2/media/'+attachment+'/finalize',{method:'POST',headers:{Authorization:authorization,'Content-Type':'application/json'},body:JSON.stringify({sub_sizes:[]})});
body=await response.json();
if(!(response.status===400&&body.code==='ccug_dimensions'))console.log('Finalize response',response.status,body);
check('real core finalize rechecks changed policy',response.status===400&&body.code==='ccug_dimensions');
response=await rawUpload();
check('cookie REST upload requires nonce',response.status===401||response.status===403);
const browserNonce=mediaHtml.match(/"multipart_params"\s*:\s*\{[^}]*"_wpnonce"\s*:\s*"([^"]+)"/)?.[1];
check('classic uploader has real upload nonce',Boolean(browserNonce));
const classic=new FormData();classic.append('action','upload-attachment');classic.append('_wpnonce',browserNonce);classic.append('async-upload',new Blob([png],{type:'image/png'}),'uploadgo-classic.png');
response=await editor.send('/wp-admin/async-upload.php',{method:'POST',body:classic});
body=await response.text();
check('classic authenticated upload rejects dimensions',response.status===200&&body.includes('40')&&body.includes('39'));
settings=await (await admin.send(settingsUrl)).text();form=policyForm(settings,{empty:true,types:true});
check('empty enabled allowlist requires explicit confirmation',(await admin.send('/wp-admin/admin-post.php',{method:'POST',body:form})).status===400);
form.set('confirm_empty','1');
check('explicit empty allowlist saves',(await admin.send('/wp-admin/admin-post.php',{method:'POST',body:form})).status===302);
response=await rawUpload({Authorization:authorization});
check('empty enabled allowlist rejects upload',response.status>=400);
settings=await (await admin.send(settingsUrl)).text();form=policyForm(settings,{width:'40'});
await admin.send('/wp-admin/admin-post.php',{method:'POST',body:form});
await app.send('/wp-json/wp/v2/media/'+attachment+'?force=true',{method:'DELETE',headers:{Authorization:authorization}});
fs.writeFileSync(path.join(root,'qa/results/uploadgo-http.json'),JSON.stringify({passed:results.length,checks:results},null,2));
console.log(JSON.stringify({passed:results.length}));
