<?php
require_once __DIR__ . '/../func/bc-secure-session.php';

bc_secure_session_start();
include("../func/bc-config.php");
require_once __DIR__."/../func/bc-referral-coins.php";

if(!isServiceEnabled('vtu_coins')) {
    header("Location: Dashboard.php");
    exit();
}

// Fetch loyalty settings for the vendor
$vendor_id = $get_logged_user_details["vendor_id"];
$settings_query = mysqli_query($connection_server, "SELECT * FROM sas_settings WHERE vendor_id = '$vendor_id' AND setting_name IN ('points_conversion_rate', 'min_points_conversion')");
$settings = [];
while($row = mysqli_fetch_assoc($settings_query)){
    $settings[$row['setting_name']] = $row['setting_value'];
}
$points_conversion_rate = $settings['points_conversion_rate'] ?? 500;
$min_points_conversion = $settings['min_points_conversion'] ?? 100;

// Fetch user's current points balance
$username = $get_logged_user_details["username"];
$user_points_balance = get_user_vtu_details($username)['total_points'];

// Fetch user's conversion history
$stmt = mysqli_prepare($connection_server, "SELECT * FROM sas_conversions WHERE vendor_id = ? AND username = ? ORDER BY id DESC");
mysqli_stmt_bind_param($stmt, "is", $vendor_id, $username);
mysqli_stmt_execute($stmt);
$history_query = mysqli_stmt_get_result($stmt);
$conversion_history = [];
while($row = mysqli_fetch_assoc($history_query)){
    $conversion_history[] = $row;
}

// Handle form submission
if (isset($_POST["convert-coins"])) {
    bc_validate_csrf();
    $raw = $_POST['points'] ?? '';
    $points = preg_match('/^[1-9][0-9]{0,9}$/D', (string)$raw) ? (int)$raw : 0;
    $r = bc_coin_conversion_change((int)$vendor_id, $username, $points);
    $_SESSION['product_purchase_response'] = $r['message'];
    if ($r['ok']) bc_send_admin_coin_conversion_email($vendor_id,$username,$r['points'],$r['amount']);
    header("Location: " . $_SERVER["REQUEST_URI"]);
    exit();
}
?>
<?php
// Plate app shell: one shared chrome for the signed-in console.
include_once(__DIR__ . "/../func/bc-plate-app.php");
bc_app_head("Convert Coins | " . ($get_all_site_details["site_title"]));
?>
<?php bc_app_sidebar(); ?>
<?php bc_app_topbar(array("title" => "CONVERT COINS", "crumb" => "Home / Convert Coins")); ?><!-- End Page Title -->

    <section class="section dashboard">
        <?php include("../func/service-header.php"); ?>

        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="card shadow-sm border-0 p-4 mb-4">
                    <h5 class="fw-bold mb-4 text-center">Convert Coins to Cash</h5>

                    <div class="bg-warning bg-opacity-10 p-3 rounded-4 border border-warning border-opacity-25 mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="small fw-bold text-dark-primary">CURRENT BALANCE</span>
                            <span class="h5 fw-bold text-dark mb-0"><?php echo number_format($user_points_balance, 0); ?> Coins</span>
                        </div>
                        <div class="d-flex justify-content-between small text-dark-primary" style="opacity: 0.8;">
                            <span>Rate</span>
                            <span><?php echo $points_conversion_rate; ?> Coins = ₦1.00</span>
                        </div>
                        <div class="d-flex justify-content-between small text-dark-primary" style="opacity: 0.8;">
                            <span>Minimum</span>
                            <span><?php echo number_format($min_points_conversion, 0); ?> Coins</span>
                        </div>
                    </div>

                    <form method="post" action=""><?php echo bc_csrf_field(); ?>
                        <div class="mb-3">
                            <label for="points" class="form-label small fw-bold text-uppercase">Points to Convert</label>
                            <input type="number" class="form-control form-control-lg fw-bold" id="points" name="points" placeholder="0" required onkeyup="calculateNairaValue()">
                        </div>

                        <div class="p-3 bg-light rounded-3 text-center mb-4">
                            <span class="text-muted small">You will receive:</span><br>
                            <span id="naira-value" class="h3 fw-bold text-success">₦0.00</span>
                        </div>

                        <button id="convert-btn" name="convert-coins" type="submit" class="btn btn-primary btn-lg w-100 shadow-sm" disabled>
                            SUBMIT REQUEST
                        </button>
                    </form>
                </div>

                <div class="card shadow-sm border-0 p-4">
                    <h5 class="fw-bold mb-4">Conversion History</h5>
                    <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th scope="col">Date</th>
                                <th scope="col">Points</th>
                                <th scope="col">Amount (₦)</th>
                                <th scope="col">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($conversion_history)): ?>
                                <tr>
                                    <td colspan="4" class="text-center">No conversion history found.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($conversion_history as $conversion): ?>
                                    <tr>
                                        <?php /* sas_conversions has request_date/completion_date - there is no "date" column, so the old strtotime($conversion['date']) rendered 1970-01-01. */ ?>
                                        <td><?php echo date("Y-m-d H:i", strtotime($conversion['request_date'] ?? '')); ?></td>
                                        <td><?php echo number_format($conversion['points'], 0); ?></td>
                                        <td><?php echo number_format($conversion['amount'], 2); ?></td>
                                        <td>
                                            <span class="badge bg-<?php
                                                switch ($conversion['status']) {
                                                    case 'pending':
                                                        echo 'warning';
                                                        break;
                                                    case 'approved':
                                                        echo 'success';
                                                        break;
                                                    case 'declined':
                                                        echo 'danger';
                                                        break;
                                                    default:
                                                        echo 'secondary';
                                                }
                                            ?>"><?php echo ucfirst($conversion['status']); ?></span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </section>

    <script>
        const pointsInput = document.getElementById('points');
        const nairaValueSpan = document.getElementById('naira-value');
        const convertBtn = document.getElementById('convert-btn');

        const conversionRate = <?php echo $points_conversion_rate; ?>;
        const minConversion = <?php echo $min_points_conversion; ?>;
        const userBalance = <?php echo $user_points_balance; ?>;

        function calculateNairaValue() {
            const points = parseInt(pointsInput.value, 10);

            if (isNaN(points) || points <= 0) {
                nairaValueSpan.textContent = 'You will receive: ₦0.00';
                convertBtn.disabled = true;
                return;
            }

            const nairaValue = points / conversionRate;
            nairaValueSpan.textContent = `You will receive: ₦${nairaValue.toFixed(2)}`;

            if (points >= minConversion && points <= userBalance) {
                convertBtn.disabled = false;
            } else {
                convertBtn.disabled = true;
            }
        }
    </script>
	<?php bc_app_foot(); ?>
