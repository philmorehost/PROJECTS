<?php
require_once __DIR__ . '/../func/bc-secure-session.php';
 bc_secure_session_start();
    include("../func/bc-connect.php");
    require_once(__DIR__ . "/../func/bc-plate-auth.php");
    //Select Vendor Table
    $vendor_id = resolveVendorID();
	$select_vendor_table = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='$vendor_id' AND status=1 LIMIT 1"));
	if($select_vendor_table){
		$get_vendor_details = $select_vendor_table;
    }else{
        $get_vendor_details = "";
    }

    if($get_vendor_details == ""){
        header("Location: /Error.php");
        exit();
    }

bc_plate_auth_head('Website suspended');
bc_plate_auth_top(array(
    'icon'      => 'warn',
    'icon_style'=> 'warn',
    'title'     => 'Website suspended',
    'sub'       => 'We are sorry, but this website is currently suspended. Please contact the site administrator for more information.',
));
?>
        <?php if (!empty($get_vendor_details['email'])): ?>
        <div class="pk-auth__field">
            <a class="pk-auth__btn" href="mailto:<?php echo htmlspecialchars($get_vendor_details['email']); ?>">
                <?php echo bc_plate_auth_icon('mail', 18); ?> Contact <?php echo htmlspecialchars($get_vendor_details['email']); ?>
            </a>
        </div>
        <?php endif; ?>
        <p class="pk-auth__note">
            Are you the owner? <a class="pk-auth__link" href="/bc-admin/Login.php">Admin sign in</a>
        </p>
<?php
bc_plate_auth_end();
?>
