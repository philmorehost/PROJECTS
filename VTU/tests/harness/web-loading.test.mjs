// Real Chromium cascade regression. Requires npm ci in this harness directory.
import chromium from '@sparticuz/chromium';
import { chromium as playwright } from 'playwright-core';
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import { brotliDecompressSync } from 'node:zlib';
import { createRequire } from 'node:module';
const dir=path.dirname(fileURLToPath(import.meta.url));
// The npm binary bundles NSS/NSPR for minimal Linux CI images as well as Lambda.
const require=createRequire(import.meta.url);
const libraries=fs.mkdtempSync(path.join(os.tmpdir(),'web-loading-libs-'));
const archive=path.resolve(path.dirname(require.resolve('@sparticuz/chromium')),'../../bin/al2023.tar.br');
fs.writeFileSync(path.join(libraries,'libs.tar'),brotliDecompressSync(fs.readFileSync(archive)));
execFileSync('tar',['-xf',path.join(libraries,'libs.tar'),'-C',libraries]);
const browser=await playwright.launch({args:chromium.args,executablePath:await chromium.executablePath(),headless:true,
 env:{...process.env,LD_LIBRARY_PATH:path.join(libraries,'lib')+':'+(process.env.LD_LIBRARY_PATH||'')}});
const html=execFileSync(process.execPath,[path.join(dir,'run-test.mjs'),'tests/web_loading_fixture.php'],{encoding:'utf8',maxBuffer:4*1024*1024});
const root=path.resolve(dir,'../../DGV7.0-SAAS');
for (const file of ['assets-2/vendor/sweetalert2/sweetalert2.all.min.js','assets-2/vendor/sweetalert2/LICENSE']) {
 assert.deepEqual(fs.readFileSync(path.join(root,file)),fs.readFileSync(path.resolve(root,'../DGV7.0-NON-SAAS',file)),'asset parity');
}
assert.ok(fs.existsSync(path.join(root,'assets-2/vendor/bootstrap-icons/fonts/bootstrap-icons.woff2')));
try {
 for (const failAlerts of [false,true]) {
  const page=await browser.newPage();
  const external=[];const pending=[];const dialogs=[];const errors=[];
  page.on('dialog',async d=>{dialogs.push(d.type());await d.dismiss();});
  page.on('pageerror',e=>errors.push(e.message));
  await page.route('**/*',async route=>{
   const url=new URL(route.request().url());
   if(url.hostname!=='performance.test') {
    external.push(url.href);
    // Deliberately never return remote font/third-party requests until assertions finish.
    pending.push(route);return;
   }
   if(url.pathname==='/web/Dashboard.php')return route.fulfill({body:html,contentType:'text/html'});
   if(failAlerts && url.pathname.includes('sweetalert2'))return route.abort();
   const file=path.join(root,url.pathname);
   if(fs.existsSync(file)&&fs.statSync(file).isFile())return route.fulfill({body:fs.readFileSync(file),contentType:file.endsWith('.js')?'application/javascript':file.endsWith('.css')?'text/css':'application/octet-stream'});
   return route.fulfill({status:404,body:''});
  });
  await page.goto('https://performance.test/web/Dashboard.php',{waitUntil:'domcontentloaded',timeout:8000});
  assert.equal(await page.locator('#page-ready').isVisible(),true);
  assert.equal(await page.evaluate(()=>typeof window.copyReferLinkModal),'undefined'); // unsigned-in fixture
  assert.equal(await page.evaluate(()=>typeof window.filterBulkSMSPhoneNumbers),'function','deferred shared helper executed');
  if(!failAlerts){
   await page.locator('.swal2-confirm').waitFor({state:'visible'});
   assert.equal(await page.locator('.swal2-confirm').textContent(),'View Receipt');
   await page.locator('.swal2-confirm').click();
   await page.locator('#bcReceiptModal .btn-close').click();
  }else{
   await page.getByRole('button',{name:'View Receipt',exact:true}).waitFor();
   await page.getByRole('button',{name:'Dismiss',exact:true}).click();
  }
  await page.locator('#primary-action').click();
  assert.equal(await page.locator('#primary-action').textContent(),'Working');
  assert.deepEqual(dialogs,[],'no synchronous browser alerts during load');
  assert.deepEqual(errors,[],'no script errors');
  assert.ok(external.every(url=>url.startsWith('https://fonts.')),'only optional font requests leave origin');
  assert.ok(pending.length>0,'test really stalled optional font network');
  const blocking=await page.evaluate(()=>[...document.querySelectorAll('script[src]:not([async]):not([defer])')].map(s=>s.src));
  assert.deepEqual(blocking,[],'no parser-blocking shell scripts');
  for(const route of pending)await route.abort().catch(()=>{});
  // One shared browser page avoids the Lambda single-process close/newPage issue.
  if(failAlerts) break;
  // Reuse the browser context without closing the only page.
 }
 console.log('Web loading passed: usable UI and receipt feedback while external font requests stall, including local alert-library failure.');
} finally {await browser.close();fs.rmSync(libraries,{recursive:true,force:true});}
