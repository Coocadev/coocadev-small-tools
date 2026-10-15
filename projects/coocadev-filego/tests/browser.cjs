const fs=require('fs'), path=require('path'), assert=require('assert');
const {chromium}=require('C:/Users/kokak/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
(async()=>{const project=path.resolve(__dirname,'..'), root=path.resolve(project,'../..'), report=path.join(project,'reports/0.1.0');
const c=JSON.parse(fs.readFileSync(path.join(root,'qa/secrets/filego.json'),'utf8').replace(/^\uFEFF/,''));
const browser=await chromium.launch({headless:true,executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe'});const page=await browser.newPage({viewport:{width:1440,height:1000}});const errors=[];page.on('pageerror',e=>errors.push(String(e)));const checks=[];
function check(name,ok){assert(ok,name);checks.push({test:name,pass:true});console.log('PASS '+name);}
try{await page.goto(c.url+'/wp-login.php');await page.locator('#user_login').fill(c.user);await page.locator('#user_pass').fill(c.password);await Promise.all([page.waitForURL(/wp-admin/),page.locator('#wp-submit').click()]);
await page.goto(c.url+'/wp-admin/upload.php?page=ccfg-documents');await page.getByLabel('Document title',{exact:true}).fill('Browser QA PDF');await page.getByLabel('Permanent-link slug',{exact:true}).fill('browser-'+Date.now());await Promise.all([page.waitForURL(/document=/),page.getByRole('button',{name:'Create document',exact:true}).click()]);
check('Create document through Chromium labels and native controls',await page.getByRole('heading',{name:'Browser QA PDF'}).isVisible());
await page.getByLabel('Public PDF',{exact:true}).setInputFiles(path.join(report,'fixture-a.pdf'));await page.getByLabel('Version note (optional)',{exact:true}).fill('Browser verified upload');await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'Upload version',exact:true}).click()]);
check('Browser multipart upload shows staged history',await page.getByRole('button',{name:'Publish / roll back to this version',exact:true}).isVisible());
await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'Publish / roll back to this version',exact:true}).click()]);
check('Publish reflected without stale cached pointer',await page.getByRole('heading',{name:/Version .*Current/}).isVisible());
await page.getByLabel('Copy this permanent link',{exact:true}).focus();await page.keyboard.press('Tab');check('Keyboard reaches following actionable control',await page.evaluate(()=>document.activeElement!==document.body));
await page.screenshot({path:path.join(report,'browser-desktop.png'),fullPage:true});
await page.setViewportSize({width:390,height:844});await page.screenshot({path:path.join(report,'browser-mobile.png'),fullPage:true});check('Mobile form remains reachable',await page.getByRole('button',{name:'Upload version',exact:true}).isVisible());
check('No browser runtime errors',errors.length===0);
}catch(e){checks.push({test:'exception',pass:false,detail:String(e)});process.exitCode=1;console.error(String(e));}finally{fs.writeFileSync(path.join(report,'browser-results.json'),JSON.stringify({browser:'Chromium',checks,errors},null,2));await browser.close();}})();
