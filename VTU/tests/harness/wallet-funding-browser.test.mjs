process.on('uncaughtException', error => { console.error('::error::' + String(error.stack || error).replace(/[\r\n]+/g, ' ')); process.exit(1); });
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
let browser;
const cases=JSON.parse(execFileSync(process.execPath,[path.join(dir,'run-test.mjs'),'tests/wallet_funding_render.php'],{encoding:'utf8',maxBuffer:4*1024*1024}));
const script=fs.readFileSync(path.resolve(dir,'../../DGV7.0-SAAS/jsfile/bc-wallet-funding.js'),'utf8');
try {
 for(const edition of ['SAAS','NON-SAAS'])assert.equal(fs.readFileSync(path.resolve(dir,`../../DGV7.0-${edition}/jsfile/bc-wallet-funding.js`),'utf8'),script);
 for(const js of [true,false]){
  browser=await playwright.launch({args:chromium.args,executablePath:await chromium.executablePath(),headless:true,
 env:{...process.env,LD_LIBRARY_PATH:path.join(libraries,'lib')+':'+(process.env.LD_LIBRARY_PATH||'')}});

  const page=await browser.newPage({javaScriptEnabled:js});const errors=[],posts=[];
  page.on('pageerror',e=>errors.push(e.message));
  await page.route('**/*',route=>{
   const req=route.request(),url=new URL(req.url());
   if(url.pathname==='/jsfile/bc-wallet-funding.js')return route.fulfill({contentType:'text/javascript',body:script});
   if(req.method()==='POST'){
    const form=new URLSearchParams(req.postData());posts.push(Object.fromEntries(form));
    assert.equal(url.pathname,'/web/Fund.php');assert.equal(form.get('_csrf_token'),'fixture-csrf');assert.equal(form.get('gateway'),'payhub');assert.equal(form.get('amount'),'100.25');
    assert.deepEqual([...form.keys()].sort(),['_csrf_token','amount','gateway']);
    return route.fulfill({contentType:'text/html',body:cases.done});
   }
   return route.fulfill({contentType:'text/html',body:cases[url.searchParams.get('case')||'ready']});
  });
  for(const width of [320,390,768,1440]){
   await page.setViewportSize({width,height:900});await page.goto('https://fund.test/web/Fund.php');
   assert.equal(await page.locator('[data-fund-bank]').count(),0,'bank details only after server request');
   assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
   await page.getByRole('button',{name:/PAY NOW/}).click();await page.waitForSelector('[data-fund-bank]');
   assert.equal(await page.locator('[data-fund-bank] img').count(),0,'bank name escaped');
   assert.ok((await page.locator('[data-fund-bank]').textContent()).includes('<img src=x'));
   if(js)assert.ok(await page.locator('[data-fund-bank]').evaluate(e=>e===document.activeElement));
   console.log(`PASS Fund.php ${width}px native POST, JavaScript=${js}`);
  }
  await page.goto('https://fund.test/web/Fund.php?case=blocked');assert.ok(await page.getByRole('button',{name:/PAY NOW/}).isDisabled());assert.match(await page.locator('[role=status]').textContent(),/temporarily unavailable/);
  assert.equal(await page.locator('[data-fund-bank]').count(),0);
  await page.goto('https://fund.test/web/Fund.php?case=error');if(js)assert.ok(await page.locator('[data-fund-error]').evaluate(e=>e===document.activeElement));
  await page.goto('https://fund.test/web/Fund.php');const before=posts.length;
  await page.locator('#fund-amount').fill('1e4');await page.getByRole('button',{name:/PAY NOW/}).click();assert.equal(posts.length,before,'invalid amount blocked by native pattern');
  if(js){await page.locator('#fund-amount').fill('99.99');await page.getByRole('button',{name:/PAY NOW/}).click();assert.equal(posts.length,before,'JS min guard');assert.match(await page.locator('[role=status]').textContent(),/100.00/);}
  assert.deepEqual(errors,[]);await browser.close();browser=null;
 }
 console.log('PASS unavailable, validation feedback, escaped details, focus, no-JS and edition parity');
} finally {if(browser)await browser.close();fs.rmSync(libraries,{recursive:true,force:true});}
