<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Settings;

/**
 * Live Payhub Fiat Gateway Client (for Merchant Prefund Credit Top-Ups).
 *
 * Official Payhub API contract:
 * - POST {base}/api/transaction/initialize (Authorization: Bearer sk_live_..., amount in kobo)
 * - GET  {base}/api/transaction/verify/{reference}
 * - Webhook X-Payhub-Signature = HMAC-SHA256(raw JSON body, secret_key)
 */
final class PayhubService
{
    public function isConfigured(): bool
    {
        return Settings::bool('payhub_enabled')
            && trim(Settings::get('payhub_secret_key', '')) !== '';
    }

    public function baseUrl(): string
    {
        $url = trim(Settings::get('payhub_base_url', 'https://merchant.payhub.com.ng'));
        return rtrim($url !== '' ? $url : 'https://merchant.payhub.com.ng', '/');
    }

    /**
     * Initialize a live Payhub transaction for NGN kobo equivalent of a USD prefund top-up.
     *
     * @return array{ok:bool,authorization_url?:string,reference?:string,amount_ngn?:float,amount_kobo?:int,public_key?:string,inline_script?:string,error?:string}
     */
    public function initializeTopUp(string $email, string $name, float $usdAmount, string $reference): array
    {
        $ngnRate = max(1.0, Settings::float('usd_ngn_rate', 1600.0));
        $amountNgn = round($usdAmount * $ngnRate, 2);
        $amountKobo = (int) round($amountNgn * 100);

        $secretKey = trim(Settings::get('payhub_secret_key', ''));
        $publicKey = trim(Settings::get('payhub_public_key', ''));
        $base = $this->baseUrl();

        if ($secretKey === '') {
            return [
                'ok'    => false,
                'error' => 'Payhub live API credentials have not been configured yet. Configure Payhub Secret Key in Admin -> Gateway Setup or top up with Crypto.',
            ];
        }

        if (!function_exists('curl_init')) {
            return ['ok' => false, 'error' => 'Server PHP cURL extension is required for live Payhub API requests.'];
        }

        $ch = curl_init($base . '/api/transaction/initialize');
        if ($ch === false) {
            return ['ok' => false, 'error' => 'Unable to initialize HTTP client for Payhub.'];
        }

        $postFields = http_build_query([
            'email'        => $email,
            'name'         => $name,
            'amount'       => $amountKobo,
            'reference'    => $reference,
            'callback_url' => bp_abs_url('dashboard?tab=prefund&payhub_ref=' . rawurlencode($reference)),
        ]);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $postFields,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $secretKey,
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $raw = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!is_string($raw) || $httpCode < 200 || $httpCode >= 300) {
            return ['ok' => false, 'error' => 'Payhub gateway rejected initialization (HTTP ' . $httpCode . '). Check your live secret key.'];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || empty($decoded['status']) || empty($decoded['data']['authorization_url'])) {
            return ['ok' => false, 'error' => (string) ($decoded['message'] ?? 'Unexpected response from Payhub.')];
        }

        return [
            'ok'                => true,
            'authorization_url' => (string) $decoded['data']['authorization_url'],
            'reference'         => (string) ($decoded['data']['reference'] ?? $reference),
            'amount_ngn'        => $amountNgn,
            'amount_kobo'       => $amountKobo,
            'public_key'        => $publicKey,
            'inline_script'     => $base . '/inline.js',
        ];
    }

    /**
     * Authoritatively verify a live Payhub transaction reference (`paid === true` or `data.status === 'success'`).
     *
     * @return array{ok:bool,paid:bool,amount_kobo:int,currency:string,message:string}
     */
    public function verifyTransaction(string $reference): array
    {
        $secretKey = trim(Settings::get('payhub_secret_key', ''));
        if ($secretKey === '' || !function_exists('curl_init')) {
            return ['ok' => false, 'paid' => false, 'amount_kobo' => 0, 'currency' => 'NGN', 'message' => 'Payhub live secret key is not configured.'];
        }

        $url = $this->baseUrl() . '/api/transaction/verify/' . rawurlencode($reference);
        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'paid' => false, 'amount_kobo' => 0, 'currency' => 'NGN', 'message' => 'cURL initialization failed.'];
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $secretKey,
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);

        if (!is_string($raw)) {
            return ['ok' => false, 'paid' => false, 'amount_kobo' => 0, 'currency' => 'NGN', 'message' => 'Empty response from Payhub.'];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || empty($decoded['status'])) {
            return ['ok' => false, 'paid' => false, 'amount_kobo' => 0, 'currency' => 'NGN', 'message' => (string) ($decoded['message'] ?? 'Verification failed.')];
        }

        $data = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
        $paid = ($decoded['paid'] ?? false) === true
            || ($data['paid'] ?? false) === true
            || strtolower((string) ($data['status'] ?? '')) === 'success';

        return [
            'ok'          => true,
            'paid'        => $paid,
            'amount_kobo' => (int) ($data['amount'] ?? 0),
            'currency'    => (string) ($data['currency'] ?? 'NGN'),
            'message'     => (string) ($decoded['message'] ?? 'Verified'),
        ];
    }

    /**
     * Verify HMAC-SHA256 webhook signature from `X-Payhub-Signature`.
     */
    public function verifyWebhookSignature(string $rawBody, string $signatureHeader, ?string $explicitSecret = null): bool
    {
        $secret = trim($explicitSecret ?? Settings::get('payhub_secret_key', ''));
        $sig = trim($signatureHeader);
        if ($secret === '' || $sig === '') {
            return false;
        }
        $expected = hash_hmac('sha256', $rawBody, $secret);
        return hash_equals($expected, $sig);
    }
}
