// Real Chromium cascade regression. Requires npm ci in this harness directory.
import chromium from '@sparticuz/chromium';
import { chromium as playwright } from 'playwright-core';
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import fs from 'node:fs';
import os from 'node:os';
import { brotliDecompressSync } from 'node:zlib';
import { createRequire } from 'node:module';
const dir=path.dirname(fileURLToPath(import.meta.url));
const fixtures=JSON.parse(execFileSync(process.execPath,[path.join(dir,'run-test.mjs'),'tests/user_confirmation_fixture.php'],{encoding:'utf8',maxBuffer:4*1024*1024}));
// The npm binary bundles NSS/NSPR for minimal Linux CI images as well as Lambda.
const require=createRequire(import.meta.url);
const libraries=fs.mkdtempSync(path.join(os.tmpdir(),'user-action-libs-'));
const archive=path.resolve(path.dirname(require.resolve('@sparticuz/chromium')),'../../bin/al2023.tar.br');
fs.writeFileSync(path.join(libraries,'libs.tar'),brotliDecompressSync(fs.readFileSync(archive)));
execFileSync('tar',['-xf',path.join(libraries,'libs.tar'),'-C',libraries]);
const browser=await playwright.launch({args:chromium.args,executablePath:await chromium.executablePath(),headless:true,
 env:{...process.env,LD_LIBRARY_PATH:path.join(libraries,'lib')+':'+(process.env.LD_LIBRARY_PATH||'')}});
let checks=0;
// Exercise the actual page entry points with the actual legacy footer overrides present.
for(const rel of ['DGV7.0-SAAS/bc-admin/Users.php','DGV7.0-NON-SAAS/bc-admin/Users.php','DGV7.0-SAAS/bc-spadmin/Users.php']) {
 const source=fs.readFileSync(path.resolve(dir,'../..',rel),'utf8');
 const sp=rel.includes('bc-spadmin');
 const names=sp?['sec33OpenUserModal','updateAPI']:['sec33OpenUserModal','updateUserAccountAPIStatus'];
 const js=[...source.matchAll(/^    function (\w+)\([^\n]*\) \{[\s\S]*?^    \}/gm)].filter(m=>names.includes(m[1])).map(m=>m[0]).join('\n');
 let destination;
 const sandbox={URLSearchParams,location:{assign:url=>{destination=url}}};sandbox.window=sandbox;
 vm.createContext(sandbox);vm.runInContext(js,sandbox);
 if(!sp){
  const legacy=fs.readFileSync(path.resolve(dir,'../..',rel.split('/')[0],'jsfile/bc-custom-vendor.js'),'utf8');
  vm.runInContext(legacy.slice(legacy.indexOf('    // Page-specific secure handlers'),legacy.indexOf('    function getCookieDet')),sandbox);
 }
 for(const status of ['1','2']){
  if(sp) sandbox.updateAPI('test+user','7',status==='1');
  else sandbox.updateUserAccountAPIStatus(status,'test+user','42');
  const target=new URL(destination,'https://console.test/bc-admin/');
  assert.ok(target.pathname.endsWith('/UserAction.php'));
  assert.equal(target.searchParams.get('mode'),'api');
  assert.equal(target.searchParams.get('status'),status);
  assert.equal(target.searchParams.get('username'),'test+user');
  if(sp) assert.equal(target.searchParams.get('vid'),'7');
  else assert.equal(target.searchParams.get('id'),rel.includes('NON-SAAS')?'':'42');
  assert.ok(!target.searchParams.has('sec_pin')&&!target.searchParams.has('sec_factor'));
  checks+=6;
 }
}

try {
 const page=await browser.newPage({javaScriptEnabled:false});
 let current;let posted;
 await page.route('https://console.test/**', async r=>{
  if(r.request().method()==='POST'){
   posted=Object.fromEntries(new URLSearchParams(r.request().postData()));
   return r.fulfill({body:'<!doctype html><p>Request captured</p>',contentType:'text/html'});
  }
  return r.fulfill({body:current.html,contentType:'text/html'});
 });
 for(const width of [390,1280]) {
  await page.setViewportSize({width,height:850});
  for(current of fixtures){
   await page.goto('https://console.test/UserAction.php');
   assert.equal(await page.locator('h1').isVisible(),true);
   const pin=await page.locator('[name=sec_pin]').boundingBox();
   assert.ok(pin.x>=0 && pin.width>250 && pin.x+pin.width<=width && pin.y>=0 && pin.y+pin.height<=850,'usable input in viewport');
   posted=undefined;
   await page.locator('.actions button').click();
   assert.equal(posted,undefined,'empty credentials blocked by HTML validation without JS');
   await page.locator('[name=sec_pin]').fill('1234');
   await page.locator('[name=sec_factor]').fill('123456');
   await Promise.all([page.waitForURL('**/Users.php'),page.locator('.actions button').click()]);
   assert.equal(posted.sec33_user_action,current.mode);
   assert.equal(posted.sec33_status,current.status);
   assert.equal(posted['account-user-id'],'42');
   assert.equal(posted['account-username'],'test+user');
   assert.equal(posted._csrf_token,'fixture-token');
   assert.equal(posted.sec_factor,'123456');
   assert.equal(posted.vid,current.super?'7':undefined);
   await page.goto('https://console.test/UserAction.php');
   if(current.enrolled){
    assert.equal(await page.locator('[name=sec33_send_code]').count(),0,'no TOTP email bypass');
   }else{
    await Promise.all([page.waitForURL('**/Users.php'),page.locator('[name=sec33_send_code]').click()]);
    assert.equal(posted.sec33_send_code,'1','email submission works with empty credentials and no JS');
    assert.equal(posted['account-user-id'],'42');
   }
   checks+=11;
  }
 }
 console.log(`${checks} standalone user-confirmation browser checks passed, mobile/desktop with JavaScript disabled.`);
}finally{await browser.close();fs.rmSync(libraries,{recursive:true,force:true});}
