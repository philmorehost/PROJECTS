<?php
/**
 * DGV7 §25 — provider service catalogue, active-provider context, referral engine
 * and generic provider service requests (eSIM / POS / tuition / international transfer).
 *
 * Design rules carried over from bc-fintech-providers.php:
 *   - A catalogue lists what a provider COULD support. It is not consent and it is
 *     not configuration: live calls still go through the provider registry seam and
 *     fail closed while endpoints/credentials are unset.
 *   - The frontend renders EXACTLY the active provider's services. Switching the
 *     active provider in bc-admin → Service Control changes what users see; no
 *     other provider's services leak into the page.
 *   - BTC swap: none of the current providers offers a BTC swap rail, so BTC
 *     pairs are hidden rather than silently routed elsewhere (§28: the legacy
 *     Plisio rail was removed entirely).
 *
 * This file is side-effect free apart from lazy CREATE TABLE IF NOT EXISTS calls
 * inside the functions that need the tables.
 */

require_once(__DIR__ . '/bc-fintech-api-access.php');

if (!function_exists('bc_svc_provider_catalogue')) {
    /**
     * What each provider offers, per the client specification (MZEE SITE UPGRADE +
     * BP-DGV7-2026-01). Keys are stable service slugs used in URLs, toggles and logs.
     */
    function bc_svc_provider_catalogue() {
        return [
            'klasha' => [
                'label' => 'Klasha',
                'tagline' => 'Cross-border payouts, multi-currency wallets & live FX',
                'services' => [
                    'china_payout' => ['label' => 'Payout to China', 'page' => 'IntlTransfer.php', 'note' => 'Direct settlement to Chinese bank accounts & mobile wallets'],
                    'wire_transfer' => ['label' => 'Wire Transfer', 'page' => 'IntlTransfer.php', 'note' => 'Wire quotes locked at live FX before you send'],
                    'multi_currency_wallet' => ['label' => 'Multi-Currency Wallet', 'page' => 'IntlTransfer.php', 'note' => 'NGN, GHS, USD & ZAR wallets under one account'],
                    'stablecoin_wallet' => ['label' => 'USDC / USDT Wallet', 'page' => 'CryptoHub.php', 'note' => 'Hold and move USDC & USDT'],
                    'fx_rates' => ['label' => 'Live Exchange Rates', 'page' => 'IntlTransfer.php', 'note' => 'Real-time rates before every conversion'],
                    'virtual_account' => ['label' => 'Virtual Account', 'page' => 'VirtualBanks.php', 'note' => 'Dedicated account numbers for collections'],
                ],
                'crypto_assets' => ['USDC', 'USDT'],
                'btc_swap' => false,
                'currencies' => ['NGN', 'GHS', 'USD', 'ZAR', 'CNY'],
            ],
            'flutterwave' => [
                'label' => 'Flutterwave',
                'tagline' => 'Global transfers, multi-currency accounts, cards & POS',
                'services' => [
                    'global_transfer' => ['label' => 'Global Transfers', 'page' => 'IntlTransfer.php', 'note' => 'Send money across borders quickly and securely'],
                    'multi_currency_accounts' => ['label' => 'USD / GBP / EUR Accounts', 'page' => 'VirtualBanks.php', 'note' => 'Receive payments globally in three currencies'],
                    'stablecoins' => ['label' => 'Stablecoins', 'page' => 'CryptoHub.php', 'note' => 'Send & receive USDC and USDT (RLUSD on request)'],
                    'payment_links' => ['label' => 'Payment Links', 'page' => 'IntlTransfer.php', 'note' => 'Collect one-time or recurring payments with a link'],
                    'invoicing' => ['label' => 'Invoicing', 'page' => 'IntlTransfer.php', 'note' => 'Branded invoices that get you paid faster'],
                    'single_bulk_transfers' => ['label' => 'Single & Bulk Transfers', 'page' => 'IntlTransfer.php', 'note' => 'Pay bank accounts, mobile money & stablecoin wallets'],
                    'pos_request' => ['label' => 'POS Devices', 'page' => 'POSRequest.php', 'note' => 'Request a point-of-sale terminal for your business'],
                    'virtual_card' => ['label' => 'Virtual Dollar Card', 'page' => 'VirtualCard.php', 'note' => 'USD virtual cards, issued instantly'],
                ],
                'crypto_assets' => ['USDC', 'USDT'],
                'btc_swap' => false,
                'currencies' => ['NGN', 'USD', 'GBP', 'EUR'],
            ],
            'platnova' => [
                'label' => 'Platnova',
                'tagline' => 'Tuition worldwide & cross-border payments',
                'services' => [
                    'tuition' => ['label' => 'Pay Tuition Worldwide', 'page' => 'Tuition.php', 'note' => '160+ universities, student ID validated before payment'],
                    'multi_currency_wallet' => ['label' => 'Multi-Currency Wallet', 'page' => 'IntlTransfer.php', 'note' => 'Fund via local conversion or USDC/USDT at live FX'],
                    'china_payout' => ['label' => 'Payout to China', 'page' => 'IntlTransfer.php', 'note' => 'Wallet provisioning → instant funding → China settlement'],
                ],
                'crypto_assets' => ['USDC', 'USDT'],
                'btc_swap' => false,
                'currencies' => ['NGN', 'USD', 'GBP', 'EUR', 'CNY'],
            ],
        ];
    }
}

if (!function_exists('bc_svc_active_provider')) {
    /**
     * The provider the vendor has switched on (§28: the legacy Plisio rail was
     * removed; the default is the first API the vendor may actually use).
     */
    function bc_svc_active_provider($vendor_id) {
        $vendor_id = (int)$vendor_id;
        $value = function_exists('getVendorOption') ? (string)getVendorOption($vendor_id, 'fintech_active_provider', '') : '';
        $value = strtolower(trim($value));
        $catalogue = bc_svc_provider_catalogue();
        if (isset($catalogue[$value])) return $value;
        // §28: Plisio is gone. Default to the first API this vendor may use
        // (all three on NON-SAAS; purchased ones on SAAS), else Flutterwave.
        if (function_exists('bc_fintech_api_accessible_providers')) {
            $usable = bc_fintech_api_accessible_providers($vendor_id);
            if ($usable) return $usable[0];
        }
        return 'flutterwave';
    }
}

if (!function_exists('bc_svc_provider_services')) {
    /** Services of one provider (or of the active provider when $provider is null). */
    function bc_svc_provider_services($vendor_id, $provider = null) {
        $catalogue = bc_svc_provider_catalogue();
        $provider = $provider !== null ? strtolower(trim((string)$provider)) : bc_svc_active_provider($vendor_id);
        return $catalogue[$provider]['services'] ?? [];
    }
}

if (!function_exists('bc_svc_provider_assets')) {
    /**
     * Tradable crypto assets under the active provider. RLUSD stays opt-in only:
     * it appears solely when the merchant profile explicitly enables it (same rule
     * as bc_fintech_provider_asset_enabled()).
     */
    function bc_svc_provider_assets($vendor_id, $provider = null) {
        $catalogue = bc_svc_provider_catalogue();
        $provider = $provider !== null ? strtolower(trim((string)$provider)) : bc_svc_active_provider($vendor_id);
        $assets = $catalogue[$provider]['crypto_assets'] ?? [];
        if ($provider === 'flutterwave'
            && function_exists('bc_fintech_provider_asset_enabled')
            && bc_fintech_provider_asset_enabled((int)$vendor_id, 'flutterwave', 'RLUSD')) {
            $assets[] = 'RLUSD';
        }
        return $assets;
    }
}

if (!function_exists('bc_svc_provider_label')) {
    function bc_svc_provider_label($provider) {
        $catalogue = bc_svc_provider_catalogue();
        $provider = strtolower(trim((string)$provider));
        return $catalogue[$provider]['label'] ?? 'Provider';
    }
}

// ─────────────────────────── service requests ───────────────────────────

if (!function_exists('bc_svc_ensure_schema')) {
    function bc_svc_ensure_schema() {
        global $connection_server;
        if (!($connection_server instanceof mysqli)) return false;
        mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_service_requests (
            id INT NOT NULL AUTO_INCREMENT,
            vendor_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL DEFAULT 0,
            username VARCHAR(191) NOT NULL DEFAULT '',
            type VARCHAR(32) NOT NULL DEFAULT '',
            provider VARCHAR(32) NOT NULL DEFAULT '',
            reference VARCHAR(64) NOT NULL DEFAULT '',
            payload TEXT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'pending',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY idx_vendor_user (vendor_id, user_id),
            KEY idx_vendor_type (vendor_id, type, status)
        )");
        return true;
    }
}

if (!function_exists('bc_svc_request_create')) {
    /** Record a provider service request. Returns the reference or ''. */
    function bc_svc_request_create($vendor_id, $user, $type, $provider, array $payload) {
        global $connection_server;
        if (!($connection_server instanceof mysqli) || !is_array($user)) return '';
        bc_svc_ensure_schema();
        $vendor_id = (int)$vendor_id;
        $user_id = (int)($user['id'] ?? 0);
        $username = mysqli_real_escape_string($connection_server, (string)($user['username'] ?? ''));
        $type_e = mysqli_real_escape_string($connection_server, substr((string)$type, 0, 32));
        $provider_e = mysqli_real_escape_string($connection_server, substr((string)$provider, 0, 32));
        $reference = 'SVC-' . strtoupper(substr((string)$type, 0, 3)) . '-' . date('ymdHis') . '-' . $user_id;
        $payload_e = mysqli_real_escape_string($connection_server, json_encode($payload) ?: '{}');
        $ok = mysqli_query($connection_server, "INSERT INTO sas_service_requests
            (vendor_id, user_id, username, type, provider, reference, payload, status, created_at)
            VALUES ('$vendor_id', '$user_id', '$username', '$type_e', '$provider_e', '$reference', '$payload_e', 'pending', NOW())");
        return $ok ? $reference : '';
    }
}

if (!function_exists('bc_svc_requests_for_user')) {
    function bc_svc_requests_for_user($vendor_id, $user_id, $type = '', $limit = 20) {
        global $connection_server;
        if (!($connection_server instanceof mysqli)) return [];
        bc_svc_ensure_schema();
        $vendor_id = (int)$vendor_id; $user_id = (int)$user_id; $limit = max(1, min(100, (int)$limit));
        $type_sql = $type !== '' ? " AND type='" . mysqli_real_escape_string($connection_server, substr((string)$type, 0, 32)) . "'" : '';
        $q = mysqli_query($connection_server, "SELECT * FROM sas_service_requests
            WHERE vendor_id='$vendor_id' AND user_id='$user_id'$type_sql ORDER BY id DESC LIMIT $limit");
        $rows = [];
        if ($q) while ($r = mysqli_fetch_assoc($q)) $rows[] = $r;
        return $rows;
    }
}

// ─────────────────────────── referral engine ───────────────────────────
// $10 to the referrer AND $10 to the referee, once the referee's cumulative
// Crypto + International Transfer volume reaches $100. Other products never
// count (MZEE SITE UPGRADE p.8 / Blueprint Module 5).

if (!function_exists('bc_referral_ensure_schema')) {
    function bc_referral_ensure_schema() {
        global $connection_server;
        if (!($connection_server instanceof mysqli)) return false;
        mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_referral_progress (
            id INT NOT NULL AUTO_INCREMENT,
            vendor_id INT UNSIGNED NOT NULL,
            referrer_id INT UNSIGNED NOT NULL,
            referee_id INT UNSIGNED NOT NULL,
            qualifying_usd DECIMAL(14,2) NOT NULL DEFAULT 0,
            rewarded TINYINT(1) NOT NULL DEFAULT 0,
            reward_status VARCHAR(32) NOT NULL DEFAULT 'tracking',
            created_at DATETIME NOT NULL,
            rewarded_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_referee (vendor_id, referee_id),
            KEY idx_referrer (vendor_id, referrer_id)
        )");
        mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_creator_applications (
            id INT NOT NULL AUTO_INCREMENT,
            vendor_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL DEFAULT 0,
            full_name VARCHAR(191) NOT NULL DEFAULT '',
            email VARCHAR(191) NOT NULL DEFAULT '',
            platform VARCHAR(64) NOT NULL DEFAULT '',
            handle VARCHAR(191) NOT NULL DEFAULT '',
            followers VARCHAR(64) NOT NULL DEFAULT '',
            pitch TEXT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'pending',
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_vendor_status (vendor_id, status)
        )");
        return true;
    }
}

if (!function_exists('bc_referral_categories')) {
    /** Only these transaction categories build referral volume. */
    function bc_referral_categories() {
        return ['crypto', 'intl_transfer'];
    }
}

if (!function_exists('bc_referral_record_volume')) {
    /**
     * Add qualifying USD volume for a referee and pay out when the $100 mark is
     * crossed. Safe to call repeatedly: the rewarded flag + ledger reference make
     * the payout idempotent. Returns a status string.
     */
    function bc_referral_record_volume($vendor_id, $referee_id, $usd_amount, $category) {
        global $connection_server;
        if (!($connection_server instanceof mysqli)) return 'unavailable';
        if (!in_array(strtolower((string)$category), bc_referral_categories(), true)) return 'not_qualifying';
        $usd_amount = (float)$usd_amount;
        if ($usd_amount <= 0) return 'invalid_amount';
        bc_referral_ensure_schema();
        $vendor_id = (int)$vendor_id; $referee_id = (int)$referee_id;

        $q = mysqli_query($connection_server, "SELECT id, referrer FROM sas_users WHERE vendor_id='$vendor_id' AND id='$referee_id' LIMIT 1");
        $referee = $q ? mysqli_fetch_assoc($q) : null;
        // sas_users.referral_id holds the referrer's user id (set at Register.php).
        $q2 = mysqli_query($connection_server, "SELECT referral_id, username FROM sas_users WHERE vendor_id='$vendor_id' AND id='$referee_id' LIMIT 1");
        $ref_row = $q2 ? mysqli_fetch_assoc($q2) : null;
        $referrer_id = (int)($ref_row['referral_id'] ?? 0);
        if ($referrer_id <= 0 || $referrer_id === $referee_id) return 'no_referrer';

        mysqli_query($connection_server, "INSERT INTO sas_referral_progress
            (vendor_id, referrer_id, referee_id, qualifying_usd, created_at)
            VALUES ('$vendor_id', '$referrer_id', '$referee_id', '$usd_amount', NOW())
            ON DUPLICATE KEY UPDATE qualifying_usd = qualifying_usd + VALUES(qualifying_usd)");

        $q3 = mysqli_query($connection_server, "SELECT * FROM sas_referral_progress WHERE vendor_id='$vendor_id' AND referee_id='$referee_id' LIMIT 1");
        $progress = $q3 ? mysqli_fetch_assoc($q3) : null;
        if (!$progress) return 'unavailable';
        if ((int)$progress['rewarded'] === 1) return 'already_rewarded';
        if ((float)$progress['qualifying_usd'] < 100.00) return 'tracking';

        // Threshold crossed — pay $10 to BOTH sides, converted at the live rate.
        $rate = function_exists('getLiveExchangeRate') ? (float)getLiveExchangeRate('USD', 'NGN', $vendor_id) : 0.0;
        if ($rate <= 0 || !function_exists('bc_fintech_ledger_post')) {
            mysqli_query($connection_server, "UPDATE sas_referral_progress SET reward_status='pending_rate'
                WHERE vendor_id='$vendor_id' AND referee_id='$referee_id' AND rewarded=0");
            return 'pending_rate';
        }
        $ngn = round(10.00 * $rate, 2);
        $names = [];
        foreach ([$referrer_id, $referee_id] as $uid) {
            $qu = mysqli_query($connection_server, "SELECT username FROM sas_users WHERE vendor_id='$vendor_id' AND id='$uid' LIMIT 1");
            $u = $qu ? mysqli_fetch_assoc($qu) : null;
            if ($u) $names[$uid] = (string)$u['username'];
        }
        if (count($names) !== 2) return 'unavailable';
        $ref = 'REF10-' . $referee_id;
        $paid_r = bc_fintech_ledger_post($vendor_id, $names[$referrer_id], 'NGN', 'credit', $ngn, $ref . '-R', ['type' => 'referral_reward', 'memo' => 'Referral reward $10 (referee reached $100 qualifying volume)']);
        $paid_e = bc_fintech_ledger_post($vendor_id, $names[$referee_id], 'NGN', 'credit', $ngn, $ref . '-E', ['type' => 'referral_reward', 'memo' => 'Welcome referral reward $10']);
        if (empty($paid_r['ok']) || empty($paid_e['ok'])) {
            mysqli_query($connection_server, "UPDATE sas_referral_progress SET reward_status='pending_payout'
                WHERE vendor_id='$vendor_id' AND referee_id='$referee_id' AND rewarded=0");
            return 'pending_payout';
        }
        mysqli_query($connection_server, "UPDATE sas_referral_progress SET rewarded=1, reward_status='paid', rewarded_at=NOW()
            WHERE vendor_id='$vendor_id' AND referee_id='$referee_id'");
        return 'paid';
    }
}

if (!function_exists('bc_referral_progress_for_user')) {
    /** Referral stats for the Referral page: people referred + their progress. */
    function bc_referral_progress_for_user($vendor_id, $user_id) {
        global $connection_server;
        $out = ['referred' => 0, 'qualified' => 0, 'earned_usd' => 0.0, 'rows' => []];
        if (!($connection_server instanceof mysqli)) return $out;
        bc_referral_ensure_schema();
        $vendor_id = (int)$vendor_id; $user_id = (int)$user_id;
        $q = mysqli_query($connection_server, "SELECT COUNT(*) AS c FROM sas_users WHERE vendor_id='$vendor_id' AND referral_id='$user_id'");
        if ($q && ($r = mysqli_fetch_assoc($q))) $out['referred'] = (int)$r['c'];
        $q = mysqli_query($connection_server, "SELECT p.*, u.username AS referee_name FROM sas_referral_progress p
            LEFT JOIN sas_users u ON u.id = p.referee_id AND u.vendor_id = p.vendor_id
            WHERE p.vendor_id='$vendor_id' AND p.referrer_id='$user_id' ORDER BY p.id DESC LIMIT 50");
        if ($q) while ($r = mysqli_fetch_assoc($q)) {
            $out['rows'][] = $r;
            if ((int)$r['rewarded'] === 1) { $out['qualified']++; $out['earned_usd'] += 10.00; }
        }
        return $out;
    }
}
