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

const directorySearch=document.querySelector('#directory-search');
const directoryType=document.querySelector('#directory-type');
const directoryZone=document.querySelector('#directory-zone');
function filterDirectory(){
 const rows=Array.from(document.querySelectorAll('[data-directory-unit]'));let shown=0;
 const query=(directorySearch?.value||'').trim().toLocaleLowerCase();
 rows.forEach(row=>{row.hidden=!(row.textContent.toLocaleLowerCase().includes(query)&&(!directoryType?.value||row.dataset.type===directoryType.value)&&(!directoryZone?.value||row.dataset.zone===directoryZone.value));if(!row.hidden)shown++;});
 const result=document.querySelector('#directory-result');if(result)result.textContent=`${shown} of ${rows.length} church units shown`;
 const empty=document.querySelector('#directory-empty');if(empty)empty.hidden=shown>0;
}
[directorySearch,directoryType,directoryZone].forEach(control=>control?.addEventListener(control===directorySearch?'input':'change',filterDirectory));filterDirectory();
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
