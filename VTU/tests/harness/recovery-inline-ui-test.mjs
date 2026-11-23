import fs from 'node:fs';import vm from 'node:vm';import assert from 'node:assert/strict';
for(const edition of ['SAAS','NON-SAAS'])for(const mode of ['success','refused','invalid-json','network','missing-stage']){
 const script=fs.readFileSync(`VTU/DGV7.0-${edition}/asset/bc-recovery-inline.js`,'utf8');
 let click,resolve,requests=0,focused=false;
 const fields={_csrf_token:{value:'csrf'},email:{value:'fixture@example.test'},pass:{value:'never-send-password'},code:{value:'keep-code',focus(){focused=true;}}};
 const status={textContent:'',setAttribute(){}};
 const form={action:'/PasswordRecovery.php',innerHTML:'original',reportValidity(){return true;},elements:{namedItem(n){return fields[n];}}};
 const button={disabled:false,closest(){return form;},after(){},addEventListener(_,fn){click=fn;}};
 class FormData{data=new Map();set(k,v){this.data.set(k,v);}}
 vm.runInNewContext(script,{document:{querySelector(){return button;},createElement(){return status;}},FormData,SyntaxError,Error,fetch:async(url,options)=>{
  requests++;assert.equal(url,form.action);assert.deepEqual([...options.body.data.keys()],['_csrf_token','email','send-code']);assert.equal(options.credentials,'same-origin');assert.equal(options.headers['X-Requested-With'],'XMLHttpRequest');
  await new Promise(r=>resolve=r);if(mode==='network')throw new Error('offline');
  return {ok:mode!=='refused',json:async()=>{if(mode==='invalid-json')throw new SyntaxError('html');return {status:mode==='refused'?'error':'success',message:'Refused',form_html:mode==='missing-stage'?'bad':'<button name="verify-code">Reset</button>'};}};
 }});
 const event={preventDefault(){}};const pending=click(event);await click(event);assert.equal(requests,1);assert.equal(button.disabled,true);resolve();await pending;
 assert.equal(fields.pass.value,'never-send-password');assert.equal(fields.code.value,'keep-code');
 if(mode==='success'){assert(form.innerHTML.includes('name="verify-code"'));assert(focused);}else{assert.equal(form.innerHTML,'original');assert.equal(button.disabled,false);assert(status.textContent);}
}
console.log('Recovery inline UI: both editions advance only on confirmed success; inputs retained on refusal/network/HTML/missing-stage; no secrets or duplicate sends.');
