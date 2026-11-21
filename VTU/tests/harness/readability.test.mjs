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
const fixture=JSON.parse(execFileSync(process.execPath,[path.join(dir,'run-test.mjs'),'tests/readability_fixture.php'],{encoding:'utf8',maxBuffer:4*1024*1024}));
// The npm binary bundles NSS/NSPR for minimal Linux CI images as well as Lambda.
const require=createRequire(import.meta.url);
const libraries=fs.mkdtempSync(path.join(os.tmpdir(),'readability-libs-'));
const archive=path.resolve(path.dirname(require.resolve('@sparticuz/chromium')),'../../bin/al2023.tar.br');
fs.writeFileSync(path.join(libraries,'libs.tar'),brotliDecompressSync(fs.readFileSync(archive)));
execFileSync('tar',['-xf',path.join(libraries,'libs.tar'),'-C',libraries]);
const browser=await playwright.launch({args:chromium.args,executablePath:await chromium.executablePath(),headless:true,
 env:{...process.env,LD_LIBRARY_PATH:path.join(libraries,'lib')+':'+(process.env.LD_LIBRARY_PATH||'')}});
let checks=0;
try {
 const page=await browser.newPage();
 for(const width of [1280,390]){
  await page.setViewportSize({width,height:1100});
  for(const [brand,css] of Object.entries(fixture.styles)){
   const badges=['secondary','success','danger','warning','info','dark'].flatMap(name=>[10,25,50,75,100].map(op=>`<span class="badge bg-${name} bg-opacity-${op} text-${name} check">${name} status</span>`)).join('');
   const panels=[0,10,20,25,50,75,100].map(op=>`<section class="bg-primary bg-opacity-${op}"><label class="text-dark-primary check">Label ${op}</label><p class="text-muted check">Muted description</p><i class="text-primary check" aria-label="Action icon">◆</i><a class="text-primary check" href="#">Link</a><span class="text-white check">Legacy white label</span><button class="btn btn-primary"><span class="text-primary check">Nested button</span></button><div class="card bg-white"><span class="text-muted check">Nested neutral card</span><input class="form-control check" value="Input text"></div></section>`).join('');
   await page.setContent(`<!doctype html>${css}<body style="background:white"><main class="pk-app__wrap">${fixture.panel}${panels}${badges}<div class="nav-pills"><button class="nav-link active check">Selected settings tab</button></div></main></body>`);
   await page.locator('.btn-primary').first().hover();
   const result=await page.evaluate(()=>{
    const rgb=s=>(s.match(/[\d.]+/g)||[]).map(Number);
    const blend=(a,b)=>{let alpha=a.length>3?a[3]:1;return [0,1,2].map(i=>a[i]*alpha+b[i]*(1-alpha))};
    const background=el=>{if(!el)return [255,255,255];const c=rgb(getComputedStyle(el).backgroundColor);return blend(c.length?c:[0,0,0,0],background(el.parentElement));};
    const lum=a=>{const l=a.map(c=>{c/=255;return c<=.04045?c/12.92:((c+.055)/1.055)**2.4});return .2126*l[0]+.7152*l[1]+.0722*l[2]};
    const contrast=(a,b)=>{const x=lum(a),y=lum(b);return (Math.max(x,y)+.05)/(Math.min(x,y)+.05)};
    const els=[...document.querySelectorAll('.check, label.text-dark-primary, p.text-dark-primary')];
    return {tint:getComputedStyle(document.querySelector('.bg-opacity-10')).backgroundColor,failures:els.map(el=>{const style=getComputedStyle(el),bg=background(el),fg=blend([...rgb(style.color).slice(0,3),Number(style.opacity)],bg);return {text:el.textContent||el.value,ratio:contrast(fg,bg),color:style.color,background:bg}}).filter(r=>r.ratio<4.5),count:els.length,overflow:document.documentElement.scrollWidth>innerWidth};
   });
   assert.deepEqual(result.failures,[],`${brand} at ${width}px has unreadable text: ${JSON.stringify(result.failures)}`);
   assert.equal(result.overflow,false,`${brand} horizontal overflow at ${width}px`);
   if(brand==='#10756F')assert.equal(result.tint,'rgb(231, 241, 241)','reported panel is a pale tint, not solid teal');
   checks+=result.count;
  }
 }
 console.log(`${checks} browser contrast checks passed across eight brands, seven opacity levels and desktop/mobile viewports.`);
} finally {await browser.close();fs.rmSync(libraries,{recursive:true,force:true})}
