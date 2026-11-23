<?php
declare(strict_types=1);

function mz_cfg(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = require __DIR__ . '/config.php';
        foreach (['api_secret', 'callback_secret'] as $k) {
            if (strlen($cfg[$k]) < 32) {
                mz_json(['ok' => false, 'error' => 'Crypto desk is not configured'], 503);
            }
        }
        foreach (['table', 'id_col', 'balance_col'] as $k) {
            if (!preg_match('/^[A-Za-z0-9_]+$/', $cfg['wallet'][$k])) {
                throw new RuntimeException('Invalid wallet config');
            }
        }
    }
    return $cfg;
}

function mz_db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = mz_cfg()['db'];
        $pdo = new PDO($c['dsn'], $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

function mz_json(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($data);
    exit;
}

function mz_fail(string $message, int $code = 400): void
{
    mz_json(['ok' => false, 'error' => $message], $code);
}

function mz_input(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

// ── session / auth ───────────────────────────────────────────────────────
function mz_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

function mz_require_user(): int
{
    mz_session();
    $uid = $_SESSION[mz_cfg()['session_user_key']] ?? null;
    if (!$uid || !ctype_digit((string) $uid)) {
        mz_fail('Please log in', 401);
    }
    return (int) $uid;
}

function mz_csrf_token(): string
{
    mz_session();
    if (empty($_SESSION['mz_csrf'])) {
        $_SESSION['mz_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['mz_csrf'];
}

function mz_require_csrf(): void
{
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($sent) || !hash_equals(mz_csrf_token(), $sent)) {
        mz_fail('Session expired — refresh the page', 419);
    }
}

/** ⚠️ ADAPT: block users who haven't completed KYC / are suspended. */
function mz_user_can_trade(int $uid): bool
{
    // No placeholder identity or KYC approval may authorize a FINTECHFLOW trade.
    // Replace only as part of reviewed tenant-scoped integration; keep the entry-point hold.
    return false;
}

/** Simple per-user rate limit (requests per minute) using the session. */
function mz_rate_limit(string $bucket, int $perMinute): void
{
    mz_session();
    $now = time();
    $hits = array_filter($_SESSION['mz_rl'][$bucket] ?? [], fn ($t) => $t > $now - 60);
    if (count($hits) >= $perMinute) {
        mz_fail('Too many requests — slow down a little', 429);
    }
    $hits[] = $now;
    $_SESSION['mz_rl'][$bucket] = array_values($hits);
}

/** Quick format check before charging the user (the service does the full checksum validation). */
function mz_valid_address(string $type, string $a): bool
{
    switch ($type) {
        case 'evm':  return (bool) preg_match('/^0x[0-9a-fA-F]{40}$/', $a);
        case 'tron': return (bool) preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/', $a);
        case 'btc':  return (bool) preg_match('/^(bc1[02-9ac-hj-np-z]{11,87}|BC1[02-9AC-HJ-NP-Z]{11,87}|[13][1-9A-HJ-NP-Za-km-z]{25,34})$/', $a);
        default:     return false;
    }
}

// ── wallet (DGV7.0 balance) ──────────────────────────────────────────────
function mz_wallet_sql(string $op): string
{
    $w = mz_cfg()['wallet'];
    [$t, $id, $b] = [$w['table'], $w['id_col'], $w['balance_col']];
    return $op === 'debit'
        ? "UPDATE `$t` SET `$b` = `$b` - :a1 WHERE `$id` = :uid AND `$b` >= :a2"
        : "UPDATE `$t` SET `$b` = `$b` + :a1 WHERE `$id` = :uid";
}

/** Debit inside the caller's transaction. Returns false if balance is too low. */
function mz_wallet_debit(PDO $db, int $uid, string $amount, string $ref, string $desc): bool
{
    $st = $db->prepare(mz_wallet_sql('debit'));
    $st->execute([':a1' => $amount, ':a2' => $amount, ':uid' => $uid]);
    if ($st->rowCount() !== 1) {
        return false;
    }
    mz_history($db, $uid, $amount, $ref, $desc, 'debit');
    return true;
}

function mz_wallet_credit(PDO $db, int $uid, string $amount, string $ref, string $desc): void
{
    $st = $db->prepare(mz_wallet_sql('credit'));
    $st->execute([':a1' => $amount, ':uid' => $uid]);
    if ($st->rowCount() !== 1) {
        throw new RuntimeException("Wallet credit failed for user $uid");
    }
    mz_history($db, $uid, $amount, $ref, $desc, 'credit');
}

function mz_history(PDO $db, int $uid, string $amount, string $ref, string $desc, string $type): void
{
    $sql = mz_cfg()['history_sql'];
    if ($sql) {
        $db->prepare($sql)->execute([':uid' => $uid, ':amount' => $amount, ':ref' => $ref, ':desc' => $desc, ':type' => $type]);
    }
}

// ── signed calls to the Node service ─────────────────────────────────────
/**
 * @return array{ok:bool, status:int, data:?array, network_error:bool}
 */
function mz_service(string $method, string $path, ?array $body = null): array
{
    $cfg = mz_cfg();
    $raw = $body === null ? '' : json_encode($body);
    $ts = (string) time();
    $sig = hash_hmac('sha256', "$ts." . strtoupper($method) . ".$path.$raw", $cfg['api_secret']);

    $ch = curl_init(rtrim($cfg['service_url'], '/') . $path);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', "X-Mz-Timestamp: $ts", "X-Mz-Signature: $sig"],
    ]);
    if ($raw !== '') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $raw);
    }
    $resp = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_errno($ch);
    curl_close($ch);

    if ($resp === false || $err) {
        return ['ok' => false, 'status' => 0, 'data' => null, 'network_error' => true];
    }
    $data = json_decode((string) $resp, true);
    return ['ok' => $status === 200, 'status' => $status, 'data' => is_array($data) ? $data : null, 'network_error' => $status >= 500];
}

/** Same call, retried on network errors (safe because the service is idempotent on clientRef). */
function mz_service_retry(string $method, string $path, ?array $body = null, int $tries = 3): array
{
    for ($i = 0; $i < $tries; $i++) {
        $r = mz_service($method, $path, $body);
        if (!$r['network_error']) {
            return $r;
        }
        usleep(400000 * ($i + 1));
    }
    return $r;
}

function mz_service_error(array $r): string
{
    return $r['data']['message'] ?? 'Crypto desk is temporarily unavailable';
}
