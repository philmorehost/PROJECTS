'use strict';
document.querySelectorAll('form[data-confirm]').forEach(form=>{
  form.addEventListener('submit',event=>{if(!confirm(form.dataset.confirm))event.preventDefault();});
});
if(new URLSearchParams(location.search).has('new'))document.querySelector('#question-editor,#account-editor')?.scrollIntoView({block:'start'});
