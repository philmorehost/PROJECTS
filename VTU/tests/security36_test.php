<?php
/** Run: php -n VTU/tests/security36_test.php
 * §36 (2026-11) checks — shell-owned modals must be SELF-HIDING.
 *
 * Bug (screenshot, SAAS spadmin updater): the §32 transaction-receipt
 * modal printed by bc_adm_foot()/bc_app_foot() relied entirely on shell /
 * Bootstrap CSS to stay hidden. Pages that render the foot without the
 * matching head CSS (standalone pages such as the Platform Updater)
 * showed a raw "Transaction Receipt / Loading receipt..." block in the
 * footer, and closeModal() resetting inline display to '' would have
 * re-exposed it after a close on any CSS-less page.
 *
 * Fix: every modal the shells themselves inject carries inline
 * display:none and its JS drives display explicitly (open → flex,
 * close → none), so no page can ever leak it regardless of which
 * stylesheets it loads.
 * Source assertions only; no DB needed.
 */
$root = dirname(__DIR__);
$editions = array('DGV7.0-SAAS', 'DGV7.0-NON-SAAS');

function src36($root, $ed, $rel) {
    $p = $root . '/' . $ed . '/' . $rel;
    if (!is_file($p)) throw new RuntimeException("missing $p");
    return file_get_contents($p);
}

$count = 0;
function chk36($label, $cond) {
    global $count;
    $count++;
    if (!$cond) throw new RuntimeException('security36: ' . $label);
    echo "ok $label\n";
}

foreach (array('func/bc-plate-app.php', 'func/bc-plate-admin.php') as $shared) {
    chk36("$shared identical across editions",
        src36($root, 'DGV7.0-SAAS', $shared) === src36($root, 'DGV7.0-NON-SAAS', $shared));
}

// Every shell-owned modal tag (class="modal" with an id) must self-hide.
foreach ($editions as $ed) {
    foreach (array('func/bc-plate-app.php', 'func/bc-plate-admin.php', 'func/bc-plate-auth.php') as $shell) {
        $s = src36($root, $ed, $shell);
        preg_match_all('/<div class="modal" id="[^"]+"[^>]*>/', $s, $tags);
        foreach ($tags[0] as $tag) {
            chk36("$ed $shell self-hides " . substr($tag, 0, 60) . '...',
                strpos($tag, 'display:none') !== false);
        }
        // The receipt modal specifically (the reported leak).
        if (strpos($s, 'bcReceiptModal') !== false) {
            chk36("$ed $shell receipt modal starts hidden inline",
                strpos($s, 'id="bcReceiptModal" tabindex="-1" aria-hidden="true" style="display:none;z-index:2000;"') !== false);
            chk36("$ed $shell receipt open drives display:flex",
                strpos($s, "el.style.display = 'flex';") !== false);
            chk36("$ed $shell receipt close drives display:none (never '')",
                strpos($s, "el.style.display = 'none';") !== false
                && strpos($s, "el.style.display = '';") === false);
        }
    }
    // Forced-PIN modal (admin shell): shown/hidden by its own script.
    $adm = src36($root, $ed, 'func/bc-plate-admin.php');
    chk36("$ed forcePinModal show path sets display flex",
        strpos($adm, "modalEl.style.display = 'flex';") !== false);
    chk36("$ed forcePinModal hide path sets display none",
        strpos($adm, "modalEl.style.display = 'none';") !== false);
}

// The receipt modal exists in exactly the two shells per edition (no stray
// copies elsewhere that could leak the same way).
foreach ($editions as $ed) {
    $hits = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $ed, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isDir() || substr($file->getFilename(), -4) !== '.php') continue;
        $c = file_get_contents($file->getPathname());
        if (strpos($c, 'bcReceiptModal') !== false) $hits++;
    }
    chk36("$ed bcReceiptModal defined in exactly the two shell files", $hits === 2);
}

echo "security36: $count checks passed\n";
