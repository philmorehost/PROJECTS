<?php declare(strict_types=1);
if(!isset($payhubReady))$payhubReady=PayHub::enabled($db);
$payhubKey=trim(setting($db,'payhub_public_key'));
$canRemitNow=canRemit($user);
$provBank=trim(setting($db,'provincial_bank_name'));
$provAccountName=trim(setting($db,'provincial_account_name'));
$provAccountNumber=trim(setting($db,'provincial_account_number'));
$bankReady=$provAccountNumber!=='';
$ownRows=[];$awaiting=false;$latestLedger=null;$latestPendingKobo=0;
$flash=is_string($flash??null)?$flash:'';$error=is_string($error??null)?$error:'';
if($canRemitNow){
 $s=$db->prepare("SELECT * FROM remittance_ledgers WHERE parish_id=? AND status IN ('pending','partial') ORDER BY period_year DESC,period_month DESC,id DESC");
 $s->execute([(int)$user['hierarchy_id']]);$ownRows=$s->fetchAll();
 foreach($ownRows as &$r){$due=Money::cents($r['provincial_levy_due'])-Money::cents($r['amount_paid']);$r['available_kobo']=max(0,$due);$r['available_amount']=Money::decimal(max(0,$due));}unset($r);
 $ps=$db->prepare("SELECT COUNT(*) FROM payment_notifications n JOIN remittance_ledgers l ON l.id=n.ledger_id WHERE l.parish_id=? AND n.status='pending'");$ps->execute([(int)$user['hierarchy_id']]);$awaiting=(int)$ps->fetchColumn()>0;
 $requestedLedgerId=(int)($_GET['ledger_id']??0);
 if(!$awaiting){
  if($requestedLedgerId>0){$ls=$db->prepare("SELECT * FROM remittance_ledgers WHERE id=? AND parish_id=? AND status IN ('pending','partial') LIMIT 1");$ls->execute([$requestedLedgerId,(int)$user['hierarchy_id']]);$latestLedger=$ls->fetch()?:null;}
  if(!$latestLedger){$ls=$db->prepare("SELECT * FROM remittance_ledgers WHERE parish_id=? AND status IN ('pending','partial') ORDER BY id DESC LIMIT 1");$ls->execute([(int)$user['hierarchy_id']]);$latestLedger=$ls->fetch()?:null;}
 }
 foreach($ownRows as $r){if($latestLedger && (int)$r['id']===(int)$latestLedger['id']){$latestPendingKobo=(int)$r['available_kobo'];break;}}
}
$payTab=($_GET['pay']??($payhubReady?'payhub':'bank'));if(!in_array($payTab,['bank','payhub'],true))$payTab=$payhubReady?'payhub':'bank';
if(!$canRemitNow){ ?><div class="notice">Approved parishes and representatives can start remittances here.</div><?php return; }
$defaultVirtual=null;
if($payhubReady && $payTab==='payhub' && !$awaiting){try{$all=PayHub::virtualAccounts($db);$defaultVirtual=$all[0]??null;}catch(Throwable $e){PayHub::log($db,'virtual_accounts_list_error',['error'=>$e->getMessage(),'user'=>$user['email']??'']);}}
?><div class="payment-layout" id="payment-options"><section class="panel form-panel payment-method-panel"><div class="section-kicker">STEP 2 · <?= $payTab==='bank'?'MANUAL BANK TRANSFER':'PAYHUB VIRTUAL ACCOUNT OR CHECKOUT' ?></div><h2>Complete payment</h2><p class="muted small"><?= $payTab==='bank'?'Transfer the exact amount due to the provincial church account below, then submit your transfer details for treasury confirmation.':'Transfer the exact amount to the PayHub virtual account below or pay online for instant confirmation.' ?></p>
<?php if($flash && ($_GET['pay']??'')==='payhub'): ?><div class="alert success"><?= e((string)$flash) ?></div><?php elseif($error): ?><div class="alert"><?= e((string)$error) ?></div><?php endif; ?>
<div role="tablist" aria-label="Payment methods" class="payment-tabs"><a role="tab" aria-selected="<?= $payTab==='payhub'?'true':'false' ?>" class="payment-tab <?= $payTab==='payhub'?'active':'' ?>" href="?page=remittances&pay=payhub#payment-options"><span class="method-icon">₦</span><div><strong>PayHub Checkout & Virtual Account</strong><small><?= $payhubReady?'Automatic Instant Approval':'Configure in settings' ?></small></div></a><a role="tab" aria-selected="<?= $payTab==='bank'?'true':'false' ?>" class="payment-tab <?= $payTab==='bank'?'active':'' ?>" href="?page=remittances&pay=bank#payment-options"><span class="method-icon">⇄</span><div><strong>Bank Transfer</strong><small>Manual approval</small></div></a></div>
<?php if($awaiting): ?><div class="empty"><h3>Awaiting treasury confirmation</h3><p>Your pending payment is being reviewed. You do not need to submit another payment for the same offering until it is approved or rejected.</p></div><?php else: ?>
<?php if($payTab==='payhub'): ?><div class="payhub-virtual-box" id="payhub-virtual-box"><div class="payhub-header-bar"><span class="badge verified">Instant Approval</span></div>
<form method="post" action="index.php?page=remittances&amp;pay=payhub#payment-options" class="payhub-form-card" id="payhub-form" novalidate><?php hiddenAction('payhub_session') ?><label class="full-width">Select pending offering report<select name="ledger_id" id="payhub-ledger" required><?php if(!$ownRows): ?><option value="">No pending reports</option><?php else: ?><?php foreach($ownRows as $r):?><option value="<?= (int)$r['id'] ?>" data-amount="<?= (int)$r['available_kobo'] ?>" data-ref="<?= e($r['payment_reference']) ?>" <?= $latestLedger && (int)$latestLedger['id']===(int)$r['id']?'selected':'' ?>><?= e($r['period_month'].'/'.$r['period_year'].' · Ref: '.$r['payment_reference'].' · Due '.Money::display($r['available_amount'])) ?></option><?php endforeach; endif; ?></select></label><input type="hidden" name="amount" id="payhub-amount" value="<?= $latestPendingKobo?Money::decimal($latestPendingKobo):'' ?>">
<div class="payhub-showcase"><div class="hero-amount-card"><span class="hero-amount-label">Amount to be Paid</span><div class="hero-amount hero-amount-value" id="payhub-hero-amount"><?= $latestPendingKobo?Money::display(Money::decimal($latestPendingKobo)):'₦0.00' ?></div><div class="hero-reference hero-ref-badge" id="payhub-hero-ref">Reference: <?= $latestLedger?e($latestLedger['payment_reference']):'—' ?></div><div class="hero-tag hero-status-pill">✓ Instant Auto-Approval on Bank Transfer</div></div>
<div class="virtual-account-display-card" id="payhub-virtual-account"><div class="va-header"><span class="va-badge">Official PayHub Virtual Account</span><span class="va-live-pulse">● Active</span></div><div class="va-row"><span class="va-label">Bank Name</span><strong class="va-value" id="payhub-virtual-bank"><?= e($defaultVirtual['bank_name']??'PayHub Virtual Bank') ?></strong></div><div class="va-row va-account-number-box"><span class="va-label">Virtual Account Number</span><div class="va-copy-wrapper"><span class="va-number" id="payhub-virtual-number"><?= e($defaultVirtual['account_number']??'Generating...') ?></span><button type="button" class="copy-btn" id="copy-va-btn" title="Copy account number">Copy</button></div></div><div class="va-row"><span class="va-label">Account Name</span><span class="va-value" id="payhub-virtual-name"><?= e($defaultVirtual['account_name']??'REMIT RCCG LP 63 YAYA') ?></span></div><div class="va-instructions"><small id="payhub-virtual-note">Transfer the exact amount above to this virtual account. Payment is automatically detected &amp; approved immediately once received.</small></div></div></div>
<div id="payhub-session-details" class="payhub-session-details" hidden><div class="session-info-grid"><div class="session-cell"><span>Generated PayHub Reference</span><strong id="payhub-session-ref">—</strong></div><div class="session-cell"><span>Exact Amount Payable</span><strong id="payhub-session-amount" class="text-green">₦0.00</strong></div><div class="session-cell"><a class="primary hosted-link" id="payhub-hosted-link" href="#" target="_blank" rel="noopener">Pay online via card or mobile bank →</a></div></div><p class="small muted" id="payhub-session-note">REMIT monitors this transaction in real-time. Once your bank transfer is received, this page will update automatically.</p></div><p class="payhub-error alert" id="payhub-error" hidden></p><p class="payhub-status alert success" id="payhub-status" hidden></p><div class="alt-payment-cta"><span>Prefer direct church bank deposit?</span><a class="alt-bank-link" href="?page=remittances&pay=bank#payment-options">Switch to Manual Bank Transfer</a></div></form></div><?php else: ?>
<?php $bankEligibleRows=array_values(array_filter($ownRows,fn($r)=>Money::cents($r['provincial_levy_due'])>Money::cents($r['amount_paid']))); ?>
<div class="bank-transfer-card"><?php if($bankReady): ?><div class="bank-card" id="provincial-bank-card"><span>PROVINCIAL RECEIVING BANK ACCOUNT</span><h3><?= e($provBank?:'Provincial Bank Account') ?></h3><div class="va-copy-wrapper"><strong id="prov-bank-number"><?= e($provAccountNumber) ?></strong><button type="button" class="copy-btn bank-copy-btn" id="copy-bank-btn" title="Copy account number">Copy</button></div><p><?= e($provAccountName?:setting($db,'site_title')) ?></p></div><?php if($bankEligibleRows): ?><form method="post" class="form-grid" id="bank-transfer-form" enctype="multipart/form-data"><?php hiddenAction('payment_submit') ?><label class="full-width">Select pending offering report<select name="ledger_id" id="bank-ledger" required><?php foreach($bankEligibleRows as $r): ?><option value="<?= (int)$r['id'] ?>" data-amount="<?= (int)$r['available_kobo'] ?>" data-ref="<?= e($r['payment_reference']) ?>" <?= $latestLedger && (int)$latestLedger['id']===(int)$r['id']?'selected':'' ?>><?= e($r['period_month'].'/'.$r['period_year'].' · Ref: '.$r['payment_reference'].' · Due '.Money::display($r['available_amount'])) ?></option><?php endforeach ?></select></label><label>Amount transferred (NGN)<input name="amount" id="bank-amount" value="<?= $latestPendingKobo?e(Money::decimal($latestPendingKobo)):'' ?>" required inputmode="decimal" pattern="[0-9]{1,13}(\.[0-9]{1,2})?"></label><label>Transfer date<input name="transfer_date" type="date" min="2020-01-01" max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>" required></label><label class="full-width">Bank transaction reference<input name="transfer_reference" maxlength="100" placeholder="Enter bank transfer reference or session ID" required></label><?php if(PaymentProof::ready($db) && PaymentProof::supported()): ?><label class="full-width">Payment receipt (optional)<input type="file" name="payment_proof" accept="image/jpeg,image/png,image/webp"><small>JPEG, PNG or WebP · up to 8 MB.</small></label><?php endif ?><button class="primary full-width">Submit manual payment →</button></form><?php else: ?><div class="empty"><p>No unpaid offering reports found. Record a monthly offering first to submit a manual transfer.</p></div><?php endif; ?><?php else: ?><div class="empty"><p>The provincial receiving bank account has not been configured in Portal settings yet. Ask your administrator to add it<?= $payhubReady?', or use PayHub Checkout & Virtual Account':'' ?>.</p></div><?php endif; ?></div><?php endif; ?><?php endif; ?>
</section></div>
<script>
document.addEventListener('DOMContentLoaded',function(){
if(window.history&&window.history.replaceState&&location.search){
 const cleanUrl=new URL(location.href);
 let changed=false;
 ['payhub_ref','reference','trxref','status'].forEach(k=>{if(cleanUrl.searchParams.has(k)){cleanUrl.searchParams.delete(k);changed=true;}});
 if(changed){window.history.replaceState(null,'',cleanUrl.pathname+(cleanUrl.searchParams.toString()?('?'+cleanUrl.searchParams.toString()):'')+cleanUrl.hash);}
}
window.REMITPayHub={ready:<?= $payhubReady?'true':'false' ?>,base:'https://merchant.payhub.com.ng',defaultVirtual:<?= json_encode($defaultVirtual) ?>};
function money(k){if(typeof k!=='bigint')k=BigInt(k||0);const n=k/100n,c=k%100n;return '₦'+n.toLocaleString('en-NG')+'.'+c.toString().padStart(2,'0');}
function decimalFromKobo(k){if(typeof k!=='bigint')k=BigInt(k||0);return (k/100n).toString()+'.'+(k%100n).toString().padStart(2,'0');}
function koboFromDecimal(raw){raw=(raw||'').toString().trim().replace(/,/g,'');if(!raw||!/^\d{1,13}(?:\.\d{1,2})?$/.test(raw))return 0n;const [w,f='']=raw.split('.');return BigInt(w)*100n+BigInt(f.padEnd(2,'0'));}
async function payhubLog(event,payload){try{await fetch('index.php?payhub_log=1',{method:'POST',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},credentials:'same-origin',body:JSON.stringify({event,payload,url:location.href}),keepalive:true});}catch(e){}}
function updateOffering(){
 const gross=document.getElementById('gross'),due=document.getElementById('levy-preview'),ret=document.getElementById('retained-preview'),form=document.getElementById('income-form');
 if(!gross||!due||!ret)return;const c=koboFromDecimal(gross.value);const rate=BigInt(form?.dataset.rate||5000);
 const levy=(c*rate+5000n)/10000n;due.textContent=money(levy);ret.textContent=money(c-levy);
}
['input','change','keyup','blur'].forEach(ev=>document.addEventListener(ev,e=>{if(e.target&&e.target.id==='gross')updateOffering();}));
setTimeout(updateOffering,30);setTimeout(updateOffering,200);setTimeout(updateOffering,600);window.addEventListener('load',updateOffering);
function payHubDue(sel){const opt=sel?.selectedOptions?.[0];return opt?BigInt(opt.dataset.amount||0):0n;}
function syncPayhub(){const sel=document.getElementById('payhub-ledger'),amt=document.getElementById('payhub-amount'),heroAmt=document.getElementById('payhub-hero-amount'),heroRef=document.getElementById('payhub-hero-ref');if(!sel)return;const opt=sel.selectedOptions?.[0];const due=payHubDue(sel);if(heroAmt)heroAmt.textContent=money(due);if(heroRef)heroRef.textContent='Reference: '+(opt?.dataset.ref||'—');if(amt){amt.value=due?decimalFromKobo(due):'';}}
function syncBankTransfer(){const sel=document.getElementById('bank-ledger'),amt=document.getElementById('bank-amount');if(!sel||!amt)return;const due=payHubDue(sel);if(due)amt.value=decimalFromKobo(due);}
document.addEventListener('change',e=>{if(e.target&&e.target.id==='payhub-ledger'){syncPayhub();autoGeneratePayhub();}else if(e.target&&e.target.id==='bank-ledger'){syncBankTransfer();}});
document.addEventListener('click',e=>{
 const btn=e.target?.closest?.('[data-pay-now]');if(!btn)return;
 const lid=btn.dataset.payNow;if(!lid)return;
 const sel=document.getElementById('payhub-ledger');
 const bankSel=document.getElementById('bank-ledger');
 if(sel){if([...sel.options].some(o=>o.value===lid)){sel.value=lid;syncPayhub();autoGeneratePayhub();}document.getElementById('payment-options')?.scrollIntoView({behavior:'smooth'});}
 else if(bankSel){if([...bankSel.options].some(o=>o.value===lid)){bankSel.value=lid;syncBankTransfer();}document.getElementById('payment-options')?.scrollIntoView({behavior:'smooth'});}
 else{location.href='index.php?page=remittances&pay=payhub&ledger_id='+encodeURIComponent(lid)+'#payment-options';}
});
syncPayhub();syncBankTransfer();
function setVirtualAccount(acct){
 const bank=document.getElementById('payhub-virtual-bank'),num=document.getElementById('payhub-virtual-number'),name=document.getElementById('payhub-virtual-name'),note=document.getElementById('payhub-virtual-note');
 const use=acct||window.REMITPayHub.defaultVirtual;
 if(use&&bank&&num&&name){
  if(use.bank_name)bank.textContent=use.bank_name;
  if(use.account_number)num.textContent=use.account_number;
  if(use.account_name)name.textContent=use.account_name;
  if(note)note.textContent='Transfer the exact amount shown above to this virtual account. When received, payment is confirmed automatically.';
 }else if(num&&num.textContent.trim()==='Generating...'){
  num.textContent='Use online checkout below';
 }
}
function showSession(data){
 const details=document.getElementById('payhub-session-details'),ref=document.getElementById('payhub-session-ref'),amt=document.getElementById('payhub-session-amount'),link=document.getElementById('payhub-hosted-link');
 if(!details)return;
 if(ref)ref.textContent=data.reference||'—';
 if(amt)amt.textContent=data.amount?('₦'+Number(data.amount).toLocaleString('en-NG',{minimumFractionDigits:2,maximumFractionDigits:2})):'₦0.00';
 const checkoutRef=data.payhub_reference||data.reference;
 const checkoutUrl=(typeof data.checkout_url==='string'&&data.checkout_url.startsWith(window.REMITPayHub.base+'/'))?data.checkout_url:(checkoutRef?(window.REMITPayHub.base+'/checkout.php?ref='+encodeURIComponent(checkoutRef)):'');
 if(link&&checkoutUrl){link.href=checkoutUrl;link.hidden=false;}
 details.hidden=false;
 setVirtualAccount(data.virtual_accounts&&data.virtual_accounts[0]?data.virtual_accounts[0]:null);
 payhubLog('session_displayed',{reference:data.reference||'',payhub_reference:checkoutRef||'',amount:data.amount||''});
}
function showPayhubError(message,showRefreshLink){
 const err=document.getElementById('payhub-error');
 if(!err)return;
 err.textContent=message||'';
 if(showRefreshLink){
  const sep=document.createTextNode(' ');
  const a=document.createElement('a');
  a.className='text-link';
  a.href='index.php?page=remittances&pay=payhub#payment-options';
  a.textContent='Refresh page';
  err.append(sep,a);
 }
 err.hidden=false;
}
let isGenerating=false,pendingRegenerate=false,pollTimer=null,activePollRef=null;
function stopPolling(){activePollRef=null;if(pollTimer){clearTimeout(pollTimer);pollTimer=null;}}
function startPolling(ref,ledgerId){
 stopPolling();
 activePollRef=ref;
 const poll=async()=>{
  if(activePollRef!==ref)return;
  try{
   const q='index.php?payhub_status=1&payhub_ref='+encodeURIComponent(ref)+(ledgerId?('&ledger_id='+encodeURIComponent(ledgerId)):'');
   const r=await fetch(q,{credentials:'same-origin',headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'}});
   if(r.status===401||r.status===403){
    stopPolling();
    const d=await r.json().catch(()=>null);
    showPayhubError(d?.message||'Your session expired. Refresh and try again.',true);
    return;
   }
   const d=await r.json().catch(()=>null);
   if(r.ok&&d&&d.paid===true){
    stopPolling();
    payhubLog('payhub_confirmed_poll',{ref,ledgerId:ledgerId||''});
    const target=new URL('index.php?page=remittances&pay=payhub#payment-options',location.href);
    if(target.pathname+target.search===location.pathname+location.search){location.reload();}
    else{location.href=target.href;}
    return;
   }
  }catch(e){payhubLog('payhub_poll_error',{ref,error:e.message});}
  if(activePollRef===ref)pollTimer=setTimeout(poll,10000);
 };
 pollTimer=setTimeout(poll,10000);
}
async function autoGeneratePayhub(){
 const form=document.getElementById('payhub-form'),ledger=document.getElementById('payhub-ledger'),err=document.getElementById('payhub-error');
 if(!window.REMITPayHub.ready||!form||!ledger||!ledger.value)return;
 const due=payHubDue(ledger);if(!due||due<10000n)return;
 if(isGenerating){pendingRegenerate=true;return;}
 isGenerating=true;pendingRegenerate=false;
 if(err){err.hidden=true;err.textContent='';}
 try{
  const ledgerId=ledger.value;
  const fd=new FormData(form);fd.set('amount',decimalFromKobo(due));
  const res=await fetch('index.php?page=remittances&pay=payhub',{method:'POST',body:fd,headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'},credentials:'same-origin'});
  const text=await res.text();let data=null;
  try{data=JSON.parse(text);}catch{throw new Error(res.ok?'Invalid response from payment server.':'Payment server returned HTTP '+res.status+'. Please refresh and try again.');}
  if(res.status===401||res.status===403){stopPolling();showPayhubError(data?.message||'Your session expired. Refresh and try again.',true);return;}
  if(!res.ok||!data?.reference)throw new Error(data?.message||'Could not generate PayHub virtual account.');
  showSession(data);
  startPolling(data.payhub_reference||data.reference,ledgerId);
 }catch(e){
  showPayhubError(e.message||'Failed to automatically generate PayHub session.',false);
  payhubLog('payhub_auto_error',{message:e.message});
 }finally{
  isGenerating=false;
  if(pendingRegenerate){pendingRegenerate=false;autoGeneratePayhub();}
 }
}
window.addEventListener('pageshow',e=>{
 if(!e.persisted)return;
 stopPolling();
 try{
  const key='remit-bfcache-reload:'+location.pathname;
  const last=Number(sessionStorage.getItem(key)||0);
  if(!Number.isFinite(last)||Date.now()-last>=30000){
   sessionStorage.setItem(key,String(Date.now()));
   location.reload();
  }
 }catch{}
});
const copyBtn=document.getElementById('copy-va-btn');if(copyBtn){copyBtn.addEventListener('click',()=>{const num=document.getElementById('payhub-virtual-number');if(num&&num.textContent){navigator.clipboard?.writeText(num.textContent.trim()).then(()=>{const orig=copyBtn.textContent;copyBtn.textContent='Copied!';setTimeout(()=>copyBtn.textContent=orig,2000);}).catch(()=>{});}});}
const copyBankBtn=document.getElementById('copy-bank-btn');if(copyBankBtn){copyBankBtn.addEventListener('click',()=>{const num=document.getElementById('prov-bank-number');if(num&&num.textContent){navigator.clipboard?.writeText(num.textContent.trim()).then(()=>{const orig=copyBankBtn.textContent;copyBankBtn.textContent='Copied!';setTimeout(()=>copyBankBtn.textContent=orig,2000);}).catch(()=>{});}});}
if(document.getElementById('payhub-virtual-box')){setTimeout(autoGeneratePayhub,300);}
});
</script>
