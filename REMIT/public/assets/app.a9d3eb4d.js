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
const updateOfferingPreview=()=>{const g=document.getElementById('gross');if(!g)return;g.dispatchEvent(new Event('input'));g.dispatchEvent(new Event('change'));};
setTimeout(updateOfferingPreview,50);setTimeout(updateOfferingPreview,300);setTimeout(updateOfferingPreview,800);
const deleteForm=document.getElementById('delete-reports-form');
if(deleteForm){
 const checks=Array.from(deleteForm.querySelectorAll('.report-delete-check'));const all=deleteForm.querySelector('[data-select-all-reports]');const btn=deleteForm.querySelector('button[type=submit]');
 const update=()=>{const selected=checks.filter(c=>c.checked).length;if(all){all.checked=selected>0&&selected===checks.length;all.indeterminate=selected>0&&selected<checks.length;}btn.disabled=selected===0;btn.textContent=selected?`Delete ${selected} pending`+' →':'Delete selected pending →';};
 checks.forEach(c=>c.addEventListener('change',update));all?.addEventListener('change',()=>{checks.forEach(c=>{if(!c.disabled)c.checked=all.checked;});update();});
 deleteForm.addEventListener('submit',event=>{if(!deleteForm.dataset.confirmMessage)return;if(!confirm(deleteForm.dataset.confirmMessage))event.preventDefault();});
 update();
}
