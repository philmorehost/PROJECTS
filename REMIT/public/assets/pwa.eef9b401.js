'use strict';
(()=>{
 if(!window.isSecureContext||!('serviceWorker' in navigator))return;
 const base=new URL('../../',document.currentScript.src);
 const key=name=>'remit-pwa:'+base.pathname+':'+name;
 const read=name=>{try{return localStorage.getItem(key(name));}catch{return null;}};
 const save=(name,value)=>{try{localStorage.setItem(key(name),value);}catch{/* Private mode still works for this page. */}};
 const standalone=()=>matchMedia('(display-mode: standalone)').matches||navigator.standalone===true;
 let installed=standalone()||read('installed')==='1',prompt=null,banner=null;
 const hide=()=>{banner?.remove();banner=null;};
 if(standalone())save('installed','1');
 navigator.serviceWorker.register(new URL('sw.js',base),{scope:base.pathname,updateViaCache:'none'}).catch(()=>{/* Online banking remains usable if installation is unavailable. */});
 const show=()=>{
  if(installed||standalone()||banner||Number(read('dismissedUntil'))>Date.now())return;
  banner=document.createElement('section');banner.className='pwa-install';banner.setAttribute('aria-label','Install REMIT');
  const text=document.createElement('strong');text.textContent='Keep REMIT on your home screen';
  const install=document.createElement('button');install.type='button';install.className='primary';install.textContent='Install app';
  const dismiss=document.createElement('button');dismiss.type='button';dismiss.className='pwa-dismiss';dismiss.textContent='×';dismiss.setAttribute('aria-label','Dismiss install prompt');
  const status=document.createElement('p');status.className='pwa-status';status.setAttribute('role','status');status.hidden=true;
  dismiss.addEventListener('click',()=>{save('dismissedUntil',String(Date.now()+30*86400000));hide();});
  install.addEventListener('click',async()=>{
   if(prompt){const current=prompt;prompt=null;install.disabled=true;
    try{await current.prompt();const result=await current.userChoice;if(result.outcome==='accepted'){installed=true;save('installed','1');hide();}else{save('dismissedUntil',String(Date.now()+30*86400000));hide();}}
    catch{status.hidden=false;status.textContent='Use your browser menu to install REMIT or add it to your home screen.';}
    finally{install.disabled=false;}
   }else{
    status.hidden=false;status.textContent=/iPad|iPhone|iPod/.test(navigator.userAgent)||(navigator.platform==='MacIntel'&&navigator.maxTouchPoints>1)?'In Safari, tap Share → Add to Home Screen → Add.':'Open your browser menu and choose Install app or Add to Home screen. If unavailable, use an installation-capable browser.';
   }
  });
  banner.append(text,install,dismiss,status);(document.querySelector('main')||document.body).prepend(banner);
 };
 window.addEventListener('beforeinstallprompt',event=>{if(standalone())return;event.preventDefault();prompt=event;installed=false;save('installed','0');show();});
 window.addEventListener('appinstalled',()=>{installed=true;prompt=null;save('installed','1');hide();});
 matchMedia('(display-mode: standalone)').addEventListener?.('change',()=>{if(standalone()){installed=true;save('installed','1');hide();}});
 window.addEventListener('storage',event=>{if(event.key===key('installed')&&event.newValue==='1'){installed=true;hide();}if(event.key===key('dismissedUntil'))hide();});
 // Installation state is browser/profile-specific. Never force redirects or claim to launch an app.
 // A new beforeinstallprompt event clears a stale installed hint after uninstalling.
 // Native Chromium prompts appear only when the browser reports eligibility.
 // Safari has no beforeinstallprompt; offer its manual Add to Home Screen instructions.
 if(/iPad|iPhone|iPod/.test(navigator.userAgent)||(navigator.platform==='MacIntel'&&navigator.maxTouchPoints>1)||(/Safari/.test(navigator.userAgent)&&!/Chrome|Chromium|CriOS|Edg|Android/.test(navigator.userAgent)))show();
})();
