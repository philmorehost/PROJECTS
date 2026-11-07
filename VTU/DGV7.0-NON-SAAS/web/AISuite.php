<?php session_start();
    include("../func/bc-config.php");
    
    if(!isset($_SESSION["user_session"])){
        header("Location: Login.php");
        exit();
    }

    // User details are already fetched in bc-config.php as $get_logged_user_details
    $get_all_site_details = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_site_details WHERE vendor_id='".$get_logged_user_details["vendor_id"]."'"));

    // Handle AI Applications
    if (isset($_POST["apply-ai-voice"])) {
        $uid = $get_logged_user_details['id'];
        $tx_count_q = mysqli_query($connection_server, "SELECT COUNT(*) as c FROM sas_transactions WHERE username='".$get_logged_user_details["username"]."' AND status=1");
        $tx_count = ($tx_count_q && $row_c = mysqli_fetch_assoc($tx_count_q)) ? (int)$row_c['c'] : 0;
        
        $v_limit_q = mysqli_query($connection_server, "SELECT voice_tx_threshold, ai_voice_fee_tokens FROM sas_vendors WHERE id='".$get_logged_user_details["vendor_id"]."'");
        $v_limit_row = ($v_limit_q) ? mysqli_fetch_assoc($v_limit_q) : null;
        $v_limit = $v_limit_row['voice_tx_threshold'] ?? 50;
        $v_fee = $v_limit_row['ai_voice_fee_tokens'] ?? 0;

        if ($tx_count >= $v_limit) {
            if ((int)$get_logged_user_details['ai_token_balance'] >= $v_fee) {
                $new_token_bal = (int)$get_logged_user_details['ai_token_balance'] - $v_fee;
                mysqli_query($connection_server, "UPDATE sas_users SET ai_status=1, ai_voice_status=1, ai_token_balance='$new_token_bal' WHERE id='$uid'");
                $_SESSION["product_purchase_response"] = "✅ AI Assistant enabled! " . ($v_fee > 0 ? "$v_fee tokens deducted. " : "") . "Your Zero-Click Voice access is now pending admin review.";
            } else {
                $_SESSION["product_purchase_response"] = "❌ You need at least $v_fee AI tokens to activate this feature.";
            }
        } else {
            $_SESSION["product_purchase_response"] = "You have not met the transaction requirement ($v_limit) to apply.";
        }
        header("Location: AISuite.php"); exit();
    }

    // Handle Token Purchase
    if (isset($_POST["buy-user-ai-tokens"])) {
        $uid = $get_logged_user_details['id'];
        $vid = $get_logged_user_details['vendor_id'];
        $token_amount = (int)($_POST["token_amount"] ?? 0);
        
        $v_q = mysqli_query($connection_server, "SELECT ai_user_token_price, ai_status, ai_bonus_tokens FROM sas_vendors WHERE id='$vid'");
        $v_data = mysqli_fetch_assoc($v_q);
        $price_per_1k = (float)($v_data['ai_user_token_price'] ?? 150.00);
        $bonus_tokens = (int)($v_data['ai_bonus_tokens'] ?? 500);
        $cost = ($token_amount / 1000) * $price_per_1k;

        if ($token_amount >= 100 && $get_logged_user_details['balance'] >= $cost) {
            $ref = "AI_TKN_" . time() . rand(10, 99);

            // Check if first purchase
            $check_first = mysqli_query($connection_server, "SELECT id FROM sas_transactions WHERE username='".$get_logged_user_details['username']."' AND product_unique_id='AI_TOKEN' LIMIT 1");
            $is_first = (!$check_first || mysqli_num_rows($check_first) == 0);

            $desc = "Purchase of $token_amount AI Tokens";
            $final_tokens = $token_amount;
            if ($is_first && $bonus_tokens > 0) {
                $final_tokens += $bonus_tokens;
                $desc .= " (+ $bonus_tokens Welcome Bonus)";
            }

            $new_bal = $get_logged_user_details['balance'] - $cost;
            $new_token_bal = $get_logged_user_details['ai_token_balance'] + $final_tokens;
            
            mysqli_query($connection_server, "UPDATE sas_users SET balance='$new_bal', ai_token_balance='$new_token_bal' WHERE id='$uid'");
            mysqli_query($connection_server, "INSERT INTO sas_transactions (vendor_id, product_unique_id, type_alternative, reference, username, amount, discounted_amount, balance_before, balance_after, description, mode, api_website, status, date) VALUES ('$vid', 'AI_TOKEN', 'AI Token Purchase', '$ref', '".$get_logged_user_details['username']."', '$token_amount', '$cost', '".$get_logged_user_details['balance']."', '$new_bal', '$desc', 'web', '".$_SERVER['HTTP_HOST']."', 1, NOW())");
            $_SESSION["product_purchase_response"] = "✅ $token_amount AI Tokens purchased successfully!";
        } else {
            $_SESSION["product_purchase_response"] = "❌ Insufficient balance or invalid amount.";
        }
        header("Location: AISuite.php"); exit();
    }

    // Fetch Stats
    $tx_count_q = mysqli_query($connection_server, "SELECT COUNT(*) as c FROM sas_transactions WHERE username='".$get_logged_user_details["username"]."' AND status=1");
    $tx_count = ($tx_count_q && $row_c = mysqli_fetch_assoc($tx_count_q)) ? (int)$row_c['c'] : 0;
    
    $v_q = mysqli_query($connection_server, "SELECT voice_tx_threshold, ai_user_token_price, ai_status FROM sas_vendors WHERE id='".$get_logged_user_details["vendor_id"]."'");
    $v_data = mysqli_fetch_assoc($v_q);
    
    $v_limit = (int)($v_data['voice_tx_threshold'] ?? 50);
    $user_token_price = (float)($v_data['ai_user_token_price'] ?? 150.00);
    $ai_system_on = (int)($v_data['ai_status'] ?? 0);
    
    $ai_status = (int)$get_logged_user_details['ai_status'];
    $ai_voice_status = (int)$get_logged_user_details['ai_voice_status'];
    $user_tokens = (int)$get_logged_user_details['ai_token_balance'];
    $progress = min(100, ($tx_count / max(1, $v_limit)) * 100);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>AI Suite | <?php echo $get_all_site_details["site_title"]; ?></title>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <link rel="stylesheet" href="../assets-2/vendor/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets-2/css/style.css">
    <link rel="stylesheet" href="/cssfile/bc-style.css">
    <style>
        .ai-card { border-radius: 1.5rem; transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1); border: none; }
        .ai-card:hover { transform: translateY(-5px); }
        .bg-ai { background: linear-gradient(135deg, #4f46e5, #7c3aed); }
        .token-stat { font-size: 2.5rem; font-weight: 800; background: linear-gradient(to right, #4f46e5, #9333ea); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
    </style>
</head>
<body>
    <?php include("../func/bc-header.php"); ?>
    
    <div class="pagetitle">
      <h1>AI COMMAND CENTER</h1>
      <nav>
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="Dashboard.php">Dashboard</a></li>
          <li class="breadcrumb-item active">AI Suite</li>
        </ol>
      </nav>
    </div>

    <section class="section">
        <div class="row">
            <!-- Main Content: AI Status & Tokens -->
            <div class="col-lg-8">
                <!-- TOKEN WALLET -->
                <div class="card ai-card shadow-sm mb-4">
                    <div class="card-body p-4">
                        <div class="d-flex justify-content-between align-items-start mb-4">
                            <div>
                                <h5 class="fw-bold"><i class="bi bi-wallet2 me-2 text-primary"></i>AI Token Wallet</h5>
                                <p class="text-muted small">Tokens power your AI Assistant and Voice commands.</p>
                            </div>
                            <div class="text-end">
                                <span class="badge bg-primary text-white rounded-pill px-3 py-2 shadow-sm">
                                    <i class="bi bi-shield-check me-1"></i> Active
                                </span>
                            </div>
                        </div>

                        <div class="row align-items-center mb-4">
                            <div class="col-md-6 border-end">
                                <div class="p-3">
                                    <span class="text-muted small d-block mb-1">Available Tokens</span>
                                    <h1 class="token-stat mb-0"><?php echo number_format($user_tokens); ?></h1>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="p-3">
                                    <form method="post">
                                        <?php echo bc_csrf_field(); ?>
                                        <div class="mb-3">
                                            <label class="form-label small fw-bold">RECHARGE TOKENS</label>
                                            <select name="token_amount" class="form-select bg-light border-0" required onchange="updateTokenCost(this.value)">
                                                <option value="1000">1,000 Tokens (₦<?php echo number_format($user_token_price, 2); ?>)</option>
                                                <option value="5000">5,000 Tokens (₦<?php echo number_format($user_token_price * 5, 2); ?>)</option>
                                                <option value="10000">10,000 Tokens (₦<?php echo number_format($user_token_price * 10, 2); ?>)</option>
                                                <option value="50000">50,000 Tokens (₦<?php echo number_format($user_token_price * 50, 2); ?>)</option>
                                            </select>
                                        </div>
                                        <button type="submit" name="buy-user-ai-tokens" class="btn btn-primary w-100 rounded-pill fw-bold py-2 shadow-sm">
                                            BUY <span id="token-cost">₦<?php echo number_format($user_token_price, 2); ?></span>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- AUTONOMOUS AI -->
                <div class="card ai-card shadow-sm border-0 overflow-hidden" style="background: #f8fafc;">
                    <div class="card-body p-4">
                        <h5 class="fw-bold mb-3"><i class="bi bi-mic-fill me-2 text-primary"></i>Autonomous Voice Access</h5>
                        <p class="small text-muted mb-4">Unlock "Zero-Click" Voice commands. Buy airtime and data just by speaking to the AI.</p>
                        
                        <div class="bg-white p-4 rounded-4 mb-4 border border-light">
                            <div class="d-flex justify-content-between small fw-bold mb-2">
                                <span>Activation Progress</span>
                                <span><?php echo $tx_count; ?> / <?php echo $v_limit; ?> Successful Tx</span>
                            </div>
                            <div class="progress mb-4" style="height: 12px; border-radius: 10px;">
                                <div class="progress-bar bg-ai shadow-sm" role="progressbar" style="width: <?php echo $progress; ?>%"></div>
                            </div>

                            <?php if ($ai_voice_status == 2): ?>
                                <div class="alert alert-success border-0 rounded-4 d-flex align-items-center p-3 mb-0">
                                    <i class="bi bi-check-circle-fill fs-3 me-3"></i>
                                    <div>
                                        <div class="fw-bold">Fully Activated!</div>
                                        <div class="small">Tap the microphone in the AI Assistant to buy VTU with your voice.</div>
                                    </div>
                                </div>
                            <?php elseif ($ai_voice_status == 1 || (int)$get_logged_user_details['ai_status'] == 1): ?>
                                <div class="alert alert-warning border-0 rounded-4 d-flex align-items-center p-3 mb-0">
                                    <i class="bi bi-hourglass-split fs-3 me-3"></i>
                                    <div>
                                        <div class="fw-bold">Review Pending</div>
                                        <div class="small">The admin is reviewing your account for autonomous access.</div>
                                    </div>
                                </div>
                            <?php elseif ($ai_voice_status == 0 || $ai_voice_status == 3): ?>
                                <form method="post">
                                    <?php echo bc_csrf_field(); ?>
                                    <button type="submit" name="apply-ai-voice" class="btn btn-dark btn-lg w-100 fw-bold rounded-pill shadow-sm" <?php if ($tx_count < $v_limit) echo 'disabled'; ?>>
                                        <?php echo $tx_count >= $v_limit ? 'ACTIVATE AUTONOMOUS AI' : 'COMPLETED '.$tx_count.'/'.$v_limit.' TRANSACTIONS TO UNLOCK'; ?>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Sidebar: How to Use & Commands -->
            <div class="col-lg-4">
                <div class="card ai-card shadow-sm border-0 mb-4 overflow-hidden">
                    <div class="card-header bg-ai text-white p-3 border-0">
                        <h6 class="fw-bold mb-0"><i class="bi bi-terminal-fill me-2"></i>AI Command Center</h6>
                    </div>
                    <div class="card-body p-3">
                        <p class="x-small text-muted mb-3">Copy and paste these commands into the AI Chat or say them aloud.</p>
                        
                        <div class="command-group mb-3">
                            <label class="x-small fw-bold text-uppercase text-muted mb-1">Airtime</label>
                            <div class="input-group input-group-sm mb-2">
                                <input type="text" class="form-control bg-light border-0 x-small" value="Buy MTN 100 airtime for 08012345678" readonly id="cmd-airtime">
                                <button class="btn btn-outline-primary" type="button" onclick="copyCmd('cmd-airtime')"><i class="bi bi-clipboard"></i></button>
                            </div>
                        </div>

                        <div class="command-group mb-3">
                            <label class="x-small fw-bold text-uppercase text-muted mb-1">Data Bundle</label>
                            <div class="input-group input-group-sm mb-2">
                                <input type="text" class="form-control bg-light border-0 x-small" value="Buy Airtel 2GB SME data for 08123456789" readonly id="cmd-data">
                                <button class="btn btn-outline-primary" type="button" onclick="copyCmd('cmd-data')"><i class="bi bi-clipboard"></i></button>
                            </div>
                        </div>

                        <div class="command-group mb-3">
                            <label class="x-small fw-bold text-uppercase text-muted mb-1">Electricity</label>
                            <div class="input-group input-group-sm mb-2">
                                <input type="text" class="form-control bg-light border-0 x-small" value="Pay 2000 for IKEDC Prepaid 010123456789" readonly id="cmd-power">
                                <button class="btn btn-outline-primary" type="button" onclick="copyCmd('cmd-power')"><i class="bi bi-clipboard"></i></button>
                            </div>
                        </div>

                        <div class="command-group mb-0">
                            <label class="x-small fw-bold text-uppercase text-muted mb-1">Cable TV</label>
                            <div class="input-group input-group-sm">
                                <input type="text" class="form-control bg-light border-0 x-small" value="Renew DSTV Compact for 123456789" readonly id="cmd-cable">
                                <button class="btn btn-outline-primary" type="button" onclick="copyCmd('cmd-cable')"><i class="bi bi-clipboard"></i></button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card ai-card shadow-sm border-0 bg-dark text-white mb-4">
                <div class="card ai-card shadow-sm border-0 bg-dark text-white mb-4">
                    <div class="card-body p-4">
                        <h6 class="fw-bold mb-3"><i class="bi bi-lightbulb me-2 text-warning"></i>Quick Start Guide</h6>
                        <ul class="list-unstyled small mb-0">
                            <li class="mb-3 d-flex align-items-start">
                                <span class="bg-white bg-opacity-10 rounded-circle p-1 me-2"><i class="bi bi-1-circle"></i></span>
                                <span>Look for the 🤖 icon at the bottom right of any page.</span>
                            </li>
                            <li class="mb-3 d-flex align-items-start">
                                <span class="bg-white bg-opacity-10 rounded-circle p-1 me-2"><i class="bi bi-2-circle"></i></span>
                                <span>Click it to open the AI Chat Assistant.</span>
                            </li>
                            <li class="mb-3 d-flex align-items-start">
                                <span class="bg-white bg-opacity-10 rounded-circle p-1 me-2"><i class="bi bi-3-circle"></i></span>
                                <span>Type or Speak commands like:<br><i>"Buy MTN 500 airtime for 08012345678"</i></span>
                            </li>
                            <li class="d-flex align-items-start">
                                <span class="bg-white bg-opacity-10 rounded-circle p-1 me-2"><i class="bi bi-4-circle"></i></span>
                                <span>If approved, the transaction happens instantly!</span>
                            </li>
                        </ul>
                    </div>
                </div>

                <div class="card ai-card shadow-sm border-0">
                    <div class="card-body p-4 text-center">
                        <div class="p-3 bg-light rounded-circle d-inline-flex mb-3">
                            <i class="bi bi-chat-dots-fill fs-3 text-primary"></i>
                        </div>
                        <h6 class="fw-bold">Need Custom Help?</h6>
                        <p class="text-muted x-small mb-3">The AI Assistant can also help you with platform questions, balance checks, and more.</p>
                        <button onclick="window.__ai_open()" class="btn btn-outline-primary btn-sm rounded-pill px-4">Open AI Now</button>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <?php include("../func/bc-footer.php"); ?>
    
    <script>
        function updateTokenCost(amount) {
            const pricePer1k = <?php echo $user_token_price; ?>;
            const cost = (amount / 1000) * pricePer1k;
            document.getElementById('token-cost').innerText = '₦' + cost.toLocaleString(undefined, {minimumFractionDigits: 2});
        }

        function copyCmd(id) {
            const input = document.getElementById(id);
            input.select();
            input.setSelectionRange(0, 99999);
            navigator.clipboard.writeText(input.value);
            
            // Visual feedback
            const btn = input.nextElementSibling;
            const icon = btn.querySelector('i');
            icon.className = 'bi bi-check2';
            btn.className = 'btn btn-success';
            setTimeout(() => {
                icon.className = 'bi bi-clipboard';
                btn.className = 'btn btn-outline-primary';
            }, 2000);
        }
    </script>
</body>
</html>
