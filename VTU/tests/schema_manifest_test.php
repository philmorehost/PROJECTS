<?php
$_SERVER['SCRIPT_FILENAME'] = '';
require_once __DIR__ . '/../bin/verify-schema-manifest.php';

$manifestPath = __DIR__ . '/../migrations/MANIFEST.json';
$res = bc_manifest_verify_offline($manifestPath);
if (empty($res['ok']) || (int)$res['migrations'] < 15 || (int)$res['paired_helpers'] < 15) {
    throw new RuntimeException('Schema manifest verification returned unexpected result.');
}
echo "PASS schema manifest verified {$res['migrations']} migrations, {$res['paired_helpers']} paired helpers, {$res['tables']} InnoDB tables\n";
