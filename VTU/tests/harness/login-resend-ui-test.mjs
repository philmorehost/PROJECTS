import fs from 'node:fs';import vm from 'node:vm';import assert from 'node:assert/strict';
for(const edition of ['SAAS']){
 const source=fs.readFileSync(`VTU/DGV7.0-${edition}/asset/bc-login-resend.js`,'utf8');
 for(const result of ['success','error','invalid-json']){
  let click,resolve,requests=0;const status={setAttribute(){},textContent:''};
  const fields={_csrf_token:{value:'csrf'},sec_pin:{value:'private'},sec_factor:{value:'123456'},reason:{value:'keep reason'}};
  const form={action:'/admin/decision',elements:{namedItem(n){return fields[n];}},appendChild(el){fields[el.name]=el;}};
  const reviewFields={sec_factor:{value:'keep review code'}};const reviewForm={elements:{namedItem(n){return reviewFields[n];}},appendChild(el){reviewFields[el.name]=el;}};
  const button={disabled:false,closest(){return form;},after(){},addEventListener(_,fn){click=fn;}};
  class FormData{data=new Map();set(k,v){this.data.set(k,v);}}
  vm.runInNewContext(source,{document:{querySelector(){return button;},createElement(tag){return tag==='p'?status:{};}},FormData,Error,SyntaxError,fetch:async(url,opts)=>{
   requests++;assert.equal(url,form.action);assert.deepEqual([...opts.body.data.keys()],['_csrf_token','resend-login-otp']);assert.equal(opts.headers['X-Requested-With'],'XMLHttpRequest');
   await new Promise(r=>resolve=r);return {ok:true,json:async()=>{if(result==='invalid-json')throw new SyntaxError('html');return {status:result,message:'Refused'};}};
  }});
  let prevented=0;const event={preventDefault(){prevented++;}};const promise=click(event);await click(event);assert.equal(requests,1);resolve();await promise;
  assert.equal(prevented,2);assert.equal(button.disabled,false);assert.equal(fields.sec_pin.value,'private');assert.equal(fields.sec_factor.value,'123456');assert.equal(fields.reason.value,'keep reason');
  if(result==='success'){assert.equal(button.textContent,'SENT');assert.equal(status.textContent,'OTP SENT');}else assert.notEqual(status.textContent,'OTP SENT');
  assert.equal(fields.factor_method,undefined);
 }
}
console.log('Login resend: preserves entered code, sends no password/code, blocks duplicates and reports inline errors.');
