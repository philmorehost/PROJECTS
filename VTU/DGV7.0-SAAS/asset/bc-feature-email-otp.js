/* Feature dispatch only. Financial submit/verification behavior is not intercepted. */
(function(){
 'use strict';
 document.querySelectorAll('[data-feature-otp]').forEach(function(button){
  var status=document.createElement('p');status.setAttribute('role','status');status.setAttribute('aria-live','polite');button.parentElement.after(status);
  button.addEventListener('click',async function(event){
   event.preventDefault();if(button.disabled)return;button.disabled=true;status.textContent='Sending…';
   var body=new FormData();body.set('_csrf_token',button.dataset.csrf);
   if(button.dataset.featureOtp==='withdrawal')body.set('send_otp','1');else body.set('action','send_otp');
   try{
    var response=await fetch(button.dataset.otpEndpoint,{method:'POST',body:body,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}});
    var data=await response.json();if(!response.ok||data.status!=='success')throw new Error(data.message||'Could not send code.');
    button.textContent='SENT';status.textContent='OTP SENT. Please wait a minute before requesting another code.';
   }catch(error){button.textContent='Retry code';status.textContent=error instanceof SyntaxError?'Session or service unavailable. Refresh before retrying.':(error.message||'Could not send code.');}
   finally{button.disabled=false;}
  });
 });
}());
