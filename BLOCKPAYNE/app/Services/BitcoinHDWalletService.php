<?php
declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;
use RuntimeException;

/**
 * Pure-PHP Non-Custodial HD Wallet Derivation Service (secp256k1 + BIP32/BIP44/BIP49/BIP84).
 *
 * Supports:
 * - BTC: xpub (Legacy 1...), ypub (Nested SegWit 3...), zpub (Native SegWit bc1q...), tpub/upub/vpub (Testnet)
 * - LTC: Ltub / Mtub / xpub / zpub -> ltc1q... or L...
 * - BCH: xpub -> CashAddr (bitcoincash:q...)
 * - ETH & USDT-ERC20: xpub -> Keccak-256 + EIP-55 checksummed 0x... address
 * - USDT-TRC20: xpub -> Keccak-256 + 0x41 Base58Check T... Tron address
 * - Static Watch Address passthrough validation
 */
final class BitcoinHDWalletService
{
    private const P = '115792089237316195423570985008687907853269984665640564039457584007908834671663';
    private const N = '115792089237316195423570985008687907852837564279074904382605163141518161494337';
    private const GX = '55066263022277343669578718895168534326250603453777594175500187360389116729240';
    private const GY = '32670510020758816978083085130507043184471273380659243275938904335757337482424';
    private const P_PLUS_1_DIV_4 = '28948022309329048855892746252171976963317496166410141009864396001977208667916';

    private const BASE58_ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
    private const BECH32_CHARSET  = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';
    private const CASHADDR_CHARSET = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';

    /** @var array<string, array{network:string, script:string}> */
    private const XPUB_VERSIONS = [
        '0488b21e' => ['network' => 'mainnet', 'script' => 'p2pkh'],       // xpub
        '049d7cb2' => ['network' => 'mainnet', 'script' => 'p2sh-p2wpkh'], // ypub
        '04b24746' => ['network' => 'mainnet', 'script' => 'p2wpkh'],      // zpub
        '019da462' => ['network' => 'ltc',     'script' => 'p2pkh'],       // Ltub
        '01b26ef6' => ['network' => 'ltc',     'script' => 'p2wpkh'],      // Mtub
        '043587cf' => ['network' => 'testnet', 'script' => 'p2pkh'],       // tpub
        '044a5262' => ['network' => 'testnet', 'script' => 'p2sh-p2wpkh'], // upub
        '045f1cf6' => ['network' => 'testnet', 'script' => 'p2wpkh'],      // vpub
    ];

    /**
     * Supported coins metadata for UI & validation.
     *
     * @return array<string, array{symbol:string,name:string,network:string,decimals:int,uri_scheme:string}>
     */
    public static function supportedCoins(): array
    {
        return [
            'BTC' => ['symbol' => 'BTC', 'name' => 'Bitcoin', 'network' => 'Bitcoin Mainnet', 'decimals' => 8, 'uri_scheme' => 'bitcoin'],
            'LTC' => ['symbol' => 'LTC', 'name' => 'Litecoin', 'network' => 'Litecoin', 'decimals' => 8, 'uri_scheme' => 'litecoin'],
            'BCH' => ['symbol' => 'BCH', 'name' => 'Bitcoin Cash', 'network' => 'Bitcoin Cash', 'decimals' => 8, 'uri_scheme' => 'bitcoincash'],
            'ETH' => ['symbol' => 'ETH', 'name' => 'Ethereum', 'network' => 'ERC-20 / Ethereum', 'decimals' => 6, 'uri_scheme' => 'ethereum'],
            'USDT-TRC20' => ['symbol' => 'USDT-TRC20', 'name' => 'Tether USDT (TRC-20)', 'network' => 'Tron TRC-20', 'decimals' => 2, 'uri_scheme' => 'tron'],
            'USDT-ERC20' => ['symbol' => 'USDT-ERC20', 'name' => 'Tether USDT (ERC-20)', 'network' => 'Ethereum ERC-20', 'decimals' => 2, 'uri_scheme' => 'ethereum'],
        ];
    }

    /**
     * Check whether a string is a valid BIP32 extended public key (xpub/ypub/zpub/Ltub/tpub/vpub).
     */
    public static function isExtendedPubKey(string $key): bool
    {
        $trimmed = trim($key);
        if (!preg_match('/^(xpub|ypub|zpub|Ltub|Mtub|tpub|upub|vpub)[1-9A-HJ-NP-Za-km-z]{90,120}$/', $trimmed)) {
            return false;
        }
        try {
            self::parseExtendedPubKey($trimmed);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Validate a static destination wallet address for a given coin.
     */
    public static function isValidAddress(string $coin, string $address): bool
    {
        $coin = strtoupper(trim($coin));
        $addr = trim($address);
        if ($addr === '') {
            return false;
        }
        return match ($coin) {
            'BTC' => (bool) preg_match('/^(bc1[qpzry9x8gf2tvdw0s3jn54khce6mua7l]{25,87}|tb1[qpzry9x8gf2tvdw0s3jn54khce6mua7l]{25,87}|[13mn2][a-km-zA-HJ-NP-Z1-9]{25,34})$/', $addr),
            'LTC' => (bool) preg_match('/^(ltc1[qpzry9x8gf2tvdw0s3jn54khce6mua7l]{25,87}|[LM3][a-km-zA-HJ-NP-Z1-9]{25,34})$/', $addr),
            'BCH' => (bool) preg_match('/^((bitcoincash:)?[qp][qpzry9x8gf2tvdw0s3jn54khce6mua7l]{41}|[13][a-km-zA-HJ-NP-Z1-9]{25,34})$/i', $addr),
            'ETH', 'USDT-ERC20' => (bool) preg_match('/^0x[0-9a-fA-F]{40}$/', $addr),
            'USDT-TRC20' => (bool) preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/', $addr),
            default => strlen($addr) >= 24 && strlen($addr) <= 120,
        };
    }

    /**
     * Derive a non-custodial receiving address for path m/0/{index} from an account-level xPub/yPub/zPub.
     */
    public function deriveAddress(string $xpub, int $index, string $coinSymbol = 'BTC'): string
    {
        if ($index < 0 || $index > 2147483647) {
            throw new InvalidArgumentException('Derivation index must be a non-hardened integer >= 0.');
        }

        $parsed = self::parseExtendedPubKey(trim($xpub));
        // Account xPub -> External chain 0 -> Address index $index (m/0/$index)
        $externalChain = $this->ckdPub($parsed['pubKey'], $parsed['chainCode'], 0);
        $leaf = $this->ckdPub($externalChain['pubKey'], $externalChain['chainCode'], $index);

        return $this->encodePublicKeyForCoin($leaf['pubKey'], $leaf['point'], strtoupper($coinSymbol), $parsed['versionHex']);
    }

    /**
     * Derive a direct child m/{index} (useful for single-level xPubs and BIP32 test vectors).
     *
     * @return array{pubKeyHex:string,chainCodeHex:string,address:string}
     */
    public function deriveDirectChild(string $xpub, int $index, string $coinSymbol = 'BTC'): array
    {
        $parsed = self::parseExtendedPubKey(trim($xpub));
        $child = $this->ckdPub($parsed['pubKey'], $parsed['chainCode'], $index);
        $address = $this->encodePublicKeyForCoin($child['pubKey'], $child['point'], strtoupper($coinSymbol), $parsed['versionHex']);

        return [
            'pubKeyHex'    => bin2hex($child['pubKey']),
            'chainCodeHex' => bin2hex($child['chainCode']),
            'address'      => $address,
        ];
    }

    /**
     * Build standard mobile wallet URI (with amount) for QR codes and 1-tap "Open in Wallet".
     */
    public static function buildPaymentUri(string $coin, string $address, string $cryptoAmount): string
    {
        $coin = strtoupper(trim($coin));
        $cleanAmt = rtrim(rtrim(number_format((float) $cryptoAmount, 8, '.', ''), '0'), '.');
        return match ($coin) {
            'BTC' => "bitcoin:{$address}?amount={$cleanAmt}",
            'LTC' => "litecoin:{$address}?amount={$cleanAmt}",
            'BCH' => (str_starts_with(strtolower($address), 'bitcoincash:') ? $address : "bitcoincash:{$address}") . "?amount={$cleanAmt}",
            'ETH' => "ethereum:{$address}",
            default => $address,
        };
    }

    /**
     * Derive a BIP32 extended public key from a master seed hex (used by cryptographic test vectors).
     */
    public function xpubFromMasterSeedHex(string $seedHex, string $versionHex = '04b24746'): string
    {
        $seed = hex2bin($seedHex);
        if ($seed === false || strlen($seed) < 16) {
            throw new InvalidArgumentException('Invalid seed hex.');
        }
        $i = hash_hmac('sha512', $seed, 'Bitcoin seed', true);
        $il = substr($i, 0, 32);
        $ir = substr($i, 32, 32);
        $k = self::binToDec($il);
        $point = $this->scalarMul($k, [self::GX, self::GY]);
        $compPub = self::compressPoint($point);

        $raw = hex2bin($versionHex)
            . "\x00"                 // depth 0
            . "\x00\x00\x00\x00"     // parent fingerprint
            . "\x00\x00\x00\x00"     // child number
            . $ir
            . $compPub;

        return self::base58CheckEncode($raw);
    }

    /**
     * Parse and checksum-verify a Base58Check extended public key.
     *
     * @return array{versionHex:string,network:string,script:string,depth:int,chainCode:string,pubKey:string}
     */
    public static function parseExtendedPubKey(string $xpub): array
    {
        $raw = self::base58CheckDecode($xpub);
        if (strlen($raw) !== 78) {
            throw new InvalidArgumentException('Extended public key must decode to 78 bytes.');
        }
        $versionHex = strtolower(bin2hex(substr($raw, 0, 4)));
        if (!isset(self::XPUB_VERSIONS[$versionHex])) {
            throw new InvalidArgumentException('Unsupported extended public key prefix (rejecting private xprv keys).');
        }
        $depth = ord($raw[4]);
        $chainCode = substr($raw, 13, 32);
        $pubKey = substr($raw, 45, 33);
        $prefixByte = ord($pubKey[0]);
        if ($prefixByte !== 0x02 && $prefixByte !== 0x03) {
            throw new InvalidArgumentException('Invalid compressed secp256k1 public key in xPub.');
        }

        $meta = self::XPUB_VERSIONS[$versionHex];
        return [
            'versionHex' => $versionHex,
            'network'    => $meta['network'],
            'script'     => $meta['script'],
            'depth'      => $depth,
            'chainCode'  => $chainCode,
            'pubKey'     => $pubKey,
        ];
    }

    /**
     * BIP32 Child Key Derivation — Non-hardened Public -> Public (CKDpub).
     *
     * @return array{pubKey:string,chainCode:string,point:array{0:string,1:string}}
     */
    private function ckdPub(string $parentCompressedPub, string $parentChainCode, int $index): array
    {
        if ($index < 0 || $index > 2147483647) {
            throw new InvalidArgumentException('Cannot derive hardened child from public key.');
        }
        $data = $parentCompressedPub . pack('N', $index);
        $i = hash_hmac('sha512', $data, $parentChainCode, true);
        $il = substr($i, 0, 32);
        $ir = substr($i, 32, 32);

        $ilDec = self::binToDec($il);
        if (bccomp($ilDec, self::N) >= 0 || bccomp($ilDec, '0') === 0) {
            throw new RuntimeException('Derived scalar out of curve order; increment index.');
        }

        $parentPoint = $this->decompressPoint($parentCompressedPub);
        $ilPoint = $this->scalarMul($ilDec, [self::GX, self::GY]);
        $childPoint = $this->pointAddAffine($ilPoint, $parentPoint);
        $compressed = self::compressPoint($childPoint);

        return [
            'pubKey'    => $compressed,
            'chainCode' => $ir,
            'point'     => $childPoint,
        ];
    }

    /**
     * Encode derived child public key into the target blockchain's canonical address format.
     *
     * @param array{0:string,1:string} $point
     */
    private function encodePublicKeyForCoin(string $compressedPub, array $point, string $coin, string $versionHex): string
    {
        $meta = self::XPUB_VERSIONS[$versionHex] ?? ['network' => 'mainnet', 'script' => 'p2wpkh'];
        $hash160 = hash('ripemd160', hash('sha256', $compressedPub, true), true);

        if ($coin === 'ETH' || $coin === 'USDT-ERC20') {
            $uncompressed64 = self::decToBin32($point[0]) . self::decToBin32($point[1]);
            $keccak = self::keccak256($uncompressed64);
            $addrHex = substr(bin2hex($keccak), 24); // last 20 bytes (40 hex chars)
            return self::eip55Checksum($addrHex);
        }

        if ($coin === 'USDT-TRC20' || $coin === 'TRX') {
            $uncompressed64 = self::decToBin32($point[0]) . self::decToBin32($point[1]);
            $keccak = self::keccak256($uncompressed64);
            $raw21 = "\x41" . substr($keccak, 12, 20);
            return self::base58CheckEncode($raw21);
        }

        if ($coin === 'BCH') {
            return self::encodeCashAddr('bitcoincash', 0, $hash160);
        }

        if ($coin === 'LTC') {
            if ($meta['script'] === 'p2pkh' && $versionHex === '019da462') {
                return self::base58CheckEncode("\x30" . $hash160); // L...
            }
            return self::encodeBech32('ltc', 0, $hash160); // ltc1q...
        }

        // Default: BTC (mainnet or testnet based on key prefix)
        $isTestnet = ($meta['network'] === 'testnet');
        if ($meta['script'] === 'p2wpkh') {
            return self::encodeBech32($isTestnet ? 'tb' : 'bc', 0, $hash160);
        }
        if ($meta['script'] === 'p2sh-p2wpkh') {
            $redeem = "\x00\x14" . $hash160;
            $scriptHash = hash('ripemd160', hash('sha256', $redeem, true), true);
            return self::base58CheckEncode(($isTestnet ? "\xc4" : "\x05") . $scriptHash);
        }

        // xpub / tpub -> Legacy P2PKH (1... / m... or n...)
        return self::base58CheckEncode(($isTestnet ? "\x6f" : "\x00") . $hash160);
    }

    /* ────────────────────── secp256k1 Elliptic Curve Math (BCMath) ────────────────────── */

    /**
     * Decompress 33-byte secp256k1 compressed public key into affine (x, y).
     *
     * @return array{0:string,1:string}
     */
    private function decompressPoint(string $compressed): array
    {
        $prefix = ord($compressed[0]);
        $x = self::binToDec(substr($compressed, 1, 32));

        // y^2 = x^3 + 7 mod P
        $x2 = self::modMul($x, $x, self::P);
        $x3 = self::modMul($x2, $x, self::P);
        $rhs = self::modAdd($x3, '7', self::P);
        $y = bcpowmod($rhs, self::P_PLUS_1_DIV_4, self::P);

        $isOdd = (bcmod($y, '2') === '1');
        $wantOdd = ($prefix === 0x03);
        if ($isOdd !== $wantOdd) {
            $y = bcsub(self::P, $y);
        }
        return [$x, $y];
    }

    /**
     * @param array{0:string,1:string} $point
     */
    private static function compressPoint(array $point): string
    {
        $prefix = (bcmod($point[1], '2') === '1') ? "\x03" : "\x02";
        return $prefix . self::decToBin32($point[0]);
    }

    /**
     * Fast Jacobian projective scalar multiplication k * P on secp256k1 (a = 0).
     *
     * @param array{0:string,1:string} $affine
     * @return array{0:string,1:string}
     */
    private function scalarMul(string $kDec, array $affine): array
    {
        $bits = self::decToBinBits($kDec);
        /** @var array{0:string,1:string,2:string}|null $r */
        $r = null;
        $base = [$affine[0], $affine[1], '1'];

        $len = strlen($bits);
        for ($i = 0; $i < $len; $i++) {
            if ($r !== null) {
                $r = $this->jacobianDouble($r);
            }
            if ($bits[$i] === '1') {
                $r = ($r === null) ? $base : $this->jacobianAddAffine($r, $affine);
            }
        }
        if ($r === null || $r[2] === '0') {
            throw new RuntimeException('Point at infinity.');
        }
        return $this->jacobianToAffine($r);
    }

    /**
     * @param array{0:string,1:string,2:string} $p
     * @return array{0:string,1:string,2:string}
     */
    private function jacobianDouble(array $p): array
    {
        [$x1, $y1, $z1] = $p;
        if ($y1 === '0' || $z1 === '0') {
            return ['0', '1', '0'];
        }
        $y1sq = self::modMul($y1, $y1, self::P);
        $s = self::modMul('4', self::modMul($x1, $y1sq, self::P), self::P);
        $x1sq = self::modMul($x1, $x1, self::P);
        $m = self::modMul('3', $x1sq, self::P); // a = 0 on secp256k1
        $x3 = self::modSub(self::modMul($m, $m, self::P), self::modMul('2', $s, self::P), self::P);
        $y1_4 = self::modMul($y1sq, $y1sq, self::P);
        $y3 = self::modSub(
            self::modMul($m, self::modSub($s, $x3, self::P), self::P),
            self::modMul('8', $y1_4, self::P),
            self::P
        );
        $z3 = self::modMul('2', self::modMul($y1, $z1, self::P), self::P);
        return [$x3, $y3, $z3];
    }

    /**
     * Mixed Jacobian + Affine addition (Z2 = 1).
     *
     * @param array{0:string,1:string,2:string} $p1
     * @param array{0:string,1:string} $p2
     * @return array{0:string,1:string,2:string}
     */
    private function jacobianAddAffine(array $p1, array $p2): array
    {
        [$x1, $y1, $z1] = $p1;
        [$x2, $y2] = $p2;
        if ($z1 === '0') {
            return [$x2, $y2, '1'];
        }
        $z1sq = self::modMul($z1, $z1, self::P);
        $z1cu = self::modMul($z1sq, $z1, self::P);
        $u2 = self::modMul($x2, $z1sq, self::P);
        $s2 = self::modMul($y2, $z1cu, self::P);

        if ($x1 === $u2) {
            if ($y1 !== $s2) {
                return ['0', '1', '0'];
            }
            return $this->jacobianDouble($p1);
        }

        $h = self::modSub($u2, $x1, self::P);
        $r = self::modSub($s2, $y1, self::P);
        $h2 = self::modMul($h, $h, self::P);
        $h3 = self::modMul($h2, $h, self::P);
        $x1h2 = self::modMul($x1, $h2, self::P);

        $x3 = self::modSub(
            self::modSub(self::modMul($r, $r, self::P), $h3, self::P),
            self::modMul('2', $x1h2, self::P),
            self::P
        );
        $y3 = self::modSub(
            self::modMul($r, self::modSub($x1h2, $x3, self::P), self::P),
            self::modMul($y1, $h3, self::P),
            self::P
        );
        $z3 = self::modMul($z1, $h, self::P);
        return [$x3, $y3, $z3];
    }

    /**
     * @param array{0:string,1:string,2:string} $p
     * @return array{0:string,1:string}
     */
    private function jacobianToAffine(array $p): array
    {
        [$x, $y, $z] = $p;
        $zInv = self::modInv($z, self::P);
        $zInv2 = self::modMul($zInv, $zInv, self::P);
        $zInv3 = self::modMul($zInv2, $zInv, self::P);
        return [
            self::modMul($x, $zInv2, self::P),
            self::modMul($y, $zInv3, self::P),
        ];
    }

    /**
     * @param array{0:string,1:string} $p1
     * @param array{0:string,1:string} $p2
     * @return array{0:string,1:string}
     */
    private function pointAddAffine(array $p1, array $p2): array
    {
        $jac = $this->jacobianAddAffine([$p1[0], $p1[1], '1'], $p2);
        return $this->jacobianToAffine($jac);
    }

    private static function modAdd(string $a, string $b, string $m): string
    {
        $res = bcadd($a, $b);
        return bccomp($res, $m) >= 0 ? bcsub($res, $m) : $res;
    }

    private static function modSub(string $a, string $b, string $m): string
    {
        $res = bcsub($a, $b);
        return bccomp($res, '0') < 0 ? bcadd($res, $m) : $res;
    }

    private static function modMul(string $a, string $b, string $m): string
    {
        return bcmod(bcmul($a, $b), $m);
    }

    private static function modInv(string $a, string $m): string
    {
        $a = bcmod($a, $m);
        if (bccomp($a, '0') < 0) {
            $a = bcadd($a, $m);
        }
        $t = '0';
        $newT = '1';
        $r = $m;
        $newR = $a;

        while ($newR !== '0') {
            $q = bcdiv($r, $newR, 0);
            [$t, $newT] = [$newT, bcsub($t, bcmul($q, $newT))];
            [$r, $newR] = [$newR, bcsub($r, bcmul($q, $newR))];
        }
        if (bccomp($t, '0') < 0) {
            $t = bcadd($t, $m);
        }
        return $t;
    }

    private static function binToDec(string $bin): string
    {
        $dec = '0';
        $len = strlen($bin);
        for ($i = 0; $i < $len; $i++) {
            $dec = bcadd(bcmul($dec, '256'), (string) ord($bin[$i]));
        }
        return $dec;
    }

    private static function decToBin32(string $dec): string
    {
        $bytes = [];
        $n = $dec;
        for ($i = 0; $i < 32; $i++) {
            $rem = (int) bcmod($n, '256');
            $bytes[] = chr($rem);
            $n = bcdiv($n, '256', 0);
        }
        return implode('', array_reverse($bytes));
    }

    private static function decToBinBits(string $dec): string
    {
        if ($dec === '0') {
            return '0';
        }
        $bits = '';
        $n = $dec;
        while ($n !== '0') {
            $bits .= bcmod($n, '2');
            $n = bcdiv($n, '2', 0);
        }
        return strrev($bits);
    }

    /* ────────────────────── Base58Check, Bech32, CashAddr, Keccak-256 ────────────────────── */

    public static function base58CheckEncode(string $payload): string
    {
        $checksum = substr(hash('sha256', hash('sha256', $payload, true), true), 0, 4);
        $data = $payload . $checksum;

        $zeros = 0;
        $len = strlen($data);
        while ($zeros < $len && $data[$zeros] === "\x00") {
            $zeros++;
        }

        $num = self::binToDec($data);
        $encoded = '';
        while (bccomp($num, '0') > 0) {
            $rem = (int) bcmod($num, '58');
            $encoded = self::BASE58_ALPHABET[$rem] . $encoded;
            $num = bcdiv($num, '58', 0);
        }

        return str_repeat('1', $zeros) . $encoded;
    }

    public static function base58CheckDecode(string $str): string
    {
        $zeros = 0;
        $len = strlen($str);
        while ($zeros < $len && $str[$zeros] === '1') {
            $zeros++;
        }

        $num = '0';
        for ($i = 0; $i < $len; $i++) {
            $pos = strpos(self::BASE58_ALPHABET, $str[$i]);
            if ($pos === false) {
                throw new InvalidArgumentException('Invalid Base58 character.');
            }
            $num = bcadd(bcmul($num, '58'), (string) $pos);
        }

        $bytes = [];
        while (bccomp($num, '0') > 0) {
            $bytes[] = chr((int) bcmod($num, '256'));
            $num = bcdiv($num, '256', 0);
        }
        $decoded = str_repeat("\x00", $zeros) . implode('', array_reverse($bytes));
        if (strlen($decoded) < 5) {
            throw new InvalidArgumentException('Base58 payload too short.');
        }

        $body = substr($decoded, 0, -4);
        $check = substr($decoded, -4);
        $expected = substr(hash('sha256', hash('sha256', $body, true), true), 0, 4);
        if (!hash_equals($expected, $check)) {
            throw new InvalidArgumentException('Invalid Base58Check checksum.');
        }
        return $body;
    }

    /**
     * BIP173 Witness v0 Bech32 Encoder (bc1q..., tb1q..., ltc1q...).
     */
    public static function encodeBech32(string $hrp, int $witVer, string $witProg): string
    {
        $hrp = strtolower($hrp);
        $bytes = array_values(unpack('C*', $witProg) ?: []);
        $words = array_merge([$witVer], self::convertBits($bytes, 8, 5, true));
        $checksum = self::bech32CreateChecksum($hrp, $words);
        $combined = array_merge($words, $checksum);

        $out = $hrp . '1';
        foreach ($combined as $w) {
            $out .= self::BECH32_CHARSET[$w];
        }
        return $out;
    }

    /**
     * @param list<int> $values
     */
    private static function bech32Polymod(array $values): int
    {
        $gen = [0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3];
        $chk = 1;
        foreach ($values as $v) {
            $top = $chk >> 25;
            $chk = (($chk & 0x1ffffff) << 5) ^ $v;
            for ($i = 0; $i < 5; $i++) {
                if (($top >> $i) & 1) {
                    $chk ^= $gen[$i];
                }
            }
        }
        return $chk;
    }

    /**
     * @param list<int> $data
     * @return list<int>
     */
    private static function bech32CreateChecksum(string $hrp, array $data): array
    {
        $expand = [];
        $len = strlen($hrp);
        for ($i = 0; $i < $len; $i++) {
            $expand[] = ord($hrp[$i]) >> 5;
        }
        $expand[] = 0;
        for ($i = 0; $i < $len; $i++) {
            $expand[] = ord($hrp[$i]) & 31;
        }
        $polymod = self::bech32Polymod(array_merge($expand, $data, [0, 0, 0, 0, 0, 0])) ^ 1;
        $ret = [];
        for ($i = 0; $i < 6; $i++) {
            $ret[] = ($polymod >> (5 * (5 - $i))) & 31;
        }
        return $ret;
    }

    /**
     * Bitcoin Cash CashAddr Encoder (bitcoincash:q...) — 32-bit & 64-bit PHP safe.
     */
    public static function encodeCashAddr(string $prefix, int $type, string $hash20): string
    {
        $versionByte = ($type << 3) | 0; // 0 = 160-bit hash
        $payloadBytes = array_merge([$versionByte], array_values(unpack('C*', $hash20) ?: []));
        $payload5 = self::convertBits($payloadBytes, 8, 5, true);

        $prefixData = [];
        for ($i = 0, $n = strlen($prefix); $i < $n; $i++) {
            $prefixData[] = ord($prefix[$i]) & 0x1f;
        }
        $prefixData[] = 0;

        // 40-bit polymod split into high 20 bits ($cHi) and low 20 bits ($cLo) for 32-bit safety
        $polyInput = array_merge($prefixData, $payload5, [0, 0, 0, 0, 0, 0, 0, 0]);
        $cHi = 0;
        $cLo = 1;
        foreach ($polyInput as $d) {
            $c0 = ($cHi >> 15) & 0x1f;
            $cHi = (($cHi & 0x7fff) << 5) | (($cLo >> 15) & 0x1f);
            $cLo = (($cLo & 0x7fff) << 5) ^ $d;
            if ($c0 & 0x01) {
                $cHi ^= 0x98f2b;
                $cLo ^= 0xc8e61;
            }
            if ($c0 & 0x02) {
                $cHi ^= 0x79b76;
                $cLo ^= 0xd99e2;
            }
            if ($c0 & 0x04) {
                $cHi ^= 0xf33e5;
                $cLo ^= 0xfb3c4;
            }
            if ($c0 & 0x08) {
                $cHi ^= 0xae2ea;
                $cLo ^= 0xbe2a8;
            }
            if ($c0 & 0x10) {
                $cHi ^= 0x1e4f4;
                $cLo ^= 0x3e470;
            }
        }
        $cLo ^= 1;
        $checksum = [
            ($cHi >> 15) & 0x1f,
            ($cHi >> 10) & 0x1f,
            ($cHi >> 5) & 0x1f,
            $cHi & 0x1f,
            ($cLo >> 15) & 0x1f,
            ($cLo >> 10) & 0x1f,
            ($cLo >> 5) & 0x1f,
            $cLo & 0x1f,
        ];

        $out = $prefix . ':';
        foreach (array_merge($payload5, $checksum) as $w) {
            $out .= self::CASHADDR_CHARSET[$w];
        }
        return $out;
    }

    /**
     * @param list<int> $data
     * @return list<int>
     */
    private static function convertBits(array $data, int $fromBits, int $toBits, bool $pad): array
    {
        $acc = 0;
        $bits = 0;
        $ret = [];
        $maxv = (1 << $toBits) - 1;
        foreach ($data as $value) {
            $acc = ($acc << $fromBits) | $value;
            $bits += $fromBits;
            while ($bits >= $toBits) {
                $bits -= $toBits;
                $ret[] = ($acc >> $bits) & $maxv;
            }
        }
        if ($pad && $bits > 0) {
            $ret[] = ($acc << ($toBits - $bits)) & $maxv;
        }
        return $ret;
    }

    /**
     * Pure-PHP Keccak-256 (0x01 padding, NOT NIST SHA3-256) using 4x16-bit limbs so both
     * 32-bit (WASM) and 64-bit native PHP produce identical hashes without overflow warnings.
     */
    public static function keccak256(string $input): string
    {
        // Each 64-bit RC constant as [w0 (bits 0..15), w1 (16..31), w2 (32..47), w3 (48..63)]
        $rc = [
            [0x0001, 0x0000, 0x0000, 0x0000], [0x8082, 0x0000, 0x0000, 0x0000],
            [0x808A, 0x0000, 0x0000, 0x8000], [0x8000, 0x8000, 0x0000, 0x8000],
            [0x808B, 0x0000, 0x0000, 0x0000], [0x0001, 0x8000, 0x0000, 0x0000],
            [0x8081, 0x8000, 0x0000, 0x8000], [0x8009, 0x0000, 0x0000, 0x8000],
            [0x008A, 0x0000, 0x0000, 0x0000], [0x0088, 0x0000, 0x0000, 0x0000],
            [0x8009, 0x8000, 0x0000, 0x0000], [0x000A, 0x8000, 0x0000, 0x0000],
            [0x808B, 0x8000, 0x0000, 0x0000], [0x008B, 0x0000, 0x0000, 0x8000],
            [0x8089, 0x0000, 0x0000, 0x8000], [0x8003, 0x0000, 0x0000, 0x8000],
            [0x8002, 0x0000, 0x0000, 0x8000], [0x0080, 0x0000, 0x0000, 0x8000],
            [0x800A, 0x0000, 0x0000, 0x0000], [0x000A, 0x8000, 0x0000, 0x8000],
            [0x8081, 0x8000, 0x0000, 0x8000], [0x8080, 0x0000, 0x0000, 0x8000],
            [0x0001, 0x8000, 0x0000, 0x0000], [0x8008, 0x8000, 0x0000, 0x8000],
        ];
        $rotc = [1, 3, 6, 10, 15, 21, 28, 36, 45, 55, 2, 14, 27, 41, 56, 8, 25, 43, 62, 18, 39, 61, 20, 44];
        $piln = [10, 7, 11, 17, 18, 3, 5, 16, 8, 21, 24, 4, 15, 23, 19, 13, 12, 2, 20, 14, 22, 9, 6, 1];

        $rotl = static function (array $a, int $n): array {
            $wShift = intdiv($n, 16);
            $bShift = $n % 16;
            $tmp = [
                $a[(4 - $wShift) % 4],
                $a[(5 - $wShift) % 4],
                $a[(6 - $wShift) % 4],
                $a[(7 - $wShift) % 4],
            ];
            if ($bShift === 0) {
                return $tmp;
            }
            $rShift = 16 - $bShift;
            return [
                (($tmp[0] << $bShift) | ($tmp[3] >> $rShift)) & 0xffff,
                (($tmp[1] << $bShift) | ($tmp[0] >> $rShift)) & 0xffff,
                (($tmp[2] << $bShift) | ($tmp[1] >> $rShift)) & 0xffff,
                (($tmp[3] << $bShift) | ($tmp[2] >> $rShift)) & 0xffff,
            ];
        };

        $rate = 136;
        $padLen = $rate - (strlen($input) % $rate);
        $padded = ($padLen === 1)
            ? ($input . "\x81")
            : ($input . "\x01" . str_repeat("\x00", $padLen - 2) . "\x80");

        $state = array_fill(0, 25, [0, 0, 0, 0]);
        $blocks = str_split($padded, $rate);
        foreach ($blocks as $block) {
            for ($i = 0; $i < 17; $i++) {
                $w = array_values(unpack('v4', substr($block, $i * 8, 8)) ?: [0, 0, 0, 0]);
                $state[$i] = [
                    $state[$i][0] ^ $w[0],
                    $state[$i][1] ^ $w[1],
                    $state[$i][2] ^ $w[2],
                    $state[$i][3] ^ $w[3],
                ];
            }

            for ($round = 0; $round < 24; $round++) {
                $bc = [];
                for ($i = 0; $i < 5; $i++) {
                    $bc[$i] = [
                        $state[$i][0] ^ $state[$i + 5][0] ^ $state[$i + 10][0] ^ $state[$i + 15][0] ^ $state[$i + 20][0],
                        $state[$i][1] ^ $state[$i + 5][1] ^ $state[$i + 10][1] ^ $state[$i + 15][1] ^ $state[$i + 20][1],
                        $state[$i][2] ^ $state[$i + 5][2] ^ $state[$i + 10][2] ^ $state[$i + 15][2] ^ $state[$i + 20][2],
                        $state[$i][3] ^ $state[$i + 5][3] ^ $state[$i + 10][3] ^ $state[$i + 15][3] ^ $state[$i + 20][3],
                    ];
                }
                for ($i = 0; $i < 5; $i++) {
                    $r1 = $rotl($bc[($i + 1) % 5], 1);
                    $c4 = $bc[($i + 4) % 5];
                    $t = [$c4[0] ^ $r1[0], $c4[1] ^ $r1[1], $c4[2] ^ $r1[2], $c4[3] ^ $r1[3]];
                    for ($j = 0; $j < 25; $j += 5) {
                        $idx = $j + $i;
                        $state[$idx] = [
                            $state[$idx][0] ^ $t[0],
                            $state[$idx][1] ^ $t[1],
                            $state[$idx][2] ^ $t[2],
                            $state[$idx][3] ^ $t[3],
                        ];
                    }
                }
                $t = $state[1];
                for ($i = 0; $i < 24; $i++) {
                    $j = $piln[$i];
                    $tmp = $state[$j];
                    $state[$j] = $rotl($t, $rotc[$i]);
                    $t = $tmp;
                }
                for ($j = 0; $j < 25; $j += 5) {
                    $row = [$state[$j], $state[$j + 1], $state[$j + 2], $state[$j + 3], $state[$j + 4]];
                    for ($i = 0; $i < 5; $i++) {
                        $n1 = $row[($i + 1) % 5];
                        $n2 = $row[($i + 2) % 5];
                        $idx = $j + $i;
                        $state[$idx] = [
                            $row[$i][0] ^ ((~$n1[0] & 0xffff) & $n2[0]),
                            $row[$i][1] ^ ((~$n1[1] & 0xffff) & $n2[1]),
                            $row[$i][2] ^ ((~$n1[2] & 0xffff) & $n2[2]),
                            $row[$i][3] ^ ((~$n1[3] & 0xffff) & $n2[3]),
                        ];
                    }
                }
                $rcon = $rc[$round];
                $state[0] = [
                    $state[0][0] ^ $rcon[0],
                    $state[0][1] ^ $rcon[1],
                    $state[0][2] ^ $rcon[2],
                    $state[0][3] ^ $rcon[3],
                ];
            }
        }

        $out = '';
        for ($i = 0; $i < 4; $i++) {
            $out .= pack('v4', $state[$i][0], $state[$i][1], $state[$i][2], $state[$i][3]);
        }
        return $out;
    }

    public static function eip55Checksum(string $lowerHex40): string
    {
        $lower = strtolower($lowerHex40);
        $hashHex = bin2hex(self::keccak256($lower));
        $out = '0x';
        for ($i = 0; $i < 40; $i++) {
            $ch = $lower[$i];
            if ($ch >= 'a' && $ch <= 'f' && hexdec($hashHex[$i]) >= 8) {
                $out .= strtoupper($ch);
            } else {
                $out .= $ch;
            }
        }
        return $out;
    }
}
