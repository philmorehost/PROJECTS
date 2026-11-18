<?php
/**
 * DGV7 service control matrix — CONTROLLER half.
 *
 * Handles the posted toggle (with post/redirect/get) and prepares $matrix_services /
 * $matrix_current / $bc_matrix_switch. The markup lives in bc-service-matrix-render.php so a
 * page can include the controller in its PHP preamble and the markup in its body — including a
 * file that echoes HTML from the preamble would emit output before headers are sent.
 *
 * Expects: $connection_server, $matrix_vendor_id, $matrix_return_url
 */


require_once __DIR__ . '/bc-fintech-accounts.php';

// The platform console manages every tenant, so it passes a selector. A chosen vendor is
// validated against sas_vendors before it is used for a write, so a crafted POST cannot target a
// tenant that does not exist.
$matrix_selectable = !empty($matrix_selectable);
if ($matrix_selectable) {
    if (isset($_GET['vendor_id'])) {
        $_SESSION['bc_matrix_vendor'] = (int)$_GET['vendor_id'];
    }
    $matrix_vendor_id = (int)($_SESSION['bc_matrix_vendor'] ?? 0);
    if ($matrix_vendor_id > 0 && $connection_server instanceof mysqli) {
        $mv = mysqli_query($connection_server, "SELECT id FROM sas_vendors WHERE id='$matrix_vendor_id' LIMIT 1");
        if (!$mv || mysqli_num_rows($mv) !== 1) $matrix_vendor_id = 0;
    }
} else {
    $matrix_vendor_id = (int)($matrix_vendor_id ?? 0);
}
$matrix_return_url = (string)($matrix_return_url ?? 'ServiceControl.php');
if ($matrix_selectable && (int)($matrix_vendor_id ?? 0) > 0) {
    $matrix_return_url .= (strpos($matrix_return_url, '?') === false ? '?' : '&') . 'vendor_id=' . (int)$matrix_vendor_id;
}
$matrix_notice = '';

if ($matrix_vendor_id > 0 && isset($_POST['bc_matrix_save'])) {
    $svc = (string)($_POST['matrix_service'] ?? '');
    $field = (string)($_POST['matrix_field'] ?? '');
    $value = (int)($_POST['matrix_value'] ?? 0);
    $result = bc_fintech_matrix_set($matrix_vendor_id, $svc, $field, $value);
    // Dedicated flash key: the shared product_purchase_response is used by the surrounding
    // ServiceControl pages for gateway messages and must not be consumed by this card.
    $_SESSION['bc_matrix_flash'] = ($result === true) ? 'Service control updated.' : (string)$result;
    $matrix_notice = (string)$_SESSION['bc_matrix_flash'];
    if (!headers_sent()) {
        // Post/redirect/get so a refresh does not re-submit a toggle.
        header('Location: ' . $matrix_return_url);
        exit;
    }
}

$matrix_services = bc_fintech_matrix_services();
$matrix_current = $matrix_vendor_id > 0 ? bc_fintech_matrix_read($matrix_vendor_id) : [];

/**
 * Renders one three-state control for a tier. Disabled when the tier is locked off.
 */
$bc_matrix_switch = function ($service, $field, $enabled, $locked, $label) use ($matrix_return_url) {
    $checked = $enabled ? 'checked' : '';
    $disabled = $locked ? 'disabled' : '';
    $lock_note = $locked ? ' title="Locked off for this tier by policy"' : '';
    return '<form method="post" action="" class="d-inline">'
        . '<input type="hidden" name="bc_matrix_save" value="1">'
        . '<input type="hidden" name="matrix_service" value="' . htmlspecialchars($service) . '">'
        . '<input type="hidden" name="matrix_field" value="' . htmlspecialchars($field) . '">'
        . '<input type="hidden" name="matrix_value" value="' . ($enabled ? '0' : '1') . '">'
        . '<div class="form-check form-switch mb-0"' . $lock_note . '>'
        . '<input class="form-check-input" type="checkbox" role="switch" ' . $checked . ' ' . $disabled
        . ' aria-label="' . htmlspecialchars($label) . '" onchange="this.form.submit()">'
        . '</div></form>';
};
