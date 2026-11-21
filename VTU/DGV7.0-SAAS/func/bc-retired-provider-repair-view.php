<?php
// Only the authenticated owner wrapper may include this view.
if (!defined('BC_RETIRED_REPAIR_OWNER') || BC_RETIRED_REPAIR_OWNER !== true || empty($repair_actor['id'])) {
    http_response_code(403); exit('Forbidden');
}
require_once __DIR__.'/bc-retired-provider-repair.php';
header('Cache-Control: no-store');
$notice = ''; $error = ''; $plan = null;
$session_key = 'retired_provider_repair_'.$repair_role.'_'.(int)$repair_actor['id'];
$context = 'retired_provider_repair';
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        bc_validate_csrf();
        if (isset($_POST['send_code'])) {
            $err = bc_stepup_send_email_otp($repair_role === 'spadmin' ? 0 : (int)$repair_actor['id'], (string)$repair_actor['email'], (string)$repair_actor['email'], $context, 'Retired Provider Configuration Cleanup');
            if ($err !== '') throw new RuntimeException($err);
            $notice = 'Verification code requested. Check your email; authenticator users should use their authenticator code.';
        } elseif (isset($_POST['execute_repair'])) {
            $preview = $_SESSION[$session_key] ?? array();
            unset($_SESSION[$session_key]); // One execution attempt per preview.
            if (empty($preview['hash']) || (int)($preview['expires'] ?? 0) < time()) throw new RuntimeException('Preview expired. Reload the page.');
            if (($_POST['confirmation'] ?? '') !== 'REMOVE RETIRED PROVIDERS' || empty($_POST['backup_confirmed'])) throw new RuntimeException('Confirm a protected backup and type the confirmation phrase.');
            // Whitelist factor fields: never forward client policy flags such as pin_check_only.
            $factors = array('pin'=>(string)($_POST['pin'] ?? ''),'otp_code'=>(string)($_POST['factor'] ?? ''),'totp_code'=>(string)($_POST['factor'] ?? ''));
            $verifier = $repair_role === 'spadmin' ? 'bc_stepup_spadmin_verify' : 'bc_stepup_admin_verify';
            $err = $verifier($repair_actor, $factors, $context);
            if ($err !== '') throw new RuntimeException($err);
            $result = bc_retired_repair_execute($connection_server, null, $preview['hash']);
            if (function_exists('bc_sec_audit')) bc_sec_audit($repair_role === 'spadmin' ? 0 : (int)$repair_actor['id'], $repair_role, (string)$repair_actor['email'], 'retired_provider_configuration_removed', $result['hash'], json_encode(array('apis'=>$result['apis'],'counts'=>array_map(function($op){return array('table'=>$op['table'],'count'=>$op['count']);},$result['operations']))));
            $notice = 'Configuration cleanup committed. Financial transactions, balances, receipts and audit records were not changed.';
        } else throw new RuntimeException('Unknown action.');
    }
    $plan = bc_retired_repair_plan($connection_server);
    $_SESSION[$session_key] = array('hash'=>$plan['hash'],'expires'=>time()+600);
} catch (Throwable $e) { $error = $e->getMessage(); }
function bc_retired_repair_html($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Retired provider database repair</title><style>body{font:16px system-ui;max-width:960px;margin:32px auto;padding:20px;color:#172033;background:#f5f6fa}section{background:white;border-radius:16px;padding:24px;margin:20px 0}td,th{text-align:left;padding:10px;border-bottom:1px solid #ddd}table{width:100%;border-collapse:collapse}input{padding:10px;margin:8px;max-width:90%}button{padding:12px 18px;cursor:pointer} .warn{background:#fff0cf;padding:16px}.error{background:#ffe0e0;padding:16px}</style></head><body>
<h1>Retired provider database repair</h1>
<p>Remove LegitDataWay and Bilalsadasub credentials, service mappings, pricing parameters and marketplace configuration from this installation.</p>
<div class="warn"><strong>Back up and preserve evidence first.</strong> Pause purchases and queue workers during cleanup. This is configuration cleanup, not balance reconciliation or a fix for the other security-audit findings. No financial tables are dropped or cleared. Pending provider transactions require manual reconciliation.</div>
<?php if ($notice !== ''): ?><p role="status"><?=bc_retired_repair_html($notice)?></p><?php endif; ?>
<?php if ($error !== ''): ?><p role="alert" class="error"><?=bc_retired_repair_html($error)?></p><a href="RetiredProviderRepair.php">Refresh preview</a><?php endif; ?>
<?php if ($plan !== null): ?><section><h2>Preview — no deletion until confirmed</h2><p><?=count($plan['apis'])?> matching API configurations. Scope: this installation (all tenants).</p><table><tr><th>Configuration table</th><th>Rows to remove</th></tr>
<?php foreach ($plan['operations'] as $op): ?><tr><td><?=bc_retired_repair_html($op['table'])?></td><td><?=(int)$op['count']?></td></tr><?php endforeach; ?></table>
<?php if (!$plan['operations']): ?><p>No matching configuration remains. Re-running is safe.</p><?php endif; ?>
<p>Missing optional tables skipped: <?=count($plan['missing_optional_tables'])?>. Any incompatible existing schema or nontransactional affected table blocks execution.</p></section>
<?php if ($plan['operations']): ?><section><h2>Owner authorization</h2><form method="post"><?=bc_csrf_field()?><button name="send_code" value="1">Request email verification code</button></form><form method="post" autocomplete="off"><?=bc_csrf_field()?>
<p><label>Security PIN <input type="password" name="pin" required inputmode="numeric"></label></p>
<p><label>Authenticator / email verification code <input name="factor" required inputmode="numeric" autocomplete="one-time-code"></label></p>
<p><label><input type="checkbox" name="backup_confirmed" value="1" required>I preserved a protected backup and incident evidence.</label></p>
<p><label>Type REMOVE RETIRED PROVIDERS <input name="confirmation" required pattern="REMOVE RETIRED PROVIDERS" size="30"></label></p>
<button name="execute_repair" value="1">Remove only the previewed configuration</button></form></section><?php endif; endif; ?>
<p>Deploy with stale-file deletion, revoke these providers' credentials externally, and verify old callback URLs return 410/404. Keep backups outside the public webroot.</p></body></html>
