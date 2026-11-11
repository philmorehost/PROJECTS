'use strict';
const search=document.querySelector('#ledger-search');
const statusFilter=document.querySelector('#ledger-status');
function filterLedger(){
 const q=(search?.value||'').trim().toLowerCase();const status=statusFilter?.value||'';
 const rows=Array.from(document.querySelectorAll('#ledger tbody tr[data-status]'));let visible=0;
 rows.forEach(row=>{row.hidden=!(row.textContent.toLowerCase().includes(q)&&(!status||row.dataset.status===status));if(!row.hidden)visible++;});
 const result=document.querySelector('#ledger-result');
 if(result&&search)result.textContent=`${visible} of ${rows.length} loaded reports shown`;
 const empty=document.querySelector('#ledger-no-results');if(empty)empty.hidden=rows.length===0||visible>0;
}
search?.addEventListener('input',filterLedger);statusFilter?.addEventListener('change',filterLedger);filterLedger();
document.querySelectorAll('[data-percent]').forEach(bar=>{bar.style.width=`${Math.max(0,Math.min(100,Number(bar.dataset.percent)))}%`;});
function currency(cents){return `₦${(cents/100n).toLocaleString('en-NG')}.${(cents%100n).toString().padStart(2,'0')}`;}
const offeringForm=document.querySelector('#income-form');
const grossInput=document.querySelector('#gross');
const levyPreview=document.querySelector('#levy-preview');const retainedPreview=document.querySelector('#retained-preview');
function updateOfferingPreview(){
 if(!offeringForm||!grossInput)return;
 const raw=grossInput.value.trim().replace(/,/g,'');
 if(!raw||!/^\d{1,13}(?:\.\d{1,2})?$/.test(raw)){if(levyPreview){levyPreview.textContent='₦0.00';levyPreview.dataset.kobo='0';}if(retainedPreview){retainedPreview.textContent='₦0.00';retainedPreview.dataset.kobo='0';}return;}
 const [whole,fraction='']=raw.split('.');const cents=BigInt(whole)*100n+BigInt(fraction.padEnd(2,'0'));
 const rate=BigInt(offeringForm.dataset.rate||'5000');const due=(cents*rate+5000n)/10000n;
 if(levyPreview){levyPreview.textContent=currency(due);levyPreview.dataset.kobo=String(due);}
 if(retainedPreview){retainedPreview.textContent=currency(cents-due);retainedPreview.dataset.kobo=String(cents-due);}
}
['input','change','keyup','blur','focus'].forEach(ev=>grossInput?.addEventListener(ev,updateOfferingPreview));
document.addEventListener('DOMContentLoaded',updateOfferingPreview);updateOfferingPreview();
offeringForm?.addEventListener('submit',()=>{setTimeout(()=>{location.hash='#payment-options';},80);});
const retention=document.querySelector('#retained-percentage');
retention?.addEventListener('input',()=>{
 const raw=retention.value;const output=document.querySelector('#payable-percentage');
 if(!/^\d{1,3}(?:\.\d{1,2})?$/.test(raw)){output.textContent='—';return;}
 const [whole,fraction='']=raw.split('.');const bp=Number(whole)*100+Number(fraction.padEnd(2,'0'));
 output.textContent=bp<=10000?`${((10000-bp)/100).toFixed(2)}%`:'Enter 0–100%';
});
// Native modal dialog provides focus containment and Escape-to-close on mobile.
const menu=document.querySelector('#mobile-menu');
const openers=Array.from(document.querySelectorAll('[data-menu-open]'));
let returnFocus=null;
if(menu&&typeof menu.showModal==='function'){
 document.documentElement.classList.add('has-mobile-menu');
 const close=()=>{if(menu.open)menu.close();};
 openers.forEach(button=>button.addEventListener('click',()=>{
  if(menu.open)return;returnFocus=button;menu.showModal();
  openers.forEach(item=>item.setAttribute('aria-expanded','true'));
  document.documentElement.classList.add('menu-is-open');
  menu.querySelector('[data-menu-close]')?.focus();
 }));
 menu.querySelector('[data-menu-close]')?.addEventListener('click',close);
 menu.addEventListener('close',()=>{
  openers.forEach(item=>item.setAttribute('aria-expanded','false'));
  document.documentElement.classList.remove('menu-is-open');
  if(returnFocus&&returnFocus.getClientRects().length)returnFocus.focus();
 });
 menu.addEventListener('click',event=>{
  if(event.target!==menu)return;const box=menu.getBoundingClientRect();
  if(event.clientX<box.left||event.clientX>box.right||event.clientY<box.top||event.clientY>box.bottom)close();
 });
 window.matchMedia('(min-width: 901px)').addEventListener('change',event=>{if(event.matches)close();});
}

// Progressively enhance unit selects; native selects remain usable without JavaScript.
document.querySelectorAll('select[data-unit-search]').forEach((select,index)=>{
 const options=Array.from(select.options).filter(o=>o.value!=='').map(o=>({value:o.value,label:o.textContent.trim()}));
 const wrapper=document.createElement('div');wrapper.className='unit-autocomplete';
 const input=document.createElement('input');input.type='search';input.autocomplete='off';input.id=`unit-search-${index}`;
 input.placeholder='Type a church name, code, area or zone…';input.setAttribute('role','combobox');input.setAttribute('aria-autocomplete','list');input.setAttribute('aria-expanded','false');
 const label=select.closest('label');const labelText=label?Array.from(label.childNodes).filter(n=>n.nodeType===Node.TEXT_NODE).map(n=>n.textContent).join('').trim():'Church unit';
 input.setAttribute('aria-label',labelText);if(label)label.htmlFor=input.id;
 const list=document.createElement('div');list.id=`unit-results-${index}`;list.className='unit-search-results';list.setAttribute('role','listbox');list.setAttribute('aria-label',`${labelText} suggestions`);list.hidden=true;input.setAttribute('aria-controls',list.id);
 const status=document.createElement('span');status.className='unit-search-status';status.setAttribute('role','status');status.setAttribute('aria-live','polite');
 const required=select.required;select.required=false;input.required=required;
 const initial=options.find(o=>o.value===select.value);input.value=initial?.label||'';
 wrapper.append(input,list,status);select.insertAdjacentElement('afterend',wrapper);select.hidden=true;
 let matches=[],active=-1,selected=initial||null;
 const validity=()=>input.setCustomValidity(input.value.trim()&&!selected?'Select a matching church unit from the suggestions.':'');
 const close=()=>{list.hidden=true;input.setAttribute('aria-expanded','false');input.removeAttribute('aria-activedescendant');active=-1;};
 const choose=option=>{selected=option;select.value=option.value;input.value=option.label;validity();select.dispatchEvent(new Event('change',{bubbles:true}));status.textContent='Selected: '+option.label;close();};
 const highlight=()=>{Array.from(list.children).forEach((item,i)=>item.setAttribute('aria-selected',String(i===active)));if(active>=0){input.setAttribute('aria-activedescendant',list.children[active].id);list.children[active].scrollIntoView({block:'nearest'});}};
 const render=()=>{
  const query=(selected&&input.value===selected.label?'':input.value).trim().toLocaleLowerCase();
  const all=options.filter(o=>o.label.toLocaleLowerCase().includes(query));matches=all.slice(0,20);active=-1;list.replaceChildren();input.removeAttribute('aria-activedescendant');
  matches.forEach((option,i)=>{const item=document.createElement('div');item.id=`unit-option-${index}-${i}`;item.className='unit-search-option';item.setAttribute('role','option');item.setAttribute('aria-selected','false');item.textContent=option.label;item.addEventListener('pointerdown',event=>event.preventDefault());item.addEventListener('click',event=>{event.preventDefault();event.stopPropagation();choose(option);});list.append(item);});
  status.textContent=all.length?(all.length>20?`${all.length} matches. Showing 20; keep typing to narrow the list.`:`${all.length} matching unit${all.length===1?'':'s'}.`):'No matching churches. Try another name or code.';
  list.hidden=matches.length===0;input.setAttribute('aria-expanded',String(matches.length>0));
 };
 input.addEventListener('focus',render);input.addEventListener('click',()=>{if(list.hidden)render();});
 input.addEventListener('input',()=>{selected=null;select.value='';validity();render();});
 input.addEventListener('keydown',event=>{
  if(event.key==='Escape'){event.preventDefault();close();}
  if(event.key==='ArrowDown'||event.key==='ArrowUp'){event.preventDefault();if(list.hidden)render();if(!matches.length)return;active=event.key==='ArrowDown'?(active+1)%matches.length:(active<=0?matches.length-1:active-1);highlight();}
  if(event.key==='Enter'&&!list.hidden){event.preventDefault();if(active>=0)choose(matches[active]);else if(matches.length===1)choose(matches[0]);}
 });
 input.addEventListener('blur',()=>{close();validity();});
 select.form?.addEventListener('reset',()=>{setTimeout(()=>{selected=options.find(o=>o.value===select.value)||null;input.value=selected?.label||'';validity();close();},0);});
});
document.querySelectorAll('[data-unit-bulk]').forEach(form=>{
 const search=form.querySelector('[data-bulk-search]');const type=form.querySelector('[data-bulk-type]');const rows=Array.from(form.querySelectorAll('.unit-check'));const count=form.querySelector('[data-bulk-count]');
 const update=()=>{const selected=rows.filter(row=>row.querySelector('input').checked).length;const visible=rows.filter(row=>!row.hidden).length;count.textContent=`${selected} selected · ${visible} shown`;};
 const filter=()=>{const q=search.value.trim().toLocaleLowerCase();rows.forEach(row=>{row.hidden=!(row.textContent.toLocaleLowerCase().includes(q)&&(!type.value||row.dataset.unitType===type.value));});update();};
 search.addEventListener('input',filter);type.addEventListener('change',filter);rows.forEach(row=>row.querySelector('input').addEventListener('change',update));
 form.querySelector('[data-bulk-all]').addEventListener('click',()=>{let selected=rows.filter(row=>row.querySelector('input').checked).length;rows.filter(row=>!row.hidden).forEach(row=>{const box=row.querySelector('input');if(!box.checked&&selected<500){box.checked=true;selected++;}});update();});
 form.querySelector('[data-bulk-clear]').addEventListener('click',()=>{rows.forEach(row=>row.querySelector('input').checked=false);update();});
 form.addEventListener('submit',event=>{const selected=rows.filter(row=>row.querySelector('input').checked).length;if(!selected||selected>500){event.preventDefault();count.textContent='Select between 1 and 500 units before assigning.';}});update();
});

// The server renders one page and real GET links. Enhance with instant pagination
// over the same authorized records kept in an inert template (not visible cards).
const directoryForm=document.querySelector('#directory-filters');
const directoryTemplate=document.querySelector('#directory-all-units');
const directoryList=document.querySelector('#directory-list');
const directoryControls=document.querySelector('#directory-pagination-controls');
if(directoryForm&&directoryTemplate&&directoryList&&directoryControls){
 const records=Array.from(directoryTemplate.content.querySelectorAll('[data-directory-unit]')).map(node=>({node,text:node.dataset.directorySearch.toLocaleLowerCase(),type:node.dataset.type,zone:node.dataset.zone}));
 const search=directoryForm.querySelector('#directory-search');const type=directoryForm.querySelector('#directory-type');const zone=directoryForm.querySelector('#directory-zone');const size=directoryForm.querySelector('#directory-size');
 let current=Number(document.querySelector('.directory-pagination').dataset.directoryPage)||1;
 const pageUrl=(page,action=null,id=null)=>{
  try{const url=new URL(directoryForm.getAttribute('action'),location.href);
   url.search=new URLSearchParams(new FormData(directoryForm)).toString();url.searchParams.set('directory_page',String(page));
   if(action){url.searchParams.set(action==='edit'?'edit_unit':'delete_unit',id);url.hash=action==='edit'?'hierarchy-editor':'delete-unit';}
   return url;
  }catch{return null;}
 };
 const render=(requested=1,moveFocus=false,saveUrl=true)=>{
  const query=search.value.trim().toLocaleLowerCase();const perPage=[6,12,24].includes(Number(size.value))?Number(size.value):6;
  const matches=records.filter(record=>record.text.includes(query)&&(!type.value||record.type===type.value)&&(!zone.value||record.zone===zone.value));
  const pages=Math.max(1,Math.ceil(matches.length/perPage));current=Math.max(1,Math.min(pages,requested));
  const start=(current-1)*perPage;const visible=matches.slice(start,start+perPage);
  directoryList.replaceChildren(...visible.map(record=>{const node=record.node.cloneNode(true);node.querySelectorAll('[data-directory-action]').forEach(link=>{const url=pageUrl(current,link.dataset.directoryAction,node.dataset.unitId);if(url)link.href=url.href;});return node;}));
  document.querySelector('#directory-result').textContent=`Showing ${matches.length?start+1:0}–${Math.min(start+perPage,matches.length)} of ${matches.length} matching units · ${records.length} in your scope`;
  document.querySelector('#directory-empty').hidden=matches.length>0;
  document.querySelector('#directory-page-counter').textContent=`Page ${current} of ${pages}`;
  document.querySelector('.directory-pagination').dataset.directoryPage=String(current);
  directoryControls.replaceChildren();
  const control=(label,page,disabled=false,number=false)=>{
   const item=document.createElement(disabled?'span':'a');item.className='page-link'+(disabled?' is-disabled':'')+(number?' page-number':'');item.textContent=label;
   if(disabled)item.setAttribute('aria-disabled','true');
   else{item.dataset.directoryPage=String(page);const url=pageUrl(page);item.href=url?url.href:'#church-directory';if(number)item.setAttribute('aria-label',`Page ${page}`);if(number&&page===current)item.setAttribute('aria-current','page');}
   return item;
  };
  directoryControls.append(control('← Previous',current-1,current===1));
  const numbers=document.createElement('div');numbers.className='directory-page-numbers';let last=0;
  const candidates=Array.from(new Set([1,Math.max(1,current-1),current,Math.min(pages,current+1),pages])).sort((a,b)=>a-b);
  candidates.forEach(page=>{if(last&&page>last+1){const gap=document.createElement('span');gap.className='page-ellipsis';gap.textContent='…';gap.setAttribute('aria-hidden','true');numbers.append(gap);}numbers.append(control(String(page),page,false,true));last=page;});
  directoryControls.append(numbers,control('Next →',current+1,current===pages));
  if(saveUrl){const url=pageUrl(current);if(url&&url.origin===location.origin){try{history[moveFocus?'pushState':'replaceState'](null,'',url);}catch{ /* GET links still work if history updates are unavailable. */ }}}
  if(moveFocus){const heading=document.querySelector('#church-directory-title');heading.setAttribute('tabindex','-1');heading.focus({preventScroll:true});heading.scrollIntoView({block:'start'});}
 };
 directoryForm.addEventListener('submit',event=>{event.preventDefault();render(1);});
 search.addEventListener('input',()=>render(1));[type,zone,size].forEach(control=>control.addEventListener('change',()=>render(1)));
 directoryControls.addEventListener('click',event=>{const link=event.target.closest('a[data-directory-page]');if(!link||event.ctrlKey||event.metaKey||event.shiftKey||event.altKey)return;event.preventDefault();render(Number(link.dataset.directoryPage),true);});
 window.addEventListener('popstate',()=>{const params=new URLSearchParams(location.search);search.value=params.get('directory_q')||'';type.value=params.get('directory_type')||'';zone.value=params.get('directory_zone')||'';size.value=params.get('directory_size')||'6';render(Number(params.get('directory_page'))||1,false,false);});
 render(current,false,false);
}
function openHierarchyTool(){
 let id;try{id=decodeURIComponent(location.hash.slice(1));}catch{return;}
 const target=document.getElementById(id);if(!target)return;
 let detail=target.closest('details');while(detail){detail.open=true;detail=detail.parentElement?.closest('details');}
}
window.addEventListener('hashchange',openHierarchyTool);openHierarchyTool();

// Reopen a disclosure even when its shortcut's hash is already the current hash.
document.querySelectorAll('.hierarchy-shortcuts a[href^="#"]').forEach(link=>link.addEventListener('click',()=>{
 const target=document.getElementById(link.getAttribute('href').slice(1));
 if(target?.matches('details'))target.open=true;
}));

// Officer registration: suggest, never silently choose a fuzzy church match.
const registrationSelect=document.querySelector('#registration-church');
if(registrationSelect){
 const unlisted=document.querySelector('#church-unlisted'),fields=document.querySelector('#unlisted-church-fields'),name=document.querySelector('#new-church-name'),level=document.querySelector('#new-church-type');
 const normalize=text=>text.normalize('NFKD').replace(/[\u0300-\u036f]/g,'').toLowerCase().replace(/[^\p{L}\p{N}]+/gu,' ').trim();
 const distance=(a,b)=>{let row=Array.from({length:b.length+1},(_,i)=>i);for(let i=1;i<=a.length;i++){const next=[i];for(let j=1;j<=b.length;j++)next[j]=Math.min(next[j-1]+1,row[j]+1,row[j-1]+(a[i-1]===b[j-1]?0:1));row=next;}return row[b.length];};
 const options=Array.from(registrationSelect.options).filter(o=>o.value).map(o=>({id:o.value,label:o.textContent.trim(),name:normalize(o.dataset.churchName),search:normalize(o.textContent)}));
 const searchLabel=document.createElement('label');searchLabel.textContent='Search church names, codes or parents';
 const search=document.createElement('input');search.type='search';search.id='registration-church-search';search.maxLength=150;search.autocomplete='off';search.placeholder='Start typing your church name…';searchLabel.append(search);
 const suggestions=document.createElement('div');document.querySelector('#church-discovery').append(searchLabel,suggestions);
 const sync=()=>{fields.hidden=!unlisted.checked;fields.disabled=!unlisted.checked;name.required=unlisted.checked;level.required=unlisted.checked;registrationSelect.required=!unlisted.checked;};
 const choose=option=>{registrationSelect.value=option.id;unlisted.checked=false;sync();search.value=option.label;suggestions.replaceChildren();document.querySelector('#new-church-suggestions').replaceChildren();registrationSelect.focus();};
 const rank=(option,query)=>{
  if(!query)return 4;
  if(option.name.replace(/ /g,'')===query.replace(/ /g,''))return 0;
  if(option.search.includes(query))return 1;
  const words=query.split(' '),target=option.search.split(' ');
  if(words.every(word=>target.some(value=>value.startsWith(word)||(word.length>=3&&Math.abs(value.length-word.length)<=2&&distance(word,value)<=(word.length>=6?2:1)))))return 2;
  if(query.length>=4&&Math.abs(option.name.length-query.length)<=3&&distance(query,option.name)<=Math.max(1,Math.floor(query.length*.2)))return 3;
  return 99;
 };
 const render=(text,target)=>{
  const query=normalize(text).slice(0,150);target.replaceChildren();if(!query)return;
  const matches=options.map(option=>({option,score:rank(option,query)})).filter(match=>match.score<99).sort((a,b)=>a.score-b.score).slice(0,8);
  const status=document.createElement('p');status.className='small muted';status.setAttribute('role','status');status.textContent=matches.length?'Similar churches — select the correct name and parent:':'No close matches found. Try another spelling or code, or use the unlisted church option for admin review.';target.append(status);
  matches.forEach(({option})=>{const button=document.createElement('button');button.type='button';button.className='church-suggestion';button.textContent=option.label;button.addEventListener('click',()=>choose(option));target.append(button);});
 };
 search.addEventListener('input',()=>{registrationSelect.value='';render(search.value,suggestions);});
 name.addEventListener('input',()=>render(name.value,document.querySelector('#new-church-suggestions')));
 unlisted.addEventListener('change',()=>{if(unlisted.checked){registrationSelect.value='';if(!name.value)name.value=search.value.toUpperCase();render(name.value,document.querySelector('#new-church-suggestions'));}sync();});
 registrationSelect.addEventListener('change',()=>{if(registrationSelect.value){const option=options.find(o=>o.id===registrationSelect.value);if(option)choose(option);}});
 registrationSelect.form.addEventListener('reset',()=>setTimeout(sync,0));sync();
}

// Change the submitted value, not just its visual styling; keep the typing caret stable.
document.querySelectorAll('[data-church-caps]').forEach(field=>{
 const uppercase=()=>{const start=field.selectionStart,end=field.selectionEnd;const before=field.value;field.value=before.toUpperCase();if(start!==null)field.setSelectionRange(before.slice(0,start).toUpperCase().length,before.slice(0,end).toUpperCase().length);};
 field.addEventListener('input',uppercase);uppercase();
});

// Receipt files are served only by the authenticated endpoint; no public storage URLs.
const receiptDialog=document.querySelector('#receipt-dialog');
if(receiptDialog&&typeof receiptDialog.showModal==='function'){
 const image=receiptDialog.querySelector('[data-proof-image]'),error=receiptDialog.querySelector('[data-proof-error]');let opener=null;
 const close=()=>receiptDialog.close();
 document.addEventListener('click',event=>{const link=event.target.closest('[data-proof-open]');if(!link||event.ctrlKey||event.metaKey||event.shiftKey||event.altKey)return;const url=new URL(link.href,location.href);if(url.origin!==location.origin)return;event.preventDefault();opener=link;error.hidden=true;image.hidden=false;image.src=url.href;if(!receiptDialog.open)receiptDialog.showModal();receiptDialog.querySelector('[data-proof-close]').focus();});
 receiptDialog.querySelector('[data-proof-close]').addEventListener('click',close);
 image.addEventListener('error',()=>{image.hidden=true;error.hidden=false;});
 receiptDialog.addEventListener('click',event=>{if(event.target!==receiptDialog)return;const rect=receiptDialog.getBoundingClientRect();if(event.clientX<rect.left||event.clientX>rect.right||event.clientY<rect.top||event.clientY>rect.bottom)close();});
 receiptDialog.addEventListener('close',()=>{image.removeAttribute('src');if(opener?.isConnected)opener.focus();});
}
// Server-filtered financial history: real GET fallback plus debounced progressive refresh.
const historyRequests=new Map();
function historyRequestState(id){if(!historyRequests.has(id))historyRequests.set(id,{timer:null,controller:null,seq:0});return historyRequests.get(id);}
async function refreshFinancialHistory(panel,url){
 const id=panel.id,state=historyRequestState(id);clearTimeout(state.timer);state.controller?.abort();state.controller=new AbortController();const seq=++state.seq;
 const active=document.activeElement;const focus=panel.contains(active)&&active.name?{name:active.name,start:active.selectionStart,end:active.selectionEnd}:null;
 panel.setAttribute('aria-busy','true');
 try{
  const response=await fetch(url,{credentials:'same-origin',cache:'no-store',signal:state.controller.signal});if(!response.ok)throw new Error('History unavailable');
  const html=new DOMParser().parseFromString(await response.text(),'text/html');const replacement=html.getElementById(id);if(!replacement||!replacement.hasAttribute('data-server-history'))throw new Error('Sign in again');
  if(seq!==state.seq)return;replacement.open=true;panel.replaceWith(replacement);history.replaceState(null,'',url);
  if(focus){const field=replacement.querySelector(`[name="${CSS.escape(focus.name)}"]`);if(field){field.focus({preventScroll:true});if(field.type==='search'&&focus.start!==null)field.setSelectionRange(focus.start,focus.end);}}
 }catch(error){if(error.name==='AbortError')return;location.assign(url.href);}
 finally{panel.removeAttribute('aria-busy');}
}
function financialFilterUrl(form){
 const panel=form.closest('[data-server-history]'),prefix=panel.id==='reports-history'?'reports':'payments',url=new URL(location.href);
 for(const [key,value] of new FormData(form))if(key.startsWith(prefix+'_')){if(value)url.searchParams.set(key,value);else url.searchParams.delete(key);}
 url.searchParams.set('page','remittances');url.searchParams.delete(prefix+'_page');url.hash=panel.id;return url;
}
document.addEventListener('submit',event=>{const form=event.target.closest('[data-history-form]');if(!form)return;event.preventDefault();refreshFinancialHistory(form.closest('[data-server-history]'),financialFilterUrl(form));});
for(const type of ['input','change'])document.addEventListener(type,event=>{
 const form=event.target.closest('[data-history-form]');if(!form||event.target.type==='hidden')return;
 if(type==='input'&&event.target.tagName==='SELECT')return;if(type==='change'&&event.target.type==='search')return;
 const panel=form.closest('[data-server-history]'),state=historyRequestState(panel.id);state.controller?.abort();clearTimeout(state.timer);
 state.timer=setTimeout(()=>{if(form.reportValidity())refreshFinancialHistory(panel,financialFilterUrl(form));},type==='input'?400:0);
});
document.addEventListener('click',event=>{
 const link=event.target.closest('a[data-history-link]');if(!link||event.ctrlKey||event.metaKey||event.shiftKey||event.altKey)return;const panel=link.closest('[data-server-history]');if(!panel)return;event.preventDefault();
 const url=new URL(link.href,location.href),other=panel.id==='reports-history'?'payments':'reports',current=new URL(location.href);
 for(const key of Array.from(url.searchParams.keys()))if(key.startsWith(other+'_'))url.searchParams.delete(key);
 for(const [key,value] of current.searchParams)if(key.startsWith(other+'_'))url.searchParams.set(key,value);
 refreshFinancialHistory(panel,url);
});

// Per-account, per-installation onboarding preference; no financial data is stored.
document.querySelectorAll('[data-welcome-guide]').forEach(guide=>{
 const details=guide.querySelector('[data-guide-details]'),close=guide.querySelector('[data-guide-close]'),summary=details.querySelector('summary');
 const key=`remit-guide-v1:${location.pathname}:${guide.dataset.guideUser}`;
 try{if(localStorage.getItem(key)==='closed')details.open=false;}catch{/* Native disclosure remains usable when storage is unavailable. */}
 close.hidden=false;
 close.addEventListener('click',()=>{details.open=false;summary.focus();});
 details.addEventListener('toggle',()=>{try{localStorage.setItem(key,details.open?'open':'closed');}catch{/* Dismiss for this page even if persistence is blocked. */}});
});
// Deep links should reveal the native history disclosure without requiring another tap.
function revealFinancialHistory(){const id=location.hash.slice(1);if(!['payments-history','reports-history'].includes(id))return;const panel=document.getElementById(id);if(panel)panel.open=true;}
revealFinancialHistory();window.addEventListener('hashchange',revealFinancialHistory);

// Convenience only: the server independently rejects ALL writes while impersonating.
if(document.querySelector('[data-impersonation]'))document.querySelectorAll('form[method="post"]').forEach(form=>{
 const action=form.querySelector('[name="action"]')?.value;
 if(['impersonation_return','logout'].includes(action))return;
 form.querySelectorAll('input,select,textarea,button').forEach(control=>control.disabled=true);
 form.setAttribute('aria-label','Read-only while viewing as a representative');
});

// Opening a deep link must not leave the manual representative form collapsed.
function revealRepresentativeEditor(){if(location.hash==='#representative-editor'){const editor=document.querySelector('#representative-editor');const disclosure=editor?.closest('details');if(disclosure)disclosure.open=true;}}
revealRepresentativeEditor();window.addEventListener('hashchange',revealRepresentativeEditor);
document.querySelectorAll('.copy-button[data-copy-target]').forEach(button=>button.addEventListener('click',async()=>{const target=document.getElementById(button.dataset.copyTarget);if(!target)return;try{await navigator.clipboard.writeText(target.value);button.textContent='Copied';setTimeout(()=>button.textContent='Copy webhook',1600);}catch{target.select();document.execCommand('copy');button.textContent='Copied';setTimeout(()=>button.textContent='Copy webhook',1600);}}));

const guideModal=document.getElementById('quick-guide-modal');document.querySelectorAll('[data-guide-open]').forEach(btn=>btn.addEventListener('click',()=>{if(guideModal&&typeof guideModal.showModal==='function')guideModal.showModal();}));document.querySelectorAll('[data-guide-close]').forEach(btn=>btn.addEventListener('click',()=>{guideModal?.close();}));if(guideModal){guideModal.addEventListener('click',event=>{if(event.target===guideModal)guideModal.close();});}
const payhubForm=document.getElementById('payhub-form');

if(payhubForm){
 const ledger=payhubForm.querySelector('#payhub-ledger');const amount=payhubForm.querySelector('#payhub-amount');const errorBox=document.getElementById('payhub-error');
 const showError=msg=>{if(errorBox){errorBox.hidden=false;errorBox.textContent=msg;}else alert(msg);};
 const clearError=()=>{if(errorBox){errorBox.hidden=true;errorBox.textContent='';}};
 ledger?.addEventListener('change',()=>{const opt=ledger.selectedOptions[0];if(opt?.dataset.amount){const max=Number(opt.dataset.amount)/100;amount.max=max.toFixed(2);if(!amount.value||Number(amount.value)>max)amount.value=max.toFixed(2);}});
 payhubForm.addEventListener('submit',async event=>{
  event.preventDefault();clearError();
  const key=payhubForm.dataset.payhubKey;const email=payhubForm.dataset.payhubEmail;const name=payhubForm.dataset.payhubName;const raw=Number(amount.value);if(!raw||raw<100){showError('Enter an amount of at least 100.00 NGN.');return;}
  payhubForm.querySelector('.payhub-button')?.setAttribute('disabled','disabled');
  try{
   const form=new FormData(payhubForm);
   const res=await fetch(payhubForm.getAttribute('action')||location.pathname+location.search,{method:'POST',body:form,headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'},credentials:'same-origin',redirect:'manual'});
   const text=await res.text();let data=null;try{data=JSON.parse(text);}catch{}
   if(res.type==='opaqueredirect'){throw new Error('JavaScript session request was blocked or redirected. Refresh and try again.');}
   const text=await res.text();let data=null;try{data=JSON.parse(text);}catch{throw new Error('Unexpected server response. Refresh and retry PayHub.');}
   if(!res.ok||!data||!data.reference){throw new Error(data?.message||'Could not start PayHub checkout. Confirm your keys and network.');}
   const vaBox=document.getElementById('payhub-virtual-account');const vaBank=document.getElementById('payhub-virtual-bank');const vaNum=document.getElementById('payhub-virtual-number');const vaName=document.getElementById('payhub-virtual-name');const statusBox=document.getElementById('payhub-status');
   if(vaBox&&Array.isArray(data.virtual_accounts)&&data.virtual_accounts.length){const acct=data.virtual_accounts[0];vaBank.textContent=acct.bank_name||'PayHub Virtual Bank';vaNum.textContent=acct.account_number||'—';vaName.textContent=acct.account_name||'REMIT Remittance';vaBox.hidden=false;setStatus('Virtual account generated. Transfer the exact amount to approve automatically.');}else if(vaBox){vaBox.hidden=true;setStatus('Checkout opened. Complete payment in PayHub.');}
   const successRef=data.reference;const poll=async()=>{try{const u=new URL(location.href);u.searchParams.set('payhub_ref',successRef);const r=await fetch(u,{credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}});const t=await r.text();if(t.includes('payment confirmed')||t.includes('automatically approved')){location.href=u.toString();return;}}catch{}setTimeout(poll,8000);};
   if(typeof PayhubPop==='undefined'){window.location.href=data.checkout_url||('https://merchant.payhub.com.ng/checkout.php?ref='+encodeURIComponent(data.reference));poll();return;}
   const handler=PayhubPop.setup({
    key:data.public_key||key,email:data.email||email,amount:Number(data.amount_kobo)||Math.round(raw*100),ref:data.reference,currency:'NGN',
    onClose(){launch?.removeAttribute('disabled');poll();},
    callback:function(response){if(response&&response.reference){const url=new URL(location.href);url.searchParams.set('payhub_ref',response.reference);location.href=url.toString();}}
   });
   handler.openIframe();poll();
  }catch(e){showError(e.message||'PayHub checkout failed.');launch?.removeAttribute('disabled');setStatus('');}
 };
 if(launch)launch.addEventListener('click',startPayhub);
 payhubForm.addEventListener('submit',event=>{event.preventDefault();startPayhub(event);},true);
}
if(location.hash==='#payment-options'){setTimeout(()=>document.getElementById('payment-options')?.scrollIntoView({behavior:'smooth',block:'start'}),220);}const deleteForm=document.getElementById('delete-reports-form');
if(deleteForm){
 const checks=Array.from(deleteForm.querySelectorAll('.report-delete-check'));const all=deleteForm.querySelector('[data-select-all-reports]');const btn=deleteForm.querySelector('button[type=submit]');
 const update=()=>{const selected=checks.filter(c=>c.checked).length;if(all){all.checked=selected>0&&selected===checks.length;all.indeterminate=selected>0&&selected<checks.length;}btn.disabled=selected===0;btn.textContent=selected?`Delete ${selected} pending`+' →':'Delete selected pending →';};
 checks.forEach(c=>c.addEventListener('change',update));all?.addEventListener('change',()=>{checks.forEach(c=>{if(!c.disabled)c.checked=all.checked;});update();});
 deleteForm.addEventListener('submit',event=>{if(!deleteForm.dataset.confirmMessage)return;if(!confirm(deleteForm.dataset.confirmMessage))event.preventDefault();});
 update();
}
