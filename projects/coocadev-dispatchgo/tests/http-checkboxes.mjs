import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {execFileSync} from 'node:child_process';
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const fixture=JSON.parse(fs.readFileSync(path.join(root,'evidence/http-private-fixture.json'),'utf8'));
if(fixture.url!=='http://127.0.0.1:8782')throw new Error('Isolated QA only');
const cookies=new Map(),checks=[];
function check(label,condition){if(!condition)throw new Error(label);checks.push(label);console.log('PASS '+label);}
async function send(url,init={}){
 const result=await fetch(new URL(url,fixture.url),{...init,redirect:'manual',headers:{...init.headers,Cookie:[...cookies].map(([k,v])=>`${k}=${v}`).join('; ')}});
 for(const header of result.headers.getSetCookie()){const [pair]=header.split(';'),pos=pair.indexOf('=');cookies.set(pair.slice(0,pos),pair.slice(pos+1));}
 return result;
}
function input(html,name){const tag=[...html.matchAll(/<input\b[^>]+>/g)].map(m=>m[0]).find(t=>t.includes(`name="${name}"`));return tag?.match(/value="([^"]*)"/)?.[1]??'';}
const settingsUrl='/wp-admin/admin.php?page=wc-settings&tab=shipping&section=ccdg';
await send('/wp-login.php');
check('temporary shop manager authenticated',(await send('/wp-login.php',{method:'POST',body:new URLSearchParams({log:fixture.login,pwd:fixture.password,'wp-submit':'Log In',testcookie:'1',redirect_to:fixture.url+'/wp-admin/'})})).status===302);
const page=await (await send(settingsUrl)).text();
check('real Woo shipping subsection renders',page.includes('ccdg_revision')&&page.includes('Dispatch resumes'));
const originalRevision=input(page,'ccdg_revision');
const originalFields=['ccdg_enabled','ccdg_show_cart','ccdg_uninstall_remove_settings','ccdg_starts_local','ccdg_resumes_local_date'];
function snapshot(html){return JSON.stringify({fields:originalFields.map(name=>{const tag=[...html.matchAll(/<input\b[^>]+>/g)].map(m=>m[0]).find(t=>t.includes(`name="${name}"`));return [name,input(html,name),!!tag?.includes('checked')]}),message:html.match(/<textarea\b[^>]*name="ccdg_customer_message"[^>]*>([\s\S]*?)<\/textarea>/)?.[1]});}
const originalSnapshot=snapshot(page);
function form(){return new URLSearchParams({_wpnonce:input(page,'_wpnonce'),ccdg_nonce:input(page,'ccdg_nonce'),ccdg_revision:originalRevision,ccdg_enabled:'1',ccdg_show_cart:'1',ccdg_uninstall_remove_settings:'1',ccdg_starts_local:'2026-10-14T00:00',ccdg_resumes_local_date:'2030-01-01',ccdg_customer_message:'HTTP boundary test',save:'Save changes'});}
for(const key of ['ccdg_enabled','ccdg_show_cart','ccdg_uninstall_remove_settings']){
 for(const variant of ['array','0','on','1 ']){
  const body=form();body.delete(key);body.append(variant==='array'?key+'[]':key,variant==='array'?'1':variant);
  const response=await send(settingsUrl,{method:'POST',body});
  check(`${key} ${JSON.stringify(variant)} returns HTTP 400`,response.status===400);
  const after=await (await send(settingsUrl)).text();
  check(`${key} ${JSON.stringify(variant)} leaves complete settings unchanged`,input(after,'ccdg_revision')===originalRevision&&snapshot(after)===originalSnapshot);
 }
}
const databaseCheck=execFileSync('php',[path.join(root,'tests/http-fixture.php'),path.resolve(root,'../../qa/sites/dispatchgo'),'verify'],{encoding:'utf8'});
check('actual persisted settings identical after all malformed requests',databaseCheck.includes('PASS persisted settings are identical'));
const valid=form();
const accepted=await send(settingsUrl,{method:'POST',body:valid});
check('canonical checkbox tokens accepted',accepted.status===302||accepted.status===200);
const afterValid=await (await send(settingsUrl)).text();
check('canonical submission increments revision once',Number(input(afterValid,'ccdg_revision'))===Number(originalRevision)+1);
const off=form();off.set('ccdg_revision',input(afterValid,'ccdg_revision'));for(const key of ['ccdg_enabled','ccdg_show_cart','ccdg_uninstall_remove_settings'])off.delete(key);
const disabled=await send(settingsUrl,{method:'POST',body:off});
check('omitted checkboxes accepted as unchecked',disabled.status===302||disabled.status===200);
const afterOff=await (await send(settingsUrl)).text();
check('unchecked submission increments revision once',Number(input(afterOff,'ccdg_revision'))===Number(originalRevision)+2);
fs.writeFileSync(path.join(root,'evidence/http-checkboxes.json'),JSON.stringify({passed:checks.length,checks},null,2));
