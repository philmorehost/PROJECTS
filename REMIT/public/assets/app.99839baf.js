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
const form=document.querySelector('#income-form');
form?.addEventListener('input',()=>{
 const raw=document.querySelector('#gross').value;
 if(!/^\d{1,13}(?:\.\d{1,2})?$/.test(raw)){document.querySelector('#levy-preview').textContent='—';document.querySelector('#retained-preview').textContent='—';return;}
 const [whole,fraction='']=raw.split('.');const cents=BigInt(whole)*100n+BigInt(fraction.padEnd(2,'0'));
 const due=(cents*BigInt(form.dataset.rate)+5000n)/10000n;
 document.querySelector('#levy-preview').textContent=currency(due);
 document.querySelector('#retained-preview').textContent=currency(cents-due);
});
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
 name.addEventListener('input',()=>{const start=name.selectionStart,end=name.selectionEnd;name.value=name.value.toLocaleUpperCase();name.setSelectionRange(start,end);render(name.value,document.querySelector('#new-church-suggestions'));});
 unlisted.addEventListener('change',()=>{if(unlisted.checked){registrationSelect.value='';if(!name.value)name.value=search.value.toLocaleUpperCase();render(name.value,document.querySelector('#new-church-suggestions'));}sync();});
 registrationSelect.addEventListener('change',()=>{if(registrationSelect.value){const option=options.find(o=>o.id===registrationSelect.value);if(option)choose(option);}});
 registrationSelect.form.addEventListener('reset',()=>setTimeout(sync,0));sync();
}
