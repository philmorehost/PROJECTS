<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\BitcoinHDWalletService;
use App\Services\BlockchainNodeService;
use App\Services\PayhubService;
use App\Support\Database;
use App\Support\Settings;

/**
 * Merchant Prefund Credit Accounting Controller:
 * 1. Payhub Fiat Top-Up (Initialize + Verify + Signed X-Payhub-Signature Webhook)
 * 2. Direct Crypto Address / BlockBee Top-Up Invoice
 * 3. Admin Manual Credit Adjustment
 */
final class PrefundController
{
    /**
     * Initialize a Payhub fiat payment to fund merchant's USD credit balance.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function initializePayhub(array $user, array $input): array
    {
        $usdAmount = round((float) ($input['usd_amount'] ?? 0), 2);
        if ($usdAmount < 1.0 || $usdAmount > 50000.0) {
            return ['ok' => false, 'error' => 'Enter a top-up amount between $1.00 and $50,000.00.'];
        }

        $payhub = new PayhubService();
        if (!$payhub->isConfigured()) {
            return [
                'ok'    => false,
                'error' => 'Payhub live API credentials have not been configured yet. Configure Payhub Secret Key in Admin -> Gateway Setup or top up with Crypto.',
            ];
        }

        $ref = 'PH_BP_' . strtoupper(bin2hex(random_bytes(6)));
        $pdo = Database::connection();

        $ins = $pdo->prepare(
            'INSERT INTO transactions (user_id, type, amount, currency, tx_hash, gateway, description, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $ins->execute([
            (int) $user['id'],
            'prefund_deposit',
            number_format($usdAmount, 8, '.', ''),
            'USD',
            $ref,
            'payhub',
            'Payhub Prefund Credit Top-Up ($' . number_format($usdAmount, 2) . ')',
            'pending',
            bp_now(),
        ]);

        $res = $payhub->initializeTopUp((string) $user['email'], (string) $user['name'], $usdAmount, $ref);
        if (!$res['ok']) {
            $pdo->prepare("UPDATE transactions SET status = 'failed' WHERE tx_hash = ? AND gateway = 'payhub'")->execute([$ref]);
            return $res;
        }

        return array_merge($res, [
            'usd_amount' => number_format($usdAmount, 2, '.', ''),
        ]);
    }

    /**
     * Confirm a pending Payhub transaction (strictly via signed X-Payhub-Signature webhook or live server-to-server verify).
     *
     * @return array<string, mixed>
     */
    public function confirmPayhubReference(string $reference, bool $fromSignedWebhook = false): array
    {
        $ref = trim($reference);
        if ($ref === '') {
            return ['ok' => false, 'error' => 'Missing Payhub transaction reference.'];
        }

        if (!$fromSignedWebhook) {
            $payhub = new PayhubService();
            $verify = $payhub->verifyTransaction($ref);
            if (!$verify['ok'] || !$verify['paid']) {
                return ['ok' => false, 'error' => 'Payhub has not confirmed payment for reference ' . $ref . ' yet.'];
            }
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT * FROM transactions WHERE tx_hash = ? AND gateway = 'payhub' FOR UPDATE");
            $stmt->execute([$ref]);
            $tx = $stmt->fetch();
            if (!is_array($tx)) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'Prefund transaction reference not found.'];
            }

            if ($tx['status'] === 'confirmed') {
                $pdo->commit();
                return ['ok' => true, 'already_confirmed' => true, 'message' => 'Prefund credit already applied.'];
            }

            $userId = (int) $tx['user_id'];
            $amount = (float) $tx['amount'];

            $updTx = $pdo->prepare("UPDATE transactions SET status = 'confirmed' WHERE id = ?");
            $updTx->execute([(int) $tx['id']]);

            $this->creditBalanceInternal($pdo, $userId, $amount);
            $pdo->commit();

            return [
                'ok'      => true,
                'message' => 'Credited $' . number_format($amount, 2) . ' to your Prefund Balance via Payhub!',
            ];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'error' => 'Ledger update error: ' . $e->getMessage()];
        }
    }

    /**
     * Process signed Payhub webhook (`POST /api/webhooks/payhub` with `X-Payhub-Signature`).
     *
     * @return array{ok:bool,status_code:int,message:string}
     */
    public function handlePayhubWebhook(string $rawBody, string $signatureHeader): array
    {
        $payhub = new PayhubService();
        if (!$payhub->verifyWebhookSignature($rawBody, $signatureHeader)) {
            return ['ok' => false, 'status_code' => 401, 'message' => 'Invalid X-Payhub-Signature'];
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            return ['ok' => false, 'status_code' => 400, 'message' => 'Malformed JSON payload'];
        }

        $event = strtolower((string) ($payload['event'] ?? ''));
        $data  = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $ref   = trim((string) ($data['reference'] ?? ''));
        $status = strtolower((string) ($data['status'] ?? ''));

        if ($event === 'charge.success' && $status === 'success' && $ref !== '') {
            $res = $this->confirmPayhubReference($ref, true);
            return [
                'ok'          => (bool) $res['ok'],
                'status_code' => $res['ok'] ? 200 : 404,
                'message'     => (string) ($res['message'] ?? $res['error'] ?? 'Processed'),
            ];
        }

        return ['ok' => true, 'status_code' => 200, 'message' => 'Event ignored'];
    }

    /**
     * Create a Crypto Prefund Deposit Invoice using Admin's configured crypto addresses (or BlockBee forwarding).
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createCryptoTopUp(array $user, array $input): array
    {
        $usdAmount = round((float) ($input['usd_amount'] ?? 0), 2);
        $coin = strtoupper(trim((string) ($input['coin_symbol'] ?? 'USDT-TRC20')));

        if ($usdAmount < 2.0 || $usdAmount > 50000.0) {
            return ['ok' => false, 'error' => 'Enter a crypto top-up amount between $2.00 and $50,000.00.'];
        }

        $adminAddressMap = [
            'BTC'        => trim(Settings::get('admin_btc_address', '')),
            'LTC'        => trim(Settings::get('admin_ltc_address', '')),
            'BCH'        => trim(Settings::get('admin_bch_address', '')),
            'USDT-TRC20' => trim(Settings::get('admin_usdt_trc20_address', '')),
            'ETH'        => trim(Settings::get('admin_eth_address', '')),
            'USDT-ERC20' => trim(Settings::get('admin_eth_address', '')),
        ];

        $destAddress = $adminAddressMap[$coin] ?? '';

        // If Admin hasn't entered a custom deposit address yet, derive from Admin's active connected merchant_wallets
        $pdo = Database::connection();
        if ($destAddress === '') {
            $admWalletStmt = $pdo->prepare(
                "SELECT mw.* FROM merchant_wallets mw
                 JOIN users u ON u.id = mw.user_id
                 WHERE u.role = 'admin' AND mw.coin_symbol = ? AND mw.status = 'active'
                 ORDER BY mw.id ASC LIMIT 1"
            );
            $admWalletStmt->execute([$coin]);
            $admWallet = $admWalletStmt->fetch();
            if (is_array($admWallet)) {
                if ($admWallet['derivation_mode'] === 'xpub') {
                    $hd = new BitcoinHDWalletService();
                    $idx = (int) $admWallet['derived_index'];
                    $destAddress = $hd->deriveAddress((string) $admWallet['xpub_key'], $idx, $coin);
                    $pdo->prepare('UPDATE merchant_wallets SET derived_index = ? WHERE id = ?')->execute([$idx + 1, (int) $admWallet['id']]);
                } else {
                    $destAddress = (string) $admWallet['xpub_key'];
                }
            }
        }

        if ($destAddress === '') {
            return ['ok' => false, 'error' => "Administrator has not configured a live {$coin} treasury wallet yet. Choose Payhub or another coin."];
        }

        $node = new BlockchainNodeService();
        $invoiceId = 'TOPUP-' . strtoupper(bin2hex(random_bytes(5)));

        // Optionally wrap in BlockBee unique forwarding address if API key is configured
        if (trim(Settings::get('blockbee_api_key', '')) !== '') {
            $cb = bp_abs_url('api/webhooks/blockbee?invoice_id=' . rawurlencode($invoiceId));
            $bb = $node->createBlockBeeForwardingAddress($coin, $destAddress, $cb);
            if ($bb['ok'] && !empty($bb['address'])) {
                $destAddress = $bb['address'];
            }
        }

        $cryptoAmount = $node->fiatToCrypto($usdAmount, 'USD', $coin);
        $expiresAt = bp_now(3600);

        $ins = $pdo->prepare(
            'INSERT INTO invoices (
                user_id, wallet_id, invoice_id, order_id, title,
                crypto_amount, received_amount, fiat_amount, fiat_currency, currency,
                deposit_address, derivation_index, is_prefund, fee_deducted,
                status, confirmations, expires_at, created_at
            ) VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, 0, ?, ?)'
        );
        $ins->execute([
            (int) $user['id'],
            $invoiceId,
            'PREFUND-' . $user['id'],
            'Merchant Prefund Credit Top-Up',
            $cryptoAmount,
            '0.00000000',
            number_format($usdAmount, 2, '.', ''),
            'USD',
            $coin,
            $destAddress,
            Database::boolVal(true, $pdo),
            Database::boolVal(false, $pdo),
            'pending',
            $expiresAt,
            bp_now(),
        ]);

        return [
            'ok'            => true,
            'invoice_id'    => $invoiceId,
            'checkout_path' => bp_url('pay/' . $invoiceId),
            'message'       => 'Prefund crypto invoice ready.',
        ];
    }

    /**
     * Admin manual prefund credit adjustment.
     *
     * @param array<string, mixed> $admin
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function adminAdjustBalance(array $admin, array $input): array
    {
        if (($admin['role'] ?? '') !== 'admin') {
            return ['ok' => false, 'error' => 'Administrator privileges required.'];
        }

        $targetUserId = (int) ($input['user_id'] ?? 0);
        $usdDelta = round((float) ($input['amount'] ?? 0), 2);
        $note = trim((string) ($input['note'] ?? 'Admin credit adjustment'));

        if ($targetUserId <= 0 || $usdDelta == 0.0) {
            return ['ok' => false, 'error' => 'Select a merchant and enter a non-zero USD amount.'];
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $this->creditBalanceInternal($pdo, $targetUserId, $usdDelta);

            $type = $usdDelta >= 0 ? 'prefund_deposit' : 'fee_debit';
            $ins = $pdo->prepare(
                'INSERT INTO transactions (user_id, type, amount, currency, tx_hash, gateway, description, status, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $ins->execute([
                $targetUserId,
                $type,
                number_format(abs($usdDelta), 8, '.', ''),
                'USD',
                'ADM-' . strtoupper(bin2hex(random_bytes(4))),
                'admin_credit',
                $note,
                'confirmed',
                bp_now(),
            ]);

            $pdo->commit();
            return ['ok' => true, 'message' => 'Merchant prefund balance updated by $' . number_format($usdDelta, 2) . '.'];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function creditBalanceInternal(\PDO $pdo, int $userId, float $usdDelta): void
    {
        $chk = $pdo->prepare("SELECT id FROM merchant_balances WHERE user_id = ? AND currency = 'USD' LIMIT 1");
        $chk->execute([$userId]);
        if ($chk->fetchColumn()) {
            $upd = $pdo->prepare("UPDATE merchant_balances SET balance = balance + ?, updated_at = ? WHERE user_id = ? AND currency = 'USD'");
            $upd->execute([number_format($usdDelta, 8, '.', ''), bp_now(), $userId]);
        } else {
            $ins = $pdo->prepare("INSERT INTO merchant_balances (user_id, currency, balance, updated_at) VALUES (?, 'USD', ?, ?)");
            $ins->execute([$userId, number_format($usdDelta, 8, '.', ''), bp_now()]);
        }
    }
}
