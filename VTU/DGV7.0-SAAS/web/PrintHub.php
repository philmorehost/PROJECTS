<?php session_start();
include("../func/bc-config.php");

if(!isServiceEnabled('data_card')){
    $_SESSION["product_purchase_response"] = "Print Hub service is currently unavailable.";
    header("Location: Dashboard.php");
    exit();
}
?>
<?php
// Plate app shell: one shared chrome for the signed-in console.
include_once(__DIR__ . "/../func/bc-plate-app.php");
bc_app_head("PrintHub | " . ($get_all_site_details["site_title"]));
?>
<?php bc_app_sidebar(); ?>
<?php bc_app_topbar(array("title" => "PRINTHUB", "crumb" => "Home / PrintHub")); ?>

<div class="pk-hub__hero">
    <h1><i class="bi bi-printer" aria-hidden="true"></i> Digital Vending Center</h1>
    <p>Generate and print top-up cards for every VTU service. Quick, easy, and professional.</p>
    <p style="margin-top:14px;">
        <a href="DataBundleCardHistory.php" class="btn btn-light rounded-pill px-4">
            <i class="bi bi-clock-history me-2" aria-hidden="true"></i>Printing History
        </a>
    </p>
</div>

<div class="pk-matrix">
    <?php if(isServiceEnabled('print_airtime')): ?>
    <a class="pk-matrix__card" href="DataBundleCard.php?type=airtime">
        <span class="pk-matrix__icon"><i class="bi bi-phone" aria-hidden="true"></i></span>
        <span class="pk-matrix__label">Print Airtime<small style="display:block;font-weight:500;opacity:.65;">Recharge cards</small></span>
        <span class="pk-matrix__go"><i class="bi bi-arrow-right-circle" aria-hidden="true"></i></span>
    </a>
    <?php endif; ?>
    <?php if(isServiceEnabled('print_data')): ?>
    <a class="pk-matrix__card" href="DataBundleCard.php?type=data">
        <span class="pk-matrix__icon"><i class="bi bi-wifi" aria-hidden="true"></i></span>
        <span class="pk-matrix__label">Print Data<small style="display:block;font-weight:500;opacity:.65;">Internet bundles</small></span>
        <span class="pk-matrix__go"><i class="bi bi-arrow-right-circle" aria-hidden="true"></i></span>
    </a>
    <?php endif; ?>
    <?php if(isServiceEnabled('print_cable')): ?>
    <a class="pk-matrix__card" href="DataBundleCard.php?type=cable">
        <span class="pk-matrix__icon"><i class="bi bi-tv" aria-hidden="true"></i></span>
        <span class="pk-matrix__label">Print Cable<small style="display:block;font-weight:500;opacity:.65;">TV subscriptions</small></span>
        <span class="pk-matrix__go"><i class="bi bi-arrow-right-circle" aria-hidden="true"></i></span>
    </a>
    <?php endif; ?>
    <?php if(isServiceEnabled('print_electric')): ?>
    <a class="pk-matrix__card" href="DataBundleCard.php?type=electric">
        <span class="pk-matrix__icon"><i class="bi bi-lightbulb" aria-hidden="true"></i></span>
        <span class="pk-matrix__label">Print Electric<small style="display:block;font-weight:500;opacity:.65;">Utility tokens</small></span>
        <span class="pk-matrix__go"><i class="bi bi-arrow-right-circle" aria-hidden="true"></i></span>
    </a>
    <?php endif; ?>
    <?php if(isServiceEnabled('print_exam')): ?>
    <a class="pk-matrix__card" href="DataBundleCard.php?type=exam">
        <span class="pk-matrix__icon"><i class="bi bi-card-checklist" aria-hidden="true"></i></span>
        <span class="pk-matrix__label">Print Exam<small style="display:block;font-weight:500;opacity:.65;">WAEC/NECO pins</small></span>
        <span class="pk-matrix__go"><i class="bi bi-arrow-right-circle" aria-hidden="true"></i></span>
    </a>
    <?php endif; ?>
    <?php if(isServiceEnabled('print_betting')): ?>
    <a class="pk-matrix__card" href="DataBundleCard.php?type=betting">
        <span class="pk-matrix__icon"><i class="bi bi-trophy" aria-hidden="true"></i></span>
        <span class="pk-matrix__label">Print Betting<small style="display:block;font-weight:500;opacity:.65;">Wallet funding</small></span>
        <span class="pk-matrix__go"><i class="bi bi-arrow-right-circle" aria-hidden="true"></i></span>
    </a>
    <?php endif; ?>
    <?php /* §28: NIN Slip and BVN Verify live under Verify & Print only. They were
             duplicated here (enabled + locked variants); PrintHub is printing. */ ?>
</div>

<?php bc_app_foot(); ?>
