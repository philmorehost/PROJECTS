<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Settings;

/**
 * Unified Non-Custodial Blockchain Polling & Simple Crypto API Service.
 *
 * Integrates:
 * 1. BlockBee / CryptAPI (api.blockbee.io / api.cryptapi.io) — simplest non-custodial forwarding & webhook API
 * 2. Mempool.space & Blockstream Esplora (zero-API-key BTC 0-conf mempool + confirmation polling)
 * 3. Litecoinspace.org (LTC), Blockchair (BCH), TronGrid (USDT-TRC20), Ethereum JSON-RPC / QuickNode / Alchemy (ETH & USDT-ERC20)
 * 4. CoinGecko / BlockBee live fiat-to-crypto exchange rates with 60s local cache
 */
final class BlockchainNodeService
{
    /** @var array<string, float> Default fallback USD prices when offline or rate-limited */
    private const FALLBACK_USD_RATES = [
        'BTC'        => 88500.00,
        'ETH'        => 3150.00,
        'BCH'        => 460.00,
        'LTC'        => 96.00,
        'USDT-TRC20' => 1.00,
        'USDT-ERC20' => 1.00,
    ];

    /** @var array<string, string> Coin symbol -> BlockBee / CryptAPI ticker */
    private const BLOCKBEE_TICKERS = [
        'BTC'        => 'btc',
        'LTC'        => 'ltc',
        'BCH'        => 'bch',
        'ETH'        => 'eth',
        'USDT-TRC20' => 'trc20/usdt',
        'USDT-ERC20' => 'erc20/usdt',
    ];

    private const USDT_ERC20_CONTRACT = '0xdAC17F958D2ee523a2206206994597C13D831ec7';
    private const USDT_TRC20_CONTRACT = 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';

    /**
     * Build a live public block explorer URL for a transaction hash or wallet address.
     */
    public static function explorerUrl(string $coinSymbol, ?string $txHash = null, ?string $address = null): string
    {
        $coin = strtoupper(trim($coinSymbol));
        $tx = trim((string) $txHash);
        $addr = trim((string) $address);

        if ($tx !== '' && !str_starts_with($tx, 'TX-')) {
            return match ($coin) {
                'BTC'        => 'https://mempool.space/tx/' . rawurlencode($tx),
                'LTC'        => 'https://litecoinspace.org/tx/' . rawurlencode($tx),
                'BCH'        => 'https://blockchair.com/bitcoin-cash/transaction/' . rawurlencode($tx),
                'ETH', 'USDT-ERC20' => 'https://etherscan.io/tx/' . rawurlencode($tx),
                'USDT-TRC20' => 'https://tronscan.org/#/transaction/' . rawurlencode($tx),
                default      => 'https://mempool.space/tx/' . rawurlencode($tx),
            };
        }

        if ($addr !== '') {
            $cleanBch = preg_replace('/^bitcoincash:/i', '', $addr) ?? $addr;
            return match ($coin) {
                'BTC'        => 'https://mempool.space/address/' . rawurlencode($addr),
                'LTC'        => 'https://litecoinspace.org/address/' . rawurlencode($addr),
                'BCH'        => 'https://blockchair.com/bitcoin-cash/address/' . rawurlencode($cleanBch),
                'ETH', 'USDT-ERC20' => 'https://etherscan.io/address/' . rawurlencode($addr),
                'USDT-TRC20' => 'https://tronscan.org/#/address/' . rawurlencode($addr),
                default      => 'https://mempool.space/address/' . rawurlencode($addr),
            };
        }

        return 'https://mempool.space';
    }

    /**
     * Return live USD market prices per 1 unit of each supported coin (cached 60s with multi-provider failover).
     *
     * @return array<string, float>
     */
    public function getUsdRates(bool $allowNetwork = true): array
    {
        $cacheFile = BP_STORAGE . '/cache/crypto_rates.json';
        $staleCacheRates = null;
        if (is_file($cacheFile)) {
            $raw = json_decode((string) @file_get_contents($cacheFile), true);
            if (is_array($raw) && isset($raw['ts'], $raw['rates']) && is_array($raw['rates'])) {
                if ((time() - (int) $raw['ts']) < 60) {
                    return $raw['rates'];
                }
                $staleCacheRates = $raw['rates'];
            }
        }

        $rates = is_array($staleCacheRates) ? array_merge(self::FALLBACK_USD_RATES, $staleCacheRates) : self::FALLBACK_USD_RATES;
        if (!$allowNetwork || !function_exists('curl_init')) {
            return $rates;
        }

        // Provider 1: CoinGecko Live API
        $cgUrl = 'https://api.coingecko.com/api/v3/simple/price?ids=bitcoin,ethereum,bitcoin-cash,litecoin,tether&vs_currencies=usd';
        $cg = $this->httpGetJson($cgUrl, 4);
        if (is_array($cg) && isset($cg['bitcoin']['usd'])) {
            $rates['BTC'] = max(1.0, (float) ($cg['bitcoin']['usd'] ?? $rates['BTC']));
            $rates['ETH'] = max(1.0, (float) ($cg['ethereum']['usd'] ?? $rates['ETH']));
            $rates['BCH'] = max(1.0, (float) ($cg['bitcoin-cash']['usd'] ?? $rates['BCH']));
            $rates['LTC'] = max(0.1, (float) ($cg['litecoin']['usd'] ?? $rates['LTC']));
            $usdt = max(0.95, (float) ($cg['tether']['usd'] ?? 1.0));
            $rates['USDT-TRC20'] = $usdt;
            $rates['USDT-ERC20'] = $usdt;
            @file_put_contents($cacheFile, json_encode(['ts' => time(), 'source' => 'coingecko', 'rates' => $rates]));
            return $rates;
        }

        // Provider 2: Binance Public Spot Ticker API
        $bnUrl = 'https://api.binance.com/api/v3/ticker/price?symbols=%5B%22BTCUSDT%22,%22ETHUSDT%22,%22BCHUSDT%22,%22LTCUSDT%22%5D';
        $bn = $this->httpGetJson($bnUrl, 4);
        if (is_array($bn) && !empty($bn)) {
            $map = [];
            foreach ($bn as $row) {
                if (is_array($row) && isset($row['symbol'], $row['price'])) {
                    $map[(string) $row['symbol']] = (float) $row['price'];
                }
            }
            if (!empty($map['BTCUSDT'])) {
                $rates['BTC'] = max(1.0, $map['BTCUSDT']);
                $rates['ETH'] = max(1.0, $map['ETHUSDT'] ?? $rates['ETH']);
                $rates['BCH'] = max(1.0, $map['BCHUSDT'] ?? $rates['BCH']);
                $rates['LTC'] = max(0.1, $map['LTCUSDT'] ?? $rates['LTC']);
                $rates['USDT-TRC20'] = 1.00;
                $rates['USDT-ERC20'] = 1.00;
                @file_put_contents($cacheFile, json_encode(['ts' => time(), 'source' => 'binance', 'rates' => $rates]));
                return $rates;
            }
        }

        // Provider 3: Coinbase Live Exchange Rates API
        $cb = $this->httpGetJson('https://api.coinbase.com/v2/exchange-rates?currency=USD', 4);
        $invRates = $cb['data']['rates'] ?? null;
        if (is_array($invRates) && !empty($invRates['BTC']) && (float) $invRates['BTC'] > 0) {
            $rates['BTC'] = round(1.0 / (float) $invRates['BTC'], 2);
            if (!empty($invRates['ETH']) && (float) $invRates['ETH'] > 0) {
                $rates['ETH'] = round(1.0 / (float) $invRates['ETH'], 2);
            }
            if (!empty($invRates['BCH']) && (float) $invRates['BCH'] > 0) {
                $rates['BCH'] = round(1.0 / (float) $invRates['BCH'], 2);
            }
            if (!empty($invRates['LTC']) && (float) $invRates['LTC'] > 0) {
                $rates['LTC'] = round(1.0 / (float) $invRates['LTC'], 2);
            }
            $rates['USDT-TRC20'] = 1.00;
            $rates['USDT-ERC20'] = 1.00;
            @file_put_contents($cacheFile, json_encode(['ts' => time(), 'source' => 'coinbase', 'rates' => $rates]));
        }

        return $rates;
    }

    /**
     * Convert a fiat amount (USD, NGN, EUR, GBP) into exact 8-decimal crypto amount.
     */
    public function fiatToCrypto(float $fiatAmount, string $fiatCurrency, string $coinSymbol, bool $allowNetwork = true): string
    {
        $usdAmount = $this->toUsd($fiatAmount, $fiatCurrency);
        $rates = $this->getUsdRates($allowNetwork);
        $coin = strtoupper(trim($coinSymbol));
        $coinPriceUsd = $rates[$coin] ?? self::FALLBACK_USD_RATES[$coin] ?? 1.0;

        $decimals = in_array($coin, ['USDT-TRC20', 'USDT-ERC20'], true) ? 2 : 8;
        $crypto = $usdAmount / max(0.0001, $coinPriceUsd);
        return number_format($crypto, $decimals, '.', '');
    }

    public function toUsd(float $amount, string $fiatCurrency): float
    {
        $cur = strtoupper(trim($fiatCurrency));
        if ($cur === 'USD' || $cur === '') {
            return $amount;
        }
        if ($cur === 'NGN') {
            $ngnRate = max(1.0, Settings::float('usd_ngn_rate', 1600.0));
            return $amount / $ngnRate;
        }
        if ($cur === 'EUR') {
            return $amount * 1.08;
        }
        if ($cur === 'GBP') {
            return $amount * 1.27;
        }
        return $amount;
    }

    /**
     * Create a unique non-custodial forwarding address via BlockBee / CryptAPI
     * (ideal when a merchant enters a plain crypto wallet address instead of an xPub!).
     *
     * @return array{ok:bool,address?:string,error?:string}
     */
    public function createBlockBeeForwardingAddress(string $coinSymbol, string $destinationAddress, string $callbackUrl): array
    {
        $coin = strtoupper(trim($coinSymbol));
        $ticker = self::BLOCKBEE_TICKERS[$coin] ?? null;
        if ($ticker === null) {
            return ['ok' => false, 'error' => 'Unsupported coin for BlockBee forwarding.'];
        }

        $apiKey = trim(Settings::get('blockbee_api_key', ''));
        $baseUrl = $apiKey !== ''
            ? "https://api.blockbee.io/{$ticker}/create/"
            : "https://api.cryptapi.io/{$ticker}/create/";

        $params = [
            'callback' => $callbackUrl,
            'address'  => $destinationAddress,
            'pending'  => '1', // Notify immediately on 0-confirmation mempool detection!
        ];
        if ($apiKey !== '') {
            $params['apikey'] = $apiKey;
        }

        if (!function_exists('curl_init')) {
            return ['ok' => false, 'error' => 'cURL extension unavailable; using direct wallet address mode.'];
        }

        $resp = $this->httpGetJson($baseUrl . '?' . http_build_query($params), 8);
        if (is_array($resp) && ($resp['status'] ?? '') === 'success' && !empty($resp['address_in'])) {
            return ['ok' => true, 'address' => (string) $resp['address_in']];
        }

        return ['ok' => false, 'error' => (string) ($resp['error'] ?? 'Could not create forwarding address.')];
    }

    /**
     * Poll blockchain explorers / RPC nodes for incoming payment to an invoice deposit address.
     *
     * @return array{detected:bool,confirmed:bool,received_amount:string,confirmations:int,tx_hash:?string}
     */
    public function checkAddressPayment(string $coinSymbol, string $address, float $expectedAmount, int $sinceTimestamp = 0): array
    {
        $default = [
            'detected'        => false,
            'confirmed'       => false,
            'received_amount' => '0.00000000',
            'confirmations'   => 0,
            'tx_hash'         => null,
        ];

        if (!function_exists('curl_init') || trim($address) === '') {
            return $default;
        }

        $coin = strtoupper(trim($coinSymbol));
        $reqConfs = max(1, Settings::int('required_confirmations_btc', 1));

        if ($coin === 'BTC' || $coin === 'LTC') {
            $apiBase = $coin === 'BTC'
                ? rtrim(Settings::get('btc_rpc_or_api_url', 'https://mempool.space/api'), '/')
                : rtrim(Settings::get('ltc_api_url', 'https://litecoinspace.org/api'), '/');

            $txs = $this->httpGetJson("{$apiBase}/address/" . rawurlencode($address) . '/txs', 6);
            if (!is_array($txs) && $coin === 'BTC') {
                $txs = $this->httpGetJson('https://blockstream.info/api/address/' . rawurlencode($address) . '/txs', 6);
            }
            if (!is_array($txs)) {
                return $default;
            }

            $tipHeight = 0;
            foreach ($txs as $tx) {
                if (!is_array($tx) || empty($tx['vout'])) {
                    continue;
                }
                $satoshis = 0;
                foreach ($tx['vout'] as $vout) {
                    if (($vout['scriptpubkey_address'] ?? '') === $address) {
                        $satoshis += (int) ($vout['value'] ?? 0);
                    }
                }
                if ($satoshis <= 0) {
                    continue;
                }
                $txConfirmed = (bool) ($tx['status']['confirmed'] ?? false);
                $blockTime = (int) ($tx['status']['block_time'] ?? time());
                if ($sinceTimestamp > 0 && $txConfirmed && $blockTime < ($sinceTimestamp - 600)) {
                    continue;
                }

                $amount = $satoshis / 100000000;
                if ($amount + 0.0000005 >= $expectedAmount * 0.995) {
                    $confs = 0;
                    if ($txConfirmed) {
                        if ($tipHeight === 0) {
                            $tip = $this->httpGetRaw("{$apiBase}/blocks/tip/height", 4);
                            $tipHeight = is_numeric(trim((string) $tip)) ? (int) trim((string) $tip) : 0;
                        }
                        $bHeight = (int) ($tx['status']['block_height'] ?? 0);
                        $confs = ($tipHeight > 0 && $bHeight > 0) ? max(1, $tipHeight - $bHeight + 1) : 1;
                    }
                    return [
                        'detected'        => true,
                        'confirmed'       => $confs >= $reqConfs,
                        'received_amount' => number_format($amount, 8, '.', ''),
                        'confirmations'   => $confs,
                        'tx_hash'         => (string) ($tx['txid'] ?? ''),
                    ];
                }
            }
            return $default;
        }

        if ($coin === 'BCH') {
            $resp = $this->httpGetJson('https://api.blockchair.com/bitcoin-cash/dashboards/address/' . rawurlencode($address), 6);
            $cleanAddr = preg_replace('/^bitcoincash:/i', '', $address) ?? $address;
            $addrData = $resp['data'][$cleanAddr]['address'] ?? $resp['data'][$address]['address'] ?? null;
            if (is_array($addrData)) {
                $received = ((float) ($addrData['received'] ?? 0)) / 100000000;
                $unconfirmed = ((float) ($addrData['balance'] ?? 0)) / 100000000;
                $best = max($received, $unconfirmed);
                if ($best >= $expectedAmount * 0.995) {
                    $confs = ((int) ($addrData['transaction_count'] ?? 0)) > 0 ? 1 : 0;
                    return [
                        'detected'        => true,
                        'confirmed'       => $confs >= 1,
                        'received_amount' => number_format($best, 8, '.', ''),
                        'confirmations'   => $confs,
                        'tx_hash'         => null,
                    ];
                }
            }
            return $default;
        }

        if ($coin === 'USDT-TRC20') {
            $tronBase = rtrim(Settings::get('tron_api_url', 'https://api.trongrid.io'), '/');
            $url = "{$tronBase}/v1/accounts/" . rawurlencode($address) . '/transactions/trc20?only_to=true&limit=10&contract_address=' . self::USDT_TRC20_CONTRACT;
            $resp = $this->httpGetJson($url, 6);
            foreach (($resp['data'] ?? []) as $tx) {
                $ts = intdiv((int) ($tx['block_timestamp'] ?? 0), 1000);
                if ($sinceTimestamp > 0 && $ts > 0 && $ts < ($sinceTimestamp - 300)) {
                    continue;
                }
                $val = ((float) ($tx['value'] ?? 0)) / 1000000; // USDT TRC20 has 6 decimals
                if ($val >= $expectedAmount * 0.995) {
                    return [
                        'detected'        => true,
                        'confirmed'       => true,
                        'received_amount' => number_format($val, 8, '.', ''),
                        'confirmations'   => 19,
                        'tx_hash'         => (string) ($tx['transaction_id'] ?? ''),
                    ];
                }
            }
            return $default;
        }

        if ($coin === 'ETH' || $coin === 'USDT-ERC20') {
            $rpcUrl = Settings::get('eth_rpc_url', 'https://cloudflare-eth.com');
            if ($coin === 'ETH') {
                $res = $this->jsonRpc($rpcUrl, 'eth_getBalance', [$address, 'latest']);
                if (is_string($res) && str_starts_with($res, '0x')) {
                    $eth = self::hexWeiToFloat($res, 18);
                    if ($eth >= $expectedAmount * 0.995) {
                        return [
                            'detected'        => true,
                            'confirmed'       => true,
                            'received_amount' => number_format($eth, 8, '.', ''),
                            'confirmations'   => 1,
                            'tx_hash'         => null,
                        ];
                    }
                }
            } else {
                $paddedAddr = str_pad(strtolower( ltrim($address, '0x') ), 64, '0', STR_PAD_LEFT);
                $callData = '0x70a08231' . $paddedAddr; // balanceOf(address)
                $res = $this->jsonRpc($rpcUrl, 'eth_call', [['to' => self::USDT_ERC20_CONTRACT, 'data' => $callData], 'latest']);
                if (is_string($res) && str_starts_with($res, '0x')) {
                    $usdt = self::hexWeiToFloat($res, 6);
                    if ($usdt >= $expectedAmount * 0.995) {
                        return [
                            'detected'        => true,
                            'confirmed'       => true,
                            'received_amount' => number_format($usdt, 8, '.', ''),
                            'confirmations'   => 1,
                            'tx_hash'         => null,
                        ];
                    }
                }
            }
        }

        return $default;
    }

    /**
     * Quick wallet / address balance & tx summary lookup (powers the Blockonomics-style Landing Search Bar).
     *
     * @return array<string, mixed>
     */
    public function lookupAddressSummary(string $query): array
    {
        $q = trim($query);
        if ($q === '') {
            return ['ok' => false, 'error' => 'Enter a wallet address, xPub, or invoice ID.'];
        }

        if (BitcoinHDWalletService::isExtendedPubKey($q)) {
            $hd = new BitcoinHDWalletService();
            $sample = [];
            for ($i = 0; $i < 3; $i++) {
                $sample[] = ['index' => $i, 'address' => $hd->deriveAddress($q, $i, 'BTC')];
            }
            return [
                'ok'      => true,
                'type'    => 'xpub',
                'network' => 'HD Extended Public Key (Non-Custodial)',
                'samples' => $sample,
            ];
        }

        $coin = 'BTC';
        if (str_starts_with($q, '0x') && strlen($q) === 42) {
            $coin = 'ETH';
        } elseif (str_starts_with($q, 'T') && strlen($q) === 34) {
            $coin = 'USDT-TRC20';
        } elseif (str_starts_with(strtolower($q), 'ltc1') || str_starts_with($q, 'L') || str_starts_with($q, 'M')) {
            $coin = 'LTC';
        } elseif (str_starts_with(strtolower($q), 'bitcoincash:')) {
            $coin = 'BCH';
        }

        if (!BitcoinHDWalletService::isValidAddress($coin, $q)) {
            return ['ok' => false, 'error' => 'Unrecognized crypto address or invoice ID format.'];
        }

        $balance = '0.00000000';
        $txCount = 0;
        if (function_exists('curl_init')) {
            if ($coin === 'BTC' || $coin === 'LTC') {
                $base = $coin === 'BTC'
                    ? rtrim(Settings::get('btc_rpc_or_api_url', 'https://mempool.space/api'), '/')
                    : rtrim(Settings::get('ltc_api_url', 'https://litecoinspace.org/api'), '/');
                $info = $this->httpGetJson("{$base}/address/" . rawurlencode($q), 5);
                if (!is_array($info) && $coin === 'BTC') {
                    $info = $this->httpGetJson('https://blockstream.info/api/address/' . rawurlencode($q), 5);
                }
                if (is_array($info) && isset($info['chain_stats'])) {
                    $funded = (int) ($info['chain_stats']['funded_txo_sum'] ?? 0) + (int) ($info['mempool_stats']['funded_txo_sum'] ?? 0);
                    $spent  = (int) ($info['chain_stats']['spent_txo_sum'] ?? 0) + (int) ($info['mempool_stats']['spent_txo_sum'] ?? 0);
                    $balance = number_format(max(0, $funded - $spent) / 100000000, 8, '.', '');
                    $txCount = (int) ($info['chain_stats']['tx_count'] ?? 0) + (int) ($info['mempool_stats']['tx_count'] ?? 0);
                }
            } elseif ($coin === 'BCH') {
                $clean = preg_replace('/^bitcoincash:/i', '', $q) ?? $q;
                $info = $this->httpGetJson('https://api.blockchair.com/bitcoin-cash/dashboards/address/' . rawurlencode($clean), 5);
                $addrData = $info['data'][$clean]['address'] ?? null;
                if (is_array($addrData)) {
                    $balance = number_format(((float) ($addrData['balance'] ?? 0)) / 100000000, 8, '.', '');
                    $txCount = (int) ($addrData['transaction_count'] ?? 0);
                }
            } elseif ($coin === 'ETH') {
                $rpcUrl = Settings::get('eth_rpc_url', 'https://cloudflare-eth.com');
                $hexBal = $this->jsonRpc($rpcUrl, 'eth_getBalance', [$q, 'latest']);
                $hexCnt = $this->jsonRpc($rpcUrl, 'eth_getTransactionCount', [$q, 'latest']);
                if (is_string($hexBal) && str_starts_with($hexBal, '0x')) {
                    $balance = number_format(self::hexWeiToFloat($hexBal, 18), 8, '.', '');
                }
                if (is_string($hexCnt) && str_starts_with($hexCnt, '0x')) {
                    $txCount = (int) hexdec(substr($hexCnt, 2));
                }
            } elseif ($coin === 'USDT-TRC20') {
                $tronBase = rtrim(Settings::get('tron_api_url', 'https://api.trongrid.io'), '/');
                $info = $this->httpGetJson("{$tronBase}/v1/accounts/" . rawurlencode($q), 5);
                $acc = $info['data'][0] ?? null;
                if (is_array($acc)) {
                    $usdtRaw = 0.0;
                    foreach (($acc['trc20'] ?? []) as $tokenMap) {
                        if (is_array($tokenMap) && isset($tokenMap[self::USDT_TRC20_CONTRACT])) {
                            $usdtRaw = (float) $tokenMap[self::USDT_TRC20_CONTRACT];
                        }
                    }
                    $balance = number_format($usdtRaw / 1000000, 2, '.', '');
                    $txCount = count($acc['trc20'] ?? []);
                }
            }
        }

        return [
            'ok'           => true,
            'type'         => 'address',
            'coin'         => $coin,
            'address'      => $q,
            'balance'      => $balance,
            'tx_count'     => $txCount,
            'explorer_url' => self::explorerUrl($coin, null, $q),
        ];
    }

    private static function hexWeiToFloat(string $hex, int $decimals): float
    {
        $clean = ltrim(strtolower($hex), '0x');
        if ($clean === '') {
            return 0.0;
        }
        $dec = '0';
        for ($i = 0, $n = strlen($clean); $i < $n; $i++) {
            $dec = bcadd(bcmul($dec, '16'), (string) hexdec($clean[$i]));
        }
        $div = bcpow('10', (string) $decimals);
        return (float) bcdiv($dec, $div, 8);
    }

    /**
     * @return array<string, mixed>|list<mixed>|null
     */
    private function httpGetJson(string $url, int $timeoutSec = 6): array|null
    {
        $raw = $this->httpGetRaw($url, $timeoutSec);
        if ($raw === null) {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function httpGetRaw(string $url, int $timeoutSec = 6): ?string
    {
        if (!function_exists('curl_init')) {
            return null;
        }
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => $timeoutSec,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'BlockPayne-Gateway/' . BP_VERSION,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!is_string($body) || $code < 200 || $code >= 300) {
            return null;
        }
        return $body;
    }

    /**
     * @param list<mixed> $params
     */
    private function jsonRpc(string $url, string $method, array $params): mixed
    {
        if (!function_exists('curl_init')) {
            return null;
        }
        $payload = json_encode([
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => $method,
            'params'  => $params,
        ]);
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 6,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        if (!is_string($resp)) {
            return null;
        }
        $decoded = json_decode($resp, true);
        return is_array($decoded) ? ($decoded['result'] ?? null) : null;
    }
}
