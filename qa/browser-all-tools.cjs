/**
 * Actual headless browser checks of local admin pages and both scan controls.
 * Runtime credentials/fixtures are read only from excluded local QA files.
 * Copyright 2026 Coocadev. SPDX-License-Identifier: GPL-2.0-or-later.
 */
'use strict';
const fs = require('node:fs'), path = require('node:path'), assert = require('node:assert/strict');
let playwright;
try { playwright = require('playwright'); } catch {
    // Maintainer runtime fallback; contributors can install Playwright separately.
    playwright = require('C:/Users/kokak/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
}
const root = path.resolve(__dirname, '..');
const parse = file => JSON.parse(fs.readFileSync(file, 'utf8').replace(/^\uFEFF/, ''));
const credentials = parse(path.join(root, 'qa/secrets/combined.json'));
if (new URL(credentials.url).hostname !== '127.0.0.1' || new URL(credentials.url).port !== '8785') throw new Error('Dedicated localhost browser target only.');
const fixtures = parse(path.join(root, 'qa/secrets/browser-020.json'));
const reports = path.join(root, 'reports/0.2.0'); fs.mkdirSync(reports, {recursive:true});
const checks = [], errors = [], forbidden = [], compatibilityNotices = [];
const check = (name, condition) => { assert(condition, name); checks.push({name, pass:true}); console.log('PASS ' + name); };
const screens = [
 ['FileGo', '/wp-admin/upload.php?page=ccfg-documents', /FileGo/],
 ['UploadGo', '/wp-admin/options-general.php?page=ccug-settings', /UploadGo/],
 ['DispatchGo', '/wp-admin/admin.php?page=wc-settings&tab=shipping&section=ccdg', /DispatchGo/],
 ['Reader Notes', '/wp-admin/options-general.php?page=ccrn-settings', /Reader Notes/],
 ['DoneMark', '/wp-admin/options-general.php?page=ccdm-settings', /DoneMark/],
 ['Client Nudge', '/wp-admin/tools.php?page=cccn-tasks', /Client Nudge/],
 ['StockDigest', '/wp-admin/admin.php?page=ccsd', /StockDigest/],
 ['DownloadRescue', '/wp-admin/admin.php?page=ccdr', /DownloadRescue/],
 ['HoursGo', '/wp-admin/options-general.php?page=cchg-hours', /HoursGo/],
 ['MediaMap', '/wp-admin/tools.php?page=coocadev-mediamap', /MediaMap/],
 ['UseMap', '/wp-admin/tools.php?page=coocadev-usemap', /UseMap/],
 ['Passport', '/wp-admin/options-general.php?page=ccpp-cards', /Passport/],
 ['ReleaseGo', '/wp-admin/edit.php?post_type=ccrg_release', /Releases/],
 ['MailSkin', '/wp-admin/options-general.php?page=ccms-skin', /MailSkin/]
];
(async () => {
 const browser = await playwright.chromium.launch({headless:true, executablePath: process.env.CHROME_PATH || 'C:/Program Files/Google/Chrome/Application/chrome.exe'});
 const context = await browser.newContext({viewport:{width:1440,height:1000}});
 // Product pages may not depend on outside assets. Abort unrelated Core/admin
 // network calls too, then report only Coocadev-owned external requests as failures.
 await context.route('**/*', async route => {
   const request = route.request(), url = new URL(request.url());
   if (!['127.0.0.1','localhost'].includes(url.hostname) && !['data:','blob:'].includes(url.protocol)) {
     if (/coocadev|cooca\.dev/.test(url.hostname)) forbidden.push(request.url());
     return route.abort();
   }
   return route.continue();
 });
 const page = await context.newPage();
 page.setDefaultTimeout(60000);
 page.on('pageerror', error => errors.push(String(error)));
 try {
   await page.goto(credentials.url + '/wp-login.php', {waitUntil:'domcontentloaded'});
   await page.locator('#user_login').fill(credentials.user);
   await page.locator('#user_pass').fill(credentials.password);
   await Promise.all([page.waitForURL(/wp-admin/, {waitUntil:'domcontentloaded'}),page.locator('#wp-submit').click()]);
   for (const [name, url, text] of screens) {
     const response = await page.goto(credentials.url + url, {waitUntil:'domcontentloaded'});
     await page.locator('#wpbody-content').waitFor();
     check(name + ' native admin screen renders', response.status() === 200 && text.test(await page.locator('#wpbody-content').innerText()));
     check(name + ' screen has no displayed PHP diagnostics', !/Fatal error:|Warning:|Notice:.* on line|Parse error:/.test(await page.locator('#wpbody-content').innerText()));
     if ((await page.locator('#wpbody-content').innerText()).includes('some of your active plugins are incompatible')) compatibilityNotices.push(name);
   }
   check('No WooCommerce active-feature incompatibility notice on checked screens', compatibilityNotices.length === 0);
   await page.goto(credentials.url + '/wp-admin/tools.php?page=coocadev-mediamap');
   await page.locator('#ccmm_image').fill(String(fixtures.image));
   await page.getByRole('button',{name:'Start new scan',exact:true}).click();
   await page.locator('#ccmm_status').filter({hasText:/Scan complete/}).waitFor({timeout:60000});
   check('MediaMap actual browser finds the fixture image', (await page.locator('#ccmm_results').innerText()).includes('UI scanner image fixture'));
   await page.setViewportSize({width:390,height:844});
   const mobileStart = page.getByRole('button',{name:'Start new scan',exact:true});
   await mobileStart.focus();
   check('MediaMap mobile Start control is visible and focusable', await mobileStart.isVisible() && await mobileStart.evaluate(element => document.activeElement === element));
   await page.screenshot({path:path.join(reports,'mediamap-mobile.png'),fullPage:true});
   await page.setViewportSize({width:1440,height:1000});
   await page.goto(credentials.url + '/wp-admin/tools.php?page=coocadev-usemap');
   await page.locator('#ccum_blocks').fill('ccst/ui-card');
   await page.locator('#ccum_shortcodes').fill('ccst_ui_tag');
   await page.getByRole('button',{name:'Start new scan',exact:true}).focus();
   await page.keyboard.press('Enter');
   await page.locator('#ccum_status').filter({hasText:/Scan complete/}).waitFor({timeout:60000});
   check('UseMap keyboard-started scan finds block and shortcode', (await page.locator('#ccum_results').innerText()).includes('ccst/ui-card') && (await page.locator('#ccum_results').innerText()).includes('[ccst_ui_tag]'));
   const endpoint = await page.evaluate(() => window.ccumScan.endpoint);
   const slowScan = async route => { await new Promise(resolve => setTimeout(resolve, 400)); await route.fallback(); };
   const isScan = url => url.href.startsWith(endpoint);
   await page.route(isScan, slowScan);
   await page.getByRole('button',{name:'Start new scan',exact:true}).click();
   await page.locator('#ccum_pause').click();
   await page.locator('#ccum_continue').waitFor({state:'visible'});
   check('Pause exposes an enabled Continue action', await page.locator('#ccum_continue').isEnabled());
   await page.locator('#ccum_continue').click();
   await page.locator('#ccum_status').filter({hasText:/Scan complete/}).waitFor({timeout:60000});
   check('Resumed scan does not duplicate results', await page.locator('#ccum_results > li').count() === 1);
   await page.unroute(isScan, slowScan);
   check('Private scan controller fetches no external Coocadev service', forbidden.length === 0);
   await page.screenshot({path:path.join(reports,'usemap-desktop.png'),fullPage:true});
   const boundary = await page.evaluate(async base => {
     const endpoint = new URL(window.ccumScan.endpoint); endpoint.searchParams.set('blocks','ccst/ui-card'); endpoint.searchParams.set('after','0');
     const noNonce = await fetch(endpoint,{credentials:'same-origin'});
     const badNonce = await fetch(endpoint,{credentials:'same-origin',headers:{'X-WP-Nonce':'invalid'}});
     endpoint.searchParams.set('after[]','1'); endpoint.searchParams.delete('after');
     const badType = await fetch(endpoint,{credentials:'same-origin',headers:{'X-WP-Nonce':window.ccumScan.nonce}});
     return {noNonce:noNonce.status,badNonce:badNonce.status,badType:badType.status};
   }, credentials.url);
   check('Real cookie REST request without nonce is denied', [401,403].includes(boundary.noNonce));
   check('Real cookie REST request with invalid nonce is denied', boundary.badNonce === 403);
   check('Array cursor is refused by real HTTP endpoint', boundary.badType === 400);
   check('No JavaScript runtime exceptions across checked screens', errors.length === 0);
 } catch (error) {
   checks.push({name:'browser exception',pass:false,error:String(error)}); console.error(String(error)); process.exitCode=1;
   await page.screenshot({path:path.join(reports,'browser-failure.png'),fullPage:true});
 } finally {
   fs.writeFileSync(path.join(reports,'browser-all-tools.json'),JSON.stringify({date:new Date().toISOString(),browser:await browser.version(),scope:'14 native admin screens; InvoiceRoute has native checkout/order fields tested separately',checks,errors,forbidden},null,2));
   await browser.close();
 }
})();
