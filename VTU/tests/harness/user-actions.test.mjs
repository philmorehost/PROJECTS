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
const shellCSS=JSON.parse(execFileSync(process.execPath,[path.join(dir,'run-test.mjs'),'tests/readability_fixture.php'],{encoding:'utf8',maxBuffer:4*1024*1024})).styles['#10756F'];
// The npm binary bundles NSS/NSPR for minimal Linux CI images as well as Lambda.
const require=createRequire(import.meta.url);
const libraries=fs.mkdtempSync(path.join(os.tmpdir(),'user-action-libs-'));
const archive=path.resolve(path.dirname(require.resolve('@sparticuz/chromium')),'../../bin/al2023.tar.br');
fs.writeFileSync(path.join(libraries,'libs.tar'),brotliDecompressSync(fs.readFileSync(archive)));
execFileSync('tar',['-xf',path.join(libraries,'libs.tar'),'-C',libraries]);
const browser=await playwright.launch({args:chromium.args,executablePath:await chromium.executablePath(),headless:true,
 env:{...process.env,LD_LIBRARY_PATH:path.join(libraries,'lib')+':'+(process.env.LD_LIBRARY_PATH||'')}});
let checks = 0;
try {
 const page=await browser.newPage();
 await page.route('https://console.test/**',r=>r.fulfill({body:'<!doctype html><body></body>',contentType:'text/html'}));
 await page.goto('https://console.test/');
 for (const rel of ['DGV7.0-SAAS/bc-admin/Users.php','DGV7.0-NON-SAAS/bc-admin/Users.php','DGV7.0-SAAS/bc-spadmin/Users.php']) {
  const src=fs.readFileSync(path.resolve(dir,'../..',rel),'utf8');
  const sp=rel.includes('bc-spadmin');
  const functions=[...src.matchAll(/^    function (\w+)\([^\n]*\) \{[\s\S]*?^    \}/gm)];
  const names=sp?['sec33OpenUserModal','updateStatus','updateAPI','permanentlyDelete']:['sec33OpenUserModal','updateUserAccountStatus','updateUserAccountAPIStatus','permanentlyDeleteUser','loginUserAccount'];
  const js=functions.filter(m=>names.includes(m[1])).map(m=>m[0]).join('\n');
  assert.equal(names.filter(n=>js.includes('function '+n+'(')).length,names.length);
  const markup=src.match(/<dialog id="sec33UserModal"[\s\S]*?<\/dialog>/)[0].replace(/<\?php[\s\S]*?\?>/g,'');
  const cases=sp?[
   ["updateAPI('test+user','7',true)",'api','1'],["updateAPI('test+user','7',false)",'api','2'],
   ["updateStatus('3','test+user','7')",'status','3'],["permanentlyDelete('test+user','7')",'permanent','']
  ]:[
   ["updateUserAccountAPIStatus('1','test+user','42')",'api','1'],["updateUserAccountAPIStatus('2','test+user','42')",'api','2'],
   ["updateUserAccountStatus('3','test+user','42')",'status','3'],["permanentlyDeleteUser('test+user','42')",'permanent',''],
   ["loginUserAccount('42','test+user')",'log','']
  ];
  for(const width of [390,1280]) {
   await page.setViewportSize({width,height:850});
   const errors=[];page.on('pageerror',e=>errors.push(e.message));
   // Deliberately NO Bootstrap or SweetAlert scripts: the shell doesn't promise them.
   const legacy = sp ? '' : fs.readFileSync(path.resolve(dir,'../..',rel.split('/')[0],'jsfile/bc-custom-vendor.js'),'utf8');
   await page.setContent('<!doctype html>'+shellCSS+'<body><main class="pk-app__wrap"><div style="height:1400px">Users table</div></main>'+markup+'<script>'+js+'</script><script>'+legacy+'</script>');
   await page.evaluate(()=>{delete window.submitted;document.querySelector('form').addEventListener('submit',e=>{e.preventDefault();window.submitted=Object.fromEntries(new FormData(e.target,e.submitter));});});
   assert.equal(await page.locator('dialog').isVisible(),false);checks++;
   for(const [call,mode,status] of cases){
    await page.evaluate(call);
    assert.equal(await page.locator('dialog').isVisible(),true);
    const box=await page.locator('dialog').boundingBox();
    assert.ok(box.x>=0 && box.y>=0 && box.x+box.width<=width+1 && box.y+box.height<=851,'dialog stays in viewport');
    assert.equal(await page.locator('#sec33UserId').inputValue(),sp ? '' : (rel.includes('NON-SAAS') && mode !== 'log' ? '' : '42'), 'footer must not overwrite row ID');
    const inputBox=await page.locator('[name=sec_pin]').boundingBox();
    assert.ok(inputBox.width>box.width*.75 && inputBox.x>=box.x && inputBox.y>=box.y, 'usable fields inside overlay even outside content wrapper');
    assert.equal(await page.locator('#sec33UserMode').inputValue(),mode);
    assert.equal(await page.locator('#sec33UserStatus').inputValue(),status);
    assert.equal(await page.locator('#sec33UserName').inputValue(),'test+user');
    await page.locator('#sec33UserBtn').click();
    assert.equal(await page.evaluate(()=>window.submitted),undefined,'empty credentials cannot submit');
    await page.locator('[name=sec_pin]').fill('1234');
    await page.locator('[name=sec_factor]').fill('123456');
    await page.locator('#sec33UserBtn').click();
    const submitted=await page.evaluate(()=>window.submitted);
    assert.equal(submitted.sec33_user_action,mode);assert.equal(submitted.sec_factor,'123456');
    await page.getByRole('button',{name:'Cancel',exact:true}).click();
    assert.equal(await page.locator('dialog').isVisible(),false);
    await page.evaluate(()=>{delete window.submitted;});
    checks+=8;
   }
   await page.evaluate(cases[0][0]);
   await page.locator('[name=sec33_send_code]').click();
   const sent=await page.evaluate(()=>window.submitted);
   assert.ok('sec33_send_code' in sent,'email button submits without credentials');
   assert.equal(sent['account-username'],'test+user','request carries target for safe restoration');
   await page.keyboard.press('Escape');
   assert.equal(await page.locator('dialog').isVisible(),false);
   assert.deepEqual(errors,[]);
   checks+=4;
   page.removeAllListeners('pageerror');
  }
 }
 console.log(`${checks} real Chromium user-action checks passed (three panels, desktop/mobile, no external modal libraries).`);
} finally {await browser.close();fs.rmSync(libraries,{recursive:true,force:true});}
