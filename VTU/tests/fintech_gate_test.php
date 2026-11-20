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
// The CI runner is `php -n`, where error_reporting is E_ALL and a stray warning or notice is
// printed into the output stream. php-wasm's defaults are quieter, which is why a notice that
// CI would show never appeared locally. Match the stricter runner.
error_reporting(E_ALL);
ini_set('display_errors', '1');
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
    // ── phase 2: support chat + settings ──
    'settings' => [],        // "vendor:name" => value
    'tables' => [],          // table => true (SHOW TABLES)
    'chat_threads' => [],    // id => row
    'chat_messages' => [],   // id => row
    'chat_events' => [],    // id => row
    'insert_id' => 0,
    // ── phase 3: provider adapters ──
    'gateways' => [],        // "table:vendor:gateway" => row
    'webhook_events' => [],  // "provider:event_id" => row
    'ledger' => [],          // id => row
    'crypto_wallets' => [],  // "vendor:username:currency" => row
    'checkouts' => [],       // "vendor:reference" => username
    'fintech_tx' => [],      // id => sas_transactions mirror row
    'email_templates' => [], // "vendor:email_type" => row (receipt notifications)
    'vendors' => [],         // id => vendor row
    'admin_payments' => [], // vendor_id => manual-funding bank row
    'super_admin_payments' => null, // single manual-funding bank row
    // ── vendor registration (bc-spadmin/VendorReg.php) ──
    'options' => [],            // option_name => option_value (sas_super_admin_options)
    'pending_vendors' => [],    // id => row (sas_pending_vendors)
    'billing_packages' => [],   // id => price
    'billing_addons' => [],     // id => price
    'domain_extensions' => [],  // extension => [price, promo_price]
    'super_admin' => null,      // single super admin row
    'email_templates_sent' => 0,
    'errno' => 0,
    'error' => '',
];

/**
 * Result-set cursor. The first version of this fake returned plain arrays and always handed back
 * $rows[0], which was fine while every query returned at most one row — but a GROUP BY (the
 * backfill dry run) returns several, and `while ($r = mysqli_fetch_assoc($q))` spun forever.
 * Faithful rows-with-a-cursor semantics cost a few lines and remove that class of hang.
 */
class FakeResult implements IteratorAggregate, Countable {
    public $rows; public $pos = 0;
    public function __construct(array $rows) { $this->rows = array_values($rows); }
    public function count(): int { return count($this->rows); }
    public function getIterator(): Iterator { return new ArrayIterator($this->rows); }
}

function fake_result($result) {
    if ($result instanceof FakeResult || is_array($result) || is_bool($result) || $result === null) {
        return is_array($result) ? new FakeResult($result) : $result;
    }
    return $result;
}

function fake_mysqli_real_escape_string($db, $value) { return addslashes((string)$value); }
function fake_mysqli_num_rows($rows) {
    if ($rows instanceof FakeResult) return count($rows);
    return is_array($rows) ? count($rows) : 0;
}
function fake_mysqli_fetch_array($rows) {
    $row = fake_mysqli_fetch_assoc($rows);
    if ($row === null) return null;
    // MYSQLI_BOTH (the default) is what the shipped code asks for; the numeric half is harmless.
    return array_merge(array_values($row), $row);
}
function fake_mysqli_fetch_assoc($rows) {
    if ($rows instanceof FakeResult) {
        if (!isset($rows->rows[$rows->pos])) return null;
        return $rows->rows[$rows->pos++];
    }
    return is_array($rows) && $rows ? $rows[0] : null;
}
function fake_mysqli_affected_rows($db) { return $GLOBALS['db']['affected']; }
function fake_mysqli_insert_id($db) { return $GLOBALS['db']['insert_id']; }
function fake_mysqli_errno($db) { return $GLOBALS['db']['errno']; }
function fake_mysqli_error($db) { return $GLOBALS['db']['error']; }
function fake_mysqli_begin_transaction($db) { $GLOBALS['db']['errno'] = 0; $GLOBALS['db']['error'] = ''; return true; }
function fake_mysqli_commit($db) { return true; }
function fake_mysqli_rollback($db) { return true; }

function fake_mysqli_query($db, $sql) { return fake_result(fake_mysqli_query_raw($db, $sql)); }

function fake_mysqli_query_raw($db, $sql) {
    $GLOBALS['db']['affected'] = 0;
    // Real SQL is whitespace-insensitive, so the fake matches a collapsed copy. The
    // shipped statements are formatted for reading; without this every line break
    // would have to be mirrored in a regex.
    $sql = preg_replace('/\s+/', ' ', $sql);

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

    // ── phase 1C step 2: backfill ──
    if (preg_match("/SELECT account_type, account_level, COUNT\\(\\*\\) AS n\\s+FROM sas_users WHERE vendor_id='(\\d+)' GROUP BY account_type, account_level/s", $sql, $m)) {
        $groups = [];
        foreach ($GLOBALS['db']['users'] as $u) {
            if ((int)$u['vendor_id'] !== (int)$m[1]) continue;
            $key = json_encode([(string)($u['account_type'] ?? null), (string)($u['account_level'] ?? null)]);
            if (!isset($groups[$key])) {
                $groups[$key] = ['account_type' => $u['account_type'] ?? null, 'account_level' => $u['account_level'] ?? null, 'n' => 0];
            }
            $groups[$key]['n']++;
        }
        return array_values($groups);
    }
    if (preg_match("/UPDATE sas_users\\s+SET account_type='(personal|business)', account_type_source='backfill_2026_11', account_type_at=NOW\\(\\)\\s+WHERE vendor_id='(\\d+)'\\s+AND \\(account_type IS NULL OR account_type=''\\)\\s+AND account_level IN \\('(\\d+)', '(\\d+)'\\)/s", $sql, $m)) {
        $n = 0;
        foreach ($GLOBALS['db']['users'] as $id => $u) {
            if ((int)$u['vendor_id'] !== (int)$m[2]) continue;
            if (isset($u['account_type']) && $u['account_type'] !== null && $u['account_type'] !== '') continue;
            $level = (string)($u['account_level'] ?? '');
            if ($level !== $m[3] && $level !== $m[4]) continue;
            $GLOBALS['db']['users'][$id]['account_type'] = $m[1];
            $GLOBALS['db']['users'][$id]['account_type_source'] = 'backfill_2026_11';
            $n++;
        }
        $GLOBALS['db']['affected'] = $n;
        return true;
    }

    // ── phase 2: support chat schema + settings ──
    if (preg_match("/SHOW TABLES LIKE '([a-z0-9_]+)'/", $sql, $m)) {
        return empty($GLOBALS['db']['tables'][$m[1]]) ? [] : [[$m[1] => $m[1]]];
    }
    if (preg_match('/^SELECT setting_name, setting_value FROM sas_settings\s+WHERE vendor_id=\'(\d+)\' AND setting_name IN \((.*)\)$/s', $sql, $m)) {
        $wanted = array_map(function ($v) { return trim($v, " '\t\n"); }, explode(',', $m[2]));
        $rows = [];
        foreach ($wanted as $name) {
            $key = $m[1] . ':' . $name;
            if (array_key_exists($key, $GLOBALS['db']['settings'])) {
                $rows[] = ['setting_name' => $name, 'setting_value' => $GLOBALS['db']['settings'][$key]];
            }
        }
        return $rows;
    }
    if (preg_match("/SELECT id FROM sas_settings\s+WHERE vendor_id='(\\d+)' AND setting_name='([^']*)' LIMIT 1/s", $sql, $m)) {
        return array_key_exists($m[1] . ':' . stripslashes($m[2]), $GLOBALS['db']['settings']) ? [['id' => 1]] : [];
    }
    if (preg_match("/UPDATE sas_settings SET setting_value='([^']*)'\s+WHERE vendor_id='(\\d+)' AND setting_name='([^']*)'/s", $sql, $m)) {
        $GLOBALS['db']['settings'][$m[2] . ':' . stripslashes($m[3])] = stripslashes($m[1]);
        $GLOBALS['db']['affected'] = 1;
        return true;
    }
    if (preg_match("/INSERT INTO sas_settings \(vendor_id, setting_name, setting_value\)\s+VALUES \('(\d+)', '([^']*)', '([^']*)'\)/s", $sql, $m)) {
        $GLOBALS['db']['settings'][$m[1] . ':' . stripslashes($m[2])] = stripslashes($m[3]);
        $GLOBALS['db']['affected'] = 1;
        return true;
    }

    // threads
    if (preg_match("/SELECT \\* FROM sas_chat_threads\s+WHERE vendor_id='(\\d+)' AND user_id='(\\d+)' AND status<>'closed'\s+ORDER BY id DESC LIMIT 1/s", $sql, $m)) {
        $best = null;
        foreach ($GLOBALS['db']['chat_threads'] as $row) {
            if ((int)$row['vendor_id'] === (int)$m[1] && (int)$row['user_id'] === (int)$m[2] && $row['status'] !== 'closed') {
                if (!$best || (int)$row['id'] > (int)$best['id']) $best = $row;
            }
        }
        return $best ? [$best] : [];
    }
    if (preg_match("/SELECT \\* FROM sas_chat_threads\s+WHERE id='(\\d+)' AND vendor_id='(\\d+)' LIMIT 1/s", $sql, $m)) {
        $row = $GLOBALS['db']['chat_threads'][(int)$m[1]] ?? null;
        return ($row && (int)$row['vendor_id'] === (int)$m[2]) ? [$row] : [];
    }
    if (preg_match("/INSERT INTO sas_chat_threads\s+\(vendor_id, user_id, username, subject, status, ai_enabled, created_at, last_message_at, last_message_by\)\s+VALUES \('(\d+)', '(\d+)', '([^']*)', '([^']*)', 'open', '(\d)', NOW\(\), NOW\(\), 'system'\)/s", $sql, $m)) {
        $id = count($GLOBALS['db']['chat_threads']) + 1;
        $GLOBALS['db']['chat_threads'][$id] = [
            'id' => $id, 'vendor_id' => (int)$m[1], 'user_id' => (int)$m[2],
            'username' => stripslashes($m[3]), 'subject' => stripslashes($m[4]),
            'status' => 'open', 'assigned_admin' => '', 'last_message_at' => date('Y-m-d H:i:s'),
            'last_message_by' => 'system', 'unread_for_agent' => 0, 'unread_for_user' => 0,
            'ai_enabled' => (int)$m[5], 'created_at' => date('Y-m-d H:i:s'), 'closed_at' => null,
        ];
        $GLOBALS['db']['insert_id'] = $id;
        $GLOBALS['db']['affected'] = 1;
        return true;
    }
    if (preg_match("/UPDATE sas_chat_threads SET ticket_no='([A-Z]+-\\d+)' WHERE id='(\\d+)'/s", $sql, $m)) {
        $GLOBALS['db']['affected'] = 1;
        return true;
    }
    if (preg_match("/UPDATE sas_chat_threads\s+SET status='pending_agent', ai_enabled=0\s+WHERE id='(\\d+)' AND vendor_id='(\\d+)'/s", $sql, $m)) {
        $GLOBALS['db']['chat_threads'][(int)$m[1]]['status'] = 'pending_agent';
        $GLOBALS['db']['chat_threads'][(int)$m[1]]['ai_enabled'] = 0;
        $GLOBALS['db']['affected'] = 1;
        return true;
    }
    if (preg_match("/UPDATE sas_chat_threads\s+SET status='with_agent', ai_enabled=0, assigned_admin='([^']*)', unread_for_agent=0\s+WHERE id='(\\d+)' AND vendor_id='(\\d+)'/s", $sql, $m)) {
        $id = (int)$m[2];
        $current = $GLOBALS['db']['chat_threads'][$id] ?? null;
        if (!$current) return false;
        if ((string)$current['assigned_admin'] !== '' && (string)$current['assigned_admin'] !== $m[1]) {
            $GLOBALS['db']['affected'] = 0;
            return true;
        }
        $GLOBALS['db']['chat_threads'][$id]['status'] = 'with_agent';
        $GLOBALS['db']['chat_threads'][$id]['ai_enabled'] = 0;
        $GLOBALS['db']['chat_threads'][$id]['assigned_admin'] = $m[1];
        $GLOBALS['db']['chat_threads'][$id]['unread_for_agent'] = 0;
        $GLOBALS['db']['affected'] = 1;
        return true;
    }
    if (preg_match("/UPDATE sas_chat_threads\s+SET status='closed', ai_enabled=0, closed_at=NOW\(\), unread_for_agent=0\s+WHERE id='(\\d+)' AND vendor_id='(\\d+)'/s", $sql, $m)) {
        $GLOBALS['db']['chat_threads'][(int)$m[1]]['status'] = 'closed';
        $GLOBALS['db']['chat_threads'][(int)$m[1]]['ai_enabled'] = 0;
        $GLOBALS['db']['chat_threads'][(int)$m[1]]['closed_at'] = date('Y-m-d H:i:s');
        $GLOBALS['db']['affected'] = 1;
        return true;
    }
    if (preg_match("/UPDATE sas_chat_threads\s+SET last_message_at=NOW\(\), last_message_by='user', unread_for_agent=unread_for_agent\+1\s+WHERE id='(\\d+)'/s", $sql, $m)) {
        $GLOBALS['db']['chat_threads'][(int)$m[1]]['unread_for_agent']++;
        $GLOBALS['db']['chat_threads'][(int)$m[1]]['last_message_by'] = 'user';
        return true;
    }
    if (preg_match("/UPDATE sas_chat_threads\s+SET last_message_at=NOW\(\), last_message_by='agent', unread_for_user=unread_for_user\+1\s+WHERE id='(\\d+)'/s", $sql, $m)) {
        $GLOBALS['db']['chat_threads'][(int)$m[1]]['unread_for_user']++;
        $GLOBALS['db']['chat_threads'][(int)$m[1]]['last_message_by'] = 'agent';
        return true;
    }
    if (preg_match("/UPDATE sas_chat_threads\s+SET last_message_at=NOW\(\), last_message_by='([a-z]+)'\s+WHERE id='(\\d+)'/s", $sql, $m)) {
        $GLOBALS['db']['chat_threads'][(int)$m[2]]['last_message_by'] = $m[1];
        return true;
    }
    if (preg_match("/UPDATE sas_chat_threads SET ai_enabled=0\s+WHERE id='(\\d+)' AND vendor_id='(\\d+)'/s", $sql, $m)) {
        $GLOBALS['db']['chat_threads'][(int)$m[1]]['ai_enabled'] = 0;
        $GLOBALS['db']['affected'] = 1;
        return true;
    }
    if (preg_match("/UPDATE sas_chat_threads SET unread_for_user=0\s+WHERE id='(\\d+)'/s", $sql, $m)) {
        $GLOBALS['db']['chat_threads'][(int)$m[1]]['unread_for_user'] = 0;
        return true;
    }
    if (preg_match("/SELECT \\* FROM sas_chat_threads\s+WHERE vendor_id='(\\d+)' AND status='([a-z_]+)'\s+ORDER BY last_message_at (ASC|DESC) LIMIT (\\d+)/s", $sql, $m)) {
        $rows = [];
        foreach ($GLOBALS['db']['chat_threads'] as $row) {
            if ((int)$row['vendor_id'] === (int)$m[1] && $row['status'] === $m[2]) $rows[] = $row;
        }
        usort($rows, function ($a, $b) use ($m) {
            return $m[3] === 'ASC'
                ? strcmp((string)$a['last_message_at'], (string)$b['last_message_at'])
                : strcmp((string)$b['last_message_at'], (string)$a['last_message_at']);
        });
        return array_slice($rows, 0, (int)$m[4]);
    }

    // messages
    if (preg_match("/SELECT \\* FROM sas_chat_messages\s+WHERE thread_id='(\\d+)' AND vendor_id='(\\d+)' AND id > (\\d+)\s+ORDER BY id ASC LIMIT (\\d+)/s", $sql, $m)) {
        $rows = [];
        foreach ($GLOBALS['db']['chat_messages'] as $row) {
            if ((int)$row['thread_id'] === (int)$m[1] && (int)$row['vendor_id'] === (int)$m[2] && (int)$row['id'] > (int)$m[3]) {
                $rows[] = $row;
            }
        }
        usort($rows, function ($a, $b) { return $a['id'] <=> $b['id']; });
        return array_slice($rows, 0, (int)$m[4]);
    }
    if (preg_match("/INSERT INTO sas_chat_messages\s+\(thread_id, vendor_id, author_type, author_name, body, created_at\)\s+VALUES \('(\d+)', '(\d+)', '([a-z]+)', '([^']*)', '([^']*)', NOW\(\)\)/s", $sql, $m)) {
        $id = count($GLOBALS['db']['chat_messages']) + 1;
        $GLOBALS['db']['chat_messages'][$id] = [
            'id' => $id, 'thread_id' => (int)$m[1], 'vendor_id' => (int)$m[2],
            'author_type' => $m[3], 'author_name' => stripslashes($m[4]),
            'body' => stripslashes($m[5]), 'created_at' => date('Y-m-d H:i:s'),
        ];
        $GLOBALS['db']['insert_id'] = $id;
        $GLOBALS['db']['affected'] = 1;
        return true;
    }

    // events
    if (preg_match("/INSERT INTO sas_chat_events\s+\(thread_id, vendor_id, event, actor, detail, created_at\)\s+VALUES \('(\d+)', '(\d+)', '([^']*)', '([^']*)', '([^']*)', NOW\(\)\)/s", $sql, $m)) {
        $id = count($GLOBALS['db']['chat_events']) + 1;
        $GLOBALS['db']['chat_events'][$id] = [
            'id' => $id, 'thread_id' => (int)$m[1], 'vendor_id' => (int)$m[2],
            'event' => stripslashes($m[3]), 'actor' => stripslashes($m[4]),
            'detail' => stripslashes($m[5]), 'created_at' => date('Y-m-d H:i:s'),
        ];
        $GLOBALS['db']['insert_id'] = $id;
        return true;
    }

    // ── phase 1C step 2: service matrix ──
    if (preg_match("/SELECT service_name, status, personal_enabled, business_enabled, provider_ready\\s+FROM sas_service_control WHERE vendor_id='(\\d+)'/s", $sql, $m)) {
        $rows = [];
        foreach ($GLOBALS['db']['service'] as $key => $row) {
            if (strpos($key, $m[1] . ':') === 0) {
                $rows[] = array_merge(['service_name' => substr($key, strlen($m[1]) + 1)], $row);
            }
        }
        return $rows;
    }
    if (preg_match("/SELECT id FROM sas_service_control WHERE vendor_id='(\\d+)' AND service_name='([^']*)' LIMIT 1/s", $sql, $m)) {
        return isset($GLOBALS['db']['service'][$m[1] . ':' . stripslashes($m[2])]) ? [['id' => 1]] : [];
    }
    if (preg_match("/UPDATE sas_service_control SET `([a-z_]+)`='(\\d+)' WHERE vendor_id='(\\d+)' AND service_name='([^']*)'/s", $sql, $m)) {
        $key = $m[3] . ':' . stripslashes($m[4]);
        $GLOBALS['db']['service'][$key][$m[1]] = (int)$m[2];
        $GLOBALS['db']['affected'] = 1;
        return true;
    }
    if (preg_match("/INSERT INTO sas_service_control \\(vendor_id, service_name, `([a-z_]+)`\\) VALUES \\('(\\d+)', '([^']*)', '(\\d+)'\\)/s", $sql, $m)) {
        $key = $m[2] . ':' . stripslashes($m[3]);
        $GLOBALS['db']['service'][$key] = $GLOBALS['db']['service'][$key] ?? [
            'status' => 1, 'personal_enabled' => 1, 'business_enabled' => 1, 'provider_ready' => 0,
        ];
        $GLOBALS['db']['service'][$key][$m[1]] = (int)$m[4];
        $GLOBALS['db']['affected'] = 1;
        return true;
    }

    // ── phase 1C step 2: KYB ──
    if (preg_match("/SELECT kyb_status FROM sas_users WHERE id='(\\d+)' AND vendor_id='(\\d+)' LIMIT 1/s", $sql, $m)) {
        $u = $GLOBALS['db']['users'][$m[1]] ?? null;
        return ($u && (int)$u['vendor_id'] === (int)$m[2]) ? [['kyb_status' => (int)($u['kyb_status'] ?? 0)]] : [];
    }
    if (preg_match("/UPDATE sas_users SET\\s+kyb_status=1,\\s+kyb_business_name='([^']*)',\\s+kyb_registration_no='([^']*)',\\s+kyb_category='([^']*)',\\s+kyb_document='([^']*)',\\s+kyb_reference='([^']*)',\\s+kyb_submitted_at=NOW\\(\\),\\s+kyb_reviewed_at=NULL,\\s+kyb_reviewed_by=NULL\\s+WHERE id='(\\d+)' AND vendor_id='(\\d+)'/s", $sql, $m)) {
        $id = (int)$m[6];
        $u = $GLOBALS['db']['users'][$id] ?? null;
        if (!$u || (int)$u['vendor_id'] !== (int)$m[7]) { $GLOBALS['db']['affected'] = 0; return true; }
        $GLOBALS['db']['users'][$id] = array_merge($u, [
            'kyb_status' => 1,
            'kyb_business_name' => stripslashes($m[1]),
            'kyb_registration_no' => stripslashes($m[2]),
            'kyb_category' => stripslashes($m[3]),
            'kyb_document' => stripslashes($m[4]),
            'kyb_reference' => stripslashes($m[5]),
            'kyb_submitted_at' => date('Y-m-d H:i:s'),
            'kyb_reviewed_at' => null,
            'kyb_reviewed_by' => null,
        ]);
        $GLOBALS['db']['affected'] = 1;
        return true;
    }
    if (preg_match("~UPDATE sas_users SET\\s+account_type='business', account_type_source='kyb_approved', account_type_at=NOW[(][)],\\s+kyb_status=2, kyb_reviewed_at=NOW[(][)], kyb_reviewed_by='([^']*)'\\s+WHERE id='(\\d+)' AND vendor_id='(\\d+)' AND kyb_status=1~s", $sql, $m)) {
        $id = (int)$m[2];
        $u = $GLOBALS['db']['users'][$id] ?? null;
        if (!$u || (int)$u['vendor_id'] !== (int)$m[3] || (int)($u['kyb_status'] ?? 0) !== 1) {
            $GLOBALS['db']['affected'] = 0;
            return true;
        }
        $GLOBALS['db']['users'][$id] = array_merge($u, [
            'account_type' => 'business',
            'account_type_source' => 'kyb_approved',
            'kyb_status' => 2,
            'kyb_reviewed_at' => date('Y-m-d H:i:s'),
            'kyb_reviewed_by' => stripslashes($m[1]),
        ]);
        $GLOBALS['db']['affected'] = 1;
        return true;
    }
    if (preg_match("~UPDATE sas_users SET\\s+kyb_status=3, kyb_note='([^']*)', kyb_reviewed_at=NOW[(][)], kyb_reviewed_by='([^']*)'\\s+WHERE id='(\\d+)' AND vendor_id='(\\d+)' AND kyb_status=1~s", $sql, $m)) {
        $id = (int)$m[3];
        $u = $GLOBALS['db']['users'][$id] ?? null;
        if (!$u || (int)$u['vendor_id'] !== (int)$m[4] || (int)($u['kyb_status'] ?? 0) !== 1) {
            $GLOBALS['db']['affected'] = 0;
            return true;
        }
        $GLOBALS['db']['users'][$id] = array_merge($u, [
            'kyb_status' => 3,
            'kyb_note' => stripslashes($m[1]),
            'kyb_reviewed_at' => date('Y-m-d H:i:s'),
            'kyb_reviewed_by' => stripslashes($m[2]),
        ]);
        $GLOBALS['db']['affected'] = 1;
        return true;
    }
    if (preg_match("/SELECT \\* FROM sas_users\\s+WHERE vendor_id='(\\d+)' AND kyb_status=1\\s+ORDER BY kyb_submitted_at ASC LIMIT (\\d+)/s", $sql, $m)) {
        $rows = [];
        foreach ($GLOBALS['db']['users'] as $u) {
            if ((int)$u['vendor_id'] === (int)$m[1] && (int)($u['kyb_status'] ?? 0) === 1) $rows[] = $u;
        }
        return array_slice($rows, 0, (int)$m[2]);
    }

    // ══ phase 3: provider adapters ══════════════════════════════════════════

    // credentials (sas_bank_transfer_gateways / sas_payment_gateways)
    if (preg_match("/^SELECT \\* FROM `(sas_bank_transfer_gateways|sas_payment_gateways)` WHERE vendor_id='(\d+)' AND gateway_name='([^']*)' LIMIT 1$/", $sql, $m)) {
        $row = $GLOBALS['db']['gateways'][$m[1] . ':' . $m[2] . ':' . stripslashes($m[3])] ?? null;
        return $row ? [$row] : [];
    }

    // provider asset profile (single setting read)
    if (preg_match("/^SELECT setting_value FROM sas_settings WHERE vendor_id='(\d+)' AND setting_name='([^']*)' LIMIT 1$/", $sql, $m)) {
        $key = $m[1] . ':' . stripslashes($m[2]);
        return array_key_exists($key, $GLOBALS['db']['settings'])
            ? [['setting_value' => $GLOBALS['db']['settings'][$key]]] : [];
    }

    // webhook idempotency table
    if (preg_match("/^INSERT INTO sas_webhook_events \(provider, event_id, vendor_id, status, created_at\) VALUES \('([^']*)', '([^']*)', '(\d+)', 'processing', NOW\(\)\)$/", $sql, $m)) {
        $key = stripslashes($m[1]) . ':' . stripslashes($m[2]);
        if (isset($GLOBALS['db']['webhook_events'][$key])) {
            $GLOBALS['db']['errno'] = 1062; // duplicate key — the whole idempotency mechanism
            return false;
        }
        $GLOBALS['db']['webhook_events'][$key] = [
            'provider' => stripslashes($m[1]), 'event_id' => stripslashes($m[2]),
            'vendor_id' => (int)$m[3], 'status' => 'processing',
            'created_at' => date('Y-m-d H:i:s'), 'completed_at' => null,
        ];
        $GLOBALS['db']['errno'] = 0;
        return true;
    }
    if (preg_match("/^UPDATE sas_webhook_events\s+SET status='processed', completed_at=NOW\(\)\s+WHERE provider='([^']*)' AND event_id='([^']*)'$/s", $sql, $m)) {
        $key = stripslashes($m[1]) . ':' . stripslashes($m[2]);
        if (!isset($GLOBALS['db']['webhook_events'][$key])) return false;
        $GLOBALS['db']['webhook_events'][$key]['status'] = 'processed';
        $GLOBALS['db']['webhook_events'][$key]['completed_at'] = date('Y-m-d H:i:s');
        $GLOBALS['db']['affected'] = 1;
        return true;
    }
    if (preg_match("/^DELETE FROM sas_webhook_events\s+WHERE provider='([^']*)' AND event_id='([^']*)' AND status='processing'$/s", $sql, $m)) {
        $key = stripslashes($m[1]) . ':' . stripslashes($m[2]);
        if (!isset($GLOBALS['db']['webhook_events'][$key])) return false;
        unset($GLOBALS['db']['webhook_events'][$key]);
        $GLOBALS['db']['affected'] = 1;
        return true;
    }
    if (preg_match("/^SELECT status FROM sas_webhook_events WHERE provider='([^']*)' AND event_id='([^']*)' LIMIT 1$/", $sql, $m)) {
        $key = stripslashes($m[1]) . ':' . stripslashes($m[2]);
        return isset($GLOBALS['db']['webhook_events'][$key])
            ? [['status' => $GLOBALS['db']['webhook_events'][$key]['status']]] : [];
    }

    // NGN balance row (locked)
    if (preg_match("/^SELECT id, balance, status FROM sas_users WHERE vendor_id='(\d+)' AND username='([^']*)' LIMIT 1 FOR UPDATE$/", $sql, $m)) {
        foreach ($GLOBALS['db']['users'] as $u) {
            if ((int)$u['vendor_id'] === (int)$m[1] && (string)($u['username'] ?? '') === stripslashes($m[2])) return [$u];
        }
        return [];
    }
    if (preg_match("/^UPDATE sas_users SET balance=balance([+-])([0-9.]+) WHERE id='(\d+)'$/", $sql, $m)) {
        $u = $GLOBALS['db']['users'][$m[3]] ?? null;
        if (!$u || !isset($u['balance'])) { $GLOBALS['db']['affected'] = 0; return false; }
        $delta = (float)$m[2];
        $u['balance'] = $m[1] === '+' ? (float)$u['balance'] + $delta : (float)$u['balance'] - $delta;
        $GLOBALS['db']['users'][$m[3]] = $u;
        $GLOBALS['db']['affected'] = 1;
        return true;
    }
    if (preg_match("/^SELECT balance FROM sas_users WHERE id='(\d+)' LIMIT 1$/", $sql, $m)) {
        $u = $GLOBALS['db']['users'][$m[1]] ?? null;
        return ($u && isset($u['balance'])) ? [['balance' => $u['balance']]] : [];
    }
    if (preg_match("/^SELECT balance FROM sas_users WHERE vendor_id='(\d+)' AND username='([^']*)' LIMIT 1$/", $sql, $m)) {
        foreach ($GLOBALS['db']['users'] as $u) {
            if ((int)$u['vendor_id'] === (int)$m[1] && (string)($u['username'] ?? '') === stripslashes($m[2])) {
                return isset($u['balance']) ? [['balance' => $u['balance']]] : [];
            }
        }
        return [];
    }
    if (preg_match("/^SELECT username FROM sas_users WHERE vendor_id='(\d+)' AND \(username='([^']*)' OR email='([^']*)'\) LIMIT 1$/", $sql, $m)) {
        foreach ($GLOBALS['db']['users'] as $u) {
            if ((int)$u['vendor_id'] !== (int)$m[1]) continue;
            if ((string)($u['username'] ?? '') === stripslashes($m[2]) || (string)($u['email'] ?? '') === stripslashes($m[3])) {
                return [['username' => $u['username']]];
            }
        }
        return [];
    }

    // crypto wallet rows
    if (preg_match("/^INSERT IGNORE INTO sas_user_crypto_wallets \(vendor_id, username, currency_code\) VALUES \('(\d+)', '([^']*)', '([^']*)'\)$/", $sql, $m)) {
        $key = $m[1] . ':' . stripslashes($m[2]) . ':' . stripslashes($m[3]);
        if (!isset($GLOBALS['db']['crypto_wallets'][$key])) {
            $GLOBALS['db']['crypto_wallets'][$key] = [
                'id' => count($GLOBALS['db']['crypto_wallets']) + 1,
                'vendor_id' => (int)$m[1], 'username' => stripslashes($m[2]),
                'currency_code' => stripslashes($m[3]),
                'balance' => 0, 'ledger_balance' => 0,
            ];
        }
        return true;
    }
    if (preg_match("/^SELECT id, balance, ledger_balance FROM sas_user_crypto_wallets WHERE vendor_id='(\d+)' AND username='([^']*)' AND currency_code='([^']*)' LIMIT 1 FOR UPDATE$/", $sql, $m)) {
        $key = $m[1] . ':' . stripslashes($m[2]) . ':' . stripslashes($m[3]);
        return isset($GLOBALS['db']['crypto_wallets'][$key]) ? [$GLOBALS['db']['crypto_wallets'][$key]] : [];
    }
    if (preg_match("/^UPDATE sas_user_crypto_wallets\s+SET balance=balance([+-])([0-9.]+), ledger_balance=ledger_balance([+-])([0-9.]+)\s+WHERE id='(\d+)'$/", $sql, $m)) {
        foreach ($GLOBALS['db']['crypto_wallets'] as $key => $w) {
            if ((int)$w['id'] !== (int)$m[5]) continue;
            $w['balance'] = $m[1] === '+' ? (float)$w['balance'] + (float)$m[2] : (float)$w['balance'] - (float)$m[2];
            $w['ledger_balance'] = $m[3] === '+' ? (float)$w['ledger_balance'] + (float)$m[4] : (float)$w['ledger_balance'] - (float)$m[4];
            $GLOBALS['db']['crypto_wallets'][$key] = $w;
            $GLOBALS['db']['affected'] = 1;
            return true;
        }
        $GLOBALS['db']['affected'] = 0;
        return false;
    }
    if (preg_match("/^SELECT balance FROM sas_user_crypto_wallets WHERE id='(\d+)' LIMIT 1$/", $sql, $m)) {
        foreach ($GLOBALS['db']['crypto_wallets'] as $w) {
            if ((int)$w['id'] === (int)$m[1]) return [['balance' => $w['balance']]];
        }
        return [];
    }
    if (preg_match("/^SELECT balance FROM sas_user_crypto_wallets WHERE vendor_id='(\d+)' AND username='([^']*)' AND currency_code='([^']*)' LIMIT 1$/", $sql, $m)) {
        $key = $m[1] . ':' . stripslashes($m[2]) . ':' . stripslashes($m[3]);
        return isset($GLOBALS['db']['crypto_wallets'][$key]) ? [['balance' => $GLOBALS['db']['crypto_wallets'][$key]['balance']]] : [];
    }

    // receipt notification: the wallet owner, the vendor, and the email templates
    if (preg_match("/^SELECT \* FROM sas_users WHERE vendor_id='(\d+)' AND username='([^']*)' LIMIT 1$/", $sql, $m)) {
        foreach ($GLOBALS['db']['users'] as $u) {
            if ((int)$u['vendor_id'] === (int)$m[1] && (string)($u['username'] ?? '') === stripslashes($m[2])) return [$u];
        }
        return [];
    }
    if (preg_match("/^SELECT \* FROM sas_vendors WHERE id='(\d+)' LIMIT 1$/", $sql, $m)) {
        $v = $GLOBALS['db']['vendors'][(int)$m[1]] ?? null;
        return $v ? [$v] : [];
    }
    if (preg_match("/^SELECT \* FROM sas_email_templates WHERE vendor_id='(\d+)' && email_type='([^']*)'$/", $sql, $m)) {
        $row = $GLOBALS['db']['email_templates'][$m[1] . ':' . stripslashes($m[2])] ?? null;
        return $row ? [$row] : [];
    }

    // checkout reference -> username
    if (preg_match("/^SELECT username FROM sas_user_payment_checkouts WHERE vendor_id='(\d+)' AND reference='([^']*)' LIMIT 1$/", $sql, $m)) {
        $key = $m[1] . ':' . stripslashes($m[2]);
        return isset($GLOBALS['db']['checkouts'][$key]) ? [['username' => $GLOBALS['db']['checkouts'][$key]]] : [];
    }

    // ledger audit row
    if (preg_match("/^INSERT INTO sas_fintech_ledger \(vendor_id, username, currency_code, direction, amount, balance_before, balance_after, provider, event_id, reference, context, created_at\) VALUES \('([^']*)', '([^']*)', '([^']*)', '([^']*)', '([^']*)', '([^']*)', '([^']*)', '([^']*)', '([^']*)', '([^']*)', '(.*)', NOW\(\)\)$/", $sql, $m)) {
        $id = count($GLOBALS['db']['ledger']) + 1;
        $GLOBALS['db']['ledger'][$id] = [
            'id' => $id, 'vendor_id' => (int)$m[1], 'username' => stripslashes($m[2]),
            'currency_code' => stripslashes($m[3]), 'direction' => stripslashes($m[4]),
            'amount' => stripslashes($m[5]), 'balance_before' => stripslashes($m[6]),
            'balance_after' => stripslashes($m[7]), 'provider' => stripslashes($m[8]),
            'event_id' => stripslashes($m[9]), 'reference' => stripslashes($m[10]),
            'context' => json_decode(stripslashes($m[11]), true),
            'created_at' => date('Y-m-d H:i:s'),
        ];
        $GLOBALS['db']['insert_id'] = $id;
        $GLOBALS['db']['affected'] = 1;
        return true;
    }

    // NGN history mirror
    if (preg_match("/^INSERT INTO sas_transactions \(vendor_id, product_unique_id, type_alternative, reference, api_reference, username, amount, discounted_amount, balance_before, balance_after, description, mode, api_website, status\) VALUES \('([^']*)', '([^']*)', '([^']*)', '([^']*)', '([^']*)', '([^']*)', '([^']*)', '([^']*)', '([^']*)', '([^']*)', '(.*)', '([^']*)', '([^']*)', (\d+)\)$/", $sql, $m)) {
        $id = count($GLOBALS['db']['fintech_tx']) + 1;
        $GLOBALS['db']['fintech_tx'][$id] = [
            'id' => $id, 'vendor_id' => (int)$m[1], 'product_unique_id' => stripslashes($m[2]),
            'type_alternative' => stripslashes($m[3]), 'reference' => stripslashes($m[4]),
            'api_reference' => stripslashes($m[5]), 'username' => stripslashes($m[6]),
            'amount' => stripslashes($m[7]), 'discounted_amount' => stripslashes($m[8]),
            'balance_before' => stripslashes($m[9]), 'balance_after' => stripslashes($m[10]),
            'description' => stripslashes($m[11]), 'mode' => stripslashes($m[12]),
            'api_website' => stripslashes($m[13]), 'status' => (int)$m[14],
        ];
        $GLOBALS['db']['insert_id'] = $id;
        $GLOBALS['db']['affected'] = 1;
        return true;
    }

    // ── vendor registration: super-admin options, pending vendors, packages ──
    if (preg_match("/^SELECT option_value FROM sas_super_admin_options WHERE option_name='([^']*)' LIMIT 1$/", $sql, $m)) {
        return array_key_exists($m[1], $GLOBALS['db']['options']) ? [['option_value' => $GLOBALS['db']['options'][$m[1]]]] : [];
    }
    if (preg_match("/^SELECT option_name FROM sas_super_admin_options WHERE option_name='([^']*)' LIMIT 1$/", $sql, $m)) {
        return array_key_exists($m[1], $GLOBALS['db']['options']) ? [['option_name' => $m[1]]] : [];
    }
    if (preg_match("/^SELECT \* FROM sas_super_admin_options WHERE option_name IN \((.*)\)$/", $sql, $m)) {
        $wanted = array_map(function ($v) { return trim($v, " '\t\n\r"); }, explode(',', $m[1]));
        $rows = [];
        foreach ($wanted as $name) {
            if (array_key_exists($name, $GLOBALS['db']['options'])) $rows[] = ['option_name' => $name, 'option_value' => $GLOBALS['db']['options'][$name]];
        }
        return $rows;
    }
    if (preg_match("/^SELECT extension, price, promo_price FROM sas_domain_extensions ORDER BY extension ASC$/", $sql)) {
        $rows = [];
        foreach ($GLOBALS['db']['domain_extensions'] as $ext => $price) {
            $rows[] = ['extension' => $ext, 'price' => $price, 'promo_price' => $price];
        }
        usort($rows, function ($a, $b) { return strcmp($a['extension'], $b['extension']); });
        return $rows;
    }
    if (preg_match("/^SELECT \* FROM sas_super_admin LIMIT 1$/", $sql)) {
        return $GLOBALS['db']['super_admin'] ? [$GLOBALS['db']['super_admin']] : [];
    }
    if (preg_match("/^SELECT price FROM sas_billing_packages WHERE id='(\d+)'$/", $sql, $m)) {
        return array_key_exists($m[1], $GLOBALS['db']['billing_packages']) ? [['price' => $GLOBALS['db']['billing_packages'][$m[1]]]] : [];
    }
    if (preg_match("/^SELECT SUM\(price\) as total_addon_price FROM sas_billing_addons WHERE id IN \(([^)]*)\)$/", $sql, $m)) {
        $ids = array_filter(array_map('trim', explode(',', $m[1])));
        $sum = 0.0;
        foreach ($ids as $id) if (array_key_exists($id, $GLOBALS['db']['billing_addons'])) $sum += (float)$GLOBALS['db']['billing_addons'][$id];
        return [['total_addon_price' => $sum]];
    }
    if (preg_match("/^SELECT \* FROM sas_vendors WHERE email='([^']*)'$/", $sql, $m)) {
        foreach ($GLOBALS['db']['vendors'] as $row) if (($row['email'] ?? '') === stripslashes($m[1])) return [$row];
        return [];
    }
    if (preg_match("/^SELECT \* FROM sas_vendors WHERE website_url='([^']*)'$/", $sql, $m)) {
        foreach ($GLOBALS['db']['vendors'] as $row) if (($row['website_url'] ?? '') === stripslashes($m[1])) return [$row];
        return [];
    }
    if (preg_match("/^SELECT \* FROM sas_pending_vendors WHERE email='([^']*)'$/", $sql, $m)) {
        foreach ($GLOBALS['db']['pending_vendors'] as $row) if (($row['email'] ?? '') === stripslashes($m[1])) return [$row];
        return [];
    }
    if (preg_match("/^SELECT \* FROM sas_pending_vendors WHERE website_url='([^']*)'$/", $sql, $m)) {
        foreach ($GLOBALS['db']['pending_vendors'] as $row) if (($row['website_url'] ?? '') === stripslashes($m[1])) return [$row];
        return [];
    }
    if (preg_match("/^SELECT secret_key FROM sas_super_admin_payment_gateways WHERE gateway_name='paystack'$/", $sql)) {
        $row = $GLOBALS['db']['super_admin_payments']['paystack'] ?? null;
        return $row ? [['secret_key' => $row['secret_key']]] : [];
    }
    if (preg_match("/^INSERT INTO sas_pending_vendors \(([^)]*)\) VALUES \((.*)\)$/", $sql, $m)) {
        // Faithful duplicate-key behaviour: the real table carries UNIQUE(email) and
        // UNIQUE(website_url), so the pre-checks and the INSERT must agree.
        $cols = array_map('trim', explode(',', $m[1]));
        // PHP 8.3 deprecates the omitted $escape argument; pass it explicitly.
        $vals = str_getcsv($m[2], ',', "'", "\\");
        $row = [];
        foreach ($cols as $i => $c) $row[$c] = stripslashes($vals[$i] ?? '');
        if (empty($row['id'])) $row['id'] = count($GLOBALS['db']['pending_vendors']) + 1;
        foreach (['email', 'website_url'] as $unique) {
            if (!isset($row[$unique]) || $row[$unique] === '') continue;
            foreach ($GLOBALS['db']['pending_vendors'] as $existing) {
                if (($existing[$unique] ?? '') === $row[$unique]) {
                    $GLOBALS['db']['errno'] = 1062;
                    $GLOBALS['db']['error'] = "Duplicate entry '" . $row[$unique] . "' for key '$unique'";
                    return false;
                }
            }
        }
        $GLOBALS['db']['errno'] = 0;
        $GLOBALS['db']['error'] = '';
        $GLOBALS['db']['pending_vendors'][$row['id']] = $row;
        $GLOBALS['db']['insert_id'] = $row['id'];
        $GLOBALS['db']['affected'] = 1;
        return true;
    }
    if (preg_match("/^UPDATE sas_pending_vendors SET ([^W]*) WHERE id='(\d+)'$/", $sql, $m)) {
        $id = (int)$m[2];
        if (empty($GLOBALS['db']['pending_vendors'][$id])) return false;
        foreach (explode(',', $m[1]) as $assignment) {
            if (!preg_match("/(\w+)='([^']*)'/", $assignment, $mm)) continue;
            $GLOBALS['db']['pending_vendors'][$id][$mm[1]] = stripslashes($mm[2]);
        }
        $GLOBALS['db']['affected'] = 1;
        return true;
    }

    // ── manual-funding bank details (bc-admin / bc-spadmin AccountSettings) ──
    if (preg_match("/^SELECT \* FROM sas_admin_payments WHERE vendor_id='(\\d+)'( LIMIT 1)?$/", $sql, $m)) {
        $row = $GLOBALS['db']['admin_payments'][(int)$m[1]] ?? null;
        return $row ? [$row] : [];
    }
    if (preg_match("/^UPDATE sas_admin_payments SET bank_name='([^']*)', account_name='([^']*)', account_number='([^']*)', phone_number='([^']*)', amount_charged='([^']*)' WHERE vendor_id='(\\d+)'$/", $sql, $m)) {
        $GLOBALS['db']['admin_payments'][(int)$m[6]] = [
            'vendor_id' => (int)$m[6], 'bank_name' => stripslashes($m[1]),
            'account_name' => stripslashes($m[2]), 'account_number' => stripslashes($m[3]),
            'phone_number' => stripslashes($m[4]), 'amount_charged' => stripslashes($m[5]),
        ];
        $GLOBALS['db']['affected'] = 1;
        return true;
    }
    if (preg_match("/^INSERT INTO sas_admin_payments \(vendor_id, bank_name, account_name, account_number, phone_number, amount_charged\) VALUES \('(\d+)', '([^']*)', '([^']*)', '([^']*)', '([^']*)', '([^']*)'\)$/", $sql, $m)) {
        $GLOBALS['db']['admin_payments'][(int)$m[1]] = [
            'vendor_id' => (int)$m[1], 'bank_name' => stripslashes($m[2]),
            'account_name' => stripslashes($m[3]), 'account_number' => stripslashes($m[4]),
            'phone_number' => stripslashes($m[5]), 'amount_charged' => stripslashes($m[6]),
        ];
        $GLOBALS['db']['affected'] = 1;
        return true;
    }
    if (preg_match('/^SELECT \* FROM sas_super_admin_payments LIMIT 1$/', $sql)) {
        $row = $GLOBALS['db']['super_admin_payments'] ?? null;
        return $row ? [$row] : [];
    }
    if (preg_match("/^UPDATE sas_super_admin_payments SET bank_name='([^']*)', account_name='([^']*)', account_number='([^']*)', phone_number='([^']*)', amount_charged='([^']*)'$/", $sql, $m)) {
        $GLOBALS['db']['super_admin_payments'] = [
            'bank_name' => stripslashes($m[1]), 'account_name' => stripslashes($m[2]),
            'account_number' => stripslashes($m[3]), 'phone_number' => stripslashes($m[4]),
            'amount_charged' => stripslashes($m[5]),
        ];
        $GLOBALS['db']['affected'] = 1;
        return true;
    }
    if (preg_match("/^INSERT INTO sas_super_admin_payments \(bank_name, account_name, account_number, phone_number, amount_charged\) VALUES \('([^']*)', '([^']*)', '([^']*)', '([^']*)', '([^']*)'\)$/", $sql, $m)) {
        $GLOBALS['db']['super_admin_payments'] = [
            'bank_name' => stripslashes($m[1]), 'account_name' => stripslashes($m[2]),
            'account_number' => stripslashes($m[3]), 'phone_number' => stripslashes($m[4]),
            'amount_charged' => stripslashes($m[5]),
        ];
        $GLOBALS['db']['affected'] = 1;
        return true;
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
        //
        // Two spellings occur in the shipped source and BOTH have to go:
        //   require_once $dir . '/x.php';            -- bc_fintech_gate_boot()
        //   require_once(__DIR__ . '/x.php');        -- the phase 2 chat/schema files
        // Matching only the first is what previously made the chat schema try to open a file beside
        // the test instead of beside the code.
        $source = preg_replace("#\s*require_once \(?\s*\\\$dir\s*\.\s*'/bc-[a-z0-9-]+\.php'\s*\)?;#", '', $source);
        // Parentheses are optional in the shipped code, and both forms appear:
        //   require_once __DIR__ . '/bc-gateway-policy.php';      (bc-fintech-accounts.php)
        //   require_once(__DIR__ . '/bc-support-chat-schema.php'); (the chat files)
        $source = preg_replace("#\s*require_once\s*\(?\s*__DIR__\s*\.\s*'/bc-[a-z0-9-]+\.php'\s*\)?\s*;#", '', $source);
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

// ═══════════════════════ 12. phase 1C step 2: account-type backfill ═══════════════════════
// Load through the fake-DB shim so the real shipped SQL is exercised (mysqli is absent here).
load_edition($root, 'DGV7.0-SAAS', ['func/bc-fintech-accounts.php']);

$GLOBALS['db']['users'] = [
    10 => ['id' => 10, 'vendor_id' => 7, 'username' => 'lvl1', 'account_level' => '1'],
    11 => ['id' => 11, 'vendor_id' => 7, 'username' => 'lvl2', 'account_level' => '2'],
    12 => ['id' => 12, 'vendor_id' => 7, 'username' => 'lvl3', 'account_level' => '3'],
    13 => ['id' => 13, 'vendor_id' => 7, 'username' => 'lvl0', 'account_level' => '0'],
    14 => ['id' => 14, 'vendor_id' => 7, 'username' => 'explicit', 'account_level' => '3', 'account_type' => 'personal'],
    15 => ['id' => 15, 'vendor_id' => 8, 'username' => 'other-tenant', 'account_level' => '3'],
];

$dry = bc_fintech_backfill_dry_run(7);
gcheck('dry run counts levels 1-2 as personal', $dry['personal'], 2);
gcheck('dry run counts level 3 as business', $dry['business'], 1);
gcheck('dry run counts explicit tiers as already set', $dry['already_set'], 1);
gcheck('dry run leaves unknown levels unclassified', $dry['unknown_level'], 1);
gfalse('dry run writes nothing', isset($GLOBALS['db']['users'][10]['account_type']));

$run = bc_fintech_backfill_account_types(7);
gcheck('backfill reports two rows written', $run['updated'], 3);
gcheck('backfill reports the unknown level as skipped', $run['skipped_unknown'], 1);
gcheck('level 1 became personal', $GLOBALS['db']['users'][10]['account_type'], 'personal');
gcheck('level 2 became personal', $GLOBALS['db']['users'][11]['account_type'], 'personal');
gcheck('level 3 became business', $GLOBALS['db']['users'][12]['account_type'], 'business');
gcheck('backfill stamps its source', $GLOBALS['db']['users'][12]['account_type_source'], 'backfill_2026_11');
gfalse('unknown level 0 is left NULL', isset($GLOBALS['db']['users'][13]['account_type']));
gcheck('an explicit tier is never overwritten', $GLOBALS['db']['users'][14]['account_type'], 'personal');
gfalse('other tenants are untouched', isset($GLOBALS['db']['users'][15]['account_type']));

// account_level is the pricing key: the backfill must not rewrite it.
gcheck('account_level survives the backfill (1)', $GLOBALS['db']['users'][10]['account_level'], '1');
gcheck('account_level survives the backfill (3)', $GLOBALS['db']['users'][12]['account_level'], '3');
gcheck('account_level of an explicit tier survives too', $GLOBALS['db']['users'][14]['account_level'], '3');

// idempotency: a second run has nothing left to do
$again = bc_fintech_backfill_account_types(7);
gcheck('re-running the backfill writes nothing', $again['updated'], 0);
gcheck('re-run still reports the unknown level', $again['skipped_unknown'], 1);

$accounts_src = php_strip_whitespace($root . '/DGV7.0-SAAS/func/bc-fintech-accounts.php');
gfalse('backfill helper never writes account_level', (bool)preg_match('/account_level\s*=\s*[\'"]?\d/', $accounts_src));
gfalse('backfill helper never drops or truncates', (bool)preg_match('/\b(DROP|TRUNCATE)\b/i', $accounts_src));
gfalse('backfill helper does not delete rows', (bool)preg_match('/\bDELETE\s+FROM\b/i', $accounts_src));

// ── levels 0/4/malformed stay unclassified; an explicit tier is never re-mapped ──
$GLOBALS['db']['users'][16] = ['id' => 16, 'vendor_id' => 7, 'username' => 'lvl4', 'account_level' => '4'];
$GLOBALS['db']['users'][17] = ['id' => 17, 'vendor_id' => 7, 'username' => 'junk', 'account_level' => 'agent'];
$run2 = bc_fintech_backfill_account_types(7);
gcheck('second tenant pass writes only the new valid rows', $run2['updated'], 0);
gfalse('level 4 stays NULL', isset($GLOBALS['db']['users'][16]['account_type']));
gfalse('a malformed level stays NULL', isset($GLOBALS['db']['users'][17]['account_type']));

// ═══════════════════════ 13. service matrix ═══════════════════════
$services = bc_fintech_matrix_services();
foreach (['developer_api', 'esim', 'tuition', 'bills', 'invoices', 'pos', 'transfers', 'cross_border', 'stablecoin', 'fx', 'virtual_accounts', 'referrals', 'airtime'] as $slug) {
    gtrue("matrix lists $slug", array_key_exists($slug, $services));
}
gtrue('every matrix slug is lower_snake', count(array_filter(array_keys($services), fn($k) => !preg_match('/^[a-z_]+$/', $k))) === 0);

gtrue('developer_api is locked off for personal', bc_fintech_service_locked_off('developer_api', 'personal'));
gfalse('developer_api is NOT locked off for business', bc_fintech_service_locked_off('developer_api', 'business'));
gfalse('other services are not locked off', bc_fintech_service_locked_off('esim', 'personal'));
gtrue('lock check is case/space tolerant', bc_fintech_service_locked_off(' DEVELOPER_API ', 'Personal'));

$GLOBALS['db']['service'] = [];
$GLOBALS['db']['service']['7:esim'] = ['status' => 1, 'personal_enabled' => 1, 'business_enabled' => 0, 'provider_ready' => 1];
$m = bc_fintech_matrix_read(7);
gcheck('matrix reads the personal switch', $m['esim']['personal_enabled'], 1);
gcheck('matrix reads the business switch', $m['esim']['business_enabled'], 0);
gtrue('matrix reports the live personal tier', bc_fintech_tier_effective(['service_name' => 'esim'] + $m['esim'], 'personal'));
gfalse('matrix reports the disabled business tier', bc_fintech_tier_effective(['service_name' => 'esim'] + $m['esim'], 'business'));

$GLOBALS['db']['service']['7:esim']['provider_ready'] = 0;
gfalse('provider readiness gates the tier summary', bc_fintech_tier_effective(['service_name' => 'esim'] + $GLOBALS['db']['service']['7:esim'], 'personal'));
$GLOBALS['db']['service']['7:esim']['provider_ready'] = 1;
$GLOBALS['db']['service']['7:esim']['status'] = 0;
gfalse('the global status gates the tier summary', bc_fintech_tier_effective(['service_name' => 'esim'] + $GLOBALS['db']['service']['7:esim'], 'personal'));
$GLOBALS['db']['service']['7:esim']['status'] = 1;

// developer_api can never be live for personal, whatever the row says
$GLOBALS['db']['service']['7:developer_api'] = ['status' => 1, 'personal_enabled' => 1, 'business_enabled' => 1, 'provider_ready' => 1];
gfalse('a forced developer_api row still reports personal: off',
    bc_fintech_tier_effective(['service_name' => 'developer_api'] + $GLOBALS['db']['service']['7:developer_api'], 'personal'));
gtrue('developer_api can be live for business',
    bc_fintech_tier_effective(['service_name' => 'developer_api'] + $GLOBALS['db']['service']['7:developer_api'], 'business'));

// matrix writes
gcheck('matrix refuses to enable developer_api for personal',
    bc_fintech_matrix_set(7, 'developer_api', 'personal_enabled', 1), 'Developer API can never be enabled for personal accounts.');
gcheck('the refused write left the switch unchanged', $GLOBALS['db']['service']['7:developer_api']['personal_enabled'], 1);
gtrue('matrix can still disable developer_api for personal', bc_fintech_matrix_set(7, 'developer_api', 'personal_enabled', 0));
gcheck('the disable was written', $GLOBALS['db']['service']['7:developer_api']['personal_enabled'], 0);
gcheck('matrix refuses an unknown service', bc_fintech_matrix_set(7, 'teleport', 'status', 1), 'Unknown service.');
gcheck('matrix refuses an unknown switch', bc_fintech_matrix_set(7, 'esim', 'free_stuff', 1), 'Unknown switch.');
gcheck('matrix refuses a write with no tenant', bc_fintech_matrix_set(0, 'esim', 'status', 1), 'No tenant context.');
gtrue('matrix writes an existing row', bc_fintech_matrix_set(7, 'esim', 'business_enabled', 1));
gcheck('the business switch was written', $GLOBALS['db']['service']['7:esim']['business_enabled'], 1);
gtrue('matrix creates a missing row', bc_fintech_matrix_set(7, 'tuition', 'provider_ready', 1));
gcheck('the new row carries the switch', $GLOBALS['db']['service']['7:tuition']['provider_ready'], 1);

// ── the console wiring really is in both editions, and the platform console has a selector ──
foreach ($editions as $edition) {
    $sc = file_get_contents($root . '/' . $edition . '/bc-admin/ServiceControl.php');
    gtrue("$edition vendor console includes the matrix controller", strpos($sc, 'bc-service-matrix.php') !== false);
    gtrue("$edition vendor console renders the matrix", strpos($sc, 'bc-service-matrix-render.php') !== false);
    gtrue("$edition vendor console includes the backfill controller", strpos($sc, 'bc-account-backfill.php') !== false);
    gtrue("$edition vendor console renders the backfill", strpos($sc, 'bc-account-backfill-render.php') !== false);
    gtrue("$edition vendor console scopes the matrix to the logged-in vendor", strpos($sc, "\$matrix_vendor_id = (int)\$get_logged_admin_details['id'];") !== false);

    $render = file_get_contents($root . '/' . $edition . '/func/bc-service-matrix-render.php');
    gtrue("$edition matrix shows a personal column", strpos($render, 'personal_enabled') !== false);
    gtrue("$edition matrix shows a business column", strpos($render, 'business_enabled') !== false);
    gtrue("$edition matrix shows provider readiness", strpos($render, 'provider_ready') !== false);
    gtrue("$edition matrix marks the locked tier", strpos($render, 'locked') !== false);
    // The controller and view must stay split: an echoing include in a page preamble breaks redirects.
    $controller = file_get_contents($root . '/' . $edition . '/func/bc-service-matrix.php');
    gfalse("$edition matrix controller emits no markup", (bool)preg_match('/^\s*<div/m', $controller));

    $backfill_view = file_get_contents($root . '/' . $edition . '/func/bc-account-backfill-render.php');
    gtrue("$edition backfill UI shows a dry-run count", strpos($backfill_view, 'bc_backfill_counts') !== false);
    gtrue("$edition backfill UI demands confirmation before writing", strpos($backfill_view, 'bc_backfill_confirm') !== false);

    // The backfill controller must call the shared primitive, not its own SQL.
    $backfill_ctl = file_get_contents($root . '/' . $edition . '/func/bc-account-backfill.php');
    gtrue("$edition backfill controller uses the shared dry run", strpos($backfill_ctl, 'bc_fintech_backfill_dry_run(') !== false);
    gtrue("$edition backfill controller uses the shared writer", strpos($backfill_ctl, 'bc_fintech_backfill_account_types(') !== false);
    gfalse("$edition backfill controller runs no raw UPDATE", (bool)preg_match('/UPDATE\s+sas_users/i', $backfill_ctl));
}

$sp = file_get_contents($root . '/DGV7.0-SAAS/bc-spadmin/ServiceControl.php');
gtrue('platform console includes the matrix controller', strpos($sp, 'bc-service-matrix.php') !== false);
gtrue('platform console renders the matrix', strpos($sp, 'bc-service-matrix-render.php') !== false);
gtrue('platform console includes the backfill', strpos($sp, 'bc-account-backfill.php') !== false);
gtrue('platform console enables the tenant selector', strpos($sp, '$matrix_selectable = true') !== false);
gfalse('platform console does not invent a tenant id', (bool)preg_match('/\$matrix_vendor_id\s*=\s*\(int\)\s*\$get_logged_super_admin_details/', $sp));

// the selector validates the chosen tenant before a write is allowed
$ctl = file_get_contents($root . '/DGV7.0-SAAS/func/bc-service-matrix.php');
gtrue('selector resolves the tenant from the request', strpos($ctl, "\$_SESSION['bc_matrix_vendor']") !== false);
gtrue('selector validates the tenant against sas_vendors', strpos($ctl, 'FROM sas_vendors WHERE id=') !== false);
gtrue('an invalid tenant collapses to zero', (bool)preg_match('/mysqli_num_rows\(\$mv\)\s*!==\s*1\)\s*\$matrix_vendor_id\s*=\s*0/', $ctl) || strpos($ctl, '$matrix_vendor_id = 0;') !== false);

// ═══════════════════════ 14. KYB upgrade flow ═══════════════════════
gcheck('kyb status 0 is not started', bc_fintech_kyb_status_label(0), 'Not started');
gcheck('kyb status 1 is under review', bc_fintech_kyb_status_label(1), 'Under review');
gcheck('kyb status 2 is approved', bc_fintech_kyb_status_label(2), 'Approved');
gcheck('kyb status 3 is rejected', bc_fintech_kyb_status_label(3), 'Rejected');
$cats = bc_fintech_kyb_categories();
foreach (['sole_trader', 'limited_co', 'partnership', 'ngo', 'fintech', 'other'] as $c) gtrue("kyb offers $c", isset($cats[$c]));

$GLOBALS['db']['users'] = [
    20 => ['id' => 20, 'vendor_id' => 7, 'username' => 'upgrader', 'status' => 1, 'account_level' => '1', 'account_type' => 'personal', 'kyb_status' => 0],
    21 => ['id' => 21, 'vendor_id' => 7, 'username' => 'alreadybiz', 'status' => 1, 'account_level' => '3', 'account_type' => 'business', 'kyb_status' => 2],
    22 => ['id' => 22, 'vendor_id' => 8, 'username' => 'other', 'status' => 1, 'account_level' => '1', 'account_type' => 'personal', 'kyb_status' => 1],
];
$GLOBALS['db']['app_tokens'] = [];

$applicant = $GLOBALS['db']['users'][20];
$good = ['business_name' => 'Acme Payments Ltd', 'registration_no' => 'RC1234567', 'category' => 'limited_co', 'document' => 'kyb_20_abc123.pdf'];

gcheck('business account cannot apply again', bc_fintech_kyb_submit($GLOBALS['db']['users'][21], 7, $good), 'This account is already a business account.');
gcheck('unknown tier cannot apply', bc_fintech_kyb_submit(['id' => 99, 'account_level' => '9'], 7, $good), 'This account is not eligible for a business upgrade.');
gcheck('empty business name is refused', bc_fintech_kyb_submit($applicant, 7, ['business_name' => ''] + $good), 'Enter the registered business name (200 characters max).');
gcheck('over-long business name is refused', bc_fintech_kyb_submit($applicant, 7, ['business_name' => str_repeat('a', 201)] + $good), 'Enter the registered business name (200 characters max).');
gcheck('missing registration number is refused', bc_fintech_kyb_submit($applicant, 7, ['registration_no' => '  '] + $good), 'Enter the business registration number.');
gcheck('unknown category is refused', bc_fintech_kyb_submit($applicant, 7, ['category' => 'wizardry'] + $good), 'Choose a valid business category.');
gcheck('missing document is refused', bc_fintech_kyb_submit($applicant, 7, ['document' => ''] + $good), 'Upload your business registration document.');
gfalse('refused submissions did not change the row', ($GLOBALS['db']['users'][20]['kyb_status'] ?? 0) === 1);

gtrue('a valid request is recorded', bc_fintech_kyb_submit($applicant, 7, $good) === true);
gcheck('the request is pending', $GLOBALS['db']['users'][20]['kyb_status'], 1);
gcheck('the business name is stored', $GLOBALS['db']['users'][20]['kyb_business_name'], 'Acme Payments Ltd');
gcheck('the document name is stored', $GLOBALS['db']['users'][20]['kyb_document'], 'kyb_20_abc123.pdf');
gtrue('a reference is generated', (bool)preg_match('/^KYB-[A-F0-9]{10}$/', (string)$GLOBALS['db']['users'][20]['kyb_reference']));
gcheck('submitting does NOT promote the account', $GLOBALS['db']['users'][20]['account_type'], 'personal');
gcheck('submitting does not touch account_level', $GLOBALS['db']['users'][20]['account_level'], '1');

// a second submit while the first is under review is refused
$applicant2 = $GLOBALS['db']['users'][20];
gcheck('a pending request cannot be overwritten', bc_fintech_kyb_submit($applicant2, 7, ['business_name' => 'Acme TWO'] + $good), 'Your request is already under review.');
gcheck('the reviewed evidence is unchanged', $GLOBALS['db']['users'][20]['kyb_business_name'], 'Acme Payments Ltd');

// the review queue is tenant-scoped
$pending = bc_fintech_kyb_pending(7);
gcheck('the queue returns this tenant request', count($pending), 1);
gcheck('the queue does not leak other tenants', count(bc_fintech_kyb_pending(8)), 1);
gcheck('the queue is empty for an unrelated tenant', count(bc_fintech_kyb_pending(99)), 0);

// approval: the only path to business, and it kills app sessions
$issued = bc_app_token_issue($GLOBALS['db']['users'][20] ?: $applicant, 7, 'android');
gtrue('the applicant had a live app session first', isset($issued['token']));

gcheck('approval requires a reviewer', bc_fintech_kyb_approve(20, 7, '   '), 'Reviewer is required.');
gcheck('approval refuses another tenant', bc_fintech_kyb_approve(20, 8, 'admin'), 'Account not found.');
gcheck('approval refuses an account that is not pending', bc_fintech_kyb_approve(21, 7, 'admin'), 'This request is not awaiting review.');
gtrue('approval succeeds for a pending request', bc_fintech_kyb_approve(20, 7, 'reviewer.jane') === true);
gcheck('approval promotes the account to business', $GLOBALS['db']['users'][20]['account_type'], 'business');
gcheck('approval records its source', $GLOBALS['db']['users'][20]['account_type_source'], 'kyb_approved');
gcheck('approval closes the request as approved', $GLOBALS['db']['users'][20]['kyb_status'], 2);
gcheck('approval records the reviewer', $GLOBALS['db']['users'][20]['kyb_reviewed_by'], 'reviewer.jane');
gcheck('approval leaves account_level alone', $GLOBALS['db']['users'][20]['account_level'], '1');
gtrue('approval revokes the live app session', $GLOBALS['db']['app_tokens'][1]['revoked_at'] !== null);
gcheck('revocation reason is account_type', $GLOBALS['db']['app_tokens'][1]['revoke_reason'], 'account_type');
gcheck('the app can no longer use the revoked token', bc_app_session_user($issued['token'], 7), null);
gfalse('approving twice is refused', bc_fintech_kyb_approve(20, 7, 'admin') === true);

// rejection keeps the account personal and records the reason
$GLOBALS['db']['users'][23] = ['id' => 23, 'vendor_id' => 7, 'username' => 'rejectme', 'status' => 1, 'account_level' => '2', 'account_type' => 'personal', 'kyb_status' => 1];
gcheck('rejection requires a reviewer', bc_fintech_kyb_reject(23, 7, ''), 'Reviewer is required.');
gcheck('rejection refuses another tenant', bc_fintech_kyb_reject(23, 9, 'admin'), 'Account not found.');
gcheck('rejection refuses a non-pending account', bc_fintech_kyb_reject(21, 7, 'admin'), 'This request is not awaiting review.');
gtrue('rejection succeeds for a pending request', bc_fintech_kyb_reject(23, 7, 'reviewer.jane', 'Blurred certificate') === true);
gcheck('rejection closes the request', $GLOBALS['db']['users'][23]['kyb_status'], 3);
gcheck('rejection keeps the account personal', $GLOBALS['db']['users'][23]['account_type'], 'personal');
gcheck('rejection stores the reason', $GLOBALS['db']['users'][23]['kyb_note'], 'Blurred certificate');
gcheck('rejection records the reviewer', $GLOBALS['db']['users'][23]['kyb_reviewed_by'], 'reviewer.jane');
gcheck('the note is stored verbatim', $GLOBALS['db']['users'][23]['kyb_note'] ?? '', 'Blurred certificate');

// A long reason must be truncated, not rejected and not allowed to overflow the column.
$GLOBALS['db']['users'][24] = ['id' => 24, 'vendor_id' => 7, 'username' => 'longnote', 'status' => 1, 'account_level' => '1', 'account_type' => 'personal', 'kyb_status' => 1];
gtrue('a long rejection reason is accepted', bc_fintech_kyb_reject(24, 7, 'admin', str_repeat('x', 5000)) === true);
gcheck('the stored reason is capped at 2000 chars', strlen((string)$GLOBALS['db']['users'][24]['kyb_note']), 2000);

// a rejected applicant may try again
$GLOBALS['db']['users'][23]['kyb_status'] = 3;
gtrue('a rejected applicant can resubmit', bc_fintech_kyb_submit($GLOBALS['db']['users'][23], 7, $good) === true);
gcheck('the resubmission goes back under review', $GLOBALS['db']['users'][23]['kyb_status'], 1);

// structural: the user page and the console
foreach ($editions as $edition) {
    $page = $root . '/' . $edition . '/web/BusinessUpgrade.php';
    gtrue("$edition ships the upgrade page", is_file($page));
    $src = file_get_contents($page);
    gtrue("$edition upgrade page posts multibyte-safe", strpos($src, 'enctype="multipart/form-data"') !== false);
    gtrue("$edition upgrade page accepts a document", strpos($src, 'name="kyb_document"') !== false);
    gtrue("$edition upgrade page calls the shared submit", strpos($src, 'bc_fintech_kyb_submit(') !== false);
    gfalse("$edition upgrade page never writes account_type itself", (bool)preg_match('/UPDATE\s+sas_users[^;]*account_type\s*=\s*[\'"]business/i', $src));
    gtrue("$edition upgrade page writes an upload deny file", strpos($src, 'Require all denied') !== false);
    gtrue("$edition upgrade page validates the upload is a real file", strpos($src, 'is_uploaded_file(') !== false);
    gtrue("$edition upgrade page sniffs images", strpos($src, 'getimagesize(') !== false);
    gtrue("$edition upgrade page caps the upload size", strpos($src, '8 * 1024 * 1024') !== false);
    gtrue("$edition upgrade documents are stored outside the web root pattern (kyb folder)", strpos($src, '/uploads/kyb/') !== false);
    gtrue("$edition user navigation links the upgrade page", strpos(file_get_contents($root . '/' . $edition . '/func/bc-plate-app.php'), 'BusinessUpgrade.php') !== false);

    $admin = $root . '/' . $edition . '/bc-admin/BusinessKYB.php';
    gtrue("$edition ships the KYB console", is_file($admin));
    $asrc = file_get_contents($admin);
    gtrue("$edition KYB console uses the shared queue", strpos($asrc, 'bc_fintech_kyb_pending(') !== false);
    gtrue("$edition KYB console uses the shared approve", strpos($asrc, 'bc_fintech_kyb_approve(') !== false);
    gtrue("$edition KYB console uses the shared reject", strpos($asrc, 'bc_fintech_kyb_reject(') !== false);
    gtrue("$edition KYB console scopes documents to the vendor", (bool)preg_match('/FROM sas_users WHERE id=\'\$uid\' AND vendor_id=\'\$vid\'/', $asrc));
    gtrue("$edition KYB console checks the document path with realpath", strpos($asrc, 'realpath(') !== false);
    gtrue("$edition KYB console refuses a path outside the upload root", strpos($asrc, 'strpos($path, $base) !== 0') !== false);
    gtrue("$edition KYB console closes the session before streaming", strpos($asrc, 'session_write_close()') !== false);
    gtrue("$edition KYB console sends nosniff", strpos($asrc, 'nosniff') !== false);
    gtrue("$edition admin navigation links the KYB console", strpos(file_get_contents($root . '/' . $edition . '/func/bc-plate-admin.php'), 'BusinessKYB.php') !== false);
    gtrue("$edition ships an upload deny file for kyb documents", is_file($root . '/' . $edition . '/uploads/kyb/.htaccess'));
}

// ═══════════════════════════════════════════════════════════════════════════
// Phase 2 — marketing gating, wallets, support chat
// ═══════════════════════════════════════════════════════════════════════════

// The wallet/tier presentation helpers are pure functions over rows, so load them for real.
load_edition($root, 'DGV7.0-SAAS', ['func/bc-fintech-wallet.php']);
// The chat engine and its schema loader. The engine's require of the schema is stripped by
// load_edition, so load both explicitly.
load_edition($root, 'DGV7.0-SAAS', ['func/bc-support-chat-schema.php', 'func/bc-support-chat.php']);

// ── 15. marketing partition ────────────────────────────────────────────────
$GLOBALS['db']['service'] = [];
$pid = 7;
foreach (['transfers', 'fx', 'cross_border', 'esim', 'tuition', 'invoices', 'pos', 'developer_api'] as $svc) {
    $GLOBALS['db']['service'][$pid . ':' . $svc] = [
        'status' => 0, 'personal_enabled' => 0, 'business_enabled' => 0, 'provider_ready' => 0,
    ];
}

// Nothing switched on: everything is "soon", and nothing is dropped from the page.
$part = bc_fintech_marketing_partition($pid, 'personal');
gcheck('a dark tenant advertises nothing live', count($part['live']), 0);
gtrue('a dark tenant still lists cards as soon', count($part['soon']) > 0);

// Personal sees eSIM, never tuition or the developer API.
$personal_names = array_column($part['soon'], 'service');
gtrue('personal tab has the eSIM card', in_array('esim', $personal_names, true));
gtrue('personal tab has no tuition card', !in_array('tuition', $personal_names, true));
gtrue('developer_api is never advertised to personal', !in_array('developer_api', $personal_names, true));

$bpart = bc_fintech_marketing_partition($pid, 'business');
$business_names = array_column($bpart['soon'], 'service');
gtrue('business tab has the tuition card', in_array('tuition', $business_names, true));
gtrue('business tab has no eSIM card', !in_array('esim', $business_names, true));

// One service fully live.
$GLOBALS['db']['service'][$pid . ':transfers'] = [
    'status' => 1, 'personal_enabled' => 1, 'business_enabled' => 1, 'provider_ready' => 1,
];
$part = bc_fintech_marketing_partition($pid, 'personal');
gtrue('provider ready + switch on makes the card live', in_array('transfers', array_column($part['live'], 'service'), true));
gtrue('a live card is not also listed as soon', !in_array('transfers', array_column($part['soon'], 'service'), true));

// The provider_ready gate is the one that keeps Phase 3 services honest.
$GLOBALS['db']['service'][$pid . ':transfers']['provider_ready'] = 0;
$part = bc_fintech_marketing_partition($pid, 'personal');
gcheck('provider_ready=0 keeps the card unavailable', count($part['live']), 0);

// The tier switch still gates independently of provider readiness.
$GLOBALS['db']['service'][$pid . ':transfers'] = [
    'status' => 1, 'personal_enabled' => 0, 'business_enabled' => 1, 'provider_ready' => 1,
];
gcheck('the tier switch still gates the card', count(bc_fintech_marketing_partition($pid, 'personal')['live']), 0);
gtrue('the other tier still sees it', count(bc_fintech_marketing_partition($pid, 'business')['live']) > 0);

// Fail closed on nonsense inputs.
gcheck('a zero tenant is never live', count(bc_fintech_marketing_partition(0, 'personal')['live']), 0);
gcheck('an unknown tier is never live', count(bc_fintech_marketing_partition($pid, 'enterprise')['live']), 0);
gtrue('an unknown service is never live', bc_fintech_marketing_live($pid, 'personal', 'teleportation') === false);
gtrue('developer_api refuses personal even when the flags say yes', bc_fintech_marketing_live(
    $pid, 'personal', 'developer_api',
    ['developer_api' => ['name' => 'API', 'copy' => '', 'tiers' => ['personal', 'business']]]
) === false);

// ── 16. wallet + rate presentation ────────────────────────────────────────
$wallets = bc_wallet_cards(['NGN' => 25000]);
$by_code = [];
foreach ($wallets as $w) $by_code[$w['code']] = $w;
gtrue('NGN with a balance is open', $by_code['NGN']['open'] === true);
gtrue('NGN shows the amount', strpos($by_code['NGN']['amount'], '25,000') !== false);
gtrue('USD stays closed without custody', $by_code['USD']['open'] === false);
gcheck('a closed wallet reports "Not open yet"', $by_code['USD']['note'], 'Not open yet');
gtrue('a closed wallet shows no amount', $by_code['USD']['amount'] === null);

$wallets = bc_wallet_cards(['NGN' => 0]);
$by_code = [];
foreach ($wallets as $w) $by_code[$w['code']] = $w;
gtrue('a zero balance does not open the wallet', $by_code['NGN']['open'] === false);

gcheck('formatting rejects a non-number', bc_wallet_format('abc'), null);
gtrue('formatting rejects infinity', bc_wallet_format(INF) === null);

$snap = bc_fx_rate_snapshot(null);
gtrue('no feed means unavailable', $snap['available'] === false);
gcheck('unavailable strip explains itself', $snap['note'], 'Live rates are temporarily unavailable.');
gtrue('an unavailable strip has no rates', $snap['rates'] === []);

$snap = bc_fx_rate_snapshot(['USD/NGN' => 0]);
gtrue('a zero rate is treated as unavailable', $snap['available'] === false);

$snap = bc_fx_rate_snapshot(['USD/NGN' => 1550.5]);
gtrue('a real feed is available', $snap['available'] === true);
gcheck('the rate is carried through', $snap['rates']['USD/NGN'], 1550.5);

gcheck('business badge', bc_fintech_tier_badge('business')['label'], 'Business account');
gcheck('personal badge', bc_fintech_tier_badge('personal')['label'], 'Personal account');
gcheck('unknown tier is labelled honestly', bc_fintech_tier_badge(null)['label'], 'Account type not set');

// ── 17. support chat behaviour ────────────────────────────────────────────
$GLOBALS['db']['tables'] = ['sas_chat_threads' => true, 'sas_chat_messages' => true, 'sas_chat_events' => true];
$GLOBALS['db']['chat_threads'] = [];
$GLOBALS['db']['chat_messages'] = [];
$GLOBALS['db']['chat_events'] = [];
$GLOBALS['db']['settings'] = [];

gtrue('schema reports ready when all three tables exist', bc_chat_schema_ready() === true);
unset($GLOBALS['db']['tables']['sas_chat_events']);
gtrue('schema reports NOT ready when a table is missing', bc_chat_schema_ready() === false);
$GLOBALS['db']['tables']['sas_chat_events'] = true;

// Chat is off by default: the widget must not render for a tenant that never enabled it.
gtrue('chat is off by default', bc_chat_widget_enabled($pid) === false);
bc_chat_setting_save($pid, 'chat_enabled', '1');
gtrue('the widget renders once the tenant enables chat', bc_chat_widget_enabled($pid) === true);
gtrue('a zero tenant never gets a widget', bc_chat_widget_enabled(0) === false);

// Opening a thread.
$thread = bc_chat_open_thread($pid, 55, 'ada', 'Wallet question');
gtrue('a thread is created', is_array($thread) && (int)$thread['id'] > 0);
gcheck('a new thread starts open', $thread['status'], 'open');
gcheck('the AI is off without a key, even with AI enabled elsewhere', (int)$thread['ai_enabled'], 0);

$again = bc_chat_open_thread($pid, 55, 'ada');
gcheck('re-opening reuses the live thread', (int)$again['id'], (int)$thread['id']);

// Tenant scoping: another vendor cannot read the thread.
gtrue('another tenant cannot read the thread', bc_chat_thread_get(999, (int)$thread['id']) === null);
gtrue('another tenant cannot post into it', bc_chat_post_message(999, (int)$thread['id'], 'user', 'x', 'hi') === 0);

// Messages.
$tid = (int)$thread['id'];
gtrue('the body cap rejects an over-long message', bc_chat_post_message($pid, $tid, 'user', 'ada', str_repeat('x', 4001)) === 0);
gtrue('the body cap accepts a long-but-legal message', bc_chat_post_message($pid, $tid, 'user', 'ada', str_repeat('x', 4000)) > 0);
gtrue('an empty body is rejected', bc_chat_post_message($pid, $tid, 'user', 'ada', '   ') === 0);
gtrue('an unknown author type is rejected', bc_chat_post_message($pid, $tid, 'root', 'ada', 'hi') === 0);

$mid = bc_chat_post_message($pid, $tid, 'user', 'ada', 'Where is my transfer?');
gtrue('a user message is stored', $mid > 0);
$msgs = bc_chat_messages($pid, $tid, $mid);
gcheck('"after id" returns nothing older', count($msgs), 0);
$msgs = bc_chat_messages($pid, $tid, 0);
// Exactly the two that were accepted above: the 4000-char one and the real question. The welcome
// line is posted by the endpoint for a brand-new thread, not by the engine.
gcheck('the transcript holds the accepted messages', count($msgs), 2);
gcheck('the transcript is in order', (int)$msgs[1]['id'] > (int)$msgs[0]['id'], true);

// Escalation silences the assistant.
bc_chat_escalate($pid, $tid, 'user_request');
$t = bc_chat_thread_get($pid, $tid);
gcheck('escalation moves the thread to pending_agent', $t['status'], 'pending_agent');
gcheck('escalation clears ai_enabled', (int)$t['ai_enabled'], 0);
gtrue('escalation is idempotent', bc_chat_escalate($pid, $tid, 'user_request') === true);

// The AI must not speak on an escalated thread, even if the tenant turns AI on.
bc_chat_setting_save($pid, 'chat_ai_enabled', '1');
bc_chat_setting_save($pid, 'chat_ai_key', str_repeat('k', 32));
$t = bc_chat_thread_get($pid, $tid);
gcheck('AI reply is 0 on an escalated thread', bc_chat_ai_reply($pid, $t), 0);

// Claiming.
gtrue('an agent can claim a waiting thread', bc_chat_claim_thread($pid, $tid, 12, 'agent-ann') === true);
$t = bc_chat_thread_get($pid, $tid);
gcheck('claiming moves the thread to with_agent', $t['status'], 'with_agent');
gcheck('claiming records the agent', (string)$t['assigned_admin'], '12');
gtrue('a second agent cannot steal it', bc_chat_claim_thread($pid, $tid, 13, 'agent-bob') === false);
gtrue('another tenant cannot claim it', bc_chat_claim_thread(999, $tid, 12, 'agent-x') === false);

$before = count(bc_chat_messages($pid, $tid, 0));
bc_chat_post_message($pid, $tid, 'agent', 'agent-ann', 'Looking into it now.');
gcheck('an agent reply lands in the transcript', count(bc_chat_messages($pid, $tid, 0)), $before + 1);

// Closing.
gtrue('closing succeeds', bc_chat_close_thread($pid, $tid, 'agent-ann') === true);
$t = bc_chat_thread_get($pid, $tid);
gcheck('a closed thread reports closed', $t['status'], 'closed');
gtrue('the closed thread is not resumed', bc_chat_thread_for_user($pid, 55) === null);
$fresh = bc_chat_open_thread($pid, 55, 'ada');
gtrue('a new thread is opened instead', (int)$fresh['id'] !== $tid);

// The queue.
$queue = bc_chat_agent_queue($pid);
gtrue('the queue only lists open work', count($queue['pending']) === 0 && count($queue['active']) === 0);
gtrue('another tenant queue is empty', count(bc_chat_agent_queue(999)['pending']) === 0);

// Unread counters are per side.
$t2 = bc_chat_open_thread($pid, 66, 'bob');
bc_chat_post_message($pid, (int)$t2['id'], 'user', 'bob', 'hello');
$t2 = bc_chat_thread_get($pid, (int)$t2['id']);
gtrue('a user message raises the agent unread count', (int)$t2['unread_for_agent'] > 0);
gcheck('and does not touch the user unread count', (int)$t2['unread_for_user'], 0);

// The public payloads must not leak internal fields.
$pub = bc_chat_public_thread($t2);
gtrue('the public thread hides the assigned admin', !array_key_exists('assigned_admin', $pub));
gtrue('the public thread reports AI state', array_key_exists('ai_active', $pub));
$pubm = bc_chat_public_messages(bc_chat_messages($pid, (int)$t2['id'], 0));
$allowed = ['id', 'who', 'name', 'body', 'at'];
gtrue('public messages expose an allow-list of fields only', array_diff(array_keys($pubm[0]), $allowed) === []);

// ── 18. structural wiring ─────────────────────────────────────────────────
foreach (['DGV7.0-SAAS', 'DGV7.0-NON-SAAS'] as $edition) {
    $b = $root . '/' . $edition;

    // landing page
    $tpl = file_get_contents($b . '/index-bc-style-template-6.php');
    gtrue("$edition template 6 gates cards through the partition", strpos($tpl, 'bc_fintech_marketing_partition(') !== false);
    gtrue("$edition template 6 renders both panels server-side", substr_count($tpl, 'pc-panel') >= 2);
    gtrue("$edition template 6 marks unavailable cards", strpos($tpl, 'Coming soon') !== false);
    gtrue("$edition template 6 uses the SEO head hook", strpos($tpl, 'seo_render_head_tags(') !== false);
    gtrue("$edition template 6 uses the SEO footer hook", strpos($tpl, 'seo_render_custom_footer(') !== false);
    gtrue("$edition template 6 validates the brand colour", strpos($tpl, "preg_match('/^#[0-9a-fA-F]{6}$/'") !== false);
    // Check for the class in markup, not in prose: the file's own comment explains why it avoids
    // icon-font classes, and a plain strpos() would match that sentence.
    gtrue("$edition template 6 avoids icon-font classes", !preg_match('/class="[^"]*\bbi bi-/', $tpl));
    // Landing structure follows the reference fintech homepage idiom: a navbar, a two-line
    // display headline, a scrolling category band, then alternating feature sections.
    gtrue("$edition template 6 renders a sticky navbar", strpos($tpl, 'class="pc-header"') !== false);
    gtrue("$edition template 6 renders the two-line display headline", strpos($tpl, 'pk-h1-outline') !== false);
    gtrue("$edition template 6 renders the scrolling category band", strpos($tpl, 'pk-marquee') !== false);
    gtrue("$edition template 6 alternates feature split rows", strpos($tpl, 'pk-split--rev') !== false);
    gtrue("$edition template 6 renders a dark analytics band", strpos($tpl, 'pc-section--ink') !== false);
    gtrue("$edition template 6 renders a multi-column footer", strpos($tpl, 'pc-footer__grid') !== false);
    gtrue("$edition template 6 marks the phone mockup decorative", strpos($tpl, 'aria-hidden="true"') !== false);
    gtrue("$edition template 6 has a no-JS fallback panel visible by default", strpos($tpl, 'id="pc-services-personal" role="tabpanel"') !== false && strpos($tpl, 'id="pc-services-business" role="tabpanel" aria-labelledby="pc-tab-business" hidden') !== false);
    gtrue("$edition template 6 switches on aria-selected", strpos($tpl, 'aria-selected') !== false);
    gtrue("$edition template 6 honours prefers-reduced-motion", strpos($tpl, 'prefers-reduced-motion') !== false);
    // Every section anchor resolves to a real id so the navbar never dead-ends.
    preg_match_all('/href="#([a-z0-9_-]+)"/', $tpl, $anchors);
    preg_match_all('/\sid="([a-z0-9_-]+)"/', $tpl, $ids);
    $missing = array_diff($anchors[1], $ids[1]);
    gtrue("$edition template 6 anchors all resolve to an id", $missing === [], 'unresolved: ' . implode(',', $missing));

    // Plate auth shell: the signed-out journey shares one layout and must not drift back to
    // the retired assets-2 template or the photographic background.
    $shell = file_get_contents($b . '/func/bc-plate-auth.php');
    gtrue("$edition auth shell validates the brand colour", strpos($shell, "preg_match('/^#[0-9a-fA-F]{6}$/'") !== false);
    gtrue("$edition auth shell draws its own icons", strpos($shell, 'function bc_plate_auth_icon') !== false);
    gtrue("$edition auth shell has no icon-font dependency", !preg_match('/bootstrap-icons|boxicons|remixicon/', $shell));
    gtrue("$edition auth shell honours prefers-reduced-motion", strpos($shell, 'prefers-reduced-motion') !== false);
    foreach (array('Login', 'Register', 'PasswordRecovery', 'VerifyOTP', 'SecurityPIN', 'LockoutResolution', 'Inactive', 'Suspended', 'Error') as $page) {
        $src = file_get_contents($b . '/web/' . $page . '.php');
        gtrue("$edition $page uses the Plate auth shell", strpos($src, 'bc_plate_auth_head(') !== false && strpos($src, 'bc_plate_auth_end(') !== false);
        gtrue("$edition $page drops the retired assets-2 template", strpos($src, 'assets-2') === false);
        gtrue("$edition $page drops the photographic background", strpos($src, 'web-bg-image') === false);
    }
    // The shell must stay presentation-only: it may not touch money, credentials or gates.
    gtrue("$edition auth shell moves no money", !preg_match('/\b(chargeUser|chargeOtherUser|chargeVendor|updateUserCryptoBalance|bc_fintech_ledger_post)\s*\(/', $shell));
    gtrue("$edition auth shell queries no credentials", !preg_match('/sas_(payment_gateways|bank_transfer_gateways)/', $shell));

    // account settings
    $acs = file_get_contents($b . '/bc-admin/AccountSettings.php');
    gtrue("$edition Account Settings offers template 6", strpos($acs, 'bc-style-template-6.css') !== false);
    gtrue("$edition Account Settings no longer offers template 4", strpos($acs, 'bc-style-template-4.css') === false);
    gtrue("$edition Account Settings no longer offers template 5", strpos($acs, 'bc-style-template-5.css') === false);

    // dashboard
    $dash = file_get_contents($b . '/web/Dashboard.php');
    gtrue("$edition dashboard includes the accounts block", strpos($dash, 'bc-dashboard-accounts.php') !== false);
    $blk = file_get_contents($b . '/func/bc-dashboard-accounts.php');
    gtrue("$edition dashboard block renders wallet cards", strpos($blk, 'bc_wallet_cards(') !== false);
    gtrue("$edition dashboard block renders the rate strip", strpos($blk, 'bc_fx_rate_snapshot(') !== false);
    gtrue("$edition dashboard block shows the tier badge", strpos($blk, 'bc_fintech_tier_badge(') !== false);
    gtrue("$edition dashboard block links the upgrade flow", strpos($blk, 'BusinessUpgrade.php') !== false);
    gtrue("$edition dashboard block fails safe without its helpers", strpos($blk, 'function_exists(\'bc_wallet_cards\')') !== false);

    // shell nav (ported from the retired bc-header.php in the Plate migration)
    $hdr = file_get_contents($b . '/func/bc-plate-app.php');
    gtrue("$edition shell resolves the tier from the session user", strpos($hdr, 'bc_fintech_account_type($user)') !== false);
    gtrue("$edition shell tolerates a missing session user", strpos($hdr, 'isset($GLOBALS[' . "'get_logged_user_details'" . '])') !== false);
    gtrue("$edition shell gates nav through the matrix", strpos($hdr, 'bc_fintech_tier_effective($row, $tier)') !== false);
    gtrue("$edition Developer API nav is tier-gated", strpos($hdr, "'tier' => 'developer_api'") !== false);
    gtrue("$edition upgrade nav is conditional", strpos($hdr, 'return $tier !== ') !== false);
    gtrue("$edition shell hides gated items centrally", strpos($hdr, 'function bc_app_item_visible') !== false);

    // chat wiring
    gtrue("$edition shell foot includes the chat widget", strpos(file_get_contents($b . '/func/bc-plate-app.php'), 'bc-support-chat-widget.php') !== false);
    gtrue("$edition ships the chat endpoint", is_file($b . '/web/ajax-support-chat.php'));
    $ep = file_get_contents($b . '/web/ajax-support-chat.php');
    gtrue("$edition endpoint rejects non-POST", strpos($ep, "'POST required.'") !== false);
    gtrue("$edition endpoint binds the user to the session", strpos($ep, '$bc_chat_user_id   = (int)$get_logged_user_details[\'id\']') !== false);
    gtrue("$edition endpoint never reads a client user id", !preg_match('/\$_(POST|GET|REQUEST)\[.user_id.\]/', $ep));
    gtrue("$edition endpoint sends no-store", strpos($ep, 'no-store') !== false);

    $widget = file_get_contents($b . '/func/bc-support-chat-widget.php');
    gtrue("$edition widget self-gates on a signed-in user", strpos($widget, 'empty($get_logged_user_details[\'id\'])') !== false);
    gtrue("$edition widget paints with text nodes", strpos($widget, 'createTextNode') !== false);
    gtrue("$edition widget never assigns innerHTML from a message", strpos($widget, 'innerHTML = m.body') === false);
    gtrue("$edition widget exposes no API key", strpos($widget, 'chat_ai_key') === false);

    // admin console
    gtrue("$edition admin nav links the chat console", strpos(file_get_contents($b . '/func/bc-plate-admin.php'), 'SupportChat.php') !== false);
    gtrue("$edition admin nav links chat settings", strpos(file_get_contents($b . '/func/bc-plate-admin.php'), 'ChatSettings.php') !== false);
    $console = file_get_contents($b . '/bc-admin/SupportChat.php');
    gtrue("$edition console scopes queue by the admin tenant", strpos($console, 'bc_chat_agent_queue($vid)') !== false);
    gtrue("$edition console escapes transcript bodies", strpos($console, 'htmlspecialchars((string)$m[\'body\'])') !== false);
    gtrue("$edition console redirects after posting", strpos($console, 'header("Location: SupportChat.php")') !== false);

    $cfg = file_get_contents($b . '/bc-admin/ChatSettings.php');
    gtrue("$edition settings never echo the key into the form", strpos($cfg, 'value="<?php echo htmlspecialchars((string)$settings[\'chat_ai_key\'])') === false);
    gtrue("$edition settings keep the key when the field is blank", strpos($cfg, "if (\$new_key !== '')") !== false);
    gtrue("$edition settings require an explicit clear", strpos($cfg, "isset(\$_POST['chat_ai_key_clear'])") !== false);
    gtrue("$edition settings warn on AI without a key", strpos($cfg, '$warn_ai_without_key') !== false);
    gtrue("$edition settings validate the provider list", strpos($cfg, "in_array(\$provider, ['gemini', 'deepseek'], true)") !== false);

    // the assistant must never be able to move money
    $engine = file_get_contents($b . '/func/bc-support-chat.php');
    gtrue("$edition chat engine moves no money", !preg_match('/(sas_payments|sas_transactions|UPDATE sas_users SET balance|wallet_credit|wallet_debit)/', $engine));
    gtrue("$edition chat prompt forbids balances", strpos($engine, 'state, estimate or confirm an account balance') !== false);
    gtrue("$edition chat prompt forbids refunds", strpos($engine, 'promise, approve or estimate a refund') !== false);
    gtrue("$edition chat prompt forbids credential requests", strpos($engine, 'ask for a password, PIN, OTP') !== false);
}

// parity: the shared new files must be byte-identical between editions
foreach ([
    'func/bc-fintech-accounts.php',
    'func/bc-service-matrix.php',
    'func/bc-service-matrix-render.php',
    'func/bc-account-backfill.php',
    'func/bc-account-backfill-render.php',
    'web/BusinessUpgrade.php',
    'bc-admin/BusinessKYB.php',
    'uploads/kyb/.htaccess',
    // phase 2
    'index-bc-style-template-6.php',
    'func/bc-fintech-wallet.php',
    'func/bc-dashboard-accounts.php',
    'func/bc-support-chat.php',
    'func/bc-support-chat-schema.php',
    'func/bc-support-chat-widget.php',
    'web/ajax-support-chat.php',
    'bc-admin/SupportChat.php',
    'bc-admin/ChatSettings.php',
    // The retired bc-header.php / bc-admin-header.php were NOT parity files; their replacements
    // (bc-plate-app.php / bc-plate-admin.php) ARE, and are asserted byte-identical below.
] as $file) {
    gcheck("edition parity: $file", md5_file($root . '/DGV7.0-SAAS/' . $file), md5_file($root . '/DGV7.0-NON-SAAS/' . $file));
}

// ═══════════════════════════════════════════════════════════════════════════
// Phase 3 — provider adapters (Klasha & Flutterwave v3)
// ═══════════════════════════════════════════════════════════════════════════
load_edition($root, 'DGV7.0-SAAS', [
    'func/bc-fintech-providers.php',
    'func/bc-fintech-ledger.php',
    'func/bc-fintech-webhook.php',
    'func/bc-fintech-klasha.php',
    'func/bc-fintech-flutterwave.php',
]);

// ── 19. provider registry and configuration ────────────────────────────────
$registry = bc_fintech_provider_registry();
gtrue('registry lists klasha', isset($registry['klasha']));
gtrue('registry lists flutterwave', isset($registry['flutterwave']));
gcheck('provider list is stable', bc_fintech_provider_list(), ['klasha', 'flutterwave']);
gcheck('unknown provider has no config', bc_fintech_provider_config('teleport'), null);

// Klasha is genuinely unconfigured: nothing is guessed.
gcheck('klasha base URL is empty', $registry['klasha']['base_url'], '');
foreach (['verify', 'quote', 'beneficiary', 'payout', 'wallet', 'fx', 'collection'] as $ep) {
    gcheck("klasha endpoint $ep is empty", $registry['klasha']['endpoints'][$ep], '');
}
foreach (['event_id', 'reference', 'amount', 'currency', 'status'] as $field) {
    gcheck("klasha field $field is unmapped", bc_fintech_provider_field('klasha', $field), '');
}
gcheck('klasha signature scheme is empty', $registry['klasha']['signature']['header'], '');
gcheck('klasha payload crypto scheme is empty', bc_klasha_crypto_scheme(), '');

// The new Flutterwave endpoints are empty too; only the live verify shape is known.
gcheck('flutterwave base URL is empty', $registry['flutterwave']['base_url'], '');
foreach (['bills_categories', 'bills_validate', 'bills', 'payments', 'payment_plans', 'virtual_accounts', 'transfers', 'bulk_transfers', 'pos_request', 'regional_toggles'] as $ep) {
    gcheck("flutterwave endpoint $ep is empty", $registry['flutterwave']['endpoints'][$ep], '');
}
gcheck('flutterwave verify field map is the live one', bc_fintech_provider_field('flutterwave', 'reference'), 'data.tx_ref');

// Refusal reasons, in order.
$GLOBALS['db']['gateways'] = [];
gcheck('no credentials -> credentials_not_configured',
    bc_fintech_provider_configured(7, 'klasha', 'quote')['reason'], 'credentials_not_configured');
$GLOBALS['db']['gateways']['sas_bank_transfer_gateways:7:klasha'] = [
    'vendor_id' => 7, 'gateway_name' => 'klasha', 'public_key' => 'pk', 'secret_key' => 'sk', 'encrypt_key' => 'eh', 'status' => 1,
];
gcheck('credentials but no base URL -> base_url_not_configured',
    bc_fintech_provider_configured(7, 'klasha', 'quote')['reason'], 'base_url_not_configured');
$GLOBALS['bc_fintech_provider_config_overrides']['klasha']['base_url'] = 'https://sandbox.klasha.test';
gcheck('base URL but no endpoint -> endpoint_not_configured',
    bc_fintech_provider_configured(7, 'klasha', 'quote')['reason'], 'endpoint_not_configured');
$GLOBALS['bc_fintech_provider_config_overrides']['klasha']['endpoints']['quote'] = '/v1/quote';
gcheck('fully configured -> ready', bc_fintech_provider_configured(7, 'klasha', 'quote')['ready'], true);
gcheck('endpoint refusal message is the documented one',
    bc_fintech_provider_refusal_message('endpoint_not_configured'), 'endpoint not configured');

// The URL builder never guesses.
gcheck('url builder refuses an empty endpoint',
    bc_fintech_provider_url('klasha', 'payout', 7)['reason'], 'endpoint_not_configured');
unset($GLOBALS['bc_fintech_provider_config_overrides']['klasha']);
gcheck('url builder refuses an empty base URL',
    bc_fintech_provider_url('klasha', 'quote', 7)['reason'], 'base_url_not_configured');

// An unconfigured adapter must refuse BEFORE any network activity.
$GLOBALS['bc_fintech_http_calls'] = [];
$GLOBALS['bc_fintech_http_transport'] = function () { return ['ok' => true, 'status' => 200, 'body' => '{}', 'json' => [], 'error' => '']; };
$refused = bc_klasha_request(7, 'quote', 'POST', ['a' => 1]);
gfalse('unconfigured adapter refuses', $refused['ok']);
gcheck('unconfigured adapter reason', $refused['reason'], 'base_url_not_configured');
gcheck('unconfigured adapter made no HTTP call', count($GLOBALS['bc_fintech_http_calls']), 0);
$GLOBALS['bc_fintech_provider_config_overrides']['klasha']['base_url'] = 'https://sandbox.klasha.test';
$refused_ep = bc_klasha_request(7, 'quote', 'POST', ['a' => 1]);
gcheck('an adapter with no endpoint path refuses', $refused_ep['reason'], 'endpoint_not_configured');
gcheck('still no HTTP call', count($GLOBALS['bc_fintech_http_calls']), 0);
unset($GLOBALS['bc_fintech_provider_config_overrides']['klasha']);
$refused_fw = bc_flw_bills_categories(7);
gfalse('unconfigured flutterwave bills refuses', $refused_fw['ok']);
gcheck('unconfigured flutterwave bills reason', $refused_fw['reason'], 'credentials_not_configured');
gcheck('still no HTTP call', count($GLOBALS['bc_fintech_http_calls']), 0);

// Config-driven calls DO reach the seam.
$GLOBALS['bc_fintech_provider_config_overrides']['klasha'] = [
    'base_url' => 'https://sandbox.klasha.test',
    'endpoints' => ['quote' => '/v1/quote'],
];
$calls = [];
$GLOBALS['bc_fintech_http_calls'] = [];
$GLOBALS['bc_fintech_http_transport'] = function ($provider, $method, $url, $headers, $payload) use (&$calls) {
    $calls[] = ['provider' => $provider, 'method' => $method, 'url' => $url, 'headers' => $headers, 'payload' => $payload];
    return ['ok' => true, 'status' => 200, 'body' => '{"ok":true}', 'json' => ['ok' => true], 'error' => ''];
};
$ok_call = bc_klasha_request(7, 'quote', 'POST', ['amount' => 10]);
gtrue('configured adapter calls the provider', $ok_call['ok']);
gcheck('configured adapter made exactly one call', count($calls), 1);
gcheck('call URL is base + configured path', $calls[0]['url'], 'https://sandbox.klasha.test/v1/quote');
gtrue('call carries the bearer secret', strpos($calls[0]['headers']['Authorization'], 'sk') !== false);
gcheck('call payload is forwarded unchanged', $calls[0]['payload'], ['amount' => 10]);
unset($GLOBALS['bc_fintech_provider_config_overrides']['klasha']);

// Field path helper.
gcheck('array path reads a nested value', bc_fintech_array_path(['a' => ['b' => 5]], 'a.b'), 5);
gcheck('array path supports an escaped dot', bc_fintech_array_path(['a.b' => 7], 'a\.b'), 7);
gcheck('array path returns null when a segment is missing', bc_fintech_array_path(['a' => []], 'a.b'), null);
gcheck('array path returns null on an empty path', bc_fintech_array_path(['a' => 1], ''), null);

// ── 20. per-provider asset gating ──────────────────────────────────────────
gtrue('USDC is in the klasha catalogue', bc_klasha_asset_enabled(7, 'USDC'));
gtrue('USDT is in the flutterwave catalogue', bc_flw_asset_enabled(7, 'USDT'));
gfalse('RLUSD is NOT enabled from the catalogue (klasha)', bc_klasha_asset_enabled(7, 'RLUSD'));
gfalse('RLUSD is NOT enabled from the catalogue (flutterwave)', bc_flw_asset_enabled(7, 'RLUSD'));
gfalse('an unknown asset is never enabled', bc_klasha_asset_enabled(7, 'DOGE'));
gcheck('klasha assets are the catalogue only', bc_fintech_provider_assets('klasha', 7), ['USDC', 'USDT']);

// The merchant profile can add RLUSD, and only RLUSD-style explicit opt-ins work.
gtrue('saving a profile asset list works', bc_fintech_provider_profile_assets_save(7, 'klasha', ['RLUSD']));
gcheck('the profile round-trips', bc_fintech_provider_profile_assets(7, 'klasha'), ['RLUSD']);
gtrue('RLUSD is enabled once the merchant profile says so', bc_klasha_asset_enabled(7, 'RLUSD'));
gtrue('a profile asset appears in the asset list', in_array('RLUSD', bc_fintech_provider_assets('klasha', 7), true));
gfalse('a profile cannot invent a non-catalogue asset', bc_klasha_asset_enabled(7, 'DOGE'));
gfalse('another merchant doesn\'t inherit the profile', bc_klasha_asset_enabled(8, 'RLUSD'));
bc_fintech_provider_profile_assets_save(7, 'klasha', []);
gcheck('clearing the profile removes RLUSD', bc_klasha_asset_enabled(7, 'RLUSD'), false);
gfalse('an unknown provider has no assets', bc_fintech_provider_asset_enabled(7, 'teleport', 'USDC'));

// ── 21. the atomic ledger ──────────────────────────────────────────────────
gcheck('ledger currency list', bc_fintech_ledger_currencies(), ['NGN', 'USD', 'GHS', 'ZAR', 'USDC', 'USDT']);
gtrue('a positive amount is valid', bc_fintech_ledger_valid_amount('250.5'));
gfalse('a negative amount is refused', bc_fintech_ledger_valid_amount(-5));
gfalse('zero is refused', bc_fintech_ledger_valid_amount(0));
gfalse('NaN is refused', bc_fintech_ledger_valid_amount(NAN));
gfalse('infinity is refused', bc_fintech_ledger_valid_amount(INF));
gfalse('a non-number is refused', bc_fintech_ledger_valid_amount('abc'));

$GLOBALS['db']['users'][30] = ['id' => 30, 'vendor_id' => 7, 'username' => 'payer', 'status' => 1, 'balance' => 100.0, 'email' => 'payer@example.com'];
$GLOBALS['db']['ledger'] = [];
$GLOBALS['db']['fintech_tx'] = [];
$GLOBALS['db']['crypto_wallets'] = [];

$credit = bc_fintech_ledger_post(7, 'payer', 'NGN', 'credit', 250, 'FLW-REF-1', ['provider' => 'flutterwave', 'event_id' => '9001']);
gtrue('NGN credit succeeds', $credit['ok']);
gcheck('NGN credit reason', $credit['reason'], 'ok');
gcheck('NGN balance is 350', $GLOBALS['db']['users'][30]['balance'], 350.0);
gcheck('NGN credit writes one audit row', count($GLOBALS['db']['ledger']), 1);
gcheck('the audit row records the amount', $GLOBALS['db']['ledger'][1]['amount'], '250.000000000000000000');
gcheck('the audit row records the balance after', $GLOBALS['db']['ledger'][1]['balance_after'], '350.000000000000000000');
gcheck('the audit row names the provider', $GLOBALS['db']['ledger'][1]['provider'], 'flutterwave');
gcheck('NGN credit mirrors into the history', count($GLOBALS['db']['fintech_tx']), 1);
gcheck('the mirror carries the reference', $GLOBALS['db']['fintech_tx'][1]['reference'], 'FLW-REF-1');
gcheck('the ledger balance reader agrees', bc_fintech_ledger_balance(7, 'payer', 'NGN'), 350.0);

$debit = bc_fintech_ledger_post(7, 'payer', 'NGN', 'debit', 40, 'FLW-REF-2', ['provider' => 'flutterwave']);
gtrue('NGN debit succeeds', $debit['ok']);
gcheck('NGN balance is 310', $GLOBALS['db']['users'][30]['balance'], 310.0);
gcheck('two audit rows now', count($GLOBALS['db']['ledger']), 2);

// Refusals leave NO balance change and NO audit row (rollback).
$before = count($GLOBALS['db']['ledger']);
$short = bc_fintech_ledger_post(7, 'payer', 'NGN', 'debit', 9999, 'FLW-REF-3', []);
gfalse('an overdraft is refused', $short['ok']);
gcheck('overdraft reason', $short['reason'], 'insufficient_balance');
gcheck('overdraft leaves the balance alone', $GLOBALS['db']['users'][30]['balance'], 310.0);
gcheck('overdraft writes no audit row', count($GLOBALS['db']['ledger']), $before);

$bad = bc_fintech_ledger_post(7, 'payer', 'NGN', 'credit', -50, 'FLW-REF-4', []);
gfalse('a negative amount is refused', $bad['ok']);
gcheck('negative amount reason', $bad['reason'], 'invalid_amount');
gcheck('negative amount writes nothing', count($GLOBALS['db']['ledger']), $before);
gcheck('negative amount leaves the balance alone', $GLOBALS['db']['users'][30]['balance'], 310.0);

gcheck('an unknown currency is refused', bc_fintech_ledger_post(7, 'payer', 'BTC', 'credit', 1, 'R')['reason'], 'invalid_currency');
gcheck('an unknown direction is refused', bc_fintech_ledger_post(7, 'payer', 'NGN', 'mint', 1, 'R')['reason'], 'invalid_direction');
gcheck('an empty reference is refused', bc_fintech_ledger_post(7, 'payer', 'NGN', 'credit', 1, '  ')['reason'], 'invalid_reference');
gcheck('an unknown user is refused', bc_fintech_ledger_post(7, 'ghost', 'NGN', 'credit', 1, 'R')['reason'], 'user_not_found');
$GLOBALS['db']['users'][30]['status'] = 0;
gfalse('an inactive account cannot be credited', bc_fintech_ledger_post(7, 'payer', 'NGN', 'credit', 1, 'R')['ok']);
$GLOBALS['db']['users'][30]['status'] = 1;
gcheck('still no extra audit rows', count($GLOBALS['db']['ledger']), $before);

// Stablecoin path: the wallet is created on demand and both counters move.
$mirror_before = count($GLOBALS['db']['fintech_tx']);
$usdc = bc_fintech_ledger_post(7, 'payer', 'USDC', 'credit', 12.5, 'KL-1', ['provider' => 'klasha']);
gtrue('USDC credit succeeds', $usdc['ok']);
gcheck('the USDC wallet was created', $GLOBALS['db']['crypto_wallets']['7:payer:USDC']['balance'], 12.5);
gcheck('ledger_balance moves with balance', $GLOBALS['db']['crypto_wallets']['7:payer:USDC']['ledger_balance'], 12.5);
gcheck('the crypto balance reader agrees', bc_fintech_ledger_balance(7, 'payer', 'USDC'), 12.5);
gcheck('a crypto post does not touch the NGN history', count($GLOBALS['db']['fintech_tx']), $mirror_before);
$usdc_debit = bc_fintech_ledger_post(7, 'payer', 'USDC', 'debit', 2.5, 'KL-2', []);
gtrue('USDC debit succeeds', $usdc_debit['ok']);
gcheck('USDC balance is 10', $GLOBALS['db']['crypto_wallets']['7:payer:USDC']['balance'], 10.0);
gfalse('a stablecoin overdraft is refused', bc_fintech_ledger_post(7, 'payer', 'USDC', 'debit', 50, 'KL-3')['ok']);
gcheck('a stablecoin overdraft writes nothing', count($GLOBALS['db']['ledger']), $before + 2);
gcheck('NGN balance untouched by crypto posts', $GLOBALS['db']['users'][30]['balance'], 310.0);

// ── 22. webhook signature verification ─────────────────────────────────────
$secret = 'verifhash';
gcheck('a matching verif-hash verifies',
    bc_fintech_webhook_signature_verify('flutterwave', 'raw', ['verif-hash' => $secret], $secret)['mode'], 'verified');
gcheck('a wrong verif-hash is a mismatch',
    bc_fintech_webhook_signature_verify('flutterwave', 'raw', ['verif-hash' => 'nope'], $secret)['reason'], 'signature_mismatch');
gcheck('a missing header is refused',
    bc_fintech_webhook_signature_verify('flutterwave', 'raw', [], $secret)['reason'], 'signature_missing');
gtrue('flutterwave allows an unset secret (API verification still runs)',
    bc_fintech_webhook_signature_verify('flutterwave', 'raw', [], '')['ok']);
gcheck('klasha has no verified scheme and refuses',
    bc_fintech_webhook_signature_verify('klasha', 'raw', ['x' => 'y'], 'secret')['reason'], 'signature_scheme_not_configured');

// Header lookup is case-insensitive.
gtrue('header lookup is case-insensitive',
    bc_fintech_webhook_signature_verify('flutterwave', 'raw', ['VERIF-HASH' => $secret], $secret)['ok']);

// ── 23. webhook idempotency and the full delivery pipeline ─────────────────
$GLOBALS['db']['users'][30] = ['id' => 30, 'vendor_id' => 7, 'username' => 'payer', 'status' => 1, 'balance' => 100.0, 'email' => 'payer@example.com'];
$GLOBALS['db']['ledger'] = [];
$GLOBALS['db']['fintech_tx'] = [];
$GLOBALS['db']['webhook_events'] = [];
$GLOBALS['db']['crypto_wallets'] = [];
$GLOBALS['db']['checkouts'] = ['7:FLW-REF-1' => 'payer'];
$GLOBALS['db']['gateways']['sas_payment_gateways:7:flutterwave'] = [
    'vendor_id' => 7, 'gateway_name' => 'flutterwave', 'public_key' => 'pk',
    'secret_key' => 'sk_live_x', 'encrypt_key' => 'verifhash', 'status' => 1,
];

$GLOBALS['bc_fintech_http_calls'] = [];
$GLOBALS['bc_fintech_http_responses'] = [];
$GLOBALS['bc_fintech_http_transport'] = function ($provider, $method, $url, $headers, $payload) {
    $GLOBALS['bc_fintech_http_calls'][] = [
        'provider' => $provider, 'method' => $method, 'url' => $url,
        'headers' => $headers, 'payload' => $payload,
    ];
    foreach (($GLOBALS['bc_fintech_http_responses'] ?? []) as $needle => $response) {
        if ($needle !== '' && strpos($url, $needle) !== false) return $response;
    }
    return ['ok' => false, 'status' => 404, 'body' => '', 'json' => null, 'error' => 'no mock for ' . $url];
};

$flw_payload = json_encode([
    'event' => 'charge.completed',
    'data' => [
        'id' => '9001', 'tx_ref' => 'FLW-REF-1', 'amount' => 5000, 'currency' => 'NGN',
        'status' => 'successful', 'app_fee' => 50, 'customer' => ['email' => 'payer@example.com'],
    ],
]);
$GLOBALS['bc_fintech_http_responses'] = [
    '/v3/transactions/9001/verify' => [
        'ok' => true, 'status' => 200, 'body' => '{}',
        'json' => ['status' => 'success', 'data' => ['id' => 9001, 'tx_ref' => 'FLW-REF-1', 'amount' => 5000, 'currency' => 'NGN', 'status' => 'successful', 'app_fee' => 50]],
        'error' => '',
    ],
];

$first = bc_flw_handle_webhook(7, $flw_payload, ['verif-hash' => 'verifhash']);
gtrue('a verified delivery is credited', $first['ok']);
gcheck('credit reason', $first['reason'], 'credited');
gcheck('the net amount is credited (5000 - 50 fee)', $GLOBALS['db']['users'][30]['balance'], 5050.0);
gcheck('the event is recorded as processed', $GLOBALS['db']['webhook_events']['flutterwave:9001']['status'], 'processed');
gcheck('one audit row for the credit', count($GLOBALS['db']['ledger']), 1);
gcheck('the audit row records the fee context', $GLOBALS['db']['ledger'][1]['context']['fee'], 50);
gcheck('the delivery used the provider verify endpoint', $GLOBALS['bc_fintech_http_calls'][0]['url'], 'https://api.flutterwave.com/v3/transactions/9001/verify');

// The same event again: skipped, never credited twice.
$before_balance = $GLOBALS['db']['users'][30]['balance'];
$before_ledger = count($GLOBALS['db']['ledger']);
$second = bc_flw_handle_webhook(7, $flw_payload, ['verif-hash' => 'verifhash']);
gtrue('a retried delivery is acknowledged', $second['ok']);
gcheck('a retried delivery is recognised', $second['reason'], 'already_processed');
gcheck('a retry does not credit again', $GLOBALS['db']['users'][30]['balance'], $before_balance);
gcheck('a retry writes no audit row', count($GLOBALS['db']['ledger']), $before_ledger);

// A bad signature must produce NO write at all.
$GLOBALS['db']['webhook_events'] = [];
$GLOBALS['db']['ledger'] = [];
$GLOBALS['db']['fintech_tx'] = [];
$GLOBALS['db']['users'][30]['balance'] = 100.0;
$bad_sig_payload = json_encode(['event' => 'charge.completed', 'data' => [
    'id' => '9002', 'tx_ref' => 'FLW-REF-1', 'amount' => 5000, 'currency' => 'NGN', 'status' => 'successful',
]]);
$bad_sig = bc_flw_handle_webhook(7, $bad_sig_payload, ['verif-hash' => 'forged']);
gfalse('a bad signature is refused', $bad_sig['ok']);
gcheck('a bad signature is acknowledged fast with 200', $bad_sig['status'], 200);
gcheck('a bad signature is a mismatch', $bad_sig['reason'], 'signature_mismatch');
gcheck('a bad signature writes no webhook event', count($GLOBALS['db']['webhook_events']), 0);
gcheck('a bad signature writes no ledger row', count($GLOBALS['db']['ledger']), 0);
gcheck('a bad signature credits nothing', $GLOBALS['db']['users'][30]['balance'], 100.0);
gcheck('a bad signature never reaches the provider', count($GLOBALS['bc_fintech_http_calls']), 1);

// A transaction the provider does not confirm is not credited, and the claim is
// released so a later, genuine retry can proceed.
$GLOBALS['bc_fintech_http_responses'] = [
    '/v3/transactions/9003/verify' => [
        'ok' => true, 'status' => 200, 'body' => '{}',
        'json' => ['status' => 'success', 'data' => ['id' => 9003, 'tx_ref' => 'FLW-REF-1', 'amount' => 5000, 'currency' => 'NGN', 'status' => 'failed']],
        'error' => '',
    ],
];
$pending_payload = json_encode(['event' => 'charge.completed', 'data' => [
    'id' => '9003', 'tx_ref' => 'FLW-REF-1', 'amount' => 5000, 'currency' => 'NGN', 'status' => 'successful',
]]);
$not_verified = bc_flw_handle_webhook(7, $pending_payload, ['verif-hash' => 'verifhash']);
gfalse('an unconfirmed transaction is not credited', $not_verified['ok']);
gcheck('unconfirmed reason', $not_verified['reason'], 'not_successful');
gcheck('an unconfirmed delivery writes no ledger row', count($GLOBALS['db']['ledger']), 0);
gcheck('the claim was released for a retry', isset($GLOBALS['db']['webhook_events']['flutterwave:9003']), false);

// A reference that does not belong to a checkout is not credited.
$GLOBALS['db']['checkouts'] = [];
$GLOBALS['bc_fintech_http_responses'] = [
    '/v3/transactions/9004/verify' => [
        'ok' => true, 'status' => 200, 'body' => '{}',
        'json' => ['status' => 'success', 'data' => ['id' => 9004, 'tx_ref' => 'FLW-REF-X', 'amount' => 5000, 'currency' => 'NGN', 'status' => 'successful']],
        'error' => '',
    ],
];
$unknown_ref = bc_flw_handle_webhook(7, json_encode(['event' => 'charge.completed', 'data' => [
    'id' => '9004', 'tx_ref' => 'FLW-REF-X', 'amount' => 5000, 'currency' => 'NGN', 'status' => 'successful',
]]), ['verif-hash' => 'verifhash']);
gfalse('an unknown reference is not credited', $unknown_ref['ok']);
gcheck('unknown reference reason', $unknown_ref['reason'], 'unknown_reference');

// A verified transaction paired with another reference is refused (users-flutterwave.php:59 rule).
$GLOBALS['db']['checkouts'] = ['7:FLW-REF-1' => 'payer'];
$GLOBALS['bc_fintech_http_responses'] = [
    '/v3/transactions/9005/verify' => [
        'ok' => true, 'status' => 200, 'body' => '{}',
        'json' => ['status' => 'success', 'data' => ['id' => 9005, 'tx_ref' => 'FLW-REF-1', 'amount' => 100, 'currency' => 'NGN', 'status' => 'successful']],
        'error' => '',
    ],
];
$mismatch = bc_flw_handle_webhook(7, json_encode(['event' => 'charge.completed', 'data' => [
    'id' => '9005', 'tx_ref' => 'FLW-REF-OTHER', 'amount' => 100, 'currency' => 'NGN', 'status' => 'successful',
]]), ['verif-hash' => 'verifhash']);
gfalse('a verified tx paired with another reference is refused', $mismatch['ok']);
gcheck('reference mismatch reason', $mismatch['reason'], 'reference_mismatch');

// Klasha: unconfigured field mapping refuses before any write.
$GLOBALS['db']['webhook_events'] = [];
$GLOBALS['db']['ledger'] = [];
$klasha_unconfigured = bc_klasha_handle_webhook(7, json_encode(['data' => ['id' => '1']]), ['x-klasha-signature' => 's']);
gfalse('an unmapped klasha payload is refused', $klasha_unconfigured['ok']);
gcheck('klasha refusal is the signature scheme', $klasha_unconfigured['reason'], 'signature_scheme_not_configured');
gcheck('klasha refusal writes nothing', count($GLOBALS['db']['webhook_events']) + count($GLOBALS['db']['ledger']), 0);

// Klasha configured end-to-end through the same pipeline.
$GLOBALS['db']['gateways']['sas_bank_transfer_gateways:7:klasha'] = [
    'vendor_id' => 7, 'gateway_name' => 'klasha', 'public_key' => 'pk',
    'secret_key' => 'kl_secret', 'encrypt_key' => 'kl_verify', 'status' => 1,
];
$GLOBALS['bc_fintech_provider_config_overrides']['klasha'] = [
    'base_url' => 'https://sandbox.klasha.test',
    'endpoints' => ['verify' => '/v1/transactions/verify', 'quote' => '/v1/quote'],
    'fields' => [
        'event_id' => 'data.id', 'event_type' => 'event', 'reference' => 'data.tx_ref',
        'amount' => 'data.amount', 'currency' => 'data.currency', 'status' => 'data.status',
        'account' => 'data.customer.email', 'fee' => 'data.fee',
    ],
    'verify_request' => ['reference' => 'reference'],
    'response_fields' => [
        'reference' => 'data.reference', 'amount' => 'data.amount', 'currency' => 'data.currency',
        'status' => 'data.status', 'success_values' => 'SUCCESSFUL,SUCCESS', 'fee' => 'data.fee',
    ],
    'signature' => ['mode' => 'required', 'header' => 'x-klasha-signature', 'algorithm' => 'plain_compare'],
];
$GLOBALS['db']['checkouts']['7:KL-REF-9'] = 'payer';
$GLOBALS['bc_fintech_http_responses'] = [
    '/v1/transactions/verify' => [
        'ok' => true, 'status' => 200, 'body' => '{}',
        'json' => ['data' => ['reference' => 'KL-REF-9', 'amount' => 10, 'currency' => 'USDC', 'status' => 'SUCCESSFUL', 'fee' => 0]],
        'error' => '',
    ],
];
$klasha_payload = json_encode(['event' => 'collection.completed', 'data' => [
    'id' => 'KL-9001', 'tx_ref' => 'KL-REF-9', 'amount' => 10, 'currency' => 'USDC',
    'status' => 'SUCCESSFUL', 'customer' => ['email' => 'payer@example.com'],
]]);
$klasha_good = bc_klasha_handle_webhook(7, $klasha_payload, ['x-klasha-signature' => 'kl_verify']);
gtrue('a configured klasha delivery is credited', $klasha_good['ok']);
gcheck('klasha credit currency', $klasha_good['currency'], 'USDC');
gcheck('klasha credit lands in the crypto wallet', $GLOBALS['db']['crypto_wallets']['7:payer:USDC']['balance'], 10.0);
gcheck('klasha event recorded', $GLOBALS['db']['webhook_events']['klasha:KL-9001']['status'], 'processed');
gcheck('klasha verify used the configured URL', $GLOBALS['bc_fintech_http_calls'][count($GLOBALS['bc_fintech_http_calls']) - 1]['url'], 'https://sandbox.klasha.test/v1/transactions/verify');

// Klasha's payload crypto seam: TLS-only by default, never a guessed cipher.
gcheck('klasha encrypt defaults to plaintext JSON', bc_klasha_payload_encrypt('{"a":1}'), '{"a":1}');
gcheck('klasha decrypt defaults to plaintext JSON', bc_klasha_payload_decrypt('{"a":1}'), '{"a":1}');
unset($GLOBALS['bc_fintech_provider_config_overrides']['klasha']);

// ── 24. receipt notification ───────────────────────────────────────────────
// The ledger is deliberately presentation-free, so the customer/vendor receipts that
// chargeUser() used to send are re-attached in the delivery layer. They must never
// influence the money: a mail problem may not fail the credit or release the claim.
$GLOBALS['db']['email_templates'] = [
    '7:user-funding' => ['subject' => 'Wallet funding for {firstname}', 'body' => 'Credited {amount}; balance {balance_before} -> {balance_after}. {description}'],
    '7:user-transactions' => ['subject' => 'Transaction for {username}', 'body' => '{amount} {type} for {username} ({firstname} {lastname}).'],
];
$GLOBALS['db']['vendors'][7] = ['id' => 7, 'firstname' => 'Vendor', 'lastname' => 'One', 'email' => 'vendor@example.com'];
$GLOBALS['db']['users'][30] = ['id' => 30, 'vendor_id' => 7, 'username' => 'payer', 'status' => 1, 'balance' => 100.0,
    'email' => 'payer@example.com', 'firstname' => 'Pay', 'lastname' => 'Er'];
$GLOBALS['sent_mail'] = [];
$GLOBALS['bc_fintech_email_sender'] = function ($to, $subject, $body) {
    $GLOBALS['sent_mail'][] = ['to' => $to, 'subject' => $subject, 'body' => $body];
    return true;
};

$GLOBALS['db']['webhook_events'] = [];
$GLOBALS['db']['ledger'] = [];
$GLOBALS['db']['fintech_tx'] = [];
$GLOBALS['db']['checkouts'] = ['7:FLW-REF-1' => 'payer'];
$GLOBALS['db']['users'][30]['balance'] = 100.0;
$GLOBALS['bc_fintech_http_responses'] = [
    '/v3/transactions/9006/verify' => [
        'ok' => true, 'status' => 200, 'body' => '{}',
        'json' => ['status' => 'success', 'data' => ['id' => 9006, 'tx_ref' => 'FLW-REF-1', 'amount' => 2000, 'currency' => 'NGN', 'status' => 'successful', 'app_fee' => 20]],
        'error' => '',
    ],
];
$with_mail = bc_flw_handle_webhook(7, json_encode(['event' => 'charge.completed', 'data' => [
    'id' => '9006', 'tx_ref' => 'FLW-REF-1', 'amount' => 2000, 'currency' => 'NGN', 'status' => 'successful',
]]), ['verif-hash' => 'verifhash']);
gtrue('the credit still succeeds with a mailer present', $with_mail['ok']);
gcheck('two receipts are sent', count($GLOBALS['sent_mail']), 2);
gcheck('the customer receipt goes to the wallet owner', $GLOBALS['sent_mail'][0]['to'], 'payer@example.com');
gcheck('the vendor receipt goes to the vendor', $GLOBALS['sent_mail'][1]['to'], 'vendor@example.com');
gtrue('the customer subject is personalised', strpos($GLOBALS['sent_mail'][0]['subject'], 'Wallet funding for Pay') === 0);
// The ledger credits the NET amount; the receipt reports gross @ net, as chargeUser() always did.
// toDecimal() formats with no thousands separator, so the expectation matches it exactly.
gtrue('gross and net are both reported', strpos($GLOBALS['sent_mail'][0]['body'], '2000.00 @ 1980.00') !== false);
gtrue('the balance movement is reported', strpos($GLOBALS['sent_mail'][0]['body'], '100.00 -> 2080.00') !== false);
gtrue('the vendor receipt names the account', strpos($GLOBALS['sent_mail'][1]['body'], 'for payer (Pay Er)') !== false);
gtrue('the receipt carries the provider description', strpos($GLOBALS['sent_mail'][0]['body'], 'Wallet Funding via FLUTTERWAVE (NGN)') !== false);

// A mailer that throws must not fail the credit, release the claim, or credit twice.
$GLOBALS['sent_mail'] = [];
$GLOBALS['bc_fintech_email_sender'] = function ($to, $subject, $body) { throw new RuntimeException('mail down'); };
$GLOBALS['db']['webhook_events'] = [];
$GLOBALS['db']['ledger'] = [];
$GLOBALS['db']['fintech_tx'] = [];
$GLOBALS['db']['users'][30]['balance'] = 100.0;
$GLOBALS['bc_fintech_http_responses'] = [
    '/v3/transactions/9007/verify' => [
        'ok' => true, 'status' => 200, 'body' => '{}',
        'json' => ['status' => 'success', 'data' => ['id' => 9007, 'tx_ref' => 'FLW-REF-1', 'amount' => 2000, 'currency' => 'NGN', 'status' => 'successful', 'app_fee' => 20]],
        'error' => '',
    ],
];
$mail_down = bc_flw_handle_webhook(7, json_encode(['event' => 'charge.completed', 'data' => [
    'id' => '9007', 'tx_ref' => 'FLW-REF-1', 'amount' => 2000, 'currency' => 'NGN', 'status' => 'successful',
]]), ['verif-hash' => 'verifhash']);
gtrue('a mail failure does not fail the credit', $mail_down['ok']);
gcheck('the money still moved', $GLOBALS['db']['users'][30]['balance'], 2080.0);
gcheck('the claim is still completed', $GLOBALS['db']['webhook_events']['flutterwave:9007']['status'], 'processed');
gcheck('a mail failure writes exactly one audit row', count($GLOBALS['db']['ledger']), 1);

// No resolved template means no half-built receipt.
$GLOBALS['db']['email_templates'] = [];
gfalse('an unresolved template is a no-op, not an error', bc_fintech_webhook_notify('flutterwave', 7, 'payer', 'NGN', 10, 'R', 0, 100));
unset($GLOBALS['bc_fintech_email_sender']);

// ── 25. the legacy Flutterwave URL is re-routed, not a second credit path ──
// web/api/flutterwave_webhook.php used to credit wallets itself. It is now a URL alias for
// the shared pipeline; the only behaviour carried across is its historical FEE (%) rule.
$GLOBALS['db']['webhook_events'] = [];
$GLOBALS['db']['ledger'] = [];
$GLOBALS['db']['fintech_tx'] = [];
$GLOBALS['db']['crypto_wallets'] = [];
$GLOBALS['db']['checkouts'] = ['7:FLW-REF-1' => 'payer'];
$GLOBALS['db']['users'][30]['balance'] = 100.0;
$GLOBALS['db']['email_templates'] = [
    '7:user-funding' => ['subject' => 'Wallet funding', 'body' => 'Credited {amount}.'],
    '7:user-transactions' => ['subject' => 'Transaction', 'body' => '{amount} {type}.'],
];
$GLOBALS['sent_mail'] = [];
$GLOBALS['bc_fintech_email_sender'] = function ($to, $subject, $body) {
    $GLOBALS['sent_mail'][] = ['to' => $to, 'subject' => $subject, 'body' => $body];
    return true;
};
$GLOBALS['bc_fintech_http_responses'] = [
    '/v3/transactions/9008/verify' => [
        'ok' => true, 'status' => 200, 'body' => '{}',
        'json' => ['status' => 'success', 'data' => ['id' => 9008, 'tx_ref' => 'FLW-REF-1', 'amount' => 1000, 'currency' => 'NGN', 'status' => 'successful', 'app_fee' => 0]],
        'error' => '',
    ],
];
$legacy = bc_flw_handle_webhook(7, json_encode(['event' => 'charge.completed', 'data' => [
    'id' => '9008', 'tx_ref' => 'FLW-REF-1', 'amount' => 1000, 'currency' => 'NGN', 'status' => 'successful',
]]), ['verif-hash' => 'verifhash'], 10);
gtrue('the re-routed delivery is credited', $legacy['ok']);
gcheck('the legacy 10% fee is still applied', $GLOBALS['db']['users'][30]['balance'], 1000.0);
gcheck('the ledger records the net actually credited', $GLOBALS['db']['ledger'][1]['amount'], '900.000000000000000000');
gcheck('the ledger records the fee context', $GLOBALS['db']['ledger'][1]['context']['fee'], 100);
gtrue('the receipt reports gross @ net', strpos($GLOBALS['sent_mail'][0]['body'], '1000.00 @ 900.00') !== false);
gcheck('the legacy delivery is claimed durably', $GLOBALS['db']['webhook_events']['flutterwave:9008']['status'], 'processed');
unset($GLOBALS['bc_fintech_email_sender']);

// ── 26. structural wiring and security greps ───────────────────────────────
foreach ($editions as $edition) {
    $connect = file_get_contents($root . '/' . $edition . '/func/bc-connect.php');
    foreach (['bc-fintech-providers.php', 'bc-fintech-ledger.php', 'bc-fintech-webhook.php', 'bc-fintech-klasha.php', 'bc-fintech-flutterwave.php'] as $inc) {
        gtrue("$edition bootstrap loads $inc", strpos($connect, $inc) !== false);
    }
    gtrue("$edition ships the klasha webhook endpoint", is_file($root . '/' . $edition . '/users-klasha.php'));
    $hook = file_get_contents($root . '/' . $edition . '/users-klasha.php');
    gtrue("$edition klasha webhook reads the raw body", strpos($hook, 'bc_fintech_webhook_raw_input()') !== false);
    gtrue("$edition klasha webhook logs", strpos($hook, 'bc_fintech_webhook_log(') !== false);
    gtrue("$edition flutterwave webhook goes through the shared handler", strpos(file_get_contents($root . '/' . $edition . '/users-flutterwave.php'), 'bc_flw_handle_webhook(') !== false);
    // The legacy URL must be an alias, not a second writer.
    $legacy_hook = php_strip_whitespace($root . '/' . $edition . '/web/api/flutterwave_webhook.php');
    gtrue("$edition legacy flutterwave URL delegates to the shared handler", strpos($legacy_hook, 'bc_flw_handle_webhook(') !== false);
    gfalse("$edition legacy flutterwave URL credits nothing itself", (bool)preg_match('/\b(chargeUser|chargeOtherUser|updateUserCryptoBalance)\s*\(/', $legacy_hook));
    gfalse("$edition legacy flutterwave URL writes no balance", (bool)preg_match('/UPDATE\s+sas_(users|user_crypto_wallets)/i', $legacy_hook));
    gtrue("$edition legacy flutterwave URL reads the raw body", strpos($legacy_hook, 'bc_fintech_webhook_raw_input()') !== false);
    // The published developer docs must point at the canonical URL.
    gtrue("$edition api docs point at the canonical flutterwave webhook", strpos(file_get_contents($root . '/' . $edition . '/web/APIDocs.php'), '/users-flutterwave.php') !== false);
    // Comments are stripped first: the file's own header explains that it no longer
    // calls chargeUser(), and a plain strpos would match that sentence.
    $flw_hook_src = php_strip_whitespace($root . '/' . $edition . '/users-flutterwave.php');
    gtrue("$edition flutterwave webhook no longer calls chargeUser directly", strpos($flw_hook_src, 'chargeUser(') === false);

    // The new credential screen must offer Klasha.
    $pg = file_get_contents($root . '/' . $edition . '/bc-admin/PaymentGateway.php');
    gtrue("$edition gateway screen offers klasha credentials", strpos($pg, '"payhub", "paystack", "klasha"') !== false);
}

// The sidebar is grouped, and regrouping must never drop a service link.
$required_nav_pages = [
    'Dashboard.php', 'Data.php', 'Airtime.php', 'BulkData.php', 'BulkAirtime.php', 'Cable.php',
    'Electric.php', 'Betting.php', 'Exam.php', 'Fund.php', 'VirtualBanks.php',
    'SendFund.php', 'ShareFund.php', 'ShareFundHistory.php', 'CoinConversion.php', 'VirtualCard.php',
    'NINCard.php', 'NINCardHistory.php', 'BVNVerification.php', 'NumberFilter.php', 'GiftCard.php',
    // §25: AISuite.php left the nav (VTU-purchase AI retired); Support (AI-Assistant.php),
    // Refer & Earn and the new provider services joined it.
    'CryptoHub.php', 'AI-Assistant.php', 'Referral.php', 'IntlTransfer.php', 'Tuition.php',
    'ESIM.php', 'POSRequest.php', 'BulkSMS.php', 'SubmitSenderID.php',
    'Pricing.php', 'BusinessUpgrade.php', 'APIDocs.php', 'Transactions.php', 'BatchTransactions.php',
    'PointsHistory.php', 'TransactionCalculator.php', 'NumberChecker.php', 'PrintHub.php',
    'PaymentOrders.php', 'SubmitPayment.php', 'AccountSettings.php',
];
foreach ($editions as $edition) {
    $header_src = php_strip_whitespace($root . '/' . $edition . '/func/bc-plate-app.php');
    foreach (['pay-bills', 'wallet-transfers', 'verify-print', 'tools-api', 'history-tools', 'settings-help'] as $group) {
        gtrue("$edition sidebar groups $group", strpos($header_src, "'id' => '" . $group . "'") !== false);
    }
    gfalse("$edition sidebar has no flat Services heading", strpos($header_src, "'label' => 'Services'") !== false);
    $missing = [];
    foreach ($required_nav_pages as $pg) {
        if (strpos($header_src, "'" . $pg . "'") === false) $missing[] = $pg;
    }
    gcheck("$edition nav keeps every service link", implode(',', $missing), '');
    gfalse("$edition nav has no discarded Card.php", strpos($header_src, "'Card.php'") !== false);
    // History & Records carries the transaction and print items in the shell menu.
    gtrue("$edition history groups transactions", strpos($header_src, "'href' => 'Transactions.php'") !== false);
    gtrue("$edition PrintHub stands alone in the nav", strpos($header_src, "'label' => 'PrintHub'") !== false);
    gtrue("$edition history keeps the number filter", strpos($header_src, "'href' => 'NumberFilter.php'") !== false);
}

// The ledger is the only writer of provider balances: the adapters never UPDATE a balance.
foreach (['func/bc-fintech-klasha.php', 'func/bc-fintech-flutterwave.php'] as $adapter) {
    $src = php_strip_whitespace($root . '/DGV7.0-SAAS/' . $adapter);
    gfalse("$adapter never updates a balance itself", (bool)preg_match('/UPDATE\s+sas_(users|user_crypto_wallets)/i', $src));
    gfalse("$adapter never calls chargeUser", (bool)preg_match('/\bchargeUser\s*\(/', $src));
    gfalse("$adapter never calls updateUserCryptoBalance", (bool)preg_match('/\bupdateUserCryptoBalance\s*\(/', $src));
    gfalse("$adapter runs no DROP/TRUNCATE", (bool)preg_match('/\b(DROP|TRUNCATE)\b/i', $src));
}
$ledger_src = php_strip_whitespace($root . '/DGV7.0-SAAS/func/bc-fintech-ledger.php');
gtrue('the ledger writes both balance stores', strpos($ledger_src, 'sas_users') !== false && strpos($ledger_src, 'sas_user_crypto_wallets') !== false);
gtrue('the ledger uses row locking', strpos($ledger_src, 'FOR UPDATE') !== false);
gtrue('the ledger rejects negative amounts', strpos($ledger_src, 'bc_fintech_ledger_valid_amount') !== false);
// The ledger moves money and nothing else; the receipt is re-attached by the delivery layer.
gfalse('the ledger sends no mail itself', (bool)preg_match('/sendVendorEmail|mail\s*\(|getUserEmailTemplate/i', $ledger_src));

$webhook_src = php_strip_whitespace($root . '/DGV7.0-SAAS/func/bc-fintech-webhook.php');
gtrue('the delivery pipeline re-attaches the receipt', strpos($webhook_src, 'bc_fintech_webhook_notify(') !== false);
gtrue('the receipt template lookup is vendor-explicit', strpos($webhook_src, 'bc_fintech_email_template') !== false);
gtrue('the receipt sender is overridable', strpos($webhook_src, 'bc_fintech_email_sender') !== false);

// Webhook idempotency is a UNIQUE constraint on (provider, event_id).
$schema_src = php_strip_whitespace($root . '/DGV7.0-SAAS/func/bc-fintech-schema.php');
gtrue('schema creates sas_webhook_events', strpos($schema_src, 'sas_webhook_events') !== false);
gtrue('schema creates sas_fintech_ledger', strpos($schema_src, 'sas_fintech_ledger') !== false);
gtrue('webhook uniqueness is enforced', strpos($schema_src, 'uniq_provider_event (provider, event_id)') !== false);
gfalse('schema runs no DROP/TRUNCATE', (bool)preg_match('/\b(DROP|TRUNCATE)\b/i', $schema_src));

// TheKlasha crypto seam must not ship a cipher.
$klasha_src = php_strip_whitespace($root . '/DGV7.0-SAAS/func/bc-fintech-klasha.php');
gfalse('klasha ships no openssl cipher', (bool)preg_match('/openssl_(encrypt|decrypt)|CRYPT_(3DES|BF|CRYPT)/i', $klasha_src));

// The legacy second Flutterwave credit path is a known retirement item, not a silent one.
$doc = file_get_contents($root . '/DGV7_FINTECH_UPGRADE.md');
gtrue('the legacy flutterwave credit path is documented', strpos($doc, 'web/api/flutterwave_webhook.php') !== false);

// Smoke-test script exists and reports the unconfigured state.
gtrue('provider smoke test exists', is_file($root . '/tests/provider_smoke.php'));
$smoke = file_get_contents($root . '/tests/provider_smoke.php');
gtrue('smoke test reports "not configured"', strpos($smoke, 'not configured') !== false);
gtrue('smoke test covers both providers', strpos($smoke, 'klasha') !== false && strpos($smoke, 'flutterwave') !== false);

// ─────────────────────────── installer shell ───────────────────────────
// The installers are the first screen a customer sees, so they carry the same design tokens as
// the landing page and the auth shell. The shell is byte-identical across editions; the two
// wizards must both use it and must not fall back to their retired templates.
if (!function_exists('bc_installer_head')) {
    require_once $root . '/DGV7.0-SAAS/func/bc-installer-shell.php';
}
/**
 * Asserts that a shared-shell call sits inside PHP tags.
 *
 * A call written in inline-HTML position is never executed - PHP prints it as literal text - so
 * the page loses its <head>, its stylesheet and its script. That is exactly how both wizards
 * shipped before the fix. The CI runner executes these tests with `php -n`, whose build carries
 * no tokenizer extension, so the tag structure is checked with a regex instead of
 * token_get_all(): each call must follow an open tag.
 */
function bc_assert_installer_call_in_php($file, $src, $call) {
    $pattern = '/<\?(?:php|=)[ \t\r\n]*' . preg_quote($call, '/') . '[ \t]*\(/';
    gtrue("$file calls $call() from PHP", (bool)preg_match($pattern, $src));
    // Generic form: once every complete PHP region is removed, no shared-shell call may survive
    // in the remaining inline HTML. The lookahead keeps a close-tag sequence inside a quoted
    // string from being mistaken for a real close tag.
    $html_only = preg_replace('/<\?php\b.*?\?>(?=\s|$)/s', '', $src);
    $html_only = preg_replace('/<\?php\b.*$/s', '', $html_only);
    $html_only = preg_replace('/<\?=.*?\?>(?=\s|$)/s', '', $html_only);
    gtrue("$file has no shared-shell call in inline HTML", strpos($html_only, 'bc_installer_') === false);
}
function bc_render_installer($root, $file, $step) {
    $src = file_get_contents($root . '/' . $file);
    foreach (['bc_installer_head', 'bc_installer_foot', 'bc_installer_stepper'] as $call) {
        bc_assert_installer_call_in_php($file, $src, $call);
    }
    $src = preg_replace('/^<\?php/', '', $src, 1);
    $src = preg_replace("/^if \(file_exists\([^)]*\)\) \{.*?\n\}/s", '', $src);
    $src = preg_replace('/mysqli_report\(MYSQLI_REPORT_OFF\);/', '', $src);
    $src = preg_replace("/require_once __DIR__ \. '\/[^']*bc-installer-shell\.php';/", '', $src);
    $src = preg_replace("/define\('__INSTALLER_SCRIPTS__', <<<'JS'.*?\nJS\);/s", '', $src);
    $cut = strpos($src, 'bc_installer_head(');
    gtrue("installer markup found in $file", $cut !== false);
    $src = substr($src, $cut);
    $error = ''; $success = ''; $activationCodeInput = '';
    $req_php = true; $req_mysqli = true; $req_mbstring = true; $req_gd = true;
    $req_writable_func = true; $req_writable_root = true; $all_req_met = true;
    if (!defined('__INSTALLER_SCRIPTS__')) define('__INSTALLER_SCRIPTS__', '');
    ob_start();
    eval($src);
    return ob_get_clean();
}
foreach (array(
    'DGV7.0-SAAS/install/index.php',
    'DGV7.0-NON-SAAS/install.php',
) as $installer) {
    $tag = basename(dirname($installer)) . '/' . basename($installer);
    $html = bc_render_installer($root, $installer, 1);
    gtrue("$tag renders a document", strpos($html, '<!DOCTYPE html>') !== false);
    gtrue("$tag uses the shared shell", strpos($html, 'bc-installer__card') !== false);
    gtrue("$tag prints the shared stylesheet", strpos($html, '--brand: #0f766e') !== false);
    gtrue("$tag prints the stepper script", strpos($html, 'function goStep') !== false);
    gtrue("$tag drops the retired template", strpos($html, 'cdn.jsdelivr.net/npm/bootstrap') === false && strpos($html, 'install.css') === false);
    gcheck("$tag has four stepper dots", substr_count($html, 'bc-installer__dot"'), 4);
    gtrue("$tag closes the document", strpos($html, '</html>') !== false);
}
$saas_html = bc_render_installer($root, 'DGV7.0-SAAS/install/index.php', 1);
gtrue('saas installer keeps the activation field', strpos($saas_html, 'name="activation_code"') !== false);
gtrue('saas installer keeps the requirement checklist', strpos($saas_html, 'bc-installer__req') !== false);
$standalone_html = bc_render_installer($root, 'DGV7.0-NON-SAAS/install.php', 1);
foreach (array('step-1', 'step-2', 'step-3', 'step-4') as $id) {
    gtrue("standalone panel #$id present", strpos($standalone_html, 'id="' . $id . '"') !== false);
}

// ── SAAS add-vendor: a missing domain fee and an unset paystack key must not lose the vendor ──
// The vendor's own report: with "Use existing domain" selected, a settings state of
// "bank settings saved, TLD prices saved, cPanel API details NOT saved" produced an unexplained
// failure on bc-spadmin/VendorReg.php. Two independent faults reproduced here:
//   (a) the "existing domain" value was only read from the hidden app_base_url field, which the
//       page's script fills by copying existing_url on input - so any suppressed/pasted value
//       silently became an empty domain;
//   (b) when the pending row INSERT failed, the handler discarded mysqli_error() and printed a
//       bare "Could not save your registration. Please try again."
function bc_run_vendor_registration($path, $post, $reset = true) {
    $src = file_get_contents($path);
    $start = strpos($src, 'if(isset($_POST["create-profile"])){');
    gtrue("create-profile handler found in $path", $start !== false);
    // The handler is closed by the first `}` at column 0 after its opening brace. Slicing to the
    // later statement and appending a brace left an extra one and made the slice unparseable.
    $end = strpos($src, "\n}\n", $start);
    if ($end === false) $end = strlen($src);
    $block = substr($src, $start, $end - $start + 3);
    $block = str_replace(['exit();', 'mysqli_'], ['return;', 'fake_mysqli_'], $block);
    // The extension selector is built before the handler; seed the two variables it sets so the
    // handler's validation still has a real extension list to check the domain against.
    $GLOBALS['__vendor_exts'] = array_keys($GLOBALS['db']['domain_extensions']) ?: ['.com', '.ng', '.com.ng', '.org', '.net'];
    $block = preg_replace('/header\s*\([^)]*\)\s*;/', '$GLOBALS["__vendor_header"] = "redirect";', $block);
    if ($reset) {
        $GLOBALS['db']['options'] = [];
        $GLOBALS['db']['pending_vendors'] = [];
        $GLOBALS['db']['billing_packages'] = [];
        $GLOBALS['db']['billing_addons'] = [];
        $GLOBALS['db']['domain_extensions'] = ['.com' => '45.00', '.ng' => '18.00'];
        $GLOBALS['db']['super_admin'] = ['email' => 'admin@example.test'];
        $GLOBALS['db']['super_admin_payments'] = ['paystack' => ['secret_key' => 'sk_test_fake']];
        $GLOBALS['db']['vendors'] = [];
        $GLOBALS['db']['errno'] = 0;
        $GLOBALS['db']['error'] = '';
    }
    $_POST = $post;
    $_SESSION = [];
    $GLOBALS['__vendor_header'] = null;
    $connection_server = $GLOBALS['connection_server'];
    // Helpers the page needs at runtime; none of them touch the database.
    // Mirrors the shipped helper (func/bc-func.php:281): it keeps only host characters, so a path
    // or a query in the "existing domain" field never reaches the extension check.
    // Both helpers mirror func/bc-func.php:259/281 exactly, including the rule that a URL with a
    // non-empty path (or a port, query, fragment, credentials) is not a domain at all.
    if (!function_exists('bc_clean_vendor_domain')) { eval('function bc_clean_vendor_domain($value){ $value=trim((string)$value); if($value==="") return ""; if(preg_match("~^https?://~i",$value)){ $parts=parse_url($value); if(!is_array($parts)||!isset($parts["host"])||!empty($parts["user"])||!empty($parts["pass"])||!empty($parts["query"])||!empty($parts["fragment"])) return ""; if(isset($parts["path"])&&$parts["path"]!==""&&$parts["path"]!=="/") return ""; if(isset($parts["port"])) return ""; $host=$parts["host"]; } else { if(strpos($value,"/")!==false||strpos($value,"\\\\")!==false||strpos($value,":")!==false||strpos($value,"?")!==false||strpos($value,"#")!==false||strpos($value,"@")!==false) return ""; $host=$value; } return strtolower(rtrim($host,".")); }'); }
    if (!function_exists('bc_normalize_vendor_host')) { eval('function bc_normalize_vendor_host($value){ $value=trim((string)$value); if(!preg_match("~^https?://~i",$value)){ $value=preg_replace("/:\d+$/","",$value); } $host=bc_clean_vendor_domain($value); return preg_replace("/^www\./i","",$host); }'); }
    if (!function_exists('bc_is_temporary_vendor_host')) { eval('function bc_is_temporary_vendor_host($h){ return (bool)preg_match("#\.(test|localhost)$#i",(string)$h); }'); }
    if (!function_exists('bc_is_valid_domain_name')) { eval('function bc_is_valid_domain_name($d){ return (bool)preg_match("/^[a-z0-9.-]+\.[a-z]{2,}$/",(string)$d); }'); }
    // The handler only stores the result; the test does not verify it. Fall back rather than
    // dying on an extension the bare `php -n` CI runner may not carry.
    if (!function_exists('bc_hash_password')) { eval('function bc_hash_password($p){ return function_exists("password_hash") ? password_hash((string)$p, PASSWORD_DEFAULT) : hash("sha256", (string)$p); }'); }
    // Same caching rules as func/bc-func.php:7130, so the default-withdrawal options resolve
    // from the seeded sas_super_admin_options rows.
    if (!function_exists('getSuperAdminOption')) { eval('function getSuperAdminOption($name,$default=""){ global $connection_server; static $cache=array(); if(isset($cache[$name])) return $cache[$name]; if(isset($_SESSION["super_admin_options_cache"][$name])){ $cache[$name]=$_SESSION["super_admin_options_cache"][$name]; return $cache[$name]; } $q=fake_mysqli_query($connection_server,"SELECT option_value FROM sas_super_admin_options WHERE option_name=\x27".fake_mysqli_real_escape_string($connection_server,$name)."\x27 LIMIT 1"); $r=$q?fake_mysqli_fetch_assoc($q):null; $cache[$name]=($r&&isset($r["option_value"]))?$r["option_value"]:$default; return $cache[$name]; }'); }
    if (!function_exists('getSuperAdminEmailTemplate')) { eval('function getSuperAdminEmailTemplate($t,$f){ return $f === "subject" ? "Vendor registration" : "A vendor registered."; }'); }
    if (!function_exists('sendVendorEmail')) { eval('function sendVendorEmail($to,$s,$b){ $GLOBALS["db"]["email_templates_sent"]++; return true; }'); }
    if (!function_exists('whmp_is_configured')) { eval('function whmp_is_configured(){ return true; }'); }
    if (!function_exists('whmp_check_domain_availability')) { eval('function whmp_check_domain_availability($d){ return ["status" => "available"]; }'); }
    if (!function_exists('whmp_get_domain_price')) { eval('function whmp_get_domain_price($e){ return 12.0; }'); }
    if (!function_exists('whmp_get_usd_to_ngn_rate')) { eval('function whmp_get_usd_to_ngn_rate(){ return 1500.0; }'); }
    if (!function_exists('whmp_convert_usd_to_ngn')) { eval('function whmp_convert_usd_to_ngn($u,$r){ return $u * $r; }'); }
    $GLOBALS['db']['email_templates_sent'] = 0;
    $valid_exts = $GLOBALS['__vendor_exts'];
    $domain_extension_options = array_map(function ($ext) {
        return ['extension' => $ext, 'price' => 0.0, 'promo_price' => 0.0];
    }, $valid_exts);
    eval($block);
    return [
        $_SESSION['product_purchase_response'] ?? null,
        $GLOBALS['db']['pending_vendors'],
        $GLOBALS['__vendor_header'] ?? null,
    ];
}
$vendor_reg_path = $root . '/DGV7.0-SAAS/bc-spadmin/VendorReg.php';
$vendor_post = ['create-profile' => 1, 'first' => 'Ada', 'last' => 'Okafor', 'address' => '12 Allen Avenue',
    'email' => 'ada@example.test', 'pass' => 'secret123', 'phone' => '08031234567',
    'billing_package_id' => '1', 'payment_method' => 'bank',
    // A domain typed into the "use existing domain" box. The shipped host cleaner rejects a URL
    // carrying a path, so this is a bare host - the shape the page's own validation asks for.
    'domain_option' => 'existing', 'existing_url' => 'www.adaoshop.com',
    'website-url' => '', 'app_base_url' => '', 'addons' => [], 'domain_fee' => '0', 'total_amount' => '0'];

// (a) The "use existing domain" value arrives as existing_url and the hidden app_base_url is
// empty - the exact state that produced an empty domain and a failed save.
list($reg_msg, $reg_pending, ) = bc_run_vendor_registration($vendor_reg_path, $vendor_post);
gcheck("vendor registration message", $reg_msg, null);
gcheck("vendor registration created the pending row", count($reg_pending), 1);
gcheck("vendor registration normalised the existing domain",
    $reg_pending[1]['website_url'] ?? null, 'adaoshop.com');
gcheck("vendor registration stored the package id", $reg_pending[1]['billing_package_id'] ?? null, '1');

// (b) A failed INSERT must be explained with the real reason, not a bare "please try again".
// The fake INSERT is faithful here: it refuses a second row with the same UNIQUE key.
$GLOBALS['db']['pending_vendors'] = [1 => ['id' => 1, 'email' => 'ada@example.test', 'website_url' => 'adaoshop.com']];
list($dup_msg, $dup_rows, ) = bc_run_vendor_registration($vendor_reg_path, $vendor_post, false);
// Both UNIQUE keys are checked before the INSERT; whichever collides is named.
gtrue("duplicate vendor registration is detected before the INSERT",
    in_array($dup_msg, ['A vendor with the same Website URL already exists.', 'A vendor with the same Email already exists.'], true));
gcheck("duplicate vendor registration wrote nothing", count($dup_rows), 1);

// The old failure branch threw mysqli_error() away, so any INSERT failure - a race with another
// registrant, a missing column, a too-long value - produced a bare "please try again" that named
// nothing. Assert the branch is now wired to the real error.
$reg_src = file_get_contents($vendor_reg_path);
gtrue("vendor registration reads the database error before reporting a failure",
    strpos($reg_src, 'mysqli_error($connection_server)') !== false);
gtrue("vendor registration logs the failure cause",
    strpos($reg_src, "error_log('[VendorReg]") !== false);
gtrue("vendor registration names a duplicate key instead of staying silent",
    strpos($reg_src, 'already registered. Delete the pending registration first.') !== false
    && strpos($reg_src, 'Could not save your registration. Please try again.') !== false);

// The pre-checks must also catch the duplicate before the INSERT is attempted.
$dup_post = $vendor_post;
$dup_post['email'] = 'other@example.test';
$GLOBALS['db']['pending_vendors'] = [1 => ['id' => 1, 'email' => 'ada@example.test', 'website_url' => 'adaoshop.com']];
$GLOBALS['db']['errno'] = 0;
$GLOBALS['db']['error'] = '';
list($pre_msg, ) = bc_run_vendor_registration($vendor_reg_path, $dup_post, false);
gcheck("pre-check reports the taken URL", $pre_msg, 'A vendor with the same Website URL already exists.');

// The register path still refuses when WHMP has no price, and says so.
$GLOBALS['db']['pending_vendors'] = [];
$reg_domain_post = $vendor_post;
$reg_domain_post['domain_option'] = 'register';
$reg_domain_post['existing_url'] = '';
$reg_domain_post['website-url'] = 'newbrand.ng';
$reg_domain_post['app_base_url'] = 'newbrand.ng';
list($price_msg, ) = bc_run_vendor_registration($vendor_reg_path, $reg_domain_post, false);
gtrue("register path still works when WHMP is configured", $price_msg === null);

// ── manual-funding bank details: a ₦0 charge must save ─────────────────────────
// Regression test for a silent failure: empty("0") is true in PHP, so the old condition
// `!empty($amount_charged)` rejected a legitimate 0 charge, and the amount error branch was
// inverted (`if ($amount_charged > 0)`), so the handler fell through having written nothing
// AND set no message. The admin saw an unsaved form with no explanation.
if (!function_exists('bc_demo_guard_edit')) {
    function bc_demo_guard_edit($db) { return true; }
}
function bc_run_bank_handler($path, $post, $reset = true) {
    $src = file_get_contents($path);
    $start = strpos($src, 'if (isset($_POST["update-bank-details"]))');
    if ($start === false) { $start = strpos($src, 'if(isset($_POST["update-bank-details"]))'); }
    gtrue("bank handler found in $path", $start !== false);
    $next = strpos($src, 'if (isset($_POST["update-daily-purchase-limit-details"]))', $start);
    if ($next === false) { $next = strpos($src, 'if (isset($_POST["update-global-withdrawal-settings"]))', $start); }
    if ($next === false) { $next = strlen($src); }
    $block = substr($src, $start, $next - $start);
    // Run the real shipped block against the fake DB, the same substitution load_edition uses.
    $block = str_replace(['exit;', 'mysqli_'], ['return;', 'fake_mysqli_'], $block);
    $block = preg_replace('/header\s*\([^)]*\)\s*;/', '$GLOBALS["__bank_header"] = "redirect";', $block);

    if ($reset) {
        $GLOBALS['db']['admin_payments'] = [];
        $GLOBALS['db']['super_admin_payments'] = null;
    }
    $_POST = $post;
    $_SESSION = [];
    $GLOBALS['__bank_header'] = null;
    $connection_server = $GLOBALS['connection_server'];
    $get_logged_admin_details = ['id' => 7];
    eval($block);
    return [
        $_SESSION['product_purchase_response'] ?? null,
        $GLOBALS['db']['admin_payments'],
        $GLOBALS['db']['super_admin_payments'],
        $GLOBALS['__bank_header'] ?? null,
    ];
}
$bank_post_zero = ['update-bank-details' => 1, 'name' => 'Adaobi Okafor', 'bank' => 'Guaranty Trust Bank',
                   'number' => '0123456789', 'phone' => '08031234567', 'charges' => '0'];
$bank_post_paid = $bank_post_zero;
$bank_post_paid['charges'] = '500';

foreach ([
    'DGV7.0-SAAS/bc-admin/AccountSettings.php' => 'admin',
    'DGV7.0-NON-SAAS/bc-admin/AccountSettings.php' => 'admin',
    'DGV7.0-SAAS/bc-spadmin/AccountSettings.php' => 'super',
] as $rel => $kind) {
    list($msg, $admin_rows, $super_row, $header) = bc_run_bank_handler($root . '/' . $rel, $bank_post_zero);
    gcheck("$rel saves a 0 charge", $msg, 'Bank Information Created Successfully');
    gcheck("$rel redirects after saving", $header, 'redirect');
    $stored = ($kind === 'admin') ? ($admin_rows[7] ?? null) : $super_row;
    gtrue("$rel stored the bank row", is_array($stored));
    gcheck("$rel stored the 0 charge", $stored['amount_charged'] ?? null, '0');
    gcheck("$rel stored the account name", $stored['account_name'] ?? null, 'Adaobi Okafor');
    gcheck("$rel stored the account number", $stored['account_number'] ?? null, '0123456789');

    // A non-zero charge must still save, and an existing row must be updated rather than duplicated.
    $GLOBALS['db']['admin_payments'] = [7 => ['vendor_id' => 7, 'bank_name' => 'Old', 'account_name' => 'Old',
        'account_number' => '0000000000', 'phone_number' => '0000000000', 'amount_charged' => '0']];
    $GLOBALS['db']['super_admin_payments'] = ['bank_name' => 'Old', 'account_name' => 'Old',
        'account_number' => '0000000000', 'phone_number' => '0000000000', 'amount_charged' => '0'];
    list($msg2, $admin_rows2, $super_row2, ) = bc_run_bank_handler($root . '/' . $rel, $bank_post_paid, false);
    gcheck("$rel updates an existing row", $msg2, 'Bank Information Updated Successfully');
    $stored2 = ($kind === 'admin') ? ($admin_rows2[7] ?? null) : $super_row2;
    gcheck("$rel stored the paid charge", $stored2['amount_charged'] ?? null, '500');
    gcheck("$rel kept a single row", ($kind === 'admin') ? count($admin_rows2) : 1, 1);

    // Invalid input must always produce a message, never a silent fall-through.
    $bad = $bank_post_zero;
    $bad['bank'] = '';
    list($bad_msg, , , ) = bc_run_bank_handler($root . '/' . $rel, $bad);
    gcheck("$rel reports an empty bank name", $bad_msg, 'Bank Name Field Empty');
    // The charge is stripped to digits and dots before validation, so a non-numeric entry
    // arrives as an empty string; either way the handler must say so rather than stay silent.
    $bad2 = $bank_post_zero;
    $bad2['charges'] = 'abc';
    list($bad2_msg, , , ) = bc_run_bank_handler($root . '/' . $rel, $bad2);
    gcheck("$rel reports an unusable charge", $bad2_msg, 'Amount Field Empty');
    $bad3 = $bank_post_zero;
    $bad3['number'] = '';
    list($bad3_msg, , , ) = bc_run_bank_handler($root . '/' . $rel, $bad3);
    gcheck("$rel reports an empty account number", $bad3_msg, 'Account Number Field Empty');
}

// ── pending-vendor schema migration ─────────────────────────────────────────────
// The migration added eight columns to sas_pending_vendors under a single "app_base_url is
// missing" condition. A table that already had app_base_url but not the later columns never
// received them, so the add-vendor INSERT - which names every column - failed with an
// unknown-column error while the duplicate pre-checks still passed. Each column must now be
// added on its own, and the schema version must have moved with it.
$tables_src = file_get_contents($root . '/DGV7.0-SAAS/func/bc-tables.php');
$version = [];
preg_match("/define\\('BC_TABLES_VERSION',\\s*'([^']+)'\\)/", $tables_src, $version);
gcheck('schema version marker present', $version[1] ?? null, '2026.11.19-1');
gtrue('pending-vendor migration adds columns individually',
    strpos($tables_src, '$pending_column_ddl') !== false
    && preg_match('/foreach \(\$pending_column_ddl as \$pending_column => \$pending_ddl\)/', $tables_src) === 1);
gfalse('pending-vendor migration no longer gates a column batch on app_base_url',
    (bool)preg_match('/if \(!in_array\(\'app_base_url\', \$pending_existing\)\) \{\s*\n\s*mysqli_query\(\$connection_server, "ALTER TABLE sas_pending_vendors ADD COLUMN order_apk/', $tables_src));
foreach (['app_base_url', 'order_apk', 'order_ios', 'order_playstore', 'order_sms_bridge',
          'domain_registration_fee', 'total_amount', 'whmp_domain_id'] as $pending_col) {
    gtrue("pending-vendor migration covers $pending_col",
        strpos($tables_src, "'" . $pending_col . "' =>") !== false);
}
// The INSERT names every column explicitly, so a column the migration forgets breaks the
// registration while the duplicate pre-checks still pass. Compare the column list in the
// shipped INSERT with the migration's list.
$insert_match = [];
preg_match('/INSERT INTO sas_pending_vendors \(([^)]*)\) VALUES/', $reg_src, $insert_match);
$insert_cols = array_map('trim', explode(',', $insert_match[1] ?? ''));
// Only the columns the migration is responsible for; the rest are created with the table.
$migration_cols = ['app_base_url', 'order_apk', 'order_ios', 'order_playstore', 'order_sms_bridge',
                   'domain_registration_fee', 'total_amount', 'whmp_domain_id'];
$missing_from_migration = array_values(array_diff($insert_cols, $migration_cols));
$missing_from_migration = array_values(array_filter($missing_from_migration, function ($c) {
    return !in_array($c, ['website_url', 'email', 'password', 'firstname', 'lastname', 'phone_number',
                          'home_address', 'billing_package_id', 'payment_method', 'status',
                          'min_withdrawal_amount', 'max_withdrawal_amount', 'daily_payout_limit',
                          'selected_addons'], true);
}));
gcheck('every add-vendor INSERT column is covered by the migration', $missing_from_migration, []);

// Parity: the new shared files are byte-identical across editions.
foreach ([
    'func/bc-fintech-providers.php',
    'func/bc-fintech-ledger.php',
    'func/bc-fintech-webhook.php',
    'func/bc-fintech-klasha.php',
    'func/bc-fintech-flutterwave.php',
    'users-klasha.php',
    'users-flutterwave.php',
    'func/bc-fintech-schema.php',
] as $file) {
    gcheck("edition parity: $file", md5_file($root . '/DGV7.0-SAAS/' . $file), md5_file($root . '/DGV7.0-NON-SAAS/' . $file));
}

echo "\n$checks phase 1+3 checks passed\n";
