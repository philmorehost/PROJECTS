<?php
/**
 * License Manager edition guard — the OTA update server must serve each DGV7.0
 * edition its own package.
 *
 * Covers LM/check-update.php (edition-aware tier picking + license healing),
 * LM/includes/github.php (manifest edition, changelog scoping, publish-time
 * cross-edition refusal) and the admin upload path. The pure helpers are
 * extracted and executed; the endpoint wiring is asserted statically because
 * the endpoint itself needs the manager database.
 *
 * Runs under `php -n` in CI (repo root checkout). Under the local php-wasm
 * harness the LM tree is mirrored next to the VTU tree.
 */

$checks = 0;
$failures = 0;
function lchk($cond, $label) {
    global $checks, $failures;
    $checks++;
    if (!$cond) {
        $failures++;
        echo "FAIL: $label\n";
    }
}

// Locate the LM tree: <repo>/LM in CI, /host/LM under the wasm harness.
$vtRoot = dirname(__DIR__);
$lm = null;
foreach (array($vtRoot . '/LM', dirname($vtRoot) . '/LM') as $cand) {
    if (is_dir($cand)) { $lm = $cand; break; }
}
lchk($lm !== null, 'LM tree is reachable');
if ($lm === null) {
    echo "lm_edition_test: $checks checks, $failures failures\n";
    exit(1);
}
$rd = function ($rel) use ($lm) {
    $c = file_get_contents($lm . '/' . $rel);
    if ($c === false) throw new RuntimeException("cannot read $rel");
    return $c;
};

// ---------------------------------------------------------- check-update.php
$cu = $rd('check-update.php');
lchk(strpos($cu, 'function lm_check_edition_tier') !== false, 'check-update normalises the client edition');
lchk(strpos($cu, "\$_REQUEST['edition']") !== false, 'check-update reads the edition parameter');
lchk(strpos($cu, '$expected_tier = $client_edition;') !== false, 'declared edition feeds the enforced expected_tier');
lchk(strpos($cu, 'UPDATE licenses SET tier_id') !== false, 'NULL-tier licenses are healed to the resolved tier');
lchk(strpos($cu, 'UPPER(tier_code) = ?') !== false, 'fallback resolves tier ids by tier_code, not hardcoded 1/2');
lchk(strpos($cu, "'edition' => \$tier_code") !== false, 'responses report the edition served');
lchk(strpos($cu, '$type_edition') !== false, 'license_type still outranks a conflicting client declaration');

if (preg_match('/function lm_check_edition_tier\(\$raw\) \{.*?\n    \}/s', $cu, $m)) {
    eval($m[0]);
    lchk(lm_check_edition_tier('saas') === 'SAAS', 'edition saas -> SAAS');
    lchk(lm_check_edition_tier('DGV7-SAAS') === 'SAAS', 'edition DGV7-SAAS -> SAAS');
    lchk(lm_check_edition_tier('non-saas') === 'NON-SAAS', 'edition non-saas -> NON-SAAS');
    lchk(lm_check_edition_tier('NON_SAAS') === 'NON-SAAS', 'edition NON_SAAS -> NON-SAAS');
    lchk(lm_check_edition_tier('standalone') === 'NON-SAAS', 'edition standalone -> NON-SAAS');
    lchk(lm_check_edition_tier('') === '', 'no edition declared -> unclassified');
} else {
    lchk(false, 'lm_check_edition_tier is extractable for unit checks');
}

// ------------------------------------------------------------- github.php
$gh = $rd('includes/github.php');
lchk(strpos($gh, "'edition' => strtoupper((string) \$tier['tier_code'])") !== false, 'GitHub manifests declare the edition');
lchk(strpos($gh, '$files = lm_github_filter_changelog_files($files, $selectors);') !== false, 'pull scopes the changelog to the packaged selection');
lchk(strpos($gh, '$editionViolation = lm_github_edition_violation(') !== false, 'pull refuses cross-edition packages before publishing');

if (preg_match('/function lm_github_edition_violation\(\$tierCode, array \$files, array \$repoFiles = array\(\)\)\s*\{.*?\n    \}/s', $gh, $m)) {
    eval($m[0]);
    lchk(lm_github_edition_violation('SAAS', array(), array('VTU/DGV7.0-NON-SAAS/web/x.php')) !== '', 'SAAS project rejects a NON-SAAS folder in the selection');
    lchk(lm_github_edition_violation('NON-SAAS', array(), array('VTU/DGV7.0-SAAS/func/y.php')) !== '', 'NON-SAAS project rejects a SAAS folder in the selection');
    lchk(lm_github_edition_violation('SAAS', array('func/bc-integrity.php')) !== '', 'SAAS package rejects the NON-SAAS integrity file');
    lchk(lm_github_edition_violation('NON-SAAS', array('func/bc-levelup.php')) !== '', 'NON-SAAS package rejects the SAAS levelup file');
    lchk(lm_github_edition_violation('SAAS', array('func/bc-levelup.php'), array('VTU/DGV7.0-SAAS/func/bc-levelup.php')) === '', 'SAAS package accepts its own files');
    lchk(lm_github_edition_violation('NON-SAAS', array('func/bc-integrity.php'), array('VTU/DGV7.0-NON-SAAS/func/bc-integrity.php')) === '', 'NON-SAAS package accepts its own files');
    lchk(lm_github_edition_violation('SMSP-SAAS', array(), array('SMSP/index.php')) === '', 'non-DGV7 edition projects are untouched by folder markers');
    lchk(lm_github_edition_violation('', array('func/bc-integrity.php')) === '', 'non-edition tiers are not edition-guarded');
} else {
    lchk(false, 'lm_github_edition_violation is extractable for unit checks');
}

if (preg_match('/function lm_github_filter_changelog_files\(array \$files, array \$selectors\)\s*\{.*?\n    \}/s', $gh, $m)) {
    eval($m[0]);
    $files = array(
        array('filename' => 'VTU/DGV7.0-SAAS/web/A.php'),
        array('filename' => 'VTU/DGV7.0-NON-SAAS/web/B.php'),
        array('filename' => 'VTU/tests/C.php'),
    );
    $kept = lm_github_filter_changelog_files($files, array('VTU/DGV7.0-SAAS'));
    lchk(count($kept) === 1 && $kept[0]['filename'] === 'VTU/DGV7.0-SAAS/web/A.php', 'changelog keeps only the packaged folder');
    lchk(count(lm_github_filter_changelog_files($files, array())) === 3, 'whole-repo projects keep the full changelog');
} else {
    lchk(false, 'lm_github_filter_changelog_files is extractable for unit checks');
}

// ------------------------------------------------------- admin upload path
$up = $rd('admin/ajax_upload_update.php');
lchk(strpos($up, "\$decoded['edition'] = strtoupper(\$tier['tier_code'])") !== false, 'uploaded manifests are restamped to the target tier');
lchk(strpos($up, "'edition'          => strtoupper(\$tier['tier_code'])") !== false, 'injected manifests declare the edition');
$patch = $rd('admin/patch_zip_manifests.php');
lchk(strpos($patch, "'edition'          => strtoupper((string) \$row['tier_code'])") !== false, 'backfilled manifests declare the edition');

echo "lm_edition_test: $checks checks, $failures failures\n";
exit($failures > 0 ? 1 : 0);
