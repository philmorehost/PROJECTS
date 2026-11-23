import fs from 'node:fs';import {JSDOM} from 'jsdom';import assert from 'node:assert/strict';
const tick=()=>new Promise(r=>setTimeout(r,0));
for(const edition of ['SAAS','NON-SAAS']){
 const source=fs.readFileSync(`VTU/DGV7.0-${edition}/func/bc-plate-admin.php`,'utf8');
 const start=source.indexOf('<div class="modal" id="forcePinModal"'),end=source.indexOf('</script>',start);
 const html=source.slice(start,end+9).replace(/<\?php echo json_encode\(bc_generate_csrf_token\(\).*?\?>/s,'"csrf-test"');
 for(const outcome of ['saved','refused','redirect','html','bad-json','network','legacy-success']){
  const dom=new JSDOM(html,{url:'https://tenant.test/bc-admin/Dashboard.php',runScripts:'outside-only'}),w=dom.window,d=w.document;let calls=0,release;
  w.fetch=async(url,opts)=>{calls++;assert.equal(url,'/bc-admin/AccountSettings.php');assert.equal(opts.credentials,'same-origin');assert.equal(opts.body.get('_csrf_token'),'csrf-test');assert.equal(opts.body.get('admin_pin'),'0123');
   await new Promise(r=>release=r);
   if(outcome==='network')throw new w.TypeError('fetch failed');
   return {redirected:outcome==='redirect',headers:{get:()=>outcome==='html'?'text/html':'application/json'},json:async()=>{if(outcome==='bad-json')throw new SyntaxError('bad');if(outcome==='saved')return {ok:true,code:'saved'};if(outcome==='legacy-success')return {ok:true};return {ok:false,msg:'Security token expired.'};}};
  };
  w.eval(d.querySelector('script').textContent);d.dispatchEvent(new w.Event('DOMContentLoaded'));
  const modal=d.getElementById('forcePinModal'),button=d.getElementById('forcePinSubmit'),pin=d.getElementById('forcePinInput'),confirm=d.getElementById('forcePinConfirm');
  pin.value='123';confirm.value='123';button.click();assert.equal(calls,0);
  pin.value='0123';confirm.value='0123';button.click();button.click();assert.equal(calls,1);assert.equal(button.disabled,true);release();await tick();await tick();
  if(outcome==='saved')assert.equal(modal.style.display,'none');else {assert.equal(modal.style.display,'flex');assert.equal(button.disabled,false);assert.equal(pin.value,'0123');assert.equal(confirm.value,'0123');const error=d.getElementById('forcePinError');assert.equal(error.style.display,'block');assert.ok(error.textContent);if(outcome!=='network')assert.ok(!error.textContent.includes('connection'));}
  w.close();
 }
 console.log(`PASS ${edition} PIN modal: CSRF, duplicate prevention, save, refused/redirect/HTML/parse/network errors`);
}
