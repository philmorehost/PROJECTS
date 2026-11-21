<?php
require_once __DIR__ . '/../func/bc-secure-session.php';
 bc_secure_session_start();
include("../func/bc-connect.php");
require_once(__DIR__ . "/../func/bc-plate-auth.php");

$vendor_id = resolveVendorID();
$select_vendor_table = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='$vendor_id' LIMIT 1"));

if (!$select_vendor_table) {
    header("Location: /index.php");
    exit();
}

$vendor_name = $select_vendor_table["firstname"] . " " . $select_vendor_table["lastname"];
$support_email = $select_vendor_table["email"];

bc_plate_auth_head('Website inactive');
bc_plate_auth_top(array(
    'icon'      => 'warn',
    'icon_style'=> 'warn',
    'title'     => 'Website inactive',
    'sub'       => 'This website is currently inactive. If you are the owner, please sign in to your admin panel to renew your subscription. If you are a user, please contact the administrator for assistance.',
));
?>
        <div class="pk-auth__field">
            <a class="pk-auth__btn" href="mailto:<?php echo htmlspecialchars($support_email); ?>">
                <?php echo bc_plate_auth_icon('mail', 18); ?> Contact admin
            </a>
        </div>
        <p class="pk-auth__note">
            Owner? <a class="pk-auth__link" href="/bc-admin/Login.php">Admin sign in</a>
        </p>
<?php
bc_plate_auth_end();
?>
