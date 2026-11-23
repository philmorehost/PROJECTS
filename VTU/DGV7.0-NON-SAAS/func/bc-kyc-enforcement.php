<?php
/** Fresh local policy read. Never establish authorization from session/option caches. */
function bc_kyc_enforcement_current($vendor_id){
    $vendor_id=(int)$vendor_id;
    if($vendor_id<=0)throw new RuntimeException('KYC policy is unavailable.');
    $row=bc_q_row("SELECT v.force_kyc AS tenant_force,
        (SELECT MAX(CASE WHEN option_value='1' THEN 1 WHEN option_value='0' THEN 0 ELSE 2 END)
         FROM sas_super_admin_options WHERE option_name='force_kyc') AS platform_force
        FROM sas_vendors v WHERE v.id=? LIMIT 1",[$vendor_id]);
    if(!is_array($row))throw new RuntimeException('KYC policy is unavailable.');
    $tenant=$row['tenant_force']??'0';$platform=$row['platform_force']??'0';
    if(!in_array((string)$tenant,['0','1'],true)||!in_array((string)$platform,['0','1'],true))throw new RuntimeException('KYC policy is invalid.');
    return (string)$tenant==='1'||(string)$platform==='1';
}
