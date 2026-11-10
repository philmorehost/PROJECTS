<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\BitcoinHDWalletService;
use App\Services\BlockchainNodeService;
use App\Services\NotificationService;
use App\Support\Database;
use App\Support\Settings;

/**
 * Non-Custodial Crypto Gateway Controller:
 * - Merchant xPub / Forwarding Wallets
 * - Deterministic Child Address Derivation
 * - Invoices, Payment Links, Polling, BlockBee Webhooks & Merchant REST API v1
 */
final class GatewayController
{
    private BitcoinHDWalletService $hd;
    private BlockchainNodeService $node;

    public function __construct()
    {
        $this->hd = new BitcoinHDWalletService();
        $this->node = new BlockchainNodeService();
    }

    /**
     * Add a non-custodial wallet (either BIP32/84 xPub/yPub/zPub/Ltub OR direct address / BlockBee).
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input
     * @return array{ok:bool,wallet?:array<string,mixed>,sample_address?:string,message?:string,error?:string}
     */
    public function addWallet(array $user, array $input): array
    {
        $userId = (int) $user['id'];
        $coin = strtoupper(trim((string) ($input['coin_symbol'] ?? 'BTC')));
        $label = trim((string) ($input['wallet_label'] ?? ($coin . ' Wallet')));
        $keyOrAddr = trim((string) ($input['xpub_key'] ?? ''));
        $requestedMode = strtolower(trim((string) ($input['derivation_mode'] ?? 'auto')));

        $supported = BitcoinHDWalletService::supportedCoins();
        if (!isset($supported[$coin])) {
            return ['ok' => false, 'error' => 'Unsupported cryptocurrency selected.'];
        }
        if ($keyOrAddr === '') {
            return ['ok' => false, 'error' => 'Paste your wallet xPub/zPub key or destination crypto address.'];
        }

        // Security check: never allow merchants to accidentally paste an xprv private key!
        if (preg_match('/^(xprv|yprv|zprv|tprv|uprv|vprv|Ltpv)/', $keyOrAddr)) {
            return ['ok' => false, 'error' => 'Never paste a private key (xprv)! BLOCKPAYNE is 100% non-custodial — use your public xpub/ypub/zpub or receiving address only.'];
        }

        $mode = 'xpub';
        $sampleAddress = '';

        if (BitcoinHDWalletService::isExtendedPubKey($keyOrAddr)) {
            $mode = 'xpub';
            try {
                $sampleAddress = $this->hd->deriveAddress($keyOrAddr, 0, $coin);
            } catch (\Throwable $e) {
                return ['ok' => false, 'error' => 'Could not derive child address from xPub: ' . $e->getMessage()];
            }
        } elseif (BitcoinHDWalletService::isValidAddress($coin, $keyOrAddr)) {
            $mode = ($requestedMode === 'blockbee' || Settings::get('blockchain_provider', 'auto') === 'blockbee')
                ? 'blockbee'
                : 'address';
            $sampleAddress = $keyOrAddr;
        } else {
            return [
                'ok'    => false,
                'error' => "Invalid {$coin} xPub/zPub or wallet address format. Check your wallet export and try again.",
            ];
        }

        $pdo = Database::connection();
        $ins = $pdo->prepare(
            'INSERT INTO merchant_wallets (user_id, coin_symbol, wallet_label, derivation_mode, xpub_key, derived_index, status, created_at)
             VALUES (?, ?, ?, ?, ?, 0, ?, ?)'
        );
        $ins->execute([$userId, $coin, $label ?: ($coin . ' Wallet'), $mode, $keyOrAddr, 'active', bp_now()]);
        $walletId = (int) $pdo->lastInsertId();

        return [
            'ok'             => true,
            'message'        => "{$coin} wallet connected ({$mode} mode).",
            'sample_address' => $sampleAddress,
            'wallet'         => [
                'id'              => $walletId,
                'coin_symbol'     => $coin,
                'wallet_label'    => $label,
                'derivation_mode' => $mode,
                'derived_index'   => 0,
                'status'          => 'active',
            ],
        ];
    }

    /**
     * Preview the first 3 derived addresses for an xPub so merchants can match against their wallet app.
     *
     * @return array<string, mixed>
     */
    public function previewDerivation(string $xpub, string $coin = 'BTC'): array
    {
        $coin = strtoupper(trim($coin));
        $key = trim($xpub);
        if (!BitcoinHDWalletService::isExtendedPubKey($key)) {
            return ['ok' => false, 'error' => 'Enter a valid xpub, ypub, zpub, or Ltub extended public key.'];
        }
        try {
            $list = [];
            for ($i = 0; $i < 3; $i++) {
                $list[] = [
                    'index'   => $i,
                    'path'    => "m/0/{$i}",
                    'address' => $this->hd->deriveAddress($key, $i, $coin),
                ];
            }
            return ['ok' => true, 'coin' => $coin, 'addresses' => $list];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function deleteWallet(array $user, int $walletId): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('DELETE FROM merchant_wallets WHERE id = ? AND user_id = ?');
        $stmt->execute([$walletId, (int) $user['id']]);
        return ['ok' => true, 'message' => 'Wallet removed.'];
    }

    public function toggleWallet(array $user, int $walletId): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT status FROM merchant_wallets WHERE id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$walletId, (int) $user['id']]);
        $curr = $stmt->fetchColumn();
        if (!$curr) {
            return ['ok' => false, 'error' => 'Wallet not found.'];
        }
        $next = ($curr === 'active') ? 'inactive' : 'active';
        $upd = $pdo->prepare('UPDATE merchant_wallets SET status = ? WHERE id = ? AND user_id = ?');
        $upd->execute([$next, $walletId, (int) $user['id']]);
        return ['ok' => true, 'status' => $next, 'message' => 'Wallet marked ' . $next . '.'];
    }

    /**
     * Create a new non-custodial crypto invoice for a merchant.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createInvoice(int $merchantId, array $input, bool $allowNetworkRates = true): array
    {
        $fiatAmount = round((float) ($input['fiat_amount'] ?? $input['amount'] ?? 0), 2);
        $fiatCurrency = strtoupper(trim((string) ($input['fiat_currency'] ?? 'USD')));
        $coin = strtoupper(trim((string) ($input['currency'] ?? $input['coin_symbol'] ?? 'AUTO')));
        if ($coin === '') {
            $coin = 'AUTO';
        }
        $orderId = trim((string) ($input['order_id'] ?? ''));
        $title = trim((string) ($input['title'] ?? ($orderId !== '' ? "Order #{$orderId}" : 'Crypto Payment')));
        $buyerEmail = trim((string) ($input['buyer_email'] ?? ''));
        $callbackUrl = trim((string) ($input['callback_url'] ?? ''));
        $redirectUrl = trim((string) ($input['redirect_url'] ?? ''));
        if ($redirectUrl !== '' && !preg_match('#^https?://#i', $redirectUrl)) {
            $redirectUrl = '';
        }

        $rawMeta = $input['metadata'] ?? null;
        $metadataJson = null;
        $metadataDecoded = null;
        if (is_array($rawMeta)) {
            $metadataJson = json_encode($rawMeta, JSON_UNESCAPED_SLASHES);
            $metadataDecoded = $rawMeta;
        } elseif (is_string($rawMeta) && trim($rawMeta) !== '') {
            $parsedMeta = json_decode($rawMeta, true);
            if (is_array($parsedMeta)) {
                $metadataJson = json_encode($parsedMeta, JSON_UNESCAPED_SLASHES);
                $metadataDecoded = $parsedMeta;
            } else {
                $metadataJson = json_encode(['note' => trim($rawMeta)], JSON_UNESCAPED_SLASHES);
                $metadataDecoded = ['note' => trim($rawMeta)];
            }
        }

        if ($fiatAmount <= 0) {
            return ['ok' => false, 'error' => 'Enter a payment amount greater than 0.'];
        }
        if (!in_array($fiatCurrency, ['USD', 'NGN', 'EUR', 'GBP'], true)) {
            $fiatCurrency = 'USD';
        }

        $pdo = Database::connection();

        // Check merchant prefund balance
        $minBalance = Settings::float('min_prefund_balance_usd', 0.00);
        $balStmt = $pdo->prepare("SELECT balance FROM merchant_balances WHERE user_id = ? AND currency = 'USD' LIMIT 1");
        $balStmt->execute([$merchantId]);
        $currentBalance = (float) ($balStmt->fetchColumn() ?: 0.0);

        $uStmt = $pdo->prepare('SELECT role, callback_url FROM users WHERE id = ? LIMIT 1');
        $uStmt->execute([$merchantId]);
        $merchantRow = $uStmt->fetch() ?: ['role' => 'merchant', 'callback_url' => ''];

        if (($merchantRow['role'] ?? 'merchant') !== 'admin' && $currentBalance <= $minBalance && Settings::float('gateway_fee_percent', 1.0) > 0) {
            return [
                'ok'    => false,
                'error' => 'Insufficient Prefund Credit balance ($' . number_format($currentBalance, 2) . '). Top up your fee credit via Payhub or Crypto first.',
            ];
        }

        // Locate merchant's active wallet (supports "AUTO" coin selection for CMS/plugin checkouts)
        if ($coin === 'AUTO') {
            $autoStmt = $pdo->prepare("SELECT * FROM merchant_wallets WHERE user_id = ? AND status = 'active' ORDER BY id ASC LIMIT 1");
            $autoStmt->execute([$merchantId]);
            $wallet = $autoStmt->fetch();
            if (!is_array($wallet)) {
                return [
                    'ok'    => false,
                    'error' => 'No active crypto wallet connected yet. Add at least one xPub or wallet address in Wallets first.',
                ];
            }
            $coin = strtoupper((string) $wallet['coin_symbol']);
        } else {
            $wStmt = $pdo->prepare("SELECT * FROM merchant_wallets WHERE user_id = ? AND coin_symbol = ? AND status = 'active' ORDER BY id ASC LIMIT 1");
            $wStmt->execute([$merchantId, $coin]);
            $wallet = $wStmt->fetch();
            if (!is_array($wallet)) {
                return [
                    'ok'    => false,
                    'error' => "No active {$coin} wallet connected yet. Add your {$coin} xPub or address in Wallets first.",
                ];
            }
        }

        $cryptoAmount = $this->node->fiatToCrypto($fiatAmount, $fiatCurrency, $coin, $allowNetworkRates);
        $invoiceId = 'INV-' . strtoupper(bin2hex(random_bytes(6)));
        $expiryMin = max(5, Settings::int('invoice_expiry_minutes', 30));
        $expiresAt = bp_now($expiryMin * 60);
        $finalCallback = $callbackUrl !== '' ? $callbackUrl : (string) ($merchantRow['callback_url'] ?? '');

        $pdo->beginTransaction();
        try {
            $lockSql = 'SELECT id, derived_index, derivation_mode, xpub_key FROM merchant_wallets WHERE id = ? FOR UPDATE';
            $lockStmt = $pdo->prepare($lockSql);
            $lockStmt->execute([(int) $wallet['id']]);
            $lockedWallet = $lockStmt->fetch() ?: $wallet;

            $index = (int) ($lockedWallet['derived_index'] ?? 0);
            $mode = (string) ($lockedWallet['derivation_mode'] ?? 'xpub');
            $keyOrAddr = trim((string) $lockedWallet['xpub_key']);

            if ($mode === 'xpub') {
                $depositAddress = $this->hd->deriveAddress($keyOrAddr, $index, $coin);
            } elseif ($mode === 'blockbee') {
                $cbHook = bp_abs_url('api/webhooks/blockbee?invoice_id=' . rawurlencode($invoiceId));
                $bb = $this->node->createBlockBeeForwardingAddress($coin, $keyOrAddr, $cbHook);
                $depositAddress = $bb['ok'] && !empty($bb['address']) ? $bb['address'] : $keyOrAddr;
            } else {
                $depositAddress = $keyOrAddr;
            }

            $updW = $pdo->prepare('UPDATE merchant_wallets SET derived_index = ? WHERE id = ?');
            $updW->execute([$index + 1, (int) $wallet['id']]);

            $ins = $pdo->prepare(
                'INSERT INTO invoices (
                    user_id, wallet_id, invoice_id, order_id, title, buyer_email,
                    crypto_amount, received_amount, fiat_amount, fiat_currency, currency,
                    deposit_address, derivation_index, is_prefund, fee_deducted,
                    status, confirmations, callback_url, redirect_url, metadata, expires_at, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $ins->execute([
                $merchantId,
                (int) $wallet['id'],
                $invoiceId,
                $orderId ?: null,
                $title,
                $buyerEmail ?: null,
                $cryptoAmount,
                '0.00000000',
                number_format($fiatAmount, 2, '.', ''),
                $fiatCurrency,
                $coin,
                $depositAddress,
                $index,
                Database::boolVal(false, $pdo),
                Database::boolVal(false, $pdo),
                'pending',
                0,
                $finalCallback ?: null,
                $redirectUrl ?: null,
                $metadataJson,
                $expiresAt,
                bp_now(),
            ]);

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'error' => 'Failed to create invoice: ' . $e->getMessage()];
        }

        return [
            'ok'              => true,
            'invoice_id'      => $invoiceId,
            'order_id'        => $orderId,
            'title'           => $title,
            'fiat_amount'     => number_format($fiatAmount, 2, '.', ''),
            'fiat_currency'   => $fiatCurrency,
            'crypto_amount'   => $cryptoAmount,
            'currency'        => $coin,
            'available_coins' => $this->getMerchantActiveCoins($merchantId),
            'deposit_address' => $depositAddress,
            'payment_uri'     => BitcoinHDWalletService::buildPaymentUri($coin, $depositAddress, $cryptoAmount),
            'checkout_url'    => bp_abs_url('pay/' . $invoiceId),
            'checkout_path'   => bp_url('pay/' . $invoiceId),
            'redirect_url'    => $redirectUrl,
            'metadata'        => $metadataDecoded,
            'status'          => 'pending',
            'expires_at'      => $expiresAt,
        ];
    }

    /**
     * Return distinct active coin symbols connected by a merchant.
     *
     * @return list<string>
     */
    public function getMerchantActiveCoins(int $merchantId): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare("SELECT coin_symbol FROM merchant_wallets WHERE user_id = ? AND status = 'active' ORDER BY id ASC");
        $stmt->execute([$merchantId]);
        $coins = [];
        foreach ($stmt->fetchAll() as $row) {
            $sym = strtoupper((string) ($row['coin_symbol'] ?? ''));
            if ($sym !== '' && !in_array($sym, $coins, true)) {
                $coins[] = $sym;
            }
        }
        return $coins;
    }

    /**
     * Allow a buyer on `/pay/{invoice_id}` to switch payment cryptocurrency while invoice is still unpaid (`pending`).
     * Ideal for WHMCS, WooCommerce, Shopify, Wix, PrestaShop, Joomla, FOSSBilling, Invoice Ninja & Telegram checkouts.
     *
     * @return array<string, mixed>
     */
    public function switchInvoiceCoin(string $invoiceId, string $newCoin, bool $allowNetworkRates = true): array
    {
        $invoiceId = trim($invoiceId);
        $newCoin = strtoupper(trim($newCoin));
        if ($invoiceId === '' || $newCoin === '') {
            return ['ok' => false, 'error' => 'Missing invoice_id or coin symbol.'];
        }

        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM invoices WHERE invoice_id = ? LIMIT 1');
        $stmt->execute([$invoiceId]);
        $inv = $stmt->fetch();
        if (!is_array($inv)) {
            return ['ok' => false, 'error' => 'Invoice not found.'];
        }
        if (!empty($inv['is_prefund'])) {
            return ['ok' => false, 'error' => 'Prefund invoices are locked to the selected network.'];
        }
        if ((string) $inv['status'] !== 'pending' || ((float) ($inv['received_amount'] ?? 0)) > 0) {
            return ['ok' => false, 'error' => 'Payment is already detected or settled; coin can no longer be changed.'];
        }
        if (!empty($inv['expires_at']) && strtotime((string) $inv['expires_at']) < time()) {
            return ['ok' => false, 'error' => 'This invoice has expired.'];
        }

        if (strtoupper((string) $inv['currency']) === $newCoin) {
            $fmt = $this->formatInvoice($inv);
            $fmt['qr_svg'] = \App\Support\QrCode::svg($fmt['payment_uri'], 212);
            return ['ok' => true, 'invoice' => $fmt];
        }

        $merchantId = (int) $inv['user_id'];
        $wStmt = $pdo->prepare("SELECT * FROM merchant_wallets WHERE user_id = ? AND coin_symbol = ? AND status = 'active' ORDER BY id ASC LIMIT 1");
        $wStmt->execute([$merchantId, $newCoin]);
        $wallet = $wStmt->fetch();
        if (!is_array($wallet)) {
            return ['ok' => false, 'error' => "Merchant does not have an active {$newCoin} wallet."];
        }

        $fiatAmount = (float) $inv['fiat_amount'];
        $fiatCurrency = (string) ($inv['fiat_currency'] ?: 'USD');
        $cryptoAmount = $this->node->fiatToCrypto($fiatAmount, $fiatCurrency, $newCoin, $allowNetworkRates);

        $pdo->beginTransaction();
        try {
            $lockStmt = $pdo->prepare('SELECT id, derived_index, derivation_mode, xpub_key FROM merchant_wallets WHERE id = ? FOR UPDATE');
            $lockStmt->execute([(int) $wallet['id']]);
            $lockedWallet = $lockStmt->fetch() ?: $wallet;

            $index = (int) ($lockedWallet['derived_index'] ?? 0);
            $mode = (string) ($lockedWallet['derivation_mode'] ?? 'xpub');
            $keyOrAddr = trim((string) $lockedWallet['xpub_key']);

            if ($mode === 'xpub') {
                $depositAddress = $this->hd->deriveAddress($keyOrAddr, $index, $newCoin);
            } elseif ($mode === 'blockbee') {
                $cbHook = bp_abs_url('api/webhooks/blockbee?invoice_id=' . rawurlencode($invoiceId));
                $bb = $this->node->createBlockBeeForwardingAddress($newCoin, $keyOrAddr, $cbHook);
                $depositAddress = $bb['ok'] && !empty($bb['address']) ? $bb['address'] : $keyOrAddr;
            } else {
                $depositAddress = $keyOrAddr;
            }

            $pdo->prepare('UPDATE merchant_wallets SET derived_index = ? WHERE id = ?')
                ->execute([$index + 1, (int) $wallet['id']]);

            $updInv = $pdo->prepare(
                "UPDATE invoices
                 SET wallet_id = ?, currency = ?, crypto_amount = ?, deposit_address = ?, derivation_index = ?
                 WHERE id = ? AND status = 'pending'"
            );
            $updInv->execute([
                (int) $wallet['id'],
                $newCoin,
                $cryptoAmount,
                $depositAddress,
                $index,
                (int) $inv['id'],
            ]);

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'error' => 'Could not switch coin: ' . $e->getMessage()];
        }

        $reStmt = $pdo->prepare('SELECT * FROM invoices WHERE id = ? LIMIT 1');
        $reStmt->execute([(int) $inv['id']]);
        $updated = $reStmt->fetch() ?: $inv;
        $fmt = $this->formatInvoice($updated);
        $fmt['qr_svg'] = \App\Support\QrCode::svg($fmt['payment_uri'], 212);

        return ['ok' => true, 'invoice' => $fmt];
    }

    /**
     * List invoices belonging to a merchant (used by REST API v1 `GET /api/v1/invoices`).
     *
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public function listMerchantInvoices(int $merchantId, array $filters = []): array
    {
        $pdo = Database::connection();
        $where = ['user_id = ?'];
        $params = [$merchantId];

        $status = strtolower(trim((string) ($filters['status'] ?? '')));
        if (in_array($status, ['pending', 'detected', 'confirmed', 'expired'], true)) {
            $where[] = 'status = ?';
            $params[] = $status;
        }

        $orderId = trim((string) ($filters['order_id'] ?? ''));
        if ($orderId !== '') {
            $where[] = 'order_id = ?';
            $params[] = $orderId;
        }

        $limit = max(1, min(100, (int) ($filters['limit'] ?? 25)));
        $sql = 'SELECT * FROM invoices WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT ' . $limit;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return array_map([$this, 'formatInvoice'], $stmt->fetchAll());
    }

    /**
     * Fetch invoice and optionally poll live blockchain nodes if still pending/detected.
     *
     * @return array<string, mixed>|null
     */
    public function getAndPollInvoice(string $invoiceId, bool $pollChain = true): ?array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM invoices WHERE invoice_id = ? LIMIT 1');
        $stmt->execute([trim($invoiceId)]);
        $inv = $stmt->fetch();
        if (!is_array($inv)) {
            return null;
        }

        // Expire stale pending invoices
        if ($inv['status'] === 'pending' && !empty($inv['expires_at']) && strtotime((string) $inv['expires_at']) < time()) {
            $exp = $pdo->prepare("UPDATE invoices SET status = 'expired' WHERE id = ? AND status = 'pending'");
            $exp->execute([(int) $inv['id']]);
            $inv['status'] = 'expired';
            return $this->formatInvoice($inv);
        }

        if ($pollChain && in_array($inv['status'], ['pending', 'detected'], true)) {
            $createdTs = strtotime((string) $inv['created_at']) ?: 0;
            $check = $this->node->checkAddressPayment(
                (string) $inv['currency'],
                (string) $inv['deposit_address'],
                (float) $inv['crypto_amount'],
                $createdTs
            );

            if ($check['confirmed']) {
                $this->markInvoiceConfirmed(
                    (string) $inv['invoice_id'],
                    (string) $check['received_amount'],
                    max(1, (int) $check['confirmations']),
                    $check['tx_hash']
                );
                $stmt->execute([trim($invoiceId)]);
                $inv = $stmt->fetch() ?: $inv;
            } elseif ($check['detected'] && $inv['status'] === 'pending') {
                $upd = $pdo->prepare("UPDATE invoices SET status = 'detected', received_amount = ?, tx_hash = ? WHERE id = ?");
                $upd->execute([$check['received_amount'], $check['tx_hash'], (int) $inv['id']]);
                $inv['status'] = 'detected';
                $inv['received_amount'] = $check['received_amount'];
                $inv['tx_hash'] = $check['tx_hash'];
                $this->dispatchMerchantWebhook((string) $inv['invoice_id'], 'invoice.detected');
            }
        }

        return $this->formatInvoice($inv);
    }

    /**
     * Atomically finalize a confirmed invoice:
     * - If `is_prefund`: credits merchant's internal USD prefund balance!
     * - Otherwise: deducts 1% operational gateway fee from merchant's prefund balance & fires webhook!
     */
    public function markInvoiceConfirmed(string $invoiceId, string $receivedCrypto, int $confirmations = 1, ?string $txHash = null): bool
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT * FROM invoices WHERE invoice_id = ? FOR UPDATE');
            $stmt->execute([$invoiceId]);
            $inv = $stmt->fetch();
            if (!is_array($inv)) {
                $pdo->rollBack();
                return false;
            }

            if ($inv['status'] === 'confirmed' && !empty($inv['fee_deducted'])) {
                $pdo->commit();
                return true;
            }

            $userId = (int) $inv['user_id'];
            $usdValue = $this->node->toUsd((float) $inv['fiat_amount'], (string) ($inv['fiat_currency'] ?? 'USD'));
            $finalTxHash = $txHash ?: ((string) ($inv['tx_hash'] ?? '') ?: ('TX-' . strtoupper(bin2hex(random_bytes(8)))));

            $updInv = $pdo->prepare(
                "UPDATE invoices
                 SET status = 'confirmed', received_amount = ?, confirmations = ?, tx_hash = ?, fee_deducted = ?, confirmed_at = ?
                 WHERE id = ?"
            );
            $updInv->execute([
                $receivedCrypto,
                max(1, $confirmations),
                $finalTxHash,
                Database::boolVal(true, $pdo),
                bp_now(),
                (int) $inv['id'],
            ]);

            if (!empty($inv['is_prefund'])) {
                // Credit merchant's internal prefund balance
                $balChk = $pdo->prepare("SELECT id FROM merchant_balances WHERE user_id = ? AND currency = 'USD' LIMIT 1");
                $balChk->execute([$userId]);
                if (!$balChk->fetchColumn()) {
                    $bIns = $pdo->prepare("INSERT INTO merchant_balances (user_id, currency, balance, updated_at) VALUES (?, 'USD', '0.00000000', ?)");
                    $bIns->execute([$userId, bp_now()]);
                }
                $cred = $pdo->prepare("UPDATE merchant_balances SET balance = balance + ?, updated_at = ? WHERE user_id = ? AND currency = 'USD'");
                $cred->execute([number_format($usdValue, 8, '.', ''), bp_now(), $userId]);
                $txIns = $pdo->prepare(
                    'INSERT INTO transactions (user_id, type, amount, currency, tx_hash, gateway, description, status, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $txIns->execute([
                    $userId,
                    'prefund_deposit',
                    number_format($usdValue, 8, '.', ''),
                    'USD',
                    $finalTxHash,
                    'crypto_' . strtolower((string) $inv['currency']),
                    'Crypto prefund deposit (' . $inv['invoice_id'] . ')',
                    'confirmed',
                    bp_now(),
                ]);
            } else {
                // Deduct gateway operational fee (e.g. 1%) from merchant's prefund balance
                $feePct = max(0.0, Settings::float('gateway_fee_percent', 1.00));
                $feeUsd = round($usdValue * ($feePct / 100.0), 8);

                if ($feeUsd > 0) {
                    $balChk = $pdo->prepare("SELECT id FROM merchant_balances WHERE user_id = ? AND currency = 'USD' LIMIT 1");
                    $balChk->execute([$userId]);
                    if (!$balChk->fetchColumn()) {
                        $bIns = $pdo->prepare("INSERT INTO merchant_balances (user_id, currency, balance, updated_at) VALUES (?, 'USD', '0.00000000', ?)");
                        $bIns->execute([$userId, bp_now()]);
                    }

                    $deb = $pdo->prepare("UPDATE merchant_balances SET balance = balance - ?, updated_at = ? WHERE user_id = ? AND currency = 'USD'");
                    $deb->execute([number_format($feeUsd, 8, '.', ''), bp_now(), $userId]);

                    $txIns = $pdo->prepare(
                        'INSERT INTO transactions (user_id, type, amount, currency, tx_hash, gateway, description, status, created_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                    );
                    $txIns->execute([
                        $userId,
                        'fee_debit',
                        number_format($feeUsd, 8, '.', ''),
                        'USD',
                        $inv['invoice_id'],
                        'gateway_fee',
                        number_format($feePct, 2) . '% fee on ' . $inv['invoice_id'],
                        'confirmed',
                        bp_now(),
                    ]);
                }
            }

            $pdo->commit();
        } catch (\Throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return false;
        }

        // Dispatch merchant webhook callback outside DB transaction
        $this->dispatchMerchantWebhook($invoiceId, 'invoice.confirmed');
        return true;
    }

    /**
     * Create a reusable hosted Payment Link / Button (`/link/{slug}`).
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createPaymentLink(array $user, array $input): array
    {
        $title = trim((string) ($input['title'] ?? ''));
        $desc = trim((string) ($input['description'] ?? ''));
        $amount = round((float) ($input['fiat_amount'] ?? 0), 2);
        $currency = strtoupper(trim((string) ($input['fiat_currency'] ?? 'USD')));
        $custom = !empty($input['allow_custom_amount']) || $amount <= 0;
        $coins = trim((string) ($input['accepted_coins'] ?? 'BTC,LTC,BCH,ETH,USDT-TRC20'));

        if ($title === '') {
            return ['ok' => false, 'error' => 'Enter a title for your payment link.'];
        }

        $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $title));
        $slug = trim($slug, '-') . '-' . substr(bin2hex(random_bytes(3)), 0, 5);

        $pdo = Database::connection();
        $ins = $pdo->prepare(
            'INSERT INTO payment_links (user_id, slug, title, description, fiat_amount, fiat_currency, allow_custom_amount, accepted_coins, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $ins->execute([
            (int) $user['id'],
            $slug,
            $title,
            $desc ?: null,
            number_format(max(0, $amount), 2, '.', ''),
            in_array($currency, ['USD', 'NGN', 'EUR', 'GBP'], true) ? $currency : 'USD',
            Database::boolVal($custom, $pdo),
            $coins,
            'active',
            bp_now(),
        ]);

        return [
            'ok'      => true,
            'message' => 'Payment link created.',
            'slug'    => $slug,
            'url'     => bp_abs_url('link/' . $slug),
        ];
    }

    public function deletePaymentLink(array $user, int $linkId): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('DELETE FROM payment_links WHERE id = ? AND user_id = ?');
        $stmt->execute([$linkId, (int) $user['id']]);
        return ['ok' => true, 'message' => 'Payment link deleted.'];
    }

    /**
     * Handle incoming BlockBee / CryptAPI instant payment notification callback.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function handleBlockBeeCallback(array $params): array
    {
        $invoiceId = trim((string) ($params['invoice_id'] ?? ''));
        if ($invoiceId === '') {
            return ['ok' => false, 'error' => 'Missing invoice_id.'];
        }
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM invoices WHERE invoice_id = ? LIMIT 1');
        $stmt->execute([$invoiceId]);
        $inv = $stmt->fetch();
        if (!is_array($inv)) {
            return ['ok' => false, 'error' => 'Invoice not found.'];
        }

        $valCoin = (float) ($params['value_coin'] ?? $inv['crypto_amount']);
        $pending = (int) ($params['pending'] ?? 0);
        $confs   = (int) ($params['confirmations'] ?? ($pending === 0 ? 1 : 0));
        $txidIn  = trim((string) ($params['txid_in'] ?? ''));

        if ($valCoin + 0.0000005 < ((float) $inv['crypto_amount']) * 0.99) {
            return ['ok' => false, 'error' => 'Underpaid amount received; awaiting remainder.'];
        }

        if ($pending === 1 && $confs === 0) {
            $upd = $pdo->prepare("UPDATE invoices SET status = 'detected', received_amount = ?, tx_hash = ? WHERE id = ? AND status = 'pending'");
            $upd->execute([number_format($valCoin, 8, '.', ''), $txidIn ?: null, (int) $inv['id']]);
            $this->dispatchMerchantWebhook($invoiceId, 'invoice.detected');
            return ['ok' => true, 'status' => 'detected'];
        }

        $this->markInvoiceConfirmed($invoiceId, number_format($valCoin, 8, '.', ''), max(1, $confs), $txidIn ?: null);
        return ['ok' => true, 'status' => 'confirmed'];
    }

    private function dispatchMerchantWebhook(string $invoiceId, string $eventName = 'invoice.confirmed'): void
    {
        if (!function_exists('curl_init')) {
            return;
        }
        try {
            $pdo = Database::connection();
            $stmt = $pdo->prepare(
                'SELECT i.*, u.webhook_secret, u.callback_url AS default_callback_url
                 FROM invoices i
                 JOIN users u ON u.id = i.user_id
                 WHERE i.invoice_id = ? LIMIT 1'
            );
            $stmt->execute([$invoiceId]);
            $row = $stmt->fetch();
            if (!is_array($row)) {
                return;
            }
            $targetUrl = trim((string) ($row['callback_url'] ?: $row['default_callback_url'] ?: ''));
            if ($targetUrl === '' || !preg_match('#^https?://#i', $targetUrl)) {
                return;
            }

            $decodedMeta = null;
            if (!empty($row['metadata'])) {
                $tmpMeta = json_decode((string) $row['metadata'], true);
                if (is_array($tmpMeta)) {
                    $decodedMeta = $tmpMeta;
                }
            }

            $body = json_encode([
                'event'           => $eventName,
                'invoice_id'      => $row['invoice_id'],
                'order_id'        => $row['order_id'],
                'status'          => $row['status'],
                'fiat_amount'     => $row['fiat_amount'],
                'fiat_currency'   => $row['fiat_currency'],
                'crypto_amount'   => $row['crypto_amount'],
                'received_amount' => $row['received_amount'],
                'currency'        => $row['currency'],
                'deposit_address' => $row['deposit_address'],
                'tx_hash'         => $row['tx_hash'],
                'confirmations'   => (int) $row['confirmations'],
                'confirmed_at'    => $row['confirmed_at'],
                'metadata'        => $decodedMeta,
                'timestamp'       => time(),
            ], JSON_UNESCAPED_SLASHES);

            $sig = hash_hmac('sha256', (string) $body, (string) ($row['webhook_secret'] ?? ''));
            $ch = curl_init($targetUrl);
            if ($ch === false) {
                return;
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'X-BlockPayne-Event: ' . $eventName,
                    'X-BlockPayne-Signature: ' . $sig,
                    'User-Agent: BlockPayne-Webhook/' . BP_VERSION,
                ],
                CURLOPT_TIMEOUT        => 8,
                CURLOPT_CONNECTTIMEOUT => 4,
            ]);
            curl_exec($ch);
            curl_close($ch);
        } catch (\Throwable) {
            // Ignore webhook network errors; cron retries if needed
        }
    }

    /**
     * @param array<string, mixed> $inv
     * @return array<string, mixed>
     */
    public function formatInvoice(array $inv): array
    {
        $expTs = !empty($inv['expires_at']) ? strtotime((string) $inv['expires_at']) : 0;
        $remSec = $expTs > 0 ? max(0, $expTs - time()) : 0;
        $txHash = (string) ($inv['tx_hash'] ?? '');
        $addr = (string) $inv['deposit_address'];
        $coin = (string) $inv['currency'];

        $decodedMeta = null;
        if (!empty($inv['metadata'])) {
            $tmpMeta = json_decode((string) $inv['metadata'], true);
            if (is_array($tmpMeta)) {
                $decodedMeta = $tmpMeta;
            }
        }

        return [
            'id'                => (int) $inv['id'],
            'invoice_id'        => (string) $inv['invoice_id'],
            'order_id'          => (string) ($inv['order_id'] ?? ''),
            'title'             => (string) ($inv['title'] ?? 'Crypto Payment'),
            'fiat_amount'       => (string) $inv['fiat_amount'],
            'fiat_currency'     => (string) ($inv['fiat_currency'] ?? 'USD'),
            'crypto_amount'     => bp_crypto_fmt((string) $inv['crypto_amount']),
            'received_amount'   => bp_crypto_fmt((string) ($inv['received_amount'] ?? '0')),
            'currency'          => $coin,
            'deposit_address'   => $addr,
            'payment_uri'       => BitcoinHDWalletService::buildPaymentUri($coin, $addr, (string) $inv['crypto_amount']),
            'tx_hash'           => $txHash,
            'explorer_url'      => BlockchainNodeService::explorerUrl($coin, $txHash ?: null, $addr),
            'is_prefund'        => !empty($inv['is_prefund']),
            'status'            => (string) $inv['status'],
            'confirmations'     => (int) ($inv['confirmations'] ?? 0),
            'remaining_seconds' => $remSec,
            'expires_at'        => (string) ($inv['expires_at'] ?? ''),
            'confirmed_at'      => (string) ($inv['confirmed_at'] ?? ''),
            'created_at'        => (string) ($inv['created_at'] ?? ''),
            'redirect_url'      => (string) ($inv['redirect_url'] ?? ''),
            'metadata'          => $decodedMeta,
            'checkout_url'      => bp_abs_url('pay/' . $inv['invoice_id']),
            'checkout_path'     => bp_url('pay/' . $inv['invoice_id']),
        ];
    }
}
