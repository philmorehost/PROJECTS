/* Optional visual/interaction regression. Requires Playwright and a Chromium binary.
   Generate role fixtures with tests/mobile-ui.php first. No database or live data is used. */
const fs=require('node:fs');const path=require('node:path');const assert=require('node:assert/strict');
const {chromium}=require('playwright');
(async()=>{
 const root=path.resolve(__dirname,'..');const dir=process.argv[2];
 if(!dir)throw new Error('Provide the generated fixture directory.');
 const assets=path.join(root,'public/assets');
 const css=fs.readFileSync(path.join(assets,fs.readdirSync(assets).find(f=>f.endsWith('.css'))),'utf8');
 const js=fs.readFileSync(path.join(assets,fs.readdirSync(assets).find(f=>f.endsWith('.js'))),'utf8');
 const browser=await chromium.launch({headless:true,executablePath:process.env.CHROME_EXECUTABLE||undefined,args:['--no-sandbox']});
 let checks=0;
 try{
  for(const role of ['parish_officer','provincial_admin','area_rep']){
   const page=await browser.newPage();page.setDefaultTimeout(5000);const errors=[];page.on('pageerror',e=>errors.push(e.message));
   for(const width of [320,390,768,900,1440]){
    console.log('Testing',role,width);
    await page.goto('about:blank');await page.setViewportSize({width,height:860});
    await page.setContent(fs.readFileSync(path.join(dir,role+'.html'),'utf8'));await page.addStyleTag({content:css});if(width<=900){assert(await page.locator('.mobile-fallback-nav').isVisible());checks++;}await page.addScriptTag({content:js});
    const layout=await page.evaluate(()=>({body:parseFloat(getComputedStyle(document.body).fontSize),input:parseFloat(getComputedStyle(document.querySelector('#test-input')).fontSize),overflow:document.documentElement.scrollWidth>innerWidth,nav:getComputedStyle(document.querySelector('.mobile-bottom-nav')).display,sidebar:getComputedStyle(document.querySelector('.sidebar')).display}));
    assert(layout.body>=16);assert(layout.input>=16);assert(!layout.overflow,`${role} at ${width}: page overflow`);checks+=3;
    if(width<=900){
     assert.equal(layout.sidebar,'none');assert.equal(layout.nav,'grid');checks+=2;
     assert(await page.locator('.mobile-bottom-nav span').first().evaluate(e=>getComputedStyle(e).position==='absolute'));checks++;
     const more=page.getByRole('button',{name:'More',exact:true});const box=await more.boundingBox();assert(box.width>=44&&box.height>=44);checks++;
     await more.click();assert.equal(await more.getAttribute('aria-expanded'),'true');assert(await page.getByRole('dialog').isVisible());checks+=2;
     await page.keyboard.press('Escape');await page.waitForFunction(()=>document.querySelector('.mobile-bottom-nav button').getAttribute('aria-expanded')==='false');assert(!(await page.getByRole('dialog').isVisible()));assert.equal(await more.getAttribute('aria-expanded'),'false');assert(await more.evaluate(e=>document.activeElement===e));checks+=3;
     await page.getByRole('button',{name:'Open navigation menu'}).click();await page.getByRole('button',{name:'Close navigation menu'}).click();await page.waitForFunction(()=>document.querySelector('.mobile-bottom-nav button').getAttribute('aria-expanded')==='false');assert(!(await page.getByRole('dialog').isVisible()));checks++;
     if(width<=600){assert.equal(await page.locator('#ledger td').first().evaluate(e=>getComputedStyle(e).display),'block');checks++;}
    }else{assert.equal(layout.nav,'flex');assert.equal(layout.sidebar,'none');assert(await page.locator('.mobile-bottom-nav span').first().evaluate(e=>getComputedStyle(e).position==='static'));checks+=3;}
    await page.locator('#ledger-status').selectOption('verified');assert.equal(await page.locator('#ledger tr[data-status]:visible').count(),1);checks++;
    await page.locator('#ledger-search').fill('pending');assert.equal(await page.locator('#ledger tr[data-status]:visible').count(),0);assert(await page.locator('#ledger-no-results').isVisible());checks+=2;
    await page.locator('#ledger-search').fill('');await page.locator('#ledger-status').selectOption('');assert.equal(await page.locator('#ledger tr[data-status]:visible').count(),2);checks++;
   }
   assert.deepEqual(errors,[]);checks++;await page.close();
  }
  console.log(`PASS: ${checks} browser assertions across three roles and five viewport widths`);
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
