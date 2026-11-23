import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
const php=fs.readFileSync(new URL('../../DGV7.0-SAAS/func/bc-paid-security.php',import.meta.url),'utf8');
const script=php.match(/<script>([\s\S]*?)<\/script>/)[1];
const listeners={};let calls=[],response={status:'success',message:'OTP SENT'};
class Data {
 constructor(form){this.map=new Map(Object.entries(form.values));}
 set(k,v){this.map.set(k,v);}keys(){return this.map.keys();}delete(k){this.map.delete(k);}
}
const context={document:{addEventListener:(name,fn)=>listeners[name]=fn},location:{href:'https://vendor.example.test/bc-admin/PaymentGateway.php'},FormData:Data,fetch:async(url,opts)=>{calls.push({url,data:Object.fromEntries(opts.body.map)});return {json:async()=>response};}};
vm.runInNewContext(script,context);
const status={textContent:''},secret={value:'keep-secret'},factor={value:'123456'};
const form={values:{_csrf_token:'csrf',pin:'1234',paid_factor:'123456',gateway_secret:'keep-secret',paid_quote:'150000.00'},dataset:{},action:'https://vendor.example.test/bc-admin/PaymentGateway.php',querySelector:()=>status,querySelectorAll:()=>[secret,factor],matches:s=>s==='[data-paid-save]'};
const button={dataset:{paidOtp:'paid_gateway_bybit'},disabled:false,closest:()=>form};
await listeners.click({target:{closest:()=>button}});
assert.deepEqual(calls[0].data,{_csrf_token:'csrf',paid_send_otp:'paid_gateway_bybit'});
assert.equal(status.textContent,'OTP SENT');assert.equal(secret.value,'keep-secret');assert.equal(factor.value,'123456');assert.equal(button.disabled,false);
button.disabled=true;await listeners.click({target:{closest:()=>button}});assert.equal(calls.length,1);button.disabled=false;
const save={name:'save-paid-gateway',value:'bybit',dataset:{},disabled:false};let prevented=false;
response={status:'error',message:'Invalid PIN'};
await listeners.submit({target:form,submitter:save,preventDefault:()=>prevented=true});
assert.ok(prevented);assert.equal(calls[1].data['save-paid-gateway'],'bybit');assert.equal(calls[1].data.gateway_secret,'keep-secret');assert.equal(status.textContent,'Invalid PIN');assert.equal(secret.value,'keep-secret');assert.equal(factor.value,'123456');assert.equal(save.disabled,false);
response={status:'success',message:'Encrypted settings saved'};
await listeners.submit({target:form,submitter:save,preventDefault:()=>{}});
assert.equal(status.textContent,'Encrypted settings saved');assert.equal(secret.value,'');assert.equal(factor.value,'');assert.equal(save.disabled,false);
const buy={name:'buy-fintech-api',value:'bybit',dataset:{},disabled:false};
await listeners.submit({target:form,submitter:buy,preventDefault:()=>{}});assert.equal(buy.disabled,true);assert.match(buy.textContent,/Access confirmed/);
context.fetch=async()=>{throw new Error('network');};
await listeners.submit({target:form,submitter:save,preventDefault:()=>{}});assert.match(status.textContent,/do not assume the purchase failed/);
console.log('Paid gateway UI passed: inline OTP/save, duplicate-send guard, secret stripping, error retention, uncertain outcome warning.');
