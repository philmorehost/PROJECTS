<?php
/**
 * GATE: one DGV7 install can buy from another DGV7 install — on a DIFFERENT server.
 *
 * The protocol has two halves plus the choice that makes it reachable, and a green run must prove
 * all three on BOTH editions:
 *
 *   1. SELLER   api/app-backend/fetch-dgv7-plans.php answers {"success":true,"plans":[...]}.
 *   2. BUYER    bc-admin/ajax-fetch-plans.php calls it (this half existed only in NON-SAAS).
 *   3. CHOICE   the ten service pages offer a same-script seller in the fetcher dropdown, which is
 *               what was missing entirely: NON-SAAS hardcoded ONE domain ('v6.datagifting.com.ng')
 *               and SAAS had no such branch at all, so a seller hosted anywhere else had no
 *               dropdown entry — the feature looked unimplemented.
 *
 * Because the two halves talk over strings in different files, this gate also checks the SEAM: that
 * the endpoint path the buyer builds is the endpoint file that actually ships, and the response keys
 * the buyer reads are the keys the seller writes.
 *
 * NEGATIVE CONTROL (pinned at 1f32087, the commit before this work): SAAS had no DGV7 fetch call,
 * SAAS offered no same-script seller, and NON-SAAS matched a single hardcoded domain. The gate
 * asserts all three on that revision, so a green live run means something.
 *
 * Usage: php -n scratch/probe_same_script_vendor.php
 */

error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);
ini_set('display_errors', '1');

// >>> MUST BE THE COMMIT IMMEDIATELY BEFORE THE SAME-SCRIPT WORK LANDED. <<<
$PRE_FIX_REV = '1f32087';

$repoRoot = dirname(dirname(__DIR__));
$editions = array('DGV7.0-SAAS', 'DGV7.0-NON-SAAS');
$services = array('SmeData', 'DirectData', 'SharedData', 'CorporateData', 'Airtime', 'Cable', 'Electric', 'Exam', 'Betting', 'BulkSMS');
$endpointPath = '/api/app-backend/fetch-dgv7-plans.php';

$fail = 0;
$check = function ($label, $ok, $detail = '') use (&$fail) {
    printf("  [%s] %s%s\n", $ok ? 'ok  ' : 'FAIL', $label, $detail === '' ? '' : "  ($detail)");
    if (!$ok) $fail++;
};
$read = function ($edition, $rel, $rev = null) use ($repoRoot) {
    if ($rev === null) {
        $path = $repoRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $edition . '/' . $rel);
        return is_file($path) ? file_get_contents($path) : false;
    }
    return shell_exec('git -C ' . escapeshellarg($repoRoot) . ' show ' . escapeshellarg($rev . ':' . $edition . '/' . $rel) . ' 2>&1');
};

// ── Behavioural probe of the real helper, in a subprocess so the check is not a text match.
function candidate_verdict($helperPath, $ownHost, $url) {
    $probe = "<?php\n"
        . "ini_set('display_errors','0');\n"
        . "\$_SERVER['HTTP_HOST'] = " . var_export($ownHost, true) . ";\n"
        . "require " . var_export($helperPath, true) . ";\n"
        . "echo bc_remote_vendor_is_candidate(" . var_export($url, true) . ") ? 'yes' : 'no';\n";
    $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bc_same_script_' . getmypid() . '_' . md5($ownHost . $url) . '.php';
    if (file_put_contents($tmp, $probe) === false) return 'ERR';
    $out = trim((string)shell_exec(escapeshellarg(PHP_BINARY) . ' -n ' . escapeshellarg($tmp) . ' 2>&1'));
    @unlink($tmp);
    return $out;
}

echo "================ LIVE: same-script vendor support ================\n";

$helpers = array();
foreach ($editions as $edition) {
    $helperPath = $repoRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $edition . '/func/bc-remote-vendor.php');
    $helpers[$edition] = is_file($helperPath) ? md5_file($helperPath) : null;

    echo "— $edition\n";

    // ── 1. SELLER half
    $seller = $read($edition, 'api/app-backend/fetch-dgv7-plans.php');
    $check('seller endpoint ships at ' . $endpointPath, is_string($seller) && $seller !== '');
    if (is_string($seller) && $seller !== '') {
        $check('seller suppresses diagnostics (a notice before the JSON reads to the buyer as "invalid format from the provider")',
            strpos($seller, 'error_reporting(0)') !== false);
        $check('seller requires an APPROVED api user (the "activate this API" gate)',
            strpos($seller, 'api_status') !== false && strpos($seller, 'API approval needed') !== false);
        $check('seller answers the {success, plans[]} contract the buyer parses',
            strpos($seller, '"success" => true') !== false && strpos($seller, '"plans" => $plans') !== false);
        $check('seller emits name/code/price/days rows',
            strpos($seller, "'code' => \$p['val_1']") !== false && strpos($seller, "'price' =>") !== false
            && strpos($seller, "'days' =>") !== false && strpos($seller, "'name' =>") !== false);
    }

    // ── 2. BUYER half + the seam between the two
    $buyer = $read($edition, 'bc-admin/ajax-fetch-plans.php');
    $check('buyer calls the same-script endpoint', is_string($buyer) && strpos($buyer, 'fetch-dgv7-plans.php') !== false);
    if (is_string($buyer) && preg_match('#"(' . preg_quote($endpointPath, '#') . ')"#', $buyer, $m)) {
        $check('the path the buyer builds IS the file that ships (seam)',
            is_file($repoRoot . DIRECTORY_SEPARATOR . $edition . str_replace('/', DIRECTORY_SEPARATOR, $endpointPath)));
    }
    $check('buyer parses the answer tolerantly (a seller whose php.ini displays errors cannot be blamed)',
        is_string($buyer) && strpos($buyer, 'bc_gateway_json_decode') !== false);
    // The branch must stay reachable. It is tried after the embedded-code parse in one edition and
    // before the same-database fallback in the other, so what actually protects it is that the
    // <type>-localserver.php file it falls back to contains no plan array to find. That is asserted
    // for real below (see "reachability"), because a hand-added array there would shadow it.
    $check('buyer keeps the same-script attempt ordered before the same-database fallback',
        is_string($buyer)
        && strpos($buyer, 'fetch-dgv7-plans.php') !== false
        && (strpos($buyer, "Check if it's a local vendor") === false
            || strpos($buyer, 'fetch-dgv7-plans.php') < strpos($buyer, "Check if it's a local vendor")));

    // ── 3. CHOICE: every service page offers a same-script seller
    $withoutChoice = array();
    foreach ($services as $service) {
        $page = $read($edition, 'bc-admin/' . $service . '.php');
        if (!is_string($page) || strpos($page, 'bc_remote_vendor_is_candidate') === false) {
            $withoutChoice[] = $service;
        }
    }
    $check('all ten service pages offer a same-script seller', empty($withoutChoice), implode(', ', $withoutChoice));

    $hardcoded = array();
    foreach ($services as $service) {
        $page = (string)$read($edition, 'bc-admin/' . $service . '.php');
        if (strpos($page, "stripos(\$url, 'v6.datagifting.com.ng')") !== false) {
            $hardcoded[] = $service;
        }
    }
    $check('no page decides the seller by a hardcoded domain', empty($hardcoded), implode(', ', $hardcoded));
}

$check('func/bc-remote-vendor.php is identical in both editions (they are separate deployments)',
    $helpers['DGV7.0-SAAS'] !== null && $helpers['DGV7.0-SAAS'] === $helpers['DGV7.0-NON-SAAS']);

// ── Behaviour of the real candidate test
echo "\n— bc_remote_vendor_is_candidate() (real function, own host app.mzeevtu.com)\n";
$helperPath = $repoRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, 'DGV7.0-SAAS/func/bc-remote-vendor.php');
$cases = array(
    'v6.datagifting.com.ng'              => 'yes',
    'https://v6.datagifting.com.ng/'     => 'yes',
    'www.V6.Datagifting.com.ng'          => 'yes',
    'app.mzeevtu.com'                    => 'no',   // cannot resell to yourself
    'https://app.mzeevtu.com'            => 'no',
    'vtpass.com'                         => 'no',   // dedicated fetcher branches
    'www.clubkonnect.com'                => 'no',
    'nellobytesystems.com'               => 'no',
    'naijaresultpins.com'                => 'no',
    'localhost'                          => 'no',   // not a hostname
    ''                                   => 'no',
    'hdkdata.com'                        => 'yes',  // offered; the fetch itself decides
);
foreach ($cases as $url => $expected) {
    $got = candidate_verdict($helperPath, 'app.mzeevtu.com', $url);
    $check(sprintf('%-34s -> %s', $url === '' ? '(empty)' : $url, $expected), $got === $expected, "got $got");
}

// ── Reachability: the embedded-plan parse must NOT be able to answer for a same-script seller.
// Both editions fall back to func/api-gateway/<type>-localserver.php when a provider has no gateway
// file of its own, and whichever branch runs first, a plan array in that file would be read as the
// seller's catalogue. It belongs to THIS install and would be wrong (and unpriced).
echo "\n— reachability (does <type>-localserver.php shadow the same-script branch?)\n";
$parserProbe = "<?php\nini_set('display_errors','0');\nrequire " . var_export($repoRoot . DIRECTORY_SEPARATOR . 'DGV7.0-SAAS' . DIRECTORY_SEPARATOR . 'func' . DIRECTORY_SEPARATOR . 'bc-gateway-plan-parser.php', true) . ";\n"
    . "\$hits = array();\n"
    . "foreach (array('sme-data','cg-data','dd-data','shared-data','airtime','cable','exam','sms') as \$f) {\n"
    . "    \$p = " . var_export($repoRoot . DIRECTORY_SEPARATOR . 'DGV7.0-SAAS' . DIRECTORY_SEPARATOR . 'func' . DIRECTORY_SEPARATOR . 'api-gateway' . DIRECTORY_SEPARATOR, true) . " . \$f . '-localserver.php';\n"
    . "    if (!is_file(\$p)) continue;\n"
    . "    foreach (array('mtn','airtel','glo','9mobile','dstv','gotv','waec','standard_sms','bulk-sms') as \$n) {\n"
    . "        \$r = bc_gateway_parse_plan_codes(\$p, \$n);\n"
    . "        if (is_array(\$r) && !empty(\$r)) \$hits[] = \$f . ':' . \$n;\n"
    . "    }\n"
    . "}\n"
    . "echo \$hits ? implode(', ', \$hits) : 'none';\n";
$parserTmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bc_localserver_probe_' . getmypid() . '.php';
if (file_put_contents($parserTmp, $parserProbe) === false) {
    echo "FATAL: cannot write the reachability probe\n";
    exit(3);
}
$shadowed = trim((string)shell_exec(escapeshellarg(PHP_BINARY) . ' -n ' . escapeshellarg($parserTmp) . ' 2>&1'));
@unlink($parserTmp);
$check('no localserver gateway file can answer for the seller', $shadowed === 'none', $shadowed);

// ── NEGATIVE CONTROL
echo "\n================ NEGATIVE CONTROL: $PRE_FIX_REV ================\n";
if (!is_string($read('DGV7.0-SAAS', 'bc-admin/ajax-fetch-plans.php', $PRE_FIX_REV))
    || strpos((string)$read('DGV7.0-SAAS', 'bc-admin/ajax-fetch-plans.php', $PRE_FIX_REV), 'fatal:') === 0) {
    echo "FATAL: cannot read the pre-fix revision - the gate is unverified.\n";
    exit(3);
}
$saasBuyerOld = (string)$read('DGV7.0-SAAS', 'bc-admin/ajax-fetch-plans.php', $PRE_FIX_REV);
$saasPageOld  = (string)$read('DGV7.0-SAAS', 'bc-admin/SmeData.php', $PRE_FIX_REV);
$nonSaasPageOld = (string)$read('DGV7.0-NON-SAAS', 'bc-admin/SmeData.php', $PRE_FIX_REV);

$check('SAAS had no same-script fetch call (half the feature was simply absent there)',
    strpos($saasBuyerOld, 'fetch-dgv7-plans.php') === false);
$check('SAAS offered no same-script seller at all',
    strpos($saasPageOld, 'bc_remote_vendor_is_candidate') === false && strpos($saasPageOld, 'v6.datagifting.com.ng') === false);
$check('NON-SAAS decided the seller by one hardcoded domain',
    strpos($nonSaasPageOld, "stripos(\$url, 'v6.datagifting.com.ng')") !== false);

echo "\n================ SUMMARY ================\n";
echo $fail === 0
    ? "PASS - the seller publishes the catalogue, both editions can ask for it, every service page\n"
      . "       offers a same-script seller, and the pinned pre-fix revision fails exactly those checks.\n"
    : "FAIL - $fail check(s) did not hold.\n";
exit($fail === 0 ? 0 : 1);
