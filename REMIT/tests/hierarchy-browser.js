const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');const {chromium}=require('playwright');
(async()=>{
 const root=path.resolve(__dirname,'..'),assets=path.join(root,'public/assets');
 const css=fs.readFileSync(path.join(assets,fs.readdirSync(assets).find(f=>f.endsWith('.css'))),'utf8');const js=fs.readFileSync(path.join(assets,fs.readdirSync(assets).find(f=>f.endsWith('.js'))),'utf8');
 const browser=await chromium.launch({headless:true,executablePath:process.env.CHROME_EXECUTABLE||undefined,args:['--no-sandbox']});let checks=0;
 try{for(const width of [390,1440]){
  const page=await browser.newPage({viewport:{width,height:900}});page.setDefaultTimeout(5000);const errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.setContent(fs.readFileSync(process.argv[2],'utf8'));await page.addStyleTag({content:css});
  assert(await page.locator('#single-unit select').isVisible());checks++;
  await page.addScriptTag({content:js});const input=page.getByRole('combobox',{name:'Church unit',exact:true});
  await input.fill('Example Parish');assert.equal(await page.locator('#unit-results-0 [role=option]').count(),20);assert((await page.locator('#single-unit .unit-search-status').textContent()).includes('50 matches'));checks+=2;
  await input.fill('CODE47');assert.equal(await page.locator('#unit-results-0 [role=option]').count(),1);await page.locator('#unit-results-0 [role=option]').click();
  assert.equal(await page.locator('#single-unit select').inputValue(),'147');assert.equal(await input.getAttribute('aria-expanded'),'false');checks+=3;
  await input.fill('not a real church');assert.equal(await page.locator('#single-unit select').inputValue(),'');assert(!(await input.evaluate(e=>e.checkValidity())));assert((await page.locator('#single-unit .unit-search-status').textContent()).includes('No matching'));checks+=3;
  await input.fill('CODE49');await input.press('ArrowDown');assert(await input.getAttribute('aria-activedescendant'));await input.press('Enter');assert.equal(await page.locator('#single-unit select').inputValue(),'149');checks+=2;
  await input.click();await input.press('Escape');assert.equal(await input.getAttribute('aria-expanded'),'false');checks++;
  const bulk=page.locator('[data-unit-bulk]');const filter=bulk.locator('[data-bulk-search]');await filter.fill('CODE');await bulk.locator('[data-bulk-type]').selectOption('parish');await bulk.locator('[data-bulk-all]').click();assert.equal(await bulk.locator('input[type=checkbox]:checked').count(),50);checks++;
  await filter.fill('Planted');assert((await bulk.locator('[data-bulk-count]').textContent()).includes('50 selected'));assert.equal(await bulk.locator('.unit-check:visible').count(),1);checks+=2;
  await bulk.locator('[data-bulk-clear]').click();assert.equal(await bulk.locator('input[type=checkbox]:checked').count(),0);checks++;
  await filter.fill('');await bulk.locator('[data-bulk-type]').selectOption('area');await bulk.locator('[data-bulk-all]').click();assert.equal(await bulk.locator('input[type=checkbox]:checked').count(),1);checks++;
  const dest=page.getByRole('combobox',{name:'Destination zone or area',exact:true});await dest.fill('Zone Beta');await dest.press('Enter');assert.equal(await bulk.locator('select[name=destination_id]').inputValue(),'2');checks++;
  assert(!(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth)));assert.deepEqual(errors,[]);checks+=2;await page.close();
 }console.log(`PASS: ${checks} church autocomplete/bulk-selection browser assertions`);}finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
