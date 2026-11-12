const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');const {chromium}=require('playwright');
(async()=>{const assets=path.resolve(__dirname,'../public/assets'),dir=process.argv[2]||'/tmp/remit-redesign';const browser=await chromium.launch({executablePath:process.env.CHROME_EXECUTABLE||undefined,args:['--no-sandbox']});let checks=0;
try{
 for(const [file,expectedTitle,expectedCta,expectedUrlPart] of [
  ['overview.html','A quick start for the treasury.','Review payments','page=remittances'],
  ['parish_officer-overview.html','Your monthly remittance, in three steps.','Start remittance','#offering-report'],
  ['parish_officer-remittances.html','Your monthly remittance, in three steps.','Start remittance','#offering-report']
 ]){
  for(const width of [320,390,1024]){
   const context=await browser.newContext({viewport:{width,height:900}});
   const page=await context.newPage();
   const errors=[];page.on('pageerror',e=>errors.push(e.message));
   await page.route('http://remit.test/**',route=>{
    const url=new URL(route.request().url());
    if(url.pathname.includes('/assets/')){
     const f=path.join(assets,path.basename(url.pathname));
     return route.fulfill({contentType:f.endsWith('.css')?'text/css':'text/javascript',body:fs.readFileSync(f)});
    }
    const target=url.searchParams.get('page')==='remittances'?(file.startsWith('parish_officer')?'parish_officer-remittances.html':'remittances.html'):file;
    return route.fulfill({contentType:'text/html',body:fs.readFileSync(path.join(dir,target),'utf8')});
   });
   await page.goto('http://remit.test/index.php?page='+(file.includes('remittances')?'remittances':'overview'));
   const openBtn=page.locator('[data-guide-open]');
   const modal=page.locator('#quick-guide-modal');
   assert(await openBtn.isVisible());
   assert.equal(await modal.evaluate(e=>e.open),false);
   assert.equal(await openBtn.getAttribute('aria-expanded'),'false');
   checks+=3;

   await openBtn.click();
   assert.equal(await modal.evaluate(e=>e.open),true);
   assert(await modal.isVisible());
   assert.equal(await openBtn.getAttribute('aria-expanded'),'true');
   assert.equal(await page.locator('#quick-guide-title').textContent(),expectedTitle);
   assert.equal(await page.locator('#quick-guide-modal .guide-steps li').count(),3);
   checks+=5;

   await page.getByRole('button',{name:'Close welcome guide'}).click();
   assert.equal(await modal.evaluate(e=>e.open),false);
   assert.equal(await openBtn.getAttribute('aria-expanded'),'false');
   assert(await openBtn.evaluate(e=>e===document.activeElement));
   checks+=3;

   await openBtn.click();
   assert.equal(await modal.evaluate(e=>e.open),true);
   await page.keyboard.press('Escape');
   assert.equal(await modal.evaluate(e=>e.open),false);
   assert(await openBtn.evaluate(e=>e===document.activeElement));
   checks+=3;

   await openBtn.click();
   await page.locator('#quick-guide-modal .guide-actions a').click();
   assert(page.url().includes(expectedUrlPart),`Expected URL to include ${expectedUrlPart}, got ${page.url()}`);
   assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);
   assert.deepEqual(errors,[]);
   checks+=3;
   await context.close();
  }
 }
 console.log(`PASS: ${checks} welcome guide modal assertions (admin & representative, mobile/desktop, Escape, focus, CTA)`);
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
