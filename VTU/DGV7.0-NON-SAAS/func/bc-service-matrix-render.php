<?php
/**
 * DGV7 service control matrix — VIEW half. Included from the page body, after
 * bc-service-matrix.php has prepared the data. See that file for the rules.
 */
if (!isset($matrix_services, $bc_matrix_switch)) return;

// The controller redirects here after a toggle (post/redirect/get); show its outcome once.
$bc_matrix_flash = '';
if (!empty($_SESSION['bc_matrix_flash'])) {
    $bc_matrix_flash = (string)$_SESSION['bc_matrix_flash'];
    unset($_SESSION['bc_matrix_flash']);
}
if ($bc_matrix_flash === '' && !empty($matrix_notice)) $bc_matrix_flash = (string)$matrix_notice;
?>
<?php if ($bc_matrix_flash !== ''): ?>
<div class="alert alert-info py-2 small mt-4 mb-0" role="alert"><?php echo htmlspecialchars($bc_matrix_flash); ?></div>
<?php endif; ?>
<div class="card shadow-sm border-0 rounded-4 mt-4">
    <div class="card-header bg-white py-3 border-0">
        <h5 class="fw-bold mb-0 text-primary"><i class="bi bi-diagram-3 me-2"></i>Personal / Business Service Matrix</h5>
        <p class="small text-muted mb-0">
            Controls which services each account tier may use. <strong>Status</strong> is the existing
            vendor switch. <strong>Provider ready</strong> must be on before a new fintech service can be
            used at all. <strong>Developer API is permanently off for personal accounts.</strong>
        </p>
    </div>
    <div class="card-body p-0">
        <?php if (!empty($matrix_selectable)): ?>
        <form method="get" action="" class="p-3 border-bottom d-flex align-items-end gap-2 flex-wrap">
            <div>
                <label class="form-label small fw-bold text-muted mb-1" for="bc-matrix-vendor">TENANT</label>
                <select id="bc-matrix-vendor" name="vendor_id" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="0">— select a tenant —</option>
                    <?php
                    global $connection_server;
                    $bc_matrix_vendors = [];
                    if ($connection_server instanceof mysqli) {
                        $vq = mysqli_query($connection_server, "SELECT id, website_url, email FROM sas_vendors ORDER BY id ASC LIMIT 500");
                        if ($vq) { while ($vr = mysqli_fetch_assoc($vq)) $bc_matrix_vendors[] = $vr; }
                    }
                    foreach ($bc_matrix_vendors as $vr):
                        $vid_opt = (int)$vr['id'];
                        $label_opt = trim((string)($vr['website_url'] ?? '')) ?: (trim((string)($vr['email'] ?? '')) ?: 'Vendor #' . $vid_opt);
                    ?>
                        <option value="<?php echo $vid_opt; ?>" <?php echo $vid_opt === (int)$matrix_vendor_id ? 'selected' : ''; ?>>
                            #<?php echo $vid_opt; ?> — <?php echo htmlspecialchars($label_opt); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="small text-muted pb-1">Switching the tenant reloads the matrix for that account.</div>
        </form>
        <?php endif; ?>

        <?php if ($matrix_vendor_id <= 0): ?>
            <div class="p-4 text-muted small">
                <?php echo !empty($matrix_selectable) ? 'Choose a tenant to edit its service matrix.' : 'No tenant context for this console.'; ?>
            </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th scope="col" class="ps-4">Service</th>
                        <th scope="col" class="text-center">Status</th>
                        <th scope="col" class="text-center">Personal</th>
                        <th scope="col" class="text-center">Business</th>
                        <th scope="col" class="text-center pe-4">Provider ready</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($matrix_services as $svc => $svc_label):
                    $row = $matrix_current[$svc] ?? ['status' => 1, 'personal_enabled' => 1, 'business_enabled' => 1, 'provider_ready' => 0];
                    $row['service_name'] = $svc;
                    $personal_locked = bc_fintech_service_locked_off($svc, 'personal');
                ?>
                    <tr>
                        <td class="ps-4">
                            <div class="fw-bold small"><?php echo htmlspecialchars($svc_label); ?></div>
                            <code class="small text-muted"><?php echo htmlspecialchars($svc); ?></code>
                            <div class="mt-1">
                                <?php if (bc_fintech_tier_effective($row, 'personal')): ?>
                                    <span class="badge bg-success bg-opacity-10 text-success" style="font-size:.65rem;">Personal: live</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary bg-opacity-10 text-secondary" style="font-size:.65rem;">Personal: off</span>
                                <?php endif; ?>
                                <?php if (bc_fintech_tier_effective($row, 'business')): ?>
                                    <span class="badge bg-success bg-opacity-10 text-success" style="font-size:.65rem;">Business: live</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary bg-opacity-10 text-secondary" style="font-size:.65rem;">Business: off</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="text-center">
                            <?php echo $bc_matrix_switch($svc, 'status', (bool)$row['status'], false, 'Status for ' . $svc_label); ?>
                        </td>
                        <td class="text-center">
                            <?php echo $bc_matrix_switch($svc, 'personal_enabled', (bool)$row['personal_enabled'], $personal_locked, 'Personal access to ' . $svc_label); ?>
                            <?php if ($personal_locked): ?>
                                <div><span class="badge bg-dark bg-opacity-10 text-dark" style="font-size:.6rem;">locked</span></div>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php echo $bc_matrix_switch($svc, 'business_enabled', (bool)$row['business_enabled'], false, 'Business access to ' . $svc_label); ?>
                        </td>
                        <td class="text-center pe-4">
                            <?php echo $bc_matrix_switch($svc, 'provider_ready', (bool)$row['provider_ready'], false, 'Provider readiness for ' . $svc_label); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="p-3 border-top small text-muted">
            A service is usable by a tier only when <em>Status</em>, <em>that tier</em> and
            <em>Provider ready</em> are all on. New fintech services ship with provider readiness
            off, so nothing goes live until a provider is actually contracted and tested.
        </div>
        <?php endif; ?>
    </div>
</div>
