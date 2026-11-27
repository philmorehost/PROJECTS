<?php
/**
 * Admin <-> User (A2P / P2A) CryptoHub Desk Helper.
 * Operates without any third-party exchange/custody API:
 *  - Optional zero-key live market price feed (CoinGecko + open currency FX feed,
 *    short-cached in sas_settings) with automatic fallback to admin manual rates.
 *  - Admin-configurable Buy markup %, Sell spread %, Swap fee %, min/max NGN limits,
 *    and per-asset receiving wallet addresses.
 *  - Atomic DB transactions for Naira / Crypto wallet reservations, swaps, and
 *    admin approval/rejection with double-refund/double-credit guards.
 */

if (!function_exists('bc_crypto_a2p_catalogue')) {
    function bc_crypto_a2p_catalogue() {
        return [
            'USDT_TRC20' => [
                'code' => 'USDT_TRC20',
                'symbol' => 'USDT',
                'name' => 'Tether (TRC-20)',
                'network' => 'TRON',
                'network_label' => 'TRON (TRC-20)',
                'icon' => 'usdt-trx.png',
                'coingecko_id' => 'tether',
                'default_usd' => 1.00,
                'decimals' => 2,
                'address_hint' => 'TRON address starting with T (34 characters)',
            ],
            'USDT_BEP20' => [
                'code' => 'USDT_BEP20',
                'symbol' => 'USDT',
                'name' => 'Tether (BEP-20)',
                'network' => 'BSC',
                'network_label' => 'BNB Smart Chain (BEP-20)',
                'icon' => 'usdt.png',
                'coingecko_id' => 'tether',
                'default_usd' => 1.00,
                'decimals' => 2,
                'address_hint' => 'BEP-20 / EVM address starting with 0x (42 characters)',
            ],
            'USDC' => [
                'code' => 'USDC',
                'symbol' => 'USDC',
                'name' => 'USD Coin (BEP-20 / ERC-20)',
                'network' => 'BSC',
                'network_label' => 'BEP-20 / ERC-20',
                'icon' => 'usdc.png',
                'coingecko_id' => 'usd-coin',
                'default_usd' => 1.00,
                'decimals' => 2,
                'address_hint' => 'EVM address starting with 0x (42 characters)',
            ],
            'BTC' => [
                'code' => 'BTC',
                'symbol' => 'BTC',
                'name' => 'Bitcoin',
                'network' => 'BTC',
                'network_label' => 'Bitcoin Network',
                'icon' => 'btc.png',
                'coingecko_id' => 'bitcoin',
                'default_usd' => 90000.00,
                'decimals' => 6,
                'address_hint' => 'Bitcoin address starting with bc1, 1, or 3',
            ],
            'ETH' => [
                'code' => 'ETH',
                'symbol' => 'ETH',
                'name' => 'Ethereum',
                'network' => 'ETH',
                'network_label' => 'Ethereum (ERC-20)',
                'icon' => 'eth.png',
                'coingecko_id' => 'ethereum',
                'default_usd' => 3200.00,
                'decimals' => 5,
                'address_hint' => 'Ethereum address starting with 0x (42 characters)',
            ],
            'TRX' => [
                'code' => 'TRX',
                'symbol' => 'TRX',
                'name' => 'TRON',
                'network' => 'TRON',
                'network_label' => 'TRON Network',
                'icon' => 'trx.png',
                'coingecko_id' => 'tron',
                'default_usd' => 0.22,
                'decimals' => 2,
                'address_hint' => 'TRON address starting with T (34 characters)',
            ],
            'LTC' => [
                'code' => 'LTC',
                'symbol' => 'LTC',
                'name' => 'Litecoin',
                'network' => 'LTC',
                'network_label' => 'Litecoin Network',
                'icon' => 'ltc.png',
                'coingecko_id' => 'litecoin',
                'default_usd' => 85.00,
                'decimals' => 4,
                'address_hint' => 'Litecoin address starting with ltc1, L, or M',
            ],
            'BCH' => [
                'code' => 'BCH',
                'symbol' => 'BCH',
                'name' => 'Bitcoin Cash',
                'network' => 'BCH',
                'network_label' => 'Bitcoin Cash Network',
                'icon' => 'bch.png',
                'coingecko_id' => 'bitcoin-cash',
                'default_usd' => 420.00,
                'decimals' => 4,
                'address_hint' => 'Bitcoin Cash CashAddr or legacy address',
            ],
            'DOGE' => [
                'code' => 'DOGE',
                'symbol' => 'DOGE',
                'name' => 'Dogecoin',
                'network' => 'DOGE',
                'network_label' => 'Dogecoin Network',
                'icon' => 'doge.png',
                'coingecko_id' => 'dogecoin',
                'default_usd' => 0.35,
                'decimals' => 2,
                'address_hint' => 'Dogecoin address starting with D',
            ],
        ];
    }
}

if (!function_exists('bc_crypto_a2p_validate_address')) {
    function bc_crypto_a2p_validate_address($assetCode, $address) {
        $catalogue = bc_crypto_a2p_catalogue();
        $code = strtoupper(trim((string)$assetCode));
        $addr = trim((string)$address);
        if (!isset($catalogue[$code]) || $addr === '' || strlen($addr) > 128) {
            return false;
        }
        $net = $catalogue[$code]['network'];
        if ($net === 'TRON') {
            return (bool)preg_match('/\AT[1-9A-HJ-NP-Za-km-z]{33}\z/D', $addr);
        }
        if ($net === 'BSC' || $net === 'ETH') {
            return (bool)preg_match('/\A0x[0-9a-fA-F]{40}\z/D', $addr);
        }
        if ($net === 'BTC') {
            return (bool)(preg_match('/\Abc1[0-9ac-hj-np-z]{25,87}\z/D', $addr)
                || preg_match('/\A[13][1-9A-HJ-NP-Za-km-z]{25,34}\z/D', $addr));
        }
        if ($net === 'LTC') {
            return (bool)(preg_match('/\Altc1[0-9ac-hj-np-z]{25,87}\z/D', $addr)
                || preg_match('/\A[LM3][1-9A-HJ-NP-Za-km-z]{25,34}\z/D', $addr));
        }
        if ($net === 'BCH') {
            return (bool)(preg_match('/\A(bitcoincash:)?[qp][0-9a-z]{41}\z/D', strtolower($addr))
                || preg_match('/\A[13][1-9A-HJ-NP-Za-km-z]{25,34}\z/D', $addr));
        }
        if ($net === 'DOGE') {
            return (bool)preg_match('/\AD[5-9A-HJ-NP-Za-km-z][1-9A-HJ-NP-Za-km-z]{32}\z/D', $addr);
        }
        return false;
    }
}

if (!function_exists('bc_crypto_a2p_default_config')) {
    function bc_crypto_a2p_default_config() {
        $assets = [];
        foreach (bc_crypto_a2p_catalogue() as $code => $meta) {
            $assets[$code] = [
                'enabled' => in_array($code, ['USDT_TRC20', 'USDT_BEP20', 'USDC', 'BTC', 'ETH', 'TRX'], true),
                'buy_enabled' => true,
                'sell_enabled' => true,
                'swap_enabled' => true,
                'deposit_address' => '',
                'network_note' => '',
                'manual_usd_price' => (float)$meta['default_usd'],
                'manual_buy_ngn' => 0.0,
                'manual_sell_ngn' => 0.0,
            ];
        }
        return [
            'rate_mode' => 'live',
            'usd_ngn_rate' => 1650.00,
            'buy_markup_pct' => 2.50,
            'sell_markdown_pct' => 2.50,
            'swap_fee_pct' => 1.00,
            'min_trade_ngn' => 2000.00,
            'max_trade_ngn' => 5000000.00,
            'desk_notice' => 'Admin-to-User (A2P) & User-to-Admin (P2A) Desk. Swapped or sold Naira settles directly into your NGN Wallet so you can withdraw locally via Send Fund or globally via International Transfer.',
            'assets' => $assets,
        ];
    }
}

if (!function_exists('bc_crypto_a2p_get_config')) {
    function bc_crypto_a2p_get_config($vendorId) {
        $vid = (int)$vendorId;
        $defaults = bc_crypto_a2p_default_config();
        $raw = function_exists('getVendorOption') ? (string)getVendorOption($vid, 'crypto_a2p_config', '') : '';
        $saved = $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($saved)) {
            return $defaults;
        }
        $cfg = $defaults;
        $cfg['rate_mode'] = (($saved['rate_mode'] ?? 'live') === 'manual') ? 'manual' : 'live';
        if (isset($saved['usd_ngn_rate']) && is_numeric($saved['usd_ngn_rate']) && (float)$saved['usd_ngn_rate'] > 0) {
            $cfg['usd_ngn_rate'] = round((float)$saved['usd_ngn_rate'], 4);
        }
        foreach (['buy_markup_pct' => 50, 'sell_markdown_pct' => 50, 'swap_fee_pct' => 25] as $k => $maxPct) {
            if (isset($saved[$k]) && is_numeric($saved[$k])) {
                $cfg[$k] = max(0.0, min((float)$maxPct, round((float)$saved[$k], 2)));
            }
        }
        if (isset($saved['min_trade_ngn']) && is_numeric($saved['min_trade_ngn']) && (float)$saved['min_trade_ngn'] >= 100) {
            $cfg['min_trade_ngn'] = round((float)$saved['min_trade_ngn'], 2);
        }
        if (isset($saved['max_trade_ngn']) && is_numeric($saved['max_trade_ngn']) && (float)$saved['max_trade_ngn'] >= $cfg['min_trade_ngn']) {
            $cfg['max_trade_ngn'] = round((float)$saved['max_trade_ngn'], 2);
        }
        if (isset($saved['desk_notice']) && is_string($saved['desk_notice']) && trim($saved['desk_notice']) !== '') {
            $cfg['desk_notice'] = substr(trim(strip_tags($saved['desk_notice'])), 0, 500);
        }
        if (isset($saved['assets']) && is_array($saved['assets'])) {
            foreach ($defaults['assets'] as $code => $defAsset) {
                $sa = $saved['assets'][$code] ?? null;
                if (!is_array($sa)) continue;
                $cfg['assets'][$code] = [
                    'enabled' => !empty($sa['enabled']),
                    'buy_enabled' => isset($sa['buy_enabled']) ? !empty($sa['buy_enabled']) : true,
                    'sell_enabled' => isset($sa['sell_enabled']) ? !empty($sa['sell_enabled']) : true,
                    'swap_enabled' => isset($sa['swap_enabled']) ? !empty($sa['swap_enabled']) : true,
                    'deposit_address' => substr(trim(strip_tags((string)($sa['deposit_address'] ?? ''))), 0, 128),
                    'network_note' => substr(trim(strip_tags((string)($sa['network_note'] ?? ''))), 0, 160),
                    'manual_usd_price' => (isset($sa['manual_usd_price']) && is_numeric($sa['manual_usd_price']) && (float)$sa['manual_usd_price'] > 0)
                        ? (float)$sa['manual_usd_price']
                        : (float)$defAsset['manual_usd_price'],
                    'manual_buy_ngn' => (isset($sa['manual_buy_ngn']) && is_numeric($sa['manual_buy_ngn']) && (float)$sa['manual_buy_ngn'] > 0)
                        ? round((float)$sa['manual_buy_ngn'], 4)
                        : 0.0,
                    'manual_sell_ngn' => (isset($sa['manual_sell_ngn']) && is_numeric($sa['manual_sell_ngn']) && (float)$sa['manual_sell_ngn'] > 0)
                        ? round((float)$sa['manual_sell_ngn'], 4)
                        : 0.0,
                ];
            }
        }
        return $cfg;
    }
}

if (!function_exists('bc_crypto_a2p_save_config')) {
    function bc_crypto_a2p_save_config($vendorId, array $cfg) {
        if (!function_exists('setVendorOption')) {
            return false;
        }
        setVendorOption((int)$vendorId, 'crypto_a2p_config', json_encode($cfg));
        return true;
    }
}

if (!function_exists('bc_crypto_a2p_http_json')) {
    function bc_crypto_a2p_http_json($url, $timeout = 4) {
        if (!function_exists('curl_init')) {
            return null;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => (int)$timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'FintechFlow-A2P-Rates/1.0',
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($raw) || $raw === '' || $code < 200 || $code >= 300) {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }
}

if (!function_exists('bc_crypto_a2p_get_rates')) {
    /**
     * Returns market USD prices + USD/NGN FX rate using:
     *  - Cached zero-key public feeds when rate_mode == 'live' (5-min TTL)
     *  - Automatic graceful fallback to last cached prices or admin manual prices
     */
    function bc_crypto_a2p_get_rates($vendorId, array $cfg, $forceRefresh = false) {
        $vid = (int)$vendorId;
        $catalogue = bc_crypto_a2p_catalogue();
        $fallbackPrices = [];
        foreach ($catalogue as $code => $meta) {
            $fallbackPrices[$code] = (float)($cfg['assets'][$code]['manual_usd_price'] ?? $meta['default_usd']);
        }
        $fallbackUsdNgn = (float)$cfg['usd_ngn_rate'];

        if ($cfg['rate_mode'] === 'manual') {
            return [
                'source' => 'manual',
                'usd_ngn' => $fallbackUsdNgn,
                'usd_prices' => $fallbackPrices,
                'updated_at' => gmdate('Y-m-d H:i:s') . ' UTC',
            ];
        }

        $cacheRaw = function_exists('getVendorOption') ? (string)getVendorOption($vid, 'crypto_a2p_rate_cache', '') : '';
        $cache = $cacheRaw !== '' ? json_decode($cacheRaw, true) : null;
        $cacheFresh = is_array($cache)
            && isset($cache['fetched_ts'], $cache['usd_prices'], $cache['usd_ngn'])
            && (time() - (int)$cache['fetched_ts']) < 300;

        if (!$forceRefresh && $cacheFresh) {
            return [
                'source' => 'live_cached',
                'usd_ngn' => (float)$cache['usd_ngn'] > 0 ? (float)$cache['usd_ngn'] : $fallbackUsdNgn,
                'usd_prices' => array_merge($fallbackPrices, (array)$cache['usd_prices']),
                'updated_at' => (string)($cache['updated_at'] ?? gmdate('Y-m-d H:i:s') . ' UTC'),
            ];
        }

        // Fetch public zero-key market feeds with tight timeout
        $ids = array_values(array_unique(array_map(static function ($m) { return $m['coingecko_id']; }, $catalogue)));
        $cgUrl = 'https://api.coingecko.com/api/v3/simple/price?ids=' . urlencode(implode(',', $ids)) . '&vs_currencies=usd';
        $cgData = bc_crypto_a2p_http_json($cgUrl, 4);

        $fxData = bc_crypto_a2p_http_json('https://latest.currency-api.pages.dev/v1/currencies/usd.json', 4);
        if (!is_array($fxData) || empty($fxData['usd']['ngn'])) {
            $fxData = bc_crypto_a2p_http_json('https://cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@latest/v1/currencies/usd.json', 4);
        }

        $livePrices = [];
        $gotLiveCoin = false;
        if (is_array($cgData)) {
            foreach ($catalogue as $code => $meta) {
                $cid = $meta['coingecko_id'];
                if (isset($cgData[$cid]['usd']) && is_numeric($cgData[$cid]['usd']) && (float)$cgData[$cid]['usd'] > 0) {
                    $livePrices[$code] = (float)$cgData[$cid]['usd'];
                    $gotLiveCoin = true;
                }
            }
        }
        $liveUsdNgn = 0.0;
        if (is_array($fxData) && isset($fxData['usd']['ngn']) && is_numeric($fxData['usd']['ngn']) && (float)$fxData['usd']['ngn'] > 100) {
            $liveUsdNgn = (float)$fxData['usd']['ngn'];
        }

        if ($gotLiveCoin || $liveUsdNgn > 0) {
            $resolvedPrices = array_merge($fallbackPrices, is_array($cache['usd_prices'] ?? null) ? $cache['usd_prices'] : [], $livePrices);
            $resolvedUsdNgn = $liveUsdNgn > 0
                ? $liveUsdNgn
                : ((isset($cache['usd_ngn']) && (float)$cache['usd_ngn'] > 100) ? (float)$cache['usd_ngn'] : $fallbackUsdNgn);
            $payload = [
                'fetched_ts' => time(),
                'updated_at' => gmdate('Y-m-d H:i:s') . ' UTC',
                'usd_ngn' => $resolvedUsdNgn,
                'usd_prices' => $resolvedPrices,
            ];
            if (function_exists('setVendorOption')) {
                setVendorOption($vid, 'crypto_a2p_rate_cache', json_encode($payload));
            }
            return [
                'source' => 'live',
                'usd_ngn' => $resolvedUsdNgn,
                'usd_prices' => $resolvedPrices,
                'updated_at' => $payload['updated_at'],
            ];
        }

        // Fallback gracefully to stale cache if present, else admin manual rates
        if (is_array($cache) && isset($cache['usd_prices'], $cache['usd_ngn'])) {
            return [
                'source' => 'cached_fallback',
                'usd_ngn' => (float)$cache['usd_ngn'] > 0 ? (float)$cache['usd_ngn'] : $fallbackUsdNgn,
                'usd_prices' => array_merge($fallbackPrices, (array)$cache['usd_prices']),
                'updated_at' => (string)($cache['updated_at'] ?? gmdate('Y-m-d H:i:s') . ' UTC'),
            ];
        }

        return [
            'source' => 'manual_fallback',
            'usd_ngn' => $fallbackUsdNgn,
            'usd_prices' => $fallbackPrices,
            'updated_at' => gmdate('Y-m-d H:i:s') . ' UTC',
        ];
    }
}

if (!function_exists('bc_crypto_a2p_quote_asset')) {
    function bc_crypto_a2p_quote_asset(array $cfg, array $rates, $assetCode) {
        $code = strtoupper(trim((string)$assetCode));
        $catalogue = bc_crypto_a2p_catalogue();
        if (!isset($catalogue[$code])) {
            return null;
        }
        $meta = $catalogue[$code];
        $assetCfg = $cfg['assets'][$code] ?? [];
        $usdNgn = (float)($rates['usd_ngn'] ?? $cfg['usd_ngn_rate']);
        $usdPrice = (float)($rates['usd_prices'][$code] ?? $assetCfg['manual_usd_price'] ?? $meta['default_usd']);
        $baseNgn = $usdPrice * $usdNgn;
        $buyMarkup = (float)$cfg['buy_markup_pct'];
        $sellMarkdown = (float)$cfg['sell_markdown_pct'];
        $buyRateNgn = round($baseNgn * (1 + ($buyMarkup / 100)), 4);
        $sellRateNgn = round($baseNgn * max(0.01, (1 - ($sellMarkdown / 100))), 4);
        if (($cfg['rate_mode'] ?? 'live') === 'manual') {
            if (!empty($assetCfg['manual_buy_ngn']) && (float)$assetCfg['manual_buy_ngn'] > 0) {
                $buyRateNgn = round((float)$assetCfg['manual_buy_ngn'], 4);
            }
            if (!empty($assetCfg['manual_sell_ngn']) && (float)$assetCfg['manual_sell_ngn'] > 0) {
                $sellRateNgn = round((float)$assetCfg['manual_sell_ngn'], 4);
            }
        }

        return [
            'code' => $code,
            'symbol' => $meta['symbol'],
            'name' => $meta['name'],
            'network' => $meta['network'],
            'network_label' => $meta['network_label'],
            'icon' => $meta['icon'],
            'decimals' => (int)$meta['decimals'],
            'address_hint' => $meta['address_hint'],
            'enabled' => !empty($assetCfg['enabled']),
            'buy_enabled' => !empty($assetCfg['enabled']) && !empty($assetCfg['buy_enabled']),
            'sell_enabled' => !empty($assetCfg['enabled']) && !empty($assetCfg['sell_enabled']),
            'swap_enabled' => !empty($assetCfg['enabled']) && !empty($assetCfg['swap_enabled']),
            'deposit_address' => (string)($assetCfg['deposit_address'] ?? ''),
            'network_note' => (string)($assetCfg['network_note'] ?? ''),
            'usd_price' => $usdPrice,
            'usd_ngn' => $usdNgn,
            'base_ngn' => round($baseNgn, 4),
            'buy_markup_pct' => $buyMarkup,
            'sell_markdown_pct' => $sellMarkdown,
            'buy_rate_ngn' => $buyRateNgn,
            'sell_rate_ngn' => $sellRateNgn,
        ];
    }
}

if (!function_exists('bc_crypto_a2p_all_quotes')) {
    function bc_crypto_a2p_all_quotes($vendorId, $forceRefresh = false) {
        $cfg = bc_crypto_a2p_get_config($vendorId);
        $rates = bc_crypto_a2p_get_rates($vendorId, $cfg, $forceRefresh);
        $quotes = [];
        foreach (bc_crypto_a2p_catalogue() as $code => $_) {
            $quotes[$code] = bc_crypto_a2p_quote_asset($cfg, $rates, $code);
        }
        return ['config' => $cfg, 'rates' => $rates, 'quotes' => $quotes];
    }
}

if (!function_exists('bc_crypto_a2p_submit_buy')) {
    function bc_crypto_a2p_submit_buy(mysqli $db, $vendorId, array $user, array $input) {
        $vid = (int)$vendorId;
        $userId = (int)($user['id'] ?? 0);
        $username = (string)($user['username'] ?? '');
        $asset = strtoupper(trim((string)($input['asset'] ?? '')));
        $delivery = (($input['delivery_mode'] ?? 'external') === 'internal') ? 'internal' : 'external';
        $destAddress = trim((string)($input['destination_address'] ?? ''));
        $inputUnit = (($input['amount_unit'] ?? 'NGN') === 'CRYPTO') ? 'CRYPTO' : 'NGN';
        $rawAmt = (float)($input['amount'] ?? 0);

        if ($vid < 1 || $userId < 1 || $username === '' || !is_finite($rawAmt) || $rawAmt <= 0) {
            return ['ok' => false, 'message' => 'Enter a valid positive amount.'];
        }
        $bundle = bc_crypto_a2p_all_quotes($vid);
        $cfg = $bundle['config'];
        $rates = $bundle['rates'];
        $q = $bundle['quotes'][$asset] ?? null;
        if (!$q || !$q['buy_enabled']) {
            return ['ok' => false, 'message' => 'This cryptocurrency is currently unavailable for Buy orders.'];
        }
        if ($delivery === 'external' && !bc_crypto_a2p_validate_address($asset, $destAddress)) {
            return ['ok' => false, 'message' => 'Invalid ' . $q['network_label'] . ' wallet address (' . $q['address_hint'] . ').'];
        }
        if ($delivery === 'internal') {
            $destAddress = 'INTERNAL_WALLET (' . $asset . ')';
        }

        $rateNgn = (float)$q['buy_rate_ngn'];
        if ($rateNgn <= 0) {
            return ['ok' => false, 'message' => 'Asset rate is unavailable. Please try again.'];
        }
        if ($inputUnit === 'NGN') {
            $ngnTotal = round($rawAmt, 2);
            $cryptoAmount = round($ngnTotal / $rateNgn, 8);
        } else {
            $cryptoAmount = round($rawAmt, 8);
            $ngnTotal = round($cryptoAmount * $rateNgn, 2);
        }

        if ($ngnTotal < (float)$cfg['min_trade_ngn'] || $ngnTotal > (float)$cfg['max_trade_ngn']) {
            return ['ok' => false, 'message' => 'Order amount must be between ₦' . number_format((float)$cfg['min_trade_ngn'], 2) . ' and ₦' . number_format((float)$cfg['max_trade_ngn'], 2) . '.'];
        }
        if ($cryptoAmount <= 0) {
            return ['ok' => false, 'message' => 'Calculated crypto quantity is too small.'];
        }

        $ref = 'A2PB_' . date('ymdHis') . '_' . bin2hex(random_bytes(3));
        $meta = json_encode([
            'desk_model' => 'A2P',
            'side' => 'buy',
            'asset' => $asset,
            'symbol' => $q['symbol'],
            'network' => $q['network_label'],
            'delivery_mode' => $delivery,
            'crypto_amount' => $cryptoAmount,
            'ngn_amount' => $ngnTotal,
            'base_ngn_rate' => $q['base_ngn'],
            'applied_rate_ngn' => $rateNgn,
            'markup_pct' => $q['buy_markup_pct'],
            'rate_source' => $rates['source'],
            'destination_address' => $destAddress,
        ]);

        $db->begin_transaction();
        try {
            $uq = $db->prepare('SELECT balance FROM sas_users WHERE id=? AND vendor_id=? LIMIT 1 FOR UPDATE');
            $uq->bind_param('ii', $userId, $vid);
            $uq->execute();
            $uRow = $uq->get_result()->fetch_assoc();
            $uq->close();
            if (!$uRow) {
                $db->rollback();
                return ['ok' => false, 'message' => 'Account record not found.'];
            }
            $balBefore = (float)$uRow['balance'];
            if ($balBefore < $ngnTotal) {
                $db->rollback();
                return ['ok' => false, 'message' => 'Insufficient Naira wallet balance (Available: ₦' . number_format($balBefore, 2) . ').'];
            }
            $balAfter = round($balBefore - $ngnTotal, 2);

            $deb = $db->prepare('UPDATE sas_users SET balance = balance - ? WHERE id=? AND vendor_id=? AND balance >= ?');
            $deb->bind_param('diid', $ngnTotal, $userId, $vid, $ngnTotal);
            $deb->execute();
            $affected = $deb->affected_rows;
            $deb->close();
            if ($affected !== 1) {
                $db->rollback();
                return ['ok' => false, 'message' => 'Unable to reserve Naira balance. Please try again.'];
            }

            $prod = $asset;
            $txType = 'Crypto Buy (A2P)';
            $desc = 'A2P Crypto Buy: ' . $cryptoAmount . ' ' . $q['symbol'] . ' (' . $q['network_label'] . ')';
            $mode = 'web';
            $statusPending = 2;
            $host = function_exists('bc_safe_host_sql') ? bc_safe_host_sql($db) : '';
            $txIns = $db->prepare('INSERT INTO sas_transactions (vendor_id, product_unique_id, type_alternative, reference, username, amount, discounted_amount, balance_before, balance_after, description, mode, api_website, status, date) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())');
            $txIns->bind_param('issssddddsssi', $vid, $prod, $txType, $ref, $username, $ngnTotal, $ngnTotal, $balBefore, $balAfter, $desc, $mode, $host, $statusPending);
            $txIns->execute();
            $txIns->close();

            $typeCrypto = 'a2p_buy';
            $emptyStr = '';
            $cIns = $db->prepare('INSERT INTO sas_crypto_transactions (vendor_id, username, reference, type, currency_code, amount, status, plisio_tx_id, blockchain_txid, invoice_url, address, view_key, metadata) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $stStr = '2';
            $cIns->bind_param('issssdsssssss', $vid, $username, $ref, $typeCrypto, $asset, $cryptoAmount, $stStr, $emptyStr, $emptyStr, $emptyStr, $destAddress, $emptyStr, $meta);
            $cIns->execute();
            $cIns->close();

            $db->commit();
            return [
                'ok' => true,
                'reference' => $ref,
                'message' => 'Buy order ' . $ref . ' submitted! ₦' . number_format($ngnTotal, 2) . ' reserved for ' . rtrim(rtrim(sprintf('%.8f', $cryptoAmount), '0'), '.') . ' ' . $q['symbol'] . '. Admin will fulfill shortly.',
            ];
        } catch (Throwable $e) {
            $db->rollback();
            return ['ok' => false, 'message' => 'Order could not be processed. No funds were moved.'];
        }
    }
}

if (!function_exists('bc_crypto_a2p_submit_sell')) {
    function bc_crypto_a2p_submit_sell(mysqli $db, $vendorId, array $user, array $input) {
        $vid = (int)$vendorId;
        $userId = (int)($user['id'] ?? 0);
        $username = (string)($user['username'] ?? '');
        $asset = strtoupper(trim((string)($input['asset'] ?? '')));
        $source = (($input['sell_source'] ?? 'external') === 'internal') ? 'internal' : 'external';
        $txHash = trim(strip_tags((string)($input['tx_hash'] ?? '')));
        $senderNote = substr(trim(strip_tags((string)($input['sender_note'] ?? ''))), 0, 180);
        $cryptoAmount = round((float)($input['amount'] ?? 0), 8);

        if ($vid < 1 || $userId < 1 || $username === '' || !is_finite($cryptoAmount) || $cryptoAmount <= 0) {
            return ['ok' => false, 'message' => 'Enter a valid positive crypto amount to sell.'];
        }
        $bundle = bc_crypto_a2p_all_quotes($vid);
        $cfg = $bundle['config'];
        $rates = $bundle['rates'];
        $q = $bundle['quotes'][$asset] ?? null;
        if (!$q || !$q['sell_enabled']) {
            return ['ok' => false, 'message' => 'This cryptocurrency is currently unavailable for Sell orders.'];
        }
        if ($source === 'external') {
            if (trim((string)$q['deposit_address']) === '') {
                return ['ok' => false, 'message' => 'Admin receiving wallet address for ' . $q['name'] . ' is not configured yet. Please choose another asset or contact support.'];
            }
            if (strlen($txHash) < 8 || strlen($txHash) > 160) {
                return ['ok' => false, 'message' => 'Enter the Blockchain TX Hash / Transaction ID (minimum 8 characters) after transferring to the admin wallet.'];
            }
        }

        $rateNgn = (float)$q['sell_rate_ngn'];
        $ngnPayout = round($cryptoAmount * $rateNgn, 2);
        if ($ngnPayout < (float)$cfg['min_trade_ngn'] || $ngnPayout > (float)$cfg['max_trade_ngn']) {
            return ['ok' => false, 'message' => 'Sell value must be between ₦' . number_format((float)$cfg['min_trade_ngn'], 2) . ' and ₦' . number_format((float)$cfg['max_trade_ngn'], 2) . '.'];
        }

        $ref = 'P2AS_' . date('ymdHis') . '_' . bin2hex(random_bytes(3));
        $addr = ($source === 'external') ? $q['deposit_address'] : ('INTERNAL_WALLET (' . $asset . ')');
        $meta = json_encode([
            'desk_model' => 'P2A',
            'side' => 'sell',
            'sell_source' => $source,
            'asset' => $asset,
            'symbol' => $q['symbol'],
            'network' => $q['network_label'],
            'crypto_amount' => $cryptoAmount,
            'ngn_amount' => $ngnPayout,
            'base_ngn_rate' => $q['base_ngn'],
            'applied_rate_ngn' => $rateNgn,
            'markdown_pct' => $q['sell_markdown_pct'],
            'rate_source' => $rates['source'],
            'deposit_address' => $addr,
            'tx_hash' => $txHash,
            'sender_note' => $senderNote,
        ]);

        $db->begin_transaction();
        try {
            if ($source === 'internal') {
                $wq = $db->prepare('SELECT balance FROM sas_user_crypto_wallets WHERE vendor_id=? AND username=? AND currency_code=? LIMIT 1 FOR UPDATE');
                $wq->bind_param('iss', $vid, $username, $asset);
                $wq->execute();
                $wRow = $wq->get_result()->fetch_assoc();
                $wq->close();
                if (!$wRow || (float)$wRow['balance'] < $cryptoAmount) {
                    $db->rollback();
                    return ['ok' => false, 'message' => 'Insufficient ' . $q['symbol'] . ' wallet balance for this sell order.'];
                }
                $sqlAmt = sprintf('%.18F', $cryptoAmount);
                $uStmt = $db->prepare("UPDATE sas_user_crypto_wallets SET balance = balance - $sqlAmt, ledger_balance = ledger_balance - $sqlAmt WHERE vendor_id=? AND username=? AND currency_code=? AND balance >= $sqlAmt");
                $uStmt->bind_param('iss', $vid, $username, $asset);
                $uStmt->execute();
                $aff = $uStmt->affected_rows;
                $uStmt->close();
                if ($aff !== 1) {
                    $db->rollback();
                    return ['ok' => false, 'message' => 'Could not reserve your crypto wallet balance.'];
                }
            }

            $typeCrypto = 'p2a_sell';
            $emptyStr = '';
            $stStr = '2';
            $cIns = $db->prepare('INSERT INTO sas_crypto_transactions (vendor_id, username, reference, type, currency_code, amount, status, plisio_tx_id, blockchain_txid, invoice_url, address, view_key, metadata) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $cIns->bind_param('issssdsssssss', $vid, $username, $ref, $typeCrypto, $asset, $cryptoAmount, $stStr, $emptyStr, $txHash, $emptyStr, $addr, $emptyStr, $meta);
            $cIns->execute();
            $cIns->close();

            $db->commit();
            return [
                'ok' => true,
                'reference' => $ref,
                'message' => 'Sell order ' . $ref . ' submitted! Expected payout: ₦' . number_format($ngnPayout, 2) . ' to your NGN Wallet once verified by admin.',
            ];
        } catch (Throwable $e) {
            $db->rollback();
            return ['ok' => false, 'message' => 'Sell order could not be logged. Please try again.'];
        }
    }
}

if (!function_exists('bc_crypto_a2p_execute_swap')) {
    function bc_crypto_a2p_execute_swap(mysqli $db, $vendorId, array $user, array $input) {
        $vid = (int)$vendorId;
        $userId = (int)($user['id'] ?? 0);
        $username = (string)($user['username'] ?? '');
        $from = strtoupper(trim((string)($input['swap_from'] ?? '')));
        $to = strtoupper(trim((string)($input['swap_to'] ?? '')));
        $rawAmt = (float)($input['amount'] ?? 0);

        if ($vid < 1 || $userId < 1 || $username === '' || $from === '' || $to === '' || $from === $to || !is_finite($rawAmt) || $rawAmt <= 0) {
            return ['ok' => false, 'message' => 'Choose two different wallets and enter a valid positive amount.'];
        }
        $bundle = bc_crypto_a2p_all_quotes($vid);
        $cfg = $bundle['config'];
        $rates = $bundle['rates'];
        $quotes = $bundle['quotes'];
        $feePct = (float)$cfg['swap_fee_pct'];

        // Allow swapping from a legacy held wallet if balance > 0, else require swap_enabled
        $fromIsNgn = ($from === 'NGN');
        $toIsNgn = ($to === 'NGN');
        if (!$fromIsNgn && !isset($quotes[$from])) {
            return ['ok' => false, 'message' => 'Unsupported source asset.'];
        }
        if (!$toIsNgn && (!isset($quotes[$to]) || !$quotes[$to]['swap_enabled'])) {
            return ['ok' => false, 'message' => 'Destination asset is currently unavailable for swaps.'];
        }
        if ($fromIsNgn) {
            $fromAmount = round($rawAmt, 2);
            $grossNgn = $fromAmount;
        } else {
            if (!$quotes[$from]['swap_enabled']) {
                return ['ok' => false, 'message' => 'Source asset is currently disabled for swaps.'];
            }
            $fromAmount = round($rawAmt, 8);
            $grossNgn = $fromAmount * (float)$quotes[$from]['base_ngn'];
        }

        if ($grossNgn < (float)$cfg['min_trade_ngn'] || $grossNgn > (float)$cfg['max_trade_ngn']) {
            return ['ok' => false, 'message' => 'Swap value must be between ₦' . number_format((float)$cfg['min_trade_ngn'], 2) . ' and ₦' . number_format((float)$cfg['max_trade_ngn'], 2) . '.'];
        }

        $feeNgn = round($grossNgn * ($feePct / 100), 2);
        $netNgn = round($grossNgn - $feeNgn, 2);
        if ($netNgn <= 0) {
            return ['ok' => false, 'message' => 'Swap amount is too small after fee.'];
        }

        if ($toIsNgn) {
            $toAmount = $netNgn;
        } else {
            $toBaseNgn = (float)$quotes[$to]['base_ngn'];
            if ($toBaseNgn <= 0) {
                return ['ok' => false, 'message' => 'Destination rate unavailable.'];
            }
            $toAmount = round($netNgn / $toBaseNgn, 8);
            if ($toAmount <= 0) {
                return ['ok' => false, 'message' => 'Calculated output amount is too small.'];
            }
        }

        $ref = 'SWP_' . date('ymdHis') . '_' . bin2hex(random_bytes(3));
        $meta = json_encode([
            'desk_model' => 'A2P_SWAP',
            'from' => $from,
            'to' => $to,
            'from_amount' => $fromAmount,
            'to_amount' => $toAmount,
            'gross_ngn' => round($grossNgn, 2),
            'fee_pct' => $feePct,
            'fee_ngn' => $feeNgn,
            'net_ngn' => $netNgn,
            'rate_source' => $rates['source'],
        ]);

        $db->begin_transaction();
        try {
            // Lock NGN user row first consistently to prevent lock-order deadlocks
            $uq = $db->prepare('SELECT balance FROM sas_users WHERE id=? AND vendor_id=? LIMIT 1 FOR UPDATE');
            $uq->bind_param('ii', $userId, $vid);
            $uq->execute();
            $uRow = $uq->get_result()->fetch_assoc();
            $uq->close();
            if (!$uRow) {
                $db->rollback();
                return ['ok' => false, 'message' => 'User account unavailable.'];
            }
            $ngnBefore = (float)$uRow['balance'];

            // Ensure crypto wallet rows exist before FOR UPDATE
            if (!$fromIsNgn) {
                $insW = $db->prepare('INSERT IGNORE INTO sas_user_crypto_wallets (vendor_id, username, currency_code) VALUES (?,?,?)');
                $insW->bind_param('iss', $vid, $username, $from);
                $insW->execute();
                $insW->close();
            }
            if (!$toIsNgn) {
                $insW2 = $db->prepare('INSERT IGNORE INTO sas_user_crypto_wallets (vendor_id, username, currency_code) VALUES (?,?,?)');
                $insW2->bind_param('iss', $vid, $username, $to);
                $insW2->execute();
                $insW2->close();
            }

            if ($fromIsNgn) {
                if ($ngnBefore < $fromAmount) {
                    $db->rollback();
                    return ['ok' => false, 'message' => 'Insufficient Naira balance (Available: ₦' . number_format($ngnBefore, 2) . ').'];
                }
                $deb = $db->prepare('UPDATE sas_users SET balance = balance - ? WHERE id=? AND vendor_id=? AND balance >= ?');
                $deb->bind_param('diid', $fromAmount, $userId, $vid, $fromAmount);
                $deb->execute();
                if ($deb->affected_rows !== 1) {
                    $deb->close();
                    $db->rollback();
                    return ['ok' => false, 'message' => 'Unable to debit Naira wallet.'];
                }
                $deb->close();
                $ngnAfter = round($ngnBefore - $fromAmount, 2);
            } else {
                $wq = $db->prepare('SELECT balance FROM sas_user_crypto_wallets WHERE vendor_id=? AND username=? AND currency_code=? LIMIT 1 FOR UPDATE');
                $wq->bind_param('iss', $vid, $username, $from);
                $wq->execute();
                $wRow = $wq->get_result()->fetch_assoc();
                $wq->close();
                if (!$wRow || (float)$wRow['balance'] < $fromAmount) {
                    $db->rollback();
                    return ['ok' => false, 'message' => 'Insufficient ' . $from . ' wallet balance.'];
                }
                $sqlFrom = sprintf('%.18F', $fromAmount);
                $debW = $db->prepare("UPDATE sas_user_crypto_wallets SET balance = balance - $sqlFrom, ledger_balance = ledger_balance - $sqlFrom WHERE vendor_id=? AND username=? AND currency_code=? AND balance >= $sqlFrom");
                $debW->bind_param('iss', $vid, $username, $from);
                $debW->execute();
                if ($debW->affected_rows !== 1) {
                    $debW->close();
                    $db->rollback();
                    return ['ok' => false, 'message' => 'Unable to debit ' . $from . ' wallet.'];
                }
                $debW->close();
            }

            if ($toIsNgn) {
                $cred = $db->prepare('UPDATE sas_users SET balance = balance + ? WHERE id=? AND vendor_id=?');
                $cred->bind_param('dii', $toAmount, $userId, $vid);
                $cred->execute();
                $cred->close();
                $ngnAfter = round($ngnBefore + $toAmount, 2);
            } else {
                $sqlTo = sprintf('%.18F', $toAmount);
                $credW = $db->prepare("UPDATE sas_user_crypto_wallets SET balance = balance + $sqlTo, ledger_balance = ledger_balance + $sqlTo WHERE vendor_id=? AND username=? AND currency_code=?");
                $credW->bind_param('iss', $vid, $username, $to);
                $credW->execute();
                $credW->close();
            }

            if ($fromIsNgn || $toIsNgn) {
                $prod = $from . '_TO_' . $to;
                $txType = $toIsNgn ? 'Crypto Swap to NGN' : 'NGN Swap to Crypto';
                $desc = "Instant Swap: $fromAmount $from -> $toAmount $to (Fee: ₦" . number_format($feeNgn, 2) . ')';
                $mode = 'web';
                $stOk = 1;
                $host = function_exists('bc_safe_host_sql') ? bc_safe_host_sql($db) : '';
                $ngnAmtField = $toIsNgn ? $toAmount : $fromAmount;
                $txIns = $db->prepare('INSERT INTO sas_transactions (vendor_id, product_unique_id, type_alternative, reference, username, amount, discounted_amount, balance_before, balance_after, description, mode, api_website, status, date) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())');
                $txIns->bind_param('issssddddsssi', $vid, $prod, $txType, $ref, $username, $ngnAmtField, $ngnAmtField, $ngnBefore, $ngnAfter, $desc, $mode, $host, $stOk);
                $txIns->execute();
                $txIns->close();
            }

            $typeCrypto = 'a2p_swap';
            $emptyStr = '';
            $stStr = '1';
            $addrSummary = $from . ' -> ' . $to;
            $cIns = $db->prepare('INSERT INTO sas_crypto_transactions (vendor_id, username, reference, type, currency_code, amount, status, plisio_tx_id, blockchain_txid, invoice_url, address, view_key, metadata) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $logCurrency = $toIsNgn ? $from : $to;
            $logAmount = $toIsNgn ? $fromAmount : $toAmount;
            $cIns->bind_param('issssdsssssss', $vid, $username, $ref, $typeCrypto, $logCurrency, $logAmount, $stStr, $emptyStr, $emptyStr, $emptyStr, $addrSummary, $emptyStr, $meta);
            $cIns->execute();
            $cIns->close();

            $db->commit();
            $outDisplay = $toIsNgn ? ('₦' . number_format($toAmount, 2)) : (rtrim(rtrim(sprintf('%.8f', $toAmount), '0'), '.') . ' ' . $to);
            return [
                'ok' => true,
                'reference' => $ref,
                'to_is_ngn' => $toIsNgn,
                'message' => 'Swap completed (' . $ref . ')! Received ' . $outDisplay . ($toIsNgn ? ' in your Naira Wallet (ready for Send Fund or International Transfer).' : ' in your Crypto Wallet.'),
            ];
        } catch (Throwable $e) {
            $db->rollback();
            return ['ok' => false, 'message' => 'Swap failed safely; balances unchanged.'];
        }
    }
}

if (!function_exists('bc_crypto_a2p_admin_resolve')) {
    function bc_crypto_a2p_admin_resolve(mysqli $db, $vendorId, $adminEmail, $action, $reference, array $extra = []) {
        $vid = (int)$vendorId;
        $ref = trim((string)$reference);
        if ($vid < 1 || $ref === '') {
            return ['ok' => false, 'message' => 'Invalid reference.'];
        }

        $db->begin_transaction();
        try {
            $q = $db->prepare('SELECT * FROM sas_crypto_transactions WHERE vendor_id=? AND reference=? LIMIT 1 FOR UPDATE');
            $q->bind_param('is', $vid, $ref);
            $q->execute();
            $row = $q->get_result()->fetch_assoc();
            $q->close();

            if (!$row) {
                $db->rollback();
                return ['ok' => false, 'message' => 'Order not found.'];
            }
            if ((string)$row['status'] !== '2') {
                $db->rollback();
                return ['ok' => false, 'message' => 'This order has already been processed.'];
            }

            $meta = json_decode((string)$row['metadata'], true);
            if (!is_array($meta)) $meta = [];
            $username = (string)$row['username'];
            $asset = (string)$row['currency_code'];
            $cryptoAmount = (float)$row['amount'];
            $ngnAmount = (float)($meta['ngn_amount'] ?? 0);
            $meta['reviewed_by'] = (string)$adminEmail;
            $meta['reviewed_at'] = gmdate('Y-m-d H:i:s') . ' UTC';

            if ($action === 'approve_a2p_buy' && $row['type'] === 'a2p_buy') {
                $txHash = trim(strip_tags((string)($extra['tx_hash'] ?? '')));
                $meta['fulfillment_tx_hash'] = $txHash;
                $metaJson = json_encode($meta);

                $up = $db->prepare("UPDATE sas_crypto_transactions SET status='1', blockchain_txid=COALESCE(NULLIF(?, ''), blockchain_txid), metadata=? WHERE id=? AND vendor_id=? AND status='2'");
                $up->bind_param('ssii', $txHash, $metaJson, $row['id'], $vid);
                $up->execute();
                if ($up->affected_rows !== 1) {
                    $up->close();
                    $db->rollback();
                    return ['ok' => false, 'message' => 'Order state changed concurrently.'];
                }
                $up->close();

                if (($meta['delivery_mode'] ?? 'external') === 'internal' && $cryptoAmount > 0) {
                    $insW = $db->prepare('INSERT IGNORE INTO sas_user_crypto_wallets (vendor_id, username, currency_code) VALUES (?,?,?)');
                    $insW->bind_param('iss', $vid, $username, $asset);
                    $insW->execute();
                    $insW->close();
                    $sqlAmt = sprintf('%.18F', $cryptoAmount);
                    $cw = $db->prepare("UPDATE sas_user_crypto_wallets SET balance = balance + $sqlAmt, ledger_balance = ledger_balance + $sqlAmt WHERE vendor_id=? AND username=? AND currency_code=?");
                    $cw->bind_param('iss', $vid, $username, $asset);
                    $cw->execute();
                    $cw->close();
                }

                $utx = $db->prepare('UPDATE sas_transactions SET status=1 WHERE vendor_id=? AND reference=?');
                $utx->bind_param('is', $vid, $ref);
                $utx->execute();
                $utx->close();

                $db->commit();
                return ['ok' => true, 'message' => "Buy order $ref marked fulfilled."];
            }

            if ($action === 'reject_a2p_buy' && $row['type'] === 'a2p_buy') {
                $reason = substr(trim(strip_tags((string)($extra['reason'] ?? 'Rejected by Admin'))), 0, 200);
                if ($reason === '') $reason = 'Rejected by Admin';
                $meta['reject_reason'] = $reason;
                $meta['refunded'] = true;
                $metaJson = json_encode($meta);

                $up = $db->prepare("UPDATE sas_crypto_transactions SET status='0', metadata=? WHERE id=? AND vendor_id=? AND status='2'");
                $up->bind_param('sii', $metaJson, $row['id'], $vid);
                $up->execute();
                if ($up->affected_rows !== 1) {
                    $up->close();
                    $db->rollback();
                    return ['ok' => false, 'message' => 'Order state changed concurrently.'];
                }
                $up->close();

                if ($ngnAmount > 0) {
                    $uq = $db->prepare('SELECT id, balance FROM sas_users WHERE vendor_id=? AND username=? LIMIT 1 FOR UPDATE');
                    $uq->bind_param('is', $vid, $username);
                    $uq->execute();
                    $uRow = $uq->get_result()->fetch_assoc();
                    $uq->close();
                    if ($uRow) {
                        $balBefore = (float)$uRow['balance'];
                        $balAfter = round($balBefore + $ngnAmount, 2);
                        $cr = $db->prepare('UPDATE sas_users SET balance = balance + ? WHERE id=? AND vendor_id=?');
                        $cr->bind_param('dii', $ngnAmount, $uRow['id'], $vid);
                        $cr->execute();
                        $cr->close();

                        $refRefund = $ref . '_RFND';
                        $prod = $asset;
                        $txType = 'Crypto Buy Refund';
                        $desc = "Refund for rejected A2P Buy $ref ($reason)";
                        $mode = 'web';
                        $stOk = 1;
                        $host = function_exists('bc_safe_host_sql') ? bc_safe_host_sql($db) : '';
                        $txIns = $db->prepare('INSERT INTO sas_transactions (vendor_id, product_unique_id, type_alternative, reference, username, amount, discounted_amount, balance_before, balance_after, description, mode, api_website, status, date) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())');
                        $txIns->bind_param('issssddddsssi', $vid, $prod, $txType, $refRefund, $username, $ngnAmount, $ngnAmount, $balBefore, $balAfter, $desc, $mode, $host, $stOk);
                        $txIns->execute();
                        $txIns->close();
                    }
                }

                $utx = $db->prepare('UPDATE sas_transactions SET status=3 WHERE vendor_id=? AND reference=?');
                $utx->bind_param('is', $vid, $ref);
                $utx->execute();
                $utx->close();

                $db->commit();
                return ['ok' => true, 'message' => "Buy order $ref rejected and ₦" . number_format($ngnAmount, 2) . " refunded to @$username."];
            }

            if ($action === 'approve_p2a_sell' && $row['type'] === 'p2a_sell') {
                $note = substr(trim(strip_tags((string)($extra['note'] ?? 'Verified by Admin'))), 0, 200);
                $meta['admin_note'] = $note;
                $metaJson = json_encode($meta);

                $up = $db->prepare("UPDATE sas_crypto_transactions SET status='1', metadata=? WHERE id=? AND vendor_id=? AND status='2'");
                $up->bind_param('sii', $metaJson, $row['id'], $vid);
                $up->execute();
                if ($up->affected_rows !== 1) {
                    $up->close();
                    $db->rollback();
                    return ['ok' => false, 'message' => 'Order state changed concurrently.'];
                }
                $up->close();

                if ($ngnAmount > 0) {
                    $uq = $db->prepare('SELECT id, balance FROM sas_users WHERE vendor_id=? AND username=? LIMIT 1 FOR UPDATE');
                    $uq->bind_param('is', $vid, $username);
                    $uq->execute();
                    $uRow = $uq->get_result()->fetch_assoc();
                    $uq->close();
                    if (!$uRow) {
                        $db->rollback();
                        return ['ok' => false, 'message' => 'User account not found.'];
                    }
                    $balBefore = (float)$uRow['balance'];
                    $balAfter = round($balBefore + $ngnAmount, 2);
                    $cr = $db->prepare('UPDATE sas_users SET balance = balance + ? WHERE id=? AND vendor_id=?');
                    $cr->bind_param('dii', $ngnAmount, $uRow['id'], $vid);
                    $cr->execute();
                    $cr->close();

                    $prod = $asset;
                    $txType = 'Crypto Sell (P2A)';
                    $desc = "P2A Crypto Sell settlement: $cryptoAmount $asset -> ₦" . number_format($ngnAmount, 2);
                    $mode = 'web';
                    $stOk = 1;
                    $host = function_exists('bc_safe_host_sql') ? bc_safe_host_sql($db) : '';
                    $txIns = $db->prepare('INSERT INTO sas_transactions (vendor_id, product_unique_id, type_alternative, reference, username, amount, discounted_amount, balance_before, balance_after, description, mode, api_website, status, date) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())');
                    $txIns->bind_param('issssddddsssi', $vid, $prod, $txType, $ref, $username, $ngnAmount, $ngnAmount, $balBefore, $balAfter, $desc, $mode, $host, $stOk);
                    $txIns->execute();
                    $txIns->close();
                }

                $db->commit();
                return ['ok' => true, 'message' => "Sell order $ref verified and ₦" . number_format($ngnAmount, 2) . " credited to @$username."];
            }

            if ($action === 'reject_p2a_sell' && $row['type'] === 'p2a_sell') {
                $reason = substr(trim(strip_tags((string)($extra['reason'] ?? 'Rejected by Admin'))), 0, 200);
                if ($reason === '') $reason = 'Rejected by Admin';
                $meta['reject_reason'] = $reason;
                $metaJson = json_encode($meta);

                $up = $db->prepare("UPDATE sas_crypto_transactions SET status='0', metadata=? WHERE id=? AND vendor_id=? AND status='2'");
                $up->bind_param('sii', $metaJson, $row['id'], $vid);
                $up->execute();
                if ($up->affected_rows !== 1) {
                    $up->close();
                    $db->rollback();
                    return ['ok' => false, 'message' => 'Order state changed concurrently.'];
                }
                $up->close();

                if (($meta['sell_source'] ?? 'external') === 'internal' && $cryptoAmount > 0) {
                    $sqlAmt = sprintf('%.18F', $cryptoAmount);
                    $cw = $db->prepare("UPDATE sas_user_crypto_wallets SET balance = balance + $sqlAmt, ledger_balance = ledger_balance + $sqlAmt WHERE vendor_id=? AND username=? AND currency_code=?");
                    $cw->bind_param('iss', $vid, $username, $asset);
                    $cw->execute();
                    $cw->close();
                }

                $db->commit();
                return ['ok' => true, 'message' => "Sell order $ref rejected."];
            }

            $db->rollback();
            return ['ok' => false, 'message' => 'Unsupported order action.'];
        } catch (Throwable $e) {
            $db->rollback();
            return ['ok' => false, 'message' => 'Failed to update order cleanly.'];
        }
    }
}
