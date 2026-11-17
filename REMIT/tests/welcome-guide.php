<?php
declare(strict_types=1);
ob_start();
require __DIR__ . '/church-portal.php';
ob_end_clean();

// 1. Render welcome-guide.php for ALL roles (admin, treasurer, parish_officer, area_rep, zonal_rep)
$roles = [
    ['id' => 100, 'role' => 'provincial_admin', 'full_name' => 'Admin User', 'email' => 'admin@example.org', 'hierarchy_id' => null, 'rep' => false],
    ['id' => 101, 'role' => 'provincial_treasurer', 'full_name' => 'Treasurer User', 'email' => 'treasurer@example.org', 'hierarchy_id' => null, 'rep' => false],
    ['id' => 1, 'role' => 'parish_officer', 'full_name' => 'Hope Officer', 'email' => 'officer@example.org', 'hierarchy_id' => 1, 'rep' => true],
    ['id' => 2, 'role' => 'area_rep', 'full_name' => 'Area Rep', 'email' => 'area@example.org', 'hierarchy_id' => 1, 'rep' => true],
    ['id' => 3, 'role' => 'zonal_rep', 'full_name' => 'Zonal Rep', 'email' => 'zone@example.org', 'hierarchy_id' => 3, 'rep' => true],
];

foreach ($roles as $case) {
    $user = $case;
    unset($admin); // ensure welcome-guide.php handles missing $admin cleanly
    ob_start();
    require __DIR__ . '/../app/welcome-guide.php';
    $html = ob_get_clean();

    check(str_contains($html, 'data-welcome-guide') && str_contains($html, 'data-guide-user="' . $case['id'] . '"'), "guide launcher rendered for {$case['role']}");
    check(str_contains($html, 'data-guide-open') && str_contains($html, 'aria-controls="quick-guide-modal"') && str_contains($html, 'aria-expanded="false"'), "accessible guide open button rendered for {$case['role']}");
    check(str_contains($html, 'id="quick-guide-modal"') && str_contains($html, 'data-guide-modal') && str_contains($html, 'data-guide-close'), "quick guide dialog rendered for {$case['role']}");

    if ($case['rep']) {
        check(str_contains($html, 'Your monthly remittance, in three steps.') && str_contains($html, 'index.php?page=remittances#offering-report') && str_contains($html, 'Start remittance'), "representative guide content & link rendered for {$case['role']}");
    } else {
        check(str_contains($html, 'A quick start for the treasury.') && str_contains($html, 'href="index.php?page=remittances"') && str_contains($html, 'Review payments') && !str_contains($html, '#offering-report'), "treasury/admin guide content & valid remittances link rendered for {$case['role']}");
    }
}

// 2. Verify JS & CSS contain active guide modal handlers and accessible fallback styles
$js = (string)file_get_contents(__DIR__ . '/../public/assets/app.a9d3eb4d.js');
check(
    str_contains($js, 'quick-guide-modal')
    && str_contains($js, '[data-guide-open]')
    && str_contains($js, '[data-guide-close]')
    && str_contains($js, 'showModal')
    && str_contains($js, 'guide-is-open')
    && str_contains($js, '[data-menu-open]')
    && str_contains($js, 'mobile-menu'),
    'app.a9d3eb4d.js binds quick-guide modal and mobile-menu controllers'
);

$css = (string)file_get_contents(__DIR__ . '/../public/assets/app.f99713ab.css');
check(
    str_contains($css, '.guide-open-button')
    && str_contains($css, '.welcome-guide-modal[open]:not(:modal)')
    && str_contains($css, 'body.guide-is-open{overflow:hidden}')
    && str_contains($css, '.guide-modal-card{background:#fff;border-radius:24px;overflow-y:auto'),
    'app.f99713ab.css styles scrollable guide modal card and fallback dialog positioning'
);

echo "PASS: $checks welcome/quick guide render, JS wiring and CSS assertions\n";
