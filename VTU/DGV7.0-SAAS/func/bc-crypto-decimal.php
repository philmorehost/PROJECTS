<?php
/** Exact non-negative integer units. No float, BCMath or locale dependency. */
function bc_crypto_units($value) {
    if (!is_string($value) || !preg_match('/^(?:0|[1-9][0-9]{0,59})$/D', $value)) throw new InvalidArgumentException('crypto_units_invalid');
    return $value;
}
function bc_crypto_scale($scale) {
    if (!is_int($scale) || $scale < 0 || $scale > 30) throw new InvalidArgumentException('crypto_scale_invalid');
    return $scale;
}
function bc_crypto_compare($a, $b) {
    bc_crypto_units($a); bc_crypto_units($b);
    return strlen($a) === strlen($b) ? (strcmp($a, $b) <=> 0) : (strlen($a) <=> strlen($b));
}
function bc_crypto_add($a, $b) {
    bc_crypto_units($a); bc_crypto_units($b);
    $i = strlen($a)-1; $j = strlen($b)-1; $carry = 0; $out = '';
    while ($i >= 0 || $j >= 0 || $carry) {
        $n = ($i >= 0 ? ord($a[$i--])-48 : 0) + ($j >= 0 ? ord($b[$j--])-48 : 0) + $carry;
        $out = chr(48 + $n % 10) . $out; $carry = intdiv($n, 10);
    }
    return bc_crypto_units($out);
}
function bc_crypto_subtract($a, $b) {
    if (bc_crypto_compare($a, $b) < 0) throw new InvalidArgumentException('crypto_insufficient_balance');
    $j = strlen($b)-1; $borrow = 0; $out = '';
    for ($i = strlen($a)-1; $i >= 0; $i--) {
        $n = ord($a[$i])-48-$borrow-($j >= 0 ? ord($b[$j--])-48 : 0);
        $borrow = $n < 0 ? 1 : 0; if ($n < 0) $n += 10;
        $out = chr(48+$n) . $out;
    }
    return bc_crypto_units(ltrim($out, '0') ?: '0');
}
function bc_crypto_to_units($amount, $scale) {
    bc_crypto_scale($scale);
    if (!is_string($amount) || strlen($amount) > 92 || !preg_match('/^(0|[1-9][0-9]*)(?:\.([0-9]+))?$/D', $amount, $m)) throw new InvalidArgumentException('crypto_amount_invalid');
    $fraction = $m[2] ?? '';
    if (strlen($fraction) > $scale) throw new InvalidArgumentException('crypto_amount_precision');
    return bc_crypto_units(ltrim($m[1] . str_pad($fraction, $scale, '0'), '0') ?: '0');
}
function bc_crypto_from_units($units, $scale) {
    bc_crypto_units($units); bc_crypto_scale($scale);
    if ($scale === 0) return $units;
    $padded = str_pad($units, $scale+1, '0', STR_PAD_LEFT);
    return substr($padded, 0, -$scale) . '.' . substr($padded, -$scale);
}
/** Multiply by an integer (basis-point fee numerator), rounding UP. No hidden undercharged dust. */
function bc_crypto_fee_bps($units, $bps) {
    bc_crypto_units($units);
    if (!is_int($bps) || $bps < 0 || $bps > 10000) throw new InvalidArgumentException('crypto_fee_invalid');
    // Long division accumulates only small native integers, never a monetary float.
    $carry = 0; $product = '';
    for ($i = strlen($units)-1; $i >= 0; $i--) {
        $n = (ord($units[$i])-48)*$bps+$carry;
        $product = (string)($n%10) . $product; $carry = intdiv($n,10);
    }
    $product = ltrim(($carry ? (string)$carry : '') . $product, '0') ?: '0';
    $product = str_pad($product, 5, '0', STR_PAD_LEFT);
    $quotient = ltrim(substr($product, 0, -4), '0') ?: '0';
    return substr($product, -4) === '0000' ? bc_crypto_units($quotient) : bc_crypto_add($quotient, '1');
}
