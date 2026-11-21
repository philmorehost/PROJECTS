<?php
/** Narrow, transactional configuration repair. Never deletes balances or financial history. */
require_once __DIR__ . '/bc-retired-vtu-providers.php';

function bc_retired_repair_tables() {
    return array(
        'sas_airtime_status','sas_betting_status','sas_bulk_sms_status','sas_shared_data_status',
        'sas_sme_data_status','sas_cg_data_status','sas_dd_data_status','sas_cable_status',
        'sas_exam_status','sas_electric_status','sas_datacard_status','sas_rechargecard_status',
        'sas_smart_parameter_values','sas_agent_parameter_values','sas_api_parameter_values',
        'sas_smart_card_funding_parameter_values','sas_agent_card_funding_parameter_values',
        'sas_api_card_funding_parameter_values','sas_smart_card_transaction_parameter_values',
        'sas_agent_card_transaction_parameter_values','sas_api_card_transaction_parameter_values'
    );
}
function bc_retired_repair_query($db, $sql) {
    $result = mysqli_query($db, $sql);
    if ($result === false) throw new RuntimeException('Database repair could not complete a query. No credentials are included in this response.');
    return $result;
}
function bc_retired_repair_scope($pairs) {
    $parts = array();
    foreach ($pairs as $vid => $ids) {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids) $parts[] = '(vendor_id='.(int)$vid.' AND api_id IN ('.implode(',', $ids).'))';
    }
    return $parts ? '('.implode(' OR ', $parts).')' : '0=1';
}
function bc_retired_repair_plan($db, $vendor_id = null, $lock = false) {
    // NULL means platform scope. Callers must supply a server-authenticated tenant otherwise.
    if ($vendor_id !== null && (int)$vendor_id <= 0) throw new InvalidArgumentException('Invalid repair tenant.');
    $engines = array();
    $q = bc_retired_repair_query($db, 'SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()');
    while ($r = mysqli_fetch_assoc($q)) $engines[$r['TABLE_NAME']] = strtoupper((string)$r['ENGINE']);
    if (!isset($engines['sas_apis'])) throw new RuntimeException('API configuration table is missing. Repair refused.');
    $suffix = $lock ? ' FOR UPDATE' : '';
    $where = $vendor_id === null ? '' : ' WHERE vendor_id='.(int)$vendor_id;
    $q = bc_retired_repair_query($db, 'SELECT id, vendor_id, api_base_url FROM sas_apis'.$where.' ORDER BY vendor_id,id'.$suffix);
    $pairs = array(); $apis = array();
    while ($r = mysqli_fetch_assoc($q)) {
        if (!bc_retired_vtu_provider($r['api_base_url'])) continue;
        $vid = (int)$r['vendor_id']; $id = (int)$r['id'];
        $pairs[$vid][] = $id;
        $apis[] = array('vendor_id'=>$vid, 'id'=>$id); // Never return API keys or URLs with credentials.
    }
    $pred = bc_retired_repair_scope($pairs);
    $ops = array(); $missing = array();
    foreach (bc_retired_repair_tables() as $table) {
        if (!isset($engines[$table])) { $missing[] = $table; continue; }
        // An existing but incompatible schema is an error, not a silent partial cleanup.
        $row = mysqli_fetch_assoc(bc_retired_repair_query($db, "SELECT COUNT(*) AS n FROM `$table` WHERE $pred"));
        if ((int)$row['n'] > 0) $ops[] = array('table'=>$table,'where'=>$pred,'count'=>(int)$row['n']);
    }
    // Global marketplace listings can only be cleaned by the platform owner.
    if ($vendor_id === null && isset($engines['sas_api_marketplace_listings'])) {
        $ids = array();
        $q = bc_retired_repair_query($db, 'SELECT id, api_website FROM sas_api_marketplace_listings ORDER BY id'.$suffix);
        while ($r = mysqli_fetch_assoc($q)) if (bc_retired_vtu_provider($r['api_website'])) $ids[] = (int)$r['id'];
        if ($ids) $ops[] = array('table'=>'sas_api_marketplace_listings','where'=>'id IN ('.implode(',', $ids).')','count'=>count($ids));
    }
    // Parent credentials are removed LAST, after tenant-scoped mappings.
    $parent = str_replace('api_id IN', 'id IN', $pred);
    if ($apis) $ops[] = array('table'=>'sas_apis','where'=>$parent,'count'=>count($apis));
    foreach ($ops as $op) if (($engines[$op['table']] ?? '') !== 'INNODB') throw new RuntimeException('Repair requires InnoDB for every affected table. Convert/review the database with your DBA first.');
    // Lock acquisition also requires the source/parent table to be transactional.
    if ($lock && $engines['sas_apis'] !== 'INNODB') throw new RuntimeException('Repair requires InnoDB API configuration.');
    $plan = array('scope'=>$vendor_id === null ? 'platform' : (int)$vendor_id,'apis'=>$apis,'operations'=>$ops,'missing_optional_tables'=>$missing);
    $plan['hash'] = hash('sha256', json_encode($plan));
    return $plan;
}
function bc_retired_repair_execute($db, $vendor_id, $expected_hash) {
    if (!is_string($expected_hash) || !preg_match('/^[a-f0-9]{64}$/D', $expected_hash)) throw new RuntimeException('Preview is required.');
    if (!mysqli_begin_transaction($db)) throw new RuntimeException('Could not start repair transaction.');
    try {
        $plan = bc_retired_repair_plan($db, $vendor_id, true);
        if (!hash_equals($plan['hash'], $expected_hash)) throw new RuntimeException('The repair plan changed. Preview again before executing.');
        foreach ($plan['operations'] as $op) {
            // Table identifiers/predicates are generated only by this fixed allowlist planner.
            bc_retired_repair_query($db, 'DELETE FROM `'.$op['table'].'` WHERE '.$op['where']);
            if (mysqli_affected_rows($db) !== $op['count']) throw new RuntimeException('Rows changed during repair. All changes rolled back; preview again.');
        }
        if (!mysqli_commit($db)) throw new RuntimeException('Could not commit repair.');
        return $plan;
    } catch (Throwable $e) {
        mysqli_rollback($db);
        throw $e;
    }
}
