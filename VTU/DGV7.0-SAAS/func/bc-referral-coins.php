<?php
/** Referral rewards: only audited, completed crypto/international settlements count.
 * No public endpoint accepts volume. Pending coins never enter sas_points_log.
 */
function bc_reward_query($sql) {
    global $connection_server;
    $r = mysqli_query($connection_server, $sql);
    if ($r === false) throw new RuntimeException('Reward database operation failed');
    return $r;
}
function bc_reward_escape($s) {
    global $connection_server;
    return mysqli_real_escape_string($connection_server, (string)$s);
}
function bc_referral_usd_cents($value) {
    if (!preg_match('/^(0|[1-9][0-9]{0,6})(?:\.([0-9]{1,2}))?$/D', (string)$value, $m)) return null;
    return (int)$m[1] * 100 + (int)str_pad($m[2] ?? '', 2, '0');
}
function bc_referral_coin_amount($value) {
    return preg_match('/^(0|[1-9][0-9]{0,6})$/D', (string)$value) ? (int)$value : null;
}
function bc_reward_schema() {
    static $ready = false;
    if ($ready) return;
    bc_reward_query("CREATE TABLE IF NOT EXISTS sas_referral_settlements (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, vendor_id INT NOT NULL,
        referee_id INT NOT NULL, category VARCHAR(32) NOT NULL, provider VARCHAR(32) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
        provider_reference VARCHAR(160) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL, usd_cents BIGINT NOT NULL,
        state VARCHAR(20) NOT NULL DEFAULT 'completed', confirmed_by INT NOT NULL,
        created_at DATETIME NOT NULL, reversed_at DATETIME NULL,
        UNIQUE KEY uq_provider_reference(provider,provider_reference),
        KEY idx_referee(vendor_id,referee_id,state)
    ) ENGINE=InnoDB");
    bc_reward_query("CREATE TABLE IF NOT EXISTS sas_referral_coin_awards (
        vendor_id INT NOT NULL, referee_id INT NOT NULL, referrer_id INT NOT NULL,
        coins INT NOT NULL, state VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL,
        PRIMARY KEY(vendor_id,referee_id), KEY idx_referrer(vendor_id,referrer_id)
    ) ENGINE=InnoDB");
    // Transactions must really be transactions, including the existing points ledger.
    $q = bc_reward_query("SHOW TABLE STATUS WHERE Name IN ('sas_users','sas_points_log','sas_conversions','sas_referral_settlements','sas_referral_coin_awards')");
    $n = 0;
    while ($r = mysqli_fetch_assoc($q)) {
        if (strcasecmp($r['Engine'], 'InnoDB') !== 0) throw new RuntimeException('Rewards require InnoDB tables. Contact the administrator.');
        $n++;
    }
    if ($n !== 5) throw new RuntimeException('Coin tables are not ready. Contact the administrator.');
    $ready = true;
}
function bc_referral_coin_settings($vendor_id) {
    global $connection_server;
    $vendor_id = (int)$vendor_id;
    $coins = 1000;
    if (!$connection_server) return array('reward_coins'=>1000, 'qualifying_usd'=>100.0, 'reward_usd'=>0.0, 'first_purchase_coins'=>1000);
    $q = mysqli_query($connection_server, "SELECT setting_value FROM sas_settings WHERE vendor_id=$vendor_id AND setting_name='referral_reward_coins' LIMIT 1");
    if ($q && ($r = mysqli_fetch_assoc($q))) {
        $v = bc_referral_coin_amount($r['setting_value']);
        if ($v !== null) $coins = $v;
    }
    return array('reward_coins'=>$coins, 'qualifying_usd'=>100.0, 'reward_usd'=>0.0, 'first_purchase_coins'=>$coins);
}
/** Caller must hold both users' row locks and an open transaction. */
function bc_referral_reconcile_locked($vid, $referee, $referrer) {
    $eid = (int)$referee['id']; $rid = (int)$referrer['id'];
    $r = mysqli_fetch_assoc(bc_reward_query("SELECT COALESCE(SUM(usd_cents),0) AS cents FROM sas_referral_settlements WHERE vendor_id=$vid AND referee_id=$eid AND state='completed'"));
    $cents = (int)$r['cents'];
    $award = mysqli_fetch_assoc(bc_reward_query("SELECT * FROM sas_referral_coin_awards WHERE vendor_id=$vid AND referee_id=$eid FOR UPDATE"));
    $username = bc_reward_escape($referrer['username']);
    if ($award && (int)$award['referrer_id'] !== $rid) throw new RuntimeException('Referral ownership changed; manual review required');
    if ($award) {
        if (strpos($award['state'], 'legacy_') === 0) {
            $state = $cents >= 10000 ? 'legacy_verified' : 'legacy_locked';
            bc_reward_query("UPDATE sas_referral_coin_awards SET state='$state' WHERE vendor_id=$vid AND referee_id=$eid");
            return;
        }
        if ($cents < 10000 && $award['state'] === 'paid') {
            $coins = -(int)$award['coins'];
            bc_reward_query("INSERT INTO sas_points_log(vendor_id,username,point_amount,log_type) VALUES($vid,'$username',$coins,'REFERRAL_REVERSAL')");
            bc_reward_query("UPDATE sas_referral_coin_awards SET state='reversed' WHERE vendor_id=$vid AND referee_id=$eid");
        }
        return;
    }
    // Old first-purchase or USD awards must not pay again under the new programme.
    if (!empty($referee['referral_bonus_awarded'])) {
        if ($cents >= 10000) bc_reward_query("INSERT INTO sas_referral_coin_awards(vendor_id,referee_id,referrer_id,coins,state,created_at) VALUES($vid,$eid,$rid,0,'legacy_verified',NOW())");
        return;
    }
    $old = mysqli_fetch_assoc(bc_reward_query("SELECT rewarded FROM sas_referral_progress WHERE vendor_id=$vid AND referee_id=$eid LIMIT 1"));
    if (!empty($old['rewarded']) || $cents < 10000) return;
    if ((int)$referee['status'] !== 1 || (int)$referrer['status'] !== 1) return;
    // Reject obvious self-referral aliases. Never allow a missing identity to qualify.
    foreach (array('email','phone_number') as $field) {
        $a = strtolower(trim((string)($referee[$field] ?? '')));
        $b = strtolower(trim((string)($referrer[$field] ?? '')));
        if ($field === 'phone_number') { $a = preg_replace('/\D/', '', $a); $b = preg_replace('/\D/', '', $b); }
        if ($a === '' || $b === '' || $a === $b) return;
    }
    if (empty($referee['email_verified']) || empty($referrer['email_verified'])) return;
    $coins = bc_referral_coin_settings($vid)['reward_coins'];
    bc_reward_query("INSERT INTO sas_referral_coin_awards(vendor_id,referee_id,referrer_id,coins,state,created_at) VALUES($vid,$eid,$rid,$coins,'paid',NOW())");
    bc_reward_query("INSERT INTO sas_points_log(vendor_id,username,point_amount,log_type) VALUES($vid,'$username',$coins,'REFERRAL_UNLOCK')");
    bc_reward_query("UPDATE sas_users SET referral_bonus_awarded=1 WHERE vendor_id=$vid AND id=$eid");
}
/** Admin-only settlement audit. Only call AFTER provider evidence has been checked.
 * Does not execute a payment, accept public request amounts, or mark requests paid.
 */
function bc_referral_confirm_settlement($vid, $eid, $category, $provider, $reference, $usd, $actor, $reverse_id = 0) {
    global $connection_server;
    $vid = (int)$vid; $eid = (int)$eid; $actor = (int)$actor; $reverse_id = (int)$reverse_id;
    $cents = bc_referral_usd_cents($usd);
    if ($vid < 1 || $eid < 1 || $actor !== $vid || !in_array($category, array('crypto','intl_transfer'), true)
        || !in_array($provider,array('klasha','flutterwave','platnova'),true)
        || !preg_match('/^[A-Za-z0-9_.:-]{1,160}$/D', $reference) || $cents === null || $cents < 1) return array('ok'=>false,'message'=>'Invalid settlement details.');
    try {
        bc_referral_ensure_schema(); bc_reward_schema();
        if (!mysqli_begin_transaction($connection_server)) throw new RuntimeException('Cannot start transaction');
        // Every operation locks the full tenant user set involved in ascending id order.
        // Lock referee first via a tenant advisory lock, serialising link lookup and reversals.
        $lock = 'referral-' . $vid . '-' . $eid;
        $got = mysqli_fetch_row(bc_reward_query("SELECT GET_LOCK('$lock', 5)"));
        if ((int)$got[0] !== 1) throw new RuntimeException('Referral busy; retry.');
        $ref = mysqli_fetch_assoc(bc_reward_query("SELECT referral_id FROM sas_users WHERE vendor_id=$vid AND id=$eid"));
        $rid = (int)($ref['referral_id'] ?? 0);
        if ($rid < 1 || $rid === $eid) throw new RuntimeException('No valid referrer.');
        $users = bc_reward_query("SELECT * FROM sas_users WHERE vendor_id=$vid AND id IN ($eid,$rid) ORDER BY id FOR UPDATE");
        $map = array(); while ($u = mysqli_fetch_assoc($users)) $map[(int)$u['id']] = $u;
        if (count($map) !== 2 || (int)$map[$eid]['referral_id'] !== $rid) throw new RuntimeException('Invalid referral ownership.');
        if ($reverse_id) {
            bc_reward_query("UPDATE sas_referral_settlements SET state='reversed',reversed_at=NOW() WHERE id=$reverse_id AND vendor_id=$vid AND referee_id=$eid AND state='completed'");
            if (mysqli_affected_rows($connection_server) !== 1) throw new RuntimeException('Settlement already reversed or not found.');
        } else {
            $p = bc_reward_escape($provider); $r = bc_reward_escape($reference);
            bc_reward_query("INSERT INTO sas_referral_settlements(vendor_id,referee_id,category,provider,provider_reference,usd_cents,confirmed_by,created_at) VALUES($vid,$eid,'$category','$p','$r',$cents,$actor,NOW())");
        }
        bc_referral_reconcile_locked($vid, $map[$eid], $map[$rid]);
        if (!mysqli_commit($connection_server)) throw new RuntimeException('Commit failed');
        return array('ok'=>true,'message'=>'Settlement audit saved; referral eligibility updated.');
    } catch (Throwable $e) {
        mysqli_rollback($connection_server);
        error_log('[referral] ' . $e->getMessage());
        return array('ok'=>false,'message'=>'Could not save. Check the reference is unique, the referral exists and the coin tables are ready.');
    } finally {
        if (isset($lock)) mysqli_query($connection_server, "SELECT RELEASE_LOCK('$lock')");
    }
}
function bc_referral_coin_progress($vid, $uid) {
    $vid = (int)$vid; $uid = (int)$uid;
    $out = array('referred'=>0,'qualified'=>0,'earned_coins'=>0,'locked_coins'=>0,'earned_usd'=>0,'rows'=>array());
    bc_reward_schema();
    $cfg = bc_referral_coin_settings($vid);
    $q = bc_reward_query("SELECT u.id AS referee_id,u.username AS referee_name,u.reg_date,u.referral_bonus_awarded,
        a.coins,a.state,COALESCE(s.cents,0) AS cents
        FROM sas_users u LEFT JOIN sas_referral_coin_awards a ON a.vendor_id=u.vendor_id AND a.referee_id=u.id
        LEFT JOIN (SELECT referee_id,SUM(usd_cents) AS cents FROM sas_referral_settlements WHERE vendor_id=$vid AND state='completed' GROUP BY referee_id) s ON s.referee_id=u.id
        WHERE u.vendor_id=$vid AND u.referral_id='$uid' AND u.id<>$uid ORDER BY u.id DESC");
    while ($r = mysqli_fetch_assoc($q)) {
        $r['qualifying_usd'] = (int)$r['cents'] / 100;
        $r['rewarded'] = in_array($r['state'], array('paid','legacy_verified'), true) ? 1 : 0;
        $r['reward_status'] = $r['state'] ?: (!empty($r['referral_bonus_awarded']) ? 'legacy_locked' : ((int)$r['cents'] >= 10000 ? 'identity_review' : 'locked'));
        $r['coins'] = $r['coins'] === null ? (!empty($r['referral_bonus_awarded']) ? 0 : $cfg['reward_coins']) : (int)$r['coins'];
        $r['progress_percent'] = min(100, (int)$r['cents'] / 100);
        $r['remaining_usd'] = max(0, 10000-(int)$r['cents']) / 100;
        $out['referred']++;
        if ($r['rewarded']) { $out['qualified']++; $out['earned_coins'] += $r['coins']; }
        elseif ($r['reward_status']==='locked' || $r['reward_status']==='identity_review') $out['locked_coins'] += $r['coins'];
        $out['rows'][] = $r;
    }
    $owner = mysqli_fetch_assoc(bc_reward_query("SELECT username FROM sas_users WHERE vendor_id=$vid AND id=$uid LIMIT 1"));
    $out['legacy_locked_coins'] = $owner ? bc_referral_legacy_hold($vid, $owner['username']) : 0;
    return $out;
}

/** Old first-purchase rewards have no per-referee ledger reference. Quarantine
 * that legacy bucket until every legacy-awarded friend has audited qualification;
 * do not guess individual amounts or mint replacement coins.
 */
function bc_referral_legacy_hold($vid, $username, $escaped = false) {
    $vid=(int)$vid; $username=$escaped ? $username : bc_reward_escape($username);
    $owner=mysqli_fetch_assoc(bc_reward_query("SELECT id FROM sas_users WHERE vendor_id=$vid AND username='$username' LIMIT 1"));
    if (!$owner) throw new RuntimeException('Coin owner unavailable');
    $uid=(int)$owner['id'];
    $q=bc_reward_query("SELECT u.id FROM sas_users u LEFT JOIN sas_referral_coin_awards a ON a.vendor_id=u.vendor_id AND a.referee_id=u.id WHERE u.vendor_id=$vid AND u.referral_id='$uid' AND u.referral_bonus_awarded=1 AND (a.referee_id IS NULL OR a.state='legacy_locked') LIMIT 1");
    if (!mysqli_fetch_assoc($q)) return 0;
    $r=mysqli_fetch_assoc(bc_reward_query("SELECT COALESCE(SUM(point_amount),0) AS coins FROM sas_points_log WHERE vendor_id=$vid AND username='$username' AND log_type='REFERRAL_BONUS'"));
    return max(0,(int)$r['coins']);
}

/** All conversion writers use this lock order: user row, then conversion row.
 * Pending requests reserve coins; approvals debit once; reversals may create debt.
 */
function bc_coin_conversion_change($vid, $username, $points = null, $id = 0, $action = 'submit') {
    global $connection_server;
    $vid=(int)$vid; $id=(int)$id; $username=bc_reward_escape($username);
    try {
        bc_reward_schema();
        if (!mysqli_begin_transaction($connection_server)) throw new RuntimeException('Transaction unavailable');
        $user=mysqli_fetch_assoc(bc_reward_query("SELECT id,status FROM sas_users WHERE vendor_id=$vid AND username='$username' FOR UPDATE"));
        if (!$user || (int)$user['status']!==1) throw new RuntimeException('Account unavailable');
        $conversion=null;
        if ($action!=='submit') {
            if (!in_array($action,array('approve','reject'),true)) throw new RuntimeException('Invalid action');
            $conversion=mysqli_fetch_assoc(bc_reward_query("SELECT * FROM sas_conversions WHERE vendor_id=$vid AND id=$id AND username='$username' FOR UPDATE"));
            if (!$conversion || $conversion['status']!=='pending') throw new RuntimeException('Request already processed');
            $points=(int)$conversion['points'];
        }
        if (!is_int($points) || $points<=0 || $points>1000000000) throw new RuntimeException('Invalid coin amount');
        $sum=mysqli_fetch_assoc(bc_reward_query("SELECT COALESCE(SUM(point_amount),0) AS coins FROM sas_points_log WHERE vendor_id=$vid AND username='$username'"));
        $reserved=mysqli_fetch_assoc(bc_reward_query("SELECT COALESCE(SUM(points),0) AS coins FROM sas_conversions WHERE vendor_id=$vid AND username='$username' AND status='pending'"));
        $legacy_hold=bc_referral_legacy_hold($vid,$username,true);
        $sum['coins']=(int)$sum['coins']-$legacy_hold;
        $settings=bc_get_coin_settings($vid);
        $rate=(float)$settings['points_conversion_rate'];
        if (!is_finite($rate) || $rate<=0) throw new RuntimeException('Coin conversion rate unavailable');
        if ($action==='submit') {
            if ($points < max(1,(int)$settings['min_points_conversion'])) throw new RuntimeException('Below minimum coin conversion');
            if ($points > (int)$sum['coins']-(int)$reserved['coins']) throw new RuntimeException('Insufficient unlocked coins');
            $amount=round($points/$rate,2);
            if ($amount<=0) throw new RuntimeException('Conversion amount too small');
            bc_reward_query("INSERT INTO sas_conversions(vendor_id,username,points,amount,status) VALUES($vid,'$username',$points,$amount,'pending')");
        } else {
            $amount=(float)$conversion['amount'];
            if ($action==='approve') {
                // Revalidate after refunds/reversals, including all pending reservations.
                if ((int)$sum['coins'] < (int)$reserved['coins'] || $amount<=0 || !is_finite($amount)) throw new RuntimeException('Insufficient unlocked coins; reject or review pending requests');
                bc_reward_query("INSERT INTO sas_points_log(vendor_id,username,point_amount,log_type) VALUES($vid,'$username',-$points,'CONVERSION_DEBIT')");
                bc_reward_query("UPDATE sas_users SET balance=balance+$amount WHERE vendor_id=$vid AND username='$username'");
            }
            $state=$action==='approve' ? 'approved' : 'rejected';
            bc_reward_query("UPDATE sas_conversions SET status='$state',completion_date=NOW() WHERE vendor_id=$vid AND id=$id AND status='pending'");
            if (mysqli_affected_rows($connection_server)!==1) throw new RuntimeException('Request changed; retry');
        }
        if (!mysqli_commit($connection_server)) throw new RuntimeException('Commit failed');
        return array('ok'=>true,'message'=>$action==='submit' ? 'Conversion request submitted for approval.' : 'Conversion '.$action.' completed.', 'points'=>$points,'amount'=>$amount);
    } catch (Throwable $e) {
        mysqli_rollback($connection_server);
        error_log('[coin-conversion] '.$e->getMessage());
        return array('ok'=>false,'message'=>'Conversion not processed. Check unlocked coins, pending requests and conversion settings.');
    }
}
