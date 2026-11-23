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
const html=execFileSync(process.execPath,[path.join(dir,'run-test.mjs'),'tests/public_navigation_render.php'],{encoding:'utf8',maxBuffer:4*1024*1024});
const css=fs.readFileSync(path.resolve(dir,'../../DGV7.0-SAAS/asset/bc-public-navigation.css'),'utf8');
const script=fs.readFileSync(path.resolve(dir,'../../DGV7.0-SAAS/jsfile/bc-public-navigation.js'),'utf8');
try {
 for (const edition of ['SAAS','NON-SAAS']) {
  assert.equal(fs.readFileSync(path.resolve(dir,`../../DGV7.0-${edition}/asset/bc-public-navigation.css`),'utf8'),css);
  assert.equal(fs.readFileSync(path.resolve(dir,`../../DGV7.0-${edition}/jsfile/bc-public-navigation.js`),'utf8'),script);
 }
 const page=await browser.newPage();const errors=[];
 page.on('pageerror',e=>errors.push(e.message));
 await page.route('**/*',route=> {
  const url=new URL(route.request().url());
  if(url.hostname==='public.example.test' && url.pathname==='/')return route.fulfill({contentType:'text/html',body:html});
  if(url.pathname==='/asset/bc-public-navigation.css')return route.fulfill({contentType:'text/css',body:css});
  if(url.pathname==='/jsfile/bc-public-navigation.js')return route.fulfill({contentType:'text/javascript',body:script});
  return route.abort(); // No provider/rate/external resources in this layout check.
 });
 for (const width of [320,375,390,600,768,800,801,1024,1440]) {
  await page.setViewportSize({width,height:900});
  await page.goto('https://public.example.test/');
  const nav=page.locator('[data-public-navigation]'), toggle=nav.locator('button.public-menu-toggle'), links=nav.locator('#public-menu-links');
  assert.ok(await nav.isVisible());
  assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),`no page overflow at ${width}`);
  if(width<=800){
   assert.ok(await toggle.isVisible());assert.equal(await links.isVisible(),false);
   await toggle.focus();await page.keyboard.press('Enter');
   assert.equal(await toggle.getAttribute('aria-expanded'),'true');assert.ok(await links.isVisible());
   await page.keyboard.press('Tab');assert.ok(await links.evaluate(el=>el.contains(document.activeElement)));
   await page.keyboard.press('Escape');assert.equal(await links.isVisible(),false);assert.ok(await toggle.evaluate(el=>el===document.activeElement));
   await toggle.click();await page.locator('h1').click();assert.equal(await toggle.getAttribute('aria-expanded'),'false');
   await toggle.click();
   for(const a of await links.locator('a').all()) {
    const r=await a.boundingBox();assert.ok(r && r.x>=0 && r.x+r.width<=width+1,`link within mobile width ${width}`);
    assert.ok(r.height>=44,`touch target ${width}`);
   }
   // Link activation closes before navigation; prevent navigation only in this check.
   await links.evaluate(el=>el.addEventListener('click',e=>e.preventDefault()));
   await links.locator('a').first().click();assert.equal(await toggle.getAttribute('aria-expanded'),'false');
  } else {assert.equal(await toggle.isVisible(),false);assert.ok(await links.isVisible());}
  console.log(`PASS homepage navigation at ${width}px`);
 }
 // A breakpoint crossing must not hide the focused desktop link without moving focus.
 await page.setViewportSize({width:1024,height:900});await page.goto('https://public.example.test/');
 await page.locator('#public-menu-links a').first().focus();
 await page.setViewportSize({width:390,height:900});
 await page.waitForFunction(()=>document.activeElement.matches('.public-menu-toggle'));
 assert.equal(await page.locator('#public-menu-links').isVisible(),false);
 await page.emulateMedia({reducedMotion:'reduce'});
 assert.equal(await page.evaluate(()=>getComputedStyle(document.documentElement).scrollBehavior),'auto');
 assert.deepEqual(errors,[]);
 // JS-disabled fallback: all links remain available, toggle is hidden.
 const nojs=await browser.newContext({javaScriptEnabled:false,viewport:{width:320,height:900}});
 const fallback=await nojs.newPage();
 await fallback.route('**/*',r=>r.request().url().includes('bc-public-navigation.css')?r.fulfill({contentType:'text/css',body:css}):r.fulfill({contentType:'text/html',body:html}));
 await fallback.goto('https://public.example.test/');
 assert.ok(await fallback.locator('#public-menu-links').isVisible());
 assert.equal(await fallback.locator('.public-menu-toggle').isVisible(),false);
 console.log('PASS keyboard, resize focus, reduced-motion, edition parity and no-JS fallback');
} finally { await browser.close();fs.rmSync(libraries,{recursive:true,force:true}); }
