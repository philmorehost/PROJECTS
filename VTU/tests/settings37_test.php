<?php
/**
 * §37 — admin settings layout.
 *
 * The settings pages (both editions + the SAAS platform settings) build a
 * Bootstrap-style split the compat layer never defined: col-lg-3/col-lg-9
 * had NO width rules at all, and .nav never got display:flex, so tab strips
 * stacked as block lists, the sticky rail could overlay content, and cards
 * squeezed or wrapped unpredictably (worst on mobile).
 */
chdir(dirname(__DIR__));
$fail = 0;
function chk($cond, $label) { global $fail; echo ($cond ? 'PASS ' : 'FAIL ') . $label . PHP_EOL; if (!$cond) $fail++; }

$ADMIN    = ['SAAS' => 'DGV7.0-SAAS', 'NON-SAAS' => 'DGV7.0-NON-SAAS'];
$settings = [
    'SAAS'     => ['bc-admin/AccountSettings.php', 'bc-spadmin/AccountSettings.php'],
    'NON-SAAS' => ['bc-admin/AccountSettings.php'],
];

foreach ($ADMIN as $name => $root) {
    $app  = file_get_contents($root . '/func/bc-plate-app.php');
    $adm  = file_get_contents($root . '/func/bc-plate-admin.php');

    /* --- grid completion ------------------------------------------------ */
    foreach (['col-md-2', 'col-md-9', 'col-lg-3', 'col-lg-9', 'col-lg-6', 'col-lg-12'] as $cls) {
        chk(strpos($app, '.pk-app__wrap .' . $cls . ' ') !== false, "$name: compat grid defines .$cls");
    }
    $posNew = strpos($app, '.pk-app__wrap .col-lg-3 ');
    $posOld = strpos($app, '.pk-app__wrap .col-6 ');
    chk($posNew !== false && $posOld !== false && $posNew < $posOld,
        "$name: new col-lg rules precede .col-6 (combined col-6 col-lg-3 cards keep today's winner)");

    /* --- nav flex -------------------------------------------------------- */
    chk(strpos($app, '.pk-app__wrap .nav { display: flex;') !== false, "$name: .nav is a flex strip");
    chk(stripos($app, '.pk-app__wrap .nav-tabs { flex-wrap: nowrap; overflow-x: auto;') !== false,
        "$name: tab strips scroll horizontally instead of stacking/wrapping");

    /* --- settings split + sticky safety ---------------------------------- */
    chk(strpos($adm, '.pk-app__wrap .sticky-top { position: sticky;') !== false, "$name: .sticky-top actually sticks");
    chk(strpos($adm, '.pk-settings .col-lg-3 { flex: 0 0 calc(25%') !== false, "$name: settings rail is exactly 25% on desktop");
    chk(strpos($adm, '.pk-settings .col-lg-9 { flex: 0 0 calc(75%') !== false, "$name: settings content is exactly 75% on desktop");
    chk(strpos($adm, '.pk-settings .col-lg-3, .pk-app__wrap .pk-settings .col-lg-9 { flex: 1 1 100%') !== false,
        "$name: settings rail/content stack full-width on mobile");
    chk(strpos($adm, '.card:has(.pk-settings-nav) { position: static !important; }') !== false,
        "$name: rail card unpinned on mobile (cannot overlay settings)");

    /* --- page markup contracts ------------------------------------------- */
    foreach ($settings[$name] as $rel) {
        $page = file_get_contents($root . '/' . $rel);
        chk(strpos($page, '<section class="section dashboard pk-settings">') !== false, "$name $rel: carries the pk-settings scope");
        chk(strpos($page, 'col-lg-3') !== false && strpos($page, 'col-lg-9') !== false, "$name $rel: uses the rail/content split");
        chk(strpos($page, 'pk-settings-nav') !== false, "$name $rel: renders the settings nav rail");
    }
}

/* Byte-identical shell files stay required. */
foreach (['bc-plate-app.php', 'bc-plate-admin.php'] as $f) {
    chk(file_get_contents('DGV7.0-SAAS/func/' . $f) === file_get_contents('DGV7.0-NON-SAAS/func/' . $f),
        "plate $f byte-identical across editions");
}

echo PHP_EOL . ($fail === 0 ? 'settings37: all checks passed' : "settings37: $fail FAILURES") . PHP_EOL;
exit($fail === 0 ? 0 : 1);
