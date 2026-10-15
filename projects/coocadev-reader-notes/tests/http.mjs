import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
const here = path.dirname(fileURLToPath(import.meta.url));
const fixture = JSON.parse(fs.readFileSync(path.join(here,'.runtime-fixture.json'),'utf8'));
const checks=[];
const check=(pass,name)=>checks.push({name,pass:!!pass});
function client() {
 const cookies=new Map();
 return async (route,opts={})=>{
  const headers={...(opts.headers||{})};
  if(cookies.size) headers.Cookie=[...cookies].map(([k,v])=>k+'='+v).join('; ');
  const response=await fetch(fixture.base+route,{...opts,headers,redirect:'manual'});
  for(const cookie of response.headers.getSetCookie()) {
   const pair=cookie.split(';')[0], i=pair.indexOf('='); cookies.set(pair.slice(0,i),pair.slice(i+1));
  }
  return response;
 };
}
async function login(fetcher,user) {
 await fetcher('/wp-login.php');
 return fetcher('/wp-login.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({log:user.login,pwd:user.password,'wp-submit':'Log In',redirect_to:fixture.base+'/',testcookie:'1'})});
}
const a=client(),b=client(),guest=client();
await login(a,fixture.a); await login(b,fixture.b);
let response=await a('/wp-admin/admin-ajax.php?action=ccrn_bootstrap');
let boot=await response.json(); const session=boot.data;
check(boot.success&&session.user===fixture.a.id,'Authenticated same-origin bootstrap yields only own identity/nonce');
check(!JSON.stringify(boot).includes('content'),'Bootstrap contains no note text');
check(response.headers.get('cache-control').includes('no-store'),'HTTP bootstrap no-store');
check(!response.headers.get('access-control-allow-origin'),'HTTP bootstrap does not grant CORS');
const root='/?rest_route=/coocadev-reader-notes/v1/';
response=await guest(root+'notes');
check(response.status===401,'HTTP guest cannot list');
check(response.headers.get('cache-control').includes('no-store'),'HTTP anonymous REST error no-store');
response=await a(root+'notes');
check(response.status===401,'HTTP cookie read without REST nonce denied');
response=await a(root+'notes',{headers:{'X-WP-Nonce':'invalid'}});
check(response.status===403,'HTTP invalid REST nonce denied');
response=await a('/wp-admin/admin-ajax.php?action=ccrn_bootstrap',{headers:{Origin:'https://attacker.example','Sec-Fetch-Site':'cross-site'}});
check(response.status===403,'HTTP cross-origin bootstrap denied');
const headers={'X-WP-Nonce':session.nonce,'Content-Type':'application/json'};
response=await a(root+'posts/'+fixture.post+'/note',{method:'PUT',headers,body:JSON.stringify({content:'HTTP private text <img src=x onerror=alert(1)> 😀',version:0,epoch:session.epoch})});
let saved=await response.json();
if(response.status===409) {
 const current=await (await a(root+'posts/'+fixture.post+'/note',{headers})).json();
 response=await a(root+'posts/'+fixture.post+'/note',{method:'PUT',headers,body:JSON.stringify({content:'HTTP private text <img src=x onerror=alert(1)> 😀',version:current.note.version,epoch:session.epoch})});
 saved=await response.json();
}
check(response.ok&&saved.note&&saved.note.content.includes('<img'),'HTTP valid nonce saves literal text');
check(response.headers.get('cache-control').includes('no-store'),'HTTP successful write no-store');
const bootB=(await (await b('/wp-admin/admin-ajax.php?action=ccrn_bootstrap')).json()).data;
response=await b(root+'notes/'+saved.note.id,{headers:{'X-WP-Nonce':bootB.nonce}});
check(response.status===404,'HTTP administrator cannot get reader note');
response=await a(root+'notes/'+saved.note.id,{headers:{...headers,Origin:'https://attacker.example'}});
check(response.status===403&&!response.headers.get('access-control-allow-origin'),'HTTP cross-origin private REST denied without CORS');
response=await guest('/?p='+fixture.post);
const html=await response.text();
check(!html.includes('HTTP private text')&&!html.includes(session.nonce),'Public page cache shell contains neither private text nor nonce');
check(html.includes('ccrn-shell')&&html.includes('ccrnConfig'),'Public page loads neutral shells and frontend assets');
response=await a('/wp-admin/profile.php?page=ccrn-my-notes');
check(response.status===200,'Subscriber personal My notes admin page available');
response=await a('/wp-admin/admin-post.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({action:'ccrn_settings',quota:'1',revision:'0'})});
check(response.status===403,'Subscriber cannot change quota');
const settings=await (await b('/wp-admin/options-general.php?page=ccrn-settings')).text();
const nonce=settings.match(/name="_wpnonce" value="([^"]+)"/)?.[1];
const revision=settings.match(/name="revision" value="([^"]+)"/)?.[1];
response=await b('/wp-admin/admin-post.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({action:'ccrn_settings',quota:'-1',revision,_wpnonce:nonce,'post_types[]':'post'})});
check(response.status===400,'Administrator negative quota is rejected');
response=await b('/wp-admin/admin-post.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({action:'ccrn_settings',quota:'',revision,_wpnonce:nonce,'post_types[]':'post'})});
check(response.status===302,'Administrator valid nonce settings saved');
response=await b('/wp-admin/admin-post.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({action:'ccrn_settings',quota:'20',revision,_wpnonce:nonce,'post_types[]':'post'})});
check(response.status===409,'Administrator stale revision cannot overwrite settings');
const report={checks,total:checks.length,failed:checks.filter(x=>!x.pass).length};
fs.writeFileSync(path.join(here,'../reports/0.1.0/http.json'),JSON.stringify(report,null,2));
console.log(JSON.stringify(report,null,2));
if(report.failed) process.exitCode=1;

