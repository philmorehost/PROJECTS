<?php
/** Run: php -n VTU/tests/dashboard_vitals_test.php
 * Admin-dashboard regression guards (2026-11):
 *  - the shared bc-admin/Dashboard.php calls bc_sql_successful_deposit() /
 *    bc_sql_successful_sale() for its hero metrics; those predicates must
 *    exist in BOTH editions' func/bc-func.php (their absence in NON-SAAS
 *    was a fatal that truncated the standalone dashboard after the first
 *    metric — "missing all vitals");
 *  - the standalone hero has no Add Funds CTA (no platform wallet to fund)
 *    and its subscription card does not link to the SAAS-only
 *    RenewSubscription.php;
 *  - the SAAS add-on store card is edition-gated;
 *  - the topbar Portal control is a real button (`.pk-app__action` has a
 *    button style with no underline), not a bare text link.
 * Static source assertions only; no DB needed.
 */
$root = dirname(__DIR__);
$editions = array('DGV7.0-SAAS', 'DGV7.0-NON-SAAS');
$count = 0;
function dv_check($label, $cond) {
    global $count;
    $count++;
    if (!$cond) throw new RuntimeException('dashboard vitals: ' . $label);
    echo "ok $label\n";
}
function dv_src($root, $ed, $rel) { return file_get_contents($root . '/' . $ed . '/' . $rel); }

// shared files stay byte-identical between the trees
foreach (array('bc-admin/Dashboard.php', 'func/bc-plate-admin.php') as $rel) {
    dv_check("$rel is edition-identical",
        file_get_contents($root . '/DGV7.0-SAAS/' . $rel) === file_get_contents($root . '/DGV7.0-NON-SAAS/' . $rel));
}

foreach ($editions as $ed) {
    $func = dv_src($root, $ed, 'func/bc-func.php');
    dv_check("$ed bc-func defines the sale predicate", strpos($func, 'function bc_sql_successful_sale(') !== false);
    dv_check("$ed bc-func defines the deposit predicate", strpos($func, 'function bc_sql_successful_deposit(') !== false);
    dv_check("$ed sale predicate reads the ledger direction", strpos($func, 'balance_after < ') !== false || strpos($func, 'balance_after <') !== false);
    dv_check("$ed deposit predicate reads the ledger direction", strpos($func, 'balance_after >') !== false);
    dv_check("$ed predicates exclude wallet funding from sales", strpos($func, "'wallet_funding','manual_funding','wallet_credit'") !== false);

    $dash = dv_src($root, $ed, 'bc-admin/Dashboard.php');
    dv_check("$ed dashboard stats use the deposit predicate", strpos($dash, 'bc_sql_successful_deposit()') !== false);
    dv_check("$ed dashboard stats use the sale predicate", strpos($dash, 'bc_sql_successful_sale()') !== false);
    dv_check("$ed dashboard loads the edition marker", strpos($dash, 'bc_is_saas_edition') !== false);
    // Add Funds survives exactly once, inside the SAAS branch
    dv_check("$ed dashboard has one Add Funds CTA", substr_count($dash, '>Add Funds</a>') === 1);
    $fund_pos = strpos($dash, 'href="Fund.php"');
    $gate_pos = strrpos(substr($dash, 0, $fund_pos), 'if ($bc_is_saas_ed)');
    dv_check("$ed Add Funds is SAAS-gated", $fund_pos !== false && $gate_pos !== false && ($fund_pos - $gate_pos) < 400);
    // standalone subscription CTA falls back to the portal snapshot
    dv_check("$ed dashboard has the standalone subscription fallback", strpos($dash, 'View Subscription') !== false);
    dv_check("$ed RenewSubscription link is SAAS-gated", substr_count($dash, 'RenewSubscription.php') === 1);
    // the add-on store is a SAAS surface
    $addon_pos = strpos($dash, 'Mobile App Addons');
    $addon_gate = strrpos(substr($dash, 0, $addon_pos), 'if ($bc_is_saas_ed)');
    dv_check("$ed add-on store is SAAS-gated", $addon_pos !== false && $addon_gate !== false && ($addon_pos - $addon_gate) < 400);
    // premium hero pieces are in place
    dv_check("$ed dashboard hero is v4", strpos($dash, 'fintech redesign v4') !== false);
    dv_check("$ed dashboard metrics carry icon chips", substr_count($dash, 'ad2-metric__ic') >= 4);

    // topbar: Portal is a button-styled control, never an underlined link
    $plate = dv_src($root, $ed, 'func/bc-plate-admin.php');
    dv_check("$ed topbar Portal keeps its button class", strpos($plate, 'class="pk-app__action"') !== false);
    dv_check("$ed .pk-app__action has a button style", strpos($plate, '.pk-app__action {') !== false);
    dv_check("$ed .pk-app__action is pill-shaped", strpos($plate, 'border-radius: 999px') !== false);
    dv_check("$ed .pk-app__action never underlines", strpos($plate, 'text-decoration: none !important') !== false);
    dv_check("$ed .pk-app__action uses the brand fill", strpos($plate, 'background: var(--brand)') !== false);

    // Auto-Funding card horizontal frame & plate-app scroll/border utilities
    $app_plate = dv_src($root, $ed, 'func/bc-plate-app.php');
    dv_check("$ed .pk-app__main bounds width with min-width: 0", strpos($app_plate, '.pk-app__main {') !== false && strpos($app_plate, 'min-width: 0; max-width: 100%;') !== false);
    dv_check("$ed plate-app defines .overflow-auto", strpos($app_plate, '.pk-app__wrap .overflow-auto {') !== false);
    dv_check("$ed plate-app defines .border-top", strpos($app_plate, '.pk-app__wrap .border-top {') !== false);
    dv_check("$ed plate-app defines .text-truncate", strpos($app_plate, '.pk-app__wrap .text-truncate {') !== false);
    dv_check("$ed dashboard Auto-Funding uses bounded .ad2-bank-frame and .ad2-bank-track",
        strpos($dash, 'class="ad2-bank-frame"') !== false && strpos($dash, 'class="ad2-bank-track"') !== false);
    dv_check("$ed dashboard .ad2-bank-track scrolls horizontally inside frame",
        strpos($dash, 'overflow-x: auto; overflow-y: hidden;') !== false && strpos($dash, 'flex: 0 0 min(290px, 84%)') !== false);
}

echo "$count dashboard vitals checks passed\n";
