<?php
declare(strict_types=1);

namespace App\Livewire;

use App\Http\Controllers\AuthController;
use App\Http\Controllers\GatewayController;
use App\Http\Controllers\PrefundController;
use App\Http\Controllers\SecurityController;
use App\Services\BitcoinHDWalletService;
use App\Services\BlockchainNodeService;
use App\Support\Database;
use App\Support\Settings;

/**
 * Reactive SPA Dashboard Navigation & State Container.
 * Eliminates full-page reloads across Merchant & Admin views.
 */
final class DashboardManager
{
    /**
     * Build complete reactive state snapshot for the signed-in user.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function buildState(array $user, bool $includeCountries = false): array
    {
        $pdo = Database::connection();
        $userId = (int) $user['id'];
        $isAdmin = (($user['role'] ?? '') === 'admin');

        // Refresh user record
        $uStmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $uStmt->execute([$userId]);
        $freshUser = $uStmt->fetch() ?: $user;

        // Prefund balance
        $bStmt = $pdo->prepare("SELECT balance FROM merchant_balances WHERE user_id = ? AND currency = 'USD' LIMIT 1");
        $bStmt->execute([$userId]);
        $balance = (float) ($bStmt->fetchColumn() ?: 0.0);

        // Merchant wallets
        $wStmt = $pdo->prepare('SELECT * FROM merchant_wallets WHERE user_id = ? ORDER BY id DESC');
        $wStmt->execute([$userId]);
        $wallets = [];
        foreach ($wStmt->fetchAll() as $w) {
            $keyStr = (string) $w['xpub_key'];
            $masked = strlen($keyStr) > 22
                ? substr($keyStr, 0, 12) . '…' . substr($keyStr, -8)
                : $keyStr;
            $wallets[] = [
                'id'              => (int) $w['id'],
                'coin_symbol'     => (string) $w['coin_symbol'],
                'wallet_label'    => (string) $w['wallet_label'],
                'derivation_mode' => (string) $w['derivation_mode'],
                'xpub_masked'     => $masked,
                'derived_index'   => (int) $w['derived_index'],
                'status'          => (string) $w['status'],
                'created_at'      => (string) $w['created_at'],
            ];
        }

        // Merchant invoices (latest 60)
        $gw = new GatewayController();
        $invStmt = $pdo->prepare('SELECT * FROM invoices WHERE user_id = ? ORDER BY id DESC LIMIT 60');
        $invStmt->execute([$userId]);
        $invoices = [];
        foreach ($invStmt->fetchAll() as $inv) {
            $invoices[] = $gw->formatInvoice($inv);
        }

        // Merchant payment links
        $lnkStmt = $pdo->prepare('SELECT * FROM payment_links WHERE user_id = ? ORDER BY id DESC LIMIT 40');
        $lnkStmt->execute([$userId]);
        $links = [];
        foreach ($lnkStmt->fetchAll() as $l) {
            $links[] = [
                'id'                  => (int) $l['id'],
                'slug'                => (string) $l['slug'],
                'title'               => (string) $l['title'],
                'description'         => (string) ($l['description'] ?? ''),
                'fiat_amount'         => (string) $l['fiat_amount'],
                'fiat_currency'       => (string) $l['fiat_currency'],
                'allow_custom_amount' => !empty($l['allow_custom_amount']),
                'accepted_coins'      => (string) $l['accepted_coins'],
                'status'              => (string) $l['status'],
                'url'                 => bp_abs_url('link/' . $l['slug']),
                'path'                => bp_url('link/' . $l['slug']),
            ];
        }

        // Prefund & Fee ledger transactions
        $txStmt = $pdo->prepare('SELECT * FROM transactions WHERE user_id = ? ORDER BY id DESC LIMIT 50');
        $txStmt->execute([$userId]);
        $transactions = [];
        foreach ($txStmt->fetchAll() as $t) {
            $transactions[] = [
                'id'          => (int) $t['id'],
                'type'        => (string) $t['type'],
                'amount'      => number_format((float) $t['amount'], 4, '.', ''),
                'currency'    => (string) $t['currency'],
                'tx_hash'     => (string) ($t['tx_hash'] ?? ''),
                'gateway'     => (string) ($t['gateway'] ?? ''),
                'description' => (string) ($t['description'] ?? ''),
                'status'      => (string) $t['status'],
                'created_at'  => (string) $t['created_at'],
            ];
        }

        // Summary KPIs
        $volStmt = $pdo->prepare("SELECT COALESCE(SUM(fiat_amount), 0) FROM invoices WHERE user_id = ? AND status = 'confirmed' AND is_prefund = ?");
        $volStmt->execute([$userId, Database::boolVal(false, $pdo)]);
        $confirmedVolume = (float) $volStmt->fetchColumn();

        $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE user_id = ? AND status = 'confirmed' AND is_prefund = ?");
        $cntStmt->execute([$userId, Database::boolVal(false, $pdo)]);
        $confirmedCount = (int) $cntStmt->fetchColumn();

        $pendStmt = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE user_id = ? AND status IN ('pending', 'detected')");
        $pendStmt->execute([$userId]);
        $pendingCount = (int) $pendStmt->fetchColumn();

        $node = new BlockchainNodeService();
        $state = [
            'user'            => AuthController::sanitizeUser($freshUser),
            'prefund_balance' => number_format($balance, 2, '.', ''),
            'fee_percent'     => Settings::get('gateway_fee_percent', '1.00'),
            'usd_ngn_rate'    => Settings::get('usd_ngn_rate', '1600'),
            'supported_coins' => array_values(BitcoinHDWalletService::supportedCoins()),
            'rates'           => $node->getUsdRates(true),
            'kpis'            => [
                'confirmed_volume_usd' => number_format($confirmedVolume, 2, '.', ''),
                'confirmed_count'      => $confirmedCount,
                'pending_count'        => $pendingCount,
                'wallets_count'        => count($wallets),
            ],
            'wallets'         => $wallets,
            'invoices'        => $invoices,
            'payment_links'   => $links,
            'transactions'    => $transactions,
        ];

        if ($isAdmin) {
            $state['admin'] = $this->buildAdminState($pdo, $includeCountries);
        }

        return $state;
    }

    /**
     * Dispatch an SPA action and return updated reactive state without full-page reload.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function dispatch(array $user, string $action, array $payload): array
    {
        $gw = new GatewayController();
        $pf = new PrefundController();
        $auth = new AuthController();
        $sec = new SecurityController();

        $result = match ($action) {
            'refresh'               => ['ok' => true],
            'wallet.add'            => $gw->addWallet($user, $payload),
            'wallet.preview'        => $gw->previewDerivation((string) ($payload['xpub_key'] ?? ''), (string) ($payload['coin_symbol'] ?? 'BTC')),
            'wallet.toggle'         => $gw->toggleWallet($user, (int) ($payload['wallet_id'] ?? 0)),
            'wallet.delete'         => $gw->deleteWallet($user, (int) ($payload['wallet_id'] ?? 0)),
            'invoice.create'        => $gw->createInvoice((int) $user['id'], $payload, true),
            'link.create'           => $gw->createPaymentLink($user, $payload),
            'link.delete'           => $gw->deletePaymentLink($user, (int) ($payload['link_id'] ?? 0)),
            'prefund.payhub_init'   => $pf->initializePayhub($user, $payload),
            'prefund.payhub_verify' => $pf->confirmPayhubReference((string) ($payload['reference'] ?? ''), false),
            'prefund.crypto_create' => $pf->createCryptoTopUp($user, $payload),
            'profile.update'        => $auth->updateProfile($user, $payload),

            // Admin actions
            'admin.credit_merchant' => $pf->adminAdjustBalance($user, $payload),
            'admin.merchant_status' => $sec->setMerchantStatus($user, $payload),
            'admin.country_rule'    => $sec->updateCountryRule($user, $payload),
            'admin.bf_settings'     => $sec->saveBruteForceConfig($user, $payload),
            'admin.ip_rule'         => $sec->manageIpRule($user, $payload),
            'admin.platform_save'   => $sec->savePlatformSettings($user, $payload),
            default                 => ['ok' => false, 'error' => 'Unknown SPA action: ' . $action],
        };

        // Preview action does not mutate DB; return lightweight response directly
        if ($action === 'wallet.preview') {
            return $result;
        }

        $includeCountries = str_starts_with($action, 'admin.country') || !empty($payload['include_countries']);
        $result['state'] = $this->buildState($user, $includeCountries);
        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildAdminState(\PDO $pdo, bool $includeCountries): array
    {
        // Merchants list with balances & invoice counts
        $mSql = "SELECT u.id, u.name, u.email, u.role, u.status, u.failed_login_attempts, u.created_at,
                        COALESCE(mb.balance, 0) AS prefund_balance,
                        (SELECT COUNT(*) FROM invoices i WHERE i.user_id = u.id AND i.status = 'confirmed') AS paid_invoices
                 FROM users u
                 LEFT JOIN merchant_balances mb ON mb.user_id = u.id AND mb.currency = 'USD'
                 ORDER BY u.id DESC LIMIT 100";
        $merchants = [];
        foreach ($pdo->query($mSql)->fetchAll() as $m) {
            $merchants[] = [
                'id'                    => (int) $m['id'],
                'name'                  => (string) $m['name'],
                'email'                 => (string) $m['email'],
                'role'                  => (string) $m['role'],
                'status'                => (string) $m['status'],
                'failed_login_attempts' => (int) $m['failed_login_attempts'],
                'prefund_balance'       => number_format((float) $m['prefund_balance'], 2, '.', ''),
                'paid_invoices'         => (int) $m['paid_invoices'],
                'created_at'            => (string) $m['created_at'],
            ];
        }

        // Security Monitor logs (with King Crown Green Marking metadata!)
        $sSql = 'SELECT * FROM security_logs ORDER BY id DESC LIMIT 80';
        $secLogs = [];
        foreach ($pdo->query($sSql)->fetchAll() as $log) {
            $secLogs[] = [
                'id'                    => (int) $log['id'],
                'ip_address'            => (string) $log['ip_address'],
                'username_attempted'    => (string) ($log['username_attempted'] ?? '—'),
                'event_type'            => (string) $log['event_type'],
                'details'               => (string) ($log['details'] ?? ''),
                'country_code'          => (string) ($log['country_code'] ?? ''),
                'is_whitelisted'        => !empty($log['is_whitelisted']),
                'success_session_count' => (int) ($log['success_session_count'] ?? 0),
                'created_at'            => (string) $log['created_at'],
            ];
        }

        // IP Firewall active blocks & King whitelisted IPs
        $ipRules = [];
        foreach ($pdo->query("SELECT * FROM ip_firewall WHERE rule_type IN ('block', 'whitelist') ORDER BY updated_at DESC LIMIT 80")->fetchAll() as $r) {
            $ipRules[] = [
                'id'             => (int) $r['id'],
                'ip_address'     => (string) $r['ip_address'],
                'rule_type'      => (string) $r['rule_type'],
                'duration_label' => (string) $r['duration_label'],
                'clean_sessions' => (int) $r['clean_sessions'],
                'reason'         => (string) ($r['reason'] ?? ''),
                'expires_at'     => (string) ($r['expires_at'] ?? 'Never'),
            ];
        }

        $tvStmt = $pdo->prepare("SELECT COALESCE(SUM(fiat_amount), 0) FROM invoices WHERE status = 'confirmed' AND is_prefund = ?");
        $tvStmt->execute([Database::boolVal(false, $pdo)]);
        $totalVol = (float) ($tvStmt->fetchColumn() ?: 0);
        $totalFees = (float) ($pdo->query("SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE type = 'fee_debit' AND status = 'confirmed'")->fetchColumn() ?: 0);
        $blockedCountriesCount = (int) ($pdo->query("SELECT COUNT(*) FROM country_firewall WHERE action = 'Blacklisted'")->fetchColumn() ?: 0);

        $out = [
            'stats' => [
                'total_merchants'     => count($merchants),
                'platform_volume_usd' => number_format($totalVol, 2, '.', ''),
                'total_fees_usd'      => number_format($totalFees, 2, '.', ''),
                'blocked_countries'   => $blockedCountriesCount,
                'active_ip_rules'     => count($ipRules),
            ],
            'merchants'     => $merchants,
            'security_logs' => $secLogs,
            'ip_rules'      => $ipRules,
            'settings'      => Settings::all(),
        ];

        if ($includeCountries) {
            $countries = [];
            foreach ($pdo->query('SELECT country_code, country_name, action FROM country_firewall ORDER BY country_name ASC')->fetchAll() as $c) {
                $countries[] = [
                    'country_code' => (string) $c['country_code'],
                    'country_name' => (string) $c['country_name'],
                    'action'       => (string) $c['action'],
                ];
            }
            $out['countries'] = $countries;
        }

        return $out;
    }
}
