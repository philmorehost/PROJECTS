import fs from 'node:fs';import vm from 'node:vm';import assert from 'node:assert/strict';
for(const edition of ['SAAS','NON-SAAS'])for(const purpose of ['withdrawal','giftcard'])for(const mode of ['success','refused','invalid-json','network']){
 let click,resolve,calls=0;const status={textContent:'',setAttribute(){}};
 const button={disabled:false,dataset:{featureOtp:purpose,otpEndpoint:'/feature',csrf:'csrf'},parentElement:{after(){}},addEventListener(_,fn){click=fn;}};
 class FormData{data=new Map();set(k,v){this.data.set(k,v);}}
 vm.runInNewContext(fs.readFileSync(`VTU/DGV7.0-${edition}/asset/bc-feature-email-otp.js`,'utf8'),{document:{querySelectorAll(){return [button];},createElement(){return status;}},FormData,Error,SyntaxError,fetch:async(url,options)=>{
  calls++;assert.equal(url,'/feature');assert.equal(options.credentials,'same-origin');assert.equal(options.body.data.get('_csrf_token'),'csrf');assert.deepEqual([...options.body.data.keys()],['_csrf_token',purpose==='withdrawal'?'send_otp':'action']);
  await new Promise(r=>resolve=r);if(mode==='network')throw new Error('offline');return {ok:mode!=='refused',json:async()=>{if(mode==='invalid-json')throw new SyntaxError('html');return {status:mode==='success'?'success':'error',message:'Service unavailable'};}};
 }});
 const event={preventDefault(){}};const pending=click(event);await click(event);assert.equal(calls,1);assert.equal(button.disabled,true);resolve();await pending;
 assert.equal(button.disabled,false);if(mode==='success'){assert.equal(button.textContent,'SENT');assert(status.textContent.startsWith('OTP SENT'));}else{assert.equal(button.textContent,'Retry code');assert(status.textContent);}
}
console.log('Both editions/features: purpose+CSRF only; inline SENT/error, duplicate guard, no form reset/navigation/alerts.');
