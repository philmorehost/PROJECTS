/* Sign-in resend only: no PIN/password/code or authentication-changing fields. */
(function(){
 'use strict';
 var button=document.querySelector('button[name="resend-login-otp"]');if(!button)return;
 var form=button.closest('form'),status=document.createElement('p');status.setAttribute('role','status');status.setAttribute('aria-live','polite');button.after(status);button.type='button';
 button.addEventListener('click',async function(event){
  event.preventDefault();if(button.disabled)return;button.disabled=true;status.textContent='Sending…';
  var body=new FormData(),csrf=form.elements.namedItem('_csrf_token');if(csrf)body.set('_csrf_token',csrf.value);body.set('resend-login-otp','1');
  try{
   var response=await fetch(form.action||location.href,{method:'POST',body:body,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}});
   var data=await response.json();if(!response.ok||data.status!=='success')throw new Error(data.message||'Could not send code.');
   status.textContent='OTP SENT';button.textContent='SENT';
  }catch(error){status.textContent=error instanceof SyntaxError?'Session unavailable. Sign in again if needed.':(error.message||'Could not send code.');}
  finally{button.disabled=false;}
 });
}());
