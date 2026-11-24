<?php
require_once __DIR__ . '/../bin/verify-release-readiness.php';

$n = 0;
function rr_chk($ok, $msg) { global $n; if (!$ok) throw new RuntimeException($msg); $n++; }

$report = bc_release_readiness_report(dirname(__DIR__, 2), false);
rr_chk($report['ok'] === true, 'release readiness manifest & Bybit checks pass');
rr_chk($report['manifest_errors'] === [], 'zero manifest errors');
rr_chk($report['bybit_retired'] === true, 'Bybit retired across both editions');
rr_chk(is_array($report['flags']) && count($report['flags']) === 8, 'all 8 operational gate flags reported');
rr_chk($report['db_checked'] === false, 'default mode does not require DB');

echo "PASS $n release readiness preflight checks\n";
