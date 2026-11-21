<?php
/** Caller must authenticate the vendor admin and complete CSRF + PIN/second-factor step-up. */
function bc_admin_convert_user_to_personal($vendor_id, $user_id, $reviewer) {
    global $connection_server;
    $vendor_id=(int)$vendor_id; $user_id=(int)$user_id;
    if ($vendor_id<=0 || $user_id<=0 || trim((string)$reviewer)==='') return 'Invalid account or reviewer.';
    if (!bc_fintech_gate_boot()) return 'Account policy is unavailable. Please try again later.';
    // Schema setup must finish before starting a transaction (DDL may commit implicitly).
    bc_security_ensure_schema();
    if (!mysqli_begin_transaction($connection_server)) return 'Could not start account change.';
    try {
        $q=mysqli_query($connection_server,"SELECT * FROM sas_users WHERE vendor_id='$vendor_id' AND id='$user_id' LIMIT 1 FOR UPDATE");
        $row=$q ? mysqli_fetch_assoc($q) : null;
        if (!$row) throw new RuntimeException('User not found for this vendor.');
        if (bc_fintech_account_type($row)!=='business') throw new RuntimeException('Only a Business account can be converted with this action.');
        // Pricing level, wallet, account status, KYC and historical KYB documents are untouched.
        // Cancel any pending KYB review so a stale approval cannot re-promote this account.
        $ok=mysqli_query($connection_server,"UPDATE sas_users SET account_type='personal', account_type_source='admin_personal', account_type_at=NOW(), api_status=2, kyb_status=0 WHERE vendor_id='$vendor_id' AND id='$user_id'");
        if (!$ok || mysqli_affected_rows($connection_server)!==1) throw new RuntimeException('Could not change the account type.');
        if (!bc_app_token_revoke_all($user_id,$vendor_id,'account_type')) throw new RuntimeException('Could not revoke existing app sessions.');
        if (!bc_sec_audit($vendor_id,'admin',(string)$reviewer,'user_account_type_personal','user#'.$user_id,'Business to Personal; developer API disabled; pricing level unchanged')) throw new RuntimeException('Could not record the account change.');
        if (!mysqli_commit($connection_server)) throw new RuntimeException('Could not save the account change.');
        return true;
    } catch (Throwable $e) {
        mysqli_rollback($connection_server);
        return 'Account conversion failed. No account change was saved. Please try again or contact support.';
    }
}
