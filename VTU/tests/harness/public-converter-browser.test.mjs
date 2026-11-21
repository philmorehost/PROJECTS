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
const libraries=fs.mkdtempSync(path.join(os.tmpdir(),'converter-libs-'));
const archive=path.resolve(path.dirname(require.resolve('@sparticuz/chromium')),'../../bin/al2023.tar.br');
fs.writeFileSync(path.join(libraries,'libs.tar'),brotliDecompressSync(fs.readFileSync(archive)));
execFileSync('tar',['-xf',path.join(libraries,'libs.tar'),'-C',libraries]);
const browser=await playwright.launch({args:chromium.args,executablePath:await chromium.executablePath(),headless:true,
 env:{...process.env,LD_LIBRARY_PATH:path.join(libraries,'lib')+':'+(process.env.LD_LIBRARY_PATH||'')}});
const html=execFileSync(process.execPath,[path.join(dir,'run-test.mjs'),'tests/public_converter_fixture.php'],{encoding:'utf8',maxBuffer:4*1024*1024});
const script=fs.readFileSync(path.resolve(dir,'../../DGV7.0-SAAS/jsfile/bc-public-home.js'),'utf8');
try {
 const page=await browser.newPage();const calls=[];const errors=[];
 page.on('pageerror',e=>errors.push(e.message));
 await page.route('**/*',r=>{
  const url=r.request().url();calls.push(url);
  if(url==='https://converter.test/')return r.fulfill({body:html,contentType:'text/html'});
  if(url.includes('/jsfile/bc-public-home.js'))return r.fulfill({body:script,contentType:'application/javascript'});
  if(url.includes('currency-api@latest'))return r.fulfill({json:{date:new Date().toISOString().slice(0,10),usd:{usd:1,gbp:.8,ngn:1600,cny:7,eur:.9,cad:1.4}}});
  return r.abort();
 });
 for(const width of [390,1280]){
  await page.setViewportSize({width,height:1000});
  await page.goto('https://converter.test/',{waitUntil:'domcontentloaded'});
  await page.locator('#mz-from:not([disabled])').waitFor();
  await page.locator('#send').scrollIntoViewIfNeeded();
  assert.equal(await page.locator('#mz-get').textContent(),'2,000,000');
  assert.ok(await page.locator('#mz-rate-status').textContent().then(t=>t.includes('Latest daily reference data')));
  const box=await page.locator('#send').boundingBox();
  assert.ok(box.x>=-1 && box.x+box.width<=width+1,'card fits viewport');
  for(const selector of ['#mz-send','#mz-from','#mz-to','#mz-get','#mz-refresh']){
   const b=await page.locator(selector).boundingBox();
   assert.ok(b.width>30 && b.x>=box.x && b.x+b.width<=box.x+box.width+1,selector+' stays inside card');
  }
  await page.locator('#mz-send').fill('100');
  assert.equal(await page.locator('#mz-get').textContent(),'200,000');
  await page.locator('#mz-to').selectOption('CNY');
  assert.equal(await page.locator('#mz-get').textContent(),'875');
  await page.locator('#mz-swap').click();
  assert.equal(await page.locator('#mz-from').inputValue(),'CNY');
 }
 assert.deepEqual(errors,[]);
 assert.ok(!calls.some(u=>u.includes('/web/')||u.includes('exchange-rate.php')),'no transaction endpoint called');
 assert.ok(!html.includes('Illustrations show example balances'),'requested paragraph removed');
 console.log('Real home converter browser checks passed at 390/1280px: card layout, live-feed fixture, currency choices, swap and transaction isolation.');
}finally{await browser.close();fs.rmSync(libraries,{recursive:true,force:true});}
