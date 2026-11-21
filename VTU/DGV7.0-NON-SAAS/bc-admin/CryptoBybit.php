<?php
require_once __DIR__ . '/../func/bc-secure-session.php';
bc_secure_session_start();
require_once __DIR__ . '/../func/bc-admin-config.php';
require_once __DIR__ . '/../func/bc-bybit.php';
header('Cache-Control: no-store, private');
if (empty($get_logged_admin_details['id']) || (int)($get_logged_admin_details['status'] ?? 0) !== 1 || !empty($get_logged_admin_details['is_blocked'])) {
    http_response_code(403); exit('Access denied.');
}
$discovery = null; $notice = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    bc_validate_csrf();
    $vid = (int)$get_logged_admin_details['id'];
    if (bc_is_rate_limited('bybit_discovery', (string)$vid, 3, 300)) {
        http_response_code(429); $notice = 'Too many discovery attempts. Wait five minutes.';
    } else {
        $actor = bc_q_row('SELECT * FROM sas_vendors WHERE id=? LIMIT 1', [$vid]);
        $active = is_array($actor) && (int)($actor['status'] ?? 0) === 1 && empty($actor['is_blocked']);
        $error = $active && bc_gauth_enrolled($actor) ? bc_stepup_admin_verify($actor, $_POST, 'bybit_discovery') : 'Set up Google Authenticator on your account first.';
        if ($error !== '') { http_response_code(403); $notice = $error; }
        else {
            try {
                if (!bc_sec_audit($vid, 'vendor', (string)$actor['email'], 'bybit_discovery_requested', 'read_only_catalogue', 'Read-only discovery authorized.')) throw new RuntimeException('bybit_audit_unavailable');
                $snapshot = bc_bybit_discover($vid);
                $audited = bc_sec_audit($vid, 'vendor', (string)$actor['email'], 'bybit_discovery', 'read_only_catalogue', 'Verified read-only key/IP bindings; fetched ' . count($snapshot['networks']) . ' asset/network pairs. No funds moved.');
                if (!$audited) throw new RuntimeException('bybit_audit_unavailable');
                $discovery = $snapshot;
            } catch (Throwable $e) {
                // Only module-defined safe codes leave this boundary. Never echo provider bodies, URLs, keys or curl errors.
                $code = preg_match('/^bybit_[a-z_]+$/D', $e->getMessage()) ? $e->getMessage() : 'bybit_discovery_failed';
                $notice = 'Discovery could not complete: ' . $code . '. No assets were enabled and no funds moved.';
                bc_sec_audit($vid, 'vendor', (string)$actor['email'], 'bybit_discovery_failed', 'read_only_catalogue', $code);
            }
        }
    }
} elseif (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') { http_response_code(405); header('Allow: GET, POST'); exit; }
$escape = function($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); };
require_once __DIR__ . '/../func/bc-plate-admin.php';
bc_adm_head('Bybit discovery | Crypto', ['area'=>'admin']);
bc_adm_sidebar(['area'=>'admin']);
bc_adm_topbar(['area'=>'admin','title'=>'CRYPTO PROVIDER READINESS','crumb'=>'Crypto / Bybit discovery']);
?>
<section class="section p-4">
    <h1 class="h3">Bybit coin and network discovery</h1>
    <div class="alert alert-warning"><strong>Read-only implementation stage.</strong> Deposits, trading, conversions and withdrawals are not connected to Bybit. Provider availability does not enable an asset on this platform. Existing financial holds remain in force.</div>
    <a class="btn btn-outline-primary mb-3" href="CryptoHub.php">Back to Crypto Hub</a>
    <?php if ($notice !== ''): ?><div role="alert" class="alert alert-danger"><?php echo $escape($notice); ?></div><?php endif; ?>
    <div class="card p-4 mb-4">
        <h2 class="h5">Verify connection and inspect catalogue</h2>
        <p>Requires a dedicated, IP-bound, read-only key configured by your server operator. No keys are entered or displayed here. Every request verifies the key's read-only status and exact expected IP bindings before fetching coins. No provider calls occur merely by opening this page.</p>
        <form method="post" autocomplete="off">
            <?php echo bc_csrf_field(); ?>
            <label class="form-label">Security PIN<input class="form-control mb-3" type="password" name="pin" inputmode="numeric" required autocomplete="off"></label>
            <label class="form-label">Fresh Google Authenticator code<input class="form-control mb-3" name="totp_code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autocomplete="one-time-code"></label>
            <button class="btn btn-primary" type="submit">Verify and fetch read-only catalogue</button>
        </form>
    </div>
    <?php if ($discovery !== null): ?>
    <div class="alert alert-success">Read-only key and IP binding verified. Environment: <strong><?php echo $escape($discovery['environment']); ?></strong>. Fetched <?php echo $escape($discovery['fetched_at']); ?>. This is a point-in-time snapshot, not a live transaction authorization.</div>
    <div class="table-responsive"><table class="table"><caption>All assets remain disabled locally. Fees and amounts below are exact provider decimal strings. Proportional fee is a fraction, not a percent.</caption><thead><tr><th>Coin</th><th>Network</th><th>Provider deposit</th><th>Provider withdrawal</th><th>Minimum deposit</th><th>Minimum withdrawal</th><th>Fixed fee</th><th>Proportional fee</th><th>Confirmations / withdrawal unlock</th><th>Precision</th><th>Platform</th></tr></thead><tbody>
    <?php foreach ($discovery['networks'] as $network): ?>
        <tr><td><?php echo $escape($network['asset']); ?></td><td><?php echo $escape($network['network']); ?></td><td><?php echo $network['provider_deposit_available'] ? 'Available' : 'Suspended'; ?></td><td><?php echo $network['provider_withdraw_available'] ? 'Available' : 'Unavailable'; ?></td><td><?php echo $escape($network['deposit_min']); ?></td><td><?php echo $escape($network['withdraw_min']); ?></td><td><?php echo $escape($network['withdraw_fee'] ?? 'Not offered'); ?></td><td><?php echo $escape($network['withdraw_percentage_fee']); ?></td><td><?php echo $escape($network['confirmations'] . ' / ' . $network['withdrawal_confirmations']); ?></td><td><?php echo $escape($network['precision']); ?></td><td>Disabled</td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
</section>
<?php bc_adm_foot(['area'=>'admin']); ?>
