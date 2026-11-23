/* Only email-code dispatch is enhanced. Password reset remains a CSRF-protected POST. */
(function(){
 'use strict';
 var button=document.querySelector('button[name="send-code"]');if(!button)return;
 var form=button.closest('form'),status=document.createElement('p');status.setAttribute('role','status');status.setAttribute('aria-live','polite');button.after(status);
 button.addEventListener('click',async function(event){
  event.preventDefault();if(button.disabled||!form.reportValidity())return;button.disabled=true;status.textContent='Sending…';
  var body=new FormData();['_csrf_token','user','email'].forEach(function(name){var field=form.elements.namedItem(name);if(field)body.set(name,field.value);});body.set('send-code','1');
  try{
   var response=await fetch(form.action||location.href,{method:'POST',body:body,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}});
   var data=await response.json();if(!response.ok||data.status!=='success')throw new Error(data.message||'Could not send recovery code.');
   if(typeof data.form_html!=='string'||!data.form_html.includes('name="verify-code"'))throw new Error('Code may have been sent. Refresh to continue recovery; do not repeatedly request codes.');
   form.innerHTML=data.form_html;var code=form.elements.namedItem('code');if(code)code.focus();
  }catch(error){status.textContent=error instanceof SyntaxError?'Session unavailable. Refresh before retrying.':(error.message||'Could not confirm dispatch. Refresh before retrying.');button.disabled=false;}
 });
}());
