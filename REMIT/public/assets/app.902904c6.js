'use strict';
const search=document.querySelector('#ledger-search');
search?.addEventListener('input',()=>{const q=search.value.toLowerCase();document.querySelectorAll('#ledger tbody tr').forEach(row=>{row.hidden=!row.textContent.toLowerCase().includes(q);});});
document.querySelectorAll('[data-percent]').forEach(bar=>{bar.style.width=`${Math.max(0,Math.min(100,Number(bar.dataset.percent)))}%`;});
const form=document.querySelector('#income-form');
form?.addEventListener('input',()=>{
 const raw=document.querySelector('#gross').value;
 if(!/^\d{1,13}(?:\.\d{1,2})?$/.test(raw)) {document.querySelector('#levy-preview').textContent='Enter a valid amount';return;}
 const [whole,fraction='']=raw.split('.');const cents=BigInt(whole)*100n+BigInt(fraction.padEnd(2,'0'));
 const due=(cents*BigInt(form.dataset.rate)+5000n)/10000n;
 document.querySelector('#levy-preview').textContent=`₦${(due/100n).toLocaleString('en-NG')}.${(due%100n).toString().padStart(2,'0')}`;
});
