<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Self-contained ISO/IEC 18004 QR Code SVG Generator (Byte mode, ECC Level L, Versions 1..8, up to 192 bytes).
 * Zero external image requests — protects buyer privacy on crypto checkouts.
 */
final class QrCode
{
    /** @var array<int, array{size:int, dataCodewords:int, ecCodewords:int, align:list<int>}> */
    private const VERSIONS = [
        1 => ['size' => 21, 'dataCodewords' => 19,  'ecCodewords' => 7,  'align' => []],
        2 => ['size' => 25, 'dataCodewords' => 34,  'ecCodewords' => 10, 'align' => [6, 18]],
        3 => ['size' => 29, 'dataCodewords' => 55,  'ecCodewords' => 15, 'align' => [6, 22]],
        4 => ['size' => 33, 'dataCodewords' => 80,  'ecCodewords' => 20, 'align' => [6, 26]],
        5 => ['size' => 37, 'dataCodewords' => 108, 'ecCodewords' => 26, 'align' => [6, 30]],
        6 => ['size' => 41, 'dataCodewords' => 136, 'ecCodewords' => 18, 'align' => [6, 34]], // 2 blocks of 68 data + 18 ec
        7 => ['size' => 45, 'dataCodewords' => 156, 'ecCodewords' => 20, 'align' => [6, 22, 38]], // 2 blocks of 78 data + 20 ec
        8 => ['size' => 49, 'dataCodewords' => 194, 'ecCodewords' => 24, 'align' => [6, 24, 42]], // 2 blocks of 97 data + 24 ec
    ];

    /**
     * Render a crisp scannable SVG QR code for a crypto payment URI or address.
     */
    public static function svg(string $text, int $pixelSize = 200): string
    {
        $bytes = array_values(unpack('C*', $text) ?: []);
        $len = count($bytes);
        $ver = self::pickVersion($len);
        $spec = self::VERSIONS[$ver];
        $size = $spec['size'];

        $codewords = self::encodeCodewords($bytes, $ver, $spec);
        [$matrix, $reserved] = self::initMatrix($size, $spec['align'], $ver);
        self::placeData($matrix, $reserved, $codewords, $size);

        // Apply Mask 0 ((r + c) % 2 == 0) and write format bits for ECC L (01) + Mask 000
        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c < $size; $c++) {
                if (!$reserved[$r][$c] && (($r + $c) % 2 === 0)) {
                    $matrix[$r][$c] ^= 1;
                }
            }
        }
        self::writeFormatBits($matrix, $size, 0);

        $quiet = 3;
        $viewSize = $size + $quiet * 2;
        $path = '';
        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c < $size; $c++) {
                if ($matrix[$r][$c] === 1) {
                    $x = $c + $quiet;
                    $y = $r + $quiet;
                    $path .= "M{$x},{$y}h1v1h-1z";
                }
            }
        }

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" width="%d" height="%d" shape-rendering="crispEdges" role="img" aria-label="Payment QR Code"><rect width="100%%" height="100%%" rx="2" fill="#ffffff"/><path d="%s" fill="#0f172a"/></svg>',
            $viewSize,
            $viewSize,
            $pixelSize,
            $pixelSize,
            $path
        );
    }

    private static function pickVersion(int $byteLen): int
    {
        foreach (self::VERSIONS as $v => $spec) {
            if ($byteLen + 2 <= $spec['dataCodewords']) {
                return $v;
            }
        }
        return 8;
    }

    /**
     * @param list<int> $bytes
     * @param array{size:int, dataCodewords:int, ecCodewords:int, align:list<int>} $spec
     * @return list<int>
     */
    private static function encodeCodewords(array $bytes, int $ver, array $spec): array
    {
        $maxBytes = $spec['dataCodewords'] - 2;
        if (count($bytes) > $maxBytes) {
            $bytes = array_slice($bytes, 0, $maxBytes);
        }

        $bits = '0100' . str_pad(decbin(count($bytes)), 8, '0', STR_PAD_LEFT);
        foreach ($bytes as $b) {
            $bits .= str_pad(decbin($b), 8, '0', STR_PAD_LEFT);
        }
        $totalBits = $spec['dataCodewords'] * 8;
        $bits .= substr('0000', 0, min(4, max(0, $totalBits - strlen($bits))));
        while (strlen($bits) % 8 !== 0) {
            $bits .= '0';
        }

        $data = [];
        for ($i = 0, $n = strlen($bits); $i < $n; $i += 8) {
            $data[] = (int) bindec(substr($bits, $i, 8));
        }
        $pads = [0xEC, 0x11];
        $pIdx = 0;
        while (count($data) < $spec['dataCodewords']) {
            $data[] = $pads[$pIdx % 2];
            $pIdx++;
        }

        if ($ver <= 5) {
            $ec = self::reedSolomon($data, $spec['ecCodewords']);
            return array_merge($data, $ec);
        }

        // Versions 6..8 ECC L use 2 equal-sized blocks
        $half = intdiv($spec['dataCodewords'], 2);
        $b1 = array_slice($data, 0, $half);
        $b2 = array_slice($data, $half);
        $ec1 = self::reedSolomon($b1, $spec['ecCodewords']);
        $ec2 = self::reedSolomon($b2, $spec['ecCodewords']);

        $interleaved = [];
        for ($i = 0; $i < $half; $i++) {
            $interleaved[] = $b1[$i];
            $interleaved[] = $b2[$i];
        }
        for ($i = 0; $i < $spec['ecCodewords']; $i++) {
            $interleaved[] = $ec1[$i];
            $interleaved[] = $ec2[$i];
        }
        return $interleaved;
    }

    /**
     * @param list<int> $data
     * @return list<int>
     */
    private static function reedSolomon(array $data, int $degree): array
    {
        $exp = array_fill(0, 512, 0);
        $log = array_fill(0, 256, 0);
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            $exp[$i] = $x;
            $log[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) {
                $x ^= 0x11D;
            }
        }
        for ($i = 255; $i < 512; $i++) {
            $exp[$i] = $exp[$i - 255];
        }
        $gfMul = static fn(int $a, int $b): int => ($a === 0 || $b === 0) ? 0 : $exp[$log[$a] + $log[$b]];

        $gen = [1];
        for ($i = 0; $i < $degree; $i++) {
            $next = array_fill(0, count($gen) + 1, 0);
            foreach ($gen as $j => $coef) {
                $next[$j] ^= $coef;
                $next[$j + 1] ^= $gfMul($coef, $exp[$i]);
            }
            $gen = $next;
        }

        $rem = array_fill(0, $degree, 0);
        foreach ($data as $byte) {
            $factor = $byte ^ array_shift($rem);
            $rem[] = 0;
            for ($j = 0; $j < $degree; $j++) {
                $rem[$j] ^= $gfMul($gen[$j + 1], $factor);
            }
        }
        return $rem;
    }

    /**
     * @param list<int> $align
     * @return array{0:list<list<int>>, 1:list<list<bool>>}
     */
    private static function initMatrix(int $size, array $align, int $ver): array
    {
        $m = array_fill(0, $size, array_fill(0, $size, 0));
        $res = array_fill(0, $size, array_fill(0, $size, false));

        $setFinder = static function (int $row, int $col) use (&$m, &$res, $size): void {
            for ($r = -1; $r <= 7; $r++) {
                for ($c = -1; $c <= 7; $c++) {
                    $rr = $row + $r;
                    $cc = $col + $c;
                    if ($rr < 0 || $rr >= $size || $cc < 0 || $cc >= $size) {
                        continue;
                    }
                    $inOuter = ($r >= 0 && $r <= 6 && $c >= 0 && $c <= 6)
                        && ($r === 0 || $r === 6 || $c === 0 || $c === 6 || ($r >= 2 && $r <= 4 && $c >= 2 && $c <= 4));
                    $m[$rr][$cc] = $inOuter ? 1 : 0;
                    $res[$rr][$cc] = true;
                }
            }
        };

        $setFinder(0, 0);
        $setFinder(0, $size - 7);
        $setFinder($size - 7, 0);

        for ($i = 8; $i < $size - 8; $i++) {
            $bit = ($i % 2 === 0) ? 1 : 0;
            if (!$res[6][$i]) {
                $m[6][$i] = $bit;
                $res[6][$i] = true;
            }
            if (!$res[$i][6]) {
                $m[$i][6] = $bit;
                $res[$i][6] = true;
            }
        }

        foreach ($align as $ar) {
            foreach ($align as $ac) {
                if ($res[$ar][$ac]) {
                    continue;
                }
                for ($r = -2; $r <= 2; $r++) {
                    for ($c = -2; $c <= 2; $c++) {
                        $dark = max(abs($r), abs($c)) !== 1;
                        $m[$ar + $r][$ac + $c] = $dark ? 1 : 0;
                        $res[$ar + $r][$ac + $c] = true;
                    }
                }
            }
        }

        // Reserve format information areas & dark module
        for ($i = 0; $i <= 8; $i++) {
            if ($i < $size) {
                $res[8][$i] = true;
                $res[$i][8] = true;
            }
        }
        for ($i = 0; $i < 8; $i++) {
            $res[8][$size - 1 - $i] = true;
            $res[$size - 1 - $i][8] = true;
        }
        $m[$size - 8][8] = 1;
        $res[$size - 8][8] = true;

        if ($ver >= 7) {
            // Version info bits (version 7 = 0x07C94, version 8 = 0x085BC)
            $vInfo = $ver === 7 ? 0x07C94 : 0x085BC;
            for ($i = 0; $i < 18; $i++) {
                $bit = ($vInfo >> $i) & 1;
                $a = intdiv($i, 3);
                $b = ($i % 3) + $size - 11;
                $m[$a][$b] = $bit;
                $res[$a][$b] = true;
                $m[$b][$a] = $bit;
                $res[$b][$a] = true;
            }
        }

        return [$m, $res];
    }

    /**
     * @param list<list<int>> $m
     * @param list<list<bool>> $res
     * @param list<int> $codewords
     */
    private static function placeData(array &$m, array $res, array $codewords, int $size): void
    {
        $bits = [];
        foreach ($codewords as $cw) {
            for ($b = 7; $b >= 0; $b--) {
                $bits[] = ($cw >> $b) & 1;
            }
        }

        $idx = 0;
        $total = count($bits);
        $up = true;
        for ($col = $size - 1; $col >= 1; $col -= 2) {
            if ($col === 6) {
                $col = 5;
            }
            for ($step = 0; $step < $size; $step++) {
                $row = $up ? ($size - 1 - $step) : $step;
                for ($dx = 0; $dx < 2; $dx++) {
                    $c = $col - $dx;
                    if (!$res[$row][$c]) {
                        $m[$row][$c] = $idx < $total ? $bits[$idx++] : 0;
                    }
                }
            }
            $up = !$up;
        }
    }

    /**
     * @param list<list<int>> $m
     */
    private static function writeFormatBits(array &$m, int $size, int $mask): void
    {
        // ECC Level L = 01; Mask 0 = 000 -> precomputed BCH(15,5) masked with 0x5412 = 0x77C4
        $data = (0b01 << 3) | ($mask & 0b111);
        $rem = $data << 10;
        for ($i = 4; $i >= 0; $i--) {
            if (($rem >> ($i + 10)) & 1) {
                $rem ^= (0x537 << $i);
            }
        }
        $bits = (($data << 10) | $rem) ^ 0x5412;

        for ($i = 0; $i <= 5; $i++) {
            $m[8][$i] = ($bits >> $i) & 1;
        }
        $m[8][7] = ($bits >> 6) & 1;
        $m[8][8] = ($bits >> 7) & 1;
        $m[7][8] = ($bits >> 8) & 1;
        for ($i = 9; $i < 15; $i++) {
            $m[14 - $i][8] = ($bits >> $i) & 1;
        }

        for ($i = 0; $i < 8; $i++) {
            $m[8][$size - 1 - $i] = ($bits >> $i) & 1;
        }
        for ($i = 8; $i < 15; $i++) {
            $m[$size - 15 + $i][8] = ($bits >> $i) & 1;
        }
    }
}
