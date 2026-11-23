import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
const source=fs.readFileSync(new URL('../../DGV7.0-SAAS/asset/bc-app-update-otp.js',import.meta.url),'utf8');
async function run(reply,invalid=false){
 let click,resolve,requests=0;
 const status={textContent:''};
 const secret={value:'PRIVATE_SERVICE_ACCOUNT_UNCHANGED'};
 const form={action:'https://example.test/bc-admin/AppUpdateBroadcast.php',elements:{namedItem(name){return name==='_csrf_token'?{value:'csrf'}:secret;}},querySelector(){return status;}};
 const button={disabled:false,dataset:{appUpdateOtp:'app_fcm_save'},closest(){return form;},addEventListener(_,fn){click=fn;}};
 class FormData{values=new Map();set(k,v){this.values.set(k,v);}}
 vm.runInNewContext(source,{document:{querySelectorAll(){return [button];}},FormData,Error,SyntaxError,fetch:async(url,opts)=>{
  requests++;assert.equal(url,form.action);assert.equal(opts.method,'POST');assert.equal(opts.credentials,'same-origin');
  assert.deepEqual([...opts.body.values.keys()],['_csrf_token','app_update_send_code']);
  assert.equal(opts.body.values.get('app_update_send_code'),'app_fcm_save');
  await new Promise(r=>{resolve=r;});
  return {ok:true,json:async()=>{if(invalid)throw new SyntaxError('not JSON');return reply;}};
 }});
 const pending=click();assert.equal(button.disabled,true);await click();assert.equal(requests,1);resolve();await pending;
 assert.equal(button.disabled,false);assert.equal(secret.value,'PRIVATE_SERVICE_ACCOUNT_UNCHANGED');
 return status.textContent;
}
assert.equal(await run({status:'success'}),'OTP SENT');
assert.equal(await run({status:'error',message:'Wait before retrying.'}),'Wait before retrying.');
assert.match(await run(null,true),/form values have been kept/);
assert.equal(source,fs.readFileSync(new URL('../../DGV7.0-NON-SAAS/asset/bc-app-update-otp.js',import.meta.url),'utf8'));
console.log('App-update inline OTP behavior passed: no reload, duplicate request, lost values or secret/PIN leakage.');
