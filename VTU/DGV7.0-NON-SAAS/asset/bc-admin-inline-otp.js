/* Send only CSRF, never the action, PIN, rejection reason or uploaded documents. */
(function () {
 'use strict';
 document.querySelectorAll('button[name="sec33_send_code"]').forEach(function(button){
  var form=button.closest('form');if(!form)return;
  var status=document.createElement('p');status.setAttribute('role','status');status.setAttribute('aria-live','polite');button.after(status);
  button.type='button';
  button.addEventListener('click',async function(event){
   event.preventDefault();if(button.disabled)return;button.disabled=true;status.textContent='Sending…';
   var body=new FormData(),csrf=form.elements.namedItem('_csrf_token');
   if(csrf)body.set('_csrf_token',csrf.value);body.set('sec33_send_code','1');
   try{
    var response=await fetch(form.action||location.href,{method:'POST',body:body,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}});
    var data=await response.json();if(!response.ok||data.status!=='success')throw new Error(data.message||'Could not send code.');
    button.textContent='SENT';status.textContent='OTP SENT';
    function selectEmail(target){
     var method=target.elements.namedItem('factor_method');
     if(!method){method=document.createElement('input');method.type='hidden';method.name='factor_method';target.appendChild(method);}method.value='email';
    }
    selectEmail(form);
    // Some review screens have one separate send form and many decision forms.
    document.querySelectorAll('input[name="sec_factor"],input[name="sec33_factor"]').forEach(function(field){if(field.form)selectEmail(field.form);});
   }catch(error){status.textContent=error instanceof SyntaxError?'Session unavailable. Sign in again if needed.':(error.message||'Could not send code.');}
   finally{button.disabled=false;}
  });
 });
}());
