import fs from 'node:fs';import vm from 'node:vm';import assert from 'node:assert/strict';
const source=fs.readFileSync(new URL('../../DGV7.0-SAAS/asset/bc-admin-wallet.js',import.meta.url),'utf8');
const handlers={};let calls=[],response={status:'success',message:'SENT'};
class Data{constructor(form){this.map=new Map(Object.entries(form?.values||{}));}set(k,v){this.map.set(k,v);}}
const context={window:{},document:{addEventListener:(k,v)=>handlers[k]=v},location:{href:'https://tenant.test/bc-admin/ShareFund.php'},FormData:Data,fetch:async(url,opts)=>{calls.push(Object.fromEntries(opts.body.map));return {json:async()=>response};}};
vm.runInNewContext(source,context);const first=handlers.click;vm.runInNewContext(source,context);assert.equal(handlers.click,first,'no duplicate handlers');
const status={textContent:''},pin={value:'9362'},code={value:'123456'};
const form={values:{_csrf_token:'csrf',user:'customer',amount:'100',pin:'9362',otp_code:'123456',wallet_request:'nonce'},elements:{_csrf_token:{value:'csrf'},factor_method:{value:'auto'}},dataset:{},querySelector:()=>status,querySelectorAll:()=>[pin,code],matches:()=>true};
const button={dataset:{securitySend:'send_stepup_code'},textContent:'Send code',disabled:false,closest:()=>form};
await handlers.click({target:{closest:()=>button}});assert.deepEqual(calls[0],{_csrf_token:'csrf',send_stepup_code:'1'});assert.equal(button.textContent,'SENT');assert.equal(code.value,'123456');assert.equal(pin.value,'9362');assert.equal(form.elements.factor_method.value,'email');
button.disabled=true;await handlers.click({target:{closest:()=>button}});assert.equal(calls.length,1);button.disabled=false;
const submit={name:'share-fund',value:'1',dataset:{},disabled:false};response={status:'error',message:'Invalid PIN'};
await handlers.submit({target:form,submitter:submit,preventDefault(){}});assert.equal(pin.value,'9362');assert.equal(code.value,'123456');assert.equal(submit.disabled,false);assert.equal(status.textContent,'Invalid PIN');
response={status:'success',message:'Wallet credit recorded'};await handlers.submit({target:form,submitter:submit,preventDefault(){}});assert.equal(submit.disabled,true);assert.equal(pin.value,'');assert.equal(code.value,'');
console.log('Admin wallet UI passed: SENT on button, no reload/secret leak/input loss, duplicate handler/request guards.');
