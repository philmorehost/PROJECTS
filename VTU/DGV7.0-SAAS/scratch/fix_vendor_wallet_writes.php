<?php
/**
 * ONE-SHOT MIGRATION: make the VENDOR wallet writes atomic in func/bc-func.php.
 *
 * chargeVendor() and chargeOtherVendor() both did:
 *     $after = $get_logged_user_det["balance"] +/- $discounted_amount;
 *     ...INSERT the transaction...
 *     UPDATE sas_vendors SET balance='$after' WHERE id=...
 *
 * That is the same absolute-write defect that was fixed in chargeUser() for the USER wallet:
 *   - LOST UPDATE: two requests read the same balance and both write a result computed from it,
 *     so one of them disappears.
 *   - RESURRECTION: on the credit side the absolute write also puts back a balance that a
 *     concurrent debit had already lowered, so a refused purchase's refund can hand back money
 *     that was never taken.
 *
 * The two money blocks are BYTE-IDENTICAL between the two functions, so a plain search/replace
 * cannot anchor on one of them. This script works line-by-line, VERIFIES the expected shape of
 * each block before touching it, and refuses to write anything unless it found exactly the
 * expected number of them. It uses bc_vendor_wallet_apply_delta(), which is defined in the same
 * file, so the delta form and the wallet helper cannot drift apart.
 *
 * Usage:  php fix_vendor_wallet_writes.php <path to bc-func.php> [--check]
 *         --check only reports what it would do and writes nothing.
 */

$path = isset($argv[1]) ? $argv[1] : null;
$checkOnly = in_array('--check', array_slice($argv, 2), true);

if ($path === null || !is_file($path)) {
    fwrite(STDERR, "usage: php fix_vendor_wallet_writes.php <bc-func.php> [--check]\n");
    exit(2);
}

$lines = file($path);
if ($lines === false) {
    fwrite(STDERR, "could not read $path\n");
    exit(2);
}

/** The indentation of a line, so the replacement keeps the file's own style. */
function indent_of($line)
{
    return preg_replace('/^([ \t]*).*$/s', '$1', $line);
}

function trim_of($line)
{
    return trim($line);
}

$blocks = array(
    'debit' => array(
        'first' => '$user_balance_before_debit = $get_logged_user_det["balance"];',
        // Offset of the INSERT line below the first line (0 = the first line itself).
        'insert_offset' => 3,
        'expect' => array(
            '$user_balance_after_debit = ($user_balance_before_debit - $discounted_amount);',
            '',
            'INSERT INTO sas_vendor_transactions',
            "UPDATE sas_vendors SET balance='\$user_balance_after_debit'",
            'if (($user_balance_before_debit !== false) && ($charge_user == true)) {',
        ),
        'replace' => function ($ind, $insertLine) {
            return array(
                $ind . "// Relative wallet write: the move and its read-back happen in ONE statement on the\n",
                $ind . "// row MySQL locks, and the debit additionally requires the balance to cover it, so a\n",
                $ind . "// balance that moved after the pre-check above cannot be over-drawn. The absolute\n",
                $ind . "// write that used to be here lost a concurrent debit and could resurrect a balance\n",
                $ind . "// a concurrent debit had already lowered.\n",
                $ind . '$bc_bal_delta = bc_vendor_wallet_apply_delta((int)$get_logged_user_det["id"], -1 * (float)$discounted_amount, $user_balance_before_debit, $user_balance_after_debit);' . "\n",
                $ind . 'if ($bc_bal_delta !== "success") {' . "\n",
                $ind . "\treturn \"failed\";\n",
                $ind . "}\n",
                "\n",
                $insertLine,
                $ind . '$charge_user = ($bc_bal_delta === "success");' . "\n",
                $ind . 'if ($insert_transaction && ($charge_user == true)) {' . "\n",
            );
        },
    ),
    'credit' => array(
        'first' => '$user_balance_before_credit = $get_logged_user_det["balance"];',
        'insert_offset' => 3,
        'expect' => array(
            '$user_balance_after_credit = ($user_balance_before_credit + $discounted_amount);',
            '',
            'INSERT INTO sas_vendor_transactions',
            "UPDATE sas_vendors SET balance='\$user_balance_after_credit'",
            'if (($user_balance_before_credit !== false) && ($charge_user == true)) {',
        ),
        'replace' => function ($ind, $insertLine) {
            return array(
                $ind . "// Relative wallet write: the move and its read-back happen in ONE statement on the\n",
                $ind . "// row MySQL locks. The absolute write that used to be here RESURRECTED a balance a\n",
                $ind . "// concurrent debit had already lowered, so a refused purchase's refund could hand back\n",
                $ind . "// money that was never actually taken.\n",
                $ind . '$bc_bal_delta = bc_vendor_wallet_apply_delta((int)$get_logged_user_det["id"], (float)$discounted_amount, $user_balance_before_credit, $user_balance_after_credit);' . "\n",
                $ind . 'if ($bc_bal_delta !== "success") {' . "\n",
                $ind . "\treturn \"failed\";\n",
                $ind . "}\n",
                "\n",
                $insertLine,
                $ind . '$charge_user = ($bc_bal_delta === "success");' . "\n",
                $ind . 'if ($insert_transaction && ($charge_user == true)) {' . "\n",
            );
        },
    ),
);

$applied = array('debit' => 0, 'credit' => 0);
$skipped = array();

// Walk backwards so earlier indices stay valid as we splice.
for ($i = count($lines) - 1; $i >= 0; $i--) {
    foreach ($blocks as $kind => $block) {
        if (trim_of($lines[$i]) !== $block['first']) continue;

        $matches = true;
        $needle = count($block['expect']);
        for ($k = 0; $k < $needle; $k++) {
            if (!isset($lines[$i + $k + 1])) { $matches = false; break; }
            $got = trim_of($lines[$i + $k + 1]);
            $want = $block['expect'][$k];
            if ($want === '') {
                if ($got !== '') { $matches = false; break; }
            } elseif (strpos($got, $want) === false) {
                $matches = false;
                break;
            }
        }
        if (!$matches) {
            $skipped[] = sprintf('line %d (%s) did not have the expected shape', $i + 1, $kind);
            continue;
        }

        $ind = indent_of($lines[$i]);
        $insertLine = $lines[$i + $block['insert_offset']];
        $replacement = call_user_func($block['replace'], $ind, $insertLine);
        array_splice($lines, $i, $needle + 1, $replacement);
        $applied[$kind]++;
    }
}

echo "file: $path\n";
foreach ($applied as $kind => $n) {
    echo "  $kind blocks rewritten: $n\n";
}
foreach ($skipped as $s) {
    echo "  not this script's block (ignored): $s\n";
}

// Unrelated blocks elsewhere in the file (the USER wallet) legitimately have a different shape;
// they are reported for information only. What matters is that BOTH vendor functions were found.
$ok = ($applied['debit'] === 2 && $applied['credit'] === 2);
if (!$ok) {
    echo "RESULT: expected 2 debit + 2 credit vendor blocks, found {$applied['debit']} + {$applied['credit']} - REFUSING to write\n";
    exit(1);
}

if ($checkOnly) {
    echo "RESULT: shape verified, --check so nothing written\n";
    exit(0);
}

if (file_put_contents($path, implode('', $lines)) === false) {
    fwrite(STDERR, "could not write $path\n");
    exit(1);
}
echo "RESULT: written\n";
exit(0);
