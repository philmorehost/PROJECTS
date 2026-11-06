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
const parishSearch=document.querySelector('#parish-search');
const parishSelect=document.querySelector('#parish-select');
const parishOptions=parishSelect?Array.from(parishSelect.options).map(o=>({value:o.value,label:o.textContent})):[];
parishSearch?.addEventListener('input',()=>{
 const selected=parishSelect.value;const q=parishSearch.value.trim().toLowerCase();
 parishSelect.replaceChildren(...parishOptions.filter(o=>!o.value || o.value===selected || o.label.toLowerCase().includes(q)).map(o=>{const option=new Option(o.label,o.value);option.selected=o.value===selected;return option;}));
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
