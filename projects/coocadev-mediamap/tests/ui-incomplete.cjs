/** Execute the real controller against synthetic batches; no browser or network is used. GPL-2.0-or-later. */
'use strict';
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const assert = require('node:assert/strict');
const product = path.basename(path.dirname(__dirname));
const prefix = product === 'coocadev-mediamap' ? 'ccmm' : 'ccum';
function element() { return {textContent: '', disabled: false, handlers: {}, addEventListener(name, handler) { this.handlers[name] = handler; }, appendChild() {}, replaceChildren() {}}; }
const elements = Object.fromEntries(['form', 'pause', 'continue', 'status', 'results'].map(name => [`${prefix}_${name}`, element()]));
const config = {endpoint:'http://localhost/wp-json/scan', nonce:'synthetic', running:'RUNNING', done:'DONE', paused:'PAUSED', error:'ERROR', edit:'Edit', empty:'EMPTY', summary:'SCANNED', visited:'VISITED', skipped:'SKIPPED', incomplete:'INCOMPLETE'};
let batches = [{matches:[], next:40, done:false, scanned:0, visited:40, skipped:40, incomplete:true}, {matches:[], next:41, done:true, scanned:0, visited:1, skipped:1, incomplete:true}];
const context = {window:{location:{href:'http://localhost/wp-admin/tools.php', origin:'http://localhost'}, [`${prefix}Scan`]:config}, document:{getElementById(id) { return elements[id]; }, createElement:element, createTextNode:element}, AbortController, URL, URLSearchParams, FormData:class { *[Symbol.iterator]() { yield ['image_id','1']; } }, fetch: async () => ({ok:true, json:async () => batches.shift()})};
vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../plugin/assets/scan.js'),'utf8'),context);
(async () => {
 elements[`${prefix}_form`].handlers.submit({preventDefault(){}});
 await new Promise(setImmediate);
 const incomplete = elements[`${prefix}_status`].textContent;
 assert.match(incomplete,/INCOMPLETE/); assert.match(incomplete,/SKIPPED 41/); assert.match(incomplete,/VISITED 41/); assert.doesNotMatch(incomplete,/EMPTY/);
 batches = [{matches:[], next:0, done:true, scanned:0, visited:0, skipped:0, incomplete:false}];
 elements[`${prefix}_form`].handlers.submit({preventDefault(){}});
 await new Promise(setImmediate);
 const empty = elements[`${prefix}_status`].textContent;
 assert.match(empty,/DONE/); assert.match(empty,/EMPTY/); assert.doesNotMatch(empty,/INCOMPLETE/); assert.doesNotMatch(empty,/SKIPPED/);
 console.log(JSON.stringify({product, passed:true, checks:['Skipped totals persist across batches','Skipped report says incomplete and never empty','Start new resets prior skipped totals']}));
})().catch(error => { console.error(error); process.exitCode=1; });
