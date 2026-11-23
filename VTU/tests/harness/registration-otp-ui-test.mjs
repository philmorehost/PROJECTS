import fs from 'node:fs';import vm from 'node:vm';import assert from 'node:assert/strict';
for(const edition of ['SAAS','NON-SAAS'])for(const outcome of ['success','refused','invalid-json','network']){
 const element=()=>({value:'',textContent:'',style:{},disabled:false,addEventListener(){},setAttribute(){},after(){},focus(){}});
 const nodes=Object.fromEntries(['btn-create-account','otp-overlay','otp-error','otp-success','otp-countdown','otp-verifying','otp-resend-btn','otp-email-hint'].map(id=>[id,element()]));
 const digits=Array.from({length:6},()=>element());digits.forEach((d,i)=>d.value=String(i));
 const fields={_csrf_token:{value:'csrf'},email:{value:'fixture@example.test'},pass:{value:'keep-password'}};
 const form={elements:{namedItem(n){return fields[n];}},addEventListener(){}};
 let calls=0,resolve;const window={location:{search:'',href:'unchanged'}};
 class FormData{constructor(form){this.data=new Map(form?Object.entries(fields).map(([k,v])=>[k,v.value]):[]);}append(k,v){this.data.set(k,v);}}
 vm.runInNewContext(fs.readFileSync(`VTU/DGV7.0-${edition}/asset/bc-registration-otp.js`,'utf8'),{
  document:{querySelector(selector){return selector.startsWith('form')?form:fields.email;},querySelectorAll(){return digits;},getElementById(id){return nodes[id];},createElement:element},window,FormData,URLSearchParams,Date,Array,setInterval(){return 1;},clearInterval(){},setTimeout(){},Swal:{fire(){throw new Error('unexpected alert');}},fetch:async(url,opts)=>{
   calls++;assert.equal(url,'/web/api/send-reg-otp.php');assert.equal(opts.body.data.get('_csrf_token'),'csrf');assert.equal(opts.credentials,'same-origin');await new Promise(r=>resolve=r);
   if(outcome==='network')throw new Error('offline');return {json:async()=>{if(outcome==='invalid-json')throw new SyntaxError('html');return {status:outcome==='success'?'success':'error',message:'Refused'};}};
  }
 });
 window.sendOTP(true);window.sendOTP(true);assert.equal(calls,1);assert.equal(nodes['otp-resend-btn'].disabled,true);assert.equal(digits.map(d=>d.value).join(''),'012345');
 resolve();await new Promise(setImmediate);await new Promise(setImmediate);
 assert.equal(digits.map(d=>d.value).join(''),'012345');assert.equal(fields.pass.value,'keep-password');assert.equal(window.location.href,'unchanged');assert.equal(nodes['otp-resend-btn'].disabled,false);
 if(outcome==='success')assert.equal(nodes['otp-resend-btn'].textContent,'SENT');else assert.equal(nodes['otp-error'].style.display,'block');
}
console.log('Registration resend both editions: SENT inline, one in-flight send, code/password retained on success and all error paths, no navigation or alerts.');
