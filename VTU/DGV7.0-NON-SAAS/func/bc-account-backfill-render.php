<?php
/**
 * DGV7 account_type backfill — VIEW half. Included from the page body; bc-account-backfill.php
 * must already have run in the preamble.
 */
if (!isset($bc_backfill_counts)) return;
if (($bc_backfill_vendor ?? 0) <= 0) return;
?>
<?php if ($bc_backfill_flash !== ''): ?>
<div class="alert alert-info py-2 small mt-4 mb-0" role="alert"><?php echo htmlspecialchars($bc_backfill_flash); ?></div>
<?php endif; ?>
<div class="card shadow-sm border-0 rounded-4 mt-4">
    <div class="card-header bg-white py-3 border-0">
        <h5 class="fw-bold mb-0 text-primary"><i class="bi bi-database-check me-2"></i>Account Type Backfill</h5>
        <p class="small text-muted mb-0">
            Copies the legacy <code>account_level</code> into the new <code>account_type</code> column.
            Levels 1–2 become <strong>personal</strong>, level 3 becomes <strong>business</strong>.
            The legacy column is left in place for pricing, and only accounts with no type yet are touched,
            so running this again changes nothing.
        </p>
    </div>
    <div class="card-body">
        <div class="row g-3 text-center mb-3">
            <div class="col-6 col-md-3">
                <div class="border rounded-3 py-3">
                    <div class="h4 fw-bold mb-0"><?php echo (int)$bc_backfill_counts['personal']; ?></div>
                    <div class="small text-muted">Will become personal</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="border rounded-3 py-3">
                    <div class="h4 fw-bold mb-0"><?php echo (int)$bc_backfill_counts['business']; ?></div>
                    <div class="small text-muted">Will become business</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="border rounded-3 py-3">
                    <div class="h4 fw-bold mb-0"><?php echo (int)$bc_backfill_counts['already_set']; ?></div>
                    <div class="small text-muted">Already classified</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="border rounded-3 py-3">
                    <div class="h4 fw-bold mb-0 text-warning"><?php echo (int)$bc_backfill_counts['unknown_level']; ?></div>
                    <div class="small text-muted">Unknown level (left alone)</div>
                </div>
            </div>
        </div>

        <?php if ((int)$bc_backfill_counts['invalid_tier'] > 0): ?>
            <div class="alert alert-warning py-2 small">
                <?php echo (int)$bc_backfill_counts['invalid_tier']; ?> account(s) carry an unrecognised
                <code>account_type</code>. They are not modified - fix them individually.
            </div>
        <?php endif; ?>

        <form method="post" action="" class="d-flex align-items-center gap-3 flex-wrap">
            <?php if ($bc_backfill_total > 0): ?>
                <div class="form-check mb-0">
                    <input class="form-check-input" type="checkbox" value="1" id="bcBackfillConfirm" name="bc_backfill_confirm" required>
                    <label class="form-check-label small" for="bcBackfillConfirm">
                        I have reviewed the counts above and want to write them.
                    </label>
                </div>
                <button name="bc_run_backfill" value="1" class="btn btn-sm btn-primary rounded-pill px-3 fw-bold">
                    Run backfill
                </button>
            <?php else: ?>
                <button name="bc_run_backfill" value="1" class="btn btn-sm btn-outline-secondary rounded-pill px-3" disabled>
                    Nothing to backfill
                </button>
                <span class="small text-muted">Every account in this tenant already has a tier.</span>
            <?php endif; ?>
        </form>
    </div>
</div>
