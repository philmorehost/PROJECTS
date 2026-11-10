<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Middleware\BruteForceProtection;
use App\Support\Database;
use App\Support\DynamicBrand;
use App\Support\Settings;

/**
 * Admin Security & Platform Configuration Controller:
 * - Country Firewall (`Whitelisted`, `Not Specified`, `Blacklisted`)
 * - cPHulk Brute-Force Protection parameters & Privilege Exception toggle
 * - Manual IP Block / Whitelist (King Icon with Green Marking) / Unblock
 * - Merchant Lock / Suspend / Activate management
 * - Gateway, Payhub, BlockBee & Crypto Deposit Settings
 */
final class SecurityController
{
    /**
     * @param array<string, mixed> $admin
     */
    private function assertAdmin(array $admin): void
    {
        if (($admin['role'] ?? '') !== 'admin') {
            bp_json(['ok' => false, 'error' => 'Administrator privileges required.'], 403);
        }
    }

    /**
     * Update a single country's firewall rule (`Whitelisted`, `Not Specified`, `Blacklisted`).
     *
     * @param array<string, mixed> $admin
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function updateCountryRule(array $admin, array $input): array
    {
        $this->assertAdmin($admin);

        $code = strtoupper(trim((string) ($input['country_code'] ?? '')));
        $action = trim((string) ($input['action'] ?? 'Not Specified'));

        if (!preg_match('/^[A-Z]{2}$/', $code)) {
            return ['ok' => false, 'error' => 'Invalid ISO country code.'];
        }
        if (!in_array($action, ['Whitelisted', 'Not Specified', 'Blacklisted'], true)) {
            return ['ok' => false, 'error' => 'Invalid firewall action.'];
        }

        $pdo = Database::connection();
        $stmt = $pdo->prepare('UPDATE country_firewall SET action = ?, updated_at = ? WHERE country_code = ?');
        $stmt->execute([$action, bp_now(), $code]);

        return [
            'ok'           => true,
            'country_code' => $code,
            'action'       => $action,
            'message'      => "{$code} set to {$action}.",
        ];
    }

    /**
     * Save cPHulk Brute-Force & Smart Whitelisting parameters.
     *
     * @param array<string, mixed> $admin
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function saveBruteForceConfig(array $admin, array $input): array
    {
        $this->assertAdmin($admin);

        $period = max(1, min(1440, (int) ($input['bf_period_minutes'] ?? 15)));
        $maxAcct = max(2, min(100, (int) ($input['bf_max_failures_account'] ?? 5)));
        $maxIp = max(2, min(250, (int) ($input['bf_max_failures_ip'] ?? 10)));
        $duration = (string) ($input['bf_ip_block_duration'] ?? '1_day');
        if (!isset(BruteForceProtection::DURATIONS[$duration])) {
            $duration = '1_day';
        }
        $smartThresh = max(1, min(50, (int) ($input['smart_whitelist_threshold'] ?? 5)));

        Settings::setMany([
            'firewall_enabled'          => !empty($input['firewall_enabled']) ? '1' : '0',
            'bf_period_minutes'         => (string) $period,
            'bf_max_failures_account'   => (string) $maxAcct,
            'bf_max_failures_ip'        => (string) $maxIp,
            'bf_protect_default_admin'  => !empty($input['bf_protect_default_admin']) ? '1' : '0',
            'bf_lock_account_on_fail'   => !empty($input['bf_lock_account_on_fail']) ? '1' : '0',
            'bf_ip_block_duration'      => $duration,
            'smart_whitelist_threshold' => (string) $smartThresh,
        ]);

        return ['ok' => true, 'message' => 'Brute-force & firewall rules updated.'];
    }

    /**
     * Add or remove manual IP block / King whitelist rule.
     *
     * @param array<string, mixed> $admin
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function manageIpRule(array $admin, array $input): array
    {
        $this->assertAdmin($admin);

        $ip = trim((string) ($input['ip_address'] ?? ''));
        $op = strtolower(trim((string) ($input['operation'] ?? 'block')));
        $duration = (string) ($input['duration'] ?? '1_day');
        $reason = trim((string) ($input['reason'] ?? 'Manual admin rule'));

        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return ['ok' => false, 'error' => 'Enter a valid IPv4 or IPv6 address.'];
        }

        $pdo = Database::connection();
        if ($op === 'remove' || $op === 'unblock') {
            $stmt = $pdo->prepare('DELETE FROM ip_firewall WHERE ip_address = ?');
            $stmt->execute([$ip]);
            return ['ok' => true, 'message' => "IP {$ip} rule cleared."];
        }

        if ($op === 'whitelist') {
            BruteForceProtection::whitelistIp($ip, $reason);
            BruteForceProtection::logEvent($ip, (string) $admin['email'], 'whitelisted_success', 'Manually whitelisted by Admin', null, true, 5);
            return ['ok' => true, 'message' => "IP {$ip} whitelisted with King Crown status."];
        }

        BruteForceProtection::blockIp($ip, $duration, $reason);
        BruteForceProtection::logEvent($ip, (string) $admin['email'], 'ip_block', "Manually blocked ({$duration}): {$reason}", null, false, 0);
        return ['ok' => true, 'message' => "IP {$ip} blocked ({$duration})."];
    }

    /**
     * Unlock, suspend, or reactivate a merchant account.
     *
     * @param array<string, mixed> $admin
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function setMerchantStatus(array $admin, array $input): array
    {
        $this->assertAdmin($admin);

        $targetId = (int) ($input['user_id'] ?? 0);
        $status = strtolower(trim((string) ($input['status'] ?? 'active')));
        if (!in_array($status, ['active', 'suspended', 'locked'], true)) {
            return ['ok' => false, 'error' => 'Invalid user status.'];
        }
        if ($targetId === (int) $admin['id'] && $status !== 'active') {
            return ['ok' => false, 'error' => 'You cannot suspend or lock your own active admin session.'];
        }

        $pdo = Database::connection();
        $stmt = $pdo->prepare('UPDATE users SET status = ?, failed_login_attempts = 0, updated_at = ? WHERE id = ?');
        $stmt->execute([$status, bp_now(), $targetId]);

        return ['ok' => true, 'message' => "Account status updated to {$status}."];
    }

    /**
     * Save Platform Branding, Gateway Fee, Payhub, BlockBee & Master Crypto Deposit Settings.
     *
     * @param array<string, mixed> $admin
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function savePlatformSettings(array $admin, array $input): array
    {
        $this->assertAdmin($admin);

        $allowedKeys = [
            'site_title', 'site_tagline', 'brand_color', 'custom_logo_url',
            'gateway_fee_percent', 'welcome_credit_usd', 'invoice_expiry_minutes',
            'usd_ngn_rate',
            'blockchain_provider', 'blockbee_api_key',
            'btc_rpc_or_api_url', 'ltc_api_url', 'eth_rpc_url', 'tron_api_url',
            'admin_btc_address', 'admin_ltc_address', 'admin_bch_address', 'admin_usdt_trc20_address', 'admin_eth_address',
            'payhub_enabled', 'payhub_base_url', 'payhub_public_key', 'payhub_secret_key',
            'mail_from_address', 'admin_alert_email', 'maintenance_mode',
        ];

        $toSave = [];
        foreach ($allowedKeys as $key) {
            if (array_key_exists($key, $input)) {
                $toSave[$key] = trim((string) $input[$key]);
            }
        }

        if (isset($toSave['site_title']) && $toSave['site_title'] === '') {
            return ['ok' => false, 'error' => 'Site Title cannot be empty.'];
        }
        if (isset($toSave['brand_color'])) {
            $toSave['brand_color'] = DynamicBrand::accentColor($toSave['brand_color']);
        }

        Settings::setMany($toSave);

        // Regenerate dynamic First-Letter SVG favicon whenever branding updates
        $svg = DynamicBrand::faviconSvg(Settings::get('site_title'), Settings::get('brand_color'));
        @file_put_contents(BP_STORAGE . '/cache/favicon.svg', $svg);

        return ['ok' => true, 'message' => 'Platform & gateway configuration saved.'];
    }
}
