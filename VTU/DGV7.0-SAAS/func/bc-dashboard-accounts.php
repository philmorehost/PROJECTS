<?php
/**
 * DGV7 phase 2 — dashboard accounts block.
 *
 * Included by web/Dashboard.php above the balance hero. Presentation only: reads the user row the
 * page already loaded, plus the tenant matrix, and renders wallets, the rate strip, the account
 * badge, KYB state and the upgrade trigger.
 *
 * Everything degrades quietly. If the wallet helpers are not present (an older deploy where this
 * partial shipped ahead of func/bc-fintech-wallet.php) the block renders nothing rather than
 * fataling the busiest page in the product.
 */

// Dashboard.php includes bc-config.php only, which does not pull the fintech modules in. Load them
// here so this partial is self-sufficient wherever it is included from.
if (!function_exists('bc_fintech_account_type')) {
    $da_accounts_file = __DIR__ . '/bc-fintech-accounts.php';
    if (file_exists($da_accounts_file)) require_once($da_accounts_file);
}

// Every helper this block needs, checked together: a partial that ships ahead of its dependency
// must render nothing rather than fatal the busiest page in the product.
if (!function_exists('bc_wallet_cards')
    || !function_exists('bc_fintech_tier_badge')
    || !function_exists('bc_fintech_account_type')
    || !function_exists('bc_fintech_kyb_status_label')) return;

if (!isset($get_logged_user_details) || !is_array($get_logged_user_details)) return;

$da_vendor_id = (int)($get_logged_user_details['vendor_id'] ?? 0);
$da_tier      = bc_fintech_account_type($get_logged_user_details);
$da_kyb       = (int)($get_logged_user_details['kyb_status'] ?? 0);

// The only custodied currency today is NGN; the wallet card helper decides what to open.
$da_balances = ['NGN' => $get_logged_user_details['balance'] ?? 0];

// No rate feed is wired until the Phase 3 FX adapter exists, so this reports unavailable and the
// strip prints its note instead of a row of zeroes that would read as real quotations.
$da_cards = bc_wallet_cards($da_balances);
$da_rates = bc_fx_rate_snapshot(null);
$da_badge = bc_fintech_tier_badge($da_tier);

// The upgrade trigger is pointless once a review is open or the account is already business.
$da_show_upgrade = ($da_tier === 'personal' && !in_array($da_kyb, [1, 2], true));
?>
<style>
    .da-wallets { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px; margin-bottom: 18px; }
    .da-wallet {
        background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 18px;
        display: flex; flex-direction: column; gap: 4px;
    }
    .da-wallet--closed { background: #f8fafc; border-style: dashed; }
    .da-wallet__code { font-size: .72rem; font-weight: 700; letter-spacing: .07em; color: #64748b; text-transform: uppercase; }
    .da-wallet__amount { font-size: 1.35rem; font-weight: 800; letter-spacing: -.02em; color: #0b1220; }
    .da-wallet--closed .da-wallet__amount { font-size: .95rem; font-weight: 600; color: #94a3b8; }
    .da-wallet__note { font-size: .75rem; color: #64748b; }

    .da-strip {
        display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
        background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 14px;
        padding: 12px 16px; margin-bottom: 18px; font-size: .85rem; color: #475569;
    }
    .da-strip__label { font-weight: 700; color: #0b1220; }

    .da-tier {
        display: inline-flex; align-items: center; gap: 7px;
        padding: 5px 12px; border-radius: 999px; font-size: .75rem; font-weight: 700;
        text-transform: uppercase; letter-spacing: .05em; margin-bottom: 14px;
    }
    .da-tier--tier-personal { background: #eff6ff; color: #1d4ed8; }
    .da-tier--tier-business { background: #ecfdf5; color: #047857; }
    .da-tier--tier-unknown  { background: #f1f5f9; color: #475569; }

    .da-upgrade {
        display: flex; align-items: center; justify-content: space-between; gap: 16px;
        background: linear-gradient(135deg, #0f766e, #115e59); color: #fff;
        border-radius: 16px; padding: 20px 22px; margin-bottom: 18px; flex-wrap: wrap;
    }
    .da-upgrade h6 { margin: 0 0 4px; font-weight: 700; font-size: 1rem; }
    .da-upgrade p { margin: 0; font-size: .85rem; opacity: .9; }
    .da-upgrade a {
        background: #fff; color: #0f766e; text-decoration: none; font-weight: 700;
        padding: 9px 18px; border-radius: 10px; font-size: .85rem; white-space: nowrap;
    }
    .da-upgrade a:hover { background: #ecfdf5; }

    @media (max-width: 992px) { .da-wallets { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    @media (max-width: 520px) { .da-wallets { grid-template-columns: 1fr; } }
</style>

<div class="da-dashboard-accounts">
    <span class="da-tier da-tier--<?php echo htmlspecialchars($da_badge['class']); ?>">
        <?php echo htmlspecialchars($da_badge['label']); ?>
        <?php if ($da_tier === 'business'): ?>
            &middot; KYB <?php echo htmlspecialchars(bc_fintech_kyb_status_label($da_kyb)); ?>
        <?php endif; ?>
    </span>

    <div class="da-wallets">
        <?php foreach ($da_cards as $card): ?>
            <div class="da-wallet <?php echo $card['open'] ? '' : 'da-wallet--closed'; ?>">
                <span class="da-wallet__code"><?php echo htmlspecialchars($card['code']); ?></span>
                <span class="da-wallet__amount">
                    <?php echo $card['open'] ? htmlspecialchars($card['amount']) : htmlspecialchars($card['note']); ?>
                </span>
                <span class="da-wallet__note"><?php echo htmlspecialchars($card['label']); ?></span>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="da-strip">
        <span class="da-strip__label">Exchange rates</span>
        <?php if (!empty($da_rates['available'])): ?>
            <?php foreach ($da_rates['rates'] as $pair => $rate): ?>
                <span><?php echo htmlspecialchars($pair); ?> <?php echo htmlspecialchars(number_format((float)$rate, 2)); ?></span>
            <?php endforeach; ?>
        <?php else: ?>
            <span><?php echo htmlspecialchars($da_rates['note']); ?></span>
        <?php endif; ?>
    </div>

    <?php if ($da_show_upgrade): ?>
        <div class="da-upgrade">
            <div>
                <h6>Need a business account?</h6>
                <p>Invoices, supplier payments and multi-currency settlement, after a short verification.</p>
            </div>
            <a href="<?php echo htmlspecialchars($web_http_host ?? ''); ?>/web/BusinessUpgrade.php">Upgrade to Business</a>
        </div>
    <?php endif; ?>
</div>
