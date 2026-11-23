<?php
require_once __DIR__ . '/../func/bc-secure-session.php';
 bc_secure_session_start();
    include("../func/bc-spadmin-config.php");

    $user_id_number = mysqli_real_escape_string($connection_server, preg_replace("/[^0-9]+/", "", trim(strip_tags(($_GET["userID"] ?? "")))));
    $select_user = mysqli_query($connection_server, "SELECT u.*, v.website_url FROM sas_users u LEFT JOIN sas_vendors v ON u.vendor_id = v.id WHERE u.id='$user_id_number'");
    if($select_user && mysqli_num_rows($select_user) > 0){
        $get_user_details = mysqli_fetch_array($select_user);
    }

    require_once __DIR__.'/../func/bc-complimentary-upgrade.php';
    require_once __DIR__.'/../func/bc-admin-inline-otp.php';
    bc_admin_inline_otp_dispatch($get_logged_spadmin_details,true,'complimentary_upgrade');
    if(isset($_POST['upgrade-level'])){
        $_SESSION['product_purchase_response']=bc_complimentary_upgrade($get_logged_spadmin_details,(int)$user_id_number,$_POST);
        header('Location: UserUpgrade.php?userID='.(int)$user_id_number);exit;
    }

?>
<?php include_once(__DIR__ . "/../func/bc-plate-admin.php");
bc_adm_head("Upgrade User | Super Admin", array("area" => "spadmin"));
?>
<?php bc_adm_sidebar(array("area" => "spadmin")); ?>
<?php bc_adm_topbar(array("area" => "spadmin", "title" => "USER UPGRADE (@" . ($get_user_details['username'] ?? 'Unknown') . ")", "crumb" => "Home / Users / Upgrade")); ?>

    <section class="section dashboard">
      <div class="row justify-content-center">
        <div class="col-lg-5 col-md-8">
            <?php if(!empty($get_user_details['id'])){ ?>
                <div class="card shadow-sm border-0 rounded-4 overflow-hidden p-4">
                    <div class="text-center mb-4">
                        <div class="bg-primary bg-opacity-10 p-4 rounded-circle d-inline-block mb-3">
                            <i class="bi bi-arrow-up-circle text-dark-primary display-4"></i>
                        </div>
                        <h4 class="fw-bold mb-1"><?php echo strtoupper($get_user_details['username']); ?></h4>
                        <p class="small text-muted mb-0">Current Level: <span class="badge bg-light text-dark fw-bold border"><?php echo accountLevel($get_user_details['account_level']); ?></span></p>
                    </div>

                    <form method="post">
                        <?php echo bc_csrf_field(); $nonce=bc_complimentary_nonce($get_logged_spadmin_details,$get_user_details); ?>
                        <input type="hidden" name="upgrade_nonce" value="<?php echo htmlspecialchars($nonce,ENT_QUOTES,'UTF-8'); ?>">
                        <label>Security PIN<input class="form-control" type="password" name="sec_pin" required autocomplete="off"></label>
                        <label>Verification code<input class="form-control" name="sec_factor" pattern="[0-9]{6}" inputmode="numeric" autocomplete="one-time-code"></label>
                        <select class="form-select" name="factor_method"><option value="auto">Preferred enabled factor</option><option value="email">Email OTP</option></select>
                        <button type="button" name="sec33_send_code" class="btn btn-outline-secondary my-2">Send code</button>
                        <div class="mb-4">
                            <label class="form-label small fw-bold text-muted text-uppercase mb-2">Target Account Level</label>
                            <select name="upgrade-type" class="form-select" required>
                                <option value="" default hidden selected>Select new level...</option>
                                <option value="smart" <?php echo ($get_user_details['account_level'] == 1) ? 'selected' : ''; ?>>Smart Earner</option>
                                <option value="agent" <?php echo ($get_user_details['account_level'] == 2) ? 'selected' : ''; ?>>Agent Vendor</option>
                                <option value="api" <?php echo ($get_user_details['account_level'] == 3) ? 'selected' : ''; ?>>API Vendor</option>
                            </select>
                        </div>

                        <div class="alert alert-warning border-0 rounded-3 small mb-4">
                            Note: Super Admin upgrades bypass payment. No wallet deduction will occur for this user.
                        </div>

                        <button name="upgrade-level" type="submit" class="btn btn-primary btn-lg w-100 rounded-3 py-3 fw-bold">
                            APPLY UPGRADE
                        </button>
                    </form>

                    <div class="text-center mt-4">
                        <a href="UserEdit.php?userID=<?php echo $user_id_number; ?>" class="btn btn-link btn-sm text-decoration-none text-muted">Back to Profile Edit</a>
                    </div>
                </div>
            <?php } ?>
        </div>
      </div>
    </section>
    


<?php bc_adm_foot(array("area" => "spadmin")); ?>

<script src="../asset/bc-admin-inline-otp.js" defer></script>
