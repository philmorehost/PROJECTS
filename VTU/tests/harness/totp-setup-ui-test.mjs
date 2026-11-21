import {JSDOM} from 'jsdom';
import fs from 'node:fs';
import assert from 'node:assert/strict';
const source=fs.readFileSync('VTU/DGV7.0-SAAS/asset/bc-totp-setup.js','utf8');
const dom=new JSDOM(`<section class="bc-totp-setup"><form><input name="_csrf_token" value="csrf"><input name="email_code"><input name="totp_code"><button type="button" data-step="send">Send</button><button type="button" data-step="verify">Verify</button><div data-key-stage hidden><button type="button" data-step="begin">Generate</button><div data-secret-stage hidden><div data-qr></div><code data-secret></code><button type="button" data-step="confirm">Save</button></div></div></form><p data-setup-status></p><a data-continue hidden>Continue</a></section>`,{url:'https://tenant.test/bc-admin/AccountSettings.php',runScripts:'outside-only'});
const w=dom.window,doc=w.document,requests=[];let next={ok:true,message:'Sent'},fail=false;
w.fetch=async (url,options)=>{requests.push({url,options});if(fail)throw Error('network');return {json:async()=>next};};
w.eval(source);
const card=doc.querySelector('section'),form=doc.querySelector('form');
const tick=()=>new Promise(r=>setTimeout(r,0));
async function click(step,result){next=result;doc.querySelector(`[data-step="${step}"]`).click();await tick();assert.equal(doc.querySelector('section'),card);assert.equal(w.location.href,'https://tenant.test/bc-admin/AccountSettings.php');assert.equal(doc.querySelector('[data-step="send"]').disabled,false);}
await click('send',{ok:true,message:'Sent'});assert.equal(requests.length,1);assert.equal(requests[0].options.redirect,'error');assert.equal(requests[0].options.body.get('_csrf_token'),'csrf');
await click('verify',{ok:true,verified:true,message:'Verified'});assert.equal(requests.length,1); // malformed code handled inline
form.elements.email_code.value='123456';
await click('verify',{ok:true,verified:true,message:'Verified'});assert.equal(doc.querySelector('[data-key-stage]').hidden,false);
await click('begin',{ok:true,secret:'SYNTHETIC',uri:'otpauth://test',message:'Not saved yet'});assert.equal(doc.querySelector('[data-secret]').textContent,'SYNTHETIC');assert.equal(form.hidden,false);
form.elements.totp_code.value='000000';await click('confirm',{ok:false,message:'Wrong app code'});assert.equal(doc.querySelector('[data-setup-status]').textContent,'Wrong app code');assert.equal(form.hidden,false);assert.equal(doc.querySelector('[data-secret]').textContent,'SYNTHETIC');
fail=true;await click('confirm',{});assert.match(doc.querySelector('[data-setup-status]').textContent,/Connection or session/);assert.equal(form.hidden,false);fail=false;
await click('confirm',{ok:true,enabled:true,message:'Saved — enabled'});assert.equal(form.hidden,true);assert.equal(doc.querySelector('[data-secret]').textContent,'');assert.equal(doc.querySelector('[data-continue]').hidden,false);assert.match(doc.querySelector('[data-setup-status]').textContent,/Saved/);
console.log('No-reload setup UI: send, validation, verify, generate, retry, network failure, save and secret clearing PASS');
