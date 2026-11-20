<?php session_start();
    include("../func/bc-spadmin-config.php");
         
    // §30: platform funding moves other people's money — it now takes CSRF +
    // the super admin's Security PIN + authenticator/email code (step-up).
    if(isset($_POST["send_stepup_code"])){
        if (function_exists('bc_validate_csrf')) bc_validate_csrf();
        $send_err = function_exists('bc_stepup_send_email_otp')
            ? bc_stepup_send_email_otp(0, (string)$get_logged_spadmin_details['email'], (string)$get_logged_spadmin_details['email'], 'share_fund', 'Platform Funding')
            : 'Verification is unavailable.';
        $_SESSION["product_purchase_response"] = $send_err === '' ? "Verification code sent to your email." : "Error: $send_err";
        header("Location: ShareFund.php");
        exit();
    }

    if(isset($_POST["share-fund"])){
        if (function_exists('bc_validate_csrf')) bc_validate_csrf();
        $sec30_err = function_exists('bc_stepup_spadmin_verify')
            ? bc_stepup_spadmin_verify($get_logged_spadmin_details, $_POST, 'share_fund')
            : 'Verification is unavailable.';
        if ($sec30_err !== '') {
            $_SESSION["product_purchase_response"] = "Error: " . $sec30_err;
            header("Location: ShareFund.php");
            exit();
        }
        $target = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["target"] ?? "vendor")));
        $type = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_POST["type"]))));
        $amount = mysqli_real_escape_string($connection_server, preg_replace("/[^0-9.]+/","",trim(strip_tags($_POST["amount"]))));
        $vid = isset($_POST["vid"]) ? (int)$_POST["vid"] : 0;
        $identifier = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_POST["identifier"]))));

        $discounted_amount = $amount;
        $reference = substr(str_shuffle("12345678901234567890"), 0, 15);
        $description = ucwords("account ".$type."ed by super admin");
        $transType = (in_array($type, array("debit"))) ? "debit" : "credit";
        
        $response = "failed";

        if($target === "user" && $vid > 0){
            // Super Admin Funding a Vendor's User
            // We need to bypass resolveVendorID() check or set a global override
            $GLOBALS['vendor_id'] = $vid;
            $response = chargeOtherUser($identifier, $transType, $identifier, ucwords("wallet ".$type), $reference, "", $amount, $discounted_amount, $description, "WEB", $_SERVER["HTTP_HOST"], "1");
        } else {
            // Funding a Vendor
            $response = chargeOtherVendor($identifier, $transType, $identifier, ucwords("wallet ".$type), $reference, $amount, $discounted_amount, $description, $_SERVER["HTTP_HOST"], "1");
        }

        if($response === "success"){
            if (function_exists('bc_sec_audit')) bc_sec_audit(0, 'spadmin', (string)$get_logged_spadmin_details["email"], 'wallet_' . $transType, (string)$identifier, ucwords($type) . ' of N' . $amount . ' to ' . $target . ($target === "user" ? ' (vendor #' . $vid . ')' : '') . ' (ref ' . $reference . ')');
            $_SESSION["product_purchase_response"] = ucwords($identifier." ".$type."ed with N".$amount." successfully");
        } else {
            $_SESSION["product_purchase_response"] = "Transaction Failed: Check balance or account status.";
        }
        header("Location: ".$_SERVER["REQUEST_URI"]);
        exit();
    }
?>
<?php include_once(__DIR__ . "/../func/bc-plate-admin.php");
bc_adm_head("Super Fund Management | Super Admin", array("area" => "spadmin"));
?>
<style data-pkx="page-redesign">
/* Modern fintech redesign layer (2026-11) */
.pk-app__wrap .card{border-radius:20px}
.pk-app__wrap .card-header{background:linear-gradient(135deg,color-mix(in srgb,var(--brand) 10%,var(--surface)),var(--surface));font-weight:800}
.pk-app__wrap .table thead th{letter-spacing:.04em;text-transform:uppercase;font-size:.72rem}
.pk-app__wrap .form-control,.pk-app__wrap .form-select{border-radius:12px}
.pk-app__wrap .form-label{letter-spacing:.05em}
.pk-app__wrap .btn{border-radius:12px;font-weight:700}
.pk-app__wrap .badge{border-radius:999px}
.pk-app__wrap .nav-pills .nav-link{border-radius:999px;font-weight:700}
.pk-app__wrap .modal-content{border-radius:20px;overflow:hidden}
</style>
<?php bc_adm_sidebar(array("area" => "spadmin")); ?>
<?php bc_adm_topbar(array("area" => "spadmin", "title" => "SHARE FUND", "crumb" => "Home / Wallet Funding")); ?>

    <section class="section dashboard">
      <div class="row justify-content-center">
        <div class="col-lg-7">
            <div class="card shadow-sm border-0 rounded-4 overflow-hidden">
                <div class="card-header bg-white py-4 border-0 text-center">
                    <div class="bg-primary bg-opacity-10 rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width: 70px; height: 70px;">
                        <i class="bi bi-cash-coin text-primary fs-2"></i>
                    </div>
                    <h4 class="fw-bold mb-0">Platform Funding Tool</h4>
                    <p class="text-muted small">Inject or withdraw funds from any account across the platform</p>
                </div>
                <div class="card-body p-4 p-md-5 pt-0">
                    <form method="post">
                        <?php if (function_exists('bc_csrf_field')) echo bc_csrf_field(); ?>
                        <div class="row g-3 mb-4 bg-light p-3 rounded-4 border">
                            <div class="col-md-12">
                                <label class="form-label small fw-bold text-muted text-uppercase">Target Account Type</label>
                                <div class="d-flex gap-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="target" id="targetVendor" value="vendor" <?php echo (!isset($_GET['target']) || $_GET['target'] != 'user') ? 'checked' : ''; ?> onchange="toggleTarget()">
                                        <label class="form-check-label" for="targetVendor">Vendor Account</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="target" id="targetUser" value="user" <?php echo (isset($_GET['target']) && $_GET['target'] == 'user') ? 'checked' : ''; ?> onchange="toggleTarget()">
                                        <label class="form-check-label" for="targetUser">Vendor's User</label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div id="vendorSelection" class="<?php echo (isset($_GET['target']) && $_GET['target'] == 'user') ? '' : 'd-none'; ?> mb-4">
                            <label class="form-label small fw-bold text-muted text-uppercase">Select Vendor</label>
                            <select name="vid" id="vid-select" class="form-select form-select-lg rounded-3" onchange="fetchSuggestions()">
                                <option value="0">Choose Vendor...</option>
                                <?php
                                    $vs = mysqli_query($connection_server, "SELECT id, website_url FROM sas_vendors WHERE status=1 ORDER BY website_url ASC");
                                    while($vrow = mysqli_fetch_assoc($vs)){
                                        $selected = (isset($_GET["vid"]) && $_GET["vid"] == $vrow['id']) ? 'selected' : '';
                                        echo '<option value="'.$vrow['id'].'" '.$selected.'>'.$vrow['website_url'].'</option>';
                                    }
                                ?>
                            </select>
                        </div>

                        <div class="mb-4 position-relative">
                            <label id="identifierLabel" class="form-label small fw-bold text-muted text-uppercase">
                                <?php echo (isset($_GET['target']) && $_GET['target'] == 'user') ? 'Recipient Username' : 'Vendor Email Address'; ?>
                            </label>
                            <input name="identifier" id="identifierInput" type="text" class="form-control form-control-lg rounded-3" value="<?php echo htmlspecialchars($_GET['username'] ?? $_GET['searchq'] ?? ''); ?>" placeholder="..." required autocomplete="off" oninput="fetchSuggestions()" />
                            <div id="suggestionList" class="list-group position-absolute w-100 shadow-sm z-3 d-none" style="top: 100%;"></div>
                        </div>

                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-muted text-uppercase">Action</label>
                                <select name="type" class="form-select form-select-lg rounded-3" required>
                                    <option value="credit">Credit (+)</option>
                                    <option value="debit">Debit (-)</option>
                                    <option value="refund">Refund</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-muted text-uppercase">Amount (₦)</label>
                                <input name="amount" type="number" step="0.01" class="form-control form-control-lg rounded-3" placeholder="0.00" required />
                            </div>
                        </div>

                        <div class="row g-3 mb-2">
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-muted text-uppercase"><i class="bi bi-shield-lock me-1"></i>Security PIN</label>
                                <input name="pin" type="password" maxlength="4" pattern="[0-9]{4}" inputmode="numeric" placeholder="****" class="form-control form-control-lg rounded-3 text-center fw-bold" required />
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-muted text-uppercase"><i class="bi bi-phone-lock me-1"></i>Authenticator / Email Code</label>
                                <div class="input-group input-group-lg">
                                    <input name="otp_code" type="text" maxlength="6" pattern="[0-9]{6}" inputmode="numeric" placeholder="6-digit code" class="form-control rounded-start-3 text-center fw-bold" />
                                    <input type="hidden" name="totp_code" value="" />
                                    <button type="submit" name="send_stepup_code" value="1" formnovalidate class="btn btn-outline-primary fw-bold">Email code</button>
                                </div>
                            </div>
                            <p class="small text-muted mb-0">Every execution needs your PIN plus an authenticator code (or an emailed code). Set both up in Account Settings.</p>
                        </div>

                        <button name="share-fund" type="submit" class="btn btn-primary btn-lg w-100 rounded-pill fw-bold shadow-sm py-3 mt-2">
                            EXECUTE TRANSACTION
                        </button>
                    </form>
                </div>
            </div>
        </div>
      </div>
    </section>

    <script>
    let suggestionTimeout = null;

    function toggleTarget() {
        const isUser = document.getElementById('targetUser').checked;
        document.getElementById('vendorSelection').classList.toggle('d-none', !isUser);
        document.getElementById('identifierLabel').textContent = isUser ? 'Recipient Username' : 'Vendor Email Address';
        document.getElementById('suggestionList').classList.add('d-none');
    }
    
    function fetchSuggestions() {
        const query = document.getElementById('identifierInput').value;
        const target = document.querySelector('input[name="target"]:checked').value;
        const vid = document.getElementById('vid-select').value;
        const list = document.getElementById('suggestionList');

        if (query.length < 2) {
            list.classList.add('d-none');
            return;
        }

        clearTimeout(suggestionTimeout);
        suggestionTimeout = setTimeout(() => {
            fetch(`ajax-suggestions.php?q=${encodeURIComponent(query)}&target=${target}&vid=${vid}`)
                .then(res => res.json())
                .then(data => {
                    if (data.length > 0) {
                        list.innerHTML = data.map(s => `
                            <button type="button" class="list-group-item list-group-item-action py-2" onclick="selectSuggestion('${s.value}')">
                                ${s.label}
                            </button>
                        `).join('');
                        list.classList.remove('d-none');
                    } else {
                        list.classList.add('d-none');
                    }
                });
        }, 300);
    }

    function selectSuggestion(val) {
        document.getElementById('identifierInput').value = val;
        document.getElementById('suggestionList').classList.add('d-none');
    }

    // Close suggestions on click outside
    document.addEventListener('click', function(e) {
        if (!document.getElementById('identifierInput').contains(e.target)) {
            document.getElementById('suggestionList').classList.add('d-none');
        }
    });
    </script>

<?php bc_adm_foot(array("area" => "spadmin")); ?>
