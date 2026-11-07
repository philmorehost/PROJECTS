'use strict';
document.querySelectorAll('form[data-confirm]').forEach(form=>{
  form.addEventListener('submit',event=>{if(!confirm(form.dataset.confirm))event.preventDefault();});
});
if(new URLSearchParams(location.search).has('new'))document.querySelector('#question-editor,#account-editor')?.scrollIntoView({block:'start'});

// Copy-to-clipboard for read-only values such as the cron command.
document.querySelectorAll('[data-copy]').forEach(button=>{
  if(button.dataset.bound)return;
  button.dataset.bound='1';
  button.addEventListener('click',async()=>{
    const target=document.querySelector(button.dataset.copy);
    if(!target)return;
    const text=target.textContent.trim();
    let copied=false;
    try{if(navigator.clipboard&&window.isSecureContext){await navigator.clipboard.writeText(text);copied=true;}}catch{}
    if(!copied){
      const area=document.createElement('textarea');
      area.value=text;area.setAttribute('readonly','');area.style.position='fixed';area.style.top='-1000px';
      document.body.appendChild(area);area.select();
      try{copied=document.execCommand('copy');}catch{}
      area.remove();
    }
    const label=button.dataset.copyLabel||'Copy';
    button.textContent=copied?'Copied ✓':'Select and copy manually';
    if(copied){button.classList.add('copied');setTimeout(()=>{button.textContent=label;button.classList.remove('copied');},2400);}
  });
});
