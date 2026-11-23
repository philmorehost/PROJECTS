<?php
/**
 * DGV7 §25 — provider service catalogue, active-provider context, referral engine
 * and generic provider service requests (eSIM / POS / tuition / international transfer).
 *
 * Design rules carried over from bc-fintech-providers.php:
 *   - A catalogue lists what a provider COULD support. It is not consent and it is
 *     not configuration: live calls still go through the provider registry seam and
 *     fail closed while endpoints/credentials are unset.
 *   - Legacy pages render the active provider's services. Switching the
 *     active provider in bc-admin → Service Control affects legacy defaults; no
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
        $ok = mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_service_requests (
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
        return $ok === true;
    }
}

if (!function_exists('bc_svc_request_create')) {
    /** Record a provider service request. Returns the reference or ''. */
    function bc_svc_request_create($vendor_id, $user, $type, $provider, array $payload) {
        global $connection_server;
        if (!($connection_server instanceof mysqli) || !is_array($user)) return '';
        $vendor_id = (int)$vendor_id;
        $user_id = (int)($user['id'] ?? 0);
        if($vendor_id<=0 || $user_id<=0 || (int)($user['vendor_id']??0)!==$vendor_id)return '';
        $allowed=['intl_transfer'=>['klasha','flutterwave','platnova'],'tuition'=>['platnova'],'pos'=>['flutterwave'],'esim'=>['platnova','1global']];
        if(!is_string($type)||!is_string($provider)||!in_array($provider,$allowed[$type]??[],true))return '';
        try{
            // Revalidate the selected request route at the storage boundary, not just in page code.
            if($type!=='esim'){
                require_once __DIR__.'/bc-service-provider-routing.php';
                $service=$type==='pos'?'pos_request':$type;
                if(bc_service_route_provider($vendor_id,$service)!==$provider)return '';
                if($type==='intl_transfer' && (!is_string($payload['service']??null)||!isset(bc_service_route_services($vendor_id,$service,$provider)[$payload['service']])))return '';
            }
            $encoded=json_encode($payload,JSON_THROW_ON_ERROR);
            if(strlen($encoded)>60000 || !bc_svc_ensure_schema())return '';
            // Random references do not collide when a user submits twice within the same second.
            // This is identification, NOT idempotency or permission to retry an unknown outcome.
            $reference='SVC-'.strtoupper(substr($type,0,3)).'-'.bin2hex(random_bytes(16));
            $stmt=$connection_server->prepare("INSERT INTO sas_service_requests
                (vendor_id,user_id,username,type,provider,reference,payload,status,created_at)
                SELECT vendor_id,id,username,?,?,?,?, 'pending',NOW() FROM sas_users
                WHERE vendor_id=? AND id=? AND status=1 AND COALESCE(is_blocked,0)=0 AND COALESCE(force_password_change,0)=0");
            if(!$stmt)return '';
            try{
                $stmt->bind_param('ssssii',$type,$provider,$reference,$encoded,$vendor_id,$user_id);
                return $stmt->execute() && $stmt->affected_rows===1 ? $reference : '';
            }finally{$stmt->close();}
        }catch(Throwable $e){return '';}

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
// Coins unlock for the referrer after $100 of audited, completed Crypto and
// International Transfer settlements. Unverified volume never moves money.

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

if (!function_exists('bc_referral_settings')) {
    /** Vendor coin reward; the $100 qualification floor is enforced server-side. */
    function bc_referral_settings($vendor_id) {
        return bc_referral_coin_settings($vendor_id);
    }
}

if (!function_exists('bc_referral_record_volume')) {
    /** Retired unsafe amount-only hook: settlement evidence is required. */
    function bc_referral_record_volume($vendor_id, $referee_id, $usd_amount, $category) {
        // Amount-only calls cannot prove settlement or deduplicate a transaction.
        return 'settlement_evidence_required';
    }
}

if (!function_exists('bc_referral_progress_for_user')) {
    /** Referral stats for the Referral page: people referred + their progress. */
    function bc_referral_progress_for_user($vendor_id, $user_id) {
        return bc_referral_coin_progress($vendor_id, $user_id);
    }
}

require_once __DIR__ . '/bc-referral-coins.php';
