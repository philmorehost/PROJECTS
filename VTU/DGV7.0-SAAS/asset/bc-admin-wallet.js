(function(){
if(window.bcAdminWalletLoaded)return;window.bcAdminWalletLoaded=true;
/* Inline privileged actions: OTP requests carry only CSRF and purpose. */
document.addEventListener('click', async function(event) {
 const button=event.target.closest('[data-security-send]');if(!button||button.disabled)return;
 const form=button.closest('form'),status=form.querySelector('[data-wallet-status]');
 const data=new FormData();data.set('_csrf_token',form.elements._csrf_token.value);data.set(button.dataset.securitySend,'1');
 button.disabled=true;const label=button.textContent;status.textContent='Sending…';
 try{
  const response=await fetch(form.action||location.href,{method:'POST',body:data,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}});
  const json=await response.json();status.textContent=json.message||'Could not send code.';
  button.textContent=json.status==='success'?'SENT':label;
  if(json.status==='success' && form.elements.factor_method)form.elements.factor_method.value='email';
 }catch(error){button.textContent=label;status.textContent='Could not send code. Try again.';}finally{button.disabled=false;}
});
document.addEventListener('submit',async function(event){
 const form=event.target;if(!form.matches('[data-admin-wallet],[data-channel-policy]'))return;
 event.preventDefault();if(form.dataset.busy==='1')return;
 const button=event.submitter;if(!button)return;
 const status=form.querySelector('[data-wallet-status]'),data=new FormData(form);data.set(button.name,button.value||'1');
 form.dataset.busy='1';button.disabled=true;status.textContent='Verifying and saving…';
 try{
  const response=await fetch(form.action||location.href,{method:'POST',body:data,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}});
  const json=await response.json();status.textContent=json.message||'Could not confirm result. Check history before retrying.';
  if(json.status==='success'){
   for(const input of form.querySelectorAll('input[type=password],input[name=otp_code]'))input.value='';
   button.dataset.complete='1';button.textContent='Saved — refresh before another action';
  }
 }catch(error){status.textContent='Result uncertain. Check transaction history before retrying; do not start a new request blindly.';}
 finally{form.dataset.busy='0';button.disabled=button.dataset.complete==='1';}
});

})();
