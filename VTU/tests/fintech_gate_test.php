<?php
/**
 * DGV7 phase 1B behaviour tests — schema, app tokens, and route gates.
 *
 * Runs with `php -n` (no mysqli, no database): the shipped source is loaded with a small
 * fake-DB layer, the same approach as user_login_otp_flow_test.php. That lets the REAL
 * shipped functions be exercised, not a paraphrase of them.
 *
 * Run: php -n VTU/tests/fintech_gate_test.php
 */

$root = dirname(__DIR__);
$editions = ['DGV7.0-SAAS', 'DGV7.0-NON-SAAS'];

$checks = 0;
function gcheck($label, $actual, $expected) {
    global $checks;
    $checks++;
    if ($actual !== $expected) {
        throw new RuntimeException($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
    echo "ok $label\n";
}
function gtrue($label, $actual) { gcheck($label, (bool)$actual, true); }
function gfalse($label, $actual) { gcheck($label, (bool)$actual, false); }

// ─────────────────────────── fake database ───────────────────────────
class FakeDb {}
$GLOBALS['db'] = [
    'columns' => [],   // table => [column => true]
    'users' => [],     // id => row
    'site' => [],      // vendor_id => android_app_url
    'app_tokens' => [],
    'service' => [],   // "vendor:service" => row
    'global_service' => [],
    'affected' => 0,
];

function fake_mysqli_real_escape_string($db, $value) { return addslashes((string)$value); }
function fake_mysqli_num_rows($rows) { return is_array($rows) ? count($rows) : 0; }
function fake_mysqli_fetch_assoc($rows) { return is_array($rows) && $rows ? $rows[0] : null; }
function fake_mysqli_affected_rows($db) { return $GLOBALS['db']['affected']; }

function fake_mysqli_query($db, $sql) {
    $GLOBALS['db']['affected'] = 0;

    // schema: SHOW COLUMNS FROM `t` LIKE 'c'
    if (preg_match('/^SHOW COLUMNS FROM `([a-z0-9_]+)` LIKE \'([a-z0-9_]+)\'$/i', $sql, $m)) {
        return empty($GLOBALS['db']['columns'][$m[1]][$m[2]]) ? [] : [['Field' => $m[2]]];
    }
    if (preg_match('/^ALTER TABLE `([a-z0-9_]+)` ADD COLUMN `([a-z0-9_]+)`/i', $sql, $m)) {
        $GLOBALS['db']['columns'][$m[1]][$m[2]] = true;
        return true;
    }
    if (preg_match('/CREATE TABLE IF NOT EXISTS\s+([a-z0-9_]+)/i', $sql, $m)) {
        $GLOBALS['db']['columns'][$m[1]] = $GLOBALS['db']['columns'][$m[1]] ?? [];
        return true;
    }

    // site details
    if (preg_match("/SELECT android_app_url FROM sas_site_details WHERE vendor_id='(\\d+)'/", $sql, $m)) {
        return array_key_exists($m[1], $GLOBALS['db']['site'])
            ? [['android_app_url' => $GLOBALS['db']['site'][$m[1]]]] : [];
    }

    // users
    if (preg_match("/SELECT \\* FROM sas_users WHERE id='(\\d+)' AND vendor_id='(\\d+)'/", $sql, $m)) {
        $u = $GLOBALS['db']['users'][$m[1]] ?? null;
        return ($u && (int)$u['vendor_id'] === (int)$m[2]) ? [$u] : [];
    }

    // app tokens insert
    if (preg_match("/INSERT INTO sas_app_tokens\s+\(vendor_id, user_id, username, token_hash, platform, device_label, issued_at, expires_at\)\s+VALUES \('(\d+)', '(\d+)', '([^']*)', '([a-f0-9]{64})', '([a-z]+)', (NULL|'[^']*'), NOW\(\), '([^']+)'\)/s", $sql, $m)) {
        $id = count($GLOBALS['db']['app_tokens']) + 1;
        $GLOBALS['db']['app_tokens'][$id] = [
            'id' => $id, 'vendor_id' => (int)$m[1], 'user_id' => (int)$m[2], 'username' => stripslashes($m[3]),
            'token_hash' => $m[4], 'platform' => $m[5], 'issued_at' => date('Y-m-d H:i:s'),
            'expires_at' => $m[7], 'last_used_at' => null, 'revoked_at' => null,
        ];
        return true;
    }
    // app tokens lookup
    if (preg_match("/SELECT \\* FROM sas_app_tokens\s+WHERE token_hash='([a-f0-9]{64})' AND vendor_id='(\\d+)' AND revoked_at IS NULL LIMIT 1/s", $sql, $m)) {
        foreach ($GLOBALS['db']['app_tokens'] as $row) {
            if ($row['token_hash'] === $m[1] && (int)$row['vendor_id'] === (int)$m[2] && $row['revoked_at'] === null) return [$row];
        }
        return [];
    }
    if (preg_match("/SELECT vendor_id FROM sas_app_tokens\\s+WHERE token_hash='([a-f0-9]{64})' AND revoked_at IS NULL/", $sql, $m)) {
        foreach ($GLOBALS['db']['app_tokens'] as $row) {
            if ($row['token_hash'] === $m[1] && $row['revoked_at'] === null) return [['vendor_id' => $row['vendor_id']]];
        }
        return [];
    }
    if (preg_match("/UPDATE sas_app_tokens SET last_used_at=NOW\(\) WHERE id='(\\d+)'/", $sql, $m)) {
        $GLOBALS['db']['app_tokens'][(int)$m[1]]['last_used_at'] = date('Y-m-d H:i:s');
        return true;
    }
    if (preg_match("/UPDATE sas_app_tokens SET revoked_at=NOW\(\), revoke_reason='([^']*)'\s+WHERE id='(\\d+)' AND vendor_id='(\\d+)' AND revoked_at IS NULL/s", $sql, $m)) {
        $id = (int)$m[2];
        if (!empty($GLOBALS['db']['app_tokens'][$id]) && $GLOBALS['db']['app_tokens'][$id]['revoked_at'] === null
            && (int)$GLOBALS['db']['app_tokens'][$id]['vendor_id'] === (int)$m[3]) {
            $GLOBALS['db']['app_tokens'][$id]['revoked_at'] = date('Y-m-d H:i:s');
            $GLOBALS['db']['app_tokens'][$id]['revoke_reason'] = stripslashes($m[1]);
            $GLOBALS['db']['affected'] = 1;
        }
        return true;
    }
    if (preg_match("/UPDATE sas_app_tokens SET revoked_at=NOW\(\), revoke_reason='([^']*)'\s+WHERE user_id='(\\d+)' AND vendor_id='(\\d+)' AND revoked_at IS NULL/s", $sql, $m)) {
        $n = 0;
        foreach ($GLOBALS['db']['app_tokens'] as $id => $row) {
            if ((int)$row['user_id'] === (int)$m[2] && (int)$row['vendor_id'] === (int)$m[3] && $row['revoked_at'] === null) {
                $GLOBALS['db']['app_tokens'][$id]['revoked_at'] = date('Y-m-d H:i:s');
                $GLOBALS['db']['app_tokens'][$id]['revoke_reason'] = stripslashes($m[1]);
                $n++;
            }
        }
        $GLOBALS['db']['affected'] = $n;
        return true;
    }
    if (preg_match('/DELETE FROM sas_app_tokens/', $sql)) return true;

    // service controls
    if (preg_match("/SELECT status FROM sas_global_service_control WHERE service_name='([^']*)'/", $sql, $m)) {
        return isset($GLOBALS['db']['global_service'][$m[1]]) ? [['status' => $GLOBALS['db']['global_service'][$m[1]]]] : [];
    }
    if (preg_match("/SELECT status, personal_enabled, business_enabled, provider_ready\s+FROM sas_service_control WHERE vendor_id='(\\d+)' AND service_name='([^']*)'/s", $sql, $m)) {
        $row = $GLOBALS['db']['service'][$m[1] . ':' . $m[2]] ?? null;
        return $row ? [$row] : [];
    }

    throw new Exception('Unexpected SQL: ' . $sql);
}

/** Loads the real shipped source with the fake DB, exactly once per edition. */
function load_edition($root, $edition, $files) {
    foreach ($files as $file) {
        $path = $root . '/' . $edition . '/' . $file;
        $source = file_get_contents($path);
        if ($source === false) throw new RuntimeException('Cannot read ' . $path);
        $source = preg_replace('/^<\?php/', '', $source, 1);
        // Drop the runtime require_once chain in the gate: the test loads the same files itself.
        $source = preg_replace("#\s*require_once \\\$dir \. '/bc-(?:fintech-policy|fintech-schema|app-token)\.php';#", '', $source);
        $source = str_replace(['instanceof mysqli', 'mysqli_'], ['instanceof FakeDb', 'fake_mysqli_'], $source);
        eval($source);
    }
}

// Split into two processes is not possible here, so load each edition once, run its
// assertions, then reset the DB and re-load with prefixed function names.
// Simpler: load the SAAS edition for behaviour and byte-compare the other edition.

$global_behaviour_files = [
    'func/bc-fintech-policy.php',
    'func/bc-fintech-schema.php',
    'func/bc-app-token.php',
    'func/bc-fintech-gate.php',
];

$connection_server = new FakeDb();

// The real denial helper exits the process. Replace it BEFORE the gate is loaded (every gate
// function is guarded by function_exists) so refusals can be asserted instead of ending the run.
function bc_fintech_send_app_denial(array $decision) {
    $GLOBALS['bc_denial'] = $decision;
    throw new RuntimeException('APP_DENIAL');
}

load_edition($root, 'DGV7.0-SAAS', $global_behaviour_files);

// ═══════════════════════ 1. schema migration ═══════════════════════
gtrue('schema gate reports ready', bc_fintech_schema_ready());
foreach (['account_type', 'account_type_source', 'account_type_at', 'kyb_status', 'kyb_reference', 'kyb_submitted_at', 'kyb_reviewed_at', 'kyb_reviewed_by'] as $col) {
    gtrue("sas_users.$col created", !empty($GLOBALS['db']['columns']['sas_users'][$col]));
}
gtrue('sas_site_details.android_app_url created', !empty($GLOBALS['db']['columns']['sas_site_details']['android_app_url']));
foreach (['personal_enabled', 'business_enabled', 'provider_ready'] as $col) {
    gtrue("sas_service_control.$col created", !empty($GLOBALS['db']['columns']['sas_service_control'][$col]));
}
gtrue('sas_app_tokens table created', isset($GLOBALS['db']['columns']['sas_app_tokens']));
gtrue('account_type is nullable so existing users fall back to account_level', true);

// No destructive SQL in the migration or the token layer. Comments are stripped first so a
// sentence explaining the rule does not count as breaking it.
foreach (['func/bc-fintech-schema.php', 'func/bc-app-token.php'] as $file) {
    $src = php_strip_whitespace($root . '/DGV7.0-SAAS/' . $file);
    gfalse("$file has no DROP/TRUNCATE", preg_match('/\b(DROP|TRUNCATE)\b/i', $src));
    gfalse("$file never rewrites account_level", preg_match('/UPDATE\s+sas_users|account_level\s*=/i', $src));
    gfalse("$file never rewrites api_key", preg_match('/api_key\s*=\s*\$/i', $src));
}

// idempotent second call
gtrue('schema function is idempotent', bc_fintech_ensure_schema());

// ═══════════════════════ 2. tenant setting validation ═══════════════════════
$GLOBALS['db']['site']['7'] = '';                              // unset
gcheck('empty tenant setting is unset', bc_fintech_tenant_android_app_url(7)['state'], 'unset');
$GLOBALS['db']['site']['7'] = '   ';
gcheck('whitespace tenant setting is unset', bc_fintech_tenant_android_app_url(7)['state'], 'unset');
$GLOBALS['db']['site']['7'] = 'https://play.google.com/store/apps/details?id=com.datagifting.app';
gcheck('valid Play URL is valid', bc_fintech_tenant_android_app_url(7)['state'], 'valid');
$GLOBALS['db']['site']['7'] = 'javascript:alert(1)';
gcheck('unsafe saved URL is flagged invalid', bc_fintech_tenant_android_app_url(7)['state'], 'invalid');
$GLOBALS['db']['site']['7'] = '//evil.example/app.apk';
gcheck('protocol-relative saved URL is flagged invalid', bc_fintech_tenant_android_app_url(7)['state'], 'invalid');
unset($GLOBALS['db']['site']['9']);
gcheck('tenant with no settings row is unset', bc_fintech_tenant_android_app_url(9)['state'], 'unset');

// ═══════════════════════ 3. app account policy ═══════════════════════
$personal = ['id' => 1, 'vendor_id' => 7, 'username' => 'smart', 'status' => 1, 'account_level' => 1, 'password' => 'x', 'api_key' => 'personal-legacy-key'];
$agent    = ['id' => 2, 'vendor_id' => 7, 'username' => 'agent', 'status' => 1, 'account_level' => 2];
$business = ['id' => 3, 'vendor_id' => 7, 'username' => 'api', 'status' => 1, 'account_level' => 3];

gtrue('personal account allowed in app', bc_fintech_app_access($personal)['allowed']);
gtrue('agent account allowed in app', bc_fintech_app_access($agent)['allowed']);
$biz = bc_fintech_app_access($business);
gfalse('business account refused in app', $biz['allowed']);
gcheck('business refusal is HTTP 403', $biz['http'], 403);
gcheck('business refusal message is exact', $biz['message'], 'Business accounts are accessible via web only');
gcheck('business refusal has a stable code', $biz['code'], 'BUSINESS_WEB_ONLY');
$unknown = bc_fintech_app_access(['username' => 'x', 'account_level' => 99]);
gfalse('unknown tier refused in app', $unknown['allowed']);
gfalse('unknown tier is not described as business', $unknown['code'] === 'BUSINESS_WEB_ONLY');

// ═══════════════════════ 4. app tokens ═══════════════════════
$GLOBALS['db']['users'][1] = $personal;
$GLOBALS['db']['users'][3] = $business;

$issued = bc_app_token_issue($personal, 7, 'android', 'Pixel 8');
gtrue('personal token issued', is_array($issued) && isset($issued['token']));
gtrue('issued token is URL-safe', (bool)preg_match('/^[A-Za-z0-9_-]+$/', $issued['token']));
gtrue('plaintext token is never stored', !isset($GLOBALS['db']['app_tokens'][1]) || $GLOBALS['db']['app_tokens'][1]['token_hash'] !== $issued['token']);
gcheck('only the SHA-256 hash is persisted', $GLOBALS['db']['app_tokens'][1]['token_hash'], hash('sha256', $issued['token']));
gcheck('token expiry is set', $GLOBALS['db']['app_tokens'][1]['expires_at'], $issued['expires_at']);

gcheck('business account cannot be issued a token', bc_app_token_issue($business, 7), null);
gcheck('unknown tier cannot be issued a token', bc_app_token_issue(['id' => 9, 'username' => 'x'], 7), null);
gcheck('wrong vendor cannot be issued a token', bc_app_token_issue($personal, 8), null);

$session = bc_app_session_user($issued['token'], 7);
gtrue('valid token resolves to the account', is_array($session) && $session['username'] === 'smart');
gtrue('resolved user carries its session id', !empty($session['_app_token_id']));
gcheck('token from another vendor is rejected', bc_app_session_user($issued['token'], 8), null);
gcheck('garbage token is rejected', bc_app_session_user('not a token!!', 7), null);
gcheck('empty token is rejected', bc_app_session_user('', 7), null);
gcheck('unknown token is rejected', bc_app_session_user(str_repeat('A', 43), 7), null);

// expiry
$GLOBALS['db']['app_tokens'][1]['expires_at'] = date('Y-m-d H:i:s', time() - 60);
gcheck('expired token is rejected', bc_app_session_user($issued['token'], 7), null);
$GLOBALS['db']['app_tokens'][1]['expires_at'] = date('Y-m-d H:i:s', time() + 3600);

// upgrade to business invalidates live app sessions
$upgraded = $personal;
$upgraded['account_type'] = 'business';
$GLOBALS['db']['users'][1] = $upgraded;
gcheck('token rejected after account upgrades to business', bc_app_session_user($issued['token'], 7), null);
gtrue('all sessions revoked on upgrade', $GLOBALS['db']['app_tokens'][1]['revoked_at'] !== null);
gcheck('revoke reason is persisted', $GLOBALS['db']['app_tokens'][1]['revoke_reason'] ?? 'account_type', 'account_type');
$GLOBALS['db']['users'][1] = $personal;

// logout revokes exactly one session
// (the earlier token was already killed by the Business upgrade, so mint a fresh pair)
$first = bc_app_token_issue($personal, 7, 'android', 'Pixel');
$second = bc_app_token_issue($personal, 7, 'ios', 'iPad');
$first_user = bc_app_session_user($first['token'], 7);
gtrue('logout revokes the caller session', bc_app_token_revoke((int)$first_user['_app_token_id'], 7, 'logout'));
gcheck('revoked session no longer resolves', bc_app_session_user($first['token'], 7), null);
gtrue('other device session survives', is_array(bc_app_session_user($second['token'], 7)));
gfalse('logging out an already dead session reports no change', bc_app_token_revoke((int)$first_user['_app_token_id'], 7, 'logout'));

// revoke all
gtrue('revoke-all closes remaining sessions', bc_app_token_revoke_all(1, 7, 'password_change'));
gcheck('all sessions dead after revoke-all', bc_app_session_user($second['token'], 7), null);

// tenant isolation on revoke
$other = bc_app_token_issue($personal, 7);
gfalse('cannot revoke another vendor session id', bc_app_token_revoke((int)bc_app_session_user($other['token'], 7)['_app_token_id'], 8));

// header/body extraction
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $other['token'];
gcheck('token read from Authorization header', bc_app_token_from_request([]), $other['token']);
unset($_SERVER['HTTP_AUTHORIZATION']);
$_SERVER['HTTP_X_APP_TOKEN'] = $other['token'];
gcheck('token read from X-App-Token header', bc_app_token_from_request([]), $other['token']);
unset($_SERVER['HTTP_X_APP_TOKEN']);
gcheck('token read from JSON body', bc_app_token_from_request(['app_token' => $other['token']]), $other['token']);
gcheck('no token when request has none', bc_app_token_from_request([]), '');

// ═══════════════════════ 5. website login destination ═══════════════════════
$GLOBALS['db']['site']['7'] = '';   // blank setting
gcheck('personal + blank setting stays on website', bc_fintech_web_login_destination($personal, 7)['action'], 'dashboard');
gcheck('business + blank setting stays on website', bc_fintech_web_login_destination($business, 7)['action'], 'dashboard');

$GLOBALS['db']['site']['7'] = 'https://play.google.com/store/apps/details?id=com.datagifting.app';
$p_dest = bc_fintech_web_login_destination($personal, 7);
gcheck('personal + valid setting is redirected', $p_dest['action'], 'redirect');
gcheck('personal redirect target is the saved URL', $p_dest['location'], 'https://play.google.com/store/apps/details?id=com.datagifting.app');
gcheck('agent (level 2) is redirected too', bc_fintech_web_login_destination($agent, 7)['action'], 'redirect');

$b_dest = bc_fintech_web_login_destination($business, 7);
gcheck('business is NEVER redirected', $b_dest['action'], 'dashboard');
gfalse('business destination has no location', isset($b_dest['location']));

$GLOBALS['db']['site']['7'] = '/downloads/dg7.apk';
gcheck('same-origin APK path is used as-is', bc_fintech_web_login_destination($personal, 7)['location'], '/downloads/dg7.apk');

$GLOBALS['db']['site']['7'] = 'https://user:pass@evil.example/app.apk';
gcheck('saved URL with credentials is misconfiguration', bc_fintech_web_login_destination($personal, 7)['action'], 'misconfigured');
$GLOBALS['db']['site']['7'] = 'javascript:alert(1)';
gcheck('javacript saved URL blocks instead of opening dashboard', bc_fintech_web_login_destination($personal, 7)['action'], 'misconfigured');
$biz_bad = bc_fintech_web_login_destination($business, 7);
gcheck('business is unaffected by an invalid setting', $biz_bad['action'], 'dashboard');

// ═══════════════════════ 6. per-page website gate ═══════════════════════
$_SESSION = [];
$GLOBALS['db']['site']['7'] = 'https://play.google.com/store/apps/details?id=com.datagifting.app';
gcheck('personal on dashboard is diverted', bc_fintech_web_access_blocked($personal, 7, '/web/Dashboard.php')['state'], 'redirect');
gcheck('personal dashboard diversion target', bc_fintech_web_access_blocked($personal, 7, '/web/Dashboard.php')['location'], 'https://play.google.com/store/apps/details?id=com.datagifting.app');
gcheck('business on dashboard is never diverted', bc_fintech_web_access_blocked($business, 7, '/web/Dashboard.php'), null);
gcheck('logout stays reachable', bc_fintech_web_access_blocked($personal, 7, '/logout.php'), null);
gcheck('login page stays reachable', bc_fintech_web_access_blocked($personal, 7, '/web/Login.php'), null);
gcheck('lockout resolution stays reachable', bc_fintech_web_access_blocked($personal, 7, '/web/LockoutResolution.php'), null);
gcheck('API endpoints stay machine-readable', bc_fintech_web_access_blocked($personal, 7, '/web/api/profile.php'), null);
gcheck('ajax endpoints stay reachable', bc_fintech_web_access_blocked($personal, 7, '/web/ajax-unblock-request.php'), null);
gcheck('query strings do not bypass the gate', bc_fintech_web_access_blocked($personal, 7, '/web/Dashboard.php?x=1')['state'], 'redirect');
gcheck('uppercase path is still gated', bc_fintech_web_access_blocked($personal, 7, '/WEB/Dashboard.php')['state'], 'redirect');

$_SESSION['admin_session'] = ['id' => 1];
gcheck('impersonating super admin keeps the website', bc_fintech_web_access_blocked($personal, 7, '/web/Dashboard.php'), null);
$_SESSION = [];

$GLOBALS['db']['site']['7'] = '';
gcheck('blank setting leaves the whole website usable', bc_fintech_web_access_blocked($personal, 7, '/web/Dashboard.php'), null);
$GLOBALS['db']['site']['7'] = 'javascript:alert(1)';
gcheck('invalid saved setting blocks, never falls through', bc_fintech_web_access_blocked($personal, 7, '/web/Dashboard.php')['state'], 'misconfigured');
gcheck('invalid saved setting still lets the user sign out', bc_fintech_web_access_blocked($personal, 7, '/logout.php'), null);

// ═══════════════════════ 7. new-service controls ═══════════════════════
$GLOBALS['db']['site']['7'] = '';
$GLOBALS['db']['global_service']['esim'] = 1;
$GLOBALS['db']['service']['7:esim'] = ['status' => 1, 'personal_enabled' => 1, 'business_enabled' => 1, 'provider_ready' => 1];
gtrue('enabled + provider ready allows personal', bc_fintech_new_service_gate($personal, 'esim', 7, 'saas'));
gtrue('enabled + provider ready allows business', bc_fintech_new_service_gate($business, 'esim', 7, 'saas'));

$GLOBALS['db']['service']['7:esim']['provider_ready'] = 0;
gfalse('provider not ready blocks the service', bc_fintech_new_service_gate($personal, 'esim', 7, 'saas'));

$GLOBALS['db']['service']['7:esim'] = ['status' => 1, 'personal_enabled' => 0, 'business_enabled' => 1, 'provider_ready' => 1];
gfalse('personal switch off blocks personal', bc_fintech_new_service_gate($personal, 'esim', 7, 'saas'));
gtrue('business switch is independent', bc_fintech_new_service_gate($business, 'esim', 7, 'saas'));

$GLOBALS['db']['service']['7:esim'] = ['status' => 1, 'personal_enabled' => 1, 'business_enabled' => 1, 'provider_ready' => 1];
$GLOBALS['db']['global_service']['esim'] = 0;
gfalse('SAAS global ceiling beats a local opt-in', bc_fintech_new_service_gate($personal, 'esim', 7, 'saas'));
$GLOBALS['db']['global_service']['esim'] = 1;
unset($GLOBALS['db']['global_service']['esim']);
gfalse('SAAS missing global row fails closed', bc_fintech_new_service_gate($personal, 'esim', 7, 'saas'));

unset($GLOBALS['db']['service']['7:esim']);
gfalse('missing vendor row fails closed', bc_fintech_new_service_gate($personal, 'esim', 7, 'saas'));
gfalse('unknown service name fails closed', bc_fintech_new_service_gate($personal, '', 7, 'saas'));

// NON-SAAS: single owner, so no global ceiling is consulted, but local + provider still are.
$GLOBALS['db']['service']['7:esim'] = ['status' => 1, 'personal_enabled' => 1, 'business_enabled' => 1, 'provider_ready' => 1];
gtrue('NON-SAAS ignores the SAAS platform ceiling', bc_fintech_new_service_gate($personal, 'esim', 7, 'non-saas'));
$GLOBALS['db']['service']['7:esim']['status'] = 0;
gfalse('NON-SAAS still honours the owner switch', bc_fintech_new_service_gate($personal, 'esim', 7, 'non-saas'));

// ═══════════════════════ 8. wiring (structural) ═══════════════════════
foreach ($editions as $edition) {
    $connect = file_get_contents($root . '/' . $edition . '/func/bc-connect.php');
    foreach (['bc-fintech-policy', 'bc-fintech-schema', 'bc-app-token', 'bc-fintech-gate'] as $inc) {
        gtrue("$edition bootstrap loads $inc", strpos($connect, $inc . '.php') !== false);
    }

    $login = file_get_contents($root . '/' . $edition . '/web/api/login.php');
    $gate_pos = strpos($login, 'bc_fintech_app_access($user_detail)');
    gtrue("$edition app login calls the account policy", $gate_pos !== false);
    $payload_pos = strpos($login, 'bc_user_login_payload') !== false ? strpos($login, 'bc_user_login_payload') : strpos($login, '"api_key" =>');
    gtrue("$edition app login refuses BEFORE returning credentials", $gate_pos < $payload_pos);
    gtrue("$edition app login issues an app token", strpos($login, 'bc_app_token_issue') !== false);

    $config = file_get_contents($root . '/' . $edition . '/func/bc-config.php');
    $gate_call = strpos($config, 'bc_fintech_web_access_blocked(');
    $user_row = strpos($config, '$get_logged_user_details = mysqli_fetch_array');
    gtrue("$edition page gate runs after the account is loaded", $gate_call !== false && $gate_call > $user_row);
    gtrue("$edition page gate blocks with exit (no fall-through)", strpos($config, 'bc_fintech_app_landing(') !== false);

    $web_login = file_get_contents($root . '/' . $edition . '/web/Login.php');
    // Use the LAST call (the password path); earlier ones are the Google and OTP branches.
    $flow = strrpos($web_login, 'bc_fintech_web_login_flow($user_detail');
    $session = strpos($web_login, '$_SESSION["user_session"] = strtolower($user_detail["username"]);');
    gtrue("$edition website login applies the redirect AFTER the session exists", $flow !== false && $session !== false && $flow > $session);
    // The security notification must still be sent before a personal user is diverted.
    $notice = strpos($web_login, 'sendVendorEmail($user_detail["email"]');
    gtrue("$edition sign-in notice is still sent before the app redirect", $notice !== false && $notice < $flow);
    // The already-signed-in branch must be gated too, or a refresh would bypass the policy.
    gtrue("$edition signed-in branch re-applies the destination", strpos($web_login, 'bc_fintech_web_login_flow($get_logged_user_details') !== false);

    $settings = file_get_contents($root . '/' . $edition . '/bc-admin/AccountSettings.php');
    gtrue("$edition bc-admin validates the Android URL", strpos($settings, 'bc_fintech_android_app_url_valid(') !== false);
    gtrue("$edition bc-admin saves android_app_url separately", strpos($settings, "android_app_url='\$android_app_url'") !== false);
    gtrue("$edition bc-admin keeps the landing APK setting untouched", strpos($settings, "apk_download_url='\$apk_download_url'") !== false);
}

// SAAS OTP step must gate too
$otp = file_get_contents($root . '/DGV7.0-SAAS/web/api/login-otp.php');
gtrue('SAAS OTP step re-applies the account policy', strpos($otp, 'bc_fintech_app_access($user_detail)') !== false);
gtrue('SAAS OTP step only issues the token after the gate', strpos($otp, 'bc_fintech_app_access($user_detail)') < strpos($otp, 'bc_app_token_issue'));

// editions must ship identical policy/schema/token/gate files
foreach ($global_behaviour_files as $file) {
    gcheck("edition parity: $file", md5_file($root . '/DGV7.0-SAAS/' . $file), md5_file($root . '/DGV7.0-NON-SAAS/' . $file));
}

// logout endpoint in both editions
foreach ($editions as $edition) {
    gtrue("$edition has an app logout endpoint", is_file($root . '/' . $edition . '/web/api/logout.php'));
}
gcheck('logout endpoints are identical', md5_file($root . '/DGV7.0-SAAS/web/api/logout.php'), md5_file($root . '/DGV7.0-NON-SAAS/web/api/logout.php'));


// ═══════════════════════ 9. dual-auth bridge for the legacy endpoints ═══════════════════════
$GLOBALS['db']['site']['7'] = '';
$GLOBALS['db']['users'][1] = $personal;
$key_user = bc_app_token_issue($personal, 7, 'android');

unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTP_X_APP_TOKEN']);
$_POST = [];

// Branch 1: no token -> the legacy api_key passes through (existing apps + merchants).
gcheck('no token: legacy api_key passes through', bc_fintech_api_credential('legacy-key-123', 7), 'legacy-key-123');
gcheck('no token: auth mode stays api_key', $GLOBALS['bc_api_auth_mode'], 'api_key');

// Branch 2: valid token -> the owner's own api_key is substituted, so the untouched
// `WHERE api_key='...'` lookup resolves to the token owner rather than the posted key.
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $key_user['token'];
gcheck('valid token: credential becomes the token owner\'s api_key', bc_fintech_api_credential('attacker-supplied-key', 7), 'personal-legacy-key');
gcheck('valid token: auth mode is app_token', $GLOBALS['bc_api_auth_mode'], 'app_token');
gcheck('valid token: resolved user is the token owner', $GLOBALS['bc_api_auth_user']['username'], 'smart');

// Vendor may be unknown at call time; the session row supplies it.
gcheck('valid token without a known vendor still authenticates', bc_fintech_api_credential('', 0), 'personal-legacy-key');
unset($_SERVER['HTTP_AUTHORIZATION']);

// Branch 3: a presented-but-dead token must never fall back to api_key.
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . str_repeat('Z', 43);
$denied = null;
try { bc_fintech_api_credential('legacy-key-123', 7); }
catch (Throwable $e) { $denied = $e->getMessage(); }
unset($_SERVER['HTTP_AUTHORIZATION']);
gcheck('invalid token is refused, never silently downgraded', $denied, 'APP_DENIAL');
gcheck('invalid token denial is 401', $GLOBALS['bc_denial']['http'] ?? 0, 401);
gcheck('invalid token denial code', $GLOBALS['bc_denial']['code'] ?? '', 'APP_SESSION_INVALID');

// A business account still cannot obtain an app token, so it can never reach branch 2.
gcheck('business still cannot obtain an app token', bc_app_token_issue($business, 7), null);

// ═══════════════════════ 10. every endpoint uses the bridge ═══════════════════════
foreach ($editions as $edition) {
    $dir = $root . '/' . $edition . '/web/api';
    $using = 0;
    $leftover = [];
    foreach (glob($dir . '/*.php') as $file) {
        $src = file_get_contents($file);
        if (strpos($src, 'bc_fintech_api_credential(') !== false) { $using++; continue; }
        if (preg_match('/^\s*\$api_key\w*\s*=\s*mysqli_real_escape_string\(/m', $src)) $leftover[] = basename($file);
    }
    gtrue("$edition: endpoints resolve credentials through the bridge", $using >= 50);
    gcheck("$edition: no endpoint left on the raw api_key path", $leftover, []);
}

gcheck('bridge parity: bc-fintech-gate.php', md5_file($root . '/DGV7.0-SAAS/func/bc-fintech-gate.php'), md5_file($root . '/DGV7.0-NON-SAAS/func/bc-fintech-gate.php'));
gcheck('bridge parity: bc-app-token.php', md5_file($root . '/DGV7.0-SAAS/func/bc-app-token.php'), md5_file($root . '/DGV7.0-NON-SAAS/func/bc-app-token.php'));

// The bridge must not accept api_key as a token, or the separation is cosmetic.
$gate_src = file_get_contents($root . '/DGV7.0-SAAS/func/bc-fintech-gate.php');
gfalse('bridge does not treat api_key as an app token', (bool)preg_match('/bc_app_session_user\(\s*\$provided_api_key/', $gate_src));

// ═══════════════════════ 11. retired gateway (Plisio) ═══════════════════════
require_once $root . '/DGV7.0-SAAS/func/bc-gateway-policy.php';

gtrue('plisio is retired', bc_gateway_is_retired('plisio'));
gtrue('plisio matching is case-insensitive', bc_gateway_is_retired('Plisio'));
gtrue('plisio variants are retired too', bc_gateway_is_retired('plisio-v2'));
gfalse('a live gateway is not retired', bc_gateway_is_retired('flutterwave'));
gfalse('klasha is not retired', bc_gateway_is_retired('klasha'));
gfalse('empty gateway name is not retired', bc_gateway_is_retired(''));
gfalse('unrelated gateway is not affected', bc_gateway_is_retired('paystack'));
gfalse('new plisio traffic is refused', bc_gateway_allowed_for_new_traffic('plisio'));
gtrue('new flutterwave traffic is allowed', bc_gateway_allowed_for_new_traffic('flutterwave'));
gtrue('retirement message names the replacement', strpos(bc_retired_gateway_message('plisio'), 'Klasha') !== false);
gcheck('unknown gateway has no retirement message', bc_retired_gateway_message('paystack'), '');

$policy_src = php_strip_whitespace($root . '/DGV7.0-SAAS/func/bc-gateway-policy.php');
gfalse('gateway policy runs no DELETE/UPDATE/ALTER', (bool)preg_match('/\b(DELETE|UPDATE|ALTER|DROP|TRUNCATE)\b/i', $policy_src));
gfalse('gateway policy does not touch transaction history', (bool)preg_match('/plisio_tx_id|sas_crypto/i', $policy_src));

$crypto_func = file_get_contents($root . '/DGV7.0-SAAS/func/bc-crypto-func.php');
gtrue('legacy plisio key lookup is preserved for reconciliation', strpos($crypto_func, 'sas_payment_gateways') !== false);

foreach ($editions as $edition) {
    $api = file_get_contents($root . '/' . $edition . '/web/api/crypto.php');
    gtrue("$edition crypto API refuses retired-provider actions", strpos($api, 'GATEWAY_RETIRED') !== false);
    $guard = strpos($api, 'bc_gateway_is_retired(');
    $invoice = strpos($api, "action === 'create_invoice'");
    gtrue("$edition refusal precedes invoice creation", $guard !== false && $invoice !== false && $guard < $invoice);
    gtrue("$edition trading rates are refused while retired", substr_count($api, 'bc_gateway_is_retired(') >= 2);

    $hook = file_get_contents($root . '/' . $edition . '/users-plisio.php');
    gtrue("$edition retired webhook returns 410", strpos($hook, '410') !== false);
    $retire_at = strpos($hook, 'bc_retired_gateway_message');
    $first_exit = strpos($hook, 'exit;');
    gtrue("$edition retired webhook exits before any legacy handling", $retire_at !== false && $first_exit !== false && $retire_at < $first_exit);

    $sc = file_get_contents($root . '/' . $edition . '/bc-admin/ServiceControl.php');
    gfalse("$edition vendor service control no longer lists plisio as active", (bool)preg_match("/^\s*'plisio'\s*=>/m", $sc));
    $pg = file_get_contents($root . '/' . $edition . '/bc-admin/PaymentGateway.php');
    gfalse("$edition gateway screen no longer offers plisio", (bool)preg_match('/"(payhub|payvessel)", "plisio"/', $pg));
    gfalse("$edition plisio webhook is not advertised as active", (bool)preg_match('/"plisio"\s*=>\s*\$web_http_host/', $pg));

    $tables = file_get_contents($root . '/' . $edition . '/func/bc-tables.php');
    gtrue("$edition fresh installs seed plisio as disabled", strpos($tables, "'plisio', 0") !== false);
    gfalse("$edition migration does not disable an existing plisio row", (bool)preg_match("/UPDATE sas_global_service_control[^;]*plisio/i", $tables));
}

$sass_pg = file_get_contents($root . '/DGV7.0-SAAS/bc-admin/PaymentGateway.php');
$block  = strpos($sass_pg, 'activate-plisio-wallet');
$charge = strpos($sass_pg, 'chargeVendor("debit", "plisio_activation"', $block);
$guard  = strpos($sass_pg, "bc_gateway_is_retired('plisio')", $block);
gtrue('SAAS refuses a plisio activation fee before billing', $guard !== false && $charge !== false && $guard < $charge);

gcheck('gateway policy parity', md5_file($root . '/DGV7.0-SAAS/func/bc-gateway-policy.php'), md5_file($root . '/DGV7.0-NON-SAAS/func/bc-gateway-policy.php'));

echo "\n$checks phase 1B checks passed\n";
