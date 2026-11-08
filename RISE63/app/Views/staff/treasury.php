<?php
declare(strict_types=1);

use RISE63\Core\Auth;
use RISE63\Core\Money;
use RISE63\Core\Security;

use function RISE63\{e, human_date, relative_time, url};

$canConfirm = Auth::isTreasurer($account);
?>
<section class="card">
  <h1>Treasury</h1>
  <p class="muted">Two people, one ledger: a disbursement is requested, then confirmed with the transaction PIN. Ledger rows can never be edited or deleted.</p>
  <div class="grid grid-3" style="margin-top:12px">
    <div class="stat"><b><?= e($totals['disbursed']->short()) ?></b><span>Disbursed (ledger)</span></div>
    <div class="stat"><b><?= e($totals['pending']->short()) ?></b><span>Awaiting confirmation</span></div>
    <div class="stat"><b><?= e($totals['pool']->short()) ?></b><span>Seed grant pool left</span></div>
  </div>
</section>

<?php if ($pinLock > 0): ?>
  <div class="flash flash-error" role="alert">The transaction PIN is locked for about <?= (int) ceil($pinLock / 60) ?> minute(s) after repeated mistakes.</div>
<?php endif; ?>

<section class="card">
  <h2>Approved, not yet paid</h2>
  <?php if ($approved === []): ?>
    <p class="faint">Nothing is waiting for a disbursement request.</p>
  <?php else: ?>
    <ul class="list-plain">
      <?php foreach ($approved as $row): ?>
        <li>
          <details>
            <summary style="cursor:pointer;min-height:32px">
              <strong><?= e((string) $row['full_name']) ?></strong> ·
              <?= e(\RISE63\Core\Program::short((string) $row['program_type'])) ?> ·
              <?= e(Money::fromDecimal($row['requested_amount'])->format()) ?>
            </summary>
            <form method="post" action="<?= e(url('')) ?>" style="margin-top:12px">
              <?= Security::csrfField() ?>
              <input type="hidden" name="action" value="remittance-request">
              <input type="hidden" name="application_id" value="<?= (int) $row['id'] ?>">
              <input type="hidden" name="return_to" value="treasury">
              <div class="grid grid-2">
                <div class="field">
                  <label for="amount-<?= (int) $row['id'] ?>">Amount (₦)</label>
                  <input type="number" id="amount-<?= (int) $row['id'] ?>" name="amount" min="1" step="100" required
                         value="<?= e($row['requested_amount']) ?>">
                </div>
                <div class="field">
                  <label for="payee-<?= (int) $row['id'] ?>">Payee name</label>
                  <input type="text" id="payee-<?= (int) $row['id'] ?>" name="payee_name" required maxlength="150"
                         value="<?= (string) $row['program_type'] === 'education_bridge' ? '' : e((string) $row['full_name']) ?>"
                         placeholder="<?= (string) $row['program_type'] === 'education_bridge' ? 'School name' : '' ?>">
                </div>
                <div class="field">
                  <label for="bank-<?= (int) $row['id'] ?>">Bank</label>
                  <input type="text" id="bank-<?= (int) $row['id'] ?>" name="bank_name" required maxlength="150">
                </div>
                <div class="field">
                  <label for="acct-<?= (int) $row['id'] ?>">Account number</label>
                  <input type="text" id="acct-<?= (int) $row['id'] ?>" name="account_number" required inputmode="numeric"
                         pattern="[0-9]{10}" maxlength="10">
                </div>
              </div>
              <button class="btn btn-primary" type="submit">Queue disbursement</button>
            </form>
          </details>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>

<section class="card">
  <h2>Awaiting confirmation</h2>
  <?php if ($pending === []): ?>
    <p class="faint">No queued disbursements.</p>
  <?php else: ?>
    <?php foreach ($pending as $row): ?>
      <article class="card card-flat" style="margin-bottom:12px">
        <div class="card-head">
          <div>
            <h3 style="margin:0"><?= e(Money::fromDecimal($row['amount'])->format()) ?></h3>
            <p class="faint" style="margin:2px 0 0">
              <?= e((string) $row['applicant']) ?> · <?= e(\RISE63\Core\Program::short((string) $row['program_type'])) ?> ·
              ref <?= e((string) $row['payment_reference']) ?>
            </p>
          </div>
          <span class="badge badge-warn">Pending</span>
        </div>
        <ul class="list-plain">
          <li class="row row-end"><span class="muted">Payee</span><span><?= e((string) $row['payee_name']) ?></span></li>
          <li class="row row-end"><span class="muted">Bank</span><span><?= e((string) $row['bank_name']) ?></span></li>
          <li class="row row-end"><span class="muted">Account</span><span class="mono"><?= e((string) $row['account_number']) ?></span></li>
          <li class="row row-end"><span class="muted">Requested</span><span><?= e(relative_time((string) $row['created_at'])) ?></span></li>
        </ul>
        <?php if ($canConfirm): ?>
          <form method="post" action="<?= e(url('')) ?>" class="row" style="margin-top:12px;align-items:flex-end">
            <?= Security::csrfField() ?>
            <input type="hidden" name="action" value="remittance-confirm">
            <input type="hidden" name="remittance_id" value="<?= (int) $row['id'] ?>">
            <input type="hidden" name="return_to" value="treasury">
            <div class="field grow" style="margin:0">
              <label for="pin-<?= (int) $row['id'] ?>">Transaction PIN</label>
              <input type="password" id="pin-<?= (int) $row['id'] ?>" name="pin" required inputmode="numeric"
                     pattern="[0-9]{4}" maxlength="4" autocomplete="off">
            </div>
            <button class="btn btn-primary" type="submit">Confirm payment</button>
          </form>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>
  <?php endif; ?>
</section>

<section class="card">
  <h2>Ledger</h2>
  <p class="faint">Append-only. Every balance shown is the ledger's own record at the time of entry.</p>
  <div class="table-wrap">
    <table class="table-stack">
      <thead><tr><th>When</th><th>Reference</th><th>Type</th><th>Program</th><th class="num">Amount</th><th class="num">Balance after</th><th>By</th></tr></thead>
      <tbody>
        <?php foreach ($entries as $entry): ?>
          <tr>
            <td data-label="When"><?= e(human_date((string) $entry['created_at'], true)) ?></td>
            <td data-label="Reference" class="mono"><?= e((string) $entry['reference']) ?></td>
            <td data-label="Type"><?= e(ucfirst((string) $entry['entry_type'])) ?></td>
            <td data-label="Program"><?= e($entry['program_type'] !== null ? \RISE63\Core\Program::short((string) $entry['program_type']) : '—') ?></td>
            <td data-label="Amount" class="num money"><?= e(Money::fromDecimal($entry['amount'])->format()) ?></td>
            <td data-label="Balance after" class="num"><?= e($entry['balance_after'] !== null ? Money::fromDecimal($entry['balance_after'])->format() : '—') ?></td>
            <td data-label="By"><?= e((string) ($entry['actor_name'] ?? 'system')) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if ($entries === []): ?><tr><td colspan="7" class="faint">No ledger entries yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</section>
