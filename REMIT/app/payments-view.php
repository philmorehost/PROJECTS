<?php declare(strict_types=1);
if(!isset($payhubReady))$payhubReady=PayHub::enabled($db);
$payhubKey=trim(setting($db,'payhub_public_key'));
$canRemitNow=canRemit($user);
$bankReady=trim(setting($db,'bank_name'))!=='';
$ownRows=[];$awaiting=false;$latestLedger=null;$latestPendingKobo=0;
$flash=is_string($flash??null)?$flash:'';$error=is_string($error??null)?$error:'';
if($canRemitNow){
 $s=$db->prepare("SELECT * FROM remittance_ledgers WHERE parish_id=? AND status IN ('pending','partial') ORDER BY period_year DESC,period_month DESC,id DESC");
 $s->execute([(int)$user['hierarchy_id']]);$ownRows=$s->fetchAll();
 foreach($ownRows as &$r){$due=Money::cents($r['provincial_levy_due'])-Money::cents($r['amount_paid']);$r['available_kobo']=max(0,$due);$r['available_amount']=Money::decimal(max(0,$due));}unset($r);
 $ps=$db->prepare("SELECT COUNT(*) FROM payment_notifications n JOIN remittance_ledgers l ON l.id=n.ledger_id WHERE l.parish_id=? AND n.status='pending'");$ps->execute([(int)$user['hierarchy_id']]);$awaiting=(int)$ps->fetchColumn()>0;
 if(!$awaiting){$ls=$db->prepare("SELECT * FROM remittance_ledgers WHERE parish_id=? AND status IN ('pending','partial') ORDER BY id DESC LIMIT 1");$ls->execute([(int)$user['hierarchy_id']]);$latestLedger=$ls->fetch()?:null;}
 foreach($ownRows as $r){if($latestLedger && (int)$r['id']===(int)$latestLedger['id']){$latestPendingKobo=(int)$r['available_kobo'];break;}}
}
$payTab=($_GET['pay']??($payhubReady?'payhub':'bank'));if(!in_array($payTab,['bank','payhub'],true))$payTab=$payhubReady?'payhub':'bank';
if(!$canRemitNow){ ?><div class="notice">Approved parishes and representatives can start remittances here.</div><?php return; }
$defaultVirtual=null;
if($payhubReady){try{$all=PayHub::virtualAccounts($db);$defaultVirtual=$all[0]??null;}catch(Throwable $e){PayHub::log($db,'virtual_accounts_list_error',['error'=>$e->getMessage(),'user'=>$user['email']??'']);}}
?><div class="payment-layout" id="payment-options"><section class="panel form-panel payment-method-panel"><div class="section-kicker">STEP 2 · PAY WITH PAYHUB VIRTUAL ACCOUNT OR RECORD TRANSFER</div><h2>Complete payment</h2><p class="muted small">PayHub generates a unique payment reference and amount. Transfer the exact amount to the PayHub virtual bank account shown below. REMIT confirms automatically when PayHub marks the payment successful.</p>
<?php if($flash && ($_GET['pay']??'')==='payhub'): ?><div class="alert success"><?= e((string)$flash) ?></div><?php elseif($error): ?><div class="alert"><?= e((string)$error) ?></div><?php endif; ?>
<div role="tablist" aria-label="Payment methods" class="payment-tabs"><a role="tab" aria-selected="<?= $payTab==='bank'?'true':'false' ?>" class="payment-tab <?= $payTab==='bank'?'active':'' ?>" href="?page=remittances&pay=bank#payment-options"><span class="method-icon">⇄</span><strong>Bank Transfer</strong><small>Manual approval</small></a><a role="tab" aria-selected="<?= $payTab==='payhub'?'true':'false' ?>" class="payment-tab <?= $payTab==='payhub'?'active':'' ?>" href="?page=remittances&pay=payhub#payment-options"><span class="method-icon">₦</span><strong>PayHub Checkout & Virtual Account</strong><small><?= $payhubReady?'Instant virtual account approval':'Configure in settings' ?></small></a></div>
<?php if($awaiting): ?><div class="empty"><h3>Awaiting treasury confirmation</h3><p>Your pending payment is being reviewed. You do not need to submit another payment for the same offering until it is approved or rejected.</p></div><?php else: ?>
<?php if($payTab==='payhub'): ?><div class="payhub-virtual-box" id="payhub-virtual-box"><h3 class="payhub-title">PayHub Checkout & Virtual Account</h3>
<div class="quick-pay-grid"><?php if($payhubReady): ?><button type="button" class="primary payhub-button" id="payhub-quick-pay" <?= !$ownRows?'disabled':'' ?>>Generate PayHub payment reference</button><?php endif; ?><a class="secondary" href="?page=remittances&pay=bank#payment-options">Record manual bank transfer</a></div>
<form method="post" class="form-grid payhub-form-card" id="payhub-form" novalidate><?php hiddenAction('payhub_session') ?><label class="full-width">Pending offering report<select name="ledger_id" id="payhub-ledger" required><?php if(!$ownRows): ?><option value="">No pending reports</option><?php else: ?><option value="">Select an outstanding report</option><?php foreach($ownRows as $r):?><option value="<?= (int)$r['id'] ?>" data-amount="<?= (int)$r['available_kobo'] ?>" data-ref="<?= e($r['payment_reference']) ?>" <?= $latestLedger && (int)$latestLedger['id']===(int)$r['id']?'selected':'' ?>><?= e($r['period_month'].'/'.$r['period_year'].' · '.$r['payment_reference'].' · Due '.Money::display($r['available_amount'])) ?></option><?php endforeach; endif; ?></select></label><label>Amount to pay (NGN)<input name="amount" id="payhub-amount" inputmode="decimal" placeholder="Full amount due" autocomplete="off" value="<?= $latestPendingKobo?Money::decimal($latestPendingKobo):'' ?>"></label><div id="payhub-summary" class="payhub-summary"><span>Outstanding:</span><strong id="payhub-due-display">₦0.00</strong><span id="payhub-ref-display" class="muted small">Reference: —</span></div>
<div id="payhub-virtual-account" class="virtual-account-card active-virtual"><span>PAYHUB VIRTUAL BANK ACCOUNT</span><h3 id="payhub-virtual-bank"><?= e($defaultVirtual['bank_name']??'PayHub Virtual Bank') ?></h3><strong id="payhub-virtual-number"><?= e($defaultVirtual['account_number']??'—') ?></strong><p id="payhub-virtual-name"><?= e($defaultVirtual['account_name']??'REMIT Remittance') ?></p><small class="muted" id="payhub-virtual-note">Generate a payment reference first, then transfer only the exact amount shown for that reference.</small></div><div id="payhub-session-details" class="payhub-session-details" hidden><div class="session-row"><span>PayHub reference</span><strong id="payhub-session-ref">—</strong></div><div class="session-row"><span>Exact amount to transfer</span><strong id="payhub-session-amount">₦0.00</strong></div><a class="primary payhub-button hosted-link" id="payhub-hosted-link" href="#" target="_blank" rel="noopener">Open PayHub payment page →</a><p class="small muted" id="payhub-session-note">Use this reference for the transfer. REMIT will check PayHub automatically. You can keep this page open while completing the bank transfer.</p></div><p class="small muted payhub-error" id="payhub-error" hidden></p><p class="small muted" id="payhub-status" hidden></p><noscript><div class="notice">Enable JavaScript to generate a PayHub payment reference, or use manual bank transfer.</div></noscript></form></div><?php else: ?>
<div class="bank-transfer-card"><?php if($bankReady && $ownRows): ?><form method="post" class="form-grid" enctype="multipart/form-data"><?php hiddenAction('payment_submit') ?><label class="full-width">Outstanding report<select name="ledger_id" required><option value="">Select an outstanding report</option><?php foreach($ownRows as $r):if(Money::cents($r['provincial_levy_due'])<=Money::cents($r['amount_paid']))continue; ?><option value="<?= (int)$r['id'] ?>" <?= $latestLedger && (int)$latestLedger['id']===(int)$r['id']?'selected':'' ?>><?= e($r['period_month'].'/'.$r['period_year'].' · '.$r['payment_reference'].' · Due '.Money::display($r['available_amount'])) ?></option><?php endforeach ?></select></label><label>Amount transferred (NGN)<input name="amount" required inputmode="decimal" pattern="[0-9]{1,13}(\.[0-9]{1,2})?"></label><label>Transfer date<input name="transfer_date" type="date" min="2020-01-01" max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>" required></label><label class="full-width">Bank transaction reference<input name="transfer_reference" maxlength="100" required></label><?php if(PaymentProof::ready($db) && PaymentProof::supported()): ?><label class="full-width">Payment receipt (optional)<input type="file" name="payment_proof" accept="image/jpeg,image/png,image/webp"><small>JPEG, PNG or WebP · up to 8 MB.</small></label><?php endif ?><button class="primary">Submit manual payment →</button></form><?php else: ?><div class="empty"><p>No unpaid reports or bank transfer settings incomplete.</p></div><?php endif; ?></div><?php endif; ?><?php endif; ?>
</section></div>
<script>
document.addEventListener('DOMContentLoaded',function(){
window.REMITPayHub={ready:<?= $payhubReady?'true':'false' ?>,base:'https://merchant.payhub.com.ng',defaultVirtual:<?= json_encode($defaultVirtual) ?>};
function money(k){if(typeof k!=='bigint')k=BigInt(k||0);const n=k/100n,c=k%100n;return '₦'+n.toLocaleString('en-NG')+'.'+c.toString().padStart(2,'0');}
function koboFromDecimal(raw){raw=(raw||'').toString().trim().replace(/,/g,'');if(!raw||!/^\d{1,13}(?:\.\d{1,2})?$/.test(raw))return 0n;const [w,f='']=raw.split('.');return BigInt(w)*100n+BigInt(f.padEnd(2,'0'));}
async function payhubLog(event,payload){try{await fetch('index.php?payhub_log=1',{method:'POST',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},credentials:'same-origin',body:JSON.stringify({event,payload,url:location.href}),keepalive:true});}catch(e){}}
function updateOffering(){
 const gross=document.getElementById('gross'),due=document.getElementById('levy-preview'),ret=document.getElementById('retained-preview'),form=document.getElementById('income-form');
 if(!gross||!due||!ret)return;const c=koboFromDecimal(gross.value);const rate=BigInt(form?.dataset.rate||5000);
 const levy=(c*rate+5000n)/10000n;due.textContent=money(levy);ret.textContent=money(c-levy);
}
['input','change','keyup','blur'].forEach(ev=>document.addEventListener(ev,e=>{if(e.target&&e.target.id==='gross')updateOffering();}));
setTimeout(updateOffering,30);setTimeout(updateOffering,200);setTimeout(updateOffering,600);window.addEventListener('load',updateOffering);
const offerForm=document.getElementById('income-form');if(offerForm){offerForm.addEventListener('submit',()=>{setTimeout(()=>{location.hash='#payment-options';const b=document.getElementById('payhub-quick-pay');if(b&&!b.disabled)b.click();},400);});}
function payHubDue(sel){const opt=sel?.selectedOptions?.[0];return opt?BigInt(opt.dataset.amount||0):0n;}
function syncPayhub(){const sel=document.getElementById('payhub-ledger'),amt=document.getElementById('payhub-amount'),show=document.getElementById('payhub-due-display'),ref=document.getElementById('payhub-ref-display');if(!sel)return;const opt=sel.selectedOptions?.[0];const due=payHubDue(sel);if(show)show.textContent=money(due);if(ref)ref.textContent='Reference: '+(opt?.dataset.ref||'—');if(amt&&(!amt.value||amt.dataset.auto==='1')){amt.value=due?(Number(due)/100).toFixed(2):'';amt.dataset.auto='1';}}
document.addEventListener('change',e=>{if(e.target&&e.target.id==='payhub-ledger'){const amt=document.getElementById('payhub-amount');if(amt)amt.dataset.auto='1';syncPayhub();hideSession();}});
document.addEventListener('input',e=>{if(e.target&&e.target.id==='payhub-amount'){e.target.dataset.auto='0';hideSession();}});
syncPayhub();setTimeout(syncPayhub,200);
function setVirtualAccount(acct){const box=document.getElementById('payhub-virtual-account'),bank=document.getElementById('payhub-virtual-bank'),num=document.getElementById('payhub-virtual-number'),name=document.getElementById('payhub-virtual-name'),note=document.getElementById('payhub-virtual-note');const use=acct||window.REMITPayHub.defaultVirtual;if(use&&bank&&num&&name&&box){bank.textContent=use.bank_name||'PayHub Virtual Bank';num.textContent=use.account_number||'—';name.textContent=use.account_name||'REMIT Remittance';if(use.reference||acct)note.textContent='Transfer the exact session amount to this virtual account using the generated PayHub reference.';else note.textContent='Generate a payment reference first, then transfer only the exact amount shown.';box.classList.add('active-virtual');}}
function showSession(data){const details=document.getElementById('payhub-session-details'),ref=document.getElementById('payhub-session-ref'),amt=document.getElementById('payhub-session-amount'),link=document.getElementById('payhub-hosted-link'),status=document.getElementById('payhub-status');if(!details)return;ref.textContent=data.reference||'—';amt.textContent=data.amount?('₦'+Number(data.amount).toLocaleString('en-NG',{minimumFractionDigits:2,maximumFractionDigits:2})):'₦0.00';const checkoutRef=data.payhub_reference||data.reference;if(link&&checkoutRef){link.href=window.REMITPayHub.base+'/checkout.php?ref='+encodeURIComponent(checkoutRef);link.hidden=false;}details.hidden=false;if(status){status.hidden=false;status.textContent='Payment reference created. Transfer the exact amount to the PayHub virtual account, or open the PayHub page to pay by card/transfer.';}setVirtualAccount((data.virtual_accounts&&data.virtual_accounts[0])||window.REMITPayHub.defaultVirtual);payhubLog('session_displayed',data);}
function hideSession(){const details=document.getElementById('payhub-session-details');if(details)details.hidden=true;setVirtualAccount(window.REMITPayHub.defaultVirtual);}
async function startPayhub(event){
 if(event){event.preventDefault();event.stopPropagation?.();}
 const form=document.getElementById('payhub-form'),ledger=document.getElementById('payhub-ledger'),amount=document.getElementById('payhub-amount'),err=document.getElementById('payhub-error'),status=document.getElementById('payhub-status'),btns=document.querySelectorAll('.payhub-button');
 const showError=msg=>{if(err){err.hidden=false;err.textContent=msg;}else alert(msg);status && (status.hidden=true);payhubLog('payhub_error',{message:msg});};
 const setStatus=msg=>{if(status){status.hidden=false;status.textContent=msg;}};const clearErr=()=>{if(err){err.hidden=true;err.textContent='';}};
 if(!window.REMITPayHub.ready){showError('PayHub is not enabled.');return;}if(!ledger?.value){showError('Select a pending report.');return;}
 let raw=(amount?.value||'').trim().replace(/,/g,'');const due=payHubDue(ledger);if(!raw){raw=due?(Number(due)/100).toFixed(2):'';}const kobo=koboFromDecimal(raw);if(!kobo||kobo<10000n){showError('Enter at least ₦100.00.');return;}if(due&&kobo>due){showError('Amount cannot exceed outstanding balance.');return;}
 btns.forEach(b=>b.setAttribute('disabled','disabled'));clearErr();setStatus('Creating PayHub payment reference...');payhubLog('payhub_start',{ledger:ledger.value,kobo:String(kobo)});
 try{
  const fd=new FormData(form);fd.set('amount',(Number(kobo)/100).toFixed(2));
  const res=await fetch(form.getAttribute('action')||(location.pathname+location.search),{method:'POST',body:fd,headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'},credentials:'same-origin'});
  const text=await res.text();let data=null;try{data=JSON.parse(text);}catch{throw new Error('Unexpected PayHub response.');}
  if(!res.ok||!data?.reference){throw new Error(data?.message||'Could not create PayHub session.');}
  btns.forEach(b=>b.removeAttribute('disabled'));
  showSession(data);
  const ref=data.payhub_reference||data.reference;
  const poll=async()=>{try{const u=new URL(location.href);u.searchParams.set('payhub_ref',ref);u.hash='payment-options';u.searchParams.set('pay','payhub');const r=await fetch(u.toString(),{credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}});const t=await r.text();if(t.includes('payment confirmed')||t.includes('automatically approved')){payhubLog('payhub_confirmed_poll',{ref});location.href=u.toString();return;}}catch(e){payhubLog('payhub_poll_error',{ref,error:e.message});}setTimeout(poll,10000);};
  poll();
 }catch(e){showError(e.message||'PayHub failed.');btns.forEach(b=>b.removeAttribute('disabled'));payhubLog('payhub_exception',{message:e.message});}
}
document.addEventListener('click',e=>{
 const b=e.target.closest?.('.payhub-button');if(b&&!b.disabled&&b.id!=='payhub-hosted-link'){startPayhub(e);}
 const ip=e.target.closest?.('[data-pay-now]');if(ip){
  const sel=document.getElementById('payhub-ledger'),amt=document.getElementById('payhub-amount'),pf=document.getElementById('payhub-form');
  if(sel){sel.value=ip.dataset.payNow;sel.dispatchEvent(new Event('change',{bubbles:true}));}
  if(amt&&ip.dataset.payAmount){amt.value=ip.dataset.payAmount;amt.dataset.auto='0';}
  if(pf)pf.hidden=false;
  setTimeout(()=>{document.getElementById('payhub-launch')?.scrollIntoView({behavior:'smooth',block:'center'});startPayhub();},150);
 }
});
if(document.getElementById('payhub-quick-pay')&&ledgerHasSelection()){setTimeout(()=>document.getElementById('payhub-quick-pay').click(),500);}
function ledgerHasSelection(){const s=document.getElementById('payhub-ledger');return !!s?.value;}
});
</script>
