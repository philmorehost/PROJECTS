<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Services\BitcoinHDWalletService;
use App\Services\LicenseService;
use App\Services\PayhubService;
use App\Support\DynamicBrand;
use App\Support\QrCode;
use App\Support\Settings;

$passed = 0;
$failed = 0;

function assert_check(string $name, bool $cond, string $detail = ''): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "PASS [unit] {$name}\n";
    } else {
        $failed++;
        echo "FAIL [unit] {$name}" . ($detail !== '' ? " :: {$detail}" : '') . "\n";
    }
}

$hd = new BitcoinHDWalletService();

// 1. Official BIP84 Test Vector (zpub -> bc1q...)
$bip84Zpub = 'zpub6rFR7y4Q2AijBEqTUquhVz398htDFrtymD9xYYfG1m4wAcvPhXNfE3EfH1r1ADqtfSdVCToUG868RvUUkgDKf31mGDtKsAYz2oz2AGutZYs';
$addr0 = $hd->deriveAddress($bip84Zpub, 0, 'BTC');
$addr1 = $hd->deriveAddress($bip84Zpub, 1, 'BTC');

assert_check(
    'BIP84 zpub external address m/0/0 matches official Bitcoin vector',
    $addr0 === 'bc1qcr8te4kr609gcawutmrza0j4xv80jy8z306fyu',
    "got {$addr0}"
);
assert_check(
    'BIP84 zpub external address m/0/1 matches official Bitcoin vector',
    $addr1 === 'bc1qnjg0jd8228aq7egyzacy8cys3knf9xvrerkf9g',
    "got {$addr1}"
);

// 2. Multi-coin non-custodial derivation (LTC, BCH, ETH, USDT-TRC20)
$ltcAddr = $hd->deriveAddress($bip84Zpub, 0, 'LTC');
$bchAddr = $hd->deriveAddress($bip84Zpub, 0, 'BCH');
$ethAddr = $hd->deriveAddress($bip84Zpub, 0, 'ETH');
$trcAddr = $hd->deriveAddress($bip84Zpub, 0, 'USDT-TRC20');

assert_check('LTC Bech32 derived address valid', str_starts_with($ltcAddr, 'ltc1q') && BitcoinHDWalletService::isValidAddress('LTC', $ltcAddr), $ltcAddr);
assert_check('BCH CashAddr derived address valid', str_starts_with($bchAddr, 'bitcoincash:q') && BitcoinHDWalletService::isValidAddress('BCH', $bchAddr), $bchAddr);
assert_check('ETH EIP-55 derived address valid', preg_match('/^0x[0-9a-fA-F]{40}$/', $ethAddr) === 1 && $ethAddr !== strtolower($ethAddr), $ethAddr);
assert_check('USDT-TRC20 Base58Check Tron derived address valid', str_starts_with($trcAddr, 'T') && strlen($trcAddr) === 34 && count_chars($trcAddr, 3) !== '', $trcAddr);

// 3. Keccak-256 standard test vectors
$kEmpty = bin2hex(BitcoinHDWalletService::keccak256(''));
$kAbc   = bin2hex(BitcoinHDWalletService::keccak256('abc'));
assert_check('Keccak-256("") matches canonical Ethereum hash', $kEmpty === 'c5d2460186f7233c927e7db2dcc703c0e500b653ca82273b7bfad8045d85a470', $kEmpty);
assert_check('Keccak-256("abc") matches canonical Ethereum hash', $kAbc === '4e03657aea45a94fc7d47ba826c8d667c0d1e6e33a64a036ec44f58fa12d6c45', $kAbc);

// 4. Rejects xprv private keys
$rejectedXprv = !BitcoinHDWalletService::isExtendedPubKey('xprv9s21ZrQH143K3QTDL4LXw2F7HEK3wJUD2nW2nRk4stbPy6cq3jPPqjiChkVvvNKmPGJxWUtg6LnF5kejMRNNU3TGtRBeJgk33yuGBxrMPHi');
assert_check('Extended key validator strictly rejects private xprv keys', $rejectedXprv);

// 5. Dynamic First-Letter SVG Logo & Favicon
assert_check('DynamicBrand extracts first letter accurately', DynamicBrand::firstLetter('PayneX Gateway') === 'P');
$svg = DynamicBrand::faviconSvg('BlockPayne', '#10b981');
assert_check('DynamicBrand renders valid SVG with first letter', str_contains($svg, '<svg') && str_contains($svg, '>B</text>'));

// 6. Self-contained ISO QR Code SVG generator
$qr = QrCode::svg('bitcoin:bc1qcr8te4kr609gcawutmrza0j4xv80jy8z306fyu?amount=0.0025');
assert_check('QrCode::svg generates crisp path-based SVG', str_contains($qr, '<svg') && str_contains($qr, '<path d="M'));

// 7. Payhub HMAC-SHA256 Webhook verification
$payhub = new PayhubService();
$sampleBody = '{"event":"charge.success","data":{"reference":"PH_123","status":"success"}}';
$secret = 'sk_live_testsecret999';
$validSig = hash_hmac('sha256', $sampleBody, $secret);
assert_check('Payhub webhook accepts valid HMAC-SHA256 signature', $payhub->verifyWebhookSignature($sampleBody, $validSig, $secret));
assert_check('Payhub webhook rejects tampered body', !$payhub->verifyWebhookSignature($sampleBody . ' ', $validSig, $secret));

// 8. License Manager contract & domain normalization
assert_check('LicenseService normalizes https://www.pay.example.com:8443/', LicenseService::normalizeDomain('https://www.pay.example.com:8443/') === 'pay.example.com');
assert_check('LicenseService accepts numeric status 1', LicenseService::interpretResponse('{"status":1,"message":"License is valid."}', 'example.com')['valid'] === true);
assert_check('LicenseService rejects status 0', LicenseService::interpretResponse('{"status":0,"message":"Invalid"}', 'example.com')['valid'] === false);
assert_check('LicenseService rejects suspended status', LicenseService::interpretResponse('{"status":"suspended"}', 'example.com')['valid'] === false);
assert_check('LicenseService fails closed on malformed JSON', LicenseService::interpretResponse('not-json', 'example.com')['valid'] === false);

echo sprintf("\nUnit Summary: %d passed, %d failed\n", $passed, $failed);
if ($failed > 0) {
    exit(1);
}
