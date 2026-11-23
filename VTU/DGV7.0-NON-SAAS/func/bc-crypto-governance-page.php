<?php
if (!defined('BC_CRYPTO_POLICY_PAGE')) { http_response_code(404); exit; }
require_once __DIR__.'/bc-crypto-governance.php';
require_once __DIR__.'/bc-edition.php';
header('Cache-Control: no-store, private');
$platform=BC_CRYPTO_POLICY_PAGE==='spadmin';
$actor=$platform?$get_logged_spadmin_details:$get_logged_admin_details;
if (empty($actor['id']) || (int)($actor['status']??0)!==1 || !empty($actor['is_blocked'])) {http_response_code(403);exit('Access denied.');}
$single_owner=!bc_is_saas_edition();
$scope=$platform?0:($single_owner && ($_GET['scope']??'')==='platform'?0:(int)$actor['id']);
$source=$platform?(int)($_GET['source_vendor']??0):(int)$actor['id'];
$notice='';$snapshot=null;$networks=[];$records=[];
$env=in_array($_GET['environment']??'', ['testnet','mainnet'],true)?$_GET['environment']:'testnet';
if (($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
    bc_validate_csrf();
    try {
        if (bc_is_rate_limited('crypto_policy_change',($platform?'spadmin:':'vendor:').(int)$actor['id'],5,300)) throw new RuntimeException('crypto_policy_rate_limited');
        if (!$platform && function_exists('bc_demo_guard_edit')) bc_demo_guard_edit($connection_server);
        $table=$platform?'sas_super_admin':'sas_vendors';
        $fresh=bc_q_row("SELECT * FROM `$table` WHERE id=? LIMIT 1",[(int)$actor['id']]);
        if (!$fresh || (int)($fresh['status']??0)!==1 || !empty($fresh['is_blocked']) || !bc_gauth_enrolled($fresh)) throw new RuntimeException('crypto_fresh_authenticator_required');
        $error=$platform?bc_stepup_spadmin_verify($fresh,$_POST,'crypto_policy_change'):bc_stepup_admin_verify($fresh,$_POST,'crypto_policy_change');
        if ($error!=='') throw new RuntimeException('crypto_stepup_failed');
        $revision=bc_crypto_gov_uint($_POST['revision']??null,1000000000);
        $authority=['role'=>$platform?'spadmin':'vendor','id'=>(int)$fresh['id'],'single_owner'=>$single_owner];
        bc_crypto_gov_save($authority,$scope,$env,$_POST['policy_key']??'', $revision,$_POST,$source);
        $_SESSION['product_purchase_response']='Crypto policy saved. Local approval does not activate live deposits/withdrawals or release financial holds.';
        header('Location: '.$_SERVER['REQUEST_URI']);exit;
    } catch(Throwable $e) {
        $notice=preg_match('/^crypto_[a-z_]+$/D',$e->getMessage())?$e->getMessage():'crypto_policy_unavailable';
        http_response_code(400);
    }
} elseif (($_SERVER['REQUEST_METHOD']??'GET')!=='GET') {http_response_code(405);header('Allow: GET, POST');exit;}
try {
    $result=bc_q('SELECT * FROM sas_crypto_v2_governance WHERE vendor_id=? AND environment=? ORDER BY policy_key',[$scope,$env]);
    if (!($result instanceof mysqli_result)) throw new RuntimeException('crypto_governance_schema_required');
    while($row=mysqli_fetch_assoc($result)) $records[$row['policy_key']]=$row;
    if ($source>0) {
        $snapshot=bc_crypto_gov_snapshot($source,$env);
        if ($snapshot && (int)$snapshot['expires_at_epoch']>time()) $networks=bc_crypto_gov_decode($snapshot);
    }
} catch(Throwable $e) {$notice='crypto_governance_schema_required';}
$selected=$_GET['asset']??'';
if (!is_string($selected) || !isset($networks[$selected])) $selected='';
$e=function($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');};
$area=$platform?'spadmin':'admin';
require_once __DIR__.'/bc-plate-admin.php';
bc_adm_head('Crypto asset and network policies',['area'=>$area]);bc_adm_sidebar(['area'=>$area]);bc_adm_topbar(['area'=>$area,'title'=>'CRYPTO POLICIES','crumb'=>'Crypto / Asset approvals']);
?>
<section class="section p-4">
<p><a href="CryptoOverview.php">Crypto operations overview</a></p>
<h1 class="h3">Crypto asset/network approvals and emergency stop</h1>
<p><a href="CryptoCustody.php"><?php echo $platform?'Custody accounts and protected evidence reviews':'Custody review requirements'; ?></a></p>
<div class="alert alert-warning">Governance only: live deposits, trading and withdrawals remain disconnected. Nothing here changes reconciliation status, legacy withdrawals, customer balances or existing holds. Missing policies/controls, stale catalogues and expired approvals fail closed.</div>
<?php if($notice):?><div role="alert" class="alert alert-danger"><?php echo $e($notice); ?>. If schema is missing, have the operator review and run the explicit CLI migration; this page performs no DDL.</div><?php endif;?>
<p>Editing <strong><?php echo $scope===0?'platform ceilings':'vendor '.$scope; ?></strong>, environment <strong><?php echo $e($env); ?></strong>. Platform controls and asset approval are required in addition to each vendor's controls and approval. No setting overrides an existing financial hold.</p>
<?php if(!$platform):?><p><a href="CryptoBybit.php">Fetch/refresh verified Bybit catalogue</a><?php if($single_owner):?> · <a href="?scope=platform&amp;environment=<?php echo $e($env); ?>">Platform ceilings</a> · <a href="?environment=<?php echo $e($env); ?>">Vendor rules</a><?php endif;?></p><?php endif;?>
<form method="get" class="card p-3 mb-3">
<?php if($single_owner && $scope===0):?><input type="hidden" name="scope" value="platform"><?php endif;?>
<label>Environment<select name="environment" class="form-select"><option value="testnet" <?php echo $env==='testnet'?'selected':''; ?>>Testnet</option><option value="mainnet" <?php echo $env==='mainnet'?'selected':''; ?>>Mainnet governance only</option></select></label>
<?php if($platform):?><label>Verified catalogue source vendor ID<input class="form-control" name="source_vendor" type="number" min="1" value="<?php echo $source>0?$source:''; ?>"></label><?php endif;?>
<button class="btn btn-outline-primary mt-2">Load local catalogue</button></form>
<?php $control=$records['control']??null;$control_data=bc_crypto_gov_decode($control); ?>
<div class="card p-3 mb-3"><h2 class="h5">Emergency admission stop: <?php echo ($control_data['stopped']??true)?'STOPPED':'released for policy evaluation'; ?></h2>
<p>Stops new V2 reservation admission after the stop commits. Existing reservations are not automatically refunded. Already committed work, legacy services and future provider workers require their own controls; this is not a global legacy-withdrawal switch.</p>
<form method="post"><?php echo bc_csrf_field();?><input type="hidden" name="policy_key" value="control"><input type="hidden" name="revision" value="<?php echo (int)($control['revision']??0); ?>">
<label>Security PIN<input class="form-control" type="password" name="pin" inputmode="numeric" required></label><label>Fresh authenticator code<input class="form-control" name="totp_code" pattern="[0-9]{6}" maxlength="6" inputmode="numeric" autocomplete="one-time-code" required></label>
<button class="btn btn-danger mt-2" name="mode" value="stop">Stop new admissions</button> <button class="btn btn-outline-primary mt-2" name="mode" value="resume">Release this admission stop only</button></form></div>
<?php if(!$networks):?><div class="alert alert-info">No fresh verified catalogue for this source/environment. Refresh discovery first. Emergency stop and revocation remain available during outages.</div><?php else:?>
<p>Local snapshot expires <?php echo $e(gmdate('c',(int)$snapshot['expires_at_epoch'])); ?> (15-minute freshness window). Provider changes invalidate prior matching approvals.</p>
<form method="get" class="mb-3"><input type="hidden" name="environment" value="<?php echo $e($env); ?>"><?php if($platform):?><input type="hidden" name="source_vendor" value="<?php echo $source; ?>"><?php endif;?><?php if($single_owner&&$scope===0):?><input type="hidden" name="scope" value="platform"><?php endif;?>
<label>Coin and network<select name="asset" class="form-select"><?php foreach($networks as $key=>$network):?><option value="<?php echo $e($key); ?>" <?php echo $selected===$key?'selected':''; ?>><?php echo $e($key); ?></option><?php endforeach;?></select></label><button class="btn btn-outline-primary mt-2">Review selected network</button></form>
<?php endif;?>
<?php if($selected!==''): $network=$networks[$selected];$existing=$records[$selected]??null;$rule=bc_crypto_gov_decode($existing)??[]; ?>
<div class="card p-3 mb-3"><h2 class="h5"><?php echo $e($selected); ?></h2><p>Provider deposits: <?php echo $network['provider_deposit_available']?'available':'suspended'; ?>. Withdrawals: <?php echo $network['provider_withdraw_available']?'available':'unavailable'; ?>. Provider confirmation requirements: <?php echo $e($network['confirmations'].' / '.$network['withdrawal_confirmations']); ?>. Provider withdrawal precision: <?php echo $e($network['precision']); ?> (not automatically the accounting scale).</p>
<form method="post"><?php echo bc_csrf_field();?><input type="hidden" name="policy_key" value="<?php echo $e($selected); ?>"><input type="hidden" name="revision" value="<?php echo (int)($existing['revision']??0); ?>">
<?php $fields=['scale'=>['Accounting scale (immutable after use)',(string)($rule['scale']??18)],'minimum'=>['Minimum reservation amount',isset($rule['min_units'])?bc_crypto_from_units($rule['min_units'],$rule['scale']):'0'],'maximum'=>['Maximum reservation amount',isset($rule['max_units'])?bc_crypto_from_units($rule['max_units'],$rule['scale']):'0'],'fee_bps'=>[$scope===0?'Platform fee ceiling (basis points)':'Platform fee (basis points)',(string)($rule['fee_bps']??0)],'confirmations'=>['Minimum confirmations',(string)max((int)$network['confirmations'],(int)$network['withdrawal_confirmations'],(int)($rule['confirmations']??0))],'cooldown_seconds'=>['Minimum beneficiary cooldown (seconds)',(string)($rule['cooldown_seconds']??86400)],'valid_seconds'=>['Approval lifetime in seconds (maximum 86400)','3600']];foreach($fields as $name=>$field):?>
<label class="d-block mb-2"><?php echo $e($field[0]); ?><input class="form-control" name="<?php echo $e($name); ?>" value="<?php echo $e($field[1]); ?>" required></label><?php endforeach;?>
<label>Security PIN<input class="form-control" type="password" name="pin" inputmode="numeric" required></label><label>Fresh authenticator code<input class="form-control" name="totp_code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required></label>
<button class="btn btn-primary mt-2" name="mode" value="approve">Save local approval / limits</button></form></div>
<?php endif;?>
<div class="card p-3"><h2 class="h5">Stored approvals and revocation</h2><p>Stored approval is not effective authorization: platform ceilings, both stops, snapshot fingerprints, expiry and independent accounting/reconciliation gates still apply.</p>
<?php foreach($records as $key=>$record):if($key==='control')continue;$r=bc_crypto_gov_decode($record);?><div class="border rounded p-2 mb-2"><strong><?php echo $e($key); ?></strong> — <?php echo ($r['approved']??false)?'locally approved':'revoked'; ?> — revision <?php echo (int)$record['revision']; ?>
<form method="post"><?php echo bc_csrf_field();?><input type="hidden" name="policy_key" value="<?php echo $e($key); ?>"><input type="hidden" name="revision" value="<?php echo (int)$record['revision']; ?>"><input class="form-control" name="pin" type="password" placeholder="Security PIN" required><input class="form-control" name="totp_code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" placeholder="Fresh authenticator code" required><button class="btn btn-outline-danger mt-2" name="mode" value="revoke">Revoke local approval</button></form></div><?php endforeach;?></div>
</section>
<?php bc_adm_foot(['area'=>$area]); ?>
