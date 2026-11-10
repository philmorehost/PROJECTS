<?php
declare(strict_types=1);

namespace App\Support;

final class Settings
{
    /** @var array<string, string>|null */
    private static ?array $cache = null;

    /**
     * Default system & security settings.
     *
     * @return array<string, string>
     */
    public static function defaults(): array
    {
        return [
            'site_title'                  => 'BlockPayne',
            'site_tagline'                => 'Direct-to-Wallet Crypto Payments',
            'brand_color'                 => '#10b981',
            'custom_logo_url'             => '',
            'gateway_fee_percent'         => '1.00',
            'welcome_credit_usd'          => '2.00',
            'min_prefund_balance_usd'     => '0.00',
            'invoice_expiry_minutes'      => '30',
            'required_confirmations_btc'  => '1',
            'enabled_coins'               => 'BTC,LTC,BCH,ETH,USDT-TRC20,USDT-ERC20',
            'usd_ngn_rate'                => '1600',

            // Blockchain Node / Live Crypto API Configuration
            'blockchain_provider'         => 'auto', // auto (Mempool/Explorers) | blockbee | custom_rpc
            'blockbee_api_key'            => '',
            'btc_rpc_or_api_url'          => 'https://mempool.space/api',
            'ltc_api_url'                 => 'https://litecoinspace.org/api',
            'eth_rpc_url'                 => 'https://cloudflare-eth.com',
            'tron_api_url'                => 'https://api.trongrid.io',

            // Admin Master Crypto Deposit Addresses (for Merchant Prefund Top-Ups)
            'admin_btc_address'           => '',
            'admin_ltc_address'           => '',
            'admin_bch_address'           => '',
            'admin_usdt_trc20_address'    => '',
            'admin_eth_address'           => '',

            // Payhub Fiat Gateway Integration (for Merchant Prefund Top-Ups)
            'payhub_enabled'              => '1',
            'payhub_base_url'             => 'https://merchant.payhub.com.ng',
            'payhub_public_key'           => '',
            'payhub_secret_key'           => '',

            // cPHulk / Imunify360-Grade Brute-Force & Firewall Parameters
            'firewall_enabled'            => '1',
            'bf_period_minutes'           => '15',
            'bf_max_failures_account'     => '5',
            'bf_max_failures_ip'          => '10',
            'bf_protect_default_admin'    => '0', // Privilege Exception: 0 = exempt default admin/administrator from username lock, 1 = lock them too
            'bf_lock_account_on_fail'     => '1',
            'bf_ip_block_duration'        => '1_day', // 1_day | 1_week | 1_month | 1_year
            'smart_whitelist_threshold'   => '5',

            // Email / Notifications
            'mail_from_address'           => 'security@blockpayne.local',
            'mail_from_name'              => 'BlockPayne Security',
            'admin_alert_email'           => '',
            'maintenance_mode'            => '0',
        ];
    }

    public static function flush(): void
    {
        self::$cache = null;
    }

    /**
     * @return array<string, string>
     */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $merged = self::defaults();
        try {
            $pdo = Database::connection();
            $rows = $pdo->query('SELECT key_name, key_value FROM settings')->fetchAll();
            foreach ($rows as $row) {
                $merged[(string) $row['key_name']] = (string) ($row['key_value'] ?? '');
            }
            self::$cache = $merged;
        } catch (\Throwable) {
            return $merged;
        }
        return self::$cache;
    }

    public static function get(string $key, ?string $default = null): string
    {
        $all = self::all();
        if (array_key_exists($key, $all)) {
            return $all[$key];
        }
        return $default ?? '';
    }

    public static function bool(string $key): bool
    {
        $val = strtolower(trim(self::get($key, '0')));
        return in_array($val, ['1', 'true', 'yes', 'on'], true);
    }

    public static function int(string $key, int $default = 0): int
    {
        $val = self::get($key, (string) $default);
        return is_numeric($val) ? (int) $val : $default;
    }

    public static function float(string $key, float $default = 0.0): float
    {
        $val = self::get($key, (string) $default);
        return is_numeric($val) ? (float) $val : $default;
    }

    public static function set(string $key, string $value): void
    {
        Database::upsert(
            'settings',
            [
                'key_name'   => $key,
                'key_value'  => $value,
                'updated_at' => bp_now(),
            ],
            ['key_name'],
            ['key_value', 'updated_at']
        );
        if (self::$cache !== null) {
            self::$cache[$key] = $value;
        }
    }

    /**
     * @param array<string, scalar|null> $pairs
     */
    public static function setMany(array $pairs): void
    {
        foreach ($pairs as $k => $v) {
            self::set((string) $k, (string) ($v ?? ''));
        }
    }
}
