<?php session_start();
    include("../func/bc-spadmin-config.php");
         
    if(isset($_POST["share-fund"])){
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
            $_SESSION["product_purchase_response"] = ucwords($identifier." ".$type."ed with N".$amount." successfully");
        } else {
            $_SESSION["product_purchase_response"] = "Transaction Failed: Check balance or account status.";
        }
        header("Location: ".$_SERVER["REQUEST_URI"]);
        exit();
    }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Super Fund Management | Super Admin</title>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <link rel="stylesheet" href="<?php echo $css_style_template_location; ?>">
    <link rel="stylesheet" href="/cssfile/bc-style.css">
    
    <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets-2/vendor/boxicons/css/boxicons.min.css" rel="stylesheet">
    <link href="../assets-2/css/style.css" rel="stylesheet">
</head>
<body>
    <?php include("../func/bc-spadmin-header.php"); ?>  
    <div class="pagetitle">
      <h1>SHARE FUND</h1>
      <nav>
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="Dashboard.php">Home</a></li>
          <li class="breadcrumb-item active">Wallet Funding</li>
        </ol>
      </nav>
    </div>

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

    <?php include("../func/bc-spadmin-footer.php"); ?>
</body>
</html>
l>